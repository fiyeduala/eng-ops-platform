<?php

declare(strict_types=1);

namespace App\Engines\Audit;

use App\Engines\Access\OrganizationContext;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Engine 4, technical half (section 59).
 *
 * Writes are done with the query builder rather than the Eloquent model, because AuditLog's
 * model events refuse mutation — and because audit writing must stay cheap and side-effect free.
 */
class AuditLogger
{
    /**
     * Attributes never written to the audit trail, in any model.
     * Recording a password hash or token in a widely-readable log is itself a vulnerability.
     */
    private const REDACTED = [
        'password', 'password_confirmation', 'remember_token', 'api_token',
        'two_factor_secret', 'two_factor_recovery_codes', 'secret', 'token',
        'access_token', 'refresh_token', 'signature_path',
    ];

    public function __construct(
        private readonly AuditContext $context,
        private readonly OrganizationContext $organization,
    ) {}

    /**
     * Record a change against a model.
     *
     * @param  array<string,mixed>|null  $old
     * @param  array<string,mixed>|null  $new
     */
    public function log(
        string $action,
        ?Model $subject = null,
        ?array $old = null,
        ?array $new = null,
        ?string $description = null,
        ?User $actor = null,
    ): void {
        $actor ??= Auth::user();

        DB::table('audit_logs')->insert([
            'organization_id' => $this->organizationIdFor($subject, $actor),
            'user_id' => $actor?->id,
            'user_name' => $actor?->name,
            'action' => $action,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'module' => $subject ? $this->moduleFor($subject) : null,
            'description' => $description !== null ? mb_substr($description, 0, 255) : null,
            'old_values' => $old !== null ? json_encode($this->redact($old)) : null,
            'new_values' => $new !== null ? json_encode($this->redact($new)) : null,
            'is_privileged' => $this->isPrivileged($action, $subject, $actor),
            ...$this->context->toArray(),
            'created_at' => now(),
        ]);
    }

    /** Convenience for read-tracking: downloads, previews and exports of sensitive material. */
    public function logAccess(string $action, Model $subject, ?string $description = null, ?User $actor = null): void
    {
        $this->log($action, $subject, null, null, $description, $actor);
    }

    /**
     * Record a failed authorization attempt. These are the entries that reveal probing.
     */
    public function logDenied(string $ability, ?Model $subject = null, ?User $actor = null): void
    {
        $this->log(
            action: 'authorization_denied',
            subject: $subject,
            description: 'Denied: '.$ability,
            actor: $actor,
        );
    }

    /**
     * Which tenant does this entry belong to?
     *
     * An Organization has no organization_id — it IS the organization — so without the second
     * branch a tenant's own creation and every later change to its profile would fall out of its
     * audit trail entirely, which is precisely the record a compliance reviewer asks for first.
     */
    private function organizationIdFor(?Model $subject, ?User $actor): ?int
    {
        if ($subject instanceof Organization) {
            return $subject->getKey();
        }

        $id = $subject?->getAttribute('organization_id')
            ?? $this->organization->id()
            ?? $actor?->organization_id;

        return $id !== null ? (int) $id : null;
    }

    /**
     * Security-relevant entries, flagged so they can be reviewed without reading everything.
     *
     * "Privileged" here describes the ENTRY, not the person: granting a role is privileged
     * whoever does it, because it changes what someone else can reach. Defining it as "the actor
     * was a super admin" would have hidden the single most important case — an ordinary
     * administrator quietly widening their own access.
     *
     * This is an index for reviewers (section 59). It is never an enforcement point.
     */
    private function isPrivileged(string $action, ?Model $subject, ?User $actor): bool
    {
        if ($actor?->is_super_admin) {
            return true;
        }

        if (in_array($action, ['authorization_denied', 'setting_changed', 'impersonated', 'password_reset'], true)) {
            return true;
        }

        if ($subject === null) {
            return false;
        }

        // Anything that changes who can do what.
        return in_array($this->moduleFor($subject), [
            'role', 'permission', 'user', 'record_permission', 'setting', 'organization',
        ], true);
    }

    /**
     * @param  array<string,mixed>  $values
     * @return array<string,mixed>
     */
    private function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (in_array($key, self::REDACTED, true)) {
                $values[$key] = '[redacted]';
            } elseif ($value instanceof \BackedEnum) {
                $values[$key] = $value->value;
            } elseif ($value instanceof \DateTimeInterface) {
                $values[$key] = $value->format('c');
            } elseif (is_string($value) && mb_strlen($value) > 2000) {
                $values[$key] = mb_substr($value, 0, 2000).'…[truncated]';
            }
        }

        return $values;
    }

    private function moduleFor(Model $model): string
    {
        return method_exists($model, 'permissionModule')
            ? $model::permissionModule()
            : str(class_basename($model))->snake()->toString();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<AuditLog>
     */
    public function trailFor(Model $record)
    {
        return AuditLog::forRecord($record)->with('user')->latest('created_at');
    }
}
