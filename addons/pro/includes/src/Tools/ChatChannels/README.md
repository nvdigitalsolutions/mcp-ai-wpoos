# Chat Channel Tool Implementations

## Purpose

48 Pro tools for reading from and writing to chat/communication platforms — Discord, Google Chat, Telegram, WhatsApp, Messenger, Slack, Teams, Twitter/X, Apple Messages, iCloud Drive, and OneDrive — plus a unified cross-channel broadcast tool and a shared Google Service Account helper.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | `addons/pro/includes/chat-channels-toolkit-init.php` — registers all 48 tools with the global tool registry when the Chat Channels toolkit flag is enabled |
| **Optional dependencies** | each platform's API credentials (bot tokens, webhook URLs, service account JSON) — tools self-report `is_available()` as `true` (credentials are validated at execution time, not registration time) |

## Public Surface

All tools implement `WP_MCP_AI_Tool_Interface` + `WP_MCP_AI_Tool_Capability_Flags_Interface`. Grouped by platform:

| Platform | Read / Get tools | Send / Create tools | Manage tools | Count |
|---|---|---|---|---|
| **Discord** | `get_discord_messages`, `get_discord_channels`, `get_discord_voice_channel_members` | `send_discord_message`, `add_discord_message_reaction` | `create_discord_channel` | 6 |
| **Google Chat** | `get_google_chat_messages`, `get_google_chat_spaces`, `list_google_chat_space_members` | `send_google_chat_message` | `create_google_chat_space`, `add_google_chat_space_member`, `remove_google_chat_space_member` | 7 |
| **Telegram** | `get_telegram_updates` | — | `add_telegram_message_reaction`, `manage_telegram_commands`, `manage_telegram_webhook` | 4 |
| **WhatsApp** | `get_whatsapp_messages` | `send_whatsapp_message`, `send_whatsapp_interactive`, `send_whatsapp_media`, `send_whatsapp_template` | — | 5 |
| **Messenger** | `get_messenger_conversations` | `send_messenger_message` | `create_messenger_broadcast` | 3 |
| **Slack** | `get_slack_messages`, `get_slack_channels` | `send_slack_message` | `create_slack_channel` | 4 |
| **Teams** | `get_teams_messages`, `get_teams_channels` | `send_teams_message` | — | 3 |
| **Twitter/X** | `get_twitter_dms` | `send_twitter_dm` | `manage_twitter_webhook` | 3 |
| **Apple Messages** | `get_apple_messages` | `send_apple_message`, `send_apple_message_group`, `send_apple_message_interactive` | — | 4 |
| **iCloud Drive** | `get_icloud_drive_file`, `list_icloud_drive_files` | `upload_icloud_drive_file` | — | 3 |
| **OneDrive** | `get_onedrive_file`, `list_onedrive_files` | `upload_onedrive_file` | — | 3 |
| **Outlook** | `get_outlook_messages` | `send_outlook_mail` | — | 2 |
| **Cross-channel** | — | `unified_channel_broadcast` | — | 1 |

Shared utility: `WP_MCP_AI_Pro_Google_Service_Account` — Google service account authentication helper used by Google Chat and Outlook tools.

## Inputs / Outputs / Neighbors

- **Reads from:** tool arguments (token, channel_id, message content, etc.), per-platform connection settings stored in `wp_mcp_ai_settings` or the remote-connection vault, external platform API responses.
- **Writes to:** external platform APIs (HTTP POST/GET to Discord, Slack, Telegram, etc.), the WP MCP AI event log via `WP_MCP_AI_Logger`.
- **Upstream callers:** the global tool registry, MCP server controllers, chat REST, slash commands (`/broadcast`), scheduled channel broadcast jobs.
- **Downstream collaborators:** `WP_MCP_AI_Logger`, `WP_MCP_AI_Pro_Google_Service_Account`, the `unified_channel_broadcast` tool (which delegates to per-platform send tools).
- **Events fired:** per-tool capability filters (e.g. `wp_mcp_ai_send_discord_message_capability`).
- **Events listened to:** none — tools are passive executors.

## Conventions

- **All conventions from [`../README.md`](../README.md) apply** — canonical envelope, two-gate sanitisation, capability flags, `is_available()`.
- Each platform gets its own family of files: `send-{platform}-message`, `get-{platform}-messages`, etc. Do not mix platforms in a single file.
- Every external API call MUST use `wp_remote_post` / `wp_remote_get` with a filterable timeout (default 15s). Return `WP_Error` with code `wp_mcp_ai_{platform}_http_error` or `wp_mcp_ai_{platform}_api_error` on failure.
- Credentials (tokens, webhook URLs) are accepted as tool arguments AND looked up from stored settings — the argument takes precedence. Never hardcode credentials.
- The `send_whatsapp_*` family shares WhatsApp API endpoint logic — consider extracting shared helpers into a trait if the family grows beyond 4 tools.
- Log every outgoing API call (request) and every non-200 response (error) via `WP_MCP_AI_Logger`.

## Tests

```bash
vendor/bin/phpunit tests/test-chat-channels.php
vendor/bin/phpunit tests/test-chat-channels-admin-pages.php
vendor/bin/phpunit tests/test-chat-channel-test-handlers.php
```

Per-platform send/get tool coverage is part of the chat-channels integration suite above. Dedicated per-tool tests live in `addons/pro/tests/tools/` when they exist.

## Also Load

- [`.context/conventions.md`](../../../../../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../../../../../.context/security-checklist.md) — external API key handling, capability checks (always)
- [`.context/tool-registry.md`](../../../../../../.context/tool-registry.md) — tool registration, availability, capability flags
- [`.context/pro-vs-base.md`](../../../../../../.context/pro-vs-base.md) — Pro tool placement
- [`CLAUDE.md`](../../../../../../CLAUDE.md) — canonical envelope + two-gate sanitisation + PHP 8.1+

## See Also

- Parent folder: [`../`](../) — ~55 additional Pro tool classes (CPT, Woo, Shopify, GitHub, email, etc.)
- Webhook handler: [`../../ChatChannels/`](../../ChatChannels/) — Google Chat webhook receiver
- Per-toolkit tools: [`../../../tools/`](../../../tools/) — toolkit-scoped tool libraries
- Base tools: [`../../../../../includes/tools/`](../../../../../includes/tools/) — ~195 Base tool implementations
