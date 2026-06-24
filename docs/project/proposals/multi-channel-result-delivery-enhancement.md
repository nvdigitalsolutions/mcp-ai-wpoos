# Multi-Channel Result Delivery Enhancement — Comprehensive Plan

> **Status:** 📋 Proposed — awaiting review and prioritisation
> **Author:** AI Coding Agent (audit + research)
> **Date:** 2026-06-24
> **Related:** `schedule-result-delivery-pipeline.md` (Phase 3 — UI), `class-wp-mcp-ai-result-delivery-service.php`, `schedule-manager.js`
> **Target Pages:**
> - `/wp-admin/admin.php?page=nvoos-pro-schedule-manager` — Edit modal Result Delivery section
> - `/wp-admin/admin.php?page=wp-mcp-ai-remote-sites` — Connection credential source

---

## 1. Executive Summary

The Pro Schedule Manager's **Result Delivery** pipeline is fully implemented on the backend — the `WP_MCP_AI_Result_Delivery_Service` supports **11 delivery channels** (email, slack, telegram, discord, teams, messenger, whatsapp, sms, paper_store, webhook, wordpress), each with per-channel templating, isolated error handling, and delivery audit logging. However, the **admin UI** only exposes **4 channels** (Email, Slack, Paper Store, WordPress Post) in the schedule edit modal, with only Email available for failure notifications.

**The problem**: A user opening `/wp-admin/admin.php?page=nvoos-pro-schedule-manager`, clicking a schedule, and scrolling to "Result Delivery" sees:

| Result Delivery ||
|---|---|
| **On Success** ||
| Email | Send result email |
| **Slack** | **Post to Slack** |
| Paper Store | Save to Paper Store |
| WordPress Post | Auto-create post from result |
| **On Failure** ||
| Failure Email | Send failure alert |

All other channels — Telegram, Discord, Microsoft Teams, Facebook Messenger, WhatsApp, SMS, Webhook — are invisible despite being fully functional on the backend.

**The solution**: A UI expansion in the schedule edit modal to surface all supported channels, a credential resolution system that references Remote Sites connections to avoid credential duplication, and the addition of Google Chat as a supported delivery channel.

### Industry validation

| System / Pattern | Key insight adopted |
|---|---|
| **Notification Master (WP plugin)** | Carrier architecture — each delivery method is a pluggable "carrier" with its own config; 20+ extensions for Slack, Discord, Push, SMS |
| **Zapier / Make.com** | Multi-step "zaps" fan out one trigger to N actions; every action has its own auth, template, and error handling |
| **n8n** | Multi-channel notification nodes as first-class primitives; each channel independently configured |
| **BracketSpace Notification** | Extensible carrier pattern; "Notification → Slack" and "Notification → Discord" are separate installable extensions |
| **Activity Guard (WP plugin)** | Single settings page configures Slack webhook, Telegram bot, and email notification channels for real-time alerts |
| **Pipedream** | Each channel integration has its own credential store; workflows reference connections by ID |

---

## 2. Current State — What Exists vs What's Exposed

### 2.1 Backend Implementation Status (✅ = Complete)

| Component | File | Status |
|---|---|---|
| `SUPPORTED_CHANNELS` constant (11 channels) | `class-wp-mcp-ai-result-delivery-service.php:40` | ✅ |
| `sanitize_delivery_channels()` — validates all 11 channels | `class-wp-mcp-ai-pro-schedule-manager.php:2941` | ✅ |
| `sanitize_result_delivery()` — success + failure split | `class-wp-mcp-ai-pro-schedule-manager.php:2914` | ✅ |
| `WP_MCP_AI_Result_Delivery_Service` — orchestration | `class-wp-mcp-ai-result-delivery-service.php` | ✅ |
| `deliver_success()` / `deliver_failure()` | `class-wp-mcp-ai-result-delivery-service.php:90,115` | ✅ |
| `deliver_to_channels()` — fan-out with per-channel try/catch | `class-wp-mcp-ai-result-delivery-service.php:157` | ✅ |
| `format_for_channel()` — per-channel templates | `class-wp-mcp-ai-result-delivery-service.php:266` | ✅ |
| `send_to_channel()` — routes to correct backend | `class-wp-mcp-ai-result-delivery-service.php:506` | ✅ |
| `send_chat()` — unified_channel_broadcast integration | `class-wp-mcp-ai-result-delivery-service.php:599` | ✅ |
| `send_sms()` — SMS backend | `class-wp-mcp-ai-result-delivery-service.php:653` | ✅ |
| `send_webhook()` — webhook backend | `class-wp-mcp-ai-result-delivery-service.php:815` | ✅ |
| `send_email()` — Nodemailer + wp_mail | `class-wp-mcp-ai-result-delivery-service.php:548` | ✅ |
| `send_paper_store()` — Paper Store backend | `class-wp-mcp-ai-result-delivery-service.php:685` | ✅ |
| `send_wordpress_post()` — wp_insert_post | `class-wp-mcp-ai-result-delivery-service.php:752` | ✅ |
| `log_delivery()` — per-channel audit | `class-wp-mcp-ai-result-delivery-service.php:869` | ✅ |
| `append_delivery_to_history()` — run history tracking | `class-wp-mcp-ai-pro-schedule-manager.php:3085` | ✅ |
| `unified_channel_broadcast` tool — 6 chat channels | `class-wp-mcp-ai-pro-tool-unified-channel-broadcast.php` | ✅ |
| Per-channel send tools (send_slack_message, etc.) | `tools/chat-channels/` | ✅ |
| Wire into `dispatch()` | `class-wp-mcp-ai-pro-schedule-manager.php:956` | ✅ |

