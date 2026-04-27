# Chat Channels Integration Toolkit

> Unified multi-platform chat: route Slack, Microsoft Teams, Google Chat, WhatsApp, web
> chat and webhooks through a single set of channel CPTs and broadcast tools.

| | |
|---|---|
| **Activation setting** | `enable_chat_channels_toolkit` |
| **Admin location** | NV oOS → Settings → Pro Features → Chat Channels |
| **Custom Post Types** | Channel Contacts, Channel Messages |
| **Companion CCT** | Channel Contacts CCT, Channel Messages CCT (JetEngine) |

---

## What it provides

The Chat Channels toolkit consolidates inbound and outbound messages across multiple
platforms behind one model:

- **Channel Contacts** — `mcp_ai_channel_contact` CPT (and an optional JetEngine CCT)
  representing a person across one or more platforms.
- **Channel Messages** — `mcp_ai_channel_message` CPT (and an optional CCT) for inbound
  and outbound messages.
- **Webhook integrations** — Google Chat (`google-chat-webhook-init.php`), WhatsApp
  (see [`WHATSAPP_SETUP_GUIDE.md`](../WHATSAPP_SETUP_GUIDE.md)), and the self-hosted
  WebRTC signaling for the WordPress chat surface.
- **Broadcast tooling** — `schedule_channel_broadcast` Pro tool to queue a broadcast
  across one or more channels at a future time.

---

## Activation

1. Activate the Pro add-on.
2. Toggle **Chat Channels Toolkit** under **NV oOS → Settings → Pro Features**.
3. Configure each platform integration on the toolkit settings page; store webhook
   secrets in the [Password Vault](password-vault.md).

---

## Related docs

- [Pro Toolkits index](README.md)
- [`addons/pro/docs/CHAT_CHANNELS_TOOLKIT.md`](../CHAT_CHANNELS_TOOLKIT.md)
- [`addons/pro/docs/CHAT_CHANNELS_README.md`](../CHAT_CHANNELS_README.md)
- [`addons/pro/docs/CHAT_CHANNELS_FINAL_PROPOSAL.md`](../CHAT_CHANNELS_FINAL_PROPOSAL.md)
- [`addons/pro/docs/WHATSAPP_SETUP_GUIDE.md`](../WHATSAPP_SETUP_GUIDE.md)
- [`addons/pro/docs/WEBCHAT-SELF-HOSTED-SIGNALING.md`](../WEBCHAT-SELF-HOSTED-SIGNALING.md)
