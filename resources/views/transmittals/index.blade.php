{{--
    The transmittal register — what left the building, when, and to whom.

    Drafts sort first and are marked as drafts, because an unissued transmittal is a list somebody is
    still assembling and must never be mistaken for a record of something sent. The access engine
    already keeps other people's drafts off this page entirely; the badge is for the person who can
    see their own.

    Expects: $transmittals, $statuses, $projects, $views, $filters, $canCreate
--}}
<x-layouts.app title="Transmittals">

    <x-page-header
        title="Transmittals"
        description="The dated record of which revisions were issued to which party, for what purpose, and whether receipt was confirmed.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('transmittals.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> New transmittal
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    {{-- ---------------------------------------------------------------- views --}}
    <div class="mb-4 flex flex-wrap gap-1">
        @foreach ($views as $key => $label)
            <a href="{{ route('transmittals.index', array_merge(request()->except(['view', 'page']), ['view' => $key])) }}"
               @class([
                   'rounded-md px-3 py-1.5 text-sm',
                   'bg-[var(--surface-muted)] font-medium text-[var(--text-strong)]' => $filters['view'] === $key,
                   'text-[var(--text-muted)] hover:text-[var(--text-body)]' => $filters['view'] !== $key,
               ])>
                {{ $label }}
            </a>
        @endforeach
    </div>

    {{-- ---------------------------------------------------------------- filters --}}
    <form method="GET" action="{{ route('transmittals.index') }}" class="card mb-5 p-4">
        <input type="hidden" name="view" value="{{ $filters['view'] }}">

        <div class="grid gap-3 sm:grid-cols-4">
            <div class="sm:col-span-2">
                <label for="q" class="label">Search</label>
                <input type="search" name="q" id="q" class="input" value="{{ $filters['q'] }}"
                       placeholder="Number, subject or addressee">
            </div>

            <div>
                <label for="project" class="label">Project</label>
                <x-select name="project" :options="$projects" :selected="$filters['project']" placeholder="Any project"/>
            </div>

            <div>
                <label for="status" class="label">Status</label>
                <x-select name="status" :options="$statuses" :selected="$filters['status']" placeholder="Any status"/>
            </div>
        </div>

        <div class="mt-3 flex items-center gap-2">
            <button type="submit" class="btn btn-secondary">
                <x-icon name="search" class="h-3.5 w-3.5"/> Search
            </button>
            @if ($filters['q'] || $filters['project'] || $filters['status'])
                <a href="{{ route('transmittals.index', ['view' => $filters['view']]) }}" class="btn btn-ghost">Clear</a>
            @endif
        </div>
    </form>

    {{-- ---------------------------------------------------------------- results --}}
    <div class="card overflow-hidden">
        @if ($transmittals->isEmpty())
            <x-empty-state
                icon="scroll"
                title="Nothing here"
                description="Either nothing has been issued that matches, or it belongs to a project you do not have access to.">
                @if ($canCreate)
                    <x-slot:actions>
                        <a href="{{ route('transmittals.create') }}" class="btn btn-primary btn-sm">Raise a transmittal</a>
                    </x-slot:actions>
                @endif
            </x-empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Number</th>
                            <th>Subject</th>
                            <th>To</th>
                            <th>Purpose</th>
                            <th>Items</th>
                            <th>Issued</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($transmittals as $transmittal)
                            <tr>
                                <td class="whitespace-nowrap">
                                    <a href="{{ route('transmittals.show', $transmittal) }}"
                                       class="font-mono text-sm font-medium text-[var(--text-strong)] hover:text-brand-600">
                                        {{ $transmittal->number ?? 'Draft' }}
                                    </a>
                                </td>

                                <td>
                                    <a href="{{ route('transmittals.show', $transmittal) }}"
                                       class="text-[var(--text-body)] hover:text-brand-600">
                                        {{ $transmittal->subject }}
                                    </a>
                                    @if ($transmittal->project)
                                        <span class="block text-xs text-[var(--text-muted)]">
                                            {{ $transmittal->project->displayName() }}
                                        </span>
                                    @endif
                                </td>

                                <td class="text-[var(--text-muted)]">
                                    {{ $transmittal->to_name }}
                                    @if ($transmittal->to_attention)
                                        <span class="block text-xs">Attn: {{ $transmittal->to_attention }}</span>
                                    @endif
                                </td>

                                <td class="text-[var(--text-muted)]">{{ $transmittal->purposeLabel() }}</td>

                                <td class="text-[var(--text-muted)]">{{ $transmittal->items_count }}</td>

                                <td class="whitespace-nowrap text-[var(--text-muted)]">
                                    @if ($transmittal->isIssued())
                                        {{ $transmittal->issued_at->format('j M Y') }}
                                    @else
                                        <span class="text-amber-600">Not issued</span>
                                    @endif
                                </td>

                                <td class="whitespace-nowrap">
                                    @if ($transmittal->isDraft())
                                        <x-badge tone="warning">Draft</x-badge>
                                    @elseif ($transmittal->isOverdue())
                                        <x-badge tone="danger">Response overdue</x-badge>
                                    @elseif ($transmittal->awaitingAcknowledgement())
                                        <x-badge tone="warning">Awaiting receipt</x-badge>
                                    @elseif ($transmittal->isAcknowledged())
                                        <x-badge tone="success">Acknowledged</x-badge>
                                    @elseif ($transmittal->status)
                                        <x-badge :tone="$transmittal->status->tone()">{{ $transmittal->status->label }}</x-badge>
                                    @else
                                        <x-badge tone="neutral">Issued</x-badge>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($transmittals->hasPages())
                <div class="border-t border-[var(--surface-border)] px-4 py-3">
                    {{ $transmittals->links() }}
                </div>
            @endif
        @endif
    </div>

</x-layouts.app>
