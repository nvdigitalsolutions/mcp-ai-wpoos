# Remote Connections — Developer Reference

NV oOS Pro addon — `addons/pro/includes/admin/`

This document is the developer-facing reference for all 24 remote connection types. For a full end-user guide including setup walkthroughs, see [`docs/REMOTE_CONNECTIONS_GUIDE.md`](../../../../docs/REMOTE_CONNECTIONS_GUIDE.md).

---

## Key PHP Classes

| Class | File | Role |
|-------|------|------|
| `WP_MCP_AI_Pro_Remote_Sites_Admin` | `class-wp-mcp-ai-pro-remote-sites-admin.php` | Admin UI: connection list, add/edit form, credential fields per type, test-connection AJAX |
| `WP_MCP_AI_Pro_Remote_Site_Manager` | `class-wp-mcp-ai-pro-remote-site-manager.php` | CRUD, encryption/decryption, connection retrieval by type/ID |
| `WP_MCP_AI_Pro_Metabox_Remote_Connections` | `class-wp-mcp-ai-pro-metabox-remote-connections.php` | Assistant metabox for assigning connections |
| `WP_MCP_AI_Mesh_Peer_Tester` | `class-wp-mcp-ai-mesh-peer-tester.php` | Health-check utility for Mesh Peer connections |

---

## Connection Type Registry

Connection types are registered in `class-wp-mcp-ai-pro-remote-sites-admin.php` in the `get_connection_types()` method (or equivalent dropdown array). The `key` is stored in post meta as the connection type identifier.

| Key | Label | Category | Badge Color |
|-----|-------|----------|-------------|
| `wordpress` | WordPress / WooCommerce | CMS | `#2271b1` |
| `mesh_peer` | Mesh Peer (Distributed AI) | Federation | `#7e57c2` |
| `generic` | Generic REST API | API | `#50575e` |
| `isams` | iSAMS (School Management) | Business | `#d63638` |
| `flowhub` | Flowhub (POS / Retail) | Business | `#00a32a` |
| `payhere` | PayHere (Payment Gateway) | Payments | `#f0b849` |
| `quickbooks` | QuickBooks (Accounting) | Business | `#2c9f47` |
| `ezuite_erp` | EZuite ERP (Inventory) | Business | `#8c50a7` |
| `gmail` | Gmail (Email Service) | Google | `#ea4335` |
| `google_drive` | Google Drive (Cloud Storage) | Google | `#4285f4` |
| `upwork` | Upwork (Freelance Marketplace) | Freelance | `#14a800` |
| `telegram` | Telegram (Chat Channel) | Chat | `#0088cc` |
| `whatsapp` | WhatsApp Business (Chat Channel) | Chat | `#25d366` |
| `slack` | Slack (Chat Channel) | Chat | `#4a154b` |
| `discord` | Discord (Chat Channel) | Chat | `#5865f2` |
| `microsoft_teams` | Microsoft Teams (Chat Channel) | Chat | `#6264a7` |
| `facebook_messenger` | Facebook Messenger (Chat Channel) | Chat | `#0084ff` |
| `webchat` | WebChat P2P (Chat Channel) | Chat | `#ff6b6b` |
| `google_chat` | Google Chat (Chat Channel) | Chat | `#1a73e8` |
| `twitter` | Twitter / X (Chat Channel) | Social | `#000000` |
| `apple_messages` | Apple Messages for Business | Chat | `#555555` |
| `office365` | Office 365 (Outlook / OneDrive) | Microsoft | `#d83b01` |
| `icloud` | iCloud Drive | Apple | `#3693f5` |
| `shopify` | Shopify (E-commerce) | E-commerce | `#96bf48` |
| `composio` | Composio Connect (AI Tool Aggregator) | Federation | `#7e57c2` |

---

## Credential Fields per Connection Type

The following tables list the meta keys stored per connection type. All sensitive values are encrypted with AES-256-CBC using the WordPress `AUTH_SALT`. The admin UI renders a masked placeholder for encrypted fields when re-editing.

### `wordpress`

| Meta Key | Type | Notes |
|----------|------|-------|
| `url` | string | Remote WordPress site URL |
| `auth_type` | enum | `application_password` \| `woocommerce` \| `basic_auth` \| `jwt` \| `none` |
| `username` | string | Required for `application_password`, `basic_auth` |
| `password` | string (encrypted) | Required for `application_password`, `basic_auth` |
| `consumer_key` | string | Required for `woocommerce` (prefix: `ck_`) |
| `consumer_secret` | string (encrypted) | Required for `woocommerce` (prefix: `cs_`) |
| `token` | string (encrypted) | Required for `jwt` |

