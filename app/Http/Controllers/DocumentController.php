<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\DocumentRequest;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Template;
use App\Models\User;
use App\Services\DocumentService;
use App\Services\DocumentVersionService;
use App\Services\TemplateService;
use App\Support\Enums\Confidentiality;
use App\Support\Enums\DocumentKind;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Documents (§26–§30).
 *
 * The register, and the record card each document sits on. Revisions, uploads, downloads and the
 * editor all live in DocumentVersionController — this file is about the document as an object: its
 * identity, where it belongs, who owns it, whether it is checked out, and where it is in its life.
 *
 * THE LIST IS ONE ScopeCompiler CALL, like every other list in the platform. What makes documents
 * different is that `document.view` is not the end of the story: confidentiality is a second axis,
 * so a document marked confidential is additionally hidden from anyone without
 * `document.view_confidential` unless they own it. That filter is applied here in SQL rather than
 * by dropping rows after the fact, because a paginator that filters its own page produces pages of
 * varying length and a total nobody can explain (§61).
 *
 * CHECK-OUT IS THE ANSWER TO CONCURRENT EDITING, and it is deliberately not real-time collaboration.
 * Two people typing into the same controlled procedure at once is a document-control problem before
 * it is a technical one: somebody has to be answerable for Rev C. So the lock is explicit, visible,
 * attributed and expiring — you can see who holds it, when they took it, and an administrator with
 * `document.manage` can break it. Everything that writes content asks DocumentPolicy first, and the
 * policy refuses an update while somebody else holds the lock (§84).
 *
 * SUBMIT HANDS THE DOCUMENT TO THE WORKFLOW ENGINE and stops caring. Which route it takes, who
 * approves it and in what order are configuration, resolved at submit time from the category or the
 * organization's default (§5) — there is no approval logic in this controller and no module-specific
 * branch in the engine, which is the whole point of §90.
 */
class DocumentController extends Controller
{
    public function __construct(
        private readonly ScopeCompiler $compiler,
        private readonly ActivityRecorder $activity,
        private readonly StatusMachine $statuses,
        private readonly DocumentService $documents,
        private readonly DocumentVersionService $versions,
        private readonly TemplateService $templates,
        private readonly NumberGenerator $numbers,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Document::class);

        $user = $request->user();

        $query = $this->compiler->apply(Document::query(), $user, 'document.view')
            ->with([
                'status:id,label,color,is_terminal',
                'category:id,name,code',
                'project:id,name,number',
                'orgUnit:id,name',
                'owner:id,name',
                'lockedBy:id,name',
                'currentVersion:id,document_id,revision,approved_at,file_size,mime_type',
            ])
            ->withCount('versions');

        $this->applyConfidentiality($query, $user);

        $query->search((string) $request->query('q', ''));

        if ($categoryId = (int) $request->query('category', 0)) {
            $query->inCategory($categoryId);
        }

        if ($projectId = (int) $request->query('project', 0)) {
            $query->where('project_id', $projectId);
        }

        if ($statusId = (int) $request->query('status', 0)) {
            $query->where('status_id', $statusId);
        }

        $view = (string) $request->query('view', 'active');

        match ($view) {
            'mine' => $query->where('owner_id', $user->id),
            'drafts' => $query->whereDoesntHave('versions', fn ($q) => $q->whereNotNull('approved_at')),
            'approved' => $query->whereHas('versions', fn ($q) => $q->whereNotNull('approved_at')),
            'checked_out' => $query->whereNotNull('locked_by'),
            'in_review' => $query->whereHas('workflowInstances', fn ($q) => $q->where('status', 'running')),
            'archived' => $query->onlyTrashed(),
            'all' => $query,
            default => $query,
        };

