@once
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <style>
        /* Keep Leaflet layers inside the map card (default pane z-index is 400+). */
        .tracking-map-shell {
            position: relative;
            z-index: 0;
            isolation: isolate;
            overflow: hidden;
        }

        .tracking-map-shell .leaflet-container {
            z-index: 0;
        }

        .tracking-map-shell .leaflet-pane { z-index: 1 !important; }
        .tracking-map-shell .leaflet-tile-pane { z-index: 1 !important; }
        .tracking-map-shell .leaflet-overlay-pane { z-index: 2 !important; }
        .tracking-map-shell .leaflet-shadow-pane { z-index: 3 !important; }
        .tracking-map-shell .leaflet-marker-pane { z-index: 4 !important; }
        .tracking-map-shell .leaflet-tooltip-pane { z-index: 5 !important; }
        .tracking-map-shell .leaflet-popup-pane { z-index: 6 !important; }
        .tracking-map-shell .leaflet-top,
        .tracking-map-shell .leaflet-bottom { z-index: 7 !important; }

        .tracking-map-shell:fullscreen {
            z-index: 200;
            isolation: auto;
            background: #fff;
            padding: 0;
        }

        .tracking-map-shell:fullscreen .tracking-map-canvas {
            height: 100% !important;
            min-height: 100vh;
            border-radius: 0;
        }

        .tracking-map-popup { min-width: 180px; }
        .tracking-map-popup strong { display: block; margin-bottom: 4px; }
    </style>
@endonce
