<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Configuration\FormCompiler;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\FormSubmissionRequest;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Status;
use App\Services\FormSubmissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Filling in a form the company built (Phase 7, Part 3D).
 *
 * THE FILL-IN SCREEN IS RENDERED FROM THE SUBMISSION'S OWN VERSION, always — never from whichever
 * edition happens to be published today. Somebody who opened an inspection on Tuesday and finishes
 * it on Thursday is answering Tuesday's questions, and a screen that quietly redrew itself after a
 * republish would be asking them to confirm answers to questions they were never shown.
 *
 * NOTHING ON THIS SCREEN DECIDES WHAT IS ACCEPTED. The controls are drawn from the definition and
 * the payload is validated against the definition — separately, by FormCompiler, on the way in. A
 * question hidden by a conditional rule is hidden by CSS on the page and by `appliesTo()` in the
 * compiler, and only the second of those is a control (§6, §84).
 *
 * SIGNING IS A THIRD ACT AGAIN, with its own endpoints. A signature block is a named person putting
 * their name to what the sheet says, bound to a fingerprint of the answers as recorded — so it is
 * posted separately from the answers rather than swept up in the save, and it is available from the
 * record screen as well as the fill-in one, because a supervisor counter-signing a submitted sheet
 * has no business being sent to a page of boxes they may not change.
 *
 * SAVING AND SUBMITTING ARE TWO ENDPOINTS BECAUSE THEY ARE TWO ACTS. Saving records progress;
 * submitting asserts that what the sheet says is true, which on a permit or an inspection is the
 * entire product. They are gated on different permissions for that reason, and the service — not
 * this controller — is what refuses an incomplete submission.
 */
class FormSubmissionController extends Controller
{
    public function __construct(
        private readonly FormSubmissionService $service,
        private readonly FormCompiler $compiler,
        private readonly StatusMachine $statuses,
    ) {}

    /**
     * Everything filled in that this person may see.
     *
     * The access engine has already narrowed the query before a single filter is applied; the
     * filters only narrow it further, and a value nobody may see cannot be revealed by typing it
     * into the search box (§61).
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', FormSubmission::class);

        $submissions = FormSubmission::query()
            ->search($request->string('q')->toString())
            ->forForm($request->integer('form') ?: null)
            ->forProject($request->integer('project') ?: null)
            ->when($request->integer('status') > 0,
                fn ($query) => $query->where('status_id', $request->integer('status')))
            ->when($request->string('view')->toString() === 'mine',
                fn ($query) => $query->where('created_by', $request->user()->getKey()))
            // Four relations, one query each, rather than four per row (§89).
            ->with(['form', 'status', 'project', 'submitter'])
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        return view('form-submissions.index', [
            'submissions' => $submissions,
            'forms' => Form::query()->orderBy('name')->pluck('name', 'id')->all(),
            'statuses' => $this->statuses->optionsFor('form_submission'),
            'filters' => [
                'q' => $request->string('q')->toString(),
                'form' => $request->integer('form') ?: null,
                'project' => $request->integer('project') ?: null,
                'status' => $request->integer('status') ?: null,
                'view' => $request->string('view')->toString(),
            ],
            'projects' => Project::query()->orderBy('name')->pluck('name', 'id')->all(),
            'canCreate' => $request->user()->can('create', FormSubmission::class),
        ]);
    }

    /**
     * Choose which form to fill in.
     *
     * Only forms that are switched on AND have a published edition are offered — the two halves of
     * Form::isSubmittable(), and the second is the one that catches people out. A form somebody
     * built this morning and did not publish is not a form anybody can fill in, and leaving it off
     * this list is how they find that out.
     */
    public function create(Request $request): View
    {
        $this->authorize('create', FormSubmission::class);

        $forms = Form::query()
            ->active()
            ->whereHas('versions', fn ($query) => $query->published())
            ->with('publishedVersion')
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        return view('form-submissions.create', [
            'forms' => $forms,
            'chosen' => $request->integer('form') ?: null,
            'projects' => Project::query()->orderBy('name')->pluck('name', 'id')->all(),
            'orgUnits' => OrgUnit::query()->orderBy('name')->pluck('name', 'id')->all(),
        ]);
    }

