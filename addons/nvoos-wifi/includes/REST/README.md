# `includes/REST/` — REST controllers

## Public surface

```
GET /wp-json/nvoos-wifi/v1/venues/{id}/kpis?from=<epoch>&to=<epoch>
```

| Concern | Implementation |
|---|---|
| Permission | `manage_options`. Phase 1 replaces this with a per-venue capability once venue ownership exists. |
| Args | `id` → `sanitize_text_field`; `from`/`to` → `absint`, both required. |
| Errors | Domain `Result` → `WP_Error` via `Class_Result_Mapper`, with a distinct status per code (400 / 404 / 422 / 502 / 503 / 504). |
| Success | `WP_REST_Response( $result->value(), 200 )`. |

## The rule that matters

**This controller adds no logic of its own.** It resolves the venue, builds the
window, calls `Class_Kpi_Service::for_venue()` and returns the payload. Anything
that shapes the response here would have to be duplicated in the Laravel
controller, and the two would drift — which is exactly what the parity test
(T-0.11) exists to catch.

## Neighbours

- **Calls** `../Services/Class_Kpi_Service`, `../Infrastructure/Wp/Class_Venue_Repository`,
  `../Infrastructure/Wp/Class_Result_Mapper`.
- **Registered by** `../Plugin::register_rest_routes()` on `rest_api_init`.

## Context files

`.context/rest-api.md` · `.context/security-checklist.md`
