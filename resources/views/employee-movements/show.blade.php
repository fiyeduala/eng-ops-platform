<x-layouts.app :title="$movement->number">
    <x-page-header :title="$movement->kindLabel().' — '.($movement->employee?->fullName() ?? 'unknown')" :description="$movement->number">
        <x-slot:actions>
            @if ($canEdit)<a href="{{ route('employee-movements.edit', $movement) }}" class="btn btn-secondary btn-sm">Edit</a>@endif
        </x-slot:actions>
    </x-page-header>
    @include('finance.errors')

    @if ($instance)
        <div class="mb-6"><x-workflow-progress :instance="$instance"/></div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <div class="card p-5">
                <h2 class="card-title">What changes</h2>
                <p class="mt-1 text-xs text-[var(--text-muted)]">
                    The left-hand side was read off the personnel record when this was raised, so it is what was true then.
                </p>
                @php $changes = $movement->changeSummary(); @endphp
                @if ($changes === [])
                    <p class="mt-3 text-sm text-[var(--text-muted)]">Nothing is recorded as changing. A movement with no change applies nothing.</p>
                @else
                    <ul class="mt-3 list-disc space-y-1 pl-5 text-sm">
                        @foreach ($changes as $change)<li>{{ $change }}</li>@endforeach
                    </ul>
                @endif
            </div>

            <div class="card p-5">
                <h2 class="card-title">Reason</h2>
                <p class="mt-2 whitespace-pre-line text-sm">{{ $movement->reason }}</p>
            </div>

            @if ($movement->notes)
                <div class="card p-5"><h2 class="card-title">Notes</h2><p class="mt-2 whitespace-pre-line text-sm">{{ $movement->notes }}</p></div>
            @endif

            <x-timeline :entries="$timeline" title="History" empty="Nothing else has happened to this movement."/>
        </div>

        <div class="space-y-6">
            <div class="card p-5">
                <dl class="space-y-4">
                    <x-register.fact label="Status" :value="$movement->status?->label"/>
                    <x-register.fact label="Person" :value="$movement->employee?->fullName()"/>
                    <x-register.fact label="Staff number" :value="$movement->employee?->employee_no"/>
                    <x-register.fact label="Kind" :value="$movement->kindLabel()"/>
                    <x-register.fact label="Effective on" :value="$movement->effective_on?->format('j M Y')"/>
                    <x-register.fact label="Ends on" :value="$movement->ends_on?->format('j M Y')"/>
                    <x-register.fact label="Raised by" :value="$movement->requester?->name"/>
                    <x-register.fact label="Applied on" :value="$movement->applied_at?->format('j M Y H:i')"/>
                    <x-register.fact label="Applied by" :value="$movement->appliedBy?->name ?? ($movement->isApplied() ? 'The nightly run' : null)"/>
                </dl>
            </div>

            @if ($canSubmit)
                <form method="POST" action="{{ route('employee-movements.submit', $movement) }}" class="card space-y-2 p-5">@csrf
                    <h2 class="card-title">Send for approval</h2>
                    <p class="text-xs text-[var(--text-muted)]">Goes to the approver the workflow names. Nothing on the personnel record moves until it comes back approved.</p>
                    <button class="btn btn-primary btn-sm w-full">Submit</button>
                </form>
            @endif

            {{--
                THIS BUTTON IS NOT THE APPROVAL. Approval happens in the workflow, on the approvals
                screen, like every other decision in the platform. This writes the agreed change onto
                the personnel record, and it is offered only once the movement is approved and the
                effective date has arrived. The nightly run presses it for anybody who does not.
            --}}
            @if ($canApply && $movement->isApproved() && ! $movement->isApplied())
                <form method="POST" action="{{ route('employee-movements.apply', $movement) }}" class="card space-y-2 p-5">@csrf
                    <h2 class="card-title">Apply to the record</h2>
                    @if ($movement->isDueToApply())
                        <p class="text-xs text-[var(--text-muted)]">This is due. Applying it rewrites the personnel record now; otherwise the nightly run does it tonight.</p>
                    @else
                        <p class="text-xs text-[var(--text-muted)]">Not due until {{ $movement->effective_on?->format('j M Y') }}. Applying it early makes the change today.</p>
                    @endif
                    <button class="btn btn-primary btn-sm w-full"
                            onclick="return confirm('This rewrites the personnel record. Continue?')">Apply now</button>
                </form>
            @endif

            @if ($canCancel)
                <form method="POST" action="{{ route('employee-movements.cancel', $movement) }}" class="card space-y-2 p-5">@csrf
                    <x-textarea name="reason" rows="2" placeholder="Why is this movement not going ahead?"></x-textarea>
                    <button class="btn btn-ghost btn-sm w-full">Cancel movement</button>
                </form>
            @endif

            @if ($canDelete)
                <div class="card p-5">
                    <x-delete-form :action="route('employee-movements.destroy', $movement)" label="Delete movement" confirm="Delete this movement?"/>
                </div>
            @endif
        </div>
    </div>
</x-layouts.app>
