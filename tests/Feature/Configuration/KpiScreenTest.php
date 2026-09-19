<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Models\ActivityLog;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\KpiDefinition;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\User;
use App\Services\KpiDefinitionService;
use App\Support\Enums\OrgUnitType;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * The measure builder and the list it feeds (Phase 7, Part 5E).
 *
 * KpiDefinitionTest proves the ENGINE counts the right records for the right person. This file is
 * about the two screens over it, and it exists because a KPI screen fails differently from the rest
 * of the platform: nothing 500s, nothing is refused, and nobody finds out. A measure built on a
 * column the platform does not offer saves happily and renders a blank tile eighteen months later.
 * A total counted once and shown to everybody looks right to every reader and is wrong for most of
 * them. So the assertions here are mostly about what must NOT be possible on the form.
 *
 *   THE FIGURE BELONGS TO THE READER (§61). Two people open one list and correctly disagree about
 *   the number beside one heading. This is the single most important assertion in the file, because
 *   the tempting optimisation — count once, cache, serve — hands one person's reach to another.
 *
 *   THE COLUMN NAMES ARE WHITELISTED (§84). Every field on this form is a select-box value that
 *   ends up inside a query builder, and "looks like a column" is not a security property.
 *
 *   READING A MEASURE IS NOT REDEFINING IT (§6), AND ANOTHER COMPANY'S MEASURE DOES NOT EXIST (§94).
 *
 * The fixture is KpiDefinitionTest's, deliberately, so an assertion about a figure on the screen can
 * be read against that file's assertion about the same figure in the engine.
 *
 *   Civil (dept)    Ngozi   document controller, Civil only — may read measures, not write them
 *   Piping (dept)   Sade    engineer, Piping only
 *   (company)       Tunde   operations director, the whole company, may manage measures
 */
