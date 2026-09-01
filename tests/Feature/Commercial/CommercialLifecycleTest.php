<?php

declare(strict_types=1);

namespace Tests\Feature\Commercial;

use App\Models\Contract;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\Status;
use App\Models\User;
use App\Services\ContractService;
use App\Services\VendorService;
use App\Support\Enums\OrgUnitType;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsCommercial;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * The rules the three commercial registers exist to enforce.
 *
 * {@see CommercialAccessTest} guards the boundary; this file guards the arithmetic and the states.
 * The distinction matters because these are the failures that do not look like failures — nothing
 * 500s, nobody is denied anything, and the register simply starts telling people something untrue:
 *
 *   · A VENDOR WHOSE PREQUALIFICATION LAPSED still appearing as available to order from. Nothing
 *     dramatic happens on the expiry date; the certificate runs out, nobody notices, and the
 *     failure surfaces on the morning an order is urgent.
 *
 *   · RETENTION DRIFTING BELOW ZERO. A release posted against the wrong contract looks exactly like
 *     a legitimate credit on the one it lands on, so the refusal is asserted rather than assumed.
 *
 *   · A CLIENT-SIDE CONTRACT NAMING A SUBCONTRACTOR. One wrong row and "what do we owe" silently
 *     contains a receivable. Enforced in the request AND the service, so both are walked.
 *
 *   · A RATING THAT REPLACES RATHER THAN MOVES. One poor delivery should shift a long-standing
 *     supplier's score, not erase five years of it.
 */
final class CommercialLifecycleTest extends TestCase
{
    use BuildsCommercial, BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $unit;

    private User $tunde;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Meridian Engineering');
        $this->unit = $this->makeUnit($this->org, 'Commercial', OrgUnitType::Division);

