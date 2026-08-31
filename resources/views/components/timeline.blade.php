@props(['entries', 'title' => 'Activity', 'empty' => null])

{{--
    The activity trail for one record.

    Extracted because Phase 3 adds five more screens that need it, and five hand-copied timelines
    drift into five different ideas of what an entry looks like. The entries themselves are
    immutable and come from the audit engine (§59) — nothing here can edit or hide one.
--}}

<div class="card">
    <div class="card-header">
        <h3 class="card-title">{{ $title }}</h3>
    </div>

    @if ($entries->isEmpty())
        <x-empty-state
            icon="clock"
            title="Nothing recorded yet"
            :description="$empty ?? 'Everything that happens to this record appears here, in order, and cannot be edited afterwards.'"/>
    @else
        <ul class="p-5">
            @foreach ($entries as $entry)
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
