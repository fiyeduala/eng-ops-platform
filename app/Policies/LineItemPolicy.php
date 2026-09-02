<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * A row that has no reach of its own, only its parent's.
 *
 * Phase 5 brings seven of these at once — request lines, enquiry lines, invitations, quotation
 * lines, scores, order lines, receipt lines — and every one of them answers the same three
 * questions the same way: may you see the parent, may you change the parent, and does this row
 * actually belong to the parent you were checked against.
 *
 * WHY THAT LAST QUESTION IS THE WHOLE POINT. The dangerous shape here is not "can this person read
 * a line", it is a form that posts `purchase_order_item_id` belonging to somebody else's order into
 * an update of their own receipt. Delegating to the parent and stopping there answers "may you edit
 * A" while the row belongs to B, so `belongsToParent()` re-reads the row's own foreign key and the
 * services check it before writing (§84).
 *
 * WHY NOT SEVEN COPIES. Phase 4 wrote them one at a time and they came out subtly different: one
 * delegated `delete` to the parent's `view`, another loaded the parent with a fresh query on a row
 * that already had it loaded. Neither is a bug you find by reading; both are bugs you find in
 * production. The shape is named once and each register says only which relation to walk.
 *
 * WHAT IT DOES NOT DO is decide the freeze. Whether a line may be edited at all depends on the
 * parent's status — a receipt that has left `draft` refuses `update` — and that answer lives in
 * the parent policy, which is exactly where a company configuring its own workflow would look.
 */
abstract class LineItemPolicy extends ScopedPolicy
{
    /** The relation on the child that reaches the parent record, e.g. 'purchaseOrder'. */
    abstract protected function parentRelation(): string;

    public function view(User $user, Model $record): bool
    {
        return $this->throughParent($user, 'view', $record) ?? parent::view($user, $record);
    }

    /**
     * Editing a line is editing the parent.
     *
     * Not `view` on the parent — a person who may read an order must not be able to change what it
     * says it ordered — and not a permission of the line's own, because a line has no independent
     * existence to hold one.
     */
    public function update(User $user, Model $record): bool
    {
        return $this->throughParent($user, 'update', $record) ?? parent::update($user, $record);
    }

    /**
     * Deleting a line is editing the parent, NOT deleting it.
     *
     * A buyer removing a line from a draft order is amending the order. Requiring the parent's
     * `delete` here would mean only somebody who could destroy the whole order could take a line
     * off it, which is the wrong shape of authority and the reason people edit the database.
     */
    public function delete(User $user, Model $record): bool
    {
        return $this->throughParent($user, 'update', $record) ?? parent::delete($user, $record);
    }

    /** Adding a line to a specific parent. Asked before the child row exists. */
    public function createFor(User $user, Model $parent): bool
    {
        return Gate::forUser($user)->allows('update', $parent);
    }

    /**
     * Whether this row really hangs off the parent it is being checked through.
     *
     * Called by the services, not by the gate, because it is a data-integrity question rather than
     * a permission one — and answering it here keeps the definition in one place.
     */
    public function belongsToParent(Model $record, Model $parent): bool
    {
        return $record->getAttribute($parent->getForeignKey()) !== null
            && (int) $record->getAttribute($parent->getForeignKey()) === (int) $parent->getKey();
    }

    /**
     * Ask the parent, or null when this is not the model we thought it was.
     *
     * Returning null rather than false lets the caller fall back to the base policy, so a
     * mis-registered policy fails as a normal permission check instead of silently denying
     * everything — which looks identical to a permission problem and wastes a day.
     */
    protected function throughParent(User $user, string $ability, Model $record): ?bool
    {
        $relation = $this->parentRelation();

        if (! method_exists($record, $relation)) {
            return null;
        }

        $parent = $record->relationLoaded($relation)
            ? $record->getRelation($relation)
            : $record->{$relation}()->first();

        return $parent instanceof Model
            ? Gate::forUser($user)->allows($ability, $parent)
            : false;
    }
}
