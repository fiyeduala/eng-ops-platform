<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Deliverable;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Deliverables — the Master Deliverable Register (§26).
 *
 * The register is a PLAN, and the rule that matters is that a plan may be revised but its history
 * may not be quietly rewritten. So:
 *
 *   - A deliverable that has been ISSUED cannot be deleted. Deleting it would remove the row that
 *     proves something was owed and produced, and every progress figure ever reported against it.
 *     Editing an issued deliverable is still allowed — a title is corrected, a document is attached
 *     late — and the audit engine records what changed.
 *
 *   - PROGRESS IS A SEPARATE ABILITY from editing. Reporting percentage complete is the daily work
 *     of the responsible engineer; changing the promised date is a decision for whoever runs the
 *     schedule. Collapsing the two would mean anybody who can report progress can also move the
 *     baseline they are being measured against, which is the one thing a register exists to
 *     prevent. `report` maps to `deliverable.edit` for reach, but is checked against the record so
 *     an engineer with `assigned` scope may update their own and nobody else's.
 */
class DeliverablePolicy extends ScopedPolicy
{
    protected string $module = 'deliverable';

    public function delete(User $user, Model $record): bool
    {
        if ($record instanceof Deliverable && $record->isIssued()) {
            return false;
        }

        return parent::delete($user, $record);
    }

    /** Send the deliverable out for review. */
    public function submit(User $user, Deliverable $deliverable): bool
    {
        return ! $deliverable->isIssued() && $this->check($user, 'submit', $deliverable);
    }

    public function review(User $user, Deliverable $deliverable): bool
    {
        return $this->check($user, 'review', $deliverable);
    }

    /**
     * Record progress against a deliverable.
     *
     * Deliberately `edit` reach on the record rather than a permission of its own: the catalogue
     * declares no `deliverable.report`, and inventing one here would put a permission in the
     * system that no role template grants and no administrator can see (§32 — the catalogue is the
     * only supported way to introduce one).
     */
    public function reportProgress(User $user, Deliverable $deliverable): bool
    {
        return $this->check($user, 'update', $deliverable);
    }

    /**
     * Attach the document that satisfies the promise.
     *
     * Requires reach over the deliverable only. The DOCUMENT is authorized separately when it is
     * chosen — the picker runs through the compiler like every other index — so linking cannot be
     * used to point at a file the actor could not otherwise see, and seeing the link discloses the
     * document's number, never its contents (§84).
     */
    public function attachDocument(User $user, Deliverable $deliverable): bool
    {
        return $this->check($user, 'update', $deliverable);
    }
}
