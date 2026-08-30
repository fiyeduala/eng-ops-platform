<?php

declare(strict_types=1);

namespace App\Engines\Notification;

use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Who a notification is aimed at (docs/10 §2).
 *
 * The word to hold on to is AIMED. An audience is a question about intent — "the project team",
 * "whoever heads that department" — and answering it produces candidates, never recipients. The
 * difference is the whole security model: NotificationDispatcher takes this list and removes every
 * person who cannot `view` the subject before a single channel is touched (§68). Nothing in this
 * class checks a permission, and it must stay that way, because the moment an audience starts
 * filtering for access there are two places that decide who may be told and they will diverge.
 *
 * Rules are collected rather than executed as they are added, for two reasons. Composition —
 * `Audience::project($p)->orRole('Document Controller')` — becomes trivial. And resolution runs in
 * one place, at one moment, which is what lets it de-duplicate honestly: a project manager who is
 * also on the distribution list and also follows the record is one person, and being named three
 * times must not produce three emails.
 *
 * Resolution deliberately returns USER ids, not employees. A notification is delivered to an
 * account; an employee without one has nowhere to receive it, and silently dropping them here is
 * correct — the alternative is a queue full of undeliverable rows.
 */
final class Audience
{
    /** @var array<int,array{0:string,1:mixed,2:mixed}> */
    private array $rules = [];

    /** @var array<int,int> user ids removed after everything else resolves */
    private array $excluded = [];

    private function __construct() {}

    public static function none(): self
    {
        return new self;
    }

    // ------------------------------------------------------------------ named audiences

    /**
     * Specific people.
     *
     * @param  int|User|iterable<int,int|User>  $users
     */
    public static function user(int|User|iterable $users): self
    {
        return self::none()->orUser($users);
    }

    /** Everybody holding a role, by slug. */
    public static function role(string $slug): self
    {
        return self::none()->orRole($slug);
    }

    /**
     * Everybody employed in a unit — by default including the units beneath it, because "tell
     * Engineering" almost never means "tell the four people whose row says Engineering and not the
     * sixty in its sections".
     */
    public static function orgUnit(OrgUnit|int $unit, bool $includeSubtree = true): self
    {
        return self::none()->orOrgUnit($unit, $includeSubtree);
    }

    /** The project team: current members, plus the manager and sponsor. */
    public static function project(Project|int $project): self
    {
        return self::none()->orProject($project);
    }

    /** The people a project is staffed with FOR a given department. */
    public static function projectDepartment(Project|int $project, OrgUnit|int $unit): self
    {
        return self::none()->orProjectDepartment($project, $unit);
    }

    /** The people holding one project role on one project — "the QA lead on Alpha". */
    public static function projectRole(Project|int $project, string $slug): self
    {
        return self::none()->orProjectRole($project, $slug);
    }

    /** Whoever subscribed to this record, for whatever reason. */
    public static function followers(Model $record): self
    {
        return self::none()->orFollowers($record);
    }

    /** The person this user reports to, one rung up the configurable reporting line (§86). */
    public static function supervisorOf(User|int $user): self
    {
        return self::none()->orSupervisorOf($user);
    }

    /** Whoever heads this unit. */
    public static function headOf(OrgUnit|int $unit): self
    {
        return self::none()->orHeadOf($unit);
    }

    /**
     * Everybody in the company with an account.
     *
     * Guarded at the calling end, not here: only `announcement.broadcast` — in practice
     * `announcement.publish` — should ever reach for this, because it is the one audience whose
     * size is the entire tenant. The dispatcher still filters it, so the worst a misuse produces is
     * a notification to people who could already see the record.
     */
    public static function organization(): self
    {
        return self::none()->orOrganization();
    }

    // ------------------------------------------------------------------ composition

