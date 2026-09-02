<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Rfq;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Who may ask suppliers for a price.
 *
 * AN ISSUED ENQUIRY IS FROZEN, and this is stricter than it looks. Once five suppliers hold a
 * document, editing our copy of it means the file no longer says what they were asked — and the
 * comparison that follows is between quotations answering different questions. Changing an issued
 * enquiry is `rfq.manage`, and the honest way to change one is to cancel it and issue another,
 * which is exactly what a buyer would have to do in the real world.
 *
 * `issue()` IS ITS OWN PERMISSION because it is the moment the company appears in a supplier's
 * inbox. Drafting an enquiry is desk work; sending it is representing the organization, and plenty
 * of companies let a junior buyer do the first and not the second.
 *
 * AWARDING IS `rfq.manage`, NOT `quotation.compare`. Scoring quotations is analysis and belongs to
 * the panel; choosing the winner is a commitment and belongs to whoever carries the commercial
 * authority. Conflating them would mean anybody who could score could also decide.
 */
class RfqPolicy extends ScopedPolicy
{
    protected string $module = 'rfq';

    /** A draft is the buyer's. An issued enquiry is what the suppliers hold. */
    public function update(User $user, Model $record): bool
    {
        if (! $record instanceof Rfq) {
            return parent::update($user, $record);
        }

        return $record->statusKey() === 'draft'
            ? parent::update($user, $record)
            : $this->check($user, 'manage', $record);
    }

    public function issue(User $user, Rfq $rfq): bool
    {
        return $this->check($user, 'issue', $rfq);
    }

    /**
     * Invite a supplier, or record that they declined.
     *
     * Editing the invitation list before issue is ordinary editing; adding a supplier AFTER issue
     * is a real decision — they get less time than everybody else — so it takes `manage` and the
     * service records it.
     */
    public function invite(User $user, Rfq $rfq): bool
    {
        return $rfq->statusKey() === 'draft'
            ? $this->check($user, 'update', $rfq)
            : $this->check($user, 'manage', $rfq);
    }

    /** Choose the winner. See the class docblock for why this is not `quotation.compare`. */
    public function award(User $user, Rfq $rfq): bool
    {
        return $this->check($user, 'manage', $rfq);
    }

    public function manage(User $user, Rfq $rfq): bool
    {
        return $this->check($user, 'manage', $rfq);
    }
}
