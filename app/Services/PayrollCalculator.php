<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\EmployeePayComponent;
use App\Models\EmployeeSalary;
use App\Models\PayComponent;
use App\Support\Payroll\PayslipCalculation;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

/**
 * The arithmetic. One code path, every country.
 *
 * THIS CLASS DOES NOT KNOW WHAT A TAX IS. It reads a list of components, each carrying a kind, a
 * method, a basis and either a rate or a table of bands, and walks them in the order the company
 * put them in. Nigerian PAYE and British National Insurance come out of the same twelve lines of
 * code, because neither is named here and neither can be. That is §47 discharged as a program
 * rather than asserted in a comment: if this file ever needs to know which country it is in, the
 * design has failed.
 *
 * THE RUNNING BUCKETS ARE THE WHOLE IDEA. Gross, pensionable, taxable, relief and deduction totals
 * accumulate as the walk proceeds, and each component's basis is resolved AT THE MOMENT IT IS
 * REACHED. That is what lets a pension deduction reduce the figure a later tax component reads,
 * without the engine knowing that pension is deductible before tax anywhere in particular — the
 * company expressed that by putting pension before tax in `sequence`, and by ticking
 * `reduces_taxable` on it.
 *
 * NO FLOATS, ANYWHERE. Every figure is a `BigDecimal` and every division names its scale and its
 * rounding mode explicitly. A hundredth lost per person per month is a real sum of money by the end
 * of the year, and it is money somebody is owed.
 *
 * ROUNDING HAPPENS PER LINE, NOT AT THE END. A payslip has to add up on its own face: if each line
 * is rounded for display but the total is computed from unrounded figures, the payslip shows a
 * column of numbers that does not sum to its own total, and every employee who checks will be
 * right that it is wrong.
 */
final class PayrollCalculator
{
    /** Money, to the cent, half up. The one rounding rule, applied in one place. */
    private const SCALE = 2;

    /**
     * Calculate one payslip.
     *
     * @param  Collection<int,PayComponent>  $components  in calculation order, with `bands` loaded
     * @param  Collection<int,EmployeePayComponent>  $assignments  this person's own rows, keyed by component id
     * @param  float|string  $overtimeHours  approved and actually worked, from the overtime register
     * @param  float|string  $periodFraction  1 for a full period; less for a starter or a leaver
     */
    public function calculate(
        EmployeeSalary $salary,
        Collection $components,
        Collection $assignments,
        string|float $overtimeHours = 0,
        string|float $periodFraction = 1,
        string $frequency = 'monthly',
    ): PayslipCalculation {
        $fraction = BigDecimal::of((string) $periodFraction);
        $hours = BigDecimal::of((string) $overtimeHours);

        // The one figure that does not come from a component. Everything else on the payslip,
        // including basic pay itself, is a row somebody configured — see PayComponent's docblock
        // for why basic is not special-cased into the engine.
        $periodBasic = $salary->amountForFrequency($frequency) ?? BigDecimal::of($salary->basic_amount);
        $basic = $this->round($periodBasic->multipliedBy($fraction));

        $hourly = $salary->hourlyRate();

        $gross = BigDecimal::zero();
        $pensionable = BigDecimal::zero();
        $taxableBase = BigDecimal::zero();
        $reliefs = BigDecimal::zero();
        $deductions = BigDecimal::zero();
        $employerCost = BigDecimal::zero();

        $lines = [];
        $sequence = 0;

        foreach ($components as $component) {
            $assignment = $assignments[$component->getKey()] ?? null;

            // A component that is not automatic and was not given to this person does not appear.
            // Not as a zero line either: a payslip listing every allowance in the company with
            // nothing against most of them is unreadable, and unreadable payslips are not checked.
            if (! $component->applies_to_all && $assignment === null) {
                continue;
            }

            $basisAmount = $this->basisFor($component, $basic, $gross, $pensionable, $taxableBase, $reliefs, $deductions);

            $amount = $this->amountFor($component, $assignment, $basisAmount, $hourly, $hours);

            if ($amount === null) {
                continue;
            }

            // Pro-rata is the component's own decision, because a starter halfway through the month
            // earns half an allowance but usually still repays a whole loan instalment — and which
            // of those a given row is cannot be inferred from its kind.
            if ($component->is_prorated && $component->method === 'fixed') {
                $amount = $amount->multipliedBy($fraction);
            }

            $amount = $this->clamp($component, $this->round($amount));

            // A zero line still appears when the component genuinely applies: "PAYE 0.00" is
            // information — it says the person was below the threshold, not that payroll forgot.
            $lines[] = [
                'pay_component_id' => $component->getKey(),
                'code' => $component->code,
                'name' => $component->name,
                'kind' => $component->kind,
                'method' => $component->method,
                'basis' => $component->basis,
                'rate' => (string) ($assignment?->rate ?? $component->rate),
                'quantity' => $component->basis === 'overtime_hours' ? (string) $hours : null,
                'basis_amount' => (string) $this->round($basisAmount),
                'amount' => (string) $amount,
                'sequence' => $sequence += 10,
            ];

            // The buckets move here and nowhere else, which is what keeps the four kinds honest:
            // an employer cost never reaches net pay, and a relief never reaches either.
            if ($component->kind === 'earning') {
                $gross = $gross->plus($amount);

                if ($component->is_taxable) {
                    $taxableBase = $taxableBase->plus($amount);
                }

                if ($component->is_pensionable) {
                    $pensionable = $pensionable->plus($amount);
                }
            } elseif ($component->kind === 'deduction') {
                $deductions = $deductions->plus($amount);

                if ($component->reduces_taxable) {
                    $taxableBase = $taxableBase->minus($amount);
                }
            } elseif ($component->kind === 'relief') {
                $reliefs = $reliefs->plus($amount);
            } elseif ($component->kind === 'employer_cost') {
                $employerCost = $employerCost->plus($amount);
            }
        }

        return new PayslipCalculation(
            lines: $lines,
            basic: $basic,
            gross: $this->round($gross),
            taxable: $this->taxableGross($taxableBase, $reliefs),
            pensionable: $this->round($pensionable),
            reliefs: $this->round($reliefs),
            deductions: $this->round($deductions),
            net: $this->round($gross->minus($deductions)),
            employerCost: $this->round($employerCost),
        );
    }