### 2.2 UI Implementation Status

| Component | File | Status |
|---|---|---|
| Edit modal — "Result Delivery" section | `schedule-manager.js:1078` | ⚠️ Partial |
| Edit modal — On Success: Slack row | `schedule-manager.js:1092` | ✅ |
| Edit modal — On Success: Email row | `schedule-manager.js:1086` | ✅ |
| Edit modal — On Success: Paper Store row | `schedule-manager.js:1097` | ✅ |
| Edit modal — On Success: WordPress row | `schedule-manager.js:1107` | ✅ |
| **Edit modal — On Success: Telegram** | — | ❌ Missing |
| **Edit modal — On Success: Discord** | — | ❌ Missing |
| **Edit modal — On Success: Microsoft Teams** | — | ❌ Missing |
| **Edit modal — On Success: Messenger** | — | ❌ Missing |
| **Edit modal — On Success: WhatsApp** | — | ❌ Missing |
| **Edit modal — On Success: SMS** | — | ❌ Missing |
| **Edit modal — On Success: Webhook** | — | ❌ Missing |
| Edit modal — On Failure: Email row | `schedule-manager.js:1119` | ✅ |
| **Edit modal — On Failure: all chat channels** | — | ❌ Missing |
| Create form — channel_broadcast type (6 channels) | `class-wp-mcp-ai-section-schedule-manager.php:544` | ✅ (but only for broadcast type) |
| Create form — Result Delivery section | — | ❌ Missing entirely |
| Schedule presets — result_delivery defaults | `class-wp-mcp-ai-pro-schedule-presets.php` | ⚠️ Partial |

### 2.3 Channel Support Matrix — Backend vs UI

| Channel | Backend `SUPPORTED_CHANNELS` | Backend `sanitize_delivery_channels` | `unified_channel_broadcast` | `send_to_channel` switch | Edit Modal UI |
|---|---|---|---|---|---|
| `email` | ✅ | ✅ | — | ✅ | ✅ Success + Failure |
| `slack` | ✅ | ✅ | ✅ | ✅ | ✅ Success only |
| `telegram` | ✅ | ✅ | ✅ | ✅ | ❌ |
| `discord` | ✅ | ✅ | ✅ | ✅ | ❌ |
| `teams` | ✅ | ✅ | ✅ | ✅ | ❌ |
| `messenger` | ✅ | ✅ | ✅ | ✅ | ❌ |
| `whatsapp` | ✅ | ✅ | ✅ | ✅ | ❌ |
| `sms` | ✅ | ✅ | — | ✅ | ❌ |
| `paper_store` | ✅ | ✅ | — | ✅ | ✅ Success only |
| `webhook` | ✅ | ✅ | — | ✅ | ❌ |
| `wordpress` | ✅ | ✅ | — | ✅ | ✅ Success only |
| `google_chat` | ❌ | ❌ | ❌ | ❌ | ❌ |

---

## 3. Connection Types Available in Remote Sites

The Remote Sites admin (`/wp-admin/admin.php?page=wp-mcp-ai-remote-sites`) already supports these connection types that are directly relevant to result delivery:

| Connection Type | Remote Sites Slug | Dedicated UI Fields | Webhook Support |
|---|---|---|---|
| Slack | `slack` | Bot token, signing secret, workspace ID, assigned assistants | ✅ Events API |
| Telegram | `telegram` | Bot token, chat ID | ✅ Webhook |
| WhatsApp Business | `whatsapp` | Access token, phone number ID | ✅ Webhook |
| Discord | `discord` | Bot token, channel ID | ✅ Webhook |
| Microsoft Teams | `microsoft_teams` | Token, team ID, channel ID | ✅ Webhook |
| Facebook Messenger | `facebook_messenger` | Access token, page ID | ✅ Webhook |
| Google Chat | `google_chat` | OAuth / webhook URL | ✅ HTTP webhook |
| WebChat P2P | `webchat` | WebRTC / signaling config | ✅ WebSocket |
| Twitter / X | `twitter` | API keys | ✅ Webhook |
| Apple Messages | `apple_messages` | Business ID, key | ✅ Webhook |

**Key insight**: These connections already store credentials. The result delivery system should reference them by `connection_id` rather than duplicating credentials in each schedule.

---

## 4. Proposed Solution

### 4.1 Architecture Overview

