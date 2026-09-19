<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * What one record answered for one custom field.
 *
 * NO Scopeable, NO POLICY, NO ACCESS HOOKS — and that is the design, not an omission. A value has
 * no visibility of its own: it is readable exactly when the record it hangs off is readable, and
 * the only way to reach one is through HasCustomFields on a record the access engine has already
 * granted. Giving it its own scope would invite a second, weaker answer to a question the record
 * has already answered correctly (§6).
 *
 * `organization_id` is still carried, for the tenant scope and so that a company's data can be
 * exported or erased in one pass without walking every register (§75).
 */
class CustomFieldValue extends Model
{
    use Auditable, BelongsToOrganization, HasFactory;

    protected $guarded = ['id'];

    public function field(): BelongsTo
    {
        return $this->belongsTo(CustomField::class, 'custom_field_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The stored text, turned back into what the field says it is.
     *
     * Returns null for an answer that was never given, which is different from an answer of "no"
     * or of zero — a boolean field nobody touched must not read as false on a report.
     */
    public function typed(string $type): mixed
    {
        $raw = $this->value;

        if ($raw === null || $raw === '') {
            return null;
        }

        return match ($type) {
            'number' => is_numeric($raw) ? $raw + 0 : null,
            'boolean' => (bool) $raw,
            'multiselect' => is_array($decoded = json_decode($raw, true)) ? $decoded : [],
            default => $raw,
        };
    }
}
