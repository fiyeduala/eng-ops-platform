<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\ReportDefinition;
use App\Models\Role;
use App\Models\SavedFilter;
use App\Models\User;
use App\Models\WorkflowStageInstance;
use App\Support\Enums\OrgUnitType;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * A department moves to another division, and everything that depended on where it was moves with
 * it (§74).
 *
 * WHAT §74 ACTUALLY ASKS. "Reorganization is a `parent_id` update plus a path rebuild" is the
 * mechanism, and `OrgUnitService::move()` has been proven to do it since Phase 2. The requirement
 * is the sentence after it: reporting lines, permissions and dashboards follow automatically,
 * because every one of them derives reach from `path` rather than from a stored copy of the
 * hierarchy. Nothing in the suite had ever checked the "automatically". Each consumer of the org
 * tree — the access compiler, the report runner, saved views, the approvals inbox — was tested
 * against a tree that never moved, so a consumer holding its own copy of the hierarchy would look
 * exactly like one that did not.
 *
 * WHY A MOVE IS THE RIGHT PROBE AND A NEW TREE IS NOT. Build two organizations and the wrong
 * answers are impossible: everything is separate to begin with. Move one department and the
 * dangerous cases appear at once — a cached subtree from before the move, a materialized path
 * rebuilt for the unit but not for its descendants, a saved filter that stored the rows it matched
 * rather than the question it asks, an approval addressed to the division the record used to sit
 * under. All four are silent. All four are a person seeing another division's work.
 *
 * THE MOVE HAPPENS IN THE SAME PROCESS AS THE ASSERTIONS ON EITHER SIDE OF IT. That is deliberate:
 * `ActorProfile::subtreeOf()` memoises, so a reorganization that only comes out right after a
 * restart is the bug this file exists to catch, not a detail of the fixture.
 *
 * AND IT IS DONE THROUGH THE SCREEN. One PUT to `org-units.update` by an administrator with no
 * database access, because §73 and §74 are the same claim about two different acts.
 */
final class ReorganizationWalkthroughTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $engineering;   // division the department starts under

    private OrgUnit $projects;      // division it ends under

    private OrgUnit $civil;         // the department that moves

    private OrgUnit $marine;        // a section UNDER it, so what moves is a subtree

    private Role $manager;

    private User $adaeze;   // administrator: performs the move

    private User $ngozi;    // document controller, Civil: raises the record

    private User $chidi;    // engineering manager, Civil: the in-flight approver

    private User $tunde;    // director standing in Engineering

    private User $yusuf;    // director standing in Projects

    private User $ifeoma;   // engineer in the section: moved without being touched

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Lagos Harbour Works');
        $this->engineering = $this->makeUnit($this->org, 'Engineering', OrgUnitType::Division);
        $this->projects = $this->makeUnit($this->org, 'Projects', OrgUnitType::Division);
        $this->civil = $this->makeUnit($this->org, 'Civil', OrgUnitType::Department, $this->engineering);

        // A level below the unit that moves. `reparent()` rewrites descendant paths in ONE UPDATE
        // rather than walking the tree, and a fixture where the moving unit is a leaf cannot tell a
        // working rewrite from no rewrite at all: the unit's own path is saved separately.
        $this->marine = $this->makeUnit($this->org, 'Marine Works', OrgUnitType::Section, $this->civil);

        // DIVISION scope, and both directors hold the identical role. The only thing that decides
        // what either of them can see is which division the org chart says they stand in — which is
        // precisely the fact this test changes half way through.
        $director = $this->makeRole($this->org, 'Division Director', [
            'document.view' => 'division',
            'report.view' => 'division',
            'report.create' => 'division',
            'saved_filter.view' => 'division',
            'saved_filter.create' => 'division',
            'dashboard.view_department' => 'division',
            // Their own division's chart and no more. This one is load-bearing for step 7: a
            // department board is addressable to whoever can see that department in the ORG CHART
            // (`BoardController::boardableUnits`), because a picker that listed a department the
            // chart never showed would leak the shape of the company through a select box (§61).
            // The figures on the board are filtered again per tile, so this grant decides which
            // boards exist for a reader, not what the numbers on them say.
            'org_unit.view' => 'division',
            // Bounded structural authority, which is the realistic shape of it (§10): a division
            // director reorganizes inside their own division and nowhere else. Test 3 exists
            // because "inside their own division" is TWO questions — the unit being moved and the
            // parent it is moving to — and a check that asked only the first would let this role
            // post a department out of the company's structure into somebody else's (§84).
            'org_unit.edit' => 'division',
            'org_unit.reorganize' => 'division',
        ]);

        $this->manager = $this->makeRole($this->org, 'Engineering Manager', [
            'document.view' => 'department',
            'approval.view' => 'department',
            'approval.act' => 'department',
            'dashboard.view_department' => 'department',
            'org_unit.view' => 'organization',
        ]);

        $administrator = $this->makeRole($this->org, 'System Administrator', [
            'org_unit.view' => 'organization',
            'org_unit.edit' => 'organization',
            'org_unit.reorganize' => 'organization',
        ]);

        $controller = $this->makeRole($this->org, 'Document Controller', [
            'document.view' => 'organization',
            'document.create' => 'organization',
            'document.edit' => 'organization',
            'document.submit' => 'organization',
            'document.manage' => 'organization',
            'document_category.view' => 'organization',
            'org_unit.view' => 'organization',
        ]);

        $this->adaeze = $this->makePerson($this->org, 'Adaeze Okonkwo', $this->engineering, [$administrator]);
        $this->ngozi = $this->makePerson($this->org, 'Ngozi Eze', $this->civil, [$controller]);
        $this->chidi = $this->makePerson($this->org, 'Chidi Nwosu', $this->civil, [$this->manager]);
        $this->tunde = $this->makePerson($this->org, 'Tunde Bello', $this->engineering, [$director]);
        $this->yusuf = $this->makePerson($this->org, 'Yusuf Danladi', $this->projects, [$director]);
        $this->ifeoma = $this->makePerson($this->org, 'Ifeoma Nnaji', $this->marine, [$this->manager]);
    }

    #[Test]
    public function a_department_moves_between_divisions_and_every_reach_that_depended_on_it_moves_too(): void
    {
        // ---- 1. Before. A Civil document belongs to Engineering's director and to nobody else. --
        $stage = $this->submitCivilDocument();
        $report = $this->reportOfDocuments($this->tunde);

        $this->assertReportShows($this->tunde, $report, true, 'Engineering: before the move');
        $this->assertReportShows($this->yusuf, $report, false, 'Projects: before the move');

        // A SHARED saved view, created by the director who can see the rows today. §54 says a
        // shared view is a shared QUESTION, so the test is whether the answer is recomputed per
        // reader — a view that stored its rows would keep showing Civil to Engineering forever.
        $view = $this->saveSharedView($this->tunde, $report);

        $this->assertViewShows($this->tunde, $report, $view, true, 'Engineering: before the move');
        $this->assertViewShows($this->yusuf, $report, $view, false, 'Projects: before the move');

        // ---- 2. The move itself. One screen, one PUT, by somebody with no database access. ------
        $before = $this->within(fn () => $this->civil->fresh());

        $this->moveCivilUnderProjects($this->adaeze)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // ---- 3. The mechanism: parent_id, and the path rebuilt underneath it (docs/04 §57). ----
        $after = $this->within(fn () => $this->civil->fresh());

        $this->assertSame($this->projects->id, (int) $after->parent_id);
        $this->assertNotSame($before->path, $after->path, 'The parent changed and the path did not.');
        $this->assertStringStartsWith(
            '/'.$this->projects->id.'/',
            (string) $after->path,
            'The rebuilt path does not run through the new division.',
        );

        // ---- 4. Reach flips, in this process, with nothing cleared in between. -----------------
        $this->assertReportShows($this->yusuf, $report, true, 'Projects: after the move');
        $this->assertReportShows($this->tunde, $report, false, 'Engineering: after the move');

        // ---- 5. And the saved view is a question, not a stored answer. --------------------------
        $this->assertViewShows($this->yusuf, $report, $view, true, 'Projects: after the move');
        $this->assertViewShows($this->tunde, $report, $view, false, 'Engineering: after the move');

        // ---- 6. The approval that was in flight throughout is still an approval. ----------------
        //
        // Chidi is department-scoped and his department is the one that moved, so nothing about his
        // relationship to this record changed — which is the answer §74 wants, and is not the
        // answer a system that had frozen the hierarchy anywhere would give.
        $this->assertTrue($stage->refresh()->isPending(), 'The reorganization closed an open approval.');
        $this->assertTrue($stage->isAssignedTo($this->chidi));

        $this->actingFresh($this->chidi)
            ->get(route('approvals.index'))
            ->assertOk()
            ->assertSee('Quay wall settlement monitoring');

        $this->actingFresh($this->chidi)
            ->post(route('approvals.decide', $stage), [
                'decision' => 'approve',
                'comment' => 'Monitoring frequency agreed with the marine team.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertFalse($stage->refresh()->isPending(),
            'A department that moved mid-approval could no longer finish it.');

        // ---- 7. And the department board changes hands (§52, §53). -----------------------------
        //
        // Chidi runs the department that moved, so his own board is unaffected — a bare visit lands
        // him on it exactly as before.
        $this->actingFresh($this->chidi)
            ->get(route('boards.department'))
            ->assertRedirect(route('boards.department', $this->civil));

        // The two directors have swapped, and neither of them was re-granted anything: the only
        // fact that changed is which division the chart says Civil sits in. Note the 404 rather
        // than a 403 — a department a reader cannot address does not announce that it exists (§84).
        $this->actingFresh($this->yusuf)
            ->get(route('boards.department', $this->civil))
            ->assertOk()
            ->assertSee('Civil');

        $this->actingFresh($this->tunde)
            ->get(route('boards.department', $this->civil))
            ->assertNotFound();
    }

    #[Test]
    public function the_people_in_the_department_are_reassigned_with_it_rather_than_left_behind(): void
    {
        // Chidi's own division anchor is derived, not stored: he belongs to Civil, and which
        // division that makes him part of is a fact about the tree. A system that had copied his
        // division onto his employee record would answer Engineering here for ever.
        $this->assertSame(
            $this->engineering->id,
            $this->divisionAnchorOf($this->chidi),
            'Chidi did not start under Engineering.',
        );

        $this->moveCivilUnderProjects($this->adaeze)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(
            $this->projects->id,
            $this->divisionAnchorOf($this->chidi),
            'The department moved and the people in it are still reporting to the old division.',
        );

        // And Ifeoma, who is a level further down. Nothing addressed her section: its row was
        // rewritten by the single descendant UPDATE inside `reparent()`, and her division anchor is
        // read back off that path. This is the assertion that fails if the rewrite silently misses
        // the subtree — the department itself would look perfectly moved.
        $this->assertSame(
            $this->projects->id,
            $this->divisionAnchorOf($this->ifeoma),
            'The department moved and the section under it was left pointing at the old division.',
        );

        $this->assertStringStartsWith(
            '/'.$this->projects->id.'/'.$this->civil->id.'/',
            (string) $this->within(fn () => $this->marine->fresh())->path,
            'A descendant path was not rebuilt.',
        );
    }

    #[Test]
    public function a_unit_cannot_be_moved_by_somebody_whose_authority_stops_at_one_end_of_the_move(): void
    {
        // Tunde holds `org_unit.reorganize`, and he holds it over Civil: it is in his division
        // today, so the near end of this move is entirely within his authority. The far end is not.
        // A check against the moving unit alone would answer yes here and hand a department to
        // another division in one request (§84) — which is why `OrgUnitController::update` asks the
        // policy about the new parent as well, and why the policy takes two arguments.
        // First, a move that IS his: Marine Works out of Civil and up to sit beside it, both ends
        // inside Engineering. It goes through, which is what makes the refusal below mean
        // something — this role reorganizes, and the question is only ever where.
        $this->actingFresh($this->tunde)
            ->put(route('org-units.update', $this->marine), [
                'name' => 'Marine Works',
                'type' => OrgUnitType::Section->value,
                'parent_id' => $this->engineering->id,
                'is_active' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(
            $this->engineering->id,
            (int) $this->within(fn () => $this->marine->fresh())->parent_id,
            'A move with both ends inside the division was refused.',
        );

        $this->moveCivilUnderProjects($this->tunde)
            ->assertForbidden();

        $this->assertSame(
            $this->engineering->id,
            (int) $this->within(fn () => $this->civil->fresh())->parent_id,
            'A director moved a department out of his own division.',
        );
    }

    // ------------------------------------------------------------------ the walk's furniture

    /**
     * The move, as the edit screen sends it.
     *
     * EVERY FIELD THE FORM POSTS, including `is_active`, which the browser sends from a hidden
     * input beside the checkbox. Leaving it out does not mean "unchanged" — the request normalises
     * a missing boolean to false, so a partial PUT reads as "reorganized AND deactivated". That is
     * a different act with a different blast radius, and a walkthrough is worth nothing if the
     * request it makes is not the request the screen makes.
     */
    private function moveCivilUnderProjects(User $actor): TestResponse
    {
        return $this->actingFresh($actor)
            ->put(route('org-units.update', $this->civil), [
                'name' => 'Civil',
                'type' => OrgUnitType::Department->value,
                'parent_id' => $this->projects->id,
                'is_active' => '1',
            ]);
    }

    /** The division a person's own unit sits under, as the access engine computes it. */
    private function divisionAnchorOf(User $user): ?int
    {
        return $this->within(function () use ($user) {
            $unit = OrgUnit::query()->whereKey($user->fresh()->employee->org_unit_id)->sole();

            // Read off the materialized path rather than a stored division key, because that is
            // exactly the claim: the tree is the only record of who reports to whom.
            $id = OrgUnit::query()
                ->whereIn('id', $unit->ancestorIds())
                ->where('type', OrgUnitType::Division->value)
                ->value('id');

            return $id === null ? null : (int) $id;
        });
    }

    /**
     * A Civil document, out for approval with the department manager, before anything moves.
     */
    private function submitCivilDocument(): WorkflowStageInstance
    {
        $workflow = $this->makeWorkflow($this->org, $this->ngozi, 'Method statement approval', 'document', [
            [
                'name' => 'Engineering review',
                'sequence' => 1,
                'assignee_type' => 'role',
                'assignee_ref' => (string) $this->manager->id,
                'quorum' => 'any',
            ],
        ]);

        $category = $this->makeDocumentCategory($this->org, 'Method Statements', [
            'requires_approval' => true,
            'workflow_id' => $workflow->id,
        ]);

        $document = $this->makeDocument($this->org, $this->ngozi, 'Quay wall settlement monitoring', [
            'document_category_id' => $category->id,
            'org_unit_id' => $this->civil->id,
        ]);

        $this->makeNativeVersion($this->org, $document, $this->ngozi);

        $this->actingFresh($this->ngozi)
            ->post(route('documents.submit', $document))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $instance = $document->refresh()->currentWorkflowInstance();

        $this->assertNotNull($instance, 'Submitting produced no workflow instance.');

        $stage = WorkflowStageInstance::query()->whereKey($instance->current_stage_instance_id)->first();

        $this->assertNotNull($stage, 'The instance opened no stage.');

        return $stage;
    }

    private function reportOfDocuments(User $author): ReportDefinition
    {
        $this->actingFresh($author)
            ->post(route('configuration.reports.store'), [
                'name' => 'Method statements on file',
                'entity_type' => 'document',
                'columns' => ['number', 'title', 'org_unit_id'],
                'sort_field' => 'created_at',
                'sort_direction' => 'desc',
                'is_active' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        return $this->within(
            fn () => ReportDefinition::query()->where('name', 'Method statements on file')->sole());
    }

    private function saveSharedView(User $author, ReportDefinition $report): SavedFilter
    {
        $this->actingFresh($author)
            ->post(route('configuration.reports.views.store', $report), [
                'name' => 'Everything this division has issued',
                // Company-wide, not department-wide, so that the director in Projects genuinely
                // HOLDS this view and the only thing left to decide what he sees through it is his
                // reach. A department share would make his empty answer the uninteresting kind:
                // he would not have the view at all.
                'visibility' => 'organization',
                // A view has to ask something; this asks for the department that is about to move.
                // Note what it does NOT ask: nothing here mentions a division. Which division's
                // work this turns out to be is the reader's reach applied to the answer, and that
                // is the property the assertions on either side of the move are checking.
                'filters' => [
                    ['field' => 'org_unit_id', 'operator' => '=', 'value' => (string) $this->civil->id],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        return $this->within(
            fn () => SavedFilter::query()->where('name', 'Everything this division has issued')->sole());
    }

    private function assertReportShows(User $user, ReportDefinition $report, bool $expected, string $moment): void
    {
        $response = $this->actingFresh($user)->get(route('configuration.reports.show', $report))->assertOk();

        $this->assertSame(
            $expected,
            str_contains($response->getContent(), 'Quay wall settlement monitoring'),
            $moment.' — the report '.($expected ? 'should' : 'must not').' reach the Civil document.',
        );
    }

    private function assertViewShows(
        User $user,
        ReportDefinition $report,
        SavedFilter $view,
        bool $expected,
        string $moment,
    ): void {
        // `apply` redirects to the ordinary run route with the stored conditions as a query string,
        // so following it is the whole point: a saved view must reach the rows down the same path a
        // typed one does, and be filtered by the reader on the way.
        $response = $this->actingFresh($user)
            ->get(route('configuration.reports.views.apply', [$report, $view]))
            ->assertRedirect();

        $response = $this->actingFresh($user)->get($response->headers->get('Location'))->assertOk();

        $this->assertSame(
            $expected,
            str_contains($response->getContent(), 'Quay wall settlement monitoring'),
            $moment.' — the saved view '.($expected ? 'should' : 'must not').' reach the Civil document.',
        );
    }

    /**
     * Run a closure inside this organization's tenant context.
     *
     * @template T
     *
     * @param  callable():T  $work
     * @return T
     */
    private function within(callable $work): mixed
    {
        return $this->withinOrganization($this->org, $work);
    }
}
