<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\ReportDefinition;
use App\Models\SavedFilter;
use App\Models\User;
use App\Services\ReportDefinitionService;
use App\Services\SavedFilterService;
use App\Support\Enums\OrgUnitType;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * Saved views — a question somebody asks often enough to name (§54, docs/11 §2, Phase 7 Part 6C).
 *
 * THE WHOLE FEATURE RESTS ON ONE PROPERTY: WHAT IS SAVED IS THE QUESTION, NOT THE ANSWER. Sharing a
 * saved view sends conditions, and the person who applies them gets their own rows. If a stored
 * result set ever crept in — a cached row count, a remembered list of ids — sharing would become a
 * way to hand one person's reach to another, which is precisely what §61 exists to stop. Several
 * tests below are therefore about what a shared view does NOT do.
 *
 * THE SECOND PROPERTY IS THAT SHARING ROUTES UPWARD WHILE ACCESS RUNS DOWNWARD, and the two must not
 * be confused. A view shared with Engineering appears for somebody in Civil beneath it, because that
 * is what its author meant by "my department" — but appearing on their screen grants them nothing.
 * `ancestorOrgUnitIds()` decides whose screen a NAME lands on; `visibleTo()` decides which rows come
 * back, and it is asked separately, downward, every single run.
 *
 * THE THIRD IS THAT NOTHING ABOUT THE SHARE IS TAKEN FROM THE REQUEST (§74). Not the owner, not the
 * company, not the department, not the register. Two of the tests here post exactly those fields and
 * assert they are ignored.
 *
 * The fixture extends ReportDefinitionTest's, with one addition — somebody sitting at division level
 * — because a share that only ever reaches the sharer's own department would pass every test in this
 * file while being the wrong routing.
 *
 *   Engineering (div)   Emeka   sits above both departments; may read reports, not build them
 *   Civil (dept)        Ngozi   document controller, Civil only
 *   Piping (dept)       Sade    engineer, Piping only
 *   (no unit)           Tunde   operations director, the whole company, may build reports
 */
