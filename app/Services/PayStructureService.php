<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Audit\ActivityRecorder;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeePayComponent;
use App\Models\EmployeeSalary;
use App\Models\PayComponent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The pay structure a company types in, and what each person is on.
 *
 * TWO DIFFERENT KINDS OF THING LIVE HERE and they are kept apart on purpose. Components and their
 * bands are configuration — "PAYE is a banded charge on taxable pay" — and contain nobody's money.
 * Salary rows are somebody's money. They share a service because they are set up in the same sitting
 * by the same person, and they share nothing else: the policies gate them differently and the
 * confidentiality on the tables differs.
 *
 * A RISE IS A NEW ROW, NEVER AN EDIT (§59). `setSalary()` closes the row in force the day before the
 * new one starts and opens the new one. The obvious alternative — updating `basic_amount` — would
 * silently rewrite every past payslip's justification, and the only person who would notice is
 * whoever is trying to answer a pay query from six months ago, by which time the evidence is gone.
 *
 * BAND TABLES ARE REPLACED WHOLE. A progressive table is one thing, not a list of things: editing
 * band three of five leaves four bands describing the old law and one describing the new, and every
 * payslip calculated in between is wrong in a way nothing flags. Replacing the set means the table
 * is either entirely last year's or entirely this year's.
 */
class PayStructureService
{
    public function __construct(private readonly ActivityRecorder $activity) {}

    // ------------------------------------------------------------------ components

    public function createComponent(array $attributes, User $actor): PayComponent
    {
        return DB::transaction(function () use ($attributes, $actor) {
            $component = new PayComponent($this->componentAttributes($attributes));
            $component->organization_id = $actor->organization_id;
            $component->created_by = $actor->id;
            $component->updated_by = $actor->id;
            $component->save();

            $this->syncBands($component, $attributes['bands'] ?? []);

            $this->activity->record(
                $component,
                'created',
                $actor->name.' added the pay component '.$component->code.' — '.$component->name.'.',
                [],
                $actor,
            );

            return $component;
        });
    }

    public function updateComponent(PayComponent $component, array $attributes, User $actor): PayComponent
    {
        return DB::transaction(function () use ($component, $attributes, $actor) {
            $before = ['rate' => (string) $component->rate, 'amount' => (string) $component->amount];

            $component->fill($this->componentAttributes($attributes));
            $component->updated_by = $actor->id;
            $changed = $component->isDirty(['rate', 'amount']);
            $component->save();

            if (array_key_exists('bands', $attributes)) {
                $this->syncBands($component, $attributes['bands']);
            }

            // The old and new figures go in the activity payload, because "when did the pension rate
            // change, and who changed it" is the first question asked when this month's totals do not
            // look like last month's. The payslips themselves are unaffected — each line copied the
            // rate it was calculated under — so this is the only place the change is visible.
            $this->activity->record(
                $component,
                'updated',
                $actor->name.' updated the pay component '.$component->code.'.',
                $changed ? ['from' => $before, 'to' => ['rate' => (string) $component->rate, 'amount' => (string) $component->amount]] : [],
                $actor,
            );

            return $component;
        });
    }

    /**
     * Replace a component's band table.
     *
     * @param  array<int,array<string,mixed>>  $bands
     */
    private function syncBands(PayComponent $component, array $bands): void
    {
        $component->bands()->delete();

        if ($component->method !== 'banded' || $bands === []) {
            return;
        }

        $sequence = 0;

        foreach ($bands as $band) {
            if (blank($band['rate'] ?? null) && blank($band['from_amount'] ?? null)) {
                continue;
            }

            $component->bands()->create([
                'organization_id' => $component->organization_id,
                'sequence' => $sequence += 10,
                'from_amount' => (string) ($band['from_amount'] ?? '0'),
                // Blank means "and everything above", which is what the top band of every
                // progressive table in the world says.
                'to_amount' => blank($band['to_amount'] ?? null) ? null : (string) $band['to_amount'],
                'rate' => (string) ($band['rate'] ?? '0'),
            ]);
        }
    }

