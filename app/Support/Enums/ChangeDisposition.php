<?php

declare(strict_types=1);

namespace App\Support\Enums;

/**
 * The answer given to a change request, as distinct from where the request has got to.
 *
 * STATUS AND DISPOSITION ARE NOT THE SAME FIELD. Status is the tenant's own process — draft,
 * submitted, under review, closed — and is configurable down to its labels. Disposition is the
 * decision, and it is fixed, because it is what every report and every claim is counted on: "we
 * approved 14 changes worth 900k, rejected 6, and 3 are deferred pending client instruction". If
 * disposition were configurable those numbers would mean something different in each tenant and
 * nothing across them.
 *
 * A closed request may carry any disposition, including none — withdrawn before anybody looked.
 */
enum ChangeDisposition: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';

    /** Not now. A real answer, and the one most often lost in a spreadsheet. */
    case Deferred = 'deferred';

    /** Taken back by whoever raised it, before a decision. */
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Deferred => 'Deferred',
            self::Withdrawn => 'Withdrawn',
        };
    }

    /** Whether this disposition means the scope and cost actually change. */
    public function changesTheContract(): bool
    {
        return $this === self::Approved;
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Approved => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
            self::Rejected => 'bg-rose-50 text-rose-700 ring-rose-200',
            self::Deferred => 'bg-amber-50 text-amber-800 ring-amber-200',
            self::Withdrawn => 'bg-slate-100 text-slate-700 ring-slate-200',
        };
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
