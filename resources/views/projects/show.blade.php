{{--
    One project.

    This page is where the two visibility axes become visible to the person using them. The team
    list below is NOT simply $project->members: it is re-filtered through ScopeCompiler, so opening
    a project does not hand you every personnel record attached to it (§61). A project manager who
    cannot see employee records sees the names of the people they manage and nothing more.

    The status buttons are drawn from StatusMachine::available(), and pressing one asks
    StatusMachine again. A move that was not offered is refused server-side (§84).
--}}
@php
    $transitionRules = $transitions->mapWithKeys(fn ($t) => [
        (string) $t->to_status_id => (bool) $t->requires_comment,
    ])->all();
@endphp

<x-layouts.app :title="$project->name">

    <x-page-header
        :title="$project->name"
        :description="$project->number.($project->client_name ? ' · '.$project->client_name : '')">
        <x-slot:actions>
            @if ($canSeeTeam)
                <a href="{{ route('projects.team', $project) }}" class="btn btn-sm btn-ghost">
                    <x-icon name="users" class="h-3.5 w-3.5"/> Team
                </a>
            @endif
            @if ($canManage)
                <a href="{{ route('projects.departments', $project) }}" class="btn btn-sm btn-ghost">
                    <x-icon name="sitemap" class="h-3.5 w-3.5"/> Departments
                </a>
            @endif
            @if ($canEdit)
                <a href="{{ route('projects.edit', $project) }}" class="btn btn-sm btn-secondary">
                    <x-icon name="pencil" class="h-3.5 w-3.5"/> Edit
                </a>
            @endif
            @if ($canArchive)
                <form method="POST"
                      action="{{ $project->archived_at ? route('projects.unarchive', $project) : route('projects.archive', $project) }}">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-ghost">
                        <x-icon name="archive" class="h-3.5 w-3.5"/>
                        {{ $project->archived_at ? 'Restore' : 'Archive' }}
                    </button>
                </form>
            @endif
            @if ($canDelete)
                <x-delete-form :action="route('projects.destroy', $project)"
                               label="Delete"
                               confirm="Delete {{ $project->name }}? This is only offered because nothing has been filed against it yet."/>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($project->archived_at)
        <div class="card mb-6 flex items-start gap-3 border-l-4 border-l-amber-400 p-4">
            <x-icon name="archive" class="mt-0.5 h-4 w-4 shrink-0 text-amber-600"/>
            <p class="text-sm text-[var(--text-body)]">
                This project was archived {{ $project->archived_at->diffForHumans() }}. Its records are intact and
                still searchable; it is out of the active register.
            </p>
        </div>
    @endif

    {{--
        The project command center (§52, docs/05 §8).

        Above the structural panels for the same reason the personal board sits above the landing
        page's cards: the panels below describe what this project IS, and change on the day somebody
        edits the record. These tiles describe how it is GOING. Each was already decided for this
        reader by WidgetComposer — a tile whose register they cannot read never arrived — and each
        figure links down to the records it counted, which is the middle step of the drill-down path.
    --}}
    @if ($tiles !== [])
        <div class="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-12">
            @foreach ($tiles as $tile)
                <x-widget :tile="$tile"/>
            @endforeach
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">

        {{-- ------------------------------------------------------------ summary --}}
        <div class="space-y-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Status</h3>
                    @if ($project->status)
                        <x-badge :tone="$project->status->color ?? 'neutral'">{{ $project->status->label }}</x-badge>
                    @endif
                </div>

                <div class="p-5">
                    <div class="flex items-center gap-3">
                        <span class="h-2 flex-1 overflow-hidden rounded-full bg-[var(--surface-muted)]">
                            <span class="block h-full rounded-full bg-brand-500"
                                  style="width: {{ max(0, min(100, (int) $project->progress_percent)) }}%"></span>
                        </span>
                        <span class="text-sm font-medium text-[var(--text-strong)]">{{ (int) $project->progress_percent }}%</span>
                    </div>

                    @php
                        $healthTone = match ($project->health) {
                            'on_track' => 'success',
                            'at_risk' => 'warning',
                            'off_track' => 'danger',
                            'on_hold' => 'neutral',
                            default => 'neutral',
                        };
                    @endphp
                    <div class="mt-4 flex flex-wrap items-center gap-2">
                        @if ($project->health)
                            <x-badge :tone="$healthTone">{{ ucwords(str_replace('_', ' ', (string) $project->health)) }}</x-badge>
                        @endif
                        <x-badge :tone="$project->priority === 'critical' ? 'danger' : ($project->priority === 'high' ? 'warning' : 'neutral')">
                            {{ ucfirst((string) $project->priority) }} priority
                        </x-badge>
                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $project->confidentiality?->badgeClasses() }}">
                            <x-icon name="lock" class="h-3 w-3"/> {{ $project->confidentiality?->label() }}
                        </span>
                    </div>

                    @if ($project->isOverdue())
                        <p class="mt-4 text-sm text-rose-600">
                            {{ abs((int) $project->daysRemaining()) }} days past the planned end date.
                        </p>
                    @endif
                </div>

                {{-- -------------------------------------------------- transitions --}}
                @if ($canEdit)
                    <div class="border-t border-[var(--surface-border)] p-5">
                        @if ($transitions->isEmpty())
                            <p class="text-sm text-[var(--text-muted)]">
                                There is nowhere for this project to move from here — either it has reached a final
                                status, or the moves that exist need a permission you do not hold.
                            </p>
                        @else
                            <form method="POST" action="{{ route('projects.status', $project) }}"
                                  x-data="{ target: '', rules: @js($transitionRules) }">
                                @csrf
                                <label for="status_id" class="label">Move this project to</label>
                                <select name="status_id" id="status_id" x-model="target" class="input" required>
                                    <option value="">Choose a status…</option>
                                    @foreach ($transitions as $transition)
                                        <option value="{{ $transition->to_status_id }}">{{ $transition->to?->label }}</option>
                                    @endforeach
                                </select>

                                <div class="mt-3" x-show="target !== ''" x-cloak>
                                    <label for="comment" class="label">
                                        Reason
                                        <span x-show="! rules[target]" class="font-normal text-[var(--text-muted)]">(optional)</span>
                                    </label>
                                    <textarea name="comment" id="comment" rows="2" class="input"
                                              :required="rules[target]"
                                              placeholder="Recorded on the timeline for whoever reads this later."></textarea>
                                </div>

                                @error('status_id')
                                    <p class="mt-2 text-sm text-rose-600">{{ $message }}</p>
                                @enderror

                                <button type="submit" class="btn btn-primary btn-sm mt-3" x-bind:disabled="target === ''">
                                    <x-icon name="check" class="h-3.5 w-3.5"/> Apply
                                </button>
                            </form>
                        @endif
                    </div>
                @endif
            </div>

            {{-- ---------------------------------------------------- particulars --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Particulars</h3>
                </div>

                <dl class="space-y-4 p-5">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Type</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">{{ $project->type?->name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Owning unit</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">
                            @if ($project->orgUnit)
                                @can ('view', $project->orgUnit)
                                    <a href="{{ route('org-units.show', $project->orgUnit) }}" class="text-brand-600 hover:underline">{{ $project->orgUnit->name }}</a>
                                @else
                                    {{ $project->orgUnit->name }}
                                @endcan
                            @else
                                —
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Location</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">{{ $project->location?->name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Manager</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">{{ $project->manager?->name ?? 'Not appointed' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Sponsor</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">{{ $project->sponsor?->name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Client</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">
                            {{ $project->client_name ?: '—' }}
                            @if ($project->contract_reference)
                                <span class="mt-0.5 block font-mono text-xs text-[var(--text-muted)]">{{ $project->contract_reference }}</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Opened by</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">
                            {{ $project->creator?->name ?? 'System' }}
                            <span class="block text-xs text-[var(--text-muted)]">{{ $project->created_at?->format('j M Y') }}</span>
                        </dd>
                    </div>
                </dl>
            </div>

            {{-- ---------------------------------------------------- commercial --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Commercial</h3>
                    @unless ($canSeeFinancials)
                        <x-badge tone="neutral"><x-icon name="lock" class="h-3 w-3"/> Restricted</x-badge>
                    @endunless
                </div>

                <div class="p-5">
                    @if ($canSeeFinancials)
                        <dl class="space-y-4">
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Contract value</dt>
                                <dd class="mt-1 text-lg font-semibold text-[var(--text-strong)]">
                                    @if ($project->contract_value !== null)
                                        {{ $project->currency ?: '' }} {{ number_format((float) $project->contract_value, 2) }}
                                    @else
                                        Not recorded
                                    @endif
                                </dd>
                            </div>
                        </dl>
                    @else
                        <p class="text-sm text-[var(--text-muted)]">
                            The contract value is not shown. Seeing it needs the
                            <span class="font-medium text-[var(--text-strong)]">project.view_financials</span> permission,
                            which is separate from being able to open the project.
                        </p>
                    @endif
                </div>
            </div>
        </div>

        {{-- ------------------------------------------------------------ detail --}}
        <div class="space-y-6 lg:col-span-2">

            {{-- ---------------------------------------------------- brief --}}
            @if ($project->description)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Brief</h3>
                    </div>
                    <div class="p-5">
                        <p class="whitespace-pre-line text-sm text-[var(--text-body)]">{{ $project->description }}</p>
                    </div>
                </div>
            @endif

            {{-- ---------------------------------------------------- schedule --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Schedule</h3>
                </div>

                <dl class="grid gap-x-6 gap-y-4 p-5 sm:grid-cols-4">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Planned start</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">{{ $project->planned_start?->format('j M Y') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Planned end</dt>
                        <dd class="mt-1 text-sm {{ $project->isOverdue() ? 'font-medium text-rose-600' : 'text-[var(--text-strong)]' }}">
                            {{ $project->planned_end?->format('j M Y') ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Actual start</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">{{ $project->actual_start?->format('j M Y') ?? 'Not started' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Actual end</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">{{ $project->actual_end?->format('j M Y') ?? '—' }}</dd>
                    </div>
                </dl>
            </div>

            {{-- ---------------------------------------------------- team --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Team</h3>
                    <div class="flex items-center gap-3">
                        <span class="text-xs text-[var(--text-muted)]">
                            {{ $members->count() }} of {{ $memberCount }} shown
                        </span>
                        @if ($canSeeTeam)
                            <a href="{{ route('projects.team', $project) }}" class="btn btn-sm btn-ghost">Manage</a>
                        @endif
                    </div>
                </div>

                @if ($members->isEmpty())
                    <x-empty-state
                        icon="users"
                        title="Nobody visible on this team"
                        :description="$memberCount > 0
                            ? 'This project has '.$memberCount.' people on it, none of whose assignment records your access reaches.'
                            : 'Nobody has been assigned yet. Until someone is, this project is visible only through the department that owns it.'"/>
                @else
                    <ul class="divide-y divide-[var(--surface-border)]">
                        @foreach ($members as $member)
                            <li class="flex items-center justify-between gap-3 px-5 py-3">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-[var(--text-strong)]">
                                        {{ $member->user?->name ?? 'Unknown account' }}
                                        @if ($member->isLead())
                                            <x-badge tone="brand" class="ml-1">Lead</x-badge>
                                        @endif
                                        @unless ($member->isCurrent())
                                            <x-badge tone="neutral" class="ml-1">Not current</x-badge>
                                        @endunless
                                    </p>
                                    <p class="truncate text-xs text-[var(--text-muted)]">
                                        {{ $member->projectRole?->name ?? 'No role recorded' }}
                                        @if ($member->orgUnit)
                                            · {{ $member->orgUnit->name }}
                                        @endif
                                    </p>
                                </div>
                                @if ($member->allocation_percent)
                                    <span class="shrink-0 text-xs text-[var(--text-muted)]">{{ $member->allocation_percent }}%</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>

                    @if ($members->count() < $memberCount)
                        <p class="border-t border-[var(--surface-border)] px-5 py-3 text-xs text-[var(--text-muted)]">
                            {{ $memberCount - $members->count() }} further team member(s) exist that your access does not
                            reach. They are counted, not hidden.
                        </p>
                    @endif
                @endif
            </div>

            {{-- ---------------------------------------------------- departments --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Departments committed</h3>
                    @if ($canManage)
                        <a href="{{ route('projects.departments', $project) }}" class="btn btn-sm btn-ghost">Manage</a>
                    @endif
                </div>

                @if ($departments->isEmpty())
                    <x-empty-state
                        icon="sitemap"
                        title="No departments assigned"
                        description="Assigning a department puts this project on its head’s list so they can commit people to it. It does not, by itself, let anyone in that department see the project."/>
                @else
                    <ul class="divide-y divide-[var(--surface-border)]">
                        @foreach ($departments as $department)
                            <li class="flex items-center justify-between gap-3 px-5 py-3">
                                {{-- The department rung of the drill-down path (§89). --}}
                                <div class="min-w-0">
                                    <a href="{{ route('projects.departments.show', [$project, $department]) }}"
                                       class="block truncate text-sm font-medium text-[var(--text-strong)] hover:text-brand-600">
                                        {{ $department->orgUnit?->name ?? 'Unknown unit' }}
                                    </a>
                                    <p class="truncate text-xs text-[var(--text-muted)]">
                                        {{ $department->lead ? 'Led by '.$department->lead->fullName() : 'No lead named' }}
                                    </p>
                                </div>
                                @unless ($department->is_active)
                                    <x-badge tone="neutral">Inactive</x-badge>
                                @endunless
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            {{-- ---------------------------------------------------- timeline --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Activity</h3>
                    <span class="text-xs text-[var(--text-muted)]">Everything filed against this project</span>
                </div>

                @if ($timeline->isEmpty())
                    <x-empty-state
                        icon="clock"
                        title="Nothing recorded yet"
                        description="As work is done on this project — status changes, people joining, documents, tasks — it appears here in order."/>
                @else
                    <ul class="p-5">
                        @foreach ($timeline as $entry)
                            <li class="relative flex gap-3 pb-5 last:pb-0">
                                @unless ($loop->last)
                                    <span class="absolute left-[5px] top-3 h-full w-px bg-[var(--surface-border)]" aria-hidden="true"></span>
                                @endunless
                                <span class="relative mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full {{ $entry->dotClasses() }}"></span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm text-[var(--text-body)]">{{ $entry->description ?? $entry->verb }}</p>
                                    @if (! empty($entry->properties['comment']))
                                        <p class="mt-1 border-l-2 border-[var(--surface-border)] pl-3 text-sm text-[var(--text-muted)]">
                                            {{ $entry->properties['comment'] }}
                                        </p>
                                    @endif
                                    <p class="mt-0.5 text-xs text-[var(--text-muted)]">
                                        {{ $entry->actorName() }} ·
                                        <time datetime="{{ $entry->created_at->toIso8601String() }}">{{ $entry->created_at->diffForHumans() }}</time>
                                    </p>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>


    {{-- What this company recorded in its own fields (§73). --}}
    <x-custom-fields.facts :record="$project" class="card mt-5"/>

</x-layouts.app>
