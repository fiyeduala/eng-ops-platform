<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * The one-field POST behind every yes, no and stop in the procurement chain.
 *
 * ONE CLASS, BECAUSE THE FIELD IS THE SAME EVERYWHERE and the only thing that varies is whether it
 * has to be filled in. Approving needs no explanation; refusing, cancelling, disputing and
 * declining always do — the person on the other end has to know what to do next, and "rejected"
 * with no words is the single most common complaint about approval systems.
 *
 * WHICH ROUTE IS ASKING DECIDES THAT, read from the route NAME rather than from a posted flag. A
 * hidden `require_comment` field would be a client-side rule dressed up as a server-side one (§84).
 *
 * The services check the same thing again and throw with the same sentence, because a seeder, an
 * import and another module all reach them without passing through here.
 */
class ProcurementDecisionRequest extends TenantRequest
{
    /** Acts that must say why. Everything else may. */
    private const EXPLAINED = ['reject', 'cancel', 'dispute', 'decline'];

    public function rules(): array
    {
        return [
            'comment' => [
                Rule::requiredIf(fn () => $this->needsReason()),
                'nullable', 'string', 'max:4000',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'comment.required' => 'Please say why. The people waiting on this see it, and it is the '
                .'only thing telling them what happens next.',
        ];
    }

    public function comment(): ?string
    {
        $comment = $this->validated()['comment'] ?? null;

        return blank($comment) ? null : trim((string) $comment);
    }

    /** The same value, non-null, for the calls that demand one. */
    public function reason(): string
    {
        return (string) $this->comment();
    }

    private function needsReason(): bool
    {
        $name = (string) ($this->route()?->getName() ?? '');
        $action = str_contains($name, '.') ? substr($name, (int) strrpos($name, '.') + 1) : $name;

        return in_array($action, self::EXPLAINED, true);
    }
}
