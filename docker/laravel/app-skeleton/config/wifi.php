<?php

/*
|--------------------------------------------------------------------------
| NV oOS WiFi (Proposal 150)
|--------------------------------------------------------------------------
|
| Venue records for the Laravel host. The WordPress host stores these in the
| `nvoos_wifi_venues` option; here they are configuration, which keeps Phase 0
| free of a migration. Both hosts hydrate the same domain `Venue` value object,
| so validation (IANA timezone, residency label) is identical.
|
| `residency` is not decorative: it is what a cross-border transfer decision is
| made against (Jamaica DPA 2020). Leave it `unspecified` only until it is known.
|
*/

return [

    /*
    | Seed for the deterministic mock controller adapter. Phase 0 has no real
    | controller; the mock derives every value from
    | crc32( seed | venue_id | native_key ), which is what lets the WordPress and
    | Laravel parity tests compare against one committed fixture.
    */
    'mock_seed' => env('NVOOS_WIFI_MOCK_SEED', 'mock-default'),

    /*
    | Venues keyed by identifier. `venue-1` is the parity fixture venue — the
    | label and timezone must stay in step with
    | addons/nvoos-wifi/tests/fixtures/kpis-venue-1-window-1.json.
    */
    'venues' => [
        'venue-1' => [
            'label' => 'Kingston Waterfront',
            'timezone' => 'America/Jamaica',
            'residency' => 'jm',
        ],
    ],

];
