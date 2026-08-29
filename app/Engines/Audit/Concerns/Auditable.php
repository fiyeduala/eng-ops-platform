<?php

declare(strict_types=1);

namespace App\Engines\Audit\Concerns;

use App\Engines\Audit\AuditLogger;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Attach to any model whose changes must be forensically recorded (section 59).
 *
 * The trait hooks model events rather than relying on controllers remembering to log, because
 * "every change is recorded" is only true if it is impossible to forget.
 *
 * A model may declare:
 *   protected array $auditExclude = ['last_seen_at'];   // noisy columns
 *   protected array $auditOnly    = ['status', 'amount']; // whitelist instead
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function ($model) {
            $model->recordAudit('created', null, $model->auditableAttributes($model->getAttributes()));
        });

        static::updated(function ($model) {
            $changes = $model->auditableAttributes($model->getChanges());

            // Touching only excluded columns is not a business event; do not create noise.
            if ($changes === []) {
                return;
            }

            $old = array_intersect_key($model->getOriginal(), $changes);

            $model->recordAudit('updated', $old, $changes);
        });

        static::deleted(function ($model) {
            $soft = method_exists($model, 'trashed') && $model->trashed();

            $model->recordAudit(
                $soft ? 'archived' : 'deleted',
                $model->auditableAttributes($model->getOriginal()),
                null,
            );
        });

        if (method_exists(static::class, 'restored')) {
            static::restored(function ($model) {
                $model->recordAudit('restored', null, null);
            });
        }
    }

    /**
     * @param  array<string,mixed>|null  $old
     * @param  array<string,mixed>|null  $new
     */
    public function recordAudit(string $action, ?array $old = null, ?array $new = null, ?string $description = null): void
    {
        app(AuditLogger::class)->log($action, $this, $old, $new, $description);
    }

    /**
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    public function auditableAttributes(array $attributes): array
    {
        $only = property_exists($this, 'auditOnly') ? $this->auditOnly : [];

        if ($only !== []) {
            $attributes = array_intersect_key($attributes, array_flip($only));
        }

        $exclude = array_merge(
            ['updated_at', 'created_at', 'remember_token', 'password'],
            property_exists($this, 'auditExclude') ? $this->auditExclude : [],
        );

        return array_diff_key($attributes, array_flip($exclude));
    }

    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable')->latest('created_at');
    }
}
