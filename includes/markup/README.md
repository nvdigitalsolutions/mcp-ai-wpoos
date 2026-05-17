# Markup

## Purpose

Implements the **markup elicitation subsystem** — the MCP-spec workflow that lets a tool interrupt the agentic loop, hand the user an editable canvas widget, validate the W3C-Web-Annotation result, and resume execution with the validated markup data.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ |
| **Loaded by** | [`includes/markup-init.php`](../markup-init.php) — `require_once`s every class in this folder, registers the loop interceptor + telemetry on `plugins_loaded` (priority 20), the REST controller on `rest_api_init`, the admin pages + assets on `init`, and the `wp_mcp_ai_markup_cleanup` daily cron |
| **Optional dependencies** | none — the subsystem is enabled via `WP_MCP_AI_Markup_Loop_Interceptor::is_enabled()` and degrades to a no-op when disabled |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Markup_Aware_Tool_Interface` | `interface-wp-mcp-ai-markup-aware-tool.php` | Tools in [`includes/tools/`](../tools/) and Pro that elicit canvas markup (`needs_markup()`, `consume_markup()`) |
| `WP_MCP_AI_Markup_Request` | `class-wp-mcp-ai-markup-request.php` | Tools building an elicitation request; the loop interceptor; the store |
| `WP_MCP_AI_Markup_Result` | `class-wp-mcp-ai-markup-result.php` | The validator (produces it); markup-aware tools (consume it) |
| `WP_MCP_AI_Markup_Elicitation` | `class-wp-mcp-ai-markup-elicitation.php` | SSE frame builder for the `markup_elicitation` event |
| `WP_MCP_AI_Markup_Store` | `class-wp-mcp-ai-markup-store.php` | Transient-backed request storage; `consume()` on submit; daily cleanup cron |
| `WP_MCP_AI_Markup_Validator` | `class-wp-mcp-ai-markup-validator.php` | Validates W3C Web Annotation payloads on resume |
| `WP_MCP_AI_Markup_Rasterizer` | `class-wp-mcp-ai-markup-rasterizer.php` | Optional server-side raster of the annotated canvas |
| `WP_MCP_AI_Markup_Loop_Interceptor` | `class-wp-mcp-ai-markup-loop-interceptor.php` | Short-circuits the agentic loop before `execute()` when a tool requests markup |
| `WP_MCP_AI_Markup_REST_Controller` | `class-wp-mcp-ai-markup-rest-controller.php` | REST routes for fetching pending requests + submitting results |
| `WP_MCP_AI_Markup_Assets` | `class-wp-mcp-ai-markup-assets.php` | Registers + enqueues the chat-side widget assets |
| `WP_MCP_AI_Markup_Admin_Page` | `class-wp-mcp-ai-markup-admin-page.php` | URL-mode elicitation admin fallback |
| `WP_MCP_AI_Markup_Telemetry` | `class-wp-mcp-ai-markup-telemetry.php` | Outcome counters surfaced via the `/markup-stats` slash command + admin page |

## Inputs / Outputs / Neighbors

- **Reads from:** raw `$arguments` passed to a markup-aware tool's `needs_markup()`; pending-request transients (`wp_mcp_ai_markup_*`); the markup index option (`wp_mcp_ai_markup_index`); submitted W3C-Web-Annotation payloads via REST; current-user + assistant context
- **Writes to:** transients (pending requests, TTL-bounded with a per-assistant cap); the markup index option; orphan mask attachments (cleaned daily); telemetry counters; the recent-activity log (markup_* event types)
- **Upstream callers:** the agentic loop in [`includes/rest/class-wp-mcp-ai-rest-chat-controller.php`](../rest/class-wp-mcp-ai-rest-chat-controller.php) (via the interceptor); the chat client (via REST submit); WP-Cron (daily cleanup)
- **Downstream collaborators:** markup-aware tools in [`includes/tools/`](../tools/); the SSE handler in [`includes/rest/`](../rest/); the slash-command handler ([`/markup-stats` registered in markup-init.php`]); WordPress media library (mask attachments)
- **Events fired:** `wp_mcp_ai_markup_request_created`, `wp_mcp_ai_markup_result_submitted`, `wp_mcp_ai_markup_cleanup` (cron), plus telemetry-side events
- **Events listened to:** `plugins_loaded` (interceptor + telemetry register), `rest_api_init` (REST routes), `init` (admin pages, asset registration, cron schedule), `wp_mcp_ai_recent_activity_types` (extend the allowlist with `markup_*`), `wp_mcp_ai_default_slash_commands_loaded` (register `/markup-stats`)

## Conventions

Folder-specific deltas:

- Tools that participate MUST implement `WP_MCP_AI_Markup_Aware_Tool_Interface` and keep `needs_markup()` **deterministic and side-effect-free** — no DB writes, no API calls. The interceptor relies on idempotency.
- Pending requests are transient-backed with a TTL and a hard per-assistant cap (`WP_MCP_AI_Markup_Store::MAX_PER_ASSISTANT = 16`) — never persist markup state in a custom table.
- `consume()` MUST be replay-safe: the store deletes on read.
- Submitted markup MUST be validated through `WP_MCP_AI_Markup_Validator` before reaching `consume_markup()`; bypassing the validator is a security regression.
- Telemetry outcomes are an enumerated set (`WP_MCP_AI_Markup_Telemetry::outcomes()`); new outcomes must be added there so the recent-activity filter + slash command stay in sync.

## Tests

```bash
vendor/bin/phpunit tests/test-markup-elicitation.php
vendor/bin/phpunit tests/test-markup-loop-interceptor.php
vendor/bin/phpunit tests/test-markup-rasterizer.php
vendor/bin/phpunit tests/test-markup-rest.php
vendor/bin/phpunit tests/test-markup-settings-toggle.php
vendor/bin/phpunit tests/test-markup-stats-slash-command.php
vendor/bin/phpunit tests/test-markup-store.php
vendor/bin/phpunit tests/test-markup-telemetry.php
vendor/bin/phpunit tests/test-markup-telemetry-admin-page.php
vendor/bin/phpunit tests/test-markup-validator.php
```

Tool-side integration tests live alongside the markup-aware tools (e.g. `tests/test-tool-crop-image-markup.php`, `tests/test-tool-edit-gemini-image-markup.php`).

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — sanitise W3C payloads, escape on render (always)
- [`.context/tool-registry.md`](../../.context/tool-registry.md) — markup-aware tools still follow canonical envelope + sanitisation rules
- [`.context/rest-api.md`](../../.context/rest-api.md) — for the markup REST controller + SSE `markup_elicitation` frame
- [`.context/chat-ui.md`](../../.context/chat-ui.md) — chat-client widget integration shim

## See Also

- Sibling subsystems: [`includes/renderers/`](../renderers/) — server-produced HTML rendering, distinct from this folder which mediates *user-produced* markup
- Consumers: markup-aware tools in [`includes/tools/`](../tools/) (e.g. `crop_image`, `edit_gemini_image`, `edit_openai_image`)
- Spec references: MCP Elicitation (2025-06-18 / 2025-11-25), W3C Web Annotation Data Model
- Bootstrap: [`includes/markup-init.php`](../markup-init.php)
