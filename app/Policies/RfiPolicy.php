<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Rfi;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * RFIs, technical queries and site queries (§27).
 *
 * THE ANSWER IS THE RECORD. Once an RFI has been answered, the question may no longer be edited and
 * the record may no longer be deleted — by anyone, at any scope. That is not tidiness: an answered
 * RFI is a contractual instruction, frequently the document a claim rests on, and a register where
 * the question can be reworded after the answer arrived is a register nobody can rely on. Corrections
 * go in as a follow-up query, which is also how it works on paper.
 *
 * RESPONDING IS ITS OWN PERMISSION, separate from editing. The person who may answer a technical
 * query is the discipline engineer; the person who may correct a typo in the register is the
 * document controller. Neither implies the other, and `rfi.respond` is what a company grants when it
 * decides who speaks for it.
 *
 * CLOSING IS SEPARATE AGAIN. An answer is not always acceptance — the asker decides whether the
 * question is actually resolved, and `rfi.close` is deliberately not implied by `rfi.respond` so the
 * responder cannot mark their own answer satisfactory.
 */
class RfiPolicy extends ScopedPolicy
{
    protected string $module = 'rfi';

    public function update(User $user, Model $record): bool
    {
        if ($record instanceof Rfi && $record->isAnswered()) {
            return false;
        }

        return parent::update($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        if ($record instanceof Rfi && $record->isAnswered()) {
            return false;
        }

        return parent::delete($user, $record);
    }

    /** Send it out — to the client, to engineering, to whoever owes the answer. */
    public function submit(User $user, Rfi $rfi): bool
    {
        return ! $rfi->isAnswered() && $this->check($user, 'submit', $rfi);
    }

    /** Answer it. Once, and then the record hardens; see the class docblock. */
    public function respond(User $user, Rfi $rfi): bool
    {
        return ! $rfi->isAnswered() && $this->check($user, 'respond', $rfi);
    }

    /**
     * Accept the answer and close the question.
     *
     * Requires an answer to exist. Closing an unanswered RFI would record that a question was
     * resolved when nobody resolved it — if it is going away unanswered it is being withdrawn,
     * which is a status transition with a reason attached, not a close.
     */
    public function close(User $user, Rfi $rfi): bool
    {
        return $rfi->isAnswered() && $this->check($user, 'close', $rfi);
    }

    /**
     * Correct an answer that was recorded wrongly.
     *
     * Needs `rfi.close` — the narrowest ability in the module — rather than `respond`, because
     * amending an answer somebody has already built work on is a heavier act than giving one. The
     * previous text stays in the audit trail either way; the service writes the correction as a
     * new activity entry rather than a silent overwrite.
     */
    public function amendAnswer(User $user, Rfi $rfi): bool
    {
        return $rfi->isAnswered() && $this->check($user, 'close', $rfi);
    }
}