```
┌──────────────────────────────────────────────────────────────────┐
│                    SCHEDULE EDIT MODAL (JS)                       │
│                                                                   │
│  ┌─ Result Delivery ──────────────────────────────────────────┐  │
│  │  On Success                    On Failure                   │  │
│  │  ┌──────────────────┐         ┌──────────────────┐         │  │
│  │  │ ☑ Email          │         │ ☑ Email          │         │  │
│  │  │ ☐ Slack          │         │ ☐ Slack          │         │  │
│  │  │ ☐ Telegram       │         │ ☐ Telegram       │         │  │
│  │  │ ☐ Discord        │         │ ☐ Discord        │         │  │
│  │  │ ☐ MS Teams       │         │ ☐ MS Teams       │         │  │
│  │  │ ☐ Messenger      │         │ ☐ Messenger      │         │  │
│  │  │ ☐ WhatsApp       │         │ ☐ WhatsApp       │         │  │
│  │  │ ☐ SMS            │         │ ☐ SMS            │         │  │
│  │  │ ☑ Paper Store    │         │ ☐ Webhook        │         │  │
│  │  │ ☐ WordPress Post │         │                  │         │  │
│  │  │ ☐ Webhook        │         │                  │         │  │
│  │  └──────────────────┘         └──────────────────┘         │  │
│  │  [Connection: ▾ my-slack-workspace]  ← per-channel ref     │  │
│  └────────────────────────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌──────────────────────────────────────────────────────────────────┐
│              RESULT DELIVERY SERVICE (PHP)                       │
│                                                                   │
│  sanitize_delivery_channels()                                     │
│    ├── Accepts connection_id → loads from Remote Sites            │
│    ├── Falls back to inline {channel}_credentials                 │
│    └── Falls back to Chat Channels Toolkit global settings        │
│                                                                   │
│  send_to_channel()                                                │
│    ├── email       → Nodemailer / wp_mail                         │
│    ├── slack       → unified_channel_broadcast → send_slack_msg   │
│    ├── telegram    → unified_channel_broadcast → send_telegram    │
│    ├── discord     → unified_channel_broadcast → send_discord     │
│    ├── teams       → unified_channel_broadcast → send_teams       │
│    ├── messenger   → unified_channel_broadcast → send_messenger   │
│    ├── whatsapp    → unified_channel_broadcast → send_whatsapp    │
│    ├── google_chat → NEW send_google_chat_message                 │
│    ├── sms         → schedule_notify_sms tool                     │
│    ├── paper_store → Paper_Store_Manager                          │
│    ├── webhook     → wp_remote_post + HMAC                        │
│    └── wordpress   → wp_insert_post                               │
└──────────────────────────────────────────────────────────────────┘
```

### 4.2 Channel Identity Fields

Each channel needs a **target identifier** in addition to credentials:

| Channel | Identifier Field | Example | Notes |
|---|---|---|---|
| `email` | `to` | `team@example.com` | Already implemented |
| `slack` | `channel` | `#research` or `C0123456789` | Channel name or ID |
| `telegram` | `chat_id` | `-1001234567890` | Group/channel/user ID |
| `discord` | `channel_id` | `123456789012345678` | Snowflake ID |
| `teams` | `team_id` + `channel_id` | `19:xxx@thread.tacv2` | Both required |
| `messenger` | `recipient_id` | `1234567890123456` | Page-scoped PSID |
| `whatsapp` | `to` | `+15551234567` | Phone number with country code |
| `sms` | `to` | `+15551234567` | Phone number |
| `google_chat` | `space_id` | `spaces/AAAABBBB` | Google Chat space |
| `webhook` | `url` | `https://hooks.example.com/...` | Already implemented |
| `paper_store` | `collection` | `blog-research` | Already implemented |
| `wordpress` | `post_type` + `post_status` | `post` / `draft` | Already implemented |

### 4.3 Credential Resolution Strategy

Three-tier fallback for credential resolution in `send_chat()`:

```
1. connection_id (NEW)
   └─→ WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id )
       └─→ Extract token/secret from connection record
           └─→ Use for this delivery

2. Inline {channel}_credentials (EXISTING)
   └─→ Directly from schedule['result_delivery'][...][channel]['{channel}_credentials']

3. Chat Channels Toolkit global settings (EXISTING)
   └─→ wp_mcp_ai_get_chat_channel_settings( $channel )
```

**Benefit**: Users configure credentials once in Remote Sites, then reference the connection in each schedule. No credential duplication. Credential rotation in Remote Sites automatically propagates to all schedules using that connection.

---

## 5. Implementation Plan

### Phase 1 — UI Expansion: Edit Modal (Primary Gap)

**Goal**: Expose all 11 supported channels in the schedule edit modal, with per-channel configuration fields and template selection.

**Files changed:**
- `addons/pro/assets/js/schedule-manager.js` — `openEditModal()` + `saveEdit()`

#### 5.1.1 New JS Helper: `editChannelRow()`

A reusable builder function that generates a table row for any delivery channel, eliminating the current hardcoded Slack-only pattern:

```javascript
/**
 * Build a delivery channel row for the edit modal.
 *
 * @param {string} label       Display label (e.g., "Telegram", "Microsoft Teams")
 * @param {string} channelSlug Internal slug (e.g., "telegram", "teams")
 * @param {object} config      Current channel config from schedule data
 * @param {boolean} isFailure  Whether this is an on_failure channel
 * @param {object} connections Available Remote Sites connections for this channel type
 * @return {string} HTML table row
 */
editChannelRow: function (label, channelSlug, config, isFailure, connections) { ... }
```

Each channel type produces:
- **Checkbox** — enable/disable
- **Target identifier field(s)** — channel-specific (see §4.2 table)
- **Template selector** — `summary`/`error` for chat; `full`/`summary`/`error` for email
- **Credential source dropdown** — "Remote Sites Connection" or "Inline Token"
- **Credential value field** — shown when "Inline Token" selected

#### 5.1.2 Channel Registration Data (JS constant)

