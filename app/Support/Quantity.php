<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Printing a quantity without the noise.
 *
 * Procurement stores quantities at `decimal(18,4)` because cable is bought by the metre and a
 * metre has decimals, chemicals are bought by the kilogram, and rounding either to whole numbers
 * loses money. The cost is that a quantity of two comes back from the database as "2.0000", and a
 * page that reads "2.0000 each" looks like a spreadsheet somebody forgot to format.
 *
 * WHY NOT `(float)`. Because `(float) "0.1"` printed back is not always "0.1", and a procurement
 * total that is out by a hundredth is a support ticket. The trailing zeros are removed from the
 * STRING, which cannot change the value.
 *
 * WHY NOT A BLADE DIRECTIVE. The same figure is needed in notification bodies, exported CSV and
 * PDF, none of which render Blade — and two implementations of "how do we print a quantity" is how
 * the delivery note and the screen end up disagreeing.
 */
final class Quantity
{
    /** "2.0000" becomes "2"; "12.5000" becomes "12.5"; "0.0100" becomes "0.01". */
    public static function trim(mixed $value): string
    {
        $string = trim((string) ($value ?? ''));

        if ($string === '') {
            return '';
        }

        if (! str_contains($string, '.')) {
            return $string;
        }

        $trimmed = rtrim(rtrim($string, '0'), '.');

        // "0.0000" trims all the way to "", and a quantity of zero is a real answer — a line
        // received in full with nothing rejected has exactly this value.
        return $trimmed === '' || $trimmed === '-' ? '0' : $trimmed;
    }

    /**
     * A quantity with its unit, skipping the unit when nobody recorded one.
     *
     * "12 m" reads correctly; "12 " with a stray space is the kind of thing that survives review
     * and then appears on a purchase order sent to a supplier.
     */
    public static function withUnit(mixed $value, ?string $uom): string
    {
        return trim(self::trim($value).' '.trim((string) $uom));
    }
}
