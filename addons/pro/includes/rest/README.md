# Pro REST

## Purpose

Implements the Pro-only REST controllers — chat-channel webhooks (Apple Messages, Discord, Google Chat, iCloud, Messenger, Outlook, Slack events, Teams, Telegram, Twitter, WhatsApp), chat-channels inbox dashboard, ECA management CRUD, NV oOS Cloud admin, Pro schedule results, skill catalogue + manager, and the Extended Cognition sensor bridge.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | Each toolkit's `*-toolkit-init.php` requires its controller and either calls `new Controller()` (whose constructor hooks `rest_api_init`) or registers an explicit `wp_mcp_ai_*_register_rest` callback. Examples: [`chat-channels-toolkit-init.php`](../chat-channels-toolkit-init.php), [`eca-management-init.php`](../eca-management-init.php), [`extended-cognition-toolkit-init.php`](../extended-cognition-toolkit-init.php), [`nv-cloud-init.php`](../nv-cloud-init.php), [`google-chat-webhook-init.php`](../google-chat-webhook-init.php) |
| **Optional dependencies** | The third-party platform whose webhook the controller terminates (Telegram Bot API, Slack Events API, Discord interactions, WhatsApp Cloud API, Google Chat, Apple Messages for Business, Outlook Graph webhooks, iCloud CalDAV, Twitter API v2, Microsoft Teams Bot Framework); JetEngine for channel CCT helpers; NV oOS Cloud SaaS for billing endpoints |

## Public Surface

Routes split across **two namespaces** — Pro chose namespace per controller based on whether the route is platform-owned (webhooks reuse `mcp-ai/v1` for back-compat) or genuinely Pro-only (`mcp-ai-pro/v1`). Clients consume the route URL, not the controller class.

