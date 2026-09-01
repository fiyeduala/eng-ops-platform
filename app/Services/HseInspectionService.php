<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\HseInspection;
use App\Models\HseInspectionItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The site walk.
 *
 * THE SCORE IS SUMMED FROM THE ITEMS, NOT TYPED. `score_achieved` and `score_possible` on the
 * inspection are rolled up from the rows that were actually scored, so a checklist with six of its
 * twenty items marked "not applicable" produces a percentage out of fourteen. That is the only
 * honest denominator: scoring the N/A rows as zero would make an inspection look worse the more of
 * it did not apply, and scoring them as full marks would make it look better.
 *
 * PERFORMING FREEZES THE RECORD. The service stamps `performed_at` and the policy refuses `update`
 * from that moment, so the results are what the inspector found rather than what somebody later
 * wished they had found. Actions arising from the failures are raised on the CORRECTIVE ACTION
 * register — through the same `create_record` verb an incident uses, configured by an administrator
 * rather than hard-wired here (§32, §90).
 */
class HseInspectionService extends RegisterService
{
    protected function entityType(): string
    {
        return 'hse_inspection';
    }

    protected function modelClass(): string
    {
        return HseInspection::class;
    }

    /** @return array<int,string> */
    protected function protectedAttributes(): array
    {
        return [
            'status_id', 'performed_at', 'performed_by',
            'score_achieved', 'score_possible',
            'closed_at', 'closed_by',
        ];
    }

    /**
     * Replace the checklist.
     *
     * Wholesale, and only before the walk — see RiskAssessmentService::setHazards() for the same
     * reasoning about tables edited as tables.
     *
     * @param  array<int,array<string,mixed>>  $rows
     */
    public function setItems(HseInspection $inspection, array $rows, User $actor): HseInspection
    {
        if ($inspection->wasPerformed()) {
            throw new \DomainException(
                $inspection->number.' has been carried out and its checklist can no longer be changed.'
            );
        }

        return DB::transaction(function () use ($inspection, $rows, $actor) {
            $inspection->items()->delete();

            foreach (array_values($rows) as $index => $row) {
                if (blank($row['description'] ?? null)) {
                    continue;
                }

                $item = new HseInspectionItem;
                $item->organization_id = $inspection->organization_id;
                $item->hse_inspection_id = $inspection->getKey();
                $item->sort_order = $index;

                $item->reference = $row['reference'] ?? null;
                $item->description = $row['description'];
                $item->category = $row['category'] ?? null;
                $item->score_possible = $row['score_possible'] ?? null;

                $item->save();
            }

            $this->activity->record(
                $inspection,
                'updated',
                $actor->name.' updated the checklist on '.$this->label($inspection).'.',
                ['items' => $inspection->items()->count()],
                $actor,
                $this->projectIdOf($inspection),
            );

            return $inspection->load('items');
        });
    }

    /**
     * Record what was found.
     *
     * `$results` is keyed by item id, and ids that do not belong to this inspection are ignored
     * rather than trusted — a posted form is a list of numbers a user can edit, and matching them
     * against the inspection's own rows is the difference between recording results and letting
     * somebody write into another inspection's checklist (§84).
     *
     * @param  array<int|string,array<string,mixed>>  $results
     */
    public function perform(HseInspection $inspection, array $results, User $actor, ?string $summary = null): HseInspection
    {
        if ($inspection->wasPerformed()) {
            throw new \DomainException($inspection->number.' has already been carried out.');
        }

        return DB::transaction(function () use ($inspection, $results, $actor, $summary) {
            $achieved = 0.0;
            $possible = 0.0;

            foreach ($inspection->items()->get() as $item) {
                $row = $results[$item->getKey()] ?? null;

                if (! is_array($row)) {
                    continue;
                }

                $item->result = $row['result'] ?? null;
                $item->severity = $row['severity'] ?? null;
                $item->comment = $row['comment'] ?? null;
                $item->action_required = (bool) ($row['action_required'] ?? false);
                $item->score_achieved = filled($row['score_achieved'] ?? null) ? $row['score_achieved'] : null;
                $item->save();

                // Only rows that were actually scored count towards either total. See the class
                // docblock — this is where the honest denominator is built.
                if ($item->isScored()) {
                    $achieved += (float) $item->score_achieved;
                    $possible += (float) $item->score_possible;
                }
            }

            $inspection->forceFill([
                'performed_at' => now(),
                'performed_by' => $actor->getKey(),
                'score_achieved' => $possible > 0 ? $achieved : null,
                'score_possible' => $possible > 0 ? $possible : null,
                'summary' => $summary ?? $inspection->summary,
            ]);

            $this->moveTo($inspection, 'performed');
            $inspection->save();

            $this->activity->record(
                $inspection,
                'performed',
                $actor->name.' carried out '.$this->label($inspection).'.',
                array_filter([
                    'compliance_percent' => $inspection->compliancePercent(),
                    'failed_items' => $inspection->failedItemCount(),
                ], fn ($v) => $v !== null),
                $actor,
                $this->projectIdOf($inspection),
            );

            return $inspection;
        });
    }

    public function close(HseInspection $inspection, User $actor): HseInspection
    {
        if (! $inspection->wasPerformed()) {
            throw new \DomainException($inspection->number.' has not been carried out yet.');
        }

        if ($inspection->closed_at !== null) {
            throw new \DomainException($inspection->number.' is already closed.');
        }

        return DB::transaction(function () use ($inspection, $actor) {
            $inspection->forceFill([
                'closed_at' => now(),
                'closed_by' => $actor->getKey(),
            ]);

            $this->moveTo($inspection, 'closed');
            $inspection->save();

            $this->activity->record(
                $inspection,
                'closed',
                $actor->name.' closed '.$this->label($inspection).'.',
                [],
                $actor,
                $this->projectIdOf($inspection),
            );

            return $inspection;
        });
    }
}
