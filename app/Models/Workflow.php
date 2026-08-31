<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use App\Engines\Audit\Concerns\HasActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An approval route, defined entirely through the UI (§19, §21, docs/07).
 *
 * The exit criterion for this whole phase is that an administrator can build one of these on a form
 * and watch a document travel it — no migration, no deploy, no developer. So everything that varies
 * between companies is a row: the stages, who each one goes to, what makes a stage apply, what
 * happens on rejection, how long it may sit.
 *
 * VERSIONING is the reason `version` and `supersedes_id` exist. Editing a published workflow does
 * not mutate it; it writes a new row at version + 1 and deactivates the old one, which survives
 * because fourteen approvals are sitting in people's inboxes referencing it and because the audit
 * trail has to be able to say what the rules were on the day a decision was taken. A workflow that
 * has never been published may be edited in place — nothing depends on it yet.
 *
 * Workflows are tenant configuration, so `workflow.view` is organization-wide: a department-scoped
 * administrator looking at the approval routes should see the routes, since a workflow belongs to
 * no department. The write abilities are pointedly not listed.
 */
class Workflow extends Model
{
    use Auditable, BelongsToOrganization, HasActivity, HasFactory, Scopeable, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function stages(): HasMany
    {
        return $this->hasMany(WorkflowStage::class)->orderBy('sequence');
    }

    public function activeStages(): HasMany
    {
        return $this->stages()->where('is_active', true);
    }

    public function instances(): HasMany
    {
        return $this->hasMany(WorkflowInstance::class);
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ------------------------------------------------------------------ access hooks

    public function accessOrgUnitColumn(): ?string
    {
        return null;
    }

    public function accessProjectColumn(): ?string
    {
        return null;
    }

    public function accessOrganizationWideAbilities(): array
    {
        return ['workflow.view'];
    }

    // ------------------------------------------------------------------ query scopes

    public function scopeForEntity(Builder $query, string $entityType): Builder
    {
        return $query->where('entity_type', $entityType);
    }

    /** Published, active, and therefore allowed to pick up live records. */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNotNull('published_at');
    }

    public function scopeDefaults(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    // ------------------------------------------------------------------ helpers

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    public function isDraft(): bool
    {
        return $this->published_at === null;
    }

    /**
     * May this definition still be changed in place?
     *
     * Only while nothing has ever used it. Once published, an edit becomes a new version — see
     * WorkflowDefinitionService::newVersion().
     */
    public function isEditableInPlace(): bool
    {
        return $this->isDraft();
    }

    public function displayName(): string
    {
        return $this->name.' (v'.$this->version.')';
    }

    public function statusLabel(): string
    {
        if ($this->isDraft()) {
            return 'Draft';
        }

        return $this->is_active ? 'Published' : 'Superseded';
    }
}