### `mesh_peer`

| Meta Key | Type | Notes |
|----------|------|-------|
| `url` | string | Remote NV oOS WordPress site URL |
| `api_key` | string (encrypted) | Inbound API key from remote site Settings → Advanced → Federation |

### `generic`

| Meta Key | Type | Notes |
|----------|------|-------|
| `url` | string | API base URL |
| `auth_type` | enum | `bearer` \| `custom_header` \| `basic_auth` \| `oauth2` \| `none` |
| `token` | string (encrypted) | Bearer token (for `bearer`, `custom_header`) |
| `username` | string | For `basic_auth` |
| `password` | string (encrypted) | For `basic_auth` |
| `test_endpoint` | string | Optional path for health-check (appended to `url`) |

### `isams`

| Meta Key | Type | Notes |
|----------|------|-------|
| `url` | string | iSAMS instance URL |
| `api_key` | string (encrypted) | iSAMS API key |
| `api_secret` | string (encrypted) | iSAMS API secret |

### `flowhub`

| Meta Key | Type | Notes |
|----------|------|-------|
| `api_key` | string (encrypted) | Flowhub API key |
| `client_id` | string | Flowhub client ID |
| `client_secret` | string (encrypted) | Flowhub client secret |
| `location_id` | string | Flowhub location ID |
| `url` | string | Auto-set to `https://api.flowhub.co` |

### `payhere`

| Meta Key | Type | Notes |
|----------|------|-------|
| `url` | string | `https://www.payhere.lk` or `https://sandbox.payhere.lk` |
| `app_id` | string | PayHere App ID |
| `app_secret` | string (encrypted) | PayHere App Secret |
| `sandbox_mode` | bool | `true` → use sandbox URL |

### `quickbooks`

| Meta Key | Type | Notes |
|----------|------|-------|
| `client_id` | string | Intuit app Client ID |
| `client_secret` | string (encrypted) | Intuit app Client Secret |
| `company_id` | string | QuickBooks Company / Realm ID |
| `refresh_token` | string (encrypted) | Stored automatically after OAuth2 flow |
| `url` | string | Auto-set to `https://appcenter.intuit.com/connect/oauth2` |

### `quickbooks_desktop`

| Meta Key | Type | Notes |
|----------|------|-------|
| `url` | string | QODBC relay API endpoint URL (e.g. `https://relay.example.com/qodbc-relay.php`) |
| `api_key` | string (encrypted) | Bearer token / shared secret for the relay API (optional) |
| `dsn_name` | string | ODBC Data Source Name on the relay server (e.g. `QuickBooks Data` or `QuickBooks Data QRemote`) |

Architecture: **QuickBooks Desktop → QODBC Driver → PHP Relay API → This Connection**.
The relay is a lightweight PHP script on the Windows machine where QuickBooks Desktop and QODBC are installed.
For remote access, use QRemote to tunnel ODBC calls from the relay to QuickBooks Desktop.

### `ezuite_erp`

| Meta Key | Type | Notes |
|----------|------|-------|
| `api_key` | string (encrypted) | EZuite API key |
| `api_secret` | string (encrypted) | EZuite API secret |
| `url` | string | Auto-set to `https://api.ezuite.com/api/External_Api/Action_Api/Invoke` |

### `gmail`

| Meta Key | Type | Notes |
|----------|------|-------|
| `client_id` | string | Google OAuth2 Client ID (`*.apps.googleusercontent.com`) |
| `client_secret` | string (encrypted) | Google OAuth2 Client Secret |
| `refresh_token` | string (encrypted) | Stored automatically after OAuth2 flow |
| `user_email` | string | Auto-filled after OAuth (connected Gmail address) |
| `url` | string | Auto-set to `https://gmail.googleapis.com` |

### `google_drive`

| Meta Key | Type | Notes |
|----------|------|-------|
| `client_id` | string | Google OAuth2 Client ID |
| `client_secret` | string (encrypted) | Google OAuth2 Client Secret |
| `refresh_token` | string (encrypted) | Stored automatically after OAuth2 flow |
| `folder_id` | string | Optional — limits access to a specific Drive folder |
| `user_email` | string | Auto-filled after OAuth |
| `url` | string | Auto-set to `https://www.googleapis.com/drive/v3` |