```javascript
var CHANNEL_DEFS = {
    email:       { label: 'Email',           icon: 'dashicons-email',        fields: ['to'],             templates: ['full','summary','error'] },
    slack:       { label: 'Slack',           icon: 'dashicons-slack',        fields: ['channel'],        templates: ['summary','error'] },
    telegram:    { label: 'Telegram',        icon: 'dashicons-telegram',     fields: ['chat_id'],        templates: ['summary','error'] },
    discord:     { label: 'Discord',         icon: 'dashicons-discord',      fields: ['channel_id'],     templates: ['summary','error'] },
    teams:       { label: 'Microsoft Teams', icon: 'dashicons-microsoft',    fields: ['team_id','channel_id'], templates: ['summary','error'] },
    messenger:   { label: 'Messenger',       icon: 'dashicons-facebook',     fields: ['recipient_id'],   templates: ['summary','error'] },
    whatsapp:    { label: 'WhatsApp',        icon: 'dashicons-whatsapp',     fields: ['to'],             templates: ['summary','error'] },
    google_chat: { label: 'Google Chat',     icon: 'dashicons-google',       fields: ['space_id'],       templates: ['summary','error'] },
    sms:         { label: 'SMS',             icon: 'dashicons-smartphone',   fields: ['to'],             templates: ['summary','error'] },
    webhook:     { label: 'Webhook',         icon: 'dashicons-admin-links',  fields: ['url'],            templates: [] },
    paper_store: { label: 'Paper Store',     icon: 'dashicons-media-document', fields: ['collection'],  templates: [],             extra: ['driver','retention'] },
    wordpress:   { label: 'WordPress Post',  icon: 'dashicons-wordpress',    fields: [],                templates: [],             extra: ['post_type','post_status','category','skip_if_ai_posted'] },
};
```

#### 5.1.3 Refactored `openEditModal()` — Result Delivery Section

```javascript
// Result Delivery section
html += '<tr><td colspan="2"><hr><strong>' + strings.resultDelivery + '</strong>';
html += '<p class="description">' + strings.resultDeliveryHelp + '</p></td></tr>';

// On Success
html += '<tr class="sm-rd-section-header"><td colspan="2"><em>' + strings.onSuccess + '</em></td></tr>';
Object.keys(CHANNEL_DEFS).forEach(function(ch) {
    var def = CHANNEL_DEFS[ch];
    html += self.editChannelRow(def.label, ch, rdSuccess[ch] || {}, false, connections[ch] || []);
});

// On Failure
html += '<tr class="sm-rd-section-header"><td colspan="2"><hr><em>' + strings.onFailure + '</em></td></tr>';
Object.keys(CHANNEL_DEFS).forEach(function(ch) {
    var def = CHANNEL_DEFS[ch];
    if (ch === 'paper_store' || ch === 'wordpress') return; // Skip content-creation channels for failure
    html += self.editChannelRow(def.label, ch, rdFailure[ch] || {}, true, connections[ch] || []);
});
```

#### 5.1.4 Refactored `saveEdit()` — Serialization

Replace the hardcoded per-channel field reads with a channel-loop pattern:

```javascript
function collectChannelConfig(channelSlug, prefix) {
    var def = CHANNEL_DEFS[channelSlug];
    var cfg = {
        enabled:  $('#' + prefix + channelSlug).is(':checked'),
        template: $('#' + prefix + channelSlug + '-template').val() || def.templates[0] || 'summary',
    };

    // Target identifier fields
    def.fields.forEach(function(f) {
        var val = $('#' + prefix + channelSlug + '-' + f).val();
        if (val) cfg[f] = val.trim();
    });

    // Extra fields (paper_store, wordpress)
    if (def.extra) {
        def.extra.forEach(function(f) {
            var val = $('#' + prefix + channelSlug + '-' + f).val();
            if (val !== undefined && val !== null && val !== '') {
                cfg[f] = (f === 'retention' || f === 'category') ? parseInt(val, 10) || 0 : val.trim();
            }
        });
        // Checkbox extras
        if (channelSlug === 'wordpress') {
            cfg.skip_if_ai_posted = $('#' + prefix + channelSlug + '-skip-if-ai').is(':checked');
        }
    }

    // Credential source
    var connId = $('#' + prefix + channelSlug + '-connection').val();
    if (connId && connId !== '__inline__') {
        cfg.connection_id = connId;
    } else {
        cfg[channelSlug + '_credentials'] = $('#' + prefix + channelSlug + '-creds').val().trim();
    }

    return cfg;
}

// Build result_delivery from all enabled channels
var rdSuccess = {};
var rdFailure = {};
Object.keys(CHANNEL_DEFS).forEach(function(ch) {
    var sc = collectChannelConfig(ch, 'edit-rd-success-');
    if (sc.enabled) rdSuccess[ch] = sc;
    var fc = collectChannelConfig(ch, 'edit-rd-failure-');
    if (fc.enabled) rdFailure[ch] = fc;
});

data.result_delivery = {
    on_success: { channels: rdSuccess },
    on_failure: { channels: rdFailure },
};
```

#### 5.1.5 UI/UX Improvements

- **Collapsible channel groups**: Group channels into "Chat" (Slack, Telegram, Discord, Teams, Messenger, WhatsApp, Google Chat), "Direct" (Email, SMS), and "Automation" (Webhook, WordPress, Paper Store) with toggle headers
- **Connection status indicator**: Green/yellow dot next to channel label showing if a Remote Sites connection is available
- **Preview badge**: Show estimated message preview inline (e.g., "✅ Weekly Blog Research — 3 new topics found")
- **"Copy from Success" button**: One-click to replicate success channel config to failure section

---

### Phase 2 — Backend: Google Chat Support

**Goal**: Add Google Chat as a fully-supported delivery channel.

**Files changed:**
- `addons/pro/includes/services/class-wp-mcp-ai-result-delivery-service.php`
- `addons/pro/includes/class-wp-mcp-ai-pro-schedule-manager.php`
- `addons/pro/includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-unified-channel-broadcast.php`
- New: `addons/pro/includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-send-google-chat-message.php`

