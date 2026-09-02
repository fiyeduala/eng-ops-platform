<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Quotation;
use App\Models\Rfq;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Who may read a supplier's price.
 *
 * QUOTATIONS DEFAULT TO CONFIDENTIAL, so Gate 5 in ScopedPolicy does most of the work: reading one
 * needs unit reach or an explicit ACL row. That is the right default because a live quotation is a
 * competitor's commercial position — circulated internally it reaches somebody who used to work
 * there, and the enquiry is compromised in a way nobody can ever prove.
 *
 * `compare()` IS A SECOND QUESTION LAYERED ON `view`, in the same shape as
 * ContractPolicy::viewFinancials(). Being entitled to see that a supplier quoted is not the same as
 * being entitled to see everybody's numbers side by side, which is the document that decides who
 * gets the work. The comparison page and the scoring both ask this.
 *
 * ONCE THE ENQUIRY IS AWARDED, NOTHING IS EDITABLE. Not by the buyer, not by `manage`. The
 * quotations are the evidence for the decision, and a comparison whose inputs can be adjusted
 * afterwards is not evidence of anything. A quotation entered wrongly against an awarded enquiry is
 * corrected the way the receipt register corrects a count: by cancelling the award, which is
 * recorded, and not by an edit that leaves no trace.
 */
class QuotationPolicy extends ScopedPolicy
{
    protected string $module = 'quotation';

    /**
     * Read everybody's numbers side by side.
     *
     * An AND, never an override: `quotation.compare` widens what you may do with quotations you can
     * already reach, and is not a way to reach one you could not (§61).
     */
    public function compare(User $user, Quotation $quotation): bool
    {
        return $this->check($user, 'view', $quotation)
            && $this->resolver->has($user, 'quotation.compare');
    }

    /**
     * The same question asked of a register rather than of one row.
     *
     * Asked with no record, so it stops at Gate 2 where the answer lives. Every row on the page is
     * still filtered by `view`, so this widens nothing — it decides whether a comparison view is
     * offered at all.
     */
    public function compareAny(User $user): bool
    {
        return $this->check($user, 'viewAny')
            && $this->resolver->has($user, 'quotation.compare');
    }

    /**
     * Record a quotation against a specific enquiry.
     *
     * Asked before the row exists, and it asks about the ENQUIRY: a person who may create
     * quotations in general still must not attach one to an enquiry they cannot see, which is
     * exactly the shape of an IDOR on a hidden `rfq_id` field (§84, §94).
     */
    public function createFor(User $user, Rfq $rfq): bool
    {
        return $this->check($user, 'create')
            && Gate::forUser($user)->allows('view', $rfq)
            && $rfq->acceptsQuotations();
    }

    /** Frozen once the decision is made. See the class docblock. */
    public function update(User $user, Model $record): bool
    {
        if (! $record instanceof Quotation) {
            return parent::update($user, $record);
        }

        $rfq = $record->relationLoaded('rfq') ? $record->rfq : $record->rfq()->first();

        if ($rfq instanceof Rfq && $rfq->isAwarded()) {
            return false;
        }

        return parent::update($user, $record);
    }

    public function manage(User $user, Quotation $quotation): bool
    {
        return $this->check($user, 'manage', $quotation);
    }
}
