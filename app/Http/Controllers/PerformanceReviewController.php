<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\PerformanceReviewAnswerRequest;
use App\Http\Requests\PerformanceReviewRequest;
use App\Models\PerformanceReview;
use App\Services\PerformanceReviewService;
use App\Support\OptionList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * One person's appraisal, from their own half to sign-off.
 *
 * THERE IS NO APPROVE ACTION ON THIS CONTROLLER. Look for it — a review is decided at
 * `approvals.decide` by the workflow engine, the same place a leave request and a timesheet are
 * decided. That absence is Phase 6's exit criterion made visible in the route table.
 *
 * "MINE" AND "TO REVIEW" ARE THE TWO SCREENS PEOPLE ACTUALLY OPEN. The register index exists for HR;
 * everybody else arrives from a link that says either "your self-assessment is due" or "three
 * reviews are waiting for you". Both are ordinary scoped queries — `own` and `assigned` — not
 * special cases bolted onto the index.
 *
 * EVERY WRITE GOES THROUGH A NAMED ACT with its own policy check: `selfAssess`, `reviewAsManager`,
 * `submitForSignOff`, `acknowledge`. None of them is `update`, which belongs to HR and changes only
 * who reviews it (§84).
 */
class PerformanceReviewController extends RegisterController
{
    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly PerformanceReviewService $reviews,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'performance_review';
    }

    protected function modelClass(): string
    {
        return PerformanceReview::class;
    }

    protected function routePrefix(): string
    {
        return 'performance-reviews';
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', PerformanceReview::class);
        $user = $request->user();

        $query = $this->scopedQuery($user, 'performance.view')->with(['status', 'employee', 'reviewer', 'cycle']);
        $this->applyCommonFilters($query, $request);
        $filters = $this->commonFilters($request, 'open');

        if ($filters['view'] === 'open') {
            $query->whereHas('status', fn ($q) => $q->whereNotIn('key', ['completed', 'rejected', 'cancelled']));
        }

        if (($cycleId = (int) $request->query('cycle', 0)) > 0) {
            $query->where('review_cycle_id', $cycleId);
        }

        return view('performance-reviews.index', [
            'reviews' => $query->orderByDesc('id')->paginate(30)->withQueryString(),
            'statuses' => $this->statuses->optionsFor('performance_review'),
            'filters' => [...$filters, 'cycle' => $cycleId],
        ]);
    }

    /**
     * The reviews this person is the subject of, and the ones they have to write.
     *
     * Gated on `performance.view` like everything else, rather than waved through because the rows
     * are the reader's own. A company that has not granted the module has said it does not run
     * appraisals in the platform, and this page showing up anyway would be the one screen in the
     * system that answered to a different rule (§6).
     */
    public function mine(Request $request): View
    {
        $user = $request->user();
        $this->authorize('viewAny', PerformanceReview::class);

        return view('performance-reviews.mine', [
            'mine' => PerformanceReview::query()
                ->where('user_id', $user->getKey())
                ->with(['status', 'cycle', 'reviewer'])
                ->orderByDesc('id')->get(),
            'toReview' => PerformanceReview::query()
                ->where('reviewer_id', $user->getKey())
                ->whereHas('status', fn ($q) => $q->whereNotIn('key', ['completed', 'rejected', 'cancelled']))
                ->with(['status', 'cycle', 'employee'])
                ->orderByDesc('id')->get(),
        ]);
    }

    public function show(Request $request, PerformanceReview $performanceReview): View
    {
        $this->authorize('view', $performanceReview);
        $user = $request->user();
        $performanceReview->load(['status', 'cycle', 'employee.position', 'reviewer', 'subject', 'items']);

        $instance = $performanceReview->workflowInstances()
            ->with(['stageInstances.stage', 'stageInstances.approvals.user'])
            ->latest('id')
            ->first();

        return view('performance-reviews.show', [
            'review' => $performanceReview,
            'instance' => $instance,
            'timeline' => $this->activity->timelineFor($performanceReview)->limit(25)->get(),
            'canEdit' => $user->can('update', $performanceReview),
            'canRelease' => $user->can('release', $performanceReview),
            'canSelfAssess' => $user->can('selfAssess', $performanceReview),
            'canReview' => $user->can('reviewAsManager', $performanceReview),
            'canSubmit' => $user->can('submitForSignOff', $performanceReview),
            'canAcknowledge' => $user->can('acknowledge', $performanceReview),
            'canCancel' => $user->can('cancel', $performanceReview),
        ]);
    }

    public function edit(Request $request, PerformanceReview $performanceReview): View
    {
        $this->authorize('update', $performanceReview);

        return view('performance-reviews.edit', [
            'review' => $performanceReview,
            'users' => $this->userOptions($request->user()),
            'units' => $this->unitOptions($request->user()),
        ]);
    }

    public function update(PerformanceReviewRequest $request, PerformanceReview $performanceReview): RedirectResponse
    {
        $this->authorize('update', $performanceReview);

        try {
            $this->reviews->update($performanceReview, $request->validated(), $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['reviewer_id' => $e->getMessage()]);
        }

        return redirect()->route('performance-reviews.show', $performanceReview)->with('status', 'The review has been updated.');
    }

    public function destroy(Request $request, PerformanceReview $performanceReview): RedirectResponse
    {
        $this->authorize('delete', $performanceReview);
        $this->recordDeletion($performanceReview, $request->user(), 'review');
        $performanceReview->delete();

        return redirect()->route('performance-reviews.index')->with('status', 'The review has been deleted.');
    }

    // ------------------------------------------------------------------ the conversation

    public function release(Request $request, PerformanceReview $performanceReview): RedirectResponse
    {
        $this->authorize('release', $performanceReview);

        try {
            $this->reviews->release($performanceReview, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['review' => $e->getMessage()]);
        }

        return back()->with('status', 'The review has been sent to '.$performanceReview->subjectName().'.');
    }

    public function saveSelf(PerformanceReviewAnswerRequest $request, PerformanceReview $performanceReview): RedirectResponse
    {
        $this->authorize('selfAssess', $performanceReview);
        $data = $request->validated();

        try {
            $this->reviews->saveSelfAssessment(
                $performanceReview,
                $data['items'] ?? [],
                $data['self_comments'] ?? null,
                $request->user(),
            );
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['items' => $e->getMessage()]);
        }

        // Saving and sending are separate buttons on one form: a self-assessment is written over
        // several sittings, and a form that could only be submitted would be filled in one panic.
        if ($request->boolean('send')) {
            try {
                $this->reviews->submitSelfAssessment($performanceReview, $request->user());
            } catch (\DomainException $e) {
                return back()->withErrors(['items' => $e->getMessage()]);
            }

            return back()->with('status', 'Your self-assessment has gone to your manager.');
        }

        return back()->with('status', 'Your answers have been saved.');
    }

    public function saveManager(PerformanceReviewAnswerRequest $request, PerformanceReview $performanceReview): RedirectResponse
    {
        $this->authorize('reviewAsManager', $performanceReview);
        $data = $request->validated();

        try {
            $this->reviews->saveManagerReview($performanceReview, $data['items'] ?? [], $data, $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['items' => $e->getMessage()]);
        }

        if ($request->boolean('send')) {
            $this->authorize('submitForSignOff', $performanceReview);

            try {
                $this->reviews->submitForSignOff($performanceReview, $request->user());
            } catch (\DomainException $e) {
                return back()->withErrors(['items' => $e->getMessage()]);
            }

            return back()->with('status', 'The review has been sent for sign-off.');
        }

        return back()->with('status', 'Your answers have been saved.');
    }

    public function acknowledge(Request $request, PerformanceReview $performanceReview): RedirectResponse
    {
        $this->authorize('acknowledge', $performanceReview);
        $data = $request->validate(['acknowledgement_comments' => ['nullable', 'string', 'max:5000']]);

        try {
            $this->reviews->acknowledge($performanceReview, $data['acknowledgement_comments'] ?? null, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['acknowledgement_comments' => $e->getMessage()]);
        }

        return back()->with('status', 'Thank you — your acknowledgement has been recorded.');
    }

    public function cancel(Request $request, PerformanceReview $performanceReview): RedirectResponse
    {
        $this->authorize('cancel', $performanceReview);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'];

        try {
            $this->reviews->cancel($performanceReview, $reason, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', 'The review has been cancelled.');
    }
}
