<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use App\Engines\Audit\Concerns\HasActivity;
use App\Engines\Workflow\Concerns\HasWorkflow;
use App\Models\Concerns\HasCustomFields;
use App\Services\DocumentVersionService;
use App\Support\Enums\Confidentiality;
use App\Support\Enums\DocumentKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A controlled document: an IDENTITY, not a file (§26, §27, docs/09).
 *
 * This row holds the number people quote — on drawings, in transmittals, in a client's letter three
 * years from now — and nothing else of substance. What that number POINTED AT on a given day lives
 * in `document_versions`. There is no content column here for a careless update to overwrite, which
 * is how "never overwrite an approved revision" becomes a property of the schema instead of a rule
 * somebody has to remember.
 *
 * CHECK-OUT rather than co-editing. Real-time collaboration needs a persistent connection this
 * platform will not require in V1 (§1), and last-writer-wins on a controlled procedure is data loss
 * with an audit trail attached. So editing is exclusive and visible: you take it out, everyone sees
 * who has it and since when, you put it back. Engineering document control has always worked this
 * way, so it needs no explaining to the people using it.
 *
 * The workflow arrives through HasWorkflow, not through columns on this table. Documents are the
 * first module to route for approval and deliberately not the last — nothing in the workflow engine
 * names them, and adding purchase requests later means adding a trait, not a second approval system
 * (§78).
 */
class Document extends Model
{
    use Auditable, BelongsToOrganization, HasActivity, HasCustomFields, HasFactory, HasWorkflow, Scopeable, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => DocumentKind::class,
            'confidentiality' => Confidentiality::class,
            'is_controlled' => 'boolean',
            'tags' => 'array',
            'retention_until' => 'date',
            'next_review_on' => 'date',
            'locked_at' => 'datetime',
            'lock_expires_at' => 'datetime',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function category(): BelongsTo
    {
        return $this->belongsTo(DocumentCategory::class, 'document_category_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }

    public function discipline(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class, 'discipline_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class);
    }

    /**
     * The revision this number currently means.
     *
     * A belongsTo on the denormalised pointer rather than `versions()->where('is_current')`, so a
     * register listing 50 documents can eager-load 50 current revisions in one query instead of 50.
     */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'current_version_id');
    }

    // ------------------------------------------------------------------ access hooks

    /**
     * The owner is the person answerable for the document; the creator merely typed it in.
     *
     * Both count as "mine" because a document raised by a coordinator on an engineer's behalf must
     * still appear on the coordinator's own list — otherwise they lose the thing they just made.
     */
    public function accessOwnerColumns(): array
    {
        return ['owner_id', 'created_by'];
    }

    public function accessAssigneeColumns(): array
    {
        return ['owner_id'];
    }

    // ------------------------------------------------------------------ query scopes

    public function scopeControlled(Builder $query): Builder
    {
        return $query->where('is_controlled', true);
    }

    public function scopeInCategory(Builder $query, int|DocumentCategory $category): Builder
    {
        return $query->where(
            'document_category_id',
            $category instanceof DocumentCategory ? $category->getKey() : $category,
        );
    }

    /**
     * The register's search box.
     *
     * Number, title and description only. Version CONTENT is deliberately excluded even though the
     * native half makes it trivially searchable: a full-text match over document bodies turns the
     * search box into an oracle that reports whether a phrase appears in a confidential procedure,
     * and the result count leaks that even when the row itself is filtered out afterwards (§61).
     * Content search arrives with a properly scoped index, not as a `like` on a longText column.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.trim($term).'%';

        return $query->where(fn (Builder $q) => $q
            ->where('number', 'like', $like)
            ->orWhere('title', 'like', $like)
            ->orWhere('description', 'like', $like));
    }

    // ------------------------------------------------------------------ helpers

    public function displayName(): string
    {
        return trim(($this->number ? $this->number.' — ' : '').$this->title);
    }

    public function statusKey(): ?string
    {
        return $this->status?->key;
    }

    public function isNative(): bool
    {
        return $this->kind === DocumentKind::Native;
    }

    public function isFileBased(): bool
    {
        return $this->kind === DocumentKind::File;
    }

    // ------------------------------------------------------------------ check-out

    /**
     * Is somebody holding this document open for editing right now?
     *
     * An expired lock is not a lock. Somebody went on leave holding it, and the register must not
     * be frozen until they come back — DocumentService lets anyone with `document.edit` take over
     * an expired lock, and records that they did.
     */
    public function isLocked(): bool
    {
        if ($this->locked_by === null) {
            return false;
        }

        return $this->lock_expires_at === null || $this->lock_expires_at->isFuture();
    }