#### 5.2.1 Add to SUPPORTED_CHANNELS

```php
// In class-wp-mcp-ai-result-delivery-service.php
const SUPPORTED_CHANNELS = array(
    'email', 'slack', 'telegram', 'discord', 'teams',
    'messenger', 'whatsapp', 'google_chat',  // ← NEW
    'sms', 'paper_store', 'webhook', 'wordpress',
);
```

#### 5.2.2 Add to sanitize_delivery_channels()

```php
// In class-wp-mcp-ai-pro-schedule-manager.php:2942
$allowed = array( 'email', 'slack', 'telegram', 'discord', 'teams',
    'messenger', 'whatsapp', 'google_chat', 'sms', 'paper_store', 'webhook', 'wordpress' );
```

#### 5.2.3 Create send_google_chat_message tool

Google Chat uses a different API pattern than other channels — it uses **webhook URLs** or the **Google Chat REST API** with OAuth2:

```php
class WP_MCP_AI_Pro_Tool_Send_Google_Chat_Message implements WP_MCP_AI_Tool_Interface {
    public function get_slug() {
        return 'send_google_chat_message';
    }

    public function execute( array $arguments = array(), array $context = array() ) {
        // Google Chat webhook URL: https://chat.googleapis.com/v1/spaces/{space}/messages
        // Auth: Bearer token (OAuth2) or webhook key in URL
        $webhook_url = $arguments['webhook_url'] ?? '';
        $message     = $arguments['text'] ?? '';

        // Send as card or simple text message
        $body = wp_json_encode( array( 'text' => $message ) );

        $response = wp_remote_post( $webhook_url, array(
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => $body,
            'timeout' => 15,
        ) );

        // ... error handling
    }
}
```

#### 5.2.4 Add to unified_channel_broadcast

```php
// In send_to_channel() switch:
case 'google_chat':
    if ( ! isset( $credentials['webhook_url'] ) ) {
        return new WP_Error( 'missing_google_chat_credentials', ... );
    }
    $tool = $tool_registry->get_tool( 'send_google_chat_message' );
    return $tool->execute( array(
        'webhook_url' => $credentials['webhook_url'],
        'text'        => $message,
    ), $context );
```

---

### Phase 3 — Credential Resolution from Remote Sites

**Goal**: Allow schedules to reference Remote Sites connections by `connection_id` instead of duplicating credentials.

**Files changed:**
- `addons/pro/includes/services/class-wp-mcp-ai-result-delivery-service.php`
- `addons/pro/includes/class-wp-mcp-ai-pro-schedule-manager.php`

#### 5.3.1 Add connection_id to sanitize_delivery_channels()

```php
// In sanitize_delivery_channels(), add to the chat-channel block:
if ( in_array( $channel, array( 'slack', 'telegram', 'discord', 'teams',
    'messenger', 'whatsapp', 'google_chat' ), true ) ) {
    // NEW: reference a Remote Sites connection
    if ( isset( $config['connection_id'] ) ) {
        $entry['connection_id'] = sanitize_text_field( $config['connection_id'] );
    }
    // Existing inline credentials (fallback)
    if ( isset( $config[ $channel . '_credentials' ] ) ) {
        $entry[ $channel . '_credentials' ] = $config[ $channel . '_credentials' ];
    }
    if ( isset( $config['channel'] ) ) {
        $entry['channel'] = sanitize_text_field( $config['channel'] );
    }
}
```

#### 5.3.2 Add resolve_credentials() helper

```php
/**
 * Resolve credentials for a delivery channel.
 *
 * Priority:
 *   1. connection_id → Remote Sites connection
 *   2. Inline {channel}_credentials in config
 *   3. Chat Channels Toolkit global settings
 */
protected static function resolve_channel_credentials( $channel, array $config ) {
    // 1. Try Remote Sites connection reference
    if ( ! empty( $config['connection_id'] ) ) {
        $connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $config['connection_id'] );
        if ( $connection ) {
            return self::extract_credentials_from_connection( $channel, $connection );
        }
    }

    // 2. Try inline credentials
    if ( isset( $config[ $channel . '_credentials' ] ) ) {
        return $config[ $channel . '_credentials' ];
    }

    // 3. Try global Chat Channels Toolkit settings
    return apply_filters( 'wp_mcp_ai_delivery_channel_default_credentials', array(), $channel );
}
```

#### 5.3.3 Update send_chat() to use resolved credentials

```php
protected static function send_chat( $channel, array $payload, array $config ) {
    // ... existing checks ...

    // Resolve credentials using the three-tier fallback
    $credentials = array();
    if ( isset( $config['connection_id'] ) ) {
        $connection_creds = self::resolve_channel_credentials( $channel, $config );
        if ( ! empty( $connection_creds ) ) {
            $credentials[ $channel ] = $connection_creds;
        }
    }

    // Fall back to inline credentials
    if ( empty( $credentials ) ) {
        if ( isset( $config[ $channel . '_credentials' ] ) ) {
            $credentials[ $channel ] = $config[ $channel . '_credentials' ];
        }
    }

    // ... call unified_channel_broadcast ...
}
```

---

### Phase 4 — Backend: Missing Channel Support in send_to_channel

**Goal**: Ensure all channels declared in `sanitize_delivery_channels()` are handled in `send_to_channel()`.

