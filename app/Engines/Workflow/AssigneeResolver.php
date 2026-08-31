<?php

declare(strict_types=1);

namespace App\Engines\Workflow;

use App\Engines\Access\ActorProfile;
use App\Models\Employee;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Support\Enums\AssigneeType;
use Illuminate\Database\Eloquent\Model;

/**
 * Turns "the head of the department that owns this record" into a list of user ids (§19, docs/07 §3).
 *
 * This is the class that makes workflows survive people leaving. A stage stores a RELATIONSHIP;
 * this resolves it against live organisation data at the moment the stage is entered. When Sarah
 * leaves, nothing breaks and nobody edits fourteen workflows — the next record routes to whoever
 * now holds the position.
 *
 * TWO PROPERTIES THE CALLER DEPENDS ON:
 *
 *   1. It returns a RESOLUTION, not a bare array — the ids plus a sentence explaining how they were
 *      chosen. That sentence is stored on the stage instance and shown in the approval history,
 *      because "why is this in my inbox" must be answerable without reading the configuration.
 *
 *   2. An empty result is a legitimate, reportable outcome, not an exception. The engine turns it
 *      into a STALLED instance with the explanation attached. A workflow that silently skips the
 *      approval it could not find anybody for is far more dangerous than one that halts and asks
 *      for help.
 *
 * Only ACTIVE, LOGIN-CAPABLE users are ever returned. Routing an approval to a disabled account is
 * a document that waits forever for somebody who cannot open it.
 */
class AssigneeResolver
{
    /** Custom resolvers, registered by key. Never a class name from configuration. */
    private array $custom = [];

    public function __construct(private readonly ActorProfile $profile) {}

    /**
     * Register a tenant-specific rule under a stable key.
     *
     * The key is what `assignee_ref` holds for AssigneeType::Custom. A class name in a
     * configuration column would let anybody with the workflow builder instantiate arbitrary
     * classes, which is remote code execution with extra steps.
     *
     * @param  callable(Model):array<int,int>  $resolver
     */
    public function register(string $key, callable $resolver): void
    {
        $this->custom[$key] = $resolver;
    }

    /**
     * The keys of every registered custom rule.
     *
     * The builder lists these so `assignee_ref` for a custom stage is chosen rather than typed. A
     * mistyped key resolves to nobody and stalls the route days later, which is a poor way to learn
     * that the key was `qa_panel` and not `qa-panel`.
     *
     * @return array<int,string>
     */
    public function keys(): array
    {
        return array_keys($this->custom);
    }

    /**
     * Who does this stage go to, for this record?
     *
     * @return array{ids:array<int,int>,note:string}
     */
    public function resolve(WorkflowStage $stage, Model $subject): array
    {
        $type = $stage->assigneeType();
        $ref = $stage->assignee_ref;

        [$ids, $note] = match ($type) {
            AssigneeType::User => $this->fromUser($ref),
            AssigneeType::Role => $this->fromRole($ref),
            AssigneeType::OrgUnit => $this->fromOrgUnit($ref),
            AssigneeType::OrgUnitHead => $this->fromUnitHead($this->orgUnitIdOf($subject)),
            AssigneeType::ParentUnitHead => $this->fromParentUnitHead($this->orgUnitIdOf($subject)),
            AssigneeType::ProjectManager => $this->fromProjectManager($this->projectIdOf($subject)),
            AssigneeType::ProjectRole => $this->fromProjectRole($this->projectIdOf($subject), $ref),
            AssigneeType::DepartmentHeadOfProject => $this->fromProjectDepartmentHead($this->projectIdOf($subject)),
            AssigneeType::Supervisor => $this->fromSupervisor($this->ownerIdOf($subject)),
            AssigneeType::ManagerChain => $this->fromManagerChain($this->ownerIdOf($subject), (int) ($ref ?? 1)),
            AssigneeType::RecordOwner => $this->fromRecordOwner($this->ownerIdOf($subject)),
            AssigneeType::RecordField => $this->fromRecordField($subject, $ref),
            AssigneeType::Custom => $this->fromCustom($subject, $ref),
        };

        $ids = $this->onlyUsableAccounts($ids);

        /*
         * SELF-APPROVAL. Removed here, at resolution, rather than checked at action time — so the
         * frozen list itself never contains the author, the inbox never shows them a decision they
         * cannot make, and a quorum of three does not silently become a quorum of two-plus-you.
         *
         * Removing the author can empty the list, and that stalls the instance. That is the right
         * outcome: a stage whose only possible approver is the person who wrote the thing is a
         * broken configuration, and it should surface as a stall somebody investigates rather than
         * as an approval that quietly went nowhere.
         */
        if (! $stage->allow_self_approval) {
            $ownerId = $this->ownerIdOf($subject);
            $before = count($ids);
            $ids = array_values(array_filter($ids, fn (int $id) => $id !== $ownerId));

            if (count($ids) < $before) {
                $note .= ' (the record owner was excluded: this stage does not permit self-approval)';
            }
        }

        return ['ids' => $ids, 'note' => mb_substr($note, 0, 300)];
    }

