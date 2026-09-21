{{--
    How this company's boards are put together.

    Expects: $boards, $widgets, $kinds, $refresh, $canManage, $maxPerBoard

    THE ARRANGEMENT, NOT THE BOARD. This page lists tiles and says what each draws from; it does not
    show their figures. That is deliberate and saves a decision: a preview here would have to be
    counted for somebody, and the only honest somebody is the person standing at this screen — which
    is not who the board is for. Boards are opened where people work, and each reader's figures are
    counted for them when they get there.
--}}
<x-layouts.app title="Boards">

    <x-page-header
        title="Boards"
        description="Which tiles sit on each board. One arrangement serves the whole company — everybody who opens a board sees the tiles they are allowed to see, carrying figures counted for them.">
        <x-slot:actions>
            @if ($canManage)
                <a href="{{ route('configuration.widgets.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> New tile
                </a>
            @endif
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

    @if ($errors->any())
        <div class="card mb-5 border-red-300 p-4 text-sm">
            <p class="mb-1 flex items-center gap-2 font-medium text-[var(--text-strong)]">
                <x-icon name="alert" class="h-4 w-4 text-red-600"/> That tile was not saved
            </p>
            <ul class="ml-6 list-disc space-y-1 text-[var(--text-body)]">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card mb-5 flex items-start gap-2 p-4 text-sm text-[var(--text-body)]">
        <x-icon name="info" class="mt-0.5 h-4 w-4 shrink-0 text-[var(--text-muted)]"/>
        <p>
            A tile carries no permission of its own. Whether somebody sees it is decided by the
            register behind it — a tile counting incidents appears only for people who may read
            incidents — so you can arrange a board once without worrying about who is looking.
        </p>
    </div>

    @foreach ($boards as $key => $board)
        @php
            $onBoard = $widgets->get($key) ?? collect();
        @endphp

        <div class="card mb-5">
            <div class="card-header flex flex-wrap items-center justify-between gap-2">
                <div>
                    <p class="card-title">{{ $board['label'] }}</p>
                    <p class="help">{{ $board['description'] }}</p>
                </div>
                <div class="flex items-center gap-2">
                    <span class="help">{{ $onBoard->count() }} of {{ $maxPerBoard }}</span>
                    @if ($canManage)
                        <a href="{{ route('configuration.widgets.create', ['board' => $key]) }}"
                           class="btn btn-ghost btn-sm">
                            <x-icon name="plus" class="h-3.5 w-3.5"/> Add
                        </a>
                    @endif
                </div>
            </div>

            @if ($onBoard->isEmpty())
                <p class="p-4 text-sm text-[var(--text-muted)]">Nothing on this board yet.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Tile</th>
                                <th>Shows</th>
                                <th>Figure</th>
                                <th>Width</th>
                                <th class="text-right">&nbsp;</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($onBoard as $widget)
                                @php $source = $widget->source(); @endphp
                                <tr class="{{ $widget->is_active ? '' : 'opacity-60' }}">
                                    <td>
                                        <span class="font-medium text-[var(--text-strong)]">{{ $widget->heading() }}</span>
                                        @unless ($widget->is_active)
                                            <x-badge tone="neutral">Hidden</x-badge>
                                        @endunless
                                        <span class="help block">{{ $kinds[$widget->kind] ?? $widget->kind }}</span>
                                    </td>
                                    <td class="text-sm text-[var(--text-body)]">
                                        {{ $source?->displayName() ?? 'Nothing — its source has gone' }}
                                    </td>
                                    <td class="text-sm text-[var(--text-body)]">
                                        {{ $refresh[$widget->refresh] ?? $widget->refresh }}
                                    </td>
                                    <td class="text-sm text-[var(--text-body)]">{{ $widget->width }}</td>
                                    <td class="text-right whitespace-nowrap">
                                        @if ($canManage)
                                            <a href="{{ route('configuration.widgets.edit', $widget) }}"
                                               class="btn btn-ghost btn-sm">
                                                <x-icon name="pencil" class="h-3.5 w-3.5"/> Edit
                                            </a>
                                            <form method="POST"
                                                  action="{{ route('configuration.widgets.toggle', $widget) }}"
                                                  class="inline">
                                                @csrf
                                                @method('PUT')
                                                <button type="submit" class="btn btn-ghost btn-sm">
                                                    {{ $widget->is_active ? 'Hide' : 'Show' }}
                                                </button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endforeach

</x-layouts.app>
