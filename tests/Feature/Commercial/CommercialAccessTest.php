<?php

declare(strict_types=1);

namespace Tests\Feature\Commercial;

use App\Models\ActivityLog;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\User;
use App\Services\VendorService;
use App\Support\Enums\OrgUnitType;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsCommercial;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * The boundary around the three commercial registers (§75, §84, §94).
 *
 * These three registers hold the two most valuable things in the application to somebody who should
 * not have them: A SUPPLIER'S BANK ACCOUNT and WHAT A SUBCONTRACTOR IS BEING PAID. Neither is
 * protected by being on a page nobody links to. So this file tests the boundary the way it would
 * actually be crossed:
 *
 *   · BY TYPING ANOTHER COMPANY'S ID IN THE URL. 404 rather than 403, because 403 confirms the
 *     record exists — and on a sequential id that is itself the leak.
 *
 *   · BY ASKING FOR THE BANK PAGE DIRECTLY. `vendor.bank` guards the GET as well as the PUT, so a
 *     manager with full `vendor.edit` is walked at both. A permission that only guards the write is
 *     a permission that hands over what it protects on the way to being checked (§67).
 *
 *   · BY READING THE PAGE THAT DOES NOT ASK. The vendor register and the vendor page are asserted
 *     NOT to contain an account number for a reader without `vendor.bank` — a positive assertion
 *     about absence, because "we never put it there" is a claim about code that changes.
 *
 *   · BY LOOKING AT A CONTRACT WITHOUT THE MONEY PERMISSION. Seeing that a subcontract exists and
 *     seeing what it is worth are two questions, and the second is asserted absent from the
 *     response body rather than merely hidden by a flag the view might forget to consult (§6, §68).
 *
 * Nothing here relies on a view hiding a link. Every assertion is against an HTTP response.
 */
final class CommercialAccessTest extends TestCase
{
    use BuildsCommercial, BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $meridian;

    private Organization $kestrel;

    private OrgUnit $commercial;

    /** Meridian: the commercial manager — everything, including bank and money. */
    private User $tunde;

    /** Kestrel: the same authority, in a different company. */
    private User $amaka;

    /** Meridian: buys things, may edit vendors, has no `vendor.bank` and no money permission. */
    private User $ify;

    protected function setUp(): void
    {
        parent::setUp();

        $this->meridian = $this->makeOrganization('Meridian Engineering');
        $this->kestrel = $this->makeOrganization('Kestrel Infrastructure');

        $this->commercial = $this->makeUnit($this->meridian, 'Commercial', OrgUnitType::Division);

        $this->tunde = $this->makePerson(
            $this->meridian,
            'Tunde Bello',
            $this->commercial,
            [$this->makeRole($this->meridian, 'Commercial Manager', $this->fullGrants())],
        );

        $kestrelUnit = $this->makeUnit($this->kestrel, 'Commercial', OrgUnitType::Division);
        $this->amaka = $this->makePerson(
            $this->kestrel,
            'Amaka Nwosu',
            $kestrelUnit,
            [$this->makeRole($this->kestrel, 'Commercial Manager', $this->fullGrants())],
        );

        // The account the leak tests are run as. Note what it HAS: full edit rights over every
        // vendor in the company. The point is that a broad, legitimate, entirely ordinary buyer's
        // account still cannot read an account number or a contract value.
        $this->ify = $this->makePerson(
            $this->meridian,
            'Ify Chukwu',
            $this->commercial,
            [$this->makeRole($this->meridian, 'Buyer', $this->buyerGrants())],
        );
    }

    // ================================================================== across tenants

    #[Test]
    public function a_commercial_record_belonging_to_another_company_is_not_found(): void
    {
        $vendor = $this->makeVendor($this->kestrel, $this->amaka, 'Kestrel Plant Hire');
        $client = $this->makeClient($this->kestrel, $this->amaka, 'Kestrel Holdings');
        $contract = $this->makeContract($this->kestrel, $this->amaka, 'Kestrel framework');

        foreach ([
            'vendors.show' => $vendor,
            'clients.show' => $client,
            'contracts.show' => $contract,
        ] as $route => $record) {
            $response = $this->actingFresh($this->tunde)->get(route($route, $record));

            $this->assertSame(404, $response->getStatusCode(), $route.' leaked another tenant\'s record.');
        }
    }

