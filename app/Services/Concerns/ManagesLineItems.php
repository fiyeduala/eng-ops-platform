<?php

declare(strict_types=1);

namespace App\Services\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The lines on a requisition, an enquiry, a quotation, an order and a receipt — written once.
 *
 * FIVE REGISTERS, ONE SHAPE. Every document in the procurement chain is a header plus a set of
 * lines that arrive from a repeating form section, and every one of them needs the same four
 * things done in the same order: keep the lines that are still there, update the ones that
 * changed, create the new ones, and delete the ones the user removed. Written five times that is
 * five chances to get the third step wrong, and the way it goes wrong is always the same — a row
 * posted with somebody else's `id` gets updated instead of created, so a form on RFQ 7 silently
 * rewrites a line on RFQ 12 (§84).
 *
 * SO THE ID IS NEVER TRUSTED. `syncItems()` loads the parent's OWN lines first, keyed by id, and a
 * posted id that is not in that set is treated as a new line rather than as an error. Not an error
 * because the honest cause is far commoner than the dishonest one: two people had the form open and
 * one of them deleted the line. The dishonest cause reaches the same place — a new row on the
 * parent the actor is already authorized to edit — and nothing of another tenant's is touched.
 *
 * LINE NUMBERS ARE ASSIGNED HERE, NOT POSTED. `line_no` is the order the lines appear in, which is
 * the order they arrived in, renumbered from 1 on every save. A posted line number is a duplicate
 * waiting to happen and tells us nothing the array order does not.
 *
 * ONE ACTIVITY ENTRY, NOT ONE PER LINE. A twelve-line order edited twice would otherwise bury its
 * own approval under fifty timeline entries. The audit engine keeps the per-row before/after under
 * its own access rules (§59); the timeline gets the sentence a person would say.
 *
 * WHAT THIS DOES NOT DO is decide what a line means. `itemAttributes()` is where each register
 * whitelists its own columns and does its own arithmetic — a quotation line multiplies a price by
 * a quantity, a receipt line does not multiply anything — and the deny list is not shared because
 * the columns are not shared.
 */
trait ManagesLineItems
{
    /** @return class-string<Model> */
    abstract protected function itemClass(): string;

    /** The column on the line pointing back at the header — `purchase_order_id`. */
    abstract protected function itemForeignKey(): string;

    /** The HasMany on the header — `items`. */
    protected function itemRelation(): string
    {
        return 'items';
    }

    /**
     * Replace the header's lines with the posted set.
     *
     * @param  array<int,array<string,mixed>>  $rows  as posted, in display order
     * @return array{created:int,updated:int,deleted:int}
     */
    protected function syncItems(Model $parent, array $rows, User $actor, bool $record = true): array
    {
        return DB::transaction(function () use ($parent, $rows, $actor, $record) {
            $class = $this->itemClass();

            /** @var Collection<int,Model> $existing */
            $existing = $class::query()
                ->where($this->itemForeignKey(), $parent->getKey())
                ->get()
                ->keyBy(fn (Model $item) => (int) $item->getKey());

            $kept = [];
            $created = 0;
            $updated = 0;
            $line = 0;

            foreach ($rows as $row) {
                if ($this->itemIsEmpty($row)) {
                    continue;
                }

                $line++;

                $attributes = $this->itemAttributes($row, $parent);
                $attributes['line_no'] = $line;

                $id = isset($row['id']) ? (int) $row['id'] : 0;

                // The id has to be one of THIS parent's lines. See the class docblock for why an
                // unrecognised one becomes a new line rather than a validation failure.
                $item = $id > 0 ? $existing->get($id) : null;

                if ($item !== null) {
                    $item->forceFill($attributes);

                    if ($item->isDirty()) {
                        $item->save();
                        $updated++;
                    }

                    $kept[] = (int) $item->getKey();

                    continue;
                }

                /** @var Model $item */
                $item = new $class;
                $item->forceFill($attributes);
                $item->setAttribute('organization_id', $parent->getAttribute('organization_id'));
                $item->setAttribute($this->itemForeignKey(), $parent->getKey());
                $item->save();

                $kept[] = (int) $item->getKey();
                $created++;
            }

            $removed = $existing->keys()->diff($kept);
            $deleted = $removed->count();

            if ($deleted > 0) {
                $class::query()
                    ->where($this->itemForeignKey(), $parent->getKey())
                    ->whereIn('id', $removed->all())
                    ->delete();
            }

            $parent->unsetRelation($this->itemRelation());

            if ($record && ($created + $updated + $deleted) > 0) {
                $this->activity->record(
                    $parent,
                    'lines_changed',
                    $actor->name.' changed the lines on '.$this->label($parent).'.',
                    array_filter([
                        'added' => $created ?: null,
                        'amended' => $updated ?: null,
                        'removed' => $deleted ?: null,
                    ]),
                    $actor,
                    $this->projectIdOf($parent),
                );
            }

            return ['created' => $created, 'updated' => $updated, 'deleted' => $deleted];
        });
    }

    /**
     * Columns this register writes onto one line, plus whatever it computes.
     *
     * ALLOW LIST, not a deny list — the opposite choice from RegisterService::safeAttributes(),
     * and deliberately so. A header has thirty columns and a handful that must not be filled; a
     * line has eight, all of them named right here, and the arithmetic columns must be computed
     * rather than accepted. A posted `line_total` is a posted price.
     *
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    abstract protected function itemAttributes(array $row, Model $parent): array;

    /**
     * A blank repeat of the form section is not a line.
     *
     * Repeating form sections ship with an empty row ready to type into, and the person who does
     * not use it should not get an empty line on their order.
     *
     * @param  array<string,mixed>  $row
     */
    protected function itemIsEmpty(array $row): bool
    {
        return blank($row['description'] ?? null);
    }

    /**
     * Money, from a quantity and a rate, at two decimals — as a STRING.
     *
     * Returned as a string because it is going straight into a decimal column and a float that has
     * been through PHP arithmetic is not the number anybody typed. See App\Support\Quantity.
     */
    protected function lineTotal(mixed $quantity, mixed $rate): string
    {
        return number_format(round((float) $quantity * (float) $rate, 2), 2, '.', '');
    }
}
