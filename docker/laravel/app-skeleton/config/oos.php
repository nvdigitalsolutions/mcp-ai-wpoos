<?php

/**
 * Application-level NV oOS configuration (Proposal 149 T-0.6).
 *
 * This file is intentionally partial. `Nvoos\Laravel` ships defaults under the
 * same `oos` key via `mergeConfigFrom()`, which does
 * `array_merge($packageDefaults, $this)`, so app keys win and every package key
 * this file does not mention keeps its default. Keep it partial — copying the
 * package file here would fork those defaults.
 */

return array(

    /*
    |--------------------------------------------------------------------------
    | Admin theme (Proposal 149 T-0.6)
    |--------------------------------------------------------------------------
    |
    | Where the Filament admin palette is generated from. The admin is a direct
    | ADS consumer (it does NOT round-trip through the NDS export, which drops
    | accent-ink, danger and focus-ring — see AdminThemeGenerator's docblock).
    |
    | Default resolves to the repo's storefront tokens. Both the container
    | (`/app`) and CI (`docker/laravel/app-skeleton`) sit three levels below the
    | repo root, so one relative path serves both.
    |
    */
    'admin_theme' => array(
        'tokens_path' => env(
            'NVOOS_TOKENS_PATH',
            base_path('../../../examples/nvoos-astro-velocity/src/tokens')
        ),
    ),

    /*
    |--------------------------------------------------------------------------
    | Routing metrics retention (Proposal 149 §73)
    |--------------------------------------------------------------------------
    |
    | `nvoos_routing_metrics` holds one row per routing deviation (a model
    | fallback or a provider hop), which is rare — but nothing bounded it, and
    | an unbounded table is a slow leak rather than a decision.
    |
    | Days to keep. `0` disables pruning and the schedule entry entirely, for a
    | deployment that exports the rows somewhere else first.
    |
    */
    'routing' => array(
        'retention_days' => (int) env('NVOOS_ROUTING_RETENTION_DAYS', 90),

        /*
        |----------------------------------------------------------------------
        | OTLP export (Proposal 149 §74)
        |----------------------------------------------------------------------
        |
        | Push unexported routing events to an OTLP-compatible collector. An
        | empty endpoint disables the push *and* relaxes retention (nothing is
        | promised to a collector, so nothing must wait for one).
        |
        | `schedule` is a cron expression; empty means "configured, but only
        | run when asked" — the console action and CLI still work.
        |
        */
        'export' => array(
            'endpoint' => (string) env('NVOOS_ROUTING_EXPORT_ENDPOINT', ''),
            'token' => (string) env('NVOOS_ROUTING_EXPORT_TOKEN', ''),
            'headers' => array(),
            'schedule' => (string) env('NVOOS_ROUTING_EXPORT_SCHEDULE', ''),
            'timeout' => (int) env('NVOOS_ROUTING_EXPORT_TIMEOUT', 10),
            'batch' => (int) env('NVOOS_ROUTING_EXPORT_BATCH', 500),
        ),
    ),

);
