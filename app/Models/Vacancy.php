<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasCustomFields;
use App\Models\Concerns\RegisterRecord;
use App\Services\VacancyService;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A head the company has asked to fill, approved by the same engine as everything else.
 *
 * A requisition is a spending decision made before anybody is interviewed, which is exactly why it
 * is routed: `submit()` hands it to WorkflowEngine and the four hooks below are the only vacancy
 * specific code on the approval path. There is no `approve()` here and no approve route.
 *
 * APPROVED MEANS OPEN FOR APPLICATIONS, and nothing else. Applicants can only be added to a vacancy
 * that got through its route, so an unapproved head cannot quietly acquire a shortlist.
 */
class Vacancy extends Model
{
    use HasCustomFields, RegisterRecord;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'openings' => 'integer',
            'hired_count' => 'integer',
            'target_start_date' => 'date',
            'closes_on' => 'date',
            'confidentiality' => Confidentiality::class,
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public static function permissionModule(): string
    {
        return 'recruitment';
    }

    // ------------------------------------------------------------------ access

    public function accessOwnerColumns(): array
    {
        return ['created_by', 'requester_id', 'hiring_manager_id'];
    }

    public function accessOrgUnitColumn(): ?string
    {
        return 'org_unit_id';
    }

    public function accessProjectColumn(): ?string
    {
        return 'project_id';
    }

    /** The heads a manager is hiring for, so the approvals inbox can show what it asks them to decide. */
    public function applyAssignedScope(Builder $query, User $user): void
    {
        $query->orWhere($this->qualifyColumn('hiring_manager_id'), $user->getKey())
            ->orWhere($this->qualifyColumn('requester_id'), $user->getKey());
    }

    public function registerConditionFields(): array
    {
        return ['openings', 'org_unit_id', 'employment_type'];
    }

    public function registerSearchColumns(): array
    {
        return ['number', 'title'];
    }

    public function registerDueColumn(): ?string
    {
        return 'closes_on';
    }

    public static function workflowEntityLabel(): string
    {
        return 'Vacancies';
    }

    // ------------------------------------------------------------------ workflow

    public function workflowOwnerId(): ?int
    {
        $owner = $this->attributes['requester_id'] ?? null;

        return $owner === null ? null : (int) $owner;
    }

    public function onWorkflowApproved(WorkflowInstance $instance): void
    {
        app(VacancyService::class)->markApproved($this, $instance);
    }

    public function onWorkflowRejected(WorkflowInstance $instance): void
    {
        app(VacancyService::class)->markRejected($this, $instance);
    }

    public function onWorkflowRevisionRequested(WorkflowInstance $instance): void
    {
        app(VacancyService::class)->markReturned($this, $instance);
    }

    public function onWorkflowCancelled(WorkflowInstance $instance): void {}

    // ------------------------------------------------------------------ relations

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function hiringManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hiring_manager_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class)->withTrashed();
    }

    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function applicants(): HasMany
    {
        return $this->hasMany(Applicant::class);
    }

    // ------------------------------------------------------------------ presentation

    /** Whether applications can still be added: approved, not closed, and not already filled. */
    public function isOpen(): bool
    {
        return $this->statusKey() === 'open'
            && $this->closed_at === null
            && $this->hired_count < $this->openings;
    }

    public function remainingOpenings(): int
    {
        return max(0, $this->openings - $this->hired_count);
    }

    public function displayName(): string
    {
        return trim($this->number.' — '.$this->title);
    }
}
