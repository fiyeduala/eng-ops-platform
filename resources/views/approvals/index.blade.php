{{--
    My Approvals — one inbox for the whole platform.

    A person who approves documents, leave, purchases and timesheets sees ONE list. That is the
    point of the workflow engine: four modules with four pending lists is how approvals get missed.
    So nothing on this page knows what a Document is. Each row asks its subject what it is called and
    renders that.

    THE ROW IS THE STAGE, NOT THE RECORD. What is being asked of you — review, approve, sign,
    acknowledge — comes from the stage, and the verb changes with it, because "Approve" on an
    acknowledgement stage is a lie about what the button does.

    Rows whose subject the viewer may no longer open have already been dropped by the controller, so
    everything below is genuinely readable and the counts are honest.

    Expects: $rows, $views, $filters, $pendingCount
--}}
<x-layouts.app title="My approvals">

    <x-page-header
        title="My approvals"
        :description="$pendingCount === 0
            ? 'Nothing is waiting on you.'
            : $pendingCount.($pendingCount === 1 ? ' thing is' : ' things are').' waiting on you.'"/>

    {{-- ---------------------------------------------------------------- views --}}
    <div class="mb-5 flex flex-wrap items-center gap-2">
        @foreach ($views as $key => $label)
            <a href="{{ route('approvals.index', ['view' => $key]) }}"
               class="btn btn-sm {{ $filters['view'] === $key ? 'btn-secondary' : 'btn-ghost' }}">
                {{ $label }}
                @if ($key === 'pending' && $pendingCount > 0)
                    <span class="badge badge-brand ml-1">{{ $pendingCount }}</span>
                @endif
            </a>
        @endforeach
    </div>

    {{-- ---------------------------------------------------------------- rows --}}
    <div class="card overflow-hidden">
        @if ($rows->isEmpty())
            <x-empty-state
                icon="gavel"
                :title="match ($filters['view']) {
                    'overdue' => 'Nothing is overdue',
                    'decided' => 'You have not decided anything yet',
                    'started' => 'Nothing you submitted is still out',
                    default => 'Your inbox is clear',
                }"
                :description="match ($filters['view']) {
                    'overdue' => 'Everything waiting on you is still inside its agreed turnaround.',
                    'decided' => 'Decisions you record are kept here permanently — they are part of the record, not a notification that expires.',
                    'started' => 'Everything you sent for approval has been answered.',
                    default => 'Nothing is waiting on you. When somebody sends you a document, a request or a timesheet it appears here.',
                }"/>
        @else
            <ul class="divide-y divide-[var(--surface-border)]">
                @foreach ($rows as $row)
                    @php
                        $subject = $row->instance?->subject;
                        $action = $row->actionType();
                    @endphp

                    <li class="p-4 transition hover:bg-[var(--surface-muted)] sm:p-5">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <a href="{{ route('approvals.show', $row) }}"
                                       class="truncate font-medium text-[var(--text-strong)] hover:text-brand-600">
                                        {{ $subject?->displayName() ?? 'A record that no longer exists' }}
                                    </a>

                                    @if ($row->isOverdue())
                                        <x-badge tone="danger">Overdue</x-badge>
                                    @elseif ($row->isPending())
                                        <x-badge tone="warning">{{ $action->verb() }}</x-badge>
                                    @else
                                        <x-badge tone="neutral">{{ ucfirst(str_replace('_', ' ', (string) $row->status)) }}</x-badge>
                                    @endif
                                </div>

                                <p class="mt-1 text-sm text-[var(--text-muted)]">
                                    {{ $row->displayName() }}
                                    @if ($row->instance?->workflow)
                                        · {{ $row->instance->workflow->name }}
                                    @endif
                                    @if ($row->instance?->starter)
                                        · submitted by {{ $row->instance->starter->name }}
                                    @endif
                                    @if ($row->attempt > 1)
                                        · attempt {{ $row->attempt }}
                                    @endif
                                </p>

                                <p class="mt-1 text-xs text-[var(--text-muted)]">
                                    {{ $action->label() }}
                                    @if ($row->entered_at)
                                        · reached you {{ $row->entered_at->diffForHumans() }}
                                    @endif
                                </p>
                            </div>

                            <div class="flex shrink-0 items-center gap-3 sm:flex-col sm:items-end sm:gap-1">
                                @if ($row->due_at)
                                    <span class="text-xs {{ $row->isOverdue() ? 'font-medium text-rose-600' : 'text-[var(--text-muted)]' }}">
                                        Due {{ $row->due_at->format('j M Y') }}
                                    </span>
                                @else
                                    <span class="text-xs text-[var(--text-muted)]">No deadline</span>
                                @endif

                                <a href="{{ route('approvals.show', $row) }}" class="btn btn-sm btn-secondary">
                                    {{ $row->isPending() ? 'Open' : 'View' }}
                                </a>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>

            @if ($rows->hasPages())
                <div class="border-t border-[var(--surface-border)] px-4 py-3">
                    {{ $rows->links() }}
                </div>
            @endif
        @endif
    </div>

    <p class="mt-4 text-xs text-[var(--text-muted)]">
        You see a row here because you were on the assignee list when the stage opened. Moving
        department does not take a decision off you that you were legitimately given — and it does not
        give you one you were not.
    </p>

</x-layouts.app>
