<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use App\Engines\Audit\Concerns\HasActivity;
use App\Engines\Workflow\Concerns\HasWorkflow;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Status;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The spine every engineering, HSE and quality register shares.
 *
 * Phase 4 adds roughly a dozen registers — deliverables, RFIs, submittals, change requests,
 * incidents, investigations, corrective actions, observations, risk assessments, inspections,
 * toolbox talks, permits, NCRs — and every one of them is the same sentence with a different noun:
 * a numbered record, belonging to a tenant, attached to a project and a discipline, owned by a
 * department, raised by somebody, moving through a status, routed for approval, audited.
 *
 * WHY A CONCERN AND NOT TWELVE COPIES. §90 is explicit that this must not become a collection of
 * unconnected CRUD modules, and the way that failure actually happens is not architectural — it is
 * that the ninth register spells the discipline relation slightly differently, forgets to exclude
 * `assignee_id` from its access hooks, or omits `discipline_id` from its condition whitelist. Each
 * omission is invisible until a workflow condition silently never matches or an index query throws
 * on a column that does not exist. Naming the shape once makes the shape enforceable.
 *
 * WHY IT DOES NOT DEFINE `assigneeColumns`. Scopeable's default names `assignee_id`, which no table
 * here has: a deliverable is *responsible*, an incident is *investigated by*, an NCR is *dispositioned
 * by*, and the column differs. Left unhandled, the compiler would emit SQL naming a missing column —
 * a 500 on an index page, in production, for exactly the users whose scope is `assigned`. So this
 * trait resets it to nothing and each register declares its own.
 *
 * WHAT IT DELIBERATELY DOES NOT DO: decide status vocabularies, numbering prefixes, workflow
 * behaviour or permissions. Those are configuration, per tenant, and belong in the catalogues.
 *
 * @mixin Model
 */
trait RegisterRecord
{
    use Auditable, BelongsToOrganization, HasActivity, HasFactory, HasWorkflow, Scopeable, SoftDeletes;

    /**
     * Column listings, keyed by table. See registerColumnListing().
     *
     * @var array<string,array<string,int>>
     */
    private static array $registerColumnCache = [];

    // ------------------------------------------------------------------ relationships

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The engineering discipline — which IS an org unit (see the Phase 4 migration docblock).
     *
     * Separate relation, same table as `orgUnit()`: "Piping" is the discipline that owns the
     * technical content, while the owning unit may be a project team or a regional office. They
     * coincide often enough that people assume one column would do, and diverge often enough that
     * it would not.
     */
    public function discipline(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class, 'discipline_id');
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

    // ------------------------------------------------------------------ access hooks

    /**
     * "Mine" is a record I raised.
     *
     * Not the person responsible for it — that is `assigned`, one rung wider, and conflating the
     * two would give an engineer owner-level reach over every deliverable ever routed to them.
     *
     * @return array<int,string>
     */
    public function accessOwnerColumns(): array
    {
        return ['created_by'];
    }

    /**
     * Nothing by default. Each register names its own; see the class docblock.
     *
     * @return array<int,string>
     */
    public function accessAssigneeColumns(): array
    {
        return [];
    }

    /**
     * Fields a workflow condition may read.
     *
     * The shared spine plus `discipline_id`, because "route electrical deliverables to the
     * electrical lead" is the single most common branch anyone configures. Registers override to
     * ADD their own business columns — a kind, a severity, a review code — and must never add a
     * free-text column: a condition that can test the words in an incident description is a way to
     * read them back out of the approval history (§61).
     *
     * Registers add their own through `registerConditionFields()` rather than by overriding this,
     * so nobody can widen the list by forgetting to merge the spine back in.
     *
     * @return array<int,string>
     */
    public function workflowConditionFields(): array
    {
        $fields = array_values(array_unique(array_merge(
            ['status_id', 'confidentiality', 'org_unit_id', 'project_id', 'discipline_id'],
            $this->registerConditionFields(),
        )));

        $columns = $this->registerColumnListing();

        // Filtered against the table, because the spine is not universal. Phase 5 brings the first
        // registers that are not attached to a project at all — a vendor and a client belong to the
        // company, not to a job — and an unfiltered list would offer "Project" in the condition
        // builder for a column that does not exist, then evaluate it as null for every vendor
        // forever. A condition that silently never matches is worse than one that cannot be built.
        // Dotted paths are relation walks and are left to the evaluator to resolve.
        return array_values(array_filter(
            $fields,
            fn (string $field): bool => str_contains($field, '.') || isset($columns[$field]),
        ));
    }

