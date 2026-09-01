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
 * One hazard on one assessment, scored before and after the controls.
 *
 * THE SCORES ARE STORED, NOT DERIVED. `risk_score` is likelihood × severity and could be computed
 * on read, but the register sorts and filters on it on every page and the matrix dimensions are a
 * per-tenant setting — 5×5 in one company, 4×4 or 3×3 in another. Storing the product keeps the
 * index usable and keeps the matrix configurable, and the service is the only thing that writes it
 * so the two halves cannot drift.
 *
 * THE RESIDUAL PAIR IS THE POINT OF THE EXERCISE. An assessment that records only raw risk says
 * what is dangerous. One that records what is left after the controls says whether the controls are
 * enough, which is the question the assessment was opened to answer.
 *
 * Reach is inherited from the parent assessment, so a hazard row can never be visible to somebody
 * the assessment is not.
 */
class RiskAssessmentHazard extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'likelihood' => 'integer',
            'severity' => 'integer',
            'risk_score' => 'integer',
            'residual_likelihood' => 'integer',
            'residual_severity' => 'integer',
            'residual_score' => 'integer',
            'target_date' => 'date',
        ];
    }

    public function riskAssessment(): BelongsTo
    {
        return $this->belongsTo(RiskAssessment::class);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    /**
     * The band this score falls into, for colouring the matrix.
     *
     * The thresholds are proportions of the configured matrix maximum rather than fixed numbers,
     * so a 3×3 matrix and a 5×5 one both band sensibly without the tenant having to say so. A
     * company that wants its own thresholds sets them on the score itself by choosing its scale.
     */
    public function band(?int $score = null, int $matrixMax = 25): string
    {
        $score ??= $this->residual_score ?? $this->risk_score;

        if ($score === null || $matrixMax < 1) {
            return 'unknown';
        }

        $share = $score / $matrixMax;

        return match (true) {
            $share >= 0.6 => 'high',
            $share >= 0.3 => 'medium',
            default => 'low',
        };
    }

    /** Whether the controls actually moved the number. */
    public function wasReducedByControls(): bool
    {
        return $this->risk_score !== null
            && $this->residual_score !== null
            && $this->residual_score < $this->risk_score;
    }
}
