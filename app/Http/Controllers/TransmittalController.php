<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Http\Controllers\Concerns\OffersPartyOptions;
use App\Http\Requests\TransmittalItemRequest;
use App\Http\Requests\TransmittalRequest;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Transmittal;
use App\Models\TransmittalItem;
use App\Services\TransmittalService;
use App\Support\Enums\Confidentiality;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Transmittals (§30) — the record that documents left the building.
 *
 * A transmittal names REVISIONS, not documents, and that distinction is the entire module. Six
 * months into a dispute the question is never "did we send them the general arrangement drawing",
 * it is "which revision did we send them, on what date, and did they confirm receipt". A register
 * that pointed at documents would answer with whatever is current today, which is exactly the
 * answer nobody needs.
 *
 * ISSUING IS A ONE-WAY DOOR, and it does two things at once that are properly the same thing:
 * `issued_at` is both "this went out" and the publication column the access engine reads. Until it
 * is set, only the sender and people holding `transmittal.issue` see the draft at all. After it is
 * set, the transmittal cannot be edited or deleted by anyone — TransmittalPolicy refuses both —
 * because it is now a statement about what was sent, and correcting it after the fact would rewrite
 * a record the other party is holding their own copy of (§59). The remedy is to cancel and re-issue.
 *
 * THE RECIPIENT IS NOT NOTIFIED BY THIS PLATFORM. They are outside the organization, they have no
 * account, and the access engine knows nothing about them — emailing them a link would hand an
 * unauthenticated URL to a party no policy governs (§67, §68). The cover sheet is generated as a
 * PDF and sent by whatever channel the transmittal records. What the platform notifies is the
 * INSIDE: the owners of the documents on it, and the project.
 *
 * EVERY ITEM IS AUTHORIZED AS A DOCUMENT. Adding a revision to a transmittal is asked of
 * DocumentVersionPolicy, which delegates to the parent document. Without that, a transmittal would
 * be a way to attach a confidential drawing to a cover sheet and send it out — an exfiltration
 * route with letterhead on it.
 */
class TransmittalController extends Controller
{
    use OffersPartyOptions;

    public function __construct(
        private readonly ScopeCompiler $compiler,
        private readonly ActivityRecorder $activity,
        private readonly StatusMachine $statuses,
        private readonly TransmittalService $transmittals,
        private readonly NumberGenerator $numbers,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Transmittal::class);

        $user = $request->user();

        $query = $this->compiler->apply(Transmittal::query(), $user, 'transmittal.view')
            ->with([
                'status:id,label,color,is_terminal',
                'project:id,name,number',
                'orgUnit:id,name',
                'issuer:id,name',
                'creator:id,name',
            ])
            ->withCount('items');

        $query->search((string) $request->query('q', ''));

        if ($projectId = (int) $request->query('project', 0)) {
            $query->where('project_id', $projectId);
        }

        if ($statusId = (int) $request->query('status', 0)) {
            $query->where('status_id', $statusId);
        }

        $view = (string) $request->query('view', 'issued');

        match ($view) {
            'drafts' => $query->drafts(),
            'awaiting' => $query->awaitingAcknowledgement(),
            'mine' => $query->where('created_by', $user->id),
            'all' => $query,
            default => $query->issued(),
        };