    // ------------------------------------------------------------------ the thirteen types

    /** @return array{0:array<int,int>,1:string} */
    private function fromUser(?string $ref): array
    {
        $id = (int) $ref;

        return [$id > 0 ? [$id] : [], 'Routed to a specific named person.'];
    }

    /** @return array{0:array<int,int>,1:string} */
    private function fromRole(?string $ref): array
    {
        $role = Role::query()->whereKey((int) $ref)->first();

        if ($role === null) {
            return [[], 'The configured role no longer exists.'];
        }

        $ids = $role->users()->pluck('users.id')->map(fn ($id) => (int) $id)->all();

        return [$ids, 'Routed to everyone holding the role "'.$role->name.'".'];
    }

    /** @return array{0:array<int,int>,1:string} */
    private function fromOrgUnit(?string $ref): array
    {
        $unitId = (int) $ref;
        $unit = OrgUnit::query()->whereKey($unitId)->first();

        if ($unit === null) {
            return [[], 'The configured department no longer exists.'];
        }

        // The subtree, not just the unit: "send it to Engineering" means the discipline sections
        // too, which is how everybody outside the org chart understands the sentence.
        $ids = $this->usersOfUnits($this->profile->subtreeOf($unitId));

        return [$ids, 'Routed to everyone in '.$unit->name.' and its sub-departments.'];
    }

    /** @return array{0:array<int,int>,1:string} */
    private function fromUnitHead(?int $orgUnitId): array
    {
        if ($orgUnitId === null) {
            return [[], 'This record belongs to no department, so no department head could be found.'];
        }

        $unit = OrgUnit::query()->with('head.user')->whereKey($orgUnitId)->first();

        if ($unit === null) {
            return [[], 'The record’s department no longer exists.'];
        }

        /*
         * WALKING UP. A section with no head of its own is normal — small teams sit under a
         * manager one level up — so an unheaded unit defers to its parent rather than stalling.
         * Bounded at ten levels because a cycle in the org chart must not become an infinite loop
         * in the approval engine.
         */
        $current = $unit;
        $climbed = 0;

        while ($current !== null && $climbed < 10) {
            $userId = $current->head?->user_id;

            if ($userId !== null) {
                $suffix = $climbed === 0 ? '' : ' (found by looking up from '.$unit->name.')';

                return [[(int) $userId], 'Routed to the head of '.$current->name.'.'.$suffix];
            }

            $current = $current->parent_id === null
                ? null
                : OrgUnit::query()->with('head')->whereKey($current->parent_id)->first();
            $climbed++;
        }

        return [[], 'No department head is recorded for '.$unit->name.' or any department above it.'];
    }

    /** @return array{0:array<int,int>,1:string} */
    private function fromParentUnitHead(?int $orgUnitId): array
    {
        if ($orgUnitId === null) {
            return [[], 'This record belongs to no department, so no parent department could be found.'];
        }

        $unit = OrgUnit::query()->whereKey($orgUnitId)->first();

        if ($unit?->parent_id === null) {
            return [[], 'The record’s department has no parent department.'];
        }

        return $this->fromUnitHead((int) $unit->parent_id);
    }

    /** @return array{0:array<int,int>,1:string} */
    private function fromProjectManager(?int $projectId): array
    {
        if ($projectId === null) {
            return [[], 'This record is not attached to a project, so it has no project manager.'];
        }

        $project = Project::query()->whereKey($projectId)->first();

        if ($project?->manager_id === null) {
            return [[], 'No manager is recorded on the project.'];
        }

        return [[(int) $project->manager_id], 'Routed to the manager of '.$project->displayName().'.'];
    }