| Symbol | File | Namespace | Routes / Purpose |
|---|---|---|---|
| `WP_MCP_AI_Chat_Channels_REST_Controller` | `class-wp-mcp-ai-chat-channels-rest-controller.php` | `mcp-ai-pro/v1` | Chat-channels inbox dashboard: conversations, messages, replies, tags, takeover |
| `WP_MCP_AI_Skill_Catalogue_REST_Controller` | `class-wp-mcp-ai-skill-catalogue-rest-controller.php` | `mcp-ai-pro/v1` | Skill-catalogue sources: list, skills, install, refresh |
| `WP_MCP_AI_Skill_Manager_REST_Controller` | `class-wp-mcp-ai-skill-manager-rest-controller.php` | `mcp-ai-pro/v1` | Skill bundle CRUD + activation |
| `WP_MCP_AI_Pro_Schedule_Result_Controller` | `class-wp-mcp-ai-pro-schedule-result-controller.php` | `mcp-ai-pro/v1` | Schedule run results, history, rendering |
| `WP_MCP_AI_REST_NV_Cloud_Controller` | `class-wp-mcp-ai-nv-cloud-rest-controller.php` | `mcp-ai-pro/v1` | NV oOS Cloud connect / disconnect / balance / ledger / top-up |
| `WP_MCP_AI_Apple_Messages_Webhook_Controller` | `class-wp-mcp-ai-apple-messages-webhook-controller.php` | `mcp-ai/v1` | Apple Messages for Business webhook |
| `WP_MCP_AI_Discord_Interaction_Controller` | `class-wp-mcp-ai-discord-interaction-controller.php` | `mcp-ai/v1` | Discord interactions (slash + components) |
| `WP_MCP_AI_Google_Chat_Webhook_Controller` | `class-wp-mcp-ai-google-chat-webhook-controller.php` | `mcp-ai/v1` | Google Chat events (Spaces, DMs, cards) |
| `WP_MCP_AI_iCloud_Webhook_Controller` | `class-wp-mcp-ai-icloud-webhook-controller.php` | `mcp-ai/v1` | iCloud Drive change webhooks |
| `WP_MCP_AI_Messenger_Webhook_Controller` | `class-wp-mcp-ai-messenger-webhook-controller.php` | `mcp-ai/v1` | Facebook Messenger webhook |
| `WP_MCP_AI_Outlook_Webhook_Controller` | `class-wp-mcp-ai-outlook-webhook-controller.php` | `mcp-ai/v1` | Outlook / Microsoft Graph change notifications |
| `WP_MCP_AI_Slack_Event_Controller` | `class-wp-mcp-ai-slack-event-controller.php` | `mcp-ai/v1` | Slack Events API + Block Kit |
| `WP_MCP_AI_Teams_Webhook_Controller` | `class-wp-mcp-ai-teams-webhook-controller.php` | `mcp-ai/v1` | Microsoft Teams Bot Framework webhook |
| `WP_MCP_AI_Telegram_Webhook_Controller` | `class-wp-mcp-ai-telegram-webhook-controller.php` | `mcp-ai/v1` | Telegram Bot webhook |
| `WP_MCP_AI_Telegram_Login_Controller` | `class-wp-mcp-ai-telegram-login-controller.php` | `mcp-ai/v1` | Telegram Login Widget callback |
| `WP_MCP_AI_Telegram_Mini_App_Controller` | `class-wp-mcp-ai-telegram-mini-app-controller.php` | `mcp-ai/v1` | Telegram Mini App host + templates |
| `WP_MCP_AI_Telegram_Mini_App_Template_Registry` (+ template base / default) | `class-wp-mcp-ai-telegram-mini-app-templates.php` | n/a | Helper registry consumed by the Mini App controller |
| `WP_MCP_AI_Twitter_Webhook_Controller` | `class-wp-mcp-ai-twitter-webhook-controller.php` | `mcp-ai/v1` | Twitter / X webhook |
| `WP_MCP_AI_WhatsApp_Webhook_Controller` | `class-wp-mcp-ai-whatsapp-webhook-controller.php` | `mcp-ai/v1` | WhatsApp Cloud API webhook |
| `WP_MCP_AI_ECA_REST_Controller` | `class-wp-mcp-ai-eca-rest-controller.php` | `mcp-ai/v1` | ECA + student CRUD |
| `WP_MCP_AI_Ext_Cog_REST` | `class-wp-mcp-ai-ext-cog-rest.php` | `mcp-ai/v1` | Extended Cognition sensor queue + data + permissions |

## Inputs / Outputs / Neighbors

