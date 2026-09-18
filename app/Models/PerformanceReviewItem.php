<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One scored line of a review — the copy, taken from the cycle when the review was opened.
 *
 * TWO SCORES, TWO AUTHORS, TWO COLUMNS. The gap between what somebody thinks of their own work and
 * what their manager thinks of it is the entire conversation a review exists to have, and one column
 * with a "last edited by" flag destroys it the moment the manager saves. Who may write which is
 * enforced in PerformanceReviewService.
 *
 * IT INHERITS ITS PARENT'S CLASSIFICATION through `accessParentRelation`, so an item cannot be read
 * by anybody who could not read the review it belongs to. An item route that resolved on its own
 * would be a way to read a restricted review one line at a time (§94).
 */
class PerformanceReviewItem extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
            'self_score' => 'decimal:2',
            'manager_score' => 'decimal:2',
            'sequence' => 'integer',
        ];
    }

    public static function permissionModule(): string
    {
        return 'performance';
    }

    public function accessParentRelation(): ?string
    {
        return 'review';
    }

    /** @return array<int,string> */
    public function accessOwnerColumns(): array
    {
        return [];
    }

    /** @return array<int,string> */
    public function accessAssigneeColumns(): array
    {
        return [];
    }

    public function accessOrgUnitColumn(): ?string
    {
        return null;
    }

    public function accessProjectColumn(): ?string
    {
        return null;
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(PerformanceReview::class, 'performance_review_id');
    }

    public function isScored(): bool
    {
        return $this->manager_score !== null;
    }

    public function displayName(): string
    {
        return (string) $this->label;
    }
}
