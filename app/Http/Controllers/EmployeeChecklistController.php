<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Models\ChecklistTemplate;
use App\Models\Employee;
use App\Models\EmployeeChecklist;
use App\Models\EmployeeChecklistItem;
use App\Services\ChecklistService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Somebody's own joining or leaving list — the copy, not the template.
 *
 * TICKING NEEDS `checklist.edit`; UNTICKING NEEDS `checklist.manage` AND A REASON. They are not the
 * same act: one is doing your job, the other is saying that something recorded as done was not, and
 * the second is the one that gets used to make an uncleared leaver look cleared. The reason goes on
 * the record's timeline, which is protected (§59).
 *
 * THE LIST IS NOT THE CLEARANCE. An exit reads what somebody still holds from the asset and petty
 * cash registers; the ticks here are the human half and cannot substitute for it (see
 * EmployeeExitService::blockers).
 */
class EmployeeChecklistController extends Controller
{
    public function __construct(
        private readonly ScopeCompiler $compiler,
        private readonly ActivityRecorder $activity,
        private readonly ChecklistService $checklists,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', EmployeeChecklist::class);
        $user = $request->user();

        $query = $this->compiler
            ->apply(EmployeeChecklist::query(), $user, 'checklist.view')
            ->with(['employee', 'template', 'orgUnit'])
            ->withCount(['items', 'items as done_items_count' => fn ($q) => $q->whereNotNull('done_at')]);

        $kind = (string) $request->query('kind', '');

        if (in_array($kind, ChecklistTemplate::KINDS, true)) {
            $query->ofKind($kind);
        }

        $view = (string) $request->query('view', 'open');

        if ($view === 'open') {
            $query->open();
        }

        return view('employee-checklists.index', [
            'checklists' => $query->orderBy('due_on')->orderByDesc('id')->paginate(30)->withQueryString(),
            'filters' => ['kind' => $kind, 'view' => $view],
            'kinds' => ChecklistTemplate::KINDS,
        ]);
    }

    public function show(Request $request, EmployeeChecklist $employeeChecklist): View
    {
        $this->authorize('view', $employeeChecklist);
        $user = $request->user();
        $employeeChecklist->load(['employee', 'template', 'orgUnit', 'completer', 'items.responsibleRole', 'items.doneBy']);

        return view('employee-checklists.show', [
            'checklist' => $employeeChecklist,
            'items' => $employeeChecklist->items->sortBy('sequence'),
            'timeline' => $this->activity->timelineFor($employeeChecklist)->limit(25)->get(),
            'canTick' => $user->can('tick', $employeeChecklist),
            'canUntick' => $user->can('untick', $employeeChecklist),
            'canDelete' => $user->can('delete', $employeeChecklist),
        ]);
    }

    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorize('create', EmployeeChecklist::class);
        // Starting a list for somebody means being able to see them in the first place (§94).
        $this->authorize('view', $employee);

        $data = $request->validate([
            'kind' => ['required', 'string', 'max:20'],
            'checklist_template_id' => ['nullable', 'integer'],
            'started_on' => ['nullable', 'date'],
            'due_on' => ['nullable', 'date'],
        ]);

        // Resolved through Eloquent so the organisation scope applies — a template id from another
        // company does not resolve, and the service refuses a mismatch again anyway (§75).
        $template = ($templateId = $data['checklist_template_id'] ?? null) !== null
            ? ChecklistTemplate::query()->findOrFail($templateId)
            : $this->checklists->defaultTemplate($employee->organization_id, $data['kind']);

        try {
            $checklist = $this->checklists->start($employee, $data['kind'], $template, $data, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['kind' => $e->getMessage()]);
        }

        return redirect()->route('employee-checklists.show', $checklist)
            ->with('status', 'The checklist has been started.');
    }

    public function destroy(Request $request, EmployeeChecklist $employeeChecklist): RedirectResponse
    {
        $this->authorize('delete', $employeeChecklist);

        $this->activity->record(
            $employeeChecklist,
            'deleted',
            $request->user()->name.' deleted the '.$employeeChecklist->kind.' checklist for '.$employeeChecklist->employee?->fullName().'.',
            [],
            $request->user(),
        );

        $employeeChecklist->delete();

        return redirect()->route('employee-checklists.index')->with('status', 'The checklist has been deleted.');
    }

    // ------------------------------------------------------------------ items

    public function addItem(Request $request, EmployeeChecklist $employeeChecklist): RedirectResponse
    {
        $this->authorize('update', $employeeChecklist);

        $data = $request->validate([
            'label' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:500'],
            'requires_note' => ['nullable', 'boolean'],
        ]);

        try {
            $this->checklists->addItem(
                $employeeChecklist,
                $data['label'],
                $data['description'] ?? null,
                (bool) ($data['requires_note'] ?? false),
                $request->user(),
            );
        } catch (\DomainException $e) {
            return back()->withErrors(['label' => $e->getMessage()]);
        }

        return back()->with('status', 'The step has been added.');
    }

    public function tick(Request $request, EmployeeChecklistItem $item): RedirectResponse
    {
        // Authorized against the CHECKLIST the item belongs to, because that is where the employee,
        // the unit and the confidentiality live. An item id belonging to somebody else's list is
        // answered by their checklist's policy, not by this one's (§94).
        $this->authorize('tick', $item->checklist);

        $note = $request->validate(['note' => ['nullable', 'string', 'max:1000']])['note'] ?? null;

        try {
            $this->checklists->tick($item, $note, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['note' => $e->getMessage()]);
        }

        return back()->with('status', 'Done.');
    }

    public function untick(Request $request, EmployeeChecklistItem $item): RedirectResponse
    {
        $this->authorize('untick', $item->checklist);

        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'];

        try {
            $this->checklists->untick($item, $reason, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', 'The step has been reopened.');
    }
}
