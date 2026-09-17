<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Payment;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Validation\Rule;

/**
 * What is being paid is chosen when the payment is raised and fixed afterwards; editing a draft
 * changes only the amount, method, reference, date and notes. Who is paid is never on the form —
 * the service copies it from the purchase order, expense or income (§84).
 */
class PaymentRequest extends TenantRequest
{
    public function rules(): array
    {
        $creating = $this->route('payment') === null;
        $direction = (string) $this->input('direction');

        return [
            'direction' => $creating ? ['required', Rule::in(array_keys(Payment::DIRECTIONS))] : ['prohibited'],
            'payable_type' => $creating ? ['required', Rule::in(Payment::PAYABLES[$direction] ?? [])] : ['prohibited'],
            'payable_id' => $creating ? ['required', 'integer'] : ['prohibited'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99'],
            'method' => ['required', 'string', Rule::in(app(OptionList::class)->keys('payment.methods', Payment::DEFAULT_METHODS))],
            'reference' => ['nullable', 'string', 'max:120'],
            'paid_on' => ['nullable', 'date'],
            'confidentiality' => ['required', Rule::enum(Confidentiality::class)],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks(['reference', 'paid_on', 'notes']);

        // The picker posts one value, "expense:42". The direction follows from the kind of payable,
        // so it cannot be set to disagree with it.
        $picked = $this->input('payable');
        if (is_string($picked) && str_contains($picked, ':')) {
            [$type, $id] = explode(':', $picked, 2);
            $direction = collect(Payment::PAYABLES)->search(fn (array $types) => in_array($type, $types, true));
            $this->merge(['payable_type' => $type, 'payable_id' => $id, 'direction' => $direction === false ? null : $direction]);
        }
    }
}
