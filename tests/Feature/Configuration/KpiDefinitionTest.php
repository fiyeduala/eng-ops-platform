<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Engines\Kpi\KpiEvaluator;
use App\Engines\Kpi\KpiResult;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\KpiDefinition;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\Task;
use App\Models\User;
use App\Services\KpiDefinitionService;
use App\Support\Enums\OrgUnitType;
use App\Support\KpiCatalogue;
use Carbon\CarbonInterface;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * What a company counts, and who each number is counted for.
 *
 * A COUNT IS AN ORACLE, and that is the single idea these tests exist to pin down. "How many
 * confidential incidents are open in Piping" is a question somebody without clearance must not be
 * able to ask, and it does not become safe by being answered with one digit instead of a list — a
 * dashboard is simply the screen on which such a digit looks harmless. So every total here is
 * counted through the asking person's own visibility (§61), which means two people opening the same
 * KPI correctly see two different numbers, and a test that asserted a single "right" answer would
 * be asserting the bug.
 *
 * THE OTHER HALF IS THAT A KPI IS CONFIGURATION SOMEBODY TYPES. Register names, filter fields,
 * measure columns and date columns all arrive from select boxes — which is to say from the browser —
 * and all of them end up inside a query builder. docs/11 §5 puts the requirement plainly: "a
 * whitelisted entity + column descriptor, never raw SQL". These tests check the whitelist on the way
 * in AND on the way out, because a definition outlives the register it names (§84).
 *
 * The fixture is the same one ExpiryWatchTest uses, deliberately: an assertion here about what a
 * number counts can then be read against DocumentAccessTest's assertion about what a person can
 * open, and the two have to agree.
 *
 *   Civil (dept)    Ngozi   document controller, Civil only
 *   Piping (dept)   Sade    engineer, Piping only — cannot read a Civil document
 *   (company)       Tunde   operations director, the whole company
 */
