<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Audit\ActivityRecorder;
use App\Models\CostCenter;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CostCenterService
{
    public function __construct(private readonly ActivityRecorder $activity) {}

    public function create(array $attributes, User $actor): CostCenter
    {
        return DB::transaction(function () use ($attributes, $actor) {
            $center = new CostCenter($this->attributes($attributes));
            $center->organization_id = $actor->organization_id;
            $center->created_by = $actor->id;
            $center->updated_by = $actor->id;
            $center->save();
            $this->activity->record($center, 'created', $actor->name.' created '.$center->displayName().'.', [], $actor, $center->project_id);

            return $center;
        });
    }

    public function update(CostCenter $center, array $attributes, User $actor): CostCenter
    {
        return DB::transaction(function () use ($center, $attributes, $actor) {
            $center->fill($this->attributes($attributes));
            $center->updated_by = $actor->id;
            $center->save();
            $this->activity->record($center, 'updated', $actor->name.' updated '.$center->displayName().'.', [], $actor, $center->project_id);

            return $center;
        });
    }

    private function attributes(array $attributes): array
    {
        return [
            'code' => mb_strtoupper(trim((string) ($attributes['code'] ?? ''))),
            'name' => trim((string) ($attributes['name'] ?? '')),
            'description' => $attributes['description'] ?? null,
            'org_unit_id' => $attributes['org_unit_id'] ?? null,
            'project_id' => $attributes['project_id'] ?? null,
            'manager_id' => $attributes['manager_id'] ?? null,
            'is_active' => (bool) ($attributes['is_active'] ?? false),
        ];
    }
}
