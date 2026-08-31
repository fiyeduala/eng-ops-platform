<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\DrawingRequest;
use App\Models\Deliverable;
use App\Models\Document;
use App\Models\Drawing;
use App\Models\User;
use App\Services\DrawingService;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The drawing register — the title block, and a pointer at the file.
 *
 * THIS REGISTER STORES NO FILES, and that is the design decision the whole module rests on. What
 * lives here is what would be printed in the bottom-right corner of a sheet: number, title, sheet
 * n of m, size, scale, revision, purpose, drawn by, checked by. The controlled file lives in
 * document control, where revisions, approvals, transmittals and downloads already are. A second
 * file store would fragment exactly the thing document control exists to hold together.
 *
 * WHAT THEN JUSTIFIES A SEPARATE TABLE. Two things. None of those columns belong on `documents` — a
 * memo has no sheet size and no checker — and a drawing register is routinely maintained for months
 * before any file exists, which a register built on documents cannot represent at all.
 *
 * OPENING THE FILE REQUIRES BOTH PERMISSIONS. `openFile` asks DrawingPolicy for `drawing.download`
 * reach AND asks the document's own policy whether this actor may view it. Only asking the first
 * would make the register a way round document confidentiality: attach a restricted drawing, then
 * read it through a module whose permissions somebody granted more freely (§67, §84).
 *
 * REVISING IS ITS OWN ACT. `revise()` writes the from → to revision and the purpose into the
 * timeline, because a drawing register that silently overwrote Rev C with Rev D would answer "what
 * was issued for construction in March" with whatever is true today.
 */
