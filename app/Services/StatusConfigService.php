<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\StatusMachine;
use App\Models\Status;
use App\Models\StatusTransition;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowStage;
use App\Support\StatusCatalogue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The rules behind the status and transition editors (§63, §73).
 *
 * Phase 7 hands the state machine to administrators, which is the configurability the platform
 * promises and also the largest new attack surface in it: `status_transitions` is the whitelist
 * StatusMachine consults, so a screen that writes to it is a screen that can rewrite what every
 * record in the company is allowed to do. Four things are therefore refused here rather than in a
 * controller, because a controller is one of several callers and this is the only chokepoint.
 *
 *   1. A SYSTEM STATUS KEEPS ITS KEY, ITS EXISTENCE AND ITS PULSE. Service code moves records by
 *      key — `moveTo($run, 'approved')` — and moveTo() looks among ACTIVE statuses and silently
 *      does nothing when it finds none. Renaming, deleting or deactivating `approved` would give a
 *      company a payroll run the engine has approved and a register that still says Submitted, with
 *      no error raised anywhere. Labels, colours and order stay editable, which is what §73 actually
 *      promises: a company renames "Approved" to "Authorised" and nothing in code notices.
 *
 *   2. A TRANSITION INTO A WORKFLOW'S OUTCOME MUST NAME A PERMISSION. This is the Phase 6 exit
 *      criterion defended against the Phase 7 editor. The engine's `set_status` auto-action runs
 *      through StatusMachine, so the row `submitted → approved` HAS to exist — forbidding it would
 *      break every routed register. What must not exist is that row with a blank `permission_key`,
 *      because the generic status endpoints (projects, tasks, memos, announcements, meetings) would
 *      then let anyone holding ordinary edit rights move a record to the status an approval was
 *      supposed to confer, and the approval engine would be decoration.
 *
 *   3. ENTITY TYPES COME FROM THE CATALOGUE, NEVER FROM THE URL (§84). `entity_type` reaches a
 *      `Str::plural()` table lookup in StatusPolicy, so accepting an arbitrary string from a path
 *      segment would let configuration name tables.
 *
 *   4. `organization_id` IS TAKEN FROM THE ACTOR (§74), never from the payload.
 */
final class StatusConfigService
{
    public function __construct(
        private readonly StatusMachine $statuses,
        private readonly ActivityRecorder $activity,
    ) {}

    // ================================================================== statuses

    /** @param array<string,mixed> $attributes */
    public function createStatus(string $entityType, array $attributes, User $actor): Status
    {
        $this->assertKnownEntityType($entityType);

        $key = $this->normaliseKey((string) ($attributes['key'] ?? ''), (string) ($attributes['label'] ?? ''));

        if ($key === '') {
            throw new \DomainException('A status needs a key.');
        }

        if ($this->keyExists($entityType, $key)) {
            throw new \DomainException('A status with the key "'.$key.'" already exists for this register.');
        }

        return DB::transaction(function () use ($entityType, $attributes, $key, $actor): Status {
            $status = Status::create([
                // §74. Never from the payload, whatever the payload says.
                'organization_id' => $actor->organization_id,
                'entity_type' => $entityType,
                'key' => $key,
                'label' => trim((string) $attributes['label']),
                'color' => $this->tone($attributes['color'] ?? null),
                'sort' => $this->nextSort($entityType),
                'is_initial' => false,
                'is_terminal' => (bool) ($attributes['is_terminal'] ?? false),
                'is_active' => true,
                // NEVER from input. A status a company invents carries no code dependency, and
                // letting the form assert otherwise would hand it the protections in this class.
                'is_system' => false,
            ]);

            if (! empty($attributes['is_initial'])) {
                $this->makeInitial($status);
            }

            $this->activity->record(
                $status,
                'created',
                $actor->name.' added the status "'.$status->label.'" to '.$this->registerName($entityType).'.',
                ['key' => $status->key],
                $actor,
            );

            $this->statuses->flush();

            return $status;
        });
    }

