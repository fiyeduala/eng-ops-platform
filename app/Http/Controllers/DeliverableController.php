<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\DeliverableRequest;
use App\Models\Deliverable;
use App\Models\Document;
use App\Models\User;
use App\Services\DeliverableService;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The Master Deliverable Register — what this organization has promised to produce.
 *
 * THE REGISTER IS NOT A LIST OF FILES. Every line here is an obligation that exists from the day
 * the contract was signed, whether or not anybody has started it. That is the whole reason the
 * module cannot be a view over `documents`: a promise nobody has begun has no file, so a register
 * built on files can never report that something is late — the row simply is not there. `document_id`
 * closes the loop when the artefact finally appears, and stays null for the many deliverables
 * satisfied by something that never enters document control at all.
 *
 * PROGRESS IS ITS OWN ENDPOINT AND ITS OWN PERMISSION. Editing a deliverable's title is not the
 * same act as declaring it 80% complete: the second is what a client invoice and a schedule report
 * are computed from. `reportProgress` therefore goes through DeliverablePolicy::reportProgress and
 * never through the edit form, which is also why DeliverableRequest refuses `progress_percent`
 * outright (§84).
 *
 * ISSUING IS A ONE-WAY DOOR. It stamps `actual_issue_on`, forces progress to 100 and moves the
 * status, and after it the policy refuses deletion — a deliverable that has been issued is a fact
 * about what the project delivered, and deleting it would quietly improve the completion figures.
 */
