<x-layouts.app :title="$checklist->displayName()">
    <x-page-header :title="ucfirst($checklist->kind).' — '.($checklist->employee?->fullName() ?? 'unknown')" :description="$checklist->template?->name ?? 'Ad hoc list'"/>
    @include('finance.errors')

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <div class="card p-5">
                <div class="flex items-center justify-between">
                    <h2 class="card-title">Steps</h2>
                    <span class="font-mono text-sm">{{ $checklist->doneCount() }}/{{ $checklist->items->count() }}</span>
                </div>
                <div class="mt-3 space-y-2">
                    @forelse ($items as $item)
                        <div class="rounded border border-[var(--border)] p-3">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p class="font-medium {{ $item->isDone() ? 'line-through text-[var(--text-muted)]' : '' }}">{{ $item->label }}</p>
                                    @if ($item->description)<p class="mt-1 text-xs text-[var(--text-muted)]">{{ $item->description }}</p>@endif
                                    <p class="mt-1 text-xs text-[var(--text-muted)]">
                                        @if ($item->responsibleRole) {{ $item->responsibleRole->name }} @else Anyone @endif
                                        @if ($item->isDone()) · done by {{ $item->doneBy?->name ?? 'unknown' }} on {{ $item->done_at?->format('j M Y') }} @endif
                                    </p>
                                    @if ($item->note)<p class="mt-2 whitespace-pre-line text-sm">{{ $item->note }}</p>@endif
                                </div>
                                <div class="shrink-0">
                                    @if (! $item->isDone() && $canTick)
                                        <form method="POST" action="{{ route('checklist-items.tick', $item) }}" class="flex flex-wrap items-center gap-2">@csrf
                                            @if ($item->requires_note)<x-input name="note" maxlength="200" placeholder="What was done"/>@endif
                                            <button class="btn btn-secondary btn-sm">Mark done</button>
                                        </form>
                                    @elseif ($item->isDone() && $canUntick)
                                        {{-- Reopening is not the same act as ticking: it says something recorded as
                                             done was not, so it needs the heavier permission and a reason. --}}
                                        <form method="POST" action="{{ route('checklist-items.untick', $item) }}" class="flex flex-wrap items-center gap-2">@csrf
                                            <x-input name="reason" maxlength="200" placeholder="Why reopen?"/>
                                            <button class="btn btn-ghost btn-sm">Reopen</button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-[var(--text-muted)]">This list has no steps yet.</p>
                    @endforelse
                </div>

                @if ($canTick)
                    <form method="POST" action="{{ route('employee-checklists.items.store', $checklist) }}" class="mt-4 grid gap-2 border-t border-[var(--border)] pt-4 sm:grid-cols-4">@csrf
                        <x-input name="label" maxlength="200" placeholder="Another step" class="sm:col-span-2"/>
                        <x-input name="description" maxlength="500" placeholder="Detail (optional)"/>
                        <button class="btn btn-secondary btn-sm">Add step</button>
                    </form>
                @endif
            </div>

            <x-timeline :entries="$timeline" title="History" empty="Nothing else has happened to this checklist."/>
        </div>

        <div class="space-y-6">
            <div class="card p-5">
                <dl class="space-y-4">
                    <x-register.fact label="Person" :value="$checklist->employee?->fullName()"/>
                    <x-register.fact label="Kind" :value="ucfirst($checklist->kind)"/>
                    <x-register.fact label="From list" :value="$checklist->template?->name"/>
                    <x-register.fact label="Department" :value="$checklist->orgUnit?->name"/>
                    <x-register.fact label="Started" :value="$checklist->started_on?->format('j M Y')"/>
                    <x-register.fact label="Due" :value="$checklist->due_on?->format('j M Y')"/>
                    <x-register.fact label="Completed" :value="$checklist->completed_at?->format('j M Y H:i')"/>
                    <x-register.fact label="Completed by" :value="$checklist->completer?->name"/>
                </dl>
            </div>
            @if ($canDelete)
                <div class="card p-5"><x-delete-form :action="route('employee-checklists.destroy', $checklist)" label="Delete checklist" confirm="Delete this checklist and everything recorded on it?"/></div>
            @endif
        </div>
    </div>
</x-layouts.app>
