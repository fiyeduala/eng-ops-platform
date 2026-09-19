<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Access\OrganizationContext;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\FormCompiler;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Engines\Workflow\WorkflowEngine;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormSubmission;
use App\Models\FormVersion;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Signature;
use App\Models\Status;
use App\Models\User;
use App\Models\WorkflowInstance;
use Illuminate\Support\Facades\DB;

/**
 * Filling a form in, and the moment that stops being reversible (Phase 7, Part 3D).
 *
 * NOT A RegisterService SUBCLASS, and the omission is the same one FormSubmission makes about
 * RegisterRecord. That base class carries a discipline, a confidentiality level, a due date and a
 * workflow, none of which a submission has — a submission is about whatever its form is about, and
 * giving one an engineering discipline would put "Piping" on a visitor sign-in sheet. What it does
 * borrow is the doctrine, not the code: numbering from the engine, status from the machine,
 * organization_id from context and never from input (§74).
 *
 * THE VERSION IS CHOSEN ONCE, HERE, AND NEVER AGAIN. `start()` binds the submission to whichever
 * edition is published at that moment, and nothing in this class ever moves it. Somebody who opens
 * an inspection on Tuesday and finishes it on Thursday answers Tuesday's questions, even if the
 * form was republished on Wednesday — because the alternative is a half-finished form silently
 * changing its questions under the person holding the tablet.
 *
 * SUBMITTING IS NOT A STATUS CHANGE, and the status catalogue deliberately has no draft → submitted
 * transition to make that structural. Submitting validates every answer against the version's
 * rules, refuses an incomplete one, stamps who and when, and closes the answers to editing. A
 * transition row would put all of that behind a select box that skipped it (§84). The only door is
 * submit(), and the permission on it is `form.submit` rather than `form.edit` — recording readings
 * and asserting the sheet is true are different acts, and companies routinely want different people
 * doing them.
 *
 * SIGNING IS NOT SUBMITTING EITHER, and both of those are separate from approving. A signature
 * block is a named person putting their name to what the sheet says at a named moment; it is
 * written to the document module's own Signature table, bound to a hash of the answers, and never
 * edited. A required block that nobody has signed — or that somebody signed before the answers
 * moved — is counted as missing, so a permit cannot be submitted with an approval block that only
 * looks satisfied (§28).
 *
 * WHAT MAY STILL BE EDITED is asked in one place — `isEditable()` — rather than in the policy,
 * because it is a question about the record's state and not about the person. A policy answer
 * would have to be repeated by every non-policy path: an importer, a workflow action, a future API.
 * Ask it here and they all get it.
 */
