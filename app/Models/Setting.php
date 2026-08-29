<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Configuration engine storage (section 72).
 *
 * Not tenant-scoped by the global scope, because a row with a null organization_id is the
 * PLATFORM DEFAULT that a tenant row of the same key overrides. Reads go through
 * SettingsRepository, which handles that fallback.
 */
class Setting extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'value' => 'array',
            'is_editable' => 'boolean',
            'is_encrypted' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
