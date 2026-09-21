{{--
    The executive board (§52).

    Expects: $board, $heading, $subheading, $tiles, $canArrange, $units

    "AS FAR AS YOU CAN SEE IT" IS IN THE SUBHEADING ON PURPOSE. Two directors open this page and may
    honestly see different numbers under the same heading, because each figure is counted over the
    records that person can open. Saying so once, at the top, is what stops the first comparison of
    two screens being read as a bug — and a tile whose figure is shared rather than personal says so
    on itself.
--}}
<x-layouts.app title="Executive board">

    <x-page-header :title="$heading" :description="$subheading">
        <x-slot:actions>
            @if ($units->isNotEmpty())
                <a href="{{ route('boards.department') }}" class="btn btn-secondary btn-sm">
                    <x-icon name="building" class="h-3.5 w-3.5"/> Department boards
                </a>
            @endif
        </x-slot:actions>
    </x-page-header>

    @include('boards.partials.grid', ['tiles' => $tiles, 'canArrange' => $canArrange, 'board' => $board])

</x-layouts.app>
