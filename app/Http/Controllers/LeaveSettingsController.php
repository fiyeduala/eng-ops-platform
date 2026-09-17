<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Http\Requests\LeaveBalanceRequest;
use App\Http\Requests\LeaveTypeRequest;
use App\Http\Requests\PublicHolidayRequest;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PublicHoliday;
use App\Services\LeaveService;
use Brick\Math\BigDecimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The company's leave rules (§47): the types it grants, the days it does not work, and what each
 * person was given. All of it rows HR types in; none of it anything the application decided.
 */
class LeaveSettingsController extends Controller
{
    public function __construct(
        private readonly ScopeCompiler $compiler,
        private readonly ActivityRecorder $activity,
        private readonly LeaveService $leave,
    ) {}

    // ================================================================== leave types

    public function types(Request $request): View
    {
        $this->authorize('viewAny', LeaveType::class);

        return view('leave.types.index', [
            'types' => LeaveType::query()->orderBy('sort')->orderBy('name')->get(),
            'canCreate' => $request->user()->can('create', LeaveType::class),
        ]);
    }

    public function createType(): View
    {
        $this->authorize('create', LeaveType::class);

        return view('leave.types.create', ['type' => new LeaveType([
            'carry_over_limit' => '0', 'min_notice_days' => 0, 'is_paid' => true,
            'requires_approval' => true, 'allow_half_days' => true, 'is_active' => true,
        ])]);
    }

    public function storeType(LeaveTypeRequest $request): RedirectResponse
    {
        $this->authorize('create', LeaveType::class);
        $user = $request->user();

        $type = new LeaveType($request->validated());
        $type->organization_id = $user->organization_id;
        $type->created_by = $user->id;
        $type->sort ??= 0;
        $type->save();

        $this->activity->record($type, 'created', $user->name.' added the leave type '.$type->displayName().'.', [], $user);

        return redirect()->route('leave-types.index')->with('status', $type->displayName().' has been added.');
    }

    public function editType(Request $request, LeaveType $leaveType): View
    {
        $this->authorize('update', $leaveType);

        return view('leave.types.edit', [
            'type' => $leaveType,
            'timeline' => $this->activity->timelineFor($leaveType)->limit(15)->get(),
        ]);
    }

    public function updateType(LeaveTypeRequest $request, LeaveType $leaveType): RedirectResponse
    {
        $this->authorize('update', $leaveType);
        $user = $request->user();

        $leaveType->fill($request->validated());
        $leaveType->sort ??= 0;
        $leaveType->updated_by = $user->id;

        if ($leaveType->isDirty()) {
            $changed = array_keys($leaveType->getDirty());
            $leaveType->save();
            $this->activity->record($leaveType, 'updated', $user->name.' updated the leave type '.$leaveType->displayName().'.', ['fields' => $changed], $user);
        }

        return redirect()->route('leave-types.index')->with('status', $leaveType->displayName().' has been updated.');
    }

    public function destroyType(Request $request, LeaveType $leaveType): RedirectResponse
    {
        $this->authorize('delete', $leaveType);

        if (LeaveRequest::withTrashed()->where('leave_type_id', $leaveType->id)->exists()) {
            return back()->withErrors(['delete' => 'Leave has been booked against '.$leaveType->name.'. Make it inactive instead.']);
        }

        $this->activity->record($leaveType, 'deleted', $request->user()->name.' deleted the leave type '.$leaveType->displayName().'.', [], $request->user());
        $leaveType->delete();

        return redirect()->route('leave-types.index')->with('status', 'The leave type has been deleted.');
    }

    // ================================================================== public holidays

    public function holidays(Request $request): View
    {
        $this->authorize('viewAny', PublicHoliday::class);
        $year = (int) $request->query('year', now()->year);
        $year = $year >= 2000 && $year <= 2100 ? $year : (int) now()->year;

        return view('leave.holidays', [
            'year' => $year,
            'holidays' => PublicHoliday::query()
                ->where(fn ($q) => $q->where('is_recurring', true)->orWhereYear('holiday_date', $year))
                ->orderByRaw('is_recurring desc')
                ->orderBy('holiday_date')
                ->get(),
            'canCreate' => $request->user()->can('create', PublicHoliday::class),
        ]);
    }

    public function storeHoliday(PublicHolidayRequest $request): RedirectResponse
    {
        $this->authorize('create', PublicHoliday::class);
        $user = $request->user();

        $holiday = new PublicHoliday($request->validated());
        $holiday->organization_id = $user->organization_id;
        $holiday->created_by = $user->id;
        $holiday->save();

        $this->activity->record($holiday, 'created', $user->name.' added the public holiday '.$holiday->displayName().'.', [], $user);

        return back()->with('status', $holiday->displayName().' has been added. Leave already booked is not recounted.');
    }

    public function destroyHoliday(Request $request, PublicHoliday $publicHoliday): RedirectResponse
    {
        $this->authorize('delete', $publicHoliday);

        $this->activity->record($publicHoliday, 'deleted', $request->user()->name.' removed the public holiday '.$publicHoliday->displayName().'.', [], $request->user());
        $publicHoliday->delete();

        return back()->with('status', 'The public holiday has been removed.');
    }

    // ================================================================== balances

    /**
     * Everybody's balance for one type and year.
     *
     * Opening the page opens the year's balance rows for anybody who has none yet — this is HR
     * reviewing entitlements, and a row they can correct is more use than a figure they cannot.
     */
    public function balances(Request $request): View
    {
        $this->authorize('create', LeaveBalance::class);
        $user = $request->user();

        $types = LeaveType::query()->whereNotNull('days_per_year')->orderBy('sort')->orderBy('name')->get();
        $type = $types->firstWhere('id', (int) $request->query('type')) ?? $types->first();
        $year = (int) $request->query('year', now()->year);
        $year = $year >= 2000 && $year <= 2100 ? $year : (int) now()->year;

        $rows = [];

        if ($type !== null) {
            $employees = $this->compiler->apply(Employee::query()->active(), $user, 'employee.view')
                ->orderBy('first_name')->orderBy('last_name')->limit(500)->get();

            DB::transaction(function () use ($employees, $type, $year, &$rows) {
                foreach ($employees as $employee) {
                    $balance = $this->leave->balanceFor($employee, $type, $year);
                    $used = $this->leave->daysUsed($employee, $type, $year);
                    $rows[] = [
                        'employee' => $employee,
                        'balance' => $balance,
                        'used' => $used,
                        'left' => (string) BigDecimal::of($balance->granted())->minus($used)->toScale(2),
                    ];
                }
            });
        }

        return view('leave.balances', [
            'types' => $types->mapWithKeys(fn (LeaveType $t) => [$t->id => $t->name])->all(),
            'type' => $type,
            'year' => $year,
            'rows' => $rows,
        ]);
    }

    public function updateBalance(LeaveBalanceRequest $request, LeaveBalance $leaveBalance): RedirectResponse
    {
        $this->authorize('update', $leaveBalance);
        $user = $request->user();

        $leaveBalance->fill($request->validated());
        $leaveBalance->updated_by = $user->id;

        if ($leaveBalance->isDirty()) {
            $changed = array_keys($leaveBalance->getDirty());
            $leaveBalance->save();
            $leaveBalance->loadMissing('leaveType');
            $this->activity->record($leaveBalance, 'updated', $user->name.' corrected a '.$leaveBalance->displayName().' balance.', ['fields' => $changed], $user);
        }

        return back()->with('status', 'The balance has been updated.');
    }
}
