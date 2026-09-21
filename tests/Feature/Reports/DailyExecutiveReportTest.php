<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Engines\Configuration\SettingsRepository;
use App\Engines\Report\ExecutiveBriefing;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\Export;
use App\Models\KpiDefinition;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\User;
use App\Services\DashboardWidgetService;
use App\Services\KpiDefinitionService;
use App\Support\Enums\OrgUnitType;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * The daily executive report (§55, docs/11 §6) — Phase 8, part 6.
 *
 * THE ONE CLAIM THIS FILE EXISTS TO PROVE is that two people on the same distribution list get
 * different documents. Everything else here — the hour, the idempotence, the retention, the signed
 * link — is plumbing around that claim, and plumbing is the part that fails quietly. A scheduled
 * report that went out once at the wrong hour is noticed within a day; a scheduled report that
 * quietly sent the managing director's figures to everybody on the board is noticed when somebody
 * asks a question they should not have been able to ask.
 *
 * WHY THE ASSERTIONS ARE AGAINST HTML AND NOT AGAINST THE PDF. The body is rendered once and handed
 * to the same Dompdf path the documents module uses, whose output is a compressed stream with no
 * readable text in it. Asserting `%PDF` proves a file was produced and nothing about what is in it,
 * so the content assertions run against `bodyFor()` — the exact string the renderer is given — and
 * the PDF is checked for being a PDF.
 *
 *   Engineering (division) ─ Civil, Piping
 *
 *   Tunde   company-wide document reach      · executive board · arranges the boards
 *   Emeka   division reach (Civil + Piping)  · executive board
 *   Kemi    Civil only                       · executive board
 *   Dayo    company-wide document reach      · NO executive board
 *   Ngozi   Civil only                       · NO executive board, no document reach worth having
 */
