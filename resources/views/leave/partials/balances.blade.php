@php
    $trim = fn ($n) => rtrim(rtrim((string) $n, '0'), '.') ?: '0';
@endphp
@if ($balances !== [])
    <div class="mb-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($balances as $row)
            <div class="card p-4">
                <p class="text-xs text-[var(--text-muted)]">{{ $row['type']->name }} · {{ now()->year }}</p>
                <p class="mt-1 font-mono text-lg font-semibold">{{ $trim($row['left']) }} <span class="text-sm font-normal text-[var(--text-muted)]">days left</span></p>
                <p class="mt-1 text-xs text-[var(--text-muted)]">{{ $trim($row['used']) }} booked of {{ $trim($row['granted']) }}</p>
            </div>
        @endforeach
    </div>
@endif
