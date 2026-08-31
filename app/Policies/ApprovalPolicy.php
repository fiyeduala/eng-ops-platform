<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Approval;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * A single recorded decision: who, what, when, from which address.
 *
 * NOTHING HERE IS WRITABLE. Not by the person who made the decision, not by an administrator, not
 * by the organization owner. An approval is evidence — it is the row somebody points at two years
 * later to show that the drawing they built to had been signed off — and evidence that can be
 * edited afterwards is not evidence (§59). `update`, `delete` and `forceDelete` all return false
 * flatly, with no permission that could switch them on.
 *
 * Changing your mind has a name and it is not "edit": the route is cancelled or the record is
 * resubmitted, and both leave the original decision readable with its own timestamp.
 *
 * VIEWING follows the stage instance, which follows the record. Same rule as everywhere else in
 * the workflow engine: if you can see the document, you can see who approved it.
 */
class ApprovalPolicy extends ScopedPolicy
{
    protected string $module = 'approval';

    public function view(User $user, Model $record): bool
    {
        if (! $record instanceof Approval) {
            return parent::view($user, $record);
        }

        if (! $this->sameOrganization($user, $record)) {
            return false;
        }

        // Your own decision is always yours to read, even if you have since lost sight of the
        // record — somebody who reviewed a document before moving departments should still be able
        // to see what they signed.
        if ((int) $record->user_id === (int) $user->getKey()) {
            return true;
        }

        $stageInstance = $record->relationLoaded('stageInstance')
            ? $record->stageInstance
            : $record->stageInstance()->first();

        return $stageInstance !== null && Gate::forUser($user)->allows('view', $stageInstance);
    }

    /**
     * Approvals are created by the engine, never by a controller.
     *
     * The ability that actually governs recording a decision is `act` on the stage instance, which
     * knows about the frozen assignee list, the quorum and self-approval. Leaving `create` open
     * here would be a second, weaker door into the same room.
     */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Model $record): bool
    {
        return false;
    }

    public function delete(User $user, Model $record): bool
    {
        return false;
    }

    public function restore(User $user, Model $record): bool
    {
        return false;
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return false;
    }
}
