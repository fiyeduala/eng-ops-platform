<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\MemoRequest;
use App\Models\Memo;
use App\Models\MemoRecipient;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Status;
use App\Models\User;
use App\Policies\MemoPolicy;
use App\Services\MemoService;
use App\Support\Enums\Confidentiality;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Memos (§18).
 *
 * A directed internal communication, which makes it the first module where reach and ADDRESSING are
 * both live at once, and the controller's job is mostly to keep them apart.
 *
 * The list is one ScopeCompiler call like every other list, so the ordinary union applies — my own
 * drafts, the memos in the units I reach, the memos on my projects — plus the assignee axis, which
 * for a memo is the distribution list. On top of that sits the draft curtain: applyPublication()
 * hides an unissued memo from everybody except its author and anyone holding `memo.issue`. Neither
 * of those is a branch in this file.
 *
 * Three writes are deliberately not `update`:
 *
 *   - ADDRESSING is a grant. For a restricted memo it writes a record_permissions row, so it has its
 *     own endpoint and re-resolves each candidate from the POST body (§84).
 *   - ISSUING moves through the whitelist and is what makes the memo visible and sends the mail;
 *     `memo.edit` is not `memo.issue`.
 *   - MARKING READ writes only the actor's own recipient row, and is refused for anybody else's.
 *
 * An issued memo cannot be edited or deleted at all — MemoPolicy refuses both. A memo is a statement
 * of record about what was communicated and when; correcting one after the fact would rewrite that
 * history under everybody who already acted on it (§59). The remedy is to withdraw and re-issue.
 */
class MemoController extends Controller
{
    public function __construct(
        private readonly ScopeCompiler $compiler,
        private readonly ActivityRecorder $activity,
        private readonly StatusMachine $statuses,
        private readonly MemoService $memos,
        private readonly NumberGenerator $numbers,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Memo::class);

        $user = $request->user();

        $query = $this->compiler->apply(Memo::query(), $user, 'memo.view')
            ->with([
                'status:id,label,color,is_terminal',
                'project:id,name,number',
                'orgUnit:id,name',
                'creator:id,name',
                'issuer:id,name',
            ])
            ->withCount([
                'recipients',
                'recipients as read_count' => fn ($q) => $q->whereNotNull('read_at'),
            ]);

        $query->search((string) $request->query('q', ''));

        if ($projectId = (int) $request->query('project', 0)) {
            $query->where('project_id', $projectId);
        }

        if ($statusId = (int) $request->query('status', 0)) {
            $query->where('status_id', $statusId);
        }

        $view = (string) $request->query('view', 'inbox');

        match ($view) {
            // The distribution list, which is the view most people want and the only one that is
            // about the actor rather than about the record.
            'inbox' => $query->issued()->whereHas(
                'recipients',
                fn ($q) => $q->where('user_id', $user->id)
            ),
            'unread' => $query->issued()->whereHas(
                'recipients',
                fn ($q) => $q->where('user_id', $user->id)->whereNull('read_at')
            ),
            'sent' => $query->where('created_by', $user->id),

            // Drafts are already invisible to everyone but their author and the issuers; this is a
            // convenience filter on top of that curtain, not the curtain itself.
            'drafts' => $query->drafts(),
            'all' => $query,
            default => $query->issued(),
        };