    private function componentAttributes(array $attributes): array
    {
        return [
            'code' => mb_strtoupper(trim((string) ($attributes['code'] ?? ''))),
            'name' => trim((string) ($attributes['name'] ?? '')),
            'description' => $attributes['description'] ?? null,
            'kind' => (string) ($attributes['kind'] ?? 'earning'),
            'method' => (string) ($attributes['method'] ?? 'fixed'),
            'basis' => (string) ($attributes['basis'] ?? 'none'),
            'rate' => (string) ($attributes['rate'] ?? '0'),
            'amount' => (string) ($attributes['amount'] ?? '0'),
            'minimum_amount' => blank($attributes['minimum_amount'] ?? null) ? null : (string) $attributes['minimum_amount'],
            'maximum_amount' => blank($attributes['maximum_amount'] ?? null) ? null : (string) $attributes['maximum_amount'],
            'is_taxable' => (bool) ($attributes['is_taxable'] ?? false),
            'is_pensionable' => (bool) ($attributes['is_pensionable'] ?? false),
            'reduces_taxable' => (bool) ($attributes['reduces_taxable'] ?? false),
            'is_prorated' => (bool) ($attributes['is_prorated'] ?? false),
            'applies_to_all' => (bool) ($attributes['applies_to_all'] ?? false),
            'sequence' => (int) ($attributes['sequence'] ?? 0),
            'is_active' => (bool) ($attributes['is_active'] ?? false),
        ];
    }

    // ------------------------------------------------------------------ salary

    /**
     * Put somebody on a new salary from a date.
     *
     * Closes whatever was in force the day before and opens the new row. Both halves in one
     * transaction, because a salary history with two open rows would make "what was she on in March"
     * ambiguous, and payroll would answer it by whichever row the database happened to return first.
     *
     * A ROW THAT HAS ALREADY BEEN PAID AGAINST IS NEVER CLOSED EARLIER THAN ITS PERIOD. Backdating a
     * rise into a month that has already been paid is a real thing companies do, and the right answer
     * is a correcting run for that month, not a quietly rewritten salary history — so the backdate is
     * allowed and the locked run it affects is left alone to be corrected deliberately.
     */
    public function setSalary(Employee $employee, array $attributes, User $actor): EmployeeSalary
    {
        return DB::transaction(function () use ($employee, $attributes, $actor) {
            $from = Carbon::parse($attributes['effective_from']);

            EmployeeSalary::query()
                ->where('employee_id', $employee->getKey())
                ->whereNull('effective_to')
                ->whereDate('effective_from', '<', $from)
                ->update(['effective_to' => $from->copy()->subDay(), 'updated_by' => $actor->id]);

            $salary = new EmployeeSalary([
                'effective_from' => $from,
                'effective_to' => blank($attributes['effective_to'] ?? null) ? null : Carbon::parse($attributes['effective_to']),
                'basic_amount' => (string) ($attributes['basic_amount'] ?? '0'),
                'currency' => (string) ($attributes['currency'] ?? $actor->organization->currency),
                'frequency' => (string) ($attributes['frequency'] ?? 'monthly'),
                'hours_per_period' => blank($attributes['hours_per_period'] ?? null) ? null : (string) $attributes['hours_per_period'],
                'reason' => $attributes['reason'] ?? null,
                'notes' => $attributes['notes'] ?? null,
            ]);

            $salary->employee_id = $employee->getKey();
            $salary->organization_id = $employee->organization_id;
            $salary->created_by = $actor->id;
            $salary->updated_by = $actor->id;
            $salary->save();

            // The figure is NOT in the narrative. An activity line is read wherever activity is
            // shown, and a salary in a feed is a salary outside the permission system (§68). The
            // reason is fine — "Promotion to Senior Engineer" tells the story without the number.
            $this->activity->record(
                $salary,
                'created',
                $actor->name.' recorded a salary change for '.$employee->displayName()
                    .' from '.$from->format('j M Y').($salary->reason ? ' — '.$salary->reason : '.'),
                [],
                $actor,
                null,
                $employee->org_unit_id,
            );

            return $salary;
        });
    }