    /** @param  int|User|iterable<int,int|User>  $users */
    public function orUser(int|User|iterable $users): self
    {
        $ids = [];

        foreach (is_iterable($users) ? $users : [$users] as $user) {
            $id = $user instanceof User ? (int) $user->getKey() : (int) $user;

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $this->add('users', $ids);
    }

    public function orRole(string $slug): self
    {
        return $this->add('role', $slug);
    }

    public function orOrgUnit(OrgUnit|int $unit, bool $includeSubtree = true): self
    {
        return $this->add('org_unit', $unit instanceof OrgUnit ? (int) $unit->getKey() : $unit, $includeSubtree);
    }

    public function orProject(Project|int $project): self
    {
        return $this->add('project', $project instanceof Project ? (int) $project->getKey() : $project);
    }

    public function orProjectDepartment(Project|int $project, OrgUnit|int $unit): self
    {
        return $this->add(
            'project_department',
            $project instanceof Project ? (int) $project->getKey() : $project,
            $unit instanceof OrgUnit ? (int) $unit->getKey() : $unit,
        );
    }

    public function orProjectRole(Project|int $project, string $slug): self
    {
        return $this->add(
            'project_role',
            $project instanceof Project ? (int) $project->getKey() : $project,
            $slug,
        );
    }

    public function orFollowers(Model $record): self
    {
        return $this->add('followers', $record->getMorphClass(), (int) $record->getKey());
    }

    public function orSupervisorOf(User|int $user): self
    {
        return $this->add('supervisor_of', $user instanceof User ? (int) $user->getKey() : $user);
    }

    public function orHeadOf(OrgUnit|int $unit): self
    {
        return $this->add('head_of', $unit instanceof OrgUnit ? (int) $unit->getKey() : $unit);
    }

    public function orOrganization(): self
    {
        return $this->add('organization', null);
    }

    /**
     * Take somebody back out.
     *
     * The overwhelmingly common use is `->except($actor)`: telling somebody what they have just
     * done themselves is noise, and enough of it trains people to ignore the bell. It is applied
     * last, so exclusion always wins over inclusion no matter which order the calls came in.
     *
     * @param  int|User|iterable<int,int|User>|null  $users
     */
    public function except(int|User|iterable|null $users): self
    {
        if ($users === null) {
            return $this;
        }

        foreach (is_iterable($users) ? $users : [$users] as $user) {
            $id = $user instanceof User ? (int) $user->getKey() : (int) $user;

            if ($id > 0) {
                $this->excluded[] = $id;
            }
        }

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->rules === [];
    }

    // ------------------------------------------------------------------ resolution

    /**
     * Expand every rule into a de-duplicated list of user ids inside one organization.
     *
     * Queried through the query builder rather than through Eloquent on purpose: the tenant is
     * pinned explicitly on every branch below. Relying on a global scope here would mean an
     * audience resolved from a queued job — which has no bound tenant, and where the scope
     * correctly fails closed — silently resolves to nobody, and a notification that reaches nobody
     * looks exactly like a notification that was correctly filtered.
     *
     * Only accounts that can actually log in survive. A suspended account is not a recipient; its
     * email is very often the reason it was suspended.
     *
     * @return array<int,int>
     */
    public function resolve(int $organizationId): array
    {
        if ($this->rules === []) {
            return [];
        }

        $ids = [];

        foreach ($this->rules as [$type, $a, $b]) {
            $ids = array_merge($ids, match ($type) {
                'users' => $a,
                'role' => $this->fromRole($organizationId, (string) $a),
                'org_unit' => $this->fromOrgUnit($organizationId, (int) $a, (bool) $b),
                'project' => $this->fromProject($organizationId, (int) $a),
                'project_department' => $this->fromProjectDepartment($organizationId, (int) $a, (int) $b),
                'project_role' => $this->fromProjectRole($organizationId, (int) $a, (string) $b),
                'followers' => $this->fromFollowers($organizationId, (string) $a, (int) $b),
                'supervisor_of' => $this->fromSupervisorOf($organizationId, (int) $a),
                'head_of' => $this->fromHeadOf($organizationId, (int) $a),
                'organization' => $this->fromOrganization($organizationId),
                default => [],
            });
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        $ids = array_values(array_diff($ids, array_unique($this->excluded)));

        if ($ids === []) {
            return [];
        }

        // One final pass that also re-pins the tenant. Every branch above already filtered by
        // organization, and this proves it: an id that arrived from an ->orUser() call carrying
        // another company's user is dropped here rather than notified (§75).
        return DB::table('users')
            ->whereIn('id', $ids)
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('locked_until')->orWhere('locked_until', '<=', now()))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    // ------------------------------------------------------------------ internals

    private function add(string $type, mixed $a = null, mixed $b = null): self
    {
        $this->rules[] = [$type, $a, $b];

        return $this;
    }

    /** @return array<int,int> */
    private function fromRole(int $organizationId, string $slug): array
    {
        $today = now()->toDateString();

        return DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->where('roles.organization_id', $organizationId)
            ->where('roles.slug', $slug)
            ->where('roles.is_active', true)
            // A role that lapsed yesterday is not a role you hold today. The same date bounds the
            // access engine applies, applied here, so being notified and being able to open the
            // thing stay in step.
            ->where(fn ($q) => $q->whereNull('role_user.starts_at')->orWhere('role_user.starts_at', '<=', $today))
            ->where(fn ($q) => $q->whereNull('role_user.ends_at')->orWhere('role_user.ends_at', '>=', $today))
            ->pluck('role_user.user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** @return array<int,int> */
    private function fromOrgUnit(int $organizationId, int $unitId, bool $includeSubtree): array
    {
        $unitIds = [$unitId];

        if ($includeSubtree) {
            $path = DB::table('org_units')
                ->where('organization_id', $organizationId)
                ->where('id', $unitId)
                ->value('path');

            if ($path !== null) {
                $unitIds = DB::table('org_units')
                    ->where('organization_id', $organizationId)
                    ->where('path', 'like', $path.'%')
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
            }
        }

        return DB::table('employees')
            ->where('organization_id', $organizationId)
            ->whereIn('org_unit_id', $unitIds)
            ->whereNotNull('user_id')
            ->whereNull('deleted_at')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** @return array<int,int> */
    private function fromProject(int $organizationId, int $projectId): array
    {
        $ids = $this->activeMemberships($organizationId, $projectId)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // The manager and the sponsor are on the project whether or not anybody remembered to add
        // them to the team table. Missing them is the single most common way a project
        // notification quietly fails to reach the person answerable for it.
        $principals = DB::table('projects')
            ->where('organization_id', $organizationId)
            ->where('id', $projectId)
            ->first(['manager_id', 'sponsor_id']);

        foreach ([$principals?->manager_id, $principals?->sponsor_id] as $id) {
            if ($id !== null) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /** @return array<int,int> */
    private function fromProjectDepartment(int $organizationId, int $projectId, int $unitId): array
    {
        return $this->activeMemberships($organizationId, $projectId)
            ->where('project_members.org_unit_id', $unitId)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** @return array<int,int> */
    private function fromProjectRole(int $organizationId, int $projectId, string $slug): array
    {
        return $this->activeMemberships($organizationId, $projectId)
            ->join('project_roles', 'project_roles.id', '=', 'project_members.project_role_id')
            ->where('project_roles.slug', $slug)
            ->pluck('project_members.user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * The same four columns ActorProfile reads for the project visibility axis, in the same order.
     * A membership that has not started or has ended does not reach the project, so it must not
     * receive the project's post either.
     */
    private function activeMemberships(int $organizationId, int $projectId): Builder
    {
        $today = now()->toDateString();

        return DB::table('project_members')
            ->where('project_members.organization_id', $organizationId)
            ->where('project_members.project_id', $projectId)
            ->where('project_members.is_active', true)
            ->where(fn ($q) => $q->whereNull('project_members.starts_on')->orWhere('project_members.starts_on', '<=', $today))
            ->where(fn ($q) => $q->whereNull('project_members.ends_on')->orWhere('project_members.ends_on', '>=', $today));
    }

    /** @return array<int,int> */
    private function fromFollowers(int $organizationId, string $type, int $id): array
    {
        return DB::table('followers')
            ->where('organization_id', $organizationId)
            ->where('followable_type', $type)
            ->where('followable_id', $id)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** @return array<int,int> */
    private function fromSupervisorOf(int $organizationId, int $userId): array
    {
        $supervisorId = DB::table('employees')
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->value('supervisor_id');

        if ($supervisorId === null) {
            return [];
        }

        $account = DB::table('employees')
            ->where('organization_id', $organizationId)
            ->where('id', $supervisorId)
            ->value('user_id');

        return $account === null ? [] : [(int) $account];
    }

    /** @return array<int,int> */
    private function fromHeadOf(int $organizationId, int $unitId): array
    {
        $headEmployeeId = DB::table('org_units')
            ->where('organization_id', $organizationId)
            ->where('id', $unitId)
            ->value('head_employee_id');

        if ($headEmployeeId === null) {
            return [];
        }

        $account = DB::table('employees')
            ->where('organization_id', $organizationId)
            ->where('id', $headEmployeeId)
            ->value('user_id');

        return $account === null ? [] : [(int) $account];
    }

    /** @return array<int,int> */
    private function fromOrganization(int $organizationId): array
    {
        return DB::table('users')
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
