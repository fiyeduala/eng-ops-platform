<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\SettingsRepository;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\RfiRequest;
use App\Models\Rfi;
use App\Services\RfiService;
use App\Support\Enums\Confidentiality;
use App\Support\Enums\RfiDirection;
use App\Support\Enums\RfiKind;
use App\Support\OptionList;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * RFIs, technical queries and site queries — one register of dated questions.
 *
 * ONE REGISTER FOR ALL THREE, because they differ only in who is asking whom and share every
 * property that matters: a required-by date, an answer, an author, and an argument about whether
 * the answer costs money. Three separate registers would produce exactly the failure this module
 * exists to prevent — nobody able to say what is outstanding, because outstanding things are in
 * three places. They keep separate NUMBER SERIES, because engineers quote "TQ-2026-0031" and mean
 * a specific document.
 *
 * DIRECTION IS THE FIELD THAT MATTERS MOST and the one a spreadsheet always loses. Outgoing means
 * somebody else owes us; incoming means the clock is running against us. Mixed together, a company
 * chases the client for forty answers while sitting on thirty of its own — which is why the default
 * view of this register is "owed by us".
 *
 * ANSWERING IS NOT EDITING. `rfi.respond` is a different permission from `rfi.edit`, and once an
 * answer exists the policy refuses both edit and delete: an answered technical query is a position
 * the organization has taken in writing, and a register that let it be silently reworded would be
 * worthless in the dispute it exists for (§59). Amending an answer is possible, requires the
 * heavier `rfi.close`, demands a reason, and is recorded as an amendment.
 */
