# Observability

## Purpose

Exports the plugin's trace spans to an **OTLP/HTTP** collector — the OpenTelemetry
wire protocol — so tool calls, chat turns and REST requests show up in whatever
backend the operator already runs (Grafana Tempo, Jaeger, Honeycomb, …).

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 8.1+ |
| **Loaded by** | `includes/bootstrap/loader.php` (before `rest_api_init`) |
| **Optional dependencies** | none — inactive until an endpoint is configured |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_OTLP_Exporter` | `class-wp-mcp-ai-otlp-exporter.php` | `includes/bootstrap/loader.php`, span emitters |

### `WP_MCP_AI_OTLP_Exporter`

```php
WP_MCP_AI_OTLP_Exporter::is_active();          // false unless configured
WP_MCP_AI_OTLP_Exporter::enqueue( $payload );  // buffer one span
WP_MCP_AI_OTLP_Exporter::flush();              // send the buffer
```

Constants worth knowing:

| Constant | Value |
|---|---|
| `OPTION_ENDPOINT` | `wp_mcp_ai_otlp_endpoint` — collector URL |
| `MAX_BATCH` | `50` spans per flush |

- **Inactive by default.** `is_active()` is false until the endpoint option or
  the `wp_mcp_ai_otlp_endpoint` filter yields a valid URL, so an install with no
  collector pays nothing.
- **Endpoint is validated.** `validate_endpoint()` resolves the host and applies
  `wp_mcp_ai_otlp_allowed_hosts`, so a misconfigured option cannot be pointed at
  an arbitrary internal service.
- **Batch, then flush.** `enqueue()` buffers up to `MAX_BATCH` spans; `build_batch()`
  and `format_span()` are pure and unit-tested.

## Inputs / Outputs / Neighbors

- **Reads from:** option `wp_mcp_ai_otlp_endpoint`.
- **Writes to:** the collector over HTTPS. No local storage.
- **Upstream callers:** span emitters in the chat/tool/REST paths.
- **Downstream collaborators:** none inside the plugin.
- **Events fired:** none.
- **Events listened to:** none — callers push spans in explicitly.

## Conventions

- Every method is static; the exporter holds no per-request object state beyond
  the span buffer (`reset()` clears it, which is what the tests use).
- Batching is bounded by `MAX_BATCH`; a caller cannot grow the buffer without
  bound. A flush that fails must not throw into the request path.
- Endpoint validation belongs here, not at the call sites.

## Tests

```bash
bash bin/run-tests-docker.sh tests/test-otlp-exporter.php
```

Covers span formatting, batch shaping, the inactive default, and endpoint
validation.

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming, style (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — security (always)
- [`.context/rest-api.md`](../../.context/rest-api.md) — where the request spans originate

## See Also

- Upstream parent: [`includes/`](../)
- Sibling: [`../security/`](../security/) — audit logging, the other observability leg
