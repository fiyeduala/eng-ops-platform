<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\TaskChecklistItemRequest;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Services\TaskChecklistService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The checklist on a task.
 *
 * Every method authorizes against the PARENT TASK, not the line. That is the whole design: a
 * checklist has no permissions of its own (see TaskChecklistItemPolicy), so "may I tick this box"
 * is exactly "may I edit this task", asked of a record that carries a project, a unit, an assignee
 * and a confidentiality for the five gates to work on.
 *
 * The routes use scoped bindings, so `/tasks/7/checklist/93` where item 93 belongs to task 8 is a
 * 404 at the router rather than an authorization near-miss — the IDOR case §94 asks about, closed
 * before a controller runs.
 */
class TaskChecklistItemController extends Controller
{
    public function __construct(
        private readonly TaskChecklistService $checklist,
    ) {}

    public function store(TaskChecklistItemRequest $request, Task $task): RedirectResponse
    {
        $this->authorize('update', $task);

        $this->checklist->add($task, $request->validated(), $request->user());

        return back()->with('status', 'Checklist item added.');
    }

    public function update(TaskChecklistItemRequest $request, Task $task, TaskChecklistItem $checklistItem): RedirectResponse
    {
        $this->authorize('update', $task);

        $this->checklist->update($checklistItem, $request->validated(), $request->user());

        return back()->with('status', 'Checklist item updated.');
    }

    /**
     * Tick or untick.
     *
     * Its own endpoint rather than a field on the editor, because the tick is stamped with who did
     * it and when. On an inspection checklist that stamp is the feature; a generic update that
     * could set the flag without it would produce a completed line with nobody's name on it.
     */
    public function toggle(Request $request, Task $task, TaskChecklistItem $checklistItem): RedirectResponse
    {
        $this->authorize('update', $task);

        $validated = $request->validate(['is_done' => ['required', 'boolean']]);

        $this->checklist->toggle($checklistItem, (bool) $validated['is_done'], $request->user());

        return back();
    }

    public function destroy(Request $request, Task $task, TaskChecklistItem $checklistItem): RedirectResponse
    {
        $this->authorize('update', $task);

        $this->checklist->remove($checklistItem, $request->user());

        return back()->with('status', 'Checklist item removed.');
    }
}
