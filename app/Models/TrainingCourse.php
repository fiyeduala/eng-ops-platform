<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * What training exists — the catalogue, per tenant.
 *
 * `validity_months` NULLABLE, AND NULL MEANS "NEVER EXPIRES" rather than "we have not decided". A
 * site induction does not lapse; a confined-space ticket lapses in three years. Defaulting to a
 * number would stamp a false expiry on the first kind and defaulting to null-as-unknown would leave
 * the expiry report unable to say anything at all, so the meaning is fixed here and documented
 * where anybody changing it will look.
 */
class TrainingCourse extends Model
{
    use Auditable, BelongsToOrganization, HasFactory;

    protected $guarded = ['id'];

    /** `training_course.categories`. */
    public const DEFAULT_CATEGORIES = [
        'Induction', 'Statutory', 'Competency', 'Refresher', 'Behavioural', 'Environmental',
    ];

    protected function casts(): array
    {
        return [
            'validity_months' => 'integer',
            'is_mandatory' => 'boolean',
            'is_active' => 'boolean',
            'duration_hours' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function records(): HasMany
    {
        return $this->hasMany(TrainingRecord::class);
    }

    /**
     * When a certificate completed on this date would lapse.
     *
     * Called ONCE, at the moment a record is created, and the result is stored on the record. Never
     * re-derived: a certification body extending a ticket from two to three years must not silently
     * move the expiry on every certificate ever issued, including ones already lapsed. The date on
     * somebody's card is a fact about that card.
     */
    public function expiryFrom(?Carbon $completedOn): ?Carbon
    {
        if ($completedOn === null || $this->validity_months === null || $this->validity_months < 1) {
            return null;
        }

        return $completedOn->copy()->addMonths($this->validity_months);
    }

    /** @param  Builder<static>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function displayName(): string
    {
        return trim($this->code.' — '.$this->name, ' —');
    }
}
