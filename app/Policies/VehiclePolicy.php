<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Vehicle;

/**
 * The engine half of an asset.
 *
 * A VEHICLE ROW IS NEVER CREATED OR DESTROYED ON ITS OWN. It is the extra page an asset gets when
 * `assets.is_vehicle` is true — a plate, a VIN, a tank size and an odometer — so the acts that read
 * as "add a vehicle" and "scrap a vehicle" are really `asset.create` and `asset.dispose` on the
 * thing itself. `vehicle.create` therefore governs a narrower act than its name suggests: attaching
 * the engine detail to an asset that already exists.
 *
 * That split is deliberate rather than tidy. If a truck were its own register, it would need its
 * own custody trail, its own acquisition link back to the goods receipt, and its own retirement —
 * three mechanisms that already exist on Asset and would then exist twice, differing quietly
 * (§90). Keeping one register means the question "who had it, what did we pay, where did it go"
 * has one answer for a truck and a generator alike.
 *
 * `assign` IS THE DRIVER RIGHT AND IT IS NOT `edit`. Naming the driver of a vehicle decides who is
 * answerable for it on the road, whose licence is on the line, and which fuel records are theirs to
 * file. A transport clerk correcting a tyre size should not, by the same permission, be able to put
 * somebody else's name behind the wheel.
 */
class VehiclePolicy extends ScopedPolicy
{
    protected string $module = 'vehicle';

    /** Name the driver answerable for this vehicle. */
    public function assign(User $user, ?Vehicle $vehicle = null): bool
    {
        return $this->check($user, 'assign', $vehicle);
    }

    public function manage(User $user, ?Vehicle $vehicle = null): bool
    {
        return $this->check($user, 'manage', $vehicle);
    }
}
