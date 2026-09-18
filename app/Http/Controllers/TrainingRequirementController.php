<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\TrainingRequirementRequest;
use App\Models\Employee;
use App\Models\Position;
use App\Models\TrainingCourse;
use App\Models\TrainingRequirement;
use App\Models\User;
use App\Services\TrainingRequirementService;
use App\Support\OptionList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * What each job requires, and the matrix that shows who is short.
 *
 * THE MATRIX IS FILTERED BY THE READER'S REACH, not by a checkbox on the screen. The employee list
 * comes out of the compiler under `employee.view`, so a department head sees their department and a
 * training officer sees the company — the same query that drives every other index page (§61). A
 * report is not a back door.
 *
 * IT READS THE HSE REGISTER RATHER THAN A SECOND ONE. Certificates live in `training_records` and
 * always did; this screen is the other half of the question — what was supposed to be held — and
 * duplicating the certificates to make the join easier would mean two registers disagreeing by
 * Thursday.
 */
class TrainingRequirementController extends RegisterController
{
    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly TrainingRequirementService $requirements,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'training_requirement';
    }

    protected function modelClass(): string
    {
        return TrainingRequirement::class;
    }

    protected function routePrefix(): string
    {
        return 'training-requirements';
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', TrainingRequirement::class);
        $user = $request->user();

        $query = $this->scopedQuery($user, 'hse_training.view')->with(['course', 'orgUnit', 'position']);

        if (($courseId = (int) $request->query('course', 0)) > 0) {
            $query->where('training_course_id', $courseId);
        }

        return view('training-requirements.index', [
            'requirements' => $query->orderBy('scope')->orderBy('id')->paginate(50)->withQueryString(),
            'courses' => $this->courseOptions($user),
            'filters' => ['course' => $courseId],
            'canCreate' => $user->can('create', TrainingRequirement::class),
        ]);
    }

    /**
     * The matrix.
     *
     * Capped at 200 people per page for the same reason every other listing is capped: this is a
     * screen, not an export, and a company-wide matrix rendered in one go is a page nobody waits for.
     */
    public function matrix(Request $request): View
    {
        $this->authorize('viewAny', TrainingRequirement::class);
        $user = $request->user();

        $employees = $this->compiler
            ->apply(Employee::query()->active(), $user, 'employee.view')
            ->when(($unitId = (int) $request->query('unit', 0)) > 0, fn ($q) => $q->where('org_unit_id', $unitId))
            ->with(['position', 'orgUnit'])
            ->orderBy('last_name')->orderBy('first_name')
            ->paginate(50)->withQueryString();

        $requirements = $this->requirements->activeRequirements();

        return view('training-requirements.matrix', [
            'employees' => $employees,
            'requirements' => $requirements,
            'rows' => $this->requirements->matrix($employees->getCollection(), $requirements),
            'units' => $this->unitOptions($user),
            'filters' => ['unit' => $unitId],
        ]);
    }

    /**
     * "My training" — what the signed-in person is required to hold, and what they hold.
     *
     * No permission check beyond being signed in, because the answer is about themselves. There is
     * nothing here they could not read on their own personnel page.
     */
    public function mine(Request $request): View
    {
        $user = $request->user();
        $employee = Employee::query()->where('user_id', $user->getKey())->with(['position', 'orgUnit'])->first();

        return view('training-requirements.mine', [
            'employee' => $employee,
            'cells' => $employee === null
                ? []
                : $this->requirements->forEmployee($employee, $this->requirements->activeRequirements()),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', TrainingRequirement::class);

        return view('training-requirements.create', [
            'requirement' => new TrainingRequirement(['scope' => TrainingRequirement::SCOPE_POSITION, 'is_mandatory' => true, 'is_active' => true]),
            ...$this->formOptions($request->user()),
        ]);
    }

    public function store(TrainingRequirementRequest $request): RedirectResponse
    {
        $this->authorize('create', TrainingRequirement::class);

        try {
            $this->requirements->create($request->validated(), $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['training_course_id' => $e->getMessage()]);
        }

        return redirect()->route('training-requirements.index')->with('status', 'The requirement has been added.');
    }

    public function edit(Request $request, TrainingRequirement $trainingRequirement): View
    {
        $this->authorize('update', $trainingRequirement);

        return view('training-requirements.edit', [
            'requirement' => $trainingRequirement,
            ...$this->formOptions($request->user()),
        ]);
    }

    public function update(TrainingRequirementRequest $request, TrainingRequirement $trainingRequirement): RedirectResponse
    {
        $this->authorize('update', $trainingRequirement);

        try {
            $this->requirements->update($trainingRequirement, $request->validated(), $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['training_course_id' => $e->getMessage()]);
        }

        return redirect()->route('training-requirements.index')->with('status', 'The requirement has been updated.');
    }

    public function destroy(Request $request, TrainingRequirement $trainingRequirement): RedirectResponse
    {
        $this->authorize('delete', $trainingRequirement);
        $this->requirements->delete($trainingRequirement, $request->user());

        return redirect()->route('training-requirements.index')->with('status', 'The requirement has been removed.');
    }

    // ------------------------------------------------------------------ helpers

    /** @return array<string,mixed> */
    private function formOptions(User $user): array
    {
        return [
            'courses' => $this->courseOptions($user),
            'units' => $this->unitOptions($user),
            'positions' => $this->positionOptions($user),
            'scopes' => [
                TrainingRequirement::SCOPE_POSITION => 'A position',
                TrainingRequirement::SCOPE_ORG_UNIT => 'A department',
                TrainingRequirement::SCOPE_ORGANIZATION => 'Everybody',
            ],
        ];
    }

    /**
     * Positions this actor can reach. Empty for somebody without `position.view`, which is honest:
     * they cannot choose a target they are not allowed to see the name of.
     *
     * @return array<int|string,string>
     */
    private function positionOptions(User $user): array
    {
        if (! $user->hasPermission('position.view')) {
            return [];
        }

        return $this->compiler
            ->apply(Position::query()->where('is_active', true), $user, 'position.view')
            ->orderBy('title')->pluck('title', 'id')->all();
    }

    /** @return array<int|string,string> */
    private function courseOptions(User $user): array
    {
        return $this->compiler
            ->apply(TrainingCourse::query()->active(), $user, 'hse_training.view')
            ->orderBy('name')->limit(500)->get()
            ->mapWithKeys(fn (TrainingCourse $c) => [$c->id => $c->name])
            ->all();
    }
}
