@props(['instance', 'compact' => false])

{{--
    Where a record has got to in its approval, and who is holding it up.

    Read from the INSTANCE, never from the definition. A route can be edited or superseded while
    records are still travelling it, so the stage names, the SLA and the assignee list shown here are
    the frozen copies taken when each stage opened — which is also the answer to "why does this
    document show a stage the workflow no longer has" (§59).

    Names of assignees are deliberately not resolved into this component. The frozen list is a list
    of ids; rendering the people requires a query per stage, and the page that genuinely needs it
    (the approvals inbox) loads them itself.

    Expects: $instance — a WorkflowInstance with stageInstances.stage and stageInstances.approvals.user
--}}

@php
    $stages = $instance->stageInstances->sortBy([['attempt', 'asc'], ['sequence', 'asc']]);
    $latestAttempt = (int) ($instance->attempt ?? 1);

    $tone = match ($instance->status) {
        \App\Models\WorkflowInstance::STATUS_COMPLETED => 'success',
        \App\Models\WorkflowInstance::STATUS_REJECTED => 'danger',
        \App\Models\WorkflowInstance::STATUS_REVISION_REQUESTED => 'warning',
        \App\Models\WorkflowInstance::STATUS_STALLED => 'danger',
        \App\Models\WorkflowInstance::STATUS_RUNNING => 'brand',
        default => 'neutral',
    };
@endphp

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Approval</h3>
        <x-badge :tone="$tone">{{ $instance->statusLabel() }}</x-badge>
    </div>

    <div class="px-5 py-4 text-sm text-[var(--text-muted)]">
        {{ $instance->workflow?->name ?? 'Route' }}
        @if ($instance->workflow?->version)
            <span class="font-mono text-xs">v{{ $instance->workflow->version }}</span>
        @endif
        @if ($latestAttempt > 1)
            · attempt {{ $latestAttempt }}
        @endif
        @if ($instance->started_at)
            · started {{ $instance->started_at->format('j M Y') }}
        @endif
    </div>

    @if ($instance->outcome_reason)
        <p class="mx-5 mb-4 border-l-2 border-[var(--surface-border)] pl-3 text-sm text-[var(--text-muted)]">
            {{ $instance->outcome_reason }}
        </p>
    @endif

    <ol class="border-t border-[var(--surface-border)] p-5">
        @foreach ($stages as $stageInstance)
            @php
                $isPast = $stageInstance->attempt < $latestAttempt;

                $dot = match ($stageInstance->status) {
                    \App\Models\WorkflowStageInstance::STATUS_APPROVED => 'bg-emerald-500',
                    \App\Models\WorkflowStageInstance::STATUS_REJECTED => 'bg-rose-500',
                    \App\Models\WorkflowStageInstance::STATUS_REVISION_REQUESTED => 'bg-amber-500',
                    \App\Models\WorkflowStageInstance::STATUS_PENDING => $stageInstance->isOverdue() ? 'bg-rose-500' : 'bg-brand-500',
                    default => 'bg-[var(--surface-border)]',
                };
            @endphp

            <li class="relative flex gap-3 pb-5 last:pb-0 {{ $isPast ? 'opacity-60' : '' }}">
                @unless ($loop->last)
                    <span class="absolute left-[5px] top-3 h-full w-px bg-[var(--surface-border)]" aria-hidden="true"></span>
                @endunless

                <span class="relative mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full {{ $dot }}"></span>

                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-sm font-medium text-[var(--text-strong)]">{{ $stageInstance->displayName() }}</p>

                        @if ($stageInstance->isPending() && $stageInstance->isOverdue())
                            <x-badge tone="danger">
                                <x-icon name="alert" class="h-3 w-3"/> Overdue
                            </x-badge>
                        @elseif ($stageInstance->isPending())
                            <x-badge tone="brand">Waiting</x-badge>
                        @endif

                        @if ($isPast)
                            <span class="text-xs text-[var(--text-muted)]">attempt {{ $stageInstance->attempt }}</span>
                        @endif
                    </div>

                    <p class="mt-0.5 text-xs text-[var(--text-muted)]">
                        @if ($stageInstance->isPending())
                            With {{ count($stageInstance->assigneeIds()) }}
                            {{ \Illuminate\Support\Str::plural('person', count($stageInstance->assigneeIds())) }}
                            @if ($stageInstance->due_at)
                                · due {{ $stageInstance->due_at->format('j M Y, H:i') }}
                            @else
                                · no time limit set
                            @endif
                        @elseif ($stageInstance->acted_at)
                            {{ ucfirst(str_replace('_', ' ', (string) $stageInstance->status)) }}
                            {{ $stageInstance->acted_at->diffForHumans() }}
                        @else
                            {{ ucfirst(str_replace('_', ' ', (string) $stageInstance->status)) }}
                        @endif
                    </p>

                    @if ($stageInstance->resolution_note)
                        <p class="mt-1 text-xs italic text-[var(--text-muted)]">{{ $stageInstance->resolution_note }}</p>
                    @endif

                    @unless ($compact)
                        @foreach ($stageInstance->approvals as $approval)
                            <div class="mt-2 rounded-md bg-[var(--surface-muted)] px-3 py-2">
                                <p class="text-xs font-medium text-[var(--text-strong)]">
                                    {{ $approval->user?->name ?? 'Someone' }} —
                                    {{ $approval->resolvedDecision()->label() }}
                                    @if ($approval->acted_at)
                                        <span class="font-normal text-[var(--text-muted)]">
                                            · {{ $approval->acted_at->format('j M Y, H:i') }}
                                        </span>
                                    @endif
                                </p>
                                @if ($approval->comment)
                                    <p class="mt-1 text-xs text-[var(--text-muted)]">{{ $approval->comment }}</p>
                                @endif
                            </div>
                        @endforeach
                    @endunless
                </div>
            </li>
        @endforeach
    </ol>
</div>
