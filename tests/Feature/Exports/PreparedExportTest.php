<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Engines\Access\OrganizationContext;
use App\Engines\Configuration\SettingsRepository;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Report\ExportManager;
use App\Jobs\GenerateExport;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\Export;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\ReportDefinition;
use App\Models\User;
use App\Services\ReportDefinitionService;
use App\Support\Enums\OrgUnitType;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * A file too large to hand over while somebody waits (Phase 8, Part 5).
 *
 * THE BACKGROUND PATH IS A SECOND WAY TO PRODUCE AN EXPORT, AND THAT IS THE DANGER IN IT. Phase 7
 * settled what an export is allowed to contain — the exporter's own rows, re-run at the moment of
 * the click, audited with its filters and count — and every one of those answers now has to hold in
 * a process with no session, no signed-in user and no ambient tenant. A queued export that was one
 * per cent wider than a direct one would be the platform's most useful permission bypass, because it
 * is the path taken by exactly the large company-wide reports somebody would want to bypass with.
 *
 * SO THE TESTS HERE ARE MOSTLY ABOUT SAMENESS. The same rows, the same audit entry, the same refusal
 * for the person who may not export — reached down a different road.
 *
 * AND THEN ONE THING PHASE 7 NEVER HAD TO ANSWER: the file now exists. It sits on disk, readable by
 * anybody who can reach the path, long after the access that produced it was withdrawn. Ownership,
 * the signature, the retention date and the sweep are four answers to that one fact, and each is
 * asserted on its own because each fails on its own.
 */
