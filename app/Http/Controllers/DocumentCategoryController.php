<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Document\FileVault;
use App\Engines\Document\RevisionSequencer;
use App\Http\Requests\DocumentCategoryRequest;
use App\Models\DocumentCategory;
use App\Models\Workflow;
use App\Support\Enums\Confidentiality;
use App\Support\Enums\DocumentKind;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Registers (§26) — the configuration that makes one company's document control different from
 * another's, without either of them getting a different build.
 *
 * A register decides how its documents are numbered, how revisions are labelled (A/B/C, 0/1/2,
 * 1.0/1.1, with or without a P-prefixed draft series), what may be uploaded, how big, whether the
 * documents are controlled, and which approval route they take. Two engineering firms with entirely
 * different document-control standards both configure them here, and neither needs a developer.
 * That is §4 and §5 in one screen.
 *
 * ADMINISTRATION, DELIBERATELY. `document_category.manage` covers create, edit and delete — there is
 * no separate "may edit numbering" permission, because anybody trusted to define a register is
 * trusted with all of it, and inventing finer permissions here would produce a role matrix nobody
 * can reason about.
 *
 * DELETION IS REFUSED WHILE THE REGISTER HOLDS ANYTHING. DocumentCategoryPolicy refuses when
 * documents or child registers exist, and it is not overridable: deleting a register that holds
 * documents would either orphan them or cascade them away, and neither is something an
 * administrator should be able to do by pressing one button (§59). Deactivating is the answer —
 * it stops new documents being raised into it and leaves the existing ones exactly where they are.
 */
class DocumentCategoryController extends Controller
{
    public function __construct(
        private readonly ScopeCompiler $compiler,
        private readonly ActivityRecorder $activity,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', DocumentCategory::class);

        $user = $request->user();

        $categories = $this->compiler
            ->apply(DocumentCategory::query(), $user, 'document_category.view')
            ->with(['parent:id,name', 'workflow:id,name,version'])
            ->withCount(['documents', 'children'])
            ->ordered()
            ->get();

        return view('document_categories.index', [
            'categories' => $categories,
            'canManage' => $user->can('create', DocumentCategory::class),
        ]);
    }

