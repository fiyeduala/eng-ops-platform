<x-layouts.app :title="$overtime->number">
    <x-page-header :title="'Overtime — '.$overtime->employee?->fullName()" :description="$overtime->number">
        <x-slot:actions>
            @if ($myApproval)<a href="{{ route('approvals.show', $myApproval) }}" class="btn btn-primary btn-sm">Decide in My Approvals</a>@endif
            @if ($canEdit)<a href="{{ route('overtime.edit', $overtime) }}" class="btn btn-secondary btn-sm">Edit</a>@endif
        </x-slot:actions>
    </x-page-header>
    @include('finance.errors')
    @php
        $status = $overtime->statusKey();
        $decisionTitles = ['rejected' => 'Reason for rejection', 'draft' => 'Returned for changes', 'approved' => 'Approver comment'];
        $fmt = fn ($value) => $value === null ? '—' : (rtrim(rtrim((string) $value, '0'), '.') ?: '0');
    @endphp

    @if ($instance)
        <div class="mb-6"><x-workflow-progress :instance="$instance"/></div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="card p-4"><p class="text-xs text-[var(--text-muted)]">Day</p><p class="mt-1 font-semibold">{{ $overtime->work_date?->format('D j M Y') }}</p></div>
                <div class="card p-4"><p class="text-xs text-[var(--text-muted)]">Asked for</p><p class="mt-1 font-mono text-lg font-semibold">{{ $fmt($overtime->hours) }}h</p></div>
                <div class="card p-4"><p class="text-xs text-[var(--text-muted)]">Worked</p><p class="mt-1 font-mono text-lg font-semibold">{{ $fmt($overtime->worked_hours) }}{{ $overtime->worked_hours === null ? '' : 'h' }}</p></div>
            </div>
            <div class="card p-5"><h2 class="card-title">Reason</h2><p class="mt-2 whitespace-pre-line text-sm">{{ $overtime->reason }}</p></div>
            @if ($overtime->decision_reason && isset($decisionTitles[$status]))
                <div class="card p-5"><h2 class="card-title">{{ $decisionTitles[$status] }}</h2><p class="mt-2 whitespace-pre-line text-sm">{{ $overtime->decision_reason }}</p></div>
            @endif
            @if ($status === 'cancelled')
                <div class="card p-5">
                    <h2 class="card-title">Cancelled</h2>
                    <p class="mt-2 text-sm">By {{ $overtime->canceller?->name ?? 'unknown' }} on {{ $overtime->cancelled_at?->format('j M Y H:i') }}.</p>
                    @if ($overtime->cancellation_reason)<p class="mt-2 whitespace-pre-line text-sm">{{ $overtime->cancellation_reason }}</p>@endif
                </div>
            @endif
            <x-timeline :entries="$timeline" title="History" empty="Nothing else has happened to this request."/>
        </div>
        <div class="space-y-6">
            <div class="card p-5">
                <dl class="space-y-4">
                    <x-register.fact label="Status" :value="$overtime->status?->label"/>
                    <x-register.fact label="Person" :value="$overtime->employee?->fullName()"/>
                    <x-register.fact label="Unit" :value="$overtime->orgUnit?->name"/>
                    <x-register.fact label="Project" :value="$overtime->project?->name"/>
                    <x-register.fact label="Submitted" :value="$overtime->submitted_at?->format('j M Y H:i')"/>
                    <x-register.fact label="Decided" :value="$overtime->decided_at?->format('j M Y H:i')"/>
                    @if ($overtime->creator && (int) $overtime->created_by !== (int) $overtime->requester_id)
                        <x-register.fact label="Asked by" :value="$overtime->creator->name"/>
                    @endif
                </dl>
                @if ($canSubmit)
                    <form method="POST" action="{{ route('overtime.submit', $overtime) }}" class="mt-5">@csrf<button class="btn btn-primary btn-sm w-full">Submit for approval</button></form>
                @endif
                @if ($canRecordWorked)
                    <form method="POST" action="{{ route('overtime.worked', $overtime) }}" class="mt-5 space-y-2 border-t border-[var(--border)] pt-4">@csrf
                        <x-field label="Hours actually worked" name="worked_hours" :help="'Up to '.$fmt($overtime->hours).'h.'">
                            <x-input name="worked_hours" inputmode="decimal" maxlength="5" :value="$overtime->worked_hours === null ? null : $fmt($overtime->worked_hours)"/>
                        </x-field>
                        <button class="btn btn-secondary btn-sm w-full">Record hours</button>
                    </form>
                @endif
                @if ($canCancel)
                    <form method="POST" action="{{ route('overtime.cancel', $overtime) }}" class="mt-5 space-y-2 border-t border-[var(--border)] pt-4">@csrf
                        <x-textarea name="reason" rows="2" placeholder="Why is this overtime no longer needed?"></x-textarea>
                        <button class="btn btn-danger btn-sm w-full">Cancel overtime</button>
                    </form>
                @endif
            </div>
            @if ($canDelete)
                <div class="card p-5"><x-delete-form :action="route('overtime.destroy', $overtime)" label="Delete draft" confirm="Delete this draft overtime request?"/></div>
            @endif
        </div>
    </div>
</x-layouts.app>
