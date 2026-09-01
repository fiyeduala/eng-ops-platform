<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * The checkpoints on a quality inspection, posted whole.
 *
 * `acceptance_criteria` IS THE COLUMN THAT DOES THE WORK. "Check the weld" is not a checkpoint;
 * "undercut not to exceed 1mm, AWS D1.1 Table 6.1" is one, because only the second can be failed
 * by somebody other than its author. It is the same argument the NCR register makes for keeping
 * `requirement` separate from `description`, and it is the cheapest quality intervention this
 * schema makes.
 *
 * The measured value and the verdict are NOT accepted here — they belong to the inspection itself,
 * are written by `inspect()`, and are matched against this inspection's own rows so that a posted
 * id from another record writes nothing (§84).
 */
class QualityInspectionItemsRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'items' => ['nullable', 'array', 'max:300'],

            'items.*.reference' => ['nullable', 'string', 'max:60'],
            'items.*.description' => ['nullable', 'string', 'max:2000'],
            'items.*.acceptance_criteria' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function itemRows(): array
    {
        return array_values($this->validated()['items'] ?? []);
    }
}
