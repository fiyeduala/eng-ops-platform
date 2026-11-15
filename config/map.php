<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Basemap tiles
    |--------------------------------------------------------------------------
    |
    | The map library is free; the IMAGERY UNDERNEATH IT IS NOT, and that is the
    | only part of this feature with a licence attached.
    |
    | The default below is OpenStreetMap's public tile server, which is correct
    | for local development and NOT LICENSED FOR PRODUCTION USE. The OSMF tile
    | usage policy forbids systematic and commercial use, and there is no fee
    | you can pay them to make it allowed — the answer is to use a provider or
    | host your own. Shipping a client deployment pointed here is a licence
    | breach that will work perfectly until it is throttled or blocked, which is
    | the worst failure shape available: fine in the demo, broken on site.
    |
    | So each deployment sets its own. Providers with a free tier large enough
    | for a company of this size: MapTiler, Stadia Maps, Carto, Thunderforest.
    | On a VPS, self-hosted PMTiles removes the per-request cost entirely.
    |
    | Attribution is a LICENCE CONDITION, not decoration. Whatever tiles you
    | point at, the string below must name their source, and the map renders it.
    |
    */

    'tiles' => [
        'url' => env('MAP_TILE_URL', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),

        'attribution' => env('MAP_TILE_ATTRIBUTION', '© OpenStreetMap contributors'),

        'max_zoom' => (int) env('MAP_TILE_MAX_ZOOM', 19),

        /*
         * A second style for dark mode. Optional: left empty, the map keeps the
         * light basemap in both themes rather than inventing one, because a
         * CSS-inverted basemap renders text and water in colours that read as a
         * rendering fault rather than a dark theme.
         */
        'dark_url' => env('MAP_TILE_DARK_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Where the map opens when a company has no coordinates yet
    |--------------------------------------------------------------------------
    |
    | Only used when NOT ONE location has been given coordinates. With any at
    | all the map fits its own bounds, which is always better than a guess —
    | these values exist so the first screen is a map rather than a grey void.
    |
    */

    'default_view' => [
        'latitude' => (float) env('MAP_DEFAULT_LATITUDE', 9.0820),
        'longitude' => (float) env('MAP_DEFAULT_LONGITUDE', 8.6753),
        'zoom' => (int) env('MAP_DEFAULT_ZOOM', 5),
    ],

];