    /**
     * Start one.
     *
     * The form id is re-read through the tenant-scoped query rather than bound, so an id belonging
     * to another company finds nothing — the same 404 an outsider gets everywhere else, rather than
     * a message confirming the form exists (§75, §61).
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', FormSubmission::class);

        $validated = $request->validate([
            'form_id' => ['required', 'integer'],
            'project_id' => ['nullable', 'integer'],
            'org_unit_id' => ['nullable', 'integer'],
        ]);

        $form = Form::query()->whereKey($validated['form_id'])->first();

        if ($form === null) {
            return back()->withErrors(['form_id' => 'That is not a form you can fill in.']);
        }

        try {
            $submission = $this->service->start($form, $validated, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['form_id' => $e->getMessage()]);
        }

        return redirect()->route('form-submissions.edit', $submission)
            ->with('status', $submission->number.' has been started. Nothing is submitted until you '
                .'press Submit — save as often as you like before then.');
    }

    /** The finished record: what was asked, and what was answered. */
    public function show(Request $request, FormSubmission $submission): View
    {
        $this->authorize('view', $submission);

        $submission->load(['form', 'status', 'project', 'orgUnit', 'creator', 'submitter',
            'version.sections', 'version.fields', 'answers', 'signatures']);

        // The route this sheet is travelling, if it is travelling one. Loaded the way every other
        // routable register loads it — the frozen stage copies and the decisions written against
        // them, in two queries rather than one per stage (§89).
        $instance = $submission->currentWorkflowInstance()?->load([
            'currentStageInstance.stage',
            'stageInstances' => fn ($q) => $q->with(['stage', 'approvals.user:id,name'])->orderBy('sequence'),
        ]);

        return view('form-submissions.show', [
            'submission' => $submission,
            'version' => $submission->version,
            'instance' => $instance,
            'values' => $this->compiler->valuesFor($submission),
            'compiler' => $this->compiler,
            'transitions' => $this->statuses->available(
                'form_submission', $submission->status_id, $request->user()
            ),
            'editable' => $this->service->isEditable($submission)
                && $request->user()->can('update', $submission),
            'canDelete' => $request->user()->can('delete', $submission),
            'canReview' => $request->user()->can('review', $submission),
            'canSign' => $request->user()->can('sign', $submission),
            // What a signature written right now would be bound to. The panel compares every
            // signature against it, so a sheet edited after somebody signed says so in words.
            'hash' => $this->compiler->contentHash($submission),
            'actorId' => $request->user()->getKey(),
            // A closed sheet takes no more signatures. A submitted one does — counter-signing after
            // submission is the ordinary case, and the reason `submit` and `sign` are separate.
            'signable' => ! $submission->isClosed(),
        ]);
    }

    /**
     * The fill-in screen.
     *
     * A submitted sheet still renders here rather than redirecting: somebody following a link from
     * their own timeline should land on something that explains why the boxes are gone, not be
     * bounced to a page they did not ask for. The controls are read-only and every write path
     * refuses independently.
     */
    public function edit(Request $request, FormSubmission $submission): View
    {
        $this->authorize('update', $submission);

        $submission->load(['form', 'status', 'project', 'version.sections', 'version.fields',
            'answers', 'signatures']);

        return view('form-submissions.edit', [
            'submission' => $submission,
            'version' => $submission->version,
            'values' => $this->compiler->valuesFor($submission),
            'compiler' => $this->compiler,
            'editable' => $this->service->isEditable($submission),
            'missing' => $this->compiler->missingRequired($submission),
            'canSubmit' => $request->user()->can('submit', $submission),
            'canSign' => $request->user()->can('sign', $submission),
            'hash' => $this->compiler->contentHash($submission),
            'actorId' => $request->user()->getKey(),
            'signable' => ! $submission->isClosed(),
        ]);
    }

    /** Save progress. */
    public function update(FormSubmissionRequest $request, FormSubmission $submission): RedirectResponse
    {
        $this->authorize('update', $submission);

        try {
            $this->service->saveAnswers($submission, $request->answers(), $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['answers' => $e->getMessage()]);
        }

        return redirect()->route('form-submissions.edit', $submission)
            ->with('status', 'Saved. Nothing has been submitted yet.');
    }