    public function isLockedBy(User $user): bool
    {
        return $this->isLocked() && (int) $this->locked_by === (int) $user->getKey();
    }

    /** Held by somebody else — the only state that actually blocks an edit. */
    public function isLockedByAnother(User $user): bool
    {
        return $this->isLocked() && ! $this->isLockedBy($user);
    }

    public function lockHasExpired(): bool
    {
        return $this->locked_by !== null
            && $this->lock_expires_at !== null
            && $this->lock_expires_at->isPast();
    }

    // ------------------------------------------------------------------ workflow

    /**
     * What a routing CONDITION may read on a document.
     *
     * Business attributes only, and note what is absent: nothing from `document_versions`. A
     * condition that could read `content_html` would let somebody build an approval route that
     * branches on the words inside a confidential document, and then read the branch taken off the
     * approval history — an oracle assembled entirely out of legitimate configuration (§61, §84).
     */
    public function workflowConditionFields(): array
    {
        return [
            'status_id',
            'confidentiality',
            'org_unit_id',
            'discipline_id',
            'project_id',
            'document_category_id',
            'is_controlled',
            'kind',
            'category.code',
            'category.slug',
            'project.project_type_id',
        ];
    }

    /**
     * The route this document takes: whatever its CATEGORY says.
     *
     * This is the line that makes the module configurable in the way §5 asks for. Drawings route
     * through a four-stage discipline check; internal memos through a one-stage sign-off; a vendor
     * data register through nothing at all — three registers, three routes, no code. Returning null
     * hands the decision back to the engine, which falls through to the module default.
     */
    public function resolveWorkflowDefinition(): ?Workflow
    {
        return $this->category?->workflow;
    }

    /**
     * An uncontrolled register really is uncontrolled.
     *
     * `requires_approval` is a checkbox on the register form, and until this method existed it was
     * decoration: the engine, finding no route on the category, fell through to the tenant default
     * and sent correspondence-received out for review anyway. The screen said one thing and the
     * system did another, which is worse than either behaviour on its own.
     *
     * The register is the authority. A document whose category requires no approval is not routed,
     * whatever defaults exist elsewhere in the tenant — DocumentService then makes the draft the
     * current revision and writes to the timeline that it was never reviewed, so nobody can later
     * mistake it for something that was.
     */
    public function requiresWorkflow(): bool
    {
        return $this->category?->requires_approval !== false;
    }

    /**
     * The route completed: this revision is now the controlled copy.
     *
     * Delegated to DocumentVersionService because promoting a revision is four coordinated writes —
     * stamp the approval, take the draft label off, move the `is_current` pointer, supersede the one
     * before — and having two places that know how to do that is how a register ends up with two
     * current revisions. Resolved from the container rather than injected because a model cannot
     * take constructor dependencies; the engine's transaction is still the one in force.
     */
    public function onWorkflowApproved(WorkflowInstance $instance): void
    {
        app(DocumentVersionService::class)->markApproved($this, $instance);
    }

    public function onWorkflowRejected(WorkflowInstance $instance): void
    {
        app(DocumentVersionService::class)->markRejected($this, $instance);
    }

    public function onWorkflowRevisionRequested(WorkflowInstance $instance): void
    {
        app(DocumentVersionService::class)->markRevisionRequested($this, $instance);
    }

    /**
     * A cancelled route leaves the document exactly where it was.
     *
     * Deliberately does nothing. Cancelling an approval is a statement about the approval, not about
     * the document — the draft is still a draft, and quietly moving it back would lose the fact that
     * it had been submitted at all.
     */
    public function onWorkflowCancelled(WorkflowInstance $instance): void {}
}
