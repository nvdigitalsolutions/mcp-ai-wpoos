# `includes/Services/` — use-cases

Orchestration over injected ports. Strict purity: no globals, no options, no
`WP_*`, no `new` for dependencies.

## Public surface

| Class | Responsibility |
|---|---|
| `Class_Kpi_Service` | `health()`, `for_venue( Venue, Window )`, `stored( Venue, Window )`. Reads adapter telemetry, normalises it through `Kpi_Schema` + the adapter's `kpi_map()`, persists it, and returns the canonical payload. |

`Class_Kpi_Service::for_venue()` is the **Phase 0 exit condition**: the WordPress
REST route and the Laravel route both call it, and both must return byte-identical
JSON (implementation plan T-0.11).

## Determinism rules

Host parity fails on any of these, so they are deliberate:

- samples are keyed by canonical KPI key and `ksort`-ed — adapter map order cannot
  leak into the payload;
- `skipped` entries are `ksort`-ed for the same reason;
- when two native keys target one canonical KPI, the **first** wins and the
  duplicate is recorded in `skipped` rather than silently replacing;
- no `time()` calls — the injected clock supplies `generated_at`.

## Degradation rules

- An adapter returning a malformed stats payload yields `adapter_shape` (502),
  never an empty success.
- An entity-count KPI with no available identity method is **skipped**, with reason
  `no_identity_method`. It is never emitted unqualified.
- A sample that fails domain validation is skipped with its error code as the
  reason, so one bad KPI cannot fail an entire venue read.

## Neighbours

- **Depends on** `../Domain/` and `../Interfaces/`.
- **Built by** `../Plugin.php` (the composition root).
- **Called by** `../REST/` and `../Tools/`.

## Context files

`.context/conventions.md`