    /**
     * Assert that what this says is true.
     *
     * Gated on `submit` rather than `update`, which is the reason FormSubmissionPolicy has the
     * ability at all: a technician who records readings and a supervisor who signs the sheet off
     * are an arrangement companies routinely want, and one permission for both makes it
     * unexpressible.
     */
    public function submit(FormSubmissionRequest $request, FormSubmission $submission): RedirectResponse
    {
        $this->authorize('submit', $submission);

        try {
            $this->service->submit($submission, $request->answers(), $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['answers' => $e->getMessage()]);
        }

        return redirect()->route('form-submissions.show', $submission)
            ->with('status', $submission->displayName().' has been submitted.');
    }

    /**
     * Put your name to what this sheet says.
     *
     * THE BLOCK IS FOUND THROUGH THE SUBMISSION, NEVER BOUND FROM THE URL. `{field}` is a number
     * somebody can type, so it is looked for among the fields of this submission's own edition and
     * nowhere else — another form's signature block, or another company's, simply is not there
     * (§84, §75). The service re-checks the same thing, because a second path in must not depend on
     * this controller having been careful.
     */
    public function sign(Request $request, FormSubmission $submission, string $field): RedirectResponse
    {
        $this->authorize('sign', $submission);

        return $this->signing($request, $submission, $field, false);
    }

    /** Take your own signature back. A new event, never an edit — see the service. */
    public function withdrawSignature(Request $request, FormSubmission $submission, string $field): RedirectResponse
    {
        $this->authorize('sign', $submission);

        return $this->signing($request, $submission, $field, true);
    }

    /**
     * One body for both, so signing and withdrawing can never resolve the block differently.
     */
    private function signing(
        Request $request,
        FormSubmission $submission,
        string $field,
        bool $withdrawing,
    ): RedirectResponse {
        $submission->load(['version.fields', 'answers', 'signatures']);

        $block = $this->compiler->fieldsFor($submission->version)
            ->first(fn ($one) => (int) $one->getKey() === (int) $field && $one->isSignature());

        if ($block === null) {
            return back()->withErrors(['signature' => 'That is not a signature block on this form.']);
        }

        try {
            $withdrawing
                ? $this->service->withdrawSignature($submission, $block, $request->user())
                : $this->service->sign($submission, $block, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['signature' => $e->getMessage()]);
        }

        return back()->with('status', $withdrawing
            ? 'Your signature on "'.$block->label.'" has been withdrawn. The record of it stays.'
            : 'You have signed "'.$block->label.'".');
    }

    /** Send a submitted sheet back to whoever filled it in. */
    public function sendBack(Request $request, FormSubmission $submission): RedirectResponse
    {
        $this->authorize('review', $submission);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $this->service->returnForCorrection($submission, $request->user(), $validated['reason']);
        } catch (\DomainException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('form-submissions.show', $submission)
            ->with('status', 'Sent back for correction. Whoever filled it in can edit it again.');
    }

    /** Any other move the status graph allows from here. */
    public function transition(Request $request, FormSubmission $submission): RedirectResponse
    {
        $this->authorize('review', $submission);

        $validated = $request->validate([
            'status_id' => ['required', 'integer'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $to = Status::query()->forEntity('form_submission')->whereKey($validated['status_id'])->first();

        if ($to === null) {
            return back()->withErrors(['status_id' => 'That is not a status a submission can be in.']);
        }

        try {
            // The machine re-checks the whole move server-side — that this transition exists from
            // the current status, that this actor holds the permission it names, and that a reason
            // was given where one is demanded. The select box is a convenience (§84).
            $this->statuses->transition($submission, $to, $request->user(), $validated['comment'] ?? null);
        } catch (\DomainException $e) {
            return back()->withErrors(['status_id' => $e->getMessage()]);
        }

        return redirect()->route('form-submissions.show', $submission)
            ->with('status', $submission->displayName().' is now '.$to->label.'.');
    }

    public function destroy(Request $request, FormSubmission $submission): RedirectResponse
    {
        $this->authorize('delete', $submission);

        $name = $submission->displayName();

        try {
            $this->service->delete($submission, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['submission' => $e->getMessage()]);
        }

        return redirect()->route('form-submissions.index')->with('status', $name.' has been deleted.');
    }
}
