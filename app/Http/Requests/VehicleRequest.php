<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Vehicle;
use App\Support\OptionList;
use Illuminate\Validation\Rule;

/**
 * Give an asset a plate, or correct the particulars of one that has one.
 *
 * IT ALWAYS STARTS FROM AN ASSET. There is no "add a vehicle" that does not first add a thing the
 * company owns — a pickup is bought, capitalised, transferred, maintained and retired exactly like a
 * generator, and everything in the asset register applies to it unchanged. This form attaches the
 * extra page: a plate, a VIN, an odometer and a fuel habit. `asset_id` is therefore required on
 * create and prohibited on edit, because moving a plate to a different machine is not an edit, it is
 * two mistakes.
 *
 * THE PLATE IS NORMALISED, NOT MERELY VALIDATED. "ABC 123 XY", "abc-123-xy" and "ABC123XY" are one
 * vehicle, and a fleet that stores all three has three vehicles as far as the unique index is
 * concerned. It is compared after normalisation for the same reason the store code is: a validator
 * that passes "abc 123" and then collides with "ABC123" on insert is a validator that lies.
 *
 * `current_odometer` IS ACCEPTED ONLY ON CREATE, and it is the one reading in the whole platform
 * that does not pass the monotonicity guard — because there is nothing yet for it to go backwards
 * from. A vehicle bought second-hand starts wherever its clock says. Afterwards the counter moves
 * only through FleetService::recordOdometer(), from a fill, a completed service or a reading typed
 * on the vehicle page.
 *
 * `assigned_driver_id` IS NOT HERE AT ALL. Whose licence is on the line when a truck is stopped sits
 * behind `vehicle.assign`, on its own form and its own route. A transport clerk who may correct a
 * tyre size should not, by the same right, be able to put somebody else's name on a lorry (§84).
 */
class VehicleRequest extends TenantRequest
{
    public function rules(): array
    {
        $lists = app(OptionList::class);
        $vehicle = $this->route('vehicle');
        $creating = $this->isMethod('POST');

        return [
            'asset_id' => [
                $creating ? 'required' : 'prohibited',
                'integer', $this->existsInTenant('assets'),
            ],

            'registration_number' => [
                'required', 'string', 'max:40',
                $this->uniqueInTenant('vehicles', 'registration_number', $vehicle?->getKey()),
            ],

            'vin' => ['nullable', 'string', 'max:60'],
            // 1900 rather than a tighter floor: plant outlives everybody, and a 1974 generator on a
            // trailer is a vehicle somebody has to license. The upper bound allows next year's
            // model, which is genuinely how vehicles are sold.
            'year_of_manufacture' => ['nullable', 'integer', 'min:1900', 'max:'.(now()->year + 1)],
            'colour' => ['nullable', 'string', 'max:40'],

            'vehicle_type' => ['nullable', Rule::in($lists->keys('vehicle.types', Vehicle::DEFAULT_TYPES))],
            'fuel_type' => ['nullable', Rule::in($lists->keys('vehicle.fuel_types', Vehicle::DEFAULT_FUEL_TYPES))],
            'tank_capacity' => ['nullable', 'numeric', 'min:0', 'max:99999999'],

            'odometer_unit' => ['nullable', Rule::in(Vehicle::ODOMETER_UNITS)],
            'current_odometer' => [
                $creating ? 'nullable' : 'prohibited',
                'integer', 'min:0', 'max:99999999',
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks([
            'vin', 'year_of_manufacture', 'colour', 'vehicle_type', 'fuel_type',
            'tank_capacity', 'odometer_unit', 'current_odometer',
        ]);

        if (is_string($this->input('registration_number'))) {
            $this->merge([
                'registration_number' => mb_strtoupper(
                    preg_replace('/[\s\-]+/', '', trim($this->input('registration_number'))) ?? ''
                ),
            ]);
        }
    }

    public function messages(): array
    {
        return [
            'registration_number.unique' => 'Another vehicle already carries that plate.',
            'asset_id.prohibited' => 'A plate cannot be moved to a different asset.',
            'current_odometer.prohibited' => 'The odometer moves through a fill, a service, or the reading form on the vehicle page.',
        ];
    }

    /** @return array<string,mixed> */
    public function vehicleAttributes(): array
    {
        return $this->validated();
    }
}
