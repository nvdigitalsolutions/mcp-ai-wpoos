# `/broadcast` — Multi-Channel Message Broadcast

> **Pro Required:** Yes  
> **Since:** 2.1.0  
> **Capability:** `manage_options`  
> **Alias:** `notify`

## Synopsis

```
/broadcast <message> --channel=<channel> [--dry-run] [--assistant-id=<n>] [--json]
```

Send a message to an external channel (Telegram, Slack, Discord, Teams, Messenger, WhatsApp).

## Flags

| Flag | Description | Required |
|------|-------------|----------|
| `<message>` | Message text to send | Yes (positional) |
| `--channel=<channel>` | Target channel | Yes |
| `--dry-run` | Preview without sending | No |
| `--assistant-id=<n>` | Override assistant ID (falls back to context) | No |
| `--json` | Return JSON envelope | No |

## Allowed Channels

| Channel Key | Platform |
|------------|---------|
| `telegram` | Telegram |
| `slack` | Slack |
| `discord` | Discord |
| `teams` | Microsoft Teams |
| `messenger` | Facebook Messenger |
| `whatsapp` | WhatsApp |

## Examples

```bash
# Broadcast a message to Slack
/broadcast "Deploy complete! 🚀" --channel=slack

# Send to Telegram
/broadcast "Alert: High traffic detected" --channel=telegram

# Preview without sending
/broadcast "Test message" --channel=discord --dry-run

# Use alias
/notify "Scheduled maintenance in 1 hour" --channel=teams

# JSON response
/broadcast "Hello!" --channel=slack --json
```

## Dispatch Logic

1. If `WP_MCP_AI_Tool_Registry` is loaded and the `unified_channel_broadcast` tool is registered, it is used to send.
2. Otherwise, fires `do_action( 'wp_mcp_ai_broadcast_message', $channel, $message, $context )`.
3. `--dry-run` skips both steps and returns a preview.

## Notes

- Requires `manage_options` for all operations.
- Channel keys are validated against the allowlist; unknown channels return `invalid_channel`.
- Guest requests are blocked.
- Requires Pro addon.
