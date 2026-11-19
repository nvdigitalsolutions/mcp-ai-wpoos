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

            // A failing collector is retried on an exponential schedule
            // (base × 2^(n-1), capped). 0 disables backoff: every tick tries.
            'backoff_base' => (int) env('NVOOS_ROUTING_EXPORT_BACKOFF_BASE', 60),
            'backoff_max' => (int) env('NVOOS_ROUTING_EXPORT_BACKOFF_MAX', 3600),

            // Additive jitter as a fraction of the computed delay (0 disables).
            // A fleet that fails together must not retry together.
            'backoff_jitter' => (float) env('NVOOS_ROUTING_EXPORT_BACKOFF_JITTER', 0.2),
        ),
    ),

    /*
    |--------------------------------------------------------------------------
    | Token usage retention (Proposal 149 §76)
    |--------------------------------------------------------------------------
    |
    | `nvoos_token_usage` takes one row per AI call — the highest-volume table
    | the console reads, and the one the cost pages aggregate over. Nothing
    | bounded it.
    |
    | Unlike routing metrics there is no export: these rows *are* the cost
    | history, so the default window is a year rather than 90 days, and a row
    | deleted past the window is gone for good. `0` disables pruning and the
    | schedule entry entirely, for a deployment that bills from this table.
    |
    | `prune_chunk` bounds each delete statement, so a first run against a long
    | unpruned history cannot hold one huge transaction.
    |
    */
    'token_usage' => array(
        'retention_days' => (int) env('NVOOS_TOKEN_USAGE_RETENTION_DAYS', 365),
        'prune_chunk' => (int) env('NVOOS_TOKEN_USAGE_PRUNE_CHUNK', 5000),
    ),

);
