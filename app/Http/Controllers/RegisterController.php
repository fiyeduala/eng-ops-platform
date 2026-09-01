<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Engines\Workflow\WorkflowEngine;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Status;
use App\Models\User;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The half of every register screen that is the same on every register screen.
 *
 * Phase 4 adds fifteen registers. Fifteen index actions each writing their own ScopeCompiler call
 * is fifteen chances to write `Deliverable::query()` without it, and that single omission is not a
 * cosmetic bug — it is a page that lists every record in the tenant to somebody whose scope is
 * `own`. The same applies to the placement check below: fifteen store actions each remembering to
 * re-authorize the posted `project_id` is fourteen too many, and the one that forgets lets a user
 * file a record into a project they cannot open (§84, §94).
 *
 * SO THE DANGEROUS PARTS LIVE HERE AND THE INTERESTING PARTS DO NOT. Subclasses still write their
 * own index, show, store and lifecycle actions, because a submittal review and a change-request
 * decision have nothing in common and a base class that tried to hold both would need a flag per
 * register. What they inherit is the scoped query, the filter vocabulary, the placement check, the
 * option lists and the status move — the parts where being identical is the whole point.
 *
 * WHAT THIS CLASS DOES NOT DO IS AUTHORIZE. Every subclass action still calls `authorize()` against
 * the bound record, because only a policy holding the model can answer Gates 3–5. `scopedQuery()`
 * narrows a LIST; it is not permission to touch a row (§6).
 */
abstract class RegisterController extends Controller
{
    public function __construct(
        protected readonly ScopeCompiler $compiler,
        protected readonly StatusMachine $statuses,
        protected readonly ActivityRecorder $activity,
        protected readonly NumberGenerator $numbers,
        protected readonly OptionList $lists,
    ) {}

    /** The catalogue key — `deliverable`, `rfi`. Drives permissions, statuses and numbering. */
    abstract protected function entityType(): string;

    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    /** The route-name prefix, e.g. `deliverables`. */
    abstract protected function routePrefix(): string;

    // ------------------------------------------------------------------ querying

    /**
     * The register, narrowed to what this person may see.
     *
     * Always `{module}.view`, never a wider permission and never none. ScopeCompiler turns the
     * actor's scope for that permission into the WHERE clause — own, assigned, unit, branch,
     * project or organization — and a query built without it is a tenant-wide list.
     *
     * @return Builder<Model>
     */
    protected function scopedQuery(User $user, ?string $permission = null): Builder
    {
        $class = $this->modelClass();

        return $this->compiler->apply(
            $class::query(),
            $user,
            $permission ?? $this->entityType().'.view',
        );
    }

    /**
     * Search, project, discipline and status — the four filters every register has.
     *
     * Status is filtered by ID here rather than by key, because the value comes from a select
     * populated with this tenant's own status rows and an id from another tenant simply matches
     * nothing. Nothing is trusted about it beyond being an integer.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    protected function applyCommonFilters(Builder $query, Request $request): Builder
    {
        $query->search((string) $request->query('q', ''));

        if ($projectId = (int) $request->query('project', 0)) {
            $query->forProject($projectId);
        }

        if ($disciplineId = (int) $request->query('discipline', 0)) {
            $query->forDiscipline($disciplineId);
        }

        if ($statusId = (int) $request->query('status', 0)) {
            $query->where('status_id', $statusId);
        }

        return $query;
    }

    /**
     * The filter values, echoed back so the form keeps what was typed.
     *
     * @return array<string,mixed>
     */
    protected function commonFilters(Request $request, string $defaultView = 'open'): array
    {
        return [
            'q' => $request->query('q'),
            'project' => $request->query('project'),
            'discipline' => $request->query('discipline'),
            'status' => $request->query('status'),
            'view' => (string) $request->query('view', $defaultView),
        ];
    }

    // ------------------------------------------------------------------ authorization

