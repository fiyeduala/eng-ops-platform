<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\NcrRequest;
use App\Models\CorrectiveAction;
use App\Models\Ncr;
use App\Models\QualityInspection;
use App\Services\NcrService;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * It does not meet the specification — and here is the clause that says so.
 *
 * FIVE ACTS, FIVE ENDPOINTS, AND THEY BELONG TO DIFFERENT PEOPLE. Issue sends the finding to the
 * party responsible; respond is that party's account of it; disposition is the engineering decision
 * about what happens to the work; verify confirms the decision was actually carried out; close files
 * it. Collapsing any pair of them into one button would put two signatures on one act, and this is
 * the register that moves money.
 *
 * NOBODY DISPOSITIONS THEIR OWN. Refused at any scope by the policy and again by the service. The
 * person who raised the finding deciding it can be left as it is would make the register
 * self-cancelling — and "use as is" is exactly the disposition somebody will be asked to justify
 * years later while holding a failed component.
 *
 * RESPONDING IS NOT DISPOSITIONING. The author may legitimately hold `ncr.review` and answer the
 * finding; `ncr.approve` is the one they may not exercise on their own record.
 *
 * A DISPOSITIONED NCR IS FROZEN. The requirement, the finding and the cost impact are what the
 * decision was made on. If the finding was wrong, that is a new NCR or a withdrawal — both of which
 * leave the original readable, which an edit would not (§59).
 *
 * THE DISPOSITION IS NOT THE STATUS. `disposition` records what was decided about the work; the
 * status records where the paperwork has got to. A company that wants "use as is" to route
 * differently from "rework" configures that in the workflow, not in this file (§32).
 */