    /** @param array<string,mixed> $attributes */
    public function updateStatus(Status $status, array $attributes, User $actor): Status
    {
        return DB::transaction(function () use ($status, $attributes, $actor): Status {
            $before = ['label' => $status->label, 'key' => $status->key, 'is_active' => $status->is_active];

            // Always editable, on every status. This is the whole of what §73 promises about the
            // vocabulary: a company's own words on its own screens.
            $status->label = trim((string) $attributes['label']);
            $status->color = $this->tone($attributes['color'] ?? null);

            if (array_key_exists('sort', $attributes)) {
                $status->sort = (int) $attributes['sort'];
            }

            if ($status->isSystem()) {
                // The three that reach into code. Silently ignoring a submitted change would be
                // worse than refusing it: the administrator would believe it took.
                $this->assertSystemFieldsUnchanged($status, $attributes);
            } else {
                if (array_key_exists('key', $attributes)) {
                    $key = $this->normaliseKey((string) $attributes['key'], $status->label);

                    if ($key !== $status->key && $this->keyExists($status->entity_type, $key)) {
                        throw new \DomainException('A status with the key "'.$key.'" already exists for this register.');
                    }

                    $status->key = $key;
                }

                $status->is_terminal = (bool) ($attributes['is_terminal'] ?? false);

                $active = (bool) ($attributes['is_active'] ?? false);

                if (! $active) {
                    $this->assertMayRetire($status);
                }

                $status->is_active = $active;
            }

            $status->save();

            if (! empty($attributes['is_initial'])) {
                $this->makeInitial($status);
            }

            $this->activity->record(
                $status,
                'updated',
                $actor->name.' changed the status "'.$status->label.'" on '.$this->registerName($status->entity_type).'.',
                array_filter(['was' => $before['label'] !== $status->label ? $before['label'] : null]),
                $actor,
            );

            $this->statuses->flush();

            return $status;
        });
    }

    public function deleteStatus(Status $status, User $actor): void
    {
        if ($status->isSystem()) {
            throw new \DomainException(
                '"'.$status->label.'" is part of how this register works and cannot be deleted. '
                .'Rename it if the word is wrong for your company.'
            );
        }

        if ($this->isInUse($status)) {
            throw new \DomainException(
                'Records are sitting in "'.$status->label.'" right now. Untick Active to retire it '
                .'instead — deleting it would leave their history pointing at nothing.'
            );
        }

        $this->assertMayRetire($status);

        DB::transaction(function () use ($status, $actor): void {
            $label = $status->label;
            $entityType = $status->entity_type;

            // The transitions naming it go with it, by the foreign key's own cascade. That is
            // correct rather than incidental: a move to a status that no longer exists is not a
            // move, and leaving the rows would make the graph unreadable.
            $status->delete();

            $this->activity->record(
                $actor->organization,
                'deleted',
                $actor->name.' deleted the status "'.$label.'" from '.$this->registerName($entityType).'.',
                ['entity_type' => $entityType],
                $actor,
            );

            $this->statuses->flush();
        });
    }

    /**
     * Put the vocabulary in the order a company reads it in.
     *
     * Ids that are not this tenant's, or not this register's, are dropped rather than rejected —
     * the list is scoped when it is fetched, so a foreign id in the payload is an attack rather
     * than a mistake, and it must not be able to reorder anything (§84).
     *
     * @param  array<int,int|string>  $orderedIds
     */
    public function reorder(string $entityType, array $orderedIds, User $actor): void
    {
        $this->assertKnownEntityType($entityType);

        $statuses = $this->statusesFor($entityType)->keyBy('id');
        $sort = 0;

        DB::transaction(function () use ($orderedIds, $statuses, &$sort): void {
            foreach ($orderedIds as $id) {
                $status = $statuses->get((int) $id);

                if ($status === null) {
                    continue;
                }

                $status->sort = $sort += 10;
                $status->save();
            }
        });

        $this->activity->record(
            $actor->organization,
            'updated',
            $actor->name.' reordered the statuses on '.$this->registerName($entityType).'.',
            ['entity_type' => $entityType],
            $actor,
        );

        $this->statuses->flush();
    }

    // ================================================================== transitions