### `upwork`

| Meta Key | Type | Notes |
|----------|------|-------|
| `client_id` | string | Upwork app Client ID |
| `client_secret` | string (encrypted) | Upwork app Client Secret |
| `refresh_token` | string (encrypted) | Stored automatically after OAuth2 flow |
| `user_email` | string | Auto-filled after OAuth (Upwork username) |
| `url` | string | Auto-set to `https://api.upwork.com/graphql` |

### `telegram`

| Meta Key | Type | Notes |
|----------|------|-------|
| `api_key` | string (encrypted) | Bot Token (format: `123456:ABCdef…`) |
| `bot_username` | string | Optional — for display/reference |
| `secret_token` | string (encrypted) | Webhook secret (A–Z, a–z, 0–9, `_`, `-`, 1–256 chars) |
| `enable_groups` | bool | Allow bot in groups |
| `enable_web_login` | bool | Enable Telegram Login Widget |
| `web_login_redirect_url` | string | Redirect URL after Telegram login |
| `auto_create_wp_user` | bool | Auto-create WP user from Telegram user |
| `new_user_role` | string | WP role for auto-created users |
| `allowed_chat_ids` | array | Allowed Telegram chat IDs (serialised) |
| `welcome_message` | string | Message sent to new users |
| `parse_mode` | enum | `HTML` \| `Markdown` \| `MarkdownV2` |
| `assigned_assistant_ids` | array | Assistant IDs that auto-reply |
| `url` | string | Auto-set to `https://api.telegram.org` |

Webhook: `wp-json/mcp-ai/v1/webhooks/telegram/{connection_id}`

### `whatsapp`

| Meta Key | Type | Notes |
|----------|------|-------|
| `api_key` | string (encrypted) | Cloud API Access Token |
| `api_secret` | string (encrypted) | App Secret (for webhook signature verification) |
| `app_id` | string | Facebook App ID |
| `phone_number_id` | string | WhatsApp Business Phone Number ID |
| `business_account_id` | string | WhatsApp Business Account ID |
| `display_phone_number` | string | Display phone number |
| `verify_token` | string (encrypted) | Webhook verify token |
| `graph_api_version` | string | API version (default: `v21.0`) |
| `url` | string | Auto-set to `https://graph.facebook.com/{version}` |

Webhook: `wp-json/mcp-ai/v1/webhooks/whatsapp/{connection_id}`

### `slack`

| Meta Key | Type | Notes |
|----------|------|-------|
| `api_key` | string (encrypted) | Bot Token (format: `xoxb-…`) |
| `signing_secret` | string (encrypted) | Signing Secret (for webhook verification) |
| `workspace_id` | string | Workspace ID (optional) |
| `slack_bot_user_id` | string | Bot User ID (optional) |
| `url` | string | Auto-set to `https://slack.com/api` |

Required OAuth scopes: `channels:read`, `chat:write`, `groups:read`, `im:read`, `mpim:read`, `users:read`

Webhook: `wp-json/mcp-ai/v1/webhooks/slack/{connection_id}`

### `discord`

| Meta Key | Type | Notes |
|----------|------|-------|
| `api_key` | string (encrypted) | Discord Bot Token |
| `application_id` | string | Discord Application ID |
| `guild_id` | string | Default Guild/Server ID (optional) |
| `public_key` | string | Discord Public Key (for webhook verification) |
| `url` | string | Auto-set to `https://discord.com/api/v10` |

Webhook: `wp-json/mcp-ai/v1/webhooks/discord/{connection_id}`

### `microsoft_teams`

| Meta Key | Type | Notes |
|----------|------|-------|
| `client_id` | string | Azure AD App Client ID |
| `client_secret` | string (encrypted) | Azure AD App Client Secret |
| `tenant_id` | string | Azure AD Tenant ID |
| `signing_secret` | string (encrypted) | Teams outgoing webhook security token |
| `assigned_assistant_ids` | array | Assistant IDs that auto-reply |
| `url` | string | Auto-set to `https://smba.trafficmanager.net/apis` |

Webhook: `wp-json/mcp-ai/v1/webhooks/teams/{connection_id}`

### `facebook_messenger`