final class KpiScreenTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $engineering;

    private OrgUnit $civil;

    private OrgUnit $piping;

    private DocumentCategory $procedures;

    private User $ngozi;

    private User $sade;

    private User $tunde;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Meridian Engineering');

        $this->engineering = $this->makeUnit($this->org, 'Engineering', OrgUnitType::Division);
        $this->civil = $this->makeUnit($this->org, 'Civil', OrgUnitType::Department, $this->engineering);
        $this->piping = $this->makeUnit($this->org, 'Piping', OrgUnitType::Department, $this->engineering);

        $controller = $this->makeRole($this->org, 'Document Controller', [
            'document.view' => 'department',
            'kpi.view' => 'organization',
        ]);

        $engineer = $this->makeRole($this->org, 'Engineer', [
            'document.view' => 'department',
            'kpi.view' => 'organization',
        ]);

        $director = $this->makeRole($this->org, 'Operations Director', [
            'document.view' => 'organization',
            'document.create' => 'organization',
            'kpi.view' => 'organization',
            'kpi.manage' => 'organization',
            'org_unit.view' => 'organization',
            'project.view' => 'organization',
        ]);

        $this->ngozi = $this->makePerson($this->org, 'Ngozi Eze', $this->civil, [$controller]);
        $this->sade = $this->makePerson($this->org, 'Sade Ajayi', $this->piping, [$engineer]);
        $this->tunde = $this->makePerson($this->org, 'Tunde Bello', null, [$director]);

        $this->procedures = $this->makeDocumentCategory($this->org, 'Procedures', [
            'requires_approval' => false,
        ]);
    }

    // ================================================================== §61 on a screen

    #[Test]
    public function two_people_open_the_same_list_and_correctly_disagree_about_the_number(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $this->documentIn($this->civil, 'Excavation procedure');
        $this->documentIn($this->piping, 'Hydrotest procedure');

        $this->kpi(['name' => 'Procedures on file']);

        // Three documents exist. The director sees all three; the Civil controller sees the two
        // filed in Civil; the Piping engineer sees the one in Piping. Same page, same heading,
        // same instant — and every one of those numbers is the right answer to "how many
        // procedures are on file", because the rest are not on file as far as that reader knows.
        $this->assertSame('3', $this->figureFor($this->tunde, 'Procedures on file'));
        $this->assertSame('2', $this->figureFor($this->ngozi, 'Procedures on file'));
        $this->assertSame('1', $this->figureFor($this->sade, 'Procedures on file'));
    }

    #[Test]
    public function the_page_says_out_loud_that_the_figures_are_the_readers_own(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $this->kpi(['name' => 'Procedures on file']);

        // Without this sentence the previous test is a bug report. An operations director who sees
        // 3 where his controller sees 2 will either not notice — and quote the wrong figure in a
        // board pack — or conclude the platform cannot count. Saying it on the page is what turns
        // the behaviour from a discrepancy into a property.
        $this->actingFresh($this->ngozi)->get(route('configuration.kpis.index'))
            ->assertOk()
            ->assertSee('Procedures on file')
            ->assertSee('you can see', false);
    }

    #[Test]
    public function a_measure_that_cannot_be_counted_shows_its_reason_rather_than_a_zero(): void
    {
        $kpi = $this->kpi(['name' => 'Procedures on file']);

        // The register the definition names stops being offered — an upgrade, a module switched
        // off, a catalogue entry withdrawn. The definition survives it, because a definition is
        // configuration and outlives what it points at (§84).
        $this->withinOrganization($this->org, fn () => $kpi
            ->forceFill(['entity_type' => 'nothing_like_this'])
            ->saveQuietly());

        $response = $this->actingFresh($this->tunde)->get(route('configuration.kpis.index'))->assertOk();

        // "0" would be a lie with a number's authority: a safety board reading zero open incidents
        // would act on it. "No figure, because —" is the only honest thing a broken measure can say.
        $response->assertSee('No figure');
        $this->assertNull($this->figureFor($this->tunde, 'Procedures on file'));
    }

    // ================================================================== who may do what

    #[Test]
    public function somebody_with_no_business_here_cannot_open_the_page(): void
    {
        $storeman = $this->makePerson($this->org, 'Chidi Okafor', $this->civil, [
            $this->makeRole($this->org, 'Storeman', ['document.view' => 'own']),
        ]);

        $this->actingFresh($storeman)->get(route('configuration.kpis.index'))->assertForbidden();
        $this->actingFresh($storeman)->get(route('configuration.kpis.create'))->assertForbidden();
    }

    #[Test]
    public function reading_a_measure_is_not_permission_to_redefine_it(): void
    {
        $kpi = $this->kpi();

        $this->actingFresh($this->ngozi)->get(route('configuration.kpis.index'))->assertOk();

        // The list hides the buttons from this person as a courtesy. The route is what stops them,
        // which is why every one of these is a request and not a search for a missing link (§6).
        $this->actingFresh($this->ngozi)->get(route('configuration.kpis.create'))->assertForbidden();
        $this->actingFresh($this->ngozi)->get(route('configuration.kpis.edit', $kpi))->assertForbidden();
        $this->actingFresh($this->ngozi)->post(route('configuration.kpis.store'), $this->payload())->assertForbidden();
        $this->actingFresh($this->ngozi)->put(route('configuration.kpis.update', $kpi), $this->payload())->assertForbidden();
        $this->actingFresh($this->ngozi)->delete(route('configuration.kpis.destroy', $kpi))->assertForbidden();
    }

    #[Test]
    public function another_company_s_measure_does_not_exist(): void
    {
        $rival = $this->makeOrganization('Continental Fabricators');
        $rivalUnit = $this->makeUnit($rival, 'Works', OrgUnitType::Department);
        $rivalBoss = $this->makePerson($rival, 'Ada Nwosu', $rivalUnit, [
            $this->makeRole($rival, 'Director', [
                'kpi.view' => 'organization',
                'kpi.manage' => 'organization',
            ]),
        ]);

        $theirs = $this->withinOrganization($rival, fn () => app(KpiDefinitionService::class)->create([
            'name' => 'Their open nonconformances',
            'entity_type' => 'document',
            'aggregate' => 'count',
            'scope' => 'organization',
            'period' => 'all_time',
        ], $rivalBoss));

        // 404 rather than 403 (§94). A forbidden response confirms the row is there, and "how many
        // measures does Continental watch" is then answerable by counting which ids give 403.
        $this->actingFresh($this->tunde)->get(route('configuration.kpis.edit', $theirs))->assertNotFound();
        $this->actingFresh($this->tunde)->put(route('configuration.kpis.toggle', $theirs))->assertNotFound();
        $this->actingFresh($this->tunde)->delete(route('configuration.kpis.destroy', $theirs))->assertNotFound();

        // And the name of a rival's measure is itself a disclosure — "days since last lost-time
        // injury" on a list tells the reader there have been injuries (§61 covers the list, not
        // only the totals).
        $this->actingFresh($this->tunde)->get(route('configuration.kpis.index'))
            ->assertOk()
            ->assertDontSee('Their open nonconformances');
    }

    // ================================================================== the builder

    #[Test]
    public function the_builder_opens_for_a_new_measure_and_for_an_existing_one(): void
    {
        $kpi = $this->kpi(['name' => 'Procedures on file']);

        $this->actingFresh($this->tunde)->get(route('configuration.kpis.create'))
            ->assertOk()
            ->assertSee('What it counts');

        // One form for both, and this asserts it renders filled in. A builder whose add and edit
        // screens drift apart is how a measure ends up with a field nobody can change afterwards.
        $this->actingFresh($this->tunde)->get(route('configuration.kpis.edit', $kpi))
            ->assertOk()
            ->assertSee('Procedures on file');
    }

    #[Test]
    public function a_measure_built_on_the_form_counts_exactly_what_was_chosen(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $this->documentIn($this->civil, 'Excavation procedure', ['is_controlled' => false]);

        $this->actingFresh($this->tunde)
            ->post(route('configuration.kpis.store'), $this->payload([
                'name' => 'Controlled procedures',
                'filters' => [
                    ['field' => 'is_controlled', 'operator' => '=', 'value' => '1'],
                ],
                'target' => '10',
                'direction' => 'higher_better',
            ]))
            ->assertRedirect(route('configuration.kpis.index'));

        $kpi = $this->withinOrganization($this->org, fn () => KpiDefinition::query()->sole());

        $this->assertSame('Controlled procedures', $kpi->name);
        $this->assertSame($this->org->getKey(), $kpi->organization_id);
        $this->assertSame([['field' => 'is_controlled', 'operator' => '=', 'value' => '1']], $kpi->baseFilters());

        // The filter posted as an indexed array of rows survives the round trip AND narrows the
        // count — one of the two documents, not both. A builder that stored the condition without
        // applying it would pass every assertion above this line.
        $this->assertSame('1', $this->figureFor($this->tunde, 'Controlled procedures'));
    }

    #[Test]
    public function a_column_the_platform_does_not_offer_cannot_be_measured(): void
    {
        foreach ([
            // A register that does not exist.
            ['entity_type' => 'nothing_like_this'],
            // A register that does, filtered on a column that is not offered on it.
            ['filters' => [['field' => 'password', 'operator' => '=', 'value' => 'x']]],
            // Free text. Offered nowhere, on purpose: a total sliced by a title is a total nobody
            // can restate, and a LIKE over a description is a report, not a measure.
            ['filters' => [['field' => 'title', 'operator' => '=', 'value' => 'x']]],
            // An aggregate with no column to add up.
            ['aggregate' => 'sum', 'measure_field' => ''],
            // A number summed out of a column that holds none.
            ['aggregate' => 'sum', 'measure_field' => 'title'],
            // A window with no date to count it over.
            ['period' => 'month', 'date_field' => ''],
            ['period' => 'month', 'date_field' => 'deleted_at'],
            // A ratio with nothing on top of it.
            ['aggregate' => 'percent', 'numerator_filters' => []],
        ] as $attempt) {
            $this->actingFresh($this->tunde)
                ->post(route('configuration.kpis.store'), $this->payload($attempt))
                ->assertSessionHasErrors('name');
        }

        $this->assertSame(0, $this->withinOrganization($this->org, fn () => KpiDefinition::query()->count()));
    }

    #[Test]
    public function a_refused_measure_comes_back_with_what_was_typed_still_in_it(): void
    {
        $this->actingFresh($this->tunde)
            ->post(route('configuration.kpis.store'), $this->payload([
                'name' => 'Hours booked to Civil',
                'aggregate' => 'sum',
                'measure_field' => 'title',
            ]))
            ->assertSessionHasErrors('name')
            // Not a nicety. This form carries ten filter rows and a period; sending somebody back
            // to a blank one teaches them to build the measure somewhere else and paste it in.
            ->assertSessionHasInput('name', 'Hours booked to Civil')
            ->assertSessionHasInput('aggregate', 'sum');
    }

    // ================================================================== living with it

    #[Test]
    public function switching_a_measure_off_keeps_what_it_meant(): void
    {
        $kpi = $this->kpi(['name' => 'Procedures on file']);

        $this->actingFresh($this->tunde)
            ->put(route('configuration.kpis.toggle', $kpi))
            ->assertRedirect();

        $kpi = $this->withinOrganization($this->org, fn () => KpiDefinition::query()->sole());

        $this->assertFalse($kpi->is_active);
        $this->assertSame('document', $kpi->entity_type);

        // Still listed, and labelled. A figure quoted in a board pack is only reproducible while
        // the definition behind it exists, so the definition stays readable after it stops
        // counting — and the reader is told, rather than left to wonder why the tile is stale.
        $this->actingFresh($this->tunde)->get(route('configuration.kpis.index'))
            ->assertOk()
            ->assertSee('Procedures on file')
            ->assertSee('Switched off');
    }

    #[Test]
    public function changing_a_definition_is_written_down(): void
    {
        $kpi = $this->kpi(['name' => 'Procedures on file']);

        $this->actingFresh($this->tunde)
            ->put(route('configuration.kpis.update', $kpi), $this->payload([
                'name' => 'Procedures on file',
                'filters' => [['field' => 'is_controlled', 'operator' => '=', 'value' => '1']],
            ]))
            ->assertRedirect(route('configuration.kpis.index'));

        // Editing a measure breaks nothing and changes everything: no record moves, no screen
        // errors, and a figure a board has read for six quarters quietly starts meaning something
        // else under the same heading. The trail is the only place that is visible (§59).
        $this->withinOrganization($this->org, function () use ($kpi) {
            $entry = ActivityLog::query()
                ->withoutGlobalScopes()
                ->where('subject_type', 'kpi_definition')
                ->where('subject_id', $kpi->getKey())
                ->where('verb', 'updated')
                ->sole();

            $this->assertContains('filters', $entry->properties['fields'] ?? []);
        });
    }

    // ================================================================== helpers

    /**
     * The figure shown beside one measure on the list, as a plain string, or null when the page
     * declined to show one.
     *
     * Read out of the rendered view's own data rather than scraped from the markup: the assertion
     * is about what this viewer was told, and the number on the page is formatted for reading.
     */
    private function figureFor(User $viewer, string $name): ?string
    {
        $response = $this->actingFresh($viewer)->get(route('configuration.kpis.index'))->assertOk();

        foreach ($response->viewData('rows') as $row) {
            if ($row['kpi']->name !== $name) {
                continue;
            }

            return $row['result']->available && $row['result']->value !== null
                ? rtrim(rtrim(number_format($row['result']->value, 2, '.', ''), '0'), '.')
                : null;
        }

        $this->fail('“'.$name.'” was not on the list '.$viewer->name.' was shown.');
    }

    /**
     * A form submission, as the browser sends it.
     *
     * Every value is a string or an array of strings, because that is what a form posts — an int
     * in this array would be a test passing on data the screen can never produce.
     *
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    private function payload(array $attributes = []): array
    {
        return [
            'name' => 'Procedures on file',
            'entity_type' => 'document',
            'aggregate' => 'count',
            'scope' => 'organization',
            'period' => 'all_time',
            'direction' => 'higher_better',
            'is_active' => '1',
            ...$attributes,
        ];
    }

    /** @param  array<string,mixed>  $attributes */
    private function kpi(array $attributes = []): KpiDefinition
    {
        return $this->withinOrganization($this->org, fn () => app(KpiDefinitionService::class)->create([
            'name' => 'Procedures on file',
            'entity_type' => 'document',
            'aggregate' => 'count',
            'scope' => 'organization',
            'period' => 'all_time',
            ...$attributes,
        ], $this->tunde));
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