class DrawingController extends RegisterController
{
    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly DrawingService $drawings,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'drawing';
    }

    protected function modelClass(): string
    {
        return Drawing::class;
    }

    protected function routePrefix(): string
    {
        return 'drawings';
    }

    // ------------------------------------------------------------------ register

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Drawing::class);

        $user = $request->user();

        $query = $this->scopedQuery($user)->with([
            'status:id,label,color,is_terminal',
            'project:id,name,number',
            'discipline:id,name',
            'drawnBy:id,name',
            'checkedBy:id,name',
            'document:id,number,title',
        ]);

        $this->applyCommonFilters($query, $request);

        if ($purpose = (string) $request->query('purpose', '')) {
            $query->where('purpose', $purpose);
        }

        $view = (string) $request->query('view', 'all');

        match ($view) {
            'no-file' => $query->withoutFile(),
            'mine' => $query->where(fn ($q) => $q->where('drawn_by', $user->id)
                ->orWhere('checked_by', $user->id)
                ->orWhere('created_by', $user->id)),
            default => $query,
        };

        /** @var LengthAwarePaginator $drawings */
        $drawings = $query->orderBy('number')->paginate(30)->withQueryString();

        return view('drawings.index', [
            'drawings' => $drawings,
            'statuses' => $this->statuses->optionsFor('drawing'),
            'projects' => $this->projectOptions($user),
            'units' => $this->unitOptions($user),
            'purposes' => $this->purposeOptions(),
            'views' => [
                'all' => 'Whole register',
                'no-file' => 'No file yet',
                'mine' => 'Mine',
            ],
            'filters' => [...$this->commonFilters($request, 'all'), 'purpose' => $request->query('purpose')],
            'canCreate' => $user->can('create', Drawing::class),
        ]);
    }

    public function show(Request $request, Drawing $drawing): View
    {
        $this->authorize('view', $drawing);

        $user = $request->user();

        $drawing->loadMissing([
            'status', 'project', 'discipline', 'orgUnit', 'deliverable:id,number,title',
            'drawnBy:id,name', 'checkedBy:id,name', 'creator:id,name',
            'document:id,number,title,confidentiality',
        ]);

        return view('drawings.show', [
            'drawing' => $drawing,
            'timeline' => $this->activity->timelineFor($drawing)->limit(25)->get(),
            'transitions' => $this->statuses->available('drawing', $drawing->status_id, $user),
            'purposeLabel' => $this->lists->label('engineering.drawing_purposes', $drawing->purpose, Drawing::DEFAULT_PURPOSES),

            // The revise form re-states the purpose, because a new revision is very often a
            // re-issue for a different one — "for review" becoming "for construction" IS the event.
            'purposes' => $this->purposeOptions(),
            'workflowInstance' => $drawing->currentWorkflowInstance(),

            // Compiled through `document.view`, so the picker lists only what this actor could
            // already open by navigating to it (§61).
            'documents' => $user->can('attachDocument', $drawing)
                ? $this->attachableDocuments($user, $drawing)
                : [],

            'canEdit' => $user->can('update', $drawing),
            'canDelete' => $user->can('delete', $drawing),
            'canAttach' => $user->can('attachDocument', $drawing),

            // Deliberately the compound check, not `drawing.download` alone. If the register says
            // the file can be opened, opening it must actually work — an affordance that leads to a
            // 403 is worse than no affordance at all.
            'canOpenFile' => $drawing->document !== null && $user->can('openFile', $drawing),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Drawing::class);

        return view('drawings.create', [
            'drawing' => new Drawing([
                'confidentiality' => Confidentiality::Internal->value,
                'project_id' => (int) $request->query('project', 0) ?: null,
                'number' => $this->numbers->preview('drawing'),
                'revision' => 'A',
            ]),
            ...$this->formOptions($request),
        ]);
    }

    public function store(DrawingRequest $request): RedirectResponse
    {
        $this->authorize('create', Drawing::class);

        $data = $request->drawingAttributes();

        $this->assertPlacementIsReachable($data);
        $this->assertDeliverableIsReachable($data);

        $drawing = $this->drawings->create($data, $request->user());

        return redirect($this->showRoute($drawing))
            ->with('status', $drawing->displayName().' is on the register.');
    }

    public function edit(Request $request, Drawing $drawing): View
    {
        $this->authorize('update', $drawing);

        return view('drawings.edit', [
            'drawing' => $drawing,
            ...$this->formOptions($request),
        ]);
    }

    public function update(DrawingRequest $request, Drawing $drawing): RedirectResponse
    {
        $this->authorize('update', $drawing);

        $data = $request->drawingAttributes();

        $this->assertPlacementIsReachable($data);
        $this->assertDeliverableIsReachable($data);

        $this->drawings->update($drawing, $data, $request->user());

        return redirect($this->showRoute($drawing))
            ->with('status', $drawing->displayName().' has been updated.');
    }

    public function destroy(Request $request, Drawing $drawing): RedirectResponse
    {
        $this->authorize('delete', $drawing);

        $name = $drawing->displayName();

        $this->recordDeletion($drawing, $request->user(), 'drawing');

        $drawing->delete();

        return redirect($this->indexRoute())->with('status', $name.' has been removed from the register.');
    }

    // ------------------------------------------------------------------ lifecycle

    /**
     * Move the title block on to the next revision.
     *
     * The register keeps only the CURRENT title block, and the timeline keeps the history of what
     * it said. That is a deliberate limit rather than an omission: a full revision history with
     * files, approvals and signatures already exists one module over, and duplicating it here would
     * produce two answers to "what is the current revision" that drift apart.
     */
    public function revise(Request $request, Drawing $drawing): RedirectResponse
    {
        $this->authorize('update', $drawing);

        $validated = $request->validate([
            'revision' => ['required', 'string', 'max:16'],
            'purpose' => ['nullable', 'string', 'max:24'],
            'revision_date' => ['nullable', 'date'],
        ]);

        $this->drawings->revise(
            $drawing,
            $validated['revision'],
            $request->user(),
            $validated['purpose'] ?? null,
            $validated['revision_date'] ?? null,
        );

        return back()->with('status', $drawing->displayName().' is now at revision '.$drawing->revision.'.');
    }

    /**
     * Attach — or detach — the controlled file.
     *
     * Two policies, both required and neither redundant: `attachDocument` asks whether this actor
     * may change THIS drawing, and `view` on the document asks whether they may see what they are
     * attaching. Skipping the second would let somebody confirm the existence and number of a
     * confidential document by attaching it and reading the register row back (§67, §84).
     */
    public function attachDocument(Request $request, Drawing $drawing): RedirectResponse
    {
        $this->authorize('attachDocument', $drawing);

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

        try {
            $this->drawings->attachDocument($drawing, $document, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['document_id' => $e->getMessage()]);
        }

        return back()->with('status', $document === null
            ? 'The file link has been removed.'
            : 'Linked to '.$document->displayName().'.');
    }

    /**
     * Open the controlled file behind this register line.
     *
     * A redirect into document control rather than a second download path. Streaming the file from
     * here would mean a second set of headers, a second MIME decision and a second place to get
     * private-storage handling wrong — and document control already answers all three, under the
     * document's own permissions (§67).
     */
    public function openFile(Request $request, Drawing $drawing): RedirectResponse
    {
        $this->authorize('openFile', $drawing);

        if ($drawing->document_id === null) {
            return back()->withErrors(['document_id' => 'There is no file on this drawing yet.']);
        }

        return redirect()->route('documents.show', $drawing->document_id);
    }

    public function transition(Request $request, Drawing $drawing): RedirectResponse
    {
        return $this->moveStatus($request, $drawing);
    }

    // ------------------------------------------------------------------ options

    /**
     * A drawing may be filed against a deliverable, and only one the actor can reach.
     *
     * The form request has confirmed the deliverable is in this tenant; this confirms the actor may
     * see it. Without the second check somebody could attach their drawing to a deliverable in a
     * project they have no access to, and the deliverable's owners would find a row on their
     * register that they did not put there (§84).
     *
     * @param  array<string,mixed>  $data
     */
    private function assertDeliverableIsReachable(array $data): void
    {
        if (($id = $data['deliverable_id'] ?? null) !== null) {
            $this->authorize('view', Deliverable::findOrFail($id));
        }
    }

    /** @return array<string,string> */
    private function purposeOptions(): array
    {
        return $this->lists->for('engineering.drawing_purposes', Drawing::DEFAULT_PURPOSES);
    }

    /**
     * Documents this actor may attach — which is exactly the documents they may already read.
     *
     * Compiled through `document.view`, not through `drawing.edit`, so attaching can never widen
     * what somebody can see. Narrowed to the drawing's project when it has one, because a drawing
     * satisfied by a file from another job is almost always a misclick (§61).
     *
     * @return array<int,string>
     */
    private function attachableDocuments(User $user, Drawing $drawing): array
    {
        $query = $this->compiler->apply(Document::query(), $user, 'document.view');

        if ($drawing->project_id !== null) {
            $query->where('project_id', $drawing->project_id);
        }

        return $query->orderByDesc('id')->limit(200)->get()
            ->mapWithKeys(fn (Document $d) => [$d->id => $d->displayName()])
            ->all();
    }

    /** @return array<string,mixed> */
    private function formOptions(Request $request): array
    {
        $user = $request->user();

        return [
            ...$this->placementOptions($user),
            'purposes' => $this->purposeOptions(),
            'sheetSizes' => array_combine(Drawing::SHEET_SIZES, Drawing::SHEET_SIZES),
            'deliverables' => $this->compiler
                ->apply(Deliverable::query(), $user, 'deliverable.view')
                ->orderBy('number')->limit(300)->get()
                ->mapWithKeys(fn (Deliverable $d) => [$d->id => $d->displayName()])
                ->all(),
        ];
    }
}
