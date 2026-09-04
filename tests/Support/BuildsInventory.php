<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Engines\Configuration\StatusMachine;
use App\Models\MaterialRequest;
use App\Models\Organization;
use App\Models\Scopes\OrganizationScope;
use App\Models\Status;
use App\Models\StockBalance;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\MaterialRequestService;
use App\Services\StockItemService;
use App\Services\StockService;
use App\Services\WarehouseService;
use Tests\Feature\Inventory\WarehouseLifecycleTest;

/**
 * Fixtures for the stores.
 *
 * SAME RULE AS {@see BuildsProcurement}: everything is made the way the application makes it. A
 * balance is never inserted — it is arrived at by posting movements, because the balance is a cache
 * of the ledger and a fixture that wrote one directly would let a test assert against a number the
 * ledger does not support. `openingStock()` exists for exactly that reason: it is a receipt.
 *
 * THE HELPERS STOP WHERE A DECISION STARTS. `makeMaterialRequest()` leaves the request in draft.
 * Submitting, approving and issuing carry permissions, a segregation rule and a reservation, so a
 * test that wants them asks for them by name.
 *
 * Companion to {@see BuildsTenants}, which this assumes.
 */
trait BuildsInventory
{
    /**
     * A store, open for movements.
     *
     * IT IS OPENED ON PURPOSE AND NOT BY THE SERVICE. A warehouse is created in `draft` — "being
     * set up" — and refuses every movement until somebody opens it, which is the correct default
     * and is asserted in {@see WarehouseLifecycleTest}. Almost every other
     * test wants a working store rather than that ceremony, so the fixture performs the decision
     * out loud here; pass `open: false` to get the draft store and make the decision yourself.
     *
     * @param  array<string,mixed>  $attributes
     */
    protected function makeWarehouse(
        Organization $organization,
        User $actor,
        string $code = 'WH-MAIN',
        array $attributes = [],
        bool $open = true,
    ): Warehouse {
        $this->provisionConfiguration($organization);

        /** @var Warehouse */
        return $this->withinOrganization($organization, function () use ($code, $attributes, $actor, $open) {
            $warehouse = app(WarehouseService::class)->create([
                'code' => $code,
                'name' => 'Main Store',
                'type' => 'store',
                ...$attributes,
            ], $actor);

            if ($open) {
                $this->moveWarehouseTo($warehouse, 'active', $actor);
            }

            return $warehouse->refresh();
        });
    }

    /** Walk a store along its own status graph, the way the transitions card does. */
    protected function moveWarehouseTo(Warehouse $warehouse, string $statusKey, User $actor): Warehouse
    {
        $to = Status::query()->forEntity('warehouse')->where('key', $statusKey)->firstOrFail();

        app(StatusMachine::class)->transition($warehouse, $to, $actor);

        return $warehouse->refresh();
    }

    /**
     * A catalogue line, live.
     *
     * Same reasoning as {@see makeWarehouse()}: an item is created in `draft` and refuses every
     * movement until somebody puts it on the catalogue. Pass `activate: false` for the draft.
     *
     * @param  array<string,mixed>  $attributes
     */
    protected function makeStockItem(
        Organization $organization,
        User $actor,
        string $code = 'MAT-0001',
        array $attributes = [],
        bool $activate = true,
    ): StockItem {
        $this->provisionConfiguration($organization);

        /** @var StockItem */
        return $this->withinOrganization($organization, function () use ($code, $attributes, $actor, $activate) {
            $item = app(StockItemService::class)->create([
                'code' => $code,
                'name' => '12" carbon steel pipe, schedule 40',
                'uom' => 'm',
                ...$attributes,
            ], $actor);

            if ($activate) {
                $to = Status::query()->forEntity('stock_item')->where('key', 'active')->firstOrFail();
                app(StatusMachine::class)->transition($item, $to, $actor);
            }

            return $item->refresh();
        });
    }

    /**
     * Stock on the shelf, put there the only way stock ever gets there.
     *
     * Returns the balance so a test can read the average off it without another query.
     */
    protected function openingStock(
        Organization $organization,
        Warehouse $warehouse,
        StockItem $item,
        float $quantity,
        User $actor,
        float $unitCost = 40000,
    ): ?StockBalance {
        return $this->withinOrganization($organization, function () use ($warehouse, $item, $quantity, $actor, $unitCost) {
            app(StockService::class)->receive($warehouse, $item, $quantity, $actor, [
                'unit_cost' => $unitCost,
                'reason' => 'Opening balance',
            ]);

            return $this->balanceOf($warehouse, $item);
        });
    }

    /**
     * A requisition on the store, in draft, one line.
     *
     * @param  array<int,array<string,mixed>>|null  $lines
     * @param  array<string,mixed>  $attributes
     */
    protected function makeMaterialRequest(
        Organization $organization,
        User $actor,
        StockItem $item,
        ?Warehouse $warehouse = null,
        ?array $lines = null,
        array $attributes = [],
    ): MaterialRequest {
        $this->provisionConfiguration($organization);

        /** @var MaterialRequest */
        return $this->withinOrganization($organization, fn () => app(MaterialRequestService::class)->create([
            'title' => 'Cable for the switchroom pull',
            'warehouse_id' => $warehouse?->getKey(),
            'items' => $lines ?? [
                [
                    'stock_item_id' => $item->getKey(),
                    'description' => $item->name,
                    'quantity_requested' => 10,
                ],
            ],
            ...$attributes,
        ], $actor));
    }

    /**
     * The cached balance row, read the way a page reads it. Null until something has moved.
     *
     * It opens the tenant context itself, because {@see OrganizationScope} fails
     * closed: read outside one, this would quietly return null and every assertion built on it
     * would pass for the wrong reason.
     */
    protected function balanceOf(Warehouse $warehouse, StockItem $item): ?StockBalance
    {
        $organization = Organization::query()->findOrFail($warehouse->organization_id);

        /** @var ?StockBalance */
        return $this->withinOrganization($organization, fn () => StockBalance::query()
            ->where('warehouse_id', $warehouse->getKey())
            ->where('stock_item_id', $item->getKey())
            ->first());
    }
}