        $this->tunde = $this->makePerson(
            $this->org,
            'Tunde Bello',
            $this->unit,
            [$this->makeRole($this->org, 'Commercial Manager', $this->grants())],
        );
    }

    // ================================================================== vendors

    #[Test]
    public function a_vendor_with_no_assessment_recorded_is_still_orderable(): void
    {
        $vendor = $this->makeVendor($this->org, $this->tunde, 'Delta Fabrication');

        // PINNING A DELIBERATE PERMISSIVE DEFAULT, not endorsing it. `canBeUsed()` bars the states
        // somebody positively caused — blacklisted, inactive, suspended, archived — plus a
        // prequalification that has RUN OUT. It does not bar a vendor nobody has assessed yet,
        // because the platform cannot assume every tenant runs a prequalification process, and the
        // alternative empties a company's supplier list on the day this column ships. It is the
        // same reasoning Vendor::prequalificationHasExpired() spells out for a null expiry date.
        //
        // The consequence is that "approved" is not a gate here. If procurement needs one it
        // belongs in a per-organization setting read by THIS method, so every caller gets one
        // answer — not a second hand-rolled status check in the purchase-order service.
        $this->assertTrue($vendor->canBeUsed());
        $this->assertNull($vendor->unusableReason());
    }

    #[Test]
    public function archiving_a_vendor_takes_them_off_the_orderable_list(): void
    {
        $vendor = $this->makeApprovedVendor($this->org, $this->tunde, 'Delta Fabrication');

        $archived = $this->withinOrganization($this->org, fn () => Status::query()
            ->where('entity_type', 'vendor')->where('key', 'archived')->firstOrFail());

        $this->withinOrganization($this->org, fn () => $vendor
            ->forceFill(['status_id' => $archived->id])->save());

        // Read from the status row's own is_terminal flag rather than from its key, so a tenant
        // that renamed "archived" to "off the register" keeps the behaviour (§63).
        //
        // Asserted inside the tenant because reaching the flag means loading the status relation,
        // and OrganizationScope fails closed outside a tenant context — a vendor whose status the
        // caller cannot see reads as having no status at all, which is the safe answer for a scope
        // and the wrong one for this assertion.
        $this->withinOrganization($this->org, function () use ($vendor) {
            $vendor->refresh()->loadMissing('status');

            $this->assertFalse($vendor->canBeUsed());
            $this->assertSame('Not approved', $vendor->unusableReason());
        });
    }

    #[Test]
    public function a_lapsed_prequalification_takes_a_vendor_out_of_use(): void
    {
        $vendor = $this->makeVendor($this->org, $this->tunde, 'Delta Fabrication', [
            'prequalified_on' => now()->subYears(2)->toDateString(),
            'prequalification_expires_on' => now()->subDay()->toDateString(),
        ]);

        $this->assertTrue($vendor->prequalificationHasExpired());
        $this->assertSame('Prequalification expired', $vendor->unusableReason());

        // And the register says so on the face of the row, not only in a method nobody calls.
        $this->actingFresh($this->tunde)->get(route('vendors.index', ['view' => 'all']))
            ->assertOk()
            ->assertSee('Prequalification expired');
    }

    #[Test]
    public function the_expiring_view_warns_before_the_date_rather_than_on_it(): void
    {
        $this->makeVendor($this->org, $this->tunde, 'Warning Soon', [
            'prequalification_expires_on' => now()->addDays(20)->toDateString(),
        ]);

        $this->makeVendor($this->org, $this->tunde, 'Fine For Ages', [
            'prequalification_expires_on' => now()->addYears(2)->toDateString(),
        ]);

        // Warning ahead of the date is the entire value of holding the date; a register that turns
        // red on the day is a register that tells you about a problem you can no longer prevent.
        $this->actingFresh($this->tunde)->get(route('vendors.index', ['view' => 'expiring']))
            ->assertOk()
            ->assertSee('Warning Soon')
            ->assertDontSee('Fine For Ages');
    }

    #[Test]
    public function blacklisting_demands_a_reason_and_bars_the_vendor(): void
    {
        $vendor = $this->makeApprovedVendor($this->org, $this->tunde, 'Delta Fabrication');

        $this->actingFresh($this->tunde)
            ->post(route('vendors.blacklist', $vendor), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->actingFresh($this->tunde)->post(route('vendors.blacklist', $vendor), [
            'reason' => 'Certificates supplied against PO-0231 found to be falsified.',
        ])->assertRedirect();

        $vendor->refresh();

        $this->assertTrue((bool) $vendor->is_blacklisted);
        $this->assertSame('Blacklisted', $vendor->unusableReason());
        $this->assertNotNull($vendor->blacklisted_at);
        $this->assertSame($this->tunde->id, $vendor->blacklisted_by);

        // The reason is what the register shows anybody who later asks why, so it is stored
        // rather than merely required.
        $this->assertStringContainsString('falsified', (string) $vendor->blacklist_reason);
    }

    #[Test]
    public function a_blacklisted_vendor_cannot_be_blacklisted_again(): void
    {
        $vendor = $this->makeApprovedVendor($this->org, $this->tunde, 'Delta Fabrication');

        $this->actingFresh($this->tunde)->post(route('vendors.blacklist', $vendor), [
            'reason' => 'First reason.',
        ])->assertRedirect();

        // Otherwise the second reason silently overwrites the first, and the record of WHY the
        // company was barred becomes whatever the last person typed.
        $this->actingFresh($this->tunde)->post(route('vendors.blacklist', $vendor), [
            'reason' => 'Second reason.',
        ])->assertForbidden();

        $this->assertStringContainsString('First reason', (string) $vendor->refresh()->blacklist_reason);
    }

    #[Test]
    public function a_rating_moves_the_mean_rather_than_replacing_it(): void
    {
        $vendor = $this->makeApprovedVendor($this->org, $this->tunde, 'Delta Fabrication');

        $vendors = app(VendorService::class);

        $this->withinOrganization($this->org, function () use ($vendors, $vendor) {
            $vendors->recordRating($vendor, 5.0, $this->tunde, 'Faultless on the first three orders.');
            $vendors->recordRating($vendor, 5.0, $this->tunde);
            $vendors->recordRating($vendor, 5.0, $this->tunde);
            $vendors->recordRating($vendor, 1.0, $this->tunde, 'Delivered three weeks late.');
        });

        $vendor->refresh();

        // Four assessments, mean 4.0. One bad delivery moves a long-standing supplier's score; it
        // does not erase what came before it.
        $this->assertSame(4, $vendor->rated_count);
        $this->assertEqualsWithDelta(4.0, (float) $vendor->rating, 0.01);
    }

    #[Test]
    public function bank_details_are_masked_down_to_the_last_four(): void
    {
        $vendor = $this->makeVendor($this->org, $this->tunde, 'Delta Fabrication');

        $this->withinOrganization($this->org, fn () => app(VendorService::class)
            ->updateBankDetails($vendor, ['bank_account_number' => '4471900023'], $this->tunde, 'Onboarding.'));

        $masked = $vendor->refresh()->maskedAccountNumber();

        // Enough to confirm you are looking at the right supplier; not enough to pay anybody.
        $this->assertStringEndsWith('0023', $masked);
        $this->assertStringNotContainsString('44719', $masked);
    }

    // ================================================================== contracts

    #[Test]
    public function a_client_side_contract_cannot_name_a_vendor(): void
    {
        $vendor = $this->makeVendor($this->org, $this->tunde, 'Delta Fabrication');

        // Caught by ContractRequest before it reaches the service — the two fields disagree.
        $this->actingFresh($this->tunde)->post(route('contracts.store'), [
            'title' => 'Wrong way round',
            'direction' => 'client',
            'party_type' => 'vendor',
            'party_id' => $vendor->id,
            'confidentiality' => 'confidential',
        ])->assertSessionHasErrors('party_type');

        $this->assertDatabaseMissing('contracts', ['title' => 'Wrong way round']);
    }

    #[Test]
    public function the_service_refuses_the_same_mismatch_on_its_own(): void
    {
        $vendor = $this->makeVendor($this->org, $this->tunde, 'Delta Fabrication');

        // The request is one door. The service is reached from seeders, imports and other modules,
        // so the rule is enforced where the data is written and not only where it is typed (§84).
        $this->expectException(\DomainException::class);

        $this->withinOrganization($this->org, fn () => app(ContractService::class)->create([
            'title' => 'Wrong way round',
            'direction' => 'client',
            'party_type' => 'vendor',
            'party_id' => $vendor->id,
        ], $this->tunde));
    }

    #[Test]
    public function a_contract_keeps_the_name_written_on_the_paper(): void
    {
        $vendor = $this->makeVendor($this->org, $this->tunde, 'Delta Fabrication Limited');

        $contract = $this->makeContract($this->org, $this->tunde, 'Steelwork subcontract', [
            'direction' => 'vendor',
            'party_type' => 'vendor',
            'party_id' => $vendor->id,
        ]);

        $this->assertSame('Delta Fabrication Limited', $contract->party_name);

        // The register row is renamed — a rebrand, an acquisition. The contract must not start
        // claiming it was signed with a company that did not exist on the day.
        $this->withinOrganization($this->org, fn () => $vendor->forceFill(['name' => 'Deltafab Group'])->save());

        $this->assertSame('Delta Fabrication Limited', $contract->refresh()->party_name);
    }

    #[Test]
    public function a_new_contract_starts_confidential_even_when_nobody_said_so(): void
    {
        // Created straight through the service, the way a seeder or an import would. The migration
        // default never fires here — the service stamps a value — so this is the assertion that
        // keeps the two in step.
        $contract = $this->makeContract($this->org, $this->tunde, 'Quietly created');

        $this->assertSame('confidential', $contract->confidentiality->value);
    }

    #[Test]
    public function retention_is_held_and_released_and_never_goes_negative(): void
    {
        $contract = $this->makeContract($this->org, $this->tunde, 'Main works', ['value' => 10000000]);

        $contracts = app(ContractService::class);

        $this->withinOrganization($this->org, function () use ($contracts, $contract) {
            $contracts->addRetention($contract, 250000, $this->tunde, 'Valuation 1.');
            $contracts->addRetention($contract, 150000, $this->tunde, 'Valuation 2.');
            $contracts->releaseRetention($contract, 100000, $this->tunde, 'Half of first moiety.');
        });

        $this->assertEqualsWithDelta(300000.0, (float) $contract->refresh()->retention_held, 0.01);

        // Refused rather than clamped. A balance that silently floors at zero is a balance that
        // stops reconciling with the certificates behind it, which is worse than an error.
        try {
            $this->withinOrganization($this->org, fn () => $contracts
                ->releaseRetention($contract, 500000, $this->tunde));

            $this->fail('Releasing more retention than is held should have been refused.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('300,000.00', $e->getMessage());
        }

        $this->assertEqualsWithDelta(300000.0, (float) $contract->refresh()->retention_held, 0.01);
    }

    #[Test]
    public function the_retention_endpoint_refuses_an_over_release_without_a_500(): void
    {
        $contract = $this->makeContract($this->org, $this->tunde, 'Main works');

        $this->withinOrganization($this->org, fn () => app(ContractService::class)
            ->addRetention($contract, 50000, $this->tunde, 'Valuation 1.'));

        $this->actingFresh($this->tunde)->post(route('contracts.retention.move', $contract), [
            'movement' => 'release',
            'amount' => 90000,
        ])->assertSessionHasErrors('amount');

        $this->assertEqualsWithDelta(50000.0, (float) $contract->refresh()->retention_held, 0.01);
    }

    #[Test]
    public function the_retention_balance_cannot_be_set_from_the_edit_form(): void
    {
        $contract = $this->makeContract($this->org, $this->tunde, 'Main works');

        $this->withinOrganization($this->org, fn () => app(ContractService::class)
            ->addRetention($contract, 50000, $this->tunde));

        // `retention_held` is on the service's protected list, so it arrives and is discarded.
        // A balance that can be retyped is a balance nobody can reconcile (§59).
        $this->actingFresh($this->tunde)->put(route('contracts.update', $contract), [
            'title' => 'Main works',
            'direction' => 'client',
            'confidentiality' => 'confidential',
            'retention_held' => 999999,
        ])->assertRedirect();

        $this->assertEqualsWithDelta(50000.0, (float) $contract->refresh()->retention_held, 0.01);
    }

    #[Test]
    public function the_defects_liability_date_is_computed_from_the_end_date(): void
    {
        $contract = $this->makeContract($this->org, $this->tunde, 'Main works', [
            'ends_on' => '2027-03-31',
            'defects_liability_months' => 12,
        ]);

        $this->assertSame('2028-03-31', $contract->defectsLiabilityEndsOn()?->toDateString());

        // No end date means no computed answer — not a guess anchored on today.
        $open = $this->makeContract($this->org, $this->tunde, 'Framework', [
            'defects_liability_months' => 12,
        ]);

        $this->assertNull($open->defectsLiabilityEndsOn());
    }

    #[Test]
    public function a_contract_value_is_never_a_searchable_field(): void
    {
        $this->makeContract($this->org, $this->tunde, 'Main works', ['value' => 48750000]);

        // §61: searching by value would let anybody who can list contracts bisect their way to a
        // figure they may not read. The register's condition fields exclude it for the same reason.
        $this->assertNotContains('value', (new Contract)->registerSearchColumns());
        $this->assertNotContains('value', (new Contract)->registerConditionFields());
    }

    // ================================================================== contacts

    #[Test]
    public function only_one_contact_at_a_time_is_primary(): void
    {
        $vendor = $this->makeVendor($this->org, $this->tunde, 'Delta Fabrication');

        $this->actingFresh($this->tunde)->post(route('vendors.contacts.store', $vendor), [
            'name' => 'Ada Eze',
            'is_primary' => '1',
        ])->assertRedirect();

        $this->actingFresh($this->tunde)->post(route('vendors.contacts.store', $vendor), [
            'name' => 'Bola Ige',
            'is_primary' => '1',
        ])->assertRedirect();

        // Two primary contacts is the state that makes a register useless for "who do we call",
        // so it cannot be held: the second promotion demotes the first in the same transaction.
        $primaries = $this->withinOrganization($this->org, fn () => $vendor->contacts()
            ->where('is_primary', true)->pluck('name')->all());

        $this->assertSame(['Bola Ige'], $primaries);
    }

    #[Test]
    public function retiring_a_contact_keeps_them_readable(): void
    {
        $vendor = $this->makeVendor($this->org, $this->tunde, 'Delta Fabrication');

        $this->actingFresh($this->tunde)->post(route('vendors.contacts.store', $vendor), [
            'name' => 'Ada Eze',
        ])->assertRedirect();

        $contact = $this->withinOrganization($this->org, fn () => $vendor->contacts()->firstOrFail());

        $this->actingFresh($this->tunde)
            ->delete(route('vendor-contacts.destroy', $contact))
            ->assertRedirect();

        // People leave. The transmittal that named them two years ago still has to render, so a
        // retired contact drops out of the pickers and stays on the record.
        $this->assertDatabaseHas('vendor_contacts', ['id' => $contact->id, 'is_active' => false]);
    }

    // ================================================================== fixtures

    /** @return array<string,string> */
    private function grants(): array
    {
        $grants = [
            'org_unit.view' => 'organization',
            'project.view' => 'organization',
            'project.view_financials' => 'organization',
            'document.view' => 'organization',
        ];

        foreach (['vendor', 'client', 'contract'] as $module) {
            foreach (['view', 'create', 'edit', 'delete', 'manage'] as $ability) {
                $grants[$module.'.'.$ability] = 'organization';
            }
        }

        $grants['vendor.evaluate'] = 'organization';
        $grants['vendor.blacklist'] = 'organization';
        $grants['vendor.bank'] = 'organization';
        $grants['contract.approve'] = 'organization';
        $grants['contract.view_confidential'] = 'organization';

        return $grants;
    }
}
