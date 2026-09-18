<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * The decision on a case, and the date the sanction stops counting.
 *
 * THE OUTCOME LIST IS COMPANY DATA. `DisciplinaryCase::DEFAULT_OUTCOMES` seeds the dropdown, but
 * what a company's handbook calls its steps — and how many there are before dismissal — is theirs
 * (§47). The rule checks that something was chosen.
 *
 * THE EXPIRY IS THE POINT OF THE FORM. A written warning that never lapses makes every first offence
 * permanent, so the field is offered on the same screen as the outcome rather than tucked away where
 * it is forgotten. Leaving it blank is allowed and means exactly what it says: this one does not
 * expire.
 */
class DisciplinaryOutcomeRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'outcome' => ['required', 'string', 'max:40'],
            'outcome_notes' => ['nullable', 'string', 'max:10000'],
            'sanction_expires_on' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks(['outcome_notes', 'sanction_expires_on']);
    }
}
