# Chat Channels — Webhook Handler Infrastructure

## Purpose

Houses the Google Chat webhook handler that receives, verifies, and dispatches incoming webhook events from the Google Chat API — and nothing else.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | `addons/pro/includes/chat-channels-toolkit-init.php` — registers the REST route on `rest_api_init` |
| **Optional dependencies** | none — this is a self-contained webhook receiver; the full `WP_MCP_AI_Google_Chat_Webhook_Controller` (with OIDC token verification) supersedes this legacy handler when configured |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Google_Chat_Webhook_Handler` | `class-wp-mcp-ai-google-chat-webhook-handler.php` | `chat-channels-toolkit-init.php`, `test-chat-channels.php` |

This is the **legacy** handler. The Pro addon also ships `WP_MCP_AI_Google_Chat_Webhook_Controller` (with proper OIDC token verification) in a sibling folder. When the full controller is available, this legacy handler is **not registered** and the `wp_mcp_ai_verify_google_chat_legacy_webhook` filter is never called.

## Inputs / Outputs / Neighbors

- **Reads from:** incoming `POST /wp-json/mcp-ai/v1/webhooks/google-chat` requests with Google Chat event payloads (MESSAGE, ADDED_TO_SPACE, REMOVED_FROM_SPACE, CARD_CLICKED).
- **Writes to:** `WP_REST_Response` JSON bodies (HTTP 200 within Google's 5-second timeout); the WP MCP AI event log via `WP_MCP_AI_Logger::log_event()`.
- **Upstream callers:** Google Chat platform (external webhook delivery), WordPress REST API.
- **Downstream collaborators:** `WP_MCP_AI_Logger` (event logging), consumer code hooked to `wp_mcp_ai_google_chat_message`, `wp_mcp_ai_google_chat_message_in_space`, `wp_mcp_ai_google_chat_message_in_dm`, `wp_mcp_ai_google_chat_added_to_space`, `wp_mcp_ai_google_chat_removed_from_space`, `wp_mcp_ai_google_chat_card_clicked`.
- **Events fired:** the six action hooks listed above; `wp_mcp_ai_verify_google_chat_legacy_webhook` filter for permission-callback customization.
- **Events listened to:** `rest_api_init` (route self-registration).

## Conventions

- The legacy handler uses a simple permission-callback filter instead of OIDC token verification. The `wp_mcp_ai_verify_google_chat_legacy_webhook` filter defaults to `true` (allow all) so existing installs are not broken. Site owners SHOULD hook this filter to add signature verification.
- Always return HTTP 200 with a JSON `{ "text": "..." }` body — Google Chat requires this within 5 seconds. An empty `"text"` key suppresses visible replies.
- `argumentText` from the Google Chat payload is the canonical clean message text (the app @mention is already stripped). Only fall back to regex-based `strip_mention_markup()` when `argumentText` is absent.
- Do **not** add new event types here for non-Google-Chat platforms — each chat platform gets its own handler/controller.

## Tests

```bash
vendor/bin/phpunit tests/test-chat-channels.php
vendor/bin/phpunit tests/test-chat-channels-admin-pages.php
vendor/bin/phpunit tests/test-chat-channel-test-handlers.php
```

## Also Load

- [`.context/conventions.md`](../../../../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../../../../.context/security-checklist.md) — REST permission callbacks, webhook verification (always)
- [`.context/rest-api.md`](../../../../../.context/rest-api.md) — namespace + route conventions
- [`.context/pro-vs-base.md`](../../../../../.context/pro-vs-base.md) — Pro placement
- [`CLAUDE.md`](../../../../../CLAUDE.md) — PHP-compat (8.1+)

## See Also

- Sibling: [`../Tools/ChatChannels/`](../Tools/ChatChannels/) — 48 chat-channel tool implementations (send, get, create, manage)
- Parent folder: [`addons/pro/includes/src/`](../) — Pro source namespace root
- Pro full controller: `WP_MCP_AI_Google_Chat_Webhook_Controller` (sibling in a parallel folder)
