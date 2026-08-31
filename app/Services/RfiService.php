<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\RfiAnswered;
use App\Models\Rfi;
use App\Models\User;
use App\Support\Enums\RfiKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * RFIs, technical queries and site queries (§27).
 *
 * THE ANSWER IS WRITTEN ONCE. `answer()` refuses to overwrite an existing answer, and the refusal
 * is here rather than only in the policy because the policy protects the HTTP route while this
 * protects every caller — the workflow engine, an import, a future API. An RFI whose answer can be
 * replaced silently is a register that cannot be produced in a dispute, which is the only reason
 * anybody keeps one.
 *
 * Corrections go through `amendAnswer()`, which keeps the previous text in the timeline and needs a
 * heavier permission (see RfiPolicy). The distinction is not pedantry: "we told them X" and "we
 * told them X, then corrected it to Y on the 8th" are different facts, and only the second one is
 * true.
 *
 * NUMBERING IS PER KIND. An RFI and a technical query raised on the same day should not share a
 * series — engineers quote "TQ-2026-0031" and mean a specific document. The numbering engine
 * auto-provisions a scheme for an entity type it has not seen, so `rfi_tq` and `rfi_sq` work
 * without configuration and can be given their own patterns by an administrator afterwards.
 */
class RfiService extends RegisterService
{
    protected function entityType(): string
    {
        return 'rfi';
    }

    protected function modelClass(): string
    {
        return Rfi::class;
    }

    /** @return array<int,string> */
    protected function protectedAttributes(): array
    {
        return ['status_id', 'answer', 'answered_by', 'answered_at'];
    }

    protected function creationNarrative(Model $record, User $actor): string
    {
        /** @var Rfi $record */
        $by = $record->required_by?->format('j M Y');

        return $actor->name.' raised '.$this->label($record)
            .($record->party_name ? ' to '.$record->party_name : '')
            .($by !== null ? ', required by '.$by : '').'.';
    }

    /**
     * Separate series per kind, so a TQ number is a TQ number.
     *
     * Falls back to the plain `rfi` series when no kind was given, which keeps a partially
     * completed form from failing at the numbering step rather than inventing a series for null.
     *
     * @param  array<string,mixed>  $attributes
     */
    protected function numberingEntityType(array $attributes): string
    {
        return match ($this->resolveKind($attributes['kind'] ?? null)) {
            RfiKind::TechnicalQuery => 'technical_query',
            RfiKind::SiteQuery => 'site_query',
            default => 'rfi',
        };
    }

    /**
     * Send the question out.
     *
     * `raised_on` is stamped here rather than at creation because a draft query may sit for days
     * before anybody sends it, and the date on the register has to be the date it went — that is
     * the date every "how long have they had this" calculation runs from.
     */
    public function send(Rfi $rfi, User $actor): Rfi
    {
        if ($rfi->isAnswered()) {
            throw new \DomainException($rfi->number.' has already been answered.');
        }

        return DB::transaction(function () use ($rfi, $actor) {
            if ($rfi->raised_on === null) {
                $rfi->raised_on = now()->toDateString();
            }

            if ($rfi->raised_by === null) {
                $rfi->raised_by = $actor->getKey();
            }

            $this->moveTo($rfi, 'open');
            $rfi->save();

            $this->activity->record(
                $rfi,
                'sent',
                $actor->name.' sent '.$this->label($rfi)
                    .($rfi->party_name ? ' to '.$rfi->party_name : '').'.',
                array_filter(['required_by' => $rfi->required_by?->toDateString()]),
                $actor,
                $this->projectIdOf($rfi),
            );

            return $rfi;
        });
    }

    /**
     * Record the answer. Once.
     *
     * The impact flags come in with the answer because that is when they are known — the person
     * answering is the one who knows whether their answer costs money. They are flags, not amounts:
     * the number belongs on the change request the answer provokes, and inviting a guess here
     * produces a column that gets summed and believed.
     */
    public function answer(
        Rfi $rfi,
        string $answer,
        User $actor,
        bool $costImpact = false,
        bool $scheduleImpact = false,
        ?string $impactNotes = null,
    ): Rfi {
        if ($rfi->isAnswered()) {
            throw new \DomainException($rfi->number.' was answered on '
                .$rfi->answered_at?->format('j M Y').' and cannot be answered again.');
        }

        return DB::transaction(function () use ($rfi, $answer, $actor, $costImpact, $scheduleImpact, $impactNotes) {
            $rfi->forceFill([
                'answer' => $answer,
                'answered_by' => $actor->getKey(),
                'answered_at' => now(),
                'cost_impact' => $costImpact,
                'schedule_impact' => $scheduleImpact,
                'impact_notes' => $impactNotes,
            ]);

            $this->moveTo($rfi, 'answered');
            $rfi->save();

            $this->activity->record(
                $rfi,
                'answered',
                // The narrative names the record and the fact, never the answer text. A timeline
                // entry is read by everyone who can see the record, and an RFI answer is often the
                // most commercially sensitive sentence on it (§68).
                $actor->name.' answered '.$this->label($rfi).'.'
                    .($costImpact ? ' Flagged as having a cost impact.' : '')
                    .($scheduleImpact ? ' Flagged as having a schedule impact.' : ''),
                ['cost_impact' => $costImpact, 'schedule_impact' => $scheduleImpact],
                $actor,
                $this->projectIdOf($rfi),
            );

            // Fired here and not in `amendAnswer`: a correction is a change to a conversation the
            // interested parties are already reading, and a second "your query was answered" for
            // the same query is how people learn to ignore the first.
            RfiAnswered::dispatch($rfi, (int) $actor->getKey());

            return $rfi;
        });
    }

    /**
     * Correct an answer already given.
     *
     * The previous text goes into the timeline, not into a column, because a second answer column
     * would only push the problem one correction further along. The audit engine holds the full
     * before/after under its own access rules; this entry records that a correction happened and
     * who made it.
     */
    public function amendAnswer(Rfi $rfi, string $answer, User $actor, string $reason): Rfi
    {
        if (! $rfi->isAnswered()) {
            throw new \DomainException($rfi->number.' has not been answered, so there is nothing to amend.');
        }

        return DB::transaction(function () use ($rfi, $answer, $actor, $reason) {
            $rfi->forceFill([
                'answer' => $answer,
                'answered_by' => $actor->getKey(),
                'answered_at' => now(),
            ])->save();

            $this->activity->record(
                $rfi,
                'answer_amended',
                $actor->name.' amended the answer to '.$this->label($rfi).'. '.$reason,
                ['reason' => $reason],
                $actor,
                $this->projectIdOf($rfi),
            );

            return $rfi;
        });
    }

    /** The asker accepts the answer. Distinct from answering; see RfiPolicy. */
    public function close(Rfi $rfi, User $actor, ?string $note = null): Rfi
    {
        if (! $rfi->isAnswered()) {
            throw new \DomainException($rfi->number.' cannot be closed before it has been answered.');
        }

        $this->moveTo($rfi, 'closed');
        $rfi->save();

        $this->activity->record(
            $rfi,
            'closed',
            $actor->name.' closed '.$this->label($rfi).'.'.($note !== null ? ' '.$note : ''),
            [],
            $actor,
            $this->projectIdOf($rfi),
        );

        return $rfi;
    }

    // ================================================================== internals

    private function resolveKind(mixed $value): ?RfiKind
    {
        if ($value instanceof RfiKind) {
            return $value;
        }

        return is_string($value) ? RfiKind::tryFrom($value) : null;
    }
}
