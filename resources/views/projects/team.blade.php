{{--
    The project team — the write side of the project visibility axis.

    Adding a row here widens what somebody else can see. That is why the picker below lists only
    people this actor may actually commit (the controller asks the policy about each candidate,
    one at a time), and why the POST asks the same question again with the resolved account. The
    picker is the courtesy; the policy call is the control (§84).

    $canManage is Gate 2 only — it decides whether an edit column is drawn at all. Whether this
    actor may change a PARTICULAR row is asked per row with @can.
--}}
<x-layouts.app :title="$project->name.' — team'">

    <x-page-header
        title="Project team"
        :description="$project->displayName()">
        <x-slot:actions>
            <a href="{{ route('projects.show', $project) }}" class="btn btn-sm btn-ghost">
                <x-icon name="chevron-left" class="h-3.5 w-3.5"/> Back to project
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-6 card flex items-start gap-3 border-l-4 border-l-sky-400 p-4">
        <x-icon name="info" class="mt-0.5 h-4 w-4 shrink-0 text-sky-600"/>
        <p class="text-sm text-[var(--text-body)]">
            Being on this team is what lets someone see this project and the records filed against it,
            wherever in the company they sit. It grants reach, never authority — what they may
            <em>do</em> to those records still comes from their roles.
        </p>
    </div>

    {{-- ---------------------------------------------------------------- add --}}
    @if ($canAssign)
        {{-- Reopened on a failed save so the errors are attached to visible fields — unless the
             failure came from one of the row editors below, which reopens itself instead. --}}
        <div class="card mb-6" x-data="{ open: {{ $errors->any() && ! old('_editing') ? 'true' : 'false' }} }">
            <div class="card-header">
                <h3 class="card-title">Add someone to the team</h3>
                <button type="button" class="btn btn-sm btn-secondary" x-on:click="open = ! open">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> <span x-text="open ? 'Close' : 'Add'"></span>
                </button>
            </div>

            <div x-show="open" x-cloak>
                @if ($candidates === [])
                    <div class="p-5">
                        <p class="text-sm text-[var(--text-muted)]">
                            There is nobody you can add. Either everyone you may commit is already on this team, or
                            committing people to a project needs authority over their department that you do not have.
                            A department head can staff the project from their own people.
                        </p>
                    </div>
                @else
                    <form method="POST" action="{{ route('projects.team.store', $project) }}">
                        @csrf

                        <div class="grid gap-5 p-5 sm:grid-cols-2">
                            <x-field label="Person" name="user_id" required>
                                <x-select name="user_id" :options="$candidates" placeholder="Choose a person…" required/>
                            </x-field>

                            <x-field label="Role on the project" name="project_role_id">
                                <x-select name="project_role_id" :options="$roles" placeholder="Not specified"/>
                            </x-field>

                            <x-field label="Representing which unit" name="org_unit_id"
                                     help="Reporting data — which department is providing this person.">
                                <x-select name="org_unit_id" :options="$units" placeholder="Not recorded"/>
                            </x-field>

                            <x-field label="Allocation" name="allocation_percent" help="Percent of their time, 0–100.">
                                <x-input name="allocation_percent" type="number" min="0" max="100"/>
                            </x-field>

                            <x-field label="From" name="starts_on"
                                     help="Before this date the assignment grants nothing.">
                                <x-input name="starts_on" type="date"/>
                            </x-field>

                            <x-field label="Until" name="ends_on"
                                     help="After this date it stops granting, with nobody having to remember to revoke it.">
                                <x-input name="ends_on" type="date"/>
                            </x-field>

                            <x-field label="Responsibilities" name="responsibilities" class="sm:col-span-2">
                                <x-textarea name="responsibilities" rows="2"/>
                            </x-field>
                        </div>

                        <div class="flex items-center justify-end gap-3 border-t border-[var(--surface-border)] px-5 py-4">
                            <button type="submit" class="btn btn-primary">
                                <x-icon name="check" class="h-3.5 w-3.5"/> Add to team
                            </button>
                        </div>
                    </form>
                @endif
            </div>
        </div>
    @endif

    {{-- ---------------------------------------------------------------- roster --}}
    <div class="card overflow-hidden">
        <div class="card-header">
            <h3 class="card-title">On this project</h3>
            <span class="text-xs text-[var(--text-muted)]">{{ $members->count() }} assignment(s) you can see</span>
        </div>

        @if ($members->isEmpty())
            <x-empty-state
                icon="users"
                title="No assignments you can see"
                description="Either nobody has been committed to this project yet, or the assignment records that exist are outside your access. Those look the same from here."/>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Person</th>
                            <th>Role</th>
                            <th>Unit</th>
                            <th>Window</th>
                            <th>Allocation</th>
                            <th>Added by</th>
                            @if ($canManage)
                                <th></th>
                            @endif
                        </tr>
                    </thead>
                    {{-- One Alpine scope for the whole roster: a <tr> cannot wrap the row below it,
                         so the open editor is tracked by id rather than by a per-row flag.

                         `_editing` reopens the right row after a failed save. Without it the page
                         would come back with the editor shut and the validation errors attached to
                         fields nobody can see — the form equivalent of failing silently. It is not
                         a data field: ProjectMemberRequest::validated() never returns it. --}}
                    <tbody x-data="{ editing: {{ old('_editing') ? (int) old('_editing') : 'null' }} }">
                        @foreach ($members as $member)
                            <tr>
                                <td>
                                    <span class="font-medium text-[var(--text-strong)]">{{ $member->user?->name ?? 'Unknown account' }}</span>
                                    @if ($member->user?->email)
                                        <span class="mt-0.5 block text-xs text-[var(--text-muted)]">{{ $member->user->email }}</span>
                                    @endif
                                </td>
                                <td class="text-[var(--text-muted)]">
                                    {{ $member->projectRole?->name ?? '—' }}
                                    @if ($member->isLead())
                                        <x-badge tone="brand" class="ml-1">Lead</x-badge>
                                    @endif
                                </td>
                                <td class="text-[var(--text-muted)]">{{ $member->orgUnit?->name ?? '—' }}</td>
                                <td class="whitespace-nowrap text-[var(--text-muted)]">
                                    {{ $member->starts_on?->format('j M Y') ?? 'Open' }}
                                    –
                                    {{ $member->ends_on?->format('j M Y') ?? 'Open' }}
                                    @unless ($member->isCurrent())
                                        <x-badge tone="neutral" class="mt-1">Grants nothing today</x-badge>
                                    @endunless
                                </td>
                                <td class="text-[var(--text-muted)]">{{ $member->allocation_percent ? $member->allocation_percent.'%' : '—' }}</td>
                                <td class="text-[var(--text-muted)]">{{ $member->addedBy?->name ?? '—' }}</td>

                                @if ($canManage)
                                    <td class="text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            @can ('update', $member)
                                                <button type="button" class="btn btn-sm btn-ghost"
                                                        x-on:click="editing = editing === {{ $member->id }} ? null : {{ $member->id }}">
                                                    <x-icon name="pencil" class="h-3.5 w-3.5"/> Edit
                                                </button>
                                            @endcan
                                            @can ('remove', $member)
                                                <x-delete-form :action="route('projects.team.destroy', [$project, $member])"
                                                               label="Remove"
                                                               confirm="Remove {{ $member->user?->name ?? 'this person' }} from the team? They will no longer be able to see this project." />
                                            @endcan
                                        </div>
                                    </td>
                                @endif
                            </tr>

                            @can ('update', $member)
                                <tr x-show="editing === {{ $member->id }}" x-cloak>
                                    <td colspan="{{ $canManage ? 7 : 6 }}" class="bg-[var(--surface-muted)]">
                                        <form method="POST" action="{{ route('projects.team.update', [$project, $member]) }}">
                                            @csrf
                                            @method('PUT')
                                            <input type="hidden" name="_editing" value="{{ $member->id }}">

                                            {{-- The labels are written out rather than passed to
                                                 x-field, because several editors render on one page
                                                 and `for=` must point at THIS row's control. The
                                                 field name stays real so errors land where they
                                                 belong. --}}
                                            <div class="grid gap-5 p-5 sm:grid-cols-3">
                                                <x-field name="project_role_id">
                                                    <label for="role-{{ $member->id }}" class="label">Role on the project</label>
                                                    <x-select name="project_role_id" id="role-{{ $member->id }}" :options="$roles"
                                                              :selected="$member->project_role_id" placeholder="Not specified"/>
                                                </x-field>

                                                <x-field name="org_unit_id">
                                                    <label for="unit-{{ $member->id }}" class="label">Representing which unit</label>
                                                    <x-select name="org_unit_id" id="unit-{{ $member->id }}" :options="$units"
                                                              :selected="$member->org_unit_id" placeholder="Not recorded"/>
                                                </x-field>

                                                <x-field name="allocation_percent">
                                                    <label for="alloc-{{ $member->id }}" class="label">Allocation</label>
                                                    <x-input name="allocation_percent" id="alloc-{{ $member->id }}" type="number"
                                                             min="0" max="100" :value="$member->allocation_percent"/>
                                                </x-field>

                                                <x-field name="starts_on">
                                                    <label for="starts-{{ $member->id }}" class="label">From</label>
                                                    <x-input name="starts_on" id="starts-{{ $member->id }}" type="date"
                                                             :value="$member->starts_on?->format('Y-m-d')"/>
                                                </x-field>

                                                <x-field name="ends_on">
                                                    <label for="ends-{{ $member->id }}" class="label">Until</label>
                                                    <x-input name="ends_on" id="ends-{{ $member->id }}" type="date"
                                                             :value="$member->ends_on?->format('Y-m-d')"/>
                                                </x-field>

                                                <label class="flex items-center gap-2 self-end pb-2 text-sm text-[var(--text-body)]">
                                                    <input type="hidden" name="is_active" value="0">
                                                    <input type="checkbox" name="is_active" value="1" @checked($member->is_active)
                                                           class="h-4 w-4 rounded border-[var(--surface-border)]">
                                                    Active
                                                </label>

                                                <x-field name="responsibilities" class="sm:col-span-3">
                                                    <label for="resp-{{ $member->id }}" class="label">Responsibilities</label>
                                                    <x-textarea name="responsibilities" id="resp-{{ $member->id }}"
                                                                :value="$member->responsibilities" rows="2"/>
                                                </x-field>
                                            </div>

                                            <div class="flex items-center justify-end gap-3 px-5 pb-5">
                                                <button type="button" class="btn btn-ghost" x-on:click="editing = null">Cancel</button>
                                                <button type="submit" class="btn btn-primary btn-sm">Save assignment</button>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            @endcan
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

</x-layouts.app>
