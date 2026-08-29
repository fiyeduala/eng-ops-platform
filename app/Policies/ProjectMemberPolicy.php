<?php

declare(strict_types=1);

namespace App\Policies;

use App\Engines\Access\ActorProfile;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Support\Enums\AccessScope;

/**
 * Project team membership — the most security-sensitive write in Phase 2.
 *
 * A row in `project_members` is a VISIBILITY GRANT (§14). Whoever may create one may widen what
 * another person can see, which makes this policy an authorization boundary rather than a CRUD
 * gate. Two questions have to be answered, and answering only the first is the classic hole:
 *
 *   1. May the actor staff THIS project?   — reach over the project
 *   2. May the actor commit THIS person?   — reach over the candidate
 *
 * Without question 2, a department administrator pinned to one department could add the Managing
 * Director's account to a project and then read everything on it through them; without question 1,
 * anyone holding `project_member.assign` could staff a project they cannot even see.
 */
class ProjectMemberPolicy extends ScopedPolicy
{
    protected string $module = 'project_member';

    /**
     * The catalogue calls these assign/remove/manage, not create/edit/delete, because that is
     * what they are: nobody "creates a membership", they put someone on a job.
     *
     * @var array<string,string>
     */
    protected array $abilityMap = [
        'viewAny' => 'view',
        'view' => 'view',
        'create' => 'assign',
        'update' => 'manage',
        'delete' => 'remove',
        'restore' => 'remove',
        'forceDelete' => 'delete_permanently',
    ];

    /**
     * Question 1 and question 2, together. This is the method controllers must call — plain
     * `create` cannot see either the project or the candidate, so it can only ever answer "does
     * this kind of person assign people at all".
     */
    public function assign(User $user, Project $project, ?User $candidate = null): bool
    {
        if (! $this->check($user, 'create')) {
            return false;
        }

        // Reach over the project, answered by the project's own policy so there is exactly one
        // definition of "projects I can see" in the system.
        if (! app(ProjectPolicy::class)->check($user, 'view', $project)) {
            return false;
        }

        return $candidate === null || $this->canCommit($user, $project, $candidate);
    }

    /**
     * Removing someone REVOKES visibility, so it is deliberately not the mirror of assigning.
     * Reach over the project is required; reach over the person is not — a manager who inherited
     * a team member from a department they cannot see must still be able to take them off the job.
     */
    public function remove(User $user, ProjectMember $member): bool
    {
        return $this->check($user, 'delete', $member);
    }

    /**
     * May this actor put this particular person on this particular project? (docs/05 §6)
     *
     * Three cases, narrowest last:
     *
     *   ORGANIZATION-WIDE — an operations manager or HR: anyone in the tenant.
     *
     *   PROJECT SCOPE ON THIS PROJECT — a project manager. Their authority is the project, not a
     *   slice of the org chart, so they may bring in anyone: that is what running a project means,
     *   and the §13 cascade explicitly has them pulling people from other departments.
     *
     *   ORGANIZATIONAL SUBTREE — a department head. They may commit their own people and nobody
     *   else's, which is the whole point of the cascade: the project asks the department for
     *   people, and the department decides which ones.
     *
     * Anything else is a no. The candidate must also be a usable account in the same tenant —
     * a disabled or foreign account is not a person you can staff.
     */
    protected function canCommit(User $user, Project $project, User $candidate): bool
    {
        if ((int) $candidate->organization_id !== (int) $user->organization_id) {
            return false;
        }

        if ($this->resolver->hasOrganizationWide($user, 'project_member.assign')) {
            return true;
        }

        $scopes = $this->resolver->scopesFor($user, 'project_member.assign');
        $profile = app(ActorProfile::class);

        if ($scopes->has(AccessScope::Project)
            && in_array((int) $project->getKey(), $profile->projectIds($user), true)) {
            return true;
        }

        $broadest = $scopes->broadestOrganizational();

        if ($broadest === null) {
            return false;
        }

        $subtree = $profile->subtreeFor($user, $broadest);
        $candidateUnit = $profile->orgUnitId($candidate);

        return $subtree !== null
            && $candidateUnit !== null
            && in_array($candidateUnit, $subtree, true);
    }
}
