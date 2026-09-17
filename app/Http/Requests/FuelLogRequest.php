<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * A fill: litres in, a reading on the clock, and what was paid.
 *
 * THE TOTAL IS ASKED FOR AND THE UNIT PRICE IS NOT. A driver is standing with a receipt that says
 * litres and a total; the pump price is what falls out of the two. Asking for the unit price instead
 * and multiplying it produces a figure that disagrees with the paper by a few kobo on every fill and
 * by a real number over a year — and the paper is what an auditor holds. FleetService derives
 * `unit_cost` from the total.
 *
 * THE ODOMETER IS `nullable` HERE AND FREQUENTLY COMPULSORY IN PRACTICE. Whether a reading is
 * required is a per-organization setting — a haulier lives or dies by consumption per 100km and wants
 * it on every fill, a small firm with two vans wants the fuel spend and nothing else — and the
 * SERVICE enforces it, so a seeder, an import and an API call obey the company's rule too. This form
 * reads the same setting only to phrase the requirement in the message; the control is one place.
 *
 * A BACKWARDS READING IS NOT REFUSED HERE EITHER. FleetService::recordOdometer() is the single writer
 * of `current_odometer` and holds the monotonicity guard, and its refusal names both figures — the
 * one on the clock and the one typed — which is a sentence a driver can act on. A validation rule
 * could only say "invalid".
 *
 * `is_full_tank` MATTERS MORE THAN IT LOOKS. Consumption can only be computed between two full
 * tanks; a partial fill has no idea what went in before it. The flag is what lets the arithmetic
 * decline to answer honestly instead of inventing a figure.
 */
class FuelLogRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            // Only on create. Moving a receipt to a different vehicle after the fact rewrites two
            // vehicles' consumption histories, and the second one silently.
            'vehicle_id' => [
                $this->isMethod('POST') ? 'required' : 'prohibited',
                'integer', $this->existsInTenant('vehicles'),
            ],

            'filled_at' => ['required', 'date', 'before_or_equal:now'],
            'litres' => ['required', 'numeric', 'gt:0', 'max:999999'],
            'odometer' => ['nullable', 'integer', 'min:0', 'max:99999999'],

            'total_cost' => ['nullable', 'numeric', 'min:0', 'max:999999999999999'],
            'is_full_tank' => ['boolean'],

            'driver_id' => ['nullable', 'integer', $this->existsInTenant('users')],
            'project_id' => ['nullable', 'integer', $this->existsInTenant('projects')],
            'station' => ['nullable', 'string', 'max:200'],
            'receipt_reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:20000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks([
            'odometer', 'total_cost', 'driver_id', 'project_id',
            'station', 'receipt_reference', 'notes',
        ]);

        $this->normaliseBooleans(['is_full_tank']);
    }

    public function messages(): array
    {
        return [
            'litres.gt' => 'A fuel record needs the number of litres that went into the tank.',
            'filled_at.before_or_equal' => 'A fill cannot be recorded for a date that has not happened.',
            'vehicle_id.prohibited' => 'A fuel record cannot be moved to a different vehicle.',
        ];
    }

    /** @return array<string,mixed> */
    public function fuelAttributes(): array
    {
        return $this->validated();
    }
}
