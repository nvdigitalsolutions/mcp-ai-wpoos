# Chat Channels Integration Toolkit

**Comprehensive Guide to Multi-Platform Chat Channel Management**

Version: 3.0.0  
Last Updated: March 2026  
Research Credit: Built on OpenClaw.ai's extensive multi-platform chat integration experience

---

## Table of Contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [Tools Reference](#tools-reference)
4. [Platform Setup Guides](#platform-setup-guides)
   - [Telegram Setup](#telegram-setup)
   - [WhatsApp Business Setup](#whatsapp-business-setup)
   - [Slack Setup](#slack-setup)
   - [Discord Setup](#discord-setup)
   - [Microsoft Teams Setup](#microsoft-teams-setup)
   - [Facebook Messenger Setup](#facebook-messenger-setup)
   - [Apple Messages for Business Setup](#apple-messages-for-business-setup)
   - [Google Chat / Google Spaces Setup](#google-chat--google-spaces-setup)
   - [Twitter/X Setup](#twitterx-setup)
   - [Office 365 Setup](#office-365-setup)
   - [iCloud Drive Setup](#icloud-drive-setup)
5. [Usage Examples](#usage-examples)
6. [Webhook Configuration](#webhook-configuration)
7. [Security & Authentication](#security--authentication)
8. [Rate Limiting & Quotas](#rate-limiting--quotas)
9. [GDPR & Compliance](#gdpr--compliance)
10. [Best Practices](#best-practices)
11. [Troubleshooting](#troubleshooting)

---

## Overview

The **Chat Channels Integration Toolkit** provides enterprise-grade integration with major chat platforms, enabling unified message management, automated responses, and cross-platform broadcasting. Built on research and real-world experience from OpenClaw.ai's multi-platform chat integrations.

### Supported Platforms

| Platform | API Type | Primary Use Case | Key Features |
|----------|----------|-----------------|--------------|
| **Telegram** | Bot API | Community management, automated support | Rich media, inline keyboards, callback queries, reactions |
| **WhatsApp** | Business API | Customer service, notifications | Template messages, interactive buttons, media messages |
| **Slack** | Web API | Team collaboration, internal tools | Workspace integration, threads, interactive components |
| **Discord** | Gateway/REST API | Community engagement, gaming | Server management, roles, embedded messages, reactions, voice |
| **Microsoft Teams** | Graph API | Enterprise collaboration | Channels, tabs, adaptive cards, compliance |
| **Facebook Messenger** | Send/Receive API | Customer engagement, marketing | Quick replies, persistent menu, webviews, broadcasts |
| **Apple Messages for Business** | MSP REST API | iOS/macOS customer engagement | List pickers, time pickers, rich links, Apple Pay, group conversations |
| **Google Chat / Spaces** | Chat API v1 | Google Workspace team messaging | Spaces, direct messages, space management, member management, incoming webhooks, OIDC bot events |
| **Twitter/X** | API v2 | Direct messaging, social notifications | Direct messages, Account Activity API webhooks |
| **Office 365 – Outlook** | Microsoft Graph API | Email communication | Send mail, retrieve inbox/folder messages, HTML/plain-text, CC support |
| **Office 365 – OneDrive** | Microsoft Graph API | File storage and sharing | List, download, and upload files and folders |
| **iCloud Drive** | Gateway / CloudKit API | Apple ecosystem file management | List, download, and upload files via configurable HTTPS gateway |

### Key Capabilities

- **Unified Messaging**: Send and receive messages across all platforms from a single interface
- **Multi-Platform Broadcasting**: Distribute content to multiple channels simultaneously
- **Rich Media Support**: Handle text, images, videos, documents, and platform-specific interactive elements
- **Channel Management**: Create, configure, and manage channels/groups programmatically
- **User Management**: Handle permissions, roles, and user profiles across platforms
- **Real-Time Analytics**: Track delivery, engagement, and user activity
- **Webhook Processing**: Receive and process events in real-time
- **AI Integration**: Leverage WordPress AI assistants for automated, context-aware responses

---

## Architecture

### System Components

```
┌─────────────────────────────────────────────────────────────┐
│                    WordPress Site                            │
│  ┌───────────────────────────────────────────────────────┐  │
│  │              NV oOS Plugin Core                        │  │
│  │  ┌─────────────────────────────────────────────────┐  │  │
│  │  │     Chat Channels Toolkit (47 Tools)            │  │  │
│  │  │                                                   │  │  │
│  │  │  ┌──────────────┐    ┌──────────────┐          │  │  │
│  │  │  │   Platform   │    │   Webhook    │          │  │  │
│  │  │  │   Adapters   │◄───┤   Handlers   │          │  │  │
│  │  │  └──────────────┘    └──────────────┘          │  │  │
│  │  │         ▲                    ▲                   │  │  │
│  │  │         │                    │                   │  │  │
│  │  │         ▼                    ▼                   │  │  │
│  │  │  ┌──────────────┐    ┌──────────────┐          │  │  │
│  │  │  │   Message    │    │   AI Agent   │          │  │  │
│  │  │  │   Router     │◄───┤   Processor  │          │  │  │
│  │  │  └──────────────┘    └──────────────┘          │  │  │
│  │  └─────────────────────────────────────────────────┘  │  │
│  └───────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
        ┌─────────────────────────────────────────────────────────┐
        │                  External Chat Platforms                 │
        │  ┌──────────┐ ┌──────────┐ ┌──────┐ ┌──────────────┐  │
        │  │ Telegram │ │ WhatsApp │ │Slack │ │   Discord    │  │
        │  └──────────┘ └──────────┘ └──────┘ └──────────────┘  │
        │  ┌──────────┐ ┌──────────┐ ┌──────┐ ┌──────────────┐  │
        │  │  Teams   │ │Messenger │ │Apple │ │ Google Chat  │  │
        │  └──────────┘ └──────────┘ └──────┘ └──────────────┘  │
        │  ┌──────────┐ ┌──────────────┐ ┌──────────────────┐   │
        │  │Twitter/X │ │  Office 365  │ │  iCloud Drive    │   │
        │  └──────────┘ └──────────────┘ └──────────────────┘   │
        └─────────────────────────────────────────────────────────┘
```

### Data Flow

1. **Outbound Messages**: WordPress → Tool → Platform Adapter → External API
2. **Inbound Messages**: External Platform → Webhook → Handler → AI Agent → Response
3. **Broadcasting**: WordPress → Tool → Message Router → Multiple Platform Adapters → External APIs

### Tool Architecture

Each tool in the toolkit follows a consistent pattern:

```php
class WP_MCP_AI_Tool_Chat_Action extends WP_MCP_AI_Tool_Base {
    public function execute( $arguments, $context ) {
        // 1. Validate platform credentials
        // 2. Sanitize and validate input
        // 3. Call platform-specific adapter
        // 4. Handle rate limiting
        // 5. Log action and metrics
        // 6. Return standardized response
    }
}
```

---

## Tools Reference

> **Version 3.0 Tool Inventory:** The Chat Channels Toolkit now contains **47 platform-specific tools** across 11 platforms. The section below lists the complete set of tool slugs you use in assistant tool calls, organized by platform. The detailed conceptual tools (send_chat_message, etc.) that follow describe the general patterns — see each Platform Setup Guide for platform-specific parameter details.

### Complete Tool Inventory

| Platform | Tool Slug | Description |
|----------|-----------|-------------|
| **Telegram** | `get_telegram_updates` | Retrieve recent messages/updates from the bot |
| **Telegram** | `send_telegram_message` | Send a message to a chat or channel |
| **Telegram** | `add_telegram_message_reaction` | Add an emoji reaction to a message (Bot API 7.0+) |
| **Telegram** | `manage_telegram_webhook` | Register or delete a Telegram bot webhook |
| **WhatsApp** | `get_whatsapp_messages` | Retrieve WhatsApp Cloud API message history |
| **WhatsApp** | `send_whatsapp_template` | Send a pre-approved template message |
| **WhatsApp** | `send_whatsapp_interactive` | Send interactive buttons or list messages |
| **WhatsApp** | `send_whatsapp_media` | Send image, video, audio, document, or sticker |
| **Slack** | `get_slack_channels` | List channels in a Slack workspace |
| **Slack** | `get_slack_messages` | Retrieve message history from a channel |
| **Slack** | `send_slack_message` | Send a message to a Slack channel |
| **Slack** | `create_slack_channel` | Create a new Slack channel |
| **Discord** | `get_discord_channels` | List channels in a Discord server |
| **Discord** | `get_discord_messages` | Retrieve message history from a channel |
| **Discord** | `send_discord_message` | Send a message to a Discord channel |
| **Discord** | `create_discord_channel` | Create a new channel in a Discord server |
| **Discord** | `add_discord_message_reaction` | Add an emoji reaction to a Discord message |
| **Discord** | `get_discord_voice_channel_members` | List users in a Discord voice channel |
| **Microsoft Teams** | `get_teams_channels` | List channels in a Teams team |
| **Microsoft Teams** | `get_teams_messages` | Retrieve message history from a Teams channel |
| **Microsoft Teams** | `send_teams_message` | Send a message to a Teams channel |
| **Facebook Messenger** | `get_messenger_conversations` | List Facebook Messenger conversations |
| **Facebook Messenger** | `send_messenger_message` | Send a Messenger message to a user |
| **Facebook Messenger** | `create_messenger_broadcast` | Create and send a Messenger broadcast |
| **Apple Messages** | `get_apple_messages` | Retrieve conversation history from an MSP |
| **Apple Messages** | `send_apple_message` | Send a text message via Apple Messages for Business |
| **Apple Messages** | `send_apple_message_group` | Send to a group conversation or create a group |
| **Apple Messages** | `send_apple_message_interactive` | Send list pickers, date pickers, or rich links |
| **Google Chat** | `send_google_chat_message` | Send a message to a Google Chat space |
| **Google Chat** | `get_google_chat_spaces` | List spaces the bot belongs to |
| **Google Chat** | `get_google_chat_messages` | Retrieve message history from a space |
| **Google Chat** | `create_google_chat_space` | Create a new space (SPACE, GROUP_CHAT, or DIRECT_MESSAGE) |
| **Google Chat** | `add_google_chat_space_member` | Add a user to a space |
| **Google Chat** | `list_google_chat_space_members` | List all members of a space |
| **Google Chat** | `remove_google_chat_space_member` | Remove a member from a space |
| **Twitter/X** | `get_twitter_dms` | Retrieve recent Direct Message events |
| **Twitter/X** | `send_twitter_dm` | Send a Direct Message to a user |
| **Twitter/X** | `manage_twitter_webhook` | Register/remove Account Activity webhooks |
| **Office 365 – Outlook** | `send_outlook_mail` | Send an email via Microsoft Outlook (Microsoft Graph API) |
| **Office 365 – Outlook** | `get_outlook_messages` | Retrieve messages from an Outlook mail folder |
| **Office 365 – OneDrive** | `list_onedrive_files` | List files and folders in a OneDrive drive |
| **Office 365 – OneDrive** | `get_onedrive_file` | Download a file from OneDrive |
| **Office 365 – OneDrive** | `upload_onedrive_file` | Upload a file to a OneDrive folder |
| **iCloud Drive** | `list_icloud_drive_files` | List files and folders via an iCloud gateway API |
| **iCloud Drive** | `get_icloud_drive_file` | Download a file via the iCloud gateway API |
| **iCloud Drive** | `upload_icloud_drive_file` | Upload a file via the iCloud gateway API |
| **Multi-Platform** | `unified_channel_broadcast` | Broadcast a message to multiple platforms simultaneously |
| **WebChat** | `send_webchat_message` | Send a message to a WebChat P2P room |

---

### Core Messaging Tools

#### 1. `send_chat_message`

Send a text message to a specific chat platform channel.

**Parameters:**
```json
{
  "platform": "telegram|whatsapp|slack|discord|teams|messenger",
  "channel_id": "string",
  "message": "string",
  "parse_mode": "plain|markdown|html (optional)",
  "reply_to": "message_id (optional)"
}
```

**Response:**
```json
{
  "success": true,
  "message_id": "12345",
  "platform": "telegram",
  "timestamp": "2025-01-13T10:30:00Z"
}
```

**Example:**
```javascript
{
  "platform": "telegram",
  "channel_id": "-1001234567890",
  "message": "Hello from WordPress!",
  "parse_mode": "markdown"
}
```

---

#### 2. `receive_chat_message`

Retrieve recent messages from a chat platform (pull-based, not webhook).

**Parameters:**
```json
{
  "platform": "telegram|slack|discord",
  "channel_id": "string",
  "limit": "number (default: 50, max: 100)",
  "before": "message_id (optional)",
  "after": "message_id (optional)"
}
```

**Note:** WhatsApp, Teams, and Messenger require webhook-based message reception.

---

#### 3. `send_multiplatform_message`

Broadcast a message to multiple platforms simultaneously.

**Parameters:**
```json
{
  "platforms": ["telegram", "slack", "discord"],
  "channel_ids": {
    "telegram": "-1001234567890",
    "slack": "C01234ABCD",
    "discord": "123456789012345678"
  },
  "message": "string",
  "media_url": "string (optional)",
  "media_type": "image|video|document (optional)"
}
```

**Response:**
```json
{
  "success": true,
  "results": {
    "telegram": { "success": true, "message_id": "12345" },
    "slack": { "success": true, "message_id": "1234567890.123456" },
    "discord": { "success": false, "error": "Rate limited" }
  }
}
```

---

### Media Tools

#### 4. `send_chat_image`

Send an image with optional caption.

**Parameters:**
```json
{
  "platform": "string",
  "channel_id": "string",
  "image_url": "string",
  "caption": "string (optional)",
  "thumbnail_url": "string (optional)"
}
```

**Supported Formats:**
- Telegram: JPG, PNG, GIF, WebP (max 10MB)
- WhatsApp: JPG, PNG (max 5MB)
- Slack: JPG, PNG, GIF, WebP (max 20MB)
- Discord: JPG, PNG, GIF, WebP (max 8MB)
- Teams: JPG, PNG, GIF (max 4MB)
- Messenger: JPG, PNG, GIF (max 25MB)

---

#### 5. `send_chat_video`

Send a video with optional caption.

**Parameters:**
```json
{
  "platform": "string",
  "channel_id": "string",
  "video_url": "string",
  "caption": "string (optional)",
  "duration": "number (optional, seconds)",
  "thumbnail_url": "string (optional)"
}
```

**Supported Formats & Limits:**
- Telegram: MP4, WebM (max 50MB)
- WhatsApp: MP4, 3GP (max 16MB)
- Slack: MP4, WebM, MOV (max 1GB)
- Discord: MP4, WebM, MOV (max 8MB standard, 50MB with Nitro)

---

#### 6. `send_chat_document`

Send a document/file attachment.

**Parameters:**
```json
{
  "platform": "string",
  "channel_id": "string",
  "document_url": "string",
  "filename": "string",
  "mime_type": "string (optional)",
  "caption": "string (optional)"
}
```

---

### Channel/Group Management Tools

#### 7. `create_chat_channel`

Create a new channel or group.

**Parameters:**
```json
{
  "platform": "telegram|slack|discord",
  "name": "string",
  "description": "string (optional)",
  "is_private": "boolean (default: false)",
  "members": ["user_id1", "user_id2"] (optional)
}
```

**Platform-Specific Notes:**
- **Telegram**: Creates a supergroup or channel (bot must have permissions)
- **Slack**: Creates a public/private channel in workspace
- **Discord**: Creates a text channel in guild (requires manage_channels permission)
- **WhatsApp**: Not supported (groups created via app)
- **Teams**: Requires Graph API admin consent
- **Messenger**: Not applicable (page-based)

---

#### 8. `manage_chat_channel`

Update channel settings.

**Parameters:**
```json
{
  "platform": "string",
  "channel_id": "string",
  "action": "update|archive|unarchive|delete",
  "name": "string (optional)",
  "description": "string (optional)",
  "topic": "string (optional)"
}
```

---

#### 9. `list_chat_channels`

List available channels/groups.

**Parameters:**
```json
{
  "platform": "string",
  "filter": "public|private|all (default: all)",
  "limit": "number (default: 50)"
}
```

---

#### 10. `add_channel_member`

Add a user to a channel/group.

**Parameters:**
```json
{
  "platform": "string",
  "channel_id": "string",
  "user_id": "string",
  "role": "admin|member (optional, platform-dependent)"
}
```

---

#### 11. `remove_channel_member`

Remove a user from a channel/group.

**Parameters:**
```json
{
  "platform": "string",
  "channel_id": "string",
  "user_id": "string",
  "ban": "boolean (default: false)"
}
```

---

### Interactive Elements Tools

#### 12. `send_interactive_message`

Send a message with interactive buttons or elements.

**Parameters:**
```json
{
  "platform": "string",
  "channel_id": "string",
  "message": "string",
  "buttons": [
    {
      "text": "string",
      "callback_data": "string (Telegram/Discord)",
      "url": "string (optional)",
      "value": "string (Slack/WhatsApp)"
    }
  ],
  "layout": "inline|reply_keyboard (Telegram)",
  "quick_replies": ["option1", "option2"] (WhatsApp/Messenger)
}
```

**Platform Examples:**

**Telegram Inline Keyboard:**
```json
{
  "platform": "telegram",
  "channel_id": "-1001234567890",
  "message": "Choose an option:",
  "buttons": [
    [
      { "text": "✅ Approve", "callback_data": "approve_123" },
      { "text": "❌ Reject", "callback_data": "reject_123" }
    ],
    [
      { "text": "🔗 Learn More", "url": "https://example.com" }
    ]
  ]
}
```

**Slack Block Kit:**
```json
{
  "platform": "slack",
  "channel_id": "C01234ABCD",
  "message": "Choose an action:",
  "buttons": [
    { "text": "Approve", "value": "approve", "style": "primary" },
    { "text": "Reject", "value": "reject", "style": "danger" }
  ]
}
```

**WhatsApp Interactive Buttons:**
```json
{
  "platform": "whatsapp",
  "channel_id": "1234567890",
  "message": "How can we help?",
  "quick_replies": ["Support", "Sales", "General"]
}
```

---

#### 13. `handle_callback_query`

Process interactive button callbacks.

**Parameters:**
```json
{
  "platform": "string",
  "callback_id": "string",
  "action": "acknowledge|respond|update_message",
  "response_text": "string (optional)"
}
```

**Usage:**
This tool is typically called from webhook handlers when users click interactive buttons.

---

### User Management Tools

#### 14. `get_chat_user_info`

Retrieve user profile information.

**Parameters:**
```json
{
  "platform": "string",
  "user_id": "string",
  "include_profile_photo": "boolean (default: false)"
}
```

**Response:**
```json
{
  "success": true,
  "user": {
    "id": "12345",
    "username": "john_doe",
    "first_name": "John",
    "last_name": "Doe",
    "display_name": "John Doe",
    "profile_photo_url": "https://...",
    "bio": "...",
    "is_bot": false,
    "language_code": "en"
  }
}
```

---

#### 15. `manage_user_permissions`

Set or update user permissions in a channel.

**Parameters:**
```json
{
  "platform": "telegram|slack|discord",
  "channel_id": "string",
  "user_id": "string",
  "permissions": {
    "can_send_messages": "boolean",
    "can_send_media": "boolean",
    "can_add_members": "boolean",
    "can_pin_messages": "boolean",
    "is_admin": "boolean"
  }
}
```

---

### Analytics Tools

#### 16. `get_chat_analytics`

Retrieve engagement and activity metrics.

**Parameters:**
```json
{
  "platform": "string",
  "channel_id": "string",
  "date_from": "ISO 8601 date",
  "date_to": "ISO 8601 date",
  "metrics": ["messages", "members", "engagement"]
}
```

**Response:**
```json
{
  "success": true,
  "analytics": {
    "period": {
      "from": "2025-01-01T00:00:00Z",
      "to": "2025-01-13T23:59:59Z"
    },
    "messages_sent": 1250,
    "messages_received": 3400,
    "active_users": 456,
    "new_members": 23,
    "engagement_rate": 0.67
  }
}
```

---

#### 17. `track_message_delivery`

Check delivery and read status of messages.

**Parameters:**
```json
{
  "platform": "whatsapp|messenger|teams",
  "message_id": "string"
}
```

**Response:**
```json
{
  "success": true,
  "status": {
    "sent": true,
    "delivered": true,
    "read": false,
    "timestamp_sent": "2025-01-13T10:30:00Z",
    "timestamp_delivered": "2025-01-13T10:30:02Z"
  }
}
```

**Note:** Only available for platforms that provide delivery receipts (WhatsApp, Messenger, Teams).

---

### Webhook Tools

#### 18. `configure_chat_webhook`

Set up or update webhook configuration.

**Parameters:**
```json
{
  "platform": "string",
  "webhook_url": "string",
  "events": ["message", "callback_query", "member_joined"],
  "secret": "string (optional, for signature verification)"
}
```

**Example:**
```json
{
  "platform": "telegram",
  "webhook_url": "https://yoursite.com/wp-json/mcp-ai/v1/webhooks/telegram",
  "events": ["message", "edited_message", "callback_query"],
  "secret": "your_webhook_secret"
}
```

---

#### 19. `process_webhook_event`

Process incoming webhook events (internal tool).

**Note:** This tool is called automatically by the webhook handler. You typically don't call it directly.

---

### Advanced Features

#### 20. `moderate_chat_content`

Automatically moderate content based on rules.

**Parameters:**
```json
{
  "platform": "string",
  "channel_id": "string",
  "rules": {
    "blocked_words": ["spam", "scam"],
    "max_links": 3,
    "auto_delete_spam": true,
    "require_approval": false
  }
}
```

---

#### 21. `auto_translate_message`

Automatically translate messages between languages.

**Parameters:**
```json
{
  "platform": "string",
  "channel_id": "string",
  "source_language": "auto|en|es|fr|de|...",
  "target_language": "en|es|fr|de|...",
  "message": "string"
}
```

**Response:**
```json
{
  "success": true,
  "original_text": "Hola, ¿cómo estás?",
  "translated_text": "Hello, how are you?",
  "detected_language": "es",
  "confidence": 0.98
}
```

---

## Platform Setup Guides

### Telegram Setup

#### Step 1: Create a Bot

1. Open Telegram and search for `@BotFather`
2. Send `/newbot` command
3. Follow prompts to set bot name and username
4. Copy the bot token (format: `1234567890:ABCdefGHIjklMNOpqrsTUVwxyz`)

#### Step 2: Configure Bot

```bash
# Set bot description
/setdescription

# Set bot profile photo
/setuserpic

# Enable inline mode (optional)
/setinline

# Set commands menu
/setcommands
start - Start conversation
help - Get help
support - Contact support
```

#### Step 3: Configure Webhook

In WordPress admin:
1. Go to **NV oOS → Chat Channels Toolkit → Configuration**
2. Expand **Telegram Configuration**
3. Enter your bot token
4. Note the webhook URL: `https://yoursite.com/wp-json/mcp-ai/v1/webhooks/telegram`

The plugin will automatically set the webhook when you save settings.

**Manual webhook setup (optional):**
```bash
curl -X POST "https://api.telegram.org/bot<YOUR_BOT_TOKEN>/setWebhook" \
  -H "Content-Type: application/json" \
  -d '{"url": "https://yoursite.com/wp-json/mcp-ai/v1/webhooks/telegram"}'
```

#### Security Best Practices

- **Use HTTPS**: Telegram requires HTTPS for webhooks
- **Validate Updates**: Check `update_id` to prevent duplicate processing
- **Rate Limiting**: Respect 30 messages/second limit per chat
- **Token Security**: Store bot token securely, never commit to version control

---

### WhatsApp Business Setup

#### Step 1: Get WhatsApp Business API Access

WhatsApp Business API requires approval and is typically accessed through:
- **Meta Business Partners** (Twilio, MessageBird, etc.)
- **WhatsApp Cloud API** (Direct from Meta, free tier available)

#### Step 2: Cloud API Setup (Recommended)

1. Go to [Meta for Developers](https://developers.facebook.com/)
2. Create a new app or select existing
3. Add **WhatsApp** product
4. Complete business verification
5. Get your:
   - Phone Number ID
   - Access Token
   - Webhook Verify Token (you create this)

#### Step 3: Configure in WordPress

1. Go to **NV oOS → Chat Channels Toolkit → Configuration**
2. Expand **WhatsApp Business Configuration**
3. Enter:
   - **Phone Number ID**: From Meta dashboard
   - **Access Token**: From Meta dashboard
   - **Verify Token**: Create a secure random string
4. Copy webhook URL: `https://yoursite.com/wp-json/mcp-ai/v1/webhooks/whatsapp`

#### Step 4: Set Up Webhook in Meta

1. In Meta dashboard, go to **WhatsApp → Configuration**
2. Click **Edit** under Webhook
3. Enter:
   - **Callback URL**: Your webhook URL
   - **Verify Token**: The token you created
4. Subscribe to fields: `messages`, `message_status`

#### Template Messages

WhatsApp requires pre-approved templates for outbound messages:

```json
{
  "platform": "whatsapp",
  "channel_id": "1234567890",
  "template": {
    "name": "hello_world",
    "language": "en_US",
    "parameters": [
      { "type": "text", "text": "John" }
    ]
  }
}
```

**Create templates in Meta Business Manager under WhatsApp → Message Templates**

#### Rate Limits

- **Tier 1** (New): 1,000 unique users per 24h
- **Tier 2**: 10,000 unique users per 24h
- **Tier 3**: 100,000 unique users per 24h
- **Unlimited**: Request from Meta after sustained usage

---

### Slack Setup

#### Step 1: Create a Slack App

1. Go to [Slack API](https://api.slack.com/apps)
2. Click **Create New App**
3. Choose **From scratch**
4. Enter app name and select workspace

#### Step 2: Configure OAuth & Permissions

1. Navigate to **OAuth & Permissions**
2. Add **Bot Token Scopes**:
   ```
   chat:write          - Send messages
   channels:read       - View channels
   channels:manage     - Manage channels
   groups:read         - View private channels
   groups:write        - Post to private channels
   im:read            - View DMs
   im:write           - Send DMs
   users:read         - View users
   files:write        - Upload files
   reactions:write    - Add reactions
   ```
3. Install app to workspace
4. Copy **Bot User OAuth Token** (starts with `xoxb-`)

#### Step 3: Enable Event Subscriptions

1. Navigate to **Event Subscriptions**
2. Enable Events
3. Enter Request URL: `https://yoursite.com/wp-json/mcp-ai/v1/webhooks/slack`
4. Subscribe to bot events:
   ```
   message.channels   - Messages in channels
   message.groups     - Messages in private channels
   message.im         - Direct messages
   app_mention        - @mentions
   reaction_added     - Emoji reactions
   ```

#### Step 4: Configure in WordPress

1. Go to **NV oOS → Chat Channels Toolkit → Configuration**
2. Expand **Slack Configuration**
3. Enter:
   - **Bot Token**: From OAuth & Permissions
   - **Signing Secret**: From Basic Information

#### Advanced: Interactive Components

For buttons and modals:
1. Enable **Interactivity & Shortcuts**
2. Set Request URL to same webhook endpoint
3. Use `send_interactive_message` tool with Slack Block Kit format

---

### Discord Setup

#### Step 1: Create Discord Application

1. Go to [Discord Developer Portal](https://discord.com/developers/applications)
2. Click **New Application**
3. Enter application name

#### Step 2: Create Bot

1. Navigate to **Bot** section
2. Click **Add Bot**
3. Copy **Token** (Click Reset Token if needed)
4. Enable **Privileged Gateway Intents**:
   - ✅ Server Members Intent
   - ✅ Message Content Intent

#### Step 3: Set Bot Permissions

1. Navigate to **OAuth2 → URL Generator**
2. Select scopes:
   - `bot`
   - `applications.commands`
3. Select permissions:
   ```
   Send Messages
   Read Messages/View Channels
   Manage Channels
   Manage Roles
   Embed Links
   Attach Files
   Read Message History
   Add Reactions
   ```
4. Copy generated URL and invite bot to your server

#### Step 4: Configure in WordPress

1. Go to **NV oOS → Chat Channels Toolkit → Configuration**
2. Expand **Discord Configuration**
3. Enter:
   - **Bot Token**: From Bot section
   - **Application ID**: From General Information
   - **Public Key**: From General Information (for interactions)

#### Webhook vs Gateway

- **Gateway**: Real-time bidirectional connection (requires long-running process)
- **Webhooks**: HTTP-based interaction endpoint

This toolkit uses Discord's webhook/interactions approach for receiving messages, suitable for WordPress environment.

---

### Microsoft Teams Setup

#### Step 1: Register Azure AD Application

1. Go to [Azure Portal](https://portal.azure.com/)
2. Navigate to **Azure Active Directory → App registrations**
3. Click **New registration**
4. Enter application name
5. Set **Supported account types**: Multitenant
6. Set **Redirect URI**: `https://yoursite.com/wp-json/mcp-ai/v1/oauth/teams`

#### Step 2: Create Client Secret

1. Navigate to **Certificates & secrets**
2. Click **New client secret**
3. Set description and expiry
4. Copy **Value** (not visible again after leaving page)

#### Step 3: Configure API Permissions

1. Navigate to **API permissions**
2. Click **Add a permission → Microsoft Graph**
3. Select **Application permissions**:
   ```
   Chat.ReadWrite.All
   Channel.ReadBasic.All
   ChannelMessage.Send
   Team.ReadBasic.All
   ```
4. Click **Grant admin consent** (requires admin)

#### Step 4: Configure in WordPress

1. Go to **NV oOS → Chat Channels Toolkit → Configuration**
2. Expand **Microsoft Teams Configuration**
3. Enter:
   - **Application (Client) ID**: From Azure AD overview
   - **Client Secret**: The secret value you copied
   - **Tenant ID**: From Azure AD overview

#### Additional: Create Teams Bot

For richer bot experience:
1. Go to [Bot Framework](https://dev.botframework.com/bots/new)
2. Create bot and connect to Teams channel
3. Set messaging endpoint to webhook URL

---

### Facebook Messenger Setup

#### Step 1: Create Facebook App

1. Go to [Facebook Developers](https://developers.facebook.com/)
2. Click **My Apps → Create App**
3. Select **Business** type
4. Enter app name and contact email

#### Step 2: Add Messenger Product

1. In app dashboard, click **Add Product**
2. Select **Messenger** and click **Set Up**
3. In Messenger settings, click **Add or Remove Pages**
4. Select your Facebook Page

#### Step 3: Generate Access Token

1. In Messenger settings, find your page
2. Click **Generate Token**
3. Authorize permissions
4. Copy **Page Access Token**

#### Step 4: Configure Webhook

1. In **Webhooks** section, click **Setup Webhooks**
2. Enter:
   - **Callback URL**: `https://yoursite.com/wp-json/mcp-ai/v1/webhooks/messenger`
   - **Verify Token**: Create a secure random string
3. Subscribe to fields:
   ```
   messages
   messaging_postbacks
   messaging_optins
   message_deliveries
   message_reads
   ```

#### Step 5: Configure in WordPress

1. Go to **NV oOS → Chat Channels Toolkit → Configuration**
2. Expand **Facebook Messenger Configuration**
3. Enter:
   - **Page Access Token**: From Generate Token step
   - **App Secret**: From Settings → Basic
   - **Verify Token**: The token you created

#### Enable Page Subscription

```bash
curl -X POST "https://graph.facebook.com/v18.0/me/subscribed_apps?access_token=<PAGE_ACCESS_TOKEN>"
```

---

### Apple Messages for Business Setup

Apple Messages for Business (formerly iMessage Business Chat) lets customers contact your business directly from iMessage, Maps, Safari, and Spotlight on iPhone, iPad, and Mac.

> **Important:** Apple requires all businesses to use an approved **Messaging Service Provider (MSP)** such as Infobip, Zendesk, Sunshine Conversations (Smooch), LivePerson, or CM.com. You cannot connect to Apple's servers directly.

#### Step 1: Register with Apple

1. Visit [register.apple.com/business-chat](https://register.apple.com/business-chat) and create a Business Account.
2. Fill in your business profile, including brand name, logo, and contact details.
3. Apple reviews applications manually — approval typically takes 1–4 weeks.
4. After approval you receive a unique **Business ID** (a UUID string).

#### Step 2: Choose and Configure an MSP

Select an Apple-certified MSP and follow their onboarding process:

| MSP | Documentation |
|-----|--------------|
| **Infobip** | https://www.infobip.com/docs/apple-messages-for-business |
| **Sunshine Conversations** | https://docs.smooch.io/guide/apple-messages-for-business/ |
| **Zendesk** | https://support.zendesk.com/hc/en-us/articles/8030634178458 |
| **LivePerson** | https://developers.liveperson.com/apple-messages-for-business-sdk-overview.html |
| **CM.com** | https://developers.cm.com/messaging/docs/apple-messages-for-business-inbound |

Your MSP will provide:
- An **API endpoint URL** (`msp_api_url`)
- An **API key** or bearer token (`api_key`)
- A **webhook signing secret** (`webhook_secret`) for payload validation

#### Step 3: Configure the Webhook in WordPress

1. In WordPress admin, go to **NV oOS → Chat Channels → Apple Messages for Business**.
2. Enter your **Business ID**, **MSP API URL**, **API key**, and **webhook secret**.
3. Note your inbound webhook URL:
   - Primary: `https://yoursite.com/wp-json/mcp-ai/v1/webhooks/apple-messages`
   - Per-connection: `https://yoursite.com/wp-json/mcp-ai/v1/webhooks/apple-messages/{connection_id}`
4. Enter this URL in your MSP dashboard as the destination for Apple Messages events.

#### Step 4: Add Customer Entry Points

Apple Messages for Business supports multiple entry points:

| Entry Point | How to Use |
|-------------|-----------|
| **Website button** | Add a `<a href="https://bcrw.apple.com/urn:biz:{businessId}">Message Us</a>` link |
| **Apple Maps** | Verified businesses get a "Message" button in Maps automatically |
| **QR code** | Generate a QR code linking to your Business Chat URL |
| **Email** | Add a "Message Us" button in email campaigns |
| **Spotlight** | Appears automatically when customers search for your business |

**Entry point URL with intent routing:**
```
https://bcrw.apple.com/urn:biz:{businessId}?biz-intent-id=support&biz-group-id=billing
```

#### Step 5: Test Your Integration

```bash
# Verify the webhook endpoint is reachable
curl -I https://yoursite.com/wp-json/mcp-ai/v1/webhooks/apple-messages

# Send a test message using the tool (replace values with your credentials)
# In your NV oOS AI assistant, run:
# Tool: send_apple_message
# Arguments:
# {
#   "msp_api_url": "https://api.your-msp.com/v1/apple/messages",
#   "api_key": "your-msp-api-key",
#   "business_id": "your-apple-business-id",
#   "conversation_id": "test-conversation-id",
#   "message": "Hello from NV oOS!"
# }
```

#### Security Best Practices

- Always configure a **webhook secret** to enable HMAC-SHA256 signature validation.
- Rotate your MSP API key periodically and update the WordPress connection settings immediately.
- Store credentials in WordPress options — never hardcode them in template files.
- Respect customer opt-outs: the plugin automatically blocks replies to closed conversations.
- Follow Apple's [privacy guidelines](https://developer.apple.com/design/human-interface-guidelines/messages-for-business): never send unsolicited messages.

---

### Google Chat / Google Spaces Setup

Google Chat is the messaging component of Google Workspace. NV oOS integrates with the **Google Chat API v1** and supports three connection approaches:

1. **Incoming Webhook** – Simplest option for posting outbound-only messages to a specific Space.
2. **Service Account** – Recommended for full bot capabilities (read messages, manage spaces/members, bot presence in spaces).
3. **OAuth 2.0** – For user-delegated access, configured through the NV oOS 1-click OAuth flow.

> **Prerequisites:** A Google Workspace account (free Gmail is not supported for creating Chat spaces via API). Your Google Cloud project must have the **Google Chat API** enabled.

#### Method 1: Incoming Webhook (Simplest – Outbound Only)

Incoming webhooks let you post messages to a specific Google Chat space without OAuth credentials.

**Step 1: Create the Webhook in Google Chat**

1. Open **Google Chat** in a browser and navigate to the target space (or create a new one).
2. Click the space name at the top → **Apps & Integrations** → **Webhooks**.
3. Click **Add Webhook**, give it a name (e.g., "NV oOS Bot"), add an optional avatar URL, and click **Save**.
4. Copy the generated webhook URL — it looks like:
   ```
   https://chat.googleapis.com/v1/spaces/AAAXXXXXXXXX/messages?key=...&token=...
   ```

**Step 2: Add the Connection in WordPress**

1. Go to **NV oOS → Remote Connections → Add New**.
2. Set **Connection Type** to **Google Chat (Chat Channel)**.
3. Paste the webhook URL into the **Incoming Webhook URL** field.
4. Click **Save**.

**Step 3: Send a Message**

Use the `send_google_chat_message` tool with the `webhook_url` parameter:

```json
{
  "tool": "send_google_chat_message",
  "arguments": {
    "webhook_url": "https://chat.googleapis.com/v1/spaces/AAAA.../messages?key=...&token=...",
    "text": "Hello from NV oOS!"
  }
}
```

> **Note:** Incoming webhooks can only *post* messages. They cannot read messages, list space members, or manage spaces. Use Method 2 (Service Account) for full bot capabilities.

---

#### Method 2: Service Account (Recommended for Full Bot Access)

A Google Service Account lets the NV oOS bot authenticate as a bot app and interact with spaces it has been added to.

**Step 1: Enable the Google Chat API**

1. Go to [Google Cloud Console](https://console.cloud.google.com/) and open or create a project.
2. Navigate to **APIs & Services → Library** and search for **Google Chat API**.
3. Click **Enable**.

**Step 2: Create a Service Account**

1. In Google Cloud Console, go to **IAM & Admin → Service Accounts**.
2. Click **Create Service Account**.
3. Enter a name (e.g., "nvoos-chat-bot") and optionally a description.
4. Click **Create and Continue**. You do not need to assign project roles for Chat API usage.
5. Click **Done**.

**Step 3: Download the JSON Key**

1. Click on the newly created service account.
2. Go to the **Keys** tab → **Add Key → Create new key**.
3. Select **JSON** and click **Create**. A `.json` file is downloaded to your computer.
4. Keep this file secure — it contains your private key.

**Step 4: Configure the Chat App in Google Cloud Console**

1. Go to **APIs & Services → Google Chat API → Configuration** (you may need to enable "Google Chat API" first).
2. Under **App Status**, set it to **Live**.
3. In **App Information**, add a name (displayed to users) and an avatar URL.
4. Under **Connection settings**, choose **HTTP endpoint URL** and enter your WordPress webhook URL:
   ```
   https://yoursite.com/wp-json/mcp-ai/v1/webhooks/google-chat
   ```
   Replace `yoursite.com` with your actual domain.
5. Click **Save**.

> **Important:** The bot will not receive events until it has been added to at least one Google Chat space. Users must manually add the bot to a space via **Apps & Integrations → Add Apps**.

**Step 5: Add the Service Account Credentials in WordPress**

1. Go to **NV oOS → Remote Connections → Add New**.
2. Set **Connection Type** to **Google Chat (Chat Channel)**.
3. Open the downloaded `.json` key file in a text editor and paste the **entire JSON content** into the **Service Account Key (JSON)** field.
4. Optionally enter the **Audience URL** (the bot endpoint URL above) if you want OIDC token verification for incoming events.
5. Click **Save**.

**Step 6: Add the Bot to a Google Chat Space**

1. In Google Chat, open the space where you want to use the bot.
2. Click the space name → **Apps & Integrations → Add Apps**.
3. Search for your bot by name and click **Add**.

The bot is now active in the space and can receive messages.

**Step 7: Use the Google Chat Tools**

Once configured, the following tools are available:

| Tool Slug | Description |
|-----------|-------------|
| `send_google_chat_message` | Send a text message to a space (webhook or OAuth) |
| `get_google_chat_spaces` | List all spaces the bot belongs to |
| `get_google_chat_messages` | Retrieve message history from a space |
| `create_google_chat_space` | Create a new space (SPACE, GROUP_CHAT, or DIRECT_MESSAGE) |
| `add_google_chat_space_member` | Add a user or app to a space |
| `list_google_chat_space_members` | List all members of a space |
| `remove_google_chat_space_member` | Remove a member from a space |

**Example – Get All Spaces:**
```json
{
  "tool": "get_google_chat_spaces",
  "arguments": {
    "service_account_key": "{...paste full JSON key here...}",
    "filter": "spaceType = \"SPACE\""
  }
}
```

**Example – Send a Message to a Space:**
```json
{
  "tool": "send_google_chat_message",
  "arguments": {
    "service_account_key": "{...paste full JSON key here...}",
    "space": "spaces/AAAAxxxxxxxxx",
    "text": "Deployment complete ✅"
  }
}
```

**Example – Create a New Space:**
```json
{
  "tool": "create_google_chat_space",
  "arguments": {
    "service_account_key": "{...paste full JSON key here...}",
    "display_name": "Project Alpha",
    "space_type": "SPACE"
  }
}
```

---

#### Method 3: OAuth 2.0 (User-Delegated Access)

For use cases where you need user-level permissions (e.g., accessing spaces the user belongs to rather than a bot), you can authenticate via the NV oOS 1-click OAuth flow.

**Step 1: Configure OAuth Credentials**

1. In Google Cloud Console, go to **APIs & Services → Credentials → Create Credentials → OAuth client ID**.
2. Set **Application type** to **Web application**.
3. Add your WordPress admin URL as an **Authorized redirect URI**:
   ```
   https://yoursite.com/wp-admin/admin.php
   ```
4. Note the **Client ID** and **Client Secret**.

**Step 2: Add the Connection in WordPress**

1. Go to **NV oOS → Remote Connections → Add New**.
2. Set **Connection Type** to **Google Chat (Chat Channel)**.
3. Enter the **Client ID** and **Client Secret** from Step 1.
4. Click **Connect with Google** to start the OAuth flow. You will be redirected to Google to grant permissions.
5. After authorization, the refresh token is saved automatically.

---

#### Google Chat Webhook Configuration

When using Service Account mode, NV oOS exposes a webhook endpoint that Google Chat will call when a user sends a message to your bot:

```
https://yoursite.com/wp-json/mcp-ai/v1/webhooks/google-chat
```

**Security:** Google Chat signs all webhook events with an OIDC Bearer token in the `Authorization` header. NV oOS validates this token automatically. If you specified an **Audience URL** in the connection settings, the token's `aud` claim is verified against it for additional security.

**Supported Event Types:**
| Event | Description |
|-------|-------------|
| `MESSAGE` | User sent a message to the bot in a space or DM |
| `ADDED_TO_SPACE` | Bot was added to a space |
| `REMOVED_FROM_SPACE` | Bot was removed from a space |
| `CARD_CLICKED` | User clicked a card action in a bot message |

**Auto-Reply Feature:** NV oOS supports AI-powered auto-reply for incoming Google Chat messages. When enabled, the bot calls a configured WordPress AI assistant and posts the response back to the space. This uses WP-Cron for async processing to avoid timeout issues.

---

#### Google Chat Rate Limits

| Limit | Value |
|-------|-------|
| Messages per space per day | 1,440 (1 per minute sustained) |
| Messages per minute (burst) | 60 |
| Spaces listing page size | 1–1,000 |
| Members listing page size | 1–1,000 |
| Message history page size | 1–1,000 |

For high-volume bots, implement exponential back-off and check the `Retry-After` header on 429 responses.

---

### Twitter/X Setup

NV oOS supports Twitter/X Direct Messages via the **Twitter API v2** using **OAuth 1.0a** authentication.

> **Prerequisites:** A Twitter Developer account with a project and app that has **Read and Write and Direct Messages** permissions. Elevated access (or Basic tier) is required for Direct Message endpoints.

#### Step 1: Create a Twitter Developer App

1. Go to [developer.twitter.com](https://developer.twitter.com/) and sign in.
2. Create a new **Project** and **App**.
3. Under **User authentication settings**, enable **OAuth 1.0a** with **Read and Write and Direct Messages** permissions.
4. Set a **Callback URL** (required even if unused, e.g., `https://yoursite.com/`).

#### Step 2: Generate Access Tokens

1. In your App dashboard, go to **Keys and Tokens**.
2. Note the **API Key** (Consumer Key) and **API Secret** (Consumer Secret).
3. Click **Generate** under **Access Token and Secret** to create tokens for your own account.
4. Note the **Access Token** and **Access Token Secret**.

#### Step 3: Configure in WordPress

1. Go to **NV oOS → Remote Connections → Add New**.
2. Set **Connection Type** to **Twitter/X (Chat Channel)**.
3. Enter:
   - **API Key** (Consumer Key)
   - **API Secret** (Consumer Secret)
   - **Access Token**
   - **Access Token Secret**
4. Click **Save**.

#### Step 4: Set Up the Account Activity Webhook (Optional)

To receive incoming DMs, register a webhook using the `manage_twitter_webhook` tool:

```json
{
  "tool": "manage_twitter_webhook",
  "arguments": {
    "action": "register",
    "webhook_url": "https://yoursite.com/wp-json/mcp-ai/v1/webhooks/twitter",
    "api_key": "your_api_key",
    "api_secret": "your_api_secret",
    "access_token": "your_access_token",
    "access_token_secret": "your_access_token_secret"
  }
}
```

#### Twitter/X Tools

| Tool Slug | Description |
|-----------|-------------|
| `get_twitter_dms` | Retrieve recent Direct Message events via API v2 |
| `send_twitter_dm` | Send a Direct Message to a Twitter/X user |
| `manage_twitter_webhook` | Register or remove Account Activity API webhooks |

---

### Office 365 Setup

NV oOS integrates with Office 365 via the **Microsoft Graph API**, enabling Outlook email management and OneDrive file operations directly from AI assistants.

> **Prerequisites:** A Microsoft Azure Active Directory (Entra ID) app registration with the following Microsoft Graph permissions: `Mail.Send`, `Mail.Read`, and `Files.ReadWrite` (which also grants read access; alternatively grant `Files.Read.All` and `Files.ReadWrite` separately for explicit read/write separation). An access token obtained via OAuth 2.0 authorization code flow or client credentials flow.

#### Step 1: Register an Azure App

1. Go to [Azure Portal → App registrations](https://portal.azure.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade).
2. Click **New registration**, enter a name, and set the redirect URI.
3. Under **API permissions**, add the following Microsoft Graph permissions:
   - `Mail.Read` – read Outlook messages
   - `Mail.Send` – send Outlook email
   - `Files.Read.All` – list and download OneDrive files
   - `Files.ReadWrite` – upload files to OneDrive
4. Click **Grant admin consent**.
5. Under **Certificates & secrets**, create a new client secret.

#### Step 2: Add to Remote Site Connections

1. Go to **NV oOS → Remote Sites → Add Connection**.
2. Set **Connection Type** to **Office 365 (Chat Channel)**.
3. Enter your **Tenant ID**, **Client ID**, and **Client Secret**.
4. Click **Save**.

The toolkit tools accept a bearer **access token** directly as a parameter. Obtain the token via the [Microsoft identity platform OAuth 2.0 flows](https://learn.microsoft.com/en-us/azure/active-directory/develop/v2-oauth2-auth-code-flow) or your application's token cache.

#### Step 3: Configure the Settings Page

Navigate to **NV oOS → Chat Channels Toolkit** and expand the **Office 365** section. Enter your app credentials; the plugin stores them securely.

#### Office 365 Tools

**Outlook (email):**

| Tool Slug | Description |
|-----------|-------------|
| `send_outlook_mail` | Send an email via Microsoft Outlook; supports `text` or `html` body, CC |
| `get_outlook_messages` | Retrieve messages from inbox, sent items, drafts, or a custom folder ID |

**Example – send an Outlook email:**

```json
{
  "tool": "send_outlook_mail",
  "arguments": {
    "token": "eyJ...<Graph API bearer token>",
    "to_email": "recipient@example.com",
    "subject": "Hello from NV oOS",
    "body": "<b>This email was sent by an AI assistant.</b>",
    "content_type": "html"
  }
}
```

**OneDrive (file storage):**

| Tool Slug | Description |
|-----------|-------------|
| `list_onedrive_files` | List files and folders in the authenticated user's OneDrive |
| `get_onedrive_file` | Download a OneDrive file by its item ID |
| `upload_onedrive_file` | Upload content to a OneDrive folder |

#### Security Notes

- Bearer tokens must be obtained by your application through a secure OAuth 2.0 flow. Never hard-code tokens.
- Tokens are passed as tool arguments and are not stored by the plugin.
- The `send_outlook_mail` and `get_outlook_messages` tools require `manage_options` capability by default; use the `wp_mcp_ai_send_outlook_mail_capability` / `wp_mcp_ai_get_outlook_messages_capability` filters to adjust.

---

### iCloud Drive Setup

NV oOS communicates with iCloud Drive via a **user-configured HTTPS gateway service**. Apple does not expose a public third-party REST API for iCloud Drive; instead, you run (or subscribe to) a gateway that bridges requests to Apple's **CloudKit** or iCloud services.

> **References:** [Apple CloudKit documentation](https://developer.apple.com/documentation/cloudkit) | [Apple iCloud developer resources](https://developer.apple.com/icloud/)

#### What Is an iCloud Gateway?

A gateway is an HTTPS service that:
1. Accepts standard REST requests from the NV oOS plugin.
2. Translates them into CloudKit Web Services API or proprietary iCloud Drive calls using your Apple Developer credentials.
3. Returns a normalised JSON response.

You can build your own gateway (e.g. using Apple's [CloudKit JS](https://developer.apple.com/documentation/cloudkitjs) or [CloudKit Web Services](https://developer.apple.com/library/archive/documentation/DataManagement/Conceptual/CloudKitWebServicesReference/index.html)) or use a compatible self-hosted proxy. A minimal gateway must expose three REST endpoints that the iCloud tools call:

| HTTP Method | Path (example) | Purpose |
|-------------|----------------|---------|
| `GET` | `/api/files?folderId=&limit=&offset=` | List files/folders |
| `GET` | `/api/files/{fileId}` | Download a file |
| `PUT` / `POST` | `/api/files/{folderId}/upload` | Upload a file |

The gateway should accept a `Bearer` token via the `Authorization` header and return JSON responses. Failed requests should return a non-2xx HTTP status code with a JSON body containing a `message`, `error`, `errorMessage`, or `detail` key describing the error.

#### Step 1: Configure Your Gateway

1. Deploy an HTTPS gateway that exposes endpoints compatible with the iCloud tools (list files, get file, upload file).
2. Secure the gateway with an API key or bearer token.
3. Ensure the gateway URL is accessible from your WordPress server.

#### Step 2: Configure the Settings Page

1. Go to **NV oOS → Chat Channels Toolkit** and expand the **iCloud Drive** section.
2. Enter your **gateway URL** and **API key**.
3. Click **Save**.

#### Step 3: Use the Tools

All iCloud Drive tools accept a `gateway_url` (HTTPS) and `api_key` parameter.

#### iCloud Drive Tools

| Tool Slug | Description |
|-----------|-------------|
| `list_icloud_drive_files` | List files and folders (supports `folder_id`, `limit`, pagination cursor) |
| `get_icloud_drive_file` | Download a file by its identifier |
| `upload_icloud_drive_file` | Upload content to a folder |

**Example – list root files:**

```json
{
  "tool": "list_icloud_drive_files",
  "arguments": {
    "gateway_url": "https://my-icloud-gateway.example.com/api/files",
    "api_key": "sk-...",
    "limit": 25
  }
}
```

#### Security Notes

- The `gateway_url` must be a valid **HTTPS** URL; HTTP is rejected.
- `api_key` / bearer tokens are passed as tool arguments and are not stored by the plugin.
- All iCloud tools require `manage_options` capability by default; use the `wp_mcp_ai_list_icloud_drive_files_capability` / `wp_mcp_ai_get_icloud_drive_file_capability` / `wp_mcp_ai_upload_icloud_drive_file_capability` filters to adjust.

---

## Usage Examples

### Example 1: Customer Support Bot

Handle incoming support requests across all platforms with AI-powered responses.

**Scenario:** Customer sends message on Telegram, WhatsApp, or Messenger. AI assistant processes and responds.

**Assistant Configuration:**

```
System Prompt:
You are a helpful customer support assistant. Respond to customer inquiries professionally and helpfully. Use the available chat channel tools to send responses.

When a customer asks a question:
1. Analyze their inquiry
2. Provide a helpful answer
3. Use send_chat_message to respond on the same platform they used
4. If needed, escalate to human support
```

**Webhook Handler (Automatic):**

When message arrives → Webhook processes → AI Assistant analyzes → Response sent

**Manual Testing:**

```json
{
  "tool": "send_chat_message",
  "arguments": {
    "platform": "telegram",
    "channel_id": "-1001234567890",
    "message": "Thank you for contacting support! How can I help you today?"
  }
}
```

---

### Example 2: Multi-Platform Announcement

Send same announcement to all your community channels.

```json
{
  "tool": "send_multiplatform_message",
  "arguments": {
    "platforms": ["telegram", "discord", "slack"],
    "channel_ids": {
      "telegram": "-1001234567890",
      "discord": "123456789012345678",
      "slack": "C01234ABCD"
    },
    "message": "🎉 **Big Announcement!**\n\nWe're launching our new feature tomorrow at 10 AM EST!\n\nStay tuned for more details.",
    "media_url": "https://yoursite.com/wp-content/uploads/announcement.jpg",
    "media_type": "image"
  }
}
```

---

### Example 3: Interactive Poll

Create a poll with interactive buttons on Telegram.

```json
{
  "tool": "send_interactive_message",
  "arguments": {
    "platform": "telegram",
    "channel_id": "-1001234567890",
    "message": "📊 **Quick Poll**: What feature do you want next?",
    "buttons": [
      [
        { "text": "🎨 Dark Mode", "callback_data": "vote_darkmode" },
        { "text": "🔔 Push Notifications", "callback_data": "vote_notifications" }
      ],
      [
        { "text": "📱 Mobile App", "callback_data": "vote_mobileapp" },
        { "text": "🌐 API Access", "callback_data": "vote_api" }
      ],
      [
        { "text": "📊 View Results", "callback_data": "poll_results" }
      ]
    ]
  }
}
```

**Handle button clicks:**

```php
// Webhook automatically calls handle_callback_query
// You can customize response in your assistant's prompt
```

---

### Example 4: WhatsApp Template Message Campaign

Send promotional message using approved template.

```json
{
  "tool": "send_chat_message",
  "arguments": {
    "platform": "whatsapp",
    "channel_id": "1234567890",
    "template": {
      "name": "product_launch",
      "language": "en_US",
      "components": [
        {
          "type": "header",
          "parameters": [
            {
              "type": "image",
              "image": {
                "link": "https://yoursite.com/product-image.jpg"
              }
            }
          ]
        },
        {
          "type": "body",
          "parameters": [
            { "type": "text", "text": "John" },
            { "type": "text", "text": "Premium Plan" },
            { "type": "text", "text": "30%" }
          ]
        },
        {
          "type": "button",
          "sub_type": "url",
          "index": "0",
          "parameters": [
            { "type": "text", "text": "ABC123" }
          ]
        }
      ]
    }
  }
}
```

---

### Example 5: Analytics Dashboard

Get engagement metrics across platforms.

```javascript
// Get Telegram analytics
const telegramStats = await callTool('get_chat_analytics', {
  platform: 'telegram',
  channel_id: '-1001234567890',
  date_from: '2025-01-01T00:00:00Z',
  date_to: '2025-01-13T23:59:59Z',
  metrics: ['messages', 'members', 'engagement']
});

// Get Slack analytics
const slackStats = await callTool('get_chat_analytics', {
  platform: 'slack',
  channel_id: 'C01234ABCD',
  date_from: '2025-01-01T00:00:00Z',
  date_to: '2025-01-13T23:59:59Z',
  metrics: ['messages', 'members', 'engagement']
});

// Compare and visualize
console.log('Cross-Platform Engagement:');
console.log(`Telegram: ${telegramStats.analytics.engagement_rate * 100}%`);
console.log(`Slack: ${slackStats.analytics.engagement_rate * 100}%`);
```

---

### Example 6: Auto-Translation Service

Automatically translate messages for multilingual communities.

```json
{
  "tool": "auto_translate_message",
  "arguments": {
    "platform": "telegram",
    "channel_id": "-1001234567890",
    "source_language": "auto",
    "target_language": "en",
    "message": "Hola a todos! ¿Cómo están hoy?"
  }
}
```

**Response:**
```json
{
  "success": true,
  "original_text": "Hola a todos! ¿Cómo están hoy?",
  "translated_text": "Hello everyone! How are you today?",
  "detected_language": "es",
  "confidence": 0.98
}
```

**Auto-translate workflow:**
1. Webhook receives message in Spanish
2. AI detects language
3. Translates to English
4. Posts translation as reply

---

## Webhook Configuration

### Overview

Webhooks enable real-time event processing. When something happens on a chat platform (message sent, button clicked, member joined), the platform sends an HTTP request to your webhook endpoint.

### Webhook Endpoints

| Platform | Endpoint |
|----------|----------|
| Telegram | `/wp-json/mcp-ai/v1/webhooks/telegram` |
| WhatsApp | `/wp-json/mcp-ai/v1/webhooks/whatsapp` |
| Slack | `/wp-json/mcp-ai/v1/webhooks/slack` |
| Discord | `/wp-json/mcp-ai/v1/webhooks/discord` |
| Microsoft Teams | `/wp-json/mcp-ai/v1/webhooks/teams` |
| Facebook Messenger | `/wp-json/mcp-ai/v1/webhooks/messenger` |
| Apple Messages for Business | `/wp-json/mcp-ai/v1/webhooks/apple-messages` |
| Apple Messages (per connection) | `/wp-json/mcp-ai/v1/webhooks/apple-messages/{connection_id}` |
| Google Chat | `/wp-json/mcp-ai/v1/webhooks/google-chat` |
| Twitter/X Account Activity | `/wp-json/mcp-ai/v1/webhooks/twitter` |

### Setting Up Webhooks

#### Requirements

1. **HTTPS Required**: All platforms require HTTPS (Let's Encrypt is fine)
2. **Valid SSL Certificate**: Self-signed certificates not accepted
3. **Public Endpoint**: Must be accessible from internet
4. **Fast Response**: Respond within 3-10 seconds (varies by platform)

#### Testing Webhooks

**Test Telegram webhook:**
```bash
curl -X POST "https://api.telegram.org/bot<YOUR_BOT_TOKEN>/setWebhook" \
  -H "Content-Type: application/json" \
  -d '{
    "url": "https://yoursite.com/wp-json/mcp-ai/v1/webhooks/telegram",
    "allowed_updates": ["message", "edited_message", "callback_query"]
  }'
```

**Check webhook status:**
```bash
curl "https://api.telegram.org/bot<YOUR_BOT_TOKEN>/getWebhookInfo"
```

**Delete webhook (for testing):**
```bash
curl "https://api.telegram.org/bot<YOUR_BOT_TOKEN>/deleteWebhook"
```

### Webhook Security

#### Signature Verification

Each platform uses different signature verification:

**Telegram:**
- No signature, verify via HTTPS and secret token in URL

**WhatsApp:**
- Uses `X-Hub-Signature-256` header
- HMAC SHA-256 with app secret

**Slack:**
- Uses `X-Slack-Signature` header
- HMAC SHA-256 with timestamp

**Discord:**
- Uses `X-Signature-Ed25519` header
- Ed25519 signature verification

**Microsoft Teams:**
- JWT token in `Authorization` header
- Verify with Microsoft's public keys

**Facebook Messenger:**
- Uses `X-Hub-Signature` header
- HMAC SHA-1 with app secret

**Apple Messages for Business (via MSP):**
- Uses `X-Apple-Messages-Signature`, `X-MSP-Signature`, or `X-Hub-Signature-256` header (varies by MSP)
- HMAC SHA-256 with MSP-supplied webhook secret
- The plugin automatically checks all common header names and strips the optional `sha256=` prefix

**Google Chat:**
- Uses OIDC Bearer token in the `Authorization` header
- NV oOS verifies the token against Google's public keys automatically
- Optionally validates the token `aud` (audience) claim against the **Audience URL** configured in the connection settings
- No manual signature code required — handled by `WP_MCP_AI_Google_Chat_Webhook_Controller`

**Twitter/X Account Activity:**
- Uses `X-Twitter-Webhooks-Signature` (HMAC SHA-256 with consumer secret)
- CRC challenge/response for webhook registration is handled automatically by `manage_twitter_webhook`

#### Example Verification (PHP)

```php
// WhatsApp signature verification
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$payload = file_get_contents('php://input');
$expected = 'sha256=' . hash_hmac('sha256', $payload, $app_secret);

if (!hash_equals($expected, $signature)) {
    http_response_code(403);
    die('Invalid signature');
}
```

### Webhook Event Types

#### Telegram
- `message` - New message
- `edited_message` - Message edited
- `callback_query` - Button clicked
- `inline_query` - Inline query
- `my_chat_member` - Bot added/removed from chat

#### WhatsApp
- `messages` - New message received
- `message_status` - Delivery/read status update

#### Slack
- `message` - Message posted
- `app_mention` - Bot mentioned
- `reaction_added` - Emoji reaction
- `member_joined_channel` - User joined channel

#### Discord
- `MESSAGE_CREATE` - Message sent
- `MESSAGE_UPDATE` - Message edited
- `GUILD_MEMBER_ADD` - Member joined server
- `INTERACTION_CREATE` - Slash command or button

#### Apple Messages for Business
- `message` - Customer sent a text message
- `interactive` - Customer responded to a list picker, time picker, or authentication request
- `typing` - Customer is composing a message (presence indicator)
- `read` - Customer read a delivered message
- `close` - Customer closed the conversation (must stop sending messages per Apple policy)

#### Google Chat
- `MESSAGE` - User sent a message to the bot in a space or DM
- `ADDED_TO_SPACE` - Bot was added to a space
- `REMOVED_FROM_SPACE` - Bot was removed from a space
- `CARD_CLICKED` - User clicked an interactive card action

#### Twitter/X Account Activity
- `direct_message_events` - Incoming DM received
- `direct_message_indicate_typing_events` - Typing indicator in DM
- `tweet_create_events` - Mention or tweet from a followed user

### Handling Webhook Delays

Platforms require fast responses. If processing takes >3 seconds:

1. **Acknowledge immediately** (200 OK)
2. **Process asynchronously** (WordPress cron or background job)
3. **Send response separately** via API

```php
// Quick acknowledge
http_response_code(200);
echo json_encode(['ok' => true]);
fastcgi_finish_request(); // Close connection

// Now process in background
wp_schedule_single_event(time(), 'process_webhook_event', [$event_data]);
```

---

## Security & Authentication

### API Credentials Storage

**Never hardcode credentials!**

Store securely in WordPress options:
- Encrypted at rest
- Never exposed in frontend
- Separate credentials per environment

### Access Control

Implement capability checks:

```php
if (!current_user_can('manage_options')) {
    return new WP_Error('permission_denied', 'Insufficient permissions');
}
```

### Rate Limiting

Prevent abuse with rate limiting:

```php
// Check rate limit (100 requests per hour per user)
$key = 'chat_tool_rate_limit_' . get_current_user_id();
$count = get_transient($key) ?: 0;

if ($count >= 100) {
    return new WP_Error('rate_limited', 'Too many requests');
}

set_transient($key, $count + 1, HOUR_IN_SECONDS);
```

### GDPR Compliance

1. **User Consent**: Obtain consent before processing messages
2. **Data Retention**: Set retention policies (default: 30 days)
3. **Right to Erasure**: Implement data deletion
4. **Data Portability**: Allow export of chat data

### Audit Logging

Log all activities for security auditing:

```php
wp_mcp_ai_log_activity([
    'action' => 'chat_message_sent',
    'platform' => 'telegram',
    'user_id' => get_current_user_id(),
    'channel_id' => $channel_id,
    'timestamp' => current_time('mysql')
]);
```

---

## Rate Limiting & Quotas

### Platform Limits

| Platform | Limit | Scope |
|----------|-------|-------|
| **Telegram** | 30 msg/sec | Per chat |
| | 20 msg/min | To same group |
| **WhatsApp** | Tier-based | See WhatsApp Setup |
| **Slack** | Tier 1: 1 msg/sec | Per workspace |
| | Tier 2: 20 msg/sec | After approval |
| **Discord** | 5 msg/5sec | Per channel |
| | 50 msg/sec | Per bot |
| **Teams** | 300 req/30sec | Per app per tenant |
| **Messenger** | 25 API calls/sec | Per page |

### Implementing Rate Limiting

```php
function check_rate_limit($platform, $identifier) {
    $limits = [
        'telegram' => ['rate' => 30, 'period' => 1], // 30 per second
        'whatsapp' => ['rate' => 80, 'period' => 1],
        'slack' => ['rate' => 1, 'period' => 1],
        'discord' => ['rate' => 5, 'period' => 5],
    ];
    
    $limit = $limits[$platform];
    $key = "rate_limit_{$platform}_{$identifier}";
    
    $count = wp_cache_get($key) ?: 0;
    
    if ($count >= $limit['rate']) {
        // Wait until period expires
        sleep($limit['period']);
        wp_cache_delete($key);
        $count = 0;
    }
    
    wp_cache_set($key, $count + 1, '', $limit['period']);
}
```

### Handling Rate Limit Errors

```php
try {
    send_chat_message($platform, $channel, $message);
} catch (RateLimitException $e) {
    // Implement exponential backoff
    $wait_time = pow(2, $e->getRetryAfter());
    sleep($wait_time);
    
    // Retry
    send_chat_message($platform, $channel, $message);
}
```

---

## GDPR & Compliance

### Data Processing

Under GDPR, chat data is **personal data**. You must:

1. **Legal Basis**: Have lawful basis for processing (consent, contract, legitimate interest)
2. **Transparency**: Inform users how data is processed
3. **Purpose Limitation**: Only use data for stated purposes
4. **Data Minimization**: Collect only necessary data
5. **Storage Limitation**: Delete data when no longer needed
6. **Security**: Implement appropriate security measures

### Privacy Policy Requirements

Your privacy policy must disclose:

- What chat data you collect
- Why you collect it
- How long you retain it
- Who you share it with (platform providers)
- User rights (access, rectification, erasure)

### User Rights Implementation

#### Right to Access
```php
function export_user_chat_data($user_id) {
    global $wpdb;
    
    $messages = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}chat_messages WHERE user_id = %d",
        $user_id
    ));
    
    return json_encode($messages, JSON_PRETTY_PRINT);
}
```

#### Right to Erasure
```php
function delete_user_chat_data($user_id) {
    global $wpdb;
    
    // Delete from local database
    $wpdb->delete(
        "{$wpdb->prefix}chat_messages",
        ['user_id' => $user_id],
        ['%d']
    );
    
    // Request deletion from platform APIs (where supported)
    // Note: Most platforms don't support message deletion via API
}
```

### Data Retention

Set automatic data retention:

```php
// Delete chat logs older than 90 days
add_action('wp_mcp_ai_daily_cleanup', function() {
    global $wpdb;
    
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}chat_messages 
         WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)"
    ));
});
```

### Compliance Checklist

- [ ] Privacy policy updated
- [ ] User consent obtained
- [ ] Data retention policy configured
- [ ] Access request handling implemented
- [ ] Deletion request handling implemented
- [ ] Data breach notification procedure established
- [ ] DPA with platform providers reviewed
- [ ] Security measures documented
- [ ] Regular audits scheduled

---

## Best Practices

### 1. Error Handling

Always handle errors gracefully:

```php
try {
    $result = send_chat_message($platform, $channel, $message);
    
    if (is_wp_error($result)) {
        wp_mcp_ai_log_error([
            'tool' => 'send_chat_message',
            'error' => $result->get_error_message(),
            'context' => compact('platform', 'channel')
        ]);
        
        // Fallback action
        notify_admin_of_failure($result);
    }
} catch (Exception $e) {
    wp_mcp_ai_log_error([
        'exception' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
```

### 2. Message Formatting

Respect platform-specific formatting:

```php
function format_message_for_platform($message, $platform) {
    switch ($platform) {
        case 'telegram':
            // Telegram supports Markdown and HTML
            return format_markdown($message);
            
        case 'slack':
            // Slack uses mrkdwn format
            return format_slack_mrkdwn($message);
            
        case 'discord':
            // Discord supports Markdown
            return format_markdown($message);
            
        case 'whatsapp':
        case 'teams':
        case 'messenger':
            // Plain text or limited formatting
            return strip_tags($message);
    }
}
```

### 3. Idempotency

Prevent duplicate messages:

```php
function send_chat_message_idempotent($platform, $channel, $message) {
    // Generate unique ID
    $idempotency_key = md5($platform . $channel . $message . time());
    
    // Check if already sent
    if (get_transient("sent_{$idempotency_key}")) {
        return ['success' => true, 'cached' => true];
    }
    
    $result = send_chat_message($platform, $channel, $message);
    
    // Cache for 1 hour
    set_transient("sent_{$idempotency_key}", true, HOUR_IN_SECONDS);
    
    return $result;
}
```

### 4. Monitoring & Alerting

Monitor system health:

```php
// Alert on high error rate
function check_error_rate() {
    $error_count = get_option('chat_errors_last_hour', 0);
    
    if ($error_count > 100) {
        wp_mail(
            get_option('admin_email'),
            'High Error Rate Alert',
            "Chat channels experiencing {$error_count} errors in last hour"
        );
    }
}
```

### 5. Testing

Always test in development first:

```php
// Use test credentials in development
if (wp_get_environment_type() === 'development') {
    $bot_token = TELEGRAM_TEST_BOT_TOKEN;
    $test_channel = TELEGRAM_TEST_CHANNEL;
} else {
    $bot_token = get_option('telegram_bot_token');
}
```

### 6. Graceful Degradation

If a platform is unavailable:

```php
function send_multiplatform_message_resilient($platforms, $message) {
    $results = [];
    $succeeded = [];
    $failed = [];
    
    foreach ($platforms as $platform) {
        try {
            $result = send_chat_message($platform, $channel, $message);
            
            if ($result['success']) {
                $succeeded[] = $platform;
            } else {
                $failed[] = $platform;
            }
        } catch (Exception $e) {
            $failed[] = $platform;
            wp_mcp_ai_log_error([
                'platform' => $platform,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    // Success if at least one platform worked
    return [
        'success' => !empty($succeeded),
        'succeeded' => $succeeded,
        'failed' => $failed
    ];
}
```

### 7. Documentation

Document your chat bot's capabilities:

```
/start - Start conversation with the bot
/help - Display help message
/support - Contact human support
/status - Check system status
/settings - Manage your preferences
```

---

## Troubleshooting

### Common Issues

#### Issue: Webhook Not Receiving Events

**Symptoms:**
- No messages arriving from platform
- Webhook shows as "not set" in platform dashboard

**Solutions:**

1. **Check HTTPS**: Ensure site uses valid SSL certificate
   ```bash
   curl -I https://yoursite.com
   ```

2. **Verify webhook URL**: Test endpoint directly
   ```bash
   curl -X POST https://yoursite.com/wp-json/mcp-ai/v1/webhooks/telegram \
     -H "Content-Type: application/json" \
     -d '{"test": true}'
   ```

3. **Check firewall**: Ensure platform IPs not blocked

4. **Review logs**: Enable debug logging
   ```php
   define('WP_MCP_AI_DEBUG', true);
   ```

5. **Test webhook**: Use platform's test feature
   - Telegram: Send `/setWebhook` again
   - Slack: Click "Verify" in Event Subscriptions
   - Discord: Check Interactions Endpoint URL validation

---

#### Issue: Messages Not Sending

**Symptoms:**
- `send_chat_message` returns error
- Messages appear sent but not received

**Solutions:**

1. **Verify credentials**: Check API tokens are correct
   ```bash
   # Telegram
   curl "https://api.telegram.org/bot<TOKEN>/getMe"
   
   # Slack
   curl -H "Authorization: Bearer xoxb-YOUR-TOKEN" \
     "https://slack.com/api/auth.test"
   ```

2. **Check permissions**: Ensure bot has necessary permissions
   - Telegram: Bot must be admin to post in channels
   - Slack: Check bot scopes
   - Discord: Verify channel permissions

3. **Validate channel IDs**: Ensure correct format
   - Telegram: Negative for supergroups (-1001234567890)
   - Slack: Starts with C or D (C01234ABCD)
   - Discord: Numeric string (123456789012345678)

4. **Test with simple message**: Remove formatting, media
   ```json
   {
     "platform": "telegram",
     "channel_id": "-1001234567890",
     "message": "test"
   }
   ```

---

#### Issue: Rate Limiting Errors

**Symptoms:**
- HTTP 429 Too Many Requests
- Platform returns "retry_after" value

**Solutions:**

1. **Enable rate limiting**: In plugin settings
   ```php
   update_option('wp_mcp_ai_enable_rate_limiting', true);
   ```

2. **Implement backoff**: Wait before retrying
   ```php
   if ($response['error_code'] === 429) {
       sleep($response['retry_after']);
       retry_request();
   }
   ```

3. **Reduce frequency**: Batch messages or increase delay

4. **Upgrade tier**: Request higher limits from platform

---

#### Issue: Authentication Failures

**Symptoms:**
- 401 Unauthorized
- 403 Forbidden
- "Invalid token" errors

**Solutions:**

1. **Regenerate token**: Get fresh token from platform

2. **Check token format**:
   - Telegram: `1234567890:ABCdefGHI...`
   - Slack: `xoxb-...`
   - WhatsApp: Long alphanumeric string
   - Discord: Long alphanumeric string

3. **Verify token scope**: Ensure token has required permissions

4. **Check expiry**: Some tokens expire (Teams, WhatsApp)
   ```php
   // Refresh expired token
   if ($response['error'] === 'token_expired') {
       refresh_access_token();
   }
   ```

---

#### Issue: Webhook Signature Verification Fails

**Symptoms:**
- 403 Forbidden on webhook endpoint
- "Invalid signature" log entries

**Solutions:**

1. **Verify secret**: Ensure correct secret in settings

2. **Check signature algorithm**: Different platforms use different methods

3. **Debug signature**:
   ```php
   wp_mcp_ai_log_debug([
       'received_signature' => $_SERVER['HTTP_X_HUB_SIGNATURE_256'],
       'calculated_signature' => $calculated,
       'payload' => $payload
   ]);
   ```

4. **Temporarily disable**: For testing only
   ```php
   // In webhook handler (remove in production!)
   // return true; // Skip verification
   ```

---

### Debug Mode

Enable comprehensive debug logging:

```php
// In wp-config.php
define('WP_MCP_AI_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

View logs:
```bash
tail -f /wp-content/debug.log | grep "WP_MCP_AI"
```

---

### Getting Help

1. **Check documentation**: Review this guide and platform docs
2. **Search issues**: [GitHub Issues](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues)
3. **Community support**: WordPress support forums
4. **Platform support**: Contact platform directly for API issues
5. **Pro support**: Available for enterprise customers

---

## Appendix

### Useful Resources

#### Official Documentation
- [Telegram Bot API](https://core.telegram.org/bots/api)
- [WhatsApp Business Platform](https://developers.facebook.com/docs/whatsapp)
- [Slack API](https://api.slack.com/)
- [Discord Developer Portal](https://discord.com/developers/docs)
- [Microsoft Graph API](https://learn.microsoft.com/en-us/graph/api/overview)
- [Messenger Platform](https://developers.facebook.com/docs/messenger-platform)

#### Testing Tools
- [Postman](https://www.postman.com/) - API testing
- [ngrok](https://ngrok.com/) - Local webhook testing
- [RequestBin](https://requestbin.com/) - Webhook debugging
- [Regex101](https://regex101.com/) - Pattern testing

#### Libraries & SDKs
- [php-telegram-bot](https://github.com/php-telegram-bot/core)
- [Guzzle HTTP Client](https://github.com/guzzle/guzzle)
- [Firebase JWT](https://github.com/firebase/php-jwt) (for Teams)

---

## Changelog

### Version 2.0.0 (February 2026)

**New Platforms:**
- ✅ **Google Chat / Google Spaces** – 7 new tools (`send_google_chat_message`, `get_google_chat_spaces`, `get_google_chat_messages`, `create_google_chat_space`, `add_google_chat_space_member`, `list_google_chat_space_members`, `remove_google_chat_space_member`)
- ✅ **Twitter/X** – 3 new tools (`get_twitter_dms`, `send_twitter_dm`, `manage_twitter_webhook`)
- ✅ **WebChat** – 1 new tool (`send_webchat_message`)

**Platform Enhancements:**
- ✅ **Telegram** – Added `add_telegram_message_reaction` and `manage_telegram_webhook`
- ✅ **WhatsApp** – Added `send_whatsapp_interactive`, `send_whatsapp_media`, `send_whatsapp_template` (expanded from 1 to 4 tools)
- ✅ **Discord** – Added `create_discord_channel`, `add_discord_message_reaction`, `get_discord_voice_channel_members`
- ✅ **Facebook Messenger** – Added `create_messenger_broadcast` and `get_messenger_conversations`
- ✅ **Apple Messages** – Added `send_apple_message_group` and `send_apple_message_interactive`

**Documentation:**
- ✅ Added comprehensive Google Chat / Google Spaces setup guide (Service Account, Incoming Webhook, OAuth 2.0)
- ✅ Added Twitter/X setup guide
- ✅ Updated complete tool inventory table (39 tools across 9 platforms + 2 utility)
- ✅ Added Google Service Account JWT authentication documentation
- ✅ Added webhook event type tables for all platforms

**Architecture:**
- ✅ `WP_MCP_AI_Pro_Google_Service_Account` helper class for JWT-based Service Account authentication with transient caching
- ✅ `WP_MCP_AI_Google_Chat_Webhook_Controller` for OIDC-verified incoming Google Chat events
- ✅ AI auto-reply via WP-Cron for incoming Google Chat events (no timeout risk)
- ✅ Support for both direct Chat app events and Google Workspace Add-ons event format

### Version 1.0.0 (January 2025)
- Initial release
- Support for 6 platforms: Telegram, WhatsApp, Slack, Discord, Microsoft Teams, Facebook Messenger
- 21 tools for comprehensive chat management
- Webhook integration for real-time events
- Multi-platform broadcasting
- AI assistant integration
- Rate limiting and quota management
- GDPR compliance features

---

## Credits

**Research & Development:** Based on OpenClaw.ai's extensive experience with multi-platform chat integrations across enterprise deployments.

**Plugin Development:** NV Digital Solutions

**Contributors:** WordPress community, platform API documentation, and real-world implementation feedback.

---

## License

GPLv3 or later - See LICENSE file in repository root.

---

**Need Help?**  
Visit: [https://github.com/nvdigitalsolutions/mcp-ai-wpoos](https://github.com/nvdigitalsolutions/mcp-ai-wpoos)  
Issues: [https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues)