    public function show(Request $request, DocumentCategory $documentCategory): View
    {
        $this->authorize('view', $documentCategory);

        $documentCategory->loadMissing(['parent', 'workflow', 'children', 'creator:id,name']);

        return view('document_categories.show', [
            'category' => $documentCategory,
            'extensions' => $documentCategory->uploadExtensions() ?? FileVault::DEFAULT_EXTENSIONS,
            'timeline' => $this->activity->timelineFor($documentCategory)->limit(20)->get(),
            'canEdit' => $request->user()->can('update', $documentCategory),
            'canDelete' => $request->user()->can('delete', $documentCategory),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', DocumentCategory::class);

        return view('document_categories.create', [
            'category' => new DocumentCategory([
                'revision_scheme' => RevisionSequencer::SCHEME_ALPHA,
                'default_confidentiality' => Confidentiality::Internal->value,
                'default_kind' => DocumentKind::Native->value,
                'is_active' => true,
            ]),
            ...$this->formOptions($request, null),
        ]);
    }

    public function store(DocumentCategoryRequest $request): RedirectResponse
    {
        $this->authorize('create', DocumentCategory::class);

        $data = $request->validated();
        $user = $request->user();

        $category = new DocumentCategory($data);
        $category->organization_id = $user->organization_id;
        $category->created_by = $user->getKey();
        $category->slug = $this->slugFor($data, null);
        $category->save();

        $this->activity->record(
            $category,
            'created',
            $user->name.' created the '.$category->name.' register.',
            ['revision_scheme' => $category->revision_scheme],
            $user,
        );

        return redirect()->route('document-categories.show', $category)
            ->with('status', $category->name.' has been created.');
    }

    public function edit(Request $request, DocumentCategory $documentCategory): View
    {
        $this->authorize('update', $documentCategory);

        return view('document_categories.edit', [
            'category' => $documentCategory,
            ...$this->formOptions($request, $documentCategory),
        ]);
    }

    public function update(DocumentCategoryRequest $request, DocumentCategory $documentCategory): RedirectResponse
    {
        $this->authorize('update', $documentCategory);

        $data = $request->validated();

        // A register cannot become its own ancestor. Checked here rather than in the form request
        // because it needs the existing tree, and a self-parented register is an infinite loop in
        // every screen that renders the hierarchy.
        if ($this->wouldCycle($documentCategory, $data['parent_id'] ?? null)) {
            return back()->withInput()->withErrors([
                'parent_id' => 'A register cannot sit inside itself or one of its own children.',
            ]);
        }

        $data['slug'] = $this->slugFor($data, $documentCategory);

        $documentCategory->fill($data)->save();

        $this->activity->record(
            $documentCategory,
            'updated',
            $request->user()->name.' updated the '.$documentCategory->name.' register.',
            [],
            $request->user(),
        );

        return redirect()->route('document-categories.show', $documentCategory)
            ->with('status', $documentCategory->name.' has been updated.');
    }

    public function destroy(Request $request, DocumentCategory $documentCategory): RedirectResponse
    {
        $this->authorize('delete', $documentCategory);

        $name = $documentCategory->name;

        $this->activity->record(
            $documentCategory,
            'deleted',
            $request->user()->name.' deleted the empty '.$name.' register.',
            [],
            $request->user(),
        );

        $documentCategory->delete();

        return redirect()->route('document-categories.index')
            ->with('status', $name.' has been deleted.');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param  array<string,mixed>  $data
     */
    private function slugFor(array $data, ?DocumentCategory $category): string
    {
        $slug = filled($data['slug'] ?? null)
            ? Str::slug((string) $data['slug'])
            : Str::slug((string) ($data['name'] ?? $category?->name ?? 'register'));

        $base = $slug;
        $n = 2;

        while (DocumentCategory::query()
            ->where('slug', $slug)
            ->when($category !== null, fn ($q) => $q->whereKeyNot($category->getKey()))
            ->exists()
        ) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    /** Would setting this parent put the register inside its own subtree? */
    private function wouldCycle(DocumentCategory $category, mixed $parentId): bool
    {
        if ($parentId === null) {
            return false;
        }

        $parentId = (int) $parentId;

        if ($parentId === (int) $category->getKey()) {
            return true;
        }

        $seen = 0;
        $cursor = DocumentCategory::query()->find($parentId);

        // The depth guard is not decoration: it stops a tree that is ALREADY cyclic — from a bad
        // import, say — turning this check into a hang.
        while ($cursor !== null && $seen++ < 50) {
            if ((int) $cursor->getKey() === (int) $category->getKey()) {
                return true;
            }

            $cursor = $cursor->parent_id !== null
                ? DocumentCategory::query()->find($cursor->parent_id)
                : null;
        }

        return false;
    }

    /**
     * @return array<string,mixed>
     */
    private function formOptions(Request $request, ?DocumentCategory $category): array
    {
        $user = $request->user();

        return [
            'parents' => $this->compiler
                ->apply(DocumentCategory::query()->active(), $user, 'document_category.view')
                ->when($category !== null, fn ($q) => $q->whereKeyNot($category->getKey()))
                ->ordered()->get()
                ->mapWithKeys(fn (DocumentCategory $c) => [$c->id => $c->displayName()])
                ->all(),

            // Only published routes are offered. A register pointed at a draft would send its first
            // document to a route that does not exist yet.
            'workflows' => $this->compiler
                ->apply(Workflow::query(), $user, 'workflow.view')
                ->forEntity('document')->usable()
                ->orderBy('name')->get()
                ->mapWithKeys(fn (Workflow $w) => [$w->id => $w->displayName()])
                ->all(),

            'schemes' => RevisionSequencer::SCHEMES,
            'confidentialities' => Confidentiality::options(),
            'kinds' => DocumentKind::options(),
            'forbiddenExtensions' => FileVault::FORBIDDEN_EXTENSIONS,
            'defaultExtensions' => FileVault::DEFAULT_EXTENSIONS,
        ];
    }
}