final class PreparedExportTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $civil;

    private OrgUnit $piping;

    private DocumentCategory $procedures;

    private User $ngozi;

    private User $tunde;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Export::DISK);

        $this->org = $this->makeOrganization('Meridian Engineering');

        $engineering = $this->makeUnit($this->org, 'Engineering', OrgUnitType::Division);
        $this->civil = $this->makeUnit($this->org, 'Civil', OrgUnitType::Department, $engineering);
        $this->piping = $this->makeUnit($this->org, 'Piping', OrgUnitType::Department, $engineering);

        // Ngozi may export and sees only Civil — the same combination Phase 7 used, for the same
        // reason: an exporter whose file must still be narrower than the company.
        $controller = $this->makeRole($this->org, 'Document Controller', [
            'document.view' => 'department',
            'report.view' => 'organization',
            'report.export' => 'organization',
        ]);

        $director = $this->makeRole($this->org, 'Operations Director', [
            'document.view' => 'organization',
            'document.create' => 'organization',
            'report.view' => 'organization',
            'report.create' => 'organization',
            'report.edit' => 'organization',
            'report.export' => 'organization',
            'org_unit.view' => 'organization',
        ]);

        $this->ngozi = $this->makePerson($this->org, 'Ngozi Eze', $this->civil, [$controller]);
        $this->tunde = $this->makePerson($this->org, 'Tunde Bello', null, [$director]);

        $this->procedures = $this->makeDocumentCategory($this->org, 'Procedures', [
            'requires_approval' => false,
        ]);
    }

    // ================================================================== the threshold

    #[Test]
    public function a_small_report_still_downloads_without_touching_the_disk(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');

        $this->thresholdIs(50);

        $this->actingFresh($this->tunde)
            ->get($this->exportUrl($this->report(), 'csv'))
            ->assertOk();

        // The whole argument for the direct path is that the strongest compliance with a rule about
        // stored files is to have no stored file. A threshold that quietly sent everything through
        // the queue would keep working and would throw that away.
        $this->assertSame(0, $this->exportCount());
        $this->assertSame([], Storage::disk(Export::DISK)->allFiles('exports'));
    }

    #[Test]
    public function a_report_over_the_threshold_is_prepared_in_the_background_instead(): void
    {
        Queue::fake();

        $this->documentIn($this->civil, 'Lifting operations procedure');
        $this->documentIn($this->civil, 'Scaffolding inspection procedure');

        $this->thresholdIs(1);

        $report = $this->report();

        $this->actingFresh($this->ngozi)
            ->get($this->exportUrl($report, 'xlsx'))
            ->assertRedirect(route('configuration.reports.show', $report))
            ->assertSessionHas('status');

        $export = $this->onlyExport();

        $this->assertSame('queued', $export->status);
        $this->assertSame('xlsx', $export->format);

        // §74: never from input. The organization and the requester come off the actor, which is
        // why neither appears in the request that created this row.
        $this->assertSame((int) $this->ngozi->getKey(), (int) $export->requested_by);
        $this->assertSame((int) $this->org->getKey(), (int) $export->organization_id);

        Queue::assertPushed(GenerateExport::class);
    }

    #[Test]
    public function the_count_that_decides_is_the_exporters_own(): void
    {
        Queue::fake();

        // Two in Civil, which Ngozi sees; two in Piping, which she does not.
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $this->documentIn($this->civil, 'Scaffolding inspection procedure');
        $this->documentIn($this->piping, 'Hydrotest procedure');
        $this->documentIn($this->piping, 'Line flushing procedure');

        // Three: above what Ngozi can see, below what Tunde can.
        $this->thresholdIs(3);

        $report = $this->report();

        // Her two rows fit, so she gets the file now.
        $this->actingFresh($this->ngozi)->get($this->exportUrl($report, 'csv'))->assertOk();

        // His four do not, so his is prepared in the background. Same report, same threshold, same
        // moment — the decision follows the reader, because the row count does.
        $this->actingFresh($this->tunde)
            ->get($this->exportUrl($report, 'csv'))
            ->assertRedirect(route('configuration.reports.show', $report));

        $export = $this->onlyExport();

        $this->assertSame((int) $this->tunde->getKey(), (int) $export->requested_by);
    }

    #[Test]
    public function the_threshold_cannot_be_set_above_the_row_ceiling(): void
    {
        $this->thresholdIs(999_999);

        $manager = app(ExportManager::class);

        // A company that set the threshold above MAX_ROWS would have switched the background path
        // off without meaning to, and the setting would still read as though it were in force.
        $this->assertSame(
            ReportDefinition::MAX_ROWS,
            $manager->threshold((int) $this->org->getKey()),
        );
    }

    // ================================================================== what the job produces

    #[Test]
    public function the_prepared_file_holds_the_requesters_rows_and_nobody_elses(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $this->documentIn($this->piping, 'Hydrotest procedure');

        $export = $this->prepare($this->ngozi, 'csv');

        $this->assertSame('ready', $export->status);
        $this->assertNotNull($export->path);

        $contents = Storage::disk(Export::DISK)->get($export->path);

        $this->assertStringContainsString('Lifting operations procedure', $contents);

        // The assertion the whole part exists for. The job runs with no session and no signed-in
        // user; if it resolved rows any way other than as the requester, this is where it shows.
        $this->assertStringNotContainsString('Hydrotest procedure', $contents);
    }

    #[Test]
    public function every_offered_format_produces_a_file_that_opens(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');

        // The signatures are checked rather than the contents: a format listed in FORMATS and not
        // handled by the renderer switch fails as "that export format is no longer offered", which
        // is the safe direction to fail in and still a bug.
        $signatures = [
            'csv' => 'Lifting operations procedure',
            'xlsx' => "PK\x03\x04",
            'docx' => "PK\x03\x04",
            'pdf' => '%PDF',
        ];

        foreach (array_keys(ReportDefinition::FORMATS) as $format) {
            $export = $this->prepare($this->ngozi, $format);

            $this->assertSame('ready', $export->status, $format.' did not finish preparing.');
            $this->assertGreaterThan(0, (int) $export->bytes, $format.' produced nothing.');

            $this->assertStringContainsString(
                $signatures[$format],
                Storage::disk(Export::DISK)->get($export->path),
                $format.' did not produce a file of that kind.',
            );
        }
    }

    #[Test]
    public function the_row_count_written_down_is_the_real_one_and_not_the_bounded_count(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $this->documentIn($this->civil, 'Scaffolding inspection procedure');
        $this->documentIn($this->civil, 'Excavation procedure');

        $export = $this->prepare($this->ngozi, 'csv', threshold: 1);

        // Three here plus the one queueFor adds. The count that DECIDED to queue stopped at
        // threshold-plus-one, which is two; writing that number into an audit column would be a lie
        // about how much left the building, and the lie would get smaller as the export got bigger.
        $this->assertSame(4, (int) $export->rows);
    }

    #[Test]
    public function the_person_is_told_when_their_file_is_ready(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');

        $this->prepare($this->ngozi, 'csv');

        $notification = $this->withinOrganization($this->org, fn () => Notification::query()
            ->where('user_id', $this->ngozi->getKey())
            ->where('key', 'export.ready')
            ->sole());

        $this->assertStringContainsString('Procedures on file', $notification->title);

        // §68: the notice says a file is ready and nothing about what is in it. Not a row, not a
        // total, not the conditions — only the report's name, which they chose and already know.
        $this->assertStringNotContainsString('Lifting operations procedure', (string) $notification->body);
    }

    #[Test]
    public function a_failed_export_says_so_rather_than_sitting_at_waiting(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');

        $report = $this->report();
        $export = $this->queueFor($this->ngozi, $report, 'csv');

        // The report is deleted between the click and the worker, which is the ordinary version of
        // "the job cannot do what it was asked".
        $this->withinOrganization($this->org, fn () => $report->delete());

        (new GenerateExport($export->getKey()))->handle(
            app(ExportManager::class),
            app(OrganizationContext::class),
            app(NotificationDispatcher::class),
        );

        $export->refresh();

        $this->assertSame('failed', $export->status);
        $this->assertNotNull($export->failure_reason);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->ngozi->getKey(),
            'key' => 'export.failed',
        ]);
    }

    #[Test]
    public function the_same_export_is_never_produced_twice(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');

        $export = $this->prepare($this->ngozi, 'csv');
        $firstPath = $export->path;

        // `queue:work --stop-when-empty` under a cron can overlap on a slow host. A second run that
        // produced a second file would leave the first orphaned on disk with nothing pointing at it
        // — an export nobody can reach and the sweep will never collect (§67).
        $this->runJob($export);

        $export->refresh();

        $this->assertSame($firstPath, $export->path);
        $this->assertCount(1, Storage::disk(Export::DISK)->allFiles('exports'));
    }

    // ================================================================== who may collect it

    #[Test]
    public function only_the_person_who_asked_may_download_it(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');

        $export = $this->prepare($this->ngozi, 'csv');

        $this->actingFresh($this->ngozi)->get($this->downloadUrl($export))->assertOk();

        // Tunde sees more of the company than Ngozi does, holds every report permission she holds,
        // and is still refused. The file is one person's visibility frozen at one moment; handing
        // it to a wider reader is not generosity, it is the platform answering a question about
        // Tunde with Ngozi's answer.
        $this->actingFresh($this->tunde)->get($this->downloadUrl($export))->assertForbidden();
    }

    #[Test]
    public function an_unsigned_link_is_refused_even_for_its_owner(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');

        $export = $this->prepare($this->ngozi, 'csv');

        $this->actingFresh($this->ngozi)
            ->get(route('exports.download', $export))
            ->assertForbidden();
    }

    #[Test]
    public function a_signed_link_is_not_a_session(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');

        $export = $this->prepare($this->ngozi, 'csv');

        $url = $this->downloadUrl($export);

        // Signed out properly, not merely "not acting as anybody" — `actingAs` leaves a user on the
        // guard, so a test that just stopped calling it would be making this request as Ngozi and
        // would pass while proving nothing.
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        // The difference between a link that leaked and a file that did. A signed URL forwarded out
        // of somebody's inbox reaches the sign-in page (§67).
        $this->get($url)->assertRedirect(route('login'));
    }

    #[Test]
    public function another_companys_export_is_not_reachable_at_all(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');

        $export = $this->prepare($this->ngozi, 'csv');

        $other = $this->makeOrganization('Coastal Fabricators');
        $role = $this->makeRole($other, 'Director', [
            'report.view' => 'organization',
            'report.export' => 'organization',
        ]);
        $outsider = $this->makePerson($other, 'Bunmi Ade', null, [$role]);

        // 404 rather than 403: whether Meridian has an export with this id is itself Meridian's
        // business (§75).
        $this->actingFresh($outsider)->get($this->downloadUrl($export))->assertNotFound();
    }

    #[Test]
    public function the_list_shows_one_persons_files_and_not_the_companys(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');

        $this->prepare($this->ngozi, 'csv');

        $this->actingFresh($this->ngozi)
            ->get(route('exports.index'))
            ->assertOk()
            ->assertSee('Procedures on file');

        // Tunde's page is empty although the company has an export on it. Anything else and this
        // page is a list of what everybody has taken out of the building, which is a different
        // feature with a different permission.
        $this->actingFresh($this->tunde)
            ->get(route('exports.index'))
            ->assertOk()
            ->assertDontSee('Procedures on file');
    }

    // ================================================================== retention

    #[Test]
    public function an_expired_export_is_gone_even_before_the_sweep_runs(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');

        $export = $this->prepare($this->ngozi, 'csv');

        // DELIBERATELY SIGNED FOR A YEAR, which no real link ever is. Signing it for the retention
        // period — as the notification does — would make this test pass on the signature expiring
        // and prove nothing about retention. The point is that the two clocks are separate and the
        // shorter wins: here the signature is still perfectly valid and the file is still refused,
        // because the date the route reads is the one on the row.
        $url = URL::temporarySignedRoute(
            'exports.download',
            now()->addYear(),
            ['export' => $export->getKey()],
        );

        $this->travel(2)->days();

        $this->actingFresh($this->ngozi)->get($url)->assertNotFound();
    }

    #[Test]
    public function the_sweep_deletes_the_file_and_keeps_the_record(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');

        $export = $this->prepare($this->ngozi, 'csv');
        $path = $export->path;

        $this->travel(2)->days();

        $this->artisan('exports:sweep')->assertSuccessful();

        $export->refresh();

        $this->assertFalse(Storage::disk(Export::DISK)->exists($path));
        $this->assertSame('expired', $export->status);
        $this->assertNull($export->path);

        // The row outlives the file on purpose. What left the platform, who took it and how many
        // rows it held is an audit question that outlasts anybody's need for the file (§59) — and
        // deleting the row with it would erase the record at the exact moment the export stopped
        // being observable any other way.
        $this->assertDatabaseHas('exports', [
            'id' => $export->getKey(),
            'requested_by' => $this->ngozi->getKey(),
            'rows' => 2,
        ]);
    }

    #[Test]
    public function a_file_that_is_still_within_retention_is_left_alone(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');

        $export = $this->prepare($this->ngozi, 'csv');

        $this->artisan('exports:sweep')->assertSuccessful();

        $this->assertTrue(Storage::disk(Export::DISK)->exists($export->refresh()->path));
        $this->assertSame('ready', $export->status);
    }

    #[Test]
    public function throwing_one_away_early_deletes_the_file_now(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');

        $export = $this->prepare($this->ngozi, 'csv');
        $path = $export->path;

        $this->actingFresh($this->ngozi)
            ->delete(route('exports.destroy', $export))
            ->assertRedirect(route('exports.index'));

        $this->assertFalse(Storage::disk(Export::DISK)->exists($path));
        $this->assertSame('expired', $export->refresh()->status);
    }

    #[Test]
    public function nobody_else_can_throw_away_somebody_elses_file(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');

        $export = $this->prepare($this->ngozi, 'csv');

        $this->actingFresh($this->tunde)
            ->delete(route('exports.destroy', $export))
            ->assertForbidden();

        $this->assertSame('ready', $export->refresh()->status);
    }

    #[Test]
    public function a_row_that_says_ready_over_a_file_that_is_gone_corrects_itself(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');

        $export = $this->prepare($this->ngozi, 'csv');

        // Cleared out by hand, or lost in a restore that did not carry storage.
        Storage::disk(Export::DISK)->delete($export->path);

        $this->actingFresh($this->ngozi)->get($this->downloadUrl($export))->assertNotFound();

        // Corrected rather than left offering a download that 404s every time somebody tries it.
        $this->assertSame('expired', $export->refresh()->status);
    }

    // ================================================================== the permission

    #[Test]
    public function somebody_who_may_not_export_cannot_reach_either_path(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');

        $reader = $this->makePerson($this->org, 'Chidi Okafor', $this->civil, [
            $this->makeRole($this->org, 'Reader', [
                'document.view' => 'department',
                'report.view' => 'organization',
            ]),
        ]);

        $report = $this->report();

        $this->thresholdIs(1);
        $this->documentIn($this->civil, 'Scaffolding inspection procedure');

        // The threshold is set so this WOULD have queued. `report.export` is checked before either
        // path is chosen, so the size of the report never gets a say in whether it may leave.
        $this->actingFresh($reader)->get($this->exportUrl($report, 'csv'))->assertForbidden();

        $this->assertSame(0, $this->exportCount());
    }

    // ================================================================== helpers

    /**
     * Counting exports has to happen inside the tenant, which is itself the point.
     *
     * OrganizationScope fails closed: with no context bound, a bare `Export::query()` returns
     * nothing rather than everything. So a test asserting "no export was created" would pass
     * whether or not one was, and every isolation assertion below it would be vacuous.
     */
    private function exportCount(): int
    {
        return $this->withinOrganization($this->org, fn () => Export::query()->count());
    }

    private function onlyExport(): Export
    {
        return $this->withinOrganization($this->org, fn () => Export::query()->sole());
    }

    private function thresholdIs(int $rows): void
    {
        app(SettingsRepository::class)->set(
            'report.export_queue_threshold',
            $rows,
            (int) $this->org->getKey(),
        );

        app(SettingsRepository::class)->flush((int) $this->org->getKey());
    }

    /**
     * Queue an export, run the job, and hand back the finished row.
     *
     * A report per format, because two reports may not share a name in one company — which is a rule
     * worth keeping rather than working around, since the name is what the export row copies and
     * what the notification quotes back.
     */
    private function prepare(User $actor, string $format, int $threshold = 1): Export
    {
        $report = $this->report(['name' => 'Procedures on file ('.$format.')']);

        $export = $this->queueFor($actor, $report, $format, $threshold);

        $this->runJob($export);

        return $export->refresh();
    }

    private function queueFor(User $actor, ReportDefinition $report, string $format, int $threshold = 1): Export
    {
        Queue::fake();

        // The background path is taken when the row count EXCEEDS the threshold, and the threshold
        // has a floor of one — so a fixture with a single visible row would quietly download instead
        // of queuing, and every assertion after it about a file on disk would never run. The second
        // row is added here rather than in each test, so no test is one document away from being
        // vacuous. It is visible to Ngozi and to Tunde alike, so it shifts nobody's count relative
        // to anybody else's.
        $this->documentIn($this->civil, 'Confined space entry procedure');

        $this->thresholdIs($threshold);

        $this->actingFresh($actor)
            ->get($this->exportUrl($report, $format))
            ->assertRedirect(route('configuration.reports.show', $report));

        return $this->withinOrganization($this->org, fn () => Export::query()->latest('id')->firstOrFail());
    }

    private function runJob(Export $export): void
    {
        (new GenerateExport($export->getKey()))->handle(
            app(ExportManager::class),
            app(OrganizationContext::class),
            app(NotificationDispatcher::class),
        );
    }

    private function exportUrl(ReportDefinition $report, string $format): string
    {
        return route('configuration.reports.export', ['report' => $report, 'format' => $format]);
    }

    private function downloadUrl(Export $export): string
    {
        return URL::temporarySignedRoute(
            'exports.download',
            $export->expires_at ?? now()->addHour(),
            ['export' => $export->getKey()],
        );
    }

    /** @param  array<string,mixed>  $attributes */
    private function report(array $attributes = []): ReportDefinition
    {
        return $this->withinOrganization($this->org, fn () => app(ReportDefinitionService::class)->create([
            'name' => 'Procedures on file',
            'entity_type' => 'document',
            'columns' => ['title', 'org_unit_id'],
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
