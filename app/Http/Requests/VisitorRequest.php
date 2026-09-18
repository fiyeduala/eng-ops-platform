<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Booking somebody in, or correcting a booking.
 *
 * THE GATE'S OWN COLUMNS ARE NOT HERE. No `checked_in_at`, no `status_id`, no `refusal_reason` — a
 * form that could post those would be a way through the gate without the permission that guards it,
 * and a way onto the roll call without ever arriving (§84). The service refuses them too; this is
 * the outer of the two.
 *
 * MOST OF THE FIELDS ARE OPTIONAL, AND THAT IS DELIBERATE. A walk-in gives a name and who they are
 * here to see, and that is genuinely all reception gets. Requiring a phone number would not produce
 * phone numbers — it would produce a column full of 0000000000, which is worse than a blank one
 * because it looks like data.
 *
 * THE PURPOSE IS COMPANY VOCABULARY (§47), so it is validated as a short string rather than against a
 * closed list. `Visitor::DEFAULT_PURPOSE_TYPES` is where a company starts, not where it has to stay.
 */
class VisitorRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:200'],
            'company_name' => ['nullable', 'string', 'max:200'],
            'phone' => ['nullable', 'string', 'max:60'],
            'email' => ['nullable', 'email', 'max:200'],

            // A delegation arrives on one record. The ceiling is arithmetic, not policy: anything
            // larger is an event with its own attendance list, not a visit.
            'party_size' => ['required', 'integer', 'between:1,200'],

            'host_employee_id' => ['nullable', 'integer', $this->existsInTenant('employees')],
            'org_unit_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],
            'location_id' => ['nullable', 'integer', $this->existsInTenant('locations')],
            'project_id' => ['nullable', 'integer', $this->existsInTenant('projects')],

            'purpose_type' => ['nullable', 'string', 'max:40'],
            'purpose' => ['nullable', 'string', 'max:300'],

            'expected_from' => ['nullable', 'date'],
            'expected_to' => ['nullable', 'date', 'after_or_equal:expected_from'],

            'requires_escort' => ['boolean'],
            'escort_employee_id' => ['nullable', 'integer', $this->existsInTenant('employees')],

            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'party_size' => $this->input('party_size') === null ? 1 : $this->input('party_size'),
            'requires_escort' => $this->boolean('requires_escort'),
        ]);

        $this->nullifyBlanks([
            'company_name', 'phone', 'email', 'host_employee_id', 'org_unit_id', 'location_id',
            'project_id', 'purpose_type', 'purpose', 'expected_from', 'expected_to',
            'escort_employee_id', 'notes',
        ]);
    }
}
