<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Access\ActorProfile;
use App\Engines\Audit\ActivityRecorder;
use App\Models\Project;
use App\Models\ProjectDepartment;
use App\Models\ProjectMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Staffing a project — the §13 cascade, and the write side of the project visibility axis.
 *
 * The cascade has four steps, and only the last one grants anything:
 *
 *   1. A project is assigned to departments  -> tells department heads there is work for them
 *   2. A department head sees it on their list -> because they can already see their department
 *   3. The head assigns their own people      -> a member row per person
 *   4. Those people can now see the project   -> ActorProfile reads exactly those rows
 *
 * Step 1 grants NO visibility by itself. Attaching Structural Engineering to a project does not
 * let the structural team read it; it lets their head decide who works on it. Skipping that
 * distinction would make "assign the department" a silent bulk grant, which is the opposite of
 * what a department head is being asked to do.
 *
 * Every write here changes who can see what, so the memoized actor profile is flushed afterwards.
 * Within a single request that matters: a controller that adds a member and then renders a list
 * must not render it against the reach the request started with.
 */
class ProjectTeamService
{
    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly ActorProfile $profile,
    ) {}

    // ------------------------------------------------------------------ members

    /**
     * @param  array<string,mixed>  $attributes  validated; `user_id` has already been checked as a
     *                                           person this actor may commit (ProjectMemberPolicy)
     */
    public function addMember(Project $project, array $attributes, User $actor): ProjectMember
    {
        return DB::transaction(function () use ($project, $attributes, $actor) {
            $userId = (int) $attributes['user_id'];

            // The employee record is derived, never posted. It is a convenience for reporting —
            // "who from Structural is on this?" — and letting a form name it would allow a member
            // row that says one person and points at another's employment.
            $attributes['employee_id'] = $this->employeeIdFor($project, $userId);
            $attributes['organization_id'] = $project->organization_id;
            $attributes['added_by'] = $actor->id;
            $attributes['is_active'] = $attributes['is_active'] ?? true;

            /** @var ProjectMember $member */
            $member = $project->members()->create($attributes);

            $this->activity->record(
                $member,
                'member_added',
                sprintf(
                    '%s added %s to %s',
                    $actor->name,
                    $member->user?->name ?? 'a team member',
                    $project->displayName(),
                ),
                array_filter([
                    'user_id' => $userId,
                    'role' => $member->projectRole?->name,
                ]),
                $actor,
                (int) $project->getKey(),
            );

            $this->profile->flush();

            return $member;
        });
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function updateMember(ProjectMember $member, array $attributes, User $actor): ProjectMember
    {
        // Moving a membership row to a different person or a different project is not an edit; it
        // is a removal and an addition, each of which the policy asks a different question about.
        unset($attributes['user_id'], $attributes['project_id'], $attributes['organization_id']);

        $member->loadMissing(['user', 'project']);

        return DB::transaction(function () use ($member, $attributes, $actor) {
            $member->fill($attributes);
            $changed = array_keys($member->getDirty());

            if ($changed === []) {
                return $member;
            }

            $member->save();

            $this->activity->record(
                $member,
                'member_updated',
                sprintf(
                    '%s updated %s’s assignment on %s',
                    $actor->name,
                    $member->user?->name ?? 'a team member',
                    $member->project?->displayName() ?? 'the project',
                ),
                ['fields' => $changed],
                $actor,
                (int) $member->project_id,
            );

            $this->profile->flush();

            return $member;
        });
    }

    /**
     * Take someone off the team.
     *
     * The row is deleted rather than deactivated. Both revoke reach, but a deleted row leaves
     * (project_id, user_id) free so the same person can be brought back later without colliding
     * with the unique key, and the history of the assignment survives in audit_logs and in the
     * activity timeline — which is where a company actually looks for it.
     *
     * To END an assignment while keeping it on the record, set `ends_on` instead: the membership
     * stops granting reach the day after, with no action from anyone (§12).
     */
    public function removeMember(ProjectMember $member, User $actor): void
    {
        $member->loadMissing(['user', 'project']);

        $project = $member->project;
        $name = $member->user?->name ?? 'a team member';

        DB::transaction(function () use ($member, $project, $name, $actor) {
            $projectId = (int) $member->project_id;

            $member->delete();

            $this->activity->record(
                $project ?? $member,
                'member_removed',
                sprintf(
                    '%s removed %s from %s',
                    $actor->name,
                    $name,
                    $project?->displayName() ?? 'the project',
                ),
                ['user_id' => (int) $member->user_id],
                $actor,
                $projectId,
            );

            $this->profile->flush();
        });
    }

    // ------------------------------------------------------------------ departments

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function attachDepartment(Project $project, array $attributes, User $actor): ProjectDepartment
    {
        return DB::transaction(function () use ($project, $attributes, $actor) {
            $attributes['organization_id'] = $project->organization_id;
            $attributes['assigned_by'] = $actor->id;
            $attributes['is_active'] = $attributes['is_active'] ?? true;

            /** @var ProjectDepartment $assignment */
            $assignment = $project->departments()->create($attributes);

            $this->activity->record(
                $assignment,
                'department_assigned',
                sprintf(
                    '%s assigned %s to %s',
                    $actor->name,
                    $assignment->orgUnit?->name ?? 'a department',
                    $project->displayName(),
                ),
                ['org_unit_id' => (int) $assignment->org_unit_id],
                $actor,
                (int) $project->getKey(),
            );

            return $assignment;
        });
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function updateDepartment(ProjectDepartment $assignment, array $attributes, User $actor): ProjectDepartment
    {
        unset($attributes['project_id'], $attributes['org_unit_id'], $attributes['organization_id']);

        $assignment->loadMissing(['orgUnit', 'project']);

        $assignment->fill($attributes);
        $changed = array_keys($assignment->getDirty());

        if ($changed === []) {
            return $assignment;
        }

        $assignment->save();

        $this->activity->record(
            $assignment,
            'department_updated',
            sprintf(
                '%s updated %s’s role on %s',
                $actor->name,
                $assignment->orgUnit?->name ?? 'a department',
                $assignment->project?->displayName() ?? 'the project',
            ),
            ['fields' => $changed],
            $actor,
            (int) $assignment->project_id,
        );

        return $assignment;
    }

    /**
     * Unassign a department.
     *
     * Deliberately leaves the individual member rows alone. The people from that department who
     * are actually working on the project keep working on it; the company has said the department
     * as a unit is no longer responsible, which is a different statement from "remove these six
     * people from a job they are mid-way through".
     */
    public function detachDepartment(ProjectDepartment $assignment, User $actor): void
    {
        $assignment->loadMissing(['orgUnit', 'project']);

        $project = $assignment->project;
        $unitName = $assignment->orgUnit?->name ?? 'a department';
        $projectId = (int) $assignment->project_id;

        $assignment->delete();

        $this->activity->record(
            $project ?? $assignment,
            'department_unassigned',
            sprintf(
                '%s unassigned %s from %s',
                $actor->name,
                $unitName,
                $project?->displayName() ?? 'the project',
            ),
            [],
            $actor,
            $projectId,
        );
    }

    /**
     * The employee record behind an account, within this tenant.
     *
     * Null is a legitimate answer: a client-side reviewer or an external consultant may hold an
     * account without appearing on the payroll, and they can still be on a project team.
     */
    private function employeeIdFor(Project $project, int $userId): ?int
    {
        $id = DB::table('employees')
            ->where('organization_id', $project->organization_id)
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->value('id');

        return $id !== null ? (int) $id : null;
    }
}
