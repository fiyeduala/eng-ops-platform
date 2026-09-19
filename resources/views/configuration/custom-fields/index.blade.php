{{--
    Every register a company may add its own questions to.

    GROUPED THE WAY THE BUSINESS IS, not the way the database is. Somebody adding a field knows
    they want it "on incidents" long before they know what an entity type is, so the path through
    this page is department → register → fields.

    Expects: $registers, $canManage
--}}
<x-layouts.app title="Custom fields">

    <x-page-header
        title="Custom fields"
        description="The things your company records that nobody else does. Pick a register and add the questions its forms should ask.">
        <x-slot:actions>
            <a href="{{ route('configuration.index') }}" class="btn btn-secondary btn-sm">
                <x-icon name="chevron-left" class="h-3.5 w-3.5"/> Configuration
            </a>
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))
        <div class="card mb-5 flex items-start gap-2 p-4 text-sm text-[var(--text-body)]">
            <x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600"/>
            <p>{{ session('status') }}</p>
        </div>
    @endif

    @unless ($canManage)
        <div class="card mb-5 flex items-start gap-2 p-4 text-sm text-[var(--text-body)]">
            <x-icon name="lock" class="mt-0.5 h-4 w-4 shrink-0 text-[var(--text-muted)]"/>
            <p>
                You can read these definitions but not change them. Editing needs the
                <span class="font-medium">custom_field.manage</span> permission.
            </p>
        </div>
    @endunless

    @php
        // Grouped here rather than in the service: the shape the screen wants is the screen's
        // business, and the service already returns the rows in catalogue order.
        $grouped = collect($registers)->groupBy('group');
    @endphp

    <div class="space-y-5">
        @foreach ($grouped as $group => $rows)
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">{{ $group }}</h2>
                </div>

                <div class="grid gap-px bg-[var(--surface-border)] sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($rows as $row)
                        <a href="{{ route('configuration.custom-fields.show', $row['key']) }}"
                           class="flex items-start justify-between gap-3 bg-[var(--surface-card)] p-4 hover:bg-[var(--surface-muted)]">
                            <span>
                                <span class="block font-medium text-[var(--text-strong)]">{{ $row['name'] }}</span>
                                <span class="block font-mono text-xs text-[var(--text-muted)]">{{ $row['key'] }}</span>
                            </span>

                            <span class="shrink-0 whitespace-nowrap text-right">
                                @if ($row['fields'] === 0)
                                    <span class="text-xs text-[var(--text-muted)]">No extra fields</span>
                                @else
                                    <x-badge tone="brand">{{ $row['active'] }} {{ Str::plural('field', $row['active']) }}</x-badge>
                                    @if ($row['fields'] > $row['active'])
                                        <span class="mt-1 block text-xs text-[var(--text-muted)]">
                                            +{{ $row['fields'] - $row['active'] }} switched off
                                        </span>
                                    @endif
                                @endif
                            </span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>

</x-layouts.app>
