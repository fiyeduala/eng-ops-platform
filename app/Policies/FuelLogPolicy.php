<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FuelLog;
use App\Models\User;

/**
 * A fuel record is a receipt, and a driver may file their own.
 *
 * THE INTERESTING PART OF THIS MODULE IS NOT IN THIS CLASS, IT IS IN THE ROLE TEMPLATE. `driver`
 * holds `fuel_log.create` and `fuel_log.view` at `own` scope — the narrowest grant in the system
 * that still lets somebody type. A driver fills up at a station, files the litres and the odometer,
 * and can see what they filed; they cannot see the fleet's consumption, cannot see another
 * driver's records, and cannot edit yesterday's after the transport manager has queried it.
 * `Scopeable` does that through `accessAssigneeColumns() => ['driver_id']` and
 * `accessOwnerColumns() => ['created_by']`, which is why this class does not have to.
 *
 * `manage` IS THE FLEET-WIDE READ AND THE CORRECTION RIGHT. Fuel is the line item most open to
 * quiet theft in any fleet — litres that never entered a tank — and the control on it is not a
 * permission but arithmetic: two full-tank fills and the distance between them give a consumption
 * figure, and a vehicle that suddenly drinks half again as much is visible without anybody
 * accusing anybody. `Vehicle::consumptionPer100()` exists for that reading.
 *
 * SOFT DELETES ARE ALLOWED HERE and are refused on the stock ledger, which looks inconsistent and
 * is not. A stock movement is the reason a balance says what it says, so removing one makes the
 * balance a lie. A fuel log is expenditure: nothing reconciles against it, and a duplicate receipt
 * entered twice on a Friday afternoon should be removable by the person who noticed.
 */
class FuelLogPolicy extends ScopedPolicy
{
    protected string $module = 'fuel_log';

    /** See and correct fuel across the fleet, rather than only what you filed yourself. */
    public function manage(User $user, ?FuelLog $log = null): bool
    {
        return $this->check($user, 'manage', $log);
    }
}