**Current gap**: The `send_to_channel()` switch in the Result Delivery Service only handles chat channels through the `send_chat()` method (which wraps `unified_channel_broadcast`). This is correct for all 7 chat channels. However, verify that:
- `google_chat` case is added (see Phase 2)
- `messenger` case is working (verify `send_messenger_message` tool exists and is registered)
- All channel slugs match between `sanitize_delivery_channels()`, `SUPPORTED_CHANNELS`, and `send_to_channel()`

---

### Phase 5 — Analytics & Debugging UI (Quality of Life)

**Goal**: Help users debug delivery failures and understand delivery performance.

**Files changed:**
- `addons/pro/includes/admin/sections/class-wp-mcp-ai-section-schedule-manager.php`
- `addons/pro/assets/js/schedule-manager.js`

#### 5.5.1 Delivery Status in Run History

Extend the run history table to show a delivery status column:

```
| Timestamp | Status | Duration | Delivery | Actions |
|-----------|--------|----------|----------|---------|
| 2026-06-24 09:00 | ✅ Success | 12.3s | ✅ Email ✅ Slack ❌ Telegram | [View] |
```

Each channel gets a status pill: green check = delivered, red X = failed, gray dash = not configured.

#### 5.5.2 "Test Delivery" Button

Add a "Test Delivery" button in the edit modal that sends a test message to each selected channel:

```javascript
// In saveEdit area, add:
'<button type="button" class="button" id="wp-mcp-ai-sm-test-delivery">' +
    strings.testDelivery +
'</button>'
```

On click: POST to a new AJAX endpoint that runs `Result_Delivery_Service::deliver_success()` with a test envelope.

---

### Phase 6 — Schedule Create Form Enhancement

**Goal**: Add a "Result Delivery" section to the create form so users can configure delivery at schedule creation time, not just editing.

**Files changed:**
- `addons/pro/includes/admin/sections/class-wp-mcp-ai-section-schedule-manager.php`

Add a collapsible "Result Delivery (Optional)" section below the current form fields, reusing the same channel checkbox pattern from the edit modal.

---

## 6. Data Flow Diagram

```mermaid
flowchart TD
    subgraph UI["Admin UI"]
        A[Edit Schedule Modal] --> B{Channel Enabled?}
        B -->|Yes| C[connection_id OR inline creds]
        B -->|No| SKIP[Skip channel]
        C --> D[saveEdit() → AJAX]
    end

    subgraph SANITIZE["Sanitization Layer"]
        D --> E[sanitize_result_delivery()]
        E --> F[sanitize_delivery_channels()]
        F --> G{connection_id set?}
        G -->|Yes| H[Store connection_id in config]
        G -->|No| I[Store inline credentials]
    end

    subgraph DISPATCH["Dispatch Layer"]
        J[WP-Cron fires] --> K[dispatch()]
        K --> L[record_run()]
        L --> M{Success?}
        M -->|Yes| N[deliver_success()]
        M -->|No| O[deliver_failure()]
    end

    subgraph DELIVERY["Delivery Service"]
        N --> P[deliver_to_channels()]
        O --> P
        P --> Q[format_for_channel()]
        Q --> R[resolve_channel_credentials()]
        R --> S{connection_id?}
        S -->|Yes| T[Remote Sites Manager]
        S -->|No| U[Inline credentials]
        T --> V[send_to_channel()]
        U --> V
        V --> W[Per-channel backend API call]
        W --> X[log_delivery()]
        X --> Y[append_delivery_to_history()]
    end

    subgraph CHANNELS["Channel Backends"]
        V --> C1[Email: Nodemailer / wp_mail]
        V --> C2[Chat: unified_channel_broadcast]
        V --> C3[SMS: schedule_notify_sms]
        V --> C4[Webhook: wp_remote_post + HMAC]
        V --> C5[Paper Store: paper_store_write]
        V --> C6[WordPress: wp_insert_post]
    end

    style A fill:#4A90D9,color:#fff
    style N fill:#4CAF50,color:#fff
    style O fill:#f44336,color:#fff
    style T fill:#FF9800,color:#fff
```

---

## 7. File Map

### Files to Create

| File Path | Description |
|---|---|
| `addons/pro/includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-send-google-chat-message.php` | Google Chat message tool (Phase 2) |
| `docs/project/proposals/multi-channel-result-delivery-enhancement.md` | This document |

### Files to Modify

| File Path | Change | Phase |
|---|---|---|
| `addons/pro/assets/js/schedule-manager.js` | Add `CHANNEL_DEFS`, `editChannelRow()`, refactor `openEditModal()` and `saveEdit()` | 1 |
| `addons/pro/includes/services/class-wp-mcp-ai-result-delivery-service.php` | Add `google_chat` to `SUPPORTED_CHANNELS`; add `resolve_channel_credentials()`; add `google_chat` case to `send_to_channel()`; update `send_chat()` to use resolved credentials | 2, 3 |
| `addons/pro/includes/class-wp-mcp-ai-pro-schedule-manager.php` | Add `google_chat` to allowed channels; add `connection_id` handling to `sanitize_delivery_channels()` | 2, 3 |
| `addons/pro/includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-unified-channel-broadcast.php` | Add `google_chat` to channel enum and `send_to_channel()` switch | 2 |
| `addons/pro/includes/admin/sections/class-wp-mcp-ai-section-schedule-manager.php` | Add Result Delivery section to create form; add delivery status to run history; add i18n strings | 5, 6 |
| `addons/pro/includes/tools/chat-channels/init.php` | Register `send_google_chat_message` tool | 2 |

