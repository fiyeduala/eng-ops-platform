<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Location;
use App\Services\AttendanceService;
use App\Services\TimesheetService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * Clock in, clock out, and — for supervisors and HR — correct the record afterwards.
 *
 * The clock card is built to work one-handed on a 375px phone on site. Nothing on it takes a person
 * or a time: whose shift it is comes from the session, and when is the server's clock (§84).
 */
class AttendanceController extends Controller
{
    public function __construct(
        private readonly ScopeCompiler $compiler,
        private readonly AttendanceService $attendance,
        private readonly TimesheetService $timesheets,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', AttendanceRecord::class);
        $user = $request->user();
        $employee = $user->employee;

        $date = CarbonImmutable::createFromFormat('!Y-m-d', (string) $request->query('date', now()->toDateString()))
            ?: CarbonImmutable::today();

        $team = $this->compiler->apply(AttendanceRecord::query(), $user, 'attendance.view')
            ->with(['employee', 'project', 'adjuster'])
            ->whereDate('work_date', $date->toDateString())
            ->when($employee !== null, fn ($q) => $q->where('employee_id', '!=', $employee->id))
            ->orderBy('clock_in_at')
            ->limit(300)
            ->get();

        return view('attendance.index', [
            'date' => $date,
            'open' => $employee ? $this->attendance->openShift($employee) : null,
            'mine' => $employee
                ? AttendanceRecord::query()->with(['project', 'adjuster'])->where('employee_id', $employee->id)->latest('clock_in_at')->limit(14)->get()
                : collect(),
            'team' => $team,
            'canClock' => $employee !== null && $user->can('create', AttendanceRecord::class),
            'canRecordFor' => $user->can('recordFor', AttendanceRecord::class),
            'capturesLocation' => $this->attendance->capturesLocation(),
            'projects' => $employee ? $this->timesheets->projectOptions($employee, $user) : [],
            'locations' => Location::query()->orderBy('name')->limit(200)->pluck('name', 'id')->all(),
            'service' => $this->attendance,
        ]);
    }

    public function clockIn(Request $request): RedirectResponse
    {
        $this->authorize('create', AttendanceRecord::class);
        $user = $request->user();
        $data = $this->position($request) + $request->validate([
            'project_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        // A project is only recorded if the person works on it, and a site only if it is this company's.
        if (($data['project_id'] ?? null) !== null && $user->employee !== null
            && ! in_array((int) $data['project_id'], $this->timesheets->allowedProjectIds($user->employee, $user), true)) {
            abort(403);
        }

        if (($data['location_id'] ?? null) !== null && ! Location::query()->whereKey($data['location_id'])->exists()) {
            abort(404);
        }

        try {
            $this->attendance->clockIn($user, $data);
        } catch (\DomainException $e) {
            return back()->withErrors(['clock' => $e->getMessage()]);
        }

        return back()->with('status', 'Clocked in at '.now()->format('H:i').'.');
    }

    public function clockOut(Request $request): RedirectResponse
    {
        $this->authorize('create', AttendanceRecord::class);

        try {
            $record = $this->attendance->clockOut($request->user(), $this->position($request));
        } catch (\DomainException $e) {
            return back()->withErrors(['clock' => $e->getMessage()]);
        }

        return back()->with('status', 'Clocked out at '.now()->format('H:i').' — '.$record->hoursLabel().'.');
    }

    public function edit(AttendanceRecord $attendance): View
    {
        $this->authorize('update', $attendance);
        $attendance->load(['employee', 'adjuster']);

        return view('attendance.edit', [
            'record' => $attendance,
            'timeline' => app(ActivityRecorder::class)->timelineFor($attendance)->limit(25)->get(),
        ]);
    }

    public function update(Request $request, AttendanceRecord $attendance): RedirectResponse
    {
        $this->authorize('update', $attendance);
        $data = $request->validate([
            'clock_in_at' => ['required', 'date'],
            'clock_out_at' => ['nullable', 'date', 'after:clock_in_at'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->attendance->adjust(
                $attendance,
                CarbonImmutable::parse($data['clock_in_at']),
                blank($data['clock_out_at'] ?? null) ? null : CarbonImmutable::parse($data['clock_out_at']),
                $data['reason'],
                $request->user(),
            );
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['clock_in_at' => $e->getMessage()]);
        }

        return redirect()->route('attendance.index', ['date' => $attendance->work_date->toDateString()])
            ->with('status', 'The shift has been corrected.');
    }

    public function create(Request $request): View
    {
        $this->authorize('recordFor', AttendanceRecord::class);

        return view('attendance.create', ['employees' => $this->reachableEmployees($request)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('recordFor', AttendanceRecord::class);
        $data = $request->validate([
            'employee_id' => ['required', 'integer'],
            'clock_in_at' => ['required', 'date'],
            'clock_out_at' => ['required', 'date', 'after:clock_in_at'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        // Only somebody this user may see, and never themselves: your own hours are not yours to type.
        $employee = $this->compiler->apply(Employee::query(), $request->user(), 'employee.view')
            ->whereKey($data['employee_id'])
            ->first();

        if ($employee === null || (int) $employee->user_id === (int) $request->user()->id) {
            abort(403);
        }

        try {
            // The new row must be one this user could correct, or they would have written somewhere
            // they cannot reach — checked inside the transaction so a refusal leaves nothing behind.
            $record = DB::transaction(function () use ($employee, $data, $request) {
                $record = $this->attendance->recordManual(
                    $employee,
                    CarbonImmutable::parse($data['clock_in_at']),
                    CarbonImmutable::parse($data['clock_out_at']),
                    $data['reason'],
                    $request->user(),
                );

                if (! $request->user()->can('update', $record)) {
                    throw new AuthorizationException;
                }

                return $record;
            });
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['clock_in_at' => $e->getMessage()]);
        }

        return redirect()->route('attendance.index', ['date' => $record->work_date->toDateString()])
            ->with('status', 'The shift has been recorded.');
    }

    // ------------------------------------------------------------------ helpers

    /** @return array{latitude?:mixed,longitude?:mixed} */
    private function position(Request $request): array
    {
        $validator = Validator::make($request->only(['latitude', 'longitude']), [
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        // A bad fix is dropped, never a reason to refuse the clock-in.
        return $validator->fails() ? [] : array_filter($validator->validated(), fn ($v) => $v !== null);
    }

    /** @return array<int,string> */
    private function reachableEmployees(Request $request): array
    {
        return $this->compiler->apply(Employee::query()->active(), $request->user(), 'employee.view')
            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $request->user()->id))
            ->orderBy('first_name')
            ->limit(500)
            ->get()
            ->mapWithKeys(fn (Employee $e) => [$e->id => $e->fullName()])
            ->all();
    }
}
