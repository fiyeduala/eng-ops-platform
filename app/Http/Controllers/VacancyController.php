<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\VacancyRequest;
use App\Models\Applicant;
use App\Models\Location;
use App\Models\Position;
use App\Models\User;
use App\Models\Vacancy;
use App\Models\WorkflowStageInstance;
use App\Services\VacancyService;
use App\Support\OptionList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Asking for a head, and what happened to it.
 *
 * THERE IS NO APPROVE ACTION ON THIS CONTROLLER AND NO APPROVE ROUTE TO IT. A vacancy is decided in
 * My Approvals, at `approvals.decide`, by the same engine that decides a document, a leave request
 * and a timesheet — that is Phase 6's exit criterion, and an `approve()` here would quietly satisfy
 * the screen while breaking the claim (§84, §90).
 *
 * `submit` HANDS THE RECORD TO THE ENGINE and nothing else. Opening the vacancy for applications is
 * the engine's callback into VacancyService::markApproved(), so a recruiter who can reach this
 * controller still cannot open a head nobody agreed to.
 */
class VacancyController extends RegisterController
{
    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly VacancyService $vacancies,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'vacancy';
    }

    protected function modelClass(): string
    {
        return Vacancy::class;
    }

    protected function routePrefix(): string
    {
        return 'vacancies';
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Vacancy::class);
        $user = $request->user();
        $query = $this->scopedQuery($user, 'recruitment.view')
            ->with(['status', 'orgUnit', 'position', 'project', 'hiringManager'])
            ->withCount('applicants');
        $this->applyCommonFilters($query, $request);
        $filters = $this->commonFilters($request, 'open');

        match ($filters['view']) {
            'mine' => $query->where(fn ($q) => $q->where('requester_id', $user->id)->orWhere('hiring_manager_id', $user->id)),
            'open' => $query->statusKey('open'),
            'closed' => $query->whereNotNull('closed_at'),
            default => null,
        };

        return view('vacancies.index', [
            'vacancies' => $query->orderByDesc('id')->paginate(30)->withQueryString(),
            'statuses' => $this->statuses->optionsFor('vacancy'),
            'filters' => $filters,
            'canCreate' => $user->can('create', Vacancy::class),
        ]);
    }

    public function show(Request $request, Vacancy $vacancy): View
    {
        $this->authorize('view', $vacancy);
        $user = $request->user();
        $vacancy->load(['status', 'orgUnit', 'position', 'location', 'project', 'requester', 'hiringManager', 'creator']);

        $instance = $vacancy->workflowInstances()
            ->with(['stageInstances.stage', 'stageInstances.approvals.user'])
            ->latest('id')
            ->first();

        // The applicant list is narrowed by the compiler in its own right, not merely by the
        // vacancy being visible: sight of a requisition is not sight of the people who applied
        // to it, and their names, contact details and CVs are restricted by default (§68).
        $applicants = $this->compiler
            ->apply(Applicant::query(), $user, 'recruitment.view')
            ->forVacancy($vacancy->id)
            ->with('status')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return view('vacancies.show', [
            'vacancy' => $vacancy,
            'instance' => $instance,
            'applicants' => $applicants,
            'myApproval' => $instance?->stageInstances
                ->where('status', WorkflowStageInstance::STATUS_PENDING)
                ->first(fn (WorkflowStageInstance $stage) => $user->can('act', $stage)),
            'timeline' => $this->activity->timelineFor($vacancy)->limit(25)->get(),
            'canEdit' => $user->can('update', $vacancy),
            'canDelete' => $user->can('delete', $vacancy),
            'canSubmit' => $user->can('submit', $vacancy),
            'canClose' => $user->can('close', $vacancy),
            'canAddApplicant' => $vacancy->isOpen() && $user->can('create', Applicant::class),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Vacancy::class);

        return view('vacancies.create', [
            'vacancy' => new Vacancy(['openings' => 1]),
            ...$this->formOptions($request->user()),
        ]);
    }

    public function store(VacancyRequest $request): RedirectResponse
    {
        $this->authorize('create', Vacancy::class);
        $data = $request->validated();
        $this->assertPlacementIsReachable($data);

        try {
            $vacancy = $this->vacancies->create($data, $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['title' => $e->getMessage()]);
        }

        return redirect()->route('vacancies.show', $vacancy)
            ->with('status', $vacancy->number.' has been saved as a draft.');
    }

    public function edit(Request $request, Vacancy $vacancy): View
    {
        $this->authorize('update', $vacancy);

        return view('vacancies.edit', [
            'vacancy' => $vacancy,
            ...$this->formOptions($request->user()),
        ]);
    }

    public function update(VacancyRequest $request, Vacancy $vacancy): RedirectResponse
    {
        $this->authorize('update', $vacancy);
        $data = $request->validated();
        $this->assertPlacementIsReachable($data);

        try {
            $this->vacancies->update($vacancy, $data, $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['title' => $e->getMessage()]);
        }

        return redirect()->route('vacancies.show', $vacancy)
            ->with('status', $vacancy->number.' has been updated.');
    }

    public function destroy(Request $request, Vacancy $vacancy): RedirectResponse
    {
        $this->authorize('delete', $vacancy);
        $this->recordDeletion($vacancy, $request->user(), 'vacancy');
        $vacancy->delete();

        return redirect()->route('vacancies.index')->with('status', 'The draft vacancy has been deleted.');
    }

    public function submit(Request $request, Vacancy $vacancy): RedirectResponse
    {
        $this->authorize('submit', $vacancy);

        try {
            $this->vacancies->submit($vacancy, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['status_id' => $e->getMessage()]);
        }

        return back()->with('status', 'The vacancy has been sent for approval.');
    }

    public function close(Request $request, Vacancy $vacancy): RedirectResponse
    {
        $this->authorize('close', $vacancy);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];

        try {
            $this->vacancies->close($vacancy, $request->user(), $reason);
        } catch (\DomainException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', 'The vacancy has been closed.');
    }

    // ------------------------------------------------------------------ helpers

    /** @return array<string,mixed> */
    private function formOptions(User $user): array
    {
        return [
            ...$this->placementOptions($user),
            'positions' => $user->hasPermission('position.view')
                ? $this->compiler
                    ->apply(Position::query()->where('is_active', true), $user, 'position.view')
                    ->orderBy('title')->limit(500)->pluck('title', 'id')->all()
                : [],
            'locations' => Location::query()->where('is_active', true)
                ->orderBy('name')->pluck('name', 'id')->all(),
            'employmentTypes' => $this->lists->for('vacancy.employment_types', [
                'permanent', 'fixed_term', 'contract', 'part_time', 'internship',
            ]),
        ];
    }
}
