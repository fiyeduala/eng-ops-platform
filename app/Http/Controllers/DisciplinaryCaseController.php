<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\DisciplinaryCaseRequest;
use App\Http\Requests\DisciplinaryOutcomeRequest;
use App\Models\DisciplinaryCase;
use App\Models\Employee;
use App\Models\User;
use App\Services\DisciplinaryService;
use App\Support\OptionList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Conduct cases.
 *
 * MOST PEOPLE WILL OPEN THIS PAGE AND SEE NOTHING, and that is the design working. The register is
 * restricted with no parent, so the list answers to `disciplinary.view_confidential` or to a Gate 4
 * row on one case. A department head seeing an empty index is not a bug to be reported (§75, §6).
 *
 * EVERY ACT IS ITS OWN ROUTE WITH ITS OWN CHECK — assign, investigate, hear, decide, appeal, close,
 * withdraw. There is no update path that reaches the outcome: sanctioning somebody is not a field on
 * an edit form (§84).
 *
 * THE SUBJECT IS RE-AUTHORIZED ON STORE. Being allowed to open cases is not being allowed to open one
 * against anybody in the company — the employee must be inside the actor's own reach (§94).
 */
class DisciplinaryCaseController extends RegisterController
{
    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly DisciplinaryService $cases,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'disciplinary_case';
    }

    protected function modelClass(): string
    {
        return DisciplinaryCase::class;
    }

    protected function routePrefix(): string
    {
        return 'disciplinary-cases';
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', DisciplinaryCase::class);
        $user = $request->user();

        $query = $this->scopedQuery($user, 'disciplinary.view')->with(['status', 'employee', 'investigator']);
        $this->applyCommonFilters($query, $request);
        $filters = $this->commonFilters($request, 'open');

        if ($filters['view'] === 'open') {
            $query->whereNull('closed_at');
        } elseif ($filters['view'] === 'live') {
            // The question the register is actually asked: who is carrying a warning that still counts.
            $query->liveSanctions();
        }

        if (($severity = trim((string) $request->query('severity', ''))) !== '') {
            $query->where('severity', $severity);
        }

        return view('disciplinary-cases.index', [
            'cases' => $query->orderByDesc('reported_on')->orderByDesc('id')->paginate(30)->withQueryString(),
            'statuses' => $this->statuses->optionsFor('disciplinary_case'),
            'severities' => $this->severityOptions(),
            'filters' => [...$filters, 'severity' => $severity],
            'canCreate' => $user->can('create', DisciplinaryCase::class),
        ]);
    }

    public function show(Request $request, DisciplinaryCase $disciplinaryCase): View
    {
        $this->authorize('view', $disciplinaryCase);
        $user = $request->user();
        $disciplinaryCase->load(['status', 'employee.position', 'employee.orgUnit', 'investigator', 'reporter', 'decidedBy', 'closedBy']);

        return view('disciplinary-cases.show', [
            'case' => $disciplinaryCase,
            'timeline' => $this->activity->timelineFor($disciplinaryCase)->limit(25)->get(),
            'users' => $user->can('assignInvestigator', $disciplinaryCase) ? $this->userOptions($user) : [],
            'outcomes' => $this->outcomeOptions($user),
            'canEdit' => $user->can('update', $disciplinaryCase),
            'canDelete' => $user->can('delete', $disciplinaryCase),
            'canAssign' => $user->can('assignInvestigator', $disciplinaryCase),
            'canInvestigate' => $user->can('investigate', $disciplinaryCase),
            'canScheduleHearing' => $user->can('scheduleHearing', $disciplinaryCase),
            'canDecide' => $user->can('decide', $disciplinaryCase),
            'canDecideAppeal' => $user->can('decideAppeal', $disciplinaryCase),
            'canClose' => $user->can('close', $disciplinaryCase),
            'canWithdraw' => $user->can('withdraw', $disciplinaryCase),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', DisciplinaryCase::class);

        return view('disciplinary-cases.create', [
            'case' => new DisciplinaryCase([
                'severity' => 'minor',
                'reported_on' => now()->toDateString(),
            ]),
            ...$this->formOptions($request->user()),
        ]);
    }

    public function store(DisciplinaryCaseRequest $request): RedirectResponse
    {
        $this->authorize('create', DisciplinaryCase::class);
        $data = $request->validated();

        $employee = Employee::query()->findOrFail($data['employee_id']);
        $this->authorize('view', $employee);

        try {
            $case = $this->cases->create($data, $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['summary' => $e->getMessage()]);
        }

        return redirect()->route('disciplinary-cases.show', $case)
            ->with('status', $case->number.' has been opened.');
    }

    public function edit(Request $request, DisciplinaryCase $disciplinaryCase): View
    {
        $this->authorize('update', $disciplinaryCase);

        return view('disciplinary-cases.edit', [
            'case' => $disciplinaryCase,
            ...$this->formOptions($request->user()),
        ]);
    }

    public function update(DisciplinaryCaseRequest $request, DisciplinaryCase $disciplinaryCase): RedirectResponse
    {
        $this->authorize('update', $disciplinaryCase);

        try {
            $this->cases->update($disciplinaryCase, $request->validated(), $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['summary' => $e->getMessage()]);
        }

        return redirect()->route('disciplinary-cases.show', $disciplinaryCase)
            ->with('status', $disciplinaryCase->number.' has been updated.');
    }

    public function destroy(Request $request, DisciplinaryCase $disciplinaryCase): RedirectResponse
    {
        $this->authorize('delete', $disciplinaryCase);
        $this->recordDeletion($disciplinaryCase, $request->user(), 'case');
        $disciplinaryCase->delete();

        return redirect()->route('disciplinary-cases.index')->with('status', 'The case has been deleted.');
    }

    // ------------------------------------------------------------------ the process

    public function assignInvestigator(Request $request, DisciplinaryCase $disciplinaryCase): RedirectResponse
    {
        $this->authorize('assignInvestigator', $disciplinaryCase);
        $data = $request->validate(['investigator_id' => ['required', 'integer']]);

        // Resolved through the tenant-scoped query rather than by raw id: a posted id from another
        // company must not so much as name a person here (§74, §75, §84).
        $investigator = User::query()->find($data['investigator_id']);

        if ($investigator === null) {
            return back()->withErrors(['investigator_id' => 'That person could not be found.']);
        }

        try {
            $this->cases->assignInvestigator($disciplinaryCase, $investigator, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['investigator_id' => $e->getMessage()]);
        }

        return back()->with('status', $investigator->name.' has been asked to investigate.');
    }

    public function recordInvestigation(Request $request, DisciplinaryCase $disciplinaryCase): RedirectResponse
    {
        $this->authorize('investigate', $disciplinaryCase);
        $data = $request->validate(['investigation_notes' => ['nullable', 'string', 'max:20000']]);

        try {
            $this->cases->recordInvestigation($disciplinaryCase, $data['investigation_notes'] ?? null, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['investigation_notes' => $e->getMessage()]);
        }

        return back()->with('status', 'The investigation has been updated.');
    }

    public function scheduleHearing(Request $request, DisciplinaryCase $disciplinaryCase): RedirectResponse
    {
        $this->authorize('scheduleHearing', $disciplinaryCase);
        $data = $request->validate(['hearing_on' => ['required', 'date']]);

        try {
            $this->cases->scheduleHearing($disciplinaryCase, $data['hearing_on'], $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['hearing_on' => $e->getMessage()]);
        }

        return back()->with('status', 'The hearing has been scheduled.');
    }

    public function recordHearing(Request $request, DisciplinaryCase $disciplinaryCase): RedirectResponse
    {
        $this->authorize('investigate', $disciplinaryCase);
        $data = $request->validate(['hearing_notes' => ['nullable', 'string', 'max:20000']]);

        try {
            $this->cases->recordHearing($disciplinaryCase, $data['hearing_notes'] ?? null, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['hearing_notes' => $e->getMessage()]);
        }

        return back()->with('status', 'The hearing record has been saved.');
    }

    public function decide(DisciplinaryOutcomeRequest $request, DisciplinaryCase $disciplinaryCase): RedirectResponse
    {
        $this->authorize('decide', $disciplinaryCase);

        try {
            $this->cases->decide($disciplinaryCase, $request->validated(), $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['outcome' => $e->getMessage()]);
        }

        return back()->with('status', 'The outcome has been recorded.');
    }

    public function recordAppeal(Request $request, DisciplinaryCase $disciplinaryCase): RedirectResponse
    {
        $this->authorize('decideAppeal', $disciplinaryCase);
        $data = $request->validate(['appeal_received_on' => ['required', 'date', 'before_or_equal:today']]);

        try {
            $this->cases->recordAppeal($disciplinaryCase, $data['appeal_received_on'], $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['appeal_received_on' => $e->getMessage()]);
        }

        return back()->with('status', 'The appeal has been recorded.');
    }

    public function decideAppeal(Request $request, DisciplinaryCase $disciplinaryCase): RedirectResponse
    {
        $this->authorize('decideAppeal', $disciplinaryCase);
        $data = $request->validate([
            'appeal_outcome' => ['required', 'string', 'max:40'],
            'appeal_notes' => ['nullable', 'string', 'max:10000'],
            // Optional, and the point of the form: an appeal that succeeds rewrites the sanction
            // rather than leaving a contradicted one on the record.
            'outcome' => ['nullable', 'string', 'max:40'],
            'sanction_expires_on' => ['nullable', 'date'],
        ]);

        try {
            $this->cases->decideAppeal($disciplinaryCase, $data, $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['appeal_outcome' => $e->getMessage()]);
        }

        return back()->with('status', 'The appeal decision has been recorded.');
    }

    public function close(Request $request, DisciplinaryCase $disciplinaryCase): RedirectResponse
    {
        $this->authorize('close', $disciplinaryCase);

        try {
            $this->cases->close($disciplinaryCase, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['case' => $e->getMessage()]);
        }

        return back()->with('status', $disciplinaryCase->number.' has been closed.');
    }

    public function withdraw(Request $request, DisciplinaryCase $disciplinaryCase): RedirectResponse
    {
        $this->authorize('withdraw', $disciplinaryCase);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'];

        try {
            $this->cases->withdraw($disciplinaryCase, $reason, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', 'The case has been withdrawn.');
    }

    // ------------------------------------------------------------------ helpers

    /** @return array<string,mixed> */
    private function formOptions(User $user): array
    {
        return [
            'employees' => $this->compiler
                ->apply(Employee::query()->active(), $user, 'employee.view')
                ->orderBy('last_name')->orderBy('first_name')->limit(500)->get()
                ->mapWithKeys(fn (Employee $e) => [$e->id => $e->fullName().' — '.$e->employee_no])
                ->all(),
            'categories' => $this->categoryOptions($user),
            'severities' => $this->severityOptions(),
        ];
    }

    /** @return array<string,string> */
    private function categoryOptions(User $user): array
    {
        $keys = $this->lists->keys('disciplinary.categories', DisciplinaryCase::DEFAULT_CATEGORIES, $user->organization_id);

        return collect($keys)->mapWithKeys(fn (string $k) => [$k => ucfirst(str_replace('_', ' ', $k))])->all();
    }

    /** @return array<string,string> */
    private function outcomeOptions(User $user): array
    {
        $keys = $this->lists->keys('disciplinary.outcomes', DisciplinaryCase::DEFAULT_OUTCOMES, $user->organization_id);

        return collect($keys)->mapWithKeys(fn (string $k) => [$k => ucfirst(str_replace('_', ' ', $k))])->all();
    }

    /** Fixed, unlike the categories: three words the platform reasons about. @return array<string,string> */
    private function severityOptions(): array
    {
        return ['minor' => 'Minor', 'serious' => 'Serious', 'gross' => 'Gross misconduct'];
    }
}
