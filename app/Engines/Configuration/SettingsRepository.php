<?php

declare(strict_types=1);

namespace App\Engines\Configuration;

use App\Engines\Access\OrganizationContext;
use App\Engines\Audit\AuditLogger;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Engine 5 (sections 5, 72).
 *
 * Resolution order for any key:
 *   1. the organization's own row
 *   2. the platform default row (organization_id IS NULL)
 *   3. the hard default passed by the caller
 *
 * Every behavioural choice a company can make lives here, so that changing how the company works
 * is a database write rather than a deployment. Nothing in the application should read a
 * behavioural constant from config/*.php — those are for infrastructure only.
 */
class SettingsRepository
{
    private const CACHE_TTL = 3600;

    /** @var array<string,array<string,mixed>> in-request memo keyed by cache key */
    private array $memo = [];

    public function __construct(
        private readonly OrganizationContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function get(string $key, mixed $default = null, ?int $organizationId = null): mixed
    {
        $organizationId ??= $this->context->id();

        $tenant = $this->all($organizationId);

        if (array_key_exists($key, $tenant)) {
            return $tenant[$key];
        }

        $platform = $organizationId === null ? $tenant : $this->all(null);

        return array_key_exists($key, $platform) ? $platform[$key] : $default;
    }

    public function bool(string $key, bool $default = false, ?int $organizationId = null): bool
    {
        return (bool) $this->get($key, $default, $organizationId);
    }

    public function int(string $key, int $default = 0, ?int $organizationId = null): int
    {
        return (int) $this->get($key, $default, $organizationId);
    }

    public function string(string $key, string $default = '', ?int $organizationId = null): string
    {
        $value = $this->get($key, $default, $organizationId);

        return is_scalar($value) ? (string) $value : $default;
    }

    /** @return array<int|string,mixed> */
    public function array(string $key, array $default = [], ?int $organizationId = null): array
    {
        $value = $this->get($key, $default, $organizationId);

        return is_array($value) ? $value : $default;
    }

    /**
     * Write a setting for the current tenant. Platform defaults are never overwritten by a
     * tenant write — the tenant gets its own row.
     *
     * A null organization is refused rather than accepted, because the two ways of arriving at one
     * are indistinguishable here and mean opposite things. "Write the platform default" and "there
     * is no tenant bound on this execution path" both present as null, and the second is the
     * dangerous one: a queued job, a console command or a scheduled task that calls this without a
     * context would silently write a row every organization on the installation then inherits. That
     * is a configuration change to somebody else's company, made by a request that was only ever
     * about one. Platform defaults are written through setPlatformDefault(), where the intent is in
     * the method name and cannot be arrived at by omission.
     *
     * @throws \RuntimeException when no organization is given and none is bound
     */
    public function set(string $key, mixed $value, ?int $organizationId = null, ?string $group = null): void
    {
        $organizationId ??= $this->context->id();

        if ($organizationId === null) {
            throw new \RuntimeException(
                "Cannot write setting [{$key}]: no organization was given and none is bound to this "
                .'request. Pass one explicitly, or call setPlatformDefault() if a platform-wide '
                .'default is genuinely what was meant.'
            );
        }

        $this->write($key, $value, $organizationId, $group);
    }

    /**
     * Write the installation-wide fallback for a key — the row every tenant inherits until it
     * overrides it. Deployment-time work: migrations, install commands, the platform console.
     * Never reachable from a tenant's own settings screen.
     */
    public function setPlatformDefault(string $key, mixed $value, ?string $group = null): void
    {
        $this->write($key, $value, null, $group);
    }

    private function write(string $key, mixed $value, ?int $organizationId, ?string $group): void
    {
        $existing = Setting::query()
            ->where('key', $key)
            ->where(fn ($q) => $organizationId === null
                ? $q->whereNull('organization_id')
                : $q->where('organization_id', $organizationId))
            ->first();

        if ($existing && ! $existing->is_editable) {
            throw new \RuntimeException("Setting [{$key}] is locked and cannot be changed.");
        }

        $stored = $existing?->is_encrypted ? Crypt::encryptString(json_encode($value)) : $value;

        $old = $existing?->value;

        Setting::query()->updateOrCreate(
            ['organization_id' => $organizationId, 'key' => $key],
            [
                'value' => $existing?->is_encrypted ? ['__encrypted' => $stored] : $value,
                'group' => $group ?? $existing?->group ?? $this->groupFor($key),
                'type' => $existing?->type ?? $this->typeOf($value),
            ],
        );

        // forget(), not flush(): flush() resolves a null argument against the bound context, which
        // is right for a caller saying "mine" and wrong here, where null means the platform row.
        // Writing a platform default from inside a tenant request would otherwise clear that
        // tenant's cache and leave the stale platform row cached.
        $this->forget($organizationId);

        // Configuration changes alter how the whole company behaves; they are audited like any
        // other privileged action (section 71).
        $this->audit->log(
            action: 'setting_changed',
            subject: null,
            old: [$key => $existing?->is_encrypted ? '[redacted]' : $old],
            new: [$key => $existing?->is_encrypted ? '[redacted]' : $value],
            description: 'Setting '.$key.' changed',
        );
    }

    /**
     * Every resolved key for a tenant, platform defaults merged underneath.
     *
     * @return array<string,mixed>
     */
    public function resolved(?int $organizationId = null): array
    {
        $organizationId ??= $this->context->id();

        return array_merge($this->all(null), $this->all($organizationId));
    }

    /**
     * @return array<string,mixed>
     */
    private function all(?int $organizationId): array
    {
        $key = 'settings:'.($organizationId ?? 'platform');

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        return $this->memo[$key] = Cache::remember($key, self::CACHE_TTL, function () use ($organizationId) {
            $rows = DB::table('settings')
                ->when($organizationId === null,
                    fn ($q) => $q->whereNull('organization_id'),
                    fn ($q) => $q->where('organization_id', $organizationId))
                ->get(['key', 'value', 'is_encrypted']);

            $values = [];

            foreach ($rows as $row) {
                $decoded = json_decode((string) $row->value, true);

                if ($row->is_encrypted && is_array($decoded) && isset($decoded['__encrypted'])) {
                    try {
                        $decoded = json_decode(Crypt::decryptString($decoded['__encrypted']), true);
                    } catch (\Throwable) {
                        // A key rotation should degrade to "unset", never to a fatal page error.
                        $decoded = null;
                    }
                }

                $values[$row->key] = $decoded;
            }

            return $values;
        });
    }

    /** Drop the cached resolution for one tenant — the bound one, unless told otherwise. */
    public function flush(?int $organizationId = null): void
    {
        $this->forget($organizationId ?? $this->context->id());
    }

    /** Drop exactly the named bucket. Null means the platform row, never "whoever is bound". */
    private function forget(?int $organizationId): void
    {
        $key = 'settings:'.($organizationId ?? 'platform');

        Cache::forget($key);
        unset($this->memo[$key]);
    }

    private function typeOf(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'float',
            is_array($value) => 'array',
            default => 'string',
        };
    }

    private function groupFor(string $key): string
    {
        return str_contains($key, '.') ? explode('.', $key)[0] : 'general';
    }
}