    /** @param array<string,mixed> $attributes */
    public function createTransition(string $entityType, array $attributes, User $actor): StatusTransition
    {
        $this->assertKnownEntityType($entityType);

        [$from, $to] = $this->resolveEnds($entityType, $attributes);

        if ($this->transitionExists($entityType, $from?->getKey(), $to->getKey())) {
            throw new \DomainException('That move is already defined.');
        }

        $permission = $this->cleanPermission($attributes['permission_key'] ?? null);
        $this->assertWorkflowOutcomeIsGuarded($to, $permission);

        return DB::transaction(function () use ($entityType, $attributes, $from, $to, $permission, $actor): StatusTransition {
            $transition = StatusTransition::create([
                'organization_id' => $actor->organization_id,        // §74
                'entity_type' => $entityType,
                'from_status_id' => $from?->getKey(),
                'to_status_id' => $to->getKey(),
                'label' => trim((string) ($attributes['label'] ?? '')) ?: null,
                'permission_key' => $permission,
                'requires_comment' => (bool) ($attributes['requires_comment'] ?? false),
                'sort' => $this->nextTransitionSort($entityType),
            ]);

            $this->activity->record(
                $transition,
                'created',
                $actor->name.' allowed '.$this->describe($from, $to).' on '.$this->registerName($entityType).'.',
                array_filter(['permission' => $permission]),
                $actor,
            );

            return $transition;
        });
    }

    /** @param array<string,mixed> $attributes */
    public function updateTransition(StatusTransition $transition, array $attributes, User $actor): StatusTransition
    {
        $permission = $this->cleanPermission($attributes['permission_key'] ?? null);

        // Re-asked on every update, not only on create. Removing the permission from an existing
        // row is precisely the edit that would open the hole, and it is the easier one to make.
        $this->assertWorkflowOutcomeIsGuarded($transition->to, $permission);

        return DB::transaction(function () use ($transition, $attributes, $permission, $actor): StatusTransition {
            // The two ENDS are structural — they are what the row means. Repointing them would
            // silently redefine a move somebody audited, so a different move is a different row.
            $transition->label = trim((string) ($attributes['label'] ?? '')) ?: null;
            $transition->permission_key = $permission;
            $transition->requires_comment = (bool) ($attributes['requires_comment'] ?? false);

            if (array_key_exists('sort', $attributes)) {
                $transition->sort = (int) $attributes['sort'];
            }

            $transition->save();

            $this->activity->record(
                $transition,
                'updated',
                $actor->name.' changed the rule for '.$this->describe($transition->from, $transition->to).'.',
                array_filter(['permission' => $permission]),
                $actor,
            );

            return $transition;
        });
    }

    public function deleteTransition(StatusTransition $transition, User $actor): void
    {
        DB::transaction(function () use ($transition, $actor): void {
            $description = $this->describe($transition->from, $transition->to);
            $entityType = $transition->entity_type;

            $transition->delete();

            $this->activity->record(
                $actor->organization,
                'deleted',
                $actor->name.' withdrew '.$description.' on '.$this->registerName($entityType).'.',
                ['entity_type' => $entityType],
                $actor,
            );
        });
    }

    // ================================================================== reading

