<x-layouts.app :title="$cost->number">
    <x-page-header :title="$cost->description" :description="$cost->number">
        <x-slot:actions><a href="{{ route('project-costs.index') }}" class="btn btn-ghost btn-sm">All project costs</a></x-slot:actions>
    </x-page-header>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <div class="card p-5">
                <h3 class="card-title mb-4">The cost</h3>
                <dl class="grid gap-x-8 gap-y-4 sm:grid-cols-2">
                    <x-register.fact label="Quantity" mono :value="(float) $cost->quantity.' '.$cost->uom"/>
                    <x-register.fact label="Unit price" mono :value="$cost->currency.' '.number_format((float) $cost->unit_price, 4)"/>
                    <x-register.fact label="Original amount" mono :value="$cost->currency.' '.number_format((float) $cost->amount, 2)"/>
                    <x-register.fact label="Exchange rate" mono :value="(float) $cost->exchange_rate"/>
                    <x-register.fact label="Base amount" mono :value="$cost->base_currency.' '.number_format((float) $cost->base_amount, 2)"/>
                    <x-register.fact label="Type" :value="Str::headline($cost->cost_type)"/>
                </dl>
            </div>
            <x-timeline :entries="$timeline" title="History" empty="Nothing else has been recorded against this cost."/>
        </div>
        <div class="space-y-6">
            <div class="card p-5">
                <h3 class="card-title mb-4">Attribution</h3>
                <dl class="space-y-4">
                    <x-register.fact label="Incurred" :value="$cost->incurred_at?->format('j M Y, H:i')"/>
                    <x-register.fact label="Project" :value="$cost->project?->name"/>
                    <x-register.fact label="Unit" :value="$cost->orgUnit?->name"/>
                    <x-register.fact label="Supplier" :value="$cost->vendor?->name"/>
                    <x-register.fact label="Posted by" :value="$cost->creator?->name"/>
                </dl>
            </div>
            <div class="card p-5">
                <h3 class="card-title mb-4">Source</h3>
                <p class="text-sm text-[var(--text-muted)]">This row was posted from an accepted goods-receipt line and cannot be edited or deleted.</p>
                <p class="mt-3 font-mono text-xs text-[var(--text-muted)]">{{ Str::headline($cost->source_type) }} #{{ $cost->source_id }}</p>
            </div>
        </div>
    </div>
</x-layouts.app>