    /**
     * What the method is applied to, as the buckets stand right now.
     *
     * `overtime_hours` falls through to zero, deliberately. The basis of an hourly component is a
     * quantity, not money, and the hours reach `amountFor()` by their own route. Never letting an
     * hour count into a money bucket here is what stops 12 hours ever being added to gross pay as
     * if it were twelve of something — a mistake that produces a payslip nobody can explain.
     */
    private function basisFor(
        PayComponent $component,
        BigDecimal $basic,
        BigDecimal $gross,
        BigDecimal $pensionable,
        BigDecimal $taxableBase,
        BigDecimal $reliefs,
        BigDecimal $deductions,
    ): BigDecimal {
        return match ($component->basis) {
            'basic' => $basic,
            'gross' => $gross,
            'pensionable' => $pensionable,
            'taxable_gross' => $this->taxableGross($taxableBase, $reliefs),
            'net' => $gross->minus($deductions),
            default => BigDecimal::zero(),
        };
    }

    /**
     * Taxable pay never goes below zero.
     *
     * Reliefs larger than income are ordinary — a part-month starter under a full personal
     * allowance — and a negative taxable figure fed to a banded table would produce a negative tax,
     * which is to say the company paying the employee's tax for them.
     */
    private function taxableGross(BigDecimal $taxableBase, BigDecimal $reliefs): BigDecimal
    {
        $taxable = $taxableBase->minus($reliefs);

        return $this->round($taxable->isNegative() ? BigDecimal::zero() : $taxable);
    }

    /** The figure itself, before pro-rata and before the floor and ceiling. */
    private function amountFor(
        PayComponent $component,
        ?EmployeePayComponent $assignment,
        BigDecimal $basis,
        ?BigDecimal $hourly,
        BigDecimal $hours,
    ): ?BigDecimal {
        $rate = BigDecimal::of((string) ($assignment?->rate ?? $component->rate));

        return match ($component->method) {
            'fixed' => BigDecimal::of((string) ($assignment?->amount ?? $component->amount)),

            'percent' => $basis->multipliedBy($rate)->dividedBy(100, self::SCALE + 4, RoundingMode::HALF_UP),

            // No divisor on the salary row means the company has not said how many hours the salary
            // covers. Returning null drops the line rather than inventing a rate — silently pricing
            // everybody's overtime at a figure nobody chose is far worse than an absent line, which
            // somebody will notice and ask about.
            'hourly' => $hourly === null
                ? null
                : $hourly->multipliedBy($rate)->multipliedBy($hours),

            'banded' => $this->banded($component, $basis),

            default => null,
        };
    }

    /**
     * A progressive table, applied marginally.
     *
     * Each band charges only the slice of the basis that falls inside it. The alternative — charging
     * the whole figure at the rate of the band it lands in — creates the cliff edge where earning
     * one more unit of currency costs somebody more than it earns them, and is the single most
     * recognisable bug a payroll system can ship.
     */
    private function banded(PayComponent $component, BigDecimal $basis): BigDecimal
    {
        $total = BigDecimal::zero();

        foreach ($component->bands as $band) {
            $portion = $band->portionOf($basis);

            if ($portion->isZero()) {
                continue;
            }

            $total = $total->plus(
                $portion->multipliedBy(BigDecimal::of((string) $band->rate))
                    ->dividedBy(100, self::SCALE + 4, RoundingMode::HALF_UP)
            );
        }

        return $total;
    }

    /**
     * The floor and the ceiling.
     *
     * Both are generic and neither is anybody's tax code: "the greater of X or 1% of gross" needs
     * the floor, and a contribution capped at an upper earnings limit needs the ceiling. Those are
     * the two shapes every capped levy in the world takes, which is why two nullable columns cover
     * a problem that looks as though it needs a rules engine.
     */
    private function clamp(PayComponent $component, BigDecimal $amount): BigDecimal
    {
        if ($component->minimum_amount !== null) {
            $minimum = BigDecimal::of((string) $component->minimum_amount);
            $amount = $amount->isLessThan($minimum) ? $minimum : $amount;
        }

        if ($component->maximum_amount !== null) {
            $maximum = BigDecimal::of((string) $component->maximum_amount);
            $amount = $amount->isGreaterThan($maximum) ? $maximum : $amount;
        }

        return $amount;
    }

    private function round(BigDecimal $value): BigDecimal
    {
        return $value->toScale(self::SCALE, RoundingMode::HALF_UP);
    }
}