class NcrController extends RegisterController
{
    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly NcrService $ncrs,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'ncr';
    }

    protected function modelClass(): string
    {
        return Ncr::class;
    }

    protected function routePrefix(): string
    {
        return 'ncrs';
    }

    // ------------------------------------------------------------------ register

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Ncr::class);

        $user = $request->user();

        $query = $this->scopedQuery($user)->with([
            'status:id,label,color,is_terminal',
            'project:id,name,number',
            'discipline:id,name',
            'detector:id,name', 'dispositioner:id,name',
            'qualityInspection:id,number,title',
        ]);

        $this->applyCommonFilters($query, $request);

        foreach (['severity', 'category', 'source'] as $column) {
            if ($value = (string) $request->query($column, '')) {
                $query->where($column, $value);
            }
        }

        $view = (string) $request->query('view', 'open');

        match ($view) {
            'awaiting_response' => $query->awaitingResponse(),
            'undispositioned' => $query->open()->undispositioned(),
            'mine' => $query->where(fn ($q) => $q->where('detected_by', $user->id)
                ->orWhere('created_by', $user->id)),
            'all' => $query,
            default => $query->open(),
        };

        /** @var LengthAwarePaginator $ncrs */
        $ncrs = $query
            ->orderByRaw('CASE WHEN required_by IS NULL THEN 1 ELSE 0 END')
            ->orderBy('required_by')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('ncrs.index', [
            'ncrs' => $ncrs,
            'statuses' => $this->statuses->optionsFor('ncr'),
            'projects' => $this->projectOptions($user),
            'severities' => $this->lists->for('ncr.severities', Ncr::DEFAULT_SEVERITIES),
            'categories' => $this->lists->for('ncr.categories', Ncr::DEFAULT_CATEGORIES),
            'sources' => $this->lists->for('ncr.sources', Ncr::DEFAULT_SOURCES),
            'views' => [
                'open' => 'Open',
                'awaiting_response' => 'Awaiting a response',
                'undispositioned' => 'Awaiting a disposition',
                'mine' => 'Raised by me',
                'all' => 'Everything you can see',
            ],
            'filters' => [
                ...$this->commonFilters($request),
                'severity' => $request->query('severity'),
                'category' => $request->query('category'),
                'source' => $request->query('source'),
            ],
            'canCreate' => $user->can('create', Ncr::class),
        ]);
    }

    public function show(Request $request, Ncr $ncr): View
    {
        $this->authorize('view', $ncr);

        $user = $request->user();

        $ncr->loadMissing([
            'status', 'project', 'discipline', 'orgUnit',
            'detector:id,name', 'dispositioner:id,name', 'verifier:id,name', 'closer:id,name',
            'creator:id,name', 'qualityInspection:id,number,title',
            'correctiveActions.status', 'correctiveActions.assignee:id,name',
        ]);

        // The inspection that produced the finding is linked only when the reader can actually open
        // it. An NCR is frequently visible to a subcontractor's engineer who has no reach into the
        // client's inspection register, and a link that leads to a 403 tells them it exists (§68).
        $inspection = $ncr->qualityInspection;

        return view('ncrs.show', [
            'ncr' => $ncr,
            'timeline' => $this->activity->timelineFor($ncr)->limit(25)->get(),
            'transitions' => $this->statuses->available('ncr', $ncr->status_id, $user),
            'workflowInstance' => $ncr->currentWorkflowInstance(),
            'dispositions' => $this->lists->for('ncr.dispositions', Ncr::DEFAULT_DISPOSITIONS),
            'dispositionsNeedingNotes' => Ncr::DISPOSITIONS_NEEDING_NOTES,
            'canOpenInspection' => $inspection !== null && $user->can('view', $inspection),
            'canEdit' => $user->can('update', $ncr),
            'canDelete' => $user->can('delete', $ncr),
            'canIssue' => $user->can('submit', $ncr),
            'canRespond' => $user->can('respond', $ncr),
            'canDisposition' => $user->can('disposition', $ncr),
            'canVerify' => $user->can('verify', $ncr),
            'canClose' => $user->can('close', $ncr),
            'canRaiseAction' => $user->can('create', CorrectiveAction::class),
            ...$this->actionOptions($request),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Ncr::class);

        $user = $request->user();

        return view('ncrs.create', [
            'ncr' => new Ncr([
                'confidentiality' => Confidentiality::Internal->value,
                'project_id' => (int) $request->query('project', 0) ?: null,
                'detected_by' => $user->id,
                'detected_on' => now()->toDateString(),
            ]),
            ...$this->formOptions($request),
        ]);
    }

    public function store(NcrRequest $request): RedirectResponse
    {
        $this->authorize('create', Ncr::class);

        return $this->persistNew($request, null);
    }

    /**
     * Raise a non-conformance out of a rejected inspection.
     *
     * The inspection is bound from the route and authorized as a record; `quality_inspection_id` is
     * then set from the bound model, never from the posted form. That is the same rule the five
     * corrective-action endpoints follow, and it exists because a link is an assertion about
     * another record — accepting one by id would let anybody attach their NCR to an inspection they
     * have never been allowed to see (§84, §94).
     */
    public function storeForInspection(NcrRequest $request, QualityInspection $qualityInspection): RedirectResponse
    {
        $this->authorize('view', $qualityInspection);
        $this->authorize('create', Ncr::class);

        return $this->persistNew($request, $qualityInspection);
    }

    public function edit(Request $request, Ncr $ncr): View
    {
        $this->authorize('update', $ncr);

        return view('ncrs.edit', [
            'ncr' => $ncr,
            ...$this->formOptions($request),
        ]);
    }

    public function update(NcrRequest $request, Ncr $ncr): RedirectResponse
    {
        $this->authorize('update', $ncr);

        $data = $request->ncrAttributes();

        $this->assertPlacementIsReachable($data);

        $this->ncrs->update($ncr, $data, $request->user());

        return redirect($this->showRoute($ncr))
            ->with('status', $ncr->displayName().' has been updated.');
    }

    public function destroy(Request $request, Ncr $ncr): RedirectResponse
    {
        $this->authorize('delete', $ncr);

        $name = $ncr->displayName();

        $this->recordDeletion($ncr, $request->user(), 'non-conformance');

        $ncr->delete();

        return redirect($this->indexRoute())->with('status', $name.' has been deleted.');
    }

    // ------------------------------------------------------------------ lifecycle

    /** Send the finding to the party responsible. */
    public function issue(Request $request, Ncr $ncr): RedirectResponse
    {
        $this->authorize('submit', $ncr);

        try {
            $this->ncrs->issue($ncr, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['issue' => $e->getMessage()]);
        }

        return back()->with('status', $ncr->displayName().' has been issued.');
    }

    /** The other side's account of it — required, because an NCR with no answer is half a document. */
    public function respond(Request $request, Ncr $ncr): RedirectResponse
    {
        $this->authorize('respond', $ncr);

        $validated = $request->validate([
            'response' => ['required', 'string', 'max:20000'],
        ]);

        try {
            $this->ncrs->respond($ncr, $request->user(), $validated['response']);
        } catch (\DomainException $e) {
            return back()->withErrors(['response' => $e->getMessage()]);
        }

        return back()->with('status', 'The response to '.$ncr->displayName().' has been recorded.');
    }

    /**
     * Decide what happens to the work.
     *
     * The notes are `nullable` here and required by the service for repair, use-as-is and regrade.
     * Deliberately: which dispositions demand an explanation is a property of the disposition, and
     * it belongs next to the constant that lists them rather than being restated in a validator
     * that a workflow action or an import would never pass through (§84).
     */
    public function disposition(Request $request, Ncr $ncr): RedirectResponse
    {
        $this->authorize('disposition', $ncr);

        $validated = $request->validate([
            'disposition' => [
                'required',
                Rule::in($this->lists->keys('ncr.dispositions', Ncr::DEFAULT_DISPOSITIONS)),
            ],
            'disposition_notes' => ['nullable', 'string', 'max:20000'],
        ]);

        try {
            $this->ncrs->disposition(
                $ncr,
                $request->user(),
                (string) $validated['disposition'],
                $validated['disposition_notes'] ?? null,
            );
        } catch (\DomainException $e) {
            return back()->withErrors(['disposition' => $e->getMessage()]);
        }

        return back()->with('status', $ncr->displayName().' has been dispositioned.');
    }

    /** Confirm the disposition was actually carried out. Weeks later, usually by somebody else. */
    public function verify(Request $request, Ncr $ncr): RedirectResponse
    {
        $this->authorize('verify', $ncr);

        $validated = $request->validate([
            'verification_notes' => ['nullable', 'string', 'max:20000'],
        ]);

        try {
            $this->ncrs->verify($ncr, $request->user(), $validated['verification_notes'] ?? null);
        } catch (\DomainException $e) {
            return back()->withErrors(['verify' => $e->getMessage()]);
        }

        return back()->with('status', $ncr->displayName().' has been verified.');
    }

    public function close(Request $request, Ncr $ncr): RedirectResponse
    {
        $this->authorize('close', $ncr);

        $validated = $request->validate([
            'closure_notes' => ['nullable', 'string', 'max:20000'],
        ]);

        try {
            $this->ncrs->close($ncr, $request->user(), $validated['closure_notes'] ?? null);
        } catch (\DomainException $e) {
            return back()->withErrors(['close' => $e->getMessage()]);
        }

        return back()->with('status', $ncr->displayName().' has been closed.');
    }

    public function transition(Request $request, Ncr $ncr): RedirectResponse
    {
        return $this->moveStatus($request, $ncr);
    }

    // ------------------------------------------------------------------ internals

    /**
     * The one place an NCR is written, whether it came from a form or from an inspection.
     *
     * `quality_inspection_id` from the posted data is discarded outright rather than merged over —
     * the link is either the bound inspection or nothing.
     */
    private function persistNew(NcrRequest $request, ?QualityInspection $inspection): RedirectResponse
    {
        $data = $request->ncrAttributes();

        unset($data['quality_inspection_id']);

        if ($inspection !== null) {
            $data['quality_inspection_id'] = $inspection->getKey();
            $data['project_id'] ??= $inspection->project_id;
            $data['discipline_id'] ??= $inspection->discipline_id;
            $data['source'] ??= 'inspection';
        }

        $this->assertPlacementIsReachable($data);

        $ncr = $this->ncrs->create($data, $request->user());

        return redirect($this->showRoute($ncr))
            ->with('status', $ncr->displayName().' has been raised.');
    }

    /** @return array<string,mixed> */
    private function formOptions(Request $request): array
    {
        return [
            ...$this->placementOptions($request->user()),
            'severities' => $this->lists->for('ncr.severities', Ncr::DEFAULT_SEVERITIES),
            'categories' => $this->lists->for('ncr.categories', Ncr::DEFAULT_CATEGORIES),
            'sources' => $this->lists->for('ncr.sources', Ncr::DEFAULT_SOURCES),
            'nextNumber' => $this->numbers->preview('ncr'),
        ];
    }

    /** @return array<string,mixed> */
    private function actionOptions(Request $request): array
    {
        return [
            'actionTypes' => $this->lists->for('corrective_action.types', CorrectiveAction::DEFAULT_TYPES),
            'actionPriorities' => $this->lists->for(
                'corrective_action.priorities',
                CorrectiveAction::DEFAULT_PRIORITIES,
            ),
            'people' => $this->userOptions($request->user()),
        ];
    }
}
