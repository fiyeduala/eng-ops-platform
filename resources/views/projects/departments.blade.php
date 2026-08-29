{{--
    Departments committed to a project — step 1 of the §13 cascade.

    Nothing on this page grants anybody sight of the project, and the wording throughout says so.
    Assigning a department puts the job on that department head's list; the head then commits
    specific people from their own subtree, and THAT is what grants reach. If attaching Engineering
    here gave all sixty engineers the project, a head would have no way to put four people on a
    restricted job, and the whole distinction between the two axes would collapse.

    The "staffed" figure is the honest measure of whether step 3 has actually happened.
--}}
<x-layouts.app :title="$project->name.' — departments'">

    <x-page-header
        title="Departments"
        :description="$project->displayName()">
        <x-slot:actions>
            <a href="{{ route('projects.show', $project) }}" class="btn btn-sm btn-ghost">
                <x-icon name="chevron-left" class="h-3.5 w-3.5"/> Back to project
            </a>
            <a href="{{ route('projects.team', $project) }}" class="btn btn-sm btn-ghost">
                <x-icon name="users" class="h-3.5 w-3.5"/> Team
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-6 card flex items-start gap-3 border-l-4 border-l-sky-400 p-4">
        <x-icon name="info" class="mt-0.5 h-4 w-4 shrink-0 text-sky-600"/>
        <p class="text-sm text-[var(--text-body)]">
            Assigning a department here does <strong>not</strong> let its people see this project. It tells the
            department head that this job is theirs to staff. Reach comes from the team list, one person at a time.
        </p>
    </div>

    {{-- ---------------------------------------------------------------- assign --}}
    @if ($canManage)
        {{-- Reopened on a failed save so the errors are attached to visible fields — unless the
             failure came from one of the row editors below, which reopens itself instead. --}}
        <div class="card mb-6" x-data="{ open: {{ $errors->any() && ! old('_editing') ? 'true' : 'false' }} }">
            <div class="card-header">
                <h3 class="card-title">Assign a department</h3>
                <button type="button" class="btn btn-sm btn-secondary" x-on:click="open = ! open">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> <span x-text="open ? 'Close' : 'Assign'"></span>
                </button>
            </div>

            <div x-show="open" x-cloak>
                @if ($units === [])
                    <div class="p-5">
                        <p class="text-sm text-[var(--text-muted)]">
                            There are no further units to assign — every unit your access reaches is already on this
                            project.
                        </p>
                    </div>
                @else
                    <form method="POST" action="{{ route('projects.departments.store', $project) }}">
                        @csrf

                        <div class="grid gap-5 p-5 sm:grid-cols-2">
                            <x-field label="Department" name="org_unit_id" required>
                                <x-select name="org_unit_id" :options="$units" placeholder="Choose a unit…" required/>
                            </x-field>

                            <x-field label="Departmental lead" name="lead_employee_id"
                                     help="Who inside that department answers for this job. Naming them here does not change their access.">
                                @if ($leads === [])
                                    <p class="input flex items-center bg-[var(--surface-muted)] text-xs text-[var(--text-muted)]">
                                        Not available to you.
                                    </p>
                                @else
                                    <x-select name="lead_employee_id" :options="$leads" placeholder="Not named"/>
                                @endif
                            </x-field>

                            <x-field label="What this department is responsible for" name="responsibilities" class="sm:col-span-2">
                                <x-textarea name="responsibilities" rows="2"/>
                            </x-field>
                        </div>

                        <div class="flex items-center justify-end gap-3 border-t border-[var(--surface-border)] px-5 py-4">
                            <button type="submit" class="btn btn-primary">
                                <x-icon name="check" class="h-3.5 w-3.5"/> Assign department
                            </button>
                        </div>
                    </form>
                @endif
            </div>
        </div>
    @endif

    {{-- ---------------------------------------------------------------- assigned --}}
    <div class="card overflow-hidden">
        <div class="card-header">
            <h3 class="card-title">Committed to this project</h3>
            <span class="text-xs text-[var(--text-muted)]">{{ $departments->count() }} department(s)</span>
        </div>

        @if ($departments->isEmpty())
            <x-empty-state
                icon="sitemap"
                title="No departments assigned"
                description="Nothing has been committed to this project yet. Until a department is assigned and staffs it, the project is visible only through the unit that owns it and to the people named on it.">
                @if ($canManage && $units !== [])
                    <x-slot:actions>
                        <span class="text-xs text-[var(--text-muted)]">Use “Assign” above to add the first one.</span>
                    </x-slot:actions>
                @endif
            </x-empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Department</th>
                            <th>Lead</th>
                            <th>People committed</th>
                            <th>Assigned by</th>
                            @if ($canManage)
                                <th></th>
                            @endif
                        </tr>
                    </thead>
                    {{-- One Alpine scope for the table: a <tr> cannot wrap the editor row below it.
                         `_editing` reopens the right row after a failed save, so the validation
                         errors are not attached to fields nobody can see. It is not a data field —
                         ProjectDepartmentRequest::validated() never returns it. --}}
                    <tbody x-data="{ editing: {{ old('_editing') ? (int) old('_editing') : 'null' }} }">
                        @foreach ($departments as $department)
                            @php $committed = (int) ($staffed[$department->org_unit_id] ?? 0); @endphp
                            <tr>
                                <td>
                                    <span class="font-medium text-[var(--text-strong)]">{{ $department->orgUnit?->name ?? 'Unknown unit' }}</span>
                                    @if ($department->responsibilities)
                                        <span class="mt-0.5 block text-xs text-[var(--text-muted)]">{{ $department->responsibilities }}</span>
                                    @endif
                                    @unless ($department->is_active)
                                        <x-badge tone="neutral" class="mt-1">Inactive</x-badge>
                                    @endunless
                                </td>
                                <td class="text-[var(--text-muted)]">{{ $department->lead?->fullName() ?? 'Not named' }}</td>
                                <td>
                                    @if ($committed > 0)
                                        <x-badge tone="success">{{ $committed }} on the team</x-badge>
                                    @else
                                        <span class="text-xs text-amber-600">Nobody committed yet</span>
                                    @endif
                                </td>
                                <td class="text-[var(--text-muted)]">{{ $department->assignedBy?->name ?? '—' }}</td>

                                @if ($canManage)
                                    <td class="text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <button type="button" class="btn btn-sm btn-ghost"
                                                    x-on:click="editing = editing === {{ $department->id }} ? null : {{ $department->id }}">
                                                <x-icon name="pencil" class="h-3.5 w-3.5"/> Edit
                                            </button>
                                            <x-delete-form :action="route('projects.departments.destroy', [$project, $department])"
                                                           label="Detach"
                                                           confirm="Detach {{ $department->orgUnit?->name ?? 'this department' }}? Anyone from it already on the team stays on the team." />
                                        </div>
                                    </td>
                                @endif
                            </tr>

                            @if ($canManage)
                                <tr x-show="editing === {{ $department->id }}" x-cloak>
                                    <td colspan="5" class="bg-[var(--surface-muted)]">
                                        <form method="POST" action="{{ route('projects.departments.update', [$project, $department]) }}">
                                            @csrf
                                            @method('PUT')
                                            <input type="hidden" name="_editing" value="{{ $department->id }}">

                                            {{-- Labels written out rather than passed to x-field:
                                                 several editors render on one page, so `for=` has
                                                 to point at THIS row's control while the field name
                                                 stays real so errors land where they belong. --}}
                                            <div class="grid gap-5 p-5 sm:grid-cols-2">
                                                <x-field name="lead_employee_id">
                                                    <label for="lead-{{ $department->id }}" class="label">Departmental lead</label>
                                                    @if ($leads === [])
                                                        <p class="input flex items-center bg-[var(--surface-muted)] text-xs text-[var(--text-muted)]">
                                                            Not available to you.
                                                        </p>
                                                    @else
                                                        <x-select name="lead_employee_id" id="lead-{{ $department->id }}" :options="$leads"
                                                                  :selected="$department->lead_employee_id" placeholder="Not named"/>
                                                    @endif
                                                </x-field>

                                                <label class="flex items-center gap-2 self-end pb-2 text-sm text-[var(--text-body)]">
                                                    <input type="hidden" name="is_active" value="0">
                                                    <input type="checkbox" name="is_active" value="1" @checked($department->is_active)
                                                           class="h-4 w-4 rounded border-[var(--surface-border)]">
                                                    Active
                                                </label>

                                                <x-field name="responsibilities" class="sm:col-span-2">
                                                    <label for="resp-{{ $department->id }}" class="label">What this department is responsible for</label>
                                                    <x-textarea name="responsibilities" id="resp-{{ $department->id }}"
                                                                :value="$department->responsibilities" rows="2"/>
                                                </x-field>
                                            </div>

                                            <div class="flex items-center justify-end gap-3 px-5 pb-5">
                                                <button type="button" class="btn btn-ghost" x-on:click="editing = null">Cancel</button>
                                                <button type="submit" class="btn btn-primary btn-sm">Save</button>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

</x-layouts.app>
