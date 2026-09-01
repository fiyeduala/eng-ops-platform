<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\SettingsRepository;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\SubmittalItemRequest;
use App\Http\Requests\SubmittalRequest;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Submittal;
use App\Models\SubmittalItem;
use App\Services\SubmittalService;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Submittals — packages sent for somebody else's review, and what they said back.
 *
 * THE REVIEW CODE AND THE STATUS ARE SET TOGETHER AND NEITHER IS DERIVED FROM THE OTHER. "Code B"
 * means approved-as-noted on one contract and revise-and-resubmit on the next; the mapping belongs
 * to a contract this platform has never read. So the reviewer states both — the code that goes on
 * the stamp and the status the package moves to — and nothing here guesses. Guessing would produce
 * a register that quietly disagrees with the paperwork.
 *
 * THE SUBMITTING PARTY MUST NOT BE ABLE TO CODE ITS OWN PACKAGE. That is the security-relevant fact
 * of the module, and it is enforced in three places rather than one: SubmittalRequest refuses
 * `review_code` outright, `review()` sits behind `submittal.review` rather than `submittal.edit`,
 * and the service refuses a second review. A form field that could carry a code would defeat all of
 * it (§84).
 *
 * RESUBMITTAL IS A NEW ROW, NOT AN EDIT. Each attempt keeps its own number, items and dates and
 * points back at the one before. That chain is what a schedule argument is settled on — "we
 * submitted this three times and they held it eleven weeks" — and it only exists if the attempts
 * are separate records.
 *
 * ITEMS ARE AUTHORIZED AS DOCUMENTS WHERE THEY NAME ONE. A line pointing at a document revision
 * passes that document's own policy on the way in; a line that names no revision (a physical
 * sample, a mock-up, a certificate nobody registered) does not, because there is nothing to
 * authorize. Without the first rule a submittal would be a way to attach a confidential drawing to
 * a package and send it out (§67).
 */
