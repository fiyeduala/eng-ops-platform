<?php

declare(strict_types=1);

namespace App\Engines\Audit\Concerns;

use App\Engines\Audit\ActivityRecorder;
use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Gives a record a human-readable timeline (section 60).
 *
 * Deliberately NOT automatic on every model event. Timeline entries are written by services at
 * the moment a business event happens ("submitted for approval", "revision B superseded revision
 * A"), because a narrative built from column diffs reads like a database dump, not a history.
 */
trait HasActivity
{
    /**
     * @param  array<string,mixed>  $properties
     */
    public function recordActivity(string $verb, ?string $description = null, array $properties = []): void
    {
        app(ActivityRecorder::class)->record($this, $verb, $description, $properties);
    }

    public function activityLogs(): MorphMany
    {
        return $this->morphMany(ActivityLog::class, 'subject')->latest('created_at');
    }

    /** Human label used in generated activity sentences; override per model. */
    public function displayName(): string
    {
        foreach (['reference', 'number', 'code', 'title', 'name'] as $attribute) {
            if (! empty($this->getAttribute($attribute))) {
                return (string) $this->getAttribute($attribute);
            }
        }

        return class_basename($this).' #'.$this->getKey();
    }
}
