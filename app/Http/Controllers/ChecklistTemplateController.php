<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Http\Requests\ChecklistTemplateRequest;
use App\Models\ChecklistTemplate;
use App\Models\ChecklistTemplateItem;
use App\Models\OrgUnit;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The company's joining and leaving lists, as configuration.
 *
 * NO STEP OF EITHER LIST IS IN CODE. What a new starter is given, what a leaver hands back and who
 * is responsible for each is company data, edited here — an office in one country issues a laptop
 * and a health card, one in another issues neither, and neither should need a deployment.
 *
 * ITEMS ARE REWRITTEN WHOLESALE on save, which is safe on a TEMPLATE and would not be on somebody's
 * own copied list: a template has no ticks, no notes and no record of who did what to destroy. The
 * copies people actually work through are edited one item at a time, in EmployeeChecklistController.
 */
class ChecklistTemplateController extends Controller
{
    public function __construct(
        private readonly ScopeCompiler $compiler,
        private readonly ActivityRecorder $activity,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', ChecklistTemplate::class);
        $user = $request->user();

        $templates = $this->compiler
            ->apply(ChecklistTemplate::query(), $user, 'checklist.view')
            ->with('orgUnit')
            ->withCount('items')
            ->orderBy('kind')->orderBy('name')
            ->get();

        return view('checklist-templates.index', [
            'onboarding' => $templates->where('kind', ChecklistTemplate::KIND_ONBOARDING),
            'exit' => $templates->where('kind', ChecklistTemplate::KIND_EXIT),
            'canCreate' => $user->can('create', ChecklistTemplate::class),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', ChecklistTemplate::class);

        return view('checklist-templates.create', [
            'template' => new ChecklistTemplate([
                'kind' => in_array((string) $request->query('kind'), ChecklistTemplate::KINDS, true)
                    ? (string) $request->query('kind')
                    : ChecklistTemplate::KIND_ONBOARDING,
                'is_active' => true,
            ]),
            'items' => collect(),
            ...$this->formOptions($request->user()),
        ]);
    }

    public function store(ChecklistTemplateRequest $request): RedirectResponse
    {
        $this->authorize('create', ChecklistTemplate::class);
        $data = $request->validated();
        $user = $request->user();

        if (($unitId = $data['org_unit_id'] ?? null) !== null) {
            $this->authorize('view', OrgUnit::findOrFail($unitId));
        }

        $template = DB::transaction(function () use ($data, $user) {
            $template = new ChecklistTemplate([
                'kind' => $data['kind'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'org_unit_id' => $data['org_unit_id'] ?? null,
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);

            $template->created_by = $user->getKey();
            $template->save();

            $this->writeItems($template, $data['items'] ?? []);
            $this->setDefault($template, (bool) ($data['is_default'] ?? false));

            $this->activity->record($template, 'created', $user->name.' created the '.$template->kind.' list “'.$template->name.'”.', [], $user);

            return $template;
        });

        return redirect()->route('checklist-templates.edit', $template)
            ->with('status', 'The checklist has been created.');
    }

    public function edit(Request $request, ChecklistTemplate $checklistTemplate): View
    {
        $this->authorize('update', $checklistTemplate);

        return view('checklist-templates.edit', [
            'template' => $checklistTemplate,
            'items' => $checklistTemplate->items()->get(),
            ...$this->formOptions($request->user()),
        ]);
    }

    public function update(ChecklistTemplateRequest $request, ChecklistTemplate $checklistTemplate): RedirectResponse
    {
        $this->authorize('update', $checklistTemplate);
        $data = $request->validated();
        $user = $request->user();

        if (($unitId = $data['org_unit_id'] ?? null) !== null) {
            $this->authorize('view', OrgUnit::findOrFail($unitId));
        }

        DB::transaction(function () use ($checklistTemplate, $data, $user) {
            $checklistTemplate->fill([
                'kind' => $data['kind'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'org_unit_id' => $data['org_unit_id'] ?? null,
                'is_active' => (bool) ($data['is_active'] ?? false),
            ]);
            $checklistTemplate->updated_by = $user->getKey();
            $checklistTemplate->save();

            $this->writeItems($checklistTemplate, $data['items'] ?? []);
            $this->setDefault($checklistTemplate, (bool) ($data['is_default'] ?? false));

            $this->activity->record($checklistTemplate, 'updated', $user->name.' changed the list “'.$checklistTemplate->name.'”.', [], $user);
        });

        return redirect()->route('checklist-templates.edit', $checklistTemplate)
            ->with('status', 'The checklist has been saved.');
    }

    public function destroy(Request $request, ChecklistTemplate $checklistTemplate): RedirectResponse
    {
        $this->authorize('delete', $checklistTemplate);

        // Soft delete only, and the copies already handed out keep working: EmployeeChecklist reads
        // its template `withTrashed()`, so retiring a list never blanks somebody's joining record.
        $this->activity->record(
            $checklistTemplate,
            'deleted',
            $request->user()->name.' retired the list “'.$checklistTemplate->name.'”.',
            [],
            $request->user(),
        );

        $checklistTemplate->delete();

        return redirect()->route('checklist-templates.index')->with('status', 'The checklist has been retired.');
    }

    // ------------------------------------------------------------------ internals

    /**
     * Replace the steps with what was posted.
     *
     * The ids of existing rows are NOT read back from the form. Everything is rewritten from
     * position, so a hidden field naming another template's item cannot move it here (§84).
     *
     * @param  array<int,array<string,mixed>>  $items
     */
    private function writeItems(ChecklistTemplate $template, array $items): void
    {
        $template->items()->delete();

        $sequence = 0;

        foreach ($items as $row) {
            $label = trim((string) ($row['label'] ?? ''));

            if ($label === '') {
                continue;
            }

            $item = new ChecklistTemplateItem([
                'label' => mb_substr($label, 0, 200),
                'description' => $row['description'] ?? null,
                'responsible_role_id' => $row['responsible_role_id'] ?? null,
                'requires_note' => (bool) ($row['requires_note'] ?? false),
                'sequence' => ++$sequence,
            ]);

            $item->organization_id = $template->organization_id;
            $item->checklist_template_id = $template->getKey();
            $item->save();
        }
    }

    /**
     * One default per kind, per organisation.
     *
     * EmployeeExitService starts a leaver's list from whatever this points at, so two defaults would
     * make which list a leaver gets depend on row order.
     */
    private function setDefault(ChecklistTemplate $template, bool $isDefault): void
    {
        if (! $isDefault) {
            $template->forceFill(['is_default' => false])->save();

            return;
        }

        ChecklistTemplate::query()
            ->where('kind', $template->kind)
            ->whereKeyNot($template->getKey())
            ->update(['is_default' => false]);

        $template->forceFill(['is_default' => true])->save();
    }

    /** @return array<string,mixed> */
    private function formOptions(User $user): array
    {
        return [
            'kinds' => ChecklistTemplate::KINDS,
            'units' => $this->compiler
                ->apply(OrgUnit::query()->where('is_active', true), $user, 'org_unit.view')
                ->orderBy('path')->get()
                ->mapWithKeys(fn (OrgUnit $u) => [$u->id => str_repeat('— ', max(0, (int) $u->depth)).$u->name])
                ->all(),
            'roles' => $user->hasPermission('role.view')
                ? Role::query()->orderBy('name')->pluck('name', 'id')->all()
                : [],
        ];
    }
}
