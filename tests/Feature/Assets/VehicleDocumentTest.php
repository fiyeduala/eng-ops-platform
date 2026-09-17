<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Events\VehicleDocumentExpiring;
use App\Models\Organization;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Services\VehicleDocumentService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsAssets;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

final class VehicleDocumentTest extends TestCase
{
    use BuildsAssets, BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private User $manager;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = $this->makeOrganization('Certificate tests');
        $this->manager = $this->makePerson($this->org, 'Manager', null, [
            $this->makeRole($this->org, 'Fleet manager', $this->fleetGrants()),
        ]);
        $this->vehicle = $this->makeVehicle($this->org, $this->makeAsset($this->org, $this->manager), $this->manager);
    }

    private function expired(): VehicleDocument
    {
        return $this->makeCertificate($this->org, $this->vehicle, $this->manager, [
            'document_number' => 'OLD-INSURANCE', 'expires_on' => now()->subDays(10)->toDateString(),
        ]);
    }

    #[Test]
    public function renewal_preserves_history_but_removes_the_old_certificate_from_alerts_and_worklists(): void
    {
        $old = $this->expired();
        $this->actingFresh($this->manager)->post(route('vehicle-documents.renew', $old), [
            'document_type' => 'insurance', 'document_number' => 'NEW-INSURANCE',
            'issued_on' => now()->toDateString(), 'expires_on' => now()->addYear()->toDateString(),
            'confidentiality' => 'internal',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->withinOrganization($this->org, function () use ($old) {
            $old->refresh();
            $this->assertTrue($old->hasExpired());
            $this->assertFalse($old->isCurrent());
            $this->assertSame('OLD-INSURANCE', $old->document_number);
            $this->assertSame(1, VehicleDocument::where('renews_id', $old->id)->count());
            $this->vehicle->load('documents');
            $this->assertFalse($this->vehicle->hasLapsedDocuments());
            Event::fake([VehicleDocumentExpiring::class]);
            $this->assertSame(0, app(VehicleDocumentService::class)->sweepExpiring($this->org->id));
            Event::assertNotDispatched(VehicleDocumentExpiring::class);
        });
        $this->actingFresh($this->manager)->get(route('vehicle-documents.index'))->assertOk()->assertDontSee('OLD-INSURANCE');
        $this->actingFresh($this->manager)->get(route('vehicle-documents.index', ['view' => 'all']))
            ->assertOk()->assertSee('OLD-INSURANCE')->assertSee('NEW-INSURANCE');
        $this->actingFresh($this->manager)->get(route('vehicles.index'))->assertOk()->assertDontSee($this->vehicle->registration_number);
        $this->actingFresh($this->manager)->get(route('vehicles.show', $this->vehicle))
            ->assertOk()->assertDontSee('This vehicle should not leave the yard.');
        $renewal = $this->withinOrganization($this->org, fn () => VehicleDocument::where('renews_id', $old->id)->firstOrFail());
        $this->actingFresh($this->manager)->delete(route('vehicle-documents.destroy', $old))->assertForbidden();
        $this->actingFresh($this->manager)->delete(route('vehicle-documents.destroy', $renewal))->assertForbidden();
    }

    #[Test]
    public function a_future_renewal_does_not_hide_a_current_gap_in_cover(): void
    {
        $old = $this->expired();
        $effective = now()->addDays(5)->startOfDay();
        $this->withinOrganization($this->org, function () use ($old, $effective) {
            app(VehicleDocumentService::class)->renew($old, [
                'issued_on' => $effective->toDateString(), 'expires_on' => now()->addYear()->toDateString(),
            ], $this->manager);
            $this->vehicle->load('documents');
            $this->assertTrue($this->vehicle->hasLapsedDocuments());
            Event::fake([VehicleDocumentExpiring::class]);
            $this->assertSame(1, app(VehicleDocumentService::class)->sweepExpiring($this->org->id));
            $this->travelTo($effective);
            $this->assertFalse($this->vehicle->hasLapsedDocuments());
            $this->assertSame(0, app(VehicleDocumentService::class)->sweepExpiring($this->org->id));
            $this->travelBack();
        });
    }

    #[Test]
    public function stale_renewal_attempts_cannot_create_two_replacements(): void
    {
        $old = $this->expired();
        $stale = clone $old;
        $this->withinOrganization($this->org, function () use ($old, $stale) {
            $service = app(VehicleDocumentService::class);
            $service->renew($old, ['expires_on' => now()->addYear()->toDateString()], $this->manager);
            try {
                $service->renew($stale, ['expires_on' => now()->addYears(2)->toDateString()], $this->manager);
                $this->fail('The same certificate was renewed twice.');
            } catch (\DomainException $e) {
                $this->assertStringContainsString('already been renewed', $e->getMessage());
            }
            $this->assertSame(2, VehicleDocument::count());
        });
    }

    #[Test]
    public function renewal_does_not_downgrade_classification_and_linked_history_cannot_be_deleted(): void
    {
        $old = $this->expired();
        $renewal = $this->withinOrganization($this->org, function () use ($old) {
            $old->forceFill(['confidentiality' => 'confidential'])->save();

            return app(VehicleDocumentService::class)->renew($old, [
                'confidentiality' => 'internal', 'expires_on' => now()->addYear()->toDateString(),
            ], $this->manager);
        });
        $this->assertSame('confidential', $renewal->confidentiality->value);
        $this->actingFresh($this->manager)->delete(route('vehicle-documents.destroy', $old))->assertForbidden();
        $this->actingFresh($this->manager)->delete(route('vehicle-documents.destroy', $renewal))->assertForbidden();
        $internal = $this->expired();
        $morePrivate = $this->withinOrganization($this->org, fn () => app(VehicleDocumentService::class)->renew(
            $internal, ['confidentiality' => 'confidential', 'expires_on' => now()->addYear()->toDateString()], $this->manager,
        ));
        $this->assertSame('confidential', $morePrivate->confidentiality->value);
    }

    #[Test]
    public function a_scan_download_checks_the_assets_restrictions_before_serving_bytes(): void
    {
        Storage::fake('local');
        $document = $this->makeCertificate($this->org, $this->vehicle, $this->manager);
        $this->withinOrganization($this->org, function () use ($document) {
            app(VehicleDocumentService::class)->attachFile($document, UploadedFile::fake()->image('certificate.png'), $this->manager);
        });
        $this->actingFresh($this->manager)->get(route('vehicle-documents.download', $document))->assertOk();
        $this->withinOrganization($this->org, fn () => $this->vehicle->asset()->firstOrFail()->forceFill(['confidentiality' => 'confidential'])->save());
        $this->actingFresh($this->manager)->get(route('vehicle-documents.download', $document))->assertForbidden();
        Storage::disk('local')->assertExists($document->file_path);
    }
}