- **Reads from:** `WP_REST_Request` (JSON / multipart / signed-form bodies, query, headers); third-party signature headers (HMAC for Slack / WhatsApp / Discord / Telegram / Google OIDC for Google Chat); the credential vault; assistant CPT meta; channel CCTs (`WP_MCP_AI_Channel_Contacts_CCT`, `WP_MCP_AI_Channel_Messages_CCT`); the Pro remote-site manager
- **Writes to:** `WP_REST_Response` (often within the platform's hard timeout — Google Chat 5s, Discord 3s); channel message/contact CCTs; outbound platform Cloud APIs via the remote-site manager; cron-scheduled reply jobs; the audit log
- **Upstream callers:** third-party platforms (webhook deliveries), the chat-channels inbox UI in [`addons/pro/includes/admin/`](../admin/), the NV oOS Cloud admin page, the Telegram Mini App in the browser, external MCP clients
- **Downstream collaborators:** [`addons/pro/includes/tools/`](../tools/) + [`addons/pro/includes/src/Tools/ChatChannels/`](../src/Tools/ChatChannels/) (outbound sends), [`addons/pro/includes/services/`](../services/), [`addons/pro/includes/class-wp-mcp-ai-pro-remote-site-manager.php`](../class-wp-mcp-ai-pro-remote-site-manager.php), [`addons/pro/includes/providers/`](../providers/), Base [`includes/class-wp-mcp-ai-logger.php`](../../../../includes/class-wp-mcp-ai-logger.php)
- **Events fired:** per-channel event hooks (`wp_mcp_ai_chat_channels_message_received`, `wp_mcp_ai_*_reply_job_scheduled`), `wp_mcp_ai_pro_schedule_*` for schedule-result reads, `wp_mcp_ai_nv_cloud_*` for cloud transactions
- **Events listened to:** `rest_api_init` (each controller's `register_routes` runs here), platform-specific cron handlers (`wp_mcp_ai_*_reply_job`), `wp_mcp_ai_nv_cloud_balance_refresh`

## Conventions

Folder-specific deltas (canonical rules in [`.context/rest-api.md`](../../../../.context/rest-api.md)):

- Pro REST controllers **do not** currently extend Base's `WP_MCP_AI_REST_Controller_Base`; they extend `WP_REST_Controller` (or are plain classes) and supply their own `$namespace` / `NAMESPACE_V1` constant. Because they bypass the Base, each controller **MUST** add security headers, rate-limit hooks, and structured-logging calls explicitly.
- New CRUD-style Pro endpoints SHOULD prefer the **`mcp-ai-pro/v1`** namespace. Webhook endpoints that already ship in production keep their existing `mcp-ai/v1` namespace for back-compat — do not rename them without a migration plan.
- Webhook signature verification belongs in `permission_callback`, not the handler. Use the platform-native scheme (Slack signing secret, Telegram `secret_token`, WhatsApp `X-Hub-Signature-256`, Google OIDC, Discord Ed25519, etc.). Never `__return_true`.
- Long-running replies MUST be deferred to WP-Cron jobs so the controller returns within the platform's webhook timeout. Reply jobs lazy-load the remote-site manager via `require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php'`.
- Capability-protected routes (NV oOS Cloud, skill manager, inbox replies) require `manage_options` or the matching toolkit capability; never accept anonymous mutations.

## Tests

```bash
vendor/bin/phpunit addons/pro/tests/test-pro-schedule-result-rest.php
vendor/bin/phpunit addons/pro/tests/test-pro-schedule-result-envelope.php
vendor/bin/phpunit addons/pro/tests/test-pro-schedule-webhook-hmac.php
vendor/bin/phpunit addons/pro/tests/test-eca-cpt.php
vendor/bin/phpunit addons/pro/tests/test-nv-cloud.php
vendor/bin/phpunit addons/pro/tests/test-remote-site-manager.php
vendor/bin/phpunit addons/pro/tests/test-remote-sites-admin.php
vendor/bin/phpunit addons/pro/tests/test-toolkit-server-contract.php
```

Cross-cutting REST infrastructure tests (Base authenticator, controller base, validator) live in the root suite under [`tests/rest/`](../../../../tests/rest/) and [`tests/rest-api/`](../../../../tests/rest-api/).

## Also Load

- [`.context/conventions.md`](../../../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../../../.context/security-checklist.md) — capability / nonce / sanitisation / signature rules (always)
- [`.context/rest-api.md`](../../../../.context/rest-api.md) — canonical REST patterns, auth modes, error envelope
- [`.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md) — when to add to Pro REST vs Base REST
- [`.context/chat-ui.md`](../../../../.context/chat-ui.md) — chat-channels inbox + webhook reply flow
- [`docs/reference/rest-api.md`](../../../../docs/reference/rest-api.md) — operator-facing endpoint reference

## See Also

- Base counterpart: [`includes/rest/`](../../../../includes/rest/) — chat, tools, MCP, SSE, A2A, approvals, teams, workflows, transcript mining
- Sibling surfaces: [`addons/pro/includes/cli/`](../cli/), [`addons/pro/includes/slash-commands/`](../slash-commands/)
- Collaborators: [`addons/pro/includes/services/`](../services/), [`addons/pro/includes/providers/`](../providers/), [`addons/pro/includes/mcp-servers/`](../mcp-servers/), [`addons/pro/includes/class-wp-mcp-ai-pro-remote-site-manager.php`](../class-wp-mcp-ai-pro-remote-site-manager.php), [`addons/pro/includes/src/ChatChannels/`](../src/ChatChannels/)
