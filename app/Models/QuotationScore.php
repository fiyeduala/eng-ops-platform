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
 * One person's mark for one quotation against one criterion.
 *
 * ROWS, NOT COLUMNS, so a company can score on three criteria or seven without a migration. The
 * criteria are words in `procurement.evaluation_criteria`, which is the same reasoning as vendor
 * categories and NCR severities: a procurement policy is not a schema.
 *
 * BOTH THE KEY AND THE LABEL ARE STORED. The key is what the unique constraint and the arithmetic
 * use; the label is the words the evaluator was actually shown. A company that renames "Technical
 * compliance" to "Fitness for purpose" next year must not silently retitle a decision taken this
 * year, and a criterion deleted from the settings entirely must still print on the evaluation it
 * belonged to.
 *
 * `weight` IS COPIED, NOT LOOKED UP, for exactly the same reason: re-weighting the criteria in
 * Settings would otherwise restate every historical comparison.
 *
 * `max_score` IS ON THE ROW rather than being a constant of ten, because the first company to score
 * out of five will otherwise have every mark silently halved.
 *
 * ONE MARK PER PERSON PER CRITERION, enforced by a unique index. Scoring is an opinion held by a
 * named person, and a second row from the same evaluator is a correction of the first, not another
 * vote — the service updates in place.
 */
class QuotationScore extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
            'score' => 'decimal:2',
            'max_score' => 'decimal:2',
        ];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluated_by');
    }

    /** This mark restated out of ten, so criteria scored on different scales can be averaged. */
    public function normalised(): ?float
    {
        $max = (float) $this->max_score;

        return $max > 0.0 ? round(((float) $this->score / $max) * 10, 2) : null;
    }
}
