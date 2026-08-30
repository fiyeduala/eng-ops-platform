<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\MeetingService;
use App\Support\Enums\Confidentiality;
use Illuminate\Validation\Rule;

/**
 * Arrange or move a meeting (§19).
 *
 * Absent by design: `number` (numbering engine), `status_id` (whitelist), `organization_id`
 * (session), and the four minutes columns. Minutes are written through their own route with their
 * own permission — `meeting.record_minutes` is a different authority from `meeting.edit`, held by a
 * different person in most organizations, and accepting a `minutes` field on the edit form would
 * collapse the two (§84).
 *
 * `organizer_id` IS accepted, unlike a task's assignee, and the asymmetry is deliberate. Being
 * organizer is an owner column, so naming somebody else confers reach — but it confers reach over a
 * meeting the actor is arranging and could equally have invited them to, and administrators booking
 * on a director's behalf is the normal case rather than the exception. MeetingPolicy::invite() is
 * still asked about the candidate, because same-tenant and can-log-in are not negotiable.
 *
 * `participants` is create-only for the same reason a task's assignee is: inviting is a grant with
 * its own route, its own policy question and its own event.
 */
class MeetingRequest extends TenantRequest
{
    public function rules(): array
    {
        $editing = $this->route('meeting') !== null;

        return [
            'title' => ['required', 'string', 'max:200'],
            'agenda' => ['nullable', 'string', 'max:20000'],

            'project_id' => ['nullable', 'integer', $this->existsInTenant('projects')],
            'org_unit_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],

            'confidentiality' => ['required', Rule::enum(Confidentiality::class)],

            // A meeting with no start time is not a meeting.
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],

            'location' => ['nullable', 'string', 'max:200'],

            // `url` rather than `active_url`: the second makes a DNS call during validation, which
            // turns a form submission into an outbound request and a slow resolver into a timeout.
            'meeting_url' => ['nullable', 'url', 'max:500'],

            'organizer_id' => ['nullable', 'integer', $this->existsInTenant('users', 'id', true)],

            'participants' => $editing ? ['nullable'] : ['nullable', 'array', 'max:200'],
            'participants.*' => $editing
                ? ['nullable']
                : ['integer', $this->existsInTenant('users', 'id', true)],

            'participant_role' => ['nullable', Rule::in(MeetingService::roles())],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks([
            'agenda', 'project_id', 'org_unit_id', 'ends_at',
            'location', 'meeting_url', 'organizer_id', 'participant_role',
        ]);

        $this->merge([
            'confidentiality' => $this->input('confidentiality') ?: Confidentiality::Internal->value,
        ]);
    }

    /** @return array<string,mixed> */
    public function meetingAttributes(): array
    {
        $data = $this->validated();

        unset($data['participants'], $data['participant_role']);

        return $data;
    }

    /** @return array<int,int> */
    public function participantIds(): array
    {
        $ids = $this->validated()['participants'] ?? [];

        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    public function participantRole(): string
    {
        $role = $this->validated()['participant_role'] ?? null;

        return is_string($role) && $role !== '' ? $role : 'participant';
    }

    public function attributes(): array
    {
        return [
            'project_id' => 'project',
            'org_unit_id' => 'department',
            'starts_at' => 'start time',
            'ends_at' => 'end time',
            'meeting_url' => 'meeting link',
            'organizer_id' => 'organizer',
            'participants.*' => 'participant',
        ];
    }
}
