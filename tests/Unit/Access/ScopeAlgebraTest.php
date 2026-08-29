<?php

declare(strict_types=1);

namespace Tests\Unit\Access;

use App\Engines\Access\ScopeGrant;
use App\Engines\Access\ScopeSet;
use App\Support\Enums\AccessScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The scope algebra, tested without a database.
 *
 * Everything downstream — the compiled SQL, every policy, every index page — is a projection of
 * the rules asserted here. They are worth testing in isolation because a mistake in them is
 * invisible at the call site: a wrong `subsumes()` does not throw, it silently drops a predicate,
 * and the page still renders. It just renders more rows than it should.
 *
 * The claim under test throughout is the two-axis model of docs/04-access-model.md section 5:
 * `project` is NOT a rung on the organizational ladder. It is a second, independent axis, and the
 * two combine by union.
 */
final class ScopeAlgebraTest extends TestCase
{
    // ------------------------------------------------------------------ the ladder

    #[Test]
    public function the_organizational_ladder_is_strictly_ordered(): void
    {
        $ladder = [
            AccessScope::Own,
            AccessScope::Assigned,
            AccessScope::Team,
            AccessScope::Section,
            AccessScope::Department,
            AccessScope::Division,
            AccessScope::Organization,
        ];

        // Asserted pairwise rather than by sorting: a duplicate rank would survive a sort-based
        // check, and two scopes sharing a rank would make `broadest()` return whichever it saw
        // first — a silent, order-dependent widening.
        foreach ($ladder as $i => $lower) {
            foreach (array_slice($ladder, $i + 1) as $higher) {
                $this->assertLessThan(
                    $higher->rank(),
                    $lower->rank(),
                    $lower->value.' must rank below '.$higher->value,
                );
            }
        }
    }

    #[Test]
    public function project_is_not_on_the_organizational_ladder(): void
    {
        // Rank 0 and isOrganizational() false are the two halves of the same statement, and both
        // are load-bearing: rank keeps `broadest()` from treating project as a rung,
        // isOrganizational keeps `broadestOrganizational()` from reporting it as one.
        $this->assertSame(0, AccessScope::Project->rank());
        $this->assertFalse(AccessScope::Project->isOrganizational());

        foreach (AccessScope::cases() as $case) {
            if ($case !== AccessScope::Project) {
                $this->assertTrue($case->isOrganizational(), $case->value.' belongs to the ladder');
            }
        }
    }

