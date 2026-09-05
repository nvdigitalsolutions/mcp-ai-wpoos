# Rest

## Purpose

Wave E2 REST surface. `JobNotifierRestController` is an aligned port of
the base plugin's `WP_MCP_AI_Job_Notifier_REST`: byte-identical
namespace/routes (`mcp-ai/v1` jobs stream, status, and webhook
registration), parameter schemas, permission callbacks, error codes
(`job_not_found`, `unauthorized`, `wp_mcp_ai_auth_unavailable`,
`wp_mcp_ai_missing_credentials`, `rest_invalid_nonce`, `rest_forbidden`),
the entity-ownership authorization model, and the dot-preserving
`sanitize_job_id()`. `SseStream` is the aligned port of
`WP_MCP_AI_SSE_Stream`: byte-identical duration/poll/heartbeat constants
and filters, the buffered `stream_job_status()` polling loop with its
connected/status/complete/timeout/close framing, heartbeat comments,
the `wp_mcp_ai_sse_stream_started|chunk_sent|ended` notification hooks,
and the message/comment/backpressure/typed-event helpers.

## Tier

| | |
|---|---|
| **Distribution** | Platform addon (`nvoos-content-graph-ai-platform`) — proprietary |
| **PHP target** | 8.1+ |
| **License** | Proprietary (commercial license required) |
| **Loaded by** | `NvoosContentGraphAiPlatform\Plugin::register()` — standalone-only (`! defined('WP_MCP_AI_PATH')`) for the controller; `SseStream` is a static utility consumed by the controller |
| **Optional dependencies** | Base request authenticator (monolith), job store (dormant), security manager (dormant standalone) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraphAiPlatform\Rest\JobNotifierRestController` | `JobNotifierRestController.php` | `Plugin::registerJobNotifierRest()` — `rest_api_init` route registration |
| `NvoosContentGraphAiPlatform\Rest\SseStream` | `SseStream.php` | `JobNotifierRestController::handle_job_stream()` — buffered status stream |

## Inputs / Outputs / Neighbors

- **Reads from:** job-status transients (`wp_mcp_ai_job_status_*`) and
  the webhook option (`wp_mcp_ai_job_webhooks`) via the per-mode notifier
  (base `WP_MCP_AI_Job_Notifier` monolith /
  `Queues\JobNotifier` standalone); `SseStream` reads
  `wp_mcp_ai_settings['cors_allow_origin']` and the
  `wp_mcp_ai_cors_allow_origin` filter
- **Writes to:** SSE response bodies (buffered, returned — never echoed),
  webhook registrations via the notifier
- **Upstream callers:** chat clients polling job progress
- **Downstream collaborators (dormant until their waves):** request
  authenticator (no standalone port — nonce auth works in both modes,
  token/mesh branches degrade with `wp_mcp_ai_auth_unavailable`),
  security manager (monolith headers only), job store (transient cache
  authoritative standalone)

## Conventions

- Standalone-only controller registration — the base plugin owns the
  same routes in monolith installs.
- Byte-identical routes/error codes/envelopes with the base; deviations
  documented in the class docblocks (auth degradation standalone,
  security headers monolith-only, text domain).
- Protected seams (`notifier_class()`, `sse_stream_class()`,
  `cors_allow_origin_setting()`, `security_headers()`) let tests
  exercise per-mode resolution without global stubs.

## Also Load

- [`../../../../.context/conventions.md`](../../../../.context/conventions.md) — naming + style
- [`../../../../.context/security-checklist.md`](../../../../.context/security-checklist.md) — escaping + auth
- [`../../../../.context/rest-api.md`](../../../../.context/rest-api.md) — REST endpoint conventions

## See Also

- Neighbor: [`../Queues/`](../Queues/) — notifier + cron/DLQ collaborators
- Bootstrap wiring: [`../Plugin.php`](../Plugin.php)
- Tracker: `docs/project/ecosystem-port-tracker.md` (Wave E2 row)
