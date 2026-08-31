<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\RegisterRecord;
use App\Support\Enums\Confidentiality;
use App\Support\Enums\RfiDirection;
use App\Support\Enums\RfiKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A dated question with a deadline and an answer: RFI, technical query or site query.
 *
 * ONE REGISTER FOR ALL THREE. They differ only in who is asking whom — a contractor asks the client,
 * construction asks engineering, site asks the design office — and share every other property: a
 * required-by date, an answer, an author, and an argument about whether the answer costs money.
 * Three separate registers would produce the failure this module exists to prevent: nobody able to
 * answer "what is outstanding", because outstanding things are in three places.
 *
 * DIRECTION IS THE FIELD THAT MATTERS MOST and the one a spreadsheet always loses. `outgoing` means
 * somebody else owes us; `incoming` means the clock is running against us. Mixed together, a company
 * produces the report where it chases the client for forty answers while sitting on thirty of its
 * own.
 *
 * THE PARTY MORPH IS EMPTY UNTIL PHASE 5. Clients and vendors do not exist as tables yet, so
 * `party_name` carries the text somebody types today and the morph is already here to point at a
 * real record later — no migration of live correspondence when it lands.
 *
 * IMPACT IS RECORDED AS FLAGS, NOT MONEY. At the moment an RFI is answered nobody knows the number,
 * and a nullable amount invites a guess into a column that later gets summed. The number belongs on
 * the change request the answer provokes.
 */
class Rfi extends Model
{
    use RegisterRecord;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => RfiKind::class,
            'direction' => RfiDirection::class,
            'confidentiality' => Confidentiality::class,
            'raised_on' => 'date',
            'required_by' => 'date',
            'answered_at' => 'datetime',
            'cost_impact' => 'boolean',
            'schedule_impact' => 'boolean',
        ];
    }

    // ------------------------------------------------------------------ relationships

    /** Who it is addressed to. Null-and-named until Phase 5 gives clients and vendors tables. */
    public function party(): MorphTo
    {
        return $this->morphTo();
    }

    public function raiser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    public function answerer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by');
    }

    // ------------------------------------------------------------------ access + workflow

    /**
     * Being named on the RFI reaches it.
     *
     * `raised_by` is here rather than in the owner columns because it is frequently not the person
     * who typed it: a document controller logs the query an engineer wrote. The controller owns
     * the record; the engineer is assigned it.
     *
     * @return array<int,string>
     */
    public function accessAssigneeColumns(): array
    {
        return ['raised_by', 'answered_by'];
    }

    /** @return array<int,string> */
    public function registerConditionFields(): array
    {
        return ['kind', 'direction', 'cost_impact', 'schedule_impact'];
    }

    public function registerTitleColumn(): string
    {
        return 'subject';
    }

    /** Derived from the class name this would read "Rfis". It is a register of questions. */
    public static function workflowEntityLabel(): string
    {
        return 'RFIs and technical queries';
    }

    /**
     * Subject and number only — never `question` or `answer`.
     *
     * The body of an RFI is the most sensitive free text in this register: it names what a design
     * got wrong. Matching on it turns the search box into an oracle that reports, through the
     * result count alone, whether a phrase appears in records the reader may list but not open
     * (§61) — the same reasoning as Memo::scopeSearch.
     *
     * @return array<int,string>
     */
    public function registerSearchColumns(): array
    {
        return ['number', 'subject'];
    }

    // ------------------------------------------------------------------ helpers

    public function isAnswered(): bool
    {
        return $this->answered_at !== null;
    }

    /** Whose problem the deadline is. Drives the two halves of the overdue report. */
    public function answerIsOwedByUs(): bool
    {
        return $this->direction instanceof RfiDirection && $this->direction->answerIsOwedByUs();
    }

    /**
     * Overdue in the sense this register means: unanswered past its date.
     *
     * Overrides the shared version because "closed" is not the test — an RFI can sit in a terminal
     * status having been withdrawn, and an answered RFI is not late however long its status takes
     * to catch up.
     */
    public function isOverdue(): bool
    {
        return ! $this->isAnswered()
            && $this->required_by !== null
            && $this->required_by->isBefore(now()->startOfDay());
    }

    /** Days until the answer is due; negative once it is late. Null when no date was set. */
    public function daysRemaining(): ?int
    {
        if ($this->required_by === null || $this->isAnswered()) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->required_by, false);
    }

    /** @param  Builder<static>  $query */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('answered_at');
    }

    /** @param  Builder<static>  $query */
    public function scopeOfKind(Builder $query, RfiKind|string|null $kind): Builder
    {
        if (blank($kind)) {
            return $query;
        }

        return $query->where('kind', $kind instanceof RfiKind ? $kind->value : $kind);
    }
}
