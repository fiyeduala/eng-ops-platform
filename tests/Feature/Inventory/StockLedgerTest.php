<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\Organization;
use App\Models\StockBalance;
use App\Models\StockItem;
use App\Models\StockTransaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockService;
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
 * The ledger is the record; the balance is a convenience.
 *
 * EVERY ASSERTION HERE IS ABOUT THAT SENTENCE. A stock system is believed or it is useless, and the
 * only reason to believe one is that the number on the screen can be re-derived from the rows that
 * produced it. So the last test in this file throws every cached balance away and rebuilds them
 * from the ledger alone: if the two ever disagree, one of them is lying and there is no way to tell
 * which from the inside.
 *
 * The arithmetic tested is the weighted moving average, and it is tested with numbers that do not
 * come out round on purpose — an average of 41,500 catches a bug that an average of 40,000 does not.
 */
final class StockLedgerTest extends TestCase
{
    use BuildsInventory, BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $meridian;

    private User $keeper;

    private Warehouse $main;

    private StockItem $pipe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->meridian = $this->makeOrganization('Meridian Engineering');
        $unit = $this->makeUnit($this->meridian, 'Stores', OrgUnitType::Department);

        $this->keeper = $this->makePerson(
            $this->meridian,
            'Musa Danladi',
            $unit,
            [$this->makeRole($this->meridian, 'Store Manager', $this->grants())],
        );

