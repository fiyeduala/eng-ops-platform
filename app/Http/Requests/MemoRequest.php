<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Enums\Confidentiality;
use Illuminate\Validation\Rule;

/**
 * Draft or amend a memo (§18).
 *
 * The three usual absences apply — `number` comes from the numbering engine, `status_id` moves only
 * through StatusMachine's whitelist, `organization_id` comes from the session — and one more that is
 * specific to this module: `issued_at` and `issued_by` are never accepted. Those columns are the
 * draft curtain that ScopeCompiler::applyPublication() reads, so a hidden field of either name would
 * be a way to publish a memo to its distribution list without holding `memo.issue` (§84).
 *
 * `recipients` is here on CREATE only. Addressing somebody is a visibility grant — for a restricted
 * memo it literally writes an ACL row — so it goes through MemoPolicy::address() with each candidate
 * in hand, and on edit it has its own route for the same reason task assignment does.
 */
class MemoRequest extends TenantRequest
{
    public function rules(): array
    {
        $editing = $this->route('memo') !== null;

        return [
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:20000'],

            // Both nullable: a memo to three people about nothing in particular is a normal memo.
            'project_id' => ['nullable', 'integer', $this->existsInTenant('projects')],
            'org_unit_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],

            'confidentiality' => ['required', Rule::enum(Confidentiality::class)],

            // Create only. See the class docblock.
            'recipients' => $editing ? ['nullable'] : ['nullable', 'array', 'max:200'],
            'recipients.*' => $editing
                ? ['nullable']
                : ['integer', $this->existsInTenant('users', 'id', true)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks(['project_id', 'org_unit_id']);

        $this->merge([
            'confidentiality' => $this->input('confidentiality') ?: Confidentiality::Internal->value,
        ]);
    }

    /**
     * The validated payload with the fields the service owns removed.
     *
     * @return array<string,mixed>
     */
    public function memoAttributes(): array
    {
        $data = $this->validated();

        unset($data['recipients']);

        return $data;
    }

    /** @return array<int,int> */
    public function recipientIds(): array
    {
        $ids = $this->validated()['recipients'] ?? [];

        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    public function attributes(): array
    {
        return [
            'project_id' => 'project',
            'org_unit_id' => 'department',
            'recipients.*' => 'recipient',
        ];
    }
}
