<x-layouts.app :title="$leave->number">
    <x-page-header :title="$leave->leaveType?->name.' — '.$leave->employee?->fullName()" :description="$leave->number">
        <x-slot:actions>
            @if ($myApproval)<a href="{{ route('approvals.show', $myApproval) }}" class="btn btn-primary btn-sm">Decide in My Approvals</a>@endif
            @if ($canEdit)<a href="{{ route('leave.edit', $leave) }}" class="btn btn-secondary btn-sm">Edit</a>@endif
        </x-slot:actions>
    </x-page-header>
    @include('finance.errors')
    @php($status = $leave->statusKey())
    @php($decisionTitles = ['rejected' => 'Reason for rejection', 'draft' => 'Returned for changes', 'approved' => 'Approver comment'])

    @if ($instance)
        <div class="mb-6"><x-workflow-progress :instance="$instance"/></div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="card p-4"><p class="text-xs text-[var(--text-muted)]">From</p><p class="mt-1 font-semibold">{{ $leave->start_date?->format('D j M Y') }}{{ $leave->start_half_day ? ' (afternoon)' : '' }}</p></div>
                <div class="card p-4"><p class="text-xs text-[var(--text-muted)]">To</p><p class="mt-1 font-semibold">{{ $leave->end_date?->format('D j M Y') }}{{ $leave->end_half_day ? ' (morning)' : '' }}</p></div>
                <div class="card p-4"><p class="text-xs text-[var(--text-muted)]">Working days</p><p class="mt-1 font-mono text-lg font-semibold">{{ rtrim(rtrim((string) $leave->days, '0'), '.') }}</p></div>
            </div>
            @if ($leave->reason)
                <div class="card p-5"><h2 class="card-title">Reason</h2><p class="mt-2 whitespace-pre-line text-sm">{{ $leave->reason }}</p></div>
            @endif
            @if ($leave->decision_reason && isset($decisionTitles[$status]))
                <div class="card p-5"><h2 class="card-title">{{ $decisionTitles[$status] }}</h2><p class="mt-2 whitespace-pre-line text-sm">{{ $leave->decision_reason }}</p></div>
            @endif
            @if ($status === 'cancelled')
                <div class="card p-5">
                    <h2 class="card-title">Cancelled</h2>
                    <p class="mt-2 text-sm">By {{ $leave->canceller?->name ?? 'unknown' }} on {{ $leave->cancelled_at?->format('j M Y H:i') }}.</p>
                    @if ($leave->cancellation_reason)<p class="mt-2 whitespace-pre-line text-sm">{{ $leave->cancellation_reason }}</p>@endif
                </div>
            @endif
            <x-timeline :entries="$timeline" title="History" empty="Nothing else has happened to this request."/>
        </div>
        <div class="space-y-6">
            <div class="card p-5">
                <dl class="space-y-4">
                    <x-register.fact label="Status" :value="$leave->status?->label"/>
                    <x-register.fact label="Person" :value="$leave->employee?->fullName()"/>
                    <x-register.fact label="Unit" :value="$leave->orgUnit?->name"/>
                    <x-register.fact label="Paid" :value="$leave->leaveType ? ($leave->leaveType->is_paid ? 'Yes' : 'No') : null"/>
                    <x-register.fact label="Handing over to" :value="$leave->handoverTo?->name"/>
                    <x-register.fact label="Contact while away" :value="$leave->contact_while_away"/>
                    <x-register.fact label="Submitted" :value="$leave->submitted_at?->format('j M Y H:i')"/>
                    <x-register.fact label="Decided" :value="$leave->decided_at?->format('j M Y H:i')"/>
                    @if ($leave->creator && (int) $leave->created_by !== (int) $leave->requester_id)
                        <x-register.fact label="Booked by" :value="$leave->creator->name"/>
                    @endif
                </dl>
                @if ($canSubmit)
                    <form method="POST" action="{{ route('leave.submit', $leave) }}" class="mt-5">@csrf<button class="btn btn-primary btn-sm w-full">Submit for approval</button></form>
                @endif
                @if ($canCancel)
                    <form method="POST" action="{{ route('leave.cancel', $leave) }}" class="mt-5 space-y-2 border-t border-[var(--border)] pt-4">@csrf
                        <x-textarea name="reason" rows="2" placeholder="Why is this leave being cancelled?"></x-textarea>
                        <button class="btn btn-danger btn-sm w-full">Cancel leave</button>
                    </form>
                @endif
            </div>
            @if ($canDelete)
                <div class="card p-5"><x-delete-form :action="route('leave.destroy', $leave)" label="Delete draft" confirm="Delete this draft leave request?"/></div>
            @endif
        </div>
    </div>
</x-layouts.app>
