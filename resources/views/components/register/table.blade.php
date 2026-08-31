@props([
    'records',
    'icon' => 'empty',
    'title' => 'Nothing here',
    'description' => null,
])

{{--
    The results card: table, empty state, pagination.

    THE EMPTY DESCRIPTION IS NOT BOILERPLATE. Every list in this application is filtered by the
    access engine before it reaches a view, so "no records" and "no records you can see" are
    different facts and only one of them is usually true. Callers pass wording that says so, because
    a person told their register is empty when it is merely scoped raises a support ticket, and a
    person told it might be scoped asks their manager instead.

    Slots: `head` for the <tr> of headers, `rows` for the body. Both are written by the caller —
    every register has different columns, and a component that tried to generalise columns would end
    up a worse table than five honest ones.
--}}

<div class="card overflow-hidden">
    @if ($records->isEmpty())
        <x-empty-state :icon="$icon" :title="$title" :description="$description">
            @isset($actions)
                <x-slot:actions>{{ $actions }}</x-slot:actions>
            @endisset
        </x-empty-state>
    @else
        <div class="overflow-x-auto">
            <table class="table">
                <thead>{{ $head }}</thead>
                <tbody>{{ $rows }}</tbody>
            </table>
        </div>

        @if ($records->hasPages())
            <div class="border-t border-[var(--surface-border)] px-4 py-3">
                {{ $records->links() }}
            </div>
        @endif
    @endif
</div>