final class FormSubmissionService
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly FormCompiler $compiler,
        private readonly NumberGenerator $numbers,
        private readonly StatusMachine $statuses,
        private readonly ActivityRecorder $activity,
        private readonly WorkflowEngine $workflow,
    ) {}

    // ================================================================== starting one

    /**
     * Open a blank submission against whatever edition of a form is live right now.
     *
     * REFUSED ON A FORM NOBODY CAN FILL IN, and the two halves of that are different mistakes worth
     * telling apart: a form that was never published has nothing to offer, and a form taken out of
     * service is no longer being offered. A person who built a form yesterday and cannot find it
     * today is almost always looking at the first.
     *
     * @param  array<string,mixed>  $attributes
     *
     * @throws \DomainException
     */
    public function start(Form $form, array $attributes, User $actor): FormSubmission
    {
        if (! $form->is_active) {
            throw new \DomainException(
                '"'.$form->name.'" has been taken out of service, so no new ones can be started. '
                .'Everything already submitted against it is still there.'
            );
        }

        $version = $form->versions()->published()->first();

        if ($version === null) {
            throw new \DomainException(
                '"'.$form->name.'" has not been published yet, so there is nothing to fill in. '
                .'Open it in the form builder and publish the draft first.'
            );
        }

        return DB::transaction(function () use ($form, $version, $attributes, $actor) {
            $submission = new FormSubmission([
                'project_id' => $this->projectFor($attributes['project_id'] ?? null),
                'org_unit_id' => $this->orgUnitFor($attributes['org_unit_id'] ?? null),
            ]);

            // §74, and the three that go with it. None of these may come from the payload: the
            // company from context, the edition from the form, the author from the session.
            $submission->organization_id = $this->context->idOrFail();
            $submission->form_id = $form->getKey();
            $submission->form_version_id = $version->getKey();
            $submission->created_by = $actor->getKey();

            // §64, and the obligation Part 3A took on: the form's key rides along as a token, so a
            // company that wants the form named in the number adds {FORM} to the pattern on the
            // numbering screen and gets it without a line of code being written (§73).
            $submission->number = $this->numbers->next('form_submission', ['FORM' => $form->key]);

            $submission->status_id = $this->statuses->initialFor('form_submission')?->id;

            $submission->save();

            $this->activity->record($submission, 'created',
                $actor->name.' started a '.$form->name.'.',
                ['number' => $submission->number, 'version' => $version->version], $actor,
                $submission->project_id, $submission->org_unit_id);

            return $submission;
        });
    }

    // ================================================================== filling it in

    /**
     * Save what has been filled in so far.
     *
     * The answers are validated by the compiler at the request boundary; what this method owns is
     * whether the submission accepts writes at all. A submitted sheet does not — see isEditable().
     *
     * @param  array<string,mixed>  $answers
     *
     * @throws \DomainException
     */
    public function saveAnswers(FormSubmission $submission, array $answers, User $actor): FormSubmission
    {
        $this->assertEditable($submission);

        return DB::transaction(function () use ($submission, $answers, $actor) {
            $changed = $this->compiler->save($submission, $answers);

            if ($changed !== []) {
                // Deliberately not a list of what was typed. An activity entry is readable by
                // anybody who can see the record's timeline, and the answers on a form are
                // frequently the sensitive part — an incident account, somebody's health
                // declaration (§68). The timeline says that answers changed and which questions;
                // the answers themselves stay behind the submission's own authorization.
                $this->activity->record($submission, 'updated',
                    $actor->name.' updated '.count($changed).' '
                    .str('answer')->plural(count($changed)).'.',
                    ['questions' => $changed], $actor,
                    $submission->project_id, $submission->org_unit_id);
            }

            return $submission->refresh();
        });
    }

    /**
     * Assert that what this says is true.
     *
     * THE ANSWERS ARE SAVED AND COMMITTED FIRST, AND THEN JUDGED. Doing it the other way — judging
     * the payload, then writing — means a submission that fails the completeness check loses
     * whatever the person had just typed, which on a fifty-question sheet filled in on a tablet in
     * a yard is the kind of loss people do not come back from. Wrapping both in one transaction is
     * the same mistake wearing a better disguise: the exception would roll the answers back with
     * it. So the write commits, and only the stamping is transactional.
     *
     * @param  array<string,mixed>  $answers
     *
     * @throws \DomainException
     */
    public function submit(FormSubmission $submission, array $answers, User $actor): FormSubmission
    {
        $this->assertEditable($submission);

        // OUTSIDE THE TRANSACTION BELOW, AND THAT IS THE POINT. The answers are committed before
        // the sheet is judged, so a refusal leaves everything typed exactly where the person put
        // it. Saving inside the same transaction as the check would roll the answers back with the
        // exception — on a fifty-question sheet filled in on a tablet in a yard, a refusal that
        // also wipes the form is a loss people do not come back from.
        $this->compiler->save($submission, $answers);

        $submission->refresh()->load(['version.fields', 'answers', 'signatures']);

        $missing = $this->compiler->missingRequired($submission);

        if ($missing !== []) {
            throw new \DomainException(
                'This cannot be submitted yet — '.$this->listOf($missing).' still '
                .(count($missing) === 1 ? 'needs' : 'need').' an answer.'
            );
        }

        $submission = DB::transaction(function () use ($submission, $actor) {
            $submission->submitted_by = $actor->getKey();
            $submission->submitted_at = now();
            $submission->status_id = $this->statusNamed('submitted')?->id ?? $submission->status_id;
            $submission->save();

            $this->activity->record($submission, 'submitted',
                $actor->name.' submitted '.$submission->displayName().'.',
                [], $actor, $submission->project_id, $submission->org_unit_id);

            return $submission;
        });

        $this->route($submission, $actor);

        return $submission;
    }

    /**
     * Send a submitted sheet out for approval, if its form says it goes out for approval.
     *
     * AFTER THE SUBMISSION IS COMMITTED, NOT INSIDE IT, and that ordering is deliberate. Starting a
     * route resolves assignees, writes stage instances and fires notifications; a failure anywhere
     * in that — a stage whose role resolves to nobody, a workflow retired since the form nominated
     * it — must not roll back the act of submitting. The sheet was submitted; that is a fact about
     * what the person did, and it should not be undone because the approval configuration is
     * wrong. A submission with no route is visible and fixable. A submission that silently
     * un-submitted itself is neither.
     *
     * A ROUTING FAILURE IS RECORDED, NEVER SWALLOWED. The engine raising here means the company's
     * approval configuration cannot serve this form, and the timeline is where somebody finds that
     * out — otherwise the sheet simply sits in "Submitted" forever with nobody able to say why
     * nothing happened.
     */
    private function route(FormSubmission $submission, User $actor): void
    {
        $submission->loadMissing('form.workflow');

        if (! $submission->requiresWorkflow()) {
            return;
        }

        try {
            // `resubmit`, not `start`, and it handles both. A sheet sent back for correction
            // already has an instance sitting in `revision_requested`, and starting a second one
            // would give the same permit two approval histories — the engine's resubmit reopens
            // the existing one at the next attempt, which is what the trail on the record screen
            // is counting. With no prior instance it falls through to `start()` itself.
            $instance = $this->workflow->resubmit($submission, $actor);
        } catch (\DomainException $e) {
            $this->activity->record($submission, 'approval_not_started',
                'This was submitted but could not be sent for approval: '.$e->getMessage(),
                [], $actor, $submission->project_id, $submission->org_unit_id);

            return;
        }

        if ($instance === null) {
            // The form asks for approval and the company has configured no route that serves it.
            // Said plainly on the record rather than left as silence (§73).
            $this->activity->record($submission, 'approval_not_started',
                'This form needs approval, but no approval route is configured for filled-in forms. '
                .'An administrator needs to set one on the form or publish a default route.',
                [], $actor, $submission->project_id, $submission->org_unit_id);
        }
    }

    /**
     * Send a submission back to whoever filled it in.
     *
     * The one way out of "submitted" that leads back to an editable sheet, and it is a status of
     * its own rather than a move to `draft` on purpose: "this was sent back to me" and "I have not
     * sent this yet" are different facts to the person holding the tablet, and a register that
     * collapses them cannot show a supervisor what is waiting on somebody.
     *
     * The stamps are LEFT ALONE. Who submitted it in March, and when, is what happened — a record
     * that erased it on return would lose the fact that a supervisor was once told it was ready.
     *
     * @throws \DomainException
     */
    public function returnForCorrection(FormSubmission $submission, User $actor, ?string $reason = null): FormSubmission
    {
        if (! $submission->isSubmitted()) {
            throw new \DomainException('This has not been submitted, so there is nothing to send back.');
        }

        $status = $this->statusNamed('returned');

        if ($status === null) {
            throw new \DomainException(
                'This company has no "returned" status configured for form submissions, so there '
                .'is nowhere to send this back to. Add one on the Statuses screen.'
            );
        }

        // Through the machine rather than by assignment, so the transition is checked, recorded
        // and notified exactly as every other status change on every other register is.
        $this->statuses->transition($submission, $status, $actor, $reason);

        return $submission->refresh();
    }

    // ================================================================== what the route decided

    /*
     | Called from FormSubmission's workflow hooks, inside the engine's closing transaction.
     |
     | THE APPROVER'S OWN PERMISSIONS ARE NOT RE-ASKED HERE, and that is not an oversight. Somebody
     | holding `approval.act` was handed this decision BY the route the company configured; the
     | register's transition whitelist describes what people may do to a submission by hand, which
     | is a different question. An approver who had to hold `form.review` as well would mean every
     | company that routes permits to a site manager must also grant that manager the run of the
     | forms register — so the route would be ignored and the permission granted, which is the
     | outcome this avoids.
     */

    /** The route approved it: accepted, and closed. */
    public function markApproved(FormSubmission $submission, WorkflowInstance $instance): void
    {
        $this->settle($submission, $instance, 'accepted', 'approved');
    }

    /** The route rejected it. The answers stand as the record of what was rejected. */
    public function markRejected(FormSubmission $submission, WorkflowInstance $instance): void
    {
        $this->settle($submission, $instance, 'rejected', 'rejected');
    }

    /**
     * Changes were asked for: back to the person who filled it in.
     *
     * Lands on `returned` rather than `draft`, the same place `returnForCorrection()` puts it, so
     * "an approver sent this back" and "a reviewer sent this back" leave the sheet in one state
     * that means one thing. isEditable() reads that status, so the tablet reopens.
     */
    public function markReturned(FormSubmission $submission, WorkflowInstance $instance): void
    {
        $this->settle($submission, $instance, 'returned', 'returned for correction');
    }

    /**
     * Move a submission to where the route says it ended up.
     *
     * TOLERATES A TENANT THAT EDITED ITS OWN TRANSITIONS. §63 says the status vocabulary and the
     * moves between statuses are the company's to change, and a company that removed
     * submitted → accepted has not thereby said approvals should stop working. The machine is asked
     * first, so the move is checked, recorded and notified like any other; if it refuses, the
     * status is set anyway, because the route's decision is the authority here and a sheet frozen
     * in "Submitted" after everybody approved it is the worse failure. Both paths leave a trail.
     */
    private function settle(
        FormSubmission $submission,
        WorkflowInstance $instance,
        string $statusKey,
        string $outcome,
    ): void {
        $actor = $this->finalApprover($instance);
        $status = $this->statusNamed($statusKey);

        if ($status === null) {
            // The company renamed or deleted this status. Nothing to move to, and inventing one
            // would put a word on the screen that is in none of their lists (§63).
            return;
        }

        if ($actor !== null) {
            try {
                $this->statuses->transition($submission, $status, $actor,
                    'Moved by the approval route.');
            } catch (\DomainException) {
                $submission->forceFill(['status_id' => $status->getKey()])->save();
            }
        } else {
            $submission->forceFill(['status_id' => $status->getKey()])->save();
        }

        $this->activity->record($submission, 'approval_'.$statusKey,
            'The approval route '.$outcome.' '.$submission->displayName().'.',
            ['workflow_instance_id' => $instance->getKey()], $actor,
            $submission->project_id, $submission->org_unit_id);
    }

    /**
     * Whoever cast the deciding vote.
     *
     * The LAST stage to have acted on this attempt, not the first: on a two-stage route, recording
     * the technical reviewer as the person who approved a permit rather than the manager who
     * endorsed it would misattribute the decision in the one record an auditor reads.
     */
    private function finalApprover(WorkflowInstance $instance): ?User
    {
        return $instance->stageInstances()
            ->where('attempt', $instance->attempt)
            ->whereNotNull('acted_by')
            ->reorder()
            ->orderByDesc('sequence')
            ->first()?->actor;
    }

    // ================================================================== signing

    /**
     * Put a named person's name to what this sheet currently says.
     *
     * THE SIGNATURE IS THE DOCUMENT MODULE'S, NOT A SECOND ONE. Same table, same written-once rule,
     * same designation snapshot, same content hash — see App\Models\Signature. A form-shaped
     * signature with its own rules would be two answers to "what does an approval prove", and the
     * weaker one would eventually be the one somebody relied on.
     *
     * WHAT IS SIGNED IS WHAT IS RECORDED, not what is on somebody's screen. The hash is taken over
     * the stored answers, so a person signs the sheet as it stands in the database — which is why
     * the panel tells them to save first. Anything else would let a signature attest to text that
     * was never written down.
     *
     * A CLOSED SUBMISSION TAKES NO MORE SIGNATURES. Once a sheet is accepted or cancelled it is a
     * record of what was decided, and a name added afterwards would be a name added to history.
     * Being submitted is not closed: counter-signing after submission is the normal case, and the
     * whole reason `submit` and `sign` are separate acts.
     *
     * @throws \DomainException
     */
    public function sign(FormSubmission $submission, FormField $field, User $actor): Signature
    {
        $this->assertSignable($submission, $field);

        if ($this->compiler->signaturesOn($submission, $field)->contains(
            fn (Signature $one) => (int) $one->user_id === (int) $actor->getKey()
        )) {
            throw new \DomainException(
                'You have already signed "'.$field->label.'". If the answers have changed since, '
                .'withdraw your signature first and sign again — a signature is never edited.'
            );
        }

        return $this->writeSignature($submission, $field, $actor, 'sign');
    }

    /**
     * Take a signature back.
     *
     * NOT A DELETE, AND NOT AN EDIT. A second row is written saying the person no longer stands
     * behind the sheet, and the original stays exactly as it was. That a supervisor signed a permit
     * on Tuesday and withdrew it on Wednesday is two facts an auditor needs, and a design that
     * removed the row would leave them with neither (§59).
     *
     * ONLY THE SIGNATORY WITHDRAWS. Somebody else removing your name from a sheet is forgery with
     * extra steps, whatever permissions they hold — a reviewer who disagrees sends the submission
     * back instead, which is a different and visible act.
     *
     * @throws \DomainException
     */
    public function withdrawSignature(FormSubmission $submission, FormField $field, User $actor): Signature
    {
        $this->assertSignable($submission, $field);

        $mine = $this->compiler->signaturesOn($submission, $field)
            ->first(fn (Signature $one) => (int) $one->user_id === (int) $actor->getKey());

        if ($mine === null) {
            throw new \DomainException(
                'You have not signed "'.$field->label.'", so there is nothing to withdraw. Only the '
                .'person who signed can take a signature back.'
            );
        }

        return $this->writeSignature($submission, $field, $actor, 'reject');
    }

    /**
     * The one writer, so a signing and a withdrawal can never be stamped differently.
     *
     * `content_hash` is taken at THIS moment for both. On a withdrawal that records what the sheet
     * said when the person stepped back from it, which is not necessarily what it said when they
     * signed — and both rows being in the table is what lets anybody see the difference.
     */
    private function writeSignature(
        FormSubmission $submission,
        FormField $field,
        User $actor,
        string $action,
    ): Signature {
        $employee = $actor->loadMissing('employee.position')->employee;
        $request = request();

        return DB::transaction(function () use ($submission, $field, $actor, $action, $employee, $request) {
            $signature = Signature::query()->create([
                // §74. The submission's company, never the actor's and never the request's — a
                // signature filed under a different organization from the thing it signs would be
                // invisible to exactly the people entitled to see it.
                'organization_id' => $submission->getAttribute('organization_id'),
                'signable_type' => $submission->getMorphClass(),
                'signable_id' => $submission->getKey(),
                'user_id' => $actor->getKey(),
                'employee_id' => $employee?->getKey(),

                // The name and the job title AS THEY ARE TODAY. A promotion next year must not
                // rewrite who signed this sheet as what they have since become.
                'name_snapshot' => $actor->name,
                'designation_snapshot' => $employee?->position?->title,

                'action' => $action,
                'signed_at' => now(),
                'ip' => $request?->ip(),
                'user_agent' => mb_substr((string) $request?->userAgent(), 0, 500) ?: null,
                'content_hash' => $this->compiler->contentHash($submission),
                'provider' => 'internal',

                // Which block on the sheet, by id AND by the words that were on it. The id is what
                // the screen reads back; the label is what a person reading this in three years
                // needs, when the form has been republished twice and the block renamed.
                'provider_reference' => [
                    'field_id' => (int) $field->getKey(),
                    'field_key' => $field->key,
                    'field_label' => $field->label,
                    'form_version' => $this->versionOf($submission)->version,
                    'submission_number' => $submission->number,
                ],
            ]);

            // Deliberately says WHICH BLOCK and not what the sheet says. A timeline entry is
            // readable by anybody who can see the record's history, and the answers a signature
            // covers are frequently the sensitive part (§68).
            $this->activity->record($submission,
                $action === 'sign' ? 'signed' : 'signature_withdrawn',
                $action === 'sign'
                    ? $actor->name.' signed "'.$field->label.'".'
                    : $actor->name.' withdrew their signature from "'.$field->label.'".',
                ['block' => $field->label], $actor,
                $submission->project_id, $submission->org_unit_id);

            $submission->unsetRelation('signatures');

            return $signature;
        });
    }

    /**
     * That this block is on this sheet, and that this sheet still accepts signatures.
     *
     * THE FIELD IS RE-READ THROUGH THE SUBMISSION'S OWN VERSION. A field id arrives in a URL, and a
     * URL is whatever somebody types — signing another form's block, or another company's, would
     * otherwise be a matter of changing a number (§84, §75).
     *
     * @throws \DomainException
     */
    private function assertSignable(FormSubmission $submission, FormField $field): void
    {
        $version = $this->versionOf($submission);

        if (! $field->isSignature() || (int) $field->form_version_id !== (int) $version->getKey()) {
            throw new \DomainException('That is not a signature block on this form.');
        }

        if ($submission->isClosed()) {
            throw new \DomainException(
                'This has been closed, so it can no longer be signed. What it says is the record of '
                .'what was decided.'
            );
        }
    }

    /**
     * Throw away a submission.
     *
     * A SOFT DELETE, always. The model carries SoftDeletes for the reason every record in this
     * build does: "delete" on a screen means "take this off my list", and a permit checklist
     * somebody signed is not a thing the platform should be able to destroy because a button was
     * pressed (§59).
     *
     * @throws \DomainException
     */
    public function delete(FormSubmission $submission, User $actor): void
    {
        if ($submission->isSubmitted()) {
            throw new \DomainException(
                'This has been submitted, so it cannot be deleted. Cancel it instead — the record '
                .'stays, marked as cancelled, which is what an auditor needs to see.'
            );
        }

        $name = $submission->displayName();

        DB::transaction(function () use ($submission, $actor, $name) {
            $this->activity->record($submission, 'deleted',
                $actor->name.' deleted the unsubmitted '.$name.'.',
                [], $actor, $submission->project_id, $submission->org_unit_id);

            $submission->delete();
        });
    }

    // ================================================================== state

    /**
     * Whether the answers may still be changed.
     *
     * The single question every write in this class asks, expressed once so that a new path cannot
     * accidentally answer it differently — the same reason FormVersion::isEditable() exists.
     *
     * A submission is editable while it has not been submitted, and again once it has been sent
     * back. A terminal status closes it either way: an accepted sheet is a record of what was
     * accepted, and a cancelled one is a record of what was cancelled.
     */
    public function isEditable(FormSubmission $submission): bool
    {
        if ($submission->isClosed()) {
            return false;
        }

        return ! $submission->isSubmitted() || $submission->statusKey() === 'returned';
    }

    /**
     * @throws \DomainException
     */
    private function assertEditable(FormSubmission $submission): void
    {
        if ($this->isEditable($submission)) {
            return;
        }

        throw new \DomainException($submission->isClosed()
            ? 'This has been closed, so its answers can no longer be changed. What it says is the '
              .'record of what was decided.'
            : 'This has already been submitted. Ask whoever is reviewing it to send it back if '
              .'something needs correcting.');
    }

    // ================================================================== plumbing

    /** A status of this register, by key — null when the company renamed or removed it (§63). */
    private function statusNamed(string $key): ?Status
    {
        return $this->statuses->statusesFor('form_submission')->firstWhere('key', $key);
    }

    /**
     * A project id, confirmed to be one of this company's.
     *
     * §74 and §75 in one method. The id arrives from a select box, which is to say from whatever
     * the browser posted, and a submission filed against another company's project would carry
     * that company's access axis — making it readable by people on the other side of the tenancy
     * boundary. A stranger's id becomes null rather than an error: the scope is what refuses it,
     * and saying "that project does not exist" to somebody who guessed correctly is itself a
     * disclosure (§61).
     */
    private function projectFor(mixed $id): ?int
    {
        if (blank($id) || ! is_numeric($id)) {
            return null;
        }

        return Project::query()->whereKey((int) $id)->value('id');
    }

    /** As projectFor(), for the department an answer is filed under. */
    private function orgUnitFor(mixed $id): ?int
    {
        if (blank($id) || ! is_numeric($id)) {
            return null;
        }

        return OrgUnit::query()->whereKey((int) $id)->value('id');
    }

    /**
     * "the inspector's name, the date and the tag number" — a list a person can read.
     *
     * @param  array<int,string>  $items
     */
    private function listOf(array $items): string
    {
        $shown = array_slice($items, 0, 5);
        $rest = count($items) - count($shown);

        $text = count($shown) === 1
            ? $shown[0]
            : implode(', ', array_slice($shown, 0, -1)).' and '.end($shown);

        return $rest > 0 ? $text.' (and '.$rest.' more)' : $text;
    }

    /** The edition a submission was started against — never the one that is live now. */
    public function versionOf(FormSubmission $submission): FormVersion
    {
        return $submission->relationLoaded('version')
            ? $submission->getRelation('version')
            : $submission->version()->firstOrFail();
    }
}
