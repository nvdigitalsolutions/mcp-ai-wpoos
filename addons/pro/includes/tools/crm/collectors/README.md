# CRM Collectors

Signal collection entry points for the CRM toolkit.

## Purpose

Own-site signal collection lives in `WP_MCP_AI_CRM_Signals::collect_own_site()`.
This folder provides the stable collector facade used by tools and
integrations, with a filter seam for provider-backed collectors.

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_CRM_Signal_Collector` | `class-wp-mcp-ai-crm-signal-collector.php` | `collect_prospect_signals`, integrations |

## Neighbours

- `../class-wp-mcp-ai-crm-signals.php` — catalogue, observations, cohorts.
- `../class-wp-mcp-ai-crm-signals-db.php` — indexed observation table.

## Also Load

- [`../README.md`](../README.md)
- [`../../../../.context/conventions.md`](../../../../.context/conventions.md)
