# `includes/Domain/` — pure domain layer

Zero WordPress. Zero I/O. PHP standard library only.

Enforced by `bin/check-domain-purity.php` at **strict** severity
(`composer run quality:domain-purity`). A single `WP_*` reference or `get_option()`
call here fails CI.

## Public surface

| Class | Responsibility |
|---|---|
| `Result` | Success/failure outcome. Replaces `WP_Error`, which the domain may not use. `to_array()` is the canonical serialisation both hosts must reproduce byte-for-byte. |
| `Venue` | Tenant root. Validates id, label, IANA timezone, data-residency label. |
| `Window` | Half-open `[from, to)` in UTC epoch seconds. |
| `Device_Identity` | Opaque, rotation-aware identity per 802.11bh-2024. **Rejects MAC-shaped values** with `mac_not_allowed`. |
| `Kpi_Schema` | The canonical KPI vocabulary, modelled on Wi-Fi Alliance Data Elements. 18 KPIs in Phase 0. |
| `Kpi_Sample` | A measured value + provenance. Fails with `method_required` when an entity count omits its counting method. |

## Invariants that are not negotiable

1. **No MAC identity.** `Device_Identity` has no `mac` property and refuses
   MAC-shaped input. MACs randomise by design (RFC 9724) and inflate unique counts
   300–500%.
2. **Entity counts name their method.** `wifi.clients.*` and `wifi.sessions.*`
   require a method; the schema flag `identity_sensitive` drives the check.
3. **`null` is not `0`.** A missing value stays `null`; it is never coerced.
4. **No `ABSPATH` guard in these files.** A guard would make the layer unusable in
   the Laravel host, defeating the point of the layering.

## Neighbours

- **Used by** `../Services/` (use-cases) and `../Infrastructure/` (adapters produce
  `Device_Identity` values).
- **Depends on** nothing. This is a leaf.

## Context files

`.context/conventions.md`
