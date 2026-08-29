<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Engines\Access\AccessResolver;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\Role;
use App\Models\User;
use App\Support\Enums\OrgUnitType;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * Gate 4: exceptions to the rules, on a single record.
 *
 * Two things have to be true at once and they pull in opposite directions.
 *
 *   An explicit ALLOW is what "share this drawing with the client's reviewer" has to mean, so it
 *   must reach past a scope that would otherwise refuse.
 *
 *   An explicit DENY is what "everyone in Engineering except Sam" has to mean, so it must survive
 *   a grant broad enough to have covered Sam twice over — including the organization owner's.
 *
 * Every assertion below is made twice, once against the policy and once against the compiled
 * list. An ACL honoured on the record page but not in the query is not an exception, it is a
 * discrepancy: the record still appears in the listing that names it.
 */
final class RecordAclTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $civil;

    private OrgUnit $mechanical;

    /** Someone in Mechanical: outside a Civil viewer's reach by every ordinary rule. */
    private Employee $outsider;

    /** Someone in Civil: inside it. */
    private Employee $colleague;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Meridian Engineering');

        $engineering = $this->makeUnit($this->org, 'Engineering', OrgUnitType::Division);
        $this->civil = $this->makeUnit($this->org, 'Civil', OrgUnitType::Department, $engineering);
        $this->mechanical = $this->makeUnit($this->org, 'Mechanical', OrgUnitType::Department, $engineering);

        $this->outsider = $this->employeeFor($this->makePerson($this->org, 'Ines Bakare', $this->mechanical));
        $this->colleague = $this->employeeFor($this->makePerson($this->org, 'Tobias Renner', $this->civil));
    }

    // ------------------------------------------------------------------ allow

    #[Test]
    public function an_explicit_allow_reaches_a_record_no_scope_would_have_reached(): void
    {
        $viewer = $this->departmentViewer('Civil Lead');

        $this->assertCannotSee($viewer, $this->outsider, 'before the share');

        $this->grant($viewer, $this->outsider, 'employee.view', 'allow');

        $this->assertCanSee($viewer, $this->outsider, 'after the share');
    }

    #[Test]
    public function an_allow_addressed_to_a_role_reaches_everyone_holding_it(): void
    {
        // The form most shares actually take: "the QA team can see this", not "these four people".
        $role = $this->makeRole($this->org, 'Reviewers', ['employee.view' => 'own']);

        $first = $this->makePerson($this->org, 'Priya Raman', $this->civil, [$role]);
        $second = $this->makePerson($this->org, 'Kofi Mensah', $this->civil, [$role]);

        $this->assertCannotSee($first, $this->outsider, 'before the share');

        $this->grantTo('role', $role->id, $this->outsider, 'employee.view', 'allow');

        $this->assertCanSee($first, $this->outsider, 'the role was granted access');
        $this->assertCanSee($second, $this->outsider, 'a second holder of the same role');
    }

    #[Test]
    public function an_allow_does_not_confer_a_permission_the_user_has_never_held(): void
    {
        // Gate 2 still runs. Sharing a personnel record for viewing must not hand the recipient
        // the ability to EDIT personnel records — an ability they were never granted anywhere,
        // and which a wildcard ACL row would otherwise quietly create out of nothing.
        $viewer = $this->departmentViewer('Civil Lead');

        $this->grantTo('user', $viewer->id, $this->outsider, '*', 'allow');

        $this->assertCanSee($viewer, $this->outsider, 'the wildcard covers viewing');
        $this->assertFalse(
            Gate::forUser($viewer)->allows('update', $this->outsider),
            'a wildcard share granted an ability the recipient does not hold at any scope',
        );
    }

    #[Test]
    public function an_allow_is_specific_to_the_ability_it_names(): void
    {
        // A viewer who also holds employee.edit at a narrow scope. Being shared the record for
        // VIEWING must not carry the edit along with it — otherwise every "let them look at this"
        // silently becomes "let them change it" for anyone who edits anything.
        $viewer = $this->departmentViewer('Civil Lead');
        $this->attach($viewer, $this->makeRole($this->org, 'Civil Editor', ['employee.edit' => 'department']));

        $this->grant($viewer, $this->outsider, 'employee.view', 'allow');

        $this->assertCanSee($viewer, $this->outsider, 'the share names employee.view');
        $this->assertFalse(
            Gate::forUser($viewer)->allows('update', $this->outsider),
            'a view-only share also lifted the edit',
        );
    }

    #[Test]
    public function an_expired_allow_is_inert(): void
    {
        // Time-bounded shares are how a contractor's access ends without anybody remembering to
        // revoke it. An expiry that was only decorative would be worse than no expiry at all,
        // because it is the reason nobody checks.
        $viewer = $this->departmentViewer('Civil Lead');

        $this->grant($viewer, $this->outsider, 'employee.view', 'allow', now()->subDay());

        $this->assertCannotSee($viewer, $this->outsider, 'the share expired yesterday');
    }

    // ------------------------------------------------------------------ deny

    #[Test]
    public function an_explicit_deny_beats_a_scope_broad_enough_to_have_covered_it(): void
    {
        $viewer = $this->departmentViewer('Civil Lead');

        $this->assertCanSee($viewer, $this->colleague, 'an ordinary colleague in the same department');

        $this->grant($viewer, $this->colleague, 'employee.view', 'deny');

        $this->assertCannotSee($viewer, $this->colleague, 'after the deny');
    }

    #[Test]
    public function an_explicit_deny_beats_organization_wide_reach(): void
    {
        // The case that decides whether Gate 4a runs before or after Gate 2. A deny that a broad
        // enough role could out-vote would not be a deny — and organization-wide reach is also
        // the compiler's fast path, the one place a predicate is easiest to forget.
        $viewer = $this->makePerson(
            $this->org,
            'Chief Engineer',
            $this->civil,
            [$this->makeRole($this->org, 'Executive', ['employee.view' => 'organization'])],
        );

        $this->grant($viewer, $this->colleague, 'employee.view', 'deny');

        $this->assertCannotSee($viewer, $this->colleague, 'organization-wide reach must not out-vote a deny');
        $this->assertCanSee($viewer, $this->outsider, 'the deny is one record, not a blanket');
    }

    #[Test]
    public function a_deny_beats_an_allow_arriving_by_another_route(): void
    {
        // The realistic conflict, and the only one the schema permits: the record is shared with
        // a ROLE the viewer holds, and the viewer is then denied it personally. Two rows, two
        // grantees, one person — which is exactly "share this with QA, except Sam".
        //
        // A single grantee cannot hold both effects for one ability: the unique key on
        // (record_type, record_id, grantee_type, grantee_id, ability) makes that unrepresentable
        // rather than merely discouraged, which is the right place to settle a contradiction.
        $role = $this->makeRole($this->org, 'Reviewers', ['employee.view' => 'own']);
        $viewer = $this->makePerson($this->org, 'Sam Iyer', $this->civil, [$role]);

        $this->grantTo('role', $role->id, $this->outsider, 'employee.view', 'allow');
        $this->assertCanSee($viewer, $this->outsider, 'the role was shared the record');

        $this->grant($viewer, $this->outsider, 'employee.view', 'deny');
        $this->assertCannotSee($viewer, $this->outsider, 'a personal deny must override a role-level allow');
    }

    #[Test]
    public function one_grantee_cannot_hold_both_effects_for_the_same_ability(): void
    {
        // Asserted rather than assumed. The precedence rule in ScopedPolicy only has to settle
        // conflicts BETWEEN grantees; a row pair that contradicted itself would leave the answer
        // depending on which the query happened to read first, and this is what stops it existing.
        $viewer = $this->departmentViewer('Civil Lead');

        $this->grant($viewer, $this->outsider, 'employee.view', 'allow');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->grant($viewer, $this->outsider, 'employee.view', 'deny');
    }

    #[Test]
    public function a_deny_addressed_to_the_users_unit_still_applies_to_them(): void
    {
        $viewer = $this->departmentViewer('Civil Lead');

        $this->grantTo('org_unit', $this->civil->id, $this->colleague, 'employee.view', 'deny');

        $this->assertCannotSee($viewer, $this->colleague, 'a deny addressed to the viewer’s own unit');
    }

    #[Test]
    public function an_expired_deny_stops_denying(): void
    {
        $viewer = $this->departmentViewer('Civil Lead');

        $this->grant($viewer, $this->colleague, 'employee.view', 'deny', now()->subDay());

        $this->assertCanSee($viewer, $this->colleague, 'the deny expired yesterday');
    }

    // ------------------------------------------------------------------ the boundaries an ACL cannot cross

    #[Test]
    public function an_allow_cannot_reach_across_organizations(): void
    {
        // Gate 1 runs before Gate 4 and nothing below it can override it. This is the assertion
        // that keeps a mis-typed or malicious ACL row from becoming a tenant breach (§75): even a
        // wildcard allow, written directly into the table, buys nothing across the boundary.
        $other = $this->makeOrganization('Kestrel Facilities');
        $otherUnit = $this->makeUnit($other, 'Kestrel Head Office', OrgUnitType::Department);
        $stranger = $this->employeeFor($this->makePerson($other, 'Rhona Calder', $otherUnit), $other);

        $viewer = $this->departmentViewer('Civil Lead');

        $this->grantTo('user', $viewer->id, $stranger, '*', 'allow', organization: $this->org->id);

        $this->assertFalse(
            Gate::forUser($viewer)->allows('view', $stranger),
            'an ACL row reached across a tenant boundary',
        );

        $this->assertNotContains(
            $stranger->id,
            $this->reach($viewer),
            'an ACL row put another organization’s record into a list',
        );
    }

    #[Test]
    public function an_allow_grants_nothing_once_the_tenant_is_suspended(): void
    {
        $viewer = $this->departmentViewer('Civil Lead');
        $this->grant($viewer, $this->outsider, 'employee.view', 'allow');

        $this->assertCanSee($viewer, $this->outsider, 'while the tenant is live');

        $this->context()->withoutScope(fn () => Organization::whereKey($this->org->id)->update(['is_active' => false]));
        app(AccessResolver::class)->flushMemo();

        $this->assertCannotSee($viewer, $this->outsider, 'a share must not outlive the tenant');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Both halves of the same question. A record the policy opens but the query omits — or the
     * reverse — is the failure mode this whole design exists to make impossible, so no assertion
     * here checks only one of them.
     */
    private function assertCanSee(User $viewer, Employee $record, string $because): void
    {
        $viewer = $this->refresh($viewer);

        $this->assertTrue(
            Gate::forUser($viewer)->allows('view', $record),
            'the policy refused the record — '.$because,
        );

        $this->assertContains(
            $record->id,
            $this->reach($viewer),
            'the record is openable but absent from the list — '.$because,
        );
    }

    private function assertCannotSee(User $viewer, Employee $record, string $because): void
    {
        $viewer = $this->refresh($viewer);

        $this->assertFalse(
            Gate::forUser($viewer)->allows('view', $record),
            'the policy allowed the record — '.$because,
        );

        $this->assertNotContains(
            $record->id,
            $this->reach($viewer),
            'the record is refused but still listed — '.$because,
        );
    }

    private function departmentViewer(string $name): User
    {
        return $this->makePerson(
            $this->org,
            $name,
            $this->civil,
            [$this->makeRole($this->org, $name.' Role', ['employee.view' => 'department'])],
        );
    }

    private function grant(User $user, Employee $record, string $ability, string $effect, ?\DateTimeInterface $expires = null): void
    {
        $this->grantTo('user', $user->id, $record, $ability, $effect, $expires);
    }

    private function grantTo(
        string $granteeType,
        int $granteeId,
        Employee $record,
        string $ability,
        string $effect,
        ?\DateTimeInterface $expires = null,
        ?int $organization = null,
    ): void {
        // Written straight to the table. There is no controller for record permissions until the
        // document module lands in Phase 3, and the point of these tests is the ENGINE — a fixture
        // routed through a form would be testing the form.
        DB::table('record_permissions')->insert([
            'organization_id' => $organization ?? $record->organization_id,
            'record_type' => $record->getMorphClass(),
            'record_id' => $record->id,
            'grantee_type' => $granteeType,
            'grantee_id' => $granteeId,
            'ability' => $ability,
            'effect' => $effect,
            'expires_at' => $expires?->format('Y-m-d H:i:s'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(AccessResolver::class)->flushMemo();
    }

    private function attach(User $user, Role $role): void
    {
        $this->withinOrganization($this->org, function () use ($user, $role) {
            $user->roles()->attach($role->id, ['scope_type' => null, 'scope_id' => null]);
            $this->org->bumpPermissionsVersion();
        });
    }

    /** @return array<int,int> */
    private function reach(User $viewer): array
    {
        return $this->withinOrganization(
            $this->org,
            fn () => Employee::visibleTo($viewer, 'employee.view')->pluck('id')->map(intval(...))->all(),
        );
    }

    private function employeeFor(User $user, ?Organization $within = null): Employee
    {
        // The context has to be the record's OWN organization, not the test's default one. Gate 1
        // is a global scope: reading a Kestrel employee with Meridian bound finds nothing, and the
        // fixture would fail for the very reason the test is trying to demonstrate.
        return $this->withinOrganization(
            $within ?? $this->org,
            fn () => Employee::where('user_id', $user->id)->firstOrFail(),
        );
    }

    private function refresh(User $user): User
    {
        app(AccessResolver::class)->flushMemo();

        return $this->withinOrganization(
            $this->org,
            fn () => User::with(['roles', 'employee'])->findOrFail($user->id),
        );
    }
}