    #[Test]
    public function comparing_the_two_axes_is_refused_rather_than_answered(): void
    {
        // The heart of it. There is no "broadest" of `project` and `department` — neither
        // contains the other — so any value returned here would be a silent lie in one direction
        // or the other. An engineer granted `own` on their timesheets and `project` on their
        // project's must keep both; a collapse to `own` would leave them unable to see the work
        // they were hired for, and nothing would report it.
        //
        // Both real callers partition by axis before comparing. This asserts the guard that
        // makes that a checked precondition instead of a convention.
        foreach (AccessScope::cases() as $case) {
            if ($case === AccessScope::Project) {
                continue;
            }

            foreach ([[AccessScope::Project, $case], [$case, AccessScope::Project]] as [$a, $b]) {
                try {
                    $result = AccessScope::broadest($a, $b);
                    $this->fail(
                        'broadest('.$a->value.', '.$b->value.') collapsed two axes into '.$result->value
                        .' instead of refusing.',
                    );
                } catch (\InvalidArgumentException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }

    #[Test]
    public function two_scopes_on_the_same_axis_still_compare(): void
    {
        // The guard must not have made the useful case unreachable.
        $this->assertSame(AccessScope::Division, AccessScope::broadest(AccessScope::Team, AccessScope::Division));
        $this->assertSame(AccessScope::Division, AccessScope::broadest(AccessScope::Division, AccessScope::Team));
        $this->assertSame(AccessScope::Project, AccessScope::broadest(AccessScope::Project, AccessScope::Project));
    }

    #[Test]
    public function only_the_four_unit_scopes_walk_the_tree(): void
    {
        // orgUnitType() is what ActorProfile uses to find the anchor to walk up to. A scope that
        // wrongly reported a type would be resolved against a subtree it has no business seeing.
        $this->assertSame('team', AccessScope::Team->orgUnitType()?->value);
        $this->assertSame('section', AccessScope::Section->orgUnitType()?->value);
        $this->assertSame('department', AccessScope::Department->orgUnitType()?->value);
        $this->assertSame('division', AccessScope::Division->orgUnitType()?->value);

        foreach ([AccessScope::Own, AccessScope::Assigned, AccessScope::Project, AccessScope::Organization] as $case) {
            $this->assertNull($case->orgUnitType(), $case->value.' must not resolve to a unit subtree');
        }
    }

    #[Test]
    public function every_scope_is_described_in_words_an_administrator_can_act_on(): void
    {
        // The role editor shows these labels and nothing else. An unlabelled scope would appear
        // in the dropdown as its raw value, and somebody would pick it without knowing what it
        // does. §33: configuration screens must explain themselves.
        foreach (AccessScope::cases() as $case) {
            $this->assertNotSame('', trim($case->label()));
            $this->assertNotSame($case->value, $case->label());
        }

        $this->assertSame(count(AccessScope::cases()), count(AccessScope::options()));
    }

    // ------------------------------------------------------------------ subsumption

    /**
     * @param  array<int,mixed>  $outer
     * @param  array<int,mixed>  $inner
     */
    #[Test]
    #[DataProvider('subsumptionCases')]
    public function subsumption_only_ever_drops_a_redundant_predicate(array $outer, array $inner, bool $expected, string $because): void
    {
        $this->assertSame(
            $expected,
            (new ScopeGrant(...$outer))->subsumes(new ScopeGrant(...$inner)),
            $because,
        );
    }

    /** @return array<string,array{0:array,1:array,2:bool,3:string}> */
    public static function subsumptionCases(): array
    {
        return [
            'organization covers everything' => [
                [AccessScope::Organization], [AccessScope::Own], true,
                'organization-wide reach makes every narrower predicate redundant',
            ],
            'organization covers the project axis too' => [
                [AccessScope::Organization], [AccessScope::Project], true,
                'seeing the whole company already includes every project inside it',
            ],
            'a broader rung covers a narrower one' => [
                [AccessScope::Division], [AccessScope::Team], true,
                'a division subtree contains the team subtree beneath it',
            ],
            'a narrower rung does not cover a broader one' => [
                [AccessScope::Team], [AccessScope::Division], false,
                'dropping the division predicate would hide rows the user may see',
            ],
            'a rung never covers the project axis' => [
                [AccessScope::Division], [AccessScope::Project], false,
                'the project a user works on need not sit inside their own division',
            ],
            'the project axis never covers a rung' => [
                [AccessScope::Project], [AccessScope::Department], false,
                'department records are not confined to the projects the user is on',
            ],
            'project covers project' => [
                [AccessScope::Project], [AccessScope::Project], true,
                'two unpinned project grants are the same predicate twice',
            ],
            'a pinned grant covers nothing' => [
                [AccessScope::Organization, 7], [AccessScope::Own], false,
                'a pin is a narrowing; a pinned grant can never stand in for an unpinned one',
            ],
            'equal rungs cover each other' => [
                [AccessScope::Department], [AccessScope::Department], true,
                'the same predicate twice is one predicate',
            ],
        ];
    }

    #[Test]
    public function a_pinned_grant_is_distinct_from_the_same_grant_unpinned(): void
    {
        $unpinned = new ScopeGrant(AccessScope::Department);
        $toUnitSeven = new ScopeGrant(AccessScope::Department, pinnedOrgUnitId: 7);
        $toProjectSeven = new ScopeGrant(AccessScope::Department, pinnedProjectId: 7);

        // Three different signatures, or a set would de-duplicate a real narrowing away — and
        // the unit pin and the project pin must not collide on the shared id 7.
        $this->assertCount(3, array_unique([
            $unpinned->signature(),
            $toUnitSeven->signature(),
            $toProjectSeven->signature(),
        ]));

        $this->assertFalse($unpinned->isPinned());
        $this->assertTrue($toUnitSeven->isPinned());
        $this->assertTrue($toProjectSeven->isPinned());
    }

    // ------------------------------------------------------------------ the set

    #[Test]
    public function an_empty_set_means_the_permission_is_not_held(): void
    {
        // Read by AccessResolver::has(). If an empty set were ever truthy the whole model would
        // fail open, which is the one failure mode that must be impossible.
        $set = ScopeSet::empty();

        $this->assertTrue($set->isEmpty());
        $this->assertFalse($set->isNotEmpty());
        $this->assertFalse($set->hasOrganizationWide());
        $this->assertNull($set->broadestOrganizational());
        $this->assertSame([], $set->all());
    }

    #[Test]
    public function identical_grants_from_two_roles_collapse_to_one(): void
    {
        $set = new ScopeSet([
            new ScopeGrant(AccessScope::Department),
            new ScopeGrant(AccessScope::Department),
        ]);

        $this->assertCount(1, $set->all());
    }

    #[Test]
    public function the_broadest_organizational_scope_wins_across_roles(): void
    {
        // Someone holding a role at `team` and another at `division` reaches the division. The
        // union is the point: revoking the narrower role must not be how you widen someone.
        $set = new ScopeSet([
            new ScopeGrant(AccessScope::Team),
            new ScopeGrant(AccessScope::Division),
            new ScopeGrant(AccessScope::Own),
        ]);

        $this->assertSame(AccessScope::Division, $set->broadestOrganizational());
    }

    #[Test]
    public function the_project_axis_does_not_answer_for_the_organizational_one(): void
    {
        $set = new ScopeSet([new ScopeGrant(AccessScope::Project)]);

        // Holding only `project` means no organizational reach at all — not "own", not the
        // user's unit. Returning any rung here would hand a project consultant a slice of the
        // company org chart they were never granted.
        $this->assertNull($set->broadestOrganizational());
        $this->assertTrue($set->isNotEmpty());
        $this->assertTrue($set->has(AccessScope::Project));
    }

    #[Test]
    public function organization_wide_is_only_true_when_the_grant_is_unpinned(): void
    {
        // A "Regional Director" role granted at organization scope but PINNED to one division is
        // not organization-wide. If hasOrganizationWide() said otherwise the compiler would take
        // its fast path and emit no predicate at all — the pin would evaporate and the pinning
        // would become decorative.
        $pinned = new ScopeSet([new ScopeGrant(AccessScope::Organization, pinnedOrgUnitId: 3)]);
        $unpinned = new ScopeSet([new ScopeGrant(AccessScope::Organization)]);

        $this->assertFalse($pinned->hasOrganizationWide());
        $this->assertTrue($unpinned->hasOrganizationWide());
    }

    #[Test]
    public function effective_drops_redundant_grants_but_never_all_of_them(): void
    {
        $set = new ScopeSet([
            new ScopeGrant(AccessScope::Own),
            new ScopeGrant(AccessScope::Team),
            new ScopeGrant(AccessScope::Division),
        ]);

        $effective = $set->effective();

        $this->assertCount(1, $effective);
        $this->assertSame(AccessScope::Division, $effective[0]->scope);
    }

    #[Test]
    public function effective_keeps_both_axes(): void
    {
        // The union in its compiled form. Reducing this to one grant is precisely the bug the
        // two-axis model exists to prevent, and it is the assertion most likely to catch a
        // well-meaning "simplification" of subsumes().
        $set = new ScopeSet([
            new ScopeGrant(AccessScope::Department),
            new ScopeGrant(AccessScope::Project),
        ]);

        $scopes = array_map(fn (ScopeGrant $g) => $g->scope, $set->effective());

        $this->assertContains(AccessScope::Department, $scopes);
        $this->assertContains(AccessScope::Project, $scopes);
        $this->assertCount(2, $scopes);
    }

    #[Test]
    public function effective_keeps_a_pin_that_reaches_somewhere_a_broader_grant_does_not(): void
    {
        // `department` in one's own unit, plus `organization` pinned to a different division:
        // neither contains the other, so both predicates have to survive into the SQL.
        $set = new ScopeSet([
            new ScopeGrant(AccessScope::Department),
            new ScopeGrant(AccessScope::Organization, pinnedOrgUnitId: 42),
        ]);

        $this->assertCount(2, $set->effective());
    }

    #[Test]
    public function effective_never_returns_an_empty_list(): void
    {
        // An OR-group with no branches is a WHERE that matches everything, so "reduced away to
        // nothing" is the one result effective() must never produce.
        $set = new ScopeSet([new ScopeGrant(AccessScope::Department)]);

        $this->assertNotSame([], $set->effective());
    }

    #[Test]
    public function merging_two_sets_takes_the_union(): void
    {
        $a = new ScopeSet([new ScopeGrant(AccessScope::Own)]);
        $b = new ScopeSet([new ScopeGrant(AccessScope::Project), new ScopeGrant(AccessScope::Own)]);

        $this->assertCount(2, $a->merge($b)->all());
    }

    #[Test]
    public function a_set_survives_the_cache_round_trip_unchanged(): void
    {
        // AccessResolver stores the array form in the cache and rebuilds from it on every
        // subsequent request, so a lossy round trip would mean a user's reach quietly differs
        // between a cold request and a warm one — the hardest class of bug to reproduce.
        $original = new ScopeSet([
            new ScopeGrant(AccessScope::Department),
            new ScopeGrant(AccessScope::Project, pinnedProjectId: 9),
            new ScopeGrant(AccessScope::Organization, pinnedOrgUnitId: 4),
        ]);

        $rebuilt = ScopeSet::fromArray($original->toArray());

        $this->assertSame(
            array_map(fn (ScopeGrant $g) => $g->signature(), $original->all()),
            array_map(fn (ScopeGrant $g) => $g->signature(), $rebuilt->all()),
        );
    }
}
