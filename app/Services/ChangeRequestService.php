<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\ChangeRequestDecided;
use App\Models\ChangeRequest;
use App\Models\User;
use App\Support\Enums\ChangeDisposition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Engineering change requests (§27).
 *
 * ONE DECISION, RECORDED ONCE. `decide()` refuses to overwrite an existing disposition, in the
 * service and not only in the policy, because the policy guards the HTTP route while this guards
 * every caller — the workflow engine's auto-actions among them. A change register whose decisions
 * can be quietly replaced cannot support a claim, which is the only reason a company keeps one.
 *
 * THE ESTIMATE IS FROZEN AT THE DECISION. `cost_impact` and `schedule_impact_days` are ordinary
 * editable fields right up to the moment a disposition is recorded, and untouchable afterwards
 * (the policy refuses `update` on a decided request). That is the honest arrangement: the numbers
 * are estimates, they are argued about, and then somebody approves a specific set of them. If they
 * turn out wrong, that is a new change request — not a rewrite of the one that was approved.
 *
 * NOTHING HERE MOVES MONEY. The variation order that actually changes a contract value arrives in
 * Phase 5; this register records the engineering decision and the estimate it was made on.
 */
class ChangeRequestService extends RegisterService
{
    protected function entityType(): string
    {
        return 'change_request';
    }

    protected function modelClass(): string
    {
        return ChangeRequest::class;
    }

    /** @return array<int,string> */
    protected function protectedAttributes(): array
    {
        return ['status_id', 'disposition', 'decided_by', 'decided_at', 'decision_notes'];
    }

    protected function creationNarrative(Model $record, User $actor): string
    {
        /** @var ChangeRequest $record */
        $origin = $record->originLabel();

        return $actor->name.' raised '.$this->label($record)
            .($origin !== null ? ' ('.$origin.')' : '').'.';
    }

    public function submit(ChangeRequest $request, User $actor): ChangeRequest
    {
        $this->assertUndecided($request, 'submitted');

        return DB::transaction(function () use ($request, $actor) {
            if ($request->raised_on === null) {
                $request->raised_on = now()->toDateString();
            }

            if ($request->raised_by === null) {
                $request->raised_by = $actor->getKey();
            }

            $this->moveTo($request, 'submitted');
            $request->save();

            $this->activity->record(
                $request,
                'submitted',
                $actor->name.' submitted '.$this->label($request).' for assessment.',
                array_filter([
                    'cost_impact' => $request->cost_impact,
                    'schedule_impact_days' => $request->schedule_impact_days,
                ], fn ($v) => $v !== null),
                $actor,
                $this->projectIdOf($request),
            );

            return $request;
        });
    }

    /**
     * Record the answer.
     *
     * A comment is REQUIRED for anything but an approval. "Rejected" with no reason is the entry
     * that turns into an argument six months later, and the person who could have explained it has
     * moved on. Approval needs no justification for the same reason a green light needs no note:
     * the request itself is the record of what was agreed.
     *
     * THE IMPACT FIGURES MAY BE SET HERE, AND ONLY HERE, ONCE. This is the last moment they can
     * move: the policy refuses `update` on a decided request, so a decision taken before anybody
     * priced the change would freeze two nulls forever. In practice the estimator prices it AS PART
     * OF deciding it, and passing the numbers through the same call keeps the estimate and the
     * approval a single atomic act rather than an edit that might not happen. Passing null leaves
     * whatever the request already carried — this cannot be used to blank an existing figure.
     */
    public function decide(
        ChangeRequest $request,
        ChangeDisposition $disposition,
        User $actor,
        ?string $notes = null,
        int|float|string|null $costImpact = null,
        ?int $scheduleImpactDays = null,
    ): ChangeRequest {
        $this->assertUndecided($request, 'decided');

        if ($disposition !== ChangeDisposition::Approved && blank($notes)) {
            throw new \DomainException('A '.$disposition->label().' decision must say why.');
        }

        return DB::transaction(function () use ($request, $disposition, $actor, $notes, $costImpact, $scheduleImpactDays) {
            if ($costImpact !== null) {
                $request->cost_impact = $costImpact;
            }

            if ($scheduleImpactDays !== null) {
                $request->schedule_impact_days = $scheduleImpactDays;
            }

            $request->forceFill([
                'disposition' => $disposition->value,
                'decided_by' => $actor->getKey(),
                'decided_at' => now(),
                'decision_notes' => $notes,
            ]);

            $this->moveTo($request, 'decided');
            $request->save();

            $this->activity->record(
                $request,
                'decided',
                $actor->name.' recorded '.$this->label($request).' as '.$disposition->label().'.',
                array_filter([
                    'disposition' => $disposition->value,
                    'cost_impact' => $request->cost_impact,
                    'schedule_impact_days' => $request->schedule_impact_days,
                ], fn ($v) => $v !== null),
                $actor,
                $this->projectIdOf($request),
            );

            ChangeRequestDecided::dispatch($request, (int) $actor->getKey());

            return $request;
        });
    }

    public function close(ChangeRequest $request, User $actor): ChangeRequest
    {
        if (! $request->isDecided()) {
            throw new \DomainException($request->number.' cannot be closed before it has been decided.');
        }

        $this->moveTo($request, 'closed');
        $request->save();

        $this->activity->record(
            $request,
            'closed',
            $actor->name.' closed '.$this->label($request).'.',
            [],
            $actor,
            $this->projectIdOf($request),
        );

        return $request;
    }

    // ================================================================== internals

    private function assertUndecided(ChangeRequest $request, string $verb): void
    {
        if ($request->isDecided()) {
            throw new \DomainException(
                $request->number.' was decided on '.$request->decided_at?->format('j M Y')
                .' and cannot be '.$verb.'. Raise a new change request instead.'
            );
        }
    }
}
