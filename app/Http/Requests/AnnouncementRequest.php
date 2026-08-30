<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * Draft or amend an announcement (§18).
 *
 * `published_at` and `published_by` are never accepted, for the reason given on MemoRequest: they
 * are the draft curtain, and a hidden field of either name would publish a notice to the whole
 * company without `announcement.publish`.
 *
 * There is no `confidentiality` field, and that is a schema decision rather than an omission — an
 * announcement is a broadcast by definition, so the column does not exist. The narrowing that IS
 * available is the addressing pair, `org_unit_id` and `project_id`, and it decides who the notice is
 * ROUTED to, never who may read it. Keeping those two ideas apart is the whole design of this
 * module; see Announcement::scopeAddressedTo().
 *
 * `urgency` is authored here rather than fixed in the catalogue because a staff notice and a site
 * closure are the same notification key on different weeks. It can escalate a notification past
 * quiet hours, which is why it is a closed list and not free text.
 */
class AnnouncementRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:20000'],

            'project_id' => ['nullable', 'integer', $this->existsInTenant('projects')],
            'org_unit_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],

            // A notice that expired before it was written is a data-entry slip, not an archive.
            'expires_on' => ['nullable', 'date', 'after_or_equal:today'],

            'is_pinned' => ['boolean'],
            'urgency' => ['required', Rule::in(self::urgencies())],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks(['project_id', 'org_unit_id', 'expires_on']);
        $this->normaliseBooleans(['is_pinned']);

        $this->merge([
            'urgency' => $this->input('urgency') ?: 'normal',
        ]);
    }

    /** @return array<string,mixed> */
    public function announcementAttributes(): array
    {
        return $this->validated();
    }

    /** @return array<int,string> */
    public static function urgencies(): array
    {
        return array_keys(self::urgencyOptions());
    }

    /**
     * The same closed list as a value => label map, for the form control.
     *
     * One definition, read two ways: the validation rule takes the keys and the select takes the
     * pairs, so a fourth urgency can never exist in the picker without also being accepted, or the
     * other way round.
     *
     * @return array<string,string>
     */
    public static function urgencyOptions(): array
    {
        return [
            'normal' => 'Normal',
            'high' => 'High — worth interrupting for',
            'urgent' => 'Urgent — ignores quiet hours',
        ];
    }

    public function attributes(): array
    {
        return [
            'project_id' => 'project',
            'org_unit_id' => 'department',
            'expires_on' => 'expiry date',
            'is_pinned' => 'pinned',
        ];
    }
}
