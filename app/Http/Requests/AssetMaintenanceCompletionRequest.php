<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Sign a job card off: what was found, what it cost, and what the clock read.
 *
 * A SEPARATE FORM FROM THE JOB CARD, behind a separate permission, because certifying that a service
 * happened is not the same act as scheduling one. The planner who arranges a 500-hour service from a
 * desk is not the person who can say the machine is safe to run again, and an insurer, an inspector
 * and an accident investigation all read the second statement rather than the first (§6). The status
 * graph gates `in_progress → completed` on `asset_maintenance.complete` for the same reason, so both
 * doors carry the same lock.
 *
 * EVERY FIELD IS OPTIONAL, INCLUDING THE COSTS. A fitter closing a job at half past six knows what
 * they did; the invoice from the contractor arrives a fortnight later. Refusing the sign-off until
 * somebody can type a figure means the machine goes back to work with an open job card against it,
 * which is precisely the record that makes a maintenance history worthless.
 *
 * `downtime_hours` IS ACCEPTED RATHER THAN ALWAYS COMPUTED. The service derives it from `started_at`
 * when it is left blank, but elapsed time counts the weekend the workshop was shut — and availability
 * is a number people are held to. Whoever knows gets to say.
 *
 * THE ODOMETER GOES THROUGH THE FLEET GUARD. A reading typed here on a vehicle is fed to
 * FleetService::recordOdometer(), which refuses a figure lower than the one already on the clock —
 * so a transposed number is caught at the point of typing rather than producing a consumption
 * figure nobody can explain six months later.
 */
class AssetMaintenanceCompletionRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'labour_cost' => ['nullable', 'numeric', 'min:0', 'max:999999999999999'],
            'parts_cost' => ['nullable', 'numeric', 'min:0', 'max:999999999999999'],
            'downtime_hours' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'odometer' => ['nullable', 'integer', 'min:0', 'max:99999999'],
            'findings' => ['nullable', 'string', 'max:20000'],
            'vendor_id' => ['nullable', 'integer', $this->existsInTenant('vendors')],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks([
            'labour_cost', 'parts_cost', 'downtime_hours', 'odometer', 'findings', 'vendor_id',
        ]);
    }

    /**
     * The outcome, in the shape complete() expects.
     *
     * @return array<string,mixed>
     */
    public function outcome(): array
    {
        return $this->validated();
    }
}
