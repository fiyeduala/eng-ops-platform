<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use App\Engines\Audit\Concerns\HasActivity;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A day the company does not work, as the company says (§47).
 *
 * The application computes no holiday. A recurring row repeats on the same day and month each year;
 * everything that moves is typed in for the year it falls in, because working out when a moveable
 * feast lands is exactly the country-specific rule the brief forbids hard-coding.
 */
class PublicHoliday extends Model
{
    use Auditable, BelongsToOrganization, HasActivity, HasFactory, Scopeable, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'holiday_date' => 'date',
            'is_recurring' => 'boolean',
        ];
    }

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
        return ['created_by'];
    }

    public function accessAssigneeColumns(): array
    {
        return [];
    }

    public function accessOrganizationWideAbilities(): array
    {
        return ['leave.view'];
    }

    /** Whether this holiday falls on the given day. */
    public function fallsOn(CarbonInterface $day): bool
    {
        return $this->is_recurring
            ? $this->holiday_date->format('m-d') === $day->format('m-d')
            : $this->holiday_date->isSameDay($day);
    }

    public function displayName(): string
    {
        return $this->name.' — '.$this->holiday_date->format($this->is_recurring ? 'j M' : 'j M Y');
    }
}
