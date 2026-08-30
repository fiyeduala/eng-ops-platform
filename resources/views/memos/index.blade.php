{{--
    The memo register.

    Two different things are on this screen at once and the copy has to keep them apart, because
    confusing them is how people mis-read their own access:

      - REACH decides which rows exist here at all — my drafts, the memos in units I reach, the
        memos on my projects, and the memos I am addressed on. One ScopeCompiler call, no branches.
      - ADDRESSING is the "Addressed to me" view, which is a filter on top of that. A memo I can see
        but am not on is a memo I may read, not one that was sent to me.

    Drafts are absent for everyone except their author and the people who hold `memo.issue` — that
    curtain is applyPublication() in the compiler, not a condition in the controller.

    Expects: $memos, $statuses, $projects, $views, $filters, $canCreate
--}}
<x-layouts.app title="Memos">

    <x-page-header
        title="Memos"
        description="Directed internal communications. Being on a distribution list is what gives you sight of one — and the register records who has opened it.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('memos.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> New memo
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    {{-- ---------------------------------------------------------------- filters --}}
    <form method="GET" action="{{ route('memos.index') }}" class="card mb-5 p-4">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="lg:col-span-2">
                <label for="q" class="label">Search</label>
                <x-input name="q" :value="$filters['q']" placeholder="Subject or memo number"/>
                <p class="help">Subjects and numbers only — memo bodies are never searched.</p>
            </div>

            <div>
                <label for="view" class="label">Show</label>
                <x-select name="view" :options="$views" :selected="$filters['view']"/>
            </div>

            <div>
                <label for="status" class="label">Status</label>
                <x-select name="status" :options="$statuses" :selected="$filters['status']" placeholder="Any status"/>
            </div>
        </div>

        <div class="mt-3 grid gap-3 sm:grid-cols-2">
            <div>
                <label for="project" class="label">Project</label>
                <x-select name="project" :options="$projects" :selected="$filters['project']" placeholder="Any project"/>
            </div>
        </div>

        <div class="mt-3 flex items-center gap-2">
            <button type="submit" class="btn btn-secondary">
                <x-icon name="filter" class="h-3.5 w-3.5"/> Filter
            </button>
            @if (array_filter($filters, fn ($v, $k) => $v && ! ($k === 'view' && $v === 'inbox'), ARRAY_FILTER_USE_BOTH))
                <a href="{{ route('memos.index') }}" class="btn btn-ghost">Clear</a>
            @endif
        </div>
    </form>

    {{-- ---------------------------------------------------------------- results --}}
    <div class="card overflow-hidden">
        @if ($memos->isEmpty())
            <x-empty-state
                icon="mail"
                title="No memos you can see"
                description="Either nothing matches this filter, or the memos it would match were addressed elsewhere — from here those two look the same, and deliberately so. Unissued drafts are visible only to whoever wrote them."/>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Memo</th>
                            <th>Status</th>
                            <th>From</th>
                            <th>Where</th>
                            <th>Issued</th>
                            <th>Read</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($memos as $memo)
                            <tr>
                                <td>
                                    <a href="{{ route('memos.show', $memo) }}"
                                       class="font-medium text-[var(--text-strong)] hover:text-brand-600">{{ $memo->subject }}</a>
                                    <span class="mt-0.5 block font-mono text-xs text-[var(--text-muted)]">{{ $memo->number }}</span>
                                    @if ($memo->isDraft())
                                        <x-badge tone="warning" class="mt-1">
                                            <x-icon name="pencil" class="h-3 w-3"/> Draft
                                        </x-badge>
                                    @endif
                                </td>
                                <td>
                                    @if ($memo->status)
                                        <x-badge :tone="$memo->status->color ?? 'neutral'">{{ $memo->status->label }}</x-badge>
                                    @else
                                        <span class="text-[var(--text-muted)]">—</span>
                                    @endif
                                </td>
                                <td class="text-[var(--text-muted)]">
                                    {{ $memo->issuer?->name ?? $memo->creator?->name ?? 'Unknown' }}
                                </td>
                                <td class="text-[var(--text-muted)]">
                                    {{ $memo->project?->name ?? 'No project' }}
                                    @if ($memo->orgUnit)
                                        <span class="mt-0.5 block text-xs">{{ $memo->orgUnit->name }}</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap text-[var(--text-muted)]">
                                    @if ($memo->issued_at)
                                        {{ $memo->issued_at->format('j M Y') }}
                                    @else
                                        <span class="italic">Not yet issued</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap text-[var(--text-muted)]">
                                    @if ((int) $memo->recipients_count > 0)
                                        {{ (int) $memo->read_count }} of {{ (int) $memo->recipients_count }}
                                    @else
                                        <span class="italic">Nobody addressed</span>
                                    @endif
                                </td>
                                <td class="text-right">
                                    <a href="{{ route('memos.show', $memo) }}" class="btn btn-sm btn-ghost">Open</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex flex-col gap-2 border-t border-[var(--surface-border)] px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-xs text-[var(--text-muted)]">
                    Showing {{ $memos->firstItem() }}–{{ $memos->lastItem() }} of {{ $memos->total() }} you can see.
                </p>
                @if ($memos->hasPages())
                    {{ $memos->links() }}
                @endif
            </div>
        @endif
    </div>

</x-layouts.app>