| Meta Key | Type | Notes |
|----------|------|-------|
| `api_key` | string (encrypted) | Page Access Token |
| `api_secret` | string (encrypted) | App Secret (for signature verification) |
| `app_id` | string | Facebook App ID |
| `page_id` | string | Facebook Page ID |
| `verify_token` | string (encrypted) | Webhook verify token |
| `graph_api_version` | string | API version (default: `v21.0`) |
| `url` | string | Auto-set to `https://graph.facebook.com/{version}` |

Webhook: `wp-json/mcp-ai/v1/webhooks/messenger/{connection_id}`

### `webchat`

| Meta Key | Type | Notes |
|----------|------|-------|
| `p2p_connection_id` | string | Auto-generated unique identifier |
| `api_secret` | string (encrypted) | Optional encryption key |
| `url` | string | Auto-set to `{home_url}/wp-json/mcp-ai/v1/webhooks/webchat` |

No third-party API. Fully internal.

### `google_chat`

| Meta Key | Type | Notes |
|----------|------|-------|
| `connection_method` | enum | `service_account` \| `oauth` \| `webhook` |
| `api_key` | string (encrypted) | Service Account JSON (for `service_account`) |
| `client_id` | string | OAuth Client ID (for `oauth`) |
| `client_secret` | string (encrypted) | OAuth Client Secret (for `oauth`) |
| `refresh_token` | string (encrypted) | OAuth refresh token (for `oauth`) |
| `google_chat_space` | string | Space ID / name (e.g. `spaces/AAAA`) |
| `verify_token` | string | Audience URL for OIDC verification |
| `reply_webhook_url` | string | Incoming webhook URL (optional) |
| `url` | string | Auto-set to `https://chat.googleapis.com/v1` |

### `twitter`

| Meta Key | Type | Notes |
|----------|------|-------|
| `api_key` | string (encrypted) | Bearer Token or OAuth 2.0 access token |
| `twitter_user_id` | string | Twitter User ID (required for DM access) |
| `url` | string | Auto-set to `https://api.twitter.com/2` |

### `apple_messages`

| Meta Key | Type | Notes |
|----------|------|-------|
| `url` | string | MSP API URL (user-configured HTTPS) |
| `api_key` | string (encrypted) | MSP API key |
| `api_secret` | string (encrypted) | Webhook secret |
| `business_id` | string | Apple Business Chat Business ID |
| `verify_token` | string (encrypted) | Webhook verify token |

Webhook: `wp-json/mcp-ai/v1/webhooks/apple/{connection_id}`

### `office365`

| Meta Key | Type | Notes |
|----------|------|-------|
| `client_id` | string | Azure AD App Client ID |
| `client_secret` | string (encrypted) | Azure AD App Client Secret |
| `tenant_id` | string | Azure AD Tenant ID |
| `token` | string (encrypted) | Microsoft Graph Bearer Token (direct token entry) |
| `enabled_services` | array | e.g. `['outlook_mail', 'onedrive']` |
| `outlook_mailbox_folder` | string | Mailbox folder (default: Inbox) |
| `onedrive_folder_path` | string | OneDrive folder path scope |
| `url` | string | Auto-set to `https://graph.microsoft.com/v1.0` |

Webhook: `wp-json/mcp-ai/v1/webhooks/office365/{connection_id}`

### `icloud`

| Meta Key | Type | Notes |
|----------|------|-------|
| `gateway_api_url` | string | HTTPS gateway URL (validated to require HTTPS) |
| `api_key` | string (encrypted) | Gateway API key or bearer token |
| `enabled_services` | array | e.g. `['icloud_drive']` |
| `icloud_default_folder_id` | string | Default folder ID scope |

Webhook: `wp-json/mcp-ai/v1/webhooks/icloud/{connection_id}`

### `shopify`

| Meta Key | Type | Notes |
|----------|------|-------|
| `shopify_api_mode` | enum | `admin_api` \| `catalog_api` |
| `url` | string | `https://{store}.myshopify.com` (admin) or `https://discover.shopifyapps.com` (catalog) |
| `api_key` | string (encrypted) | Admin API Access Token (`shpat_…` / `shpca_…`) or Catalog API Client ID |
| `api_secret` | string (encrypted) | Storefront API token (admin, optional) or Catalog Client Secret (`shpss_…`) |
| `shopify_api_version` | string | API version (default: `2025-01`) — Admin API only |

For Catalog API, a JWT bearer token is fetched dynamically from `https://api.shopify.com/auth/access_token`.

### `composio`

