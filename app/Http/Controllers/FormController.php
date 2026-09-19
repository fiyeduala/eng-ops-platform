<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\BelongsToForm;
use App\Http\Requests\FormDefinitionRequest;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormVersion;
use App\Services\FormDefinitionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The form builder — how a company asks its own questions without a developer (§73).
 *
 * THE BUILDER IS ONE PAGE, and that is a deliberate departure from the custom-field editor it
 * otherwise resembles. A form is read as a whole: "is this question already on here, and is it in
 * the right section?" is the only question anybody building one has, and a screen that answers it
 * by making you click into each section answers it badly. So sections, questions and the per-type
 * settings all live on `show`, and what changes between visits is only WHICH VERSION you are
 * looking at.
 *
 * WHICH VERSION IS THE ONE PIECE OF STATE THIS CONTROLLER OWNS. It comes from `?version=`, and it
 * is re-read against the form rather than trusted: an id from another company's form would
 * otherwise render that company's questions on this page, which is the plainest possible §75
 * failure and the plainest possible §84 one at the same time. The default is the draft if there is
 * one, then the published edition, then the newest thing there is — because the person arriving
 * here is either building or reading, and the draft is the answer to "building".
 *
 * NOTHING ON THIS PAGE DECIDES WHETHER AN EDIT IS ALLOWED. The builder greys out what a published
 * version will refuse, and FormDefinitionService refuses it anyway. Greying is the courtesy; the
 * service is the control (§6, §84).
 */
class FormController extends Controller
{
    use BelongsToForm;

    public function __construct(
        private readonly FormDefinitionService $service,
    ) {}

    /**
     * Every form this company has built.
     *
     * Eager-loads the two versions the list shows a badge for, and counts submissions in one
     * query rather than one per row (§89) — a company with forty forms is not unusual and forty
     * round trips for a list page is how a cPanel-hosted install starts feeling broken.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Form::class);

        $forms = Form::query()
            ->search($request->string('q')->toString())
            ->when($request->string('category')->toString() !== '',
                fn ($query) => $query->where('category', $request->string('category')->toString()))
            ->with(['publishedVersion', 'draftVersion'])
            ->withCount('submissions')
            ->orderBy('category')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('forms.index', [
            'forms' => $forms,
            'search' => $request->string('q')->toString(),
            'category' => $request->string('category')->toString(),
            // Distinct over the scoped query, so the filter offers only drawers this viewer can
            // actually see into — a category name is itself a disclosure (§61).
            'categories' => Form::query()->whereNotNull('category')
                ->distinct()->orderBy('category')->pluck('category')->all(),
            'canCreate' => $request->user()->can('create', Form::class),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Form::class);

        return view('forms.create');
    }

    public function store(FormDefinitionRequest $request): RedirectResponse
    {
        $this->authorize('create', Form::class);

        try {
            $form = $this->service->createForm($request->formAttributes(), $request->user());
        } catch (\DomainException $e) {
            // withInput() is safe here and nowhere else in this module: `create` is a page with one
            // form on it, so repopulating cannot bleed into a neighbouring row.
            return back()->withInput()->withErrors(['form' => $e->getMessage()]);
        }

        return redirect()->route('forms.show', $form)->with('status',
            '"'.$form->name.'" has been created as a draft. Add the questions it should ask, then '
            .'publish it — nobody can fill it in until you do.');
    }

    /**
     * The builder, showing one version.
     *
     * @see the class docblock for why `?version=` is re-read rather than bound.
     */
    public function show(Request $request, Form $form): View
    {
        $this->authorize('view', $form);

        $version = $this->versionOnScreen($form, $request->query('version'));

        // Loaded in two queries rather than one per section: sections, then every field on the
        // version keyed by section, then the table columns keyed by parent (§89).
        $sections = $version?->sections()->get() ?? collect();
        $fields = $version?->fields()->get() ?? collect();

        return view('forms.show', [
            'form' => $form,
            'version' => $version,
            'versions' => $form->versions()->orderByDesc('version')->get(),
            'sections' => $sections,
            'fieldsBySection' => $fields->whereNull('parent_field_id')->groupBy('form_section_id'),
            'columnsByParent' => $fields->whereNotNull('parent_field_id')->groupBy('parent_field_id'),
            'types' => FormField::TYPES,
            'optionLists' => $this->service->optionLists(),
            'submissions' => $this->service->submissionCount($form),
            'canEdit' => $request->user()->can('update', $form),
            'canPublish' => $request->user()->can('publish', $form),
            'canDelete' => $request->user()->can('delete', $form),
        ]);
    }

    /** Rename, re-describe, re-file or switch a form back on. */
    public function update(FormDefinitionRequest $request, Form $form): RedirectResponse
    {
        $this->authorize('update', $form);

        return $this->attempt($form, fn () => '"'.$this->service
            ->updateForm($form, $request->formAttributes(), $request->user())
            ->name.'" has been updated.');
    }

