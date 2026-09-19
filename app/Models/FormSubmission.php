<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use App\Engines\Audit\Concerns\HasActivity;
use App\Engines\Workflow\Concerns\HasWorkflow;
use App\Services\FormSubmissionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One filled-in form — somebody's answers, against the questions as they stood that day.
 *
 * NOT RegisterRecord, though it looks like one. That concern is the spine of the Phase 4
 * engineering registers and carries a discipline, a confidentiality level and a due date, none of
 * which a form submission has: a submission is about whatever its form is about, and pretending it
 * has an engineering discipline would put "Piping" on a visitor sign-in sheet. The traits it
 * genuinely needs are composed directly instead.
 *
 * IT DOES ROUTE FOR APPROVAL, through the same HasWorkflow trait every other routable module uses
 * (§78). Whether a given submission is routed at all is the FORM's decision, not this record's —
 * a permit goes to the HSE manager because the permit form says so, and a sign-in sheet goes
 * nowhere. Nothing here chooses a route, because whoever fills a sheet in must not be the person
 * who picks who approves it.
 *
 * `form_version_id` IS THE WHOLE DESIGN. It is set once, never changed, and every answer is read
 * through it — so a submission always means what it meant when it was made, whatever has happened
 * to the form since. See the migration for the argument in full.
 *
 * The access axes are real columns rather than anything derived: `project_id` and `org_unit_id` are
 * what the scope compiler reads, and a submission whose department had to be worked out at read
 * time would be a submission the compiler could not filter on (§6, §75).
 */
