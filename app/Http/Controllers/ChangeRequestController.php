<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\ChangeRequestRequest;
use App\Models\ChangeRequest;
use App\Services\ChangeRequestService;
use App\Support\Enums\ChangeDisposition;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Engineering change requests — the design changed, here is what it costs.
 *
 * DISPOSITION IS SEPARATE FROM STATUS, and keeping them apart is the point of the record. Status is
 * where the request has got to in this company's own process; disposition is the answer. A request
 * can be closed (status) as rejected (disposition), and reporting needs both: "how many changes did
 * we approve" is a different question from "how many are still open", and a single column cannot
 * answer either without lying about the other.
 *
 * NOBODY DECIDES THEIR OWN CHANGE REQUEST. ChangeRequestPolicy::decide refuses when the actor is
 * the author — checking both `created_by` and `raised_by`, because a document controller logging a
 * change on an engineer's behalf makes both people authors in different senses. This is the only
 * separation-of-duties rule in the engineering registers and it is here because this is the record
 * that moves money.
 *
 * A DECISION CLOSES THE RECORD TO EDITING. Once `decided_at` is set the policy refuses update and
 * delete: an approved change request is the basis of a variation order, and a register that let it
 * be quietly reworded afterwards would be worthless in the claim it exists for (§59).
 *
 * A REJECTION OR A DEFERRAL REQUIRES NOTES. The service enforces it, not this controller, because
 * an unexplained "no" is the single most expensive thing this register can record — six months
 * later nobody can say why, and the same change comes back.
 */
