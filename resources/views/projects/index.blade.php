{{--
    The project register.

    Every row arrived through ScopeCompiler, which is why two people with identical job titles can
    open this page and see different lists — one reaches projects through her department, the other
    through the two he was seconded to. Neither case is written down in the controller.

    $canSeeFinancials decides whether the value COLUMN exists at all; whether a particular row's
    figure is shown is asked per row, because view_financials can be held at a narrower scope than
    view (§6, §84).
--}}
<x-layouts.app title="Projects">

    <x-page-header
        title="Projects"
        description="Jobs your access reaches — through your department, or through a team you are on.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('projects.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> New project
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    {{-- ---------------------------------------------------------------- filters --}}
    <form method="GET" action="{{ route('projects.index') }}" class="card mb-5 p-4">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <div class="lg:col-span-2">
                <label for="q" class="label">Search</label>
                <x-input name="q" :value="$filters['q']" placeholder="Name, number, code or client"/>
            </div>

            <div>
                <label for="view" class="label">Show</label>
                <x-select name="view" :options="$views" :selected="$filters['view']"/>
            </div>

            <div>
                <label for="status" class="label">Status</label>
                <x-select name="status" :options="$statuses" :selected="$filters['status']" placeholder="Any status"/>
            </div>

            <div>
                <label for="type" class="label">Type</label>
                <x-select name="type" :options="$types" :selected="$filters['type']" placeholder="Any type"/>
            </div>
        </div>

        <div class="mt-3 grid gap-3 sm:grid-cols-2">
            <div>
                <label for="unit" class="label">Owning unit</label>
                <x-select name="unit" :options="$units" :selected="$filters['unit']" placeholder="Anywhere"/>
            </div>
        </div>

        <div class="mt-3 flex items-center gap-2">
            <button type="submit" class="btn btn-secondary">
                <x-icon name="filter" class="h-3.5 w-3.5"/> Filter
            </button>
            @if (array_filter($filters, fn ($v, $k) => $v && ! ($k === 'view' && $v === 'active'), ARRAY_FILTER_USE_BOTH))
                <a href="{{ route('projects.index') }}" class="btn btn-ghost">Clear</a>
            @endif
        </div>
    </form>

    {{-- ---------------------------------------------------------------- results --}}
    <div class="card overflow-hidden">
        @if ($projects->isEmpty())
            <x-empty-state
                icon="briefcase"
                title="No projects you can see"
                description="Either nothing matches this filter, or the projects it would match sit outside your access. Those two look identical from here, and deliberately so — if you expected a job to be listed, ask to be added to its team rather than assuming it does not exist.">
                @if ($canCreate)
                    <x-slot:actions>
                        <a href="{{ route('projects.create') }}" class="btn btn-primary btn-sm">
                            <x-icon name="plus" class="h-3.5 w-3.5"/> Start a project
                        </a>
                    </x-slot:actions>
                @endif
            </x-empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Project</th>
                            <th>Status</th>
                            <th>Owner</th>
                            <th>Manager</th>
                            <th>Planned end</th>
                            <th>Progress</th>
                            @if ($canSeeFinancials)
                                <th class="text-right">Value</th>
                            @endif
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($projects as $project)
                            <tr>
                                <td>
                                    <a href="{{ route('projects.show', $project) }}"
                                       class="font-medium text-[var(--text-strong)] hover:text-brand-600">{{ $project->name }}</a>
                                    <span class="mt-0.5 flex flex-wrap items-center gap-x-2 font-mono text-xs text-[var(--text-muted)]">
                                        {{ $project->number }}
                                        @if ($project->code)
                                            <span class="font-sans">· {{ $project->code }}</span>
                                        @endif
                                        @if ($project->type)
                                            <span class="font-sans">· {{ $project->type->name }}</span>
                                        @endif
                                    </span>
                                    @if ($project->archived_at)
                                        <x-badge tone="neutral" class="mt-1">
                                            <x-icon name="archive" class="h-3 w-3"/> Archived
                                        </x-badge>
                                    @endif
                                </td>
                                <td>
                                    @if ($project->status)
                                        <x-badge :tone="$project->status->color ?? 'neutral'">{{ $project->status->label }}</x-badge>
                                    @else
                                        <span class="text-[var(--text-muted)]">—</span>
                                    @endif
                                </td>
                                <td class="text-[var(--text-muted)]">{{ $project->orgUnit?->name ?? '—' }}</td>
                                <td class="text-[var(--text-muted)]">
                                    {{ $project->manager?->name ?? '—' }}
                                    <span class="mt-0.5 block text-xs">{{ $project->members_count }} on the team</span>
                                </td>
                                <td class="whitespace-nowrap">
                                    @if ($project->planned_end)
                                        <span class="{{ $project->isOverdue() ? 'font-medium text-rose-600' : 'text-[var(--text-muted)]' }}">
                                            {{ $project->planned_end->format('j M Y') }}
                                        </span>
                                        @if ($project->isOverdue())
                                            <span class="mt-0.5 block text-xs text-rose-600">
                                                {{ abs((int) $project->daysRemaining()) }} days past
                                            </span>
                                        @endif
                                    @else
                                        <span class="text-[var(--text-muted)]">No date set</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="flex items-center gap-2">
                                        <span class="h-1.5 w-16 overflow-hidden rounded-full bg-[var(--surface-muted)]">
                                            <span class="block h-full rounded-full bg-brand-500"
                                                  style="width: {{ max(0, min(100, (int) $project->progress_percent)) }}%"></span>
                                        </span>
                                        <span class="text-xs text-[var(--text-muted)]">{{ (int) $project->progress_percent }}%</span>
                                    </div>
                                    @if ($project->health && $project->health !== 'on_track')
                                        @php
                                            $healthTone = match ($project->health) {
                                                'at_risk' => 'warning',
                                                'off_track' => 'danger',
                                                default => 'neutral',
                                            };
                                        @endphp
                                        <x-badge :tone="$healthTone" class="mt-1">
                                            {{ ucwords(str_replace('_', ' ', (string) $project->health)) }}
                                        </x-badge>
                                    @endif
                                </td>
                                @if ($canSeeFinancials)
                                    <td class="whitespace-nowrap text-right text-[var(--text-muted)]">
                                        @can ('viewFinancials', $project)
                                            @if ($project->contract_value !== null)
                                                {{ $project->currency ?: '' }} {{ number_format((float) $project->contract_value, 2) }}
                                            @else
                                                —
                                            @endif
                                        @else
                                            <x-icon name="lock" class="ml-auto h-3.5 w-3.5"/>
                                        @endcan
                                    </td>
                                @endif
                                <td class="text-right">
                                    <a href="{{ route('projects.show', $project) }}" class="btn btn-sm btn-ghost">Open</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex flex-col gap-2 border-t border-[var(--surface-border)] px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-xs text-[var(--text-muted)]">
                    Showing {{ $projects->firstItem() }}–{{ $projects->lastItem() }} of {{ $projects->total() }} you can see.
                </p>
                @if ($projects->hasPages())
                    {{ $projects->links() }}
                @endif
            </div>
        @endif
    </div>

</x-layouts.app>