### Files NOT Changed (read-only integration)

| File Path | Reason |
|---|---|
| `addons/pro/includes/class-wp-mcp-ai-pro-remote-site-manager.php` | Used via `get_connection()` — already exposes public API |
| `addons/pro/includes/admin/class-wp-mcp-ai-pro-remote-sites-admin.php` | Connection CRUD UI — no changes needed |
| `addons/pro/includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-send-slack-message.php` | Used as-is by unified_channel_broadcast |
| `addons/pro/includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-send-telegram-message.php` | Used as-is |
| `addons/pro/includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-send-discord-message.php` | Used as-is |
| `addons/pro/includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-send-teams-message.php` | Used as-is |
| `addons/pro/includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-send-messenger-message.php` | Used as-is |
| `addons/pro/includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-send-whatsapp-message.php` | Used as-is |

---

## 8. UI Mockup — Edit Modal Result Delivery Section

```
┌─────────────────────────────────────────────────────────────────┐
│  Edit Schedule: Weekly Blog Topic Research                      │
│                                                                  │
│  ┌─ Result Delivery ──────────────────────────────────────────┐ │
│  │  Configure where results are sent when this schedule runs.  │ │
│  │  Credentials can reference Remote Sites connections.        │ │
│  │                                                              │ │
│  │  ┌─ On Success ──────────────────────────────────────────┐ │ │
│  │  │                                                        │ │ │
│  │  │  ▸ Chat Channels                                       │ │ │
│  │  │  ┌──────────────────────────────────────────────────┐ │ │ │
│  │  │  │ ☑ Slack        #research     [▾ my-slack (●)]   │ │ │ │
│  │  │  │ ☐ Telegram     [-100123...]  [▾ __inline__]      │ │ │ │
│  │  │  │ ☐ Discord      [12345678...] [▾ __inline__]      │ │ │ │
│  │  │  │ ☐ MS Teams     [Team/Ch ID]  [▾ No connections]  │ │ │ │
│  │  │  │ ☐ Messenger    [recipient..] [▾ __inline__]      │ │ │ │
│  │  │  │ ☐ WhatsApp     [+1555123...] [▾ __inline__]      │ │ │ │
│  │  │  │ ☐ Google Chat  [spaces/AAA]  [▾ __inline__]      │ │ │ │
│  │  │  └──────────────────────────────────────────────────┘ │ │ │
│  │  │                                                        │ │ │
│  │  │  ▸ Direct                                              │ │ │
│  │  │  ┌──────────────────────────────────────────────────┐ │ │ │
│  │  │  │ ☑ Email  team@example.com  [▾ Full Report]       │ │ │ │
│  │  │  │ ☐ SMS    +15551234567      [▾ Summary]           │ │ │ │
│  │  │  └──────────────────────────────────────────────────┘ │ │ │
│  │  │                                                        │ │ │
│  │  │  ▸ Automation                                          │ │ │
│  │  │  ┌──────────────────────────────────────────────────┐ │ │ │
│  │  │  │ ☑ Paper Store    blog-research  [▾ Markdown+YAML]│ │ │ │
│  │  │  │ ☐ WordPress Post Post / Draft   Cat: 0           │ │ │ │
│  │  │  │ ☐ Webhook        https://...                     │ │ │ │
│  │  │  └──────────────────────────────────────────────────┘ │ │ │
│  │  └────────────────────────────────────────────────────────┘ │ │
│  │                                                              │ │
│  │  ┌─ On Failure ──────────────────────────────────────────┐ │ │
│  │  │  ☑ Email          admin@example.com  [▾ Error]        │ │ │
│  │  │  ☑ Slack          #alerts            [▾ my-slack (●)] │ │ │
│  │  │  ☐ Telegram       ...                                 │ │ │
│  │  │  ☐ Webhook        https://...                         │ │ │
│  │  │  [Copy from Success ▲]                                 │ │ │
│  │  └────────────────────────────────────────────────────────┘ │ │
│  │                                                              │ │
│  │  [Test Delivery]                    [Save Changes] [Cancel] │ │
│  └──────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────┘
```

---

## 9. Backward Compatibility

### 9.1 Existing Schedules

Schedules created before this enhancement have `result_delivery` with only `email`, `slack`, `paper_store`, and/or `wordpress` channels configured (if any). The new UI reads the existing config and populates fields accordingly. Channels not present in the config default to disabled (empty checkboxes). **No migration needed** — the `sanitize_delivery_channels()` already handles partial configs.

### 9.2 Legacy `notify_*` Fields

The `build_legacy_failure_channels()` method in `Result_Delivery_Service` (line ~978) already handles on-the-fly migration of old `notify_on_failure`, `notify_email`, `notify_channels`, and `notify_channel_credentials` fields. This continues to work.

### 9.3 REST API

No changes to REST API response shape. Delivery status is already included in run history via `append_delivery_to_history()`.

### 9.4 Channel Broadcast Type

The `channel_broadcast` schedule type is unaffected. It continues to use the separate `broadcast_config` schema and the `unified_channel_broadcast` tool directly.

---

## 10. Success Metrics

