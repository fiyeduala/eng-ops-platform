{{--
    Shared by create and edit.

    Four things a task has that are NOT on this form:

      - the number, issued by the numbering engine at save (§64)
      - the status, which moves through the configured whitelist on the task page (§63)
      - the organization, which comes from the session and never from a form (§74)
      - the ASSIGNEE, on edit only — assignment is a grant with its own route, its own two-question
        policy and its own event, so it lives on the task page rather than in this form. Adding the
        input back by hand changes nothing: TaskRequest drops the key on edit (§84).

    The project, unit and parent pickers only offer records this actor can reach, and the controller
    re-authorizes every one of them on the way back in. The form is a convenience; it is not the
    boundary.

    Expects: $task, $projects, $units, $people, $confidentialities, $priorities, $parents, $nextNumber
--}}

{{-- ---------------------------------------------------------------- identity --}}
<div class="card-header">
    <h3 class="card-title">The task</h3>
    @if ($nextNumber)
        <span class="font-mono text-xs text-[var(--text-muted)]">Next number: {{ $nextNumber }}</span>
    @endif
</div>

<div class="grid gap-5 p-5 sm:grid-cols-2">
    <x-field label="Title" name="title" required class="sm:col-span-2"
             help="What has to be done, in the words the person doing it would use.">
        <x-input name="title" :value="$task->title" required autofocus/>
    </x-field>

    <x-field label="Detail" name="description" class="sm:col-span-2">
        <x-textarea name="description" :value="$task->description" rows="4"/>
    </x-field>
</div>

{{-- ---------------------------------------------------------------- placement --}}
<div class="card-header border-t border-[var(--surface-border)]">
    <h3 class="card-title">Where it belongs</h3>
</div>

<div class="grid gap-5 p-5 sm:grid-cols-2">
    <x-field label="Project" name="project_id"
             help="Optional — plenty of real work belongs to a department and no project. Filing it here also puts it on that project's timeline.">
        <x-select name="project_id" :options="$projects" :selected="$task->project_id" placeholder="No project"/>
    </x-field>

    <x-field label="Responsible unit" name="org_unit_id"
             help="Which part of the company owns this work. People who reach that unit reach this task.">
        <x-select name="org_unit_id" :options="$units" :selected="$task->org_unit_id" placeholder="Not assigned"/>
    </x-field>

    <x-field label="Parent task" name="parent_id" class="sm:col-span-2"
             help="Makes this a subtask. A subtask must sit on the same project as its parent, and a task cannot end up inside its own subtree.">
        <x-select name="parent_id" :options="$parents" :selected="$task->parent_id" placeholder="Not a subtask"/>
    </x-field>

    <x-field label="Sensitivity" name="confidentiality" required class="sm:col-span-2"
             help="Above “Internal”, being assigned the task is not enough on its own — it has to be reachable through the owning unit, the project, or an explicit grant.">
        <x-select name="confidentiality" :options="$confidentialities"
                  :selected="$task->confidentiality?->value" required/>
    </x-field>
</div>

{{-- ---------------------------------------------------------------- assignment --}}
@if (! $task->exists)
    <div class="card-header border-t border-[var(--surface-border)]">
        <h3 class="card-title">Who does it</h3>
    </div>

    <div class="grid gap-5 p-5 sm:grid-cols-2">
        <x-field label="Assign to" name="assignee_id" class="sm:col-span-2"
                 help="Optional. Naming somebody here gives that account sight of this task, so the list only contains people your own access reaches. It can be changed later from the task page.">
            @if ($people === [])
                <p class="input flex items-center bg-[var(--surface-muted)] text-xs text-[var(--text-muted)]">
                    You cannot see login accounts, so you cannot assign this yet. Raise it and someone who
                    can will pick it up from the unassigned list.
                </p>
            @else
                <x-select name="assignee_id" :options="$people" :selected="$task->assignee_id"
                          placeholder="Leave unassigned"/>
            @endif
        </x-field>
    </div>
@endif

{{-- ---------------------------------------------------------------- schedule --}}
<div class="card-header border-t border-[var(--surface-border)]">
    <h3 class="card-title">Schedule</h3>
</div>

<div class="grid gap-5 p-5 sm:grid-cols-2">
    <x-field label="Start" name="start_on">
        <x-input name="start_on" type="date" :value="$task->start_on?->format('Y-m-d')"/>
    </x-field>

    <x-field label="Due" name="due_on">
        <x-input name="due_on" type="date" :value="$task->due_on?->format('Y-m-d')"/>
    </x-field>
</div>

{{-- ---------------------------------------------------------------- effort --}}
<div class="card-header border-t border-[var(--surface-border)]">
    <h3 class="card-title">Effort and progress</h3>
</div>

<div class="grid gap-5 p-5 sm:grid-cols-2">
    <x-field label="Priority" name="priority" required>
        <x-select name="priority" :options="$priorities" :selected="$task->priority" required/>
    </x-field>

    <x-field label="Progress" name="progress_percent" help="Percent complete, 0–100. Set to 100 for you when the task closes.">
        <x-input name="progress_percent" type="number" min="0" max="100" :value="$task->progress_percent"/>
    </x-field>

    <x-field label="Estimated hours" name="estimated_hours">
        <x-input name="estimated_hours" type="number" step="0.25" min="0" :value="$task->estimated_hours"/>
    </x-field>

    <x-field label="Actual hours" name="actual_hours">
        <x-input name="actual_hours" type="number" step="0.25" min="0" :value="$task->actual_hours"/>
    </x-field>
</div>


{{-- The fields this company added to this register (§73). Renders nothing until
     somebody defines one on Configuration → Custom fields. --}}
<x-custom-fields.form entity-type="task" :record="$task" class="card mt-5"/>
