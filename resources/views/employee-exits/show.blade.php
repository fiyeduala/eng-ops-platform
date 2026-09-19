<x-layouts.app :title="$exit->number">
    <x-page-header :title="'Exit — '.($exit->employee?->fullName() ?? 'unknown')" :description="$exit->number">
        <x-slot:actions>
            @if ($canEdit)<a href="{{ route('employee-exits.edit', $exit) }}" class="btn btn-secondary btn-sm">Edit</a>@endif
        </x-slot:actions>
    </x-page-header>
    @include('finance.errors')

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <div class="card p-5">
                <h2 class="card-title">Clearance</h2>
                <p class="mt-1 text-xs text-[var(--text-muted)]">Read from the asset and petty cash registers just now, and read again before anything is signed off.</p>
                @if ($blockers === [])
                    <p class="mt-3 text-sm">They hold nothing outstanding.</p>
                @else
                    <ul class="mt-3 list-disc space-y-1 pl-5 text-sm">
                        @foreach ($blockers as $blocker)<li>{{ $blocker }}</li>@endforeach
                    </ul>
                @endif
            </div>

            @if ($exit->checklist)
                <div class="card p-5">
                    <div class="flex items-center justify-between">
                        <h2 class="card-title">Leaving checklist</h2>
                        <a href="{{ route('employee-checklists.show', $exit->checklist) }}" class="btn btn-ghost btn-sm">Open</a>
                    </div>
                    <p class="mt-2 text-sm">{{ $exit->checklist->doneCount() }} of {{ $exit->checklist->items->count() }} steps done.</p>
                </div>
            @endif

            @if ($exit->notes)
                <div class="card p-5"><h2 class="card-title">Notes</h2><p class="mt-2 whitespace-pre-line text-sm">{{ $exit->notes }}</p></div>
            @endif

            {{-- What somebody said on their way out is not for everyone who can see that they left. --}}
            @if ($canViewInterview && $exit->interview_notes)
                <div class="card p-5">
                    <h2 class="card-title">Exit interview</h2>
                    <p class="mt-2 whitespace-pre-line text-sm">{{ $exit->interview_notes }}</p>
                </div>
            @endif

            <x-timeline :entries="$timeline" title="History" empty="Nothing else has happened to this exit."/>
        </div>

        <div class="space-y-6">
            <div class="card p-5">
                <dl class="space-y-4">
                    <x-register.fact label="Status" :value="$exit->status?->label"/>
                    <x-register.fact label="Person" :value="$exit->employee?->fullName()"/>
                    <x-register.fact label="Staff number" :value="$exit->employee?->employee_no"/>
                    <x-register.fact label="Reason" :value="$exit->reason"/>
                    <x-register.fact label="Notice given" :value="$exit->notified_on?->format('j M Y')"/>
                    <x-register.fact label="Last working day" :value="$exit->last_working_day?->format('j M Y')"/>
                    <x-register.fact label="Cleared" :value="$exit->cleared_at?->format('j M Y H:i')"/>
                    <x-register.fact label="Cleared by" :value="$exit->clearedBy?->name"/>
                    <x-register.fact label="Access switched off" :value="$exit->access_revoked_at?->format('j M Y H:i')"/>
                    @if ($exit->eligible_for_rehire !== null)
                        <x-register.fact label="Eligible for rehire" :value="$exit->eligible_for_rehire ? 'Yes' : 'No'"/>
                    @endif
                </dl>

                @if ($canClear && ! $exit->isCleared())
                    <form method="POST" action="{{ route('employee-exits.clear', $exit) }}" class="mt-5">@csrf
                        <button class="btn btn-secondary btn-sm w-full">Record clearance</button>
                    </form>
                @endif

                @if ($canComplete && ! $exit->isCompleted())
                    <form method="POST" action="{{ route('employee-exits.complete', $exit) }}" class="mt-3">@csrf
                        <button class="btn btn-primary btn-sm w-full" onclick="return confirm('This ends the employment and switches their sign-in off. Continue?')">Complete exit</button>
                    </form>
                @endif

                @if ($canCancel && ! $exit->isCompleted())
                    <form method="POST" action="{{ route('employee-exits.cancel', $exit) }}" class="mt-5 space-y-2 border-t border-[var(--border)] pt-4">@csrf
                        <x-textarea name="reason" rows="2" placeholder="Why is the exit no longer happening?"></x-textarea>
                        <button class="btn btn-ghost btn-sm w-full">Cancel exit</button>
                    </form>
                @endif
            </div>

            @if ($canDelete)
                <div class="card p-5"><x-delete-form :action="route('employee-exits.destroy', $exit)" label="Delete exit" confirm="Delete this exit?"/></div>
            @endif
        </div>
    </div>

    {{-- What this company recorded in its own fields (§73). --}}
    <x-custom-fields.facts :record="$exit" class="card mt-5"/>

</x-layouts.app>