class SubmittalController extends RegisterController
{
    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly SubmittalService $submittals,
        private readonly SettingsRepository $settings,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'submittal';
    }

    protected function modelClass(): string
    {
        return Submittal::class;
    }

    protected function routePrefix(): string
    {
        return 'submittals';
    }

    // ------------------------------------------------------------------ register

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Submittal::class);

        $user = $request->user();

        $query = $this->scopedQuery($user)
            ->with([
                'status:id,label,color,is_terminal',
                'project:id,name,number',
                'discipline:id,name',
                'submitter:id,name',
                'reviewer:id,name',
            ])
            ->withCount('items');

        $this->applyCommonFilters($query, $request);

        $view = (string) $request->query('view', 'awaiting');

        match ($view) {
            'awaiting' => $query->awaitingReview(),
            'overdue' => $query->awaitingReview()->whereNotNull('required_by')
                ->whereDate('required_by', '<', now()->startOfDay()),
            'reviewed' => $query->whereNotNull('reviewed_at'),
            'mine' => $query->where(fn ($q) => $q->where('submitted_by', $user->id)
                ->orWhere('created_by', $user->id)),
            'all' => $query,
            default => $query->awaitingReview(),
        };

        /** @var LengthAwarePaginator $submittals */
        $submittals = $query
            ->orderByRaw('CASE WHEN required_by IS NULL THEN 1 ELSE 0 END')
            ->orderBy('required_by')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('submittals.index', [
            'submittals' => $submittals,
            'statuses' => $this->statuses->optionsFor('submittal'),
            'projects' => $this->projectOptions($user),
            'types' => $this->typeOptions(),
            'views' => [
                'awaiting' => 'Awaiting review',
                'overdue' => 'Late',
                'reviewed' => 'Reviewed',
                'mine' => 'Mine',
                'all' => 'Everything you can see',
            ],
            'filters' => $this->commonFilters($request, 'awaiting'),
            'canCreate' => $user->can('create', Submittal::class),
        ]);
    }

    public function show(Request $request, Submittal $submittal): View
    {
        $this->authorize('view', $submittal);

        $user = $request->user();

        $submittal->loadMissing([
            'status', 'project', 'discipline', 'orgUnit', 'party',
            'submitter:id,name', 'reviewer:id,name', 'creator:id,name',
            'resubmittalOf:id,number,title',
            'items.documentVersion.document:id,number,title,confidentiality',
        ]);

        return view('submittals.show', [
            'submittal' => $submittal,
            'items' => $submittal->items,
            'timeline' => $this->activity->timelineFor($submittal)->limit(25)->get(),
            'transitions' => $this->statuses->available('submittal', $submittal->status_id, $user),
            'reviewCodes' => $this->reviewCodeOptions(),
            // Keyed by KEY, not by id, and deliberately different from the filter list above. The
            // reviewer states where the package lands as a status key because the review service
            // works in keys — the tenant's own vocabulary — and an id would tie the form to one
            // organization's row numbers.
            'reviewStatuses' => $this->statuses->statusesFor('submittal')
                ->mapWithKeys(fn ($s) => [$s->key => $s->label])->all(),
            'typeLabel' => $this->lists->label('engineering.submittal_types', $submittal->type, Submittal::DEFAULT_TYPES),
            'attemptNumber' => $submittal->attemptNumber(),
            'workflowInstance' => $submittal->currentWorkflowInstance(),
            'canEdit' => $user->can('update', $submittal),
            'canDelete' => $user->can('delete', $submittal),
            'canAddItems' => $user->can('createFor', [SubmittalItem::class, $submittal]),
            'canSubmit' => $user->can('submit', $submittal),
            'canReview' => $user->can('review', $submittal),
            'canResubmit' => $user->can('resubmit', $submittal),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Submittal::class);

        $user = $request->user();
        $days = max(1, $this->settings->int('engineering.submittal_review_days', 14));

        return view('submittals.create', [
            'submittal' => new Submittal([
                'confidentiality' => Confidentiality::Internal->value,
                'project_id' => (int) $request->query('project', 0) ?: null,
                'submitted_by' => $user->id,
                'required_by' => now()->addDays($days)->toDateString(),
                'revision' => '0',
            ]),
            ...$this->formOptions($request),
        ]);
    }

    public function store(SubmittalRequest $request): RedirectResponse
    {
        $this->authorize('create', Submittal::class);

        $data = $request->submittalAttributes();

        $this->assertPlacementIsReachable($data);

        try {
            $submittal = $this->submittals->create($data, $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['party_id' => $e->getMessage()]);
        }

        return redirect($this->showRoute($submittal))
            ->with('status', $submittal->displayName().' has been drafted. Add the package contents, then submit it.');
    }

    public function edit(Request $request, Submittal $submittal): View
    {
        $this->authorize('update', $submittal);

        return view('submittals.edit', [
            'submittal' => $submittal,
            ...$this->formOptions($request),
        ]);
    }

    public function update(SubmittalRequest $request, Submittal $submittal): RedirectResponse
    {
        $this->authorize('update', $submittal);

        $data = $request->submittalAttributes();

        $this->assertPlacementIsReachable($data);

        try {
            $this->submittals->update($submittal, $data, $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['party_id' => $e->getMessage()]);
        }

        return redirect($this->showRoute($submittal))
            ->with('status', $submittal->displayName().' has been updated.');
    }

    public function destroy(Request $request, Submittal $submittal): RedirectResponse
    {
        $this->authorize('delete', $submittal);

        $name = $submittal->displayName();

        $this->recordDeletion($submittal, $request->user(), 'submittal');

        $submittal->delete();

        return redirect($this->indexRoute())->with('status', $name.' has been deleted.');
    }

    // ------------------------------------------------------------------ items

    /**
     * Put a line in the package.
     *
     * Where the line names a document revision, three checks run and none is redundant: the request
     * confirms the revision is in this tenant, this method confirms the actor may read the parent
     * document, and the service confirms the revision's organization matches the submittal's. A
     * posted id is an untrusted id however many times it has been looked up (§75, §84).
     */
    public function addItem(SubmittalItemRequest $request, Submittal $submittal): RedirectResponse
    {
        $this->authorize('createFor', [SubmittalItem::class, $submittal]);

        $version = null;

        if (($versionId = $request->versionId()) !== null) {
            $version = DocumentVersion::query()->with('document')->find($versionId);

            if ($version === null || $version->document === null) {
                return back()->withErrors(['document_version_id' => 'That revision no longer exists.']);
            }

            $this->authorize('view', $version);
            $this->authorize('view', $version->document);
        }

        try {
            $item = $this->submittals->addItem($submittal, $version, $request->itemAttributes(), $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['description' => $e->getMessage()]);
        }

        return back()->with('status', $item->displayName().' has been added to the package.');
    }

    public function removeItem(Request $request, Submittal $submittal, SubmittalItem $item): RedirectResponse
    {
        // Bound through the parent rather than trusted as a bare id — an item belonging to another
        // submittal is a 404, not a delete (§94).
        abort_unless((int) $item->submittal_id === (int) $submittal->getKey(), 404);

        $this->authorize('delete', $item);

        try {
            $this->submittals->removeItem($item, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['items' => $e->getMessage()]);
        }

        return back()->with('status', 'Removed from the package.');
    }

    /**
     * Stamp one line with its own verdict.
     *
     * Nine items accepted and the tenth rejected is an ordinary Tuesday, and a single code on the
     * parent cannot say it. Behind the parent's `review` ability — SubmittalItemPolicy::code asks
     * for that rather than `update`, because coding a line is reviewing, not editing.
     */
    public function codeItem(Request $request, Submittal $submittal, SubmittalItem $item): RedirectResponse
    {
        abort_unless((int) $item->submittal_id === (int) $submittal->getKey(), 404);

        $this->authorize('code', $item);

        $validated = $request->validate([
            'review_code' => ['required', 'string', 'max:24'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->submittals->codeItem($item, $validated['review_code'], $request->user(), $validated['remarks'] ?? null);
        } catch (\DomainException $e) {
            return back()->withErrors(['review_code' => $e->getMessage()]);
        }

        return back()->with('status', 'Line coded.');
    }

    // ------------------------------------------------------------------ lifecycle

    public function submit(Request $request, Submittal $submittal): RedirectResponse
    {
        $this->authorize('submit', $submittal);

        try {
            $this->submittals->submit($submittal, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['submit' => $e->getMessage()]);
        }

        return back()->with('status', $submittal->displayName().' has been submitted for review.');
    }

    /**
     * Record the verdict.
     *
     * The status is chosen by the reviewer alongside the code, for the reason in the class
     * docblock, and it is validated against this tenant's own submittal vocabulary — a key that is
     * not in the configured list is refused rather than silently ignored.
     */
    public function review(Request $request, Submittal $submittal): RedirectResponse
    {
        $this->authorize('review', $submittal);

        $codes = array_keys($this->reviewCodeOptions());
        $statusKeys = $this->statuses->statusesFor('submittal')->pluck('key')->all();

        $validated = $request->validate([
            'review_code' => ['required', 'string', 'in:'.implode(',', $codes)],
            'status_key' => ['required', 'string', 'in:'.implode(',', $statusKeys)],
            'review_comment' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $this->submittals->review(
                $submittal,
                $validated['review_code'],
                $validated['status_key'],
                $request->user(),
                $validated['review_comment'] ?? null,
            );
        } catch (\DomainException $e) {
            return back()->withErrors(['review_code' => $e->getMessage()]);
        }

        return back()->with('status', $submittal->displayName().' has been reviewed.');
    }

    /** Open the next attempt, carrying the package forward without last time's verdict. */
    public function resubmit(Request $request, Submittal $submittal): RedirectResponse
    {
        $this->authorize('resubmit', $submittal);

        try {
            $next = $this->submittals->resubmit($submittal, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['resubmit' => $e->getMessage()]);
        }

        return redirect($this->showRoute($next))
            ->with('status', 'Attempt '.$next->attemptNumber().' opened as '.$next->number.'.');
    }

    public function transition(Request $request, Submittal $submittal): RedirectResponse
    {
        return $this->moveStatus($request, $submittal);
    }

    // ------------------------------------------------------------------ picker

    /**
     * Revisions that can go in a package.
     *
     * Its own endpoint because the list is long, project-dependent and searched rather than
     * scrolled. Runs through ScopeCompiler on `document.view`, so it obeys exactly the same
     * authorization as the document register itself and can never become a way to enumerate
     * documents somebody cannot open (§61).
     */
    public function documentPicker(Request $request, Submittal $submittal): View
    {
        $this->authorize('createFor', [SubmittalItem::class, $submittal]);

        $already = $submittal->items()->whereNotNull('document_version_id')
            ->pluck('document_version_id')->map(fn ($id) => (int) $id)->all();

        $documents = $this->compiler
            ->apply(Document::query(), $request->user(), 'document.view')
            ->with([
                'category:id,name,code',
                'versions' => fn ($q) => $q->select('id', 'document_id', 'revision', 'approved_at')
                    ->orderByDesc('id'),
            ])
            ->whereHas('versions')
            ->when(
                $submittal->project_id !== null,
                fn ($q) => $q->where('project_id', $submittal->project_id)
            )
            ->search((string) $request->query('q', ''))
            ->orderBy('number')
            ->limit(100)
            ->get();

        return view('submittals.picker', [
            'submittal' => $submittal,
            'documents' => $documents,
            'already' => $already,
            'q' => $request->query('q'),
        ]);
    }

    // ------------------------------------------------------------------ options

    /** @return array<string,string> */
    private function typeOptions(): array
    {
        return $this->lists->for('engineering.submittal_types', Submittal::DEFAULT_TYPES);
    }

    /** @return array<string,string> */
    private function reviewCodeOptions(): array
    {
        return $this->lists->for('engineering.submittal_review_codes', Submittal::DEFAULT_REVIEW_CODES);
    }

    /** @return array<string,mixed> */
    private function formOptions(Request $request): array
    {
        return [
            ...$this->placementOptions($request->user()),
            ...$this->partyOptions($request->user()),
            'types' => $this->typeOptions(),
            'nextNumber' => $this->numbers->preview('submittal'),
        ];
    }
}
