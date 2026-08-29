<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Access\ActorProfile;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\Status;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Everything that happens when a project is created, changed or closed (§13, §14).
 *
 * This class exists so that "create a project" means the same thing whether it came from the
 * create form, the seeder, an import or a future API (§90). Four things happen every time, and no
 * caller is trusted to remember them:
 *
 *   1. The reference number is issued by the numbering engine, never typed by a person and never
 *      derived from the record's attributes afterwards.
 *   2. The opening status comes from the configured state machine, so a company that renamed
 *      "Planning" to "Tender" gets its own word without anyone editing PHP.
 *   3. The manager is placed on the team. Without this the person accountable for the job cannot
 *      see it unless somebody separately remembers to staff them, and "the project manager cannot
 *      open their own project" is the single most common way a permissions system loses trust.
 *   4. The timeline gets a sentence, not a diff.
 *
 * What this class does NOT do is authorize anything. Callers have already been through the policy;
 * a service that re-decides access invites the day when one caller forgets and the service's own
 * check is the only one — quietly weaker than the five gates.
 */
class ProjectService
{
    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly StatusMachine $statuses,
        private readonly ActivityRecorder $activity,
        private readonly ActorProfile $profile,
    ) {}

    /**
     * @param  array<string,mixed>  $attributes  already validated and already stripped of anything
     *                                           the actor may not write
     */
    public function create(array $attributes, User $actor): Project
    {
        return DB::transaction(function () use ($attributes, $actor) {
            $project = new Project($attributes);

            // Tenant, number, status and authorship are the service's to set. A caller that could
            // pass its own organization_id would only need one missed validator to write into
            // another company (§74).
            $project->organization_id = $actor->organization_id;
            $project->created_by = $actor->id;
            $project->number = $this->numbers->next('project');
            $project->status_id ??= $this->statuses->initialFor('project')?->id;
            $project->is_active = $attributes['is_active'] ?? true;
            $project->save();

            $this->seatManager($project, $actor);

            $this->activity->record(
                $project,
                'created',
                sprintf('%s opened project %s', $actor->name, $project->displayName()),
                ['number' => $project->number],
                $actor,
                (int) $project->getKey(),
            );

            // The actor may have just staffed themselves; the memoized project list is stale.
            $this->profile->flush();

            return $project->refresh();
        });
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function update(Project $project, array $attributes, User $actor): Project
    {
        // Neither of these is ever a form field. The number is permanent by design, and the tenant
        // is decided at creation; unsetting them here means a crafted POST cannot reach them even
        // if a future form request grows careless.
        unset($attributes['number'], $attributes['organization_id'], $attributes['created_by']);

        return DB::transaction(function () use ($project, $attributes, $actor) {
            $project->fill($attributes);
            $changed = array_keys($project->getDirty());

            if ($changed === []) {
                return $project;
            }

            $project->save();

            // A handover puts the incoming manager on the team, for the same reason creation does.
            // It does not remove the outgoing one: their membership is a separate decision, and
            // silently cutting a predecessor's access mid-handover is how work goes missing.
            if (in_array('manager_id', $changed, true)) {
                $this->seatManager($project, $actor);
                $this->profile->flush();
            }

            $this->activity->record(
                $project,
                'updated',
                sprintf('%s updated %s', $actor->name, $project->displayName()),
                ['fields' => $changed],
                $actor,
                (int) $project->getKey(),
            );

            return $project->refresh();
        });
    }

    /**
     * Move a project to a new status, through the configured whitelist.
     *
     * A thin pass-through on purpose: the rules live in StatusMachine so that a project, a task and
     * an NCR all move the same way, and a company can add a status to one of them without a
     * developer (§63).
     *
     * @throws \DomainException when the move is not defined or not permitted
     */
    public function transition(Project $project, Status $to, User $actor, ?string $comment = null): Project
    {
        $this->statuses->transition($project, $to, $actor, $comment);

        // A terminal status is what "finished" means, and the actual end date is a fact about the
        // work rather than a second thing for someone to remember to type.
        if ($project->refresh()->isClosed() && $project->actual_end === null) {
            $project->actual_end = now()->toDateString();
            $project->saveQuietly();
        }

        return $project;
    }

    /**
     * Archive rather than delete (§14).
     *
     * A finished project keeps its documents, its correspondence and its audit history; what it
     * stops doing is appearing in pickers and dashboards. `archived_at` is the flag every list
     * filters on, and it is reversible, which deletion is not.
     */
    public function archive(Project $project, User $actor): Project
    {
        if ($project->archived_at !== null) {
            return $project;
        }

        $project->archived_at = now();
        $project->is_active = false;
        $project->save();

        $this->activity->record(
            $project,
            'archived',
            sprintf('%s archived %s', $actor->name, $project->displayName()),
            [],
            $actor,
            (int) $project->getKey(),
        );

        return $project;
    }

    public function unarchive(Project $project, User $actor): Project
    {
        if ($project->archived_at === null) {
            return $project;
        }

        $project->archived_at = null;
        $project->is_active = true;
        $project->save();

        $this->activity->record(
            $project,
            'restored',
            sprintf('%s reopened %s', $actor->name, $project->displayName()),
            [],
            $actor,
            (int) $project->getKey(),
        );

        return $project;
    }

    /**
     * Put the manager on the team, idempotently.
     *
     * Membership is what grants project-axis reach, and the manager's authority over the job is
     * meaningless if they cannot open it. Written directly rather than through ProjectTeamService
     * because there is nothing to decide: the actor has just been allowed to name this person as
     * manager, which is the same commitment as staffing them.
     *
     * firstOrCreate rather than create, because a manager already on the team as an engineer must
     * not collide with the unique key on (project_id, user_id) — and their existing role, dates and
     * responsibilities are somebody's deliberate record, not something to overwrite.
     */
    private function seatManager(Project $project, User $actor): void
    {
        $managerId = $project->manager_id !== null ? (int) $project->manager_id : null;

        if ($managerId === null) {
            return;
        }

        $employeeId = DB::table('employees')
            ->where('organization_id', $project->organization_id)
            ->where('user_id', $managerId)
            ->whereNull('deleted_at')
            ->value('id');

        $leadRoleId = ProjectRole::query()
            ->where('is_active', true)
            ->where('is_lead', true)
            ->orderBy('sort')
            ->value('id');

        $project->members()->firstOrCreate(
            ['user_id' => $managerId],
            [
                'organization_id' => $project->organization_id,
                'employee_id' => $employeeId !== null ? (int) $employeeId : null,
                'project_role_id' => $leadRoleId !== null ? (int) $leadRoleId : null,
                'org_unit_id' => $project->org_unit_id,
                'responsibilities' => 'Overall delivery of the project.',
                'starts_on' => $project->planned_start,
                'added_by' => $actor->id,
                'is_active' => true,
            ],
        );
    }
}
