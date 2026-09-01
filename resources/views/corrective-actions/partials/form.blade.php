{{--
    One corrective action.

    THE DESCRIPTION IS REQUIRED AND THE FORM SAYS WHY. "Fix the scaffold" cannot be verified by
    anybody, and an action nobody can verify is one that gets marked done and changes nothing. The
    field asks what good looks like rather than what the problem was.

    THERE IS NO STATUS FIELD AND NO EFFECTIVENESS FIELD. Completing is the assignee's act, verifying
    is somebody else's, and both happen on the action's own page against their own permissions —
    putting either on this form would let one person do the whole loop with a text box (§84).

    THE SOURCE IS NOT EDITABLE EITHER. An action raised from an incident belongs to that incident for
    good; re-pointing it at a different one would rewrite the history of both.

    Expects: $action, $projects, $units, $people, $confidentialities, $types, $priorities
--}}
<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">The action</h3>
            </div>
            <div class="space-y-5 p-5">
                <x-field label="What needs doing" name="title" required>
                    <x-input name="title" :value="old('title', $action->title)" required/>
                </x-field>

                <x-field label="What good looks like" name="description" required
                         help="Written so that somebody who was not there can tell whether it has been done.">
                    <x-textarea name="description" rows="5" required>{{ old('description', $action->description) }}</x-textarea>
                </x-field>

                <div class="grid gap-4 sm:grid-cols-3">
                    <x-field label="Type" name="action_type">
                        <x-select name="action_type" :options="$types"
                                  :selected="old('action_type', $action->action_type)"
                                  placeholder="Choose one"/>
                    </x-field>

                    <x-field label="Priority" name="priority">
                        <x-select name="priority" :options="$priorities"
                                  :selected="old('priority', $action->priority)"
                                  placeholder="Choose one"/>
                    </x-field>

                    <x-field label="Due by" name="due_on"
                             help="Backdating is allowed — the date agreed at the meeting is the true one.">
                        <x-input type="date" name="due_on"
                                 :value="old('due_on', $action->due_on?->format('Y-m-d'))"/>
                    </x-field>
                </div>

                <x-field label="Assigned to" name="assigned_to"
                         help="They are notified straight away. An action nobody owns is a wish.">
                    <x-select name="assigned_to" :options="$people"
                              :selected="old('assigned_to', $action->assigned_to)"
                              placeholder="Assign later"/>
                </x-field>
            </div>
        </div>
    </div>

    <div class="space-y-6">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Where it sits</h3>
            </div>
            <div class="space-y-5 p-5">
                <x-field label="Project" name="project_id">
                    <x-select name="project_id" :options="$projects"
                              :selected="old('project_id', $action->project_id)"
                              placeholder="No project"/>
                </x-field>

                <x-field label="Discipline" name="discipline_id">
                    <x-select name="discipline_id" :options="$units"
                              :selected="old('discipline_id', $action->discipline_id)"
                              placeholder="No discipline"/>
                </x-field>

                <x-field label="Department" name="org_unit_id">
                    <x-select name="org_unit_id" :options="$units"
                              :selected="old('org_unit_id', $action->org_unit_id)"
                              placeholder="No department"/>
                </x-field>

                <x-field label="Who can read it" name="confidentiality" required
                         help="Usually internal. The person who has to do the work needs to be able to open it.">
                    <x-select name="confidentiality" :options="$confidentialities"
                              :selected="old('confidentiality', $action->confidentiality?->value)"
                              required/>
                </x-field>
            </div>
        </div>
    </div>
</div>