        /** @var LengthAwarePaginator $transmittals */
        $transmittals = $query
            ->orderByRaw('CASE WHEN issued_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('transmittals.index', [
            'transmittals' => $transmittals,
            'statuses' => $this->statuses->optionsFor('transmittal'),
            'projects' => $this->projectOptions($request),
            'views' => [
                'issued' => 'Issued',
                'drafts' => 'Drafts',
                'awaiting' => 'Awaiting acknowledgement',
                'mine' => 'Raised by me',
                'all' => 'Everything you can see',
            ],
            'filters' => [
                'q' => $request->query('q'),
                'project' => $request->query('project'),
                'status' => $request->query('status'),
                'view' => $view,
            ],
            'canCreate' => $user->can('create', Transmittal::class),
        ]);
    }

    public function show(Request $request, Transmittal $transmittal): View
    {
        $this->authorize('view', $transmittal);

        $user = $request->user();

        $transmittal->loadMissing([
            'status', 'project', 'orgUnit', 'toParty', 'issuer:id,name', 'creator:id,name',
            'items' => fn ($q) => $q->orderBy('sort')->with([
                'documentVersion.document:id,number,title,document_category_id,confidentiality',
                'documentVersion.document.category:id,name,code',
            ]),
        ]);

        return view('transmittals.show', [
            'transmittal' => $transmittal,
            'items' => $transmittal->items,
            'timeline' => $this->activity->timelineFor($transmittal)->limit(25)->get(),
            'canEdit' => $user->can('update', $transmittal),
            'canAddItems' => $user->can('createFor', [TransmittalItem::class, $transmittal]),
            'canIssue' => $user->can('issue', $transmittal),
            'canAcknowledge' => $user->can('acknowledge', $transmittal),
            'canCancel' => $user->can('cancel', $transmittal),
            'canCoverSheet' => $user->can('coverSheet', $transmittal),
            'canDelete' => $user->can('delete', $transmittal),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Transmittal::class);

        return view('transmittals.create', [
            'transmittal' => new Transmittal([
                'purpose' => 'for_information',
                'confidentiality' => Confidentiality::Internal->value,
                'project_id' => (int) $request->query('project', 0) ?: null,
            ]),
            ...$this->formOptions($request),
        ]);
    }

    public function store(TransmittalRequest $request): RedirectResponse
    {
        $this->authorize('create', Transmittal::class);

        $data = $request->transmittalAttributes();

        $this->assertPlacementIsReachable($request, $data);

        try {
            $transmittal = $this->transmittals->create($data, $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['to_party_id' => $e->getMessage()]);
        }

        return redirect()->route('transmittals.show', $transmittal)
            ->with('status', $transmittal->displayName().' has been drafted. Add the revisions, then issue it.');
    }

    public function edit(Request $request, Transmittal $transmittal): View
    {
        $this->authorize('update', $transmittal);

        return view('transmittals.edit', [
            'transmittal' => $transmittal,
            ...$this->formOptions($request),
        ]);
    }

    public function update(TransmittalRequest $request, Transmittal $transmittal): RedirectResponse
    {
        $this->authorize('update', $transmittal);

        $data = $request->transmittalAttributes();

        $this->assertPlacementIsReachable($request, $data);

        try {
            $this->transmittals->update($transmittal, $data, $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['to_party_id' => $e->getMessage()]);
        }

        return redirect()->route('transmittals.show', $transmittal)
            ->with('status', $transmittal->displayName().' has been updated.');
    }

    // ---------------------------------------------------------------------- items

    /**
     * Put a revision on the transmittal.
     *
     * Three checks, all of them necessary and none of them redundant: the request confirms the
     * revision exists in this tenant, this method confirms the actor may DOWNLOAD the parent
     * document, and the service confirms the revision's organization matches the transmittal's.
     * The middle one is the important one — sending a document out is a stronger act than reading
     * it, and `document.view` is not `document.download` (§67).
     */
    public function addItem(TransmittalItemRequest $request, Transmittal $transmittal): RedirectResponse
    {
        $this->authorize('createFor', [TransmittalItem::class, $transmittal]);

        $version = DocumentVersion::query()->with('document')->find($request->versionId());

        if ($version === null || $version->document === null) {
            return back()->withErrors(['document_version_id' => 'That revision no longer exists.']);
        }

        $this->authorize('view', $version);
        $this->authorize('download', $version->document);

        try {
            $item = $this->transmittals->addItem($transmittal, $version, $request->itemAttributes(), $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['document_version_id' => $e->getMessage()]);
        }

        return back()->with('status', $item->displayName().' has been added.');
    }

    public function removeItem(Request $request, Transmittal $transmittal, TransmittalItem $item): RedirectResponse
    {
        // Bound through the parent rather than trusted as a bare id — an item belonging to another
        // transmittal is a 404, not a delete (§94).
        abort_unless((int) $item->transmittal_id === (int) $transmittal->getKey(), 404);

        $this->authorize('delete', $item);

        try {
            $this->transmittals->removeItem($item, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['items' => $e->getMessage()]);
        }

        return back()->with('status', 'Removed from the transmittal.');
    }

    // ------------------------------------------------------------------- lifecycle

    /** Send it. After this the record is frozen. */
    public function issue(Request $request, Transmittal $transmittal): RedirectResponse
    {
        $this->authorize('issue', $transmittal);

        try {
            $this->transmittals->issue($transmittal, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['issue' => $e->getMessage()]);
        }

        return redirect()->route('transmittals.show', $transmittal)->with(
            'status',
            $transmittal->displayName().' has been issued. Download the cover sheet to send it on.'
        );
    }

    /**
     * Record that the other side confirmed receipt.
     *
     * Recorded BY somebody inside, ON BEHALF of somebody outside — `acknowledged_by_name` is free
     * text and there is no account behind it. That is honest about what actually happened: a person
     * in document control had an email or a signed receipt in hand and wrote down who sent it. A
     * system that pretended the external party clicked something would be recording a fiction.
     */
    public function acknowledge(Request $request, Transmittal $transmittal): RedirectResponse
    {
        $this->authorize('acknowledge', $transmittal);

        $validated = $request->validate([
            'acknowledged_by_name' => ['nullable', 'string', 'max:200'],
            'acknowledgement_reference' => ['nullable', 'string', 'max:200'],
        ]);

        try {
            $this->transmittals->acknowledge(
                $transmittal,
                $request->user(),
                $validated['acknowledged_by_name'] ?? null,
                $validated['acknowledgement_reference'] ?? null,
            );
        } catch (\DomainException $e) {
            return back()->withErrors(['acknowledged_by_name' => $e->getMessage()]);
        }

        return back()->with('status', 'Receipt recorded.');
    }

    public function cancel(Request $request, Transmittal $transmittal): RedirectResponse
    {
        $this->authorize('cancel', $transmittal);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $this->transmittals->cancel($transmittal, $request->user(), $validated['reason']);

        return back()->with('status', $transmittal->displayName().' has been cancelled.');
    }

    /**
     * The cover sheet.
     *
     * The actual deliverable of the module: the PDF that goes to the other party, listing every
     * revision, its purpose, the media and the copy count. Generated on demand rather than stored,
     * for the same reason document exports are — a cached cover sheet would go stale the moment an
     * item changed, and a stale one that looks official is worse than none.
     */
    public function coverSheet(Request $request, Transmittal $transmittal): Response
    {
        $this->authorize('coverSheet', $transmittal);

        return response($this->transmittals->coverSheet($transmittal), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$transmittal->number.'.pdf"',
        ]);
    }

    public function destroy(Request $request, Transmittal $transmittal): RedirectResponse
    {
        $this->authorize('delete', $transmittal);

        $name = $transmittal->displayName();

        $this->activity->record(
            $transmittal,
            'deleted',
            $request->user()->name.' deleted the draft transmittal '.$name.'.',
            [],
            $request->user(),
            $transmittal->project_id !== null ? (int) $transmittal->project_id : null,
        );

        $transmittal->delete();

        return redirect()->route('transmittals.index')->with('status', $name.' has been deleted.');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param  array<string,mixed>  $data
     */
    private function assertPlacementIsReachable(Request $request, array $data): void
    {
        if (($projectId = $data['project_id'] ?? null) !== null) {
            $this->authorize('view', Project::findOrFail($projectId));
        }

        if (($unitId = $data['org_unit_id'] ?? null) !== null) {
            $this->authorize('view', OrgUnit::findOrFail($unitId));
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function formOptions(Request $request): array
    {
        return [
            'projects' => $this->projectOptions($request),
            'units' => $this->compiler
                ->apply(OrgUnit::query()->where('is_active', true), $request->user(), 'org_unit.view')
                ->orderBy('path')->get()
                ->mapWithKeys(fn (OrgUnit $u) => [
                    $u->id => str_repeat('— ', max(0, (int) $u->depth)).$u->name,
                ])->all(),
            ...$this->partyOptions($request->user()),
            'purposes' => Transmittal::PURPOSES,
            'transports' => Transmittal::TRANSPORTS,
            'media' => TransmittalItem::MEDIA,
            'confidentialities' => Confidentiality::options(),
            'nextNumber' => $this->numbers->preview('transmittal'),
        ];
    }

    /**
     * Projects this actor can reach, for the placement field.
     *
     * @return array<int|string,string>
     */
    private function projectOptions(Request $request): array
    {
        return $this->compiler
            ->apply(Project::query()->active(), $request->user(), 'project.view')
            ->orderBy('name')->limit(500)->get()
            ->mapWithKeys(fn (Project $p) => [$p->id => $p->displayName()])
            ->all();
    }

    /**
     * Revisions that can be put on a transmittal, for the item picker.
     *
     * Exposed as its own endpoint because the list is long, project-dependent and searched rather
     * than scrolled. Runs through ScopeCompiler on `document.download`, so it obeys exactly the
     * same authorization as the register itself and can never become a way to enumerate documents
     * somebody cannot open (§61).
     */
    public function documentPicker(Request $request, Transmittal $transmittal): View
    {
        $this->authorize('createFor', [TransmittalItem::class, $transmittal]);

        $already = $transmittal->items()->pluck('document_version_id')
            ->map(fn ($id) => (int) $id)->all();

        $documents = $this->compiler
            ->apply(Document::query(), $request->user(), 'document.download')
            ->with([
                'currentVersion:id,document_id,revision,approved_at',
                'category:id,name,code',
                /*
                 * Every revision, not just the current one. Re-issuing an earlier revision is an
                 * ordinary act in document control — "the client asked for the Rev B we built from"
                 * — and a picker that only offered the latest would force somebody to record the
                 * wrong thing, in the one register whose entire purpose is being precise about
                 * which revision went out.
                 */
                'versions' => fn ($q) => $q->select('id', 'document_id', 'revision', 'approved_at')
                    ->orderByDesc('id'),
            ])
            ->whereHas('versions')
            ->when(
                $transmittal->project_id !== null,
                fn ($q) => $q->where('project_id', $transmittal->project_id)
            )
            ->search((string) $request->query('q', ''))
            ->orderBy('number')
            ->limit(100)
            ->get();

        return view('transmittals.picker', [
            'transmittal' => $transmittal,
            'documents' => $documents,
            'already' => $already,
            'media' => TransmittalItem::MEDIA,
            'q' => $request->query('q'),
        ]);
    }
}