        $this->main = $this->makeWarehouse($this->meridian, $this->keeper);
        $this->pipe = $this->makeStockItem($this->meridian, $this->keeper);
    }

    #[Test]
    public function a_receipt_sets_the_balance_and_the_average(): void
    {
        $balance = $this->openingStock($this->meridian, $this->main, $this->pipe, 100, $this->keeper, 40000);

        $this->assertSame(100.0, (float) $balance->quantity_on_hand);
        $this->assertSame(40000.0, (float) $balance->average_cost);
        $this->assertSame(4000000.0, (float) $balance->total_value);
    }

    #[Test]
    public function a_second_receipt_at_a_different_price_moves_the_average_weighted(): void
    {
        $this->openingStock($this->meridian, $this->main, $this->pipe, 100, $this->keeper, 40000);

        $this->withinOrganization($this->meridian, fn () => app(StockService::class)
            ->receive($this->main, $this->pipe, 100, $this->keeper, ['unit_cost' => 43000]));

        $balance = $this->balanceOf($this->main, $this->pipe);

        // (100 x 40,000 + 100 x 43,000) / 200. Not the last price, and not the mean of the two
        // prices — the mean only happens to be right here because the quantities match, which is
        // why the next test uses quantities that do not.
        $this->assertSame(200.0, (float) $balance->quantity_on_hand);
        $this->assertSame(41500.0, (float) $balance->average_cost);
    }

    #[Test]
    public function the_average_is_weighted_by_quantity_and_not_by_price(): void
    {
        $this->openingStock($this->meridian, $this->main, $this->pipe, 300, $this->keeper, 40000);

        $this->withinOrganization($this->meridian, fn () => app(StockService::class)
            ->receive($this->main, $this->pipe, 100, $this->keeper, ['unit_cost' => 48000]));

        // (300 x 40,000 + 100 x 48,000) / 400 = 42,000. The unweighted mean would be 44,000, and a
        // store carrying 400 m would be overstated by 800,000 naira on the strength of one small
        // delivery at a bad price.
        $this->assertSame(42000.0, (float) $this->balanceOf($this->main, $this->pipe)->average_cost);
    }

    #[Test]
    public function an_issue_moves_the_quantity_and_leaves_the_average_alone(): void
    {
        $this->openingStock($this->meridian, $this->main, $this->pipe, 100, $this->keeper, 40000);

        $movement = $this->withinOrganization($this->meridian, fn () => app(StockService::class)
            ->issue($this->main, $this->pipe, 30, $this->keeper, ['reason' => 'Switchroom pull']));

        $balance = $this->balanceOf($this->main, $this->pipe);

        // The quantity is stored negative even though the caller passed a positive number: the sign
        // belongs to the ledger, not to whoever filled in the form (§84).
        $this->assertSame(-30.0, (float) $movement->quantity);
        $this->assertSame(70.0, (float) $movement->balance_after);
        $this->assertSame(70.0, (float) $balance->quantity_on_hand);

        // Taking stock off a shelf cannot change what the rest of it cost.
        $this->assertSame(40000.0, (float) $balance->average_cost);
        $this->assertSame(2800000.0, (float) $balance->total_value);
    }

    #[Test]
    public function a_store_that_does_not_allow_it_refuses_to_go_negative(): void
    {
        $this->openingStock($this->meridian, $this->main, $this->pipe, 10, $this->keeper, 40000);

        $this->expectException(DomainException::class);

        $this->withinOrganization($this->meridian, fn () => app(StockService::class)
            ->issue($this->main, $this->pipe, 11, $this->keeper, ['reason' => 'More than is there']));
    }

    #[Test]
    public function the_refusal_writes_nothing_at_all(): void
    {
        $this->openingStock($this->meridian, $this->main, $this->pipe, 10, $this->keeper, 40000);

        try {
            $this->withinOrganization($this->meridian, fn () => app(StockService::class)
                ->issue($this->main, $this->pipe, 11, $this->keeper, ['reason' => 'More than is there']));
        } catch (DomainException) {
            // Expected. What matters is the state it left behind.
        }

        // One row, from the opening receipt, and the balance untouched. A guard that threw after
        // writing would leave a ledger that no longer adds up to its own balance.
        $this->assertSame(1, $this->withinOrganization(
            $this->meridian,
            fn () => StockTransaction::query()->count()
        ));
        $this->assertSame(10.0, (float) $this->balanceOf($this->main, $this->pipe)->quantity_on_hand);
    }

    #[Test]
    public function a_transit_store_may_be_allowed_to_go_negative(): void
    {
        $transit = $this->makeWarehouse($this->meridian, $this->keeper, 'WH-TRANSIT', [
            'name' => 'Transit',
            'type' => 'transit',
            'allow_negative_stock' => true,
        ]);

        // Nothing has ever been received into it. The switch is per store and it is a decision
        // somebody made about this store, not a global relaxation of the rule.
        $movement = $this->withinOrganization($this->meridian, fn () => app(StockService::class)
            ->issue($transit, $this->pipe, 5, $this->keeper, ['reason' => 'Loaded before the paperwork']));

        $this->assertSame(-5.0, (float) $movement->balance_after);
    }

    #[Test]
    public function a_transfer_writes_both_sides_or_neither(): void
    {
        $site = $this->makeWarehouse($this->meridian, $this->keeper, 'WH-SITE', ['name' => 'Bonny Site Store']);
        $this->openingStock($this->meridian, $this->main, $this->pipe, 100, $this->keeper, 40000);

        [$out, $in] = $this->withinOrganization($this->meridian, fn () => app(StockService::class)
            ->transfer($this->main, $site, $this->pipe, 40, $this->keeper));

        $this->assertSame(-40.0, (float) $out->quantity);
        $this->assertSame(40.0, (float) $in->quantity);

        // The pair is findable as a pair. Without the shared reference the two rows are just an
        // issue and a receipt, and nobody reading the ledger a year later can tell they were one act.
        $this->assertNotNull($out->transfer_ref);
        $this->assertSame($out->transfer_ref, $in->transfer_ref);

        $this->assertSame(60.0, (float) $this->balanceOf($this->main, $this->pipe)->quantity_on_hand);
        $this->assertSame(40.0, (float) $this->balanceOf($site, $this->pipe)->quantity_on_hand);

        // Moving stock between two shelves cannot change what the company paid for it.
        $this->assertSame(40000.0, (float) $this->balanceOf($site, $this->pipe)->average_cost);
    }

    #[Test]
    public function a_transfer_that_cannot_be_taken_out_leaves_no_half_of_itself_behind(): void
    {
        $site = $this->makeWarehouse($this->meridian, $this->keeper, 'WH-SITE', ['name' => 'Bonny Site Store']);
        $this->openingStock($this->meridian, $this->main, $this->pipe, 10, $this->keeper, 40000);

        try {
            $this->withinOrganization($this->meridian, fn () => app(StockService::class)
                ->transfer($this->main, $site, $this->pipe, 40, $this->keeper));
        } catch (DomainException) {
            // Expected: the main store holds ten.
        }

        // THE FAILURE MODE THIS TEST EXISTS FOR is stock that has left one store and never arrived
        // at the other — material that has ceased to exist as far as the books are concerned.
        $this->assertSame(1, $this->withinOrganization(
            $this->meridian,
            fn () => StockTransaction::query()->count()
        ));
        $this->assertSame(10.0, (float) $this->balanceOf($this->main, $this->pipe)->quantity_on_hand);
        $this->assertNull($this->balanceOf($site, $this->pipe));
    }

    #[Test]
    public function stock_cannot_be_transferred_to_the_store_it_is_already_in(): void
    {
        $this->openingStock($this->meridian, $this->main, $this->pipe, 100, $this->keeper, 40000);

        $this->expectException(DomainException::class);

        $this->withinOrganization($this->meridian, fn () => app(StockService::class)
            ->transfer($this->main, $this->main, $this->pipe, 10, $this->keeper));
    }

    #[Test]
    public function a_posting_is_corrected_by_a_reversal_and_never_by_an_edit(): void
    {
        $this->openingStock($this->meridian, $this->main, $this->pipe, 100, $this->keeper, 40000);

        $issue = $this->withinOrganization($this->meridian, fn () => app(StockService::class)
            ->issue($this->main, $this->pipe, 30, $this->keeper, ['reason' => 'Wrong job number']));

        $reversal = $this->withinOrganization($this->meridian, fn () => app(StockService::class)
            ->reverse($issue, $this->keeper, 'Issued against the wrong job.'));

        // THE ORIGINAL IS STILL THERE, UNCHANGED. That is the whole of what append-only means: the
        // mistake is part of the history, and the correction is a second fact rather than an
        // overwriting of the first (§59).
        $this->assertSame(-30.0, (float) $issue->refresh()->quantity);
        $this->assertSame('issue', $issue->type);

        $this->assertSame('reversal', $reversal->type);
        $this->assertSame(30.0, (float) $reversal->quantity);
        $this->assertSame($issue->getKey(), $reversal->reverses_transaction_id);

        // Reversed at what it went out at, not at today's average.
        $this->assertSame(40000.0, (float) $reversal->unit_cost);
        $this->assertSame(100.0, (float) $this->balanceOf($this->main, $this->pipe)->quantity_on_hand);
    }

    #[Test]
    public function the_same_posting_cannot_be_reversed_twice(): void
    {
        $this->openingStock($this->meridian, $this->main, $this->pipe, 100, $this->keeper, 40000);

        $issue = $this->withinOrganization($this->meridian, fn () => app(StockService::class)
            ->issue($this->main, $this->pipe, 30, $this->keeper, ['reason' => 'Switchroom pull']));

        $this->withinOrganization($this->meridian, fn () => app(StockService::class)
            ->reverse($issue, $this->keeper, 'Wrong job.'));

        // Two people with the movement open, both clicking reverse, is how stock gets put back
        // twice. The guard re-reads under a lock rather than trusting the model in hand.
        $this->expectException(DomainException::class);

        $this->withinOrganization($this->meridian, fn () => app(StockService::class)
            ->reverse($issue, $this->keeper, 'Wrong job.'));
    }

    #[Test]
    public function a_reversal_is_not_itself_reversible(): void
    {
        $this->openingStock($this->meridian, $this->main, $this->pipe, 100, $this->keeper, 40000);

        $issue = $this->withinOrganization($this->meridian, fn () => app(StockService::class)
            ->issue($this->main, $this->pipe, 30, $this->keeper, ['reason' => 'Switchroom pull']));

        $reversal = $this->withinOrganization($this->meridian, fn () => app(StockService::class)
            ->reverse($issue, $this->keeper, 'Wrong job.'));

        $this->expectException(DomainException::class);

        $this->withinOrganization($this->meridian, fn () => app(StockService::class)
            ->reverse($reversal, $this->keeper, 'Changed my mind again.'));
    }

    #[Test]
    public function a_reversal_needs_a_written_reason(): void
    {
        $this->openingStock($this->meridian, $this->main, $this->pipe, 100, $this->keeper, 40000);

        $issue = $this->withinOrganization($this->meridian, fn () => app(StockService::class)
            ->issue($this->main, $this->pipe, 30, $this->keeper, ['reason' => 'Switchroom pull']));

        $this->expectException(DomainException::class);

        $this->withinOrganization($this->meridian, fn () => app(StockService::class)
            ->reverse($issue, $this->keeper, '   '));
    }

    #[Test]
    public function the_model_itself_refuses_an_update_or_a_delete(): void
    {
        $this->openingStock($this->meridian, $this->main, $this->pipe, 100, $this->keeper, 40000);

        $movement = $this->withinOrganization($this->meridian, fn () => StockTransaction::query()->firstOrFail());

        // NOT A POLICY AND NOT A MISSING ROUTE. Both of those are doors, and a door can be walked
        // past by a seeder, an import or the next controller somebody writes. This is the table
        // saying no (§59).
        try {
            $movement->update(['quantity' => 999]);
            $this->fail('The ledger allowed a row to be edited.');
        } catch (\LogicException) {
            // Expected.
        }

        try {
            $movement->delete();
            $this->fail('The ledger allowed a row to be deleted.');
        } catch (\LogicException) {
            // Expected.
        }

        $this->assertSame(100.0, (float) $movement->refresh()->quantity);
    }

    #[Test]
    public function a_count_that_agrees_writes_no_row_and_is_still_recorded(): void
    {
        $this->openingStock($this->meridian, $this->main, $this->pipe, 100, $this->keeper, 40000);

        $posted = $this->withinOrganization($this->meridian, fn () => app(StockService::class)
            ->adjust($this->main, $this->pipe, 100, $this->keeper, 'Quarterly count'));

        // Nothing moved, so nothing is posted — a ledger where every count writes a zero-quantity
        // row is a ledger nobody can read.
        $this->assertNull($posted);
        $this->assertSame(1, $this->withinOrganization(
            $this->meridian,
            fn () => StockTransaction::query()->count()
        ));

        // But "when was this last counted" is still answerable.
        $this->assertNotNull($this->balanceOf($this->main, $this->pipe)->last_counted_at);
    }

    #[Test]
    public function a_count_takes_the_number_on_the_clipboard_and_works_out_the_variance(): void
    {
        $this->openingStock($this->meridian, $this->main, $this->pipe, 100, $this->keeper, 40000);

        // Ninety-eight were on the shelf. The storekeeper types 98, NOT -2 — a system that accepts
        // a difference cannot tell a count of ninety-eight from a typo.
        $adjustment = $this->withinOrganization($this->meridian, fn () => app(StockService::class)
            ->adjust($this->main, $this->pipe, 98, $this->keeper, 'Quarterly count: two lengths short'));

        $this->assertSame('adjustment', $adjustment->type);
        $this->assertSame(-2.0, (float) $adjustment->quantity);
        $this->assertSame(98.0, (float) $this->balanceOf($this->main, $this->pipe)->quantity_on_hand);
    }

    #[Test]
    public function a_count_needs_a_written_reason(): void
    {
        $this->openingStock($this->meridian, $this->main, $this->pipe, 100, $this->keeper, 40000);

        $this->expectException(DomainException::class);

        $this->withinOrganization($this->meridian, fn () => app(StockService::class)
            ->adjust($this->main, $this->pipe, 98, $this->keeper, ''));
    }

    #[Test]
    public function every_balance_can_be_rebuilt_from_the_ledger_alone(): void
    {
        $site = $this->makeWarehouse($this->meridian, $this->keeper, 'WH-SITE', ['name' => 'Bonny Site Store']);
        $cable = $this->makeStockItem($this->meridian, $this->keeper, 'MAT-0002', [
            'name' => '95mm² XLPE armoured cable',
            'uom' => 'm',
        ]);

        $service = $this->withinOrganization($this->meridian, fn () => app(StockService::class));

        // A history with one of everything in it: two receipts at different prices, an issue, a
        // transfer, a return, a stocktake variance and a reversal.
        $this->withinOrganization($this->meridian, function () use ($service, $site, $cable) {
            $service->receive($this->main, $this->pipe, 300, $this->keeper, ['unit_cost' => 40000]);
            $service->receive($this->main, $this->pipe, 100, $this->keeper, ['unit_cost' => 48000]);
            $service->receive($this->main, $cable, 500, $this->keeper, ['unit_cost' => 12500]);

            $issue = $service->issue($this->main, $this->pipe, 60, $this->keeper, ['reason' => 'Switchroom']);
            $service->transfer($this->main, $site, $cable, 120, $this->keeper);
            $service->returnToStore($this->main, $this->pipe, 15, $this->keeper, ['reason' => 'Not needed']);
            $service->adjust($this->main, $cable, 375, $this->keeper, 'Quarterly count');
            $service->reverse($issue, $this->keeper, 'Charged to the wrong job.');
        });

        $before = $this->snapshot();

        // Throw the cache away — every figure the application reads off a page — and put it back
        // from the rows that produced it.
        $this->withinOrganization($this->meridian, function () {
            StockBalance::query()->update([
                'quantity_on_hand' => 0,
                'average_cost' => 0,
                'total_value' => 0,
            ]);
        });

        $this->assertNotSame($before, $this->snapshot(), 'The snapshot did not actually change.');

        $written = $this->withinOrganization($this->meridian, fn () => app(StockService::class)->rebuildBalances());

        // Three pairs: pipe and cable in the main store, cable at site. A fourth would mean the
        // rebuild invented a balance for a pair that never moved.
        $this->assertSame(3, $written);

        // THE ASSERTION THE WHOLE MODULE RESTS ON. If these disagree, either post() computed a
        // balance the ledger does not support or the ledger is missing a row — and there is no way
        // to tell which from inside the application.
        $this->assertSame($before, $this->snapshot());
    }

    /**
     * Every balance in the tenant, as a comparable array.
     *
     * @return array<string,array<string,float>>
     */
    private function snapshot(): array
    {
        return $this->withinOrganization($this->meridian, fn () => StockBalance::query()
            ->orderBy('warehouse_id')->orderBy('stock_item_id')->get()
            ->mapWithKeys(fn ($balance) => [
                $balance->warehouse_id.':'.$balance->stock_item_id => [
                    'on_hand' => round((float) $balance->quantity_on_hand, 4),
                    'average' => round((float) $balance->average_cost, 4),
                    'value' => round((float) $balance->total_value, 2),
                ],
            ])->all());
    }

    // ================================================================== fixtures
    /**
     * Everything a store manager holds, expanded the way the seeder expands it.
     *
     * `syncGrants()` matches permission keys literally, so a wildcard handed to it silently grants
     * nothing — and a test whose actor holds no permissions passes every refusal for the wrong
     * reason. {@see RoleTemplates::expand()} is the same expansion the provisioner runs.
     *
     * @return array<string,array<int,string>>
     */
    private function grants(): array
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
