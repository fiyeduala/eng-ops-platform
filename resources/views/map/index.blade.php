{{--
    The site map (§3).

    Every location this company has given coordinates to, with its geofence and a count of what is
    filed against it. Read-only — coordinates are edited on the location itself, which is the one
    place they are allowed to change.

    The counts are NOT rendered from anything this page knows. They arrive per-pin from
    /map/locations, where each one is produced only if the viewer holds that module's own
    permission, so a pin shows four numbers for one colleague and none for another. §6: the gate is
    the server's, and this page only draws what it was given.
--}}
<x-layouts.app title="Site map">

    @push('styles')
        {{--
            Leaflet ships its own light-only styling for popups, tooltips and controls, which reads
            as a white card dropped onto a dark page. These overrides hand it the same surface
            variables every other card in the application uses, so the map follows the theme
            toggle rather than fighting it.

            Kept in the page rather than app.css: this is the only screen that loads Leaflet, and
            rules for classes that exist nowhere else do not belong in the stylesheet every page
            downloads.
        --}}
        <style>
            .site-map-canvas {
                height: clamp(26rem, calc(100vh - 19rem), 46rem);
                width: 100%;
                background: var(--surface-muted);
                z-index: 0; /* Keeps Leaflet's panes under the app's own dropdowns and modals. */
            }

            .leaflet-container {
                font-family: var(--font-sans);
                background: var(--surface-muted);
            }

            .leaflet-popup-content-wrapper,
            .leaflet-popup-tip,
            .leaflet-tooltip {
                background: var(--surface-card);
                color: var(--text-body);
                border: 1px solid var(--surface-border);
                box-shadow: 0 10px 20px -8px rgb(0 0 0 / 0.25);
            }

            .leaflet-popup-content-wrapper { border-radius: 0.5rem; }
            .leaflet-popup-content { margin: 0.75rem 0.875rem; }
            .leaflet-tooltip { box-shadow: none; font-size: 0.75rem; }

            .leaflet-tooltip-left::before { border-left-color: var(--surface-border); }
            .leaflet-tooltip-right::before { border-right-color: var(--surface-border); }
            .leaflet-tooltip-top::before { border-top-color: var(--surface-border); }
            .leaflet-tooltip-bottom::before { border-bottom-color: var(--surface-border); }

            .leaflet-bar a,
            .leaflet-control-attribution {
                background: var(--surface-card) !important;
                color: var(--text-muted) !important;
                border-color: var(--surface-border) !important;
            }

            .leaflet-bar a:hover { background: var(--surface-muted) !important; }
            .leaflet-control-attribution a { color: var(--text-body) !important; }

            .site-map-popup__name {
                font-size: 0.875rem;
                font-weight: 600;
                color: var(--text-strong);
            }

            .site-map-popup__meta {
                margin-top: 0.125rem;
                font-size: 0.75rem;
                color: var(--text-muted);
            }

            .site-map-popup__metrics {
                display: grid;
                grid-template-columns: 1fr auto;
                gap: 0.125rem 1rem;
                margin: 0.5rem 0 0;
                padding-top: 0.5rem;
                border-top: 1px solid var(--surface-border);
                font-size: 0.75rem;
            }

            .site-map-popup__metrics dt { color: var(--text-muted); }

            .site-map-popup__metrics dd {
                margin: 0;
                text-align: right;
                font-weight: 600;
                font-variant-numeric: tabular-nums;
                color: var(--text-strong);
            }
        </style>
    @endpush

    <x-page-header
        title="Site map"
        description="Where this company works, and what is filed against each place. Coordinates are set on the location record.">
        @if (Route::has('locations.index') && auth()->user()->hasPermission('location.view'))
            <x-slot:actions>
                <a href="{{ route('locations.index') }}" class="btn btn-secondary btn-sm">
                    <x-icon name="pin" class="h-3.5 w-3.5"/> Locations
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    <div
        class="card overflow-hidden"
        x-data="siteMap({
            endpoint: @js($endpoint),
            tiles: @js($tiles),
            defaultView: @js($defaultView),
        })"
    >
        <div class="relative">
            {{-- Leaflet measures this element on init, so it carries its height from the start. --}}
            <div x-ref="canvas" class="site-map-canvas"></div>

            {{--
                The three states sit over the canvas rather than replacing it, because Leaflet
                cannot initialise against an element that was never laid out — swapping the canvas
                out for an empty state would leave it with no size to measure on the way back.
            --}}
            <div
                x-show="loading"
                x-cloak
                class="absolute inset-0 z-10 flex items-center justify-center bg-[var(--surface-card)]/85"
            >
                <p class="text-sm text-[var(--text-muted)]">Loading locations…</p>
            </div>

            <div
                x-show="error"
                x-cloak
                class="absolute inset-0 z-10 flex items-center justify-center bg-[var(--surface-card)]/95 px-6"
            >
                <p class="max-w-sm text-center text-sm text-[var(--text-body)]" x-text="error"></p>
            </div>

            <div
                x-show="empty && ! loading && ! error"
                x-cloak
                class="absolute inset-0 z-10 flex items-center justify-center bg-[var(--surface-card)]/95"
            >
                {{--
                    Honest wording, per the empty-state component's own note: this list is
                    permission-filtered AND coordinate-filtered, so there are two distinct reasons
                    it can be empty and neither of them is "this company has no locations".
                --}}
                <x-empty-state
                    icon="map"
                    title="No locations to map"
                    description="Nothing you can see has coordinates recorded yet. Add a latitude and longitude to a location and it will appear here."
                />
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-[var(--surface-border)] px-4 py-3">
            {{-- The colours are assigned in site-map.js; this legend names them. --}}
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-[var(--text-muted)]">
                @foreach ([
                    'Office' => '#2563eb',
                    'Site' => '#ea580c',
                    'Yard' => '#ca8a04',
                    'Warehouse' => '#7c3aed',
                    'Plant' => '#dc2626',
                    'Remote' => '#0d9488',
                ] as $label => $colour)
                    <span class="flex items-center gap-1.5">
                        <span class="h-2.5 w-2.5 rounded-full ring-2 ring-[var(--surface-card)]"
                              style="background: {{ $colour }}"></span>
                        {{ $label }}
                    </span>
                @endforeach
            </div>

            <p class="text-xs text-[var(--text-muted)]">
                <span x-text="total"></span> mapped · shaded rings are geofences
            </p>
        </div>
    </div>

</x-layouts.app>
