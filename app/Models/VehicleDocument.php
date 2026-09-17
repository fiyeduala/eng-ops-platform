<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A paper that makes a vehicle legal, and the date it stops doing so.
 *
 * EXPIRY IS A DATE QUERY, NOT A STATUS. This is the arrangement `contracts`, `vendors`,
 * `training_records` and `risk_assessments` already use, and the reason none of them needs a
 * nightly job to mark anything expired: a register tab asks `expiringWithin($days)`, the warning
 * window is a per-organization setting, and a certificate that lapsed at midnight is expired at
 * 00:00:01 whether or not the cron ran. A status column would mean a company whose cron is
 * misconfigured drives on an expired insurance certificate the system calls valid.
 *
 * `document_type` IS A CONFIGURABLE WORD (§47, §73). Insurance, roadworthiness, a hackney permit, a
 * state road tax, a bridge pass, a hazardous-goods licence — the list differs by country and by
 * what the vehicle carries. Hard-coding it would bake one jurisdiction's paperwork into the schema
 * and force a migration on the first company outside it.
 *
 * THE FILE IS PRIVATE (§67). An insurance certificate carries a policy number, an address and a
 * signature. `file_path` is a key on the private disk, served only through a controller that
 * authorises first, and the upload is validated on MIME, extension and size before it is stored.
 *
 * NOTHING IS COMPUTED ABOUT RENEWAL. A renewed document is a NEW row: the old one keeps its dates
 * and its scan, because "were we insured on the day of the accident" is a question asked about last
 * year and answered by a row that still exists.
 */
class VehicleDocument extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable, SoftDeletes;

    /**
     * `vehicle_document.types`.
     *
     * A Nigerian fleet's list, offered as a starting point and owned by the organization from the
     * moment it first edits it. Nothing in the application knows what any of these words mean.
     */
    public const DEFAULT_TYPES = [
        'Insurance', 'Roadworthiness certificate', 'Vehicle licence', 'Hackney permit',
        'Proof of ownership', 'Haulage permit', 'Inspection certificate',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'confidentiality' => Confidentiality::class,
            'issued_on' => 'date',
            'expires_on' => 'date',
            'superseded_on' => 'date',
            'cost' => 'decimal:2',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ------------------------------------------------------------------ access hooks

    public function accessParentRelation(): ?string
    {
        return 'vehicle';
    }

    /** @return array<int,string> */
    public function accessOwnerColumns(): array
    {
        return ['created_by'];
    }

    /**
     * Nobody is "assigned" a certificate.
     *
     * Stated rather than left to Scopeable's default, which names `assignee_id` — a column this
     * table does not have. Left unhandled it would compile SQL naming a missing column, which is a
     * 500 on the index page for exactly the users whose scope is `assigned`.
     *
     * @return array<int,string>
     */
    public function accessAssigneeColumns(): array
    {
        return [];
    }

    /** Reach follows the vehicle, and through it the asset. See VehicleDocumentPolicy. */
    public function accessOrgUnitColumn(): ?string
    {
        return null;
    }

    public function accessProjectColumn(): ?string
    {
        return null;
    }

    // ------------------------------------------------------------------ state

    /** Early filing must not suppress a lapse before the replacement takes effect. */
    public function isCurrent(): bool
    {
        return $this->superseded_on === null || $this->superseded_on->isAfter(now()->startOfDay());
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull($this->qualifyColumn('superseded_on'))
            ->orWhereDate($this->qualifyColumn('superseded_on'), '>', now()->toDateString()));
    }

    /**
     * Whether it has lapsed.
     *
     * A document with no expiry date NEVER expires rather than always expiring — a proof of
     * ownership has no end date, and reporting every one of them as lapsed on the day it is filed
     * is how a fleet officer learns to ignore the tab.
     */
    public function hasExpired(): bool
    {
        return $this->expires_on !== null && $this->expires_on->isBefore(now()->startOfDay());
    }

    /** Whether it lapses inside the window — and not if it already has. */
    public function expiresWithin(int $days): bool
    {
        return $this->isCurrent() && $this->expires_on !== null
            && ! $this->hasExpired()
            && ! $this->expires_on->isAfter(now()->startOfDay()->addDays($days));
    }

    /** How many days are left, negative once it has lapsed, null when it never expires. */
    public function daysRemaining(): ?int
    {
        return $this->expires_on === null
            ? null
            : (int) now()->startOfDay()->diffInDays($this->expires_on, false);
    }

    /**
     * Running out, but not yet gone.
     *
     * @param  Builder<static>  $query
     */
    public function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        return $query->current()->whereNotNull($this->qualifyColumn('expires_on'))
            ->whereDate($this->qualifyColumn('expires_on'), '>=', now()->startOfDay())
            ->whereDate($this->qualifyColumn('expires_on'), '<=', now()->startOfDay()->addDays($days));
    }

    /** @param  Builder<static>  $query */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->current()->whereNotNull($this->qualifyColumn('expires_on'))
            ->whereDate($this->qualifyColumn('expires_on'), '<', now()->startOfDay());
    }

    /** @param  Builder<static>  $query */
    public function scopeForVehicle(Builder $query, int|string|null $vehicleId): Builder
    {
        return blank($vehicleId) ? $query : $query->where($this->qualifyColumn('vehicle_id'), $vehicleId);
    }

    /** @param  Builder<static>  $query */
    public function scopeOfType(Builder $query, ?string $type): Builder
    {
        return blank($type) ? $query : $query->where($this->qualifyColumn('document_type'), $type);
    }

    // ------------------------------------------------------------------ presentation

    /**
     * The stored slug turned back into something a person reads.
     *
     * Deliberately NOT resolved through OptionList here. A model that reaches into the container to
     * render one word costs a settings lookup per row on a forty-row register, and the slug was
     * built from the label in the first place — so `Str::headline()` reproduces it exactly for
     * every list entry nobody has since renamed. Screens that must show the company's own wording
     * are given the map by their controller, which is how every other configurable list in the
     * platform is rendered.
     */
    public function typeLabel(): string
    {
        return Str::headline((string) $this->document_type);
    }

    public function displayName(): string
    {
        return trim($this->typeLabel().' '.($this->document_number ?? ''));
    }

    public function hasFile(): bool
    {
        return filled($this->file_path);
    }

    /**
     * Type and number only (§61).
     *
     * @param  Builder<static>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.trim($term).'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where($this->qualifyColumn('document_type'), 'like', $like)
                ->orWhere($this->qualifyColumn('document_number'), 'like', $like)
                ->orWhere($this->qualifyColumn('issuer'), 'like', $like);
        });
    }
}