final class KpiDefinitionTest extends TestCase
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
            'document.create' => 'department',
            'task.view' => 'department',
            'kpi.view' => 'organization',
        ]);

        $engineer = $this->makeRole($this->org, 'Engineer', [
            'document.view' => 'department',
            'task.view' => 'department',
            'kpi.view' => 'organization',
        ]);

        $director = $this->makeRole($this->org, 'Operations Director', [
            'document.view' => 'organization',
            'document.create' => 'organization',
            'task.view' => 'organization',
            'task.create' => 'organization',
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

    // ================================================================== §61, the whole point

    #[Test]
    public function a_total_counts_only_what_the_person_asking_could_have_counted_by_hand(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $this->documentIn($this->civil, 'Excavation procedure');
        $this->documentIn($this->piping, 'Hydrotest procedure');

        $kpi = $this->kpi(['name' => 'Procedures on file']);

        // The same definition, the same moment, three different and equally correct answers. A
        // figure is only safe to show because it is the one the viewer could have arrived at
        // themselves; a shared total would be one person's reach handed to the next reader.
        $this->assertSame(3.0, $this->evaluate($kpi, $this->tunde)->value);
        $this->assertSame(2.0, $this->evaluate($kpi, $this->ngozi)->value);
        $this->assertSame(1.0, $this->evaluate($kpi, $this->sade)->value);
    }

    #[Test]
    public function a_measure_holds_no_number_of_its_own(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $kpi = $this->kpi();

        // Not a style point. The moment a total is stored on the definition it belongs to whoever
        // it was counted for, and the next reader gets that person's reach. The absence of a column
        // is what makes the test above possible at all.
        $columns = array_keys($kpi->getAttributes());

        foreach (['value', 'total', 'last_value', 'computed_at', 'last_computed_at'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
        }
    }

    #[Test]
    public function one_company_s_measures_never_count_another_s_records(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');

        $rival = $this->makeOrganization('Continental Fabricators');
        $rivalUnit = $this->makeUnit($rival, 'Works', OrgUnitType::Department);
        $rivalBoss = $this->makePerson($rival, 'Ada Nwosu', $rivalUnit, [
            $this->makeRole($rival, 'Director', [
                'document.view' => 'organization',
                'kpi.view' => 'organization',
            ]),
        ]);
        $rivalCategory = $this->makeDocumentCategory($rival, 'Procedures', ['requires_approval' => false]);
        $this->makeDocument($rival, $rivalBoss, 'Welding procedure', [
            'document_category_id' => $rivalCategory->getKey(),
            'org_unit_id' => $rivalUnit->getKey(),
        ]);

        $kpi = $this->kpi();

        // Meridian's director sees Meridian's one document and has no way to learn that anybody
        // else has any (§75).
        $this->assertSame(1.0, $this->evaluate($kpi, $this->tunde)->value);

        // The rival, handed Meridian's definition by its key, gets nothing — and specifically not
        // a plausible "1" counted off his own register. He would never have read Meridian's
        // documents through it, but he would have been shown a number under a heading Meridian
        // controls and can redefine, which is a slower version of the same problem.
        $strangers = $this->withinOrganization($rival,
            fn () => app(KpiEvaluator::class)->evaluate($kpi, $rivalBoss));

        $this->assertFalse($strangers->available);
        $this->assertNull($strangers->value);
    }

    // ================================================================== §84, what may be built

    #[Test]
    public function a_measure_can_only_be_built_out_of_what_the_platform_offers(): void
    {
        $refusals = [
            'a register that is not one' => ['entity_type' => 'users'],
            'a register that is configuration' => ['entity_type' => 'status'],
            'a calculation that does not exist' => ['aggregate' => 'median'],
            'a filter on a column nobody offered' => [
                'filters' => [['field' => 'password', 'operator' => '=', 'value' => 'x']],
            ],
            'a filter on free text' => [
                'filters' => [['field' => 'title', 'operator' => '=', 'value' => 'Lifting']],
            ],
            'a comparison that does not exist' => [
                'filters' => [['field' => 'org_unit_id', 'operator' => 'regex', 'value' => '.*']],
            ],
            'a total of a column that is not a figure' => [
                'aggregate' => 'sum', 'measure_field' => 'title',
            ],
            'a window on a column that is not a date' => [
                'period' => 'month', 'date_field' => 'title',
            ],
            'a period nobody offered' => ['period' => 'fortnight', 'date_field' => 'created_at'],
            'an altitude nobody offered' => ['scope' => 'continent'],
        ];

        foreach ($refusals as $what => $attributes) {
            try {
                $this->kpi(['name' => 'Attempt: '.$what, ...$attributes]);
                $this->fail('The builder accepted '.$what.'.');
            } catch (\DomainException) {
                $this->assertTrue(true);
            }
        }

        // Nothing was stored on the way past, either.
        $this->assertSame(0, $this->withinOrganization($this->org, fn () => KpiDefinition::query()->count()));
    }

    #[Test]
    public function a_measure_is_never_offered_a_field_that_holds_free_text(): void
    {
        // Asserted across EVERY countable register rather than the one this fixture uses, because
        // the list is discovered: a register added in a later phase joins it without anybody
        // editing the catalogue, and a filter that can test free text is a way to read it back out
        // one guess at a time — with a counter on a dashboard doing the guessing.
        $leaks = [];

        foreach (array_keys(KpiCatalogue::countableEntities()) as $entityType) {
            foreach (array_keys(KpiCatalogue::filterFieldsFor($entityType)) as $field) {
                if (preg_match('/(title|_name$|^name$|description|notes|comment|summary|remark|address|email|phone|national_id|tax_id)/i', $field)) {
                    $leaks[] = $entityType.'.'.$field;
                }
            }
        }

        $this->assertSame([], $leaks, 'A KPI filter was offered a free-text or identifying column.');
    }

    #[Test]
    public function the_platform_offers_only_registers_whose_totals_can_be_narrowed_to_the_viewer(): void
    {
        foreach (array_keys(KpiCatalogue::countableEntities()) as $entityType) {
            $class = KpiCatalogue::modelFor($entityType);

            // Without visibleTo there is no honest number to show anybody, so the register must
            // not be offered at all rather than counted in full and hoped about.
            $this->assertTrue(
                method_exists($class, 'scopeVisibleTo'),
                $entityType.' can be counted but cannot be narrowed to a viewer.',
            );
        }
    }

    #[Test]
    public function a_measure_pointed_at_something_the_platform_stopped_offering_falls_silent(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');

        $kpi = $this->kpi();

        // The definition is edited around the validator, which is exactly what time does to one:
        // a register is narrowed or retired years after somebody built the KPI on it.
        $this->withinOrganization($this->org, fn () => $kpi->forceFill([
            'filters' => [['field' => 'secret_column', 'operator' => '=', 'value' => 1]],
        ])->saveQuietly());

        $result = $this->evaluate($kpi->refresh(), $this->tunde);

        // Unavailable, with a reason — NOT zero. A fabricated zero is the most dangerous number a
        // dashboard can print, because nobody investigates good news.
        $this->assertFalse($result->available);
        $this->assertNull($result->value);
        $this->assertNotNull($result->reason);
    }

    // ================================================================== the arithmetic

    #[Test]
    public function a_percentage_is_counted_through_the_same_visibility_as_the_total_it_is_part_of(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure', ['is_controlled' => true]);
        $this->documentIn($this->civil, 'Excavation procedure', ['is_controlled' => false]);
        $this->documentIn($this->piping, 'Hydrotest procedure', ['is_controlled' => true]);

        $kpi = $this->kpi([
            'name' => 'Controlled procedures',
            'aggregate' => 'percent',
            'numerator_filters' => [['field' => 'is_controlled', 'operator' => '=', 'value' => 1]],
            'direction' => 'higher_better',
        ]);

        // Two of three company-wide; one of two in Civil; one of one in Piping. The numerator is
        // narrowed from the same base query rather than counted separately, so there is no
        // arrangement in which somebody sees a numerator larger than the total they can reach.
        $this->assertEqualsWithDelta(66.67, $this->evaluate($kpi, $this->tunde)->value, 0.01);
        $this->assertEqualsWithDelta(50.0, $this->evaluate($kpi, $this->ngozi)->value, 0.01);
        $this->assertEqualsWithDelta(100.0, $this->evaluate($kpi, $this->sade)->value, 0.01);
    }

    #[Test]
    public function a_ratio_over_nothing_is_not_a_number(): void
    {
        $kpi = $this->kpi([
            'name' => 'Controlled procedures',
            'aggregate' => 'percent',
            'numerator_filters' => [['field' => 'is_controlled', 'operator' => '=', 'value' => 1]],
        ]);

        $result = $this->evaluate($kpi, $this->tunde);

        // Zero out of zero is not nought per cent — it is a question nobody asked. Printing 0%
        // against a safety target would be a red tile invented out of an empty register.
        $this->assertNull($result->value);
        $this->assertSame(0, $result->population);
    }

    #[Test]
    public function a_total_and_an_average_say_how_many_records_they_are_built_on(): void
    {
        $this->taskWith('Survey the bund', 8.0);
        $this->taskWith('Pour the slab', 12.0);

        $sum = $this->kpi([
            'name' => 'Hours planned',
            'entity_type' => 'task',
            'aggregate' => 'sum',
            'measure_field' => 'estimated_hours',
        ]);

        $average = $this->kpi([
            'name' => 'Average task size',
            'entity_type' => 'task',
            'aggregate' => 'avg',
            'measure_field' => 'estimated_hours',
        ]);

        $this->assertSame(20.0, $this->evaluate($sum, $this->tunde)->value);
        $this->assertSame(10.0, $this->evaluate($average, $this->tunde)->value);

        // An average over two rows and an average over two thousand are different claims, and a
        // tile that cannot say which invites somebody to act on noise.
        $this->assertSame(2, $this->evaluate($average, $this->tunde)->population);
    }

    // ================================================================== the window

    #[Test]
    public function a_period_includes_the_days_at_both_ends_of_it(): void
    {
        $month = now()->startOfMonth();

        $this->reviewOn($this->documentIn($this->civil, 'First of the month'), $month->copy());
        $this->reviewOn($this->documentIn($this->civil, 'Last of the month'), $month->copy()->endOfMonth());
        $this->reviewOn($this->documentIn($this->civil, 'Next month'), $month->copy()->addMonthNoOverflow()->startOfMonth());

        $kpi = $this->kpi([
            'name' => 'Reviews due this month',
            'period' => 'month',
            'date_field' => 'next_review_on',
        ]);

        // Both boundary days count. A `date`-cast column persisted through the model's datetime
        // format holds "…-30 00:00:00", so a window whose far end was a bare date string would
        // silently drop the last day of every month — the same edge that hid a missing milestone
        // in the expiry sweep, and one no test with a comfortable window ever notices.
        $this->assertSame(2.0, $this->evaluate($kpi, $this->tunde, $month->copy()->addDays(10))->value);
    }

    #[Test]
    public function one_definition_answers_about_whichever_period_it_is_asked_about(): void
    {
        $month = now()->startOfMonth();

        $this->reviewOn($this->documentIn($this->civil, 'This month'), $month->copy()->addDays(3));
        $this->reviewOn($this->documentIn($this->civil, 'Next month'), $month->copy()->addMonthNoOverflow()->addDays(3));

        $kpi = $this->kpi([
            'name' => 'Reviews due',
            'period' => 'month',
            'date_field' => 'next_review_on',
        ]);

        // The window is computed from the moment the KPI is ASKED about and never stored, which is
        // what stops a company defining the same measure thirteen times to get a year of it.
        $this->assertSame(1.0, $this->evaluate($kpi, $this->tunde, $month->copy()->addDays(5))->value);
        $this->assertSame(1.0, $this->evaluate($kpi, $this->tunde, $month->copy()->addMonthNoOverflow()->addDays(5))->value);
        $this->assertSame(0.0, $this->evaluate($kpi, $this->tunde, $month->copy()->subMonthNoOverflow())->value);
    }

    // ================================================================== altitude

    #[Test]
    public function a_department_measure_counts_the_departments_beneath_it_too(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $this->documentIn($this->piping, 'Hydrotest procedure');

        $kpi = $this->kpi(['name' => 'Procedures by department', 'scope' => 'department']);

        // An organogram is a tree so that it can be read as one: "procedures in Engineering" means
        // the division, not the single desk at the top of it.
        $this->assertSame(2.0, $this->evaluate($kpi, $this->tunde, null, $this->engineering->getKey())->value);
        $this->assertSame(1.0, $this->evaluate($kpi, $this->tunde, null, $this->civil->getKey())->value);
    }

    #[Test]
    public function an_id_from_a_page_narrows_a_measure_and_can_never_widen_one(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $this->documentIn($this->piping, 'Hydrotest procedure');

        $kpi = $this->kpi(['name' => 'Procedures by department', 'scope' => 'department']);

        // Sade can reach Piping alone. Handed Civil's id — the shape of every IDOR attempt, a
        // number off a URL — she gets the intersection, which is nothing, rather than Civil's
        // count (§84, §94).
        $this->assertSame(0.0, $this->evaluate($kpi, $this->sade, null, $this->civil->getKey())->value);
        $this->assertSame(1.0, $this->evaluate($kpi, $this->sade, null, $this->piping->getKey())->value);
    }

    #[Test]
    public function a_register_that_cannot_be_cut_that_way_is_refused_rather_than_left_blank(): void
    {
        try {
            $this->kpi(['name' => 'Documents per person', 'scope' => 'employee', 'entity_type' => 'org_unit']);
            $this->fail('A measure was built at an altitude its register has no column for.');
        } catch (\DomainException $e) {
            // Refused at the builder, because the alternative is a tile that is permanently blank
            // on every page with nothing to suggest the definition is at fault.
            $this->assertStringContainsString('cannot be broken down', $e->getMessage());
        }
    }

    // ================================================================== the lifecycle

    #[Test]
    public function switching_a_measure_off_stops_it_counting_without_losing_what_it_meant(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $kpi = $this->kpi();

        $this->withinOrganization($this->org,
            fn () => app(KpiDefinitionService::class)->setActive($kpi, false, $this->tunde));

        $result = $this->evaluate($kpi->refresh(), $this->tunde);

        $this->assertFalse($result->available);

        // The definition survives, which is the reason to prefer this to deleting: a figure quoted
        // in a board pack is unreproducible once the only record of how it was arrived at is gone.
        $this->assertSame('document', $kpi->entity_type);
        $this->assertSame([], $this->evaluateScope('organization', $this->tunde));
    }

    #[Test]
    public function two_measures_cannot_share_one_name(): void
    {
        $this->kpi(['name' => 'Completion rate']);

        try {
            $this->kpi(['name' => 'Completion rate', 'entity_type' => 'task']);
            $this->fail('Two measures were given the same name.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('already a measure', $e->getMessage());
        }
    }

    #[Test]
    public function a_dashboard_lists_only_the_measures_the_viewer_may_know_about(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $this->kpi(['name' => 'Procedures on file']);

        $blind = $this->makePerson($this->org, 'Chidi Okafor', $this->civil, [
            $this->makeRole($this->org, 'Storeman', ['document.view' => 'own']),
        ]);

        // Which measures a company watches is itself a statement about the company. Somebody
        // without kpi.view must not learn that a "days since last lost-time injury" measure exists
        // by being handed an empty one.
        $this->assertCount(1, $this->evaluateScope('organization', $this->tunde));
        $this->assertSame([], $this->evaluateScope('organization', $blind));
    }

    #[Test]
    public function what_good_looks_like_is_the_company_s_to_say(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');

        $more = $this->kpi([
            'name' => 'Procedures on file',
            'target' => 10,
            'direction' => 'higher_better',
            'thresholds' => ['warning' => 5, 'critical' => 2],
        ]);

        $fewer = $this->kpi([
            'name' => 'Overdue procedures',
            'target' => 0,
            'direction' => 'lower_better',
            'thresholds' => ['warning' => 5, 'critical' => 2],
        ]);

        $result = $this->evaluate($more, $this->tunde);

        // One document against the same two thresholds. Read as "higher is better" it is critical;
        // read as "lower is better" it is comfortably fine. A platform that assumed bigger is
        // better would quietly congratulate a company on its injury rate.
        $this->assertSame('critical', $result->standing($more));
        $this->assertSame('on_target', $this->evaluate($fewer, $this->tunde)->standing($fewer));
    }

    // ================================================================== helpers

    /**
     * A measure, built the way the screen builds one — through the service, as Tunde.
     *
     * Never through KpiDefinition::create(): the validation IS the feature, and a fixture that
     * wrote rows directly would leave every §84 assertion in this file testing nothing.
     *
     * @param  array<string,mixed>  $attributes
     */
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

    private function evaluate(
        KpiDefinition $kpi,
        User $viewer,
        ?CarbonInterface $asOf = null,
        ?int $scopeId = null,
    ): KpiResult {
        return $this->withinOrganization($this->org,
            fn () => app(KpiEvaluator::class)->evaluate($kpi, $viewer, $asOf, $scopeId));
    }

    /** @return array<int,array{kpi:KpiDefinition,result:KpiResult}> */
    private function evaluateScope(string $scope, User $viewer): array
    {
        return $this->withinOrganization($this->org,
            fn () => app(KpiEvaluator::class)->evaluateScope($scope, $viewer));
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

    private function reviewOn(Document $document, CarbonInterface $date): Document
    {
        $this->withinOrganization($this->org, fn () => $document
            ->forceFill(['next_review_on' => $date->toDateString()])
            ->saveQuietly());

        return $document;
    }

    private function taskWith(string $title, float $hours): Task
    {
        $task = $this->makeTask($this->org, $this->tunde, $title, [
            'org_unit_id' => $this->civil->getKey(),
        ]);

        $this->withinOrganization($this->org, fn () => $task
            ->forceFill(['estimated_hours' => $hours])
            ->saveQuietly());

        return $task;
    }
}
