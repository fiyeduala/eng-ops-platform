<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Workflow\WorkflowEngine;
use App\Http\Requests\ApprovalDecisionRequest;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowStageInstance;
use App\Support\Enums\Decision;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * My Approvals — the inbox the whole workflow engine exists to fill (§33, §34).
 *
 * One screen, every module. A person who has to approve a document, a leave request, a purchase and
 * a timesheet sees one list, because the alternative — four modules each with their own pending
 * list — is how approvals get missed. Nothing in this file knows what a Document is: it lists stage
 * instances, and each row renders whatever its subject says it is. That is §90 made visible.
 *
 * TWO FILTERS RUN, AND BOTH ARE NECESSARY. The engine narrows to the FROZEN assignee lists this
 * person is on. Then every row's SUBJECT is put through the policy, and rows whose subject this
 * person can no longer see are dropped. The second pass is not paranoia: assignee lists are frozen
 * when a stage opens, so a document whose confidentiality was raised afterwards is a record someone
 * is still nominally assigned to and no longer permitted to read. Showing its title in an inbox
 * would leak it (§61, §68). The drop happens after the query, so the count above the list is the
 * count of what is actually shown.
 *
 * THE DECISION ENDPOINT TRUSTS NOTHING FROM THE BODY. The actor is the session; the stage is the
 * bound route model; whether this actor may act on this stage is
 * WorkflowStageInstancePolicy::act(), which re-checks the frozen list, the absence of a prior
 * decision from the same person, and the stage's self-approval rule. A POST naming somebody else's
 * stage is a 403, not a silently ignored field (§84).
 *
 * REASSIGNMENT IS DELIBERATELY NOT AVAILABLE TO ASSIGNEES. Being asked to approve something does
 * not give you the authority to decide who approves it instead — that is `approval.reassign`, held
 * by administrators, and it exists so a document does not sit behind somebody who left.
 */
class ApprovalController extends Controller
{
    public function __construct(private readonly WorkflowEngine $engine) {}

    /**
     * Everything waiting on me, plus the two views people actually ask for next:
     * what I have already decided, and what I started that is still out.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', WorkflowStageInstance::class);

        $user = $request->user();
        $view = (string) $request->query('view', 'pending');

        $query = match ($view) {
            'decided' => $this->decidedQuery($user),
            'overdue' => $this->engine->pendingFor($user)->overdue(),
            'started' => $this->startedQuery($user),
            default => $this->engine->pendingFor($user),
        };

        /** @var LengthAwarePaginator $rows */
        $rows = $query
            ->with(['stage', 'instance.subject', 'instance.starter:id,name'])
            ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        // The second filter. See the class docblock.
        $rows->setCollection($this->visibleTo($user, $rows->getCollection()));

