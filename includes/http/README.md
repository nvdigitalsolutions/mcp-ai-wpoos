# http/

## Purpose

Symfony-HttpClient-backed outbound HTTP primitive for *external* API calls — provides retry, streaming (SSE), and timeout handling without disturbing WordPress's loopback / local-AI HTTP path.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ (see [`CLAUDE.md`](../../CLAUDE.md)) |
| **Loaded by** | Symfony autoloader (the class lives in the `WP_MCP_AI\Http` namespace and is bundled via Composer); resolved through the service container or `WP_MCP_AI_Http_Client_Service::get_instance()` |
| **Optional dependencies** | `symfony/http-client` (vendored, always available). For SSL/proxy quirks on WordPress loopback or local-AI providers (Ollama, LM Studio), callers must continue to use `wp_remote_*` instead. |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI\Http\WP_MCP_AI_Http_Client_Service` | `class-wp-mcp-ai-http-client-service.php` | provider clients for streaming endpoints, integrations needing SSE, Symfony-based REST consumers |
| `::get_instance()` | same file | singleton accessor |
| `get()`, `post()`, `request()`, `stream()` | same file | request methods; all return `array{status,headers,body}` or `WP_Error` |

The companion `WP_MCP_AI_HTTP` class (parent `includes/`) and `WP_MCP_AI_HTTP_Helper` remain the canonical primitives for `wp_remote_*`-style work; this folder is reserved for the Symfony client only.

## Inputs / Outputs / Neighbors

- **Reads from:** Caller-supplied URL + Symfony HttpClient request options (`json`, `body`, `headers`, `timeout`, `max_redirects`).
- **Writes to:** Network sockets via Symfony HttpClient; no persistent storage. On transport failure returns a `WP_Error` envelope with `http_client_transport_error` / `http_client_error`.
- **Upstream callers:** [`includes/integrations/`](../integrations/) (Mailjet, Cloudways, Cloudflare, GitHub when streaming), Crawl4AI poller, provider clients that need SSE streaming, MCP REST routes that proxy upstream MCP servers.
- **Downstream collaborators:** `Symfony\Component\HttpClient\HttpClient`, `RetryableHttpClient` (3 retries on 5xx / timeouts), `Symfony\Contracts\HttpClient\HttpClientInterface`.
- **Events fired:** None — this folder is intentionally a thin primitive.
- **Events listened to:** None.

## Conventions

- **Outbound-only.** Never use this for WordPress loopback (cron spawns, REST self-calls) or for local-AI requests where WordPress HTTP filters, `WP_HTTP_BLOCK_EXTERNAL`, proxy settings, or per-host SSL overrides must apply — use `wp_remote_*` (or `WP_MCP_AI_HTTP`/`WP_MCP_AI_HTTP_Helper`) for those.
- All public methods return the canonical envelope: success → `array{status:int,headers:array,body:string}`, failure → `WP_Error`. Do not throw across the public surface.
- `stream()` callback signature is `function( string $chunk, bool $first, bool $last ): bool|void` — return `false` to abort.
- Default timeout: 30 s. Default retries: 3. Override per-request via Symfony options, not by editing the constants — this keeps the singleton safe across concurrent callers.
- User-Agent is `WP-MCP-AI/<version>`; callers should not override it unless impersonating a specific upstream-required UA.

## Tests

```bash
vendor/bin/phpunit tests/test-http-client-service.php
vendor/bin/phpunit tests/test-http.php
vendor/bin/phpunit tests/test-http-helper.php
vendor/bin/phpunit tests/test-http-helper-network-interface.php
vendor/bin/phpunit tests/test-http-logging.php
vendor/bin/phpunit tests/test-wp-http-client.php
```

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming, style, PHP compat (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — URL validation, SSRF / private-network blocking (always)
- [`.context/rest-api.md`](../../.context/rest-api.md) — when an inbound REST route proxies through this client

## See Also

- Companion HTTP primitives in parent `includes/`: `class-wp-mcp-ai-http.php`, `class-wp-mcp-ai-http-helper.php`, `class-wp-mcp-ai-retry-strategy.php`
- WordPress-loopback adapter: [`includes/infrastructure/http/`](../infrastructure/) (`WP_MCP_AI_WP_HTTP_Client`)
- Sibling primitives: [`includes/cache/`](../cache/), [`includes/filesystem/`](../filesystem/)
