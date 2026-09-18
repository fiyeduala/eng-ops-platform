<?php

declare(strict_types=1);

namespace App\Support\Payroll;

use Brick\Math\BigDecimal;

/**
 * The result of calculating one payslip: the working, and the totals it adds up to.
 *
 * A VALUE OBJECT RATHER THAN ROWS IN THE DATABASE, because calculation and persistence are separate
 * concerns and keeping them separate is what makes the engine testable without a payroll run, an
 * organization, or a single table. The service writes this out; the calculator never touches a
 * model it did not receive.
 *
 * Everything is `BigDecimal` until the moment it is stored. Payroll is the one place in the platform
 * where a float would be indefensible: a rounding error of a hundredth, multiplied across a
 * workforce and twelve months, is a real sum of money somebody is owed and has not been paid.
 */
final class PayslipCalculation
{
    /**
     * @param  array<int,array<string,mixed>>  $lines  ready to insert as payslip_lines
     */
    public function __construct(
        public readonly array $lines,
        public readonly BigDecimal $basic,
        public readonly BigDecimal $gross,
        public readonly BigDecimal $taxable,
        public readonly BigDecimal $pensionable,
        public readonly BigDecimal $reliefs,
        public readonly BigDecimal $deductions,
        public readonly BigDecimal $net,
        public readonly BigDecimal $employerCost,
    ) {}

    /**
     * The figures in the shape the payslip row wants them.
     *
     * @return array<string,string>
     */
    public function totals(): array
    {
        return [
            'basic_amount' => (string) $this->basic,
            'gross_amount' => (string) $this->gross,
            'taxable_amount' => (string) $this->taxable,
            'pensionable_amount' => (string) $this->pensionable,
            'relief_amount' => (string) $this->reliefs,
            'deduction_amount' => (string) $this->deductions,
            'net_amount' => (string) $this->net,
            'employer_cost_amount' => (string) $this->employerCost,
        ];
    }
}
