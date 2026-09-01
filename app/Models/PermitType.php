<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The permit catalogue — configuration, per tenant.
 *
 * A refinery's permit set is not a fabrication shop's and neither is a hospital estates
 * department's. Hot work, confined space, excavation, work at height, energised electrical,
 * radiography, breaking containment: which of these exist, what precautions they carry by default,
 * how long they run and whether they demand a gas test are all decisions a safety manager makes on
 * a settings screen, with no developer involved (§73).
 *
 * `precaution_template` IS COPIED ONTO EACH PERMIT, NOT REFERENCED. Editing the template must never
 * rewrite the precautions on a permit somebody already signed — the whole evidentiary value of the
 * record is that it says what was confirmed at the time. This is the same reason a transmittal
 * pins a document version rather than a document.
 */
class PermitType extends Model
{
    use Auditable, BelongsToOrganization, HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'precaution_template' => 'array',
            'requires_gas_test' => 'boolean',
            'requires_isolation' => 'boolean',
            'is_active' => 'boolean',
            'default_duration_hours' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function permits(): HasMany
    {
        return $this->hasMany(Permit::class);
    }

    /**
     * The precautions a new permit of this type starts with.
     *
     * Tolerates both shapes an administrator's JSON can arrive in — a list of strings, or a list of
     * objects with a `description` and an `is_required` flag — because the settings screen writes
     * the second and an import or a hand-edited seed writes the first. Anything unrecognisable is
     * dropped rather than rendered, so a malformed template produces a short list, never a broken
     * permit.
     *
     * @return array<int,array{description:string,is_required:bool}>
     */
    public function defaultPrecautions(): array
    {
        $out = [];

        foreach ((array) ($this->precaution_template ?? []) as $row) {
            if (is_string($row) && trim($row) !== '') {
                $out[] = ['description' => trim($row), 'is_required' => true];

                continue;
            }

            if (is_array($row) && trim((string) ($row['description'] ?? '')) !== '') {
                $out[] = [
                    'description' => trim((string) $row['description']),
                    'is_required' => (bool) ($row['is_required'] ?? true),
                ];
            }
        }

        return $out;
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
