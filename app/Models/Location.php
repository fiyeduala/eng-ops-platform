<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Location extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'geofence_radius' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    // ------------------------------------------------------------------ access hooks

    /**
     * A location is company reference data, not org-tree or project data: a site exists
     * independently of who works there. Only organization-wide reach lists locations, and a
     * narrower grant returns nothing rather than silently returning everything.
     */
    public function accessOrgUnitColumn(): ?string
    {
        return null;
    }

    public function accessProjectColumn(): ?string
    {
        return null;
    }

    public function accessOwnerColumns(): array
    {
        return [];
    }

    public function accessAssigneeColumns(): array
    {
        return [];
    }

    /**
     * Reading the site list is organization-level: a Department Head granted `*.view` at
     * department scope must still be able to see the sites their people clock in at. Creating,
     * editing and deleting sites are absent here on purpose and still require organization reach.
     */
    public function accessOrganizationWideAbilities(): array
    {
        return ['location.view', 'location.export'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function hasGeofence(): bool
    {
        return $this->latitude !== null && $this->longitude !== null && $this->geofence_radius !== null;
    }

    /**
     * Great-circle distance in metres, for validating mobile clock-in (section 48).
     */
    public function distanceTo(float $latitude, float $longitude): ?float
    {
        if ($this->latitude === null || $this->longitude === null) {
            return null;
        }

        $earthRadius = 6_371_000;
        $dLat = deg2rad($latitude - $this->latitude);
        $dLon = deg2rad($longitude - $this->longitude);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($this->latitude)) * cos(deg2rad($latitude)) * sin($dLon / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public function isWithinGeofence(float $latitude, float $longitude): bool
    {
        if (! $this->hasGeofence()) {
            return true;
        }

        return ($this->distanceTo($latitude, $longitude) ?? PHP_FLOAT_MAX) <= $this->geofence_radius;
    }
}
