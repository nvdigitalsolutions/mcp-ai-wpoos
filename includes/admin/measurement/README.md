# Admin Measurement Dashboard

## Purpose

Houses the Measurement Framework admin dashboard — a single controller class that renders tables for metrics, verifiers, rewards, suites, budgets, and recent events within the Pro admin interface.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 7.4+ |
| **Loaded by** | `addons/pro/includes/admin/` via WordPress `admin_menu` hook |
| **Optional dependencies** | none |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Admin_Measurement_Dashboard` | `class-wp-mcp-ai-admin-measurement-dashboard.php` | Pro admin menu (`admin.php?page=wp-mcp-ai-measurement`) |

The class registers an admin menu page, handles `admin-post.php` actions for exports, and renders the following sections:
- **Metrics Table** — persisted measurement metrics with value, unit, and classification
- **Verifiers Table** — active verification rules and their status
- **Rewards Table** — reward definitions and allocations
- **Suites Table** — measurement suite registrations
- **Budgets Table** — budget definitions with status controls
- **Events Table** — recent measurement events with time-bucketed display
- **Persisted Metrics Panel** — sparkline visualisations and privacy-tier counts

## Inputs / Outputs / Neighbors

- **Reads from:** measurement framework data stores (metrics, verifiers, rewards, suites, budgets), WordPress options, event logs
- **Writes to:** JSON export streams (`stream_export_json`), inline SVG sparkline elements
- **Upstream callers:** WordPress admin menu system
- **Downstream collaborators:** `includes/measurement/` (framework data layer)
- **Events fired:** `admin_post` handlers for export actions
- **Events listened to:** none

## Conventions

- The class extends no base class; it is a standalone admin page controller.
- All output is escaped: `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`.
- Privacy-tier classification uses tier labels (`code` elements) with inline formatting.
- Sparklines are rendered as inline SVG elements for zero-dependency visualisation.
- JSON export streams are triggered via `admin-post.php` with nonce verification.

## Tests

```bash
vendor/bin/phpunit tests/test-admin-measurement-dashboard.php
```

Coverage targets: menu registration, capability gating, nonce verification, table rendering, and JSON export.

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — security
- [`.context/rest-api.md`](../../.context/rest-api.md) — admin-post handler patterns
