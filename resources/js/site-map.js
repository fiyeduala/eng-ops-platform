/*
 * The site map (§3).
 *
 * WHY LEAFLET IS LOADED DYNAMICALLY. app.js is already ~566 KB, and it is loaded by every single
 * page in the application — the login screen included. Leaflet plus its stylesheet is around 45 KB
 * that only one screen in the system has any use for, so it is imported inside init() rather than
 * at the top of the file. Vite splits it into its own chunk and the browser fetches it when
 * somebody opens the map, which means the other two hundred screens pay nothing for this feature.
 *
 * WHY CIRCLE MARKERS AND NOT PINS. Leaflet's default marker is a PNG that its stylesheet references
 * by a relative URL. Under a bundler that URL resolves against the emitted CSS rather than the
 * package, which is the single most common way a Leaflet build ships with invisible markers. Circle
 * markers are vectors — no image assets, nothing to resolve, and they take a fill colour per
 * location type for free.
 */

/*
 * Location type to colour. Keys match the `type` enum on the locations table.
 *
 * Held here rather than in the Blade file because it is presentation and belongs with the renderer,
 * and because an unknown type has to fall back to something rather than render nothing.
 */
const TYPE_COLOURS = {
    office: '#2563eb',
    site: '#ea580c',
    yard: '#ca8a04',
    warehouse: '#7c3aed',
    plant: '#dc2626',
    remote: '#0d9488',
};

const FALLBACK_COLOUR = '#64748b';

/**
 * Build a popup as DOM nodes, never as an HTML string.
 *
 * Location names, codes and cities are typed in by users. `bindPopup('<b>' + name + '</b>')` would
 * parse them as HTML, which makes a location called `<img onerror=...>` into stored XSS that fires
 * for every colleague who opens the map. textContent cannot do that — the markup arrives as the
 * literal characters somebody typed. Blade escapes by default and this is the one place in the
 * feature where that protection does not apply automatically, so it is done by hand.
 */
function buildPopup(properties) {
    const root = document.createElement('div');
    root.className = 'site-map-popup';

    const heading = document.createElement('p');
    heading.className = 'site-map-popup__name';
    heading.textContent = properties.name;
    root.appendChild(heading);

    const subtitleParts = [properties.code, properties.city].filter(Boolean);

    if (subtitleParts.length > 0) {
        const subtitle = document.createElement('p');
        subtitle.className = 'site-map-popup__meta';
        subtitle.textContent = subtitleParts.join(' · ');
        root.appendChild(subtitle);
    }

    /*
     * The metrics the server chose to send. An empty array is the normal, correct state for a
     * viewer who holds no module permissions — the pin still shows where the place is, which is
     * what `sitemap.view` grants, and says nothing about what is at it.
     *
     * Nothing is inferred client-side from a missing key. A metric that is absent is absent
     * because the server would not answer, and guessing a zero here would undo that.
     */
    if (Array.isArray(properties.metrics) && properties.metrics.length > 0) {
        const list = document.createElement('dl');
        list.className = 'site-map-popup__metrics';

        properties.metrics.forEach((metric) => {
            const term = document.createElement('dt');
            term.textContent = metric.label;

            const value = document.createElement('dd');
            value.textContent = String(metric.value);

            list.appendChild(term);
            list.appendChild(value);
        });

        root.appendChild(list);
    }

    if (properties.radius) {
        const geofence = document.createElement('p');
        geofence.className = 'site-map-popup__meta';
        geofence.textContent = `Geofence ${properties.radius} m`;
        root.appendChild(geofence);
    }

    return root;
}