    /**
     * Delete a form nobody has filled in.
     *
     * The service refuses the moment a submission exists, and says to switch the form off instead.
     * That refusal is the whole reason this action is survivable: deleting a form with submissions
     * would delete the answers, and the answers are frequently the record of an inspection that
     * somebody signed.
     */
    public function destroy(Request $request, Form $form): RedirectResponse
    {
        $this->authorize('delete', $form);

        $name = $form->name;

        try {
            $this->service->deleteForm($form, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['form' => $e->getMessage()]);
        }

        return redirect()->route('forms.index')->with('status', '"'.$name.'" has been deleted.');
    }

    // ================================================================== versions

    /**
     * Start editing — or carry on editing the draft that is already open.
     *
     * Deliberately idempotent, because the button that leads here is on a page somebody may have
     * had open in two tabs since yesterday. The service hands back the existing draft rather than
     * opening a second one; two drafts would mean two people editing in different directions with
     * only the loser finding out.
     */
    public function openDraft(Request $request, Form $form): RedirectResponse
    {
        $this->authorize('update', $form);

        try {
            $draft = $this->service->openDraft($form, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['form' => $e->getMessage()]);
        }

        return redirect()->route('forms.show', ['form' => $form, 'version' => $draft->getKey()])
            ->with('status', $draft->version === 1
                ? 'This is version 1, still a draft. Nobody can fill it in until you publish it.'
                : 'Version '.$draft->version.' is a copy of the published edition, open for editing. '
                  .'The live edition keeps serving people until you publish this one.');
    }

    /** Throw away a draft and everything in it. Refused on anything published. */
    public function discardDraft(Request $request, Form $form, FormVersion $version): RedirectResponse
    {
        $this->authorize('update', $form);

        $this->assertVersionOf($form, $version);

        try {
            $this->service->discardDraft($version, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['form' => $e->getMessage()]);
        }

        return redirect()->route('forms.show', $form)
            ->with('status', 'The draft has been discarded. Nothing that was already submitted is affected.');
    }

    /**
     * Make this edition the one people fill in, and freeze it.
     *
     * Gated on `form.publish` rather than `form.edit`, which is the reason FormPolicy exists at
     * all: drafting an inspection sheet and deciding that every site supervisor in the company
     * fills it in tomorrow are different authorities, and plenty of companies want them held by
     * different people.
     */
    public function publish(Request $request, Form $form, FormVersion $version): RedirectResponse
    {
        $this->authorize('publish', $form);

        $this->assertVersionOf($form, $version);

        $validated = $request->validate([
            'change_note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $published = $this->service->publish(
                $version, $request->user(), $validated['change_note'] ?? null
            );
        } catch (\DomainException $e) {
            return back()->withErrors(['form' => $e->getMessage()]);
        }

        return redirect()->route('forms.show', ['form' => $form, 'version' => $published->getKey()])
            ->with('status', 'Version '.$published->version.' is live. These questions are now frozen — '
                .'everything already submitted keeps the questions it was asked.');
    }

    /** Take a live form out of service, keeping every submission ever made against it. */
    public function retire(Request $request, Form $form): RedirectResponse
    {
        $this->authorize('retire', $form);

        try {
            $this->service->retire($form, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['form' => $e->getMessage()]);
        }

        return redirect()->route('forms.show', $form)->with('status',
            '"'.$form->name.'" is out of service. Nobody can start a new one; everything already '
            .'submitted stays exactly where it is.');
    }

    // ================================================================== plumbing

    /**
     * Which edition the builder is showing.
     *
     * A version id in a query string is a claim, not evidence. It is looked up WITHIN the form —
     * `$form->versions()` — so an id belonging to another form, or to another company's form,
     * finds nothing and falls back rather than rendering somebody else's questions (§75, §84).
     */
    private function versionOnScreen(Form $form, mixed $requested): ?FormVersion
    {
        // `publisher` is eager-loaded because the banner names whoever pressed Publish, and lazy
        // loading is disabled outside production — a relation read in Blade that was not asked
        // for in the controller is a query per render in the best case and an exception here.
        $versions = $form->versions()->with('publisher');

        if (is_numeric($requested)) {
            $chosen = (clone $versions)->whereKey((int) $requested)->first();

            if ($chosen !== null) {
                return $chosen;
            }
        }

        return (clone $versions)->draft()->first()
            ?? (clone $versions)->published()->first()
            ?? (clone $versions)->orderByDesc('version')->first();
    }

    /**
     * Run a write and turn a refusal into a sentence on the builder.
     *
     * @param  callable():string  $write
     */
    private function attempt(Form $form, callable $write): RedirectResponse
    {
        return $this->attemptOnBuilder($form, null, $write);
    }
}
