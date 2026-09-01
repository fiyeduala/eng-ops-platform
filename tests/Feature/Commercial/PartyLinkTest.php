<?php

declare(strict_types=1);

namespace Tests\Feature\Commercial;

use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\Rfi;
use App\Models\User;
use App\Services\RfiService;
use App\Support\Enums\OrgUnitType;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsCommercial;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * The §90 payoff: engineering correspondence points at real commercial records.
 *
 * Phase 4 shipped RFIs, submittals and transmittals with a party MORPH and nothing to point it at,
 * so every one of them carries a typed name. Phase 5 gives those names something to be — and the
 * moment a form can post `party_type` and `party_id`, it can also post another tenant's vendor id,
 * or `party_type=user`, or an id belonging to a client register the person cannot open.
 *
 * So this file asks four questions of the shared resolver, on the register least likely to be
 * thought about: an RFI, raised by a site engineer who has never opened the vendor list.
 *
 *   1. Does a legitimate link actually land?
 *   2. Does the typed name survive the link, and survive the counterparty being renamed?
 *   3. Does another tenant's id fail, and fail with a message that does not confirm it exists?
 *   4. Is `party_type=user` refused, rather than pointing an RFI at somebody's account?
 *
 * The last two are the ones §84 and §94 are about, and they are asserted here rather than only on
 * the contract register because the resolver is shared and a regression would surface first
 * wherever nobody was looking.
 */
final class PartyLinkTest extends TestCase
{
    use BuildsCommercial, BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $meridian;

    private Organization $kestrel;

    private User $tunde;

    private User $chidi;

    private OrgUnit $meridianUnit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->meridian = $this->makeOrganization('Meridian Engineering');
        $this->meridianUnit = $this->makeUnit($this->meridian, 'Engineering', OrgUnitType::Division);

        $this->tunde = $this->makePerson(
            $this->meridian,
            'Tunde Bello',
            $this->meridianUnit,
            [$this->makeRole($this->meridian, 'Engineering Manager', $this->grants())],
        );

        $this->kestrel = $this->makeOrganization('Kestrel Energy');
        $kestrelUnit = $this->makeUnit($this->kestrel, 'Projects', OrgUnitType::Division);