class FormSubmission extends Model
{
    use Auditable, BelongsToOrganization, HasActivity, HasFactory, HasWorkflow, Scopeable, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
        ];
    }

    public static function permissionModule(): string
    {
        return 'form';
    }

    /**
     * The status vocabulary this record draws on — NOT its permission module.
     *
     * The one place in the build where those two words differ, and deliberately. Authority over a
     * submission follows the form it was filled in on, so the permissions are `form.*`. Its states
     * are its own: "Draft" on a blank inspection sheet means nobody has finished writing on it,
     * while "Draft" on the inspection form means nobody has decided the company uses it yet. One
     * vocabulary for both would put "Published" on a sign-in sheet and "Returned for correction"
     * on a form definition, and every status screen in the product would have to explain which of
     * the two a given word meant this time (§63).
     */
    public static function statusEntityType(): string
    {
        return 'form_submission';
    }

    // ------------------------------------------------------------------ relationships

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /**
     * The edition this was answered against.
     *
     * Read this, never `form->publishedVersion`. The difference between the two is the entire
     * point of versioning, and a screen that renders a March submission against today's questions
     * is the failure this design exists to prevent.
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class, 'form_version_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(FormAnswer::class);
    }

    /**
     * What this submission is about, when it is about something.
     *
     * An inspection against a project, a permit against an asset, a handover against a deliverable.
     * Null for the many forms that are about nothing but themselves.
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The approvals written against this submission.
     *
     * The document module's signature, reused rather than reimplemented: written once, carrying the
     * signer's designation AT THE TIME and a hash of what was signed. A second signature stack with
     * its own rules would be two answers to "what does an approval prove", and the weaker one would
     * eventually be the one somebody relies on.
     */
    public function signatures(): MorphMany
    {
        return $this->morphMany(Signature::class, 'signable');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    // ------------------------------------------------------------------ state

    public function isSubmitted(): bool
    {
        return $this->submitted_at !== null;
    }

    public function statusKey(): ?string
    {
        return $this->status?->key;
    }

    public function isClosed(): bool
    {
        return (bool) ($this->status?->is_terminal);
    }

    public function displayName(): string
    {
        $form = $this->form?->name ?? 'Form';

        return trim(($this->number ? $this->number.' — ' : '').$form);
    }

    // ------------------------------------------------------------------ queries

    /** Load a page of submissions' answers in one query rather than one per row (§89). */
    public function scopeWithAnswers(Builder $query): Builder
    {
        return $query->with(['answers', 'version.fields']);
    }

    public function scopeForForm(Builder $query, int|string|null $formId): Builder
    {
        return blank($formId) ? $query : $query->where('form_id', $formId);
    }

    public function scopeForProject(Builder $query, int|string|null $projectId): Builder
    {
        return blank($projectId) ? $query : $query->where('project_id', $projectId);
    }

    public function scopeStatusKey(Builder $query, string|array|null $keys): Builder
    {
        return blank($keys)
            ? $query
            : $query->whereHas('status', fn (Builder $q) => $q->whereIn('key', (array) $keys));
    }

    /**
     * Search the identifying columns only.
     *
     * The number, and nothing else. NOT the answers: a search box that matched what people wrote
     * into forms would report, through the result count alone, whether a phrase appears in
     * submissions the reader may list but may not open — which is how a search box becomes a way
     * to read an incident report one guess at a time (§61).
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        return blank($term)
            ? $query
            : $query->where('number', 'like', '%'.trim($term).'%');
    }

    // ------------------------------------------------------------------ access hooks

    public function accessOrgUnitColumn(): ?string
    {
        return 'org_unit_id';
    }

    public function accessProjectColumn(): ?string
    {
        return 'project_id';
    }

    /**
     * "Mine" is a submission I raised.
     *
     * `submitted_by` is deliberately NOT here. It is usually the same person, and when it is not,
     * it is a supervisor submitting on somebody's behalf — giving that supervisor owner-level reach
     * over the record would be a quiet widening nobody granted.
     *
     * @return array<int,string>
     */
    public function accessOwnerColumns(): array
    {
        return ['created_by'];
    }

    /** @return array<int,string> */
    public function accessAssigneeColumns(): array
    {
        return [];
    }

    // ------------------------------------------------------------------ workflow hooks

    /**
     * The module the workflow builder files these under.
     *
     * `form_submission`, not `form`, and for the same reason the status vocabulary splits: a route
     * approves a filled-in sheet, never the form it was filled in on. A builder that offered one
     * "Forms" entry would let somebody build an approval route for a form DEFINITION — which is
     * what `form.publish` is for — and wire it to records it was never meant to touch.
     */
    public function workflowEntityType(): string
    {
        return 'form_submission';
    }

    /** Named rather than derived, because "Form Submissions" is not what anybody calls these. */
    public static function workflowEntityLabel(): string
    {
        return 'Filled-in forms';
    }

    /**
     * What a stage CONDITION may branch on.
     *
     * A whitelist, and a short one. The obvious omission is the answers: a company would dearly
     * like "route to the safety manager when the gas reading is over 5%", and it is exactly the
     * thing this list must not offer. Answers live in `form_answers`, keyed by a field id that
     * belongs to one edition of one form, so a condition naming one would silently stop matching
     * the moment the form was republished — and worse, a stage condition is readable by anybody who
     * can open the workflow builder, so branching on an answer would publish the contents of
     * submissions to people holding only `workflow.view` (§61, §68).
     *
     * `form_id` is the useful one, and it is safe: it says which form, not what anybody wrote on it.
     *
     * @return array<int,string>
     */
    public function workflowConditionFields(): array
    {
        return ['form_id', 'status_id', 'org_unit_id', 'project_id'];
    }

    /**
     * The route this submission enters, chosen by the form and never by the submission.
     *
     * Null here does not mean "no approval" — it means "no particular route", and the engine falls
     * through to the tenant default. `requiresWorkflow()` is the one that says no.
     */
    public function resolveWorkflowDefinition(): ?Workflow
    {
        return $this->form?->workflow;
    }

    /**
     * Most forms are not approval processes, and this is where that is honoured.
     *
     * A sign-in sheet, a toolbox talk register, a delivery note: submitting one records that it
     * happened, and there is nobody to ask. Only a form whose builder ticked "needs approval" is
     * routed, and a form that did not tick it is not routed EVEN IF the tenant has a default route
     * for form submissions — the false here means do not fall back, which is the distinction
     * Document::requiresWorkflow() exists to make.
     */
    public function requiresWorkflow(): bool
    {
        return $this->form?->requires_approval === true;
    }

    // The route decided; the register's own status follows. Delegated to the service because a
    // status move on this register is a StatusMachine transition with a fallback, and two places
    // that knew how to do that would eventually disagree about one of them.

    public function onWorkflowApproved(WorkflowInstance $instance): void
    {
        app(FormSubmissionService::class)->markApproved($this, $instance);
    }

    public function onWorkflowRejected(WorkflowInstance $instance): void
    {
        app(FormSubmissionService::class)->markRejected($this, $instance);
    }

    public function onWorkflowRevisionRequested(WorkflowInstance $instance): void
    {
        app(FormSubmissionService::class)->markReturned($this, $instance);
    }

    /**
     * A cancelled route leaves the sheet where it was.
     *
     * Cancelling an approval is a statement about the approval, not about the answers. The
     * submission stays submitted, because it was — and quietly reopening it for editing would
     * discard the fact that somebody was once told it was ready.
     */
    public function onWorkflowCancelled(WorkflowInstance $instance): void {}
}