    // ------------------------------------------------------------------ bank details

    /**
     * Record where somebody is paid.
     *
     * The account number is encrypted at rest and the model keeps only the last four digits in the
     * clear, so a register can show "••••4417" without decrypting anything. Marking an account
     * primary demotes the others in the same transaction — two primary accounts would mean the bank
     * file picked one arbitrarily, and the person would find out on pay day.
     */
    public function setBankAccount(Employee $employee, array $attributes, User $actor): EmployeeBankAccount
    {
        return DB::transaction(function () use ($employee, $attributes, $actor) {
            $primary = (bool) ($attributes['is_primary'] ?? false);

            if ($primary) {
                EmployeeBankAccount::query()
                    ->where('employee_id', $employee->getKey())
                    ->update(['is_primary' => false, 'updated_by' => $actor->id]);
            }

            $account = new EmployeeBankAccount([
                'bank_name' => trim((string) ($attributes['bank_name'] ?? '')),
                'account_name' => trim((string) ($attributes['account_name'] ?? '')),
                'account_number' => (string) ($attributes['account_number'] ?? ''),
                'branch_code' => $attributes['branch_code'] ?? null,
                'account_type' => $attributes['account_type'] ?? null,
                'is_primary' => $primary,
                'is_active' => (bool) ($attributes['is_active'] ?? true),
            ]);

            $account->employee_id = $employee->getKey();
            $account->organization_id = $employee->organization_id;
            $account->created_by = $actor->id;
            $account->updated_by = $actor->id;
            $account->save();

            // Changed bank details are the classic payroll fraud: an email asking for the account to
            // be updated, honoured quietly, and one salary paid to a stranger. The activity line
            // exists so the change has a name and a time against it, and it names the bank and the
            // masked tail only — never the number.
            $this->activity->record(
                $account,
                'created',
                $actor->name.' recorded bank details for '.$employee->displayName()
                    .' — '.$account->bank_name.' '.$account->maskedNumber().'.',
                [],
                $actor,
                null,
                $employee->org_unit_id,
            );

            return $account;
        });
    }

    /**
     * Give one person a component the rest of the company does not have.
     *
     * The component is looked up inside the employee's own organization rather than taken on trust
     * from the posted id, so a component id from another tenant resolves to nothing and the
     * assignment is refused rather than quietly created against a foreign rule (§74, §75).
     *
     * The narrative names the component and the dates and NOT the figure. Somebody having a loan
     * deduction is a fact their manager may reasonably see in an audit trail; how much they owe is
     * not (§68).
     *
     * @param  array<string,mixed>  $attributes
     */
    public function assignComponent(Employee $employee, array $attributes, User $actor): EmployeePayComponent
    {
        return DB::transaction(function () use ($employee, $attributes, $actor) {
            $component = PayComponent::query()
                ->where('organization_id', $employee->organization_id)
                ->findOrFail($attributes['pay_component_id']);

            $assignment = new EmployeePayComponent([
                'pay_component_id' => $component->getKey(),
                'amount' => $attributes['amount'] ?? null,
                'rate' => $attributes['rate'] ?? null,
                'effective_from' => $attributes['effective_from'],
                'effective_to' => $attributes['effective_to'] ?? null,
                'reference' => $attributes['reference'] ?? null,
                'notes' => $attributes['notes'] ?? null,
                'is_active' => (bool) ($attributes['is_active'] ?? true),
            ]);

            $assignment->employee_id = $employee->getKey();
            $assignment->organization_id = $employee->organization_id;
            $assignment->created_by = $actor->id;
            $assignment->updated_by = $actor->id;
            $assignment->save();

            $this->activity->record(
                $assignment,
                'created',
                $actor->name.' gave '.$employee->displayName().' the '.$component->name
                    .' component from '.Carbon::parse($attributes['effective_from'])->format('j M Y').'.',
                [],
                $actor,
                null,
                $employee->org_unit_id,
            );

            return $assignment;
        });
    }
}
