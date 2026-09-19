<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\NumberingScheme;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape-checking for the numbering editor.
 *
 * This asks only whether the fields are the right KIND of thing. Whether the pattern can actually
 * issue distinct numbers — that it consumes the counter, that a resetting series carries a date,
 * that the counter is not being wound back — is NumberingSchemeService's business, because those
 * rules have to hold for every caller and a rule living in a form request only holds for a form.
 */
class NumberingSchemeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // NumberingController consults NumberingSchemePolicy
    }

    /** @return array<string,array<int,mixed>> */
    public function rules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:80'],

            // 120 rather than unbounded: a pattern is a handful of tokens and separators, and the
            // column behind it is not a text field.
            'pattern' => ['required', 'string', 'max:120'],

            // A prefix is said out loud — "PO two six oh one eight four". Twelve characters is
            // already generous; the letters are the point, not the room.
            'prefix' => ['nullable', 'string', 'max:12'],

            'reset_period' => ['required', 'string', 'in:'.implode(',', NumberingScheme::RESET_PERIODS)],

            // Twelve digits of padding is a trillion records. The cap exists so that a stray
            // keystroke cannot produce a reference nobody can read across a table column.
            'padding' => ['required', 'integer', 'between:1,12'],

            'start_at' => ['required', 'integer', 'min:1', 'max:999999999'],
        ];
    }

    /** @return array<string,string> */
    public function attributes(): array
    {
        return [
            'reset_period' => 'reset',
            'start_at' => 'starting number',
        ];
    }

    /**
     * The fields the service is allowed to see.
     *
     * organization_id, entity_type and is_active are absent by design. The first two are the
     * platform's to decide (§74, §84) and the third is not on this screen — a numbering scheme
     * cannot be switched off, because a register that creates records still has to name them.
     *
     * @return array<string,mixed>
     */
    public function schemeAttributes(): array
    {
        return $this->safe()->only(['label', 'pattern', 'prefix', 'reset_period', 'padding', 'start_at']);
    }
}