    /**
     * You may not file a record somewhere you cannot go.
     *
     * The form request has already confirmed these ids exist inside the tenant, which stops one
     * company's record being stapled to another's project. It cannot answer the second question:
     * whether THIS user may reach that project or that unit. Without this check a user whose scope
     * is one department could post a record into any project in the company — the record would then
     * be visible to that project's members and, worse, would have been placed there by somebody
     * with no business doing it (§84, §94).
     *
     * Both discipline and org unit are org units, so both go through OrgUnitPolicy.
     *
     * @param  array<string,mixed>  $data
     */
    protected function assertPlacementIsReachable(array $data): void
    {
        if (($projectId = $data['project_id'] ?? null) !== null) {
            $this->authorize('view', Project::findOrFail($projectId));
        }

        foreach (['org_unit_id', 'discipline_id'] as $key) {
            if (($unitId = $data[$key] ?? null) !== null) {
                $this->authorize('view', OrgUnit::findOrFail($unitId));
            }
        }
    }

    // ------------------------------------------------------------------ status

    /**
     * Move the record along the configured status graph.
     *
     * Identical on every register and deliberately generic: the graph, the permission on each move
     * and whether a move demands a comment are all per-tenant configuration, and StatusMachine
     * enforces every one of them. What this method contributes is the two checks configuration
     * cannot make — that the actor may edit this particular record, and that the target status
     * belongs to this kind of record in this tenant.
     *
     * The status is resolved THROUGH ELOQUENT so the tenant scope applies: an id from another
     * company does not resolve at all, let alone become assignable (§75).
     */
    protected function moveStatus(Request $request, Model $record): RedirectResponse
    {
        $this->authorize('update', $record);

        $validated = $request->validate([
            'status_id' => ['required', 'integer'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $to = Status::query()
            ->forEntity($this->entityType())
            ->whereKey($validated['status_id'])
            ->first();

        if ($to === null) {
            return back()->withErrors(['status_id' => 'That is not a status this record can be in.']);
        }

        try {
            $this->statuses->transition($record, $to, $request->user(), $validated['comment'] ?? null);
        } catch (\DomainException $e) {
            return back()->withErrors(['status_id' => $e->getMessage()]);
        }

        return back()->with('status', $this->name($record).' is now '.$to->label.'.');
    }

    /**
     * Send this record into whatever approval route its module is configured with.
     *
     * WHY IT IS HERE AND NOT IN RegisterService. The service layer deliberately refuses a `submit()`
     * — see its docblock — because submitting means something different on every register that has
     * its own version of it: an RFI is issued to a party, a submittal goes to a reviewer. But
     * ENTERING A WORKFLOW is not a register act at all. It is the engine's act, it is identical for
     * every record that adopts HasWorkflow, and duplicating it per module is how one register ends
     * up not checking `requiresWorkflow()` and routing correspondence nobody asked to approve.
     *
     * THIS IS THE PATH PHASE 4'S EXIT CRITERION RUNS ON. Until it existed, only documents could
     * enter a route — the engine was general and its one caller was not — so "an HSE incident
     * automatically spawns an investigation through configuration alone" was unreachable through
     * the UI no matter how the workflow was configured (§90).
     *
     * `update` IS THE GATE, not a new `submit` permission. Putting your own record forward for
     * review is an ordinary act of authorship, and inventing a second permission for it would mean
     * every organization has to grant a right they did not know they needed before anything routes.
     * The module policies that freeze a record — a closed incident — already refuse `update`, and
     * that refusal carries here for free.
     */
    protected function submitForApproval(Request $request, Model $record): RedirectResponse
    {
        $this->authorize('update', $record);

        if (! method_exists($record, 'isInWorkflow')) {
            return back()->withErrors(['workflow' => $this->name($record).' is not a record that goes for approval.']);
        }

        if (! $record->requiresWorkflow()) {
            return back()->withErrors(['workflow' => $this->name($record).' is not routed for approval.']);
        }

        if ($record->isInWorkflow()) {
            return back()->withErrors(['workflow' => $this->name($record).' is already going through approval.']);
        }

        try {
            // Resolved here rather than injected, because adding a sixth constructor argument would
            // mean editing every register controller in the application to reach a method most of
            // them will never call.
            $instance = app(WorkflowEngine::class)->start($record, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['workflow' => $e->getMessage()]);
        }

        // NO ROUTE CONFIGURED IS NOT AN ERROR. A company that has not defined an approval for this
        // module gets told so plainly; a company that has, and whose route stalls at the first
        // stage, is told that too — by the engine, on the record's own timeline.
        if ($instance === null) {
            return back()->withErrors([
                'workflow' => 'No approval route is configured for this register yet.',
            ]);
        }

        return back()->with('status', $this->name($record).' has been sent for approval.');
    }

    // ------------------------------------------------------------------ options

    /**
     * Projects this actor can reach, for the placement field.
     *
     * Capped at 500 because a select is not a search box and a company with four thousand projects
     * needs a picker, not a longer dropdown. The cap is a rendering decision; the SCOPE is the
     * security one, and that comes from the compiler.
     *
     * @return array<int|string,string>
     */
    protected function projectOptions(User $user): array
    {
        return $this->compiler
            ->apply(Project::query()->active(), $user, 'project.view')
            ->orderBy('name')->limit(500)->get()
            ->mapWithKeys(fn (Project $p) => [$p->id => $p->displayName()])
            ->all();
    }

    /**
     * Org units this actor can reach, indented by depth.
     *
     * The same list serves both the "owning unit" and the "discipline" fields, because a discipline
     * IS an org unit in this platform — Piping is a section of Engineering, with a head, members
     * and a place in the chart. Companies that do not organize by discipline simply leave the field
     * blank, which is why nothing requires it.
     *
     * @return array<int|string,string>
     */
    protected function unitOptions(User $user): array
    {
        return $this->compiler
            ->apply(OrgUnit::query()->where('is_active', true), $user, 'org_unit.view')
            ->orderBy('path')->get()
            ->mapWithKeys(fn (OrgUnit $u) => [
                $u->id => str_repeat('— ', max(0, (int) $u->depth)).$u->name,
            ])->all();
    }

    /**
     * People who can be named as responsible, drawn by, checked by, raising or submitting.
     *
     * Scoped on `employee.view` rather than `user.view`: naming a colleague on a deliverable is
     * something any engineer does, while `user.view` is an administrative permission covering
     * accounts, sign-in state and lockouts. Requiring the administrative one would make the field
     * empty for exactly the people who use it (§61 — the list obeys the same rules as the page).
     *
     * @return array<int|string,string>
     */
    protected function userOptions(User $user): array
    {
        return $this->compiler
            ->apply(User::query()->active(), $user, 'employee.view')
            ->orderBy('name')->limit(500)->get()
            ->mapWithKeys(fn (User $u) => [$u->id => $u->name])
            ->all();
    }

    /**
     * The options every register form needs.
     *
     * @return array<string,mixed>
     */
    protected function placementOptions(User $user): array
    {
        return [
            'projects' => $this->projectOptions($user),
            'units' => $this->unitOptions($user),
            'people' => $this->userOptions($user),
            'confidentialities' => Confidentiality::options(),
        ];
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Write the deletion to the timeline BEFORE the row goes.
     *
     * Order matters: the activity entry is polymorphic and records the id, so writing it after the
     * delete would still resolve, but writing it before means the narrative is composed while the
     * record can still describe itself. Soft-deleted records keep their trail either way (§59).
     */
    protected function recordDeletion(Model $record, User $actor, string $noun): void
    {
        $this->activity->record(
            $record,
            'deleted',
            $actor->name.' deleted the '.$noun.' '.$this->name($record).'.',
            [],
            $actor,
            $record->getAttribute('project_id') !== null ? (int) $record->getAttribute('project_id') : null,
        );
    }

    protected function name(Model $record): string
    {
        return method_exists($record, 'displayName')
            ? $record->displayName()
            : class_basename($record).' #'.$record->getKey();
    }

    /** Where to send somebody after an action on one record. */
    protected function showRoute(Model $record): string
    {
        return route($this->routePrefix().'.show', $record);
    }

    protected function indexRoute(): string
    {
        return route($this->routePrefix().'.index');
    }
}