    #[Test]
    public function another_companys_vendor_cannot_be_written_to(): void
    {
        $vendor = $this->makeVendor($this->kestrel, $this->amaka, 'Kestrel Plant Hire');

        // Hiding the record on a page and then accepting a POST against its id protects nothing.
        $this->actingFresh($this->tunde)
            ->put(route('vendors.update', $vendor), ['name' => 'Renamed', 'confidentiality' => 'internal'])
            ->assertNotFound();

        $this->actingFresh($this->tunde)
            ->put(route('vendors.bank.update', $vendor), [
                'bank_account_number' => '0000000000',
                'reason' => 'Taking over their account.',
            ])
            ->assertNotFound();

        $this->actingFresh($this->tunde)
            ->post(route('vendors.blacklist', $vendor), ['reason' => 'Because I can reach them.'])
            ->assertNotFound();
    }

    #[Test]
    public function a_contract_cannot_be_pointed_at_another_companys_client(): void
    {
        $foreign = $this->makeClient($this->kestrel, $this->amaka, 'Kestrel Holdings');

        // §84 in its purest form: a morph filled from request input. The id is real, the type is
        // whitelisted, and the only thing standing between this and a cross-tenant foreign key is
        // that the lookup runs inside the organization scope.
        $this->actingFresh($this->tunde)
            ->post(route('contracts.store'), [
                'title' => 'Reaching across',
                'direction' => 'client',
                'party_type' => 'client',
                'party_id' => $foreign->id,
                'confidentiality' => 'confidential',
            ])
            ->assertSessionHasErrors('party_id');

        $this->assertDatabaseMissing('contracts', ['title' => 'Reaching across']);
    }

    #[Test]
    public function a_contract_cannot_be_pointed_at_a_user_account(): void
    {
        // The morph map contains `user`. Relation::getMorphedModel() would resolve it happily;
        // the service's two-entry whitelist does not.
        $this->actingFresh($this->tunde)
            ->post(route('contracts.store'), [
                'title' => 'Pointing at a person',
                'direction' => 'client',
                'party_type' => 'user',
                'party_id' => $this->ify->id,
                'confidentiality' => 'confidential',
            ])
            ->assertSessionHasErrors('party_type');

        $this->assertDatabaseMissing('contracts', ['title' => 'Pointing at a person']);
    }

    // ================================================================== bank details

    #[Test]
    public function the_bank_page_is_refused_to_a_buyer_who_may_edit_the_vendor(): void
    {
        $vendor = $this->makeVendor($this->meridian, $this->tunde, 'Delta Fabrication');

        // Proving the premise: this account genuinely may edit the vendor.
        $this->actingFresh($this->ify)->get(route('vendors.edit', $vendor))->assertOk();

        // And still may not read, or write, where the money goes.
        $this->actingFresh($this->ify)->get(route('vendors.bank.edit', $vendor))->assertForbidden();

        $this->actingFresh($this->ify)
            ->put(route('vendors.bank.update', $vendor), [
                'bank_account_number' => '9999999999',
                'reason' => 'Trying it on.',
            ])
            ->assertForbidden();
    }

    #[Test]
    public function an_account_number_never_appears_on_a_page_a_buyer_can_reach(): void
    {
        $vendor = $this->makeVendor($this->meridian, $this->tunde, 'Delta Fabrication');

        $this->withinOrganization($this->meridian, fn () => app(VendorService::class)
            ->updateBankDetails($vendor, [
                'bank_name' => 'First Continental',
                'bank_account_name' => 'Delta Fabrication Limited',
                'bank_account_number' => '4471900023',
            ], $this->tunde, 'Recorded at onboarding.'));

        // Asserted against the response body rather than against a flag, because the flag is what
        // a future copy of the partial into a print layout would quietly stop consulting.
        foreach ([route('vendors.index'), route('vendors.show', $vendor)] as $url) {
            $this->actingFresh($this->ify)->get($url)
                ->assertOk()
                ->assertDontSee('4471900023')
                ->assertDontSee('First Continental');
        }
    }

