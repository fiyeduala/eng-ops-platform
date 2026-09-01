{{--
    The party, as a name and — when it is linked and the reader may open it — as a link.

    THE NAME IS ALWAYS PRINTED, THE LINK IS NOT ALWAYS OFFERED. Two separate questions: whether the
    record points at a register row, and whether THIS reader may open that row. A document controller
    who cannot see the client register still has to read who the query went to, so the name renders
    regardless; the anchor appears only for somebody the client policy would let through anyway (§6).

    Asking the policy per record rather than the permission per page is right here because there is
    exactly one party to ask about — the objection to per-row checks is thirty of them in a loop.

    Expects: $party (Model|null), $name (string|null). Optional: $fallback.
--}}
@php
    $href = null;

    if ($party !== null && auth()->user()?->can('view', $party)) {
        $href = match (true) {
            $party instanceof \App\Models\Client => route('clients.show', $party),
            $party instanceof \App\Models\Vendor => route('vendors.show', $party),
            $party instanceof \App\Models\OrgUnit => route('org-units.show', $party),
            default => null,
        };
    }

    // The typed name wins over the register's current name: it is what was written on the paper.
    $label = filled($name) ? $name : ($party?->name ?? ($fallback ?? 'Not recorded'));
@endphp

@if ($href !== null)
    <a href="{{ $href }}" class="link">{{ $label }}</a>
    @if (filled($name) && $party->name !== $name)
        <span class="text-xs text-slate-500 dark:text-slate-400">(now {{ $party->name }})</span>
    @endif
@else
    {{ $label }}
@endif