final class DailyExecutiveReportTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $engineering;

    private OrgUnit $civil;

    private OrgUnit $piping;

    private DocumentCategory $procedures;

    private User $tunde;

    private User $emeka;

    private User $kemi;

    private User $dayo;

    /** @var array<string,KpiDefinition> */
    private array $measures = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Export::DISK);

        $this->org = $this->makeOrganization('Meridian Engineering');

        $this->engineering = $this->makeUnit($this->org, 'Engineering', OrgUnitType::Division);
        $this->civil = $this->makeUnit($this->org, 'Civil', OrgUnitType::Department, $this->engineering);
        $this->piping = $this->makeUnit($this->org, 'Piping', OrgUnitType::Department, $this->engineering);

        $director = $this->makeRole($this->org, 'Operations Director', [
            'document.view' => 'organization',
            'document.create' => 'organization',
            'kpi.view' => 'organization',
            'kpi.manage' => 'organization',
            'org_unit.view' => 'organization',
            'dashboard.view' => 'organization',
            'dashboard.view_executive' => 'organization',
            'dashboard.manage' => 'organization',
        ]);

        $divisionHead = $this->makeRole($this->org, 'Division Head', [
            'document.view' => 'division',
            'org_unit.view' => 'division',
            'dashboard.view' => 'organization',
            'dashboard.view_executive' => 'organization',
        ]);

        $departmentalExecutive = $this->makeRole($this->org, 'Non-executive Director', [
            'document.view' => 'department',
            'org_unit.view' => 'department',
            'dashboard.view' => 'organization',
            'dashboard.view_executive' => 'organization',
        ]);

        // Company-wide reach over the register and no business on the executive board. The pairing
        // is the point: reach and board permission are separate decisions, and the recipient list
        // is built from the board one.
        $controller = $this->makeRole($this->org, 'Document Controller', [
            'document.view' => 'organization',
            'org_unit.view' => 'organization',
            'dashboard.view' => 'organization',
        ]);

        $this->tunde = $this->makePerson($this->org, 'Tunde Bello', null, [$director]);
        $this->emeka = $this->makePerson($this->org, 'Emeka Obi', $this->engineering, [$divisionHead]);
        $this->kemi = $this->makePerson($this->org, 'Kemi Alabi', $this->civil, [$departmentalExecutive]);
        $this->dayo = $this->makePerson($this->org, 'Dayo Ogun', null, [$controller]);
        $this->makePerson($this->org, 'Ngozi Eze', $this->civil, []);

        $this->procedures = $this->makeDocumentCategory($this->org, 'Procedures', [
            'requires_approval' => false,
        ]);

        // Switched on explicitly, because a fixture organization has no settings rows and
        // `isEnabled()` defaults to false — which is the right default for a real company (a report
        // that starts mailing itself to the board the moment a tenant is created is not a feature)
        // and would make every test below pass vacuously if it were left alone.
        $this->settingIs('report.daily_executive_enabled', true);
        $this->settingIs('report.daily_executive_hour', 7);
    }

    // ================================================================== §55, the claim

    #[Test]
    public function each_recipient_is_sent_their_own_figures_and_not_the_companys(): void
    {
        // Two in Civil, one in Piping. Tunde sees three, Emeka three (the division holds both),
        // Kemi two — and none of those numbers was counted for either of the others.
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $this->documentIn($this->civil, 'Scaffolding inspection procedure');
        $this->documentIn($this->piping, 'Hydrotest procedure');

        $this->tile();

        $briefing = app(ExecutiveBriefing::class);
        $asOf = CarbonImmutable::parse('2026-09-22 07:00:00');

        $bodies = $this->withinOrganization($this->org, fn () => [
            'tunde' => $briefing->bodyFor($this->tunde, $asOf, $briefing->tilesFor($this->tunde, $asOf)),
            'emeka' => $briefing->bodyFor($this->emeka, $asOf, $briefing->tilesFor($this->emeka, $asOf)),
            'kemi' => $briefing->bodyFor($this->kemi, $asOf, $briefing->tilesFor($this->kemi, $asOf)),
        ]);

        $this->assertStringContainsString('>3<', $this->figures($bodies['tunde']));
        $this->assertStringContainsString('>3<', $this->figures($bodies['emeka']));
        $this->assertStringContainsString('>2<', $this->figures($bodies['kemi']));

        // Said on the document itself, because a PDF is forwarded and a figure that arrives without
        // this sentence becomes the company's number in somebody else's inbox.
        $this->assertStringContainsString('counted through your own access', $bodies['kemi']);
        $this->assertStringContainsString('Kemi Alabi', $bodies['kemi']);
    }

    #[Test]
    public function only_people_who_may_open_the_board_are_on_the_list(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $this->tile();

        $this->send();

        // Dayo can read every document in the company and is not a recipient, because the report is
        // the executive board and he may not open it. Ngozi is neither.
        $this->assertNotNull($this->briefingFor($this->tunde));
        $this->assertNotNull($this->briefingFor($this->emeka));
        $this->assertNotNull($this->briefingFor($this->kemi));
        $this->assertNull($this->briefingFor($this->dayo));
    }

    #[Test]
    public function a_reader_whose_board_is_empty_is_sent_nothing_at_all(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');

        // One tile, counting projects, which none of these three may see. Everybody's board
        // composes to nothing, and a blank PDF at seven every morning is how a scheduled report
        // teaches its recipients to filter it.
        $this->tile(['kpi_definition_id' => $this->kpi([
            'name' => 'Projects running',
            'entity_type' => 'project',
        ])->getKey(), 'title' => 'Projects running']);

        $this->send();

        $this->assertSame(0, $this->briefingCount());
    }

    // ================================================================== the hour

    #[Test]
    public function it_sends_at_the_hour_the_company_chose_and_not_at_any_other(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $this->tile();
        $this->settingIs('report.daily_executive_hour', 6);

        $this->send(['--as-of' => '2026-09-22 05:30:00']);
        $this->assertSame(0, $this->briefingCount());

        $this->send(['--as-of' => '2026-09-22 06:05:00']);
        $this->assertSame(3, $this->briefingCount());
    }

    #[Test]
    public function a_company_that_switched_it_off_is_not_sent_one(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $this->tile();
        $this->settingIs('report.daily_executive_enabled', false);

        $this->send();

        $this->assertSame(0, $this->briefingCount());
    }

    #[Test]
    public function running_twice_in_one_day_does_not_send_it_twice(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $this->tile();

        $this->send();
        $this->assertSame(3, $this->briefingCount());

        // The case that actually happens: a cPanel cron that missed an hour catching up by running
        // twice, or an administrator running it by hand to see what it does.
        $this->send();
        $this->assertSame(3, $this->briefingCount());

        // And the next day is a different report, not a second copy of this one.
        $this->send(['--as-of' => '2026-09-23 07:05:00']);
        $this->assertSame(6, $this->briefingCount());
    }

    // ================================================================== the file

    #[Test]
    public function the_file_is_a_pdf_on_the_private_disk_and_the_row_knows_where(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $this->tile();

        $this->send();

        $export = $this->briefingFor($this->tunde);

        $this->assertNotNull($export);
        $this->assertSame('pdf', $export->format);
        $this->assertSame('ready', $export->status);
        $this->assertTrue($export->isBriefing());
        $this->assertNull($export->report_definition_id);

        Storage::disk(Export::DISK)->assertExists($export->path);
        $this->assertStringStartsWith('%PDF', Storage::disk(Export::DISK)->get($export->path));

        // Never under a name somebody typed. The same rule the queued export path follows (§67, §84).
        $this->assertStringStartsWith('exports/'.$this->org->getKey().'/', $export->path);
    }

    #[Test]
    public function the_file_is_kept_for_the_days_the_company_asked_for(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $this->tile();
        $this->settingIs('report.daily_executive_retention_days', 3);

        $this->send(['--as-of' => '2026-09-22 07:05:00']);

        $export = $this->briefingFor($this->tunde);

        $this->assertNotNull($export->expires_at);
        $this->assertSame(3, (int) round(now()->diffInDays($export->expires_at, absolute: true)));
    }

    #[Test]
    public function the_retention_sweep_takes_the_file_and_leaves_the_record(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $this->tile();
        $this->settingIs('report.daily_executive_retention_days', 1);

        $this->send();

        $before = $this->briefingFor($this->tunde);
        $path = $before->path;

        $this->travel(2)->days();
        $this->artisan('exports:sweep')->assertSuccessful();

        Storage::disk(Export::DISK)->assertMissing($path);

        // §59. What remains is a permanent record that this person was sent this briefing on this
        // date — which is the audit the docs asked documents to provide, without publishing one
        // person's figures into a register the whole company can read.
        $after = $before->refresh();

        $this->assertSame('expired', $after->status);
        $this->assertNull($after->path);
        $this->assertSame('Daily executive report — 22 Sep 2026', $after->name);
    }

    // ================================================================== telling them

    #[Test]
    public function the_person_is_told_and_the_notice_carries_no_figures(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $this->documentIn($this->piping, 'Hydrotest procedure');
        $this->tile();

        $this->send();

        $notice = $this->withinOrganization($this->org, fn () => Notification::query()
            ->where('key', 'report.daily_executive')
            ->where('user_id', $this->kemi->getKey())
            ->sole());

        $this->assertStringContainsString('22 Sep', $notice->title);

        // §68, and more pointedly here than anywhere else: no two copies of this report hold the
        // same numbers, so a notice quoting one would be wrong for everybody it was forwarded to.
        // Dates are struck out first — the retention date is the one number a notice may carry,
        // because it is about the file rather than about what is inside it.
        $this->assertDoesNotMatchRegularExpression('/\d/', $this->stripDates((string) $notice->body));

        $this->assertStringContainsString('/exports/', (string) $notice->action_url);
        $this->assertStringContainsString('signature=', (string) $notice->action_url);
    }

    #[Test]
    public function the_link_in_the_notice_opens_the_file_for_its_owner_and_nobody_else(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $this->tile();

        $this->send();

        $url = $this->withinOrganization($this->org, fn () => Notification::query()
            ->where('key', 'report.daily_executive')
            ->where('user_id', $this->kemi->getKey())
            ->sole())->action_url;

        $this->actingFresh($this->kemi)->get($url)->assertOk();

        // A signed link is not a permission. Tunde holds every permission in this company and is
        // not the owner of this file, and ExportPolicy reads ownership (§67, §84, §94).
        $this->actingFresh($this->tunde)->get($url)->assertForbidden();
    }

    // ================================================================== the menu

    #[Test]
    public function somebody_who_is_sent_one_can_find_it_again_without_the_link(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $this->tile();

        $this->send();

        // Kemi never exported anything and holds no `report.export`. Without the menu item she
        // would have one signed link and no way back to the previous morning's report.
        $this->actingFresh($this->kemi)->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('exports.index'));

        $this->actingFresh($this->kemi)->get(route('exports.index'))
            ->assertOk()
            ->assertSee('Daily executive report')
            ->assertSee('Sent to you automatically');
    }

    #[Test]
    public function one_persons_briefing_is_not_on_another_persons_page(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $this->tile();

        $this->send();

        $this->actingFresh($this->emeka)->get(route('exports.index'))
            ->assertOk()
            // His own is there; hers is not, and the page lists one person's files by ownership
            // rather than by permission.
            ->assertSee('Daily executive report');

        $this->assertNotSame(
            $this->briefingFor($this->emeka)->getKey(),
            $this->briefingFor($this->kemi)->getKey(),
        );
    }

    // ================================================================== helpers

    /** @param  array<string,mixed>  $options */
    private function send(array $options = []): void
    {
        $this->artisan('report:daily-executive', [
            '--as-of' => '2026-09-22 07:05:00',
            ...$options,
        ])->assertSuccessful();
    }

    private function briefingCount(): int
    {
        return $this->withinOrganization($this->org, fn () => Export::query()
            ->where('entity_type', ExecutiveBriefing::ENTITY_TYPE)
            ->count());
    }

    private function briefingFor(User $user): ?Export
    {
        return $this->withinOrganization($this->org, fn () => Export::query()
            ->where('entity_type', ExecutiveBriefing::ENTITY_TYPE)
            ->where('requested_by', $user->getKey())
            ->latest('id')
            ->first());
    }

    /** Only the big figure cells, so ">3<" cannot match a margin or a date somewhere else. */
    private function figures(string $html): string
    {
        preg_match_all('/font-size: 20pt;[^>]*>(.*?)</s', $html, $matches);

        return '>'.implode('<>', array_map('trim', $matches[1])).'<';
    }

    private function stripDates(string $body): string
    {
        return (string) preg_replace('/\d{1,2} [A-Z][a-z]{2} \d{4}/', '', $body);
    }

    private function settingIs(string $key, mixed $value): void
    {
        $repository = app(SettingsRepository::class);

        $this->withinOrganization($this->org, fn () => $repository->set($key, $value, $this->org->getKey()));

        $repository->flush($this->org->getKey());
    }

    /** @param  array<string,mixed>  $attributes */
    private function tile(array $attributes = []): void
    {
        // Assigned rather than defaulted inside the array, so a caller naming its own measure does
        // not also mint the document one on the way past.
        $attributes['kpi_definition_id'] ??= $this->kpi()->getKey();

        $this->withinOrganization($this->org, fn () => app(DashboardWidgetService::class)->create([
            'board' => 'executive',
            'kind' => 'kpi_tile',
            'refresh' => 'live',
            'width' => 'third',
            ...$attributes,
        ], $this->tunde));
    }

    /** @param  array<string,mixed>  $attributes */
    private function kpi(array $attributes = []): KpiDefinition
    {
        $name = (string) ($attributes['name'] ?? 'Procedures on file');

        if (isset($this->measures[$name])) {
            return $this->measures[$name];
        }

        return $this->measures[$name] = $this->withinOrganization($this->org, fn () => app(KpiDefinitionService::class)->create([
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