    /** @return Collection<int,Status> */
    public function statusesFor(string $entityType): Collection
    {
        return Status::query()
            ->forEntity($entityType)
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int,StatusTransition> */
    public function transitionsFor(string $entityType): Collection
    {
        return StatusTransition::query()
            ->forEntity($entityType)
            ->with(['from', 'to'])
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
    }

    /**
     * The registers a company may configure, with how much it has configured.
     *
     * @return array<int,array{key:string,name:string,statuses:int,transitions:int}>
     */
    public function registers(): array
    {
        $statuses = Status::query()
            ->selectRaw('entity_type, COUNT(*) as total')
            ->groupBy('entity_type')
            ->pluck('total', 'entity_type');

        $transitions = StatusTransition::query()
            ->selectRaw('entity_type, COUNT(*) as total')
            ->groupBy('entity_type')
            ->pluck('total', 'entity_type');

        $out = [];

        foreach (StatusCatalogue::entityTypes() as $entityType) {
            $out[] = [
                'key' => $entityType,
                'name' => $this->registerName($entityType),
                'statuses' => (int) ($statuses[$entityType] ?? 0),
                'transitions' => (int) ($transitions[$entityType] ?? 0),
            ];
        }

        usort($out, fn (array $a, array $b) => $a['name'] <=> $b['name']);

        return $out;
    }

    /**
     * Statuses this register's workflows confer, which the editor marks on screen.
     *
     * Shown rather than merely enforced, because an administrator who cannot see WHY a permission
     * is being demanded will read the refusal as a bug and go looking for a way around it.
     *
     * @return array<int,int> status ids
     */
    public function workflowOutcomes(string $entityType): array
    {
        $keys = $this->workflowStatusKeys($entityType);

        if ($keys === []) {
            return [];
        }

        return $this->statusesFor($entityType)
            ->filter(fn (Status $s) => in_array($s->key, $keys, true) || in_array((string) $s->getKey(), $keys, true))
            ->map(fn (Status $s) => (int) $s->getKey())
            ->values()
            ->all();
    }

    // ================================================================== guards

    private function assertKnownEntityType(string $entityType): void
    {
        if (! in_array($entityType, StatusCatalogue::entityTypes(), true)) {
            throw new \DomainException('There is no register called "'.$entityType.'".');
        }
    }

    /**
     * The three fields on a system status that reach into code.
     *
     * @param  array<string,mixed>  $attributes
     */
    private function assertSystemFieldsUnchanged(Status $status, array $attributes): void
    {
        if (array_key_exists('key', $attributes)) {
            $submitted = $this->normaliseKey((string) $attributes['key'], $status->label);

            if ($submitted !== '' && $submitted !== $status->key) {
                throw new \DomainException(
                    'The key of "'.$status->label.'" cannot be changed: this register\'s own code moves '
                    .'records into it by that name. The label is yours to change freely.'
                );
            }
        }

        if (array_key_exists('is_active', $attributes) && ! $attributes['is_active']) {
            throw new \DomainException(
                '"'.$status->label.'" cannot be switched off: this register moves records into it, and '
                .'a retired status would make those moves fail silently.'
            );
        }
    }

    /**
     * A company must keep at least one active status, and at least one way in.
     *
     * A register with none has a machine where new records cannot be given a state at all, which
     * surfaces as a broken create form rather than as the configuration mistake it is.
     */
    private function assertMayRetire(Status $status): void
    {
        $remaining = $this->statusesFor($status->entity_type)
            ->filter(fn (Status $s) => $s->is_active && (int) $s->getKey() !== (int) $status->getKey());

        if ($remaining->isEmpty()) {
            throw new \DomainException(
                'This would leave '.$this->registerName($status->entity_type).' with no statuses at all.'
            );
        }

        if ($status->is_initial && $remaining->firstWhere('is_initial', true) === null) {
            throw new \DomainException(
                '"'.$status->label.'" is where new records start. Make another status the starting one first.'
            );
        }
    }

    /**
     * THE PHASE 6 EXIT CRITERION, DEFENDED AGAINST THE PHASE 7 EDITOR.
     *
     * The row itself must be allowed to exist: AutoActionRunner::setStatus() moves the subject
     * through StatusMachine, so the approval engine needs `submitted → approved` in the whitelist
     * exactly like any other move. What it must not be is OPEN. A permission-less transition into a
     * status that a published workflow confers means the generic status endpoints hand anyone with
     * ordinary edit rights the outcome an approval was supposed to grant — and no test of the
     * approval engine would notice, because the engine is not involved.
     */
    private function assertWorkflowOutcomeIsGuarded(Status $to, ?string $permission): void
    {
        if ($permission !== null) {
            return;
        }

        if (! in_array((int) $to->getKey(), $this->workflowOutcomes($to->entity_type), true)) {
            return;
        }

        throw new \DomainException(
            '"'.$to->label.'" is a status an approval route confers on '
            .$this->registerName($to->entity_type).'. A move into it has to name the permission '
            .'required to make it, otherwise anyone who may edit the record could take the decision '
            .'the approval was there to make.'
        );
    }

    /**
     * The status keys the published, active workflows for this register set.
     *
     * Matched on key OR id, because AutoActionRunner accepts either.
     *
     * @return array<int,string>
     */
    private function workflowStatusKeys(string $entityType): array
    {
        $workflowIds = Workflow::query()
            ->where('entity_type', $entityType)
            ->where('is_active', true)
            ->whereNotNull('published_at')
            ->pluck('id');

        if ($workflowIds->isEmpty()) {
            return [];
        }

        $keys = [];

        foreach (WorkflowStage::query()->whereIn('workflow_id', $workflowIds)->get(['id', 'auto_actions']) as $stage) {
            foreach ((array) ($stage->auto_actions ?? []) as $action) {
                if (! is_array($action) || ($action['verb'] ?? '') !== 'set_status') {
                    continue;
                }

                $key = (string) ($action['status'] ?? '');

                if ($key !== '') {
                    $keys[] = $key;
                }
            }
        }

        return array_values(array_unique($keys));
    }

    // ================================================================== helpers

    /** @param array<string,mixed> $attributes */
    private function resolveEnds(string $entityType, array $attributes): array
    {
        $statuses = $this->statusesFor($entityType)->keyBy('id');

        $toId = (int) ($attributes['to_status_id'] ?? 0);
        $to = $statuses->get($toId);

        // Scoped lookup, so an id belonging to another company or another register is simply not
        // found (§75, §84) rather than being trusted because it arrived in a form.
        if ($to === null) {
            throw new \DomainException('That destination status does not belong to this register.');
        }

        $fromId = $attributes['from_status_id'] ?? null;
        $from = null;

        if ($fromId !== null && $fromId !== '' && $fromId !== 'any') {
            $from = $statuses->get((int) $fromId);

            if ($from === null) {
                throw new \DomainException('That starting status does not belong to this register.');
            }

            if ((int) $from->getKey() === (int) $to->getKey()) {
                throw new \DomainException('A move has to go somewhere — pick two different statuses.');
            }
        }

        return [$from, $to];
    }

    private function transitionExists(string $entityType, ?int $fromId, int $toId): bool
    {
        return StatusTransition::query()
            ->forEntity($entityType)
            ->where('to_status_id', $toId)
            ->where(fn ($q) => $fromId === null ? $q->whereNull('from_status_id') : $q->where('from_status_id', $fromId))
            ->exists();
    }

    private function keyExists(string $entityType, string $key): bool
    {
        return Status::query()->forEntity($entityType)->where('key', $key)->exists();
    }

    private function normaliseKey(string $key, string $fallback): string
    {
        $source = trim($key) !== '' ? $key : $fallback;

        return Str::of($source)->slug('_')->limit(64, '')->toString();
    }

    /** Only design-system tokens reach a class attribute; anything else becomes neutral (§84). */
    private function tone(mixed $color): string
    {
        return in_array($color, ['neutral', 'brand', 'success', 'warning', 'danger'], true)
            ? (string) $color
            : 'neutral';
    }

    private function nextSort(string $entityType): int
    {
        return ((int) Status::query()->forEntity($entityType)->max('sort')) + 10;
    }

    private function nextTransitionSort(string $entityType): int
    {
        return ((int) StatusTransition::query()->forEntity($entityType)->max('sort')) + 10;
    }

    /** Exactly one status per register is the one new records start in. */
    private function makeInitial(Status $status): void
    {
        Status::query()
            ->forEntity($status->entity_type)
            ->where('id', '!=', $status->getKey())
            ->where('is_initial', true)
            ->update(['is_initial' => false]);

        if (! $status->is_initial) {
            $status->is_initial = true;
            $status->save();
        }
    }

    /**
     * Is any record sitting in this status?
     *
     * Asked of the table the module key names, exactly as StatusPolicy asks it. A module with no
     * table yet has no records in it.
     */
    private function isInUse(Status $status): bool
    {
        $table = Str::plural((string) $status->entity_type);

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'status_id')) {
            return false;
        }

        return DB::table($table)->where('status_id', $status->getKey())->exists();
    }

    private function describe(?Status $from, Status $to): string
    {
        return ($from === null ? 'a move from anywhere' : 'a move from '.$from->label).' to '.$to->label;
    }

    private function registerName(string $entityType): string
    {
        return Str::of($entityType)->replace('_', ' ')->title()->toString();
    }

    private function cleanPermission(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
