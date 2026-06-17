# Exporters

## Purpose

Houses the OpenTelemetry JSON exporter — a dependency-free bridge that serializes buffered metric events into an OTLP/JSON-shaped payload suitable for ingestion by an OpenTelemetry Collector, without requiring the opentelemetry-php SDK.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ |
| **Loaded by** | measurement bootstrap; exporter is instantiated on demand when OTel export is enabled |
| **Optional dependencies** | OpenTelemetry Collector / OTLP endpoint (external, not required for plugin operation) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_OTel_Exporter` | `class-wp-mcp-ai-otel-exporter.php` | Measurement bootstrap, admin dashboard, any OTel subscriber |

## Inputs / Outputs / Neighbors

- **Reads from:** `WP_MCP_AI_Metric_Collector` (buffered events via `buffered()` and `export()`), `WP_MCP_AI_Measurement_Registry` (metric definitions).
- **Writes to:** OTLP/JSON payload (fired via `wp_mcp_ai_otel_payload_ready` action), rolling persistent buffer in the `wp_mcp_ai_otel_rolling_buffer` option (non-autoloaded, size-capped at 512 events by default).
- **Upstream callers:** measurement bootstrap, admin dashboard, transport subscribers on `wp_mcp_ai_otel_payload_ready`.
- **Downstream collaborators:** `WP_MCP_AI_Metric_Collector`, `WP_MCP_AI_Measurement_Registry`, WordPress options API.
- **Events fired:** `wp_mcp_ai_otel_payload_ready` (after payload is built).
- **Events listened to:** `wp_mcp_ai_otel_payload` (filter — modify payload before dispatch), `wp_mcp_ai_otel_redact` (filter — redact or drop individual events), `wp_mcp_ai_otel_buffer_max` (filter — rolling buffer size).

## Conventions

- Deliberately SDK-free: shipping `opentelemetry-php` in a WordPress plugin would add weight and a PHP 8+ floor. This exporter produces the standard OTLP/JSON shape so an external Collector can consume it.
- The exporter guarantees no raw prompts, tool arguments, or user content in payloads — only sanitized collector context.
- The `wp_mcp_ai_otel_redact` filter allows operators to strip or hash values per event before serialization. Returning `null` drops the event entirely.
- The rolling buffer is size-capped, non-autoloaded, and redacts before storage.
- Transport is the operator's choice — the exporter builds the payload, fires the hook, and stops. Subscribe to `wp_mcp_ai_otel_payload_ready` to ship.

## Tests

```bash
vendor/bin/phpunit tests/measurement/
```

OTel exporter coverage is part of the measurement test suite.

## Also Load

- [`.context/conventions.md`](../../../.context/conventions.md) — naming, style, PHP compat (always)
- [`.context/security-checklist.md`](../../../.context/security-checklist.md) — redaction, no PII in exported payloads (always)
- Parent folder: [`includes/measurement/README.md`](../README.md) — full measurement layer overview

## See Also

- Upstream parent: [`includes/measurement/`](../) — measurement layer
- Collector: [`includes/measurement/class-wp-mcp-ai-metric-collector.php`](../class-wp-mcp-ai-metric-collector.php)
- Registry: [`includes/measurement/class-wp-mcp-ai-measurement-registry.php`](../class-wp-mcp-ai-measurement-registry.php)
- Pro counterpart: `addons/pro/includes/measurement/` (OTel subscribers)
