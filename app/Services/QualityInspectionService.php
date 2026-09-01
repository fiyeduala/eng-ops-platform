<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\QualityInspectionCompleted;
use App\Events\QualityInspectionRequested;
use App\Models\QualityInspection;
use App\Models\QualityInspectionItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * "The rebar is ready — come and look before we pour."
 *
 * ONE RECORD FROM REQUEST TO VERDICT, which is what makes the register's single useful metric
 * possible: the days between asking and being inspected. Splitting request from result would double
 * the register and lose the number that explains the programme.
 *
 * A REJECTED RESULT DOES NOT AUTOMATICALLY RAISE AN NCR, and that omission is deliberate. Most
 * rejections are "the rebar spacing is out, fix it and call me back" — resolved in an hour, and
 * turning every one of them into a formal non-conformance with a disposition, a cost and a closure
 * would bury the register in paperwork and make the NCR log meaningless. Whether a rejection becomes
 * an NCR is a judgement, and where a company wants it automatic it configures a workflow action on
 * the rejected status rather than having it wired in here (§32, §73).
 *
 * NOBODY INSPECTS THEIR OWN REQUEST, enforced here as well as in the policy — a hold point the
 * requester can release is not a hold point.
 */
class QualityInspectionService extends RegisterService
{
    protected function entityType(): string
    {
        return 'quality_inspection';
    }

    protected function modelClass(): string
    {
        return QualityInspection::class;
    }

    /** @return array<int,string> */
    protected function protectedAttributes(): array
    {
        return ['status_id', 'inspected_at', 'inspected_by', 'result'];
    }

    /**
     * How a recorded RESULT lands on a STATUS.
     *
     * Results are a company's own vocabulary — OptionList lets one firm write "Accepted" where the
     * next writes "Passed" — so the two are mapped rather than assumed equal. An unrecognised result
     * is recorded and the status is left exactly where the configured workflow put it (moveTo is a
     * no-op for an unknown key), which is the right failure: a company that invents a fifth result
     * gets its result stored and routes it with a workflow rule, not a code change (§32).
     *
     * @var array<string,string>
     */
    private const RESULT_STATUS = [
        'passed' => 'passed',
        'accepted' => 'passed',
        'passed_with_comment' => 'passed_with_comment',
        'passed_with_comments' => 'passed_with_comment',
        'accepted_with_comments' => 'passed_with_comment',
        'rejected' => 'rejected',
        'failed' => 'rejected',
    ];

    /** @param array<string,mixed> $attributes */
    protected function afterCreate(Model $record, array $attributes, User $actor): void
    {
        /** @var QualityInspection $record */
        QualityInspectionRequested::dispatch($record, (int) $actor->getKey());
    }

    /**
     * Replace the checkpoint list. Only before the inspection — see HseInspectionService::setItems().
     *
     * @param  array<int,array<string,mixed>>  $rows
     */
    public function setItems(QualityInspection $inspection, array $rows, User $actor): QualityInspection
    {
        if ($inspection->wasInspected()) {
            throw new \DomainException(
                $inspection->number.' has been inspected and its checkpoints can no longer be changed.'
            );
        }

        return DB::transaction(function () use ($inspection, $rows, $actor) {
            $inspection->items()->delete();

            foreach (array_values($rows) as $index => $row) {
                if (blank($row['description'] ?? null)) {
                    continue;
                }

                $item = new QualityInspectionItem;
                $item->organization_id = $inspection->organization_id;
                $item->quality_inspection_id = $inspection->getKey();
                $item->sort_order = $index;
                $item->reference = $row['reference'] ?? null;
                $item->description = $row['description'];
                $item->acceptance_criteria = $row['acceptance_criteria'] ?? null;
                $item->save();
            }

            $this->activity->record(
                $inspection,
                'updated',
                $actor->name.' updated the checkpoints on '.$this->label($inspection).'.',
                ['items' => $inspection->items()->count()],
                $actor,
                $this->projectIdOf($inspection),
            );

            return $inspection->load('items');
        });
    }

    /**
     * Attend and record the verdict.
     *
     * Results are keyed by item id and matched against this inspection's own rows, so a posted id
     * from another record writes nothing (§84).
     *
     * @param  array<int|string,array<string,mixed>>  $results
     */
    public function inspect(
        QualityInspection $inspection,
        string $result,
        User $actor,
        array $results = [],
        ?string $comments = null,
    ): QualityInspection {
        if ($inspection->wasInspected()) {
            throw new \DomainException($inspection->number.' has already been inspected.');
        }

        if ((int) $inspection->created_by === (int) $actor->getKey()) {
            throw new \DomainException('An inspection cannot be carried out by the person who requested it.');
        }

        $statusKey = self::RESULT_STATUS[$result] ?? null;

        // Anything other than a clean pass has to say what was found. "Rejected" with no comment is
        // an argument on site three days later with nothing written down to settle it.
        if ($statusKey !== 'passed' && blank($comments)) {
            throw new \DomainException('An inspection that did not simply pass must say what was found.');
        }

        return DB::transaction(function () use ($inspection, $result, $statusKey, $actor, $results, $comments) {
            foreach ($inspection->items()->get() as $item) {
                $row = $results[$item->getKey()] ?? null;

                if (! is_array($row)) {
                    continue;
                }

                $item->result = $row['result'] ?? null;
                $item->measured_value = $row['measured_value'] ?? null;
                $item->comment = $row['comment'] ?? null;
                $item->save();
            }

            $inspection->forceFill([
                'inspected_at' => now(),
                'inspected_by' => $actor->getKey(),
                'result' => $result,
                'comments' => $comments ?? $inspection->comments,
            ]);

            if ($statusKey !== null) {
                $this->moveTo($inspection, $statusKey);
            }

            $inspection->save();

            $this->activity->record(
                $inspection,
                'inspected',
                $actor->name.' inspected '.$this->label($inspection).': '.str_replace('_', ' ', $result).'.',
                array_filter([
                    'result' => $result,
                    'response_days' => $inspection->responseDays(),
                ], fn ($v) => $v !== null),
                $actor,
                $this->projectIdOf($inspection),
            );

            QualityInspectionCompleted::dispatch($inspection, (int) $actor->getKey());

            return $inspection;
        });
    }
}
