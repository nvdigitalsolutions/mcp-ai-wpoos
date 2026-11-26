# `includes/Interfaces/` — ports

What the outside world must provide. Pure interface declarations, enforced at
**strict** purity.

The ports are **ours**, not the vendor's. `node-unifi`, `unifi-client` and the
OpenWrt tooling change shape between releases; adapters absorb that churn so
services never see it.

## Public surface

| Interface | Methods | Implementations |
|---|---|---|
| `Interface_WiFi_Controller_Adapter` | `id`, `label`, `health`, `identity_method`, `kpi_map`, `read_radio_stats`, `list_clients` | `Class_Mock_Controller_Adapter` (Phase 0); UniFi and a second controller in Phase 2 |
| `Interface_WiFi_Clock` | `now` | `Class_System_Clock`; frozen clocks in tests |
| `Interface_WiFi_Id_Generator` | `new_device_id`, `new_session_id` | `Class_Random_Id_Generator`; deterministic generator in tests |
| `Interface_WiFi_Kpi_Sink` | `write`, `read` | `Class_Wp_Kpi_Sink` (option-backed in Phase 0); Eloquent implementation in Laravel |

## Two design points worth preserving

1. **Adapters report native keys plus a map.** `kpi_map()` returns
   `native_key => canonical_kpi_key`. Normalisation therefore lives in one place
   (the service), which is what makes a second controller a small job rather than a
   rewrite.

2. **`identity_method()` is a first-class port method.** A controller that can only
   see MACs declares `probe_hash` (weaker, and flagged as such); one with no
   qualifying identity declares `null`, and the service then **withholds**
   entity-count KPIs and reports `no_identity_method` in `skipped`. Honest absence
   beats a confident wrong number.

Write operations (authorise/deauthorise a client) are deliberately absent from
Phase 0–2: the portal grants access through the guest network's own policy
mechanism, and mutating a controller is a separate, higher-risk surface.

## Neighbours

- **Implemented by** `../Infrastructure/`.
- **Consumed by** `../Services/`.
- **References** `../Domain/` types only.

## Context files

`.context/conventions.md`
