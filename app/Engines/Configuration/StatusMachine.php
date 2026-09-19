<?php

declare(strict_types=1);

namespace App\Engines\Configuration;

use App\Engines\Access\AccessResolver;
use App\Engines\Audit\ActivityRecorder;
use App\Models\Status;
use App\Models\StatusTransition;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Engine 5's state machine (§63).
 *
 * Every operational record in the system carries `status_id` and moves through this class. Three
 * rules, none of which any module gets to reimplement:
 *
 *   1. A move with no `status_transitions` row is refused. The table is a whitelist, so a status
 *      field is a controlled vocabulary rather than a text box that happens to be a dropdown.
 *   2. A transition may name a permission, and that permission is checked with the resolver — the
 *      same one the policies use. "Only QA may mark a task Verified" is configuration, not code.
 *   3. Every accepted move writes an activity entry, so the timeline reads as a history rather
 *      than a diff.
 *
 * What this class deliberately does NOT do is decide whether the actor may touch the record at
 * all. That is the policy's job, and callers authorize() first. Conflating the two would give a
 * transition permission the power to bypass Gates 1, 3 and 5.
 */
class StatusMachine
{
    /** @var array<string,Collection<int,Status>> */
    private array $memo = [];

    public function __construct(
        private readonly AccessResolver $resolver,
        private readonly ActivityRecorder $activity,
    ) {}

    /**
     * Every defined status for a module, in display order.
     *
     * @return Collection<int,Status>
     */
    public function statusesFor(string $entityType): Collection
    {
        return $this->memo[$entityType] ??= Status::query()
            ->forEntity($entityType)
            ->active()
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
    }

    /**
     * The status a newly created record starts in.
     *
     * Falls back to the lowest-sorted status when a company has flagged none, because refusing to
     * create a record over a missing checkbox helps nobody. Returns null only when the module has
     * no statuses at all, which means the tenant was never provisioned.
     */
    public function initialFor(string $entityType): ?Status
    {
        $statuses = $this->statusesFor($entityType);

        return $statuses->firstWhere('is_initial', true) ?? $statuses->first();
    }

    /** @return array<int,string> id => label, for a select */
    public function optionsFor(string $entityType): array
    {
        return $this->statusesFor($entityType)
            ->mapWithKeys(fn (Status $s) => [$s->id => $s->label])
            ->all();
    }

    /**
     * The moves this actor may make from where the record currently is.
     *
     * Rendered as the buttons on a record page — and re-checked in transition(), because a button
     * that is not drawn is not a button that cannot be pressed (§84).
     *
     * @return Collection<int,StatusTransition>
     */
    public function available(string $entityType, ?int $fromStatusId, User $user): Collection
    {
        return StatusTransition::query()
            ->forEntity($entityType)
            ->where(fn ($q) => $q->whereNull('from_status_id')->orWhere('from_status_id', $fromStatusId))
            ->where('to_status_id', '!=', $fromStatusId ?? 0)
            ->with('to')
            ->orderBy('sort')
            ->get()
            ->filter(fn (StatusTransition $t) => $this->permits($t, $user))
            ->values();
    }

    /**
     * Move a record, or explain why not.
     *
     * @param  string|null  $comment  required when the transition demands one
     *
     * @throws \DomainException when the move is not defined, not permitted, or missing a comment
     */
    public function transition(Model $record, Status $to, User $user, ?string $comment = null): void
    {
        $entityType = $this->entityTypeOf($record);
        $from = $record->getAttribute('status_id') !== null
            ? $this->statusesFor($entityType)->firstWhere('id', (int) $record->getAttribute('status_id'))
            : null;

        if ($to->entity_type !== $entityType) {
            throw new \DomainException('That status belongs to a different kind of record.');
        }

        if ($from !== null && (int) $from->id === (int) $to->id) {
            return;   // already there; not an error, and not an event worth recording
        }

        $transition = $this->find($entityType, $from?->id, $to->id);

        if ($transition === null) {
            throw new \DomainException(
                'This record cannot move from '.($from?->label ?? 'no status').' to '.$to->label.'.'
            );
        }

        if (! $this->permits($transition, $user)) {
            throw new \DomainException('You do not have permission to make that change.');
        }

        if ($transition->requires_comment && trim((string) $comment) === '') {
            throw new \DomainException('That change requires a reason.');
        }

        $record->setAttribute('status_id', $to->id);
        $record->save();

        $this->activity->record(
            $record,
            'status_changed',
            $user->name.' moved '.$this->label($record).' from '
                .($from?->label ?? 'no status').' to '.$to->label,
            array_filter([
                'from' => $from?->key,
                'to' => $to->key,
                'comment' => $comment !== null && trim($comment) !== '' ? trim($comment) : null,
            ], fn ($v) => $v !== null),
            $user,
        );
    }

    /**
     * The whitelist lookup. An exact from→to row wins over a "from anywhere" row, so a company
     * can define a general Cancel and still require a comment on the specific path that needs one.
     */
    public function find(string $entityType, ?int $fromStatusId, int $toStatusId): ?StatusTransition
    {
        return StatusTransition::query()
            ->forEntity($entityType)
            ->where('to_status_id', $toStatusId)
            ->where(fn ($q) => $q->where('from_status_id', $fromStatusId)->orWhereNull('from_status_id'))
            ->orderByRaw('CASE WHEN from_status_id IS NULL THEN 1 ELSE 0 END')
            ->first();
    }

    /**
     * Gate 2 for the move itself.
     *
     * A transition with no `permission_key` is open to anyone who may edit the record, which the
     * caller has already established through a policy. A transition WITH one demands it be held
     * at some scope; whether it reaches this particular record was settled by the policy call.
     */
    private function permits(StatusTransition $transition, User $user): bool
    {
        if (blank($transition->permission_key)) {
            return true;
        }

        return $this->resolver->has($user, $transition->permission_key);
    }

    /**
     * The status vocabulary a record belongs to: 'project', 'task', 'meeting'.
     *
     * Normally the model's own permission module, so the vocabulary, the permission catalogue and
     * the numbering scheme all key off one string. `statusEntityType()` exists for the handful of
     * records where those two are deliberately NOT the same word: a form submission is governed by
     * the `form` permissions — the same authority that built the form governs what is filled in on
     * it — but has its own statuses, because "Draft" on a blank inspection sheet and "Draft" on the
     * inspection form itself are different facts about different things. Without the hook the
     * machine would hunt for submission statuses under 'form' and find a form's.
     */
    private function entityTypeOf(Model $record): string
    {
        if (method_exists($record, 'statusEntityType')) {
            return $record::statusEntityType();
        }

        return method_exists($record, 'permissionModule')
            ? $record::permissionModule()
            : str(class_basename($record))->snake()->toString();
    }

    private function label(Model $record): string
    {
        return method_exists($record, 'displayName')
            ? $record->displayName()
            : class_basename($record).' #'.$record->getKey();
    }

    public function flush(): void
    {
        $this->memo = [];
    }
}
