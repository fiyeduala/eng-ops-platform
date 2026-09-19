<x-layouts.app :title="$budget->number">
    <x-page-header :title="$budget->title" :description="$budget->number"><x-slot:actions>@if($canEdit)<a href="{{ route('budgets.edit', $budget) }}" class="btn btn-secondary btn-sm">Edit</a>@endif</x-slot:actions></x-page-header>
    @php($remaining = (float) $budget->total_amount - $actual)
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="card p-4"><p class="text-xs text-[var(--text-muted)]">Allocated</p><p class="mt-1 font-mono text-xl font-semibold">{{ $budget->currency }} {{ number_format((float)$budget->total_amount, 2) }}</p></div>
                <div class="card p-4"><p class="text-xs text-[var(--text-muted)]">Actual cost</p><p class="mt-1 font-mono text-xl font-semibold">{{ $budget->currency }} {{ number_format($actual, 2) }}</p></div>
                <div class="card p-4"><p class="text-xs text-[var(--text-muted)]">Remaining</p><p class="mt-1 font-mono text-xl font-semibold {{ $remaining < 0 ? 'text-rose-600' : '' }}">{{ $budget->currency }} {{ number_format($remaining, 2) }}</p></div>
            </div>
            <div class="card overflow-hidden"><div class="card-header"><h2 class="card-title">Lines</h2></div><table class="table"><thead><tr><th>Category</th><th>Description</th><th class="text-right">Allocated</th><th class="text-right">Actual</th></tr></thead><tbody>@foreach($budget->items as $line)<tr><td>{{ Str::headline($line->category) }}</td><td>{{ $line->description }}</td><td class="text-right font-mono">{{ number_format((float)$line->allocated_amount, 2) }}</td><td class="text-right font-mono">{{ number_format((float)($actualByCategory[$line->category] ?? 0), 2) }}</td></tr>@endforeach</tbody></table></div>
            <x-timeline :entries="$timeline" title="History" empty="Nothing else has happened to this budget."/>
        </div>
        <div class="space-y-6"><div class="card p-5"><dl class="space-y-4"><x-register.fact label="Status" :value="$budget->status?->label"/><x-register.fact label="Period" :value="$budget->starts_on?->format('j M Y').' – '.$budget->ends_on?->format('j M Y')"/><x-register.fact label="Cost center" :value="$budget->costCenter?->displayName()"/><x-register.fact label="Project" :value="$budget->project?->name"/><x-register.fact label="Unit" :value="$budget->orgUnit?->name"/><x-register.fact label="Owner" :value="$budget->owner?->name"/></dl>
                @if($budget->statusKey()==='draft' && $canEdit)<form method="POST" action="{{ route('budgets.submit',$budget) }}" class="mt-5">@csrf<button class="btn btn-primary btn-sm w-full">Submit for approval</button></form>@endif
                @if($budget->statusKey()==='submitted' && $canApprove)<div class="mt-5 space-y-2"><form method="POST" action="{{ route('budgets.approve',$budget) }}">@csrf<button class="btn btn-primary btn-sm w-full">Approve budget</button></form><form method="POST" action="{{ route('budgets.return',$budget) }}">@csrf<button class="btn btn-secondary btn-sm w-full">Return for amendment</button></form><form method="POST" action="{{ route('budgets.reject',$budget) }}">@csrf<button class="btn btn-danger btn-sm w-full">Reject budget</button></form></div>@endif
                @if($budget->statusKey()==='approved' && auth()->user()->can('manage',$budget))<form method="POST" action="{{ route('budgets.close',$budget) }}" class="mt-5">@csrf<button class="btn btn-secondary btn-sm w-full">Close budget</button></form>@endif
            </div>@if($canDelete)<div class="card p-5"><x-delete-form :action="route('budgets.destroy',$budget)" label="Delete draft budget" confirm="Delete this draft budget?"/></div>@endif</div>
    </div>

    {{-- What this company recorded in its own fields (§73). --}}
    <x-custom-fields.facts :record="$budget" class="card mt-5"/>

</x-layouts.app>