        /** @var LengthAwarePaginator $documents */
        $documents = $query
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('documents.index', [
            'documents' => $documents,
            'categories' => $this->categoryOptions($request),
            'projects' => $this->projectOptions($request),
            'statuses' => $this->statuses->optionsFor('document'),
            'views' => $this->viewOptions(),
            'filters' => [
                'q' => $request->query('q'),
                'category' => $request->query('category'),
                'project' => $request->query('project'),
                'status' => $request->query('status'),
                'view' => $view,
            ],
            'canCreate' => $user->can('create', Document::class),
        ]);
    }

    public function show(Request $request, Document $document): View
    {
        $this->authorize('view', $document);

        $user = $request->user();

        $document->loadMissing([
            'status', 'category', 'project', 'orgUnit', 'discipline',
            'owner:id,name', 'creator:id,name', 'lockedBy:id,name',
            'currentVersion.author:id,name',
            'versions' => fn ($q) => $q->latestFirst()->with(['author:id,name', 'approver:id,name', 'status:id,label,color']),
        ]);

        $instance = $document->currentWorkflowInstance();

        return view('documents.show', [
            'document' => $document,
            'versions' => $document->versions,
            'current' => $document->currentVersion,
            'instance' => $instance?->load([
                'currentStageInstance.stage',
                'stageInstances' => fn ($q) => $q->with(['stage', 'approvals.user:id,name'])->orderBy('sequence'),
            ]),
            'timeline' => $this->activity->timelineFor($document)->limit(30)->get(),

            // Every button on the page is a separate question, asked of the policy with the record
            // in hand. The page never infers one permission from another — an actor who may view a
            // document is routinely someone who may not download it (§67).
            'canEdit' => $user->can('update', $document),
            'canCreateVersion' => $user->can('createVersion', $document),
            'canCheckOut' => $user->can('checkOut', $document),
            'canCheckIn' => $user->can('checkIn', $document),
            'canSubmit' => $user->can('submit', $document),
            'canWithdraw' => $user->can('withdraw', $document),
            'canDownload' => $user->can('download', $document),
            'canPrint' => $user->can('print', $document),
            'canShare' => $user->can('share', $document),
            'canArchive' => $user->can('archive', $document),
            'canDelete' => $user->can('delete', $document),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Document::class);

        $category = ($id = (int) $request->query('category', 0))
            ? DocumentCategory::query()->find($id)
            : null;

        return view('documents.create', [
            'document' => new Document([
                'document_category_id' => $category?->getKey(),
                'kind' => $category?->defaultKind() ?? DocumentKind::Native,
                'confidentiality' => $category?->defaultConfidentiality() ?? Confidentiality::Internal,
            ]),
            ...$this->formOptions($request, null),
        ]);
    }

    /**
     * Raise a document, and give it its first revision immediately.
     *
     * A document with no revisions is a row nobody can do anything with — it cannot be read,
     * downloaded, sent or approved. Creating Rev A in the same transaction as the document means
     * that state never exists, and it is what lets "New document" land the author straight in the
     * editor rather than on an empty record card asking them to press another button.
     */
    public function store(DocumentRequest $request): RedirectResponse
    {
        $this->authorize('create', Document::class);

        $user = $request->user();
        $data = $request->documentAttributes();

        $this->assertPlacementIsReachable($request, $data);

        $document = $this->documents->create($data, $user);

        // The template's boilerplate, with its placeholders resolved ONCE, now, against this
        // document. Never at render time — an approved procedure must keep the project name that
        // was in it when it was approved.
        $content = null;

        if (($templateId = $request->templateId()) !== null) {
            $template = Template::query()->active()->find($templateId);

            if ($template !== null && $user->can('use', $template)) {
                $content = $this->templates->seedContent($template, $this->templates->contextFor($document, $user));
            }
        }

        $this->versions->createNative($document, array_filter([
            'content_html' => $content,
            'change_summary' => 'Initial revision.',
        ], fn ($value) => $value !== null), $user);

        return redirect()->route('documents.show', $document)
            ->with('status', $document->displayName().' has been raised.');
    }

    public function edit(Request $request, Document $document): View
    {
        $this->authorize('update', $document);

        return view('documents.edit', [
            'document' => $document,
            ...$this->formOptions($request, $document),
        ]);
    }

    public function update(DocumentRequest $request, Document $document): RedirectResponse
    {
        $this->authorize('update', $document);

        $data = $request->documentAttributes();

        $this->assertPlacementIsReachable($request, $data);

        $this->documents->update($document, $data, $request->user());

        return redirect()->route('documents.show', $document)
            ->with('status', $document->displayName().' has been updated.');
    }

    // ---------------------------------------------------------------- check-out

    public function checkOut(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('checkOut', $document);

        try {
            $this->documents->checkOut($document, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['lock' => $e->getMessage()]);
        }

        return back()->with('status', 'You have checked out '.$document->displayName().'. Check it back in when you are done.');
    }

    /**
     * Release the lock.
     *
     * `force` is how an administrator recovers a document from somebody who went on leave holding
     * it. The policy allows it only with `document.manage`, and the service records who broke whose
     * lock — a lock that could be broken silently would be a lock nobody trusted (§59).
     */
    public function checkIn(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('checkIn', $document);

        $force = $request->boolean('force') && $request->user()->can('manage', $document);

        try {
            $this->documents->checkIn($document, $request->user(), $force);
        } catch (\DomainException $e) {
            return back()->withErrors(['lock' => $e->getMessage()]);
        }

        return back()->with('status', $document->displayName().' has been checked in.');
    }

    // ---------------------------------------------------------------- lifecycle

    /**
     * Send it for approval.
     *
     * The route is resolved by the engine from configuration, not chosen here. A category with no
     * workflow and no organization default returns null, which is not an error: plenty of registers
     * — correspondence received, vendor data — hold documents nobody approves. The message says so
     * rather than pretending something happened.
     */
    public function submit(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('submit', $document);

        try {
            $instance = $this->documents->submit($document, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['submit' => $e->getMessage()]);
        }

        return redirect()->route('documents.show', $document)->with(
            'status',
            $instance !== null
                ? $document->displayName().' has been submitted for approval.'
                : 'There is no approval route configured for this register, so '.$document->displayName().' needs no approval.'
        );
    }

    public function withdraw(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('withdraw', $document);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $this->documents->withdraw($document, $request->user(), $validated['reason']);
        } catch (\DomainException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', $document->displayName().' has been withdrawn from approval.');
    }

    /**
     * Archive rather than delete.
     *
     * DocumentPolicy refuses to delete a document that has ever had an approved revision — to
     * everybody, with no permission that switches it on. An approved revision is referenced by
     * transmittals, quoted in correspondence and relied on by people outside this system, and
     * deleting it would break references the platform cannot see and cannot repair (§59).
     */
    public function archive(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('archive', $document);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->documents->archive($document, $request->user(), $validated['reason'] ?? null);

        return redirect()->route('documents.index')
            ->with('status', $document->displayName().' has been archived.');
    }

    public function restore(Request $request, int $document): RedirectResponse
    {
        $record = Document::withTrashed()->findOrFail($document);

        $this->authorize('restore', $record);

        $this->documents->restore($record, $request->user());

        return redirect()->route('documents.show', $record)
            ->with('status', $record->displayName().' has been restored.');
    }

    public function destroy(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('delete', $document);

        $name = $document->displayName();

        try {
            $this->documents->delete($document, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['delete' => $e->getMessage()]);
        }

        return redirect()->route('documents.index')->with('status', $name.' has been deleted.');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * The confidentiality axis, in SQL.
     *
     * `document.view` says which documents are within reach; this says which of those the actor is
     * cleared to see the existence of. Owners are always excepted — a person who raised a
     * confidential document does not lose it by lacking the clearance permission.
     *
     * @param  Builder<Document>  $query
     */
    private function applyConfidentiality($query, User $user): void
    {
        if ($user->hasPermission('document.view_confidential')) {
            return;
        }

        $query->where(function ($q) use ($user) {
            $q->whereNotIn('confidentiality', [
                Confidentiality::Confidential->value,
                Confidentiality::Restricted->value,
            ])->orWhere('owner_id', $user->id)
                ->orWhere('created_by', $user->id);
        });
    }

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

        if (($categoryId = $data['document_category_id'] ?? null) !== null) {
            $this->authorize('view', DocumentCategory::findOrFail($categoryId));
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function formOptions(Request $request, ?Document $document): array
    {
        $user = $request->user();

        return [
            'categories' => $this->categoryOptions($request),
            'projects' => $this->projectOptions($request),
            'units' => $this->unitOptions($request),

            // Disciplines ARE org units in this system — Civil/Structural, Piping, Electrical are
            // departments, not a parallel lookup table — so the same reachable list serves both.
            'disciplines' => $this->unitOptions($request),
            'owners' => $this->ownerOptions($request),
            'confidentialities' => Confidentiality::options(),
            'kinds' => DocumentKind::options(),
            'templates' => $document === null ? $this->templateOptions($user) : [],
            'nextNumber' => $document === null ? $this->numbers->preview('document') : null,
        ];
    }

    /**
     * @return array<int|string,string>
     */
    private function templateOptions(User $user): array
    {
        return Template::query()->active()->ofKind('document')
            ->orderByDesc('is_default')->orderBy('name')->get()
            ->filter(fn (Template $t) => $user->can('use', $t))
            ->mapWithKeys(fn (Template $t) => [$t->id => $t->name])
            ->all();
    }

    /**
     * @return array<int|string,string>
     */
    private function categoryOptions(Request $request): array
    {
        return $this->compiler
            ->apply(DocumentCategory::query()->active(), $request->user(), 'document_category.view')
            ->ordered()->get()
            ->mapWithKeys(fn (DocumentCategory $c) => [$c->id => $c->displayName()])
            ->all();
    }

    /**
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
     * @return array<int|string,string>
     */
    private function unitOptions(Request $request): array
    {
        return $this->compiler
            ->apply(OrgUnit::query()->where('is_active', true), $request->user(), 'org_unit.view')
            ->orderBy('path')->get()
            ->mapWithKeys(fn (OrgUnit $u) => [
                $u->id => str_repeat('— ', max(0, (int) $u->depth)).$u->name,
            ])->all();
    }

    /**
     * @return array<int|string,string>
     */
    private function ownerOptions(Request $request): array
    {
        $user = $request->user();

        if (! $user->hasPermission('user.view')) {
            return [$user->id => $user->name];
        }

        return $this->compiler
            ->apply(User::query()->active(), $user, 'user.view')
            ->orderBy('name')->limit(500)
            ->pluck('name', 'id')->all();
    }

    /**
     * @return array<string,string>
     */
    private function viewOptions(): array
    {
        return [
            'active' => 'Everything you can see',
            'mine' => 'Documents I own',
            'drafts' => 'Not yet approved',
            'approved' => 'Approved',
            'in_review' => 'In approval',
            'checked_out' => 'Checked out',
            'archived' => 'Archived',
        ];
    }
}
