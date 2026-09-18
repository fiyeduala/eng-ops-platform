<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Where somebody is paid.
 *
 * THE ACCOUNT NUMBER IS NOT VALIDATED AGAINST ANY FORMAT (§47). Nigerian NUBAN is ten digits, UK
 * account numbers are eight with a six-digit sort code, IBANs are up to thirty-four characters, and
 * a company operating in two countries needs both in the same register. A regex here would make the
 * platform reject half its own users' real bank details.
 *
 * IT IS STORED ENCRYPTED AND IS NEVER SHOWN BACK IN FULL. The model keeps the last four digits in a
 * separate column so a register can render "••••4417" without decrypting anything, which means the
 * common case — somebody checking they used the right account — never touches the real number.
 *
 * CHANGED BANK DETAILS ARE THE CLASSIC PAYROLL FRAUD: an email asking for an account to be updated,
 * honoured quietly, one salary paid to a stranger. The service records the change with a name and a
 * time against it, which is the only thing that makes it visible before pay day.
 */
class EmployeeBankAccountRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'bank_name' => ['required', 'string', 'max:150'],
            // The name on the account, which is checked against the person's own name by whoever
            // approves the run — a mismatch is the single most useful signal that something is wrong.
            'account_name' => ['required', 'string', 'max:150'],
            'account_number' => ['required', 'string', 'max:64'],
            'branch_code' => ['nullable', 'string', 'max:40'],
            'account_type' => ['nullable', 'string', 'max:40'],
            'is_primary' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks(['branch_code', 'account_type']);

        $this->merge([
            'is_primary' => $this->boolean('is_primary'),
            'is_active' => $this->boolean('is_active'),
        ]);
    }
}
