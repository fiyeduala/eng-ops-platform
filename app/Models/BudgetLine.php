<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetLine extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['allocated_amount' => 'decimal:2'];
    }

    public static function permissionModule(): string
    {
        return 'budget';
    }

    public function accessParentRelation(): ?string
    {
        return 'budget';
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }
}
