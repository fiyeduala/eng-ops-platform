<?php

declare(strict_types=1);

namespace App\Engines\Audit;

use App\Engines\Access\OrganizationContext;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Engine 4, human half (section 60).
 *
 * The audit log answers "what column changed"; this answers "what happened, in a sentence a
 * project manager can read". Every record page renders its own timeline from these rows, and the
 * project timeline is one indexed query thanks to the denormalised project_id.
 */
class ActivityRecorder
{
    public function __construct(
        private readonly OrganizationContext $organization,
    ) {}

    /**
     * @param  array<string,mixed>  $properties
     */
    public function record(
        Model $subject,
        string $verb,
        ?string $description = null,
        array $properties = [],
        ?User $actor = null,
        ?int $projectId = null,
        ?int $orgUnitId = null,
    ): void {
        $actor ??= Auth::user();

        DB::table('activity_logs')->insert([
            'organization_id' => $subject->getAttribute('organization_id')
                ?? $this->organization->id()
                ?? $actor?->organization_id,
            'causer_id' => $actor?->id,
            'causer_name' => $actor?->name,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'project_id' => $projectId ?? $subject->getAttribute('project_id'),
            'org_unit_id' => $orgUnitId ?? $subject->getAttribute('org_unit_id'),
            'verb' => $verb,
            'description' => mb_substr($description ?? $this->describe($subject, $verb, $actor), 0, 500),
            'properties' => $properties !== [] ? json_encode($properties) : null,
            'created_at' => now(),
        ]);
    }

    /**
     * Timeline for one record, newest first.
     *
     * @return \Illuminate\Database\Eloquent\Builder<ActivityLog>
     */
    public function timelineFor(Model $record)
    {
        return ActivityLog::forRecord($record)->with('causer')->latest('created_at');
    }

    /**
     * Timeline for a whole project — every module's activity, one query (section 88).
     *
     * @return \Illuminate\Database\Eloquent\Builder<ActivityLog>
     */
    public function timelineForProject(int $projectId)
    {
        return ActivityLog::forProject($projectId)->with('causer')->latest('created_at');
    }

    private function describe(Model $subject, string $verb, ?User $actor): string
    {
        $label = method_exists($subject, 'displayName')
            ? $subject->displayName()
            : class_basename($subject).' #'.$subject->getKey();

        return trim(sprintf('%s %s %s', $actor?->name ?? 'System', str_replace('_', ' ', $verb), $label));
    }
}