class ChangeRequestController extends RegisterController
{
    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly ChangeRequestService $changes,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'change_request';
    }

    protected function modelClass(): string
    {
        return ChangeRequest::class;
    }

    protected function routePrefix(): string
    {
        return 'change-requests';
    }

    // ------------------------------------------------------------------ register

    public function index(Request $request): View
    {
        $this->authorize('viewAny', ChangeRequest::class);

        $user = $request->user();

        $query = $this->scopedQuery($user)->with([
            'status:id,label,color,is_terminal',
            'project:id,name,number',
            'discipline:id,name',
            'raiser:id,name',
            'decider:id,name',
        ]);

        $this->applyCommonFilters($query, $request);

        if ($origin = (string) $request->query('origin', '')) {
            $query->where('origin', $origin);
        }

        $view = (string) $request->query('view', 'undecided');

        match ($view) {
            'approved' => $query->approved(),
            'decided' => $query->whereNotNull('decided_at'),
            'overdue' => $query->undecided()->whereNotNull('required_by')
                ->whereDate('required_by', '<', now()->startOfDay()),
            'mine' => $query->where(fn ($q) => $q->where('raised_by', $user->id)
                ->orWhere('created_by', $user->id)),
            'all' => $query,
            default => $query->undecided(),
        };

        /** @var LengthAwarePaginator $changeRequests */
        $changeRequests = $query
            ->orderByRaw('CASE WHEN required_by IS NULL THEN 1 ELSE 0 END')
            ->orderBy('required_by')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('change-requests.index', [
            'changeRequests' => $changeRequests,
            'statuses' => $this->statuses->optionsFor('change_request'),
            'projects' => $this->projectOptions($user),
            'origins' => ChangeRequest::ORIGINS,
            'views' => [
                'undecided' => 'Awaiting a decision',
                'overdue' => 'Late',
                'approved' => 'Approved',
                'decided' => 'Decided',
                'mine' => 'Mine',
                'all' => 'Everything you can see',
            ],
            'filters' => [...$this->commonFilters($request, 'undecided'), 'origin' => $request->query('origin')],
            'canCreate' => $user->can('create', ChangeRequest::class),
        ]);
    }

    public function show(Request $request, ChangeRequest $changeRequest): View
    {
        $this->authorize('view', $changeRequest);

        $user = $request->user();

        $changeRequest->loadMissing([
            'status', 'project', 'discipline', 'orgUnit',
            'raiser:id,name', 'decider:id,name', 'creator:id,name',
        ]);

        return view('change-requests.show', [
            'changeRequest' => $changeRequest,
            'timeline' => $this->activity->timelineFor($changeRequest)->limit(25)->get(),
            'transitions' => $this->statuses->available('change_request', $changeRequest->status_id, $user),
            'dispositions' => ChangeDisposition::options(),
            'workflowInstance' => $changeRequest->currentWorkflowInstance(),
            'canEdit' => $user->can('update', $changeRequest),
            'canDelete' => $user->can('delete', $changeRequest),
            'canSubmit' => $user->can('submit', $changeRequest),
            'canDecide' => $user->can('decide', $changeRequest),
            // Closing is `review`, not `edit` and not `approve`. Filing a decided request away is
            // an administrative act that the person who made the decision should not have to
            // perform, and that somebody who may only edit the text should not be able to.
            'canClose' => $user->can('close', $changeRequest),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', ChangeRequest::class);

        $user = $request->user();

        return view('change-requests.create', [
            'changeRequest' => new ChangeRequest([
                'confidentiality' => Confidentiality::Internal->value,
                'project_id' => (int) $request->query('project', 0) ?: null,
                'origin' => 'internal',
                'raised_by' => $user->id,
                'raised_on' => now()->toDateString(),
            ]),
            ...$this->formOptions($request),
        ]);
    }

    public function store(ChangeRequestRequest $request): RedirectResponse
    {
        $this->authorize('create', ChangeRequest::class);

        $data = $request->changeRequestAttributes();

        $this->assertPlacementIsReachable($data);

        $changeRequest = $this->changes->create($data, $request->user());

        return redirect($this->showRoute($changeRequest))
            ->with('status', $changeRequest->displayName().' has been raised.');
    }

    public function edit(Request $request, ChangeRequest $changeRequest): View
    {
        $this->authorize('update', $changeRequest);

        return view('change-requests.edit', [
            'changeRequest' => $changeRequest,
            ...$this->formOptions($request),
        ]);
    }

    public function update(ChangeRequestRequest $request, ChangeRequest $changeRequest): RedirectResponse
    {
        $this->authorize('update', $changeRequest);

        $data = $request->changeRequestAttributes();

        $this->assertPlacementIsReachable($data);

        $this->changes->update($changeRequest, $data, $request->user());

        return redirect($this->showRoute($changeRequest))
            ->with('status', $changeRequest->displayName().' has been updated.');
    }

    public function destroy(Request $request, ChangeRequest $changeRequest): RedirectResponse
    {
        $this->authorize('delete', $changeRequest);

        $name = $changeRequest->displayName();

        $this->recordDeletion($changeRequest, $request->user(), 'change request');

        $changeRequest->delete();

        return redirect($this->indexRoute())->with('status', $name.' has been deleted.');
    }

    // ------------------------------------------------------------------ lifecycle

    public function submit(Request $request, ChangeRequest $changeRequest): RedirectResponse
    {
        $this->authorize('submit', $changeRequest);

        try {
            $this->changes->submit($changeRequest, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['submit' => $e->getMessage()]);
        }

        return back()->with('status', $changeRequest->displayName().' has been submitted for decision.');
    }

    /**
     * Approve, reject, defer or withdraw it.
     *
     * The policy has already refused a self-decision and a second decision. What this method adds
     * is the impact figures, which are frequently only known at the moment somebody decides — the
     * estimator prices the change as part of deciding it — so they are accepted here as well as on
     * the form. They are still refused by the service's protected list when they arrive through an
     * ordinary edit after the decision (§84).
     */
    public function decide(Request $request, ChangeRequest $changeRequest): RedirectResponse
    {
        $this->authorize('decide', $changeRequest);

        $validated = $request->validate([
            'disposition' => ['required', Rule::enum(ChangeDisposition::class)],
            'decision_notes' => ['nullable', 'string', 'max:5000'],
            'cost_impact' => ['nullable', 'numeric', 'between:-999999999999.99,999999999999.99'],
            'schedule_impact_days' => ['nullable', 'integer', 'between:-3650,3650'],
        ]);

        try {
            $this->changes->decide(
                $changeRequest,
                ChangeDisposition::from($validated['disposition']),
                $request->user(),
                $validated['decision_notes'] ?? null,
                $validated['cost_impact'] ?? null,
                $validated['schedule_impact_days'] ?? null,
            );
        } catch (\DomainException $e) {
            return back()->withErrors(['disposition' => $e->getMessage()]);
        }

        return back()->with('status', $changeRequest->displayName().' has been '
            .strtolower($changeRequest->disposition?->label() ?? 'decided').'.');
    }

    public function close(Request $request, ChangeRequest $changeRequest): RedirectResponse
    {
        // `close`, not `review`. Both run on `change_request.review`, but the policy's `review`
        // refuses a decided request — correctly, since there is nothing left to assess — and a
        // decided request is the only kind that can be closed.
        $this->authorize('close', $changeRequest);

        try {
            $this->changes->close($changeRequest, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['close' => $e->getMessage()]);
        }

        return back()->with('status', $changeRequest->displayName().' has been closed.');
    }

    public function transition(Request $request, ChangeRequest $changeRequest): RedirectResponse
    {
        return $this->moveStatus($request, $changeRequest);
    }

    // ------------------------------------------------------------------ options

    /** @return array<string,mixed> */
    private function formOptions(Request $request): array
    {
        return [
            ...$this->placementOptions($request->user()),
            'origins' => ChangeRequest::ORIGINS,
            'nextNumber' => $this->numbers->preview('change_request'),
        ];
    }
}
