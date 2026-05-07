# Pro Toolkit SPA Manifests

This directory hosts JSON manifests consumed by the
[`addons/toolkit-shell/`](../../../toolkit-shell/) addon. Each file describes
one Pro toolkit's resources, fields, and views. The shell renders these
manifests as React surfaces, talking to the toolkit's existing
`/wp-json/mcp-ai-pro/v1/*` endpoints — no parallel data plane.

## Adding a manifest

1. Create `<toolkit-slug>.json` in this directory. The filename **is** the
   toolkit slug; the shell uses it instead of the JSON `toolkit` field, so
   you cannot spoof.
2. Follow the schema documented in
   [`docs/addons/toolkit-spa-blueprint.md`](../../../../docs/addons/toolkit-spa-blueprint.md)
   §11.
3. Validate locally — manifests with no resources, an unknown `field.type`,
   or a malformed `rest_namespace` are dropped silently by the registry.
4. Test by adding `[nvoos_toolkit_app toolkit="<slug>"]` to a page.

## Current manifests

| File | Toolkit | Status |
|------|---------|--------|
| `crm.json` | CRM | Reference implementation |
| `calendar-booking.json` | Calendar & Booking | Tier A — table + kanban (status) |
| `financial-planner.json` | Financial Planner | Tier A — accounts + transactions |
| `regulatory-registration.json` | Regulatory | Tier A — table + kanban (status) |
| `analytics.json` | Analytics | Tier A — reports + metrics (read) |
| `law-firm.json` | Law Firm | Tier A — matters + documents + kanban |
| `cre-debt.json` | CRE Debt | Tier A — loans + payments + pipeline |
| `multilingual.json` | Multilingual | Tier A — strings + translations + workflow |
| `ecommerce.json` | E-commerce | Tier A — products + orders (kanban) + customers |
| `social-media.json` | Social Media | Tier A — posts + calendar view + campaigns |

Specialist toolkits (architectural-design, ai-tool-builder, document-generation,
healthcare-imaging, video-production, etc.) ship as **separate** addons rather
than manifests — see the blueprint Tier B/C/D tables.
