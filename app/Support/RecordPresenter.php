<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One row, printed the way a reader should see it.
 *
 * EXTRACTED FROM ReportRunner IN PHASE 8 RATHER THAN COPIED, which is the whole point of it being
 * here. A drill-down list is the report's own claim re-stated — "these are the records behind that
 * figure" — and the moment the two have separate formatters they start disagreeing about the same
 * row: a date printed `2026-03-04` on one screen and `4 Mar 2026` on the other, a status shown as
 * its enum name in one place and its label in the other. Somebody then has to work out whether the
 * two screens are showing different records or the same records differently, which is exactly the
 * doubt docs/11 §1 exists to remove.
 *
 * EVERYTHING COMES BACK AS A STRING, already resolved. Renderers never get raw models: the screen,
 * the CSV, the spreadsheet and the PDF must show identical contents, and four templates each
 * deciding how to print a date is how a PDF and a workbook come to disagree about one row.
 *
 * THIS IS NOT AN AUTHORIZATION BOUNDARY. It prints what it is handed. Whoever fetched the record
 * decided the reader may see it, and a column that must never be printed is excluded by
 * ReportCatalogue before it ever reaches here (§61, §67).
 */
final class RecordPresenter
{
    /**
     * One cell of one record.
     *
     * Keyed by entity type rather than by a report, because a drill-down has no report — it has a
     * measure, and the measure names the same register under the same morph alias.
     */
    public static function cell(string $entityType, Model $record, string $column): string
    {
        // A foreign key is printed as the thing it points at. "Civil" rather than "4" — a list full
        // of row ids is a list somebody has to join by hand.
        $relation = ReportCatalogue::relationFor($entityType, $column);

        if ($relation !== null) {
            return self::describe($record->getRelationValue($relation));
        }

        return self::stringify($record->getAttribute($column));
    }

    /** A related record, named the way a person would name it. */
    public static function describe(mixed $related): string
    {
        if (! $related instanceof Model) {
            return '';
        }

        if (method_exists($related, 'displayName')) {
            return (string) $related->displayName();
        }

        // The naming ladder the rest of the platform uses, in the order a record is most likely to
        // carry one. A bare key at the end rather than an empty cell: "#12" at least tells the
        // reader something is there that this could not name.
        foreach (['name', 'title', 'label', 'code', 'subject'] as $attribute) {
            $value = $related->getAttribute($attribute);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '#'.$related->getKey();
    }

    /** Any scalar a column can hold, as text. */
    public static function stringify(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if ($value instanceof CarbonInterface) {
            // Midnight means the column is a date rather than a moment, and printing "00:00" on
            // every due date is noise that makes a list look machine-generated.
            return $value->format($value->format('H:i:s') === '00:00:00' ? 'j M Y' : 'j M Y H:i');
        }

        if ($value instanceof \BackedEnum) {
            return method_exists($value, 'label')
                ? (string) $value->label()
                : Str::headline((string) $value->value);
        }

        if ($value instanceof \UnitEnum) {
            return Str::headline($value->name);
        }

        // An array or object in a cell is a JSON column. Printing the encoding would be unreadable
        // and, for a custom-field bag, would spill every field including ones this list did not ask
        // for — so it is summarised rather than dumped.
        if (is_array($value)) {
            return count($value).' '.Str::plural('item', count($value));
        }

        if (is_object($value)) {
            return method_exists($value, '__toString') ? (string) $value : '';
        }

        return (string) $value;
    }
}
