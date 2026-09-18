<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * What the desk fills in at the moment somebody walks through the door.
 *
 * SEPARATE FROM VisitorRequest ON PURPOSE. Booking a visit and admitting one are different acts done
 * by different people under different permissions, and a single form object covering both would let
 * whoever may book also post a badge number and an induction — which is the check-in, done without
 * `visitor.check_in`.
 *
 * THE IDENTITY DOCUMENT IS ACCEPTED HERE AND NOWHERE ELSE, because the gate is the only place it is
 * ever seen. It is encrypted at rest, hidden from array and JSON output and absent from the search
 * columns, and whether it is asked for at all is `visitor.capture_id_document` — off by default,
 * because a number you did not need to collect is a number you do not have to protect (§67).
 */
class VisitorCheckInRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'party_size' => ['nullable', 'integer', 'between:1,200'],
            'badge_number' => ['nullable', 'string', 'max:40'],
            'vehicle_registration' => ['nullable', 'string', 'max:40'],
            'escort_employee_id' => ['nullable', 'integer', $this->existsInTenant('employees')],

            'id_document_type' => ['nullable', 'string', 'max:40'],
            'id_document_number' => ['nullable', 'string', 'max:100'],

            'induction_completed' => ['boolean'],
            // Not in the future: an induction is recorded when it has happened, and a date after
            // today would push the expiry the service stamps out with it.
            'induction_completed_on' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['induction_completed' => $this->boolean('induction_completed')]);

        $this->nullifyBlanks([
            'party_size', 'badge_number', 'vehicle_registration', 'escort_employee_id',
            'id_document_type', 'id_document_number', 'induction_completed_on',
        ]);
    }
}
