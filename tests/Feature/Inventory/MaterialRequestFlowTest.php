<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\MaterialRequest;
use App\Models\MaterialRequestItem;
use App\Models\Organization;
use App\Models\Status;
use App\Models\StockItem;
use App\Models\StockTransaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\MaterialRequestService;
use App\Support\Enums\OrgUnitType;
use App\Support\RoleTemplates;
use Database\Seeders\PermissionSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsInventory;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * Asking the store, and what the shelf does about it.
 *
 * THE RESERVATION IS THE HARD PART, and it is the reason this file exists rather than a handful of
 * status assertions. An approved request holds stock that is still physically present: on hand, but
 * spoken for. Every arithmetic mistake in that idea has the same shape — stock that is counted
 * against two claims at once, or held forever by a request nobody finished — and both end with a
 * store that reads empty while the racks are full.
 *
 * So the hold is asserted at each of the four moments it can change: approval takes it, an issue
 * gives back what left, closing gives back the rest, and a request closed with lines outstanding
 * gives back what was never sent.
 *
 * AND THE DOOR ROUND THE BACK IS ASSERTED TOO. The status graph offers `part_issued → closed` like
 * any other move, so posting that id at the generic transition endpoint would set the status while
 * walking past the release. That is the §84 shape exactly, and the last test in this file is the
 * one that would fail if the interception were ever removed.
 */
final class MaterialRequestFlowTest extends TestCase
{
    use BuildsInventory, BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $meridian;

    private User $musa;

    private User $ada;

    private Warehouse $store;

    private StockItem $cable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->meridian = $this->makeOrganization('Meridian Engineering');
        $unit = $this->makeUnit($this->meridian, 'Stores', OrgUnitType::Department);

        // Two people, because approving and issuing are separated on purpose and a single-actor
        // fixture would hide that rule from every test built on it.
        $this->musa = $this->makePerson(
            $this->meridian, 'Musa Danladi', $unit,
            [$this->makeRole($this->meridian, 'Store Manager', $this->storeGrants())],
        );

        $this->ada = $this->makePerson(
            $this->meridian, 'Ada Eze', $unit,
            [$this->makeRole($this->meridian, 'Store Officer', $this->storeGrants())],
        );

