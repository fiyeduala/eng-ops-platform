<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\SubmittalReviewed;
use App\Models\DocumentVersion;
use App\Models\Submittal;
use App\Models\SubmittalItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Submittals: packages sent out for review, and the coded verdict that comes back (§27).
 *
 * THE VERDICT CLOSES THE RECORD. `review()` writes the code once and refuses a second attempt.
 * Everything after that is a RESUBMITTAL — a new record pointing back at this one — which is both
 * how the contract works and the only version that leaves the history intact. `resubmit()` copies
 * the package forward, including its items, because retyping thirty line items is how a register
 * stops being used.
 *
 * ITEMS MAY BE CODED WHILE THE PARENT IS UNDER REVIEW, and that is why item coding is its own
 * method rather than a field on the update form. Nine items accepted and the tenth rejected is an
 * ordinary Tuesday; a single code on the parent cannot express it, and the moment those per-item
 * codes are needed is exactly the moment the parent's ordinary `update` is about to close.
 */
class SubmittalService extends RegisterService
{
    protected function entityType(): string
    {
        return 'submittal';
    }

    protected function modelClass(): string
    {
        return Submittal::class;
    }

    /** @return array<int,string> */
    protected function protectedAttributes(): array
    {
        return ['status_id', 'review_code', 'review_comment', 'reviewed_by', 'reviewed_at', 'resubmittal_of_id'];
    }

    protected function creationNarrative(Model $record, User $actor): string
    {
        /** @var Submittal $record */
        return $actor->name.' prepared '.$this->label($record)
            .($record->party_name ? ' for '.$record->party_name : '').'.';
    }

    // ================================================================== contents

    /**
     * Add a line to the package.
     *
     * The version is optional: half the contents of a real submittal — a vendor catalogue page, a
     * certificate scanned at site — were never brought into document control and never will be.
     * A register that refuses to record them is one people keep in a spreadsheet instead.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function addItem(
        Submittal $submittal,
        ?DocumentVersion $version,
        array $attributes,
        User $actor,
    ): SubmittalItem {
        $this->assertNotReviewed($submittal, 'added to');

        if ($version !== null && (int) $version->organization_id !== (int) $submittal->organization_id) {
            throw new \DomainException('That revision does not belong to this organization.');
        }

        return DB::transaction(function () use ($submittal, $version, $attributes, $actor) {
            $item = new SubmittalItem([
                'description' => (string) ($attributes['description'] ?? $version?->document?->title ?? 'Item'),
                'reference' => $attributes['reference'] ?? null,
                'quantity' => max(1, (int) ($attributes['quantity'] ?? 1)),
                'remarks' => $attributes['remarks'] ?? null,
                'sort' => (int) ($attributes['sort'] ?? ($submittal->items()->max('sort') + 1)),
            ]);

            $item->organization_id = $submittal->organization_id;
            $item->submittal_id = $submittal->getKey();
            $item->document_version_id = $version?->getKey();
            $item->created_by = $actor->getKey();

            $item->save();

            return $item;
        });
    }

    public function removeItem(SubmittalItem $item, User $actor): void
    {
        $this->assertNotReviewed($item->submittal, 'changed');

        $item->delete();
    }

    /**
     * Record one line's own verdict.
     *
     * Allowed while the parent is under review and refused once the parent is coded — after that
     * the package is frozen and the next event is a resubmittal.
     */
    public function codeItem(SubmittalItem $item, string $code, User $actor, ?string $remarks = null): SubmittalItem
    {
        $this->assertNotReviewed($item->submittal, 'coded');

        $item->forceFill([
            'review_code' => $code,
            'remarks' => $remarks ?? $item->remarks,
        ])->save();

        $this->activity->record(
            $item->submittal,
            'item_coded',
            $actor->name.' coded "'.$item->displayName().'" as '.$code.'.',
            ['item_id' => $item->getKey(), 'code' => $code],
            $actor,
            $this->projectIdOf($item->submittal),
        );

        return $item;
    }

    // ================================================================== the round trip

