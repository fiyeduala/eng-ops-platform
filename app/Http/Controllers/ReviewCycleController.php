<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\ReviewCycleRequest;
use App\Models\ReviewCycle;
use App\Services\ReviewCycleService;
use App\Support\OptionList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Appraisal rounds.
 *
 * OPENING A ROUND IS THE BIG BUTTON ON THIS SCREEN and it is deliberately hard to press by accident:
 * it raises a review for every active person it covers and tells every manager they have work to do.
 * It reports back how many were opened and how many people it could not open one for, because a
 * round that silently skipped the forty people with no supervisor recorded is a round HR discovers
 * is wrong in November.
 *
 * THE DEPARTMENT FILTER IS A SCOPE, NOT A CONVENIENCE. A round opened for two departments opens
 * reviews for those departments only, which is how a company runs engineering's appraisals in June
 * and everybody else's in September.
 */
class ReviewCycleController extends RegisterController
{
    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly ReviewCycleService $cycles,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'review_cycle';
    }

    protected function modelClass(): string
    {
        return ReviewCycle::class;
    }

    protected function routePrefix(): string
    {
        return 'review-cycles';
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', ReviewCycle::class);
        $user = $request->user();

        $query = $this->scopedQuery($user, 'performance.view')->with(['status'])->withCount(['reviews', 'competencies']);
        $filters = $this->commonFilters($request, 'open');

        // Not `applyCommonFilters()`: a cycle is not a project record and has no discipline, so the
        // register-wide filter set would compile columns this table does not have the moment somebody
        // types `?project=3` onto the URL (§84). A cycle is found by its name and its status.
        $query->search((string) $request->query('q', ''));

        if ($statusId = (int) $request->query('status', 0)) {
            $query->where('status_id', $statusId);
        }

        if ($filters['view'] === 'open') {
            $query->whereHas('status', fn ($q) => $q->whereIn('key', ['draft', 'open']));
        }

        return view('review-cycles.index', [
            'cycles' => $query->orderByDesc('period_end')->paginate(25)->withQueryString(),
            'statuses' => $this->statuses->optionsFor('review_cycle'),
            'filters' => $filters,
            'canCreate' => $user->can('create', ReviewCycle::class),
        ]);
    }

    public function show(Request $request, ReviewCycle $reviewCycle): View
    {
        $this->authorize('view', $reviewCycle);
        $user = $request->user();
        $reviewCycle->load(['status', 'competencies.orgUnit', 'competencies.position']);

        return view('review-cycles.show', [
            'cycle' => $reviewCycle,
            // The reviews raised in this round, through the compiler — a cycle page must not become
            // a way to read everybody's appraisal (§61). Most people see none, and that is correct.
            'reviews' => $this->compiler
                ->apply($reviewCycle->reviews()->getQuery(), $user, 'performance.view')
                ->with(['status', 'employee', 'reviewer'])
                ->orderBy('number')->paginate(25),
            'timeline' => $this->activity->timelineFor($reviewCycle)->limit(25)->get(),
            'canEdit' => $user->can('update', $reviewCycle),
            'canDelete' => $user->can('delete', $reviewCycle),
            'canOpen' => $user->can('open', $reviewCycle),
            'canClose' => $user->can('close', $reviewCycle),
            'canCancel' => $user->can('cancel', $reviewCycle),
            'units' => $this->unitOptions($user),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', ReviewCycle::class);

        return view('review-cycles.create', [
            'cycle' => new ReviewCycle([
                'period_start' => now()->startOfYear()->toDateString(),
                'period_end' => now()->endOfYear()->toDateString(),
                'rating_min' => 1,
                'rating_max' => 5,
            ]),
            ...$this->formOptions($request),
        ]);
    }

    public function store(ReviewCycleRequest $request): RedirectResponse
    {
        $this->authorize('create', ReviewCycle::class);

        try {
            $cycle = $this->cycles->create($request->validated(), $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['name' => $e->getMessage()]);
        }

        return redirect()->route('review-cycles.show', $cycle)->with('status', $cycle->name.' has been created.');
    }

    public function edit(Request $request, ReviewCycle $reviewCycle): View
    {
        $this->authorize('update', $reviewCycle);
        $reviewCycle->load('competencies');

        return view('review-cycles.edit', [
            'cycle' => $reviewCycle,
            ...$this->formOptions($request),
        ]);
    }

    public function update(ReviewCycleRequest $request, ReviewCycle $reviewCycle): RedirectResponse
    {
        $this->authorize('update', $reviewCycle);

        try {
            $this->cycles->update($reviewCycle, $request->validated(), $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['name' => $e->getMessage()]);
        }

        return redirect()->route('review-cycles.show', $reviewCycle)->with('status', $reviewCycle->name.' has been updated.');
    }

    public function destroy(Request $request, ReviewCycle $reviewCycle): RedirectResponse
    {
        $this->authorize('delete', $reviewCycle);
        $this->recordDeletion($reviewCycle, $request->user(), 'review cycle');
        $reviewCycle->delete();

        return redirect()->route('review-cycles.index')->with('status', 'The cycle has been deleted.');
    }

    // ------------------------------------------------------------------ the round

    public function open(Request $request, ReviewCycle $reviewCycle): RedirectResponse
    {
        $this->authorize('open', $reviewCycle);

        $data = $request->validate([
            'org_units' => ['nullable', 'array'],
            'org_units.*' => ['integer'],
        ]);

        try {
            $opened = $this->cycles->open($reviewCycle, $request->user(), $data['org_units'] ?? null);
        } catch (\DomainException $e) {
            return back()->withErrors(['cycle' => $e->getMessage()]);
        }

        return back()->with('status', $opened === 0
            ? 'No new reviews were opened — everybody covered by this cycle already has one.'
            : $opened.' review'.($opened === 1 ? ' was' : 's were').' opened.');
    }

    public function close(Request $request, ReviewCycle $reviewCycle): RedirectResponse
    {
        $this->authorize('close', $reviewCycle);

        try {
            $this->cycles->close($reviewCycle, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['cycle' => $e->getMessage()]);
        }

        return back()->with('status', 'The cycle has been closed.');
    }

    public function cancel(Request $request, ReviewCycle $reviewCycle): RedirectResponse
    {
        $this->authorize('cancel', $reviewCycle);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'];

        try {
            $this->cycles->cancel($reviewCycle, $reason, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', 'The cycle has been cancelled.');
    }

    /** @return array<string,mixed> */
    private function formOptions(Request $request): array
    {
        return [
            'units' => $this->unitOptions($request->user()),
            'kinds' => ['competency' => 'Competency', 'objective' => 'Objective', 'value' => 'Value'],
        ];
    }
}
