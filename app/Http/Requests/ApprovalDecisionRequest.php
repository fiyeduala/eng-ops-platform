<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Enums\Decision;
use Illuminate\Validation\Rule;

/**
 * Record a decision on a stage: the most consequential POST in the platform.
 *
 * NOTE WHAT IS ABSENT. There is no `user_id`, no `stage_instance_id` in the body, and no
 * `on_behalf_of` unless the stage allows delegation. The actor is `$request->user()`; the stage
 * comes from the bound route model; and both are re-checked by WorkflowStageInstancePolicy::act()
 * against the FROZEN assignee list before anything is written. An approval is a claim about who
 * agreed to what, so every part of that claim comes from the server (§84).
 *
 * A COMMENT IS REQUIRED FOR ANYTHING BUT APPROVAL. Rejecting a document or sending it back without
 * saying why produces a notification that tells its author only that they have been refused, and
 * the round trip to find out what is wrong is the single most common complaint about approval
 * systems. Approval needs no explanation; refusal always does.
 */
class ApprovalDecisionRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::enum(Decision::class)],

            'comment' => [
                Rule::requiredIf(fn () => $this->input('decision') !== Decision::Approve->value),
                'nullable', 'string', 'max:4000',
            ],

            // Only meaningful when the decision is `delegated`, and only when the stage permits it.
            // Validated as a tenant user here; whether THIS actor may hand the decision to them is
            // WorkflowStageInstancePolicy::delegate().
            'delegate_to' => [
                Rule::requiredIf(fn () => $this->input('decision') === Decision::Delegate->value),
                'nullable', 'integer', $this->existsInTenant('users', 'id', true),
            ],

            // Signing stages capture the signatory's typed name, which is the assertion itself
            // rather than decoration — see SignaturePolicy.
            'signature_name' => ['nullable', 'string', 'max:150'],
        ];
    }

    public function messages(): array
    {
        return [
            'comment.required' => 'Please say why. The author sees this, and it is the only thing '
                .'telling them what to change.',
        ];
    }

    public function decision(): Decision
    {
        return Decision::from($this->validated()['decision']);
    }

    public function comment(): ?string
    {
        $comment = $this->validated()['comment'] ?? null;

        return filled($comment) ? (string) $comment : null;
    }

    public function delegateToId(): ?int
    {
        $id = $this->validated()['delegate_to'] ?? null;

        return $id === null ? null : (int) $id;
    }
}
