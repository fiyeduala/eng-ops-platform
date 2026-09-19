<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One cell: what a submission said in answer to one question, on one row.
 *
 * NO Scopeable, NO POLICY, NO ACCESS HOOKS — the same deliberate omission CustomFieldValue makes,
 * and for the same reason. An answer has no visibility of its own: it is readable exactly when the
 * submission it belongs to is readable, and the only way to reach one is through that submission,
 * which the access engine has already ruled on. A second scope here would be a second answer to a
 * question already answered correctly, and the two would eventually disagree (§6).
 *
 * `row_index` IS WHAT MAKES A REPEATING TABLE REAL. Zero for an ordinary question; for a column of
 * a table, which row of the table this cell is on. Twelve scaffold bays are twelve rows the
 * database can count, filter and export — not a JSON blob a report has to parse and cannot index.
 */
class FormAnswer extends Model
{
    use Auditable, BelongsToOrganization, HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'row_index' => 'integer',
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(FormSubmission::class, 'form_submission_id');
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(FormField::class, 'form_field_id');
    }

    /**
     * The stored text, turned back into what the question says it is.
     *
     * Null for a question never answered, which is not the same as an answer of "no" or of zero — a
     * yes/no question nobody touched must not read as No on a report, because "nobody checked the
     * scaffold" and "somebody checked and it failed" are different facts.
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
