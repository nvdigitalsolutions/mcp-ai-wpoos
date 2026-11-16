# `includes/Infrastructure/` — adapters

The **only** layer permitted to call WordPress, HTTP, a controller API, or the
filesystem. Business rules do not belong here.

## `Wp/` — WordPress port implementations

| Class | Implements | Notes |
|---|---|---|
| `Class_System_Clock` | `Interface_WiFi_Clock` | Uses `time()`, not `current_time()`. The domain works exclusively in UTC epoch seconds; mixing in a site offset is the timezone-drift failure class. |
| `Class_Random_Id_Generator` | `Interface_WiFi_Id_Generator` | `random_bytes`, prefixed `dev_` / `ses_`. Fails loudly via `wp_die` rather than degrading to a predictable identifier. |
| `Class_Wp_Kpi_Sink` | `Interface_WiFi_Kpi_Sink` | Non-autoloaded option per venue, capped at 500 samples. Writes are idempotent per `(key, at, source_adapter)`, so a retried poll cannot double-count a counter. |
| `Class_Venue_Repository` | — | Option-backed venue lookup. Invalid records are skipped, never fatal. |
| `Class_Result_Mapper` | — | `Domain\Result` → `WP_Error`, with a distinct HTTP status per error code. Auth failure, unreachable controller and rate limiting do **not** collapse into one message. |

Phase 0 storage is deliberately the cheapest thing that works. The Phase 0 question
is whether the domain is portable, not whether an option-backed store is optimal;
Phase 2 replaces the sink with a custom table once the KPI vocabulary is frozen.

## `Providers/` — controller adapters

| Class | Status |
|---|---|
| `Class_Mock_Controller_Adapter` | **Phase 0.** Deterministic, offline, claims `identity_method() === 'device_id'`. Ships before any real adapter so tests, demos and CI never need hardware (pattern from `@subashgautam/router-mcp`). |
| UniFi adapter | Phase 2 |
| Second controller | Phase 2 — proves the port |

The mock derives every value from `crc32( seed | venue_id | native_key )`, which is
stable across PHP versions and platforms. That determinism is what lets the
dual-runtime parity fixture be committed.

## Licence boundary

GPL-2.0 hostapd/ubus helpers (`listwificlients`, `ubus-websocket-communicator`,
`iopsys-ws-communicator`) may be invoked as **separate processes** only. They must
never be `require`d or `include`d into a shipped bundle. See
`THIRD_PARTY_NOTICES.md` and research §3.8.

## Neighbours

- **Implements** `../Interfaces/`.
- **Bound by** `../Plugin.php` (the composition root).
- **May depend on** `../Domain/` and WordPress.

## Context files

`.context/conventions.md` · `.context/security-checklist.md`
