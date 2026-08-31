{{--
    One stage of a route: who acts, what they are being asked to do, and when it applies.

    THE ASSIGNEE FIELD IS THE WHOLE POINT, and the copy on it does one job: persuade whoever is
    configuring this not to name a person. A stage names a RELATIONSHIP — "the head of the department
    that owns this record" — so that when somebody leaves, nothing breaks and nobody has to remember
    to edit fourteen routes. "A specific person" is offered because occasionally a company genuinely
    means one named individual, and it is the only choice that breaks on a resignation.

    `assignee_ref` means something different for every type — a user id, a role id, an org unit id,
    a depth, a column name — so the second field swaps shape with the first. The alternative, one
    free-text box labelled "reference", produces routes that resolve to nobody and stall a week later
    on somebody's document.

    CONDITIONS ARE DATA, never expressions. A configuration screen that evaluates what you type into
    it is a remote shell with a nice form around it. The field list comes from the module itself, so
    it cannot be widened by posting a different one.

    Expects: $workflow, $stage, $assigneeTypes, $stageActions, $quorums, $operators, $autoActions,
             $conditionFields, $rejectOptions, $references, $statuses
--}}
@php
    use App\Models\WorkflowStage;
    use App\Support\Enums\AssigneeType;

    // Everything the reference field needs to know, per assignee type, handed to Alpine as data
    // rather than rebuilt in JavaScript — the enum is the single source of truth for which types
    // need a reference and what it means.
    $assigneeMeta = [];

    foreach (AssigneeType::cases() as $case) {
        $assigneeMeta[$case->value] = [
            'needsRef' => $case->needsReference(),
            'hint' => $case->hint(),
            'list' => ($references[$case->value] ?? []) !== [] ? $case->value : null,
            'numeric' => $case === AssigneeType::ManagerChain,
            'requiresProject' => $case->requiresProject(),
        ];
    }

    $existingConditions = old('conditions', $stage->exists
        ? $stage->conditions->map(fn ($c) => [
            'field' => $c->field,
            'operator' => $c->operator,
            'value' => $c->value,
            'logic' => $c->logic ?? 'and',
        ])->values()->all()
        : []);

    $existingActions = old('auto_actions', (array) ($stage->auto_actions ?? []));
@endphp