        $this->chidi = $this->makePerson(
            $this->kestrel,
            'Chidi Okonkwo',
            $kestrelUnit,
            [$this->makeRole($this->kestrel, 'Engineering Manager', $this->grants())],
        );
    }

    #[Test]
    public function an_rfi_can_be_addressed_to_a_vendor_on_the_register(): void
    {
        $vendor = $this->makeVendor($this->meridian, $this->tunde, 'Delta Fabrication');

        $this->actingFresh($this->tunde)->post(route('rfis.store'), [
            ...$this->rfiPayload(),
            'party_type' => 'vendor',
            'party_id' => $vendor->id,
        ])->assertRedirect();

        $rfi = $this->withinOrganization($this->meridian, fn () => Rfi::query()->latest('id')->firstOrFail());

        $this->assertSame('vendor', $rfi->party_type);
        $this->assertSame($vendor->id, (int) $rfi->party_id);

        // The name is filled from the link when nobody typed one, so the register still reads
        // correctly for anybody who cannot open the vendor page.
        $this->assertSame('Delta Fabrication', $rfi->party_name);
    }

    #[Test]
    public function the_typed_name_outlives_a_rebrand(): void
    {
        $vendor = $this->makeVendor($this->meridian, $this->tunde, 'Delta Fabrication Limited');

        $this->actingFresh($this->tunde)->post(route('rfis.store'), [
            ...$this->rfiPayload(),
            'party_name' => 'Delta Fabrication Ltd (Lagos)',
            'party_type' => 'vendor',
            'party_id' => $vendor->id,
        ])->assertRedirect();

        $rfi = $this->withinOrganization($this->meridian, fn () => Rfi::query()->latest('id')->firstOrFail());

        // A name somebody typed is evidence of what was written on the correspondence. The link is
        // a convenience laid on top of it and never overwrites it.
        $this->assertSame('Delta Fabrication Ltd (Lagos)', $rfi->party_name);
        $this->assertSame($vendor->id, (int) $rfi->party_id);
    }

    #[Test]
    public function another_tenants_vendor_id_does_not_resolve(): void
    {
        $foreign = $this->makeVendor($this->kestrel, $this->chidi, 'Kestrel Approved Supplier');

        // Tunde may raise RFIs and may see his own vendor register. The id he posts belongs to
        // Kestrel, and the tenant-scoped lookup simply does not find it (§75, §94).
        $this->actingFresh($this->tunde)->post(route('rfis.store'), [
            ...$this->rfiPayload(),
            'party_type' => 'vendor',
            'party_id' => $foreign->id,
        ])->assertSessionHasErrors('party_id');

        $this->assertSame(0, $this->withinOrganization($this->meridian, fn () => Rfi::query()->count()));

        // And the refusal must not confirm what it found. "Could not be found" is the same answer
        // for a row in another organization and a row that never existed — anything else turns the
        // field into a probe for which ids exist elsewhere.
        $this->assertStringNotContainsString(
            'Kestrel',
            (string) session('errors')?->first('party_id'),
        );
    }

    #[Test]
    public function an_rfi_cannot_be_pointed_at_a_user_account(): void
    {
        // The morph map contains `user`, and a resolver written with Relation::getMorphedModel()
        // would happily attach one. The whitelist is two classes long for exactly this reason.
        $this->actingFresh($this->tunde)->post(route('rfis.store'), [
            ...$this->rfiPayload(),
            'party_type' => 'user',
            'party_id' => $this->tunde->id,
        ])->assertSessionHasErrors('party_type');

        $this->assertSame(0, $this->withinOrganization($this->meridian, fn () => Rfi::query()->count()));
    }

    #[Test]
    public function the_service_refuses_a_foreign_id_even_without_a_form(): void
    {
        $foreign = $this->makeVendor($this->kestrel, $this->chidi, 'Kestrel Approved Supplier');

        // The request is one door; a seeder, an import or another module is another. The rule lives
        // where the data is written (§84).
        $this->expectException(\DomainException::class);

        $this->withinOrganization($this->meridian, fn () => app(RfiService::class)->create([
            'subject' => 'Direct',
            'question' => 'Reaching past the form.',
            'kind' => 'rfi',
            'direction' => 'outgoing',
            'party_type' => 'vendor',
            'party_id' => $foreign->id,
        ], $this->tunde));
    }

    #[Test]
    public function the_picker_only_offers_registers_the_reader_may_open(): void
    {
        $this->makeVendor($this->meridian, $this->tunde, 'Delta Fabrication');
        $this->makeClient($this->meridian, $this->tunde, 'Sahara Petroleum');

        // Ifeoma raises queries all day and has never been given the client or vendor register. The
        // form must still work — it degrades to the free-text field it has always been — and must
        // not read her a list of counterparties out of a register she cannot open (§61).
        $ifeoma = $this->makePerson(
            $this->meridian,
            'Ifeoma Nwosu',
            $this->meridianUnit,
            [$this->makeRole($this->meridian, 'Site Engineer', [
                'rfi.view' => 'organization',
                'rfi.create' => 'organization',
                'rfi.edit' => 'own',
                'org_unit.view' => 'organization',
                'project.view' => 'organization',
            ])],
        );

        $this->actingFresh($ifeoma)->get(route('rfis.create'))
            ->assertOk()
            ->assertDontSee('Delta Fabrication')
            ->assertDontSee('Sahara Petroleum')
            // The field she does need is still there.
            ->assertSee('name="party_name"', false);

        $this->actingFresh($this->tunde)->get(route('rfis.create'))
            ->assertOk()
            ->assertSee('Delta Fabrication')
            ->assertSee('Sahara Petroleum');
    }

    // ================================================================== fixtures

    /** @return array<string,mixed> */
    private function rfiPayload(): array
    {
        return [
            'subject' => 'Weld procedure for the tie-in spools',
            'question' => 'Confirm the WPS to be used on the 12" tie-in spools at CH 4+200.',
            'kind' => 'rfi',
            'direction' => 'outgoing',
            'confidentiality' => 'internal',
        ];
    }

    /** @return array<string,string> */
    private function grants(): array
    {
        $grants = [
            'org_unit.view' => 'organization',
            'project.view' => 'organization',
        ];

        foreach (['rfi', 'vendor', 'client'] as $module) {
            foreach (['view', 'create', 'edit'] as $ability) {
                $grants[$module.'.'.$ability] = 'organization';
            }
        }

        return $grants;
    }
}
