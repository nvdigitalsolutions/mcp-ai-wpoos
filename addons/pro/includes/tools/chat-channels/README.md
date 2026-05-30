# Chat Channels Tools

> Pro integration tools for messaging and collaboration platforms — Slack, Discord, Microsoft Teams, Messenger, Google Chat, Apple Messages for Business, iCloud Drive, Telegram, WhatsApp, Twitter/X DMs, OneDrive, and Outlook Mail — plus the unified cross-channel broadcast tool.

## Purpose

Send, receive, and manage messages across ~12 messaging platforms from a single AI assistant. Also includes iCloud Drive and OneDrive file operations. The unified broadcast tool lets one message fan out to multiple channels.

## Platform coverage

| Platform | Send | Read | Manage | Notes |
|---|---|---|---|---|
| Slack | ✅ | ✅ | ✅ (channels) | Web API |
| Discord | ✅ | ✅ | ✅ (channels, reactions, voice) | Bot API |
| Microsoft Teams | ✅ | ✅ | — | Graph API |
| Messenger | ✅ | ✅ | ✅ (broadcast) | Meta Messenger Platform |
| Google Chat | ✅ | ✅ | ✅ (spaces, members) | Chat API + service account |
| Apple Messages | ✅ (simple, interactive, group) | ✅ | — | Apple Messages for Business |
| iCloud Drive | ✅ (upload) | ✅ (list, get) | — | iCloud API |
| Telegram | ✅ | ✅ | ✅ (webhook, commands, reactions) | Bot API |
| WhatsApp | ✅ (simple, template, interactive, media) | ✅ | — | Cloud API |
| Twitter/X | ✅ | ✅ | ✅ (webhook) | API v2 |
| OneDrive | ✅ (upload) | ✅ (list, get) | — | Microsoft Graph |
| Outlook | ✅ | ✅ | — | Microsoft Graph |
| Unified Broadcast | ✅ (all platforms) | — | — | Cross-channel fan-out |

## Tools (~50)

All tool classes follow the `WP_MCP_AI_Pro_Tool_{Platform}_{Action}` naming convention. Slugs follow `send_{platform}_message`, `get_{platform}_messages`, etc. The exact catalogue is authoritative from `WP_MCP_AI_Tool_Registry::get_tools()`.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **Loaded by** | `wp_mcp_ai_pro_register_tools()` in `addons/pro/mcp-ai-wpoos-pro.php` and `addons/pro/includes/chat-channels-toolkit-init.php` |
| **Optional dependencies** | Per-platform API credentials; Google service account (shared helper: `class-wp-mcp-ai-pro-google-service-account.php`) |

## Conventions

- Canonical return envelope enforced.
- Two-gate sanitisation rule applies.
- Every tool implements `WP_MCP_AI_Tool_Interface`.
- Gated behind `enable_chat_channels_toolkit` setting.
- Google Chat / Drive tools share `WP_MCP_AI_Pro_Google_Service_Account` for OAuth/JWT signing.

## Tests

```bash
vendor/bin/phpunit tests/test-chat-channels.php
```

## See Also

- Sibling: [`tools/social-media/`](../social-media/)
- Sibling: [`tools/google-workspace/`](../google-workspace/)
- Init file: [`addons/pro/includes/chat-channels-toolkit-init.php`](../../chat-channels-toolkit-init.php)
- [`.context/security-checklist.md`](../../../../../.context/security-checklist.md)
