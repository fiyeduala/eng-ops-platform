<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\EmployeeBankAccountRequest;
use App\Http\Requests\EmployeePayComponentRequest;
use App\Http\Requests\EmployeeSalaryRequest;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeePayComponent;
use App\Models\EmployeeSalary;
use App\Models\PayComponent;
use App\Services\PayStructureService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * One person's pay: their salary history, their own components, and where they are paid.
 *
 * TWO AUTHORIZATIONS ON EVERY ACTION, NOT ONE. `view` on the employee answers "may I reach this
 * person at all", and the salary policy answers "may I see what they earn" — and the second is much
 * narrower than the first. A department head holds `employee.view` over their whole team and must
 * not thereby learn what any of them are paid, so checking only the employee would be the single
 * most damaging authorization mistake available in this module (§84, §94).
 *
 * THE EMPLOYEE COMES FROM THE ROUTE AND IS RE-CHECKED, NEVER TRUSTED. `employees/{employee}/pay`
 * carries an id somebody can edit in the address bar; being allowed to set salaries is not being
 * allowed to set anybody's.
 */
class EmployeePayController extends Controller
{
    public function __construct(private readonly PayStructureService $structure) {}

    public function show(Request $request, Employee $employee): View
    {
        $this->authorize('view', $employee);
        $this->authorize('viewAny', EmployeeSalary::class);

        return view('employee-pay.show', [
            'employee' => $employee,
            // Newest first: "what are they on now" is the question in nine cases out of ten, and the
            // history below it answers the tenth.
            'salaries' => EmployeeSalary::query()
                ->where('employee_id', $employee->getKey())
                ->orderByDesc('effective_from')->orderByDesc('id')
                ->get(),
            'assignments' => EmployeePayComponent::query()
                ->where('employee_id', $employee->getKey())
                ->with('component')
                ->get(),
            'accounts' => EmployeeBankAccount::query()
                ->where('employee_id', $employee->getKey())
                ->orderByDesc('is_primary')->orderBy('bank_name')
                ->get(),
            // Only the components somebody has to be given. The automatic ones apply to everybody
            // and listing them here would suggest they are optional.
            'components' => PayComponent::query()->active()
                ->where('applies_to_all', false)
                ->inCalculationOrder()->get(),
            'frequencies' => EmployeeSalary::FREQUENCIES,
            // Read off the actor's organization rather than the employee's, which is the same
            // organization by the time anybody reaches this page and does not need a second query.
            'currency' => $request->user()->organization->currency,
            'canEdit' => $request->user()->can('create', EmployeeSalary::class),
        ]);
    }

    public function storeSalary(EmployeeSalaryRequest $request, Employee $employee): RedirectResponse
    {
        $this->authorize('view', $employee);
        $this->authorize('create', EmployeeSalary::class);

        $this->structure->setSalary($employee, $request->validated(), $request->user());

        return redirect()->route('employee-pay.show', $employee)
            ->with('status', 'The salary has been recorded. Runs already approved are unaffected.');
    }

    public function storeBankAccount(EmployeeBankAccountRequest $request, Employee $employee): RedirectResponse
    {
        $this->authorize('view', $employee);
        $this->authorize('create', EmployeeBankAccount::class);

        $account = $this->structure->setBankAccount($employee, $request->validated(), $request->user());

        return redirect()->route('employee-pay.show', $employee)
            ->with('status', 'Bank details recorded — '.$account->bank_name.' '.$account->maskedNumber().'.');
    }

    public function storeComponent(EmployeePayComponentRequest $request, Employee $employee): RedirectResponse
    {
        $this->authorize('view', $employee);
        $this->authorize('create', EmployeePayComponent::class);

        $this->structure->assignComponent($employee, $request->validated(), $request->user());

        return redirect()->route('employee-pay.show', $employee)
            ->with('status', 'The component has been assigned. It applies from the next run that covers its start date.');
    }
}