| Meta Key | Type | Notes |
|----------|------|-------|
| `url` | string | Auto-set to `https://backend.composio.dev` |
| `base_url` | string | Optional regional/self-hosted override; must be a public HTTPS URL |
| `api_key` | string (encrypted) | Composio project API key (`ak_...`) — the only provider-side secret WordPress stores |
| `auth_type` | string | Always `none` (client sends `x-api-key` header) |
| `default_user_mode` | enum | `admin_shared` \| `per_wp_user` |
| `toolkit_allowlist` | array | Optional toolkit slugs; empty = all |
| `webhook_secret` | string (encrypted) | Signing secret from `POST /api/v3.1/webhook_subscriptions` |
| `webhook_subscription_id` | string | Subscription ID for lifecycle management |

Auth flow: Composio Connect Links (`POST /api/v3.1/connected_accounts/link`) — provider OAuth tokens live in Composio and are never stored in WordPress.

Webhook: `wp-json/mcp-ai/v1/webhooks/composio/{connection_id}` (HMAC-signed, signature-gated).

---

## Credential Encryption

All credential fields marked `(encrypted)` are processed through the `Remote_Site_Manager` encrypt/decrypt methods:

- **Algorithm:** AES-256-CBC via `openssl_encrypt()` / `openssl_decrypt()`
- **Key derivation:** WordPress `AUTH_SALT` constant
- **Storage:** Encrypted base64 string stored in post meta
- **Re-edit behaviour:** The admin form renders a masked placeholder (`••••••••`) for any field that already has a saved encrypted value. Submitting the form without changing the field preserves the existing encrypted value.

---

## Validation Rules

- `url` fields: validated with `filter_var($url, FILTER_VALIDATE_URL)` and must use `http://` or `https://` (iCloud gateway requires `https://`)
- `consumer_key`: validated to start with `ck_`
- `consumer_secret`: validated to start with `cs_`
- `api_key` (Shopify Admin): validated to start with `shpat_` or `shpca_`
- `api_secret` (Shopify Catalog): validated to start with `shpss_`
- `api_key` (Composio): required, non-empty (any `ak_` project key format)
- `base_url` (Composio): must be a public HTTPS URL (private/reserved hosts rejected)
- `api_key` (Telegram): validated against Bot Token format `^\d+:[A-Za-z0-9_-]+$`
- `secret_token` (Telegram): validated to match `^[A-Za-z0-9_-]{1,256}$`
- `graph_api_version` (WhatsApp, Messenger): validated against `^v\d+\.\d+$`
- OAuth fields (`refresh_token`, `user_email`): read-only in the admin form; populated only by the OAuth callback handler

---

## Webhook Architecture

Chat channel connections use WordPress REST API endpoints to receive incoming messages:

```
POST {site}/wp-json/mcp-ai/v1/webhooks/{type}/{connection_id}
```

Each webhook controller:
1. Looks up the connection record by `{connection_id}`
2. Verifies the request signature/token using the stored secret
3. Parses the incoming payload into a normalised message format
4. Routes the message to the assigned assistant(s) for a response
5. Sends the response back via the platform API

---

## Tool-to-Connection Mapping

