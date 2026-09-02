<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Quotation;
use App\Models\QuotationScore;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * A mark somebody gave a quotation.
 *
 * NOBODY EDITS ANOTHER PERSON'S SCORE. Not a manager, not the buyer running the evaluation, not the
 * organization owner. A score is a named individual's opinion, recorded so that a decision can be
 * defended two years later — and an evaluation where a senior person can adjust a junior one's
 * marks is not an evaluation, it is a signature collection. `update` therefore checks identity
 * BEFORE it checks permission, and there is no override.
 *
 * Deleting is the same act: withdrawing your own mark. An evaluator who wants a colleague's score
 * removed is asking for the evaluation to be re-run, which is a conversation rather than a button.
 *
 * READING IS WIDER THAN WRITING, and deliberately so — it requires `quotation.compare` on top of
 * being able to see the quotation, because the comparison is the point of the exercise and an
 * evaluator who cannot see the other marks cannot tell whether the panel agreed. What that
 * permission does NOT do is let somebody read a quotation they could not otherwise open: the
 * parent check comes first, and Quotation defaults to confidential.
 */
class QuotationScorePolicy extends ScopedPolicy
{
    protected string $module = 'quotation';

    public function view(User $user, Model $record): bool
    {
        if (! $record instanceof QuotationScore) {
            return parent::view($user, $record);
        }

        $quotation = $this->quotationFor($record);

        return $quotation !== null
            && Gate::forUser($user)->allows('view', $quotation)
            && $this->resolver->has($user, 'quotation.compare');
    }

    /**
     * Score a quotation you can open, if you are allowed to evaluate at all.
     *
     * Asked without a record, so it answers "may this person take part in evaluations" and the
     * per-quotation question is asked separately by `scoreFor()`.
     */
    public function create(User $user): bool
    {
        return $this->check($user, 'create') && $this->resolver->has($user, 'quotation.compare');
    }

    /** Whether this person may put a mark on THIS quotation. */
    public function scoreFor(User $user, Quotation $quotation): bool
    {
        return Gate::forUser($user)->allows('view', $quotation)
            && $this->resolver->has($user, 'quotation.compare');
    }

    /** Your own mark, and only ever your own. See the class docblock. */
    public function update(User $user, Model $record): bool
    {
        if (! $record instanceof QuotationScore || (int) $record->evaluated_by !== (int) $user->getKey()) {
            return false;
        }

        $quotation = $this->quotationFor($record);

        return $quotation !== null && $this->scoreFor($user, $quotation);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->update($user, $record);
    }

    private function quotationFor(QuotationScore $score): ?Quotation
    {
        $quotation = $score->relationLoaded('quotation')
            ? $score->quotation
            : $score->quotation()->first();

        return $quotation instanceof Quotation ? $quotation : null;
    }
}