    #[Test]
    public function the_commercial_manager_can_read_the_bank_details(): void
    {
        $vendor = $this->makeVendor($this->meridian, $this->tunde, 'Delta Fabrication');

        $this->withinOrganization($this->meridian, fn () => app(VendorService::class)
            ->updateBankDetails($vendor, [
                'bank_name' => 'First Continental',
                'bank_account_number' => '4471900023',
            ], $this->tunde, 'Recorded at onboarding.'));

        $this->actingFresh($this->tunde)->get(route('vendors.show', $vendor))
            ->assertOk()
            ->assertSee('4471900023');
    }

    #[Test]
    public function a_bank_change_is_refused_without_a_reason(): void
    {
        $vendor = $this->makeVendor($this->meridian, $this->tunde, 'Delta Fabrication');

        // The reason is not paperwork. It is the only field that survives to tell somebody, three
        // days after the payment run, who asked for this and how they were checked.
        $this->actingFresh($this->tunde)
            ->put(route('vendors.bank.update', $vendor), ['bank_account_number' => '4471900023'])
            ->assertSessionHasErrors('reason');

        $this->assertDatabaseHas('vendors', ['id' => $vendor->id, 'bank_account_number' => null]);
    }

    #[Test]
    public function a_bank_change_records_which_fields_moved_and_never_their_values(): void
    {
        $vendor = $this->makeVendor($this->meridian, $this->tunde, 'Delta Fabrication');

        $this->actingFresh($this->tunde)->put(route('vendors.bank.update', $vendor), [
            'bank_name' => 'First Continental',
            'bank_account_number' => '4471900023',
            'reason' => 'Advised by their finance director; confirmed by telephone.',
        ])->assertRedirect();

        $activity = $this->withinOrganization($this->meridian, fn () => ActivityLog::query()
            ->where('subject_type', $vendor->getMorphClass())
            ->where('subject_id', $vendor->id)
            ->where('verb', 'bank_details_updated')
            ->firstOrFail());

        // The timeline is readable by anybody who can see the vendor, so the number must not be in
        // it. Before-and-after values live in the audit trail, under its own rules (§59, §68).
        $encoded = json_encode($activity->getAttributes(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('4471900023', $encoded);
        $this->assertStringContainsString('bank_account_number', $encoded);
    }

    // ================================================================== contract money

    #[Test]
    public function a_contract_value_is_absent_for_a_reader_without_the_money_permission(): void
    {
        $vendor = $this->makeVendor($this->meridian, $this->tunde, 'Delta Fabrication');

        $contract = $this->makeContract($this->meridian, $this->tunde, 'Steelwork subcontract', [
            'direction' => 'vendor',
            'party_type' => 'vendor',
            'party_id' => $vendor->id,
            'value' => 48750000,
            // Widened so that `view` succeeds and the ONLY thing under test is the money question.
            'confidentiality' => 'internal',
        ]);

        $this->actingFresh($this->ify)->get(route('contracts.show', $contract))
            ->assertOk()
            ->assertSee('Steelwork subcontract')      // they may know it exists
            ->assertDontSee('48,750,000.00');         // and not what it is worth

        $this->actingFresh($this->ify)->get(route('contracts.index'))
            ->assertOk()
            ->assertDontSee('48,750,000.00');
    }

    #[Test]
    public function the_commercial_manager_sees_the_contract_value(): void
    {
        $client = $this->makeClient($this->meridian, $this->tunde, 'Rivers Petroleum');

        $contract = $this->makeContract($this->meridian, $this->tunde, 'Main works', [
            'party_type' => 'client',
            'party_id' => $client->id,
            'value' => 48750000,
        ]);

        $this->actingFresh($this->tunde)->get(route('contracts.show', $contract))
            ->assertOk()
            ->assertSee('48,750,000.00');
    }

    #[Test]
    public function a_confidential_contract_is_out_of_reach_by_default(): void
    {
        // Contracts default to confidential and this account has no `view_confidential`. It is the
        // one register in the application where the restrictive default is the point.
        $contract = $this->makeContract($this->meridian, $this->tunde, 'Board-level agreement');

        $this->actingFresh($this->ify)->get(route('contracts.show', $contract))->assertForbidden();

        $this->actingFresh($this->ify)->get(route('contracts.index'))
            ->assertOk()
            ->assertDontSee('Board-level agreement');
    }

    // ================================================================== ownership widening

    #[Test]
    public function a_buyer_cannot_assign_themselves_a_client_account(): void
    {
        $client = $this->makeClient($this->meridian, $this->tunde, 'Rivers Petroleum');

        // `relationship_owner_id` is what the `assigned` scope reads, so assigning it is granting
        // reach. It is why the field is not on the edit form and why the endpoint is separate.
        $this->actingFresh($this->ify)
            ->post(route('clients.owner.assign', $client), ['relationship_owner_id' => $this->ify->id])
            ->assertForbidden();

        // Still with the manager who registered it — ClientService names the creator as the opening
        // owner, so "unchanged" here means Tunde, not null.
        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'relationship_owner_id' => $this->tunde->id,
        ]);
    }

