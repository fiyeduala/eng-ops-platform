<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Template\TemplateRenderer;
use App\Http\Requests\TemplateRequest;
use App\Models\DocumentCategory;
use App\Models\Template;
use App\Services\TemplateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Templates (§36, §46) — the house style, owned by the company rather than by whoever last saved a
 * .docx to the shared drive.
 *
 * A template carries the letterhead, the page setup, the standard clauses and the placeholders. A
 * document created from one starts with that content already in it, and the placeholders are
 * resolved ONCE, at that moment. Never at render time: an approved procedure must still say the
 * project name it was approved under, even after the project is renamed. That single decision is
 * what separates a template from a mail merge, and it lives in TemplateService::seedContent().
 *
 * PREVIEW IS DELIBERATELY NOT RENDERED AGAINST A LIVE RECORD. It renders with the placeholder names
 * standing and a PREVIEW watermark. Rendering an administrator's template against a real document to
 * "see how it looks" would put that document's content behind a template permission, which is not
 * the permission that governs the document (§61).
 */
class TemplateController extends Controller
{
    public function __construct(
        private readonly ScopeCompiler $compiler,
        private readonly ActivityRecorder $activity,
        private readonly TemplateService $templates,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Template::class);

        $user = $request->user();

        $query = $this->compiler->apply(Template::query(), $user, 'template.view')
            ->with(['category:id,name', 'creator:id,name']);

        if ($kind = (string) $request->query('kind', '')) {
            $query->ofKind($kind);
        }

        if (! $request->boolean('inactive')) {
            $query->active();
        }

        return view('templates.index', [
            'templates' => $query->orderBy('kind')->orderByDesc('is_default')->orderBy('name')
                ->paginate(25)->withQueryString(),
            'kinds' => Template::KINDS,
            'filters' => ['kind' => $request->query('kind'), 'inactive' => $request->boolean('inactive')],
            'canManage' => $user->can('create', Template::class),
        ]);
    }

    public function show(Request $request, Template $template): View
    {
        $this->authorize('view', $template);

        $template->loadMissing(['category:id,name', 'creator:id,name']);

        return view('templates.show', [
            'template' => $template,
            'placeholders' => TemplateRenderer::available(),
            'timeline' => $this->activity->timelineFor($template)->limit(20)->get(),
            'canEdit' => $request->user()->can('update', $template),
            'canDuplicate' => $request->user()->can('duplicate', $template),
            'canDelete' => $request->user()->can('delete', $template),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Template::class);

        return view('templates.create', [
            'template' => new Template([
                'kind' => (string) $request->query('kind', 'document'),
                'page_size' => 'A4',
                'orientation' => 'portrait',
                'is_active' => true,
            ]),
            ...$this->formOptions($request),
        ]);
    }

    public function store(TemplateRequest $request): RedirectResponse
    {
        $this->authorize('create', Template::class);

        $template = $this->templates->create($request->templateAttributes(), $request->user());

        return redirect()->route('templates.show', $template)
            ->with('status', $template->name.' has been created.');
    }

    public function edit(Request $request, Template $template): View
    {
        $this->authorize('update', $template);

        return view('templates.edit', [
            'template' => $template,
            ...$this->formOptions($request),
        ]);
    }

    public function update(TemplateRequest $request, Template $template): RedirectResponse
    {
        $this->authorize('update', $template);

        $this->templates->update($template, $request->templateAttributes(), $request->user());

        return redirect()->route('templates.show', $template)
            ->with('status', $template->name.' has been updated.');
    }

    /**
     * Copy a template.
     *
     * How a house style actually gets built: start from the letterhead that works, change the two
     * things that differ for this register. Copies are born inactive and never inherit `is_default`,
     * so duplicating the standard letter cannot silently replace it.
     */
    public function duplicate(Request $request, Template $template): RedirectResponse
    {
        $this->authorize('duplicate', $template);

        $copy = $this->templates->duplicate($template, $request->user(), $request->string('name')->value() ?: null);

        return redirect()->route('templates.edit', $copy)
            ->with('status', 'Copied. This is a new draft template — activate it when it is ready.');
    }

    public function activate(Request $request, Template $template): RedirectResponse
    {
        $this->authorize('update', $template);

        $active = $request->boolean('active');

        $this->templates->activate($template, $active);

        return back()->with(
            'status',
            $active
                ? $template->name.' is now available when creating documents.'
                : $template->name.' has been retired. Documents already created from it are unaffected.'
        );
    }

    /**
     * Render the template as a PDF, with placeholder names standing.
     *
     * Inline rather than as a download: this is a look, not a deliverable.
     */
    public function preview(Request $request, Template $template): Response
    {
        $this->authorize('preview', $template);

        return response($this->templates->preview($template), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="template-preview.pdf"',
        ]);
    }

    public function destroy(Request $request, Template $template): RedirectResponse
    {
        $this->authorize('delete', $template);

        $name = $template->name;

        $this->templates->delete($template, $request->user());

        return redirect()->route('templates.index')->with('status', $name.' has been deleted.');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @return array<string,mixed>
     */
    private function formOptions(Request $request): array
    {
        return [
            'kinds' => Template::KINDS,
            'pageSizes' => Template::PAGE_SIZES,
            'orientations' => ['portrait' => 'Portrait', 'landscape' => 'Landscape'],
            'placeholders' => TemplateRenderer::available(),
            'categories' => $this->compiler
                ->apply(DocumentCategory::query()->active(), $request->user(), 'document_category.view')
                ->ordered()->get()
                ->mapWithKeys(fn (DocumentCategory $c) => [$c->id => $c->displayName()])
                ->all(),
        ];
    }
}
