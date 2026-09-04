<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Approve, refuse or issue against a material request — one form for three verbs.
 *
 * ONE CLASS BECAUSE THE PAYLOAD IS THE SAME SHAPE: a note, and a quantity per line. What differs is
 * what the quantity means — how much is being approved, or how much is going out of the door right
 * now — and that is decided by the route, not by the body.
 *
 * THE QUANTITIES ARE KEYED BY LINE ID AND ARE NOT CHECKED FOR OWNERSHIP HERE. They cannot usefully
 * be: the check is "does this id belong to THIS request", which needs the request, and the service
 * has it. MaterialRequestService walks the request's own lines and looks each id up in the posted
 * array — so an id belonging to somebody else's request is not refused, it is simply never asked
 * about, which is the safer failure of the two (§84).
 *
 * A NEGATIVE IS REFUSED AND AN OVER-ISSUE IS CLAMPED. The first is nonsense and is worth a message;
 * the second is a storekeeper typing 100 where 10 was outstanding, and the service silently holds
 * it to what is left rather than refusing the whole handover.
 */
class MaterialRequestDecisionRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:5000'],
            'quantities' => ['nullable', 'array', 'max:200'],
            'quantities.*' => ['nullable', 'numeric', 'gte:0', 'max:99999999999999'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks(['notes']);
    }

    public function messages(): array
    {
        return [
            'quantities.*.gte' => 'A quantity cannot be less than nothing.',
        ];
    }

    /**
     * The note, whatever the verb calls it.
     *
     * A rejection must carry one and the service says so — the check is there rather than here
     * because `reject()` is reachable from a seeder and an import as well as from this form.
     */
    public function note(): ?string
    {
        $value = $this->validated('notes');

        return blank($value) ? null : trim((string) $value);
    }

    /**
     * Line id => quantity, with the blanks dropped.
     *
     * AN ABSENT LINE MEANS "AS ASKED FOR", not "none" — see MaterialRequestService::approve(). So
     * the empty strings a form posts for untouched rows must be removed here rather than cast to
     * zero, or an approver who edited one line would refuse every other one by leaving it alone.
     *
     * @return array<int,float>
     */
    public function quantities(): array
    {
        $out = [];

        foreach ((array) $this->validated('quantities', []) as $id => $quantity) {
            if (blank($quantity)) {
                continue;
            }

            $out[(int) $id] = (float) $quantity;
        }

        return $out;
    }
}