    #[Test]
    public function the_edit_form_cannot_smuggle_an_account_owner_through(): void
    {
        $client = $this->makeClient($this->meridian, $this->tunde, 'Rivers Petroleum');

        // Posted as an ordinary extra field, which is exactly how it would arrive from a browser
        // console. ClientRequest does not accept it, so it is silently not applied rather than
        // silently applied (§84).
        $this->actingFresh($this->tunde)->put(route('clients.update', $client), [
            'name' => 'Rivers Petroleum',
            'confidentiality' => 'internal',
            'relationship_owner_id' => $this->ify->id,
        ])->assertRedirect();

        // Note this is posted by somebody who genuinely HOLDS `client.manage`. The point is not
        // that they may not do it — they may, through the proper endpoint — but that the edit form
        // is not that endpoint, so the field arrives and is discarded rather than applied.
        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'relationship_owner_id' => $this->tunde->id,
        ]);
    }

    // ================================================================== fixtures

    /**
     * A commercial manager: the three registers, the money, and the bank block.
     *
     * @return array<string,string>
     */
    private function fullGrants(): array
    {
        return [
            'org_unit.view' => 'organization',
            'project.view' => 'organization',
            'project.view_financials' => 'organization',
            'document.view' => 'organization',

            'vendor.view' => 'organization',
            'vendor.create' => 'organization',
            'vendor.edit' => 'organization',
            'vendor.delete' => 'organization',
            'vendor.manage' => 'organization',
            'vendor.evaluate' => 'organization',
            'vendor.blacklist' => 'organization',
            'vendor.bank' => 'organization',

            'client.view' => 'organization',
            'client.create' => 'organization',
            'client.edit' => 'organization',
            'client.delete' => 'organization',
            'client.manage' => 'organization',

            'contract.view' => 'organization',
            'contract.create' => 'organization',
            'contract.edit' => 'organization',
            'contract.delete' => 'organization',
            'contract.manage' => 'organization',
            'contract.approve' => 'organization',
            'contract.view_confidential' => 'organization',
        ];
    }

    /**
     * A buyer: broad, ordinary, entirely legitimate — and without the two permissions that matter.
     *
     * No `vendor.bank`, no `contract.view_confidential`, no `project.view_financials`. Everything
     * else is granted across the whole organization on purpose, so that a failure in these tests
     * cannot be explained away as "they could not reach it anyway".
     *
     * @return array<string,string>
     */
    private function buyerGrants(): array
    {
        return [
            'org_unit.view' => 'organization',
            'project.view' => 'organization',

            'vendor.view' => 'organization',
            'vendor.create' => 'organization',
            'vendor.edit' => 'organization',

            'client.view' => 'organization',
            'client.edit' => 'organization',

            'contract.view' => 'organization',
        ];
    }
}