        $this->store = $this->makeWarehouse($this->meridian, $this->musa);
        $this->cable = $this->makeStockItem($this->meridian, $this->musa, 'MAT-0002', [
            'name' => '95mm2 XLPE armoured cable',
            'uom' => 'm',
        ]);
    }

    #[Test]
    public function approval_holds_the_stock_without_moving_it(): void
    {
        $this->openingStock($this->meridian, $this->store, $this->cable, 500, $this->musa, 12500);

        $request = $this->submitted();

        $this->withinOrganization($this->meridian, fn () => app(MaterialRequestService::class)
            ->approve($request, $this->musa));

        $balance = $this->balanceOf($this->store, $this->cable);

        // NOTHING HAS MOVED. The cable is still on the drum, still countable, still in the store —
        // a reservation modelled as a movement into a "reserved" warehouse would make a stocktake
        // of this store come up 120 m short.
        $this->assertSame(500.0, (float) $balance->quantity_on_hand);
        $this->assertSame(120.0, (float) $balance->quantity_reserved);
        $this->assertSame(380.0, $balance->available());

        // And the ledger has exactly the one row the opening receipt put there.
        $this->assertSame(1, $this->withinOrganization(
            $this->meridian,
            fn () => StockTransaction::query()->count()
        ));
    }

    #[Test]
    public function issuing_moves_the_stock_and_gives_the_hold_back(): void
    {
        $this->openingStock($this->meridian, $this->store, $this->cable, 500, $this->musa, 12500);

        $request = $this->submitted();

        $this->withinOrganization($this->meridian, fn () => app(MaterialRequestService::class)
            ->approve($request, $this->musa));

        $this->withinOrganization($this->meridian, fn () => app(MaterialRequestService::class)
            ->issue($request->refresh(), $this->ada));

        $balance = $this->balanceOf($this->store, $this->cable);

        // TAKEN AWAY ONCE, NOT TWICE. The cable has left the shelf, so on hand falls — and the hold
        // that stopped anybody else being offered it ends at the same moment, because the thing it
        // was protecting has gone. A release that never happened would show 260 m free out of 380.
        $this->assertSame(380.0, (float) $balance->quantity_on_hand);
        $this->assertSame(0.0, (float) $balance->quantity_reserved);
        $this->assertSame(380.0, $balance->available());

        $this->assertSame('issued', $this->statusOf($request));
    }

    #[Test]
    public function a_part_issue_holds_only_what_is_still_owed(): void
    {
        $this->openingStock($this->meridian, $this->store, $this->cable, 500, $this->musa, 12500);

        $request = $this->submitted();

        $this->withinOrganization($this->meridian, fn () => app(MaterialRequestService::class)
            ->approve($request, $this->musa));

        $line = $this->line($request);

        $this->withinOrganization($this->meridian, fn () => app(MaterialRequestService::class)
            ->issue($request->refresh(), $this->ada, [$line->getKey() => 50]));

        $balance = $this->balanceOf($this->store, $this->cable);

        $this->assertSame(450.0, (float) $balance->quantity_on_hand);
        $this->assertSame(70.0, (float) $balance->quantity_reserved);

        // `part_issued` is a resting place with a name, not an instant between two others: fifty
        // went out this morning and the rest goes when the drum arrives.
        $this->assertSame('part_issued', $this->statusOf($request));

        // Read inside the tenant: quantityOutstanding() counts the movements against the line, and
        // the ledger is scoped, so asking from outside would answer "nothing has been issued" and
        // this assertion would pass on the untouched request.
        $this->assertSame(70.0, $this->withinOrganization(
            $this->meridian,
            fn () => $this->line($request)->quantityOutstanding()
        ));
    }

    #[Test]
    public function closing_short_gives_back_what_was_never_sent(): void
    {
        $this->openingStock($this->meridian, $this->store, $this->cable, 500, $this->musa, 12500);

        $request = $this->submitted();

        $this->withinOrganization($this->meridian, fn () => app(MaterialRequestService::class)
            ->approve($request, $this->musa));

        $line = $this->line($request);

        $this->withinOrganization($this->meridian, fn () => app(MaterialRequestService::class)
            ->issue($request->refresh(), $this->ada, [$line->getKey() => 50]));

        $this->withinOrganization($this->meridian, fn () => app(MaterialRequestService::class)
            ->close($request->refresh(), $this->musa, 'The pull was finished with what went out.'));

        // THE FAILURE THIS TEST NAMES: seventy metres held by a request nobody will ever issue
        // against again. Left there, every closed request leaves a little stock permanently
        // unavailable and the store eventually shows nothing free while the racks are full.
        $balance = $this->balanceOf($this->store, $this->cable);

        $this->assertSame(450.0, (float) $balance->quantity_on_hand);
        $this->assertSame(0.0, (float) $balance->quantity_reserved);
        $this->assertSame('closed', $this->statusOf($request));
    }

    #[Test]
    public function the_person_who_approved_may_not_also_issue(): void
    {
        $this->openingStock($this->meridian, $this->store, $this->cable, 500, $this->musa, 12500);

        $request = $this->submitted();

        $this->withinOrganization($this->meridian, fn () => app(MaterialRequestService::class)
            ->approve($request, $this->musa));

        // Four eyes, the same reason procurement separates ordering from receiving. The setting can
        // be turned off by a company that has three people and no choice; it is on by default.
        $this->expectException(DomainException::class);

        $this->withinOrganization($this->meridian, fn () => app(MaterialRequestService::class)
            ->issue($request->refresh(), $this->musa));
    }

    #[Test]
    public function an_approval_may_cut_a_line_but_never_raise_it(): void
    {
        $this->openingStock($this->meridian, $this->store, $this->cable, 500, $this->musa, 12500);

        $request = $this->submitted();
        $line = $this->line($request);

        $this->withinOrganization($this->meridian, fn () => app(MaterialRequestService::class)
            ->approve($request, $this->musa, [$line->getKey() => 400]));

        // An approver who wants to send more than was asked for is starting a different
        // conversation, and this request is not the record of it (§84).
        $this->assertSame(120.0, (float) $this->line($request)->quantity_approved);
        $this->assertSame(120.0, (float) $this->balanceOf($this->store, $this->cable)->quantity_reserved);
    }

    #[Test]
    public function a_line_the_approver_never_touched_is_approved_as_asked(): void
    {
        $this->openingStock($this->meridian, $this->store, $this->cable, 500, $this->musa, 12500);

        $request = $this->submitted();

        // No quantities posted at all. Absent means "as asked for" — treating a missing key as zero
        // would empty a six-line request whose approver only meant to cut one of them.
        $this->withinOrganization($this->meridian, fn () => app(MaterialRequestService::class)
            ->approve($request, $this->musa));

        $this->assertSame(120.0, (float) $this->line($request)->quantity_approved);
    }

    #[Test]
    public function cutting_every_line_to_nothing_is_refused_as_a_rejection_in_disguise(): void
    {
        $this->openingStock($this->meridian, $this->store, $this->cable, 500, $this->musa, 12500);

        $request = $this->submitted();
        $line = $this->line($request);

        // The requester needs to know they were refused, and a request sitting in `approved` for
        // nothing tells them the opposite.
        $this->expectException(DomainException::class);

        $this->withinOrganization($this->meridian, fn () => app(MaterialRequestService::class)
            ->approve($request, $this->musa, [$line->getKey() => 0]));
    }

    #[Test]
    public function the_generic_transition_endpoint_cannot_close_a_request_behind_the_release(): void
    {
        $this->openingStock($this->meridian, $this->store, $this->cable, 500, $this->musa, 12500);

        $request = $this->submitted();

        $this->withinOrganization($this->meridian, fn () => app(MaterialRequestService::class)
            ->approve($request, $this->musa));

        $line = $this->line($request);

        $this->withinOrganization($this->meridian, fn () => app(MaterialRequestService::class)
            ->issue($request->refresh(), $this->ada, [$line->getKey() => 50]));

        $this->assertSame(70.0, (float) $this->balanceOf($this->store, $this->cable)->quantity_reserved);

        $closed = $this->withinOrganization($this->meridian, fn () => Status::query()
            ->forEntity('material_request')->where('key', 'closed')->firstOrFail());

        // THE BACK DOOR. `part_issued → closed` is a legitimate move on the graph, so the generic
        // handler would happily set the status — and walk straight past the only thing that gives
        // the reservation back. The row would read "closed" while the store went on showing 70 m
        // as spoken for, and nobody would ever find out why (§84).
        $this->actingFresh($this->musa)
            ->post(route('material-requests.transition', $request), [
                'status_id' => $closed->getKey(),
                'comment' => 'Finished with.',
            ])
            ->assertRedirect();

        $this->assertSame('closed', $this->statusOf($request));
        $this->assertSame(0.0, (float) $this->balanceOf($this->store, $this->cable)->quantity_reserved);
    }

    #[Test]
    public function a_request_is_issued_from_the_store_it_names_and_the_ledger_says_which_line(): void
    {
        $this->openingStock($this->meridian, $this->store, $this->cable, 500, $this->musa, 12500);

        $request = $this->submitted();

        $this->withinOrganization($this->meridian, fn () => app(MaterialRequestService::class)
            ->approve($request, $this->musa));

        $this->withinOrganization($this->meridian, fn () => app(MaterialRequestService::class)
            ->issue($request->refresh(), $this->ada));

        $movement = $this->withinOrganization($this->meridian, fn () => StockTransaction::query()
            ->where('type', 'issue')->firstOrFail());

        // §90 IN ONE ASSERTION: the ledger row knows which LINE of which request caused it, not
        // merely that a request did. Six lines produce six movements, and pointing all six at the
        // header would leave nobody able to say which one covered which.
        $this->assertSame('material_request_item', $movement->source_type);
        $this->assertSame($this->line($request)->getKey(), (int) $movement->source_id);
        $this->assertSame(-120.0, (float) $movement->quantity);
    }

    #[Test]
    public function a_request_naming_no_store_is_stopped_at_submission(): void
    {
        $request = $this->withinOrganization($this->meridian, fn () => app(MaterialRequestService::class)
            ->create([
                'title' => 'Cable for the switchroom pull',
                'items' => [[
                    'stock_item_id' => $this->cable->getKey(),
                    'description' => $this->cable->name,
                    'quantity_requested' => 120,
                ]],
            ], $this->ada));

        // The store is optional while the request is being written — "we need this, somebody work
        // out where it comes from" is a real half-finished note — and it is compulsory the moment
        // the request is sent to somebody, because an approver cannot reserve stock in no store.
        // Asked here rather than at issue: the requester is still in front of the form and can
        // answer it, which the storekeeper three days later cannot.
        $this->expectException(DomainException::class);

        $this->withinOrganization($this->meridian, fn () => app(MaterialRequestService::class)
            ->submit($request, $this->ada));
    }

    #[Test]
    public function the_whole_chain_survives_being_run_on_one_instance(): void
    {
        $this->openingStock($this->meridian, $this->store, $this->cable, 500, $this->musa, 12500);

        /*
         * NO refresh() ANYWHERE IN THIS TEST, and that is the entire point of it.
         *
         * Every other test here reloads the request between the steps, which is what a controller
         * does for free: each step is its own HTTP request and each one re-reads the row. A seeder,
         * an import, a queued job and a console command do not — they hold one object and call four
         * methods on it — and that is the path this test walks.
         *
         * It exists because a notification listener broke it. `NotifyMaterialRequestSubmitted` runs
         * synchronously inside submit()'s transaction, and it used to eager-load
         * `warehouse:id,name,keeper_id` onto the caller's own request. The store attached that way
         * had no `status_id` column on it, so it answered "no status" to everything asked
         * afterwards, and issue() two calls later refused to move anything out of a store that had
         * been open for a month. Nothing in the HTTP path could see it; a refresh() hid it.
         */
        $request = $this->makeMaterialRequest(
            $this->meridian,
            $this->ada,
            $this->cable,
            $this->store,
            [[
                'stock_item_id' => $this->cable->getKey(),
                'description' => $this->cable->name,
                'quantity_requested' => 120,
            ]],
        );

        $this->withinOrganization($this->meridian, function () use ($request) {
            $requests = app(MaterialRequestService::class);

            $requests->submit($request, $this->ada);

            // The store the request names is still a whole store after the listener has run. Asked
            // first because it is the assertion that names the defect: everything below fails as a
            // consequence of this one going wrong.
            $this->assertSame('active', $request->warehouse?->statusKey());

            $requests->approve($request, $this->musa);
            $requests->issue($request, $this->ada);
        });

        $balance = $this->balanceOf($this->store, $this->cable);

        $this->assertSame(380.0, (float) $balance->quantity_on_hand);
        $this->assertSame(0.0, (float) $balance->quantity_reserved);
        $this->assertSame('issued', $this->statusOf($request));

        // And the ledger has the row, with the line as its source — the same evidence the seeded
        // demo relies on to show a request drawing stock down against a job.
        $this->assertSame(1, $this->withinOrganization($this->meridian, fn () => StockTransaction::query()
            ->where('type', 'issue')
            ->where('source_type', 'material_request_item')
            ->count()));
    }

    // ================================================================== fixtures

    /**
     * The one line on the request, read inside the tenant.
     *
     * NOT A CONVENIENCE. OrganizationScope fails closed, so `$request->items()` outside a tenant
     * context finds nothing at all — and an assertion written against that would be asserting the
     * scope rather than the arithmetic. Same for the status relation.
     */
    private function line(MaterialRequest $request): MaterialRequestItem
    {
        return $this->withinOrganization($this->meridian, fn () => $request->items()->firstOrFail());
    }

    private function statusOf(MaterialRequest $request): ?string
    {
        return $this->withinOrganization($this->meridian, fn () => $request->refresh()->statusKey());
    }

    /** A request for 120 m of cable on the main store, raised by Ada and sent to the store. */
    private function submitted(): MaterialRequest
    {
        $request = $this->makeMaterialRequest(
            $this->meridian,
            $this->ada,
            $this->cable,
            $this->store,
            [[
                'stock_item_id' => $this->cable->getKey(),
                'description' => $this->cable->name,
                'quantity_requested' => 120,
            ]],
        );

        return $this->withinOrganization($this->meridian, function () use ($request) {
            app(MaterialRequestService::class)->submit($request, $this->ada);

            return $request->refresh();
        });
    }

    /** @return array<string,array<int,string>> */
    private function storeGrants(): array
    {
        return RoleTemplates::expand([
            'warehouse.*' => 'organization',
            'stock.*' => 'organization',
            'stock_item.*' => 'organization',
            'material_request.*' => 'organization',
            'org_unit.view' => 'organization',
            'project.view' => 'organization',
        ]);
    }
}
