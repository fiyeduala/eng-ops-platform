<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Ncr;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * It does not meet the specification.
 *
 * A DISPOSITIONED NCR IS FROZEN. The requirement, the finding and the cost impact are what the
 * disposition was decided on; a register where they can be adjusted afterwards cannot support the
 * decision it recorded. If the finding turns out to have been wrong, that is a new NCR — or a
 * withdrawal, which is a status and leaves the original readable.
 *
 * NOBODY DISPOSITIONS THEIR OWN. Refused at any scope. The person who raised the non-conformance
 * deciding that it can be left as it is would make the register self-cancelling, and use-as-is is
 * precisely the disposition somebody will be asked to justify years later while holding a failed
 * component.
 *
 * RESPONDING IS NOT DISPOSITIONING. The party who did the work answers the finding — that is
 * `review`, and the author may legitimately hold it. The engineering decision about what happens to
 * the work is `approve`, and that is the one they may not exercise here.
 *
 * VERIFICATION IS SEPARATE AGAIN, and is not gated on the author, because by then the question is
 * whether the disposition was actually carried out — a factual check the originator is often the
 * right person to make, and frequently the only one who saw the original defect.
 */
class NcrPolicy extends ScopedPolicy
{
    protected string $module = 'ncr';

    public function update(User $user, Model $record): bool
    {
        if ($record instanceof Ncr && $record->isDispositioned()) {
            return false;
        }

        return parent::update($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        if ($record instanceof Ncr && ($record->isDispositioned() || $record->hasResponse())) {
            return false;
        }

        return parent::delete($user, $record);
    }

    /** Issue the NCR to the party responsible. */
    public function submit(User $user, Ncr $ncr): bool
    {
        return ! $ncr->isDispositioned() && $this->check($user, 'submit', $ncr);
    }

    /** The responsible party's answer to the finding. */
    public function respond(User $user, Ncr $ncr): bool
    {
        return ! $ncr->isDispositioned() && $this->check($user, 'review', $ncr);
    }

    /** Decide what happens to the work: rework, repair, use as is, reject, regrade. Never your own. */
    public function disposition(User $user, Ncr $ncr): bool
    {
        if ($ncr->isDispositioned() || $ncr->isClosedOut()) {
            return false;
        }

        if ($this->raisedIt($user, $ncr)) {
            return false;
        }

        return $this->check($user, 'approve', $ncr);
    }

    /** Confirm the disposition was actually carried out. Only after one exists. */
    public function verify(User $user, Ncr $ncr): bool
    {
        return $ncr->isDispositioned()
            && ! $ncr->isVerified()
            && $this->check($user, 'review', $ncr);
    }

    /** File it away. Verified first — closing an unverified NCR closes nothing. */
    public function close(User $user, Ncr $ncr): bool
    {
        return $ncr->isVerified()
            && ! $ncr->isClosedOut()
            && $this->check($user, 'close', $ncr);
    }

    /**
     * Whoever raised the non-conformance.
     *
     * Both columns, as everywhere else in the phase: the QA engineer detects it and the document
     * controller enters it, or the reverse, and either of them dispositioning it is a
     * self-disposition.
     */
    private function raisedIt(User $user, Ncr $ncr): bool
    {
        return (int) $ncr->detected_by === $user->getKey()
            || (int) $ncr->created_by === $user->getKey();
    }
}