    /** @return array{0:array<int,int>,1:string} */
    private function fromProjectRole(?int $projectId, ?string $ref): array
    {
        if ($projectId === null) {
            return [[], 'This record is not attached to a project, so a project role cannot be resolved.'];
        }

        $ids = ProjectMember::query()
            ->current()
            ->where('project_id', $projectId)
            ->where('project_role_id', (int) $ref)
            ->pluck('user_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        return [$ids, 'Routed to whoever holds the configured role on this record’s project.'];
    }

    /** @return array{0:array<int,int>,1:string} */
    private function fromProjectDepartmentHead(?int $projectId): array
    {
        if ($projectId === null) {
            return [[], 'This record is not attached to a project.'];
        }

        $project = Project::query()->whereKey($projectId)->first();

        if ($project?->org_unit_id === null) {
            return [[], 'The project is not filed under any department.'];
        }

        return $this->fromUnitHead((int) $project->org_unit_id);
    }

    /** @return array{0:array<int,int>,1:string} */
    private function fromSupervisor(?int $ownerId): array
    {
        if ($ownerId === null) {
            return [[], 'This record has no owner, so no line manager could be found.'];
        }

        $employee = Employee::query()->with('supervisor')->where('user_id', $ownerId)->first();
        $supervisorUserId = $employee?->supervisor?->user_id;

        if ($supervisorUserId === null) {
            return [[], 'No line manager is recorded for the record owner.'];
        }

        return [[(int) $supervisorUserId], 'Routed to the record owner’s line manager.'];
    }

    /** @return array{0:array<int,int>,1:string} */
    private function fromManagerChain(?int $ownerId, int $depth): array
    {
        if ($ownerId === null) {
            return [[], 'This record has no owner, so the reporting line could not be walked.'];
        }

        $depth = max(1, min($depth, 10));
        $employee = Employee::query()->where('user_id', $ownerId)->first();

        if ($employee === null) {
            return [[], 'The record owner has no employee record, so the reporting line is unknown.'];
        }

        // chainUpward() already bounds itself against cycles; taking the nth element rather than
        // looping here keeps one definition of "up the reporting line" in the system.
        $chain = $employee->chainUpward($depth);
        $target = $chain[$depth - 1] ?? null;
        $userId = $target?->user_id;

        if ($userId === null) {
            return [[], 'The reporting line does not reach '.$depth.' level(s) above the record owner.'];
        }

        return [[(int) $userId], 'Routed '.$depth.' level(s) up the reporting line from the record owner.'];
    }

    /** @return array{0:array<int,int>,1:string} */
    private function fromRecordOwner(?int $ownerId): array
    {
        return [
            $ownerId === null ? [] : [$ownerId],
            'Routed back to the person who owns the record.',
        ];
    }

    /**
     * A user id carried on the record itself, e.g. `reviewed_by`.
     *
     * Whitelisted through the same workflowConditionFields() list a condition uses, and required to
     * end in `_id` or `_by`. Without that, `assignee_ref` would be a free-text attribute read on an
     * arbitrary model — which is how a configuration field turns into a data-disclosure primitive.
     *
     * @return array{0:array<int,int>,1:string}
     */
    private function fromRecordField(Model $subject, ?string $ref): array
    {
        $field = trim((string) $ref);
        $allowed = method_exists($subject, 'workflowConditionFields') ? $subject->workflowConditionFields() : [];

        if ($field === '' || ! in_array($field, $allowed, true) || ! preg_match('/(_id|_by)$/', $field)) {
            return [[], 'The configured field is not one this record exposes to workflows.'];
        }

        $value = $subject->getAttribute($field);

        return [
            $value === null ? [] : [(int) $value],
            'Routed to the person named in the record’s "'.str_replace('_', ' ', $field).'" field.',
        ];
    }

    /** @return array{0:array<int,int>,1:string} */
    private function fromCustom(Model $subject, ?string $ref): array
    {
        $resolver = $this->custom[(string) $ref] ?? null;

        if ($resolver === null) {
            return [[], 'The configured custom rule "'.(string) $ref.'" is not registered.'];
        }

        return [
            array_values(array_map('intval', $resolver($subject))),
            'Routed by the custom rule "'.(string) $ref.'".',
        ];
    }

    // ------------------------------------------------------------------ helpers

    private function ownerIdOf(Model $subject): ?int
    {
        return method_exists($subject, 'workflowOwnerId') ? $subject->workflowOwnerId() : null;
    }

    private function orgUnitIdOf(Model $subject): ?int
    {
        return method_exists($subject, 'workflowOrgUnitId') ? $subject->workflowOrgUnitId() : null;
    }

    private function projectIdOf(Model $subject): ?int
    {
        return method_exists($subject, 'workflowProjectId') ? $subject->workflowProjectId() : null;
    }

    /**
     * @param  array<int,int>  $unitIds
     * @return array<int,int>
     */
    private function usersOfUnits(array $unitIds): array
    {
        if ($unitIds === []) {
            return [];
        }

        return Employee::query()
            ->active()
            ->whereIn('org_unit_id', $unitIds)
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Drop duplicates, and drop anybody who could not act even if asked.
     *
     * Runs a single query rather than calling canLogIn() per id, because a stage routed to a
     * hundred-person department would otherwise be a hundred queries at the moment a document is
     * submitted.
     *
     * @param  array<int,int>  $ids
     * @return array<int,int>
     */
    private function onlyUsableAccounts(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id) => $id > 0)));

        if ($ids === []) {
            return [];
        }

        return User::query()
            ->whereKey($ids)
            ->active()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