    /**
     * This table's columns, as a lookup, read once per table per request.
     *
     * @return array<string,int>
     */
    protected function registerColumnListing(): array
    {
        $table = $this->getTable();

        if (! isset(self::$registerColumnCache[$table])) {
            self::$registerColumnCache[$table] = array_flip(
                $this->getConnection()->getSchemaBuilder()->getColumnListing($table)
            );
        }

        return self::$registerColumnCache[$table];
    }

    /**
     * Extra business columns this register lets a workflow condition branch on.
     *
     * Structured values only — a kind, a severity, a review code, an origin. Never a title,
     * description, question, answer or comment: a condition that can test free text is a way to
     * read it back out of the approval history one guess at a time (§61).
     *
     * @return array<int,string>
     */
    public function registerConditionFields(): array
    {
        return [];
    }

    // ------------------------------------------------------------------ query helpers

    /**
     * Filter by status KEY — `open`, `closed` — never by id.
     *
     * Ids are per-tenant rows; keys are the vocabulary StatusCatalogue defines. A controller that
     * filtered on an id would work in the seeder and fail in the second tenant.
     *
     * @param  Builder<static>  $query
     * @param  string|array<int,string>|null  $keys
     * @return Builder<static>
     */
    public function scopeStatusKey(Builder $query, string|array|null $keys): Builder
    {
        if (blank($keys)) {
            return $query;
        }

        return $query->whereHas('status', fn (Builder $q) => $q->whereIn('key', (array) $keys));
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForProject(Builder $query, int|string|null $projectId): Builder
    {
        return blank($projectId)
            ? $query
            : $query->where($this->qualifyColumn('project_id'), $projectId);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForDiscipline(Builder $query, int|string|null $disciplineId): Builder
    {
        return blank($disciplineId)
            ? $query
            : $query->where($this->qualifyColumn('discipline_id'), $disciplineId);
    }

    /**
     * Search the identifying columns only.
     *
     * `registerSearchColumns()` is a whitelist for the same reason Memo::scopeSearch refuses to
     * match on a memo body: a search box that matches free text reports, through the result count
     * alone, whether a phrase appears in records the reader may list but has not opened (§61).
     * Registers may widen it, and should widen it only to columns that are effectively titles.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.trim($term).'%';
        $columns = $this->registerSearchColumns();

        return $query->where(function (Builder $q) use ($columns, $like) {
            foreach ($columns as $column) {
                $q->orWhere($this->qualifyColumn($column), 'like', $like);
            }
        });
    }

    /** @return array<int,string> */
    public function registerSearchColumns(): array
    {
        return ['number', 'title'];
    }

    // ------------------------------------------------------------------ presentation

    /**
     * The column holding this register's one-line title. `title` for most, `subject` for RFIs.
     */
    public function registerTitleColumn(): string
    {
        return 'title';
    }

    public function displayName(): string
    {
        $title = (string) ($this->getAttribute($this->registerTitleColumn()) ?? '');

        return trim(($this->number ? $this->number.' — ' : '').$title);
    }

    public function statusKey(): ?string
    {
        return $this->status?->key;
    }

    /**
     * Whether the record has reached a terminal status.
     *
     * Reads the status row's own `is_terminal` flag rather than a hard-coded list of keys, because
     * which statuses are terminal is a per-tenant configuration decision — one company closes an
     * RFI at "answered", another keeps it open until "accepted".
     */
    public function isClosed(): bool
    {
        return (bool) ($this->status?->is_terminal);
    }

    /**
     * Past its date and not yet closed.
     *
     * `registerDueColumn()` returns null for registers with no deadline, and those are never
     * overdue rather than always overdue — a distinction worth spelling out because the naive
     * version reports every undated record as late on the day it is created.
     */
    public function isOverdue(): bool
    {
        $column = $this->registerDueColumn();

        if ($column === null || $this->getAttribute($column) === null || $this->isClosed()) {
            return false;
        }

        return $this->getAttribute($column)->isBefore(now()->startOfDay());
    }

    public function registerDueColumn(): ?string
    {
        return 'required_by';
    }
}