| Connection Type | Tool Class Files |
|-----------------|-----------------|
| `wordpress`, `mesh_peer` | `class-wp-mcp-ai-tool-remote-wp-connection.php` |
| `generic` | `class-wp-mcp-ai-pro-tool-generic-rest.php` |
| `isams` | `class-wp-mcp-ai-tool-isams-query.php`, `class-wp-mcp-ai-tool-sync-ecas-from-isams.php` |
| `flowhub` | `class-wp-mcp-ai-tool-flowhub-get-inventory.php` |
| `quickbooks` | `class-wp-mcp-ai-pro-tool-get-quickbooks-report.php` |
| `quickbooks_desktop` | `class-wp-mcp-ai-pro-tool-quickbooks-desktop-sync.php` |
| `ezuite_erp` | `class-wp-mcp-ai-tool-ezuite-erp.php` |
| `gmail` | `class-wp-mcp-ai-pro-tool-search-gmail.php` |
| `google_drive` | `class-wp-mcp-ai-pro-tool-search-drive.php` |
| `upwork` | `class-wp-mcp-ai-tool-search-upwork-jobs.php`, `class-wp-mcp-ai-tool-score-upwork-job.php`, `class-wp-mcp-ai-tool-draft-upwork-proposal.php` |
| `telegram` | `class-wp-mcp-ai-pro-tool-send-telegram-message.php`, `class-wp-mcp-ai-pro-tool-get-telegram-updates.php`, `class-wp-mcp-ai-pro-tool-manage-telegram-commands.php`, `class-wp-mcp-ai-pro-tool-manage-telegram-webhook.php`, `class-wp-mcp-ai-pro-tool-add-telegram-message-reaction.php` |
| `whatsapp` | `class-wp-mcp-ai-pro-tool-send-whatsapp-message.php`, `class-wp-mcp-ai-pro-tool-get-whatsapp-messages.php` |
| `slack` | `class-wp-mcp-ai-pro-tool-send-slack-message.php`, `class-wp-mcp-ai-pro-tool-get-slack-messages.php`, `class-wp-mcp-ai-pro-tool-get-slack-channels.php`, `class-wp-mcp-ai-pro-tool-create-slack-channel.php` |
| `discord` | `class-wp-mcp-ai-pro-tool-send-discord-message.php`, `class-wp-mcp-ai-pro-tool-get-discord-messages.php`, `class-wp-mcp-ai-pro-tool-get-discord-channels.php`, `class-wp-mcp-ai-pro-tool-get-discord-voice-channel-members.php`, `class-wp-mcp-ai-pro-tool-add-discord-message-reaction.php`, `class-wp-mcp-ai-pro-tool-create-discord-channel.php` |
| `microsoft_teams` | `class-wp-mcp-ai-pro-tool-get-teams-channels.php`, `class-wp-mcp-ai-pro-tool-get-teams-messages.php` |
| `facebook_messenger` | `class-wp-mcp-ai-pro-tool-send-messenger-message.php`, `class-wp-mcp-ai-pro-tool-get-messenger-conversations.php`, `class-wp-mcp-ai-pro-tool-create-messenger-broadcast.php` |
| `webchat` | Internal WebChat system |
| `google_chat` | `class-wp-mcp-ai-pro-tool-send-google-chat-message.php`, `class-wp-mcp-ai-pro-tool-get-google-chat-messages.php`, `class-wp-mcp-ai-pro-tool-get-google-chat-spaces.php`, `class-wp-mcp-ai-pro-tool-create-google-chat-space.php`, `class-wp-mcp-ai-pro-tool-add-google-chat-space-member.php`, `class-wp-mcp-ai-pro-tool-list-google-chat-space-members.php`, `class-wp-mcp-ai-pro-tool-remove-google-chat-space-member.php` |
| `twitter` | `class-wp-mcp-ai-pro-tool-get-twitter-dms.php`, `class-wp-mcp-ai-pro-tool-manage-twitter-webhook.php` |
| `apple_messages` | `class-wp-mcp-ai-pro-tool-send-apple-message.php`, `class-wp-mcp-ai-pro-tool-send-apple-message-group.php`, `class-wp-mcp-ai-pro-tool-send-apple-message-interactive.php`, `class-wp-mcp-ai-pro-tool-get-apple-messages.php` |
| `office365` | `class-wp-mcp-ai-pro-tool-send-outlook-mail.php`, `class-wp-mcp-ai-pro-tool-get-outlook-messages.php`, `class-wp-mcp-ai-pro-tool-list-onedrive-files.php`, `class-wp-mcp-ai-pro-tool-get-onedrive-file.php` |
| `icloud` | `class-wp-mcp-ai-pro-tool-list-icloud-drive-files.php`, `class-wp-mcp-ai-pro-tool-get-icloud-drive-file.php` |
| `shopify` | `class-wp-mcp-ai-pro-tool-shopify-products.php`, `class-wp-mcp-ai-pro-tool-shopify-customers.php`, `class-wp-mcp-ai-pro-tool-shopify-orders.php`, `class-wp-mcp-ai-pro-tool-shopify-inventory.php` |

---

## Security Checklist

- ✅ All credential fields encrypted at rest with AES-256-CBC
- ✅ Masked placeholders prevent credential exposure in edit forms
- ✅ `manage_options` capability required for all admin CRUD operations
- ✅ Nonce verification on all form submissions and AJAX calls
- ✅ Webhook requests verified with platform-specific HMAC signatures
- ✅ iCloud gateway URL validated to require HTTPS
- ✅ WordPress/WooCommerce connections limited to read-only REST API operations
- ✅ Per-assistant access control — connections only available to explicitly assigned assistants