    public function submit(Submittal $submittal, User $actor): Submittal
    {
        $this->assertNotReviewed($submittal, 'submitted');

        if ($submittal->items()->doesntExist()) {
            throw new \DomainException('A submittal cannot be sent with nothing on it.');
        }

        return DB::transaction(function () use ($submittal, $actor) {
            if ($submittal->submitted_on === null) {
                $submittal->submitted_on = now()->toDateString();
            }

            if ($submittal->submitted_by === null) {
                $submittal->submitted_by = $actor->getKey();
            }

            $this->moveTo($submittal, 'submitted');
            $submittal->save();

            $this->activity->record(
                $submittal,
                'submitted',
                $actor->name.' submitted '.$this->label($submittal)
                    .($submittal->party_name ? ' to '.$submittal->party_name : '')
                    .' ('.$submittal->items()->count().' item(s)).',
                ['items' => $submittal->items()->count()],
                $actor,
                $this->projectIdOf($submittal),
            );

            return $submittal;
        });
    }

    /**
     * Record the code that came back.
     *
     * `$statusKey` is passed in rather than derived from the code, because the mapping from a
     * client's code to a status is the client's convention, not ours — "B" means approved-as-noted
     * on one contract and revise-and-resubmit on another. The caller (a form the reviewer fills in)
     * says both, and nothing here guesses.
     */
    public function review(
        Submittal $submittal,
        string $code,
        string $statusKey,
        User $actor,
        ?string $comment = null,
    ): Submittal {
        $this->assertNotReviewed($submittal, 'reviewed');

        return DB::transaction(function () use ($submittal, $code, $statusKey, $actor, $comment) {
            $submittal->forceFill([
                'review_code' => $code,
                'review_comment' => $comment,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
            ]);

            $this->moveTo($submittal, $statusKey);
            $submittal->save();

            $this->activity->record(
                $submittal,
                'reviewed',
                $actor->name.' coded '.$this->label($submittal).' as '.$code.'.',
                ['code' => $code, 'status' => $statusKey],
                $actor,
                $this->projectIdOf($submittal),
            );

            SubmittalReviewed::dispatch($submittal, (int) $actor->getKey());

            return $submittal;
        });
    }

    /**
     * Open the next attempt against a coded submittal.
     *
     * Copies the header and the items forward and points the new record back at the old one. The
     * chain is what the schedule argument is settled on — "we submitted this three times and they
     * held it for eleven weeks" — and it only exists if each attempt is its own row.
     *
     * The items are copied with their document versions but WITHOUT their codes: a fresh attempt
     * has not been reviewed, and carrying last time's verdict forward would make the register
     * claim a review that has not happened.
     */
    public function resubmit(Submittal $submittal, User $actor): Submittal
    {
        if (! $submittal->isReviewed()) {
            throw new \DomainException($submittal->number.' has not been reviewed, so there is nothing to resubmit.');
        }

        return DB::transaction(function () use ($submittal, $actor) {
            /** @var Submittal $next */
            $next = $this->create([
                'title' => $submittal->title,
                'description' => $submittal->description,
                'type' => $submittal->type,
                'project_id' => $submittal->project_id,
                'discipline_id' => $submittal->discipline_id,
                'org_unit_id' => $submittal->org_unit_id,
                'party_type' => $submittal->party_type,
                'party_id' => $submittal->party_id,
                'party_name' => $submittal->party_name,
                'required_by' => $submittal->required_by,
                'confidentiality' => $submittal->confidentiality,
                'revision' => $submittal->revision,
            ], $actor);

            $next->resubmittal_of_id = $submittal->getKey();
            $next->save();

            foreach ($submittal->items()->get() as $item) {
                $copy = $item->replicate(['review_code', 'created_at', 'updated_at']);
                $copy->submittal_id = $next->getKey();
                $copy->review_code = null;
                $copy->created_by = $actor->getKey();
                $copy->save();
            }

            $this->activity->record(
                $submittal,
                'resubmitted',
                $actor->name.' opened '.$next->number.' as attempt '.$next->attemptNumber()
                    .' against '.$submittal->number.'.',
                ['resubmittal_id' => $next->getKey(), 'number' => $next->number],
                $actor,
                $this->projectIdOf($submittal),
            );

            return $next;
        });
    }

    // ================================================================== internals

    private function assertNotReviewed(?Submittal $submittal, string $verb): void
    {
        if ($submittal === null) {
            throw new \DomainException('That item does not belong to a submittal.');
        }

        if ($submittal->isReviewed()) {
            throw new \DomainException(
                $submittal->number.' was coded "'.$submittal->review_code.'" on '
                .$submittal->reviewed_at?->format('j M Y').' and cannot be '.$verb.'. Raise a resubmittal instead.'
            );
        }
    }
}
