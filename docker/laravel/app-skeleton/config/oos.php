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
    | Machine-executable tools (Proposal 159 slice 4)
    |--------------------------------------------------------------------------
    |
    | Tools a machine principal may execute over `POST /api/v1/tools/{tool}`
    | (149 plan §123). Empty by default: a machine executes nothing until an
    | operator names a tool here **and** the assistant lists it.
    |
    | The read-only capability flags in `lib/core` would be the better authority,
    | but no tool in this runtime declares them yet (214 tool classes, 0 declare),
    | so a flag-based guard would block everything while reading as protection.
    | This list is the interim authority; `ReadOnlyToolGuard` becomes usable as
    | tools start declaring flags.
    */
    'machine_tools' => array(),

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

            // Transport compression (§77). OTLP requires every receiver to
            // accept `none` and `gzip`; anything else is treated as `none`
            // rather than sending a header the collector may reject. The
            // default stays `none` so an existing deployment's wire format
            // does not change on upgrade — set `gzip` for a real collector.
            'compression' => (string) env('NVOOS_ROUTING_EXPORT_COMPRESSION', 'none'),

            // Smallest body worth gzipping. Below it the header and trailer
            // cost more than the saving; `0` compresses every body.
            'compression_threshold' => (int) env('NVOOS_ROUTING_EXPORT_COMPRESSION_THRESHOLD', 1024),

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

        // Record one row per completed chat turn (§81). On by default: the
        // cost pages read this table, and an off-by-default telemetry switch
        // is a feature nobody turns on. `false` stops the writes, not the
        // reads — existing rows and the rollup are untouched.
        'track' => filter_var(env('NVOOS_TOKEN_USAGE_TRACK', true), FILTER_VALIDATE_BOOLEAN),
        'prune_chunk' => (int) env('NVOOS_TOKEN_USAGE_PRUNE_CHUNK', 5000),

        // Days re-rolled each night (§80). The rollup is idempotent, so a week
        // of overlap heals a day a failed run skipped. `0` disables the
        // schedule entry — and, because retention refuses to delete a day that
        // was never rolled up, effectively disables pruning too.
        'rollup_days' => (int) env('NVOOS_TOKEN_USAGE_ROLLUP_DAYS', 7),
    ),

    /*
    |--------------------------------------------------------------------------
    | Access-request digest (Proposal 149 §111)
    |--------------------------------------------------------------------------
    |
    | A summary mail naming the people who tried to join an estate and were
    | refused (§110). The pending list already exists; a list nobody looks at is
    | the same as no list, and the support pressure that follows is what makes an
    | operator turn JIT on for everyone.
    |
    | Daily at a FIXED hour, not "every hour": a predictable delivery time is
    | what makes a digest worth opening, and a cadence faster than daily turns a
    | low-urgency reminder into noise that gets filtered (or the channel muted).
    |
    | `enabled = false` removes the schedule entry for the whole deployment — for
    | a runtime with no mail transport at all. Per-estate consent is the
    | `enable_access_digest` setting, which defaults ON: unlike JIT provisioning
    | this grants nothing and discloses nothing the estate's panel does not
    | already show its non-viewer members.
    |
    | `schedule` is a cron expression. Invalid or empty schedules nothing, which
    | is why this is not a bare `daily()` — an operator in a different timezone
    | should not need a code change to move an 08:00 mail.
    |
    */
    'access_digest' => array(
        'enabled' => filter_var(env('NVOOS_ACCESS_DIGEST', true), FILTER_VALIDATE_BOOLEAN),
        'schedule' => (string) env('NVOOS_ACCESS_DIGEST_SCHEDULE', '0 8 * * *'),

        // How many addresses the mail names before it summarises the rest. A
        // digest that pastes 200 rows is a log, and nobody reads a log in an
        // inbox. The page always shows the full list.
        'preview_limit' => (int) env('NVOOS_ACCESS_DIGEST_PREVIEW_LIMIT', 10),
    ),

    /*
    |--------------------------------------------------------------------------
    | Schedules (090-b)
    |--------------------------------------------------------------------------
    |
    | `user_id` is the actor a timed run performs as: tools routinely check a
    | user and a cron tick has none. `0` resolves the first administrator.
    |
    | `stale_after` is how long an in-flight run (`pending`/`running`) may stay
    | silent before it is treated as a dead worker and reaped to `timeout`. It is
    | also the RunSchedule queue-uniqueness window, so the two must agree: a
    | shorter reap window would let a re-dispatch race a live run.
    |
    */
    'schedules' => array(
        'user_id' => (int) env('NVOOS_SCHEDULES_USER_ID', 0),
        'stale_after' => (int) env('NVOOS_SCHEDULES_STALE_AFTER', 900),
    ),

);
