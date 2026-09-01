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
 * One precaution on one permit, confirmed by a named person at a recorded time.
 *
 * ONE ROW PER PRECAUTION RATHER THAN A TICKED-BOX BLOB, because the question after an incident is
 * never "was the checklist completed". It is "who confirmed that the space had been purged, and
 * when" — and `confirmed_by` with `confirmed_at` is the only shape that answers it.
 *
 * `is_required` SEPARATES THE MANDATORY FROM THE ADVISORY. Permit::requiredPrecautionsConfirmed()
 * is what the service checks before it will let a permit be issued, and a type's template decides
 * which lines carry that weight — for a company whose confined-space permit demands a gas test and
 * a company whose does not, without either of them touching code.
 */
class PermitPrecaution extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_required' => 'boolean',
            'is_confirmed' => 'boolean',
            'confirmed_at' => 'datetime',
        ];
    }

    public function permit(): BelongsTo
    {
        return $this->belongsTo(Permit::class);
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
