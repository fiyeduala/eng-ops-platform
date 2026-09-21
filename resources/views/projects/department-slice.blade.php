{{--
    One department's slice of one project (§89, docs/05 §8).

    Expects: $project, $department, $members, $registers, $canManage

    THE RUNG BETWEEN A PROJECT AND ITS RECORDS. Everything on this page was already filtered for
    this reader in the controller — the registers were counted through `visibleTo()`, and a register
    they hold no permission for is absent rather than shown empty — so there is nothing to hide
    here. A count on this page and the list underneath it are the same query.
--}}
<x-layouts.app title="{{ $department->orgUnit?->name }} · {{ $project->name }}">

    <x-page-header
        :title="$department->orgUnit?->name ?? 'Department'"
        :description="$project->name.' · '.$project->number">
        <x-slot:actions>
            <a href="{{ route('projects.show', $project) }}" class="btn btn-sm btn-ghost">
                <x-icon name="chevron-left" class="h-3.5 w-3.5"/> Project
            </a>
            @if ($canManage)
                <a href="{{ route('projects.departments', $project) }}" class="btn btn-sm btn-secondary">
                    <x-icon name="sitemap" class="h-3.5 w-3.5"/> All departments
                </a>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-6 lg:grid-cols-3">

        {{-- ------------------------------------------------------------ the commitment --}}
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">The commitment</h3>
            </div>

            <dl class="grid gap-x-6 gap-y-4 p-5">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Discipline lead</dt>
                    <dd class="mt-1 text-sm text-[var(--text-strong)]">{{ $department->lead?->fullName() ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Committed by</dt>
                    <dd class="mt-1 text-sm text-[var(--text-strong)]">{{ $department->assignedBy?->name ?? '—' }}</dd>
                </div>
                @if ($department->scope_of_work)
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Scope of work</dt>
                        <dd class="mt-1 whitespace-pre-line text-sm text-[var(--text-body)]">{{ $department->scope_of_work }}</dd>
                    </div>
                @endif
            </dl>
        </div>

        {{-- ------------------------------------------------------------ who is on it --}}
        <div class="card lg:col-span-2">
            <div class="card-header">
                <h3 class="card-title">People this department has committed</h3>
            </div>

            @if ($members->isEmpty())
                <x-empty-state
                    icon="users"
                    title="Nobody from this department is on the project yet"
                    description="A department is committed first and staffed second. Until somebody is added to the team, being in this department grants no reach into the project."/>
            @else
                <ul class="p-5">
                    @foreach ($members as $member)
                        <li class="flex items-center justify-between gap-3 border-b border-[var(--surface-border)] py-2 last:border-0">
                            <span class="min-w-0">
                                <span class="block truncate text-sm text-[var(--text-strong)]">{{ $member->user?->name ?? '—' }}</span>
                                <span class="block truncate text-xs text-[var(--text-muted)]">{{ $member->projectRole?->name ?? 'Team member' }}</span>
                            </span>
                            @unless ($member->is_active)
                                <x-badge tone="neutral">Past</x-badge>
                            @endunless
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    {{--
        The step below this one. Each card is one register, counted for this department on this
        project through the reader's own visibility, with the newest few as the sample that makes
        the count checkable. A register the reader holds no permission for is not listed at all.
    --}}
    <div class="mt-6 grid gap-4 sm:grid-cols-2">
        @forelse ($registers as $register)
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">{{ $register['label'] }}</h3>
                    <span class="text-2xl font-semibold tracking-tight text-[var(--text-strong)]">{{ number_format($register['count']) }}</span>
                </div>

                @if ($register['records']->isEmpty())
                    <p class="p-5 text-sm text-[var(--text-muted)]">
                        Nothing on this project for this department that you can see.
                    </p>
                @else
                    <ul class="p-5">
                        @foreach ($register['records'] as $record)
                            @php $href = \App\Support\RecordLinks::for($record); @endphp
                            <li class="border-b border-[var(--surface-border)] py-2 last:border-0">
                                @if ($href)
                                    <a href="{{ $href }}" class="text-sm text-[var(--text-strong)] hover:text-brand-600">
                                        {{ \App\Support\RecordPresenter::describe($record) }}
                                    </a>
                                @else
                                    <span class="text-sm text-[var(--text-strong)]">{{ \App\Support\RecordPresenter::describe($record) }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>

                    @if ($register['count'] > $register['records']->count())
                        <p class="px-5 pb-4 text-xs text-[var(--text-muted)]">
                            The newest {{ $register['records']->count() }} of {{ number_format($register['count']) }}.
                        </p>
                    @endif
                @endif
            </div>
        @empty
            <div class="sm:col-span-2">
                <x-empty-state
                    icon="empty"
                    title="No registers to show you here"
                    description="The registers this page lists are ones you hold no permission to read. Somebody with wider access sees more on this page."/>
            </div>
        @endforelse
    </div>

</x-layouts.app>