final class SavedFilterTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $engineering;

    private OrgUnit $civil;

    private OrgUnit $piping;

    private DocumentCategory $procedures;

    private User $emeka;

    private User $ngozi;

    private User $sade;

    private User $tunde;

    private ReportDefinition $report;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Meridian Engineering');

        $this->engineering = $this->makeUnit($this->org, 'Engineering', OrgUnitType::Division);
        $this->civil = $this->makeUnit($this->org, 'Civil', OrgUnitType::Department, $this->engineering);
        $this->piping = $this->makeUnit($this->org, 'Piping', OrgUnitType::Department, $this->engineering);

        $reader = $this->makeRole($this->org, 'Document Controller', [
            'document.view' => 'department',
            'report.view' => 'organization',
        ]);

        $director = $this->makeRole($this->org, 'Operations Director', [
            'document.view' => 'organization',
            'document.create' => 'organization',
            'report.view' => 'organization',
            'report.create' => 'organization',
            'report.edit' => 'organization',
            'report.delete' => 'organization',
            'report.export' => 'organization',
            'org_unit.view' => 'organization',
        ]);

        $this->emeka = $this->makePerson($this->org, 'Emeka Obi', $this->engineering, [$reader]);
        $this->ngozi = $this->makePerson($this->org, 'Ngozi Eze', $this->civil, [$reader]);
        $this->sade = $this->makePerson($this->org, 'Sade Ajayi', $this->piping, [$reader]);
        $this->tunde = $this->makePerson($this->org, 'Tunde Bello', null, [$director]);

        $this->procedures = $this->makeDocumentCategory($this->org, 'Procedures', [
            'requires_approval' => false,
        ]);

        $this->report = $this->withinOrganization($this->org,
            fn () => app(ReportDefinitionService::class)->create([
                'name' => 'Procedures on file',
                'entity_type' => 'document',
                'columns' => ['title', 'org_unit_id'],
            ], $this->tunde));
    }

    // ================================================================== the question, not the answer

    #[Test]
    public function a_saved_view_keeps_the_conditions_and_nothing_that_came_back(): void
    {
        $this->documentIn($this->civil, 'Excavation procedure');

        $this->save($this->ngozi, 'Civil only')->assertRedirect();

        $filter = SavedFilter::query()->withoutGlobalScopes()->sole();

        $this->assertSame([[
            'field' => 'org_unit_id',
            'operator' => '=',
            'value' => (string) $this->civil->getKey(),
        ]], $filter->filters);

        // The columns this table does NOT have are the point. A stored row count, a list of ids or
        // a rendered result would each turn sharing a view into sharing somebody's reach — so the
        // assertion is made against the schema rather than against a getter that could be added.
        $columns = array_map('strtolower', DB::getSchemaBuilder()->getColumnListing('saved_filters'));

        foreach (['rows', 'results', 'row_count', 'result', 'total', 'record_ids', 'payload'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns,
                'A saved filter must hold conditions only; `'.$forbidden.'` would hold an answer.');
        }
    }

    #[Test]
    public function a_shared_view_does_not_widen_anybodys_reach(): void
    {
        $this->documentIn($this->piping, 'Hydrotest procedure');

        // The director, who can see the whole company, shares a view of Piping's procedures with
        // everybody. Ngozi can see Civil and nothing else.
        $this->save($this->tunde, 'Piping procedures', $this->piping, 'organization')->assertRedirect();

        $filter = SavedFilter::query()->withoutGlobalScopes()->sole();

        $this->actingFresh($this->tunde)
            ->get($this->applyUrl($filter))
            ->assertRedirect();

        // The director gets the row. Ngozi, applying the identical conditions, gets nothing — which
        // is the entire safety argument for letting people share these at all.
        $this->assertSame(1, $this->rowsAfterApplying($this->tunde, $filter));
        $this->assertSame(0, $this->rowsAfterApplying($this->ngozi, $filter));
    }

    #[Test]
    public function applying_a_saved_view_is_an_ordinary_run_of_the_report(): void
    {
        $this->documentIn($this->civil, 'Excavation procedure');

        $this->save($this->ngozi, 'Civil only');

        $filter = SavedFilter::query()->withoutGlobalScopes()->sole();

        $response = $this->actingFresh($this->ngozi)->get($this->applyUrl($filter));

        // A redirect onto the run route with the conditions in the query string, rather than a page
        // rendered here. There is then exactly one code path that turns conditions into rows, and a
        // saved view cannot reach a row a typed condition could not.
        $response->assertRedirect();
        $this->assertStringContainsString('filters', $response->headers->get('Location') ?? '');
        $this->assertStringContainsString('org_unit_id', urldecode($response->headers->get('Location') ?? ''));
    }

    // ================================================================== who sees the name

    #[Test]
    public function a_view_shared_with_a_division_reaches_the_departments_inside_it(): void
    {
        $this->save($this->emeka, 'Engineering procedures', $this->civil, 'org_unit')->assertRedirect();

        $filter = SavedFilter::query()->withoutGlobalScopes()->sole();

        $this->assertSame($this->engineering->getKey(), $filter->org_unit_id);

        // Both departments sit inside Engineering, so both see the name. Routing runs upward from
        // the reader — "which units am I inside" — which is not the direction authorization runs.
        $this->actingFresh($this->ngozi)->get($this->showUrl())->assertOk()->assertSee('Engineering procedures');
        $this->actingFresh($this->sade)->get($this->showUrl())->assertOk()->assertSee('Engineering procedures');

        // The director has no position in the tree at all, so a departmental share is not addressed
        // to him — seniority is not the question a share answers.
        $this->actingFresh($this->tunde)->get($this->showUrl())->assertOk()->assertDontSee('Engineering procedures');
    }

    #[Test]
    public function a_private_view_is_on_one_screen_only(): void
    {
        $this->save($this->ngozi, 'My shortlist');

        $filter = SavedFilter::query()->withoutGlobalScopes()->sole();

        $this->actingFresh($this->ngozi)->get($this->showUrl())->assertOk()->assertSee('My shortlist');
        $this->actingFresh($this->sade)->get($this->showUrl())->assertOk()->assertDontSee('My shortlist');

        // Not merely hidden from the list. The name is guessable and the id is sequential, so the
        // route is asked directly and must refuse (§6, §84).
        $this->actingFresh($this->sade)->get($this->applyUrl($filter))->assertForbidden();
    }

    #[Test]
    public function another_companys_saved_view_does_not_exist(): void
    {
        $other = $this->makeOrganization('Kaduna Fabricators');

        $bilkisu = $this->makePerson($other, 'Bilkisu Sani', null, [
            $this->makeRole($other, 'Admin', [
                'report.view' => 'organization',
                'report.create' => 'organization',
            ]),
        ]);

        $theirs = $this->withinOrganization($other, fn () => app(SavedFilterService::class)->create([
            'name' => 'Their shortlist',
            'entity_type' => 'document',
            'filters' => [['field' => 'org_unit_id', 'operator' => 'is_null', 'value' => null]],
            'visibility' => 'organization',
        ], $bilkisu));

        // Shared with their whole company — which is still not this one. The director holds every
        // report permission there is and that is not an argument either (§75, §94).
        $url = route('configuration.reports.views.apply', [
            'report' => $this->report, 'savedFilter' => $theirs,
        ]);

        $this->actingFresh($this->tunde)->get($url)->assertNotFound();
        $this->actingFresh($this->tunde)->put(str_replace('?', '', $url), ['name' => 'Mine now'])->assertNotFound();
        $this->actingFresh($this->tunde)->delete($url)->assertNotFound();
    }

    // ================================================================== §74, nothing from the request

    #[Test]
    public function the_department_a_view_is_shared_with_is_the_sharers_own(): void
    {
        // Ngozi is in Civil and posts Piping's id, which is a real department in her own company
        // and therefore passes every check a naive `exists` rule would apply.
        $this->actingFresh($this->ngozi)
            ->post(route('configuration.reports.views.store', $this->report), [
                'name' => 'Not yours',
                'visibility' => 'org_unit',
                'org_unit_id' => $this->piping->getKey(),
                'organization_id' => 999,
                'user_id' => $this->tunde->getKey(),
                'entity_type' => 'purchase_order',
                'filters' => [['field' => 'org_unit_id', 'operator' => '=', 'value' => (string) $this->civil->getKey()]],
            ])
            ->assertRedirect();

        $filter = SavedFilter::query()->withoutGlobalScopes()->sole();

        $this->assertSame($this->civil->getKey(), $filter->org_unit_id, 'The share went to the department that was posted.');
        $this->assertSame($this->org->getKey(), $filter->organization_id);
        $this->assertSame($this->ngozi->getKey(), $filter->user_id);

        // And the register is the report's, not the one in the payload — otherwise a view named on
        // a document report would carry conditions meant for purchase orders.
        $this->assertSame('document', $filter->entity_type);
    }

    #[Test]
    public function sharing_with_the_whole_company_is_part_of_building_reports_for_it(): void
    {
        $this->actingFresh($this->ngozi)
            ->post(route('configuration.reports.views.store', $this->report), [
                'name' => 'Everyone sees this',
                'visibility' => 'organization',
                'filters' => [['field' => 'org_unit_id', 'operator' => '=', 'value' => (string) $this->civil->getKey()]],
            ])
            ->assertSessionHasErrors();

        // Refused outright rather than quietly downgraded to private. A person who believes they
        // shared something with the company, and did not, is worse off than one who was told no.
        $this->assertSame(0, SavedFilter::query()->withoutGlobalScopes()->count());

        $this->save($this->tunde, 'Everyone sees this', $this->civil, 'organization')->assertSessionHasNoErrors();

        $this->assertSame('organization', SavedFilter::query()->withoutGlobalScopes()->sole()->visibility);
    }

    // ================================================================== §84, the conditions

    #[Test]
    public function free_text_is_printable_and_is_still_not_filterable_here(): void
    {
        // The report prints titles, so a saved view could plausibly be expected to filter on one.
        // It may not: a condition somebody can retype is a way to read a title back one guess at a
        // time, and offering that second door would undo the first.
        $this->actingFresh($this->ngozi)
            ->post(route('configuration.reports.views.store', $this->report), [
                'name' => 'Anything with drain in it',
                'filters' => [['field' => 'title', 'operator' => 'contains', 'value' => 'drain']],
            ])
            ->assertSessionHasErrors();

        $this->assertSame(0, SavedFilter::query()->withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_condition_stored_against_a_field_the_register_no_longer_offers_is_dropped_on_the_way_out(): void
    {
        $this->save($this->ngozi, 'Civil only');

        $filter = SavedFilter::query()->withoutGlobalScopes()->sole();

        // Written straight to the column, standing in for the upgrade that withdraws a field or the
        // custom field somebody deletes. "It was valid when it was saved" is not a property the run
        // screen may rely on months later.
        DB::table('saved_filters')->where('id', $filter->getKey())->update([
            'filters' => json_encode([
                ['field' => 'org_unit_id', 'operator' => '=', 'value' => (string) $this->civil->getKey()],
                ['field' => 'secret_salary_band', 'operator' => '=', 'value' => 'A'],
                ['field' => 'title', 'operator' => 'contains', 'value' => 'drain'],
            ]),
        ]);

        $conditions = $filter->fresh()->conditions();

        $this->assertCount(1, $conditions);
        $this->assertSame('org_unit_id', $conditions[0]['field']);
    }

    #[Test]
    public function a_view_that_has_stopped_narrowing_anything_is_refused_rather_than_run_wide(): void
    {
        $this->documentIn($this->civil, 'Excavation procedure');
        $this->documentIn($this->piping, 'Hydrotest procedure');

        $this->save($this->tunde, 'Civil only', $this->civil, 'organization');

        $filter = SavedFilter::query()->withoutGlobalScopes()->sole();

        // Every condition it holds is on a field the register has stopped offering.
        DB::table('saved_filters')->where('id', $filter->getKey())->update([
            'filters' => json_encode([['field' => 'secret_salary_band', 'operator' => '=', 'value' => 'A']]),
        ]);

        $response = $this->actingFresh($this->tunde)->get($this->applyUrl($filter->fresh()));

        // NOT a redirect carrying an empty filter set. The director clicked "Civil only"; running
        // the whole company under that heading, with a row count he would go on to quote, is the
        // one failure in this module nobody would catch.
        $response->assertRedirect($this->showUrl());
        $response->assertSessionHasErrors('saved_filter');

        // And the screen says so before it is clicked, rather than only after.
        $this->actingFresh($this->tunde)->get($this->showUrl())
            ->assertOk()
            ->assertSee('No longer works');
    }

    #[Test]
    public function a_view_saved_on_one_register_cannot_be_applied_to_a_report_on_another(): void
    {
        $this->save($this->tunde, 'Civil only', $this->civil, 'organization');

        $filter = SavedFilter::query()->withoutGlobalScopes()->sole();

        $projects = $this->withinOrganization($this->org,
            fn () => app(ReportDefinitionService::class)->create([
                'name' => 'Projects on file',
                'entity_type' => 'project',
                'columns' => ['name'],
            ], $this->tunde));

        // Two ids that each pass their own check, wrongly paired — which is the shape an IDOR
        // attempt takes once every individual object is authorized (§94). "Status is open" means
        // one thing on documents and another on projects.
        $this->actingFresh($this->tunde)
            ->get(route('configuration.reports.views.apply', ['report' => $projects, 'savedFilter' => $filter]))
            ->assertNotFound();
    }

    // ================================================================== keeping and removing

    #[Test]
    public function only_the_owner_may_change_what_a_shared_name_selects(): void
    {
        $this->save($this->ngozi, 'Civil only', $this->civil, 'org_unit');

        $filter = SavedFilter::query()->withoutGlobalScopes()->sole();

        // The director may remove this view; he may not rewrite it. Removing it is visible to the
        // people using it, and rewriting it is not — they carry on clicking the same name.
        $this->actingFresh($this->tunde)
            ->put($this->applyUrl($filter), ['name' => 'Everything', 'visibility' => 'organization'])
            ->assertForbidden();

        $this->assertSame('Civil only', $filter->fresh()->name);
    }

    #[Test]
    public function a_share_can_be_taken_down_by_somebody_who_builds_reports_for_the_company(): void
    {
        $this->save($this->ngozi, 'Civil only', $this->civil, 'org_unit');

        $filter = SavedFilter::query()->withoutGlobalScopes()->sole();

        // Otherwise a view shared by somebody who has since left is on everybody's screen with
        // nobody able to take it down, and "wait for the owner" is not an answer when the owner's
        // account is disabled.
        $this->actingFresh($this->tunde)->delete($this->applyUrl($filter))->assertRedirect();

        $this->assertSame(0, SavedFilter::query()->withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_private_view_is_nobody_elses_to_remove(): void
    {
        $this->save($this->ngozi, 'My shortlist');

        $filter = SavedFilter::query()->withoutGlobalScopes()->sole();

        // It is on one screen and discloses nothing, so there is no problem for an administrator to
        // be solving by tidying it away.
        $this->actingFresh($this->tunde)->delete($this->applyUrl($filter))->assertForbidden();

        $this->assertSame(1, SavedFilter::query()->withoutGlobalScopes()->count());
    }

    #[Test]
    public function one_person_cannot_have_two_views_of_one_register_under_the_same_name(): void
    {
        $this->save($this->ngozi, 'Civil only')->assertSessionHasNoErrors();
        $this->save($this->ngozi, 'Civil only')->assertSessionHasErrors();

        $this->assertSame(1, SavedFilter::query()->withoutGlobalScopes()->count());

        // Two people naming their own view the same thing is not a collision — it is two people,
        // each with one list.
        $this->save($this->sade, 'Civil only')->assertSessionHasNoErrors();

        $this->assertSame(2, SavedFilter::query()->withoutGlobalScopes()->count());
    }

    #[Test]
    public function saving_and_sharing_a_view_is_recorded(): void
    {
        $this->save($this->ngozi, 'Civil only', $this->civil, 'org_unit');

        $filter = SavedFilter::query()->withoutGlobalScopes()->sole();

        // §59: who shared which named question with whom, and when. A filter nobody admits to
        // creating is otherwise unanswerable.
        $entry = DB::table('activity_logs')
            ->where('subject_type', 'saved_filter')
            ->where('subject_id', $filter->getKey())
            ->where('verb', 'created')
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame($this->ngozi->getKey(), (int) $entry->causer_id);
        $this->assertStringContainsString('org_unit', (string) $entry->properties);
    }

    #[Test]
    public function somebody_with_no_report_permission_cannot_save_a_view_at_all(): void
    {
        $storeman = $this->makePerson($this->org, 'Chidi Nwosu', $this->civil, [
            $this->makeRole($this->org, 'Storeman', ['document.view' => 'own']),
        ]);

        $this->actingFresh($storeman)
            ->post(route('configuration.reports.views.store', $this->report), [
                'name' => 'Anything',
                'filters' => [['field' => 'org_unit_id', 'operator' => 'is_null', 'value' => null]],
            ])
            ->assertForbidden();

        $this->assertSame(0, SavedFilter::query()->withoutGlobalScopes()->count());
    }

    // ================================================================== fixture

    /**
     * Save a view through the screens, the way a person would.
     */
    private function save(
        User $actor,
        string $name,
        ?OrgUnit $unit = null,
        string $visibility = 'private',
    ): TestResponse {
        return $this->actingFresh($actor)->post(route('configuration.reports.views.store', $this->report), [
            'name' => $name,
            'visibility' => $visibility,
            'filters' => [[
                'field' => 'org_unit_id',
                'operator' => '=',
                'value' => (string) ($unit ?? $this->civil)->getKey(),
            ]],
        ]);
    }

    /**
     * How many rows the report returns for this person once the saved view has been applied.
     *
     * Followed through the redirect rather than asserted on the way past, because the redirect is
     * the whole mechanism: the conditions are re-checked on arrival and the rows are derived from
     * the person who arrives with them.
     */
    private function rowsAfterApplying(User $actor, SavedFilter $filter): int
    {
        $response = $this->actingFresh($actor)->get($this->applyUrl($filter));

        return $this->actingFresh($actor)
            ->get($response->headers->get('Location') ?? $this->showUrl())
            ->viewData('result')
            ->total;
    }

    private function applyUrl(SavedFilter $filter): string
    {
        return route('configuration.reports.views.apply', [
            'report' => $this->report,
            'savedFilter' => $filter,
        ]);
    }

    private function showUrl(): string
    {
        return route('configuration.reports.show', $this->report);
    }

    /** @param  array<string,mixed>  $attributes */
    private function documentIn(OrgUnit $unit, string $title, array $attributes = []): Document
    {
        return $this->makeDocument($this->org, $this->tunde, $title, [
            'document_category_id' => $this->procedures->getKey(),
            'org_unit_id' => $unit->getKey(),
            ...$attributes,
        ]);
    }
}