<div class="space-y-6"
     x-data="{
        assigneeType: @js(old('assignee_type', $stage->assignee_type?->value ?? AssigneeType::Role->value)),
        assigneeRef: @js((string) old('assignee_ref', $stage->assignee_ref ?? '')),
        meta: @js($assigneeMeta),
        references: @js($references),
        quorum: @js(old('quorum', $stage->quorum ?? WorkflowStage::QUORUM_ANY)),
        conditions: @js(array_values((array) $existingConditions)),
        actions: @js(array_values((array) $existingActions)),
        get current() { return this.meta[this.assigneeType] ?? { needsRef: false, hint: '', list: null }; },
        get options() { return this.current.list ? (this.references[this.current.list] ?? {}) : {}; },
        addCondition() { this.conditions.push({ field: '', operator: '=', value: '', logic: 'and' }); },
        addAction() {
            this.actions.push({
                verb: '', status: '', title: '', message: '',
                due_in_days: null, assignee_type: '', assignee_ref: '',
            });
        },
        refListFor(type) { return (this.meta[type] || {}).list; },
        needsRefFor(type) { return ((this.meta[type] || {}).needsRef) === true; },
     }">

    {{-- ---------------------------------------------------------------- what --}}
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">What this stage is</h3>
        </div>

        <div class="space-y-5 p-5">
            <x-field label="Stage name" name="name" required
                     help="What an approver sees in their inbox. Name the check, not the step number.">
                <x-input name="name" :value="$stage->name" required maxlength="150"
                         placeholder="e.g. Discipline check"/>
            </x-field>

            <x-field label="What you are asking for" name="action_type" required
                     help="A notify-only stage does not wait — it tells people and the route moves on in the same breath. Everything else holds the record until somebody acts.">
                <x-select name="action_type" :options="$stageActions"
                          :selected="old('action_type', $stage->action_type?->value ?? 'approve')"/>
            </x-field>

            <x-field label="Instructions" name="instructions"
                     help="Shown to the approver above the decision buttons. Say what to check, not that it needs checking.">
                <x-textarea name="instructions" :value="$stage->instructions" rows="3"
                            placeholder="e.g. Confirm the drawing matches the latest survey and that clause 7 references are current."/>
            </x-field>
        </div>
    </div>

    {{-- ---------------------------------------------------------------- who --}}
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Who acts</h3>
        </div>

        <div class="space-y-5 p-5">
            <x-field label="Send it to" name="assignee_type" required>
                <x-select name="assignee_type" :options="$assigneeTypes"
                          :selected="old('assignee_type', $stage->assignee_type?->value ?? 'role')"
                          x-model="assigneeType"/>
                <p class="help" x-text="current.hint"></p>
            </x-field>

            {{-- The reference field, which changes shape with the type above. --}}
            <div x-show="current.needsRef" x-cloak>
                <label for="assignee_ref" class="label">
                    Which one <span class="text-red-500" aria-hidden="true">*</span>
                </label>

                {{-- A picker where there is a list to pick from... --}}
                <template x-if="current.list">
                    <select name="assignee_ref" id="assignee_ref" class="select" x-model="assigneeRef">
                        <option value="">Choose…</option>
                        <template x-for="(label, value) in options" :key="value">
                            <option :value="value" x-text="label"></option>
                        </template>
                    </select>
                </template>

                {{-- ...and a plain field where the reference is a depth or a column name. --}}
                <template x-if="! current.list">
                    <input type="text" name="assignee_ref" id="assignee_ref" class="input"
                           x-model="assigneeRef"
                           :placeholder="current.numeric ? 'e.g. 2' : 'e.g. reviewed_by'">
                </template>

                @error('assignee_ref')
                    <p class="error" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <p class="rounded-md bg-[var(--surface-muted)] px-3 py-2 text-xs text-[var(--text-muted)]"
               x-show="current.requiresProject" x-cloak>
                This rule needs the record to belong to a project. On a record with no project it
                resolves to nobody, and the engine stalls the approval rather than skipping the stage —
                a route that silently skips the approval nobody could be found for is worse than one
                that stops.
            </p>

            <div class="grid gap-5 sm:grid-cols-2">
                <x-field label="How many must act" name="quorum" required
                         help="Send something to three discipline leads and a company may mean any one of them, or all three. Guessing blocks a document on somebody's holiday, or lets one person wave through what three were meant to check.">
                    <x-select name="quorum" :options="$quorums"
                              :selected="old('quorum', $stage->quorum ?? 'any')" x-model="quorum"/>
                </x-field>

                <div x-show="quorum === @js(WorkflowStage::QUORUM_N_OF_M)" x-cloak>
                    <x-field label="How many" name="quorum_count"
                             help="Clamped to however many people the stage actually resolves to.">
                        <x-input name="quorum_count" type="number" min="1" max="50"
                                 :value="$stage->quorum_count"/>
                    </x-field>
                </div>
            </div>
        </div>
    </div>

    {{-- ---------------------------------------------------------------- timing and refusal --}}
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Timing and refusal</h3>
        </div>

        <div class="space-y-5 p-5">
            <x-field label="Turnaround (working hours)" name="sla_hours"
                     help="Counted in the organisation's working hours, not wall clock — a Friday afternoon submission with a 24-hour turnaround is due Monday. Leave blank for no deadline.">
                <x-input name="sla_hours" type="number" min="1" max="8760" :value="$stage->sla_hours"
                         placeholder="e.g. 48"/>
            </x-field>

            <x-field label="If it is rejected" name="on_reject" required
                     help="Returning to the author is the usual choice. Stopping the approval entirely is for a refusal that ends the matter rather than starting a revision.">
                <x-select name="on_reject" :options="$rejectOptions"
                          :selected="old('on_reject', $stage->on_reject ?? 'return_to_owner')"/>
            </x-field>

            <label class="flex items-start gap-3">
                <input type="hidden" name="allow_delegate" value="0">
                <input type="checkbox" name="allow_delegate" value="1"
                       @checked(old('allow_delegate', $stage->exists ? $stage->allow_delegate : true))
                       class="mt-0.5 h-4 w-4 rounded border-[var(--surface-border)]">
                <span class="text-sm">
                    <span class="font-medium text-[var(--text-strong)]">Approvers may hand this on</span>
                    <span class="block text-[var(--text-muted)]">
                        Delegation keeps both names on the record. Turn it off where the person asked
                        is the only person who may answer.
                    </span>
                </span>
            </label>

            <label class="flex items-start gap-3">
                <input type="hidden" name="allow_self_approval" value="0">
                <input type="checkbox" name="allow_self_approval" value="1"
                       @checked(old('allow_self_approval', $stage->allow_self_approval))
                       class="mt-0.5 h-4 w-4 rounded border-[var(--surface-border)]">
                <span class="text-sm">
                    <span class="font-medium text-[var(--text-strong)]">The submitter may approve their own record here</span>
                    <span class="block text-[var(--text-muted)]">
                        Off by default, and worth leaving off. Self-approval is the commonest control
                        failure in approval systems; making it an explicit choice means enabling it is
                        a decision somebody made and can be asked about.
                    </span>
                </span>
            </label>

            <label class="flex items-start gap-3">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1"
                       @checked(old('is_active', $stage->exists ? $stage->is_active : true))
                       class="mt-0.5 h-4 w-4 rounded border-[var(--surface-border)]">
                <span class="text-sm">
                    <span class="font-medium text-[var(--text-strong)]">This stage is in use</span>
                    <span class="block text-[var(--text-muted)]">
                        Turning it off leaves it in the route, visible, but skipped — useful while a
                        route is being reworked.
                    </span>
                </span>
            </label>
        </div>
    </div>

    {{-- ---------------------------------------------------------------- conditions --}}
    @if ($conditionFields !== [])
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Only run this stage when…</h3>
                <button type="button" class="btn btn-sm btn-ghost" @click="addCondition()">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> Add a rule
                </button>
            </div>

            <div class="p-5">
                <p class="mb-4 text-sm text-[var(--text-muted)]">
                    Leave this empty and the stage always runs. With rules, a stage whose conditions fail
                    is recorded as <em>skipped</em> — never silently omitted, because "why did this not go
                    to the MD" is a question the record has to be able to answer.
                </p>

                <template x-if="conditions.length === 0">
                    <p class="text-sm text-[var(--text-muted)]">No rules — this stage always runs.</p>
                </template>

                <div class="space-y-3">
                    <template x-for="(condition, index) in conditions" :key="index">
                        <div class="grid gap-2 sm:grid-cols-12">
                            <select class="select sm:col-span-2" x-show="index > 0"
                                    :name="`conditions[${index}][logic]`" x-model="condition.logic">
                                <option value="and">AND</option>
                                <option value="or">OR</option>
                            </select>
                            <div class="hidden sm:col-span-2 sm:block" x-show="index === 0"></div>

                            <select class="select sm:col-span-4" :name="`conditions[${index}][field]`"
                                    x-model="condition.field">
                                <option value="">Choose a field</option>
                                @foreach ($conditionFields as $field => $label)
                                    <option value="{{ $field }}">{{ $label }}</option>
                                @endforeach
                            </select>

                            <select class="select sm:col-span-2" :name="`conditions[${index}][operator]`"
                                    x-model="condition.operator">
                                @foreach ($operators as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>

                            <input type="text" class="input sm:col-span-3" :name="`conditions[${index}][value]`"
                                   x-model="condition.value" placeholder="Value">

                            <button type="button" class="btn btn-sm btn-ghost sm:col-span-1"
                                    @click="conditions.splice(index, 1)">Remove</button>
                        </div>
                    </template>
                </div>

                @error('conditions')
                    <p class="error mt-3" role="alert">{{ $message }}</p>
                @enderror
            </div>
        </div>
    @endif

    {{-- ---------------------------------------------------------------- auto-actions --}}
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">When this stage opens, also…</h3>
            <button type="button" class="btn btn-sm btn-ghost" @click="addAction()">
                <x-icon name="plus" class="h-3.5 w-3.5"/> Add an action
            </button>
        </div>

        <div class="p-5">
            <p class="mb-4 text-sm text-[var(--text-muted)]">
                This is how "NCR raised → corrective action task created → assigned → deadline" becomes
                configuration rather than code. Each action is attempted independently and a failure is
                recorded rather than thrown, because a misconfigured side effect must never stop a
                company's documents routing.
            </p>

            <template x-if="actions.length === 0">
                <p class="text-sm text-[var(--text-muted)]">Nothing happens automatically.</p>
            </template>

            <div class="space-y-4">
                <template x-for="(action, index) in actions" :key="index">
                    <div class="rounded-md border border-[var(--surface-border)] p-4">
                        <div class="flex items-start gap-3">
                            <select class="select flex-1" :name="`auto_actions[${index}][verb]`"
                                    x-model="action.verb">
                                <option value="">Choose an action</option>
                                @foreach ($autoActions as $verb => $label)
                                    <option value="{{ $verb }}">{{ $label }}</option>
                                @endforeach
                            </select>

                            <button type="button" class="btn btn-sm btn-ghost"
                                    @click="actions.splice(index, 1)">Remove</button>
                        </div>

                        {{-- Move the record to a status. --}}
                        <div class="mt-3" x-show="action.verb === 'set_status'" x-cloak>
                            <label class="label">Move it to</label>
                            @if ($statuses === [])
                                <p class="help">
                                    This module has no statuses configured, so there is nothing to move a
                                    record to. Set them up under Configuration first.
                                </p>
                            @else
                                <select class="select" :name="`auto_actions[${index}][status]`"
                                        x-model="action.status">
                                    <option value="">Choose a status</option>
                                    @foreach ($statuses as $key => $label)
                                        <option value="{{ $key }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                <p class="help">
                                    The status machine still applies. Where the two disagree — this route
                                    asking for a move the machine forbids — the machine wins and the
                                    attempt is recorded against the record.
                                </p>
                            @endif
                        </div>

                        {{-- Raise a task. --}}
                        <div class="mt-3 grid gap-3 sm:grid-cols-3" x-show="action.verb === 'create_task'" x-cloak>
                            <div class="sm:col-span-2">
                                <label class="label">Task title</label>
                                <input type="text" class="input" :name="`auto_actions[${index}][title]`"
                                       x-model="action.title"
                                       placeholder="e.g. Close out the review comments">
                            </div>
                            <div>
                                <label class="label">Due in (days)</label>
                                <input type="number" min="0" max="365" class="input"
                                       :name="`auto_actions[${index}][due_in_days]`"
                                       x-model="action.due_in_days">
                            </div>
                        </div>

                        {{-- Tell somebody. --}}
                        <div class="mt-3" x-show="action.verb === 'notify'" x-cloak>
                            <label class="label">Message</label>
                            <textarea class="textarea" rows="2" :name="`auto_actions[${index}][message]`"
                                      x-model="action.message"
                                      placeholder="Leave blank for a plain note saying the record reached this stage."></textarea>
                            <p class="help">
                                Everyone named below is still checked against the record before anything
                                is sent. A person who cannot open the record is not told it exists (§68).
                            </p>
                        </div>

                        {{-- Who, for the two verbs that need one. Same vocabulary as the stage itself. --}}
                        <div class="mt-3 grid gap-3 sm:grid-cols-2"
                             x-show="action.verb === 'create_task' || action.verb === 'notify'" x-cloak>
                            <div>
                                <label class="label">Who</label>
                                <select class="select" :name="`auto_actions[${index}][assignee_type]`"
                                        x-model="action.assignee_type">
                                    <option value="">Nobody</option>
                                    @foreach ($assigneeTypes as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div x-show="needsRefFor(action.assignee_type)" x-cloak>
                                <label class="label">Which one</label>

                                <template x-if="refListFor(action.assignee_type)">
                                    <select class="select" :name="`auto_actions[${index}][assignee_ref]`"
                                            x-model="action.assignee_ref">
                                        <option value="">Choose…</option>
                                        <template x-for="(label, value) in (references[refListFor(action.assignee_type)] || {})"
                                                  :key="value">
                                            <option :value="value" x-text="label"></option>
                                        </template>
                                    </select>
                                </template>

                                <template x-if="! refListFor(action.assignee_type)">
                                    <input type="text" class="input"
                                           :name="`auto_actions[${index}][assignee_ref]`"
                                           x-model="action.assignee_ref">
                                </template>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            @error('auto_actions')
                <p class="error mt-3" role="alert">{{ $message }}</p>
            @enderror
        </div>
    </div>
</div>
