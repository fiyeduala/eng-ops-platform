@props(['cell'])

{{--
    One cell of the training matrix.

    FOUR STATES AND FOUR COLOURS, and the choice of which is which is not decoration. "Expiring" is
    amber rather than green because a certificate with three weeks left is a booking somebody has to
    make now; "missing" and "expired" are both red because from the point of view of the person
    deciding who goes up the derrick they mean the same thing — this person may not do this job today.

    THE DATE IS SHOWN WHENEVER THERE IS ONE, including on a lapsed certificate. "Expired" alone sends
    somebody to the register to find out how long ago; "Expired 4 Mar 2026" answers it here.
--}}

@php
    $tone = match ($cell['state']) {
        'held' => 'success',
        'expiring' => 'warning',
        default => 'danger',
    };

    $label = match ($cell['state']) {
        'held' => 'Held',
        'expiring' => 'Expiring',
        'expired' => 'Expired',
        default => 'Missing',
    };

    $date = $cell['expires_on']?->format('j M Y');
@endphp

<x-badge :tone="$tone">{{ $label }}@if ($date && $cell['state'] !== 'held') {{ $date }}@endif</x-badge>
@if ($date && $cell['state'] === 'held')
    <span class="ml-1 block text-[11px] text-[var(--text-muted)]">to {{ $date }}</span>
@endif
