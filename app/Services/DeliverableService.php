<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Deliverable;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The Master Deliverable Register (§26).
 *
 * TWO OPERATIONS MATTER HERE and both are about not losing the baseline.
 *
 * `reportProgress()` writes the percentage and the FORECAST date. It cannot touch
 * `planned_issue_on`, and that is enforced by the method rather than left to the form, because the
 * whole value of the register is that the promise stays where it was put. An engineer reporting
 * "now expecting the 20th" is telling the truth about a slip; the same engineer moving the plan to
 * the 20th is erasing it, and the difference between the two is the entire subject of every
 * project review meeting.
 *
 * `issue()` stamps the actual date, and from that moment the row is a record of what happened. It
 * is also the point where a deliverable stops being deletable — see DeliverablePolicy.
 */
class DeliverableService extends RegisterService
{
    protected function entityType(): string
    {
        return 'deliverable';
    }

    protected function modelClass(): string
    {
        return Deliverable::class;
    }

    /**
     * Progress and the actual issue date are outcomes, not form fields.
     *
     * `progress_percent`, `forecast_issue_on` and `actual_issue_on` are all stripped from ordinary
     * updates so that the only route to them is the two methods below, which is what makes the
     * baseline rule above enforceable rather than merely intended.
     *
     * @return array<int,string>
     */
    protected function protectedAttributes(): array
    {
        return ['status_id', 'progress_percent', 'forecast_issue_on', 'actual_issue_on', 'document_id'];
    }

    protected function creationNarrative(Model $record, User $actor): string
    {
        /** @var Deliverable $record */
        $due = $record->planned_issue_on?->format('j M Y');

        return $actor->name.' added '.$this->label($record).' to the register'
            .($due !== null ? ', due '.$due : '').'.';
    }

    /**
     * Report where a deliverable has got to.
     *
     * The forecast is optional and separate: a percentage says how much is done, a forecast says
     * when the rest will be. Reporting one without the other is normal and neither implies the
     * other, so the method takes them independently rather than deriving a date from a percentage
     * — a derivation that is always wrong and always believed.
     */
    public function reportProgress(
        Deliverable $deliverable,
        int $percent,
        User $actor,
        ?string $forecastDate = null,
        ?string $note = null,
    ): Deliverable {
        $percent = max(0, min(100, $percent));
        $previous = (int) $deliverable->progress_percent;

        return DB::transaction(function () use ($deliverable, $percent, $previous, $actor, $forecastDate, $note) {
            $deliverable->progress_percent = $percent;

            if ($forecastDate !== null) {
                $deliverable->forecast_issue_on = $forecastDate;
            }

            // 100% is not the same as issued. The document may still be sitting in review, and a
            // register that conflated the two would report work as delivered that has not left the
            // building. Only issue() writes `actual_issue_on`.
            $deliverable->save();

            $this->activity->record(
                $deliverable,
                'progress_reported',
                $actor->name.' reported '.$this->label($deliverable).' at '.$percent.'%'
                    .($previous !== $percent ? ' (was '.$previous.'%)' : '')
                    .($forecastDate !== null ? ', now forecast for '.$deliverable->forecast_issue_on?->format('j M Y') : '')
                    .'.'.($note !== null ? ' '.$note : ''),
                array_filter([
                    'percent' => $percent,
                    'previous' => $previous,
                    'forecast' => $forecastDate,
                ], fn ($v) => $v !== null),
                $actor,
                $this->projectIdOf($deliverable),
            );

            return $deliverable;
        });
    }

    /**
     * Record that the deliverable actually went out.
     *
     * Idempotent: issuing an already-issued deliverable returns it untouched rather than moving the
     * date. The second click on a slow form must not rewrite the first click's answer.
     */
    public function issue(Deliverable $deliverable, User $actor, ?string $issuedOn = null): Deliverable
    {
        if ($deliverable->isIssued()) {
            return $deliverable;
        }

        return DB::transaction(function () use ($deliverable, $actor, $issuedOn) {
            $deliverable->actual_issue_on = $issuedOn ?? now()->toDateString();
            $deliverable->progress_percent = 100;
            $this->moveTo($deliverable, 'issued');
            $deliverable->save();

            $slippage = $deliverable->slippageDays();

            $this->activity->record(
                $deliverable,
                'issued',
                $actor->name.' issued '.$this->label($deliverable).' on '
                    .$deliverable->actual_issue_on->format('j M Y').'.'
                    .($slippage !== null && $slippage > 0 ? ' '.$slippage.' day(s) after the planned date.' : ''),
                array_filter([
                    'issued_on' => $deliverable->actual_issue_on->toDateString(),
                    'slippage_days' => $slippage,
                ], fn ($v) => $v !== null),
                $actor,
                $this->projectIdOf($deliverable),
            );

            return $deliverable;
        });
    }

    /**
     * Point the deliverable at the document that satisfies it.
     *
     * The tenant check is explicit and not left to the global scope. The scope protects QUERIES;
     * this protects a caller that already holds a model, which is exactly what an id posted in a
     * form becomes after route binding (§84, §75).
     */
    public function attachDocument(Deliverable $deliverable, ?Document $document, User $actor): Deliverable
    {
        // Null means "unlink". Somebody attaches the wrong document eventually, and a register that
        // could only ever add a link would leave the correction to a database console. The removal
        // is recorded like everything else, so the timeline still shows that a link once existed.
        if ($document === null) {
            return $this->detachDocument($deliverable, $actor);
        }

        if ((int) $document->organization_id !== (int) $deliverable->organization_id) {
            throw new \DomainException('That document does not belong to this organization.');
        }

        $deliverable->document_id = $document->getKey();
        $deliverable->save();

        $this->activity->record(
            $deliverable,
            'document_linked',
            $actor->name.' linked '.$document->displayName().' to '.$this->label($deliverable).'.',
            ['document_id' => $document->getKey()],
            $actor,
            $this->projectIdOf($deliverable),
        );

        return $deliverable;
    }

    /** Break the link to the document. The deliverable survives; only the pointer goes. */
    private function detachDocument(Deliverable $deliverable, User $actor): Deliverable
    {
        if ($deliverable->document_id === null) {
            return $deliverable;
        }

        $previous = (int) $deliverable->document_id;

        $deliverable->document_id = null;
        $deliverable->save();

        $this->activity->record(
            $deliverable,
            'document_unlinked',
            $actor->name.' removed the document link from '.$this->label($deliverable).'.',
            ['previous_document_id' => $previous],
            $actor,
            $this->projectIdOf($deliverable),
        );

        return $deliverable;
    }
}