export default function siteMap({ endpoint, tiles, defaultView }) {
    return {
        loading: true,
        error: null,
        empty: false,
        total: 0,

        // Deliberately not reactive Alpine state. Leaflet objects are large, deeply nested and
        // mutate themselves constantly; handing them to Alpine's proxy is a performance trap and
        // in places breaks Leaflet's own identity checks. Alpine tracks the flags above; the map
        // is a plain property.
        map: null,
        tileLayer: null,
        markers: null,

        async init() {
            let L;

            try {
                // Both the library and its stylesheet, in the same chunk, fetched now.
                const [leaflet] = await Promise.all([
                    import('leaflet'),
                    import('leaflet/dist/leaflet.css'),
                ]);

                L = leaflet.default ?? leaflet;
            } catch (e) {
                this.loading = false;
                this.error = 'The map library could not be loaded. Check your connection and reload.';

                return;
            }

            this.leaflet = L;

            this.map = L.map(this.$refs.canvas, {
                // The default is fine everywhere except inside a scrolling page, where a stray
                // wheel event over the map swallows the scroll and traps the reader. Ctrl+wheel
                // and the +/- control still zoom.
                scrollWheelZoom: false,
            }).setView(
                [defaultView.latitude, defaultView.longitude],
                defaultView.zoom,
            );

            this.markers = L.layerGroup().addTo(this.map);

            this.applyTiles();

            // Swap the basemap when the theme changes, but only if a dark style was configured.
            // Left unset, both themes share one basemap — see the note in config/map.php.
            if (tiles.dark_url) {
                this.$watch('$store.theme.dark', () => this.applyTiles());
            }

            await this.load();
        },

        applyTiles() {
            const L = this.leaflet;
            const dark = this.$store.theme?.dark === true;
            const url = dark && tiles.dark_url ? tiles.dark_url : tiles.url;

            if (this.tileLayer) {
                this.map.removeLayer(this.tileLayer);
            }

            this.tileLayer = L.tileLayer(url, {
                maxZoom: tiles.max_zoom,
                // Rendered by Leaflet into the corner of the map. This is a licence condition of
                // every tile provider worth using, which is why it is not optional and not styled
                // away — see config/map.php.
                attribution: tiles.attribution,
            }).addTo(this.map);
        },

        async load() {
            this.loading = true;
            this.error = null;

            let payload;

            try {
                const response = await fetch(endpoint, {
                    headers: { Accept: 'application/json' },
                    // Same-origin GET carrying the session cookie. The endpoint authorizes this
                    // request on its own rather than trusting that the page was rendered.
                    credentials: 'same-origin',
                });

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                payload = await response.json();
            } catch (e) {
                this.loading = false;
                this.error = 'The locations could not be loaded. Reload the page to try again.';

                return;
            }

            this.render(payload.features ?? []);
            this.loading = false;
        },

        render(features) {
            const L = this.leaflet;

            this.markers.clearLayers();

            this.total = features.length;
            this.empty = features.length === 0;

            if (this.empty) {
                return;
            }

            const bounds = [];

            features.forEach((feature) => {
                // GeoJSON stores [longitude, latitude]; Leaflet takes [latitude, longitude]. The
                // swap is here, once, and it is the only place in the frontend that touches the
                // order — getting it wrong does not throw, it just moves everything to the wrong
                // hemisphere.
                const [longitude, latitude] = feature.geometry.coordinates;
                const properties = feature.properties;
                const colour = TYPE_COLOURS[properties.type] ?? FALLBACK_COLOUR;

                bounds.push([latitude, longitude]);

                // The geofence first, so the marker draws on top of its own circle.
                if (properties.radius) {
                    L.circle([latitude, longitude], {
                        radius: properties.radius,
                        color: colour,
                        weight: 1,
                        opacity: 0.5,
                        fillColor: colour,
                        fillOpacity: 0.1,
                        interactive: false,
                    }).addTo(this.markers);
                }

                L.circleMarker([latitude, longitude], {
                    radius: 7,
                    color: '#ffffff',
                    weight: 2,
                    fillColor: colour,
                    fillOpacity: 1,
                })
                    .bindPopup(buildPopup(properties))
                    .bindTooltip(properties.name)
                    .addTo(this.markers);
            });

            /*
             * Fit the data rather than keep the configured default. A company with one location
             * gets a sensible zoom instead of being fitted to a single point at zoom 19; a company
             * on two continents gets both. padding keeps pins off the edge, where half a marker is
             * easy to miss.
             */
            if (bounds.length === 1) {
                this.map.setView(bounds[0], 15);
            } else {
                this.map.fitBounds(bounds, { padding: [48, 48] });
            }
        },
    };
}
