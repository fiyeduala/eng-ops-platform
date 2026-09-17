<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Audit\ActivityRecorder;
use App\Models\TaxCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Tax codes are configuration a finance manager types in (§47).
 *
 * A rate change is recorded with the old and new figure, because "when did VAT change, and who
 * changed it" is the first question anybody asks when this year's totals disagree with last year's.
 */
class TaxCodeService
{
    public function __construct(private readonly ActivityRecorder $activity) {}

    public function create(array $attributes, User $actor): TaxCode
    {
        return DB::transaction(function () use ($attributes, $actor) {
            $code = new TaxCode($this->attributes($attributes));
            $code->organization_id = $actor->organization_id;
            $code->created_by = $actor->id;
            $code->updated_by = $actor->id;
            $code->save();
            $this->activity->record($code, 'created', $actor->name.' created the tax code '.$code->displayName().'.', [], $actor);

            return $code;
        });
    }

    public function update(TaxCode $code, array $attributes, User $actor): TaxCode
    {
        return DB::transaction(function () use ($code, $attributes, $actor) {
            $before = (string) $code->rate;
            $code->fill($this->attributes($attributes));
            $code->updated_by = $actor->id;
            $rateChanged = $code->isDirty('rate');
            $code->save();
            $this->activity->record(
                $code,
                'updated',
                $actor->name.' updated the tax code '.$code->displayName().'.',
                $rateChanged ? ['rate_from' => $before, 'rate_to' => (string) $code->rate] : [],
                $actor,
            );

            return $code;
        });
    }

    private function attributes(array $attributes): array
    {
        return [
            'code' => mb_strtoupper(trim((string) ($attributes['code'] ?? ''))),
            'name' => trim((string) ($attributes['name'] ?? '')),
            'rate' => (string) ($attributes['rate'] ?? '0'),
            'applies_to' => (string) ($attributes['applies_to'] ?? 'both'),
            'is_recoverable' => (bool) ($attributes['is_recoverable'] ?? false),
            'is_active' => (bool) ($attributes['is_active'] ?? false),
            'description' => $attributes['description'] ?? null,
        ];
    }
}
