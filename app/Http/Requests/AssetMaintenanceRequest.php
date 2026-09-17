<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\AssetMaintenance;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Validation\Rule;

/**
 * Plan a service, or write up a breakdown that already happened.
 *
 * ONE FORM FOR BOTH, because the difference between them is a checkbox and a date. `is_planned` is
 * the column the whole maintenance analysis turns on — the ratio of planned to unplanned work is
 * what a maintenance department is judged on — so it is asked plainly rather than inferred from
 * which button was pressed.
 *
 * `due_on` AND `due_odometer` ARE BOTH OPTIONAL AND BOTH MEAN THE SAME THING in different units. A
 * generator is serviced every 250 running hours, a pickup every 5,000 km, an air conditioner every
 * six months. Either, both or neither may be given; whichever arrives first is what makes the work
 * due, and `AssetMaintenance::isDue()` reads them together.
 *
 * THE COSTS ARE NOT HERE. Neither is `downtime_hours`, `findings`, `completed_at` or `odometer`.
 * What a job cost and what was found are known when it is FINISHED, and they arrive through
 * AssetMaintenanceCompletionRequest behind `asset_maintenance.complete` — which is a genuinely
 * different right from scheduling the work, because closing a job card is the statement "this
 * machine is safe to use again" and an insurer reads it afterwards (§6).
 *
 * `reference` IS GENERATED, NOT TYPED. A maintenance job is an EVENT, and events are numbered by
 * NumberGenerator — MNT-2026-0001. `work_order_reference` beside it is where a genuine external
 * number goes, the one the contractor put on their invoice.
 */
class AssetMaintenanceRequest extends TenantRequest
{
    public function rules(): array
    {
        $lists = app(OptionList::class);

        return [
            // Only on create. The edit form omits the field entirely, because moving a job card to
            // a different machine after the fact rewrites two assets' service histories at once.
            'asset_id' => [
                $this->isMethod('POST') ? 'required' : 'prohibited',
                'integer', $this->existsInTenant('assets'),
            ],

            'title' => ['required', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:20000'],

            'is_planned' => ['boolean'],
            'maintenance_type' => [
                'nullable',
                Rule::in($lists->keys('asset_maintenance.types', AssetMaintenance::DEFAULT_TYPES)),
            ],

            'due_on' => ['nullable', 'date'],
            'due_odometer' => ['nullable', 'integer', 'min:0', 'max:99999999'],

            'performed_by' => ['nullable', 'integer', $this->existsInTenant('users')],
            'vendor_id' => ['nullable', 'integer', $this->existsInTenant('vendors')],
            'work_order_reference' => ['nullable', 'string', 'max:120'],

            'confidentiality' => ['required', Rule::enum(Confidentiality::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks([
            'description', 'maintenance_type', 'due_on', 'due_odometer',
            'performed_by', 'vendor_id', 'work_order_reference',
        ]);

        $this->normaliseBooleans(['is_planned']);
    }

    public function messages(): array
    {
        return [
            'asset_id.prohibited' => 'A job card cannot be moved to a different asset. Cancel it and raise another.',
            'title.required' => 'Say in a line what is being done — "500-hour service", "hydraulic leak".',
        ];
    }

    /** @return array<string,mixed> */
    public function maintenanceAttributes(): array
    {
        return $this->validated();
    }
}