        /** @var LengthAwarePaginator $memos */
        $memos = $query
            ->orderByRaw('CASE WHEN issued_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('memos.index', [
            'memos' => $memos,
            'statuses' => $this->statuses->optionsFor('memo'),
            'projects' => $this->projectOptions($request),
            'views' => $this->viewOptions(),
            'filters' => [
                'q' => $request->query('q'),
                'project' => $request->query('project'),
                'status' => $request->query('status'),
                'view' => $view,
            ],
            'canCreate' => $user->can('create', Memo::class),
        ]);
    }

    public function show(Request $request, Memo $memo): View
    {
        $this->authorize('view', $memo);

        $user = $request->user();

        $memo->loadMissing(['status', 'project', 'orgUnit', 'creator', 'issuer', 'recipients.user:id,name']);

        // Opening an issued memo you are addressed on is what "read" means. Done here rather than
        // by a button because a read receipt that people have to press is a read receipt that
        // measures diligence rather than delivery.
        $own = $memo->receiptFor($user);

        if ($own !== null && $memo->isIssued() && ! $own->isRead()) {
            $this->memos->markRead($own);
        }

        return view('memos.show', [
            'memo' => $memo,
            'ownReceipt' => $own,
            'timeline' => $this->activity->timelineFor($memo)->limit(25)->get(),
            'transitions' => $this->statuses->available('memo', $memo->status_id, $user),
            'candidates' => $this->candidateOptions($request, $memo),
            'canEdit' => $user->can('update', $memo),
            'canAddress' => $user->can('address', [$memo, null]),

            // Not the same answer as canAddress: adding a name to an issued memo is allowed and
            // grants the late recipient the same access the others got, whereas removing one from
            // an issued memo would falsify the record of who was told.
            'canUnaddress' => $user->can('unaddress', $memo),
            'canIssue' => $user->can('issue', $memo),

            // Asked separately rather than inferred from canIssue: MemoPolicy answers both from the
            // memo's current state, so exactly one of them is ever true and the page must not guess
            // which. Both routes through `memos.status`, which re-authorizes (§84).
            'canWithdraw' => $user->can('withdraw', $memo),
            'canDelete' => $user->can('delete', $memo),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Memo::class);

        return view('memos.create', [
            'memo' => new Memo(['confidentiality' => Confidentiality::Internal]),
            ...$this->formOptions($request, null),
        ]);
    }

    public function store(MemoRequest $request): RedirectResponse
    {
        $this->authorize('create', Memo::class);

        $user = $request->user();
        $data = $request->memoAttributes();

        $this->assertPlacementIsReachable($request, $data);

        $memo = $this->memos->create($data, $user);

        // The initial distribution list, authorized one candidate at a time against the memo that
        // now exists — so the policy sees its real confidentiality rather than a guess.
        $recipients = $this->authorizedCandidates($request, $memo, $request->recipientIds());

        if ($recipients !== []) {
            $this->memos->address($memo, $recipients, $user);
        }

        return redirect()->route('memos.show', $memo)
            ->with('status', $memo->displayName().' has been drafted. Issue it when the distribution is right.');
    }

    public function edit(Request $request, Memo $memo): View
    {
        $this->authorize('update', $memo);

        return view('memos.edit', [
            'memo' => $memo,
            ...$this->formOptions($request, $memo),
        ]);
    }

    public function update(MemoRequest $request, Memo $memo): RedirectResponse
    {
        $this->authorize('update', $memo);

        $data = $request->memoAttributes();

        $this->assertPlacementIsReachable($request, $data);

        $this->memos->update($memo, $data, $request->user());

        return redirect()->route('memos.show', $memo)
            ->with('status', $memo->displayName().' has been updated.');
    }

    /**
     * Add people to the distribution list.
     *
     * Every id is asked about separately, with the memo in hand, because this is the endpoint that
     * grants sight of the document. Ids the policy refuses are dropped silently rather than
     * reported: naming them back would confirm that those accounts exist (§61).
     */
    public function address(Request $request, Memo $memo): RedirectResponse
    {
        $validated = $request->validate([
            'recipients' => ['required', 'array', 'max:200'],
            'recipients.*' => ['integer'],
            'kind' => ['nullable', 'in:to,cc'],
        ]);

        $this->authorize('address', [$memo, null]);

        $ids = array_values(array_unique(array_map('intval', $validated['recipients'])));
        $allowed = $this->authorizedCandidates($request, $memo, $ids);

        if ($allowed === []) {
            return back()->withErrors(['recipients' => 'None of those people can be added to this memo.']);
        }

        $added = $this->memos->address($memo, $allowed, $request->user(), $validated['kind'] ?? 'to');

        return back()->with('status', count($added).' person(s) added to the distribution.');
    }

    public function unaddress(Request $request, Memo $memo, MemoRecipient $recipient): RedirectResponse
    {
        // Bound through the memo's own relation rather than a bare route model, so a recipient row
        // belonging to a different memo — or a different tenant — is a 404 rather than a delete.
        abort_unless((int) $recipient->memo_id === (int) $memo->getKey(), 404);

        $this->authorize('unaddress', $memo);

        $this->memos->unaddress($memo, (int) $recipient->user_id, $request->user());

        return back()->with('status', 'Removed from the distribution.');
    }

    /**
     * Issue or withdraw.
     *
     * The buttons come from StatusMachine::available(); this asks again with the same actor,
     * because a button that was not drawn is not a button that cannot be pressed (§84). The service
     * adds the rule the state machine cannot know: a memo with nobody on it cannot be issued.
     */
    public function transition(Request $request, Memo $memo): RedirectResponse
    {
        $validated = $request->validate([
            'status_id' => ['required', 'integer'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $to = Status::query()->forEntity('memo')->whereKey($validated['status_id'])->first();

        if ($to === null) {
            return back()->withErrors(['status_id' => 'That is not a status a memo can be in.']);
        }

        // Both directions are the same authority — issuing and withdrawing are the same act of
        // standing behind the document — and MemoPolicy answers from the memo's current state.
        $this->authorize($memo->isDraft() ? 'issue' : 'withdraw', $memo);

        try {
            $this->memos->transition($memo, $to, $request->user(), $validated['comment'] ?? null);
        } catch (\DomainException $e) {
            return back()->withErrors(['status_id' => $e->getMessage()]);
        }

        return redirect()->route('memos.show', $memo)
            ->with('status', $memo->displayName().' is now '.$to->label.'.');
    }

    public function destroy(Request $request, Memo $memo): RedirectResponse
    {
        $this->authorize('delete', $memo);

        $name = $memo->displayName();

        $this->activity->record(
            $memo,
            'deleted',
            $request->user()->name.' deleted '.$name,
            [],
            $request->user(),
            $memo->project_id !== null ? (int) $memo->project_id : null,
        );

        $memo->delete();

        return redirect()->route('memos.index')->with('status', $name.' has been deleted.');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * The subset of the requested ids that MemoPolicy::address() actually allows.
     *
     * Resolved through Eloquent so the tenant scope applies, then asked one at a time. A missing id
     * is skipped rather than thrown on, so one stale entry in a multi-select does not lose the
     * whole distribution list.
     *
     * @param  array<int,int>  $ids
     * @return array<int,int>
     */
    private function authorizedCandidates(Request $request, Memo $memo, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $user = $request->user();
        $policy = app(MemoPolicy::class);

        return User::query()
            ->whereIn('id', $ids)
            ->get()
            ->filter(fn (User $candidate) => $policy->address($user, $memo, $candidate))
            ->map(fn (User $candidate) => (int) $candidate->getKey())
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function assertPlacementIsReachable(Request $request, array $data): void
    {
        if (($projectId = $data['project_id'] ?? null) !== null) {
            $this->authorize('view', Project::findOrFail($projectId));
        }

        if (($unitId = $data['org_unit_id'] ?? null) !== null) {
            $this->authorize('view', OrgUnit::findOrFail($unitId));
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function formOptions(Request $request, ?Memo $memo): array
    {
        return [
            'projects' => $this->projectOptions($request),
            'units' => $this->unitOptions($request),
            'people' => $memo === null ? $this->candidateOptions($request, null) : [],
            'confidentialities' => Confidentiality::options(),
            'nextNumber' => $memo === null ? $this->numbers->preview('memo') : null,
        ];
    }

    /**
     * People this actor may put on this memo.
     *
     * Built by asking the policy about each candidate rather than reimplementing it in SQL, so the
     * picker never offers a name the save would refuse — and, just as importantly, never becomes a
     * staff directory for an account whose `user.view` says otherwise (§61).
     *
     * @return array<int|string,string>
     */
    private function candidateOptions(Request $request, ?Memo $memo): array
    {
        $user = $request->user();

        if (! $user->hasPermission('user.view')) {
            return [];
        }

        $policy = app(MemoPolicy::class);
        $subject = $memo ?? tap(new Memo, function (Memo $m) use ($user) {
            $m->organization_id = $user->organization_id;
            $m->confidentiality = Confidentiality::Internal;
        });

        $already = $memo !== null
            ? $memo->recipients()->pluck('user_id')->map(fn ($id) => (int) $id)->all()
            : [];

        return $this->compiler
            ->apply(User::query()->active(), $user, 'user.view')
            ->when($already !== [], fn ($q) => $q->whereNotIn('id', $already))
            ->orderBy('name')
            ->limit(500)
            ->get()
            ->filter(fn (User $candidate) => $policy->address($user, $subject, $candidate))
            ->mapWithKeys(fn (User $candidate) => [$candidate->id => $candidate->name])
            ->all();
    }

    /**
     * @return array<int|string,string>
     */
    private function projectOptions(Request $request): array
    {
        return $this->compiler
            ->apply(Project::query()->active(), $request->user(), 'project.view')
            ->orderBy('name')->limit(500)->get()
            ->mapWithKeys(fn (Project $p) => [$p->id => $p->displayName()])
            ->all();
    }

    /**
     * @return array<int|string,string>
     */
    private function unitOptions(Request $request): array
    {
        return $this->compiler
            ->apply(OrgUnit::query()->where('is_active', true), $request->user(), 'org_unit.view')
            ->orderBy('path')->get()
            ->mapWithKeys(fn (OrgUnit $u) => [
                $u->id => str_repeat('— ', max(0, (int) $u->depth)).$u->name,
            ])->all();
    }

    /**
     * @return array<string,string>
     */
    private function viewOptions(): array
    {
        return [
            'inbox' => 'Addressed to me',
            'unread' => 'Unread',
            'sent' => 'Written by me',
            'drafts' => 'Drafts',
            'issued' => 'All issued',
            'all' => 'Everything you can see',
        ];
    }
}