| Metric | Current | Target |
|---|---|---|
| Delivery channels visible in UI | 4 (Email, Slack, Paper Store, WordPress) | 11+ (all supported channels) |
| Failure notification channels | Email only | Email + all chat channels + Webhook + SMS |
| Credential management | Inline per-schedule only | Remote Sites connection reference + inline fallback |
| Google Chat support | ❌ Not supported | ✅ Fully integrated |
| "I didn't know we could send to Telegram" | Common support question | Self-documenting UI |
| Time to configure multi-channel delivery | Manual PHP/JSON editing | ~30 seconds via UI |
| Delivery debugging | Check PHP error logs | Delivery status in run history + test button |

---

## 11. Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Edit modal too tall with all channels visible | High | Low | Collapsible group accordions; see §8 mockup |
| Credential confusion — users unsure where to enter tokens | Medium | Medium | Connection dropdown is default; inline is hidden behind "Custom" option; help text explains |
| Google Chat API changes (relatively new Google Workspace API) | Low | Medium | Wrapped in dedicated tool class; easy to update independently |
| Performance: loading Remote Sites connections in edit modal | Low | Low | Connections are cached in a single AJAX call when modal opens; results are filtered client-side |
| WhatsApp Business API requires phone_number_id + access_token — complex config | Medium | Low | Both fields are clearly labeled; Remote Sites connection pre-fills them |
| Messenger API token refresh (short-lived page tokens) | Medium | Medium | Document that long-lived tokens should be stored in Remote Sites; `connection_id` approach means one refresh point |

---

## 12. Implementation Order & Dependencies

```
Phase 1 (UI Expansion)        ← No dependencies. Can ship independently.
    │
    ├── Phase 2 (Google Chat) ← Depends on Phase 1 for UI registration.
    │
    ├── Phase 3 (Credential)  ← Depends on Phase 1 for UI fields.
    │                           Can ship with Phase 2.
    │
    ├── Phase 4 (Backend Gap) ← Independent verification task.
    │
    ├── Phase 5 (Debugging)   ← Depends on Phases 1-3 for data to display.
    │
    └── Phase 6 (Create Form) ← Depends on Phase 1 for reusable patterns.
```

**Recommended MVP scope**: Phase 1 + Phase 2 + Phase 3. This delivers the full UI with Google Chat support and credential resolution. Phases 4–6 are quality-of-life improvements.

---

## 13. i18n Strings Required

```php
// New strings needed (add to .pot)
__( 'Result Delivery', 'mcp-ai-wpoos-pro' );
__( 'Configure where schedule results are delivered when the run completes.', 'mcp-ai-wpoos-pro' );
__( 'On Success', 'mcp-ai-wpoos-pro' );
__( 'On Failure', 'mcp-ai-wpoos-pro' );
__( 'Chat Channels', 'mcp-ai-wpoos-pro' );
__( 'Direct', 'mcp-ai-wpoos-pro' );
__( 'Automation', 'mcp-ai-wpoos-pro' );
__( 'Connection', 'mcp-ai-wpoos-pro' );
__( 'Select a Remote Sites connection or enter credentials inline.', 'mcp-ai-wpoos-pro' );
__( 'Inline Token', 'mcp-ai-wpoos-pro' );
__( 'No connections configured.', 'mcp-ai-wpoos-pro' );
__( 'Copy from Success', 'mcp-ai-wpoos-pro' );
__( 'Test Delivery', 'mcp-ai-wpoos-pro' );
__( 'Google Chat', 'mcp-ai-wpoos-pro' );
__( 'Google Chat webhook URL is required.', 'mcp-ai-wpoos-pro' );
__( 'Telegram', 'mcp-ai-wpoos-pro' );
__( 'Discord', 'mcp-ai-wpoos-pro' );
__( 'Microsoft Teams', 'mcp-ai-wpoos-pro' );
__( 'Messenger', 'mcp-ai-wpoos-pro' );
__( 'WhatsApp', 'mcp-ai-wpoos-pro' );
__( 'SMS', 'mcp-ai-wpoos-pro' );
__( 'Webhook', 'mcp-ai-wpoos-pro' );
__( 'Delivery test sent.', 'mcp-ai-wpoos-pro' );
__( 'Delivery test failed.', 'mcp-ai-wpoos-pro' );
```

---

## 14. References

- [Notification Master — Multi-Carrier WordPress Plugin](https://wordpress.org/plugins/notification-master/)
- [BracketSpace Notification — Carrier Architecture](https://bracketspace.com/blog/slack-integration-with-notificationplugin/)
- [n8n — Multi-Channel Notification Workflows](https://n8n.io/workflows/)
- [Zapier — Multi-Step Zaps Pattern](https://zapier.com/)
- [Telegram Bot API — sendMessage method](https://core.telegram.org/bots/api#sendmessage)
- [WhatsApp Business API — Cloud API](https://developers.facebook.com/docs/whatsapp/cloud-api)
- [Google Chat API — REST Resource: spaces.messages](https://developers.google.com/workspace/chat/api/reference/rest/v1/spaces.messages)
- [Discord API — Create Message](https://discord.com/developers/docs/resources/channel#create-message)
- [Microsoft Graph API — Send chatMessage](https://learn.microsoft.com/en-us/graph/api/chatmessage-post)
- [Facebook Messenger Platform — Send API](https://developers.facebook.com/docs/messenger-platform/send-messages)
- NV oOS Schedule Result Delivery Pipeline Proposal (`docs/project/proposals/schedule-result-delivery-pipeline.md`)
- NV oOS Result Delivery Service (`addons/pro/includes/services/class-wp-mcp-ai-result-delivery-service.php`)
- NV oOS Schedule Manager (`addons/pro/includes/class-wp-mcp-ai-pro-schedule-manager.php`)
