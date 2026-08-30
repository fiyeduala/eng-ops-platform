<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\TaskDependencyRequest;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Services\TaskDependencyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "This task waits for that one."
 *
 * Two authorization questions, and the second is the one a nested CRUD controller would miss:
 *
 *   1. May the actor edit the WAITING task? — reach over the record being constrained.
 *   2. May the actor SEE the BLOCKING task? — because the dependency renders that task's number
 *      and title on this page, and because without the check the endpoint would answer "does task
 *      412 exist in this company?" for any id somebody typed (§61, §84).
 *
 * `existsInTenant` in the form request covers neither: it pins the id to the tenant, which stops
 * the cross-tenant edge, but it cannot run Gates 3 and 5 — those need the model.
 *
 * Cycles are the service's to refuse. A controller sees one edge; a cycle is a property of the
 * graph.
 */
class TaskDependencyController extends Controller
{
    public function __construct(
        private readonly TaskDependencyService $dependencies,
    ) {}

    public function store(TaskDependencyRequest $request, Task $task): RedirectResponse
    {
        $this->authorize('update', $task);

        $data = $request->validated();

        // Resolved through Eloquent so the tenant scope applies, then put through the same policy
        // the task list uses — one definition of "tasks I can see".
        $blocker = Task::findOrFail((int) $data['depends_on_task_id']);

        $this->authorize('view', $blocker);

        try {
            $this->dependencies->add($task, $blocker, $request->user(), (string) $data['type']);
        } catch (\DomainException $e) {
            return back()->withErrors(['depends_on_task_id' => $e->getMessage()])->withInput();
        }

        return back()->with('status', $task->number.' now waits for '.$blocker->number.'.');
    }

    public function destroy(Request $request, Task $task, TaskDependency $dependency): RedirectResponse
    {
        $this->authorize('update', $task);

        $this->dependencies->remove($dependency, $request->user());

        return back()->with('status', 'The dependency has been removed.');
    }
}