class DeliverableController extends RegisterController
{
    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly DeliverableService $deliverables,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'deliverable';
    }

    protected function modelClass(): string
    {
        return Deliverable::class;
    }

    protected function routePrefix(): string
    {
        return 'deliverables';
    }

    // ------------------------------------------------------------------ register

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Deliverable::class);

        $user = $request->user();

        $query = $this->scopedQuery($user)->with([
            'status:id,label,color,is_terminal',
            'project:id,name,number',
            'discipline:id,name',
            'responsible:id,name',
            'document:id,number,title',
        ]);

        $this->applyCommonFilters($query, $request);

        $view = (string) $request->query('view', 'outstanding');

        match ($view) {
            'issued' => $query->issued(),
            'overdue' => $query->outstanding()->whereNotNull('planned_issue_on')
                ->whereDate('planned_issue_on', '<', now()->startOfDay()),
            'mine' => $query->where(fn ($q) => $q->where('responsible_id', $user->id)
                ->orWhere('created_by', $user->id)),
            'all' => $query,
            default => $query->outstanding(),
        };

        /** @var LengthAwarePaginator $deliverables */
        $deliverables = $query
            // Undated lines last: a register loaded from a contract schedule always has some, and
            // sorting them to the top would bury the dates the report is actually about.
            ->orderByRaw('CASE WHEN planned_issue_on IS NULL THEN 1 ELSE 0 END')
            ->orderBy('planned_issue_on')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('deliverables.index', [
            'deliverables' => $deliverables,
            'statuses' => $this->statuses->optionsFor('deliverable'),
            'projects' => $this->projectOptions($user),
            'units' => $this->unitOptions($user),
            'types' => $this->typeOptions(),
            'views' => [
                'outstanding' => 'Outstanding',
                'overdue' => 'Late',
                'issued' => 'Issued',
                'mine' => 'Mine',
                'all' => 'Everything you can see',
            ],
            'filters' => $this->commonFilters($request, 'outstanding'),
            'canCreate' => $user->can('create', Deliverable::class),
        ]);
    }

    public function show(Request $request, Deliverable $deliverable): View
    {
        $this->authorize('view', $deliverable);

        $user = $request->user();

        $deliverable->loadMissing([
            'status', 'project', 'discipline', 'orgUnit',
            'responsible:id,name', 'creator:id,name',
            'document:id,number,title,status_id',
        ]);

        return view('deliverables.show', [
            'deliverable' => $deliverable,
            'timeline' => $this->activity->timelineFor($deliverable)->limit(25)->get(),
            'transitions' => $this->statuses->available('deliverable', $deliverable->status_id, $user),
            'typeLabel' => $this->lists->label('engineering.deliverable_types', $deliverable->type, Deliverable::DEFAULT_TYPES),
            'workflowInstance' => $deliverable->currentWorkflowInstance(),

            // The attach picker. Empty for anybody who cannot attach, because building a list of
            // document numbers for somebody with no use for it is a way of telling them what
            // exists (§61).
            'documents' => $user->can('attachDocument', $deliverable)
                ? $this->attachableDocuments($user, $deliverable)
                : [],

            'canEdit' => $user->can('update', $deliverable),
            'canDelete' => $user->can('delete', $deliverable),
            'canReportProgress' => $user->can('reportProgress', $deliverable),
            'canAttach' => $user->can('attachDocument', $deliverable),
            'canIssue' => $user->can('review', $deliverable),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Deliverable::class);

        return view('deliverables.create', [
            'deliverable' => new Deliverable([
                'confidentiality' => Confidentiality::Internal->value,
                'project_id' => (int) $request->query('project', 0) ?: null,
                'weight' => 1,
            ]),
            ...$this->formOptions($request),
        ]);
    }

    public function store(DeliverableRequest $request): RedirectResponse
    {
        $this->authorize('create', Deliverable::class);

        $data = $request->deliverableAttributes();

        $this->assertPlacementIsReachable($data);

        $deliverable = $this->deliverables->create($data, $request->user());

        return redirect($this->showRoute($deliverable))
            ->with('status', $deliverable->displayName().' is on the register.');
    }

    public function edit(Request $request, Deliverable $deliverable): View
    {
        $this->authorize('update', $deliverable);

        return view('deliverables.edit', [
            'deliverable' => $deliverable,
            ...$this->formOptions($request),
        ]);
    }

    public function update(DeliverableRequest $request, Deliverable $deliverable): RedirectResponse
    {
        $this->authorize('update', $deliverable);

        $data = $request->deliverableAttributes();

        $this->assertPlacementIsReachable($data);

        $this->deliverables->update($deliverable, $data, $request->user());

        return redirect($this->showRoute($deliverable))
            ->with('status', $deliverable->displayName().' has been updated.');
    }

    public function destroy(Request $request, Deliverable $deliverable): RedirectResponse
    {
        $this->authorize('delete', $deliverable);

        $name = $deliverable->displayName();

        $this->recordDeletion($deliverable, $request->user(), 'deliverable');

        $deliverable->delete();

        return redirect($this->indexRoute())->with('status', $name.' has been removed from the register.');
    }

    // ------------------------------------------------------------------ lifecycle

    /**
     * Record how far along it is.
     *
     * The number is clamped in the service rather than only in this validator, because progress is
     * also written by the workflow engine and by imports, and a clamp that lives in one HTTP path
     * is a clamp three other paths do not have.
     */
    public function reportProgress(Request $request, Deliverable $deliverable): RedirectResponse
    {
        $this->authorize('reportProgress', $deliverable);

        $validated = $request->validate([
            'progress_percent' => ['required', 'integer', 'between:0,100'],
            'forecast_issue_on' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->deliverables->reportProgress(
            $deliverable,
            (int) $validated['progress_percent'],
            $request->user(),
            $validated['forecast_issue_on'] ?? null,
            $validated['note'] ?? null,
        );

        return back()->with('status', 'Progress recorded.');
    }

    /**
     * Declare it delivered.
     *
     * Behind `deliverable.review` rather than `deliverable.edit`: issuing is the moment a promise
     * becomes a claim the company will be measured on, and the person who typed the line is not
     * automatically the person who gets to say it is finished.
     */
    public function issue(Request $request, Deliverable $deliverable): RedirectResponse
    {
        $this->authorize('review', $deliverable);

        $validated = $request->validate([
            'actual_issue_on' => ['nullable', 'date'],
        ]);

        try {
            $this->deliverables->issue($deliverable, $request->user(), $validated['actual_issue_on'] ?? null);
        } catch (\DomainException $e) {
            return back()->withErrors(['issue' => $e->getMessage()]);
        }

        return back()->with('status', $deliverable->displayName().' has been issued.');
    }

    /**
     * Point the line at the controlled document that satisfies it.
     *
     * Two policies, both required. `attachDocument` asks whether this actor may change THIS
     * deliverable; `view` on the document asks whether they may see what they are attaching. Without
     * the second, the register would become a way to confirm the existence and number of a
     * confidential document by attaching it and reading the row back (§67, §84).
     */
    public function attachDocument(Request $request, Deliverable $deliverable): RedirectResponse
    {
        $this->authorize('attachDocument', $deliverable);

        $validated = $request->validate([
            'document_id' => ['nullable', 'integer'],
        ]);

        $document = null;

        if (filled($validated['document_id'] ?? null)) {
            $document = Document::query()->find($validated['document_id']);

            if ($document === null) {
                return back()->withErrors(['document_id' => 'That document no longer exists.']);
            }

            $this->authorize('view', $document);
        }

        $this->deliverables->attachDocument($deliverable, $document, $request->user());

        return back()->with('status', $document === null
            ? 'The document link has been removed.'
            : 'Linked to '.$document->displayName().'.');
    }

    public function transition(Request $request, Deliverable $deliverable): RedirectResponse
    {
        return $this->moveStatus($request, $deliverable);
    }

    // ------------------------------------------------------------------ options

    /** @return array<string,string> */
    private function typeOptions(): array
    {
        return $this->lists->for('engineering.deliverable_types', Deliverable::DEFAULT_TYPES);
    }

    /**
     * Documents this actor may attach — which is exactly the documents they may already read.
     *
     * Compiled through `document.view` rather than through the deliverable's own permission, so the
     * picker can never become a directory of things the person cannot open. Narrowed to the
     * deliverable's project when it has one: a deliverable satisfied by a document from a different
     * job is almost always a misclick, and the shorter list makes the right one findable (§61).
     *
     * @return array<int,string>
     */
    private function attachableDocuments(User $user, Deliverable $deliverable): array
    {
        $query = $this->compiler->apply(Document::query(), $user, 'document.view');

        if ($deliverable->project_id !== null) {
            $query->where('project_id', $deliverable->project_id);
        }

        return $query->orderByDesc('id')->limit(200)->get()
            ->mapWithKeys(fn (Document $d) => [$d->id => $d->displayName()])
            ->all();
    }

    /** @return array<string,mixed> */
    private function formOptions(Request $request): array
    {
        return [
            ...$this->placementOptions($request->user()),
            'types' => $this->typeOptions(),
            'nextNumber' => $this->numbers->preview('deliverable'),
        ];
    }
}
