# HTTP

## Purpose

Houses the single WordPress HTTP client adapter that wraps `wp_remote_get`, `wp_remote_post`, and streaming into an injectable `Interface_WP_MCP_AI_HTTP_Client` contract so consumer classes stay WordPress-agnostic and testable.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ |
| **Loaded by** | DI container via `includes/class-wp-mcp-ai-container.php` |
| **Optional dependencies** | none |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_WP_HTTP_Client` | `class-wp-mcp-ai-wp-http-client.php` | DI container → every provider client in `infrastructure/providers/`, services that fetch external resources |

## Inputs / Outputs / Neighbors

- **Reads from:** filter hooks `wp_mcp_ai_http_client_get_args`, `wp_mcp_ai_http_client_post_args`, `wp_mcp_ai_http_client_stream_args`.
- **Writes to:** WordPress HTTP API (`wp_remote_get`, `wp_remote_post`); streaming responses split on newlines and delivered via callback.
- **Upstream callers:** `infrastructure/providers/` (every provider client), `services/` (any service calling external APIs).
- **Downstream collaborators:** WordPress HTTP API; `esc_url_raw()` for URL sanitisation.
- **Events fired:** none directly; callers interpret `WP_Error` or response arrays.
- **Events listened to:** none (imperative invocation).

## Conventions

- This folder contains exactly one class — the WordPress HTTP adapter. If another adapter is needed (e.g. for a different transport), it belongs here too but must implement `Interface_WP_MCP_AI_HTTP_Client`.
- All public methods (`get`, `post`, `stream`) accept a `$url` and `$args` array and return `array|WP_Error` — consistent with WordPress HTTP API conventions.
- Streaming is implemented by reading the full buffered response body and splitting on newlines for SSE compatibility; the `stream` option in WordPress API writes to a temp file for memory efficiency.
- Default timeout is 30 seconds (`DEFAULT_TIMEOUT` constant), overridable via `$args['timeout']`.

## Tests

```bash
vendor/bin/phpunit tests/test-wp-http-client.php
vendor/bin/phpunit tests/test-http-helper-network-interface.php
```

## Also Load

- [`.context/conventions.md`](../../../.context/conventions.md) — naming, style, PHP compat (always)
- [`.context/security-checklist.md`](../../../.context/security-checklist.md) — outbound HTTP, URL sanitisation (always)
- Parent folder: [`includes/infrastructure/README.md`](../README.md) — full infrastructure layer overview

## See Also

- Upstream parent: [`includes/infrastructure/`](../) — infrastructure adapters layer
- Interface: [`includes/interfaces/interface-wp-mcp-ai-http-client.php`](../../interfaces/interface-wp-mcp-ai-http-client.php)
- DI wiring: [`includes/class-wp-mcp-ai-container.php`](../../class-wp-mcp-ai-container.php)