        return view('approvals.index', [
            'rows' => $rows,
            'views' => [
                'pending' => 'Waiting on me',
                'overdue' => 'Overdue',
                'decided' => 'I have decided',
                'started' => 'I submitted',
            ],
            'filters' => ['view' => $view],
            'pendingCount' => $this->engine->pendingCountFor($user),
        ]);
    }

    /**
     * One stage, in the context of the record it is about.
     *
     * WorkflowStageInstancePolicy::view() answers from the SUBJECT, not from the workflow
     * definition — an administrator who configured the route does not thereby gain the right to
     * read the approval history of a confidential record that travelled it.
     */
    public function show(Request $request, WorkflowStageInstance $approval): View
    {
        $this->authorize('view', $approval);

        $user = $request->user();

        $approval->loadMissing([
            'stage',
            'actor:id,name',
            'approvals.user:id,name',
            'instance.subject',
            'instance.starter:id,name',
            'instance.stageInstances' => fn ($q) => $q->with(['stage', 'approvals.user:id,name'])->orderBy('sequence'),
        ]);

        $subject = $approval->instance?->subject;

        return view('approvals.show', [
            'approval' => $approval,
            'instance' => $approval->instance,
            'subject' => $subject,
            'action' => $approval->actionType(),
            'canAct' => $user->can('act', $approval),
            'canDelegate' => $user->can('delegate', $approval),
            'canReassign' => $user->can('reassign', $approval),
            'canCancel' => $approval->instance !== null && $user->can('cancel', $approval->instance),

            // Whether the subject can be opened at all is a separate question from whether the
            // approval can be read. A stage may legitimately be visible in the inbox — you are on
            // it — while the record behind it is not something this person may open in full.
            'canOpenSubject' => $subject !== null && $user->can('view', $subject),
            'people' => $user->can('reassign', $approval) ? $this->reassignCandidates($user) : [],
        ]);
    }

    /**
     * Approve, reject, ask for changes, abstain, or delegate.
     *
     * One endpoint, because they are one act — recording what this person decided about this stage
     * — and five endpoints would give five places for an authorization check to be forgotten.
     *
     * Delegation branches to a different engine method, not because it is a different endpoint but
     * because it does a different thing to the stage: every other decision answers it, while
     * delegating hands it on and edits the frozen assignee list. Both re-check that this actor is
     * on that list before writing (§84).
     */
    public function decide(ApprovalDecisionRequest $request, WorkflowStageInstance $approval): RedirectResponse
    {
        $this->authorize('act', $approval);

        $decision = $request->decision();

        try {
            if ($decision === Decision::Delegate) {
                $this->authorize('delegate', $approval);

                // Resolved through Eloquent so the tenant scope applies. A posted id is an
                // untrusted id however many times it has been looked up (§84, §75).
                $to = User::query()->active()->find($request->delegateToId());

                if ($to === null) {
                    return back()->withInput()
                        ->withErrors(['delegate_to' => 'That person cannot be given this approval.']);
                }

                $this->engine->delegate($approval, $request->user(), $to, $request->comment());
            } else {
                $this->engine->act($approval, $request->user(), $decision, $request->comment());
            }
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['decision' => $e->getMessage()]);
        }

        return redirect()->route('approvals.index')
            ->with('status', $this->confirmation($decision, $approval));
    }

    /**
     * Hand a stuck stage to somebody else.
     *
     * Administrative, not participatory: `approval.reassign`, and never available to the people on
     * the stage. The engine REPLACES the frozen list rather than adding to it, so the original
     * assignees can no longer act on something they have been taken off.
     */
    public function reassign(Request $request, WorkflowStageInstance $approval): RedirectResponse
    {
        $this->authorize('reassign', $approval);

        $validated = $request->validate([
            'assignees' => ['required', 'array', 'min:1', 'max:20'],
            'assignees.*' => ['integer'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $ids = User::query()->active()
            ->whereIn('id', array_map('intval', $validated['assignees']))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($ids === []) {
            return back()->withErrors(['assignees' => 'None of those people can take this approval.']);
        }

        try {
            $this->engine->reassign($approval, $ids, $request->user(), $validated['reason'] ?? null);
        } catch (\DomainException $e) {
            return back()->withErrors(['assignees' => $e->getMessage()]);
        }

        return back()->with('status', 'This approval has been reassigned.');
    }

    /** Stop a route entirely. The record stays; the approval does not. */
    public function cancel(Request $request, WorkflowInstance $instance): RedirectResponse
    {
        $this->authorize('cancel', $instance);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $this->engine->cancel($instance, $request->user(), $validated['reason']);

        return back()->with('status', 'The approval has been cancelled.');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Drop rows whose subject this person may no longer see.
     *
     * Subjects are eager-loaded, so this costs no extra queries. Rows with a missing subject — a
     * record hard-deleted while a route was in flight — are dropped too: failing closed is the only
     * safe answer when there is nothing left to authorize against.
     *
     * @param  Collection<int,WorkflowStageInstance>  $rows
     * @return Collection<int,WorkflowStageInstance>
     */
    private function visibleTo(User $user, Collection $rows): Collection
    {
        return $rows->filter(function (WorkflowStageInstance $row) use ($user) {
            $subject = $row->instance?->subject;

            return $subject !== null && $user->can('view', $subject);
        })->values();
    }

    /**
     * Stages this person has already decided on.
     *
     * Answered from the approval rows rather than from `acted_by`, because a quorum stage has
     * several decisions and only the last one to close it is recorded on the stage.
     *
     * @return Builder<WorkflowStageInstance>
     */
    private function decidedQuery(User $user): Builder
    {
        return WorkflowStageInstance::query()
            ->whereHas('approvals', fn (Builder $q) => $q->where('user_id', $user->getKey()));
    }

    /**
     * Routes this person started that are still out.
     *
     * The other half of an approval inbox, and the one that stops "where is my document" being a
     * question people ask by email.
     *
     * @return Builder<WorkflowStageInstance>
     */
    private function startedQuery(User $user): Builder
    {
        return WorkflowStageInstance::query()
            ->pending()
            ->whereHas(
                'instance',
                fn (Builder $q) => $q->where('started_by', $user->getKey())
                    ->where('status', WorkflowInstance::STATUS_RUNNING)
            );
    }

    /**
     * People an administrator may hand an approval to.
     *
     * Restricted to accounts this actor can already see: a reassignment picker must not become a
     * staff directory for someone whose `user.view` says otherwise (§61).
     *
     * @return array<int|string,string>
     */
    private function reassignCandidates(User $user): array
    {
        if (! $user->hasPermission('user.view')) {
            return [];
        }

        return app(ScopeCompiler::class)
            ->apply(User::query()->active(), $user, 'user.view')
            ->orderBy('name')->limit(500)
            ->pluck('name', 'id')->all();
    }

    private function confirmation(Decision $decision, WorkflowStageInstance $approval): string
    {
        return match ($decision) {
            Decision::Approve => 'Approved. '.$approval->displayName().' has moved on.',
            Decision::Reject => 'Rejected. The author has been told why.',
            Decision::RequestRevision => 'Sent back for revision. The author has been told what to change.',
            Decision::Abstain => 'Recorded as an abstention.',
            Decision::Delegate => 'Handed on. The other person now has this approval.',
        };
    }
}