class RfiController extends RegisterController
{
    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly RfiService $rfis,
        private readonly SettingsRepository $settings,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'rfi';
    }

    protected function modelClass(): string
    {
        return Rfi::class;
    }

    protected function routePrefix(): string
    {
        return 'rfis';
    }

    // ------------------------------------------------------------------ register

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Rfi::class);

        $user = $request->user();

        $query = $this->scopedQuery($user)->with([
            'status:id,label,color,is_terminal',
            'project:id,name,number',
            'discipline:id,name',
            'raiser:id,name',
            'answerer:id,name',
        ]);

        $this->applyCommonFilters($query, $request);

        if ($kind = (string) $request->query('kind', '')) {
            $query->ofKind($kind);
        }

        $view = (string) $request->query('view', 'owed-by-us');

        match ($view) {
            // The two halves of the outstanding report, and the reason `direction` exists.
            'owed-by-us' => $query->open()->where('direction', RfiDirection::Incoming->value),
            'owed-to-us' => $query->open()->where('direction', RfiDirection::Outgoing->value),
            'overdue' => $query->open()->whereNotNull('required_by')
                ->whereDate('required_by', '<', now()->startOfDay()),
            'answered' => $query->whereNotNull('answered_at'),
            'mine' => $query->where(fn ($q) => $q->where('raised_by', $user->id)
                ->orWhere('created_by', $user->id)),
            'all' => $query,
            default => $query->open(),
        };

        /** @var LengthAwarePaginator $rfis */
        $rfis = $query
            ->orderByRaw('CASE WHEN required_by IS NULL THEN 1 ELSE 0 END')
            ->orderBy('required_by')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('rfis.index', [
            'rfis' => $rfis,
            'statuses' => $this->statuses->optionsFor('rfi'),
            'projects' => $this->projectOptions($user),
            'kinds' => RfiKind::options(),
            'views' => [
                'owed-by-us' => 'We owe an answer',
                'owed-to-us' => 'Awaiting their answer',
                'overdue' => 'Late',
                'answered' => 'Answered',
                'mine' => 'Mine',
                'all' => 'Everything you can see',
            ],
            'filters' => [...$this->commonFilters($request, 'owed-by-us'), 'kind' => $request->query('kind')],
            'canCreate' => $user->can('create', Rfi::class),
        ]);
    }

    public function show(Request $request, Rfi $rfi): View
    {
        $this->authorize('view', $rfi);

        $user = $request->user();

        $rfi->loadMissing([
            'status', 'project', 'discipline', 'orgUnit', 'party',
            'raiser:id,name', 'answerer:id,name', 'creator:id,name',
        ]);

        return view('rfis.show', [
            'rfi' => $rfi,
            'timeline' => $this->activity->timelineFor($rfi)->limit(25)->get(),
            'transitions' => $this->statuses->available('rfi', $rfi->status_id, $user),
            'workflowInstance' => $rfi->currentWorkflowInstance(),
            'canEdit' => $user->can('update', $rfi),
            'canDelete' => $user->can('delete', $rfi),
            'canSend' => $user->can('submit', $rfi),
            'canRespond' => $user->can('respond', $rfi),
            'canAmend' => $user->can('amendAnswer', $rfi),
            'canClose' => $user->can('close', $rfi),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Rfi::class);

        $user = $request->user();

        // A suggestion, not a rule. The contract governs the response time; the setting only saves
        // somebody typing the same date forty times a month, and they may overwrite it freely.
        $days = max(1, $this->settings->int('engineering.rfi_response_days', 14));

        return view('rfis.create', [
            'rfi' => new Rfi([
                'kind' => (string) $request->query('kind', RfiKind::Rfi->value),
                'direction' => RfiDirection::Outgoing->value,
                'confidentiality' => Confidentiality::Internal->value,
                'project_id' => (int) $request->query('project', 0) ?: null,
                'raised_by' => $user->id,
                'raised_on' => now()->toDateString(),
                'required_by' => now()->addWeekdays($days)->toDateString(),
            ]),
            ...$this->formOptions($request),
        ]);
    }

    public function store(RfiRequest $request): RedirectResponse
    {
        $this->authorize('create', Rfi::class);

        $data = $request->rfiAttributes();

        $this->assertPlacementIsReachable($data);

        try {
            $rfi = $this->rfis->create($data, $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['party_id' => $e->getMessage()]);
        }

        return redirect($this->showRoute($rfi))
            ->with('status', $rfi->displayName().' has been raised.');
    }

    public function edit(Request $request, Rfi $rfi): View
    {
        $this->authorize('update', $rfi);

        return view('rfis.edit', [
            'rfi' => $rfi,
            ...$this->formOptions($request),
        ]);
    }

    /**
     * `kind` is fixed after creation.
     *
     * Not a whim: the kind chose the numbering series, so an RFI that became a technical query
     * would carry an RFI number for the rest of its life and break the one thing an identifier is
     * for. The form still renders the field so the record reads correctly; the value is ignored,
     * server-side, which is the only place ignoring it counts (§84).
     */
    public function update(RfiRequest $request, Rfi $rfi): RedirectResponse
    {
        $this->authorize('update', $rfi);

        $data = $request->rfiAttributes();

        unset($data['kind']);

        $this->assertPlacementIsReachable($data);

        try {
            $this->rfis->update($rfi, $data, $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['party_id' => $e->getMessage()]);
        }

        return redirect($this->showRoute($rfi))
            ->with('status', $rfi->displayName().' has been updated.');
    }

    public function destroy(Request $request, Rfi $rfi): RedirectResponse
    {
        $this->authorize('delete', $rfi);

        $name = $rfi->displayName();

        $this->recordDeletion($rfi, $request->user(), 'query');

        $rfi->delete();

        return redirect($this->indexRoute())->with('status', $name.' has been deleted.');
    }

    // ------------------------------------------------------------------ lifecycle

    /** Put it in front of whoever has to answer. */
    public function send(Request $request, Rfi $rfi): RedirectResponse
    {
        $this->authorize('submit', $rfi);

        try {
            $this->rfis->send($rfi, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['send' => $e->getMessage()]);
        }

        return back()->with('status', $rfi->displayName().' has been sent.');
    }

    /**
     * Answer it.
     *
     * The narrative recorded against this names the FACT of the answer, never its text — an
     * activity entry is read by everybody who can see the record, and an answer can be commercially
     * sensitive in a way the question is not (§68).
     */
    public function respond(Request $request, Rfi $rfi): RedirectResponse
    {
        $this->authorize('respond', $rfi);

        $validated = $request->validate([
            'answer' => ['required', 'string', 'max:20000'],
            'cost_impact' => ['boolean'],
            'schedule_impact' => ['boolean'],
            'impact_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->rfis->answer(
                $rfi,
                $validated['answer'],
                $request->user(),
                $request->boolean('cost_impact'),
                $request->boolean('schedule_impact'),
                $validated['impact_notes'] ?? null,
            );
        } catch (\DomainException $e) {
            return back()->withErrors(['answer' => $e->getMessage()]);
        }

        return back()->with('status', $rfi->displayName().' has been answered.');
    }

    /**
     * Correct an answer that has already been given.
     *
     * Behind `rfi.close` — the heaviest ability this register has — and it demands a reason, which
     * is written to the timeline. Rewriting a position the organization already stated is a serious
     * act, and the record has to show that it happened and why (§59).
     */
    public function amend(Request $request, Rfi $rfi): RedirectResponse
    {
        $this->authorize('amendAnswer', $rfi);

        $validated = $request->validate([
            'answer' => ['required', 'string', 'max:20000'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $this->rfis->amendAnswer($rfi, $validated['answer'], $request->user(), $validated['reason']);
        } catch (\DomainException $e) {
            return back()->withErrors(['answer' => $e->getMessage()]);
        }

        return back()->with('status', 'The answer has been amended and the change recorded.');
    }

    public function close(Request $request, Rfi $rfi): RedirectResponse
    {
        $this->authorize('close', $rfi);

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->rfis->close($rfi, $request->user(), $validated['note'] ?? null);
        } catch (\DomainException $e) {
            return back()->withErrors(['close' => $e->getMessage()]);
        }

        return back()->with('status', $rfi->displayName().' has been closed.');
    }

    public function transition(Request $request, Rfi $rfi): RedirectResponse
    {
        return $this->moveStatus($request, $rfi);
    }

    // ------------------------------------------------------------------ options

    /** @return array<string,mixed> */
    private function formOptions(Request $request): array
    {
        return [
            ...$this->placementOptions($request->user()),
            ...$this->partyOptions($request->user()),
            'kinds' => RfiKind::options(),
            'directions' => RfiDirection::options(),
        ];
    }
}
