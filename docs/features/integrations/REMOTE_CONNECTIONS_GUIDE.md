# Remote Connections Guide

**NV Open Operator System (oOS) — Comprehensive Reference**

This guide documents all 24 remote connection types available in the NV oOS Pro addon. Remote connections allow AI assistants to read data from, send messages to, and interact with external services — from WordPress sites and business APIs to every major chat platform.

**Last Updated:** March 2026  
**Plugin Version:** 1.1.4  
**Requires:** NV oOS Pro addon

---

## Table of Contents

1. [Overview](#overview)
2. [All Connection Types (Quick Reference)](#all-connection-types-quick-reference)
3. [Setup Workflow](#setup-workflow)
4. [Security & Encryption](#security--encryption)
5. [Connection Type Details](#connection-type-details)
   - [WordPress / WooCommerce](#1-wordpress--woocommerce)
   - [Mesh Peer (Distributed AI)](#2-mesh-peer-distributed-ai)
   - [Generic REST API](#3-generic-rest-api)
   - [iSAMS School Management](#4-isams-school-management)
   - [Flowhub POS / Retail](#5-flowhub-pos--retail)
   - [PayHere Payment Gateway](#6-payhere-payment-gateway)
   - [QuickBooks Accounting](#7-quickbooks-accounting)
   - [EZuite ERP](#8-ezuite-erp)
   - [Gmail](#9-gmail)
   - [Google Drive](#10-google-drive)
   - [Upwork](#11-upwork)
   - [Telegram](#12-telegram)
   - [WhatsApp Business](#13-whatsapp-business)
   - [Slack](#14-slack)
   - [Discord](#15-discord)
   - [Microsoft Teams](#16-microsoft-teams)
   - [Facebook Messenger](#17-facebook-messenger)
   - [WebChat P2P](#18-webchat-p2p)
   - [Google Chat](#19-google-chat)
   - [Twitter / X](#20-twitter--x)
   - [Apple Messages for Business](#21-apple-messages-for-business)
   - [Office 365](#22-office-365)
   - [iCloud Drive](#23-icloud-drive)
   - [Shopify](#24-shopify)
6. [Webhook Reference](#webhook-reference)
7. [Authentication Methods Reference](#authentication-methods-reference)

---

## Overview

The Remote Sites system (NV oOS → Remote Sites) is the central registry for all external service connections used by AI assistants. Each connection is stored as a record with encrypted credentials and assigned to one or more assistants.

**Key capabilities provided by remote connections:**
- Read data from remote WordPress/WooCommerce sites
- Query business systems (ERP, accounting, POS, school management)
- Send and receive messages via chat platforms
- Access email, cloud storage, and productivity services
- Federation between multiple oOS-powered WordPress sites (Mesh Peers)

**Architecture:**
- Connection records stored as WordPress post meta (type `mcp_ai_remote_site`)
- Credentials encrypted with WordPress auth salt at rest
- Per-assistant access control via the "Remote Site Connections" metabox
- Webhooks registered at `wp-json/mcp-ai/v1/webhooks/{type}/{connection_id}`

---

## All Connection Types (Quick Reference)

| # | Key | Label | Category | Auth Method | Badge |
|---|-----|-------|----------|-------------|-------|
| 1 | `wordpress` | WordPress / WooCommerce | CMS | App Passwords / WooCommerce Keys / JWT / Basic | `#2271b1` |
| 2 | `mesh_peer` | Mesh Peer (Distributed AI) | Federation | API Key (inbound) | `#7e57c2` |
| 3 | `generic` | Generic REST API | API | Bearer / OAuth2 / Custom Header / None | `#50575e` |
| 4 | `isams` | iSAMS (School Management) | Business | API Key + Secret | `#d63638` |
| 5 | `flowhub` | Flowhub (POS / Retail) | Business | Custom Header (API Key) | `#00a32a` |
| 6 | `payhere` | PayHere (Payment Gateway) | Payments | App ID + App Secret | `#f0b849` |
| 7 | `quickbooks` | QuickBooks (Accounting) | Business | OAuth2 | `#2c9f47` |
| 8 | `ezuite_erp` | EZuite ERP (Inventory) | Business | Custom Header (API Key + Secret) | `#8c50a7` |
| 9 | `gmail` | Gmail (Email Service) | Google | OAuth2 Authorization Code | `#ea4335` |
| 10 | `google_drive` | Google Drive (Cloud Storage) | Google | OAuth2 Authorization Code | `#4285f4` |
| 11 | `upwork` | Upwork (Freelance Marketplace) | Freelance | OAuth2 Authorization Code | `#14a800` |
| 12 | `telegram` | Telegram (Chat Channel) | Chat | Bot Token | `#0088cc` |
| 13 | `whatsapp` | WhatsApp Business (Chat Channel) | Chat | Cloud API Access Token | `#25d366` |
| 14 | `slack` | Slack (Chat Channel) | Chat | Bot Token (xoxb-) | `#4a154b` |
| 15 | `discord` | Discord (Chat Channel) | Chat | Bot Token | `#5865f2` |
| 16 | `microsoft_teams` | Microsoft Teams (Chat Channel) | Chat | Azure AD OAuth2 | `#6264a7` |
| 17 | `facebook_messenger` | Facebook Messenger (Chat Channel) | Chat | Page Access Token | `#0084ff` |
| 18 | `webchat` | WebChat P2P (Chat Channel) | Chat | Internal (no third-party API) | `#ff6b6b` |
| 19 | `google_chat` | Google Chat (Chat Channel) | Chat | Service Account / OAuth2 / Webhook | `#1a73e8` |
| 20 | `twitter` | Twitter / X (Chat Channel) | Social | Bearer Token / OAuth 2.0 | `#000000` |
| 21 | `apple_messages` | Apple Messages for Business | Chat | MSP API Key | `#555555` |
| 22 | `office365` | Office 365 (Outlook / OneDrive) | Microsoft | Azure AD OAuth2 | `#d83b01` |
| 23 | `icloud` | iCloud Drive | Apple | Gateway API Key | `#3693f5` |
| 24 | `shopify` | Shopify (E-commerce) | E-commerce | Access Token (Admin) / JWT (Catalog) | `#96bf48` |

---

## Setup Workflow

All connection types follow the same four-step workflow:

### Step 1: Add Connection

1. Go to **NV oOS → Remote Sites** in the WordPress admin.
2. Click **Add New Connection**.
3. Enter a descriptive **Name** (e.g., "Production Store", "Support Telegram Bot").
4. Select the **Connection Type** from the dropdown.
5. The form will update to show the credentials relevant to that type.

### Step 2: Configure Credentials

Fill in all required credential fields for the chosen connection type (see per-type details below). For OAuth-based connections (Gmail, Google Drive, Upwork, QuickBooks, Microsoft Teams), click the **Connect** button after entering Client ID and Client Secret — this launches the OAuth consent flow and stores the refresh token automatically.

### Step 3: Test Connection

Click **Test Connection** to send a lightweight request to the remote service and verify credentials. The test endpoint used varies by connection type. A green success message confirms the credentials are valid.

### Step 4: Assign to Assistant

1. Open or create an AI Assistant (NV oOS → Assistants).
2. In the **Remote Site Connections** metabox, check the connections this assistant should access.
3. Save the assistant.

The assistant can now use the corresponding tools for that connection type.

---

## Security & Encryption

- **Encryption at rest:** All credential fields (API keys, tokens, secrets, passwords) are encrypted using `openssl_encrypt()` with AES-256-CBC, keyed from the WordPress `AUTH_SALT`.
- **Credential preservation:** Editing a connection and saving without changing a credential field preserves the existing encrypted value — the field displays a masked placeholder.
- **Per-assistant access control:** A connection is only usable by assistants it has been explicitly assigned to.
- **Webhook security tokens:** Chat channel webhooks include a per-connection security token embedded in the URL and verified on receipt.
- **Read-only for WordPress:** WordPress/WooCommerce connections only permit read operations via the REST API; no write tools are exposed.
- **HTTPS enforcement:** The gateway URL for iCloud connections is validated to use HTTPS.

---

## Connection Type Details

---

### 1. WordPress / WooCommerce

**Key:** `wordpress`  
**Category:** CMS  
**Badge:** `#2271b1`

**Purpose:** Query content, users, and products from any remote WordPress or WooCommerce site using the WordPress REST API in read-only mode.

#### Credential Fields

| Field | Required | Description |
|-------|----------|-------------|
| `url` | ✅ | Full URL of the remote WordPress site (e.g. `https://store.example.com`) |
| `auth_type` | ✅ | One of: `application_password`, `woocommerce`, `basic_auth`, `jwt`, `none` |
| `username` | Conditional | WordPress username — required for `application_password` and `basic_auth` |
| `password` | Conditional | Application password or user password — required for `application_password` and `basic_auth` |
| `consumer_key` | Conditional | WooCommerce consumer key (format: `ck_…`) — required for `woocommerce` |
| `consumer_secret` | Conditional | WooCommerce consumer secret (format: `cs_…`) — required for `woocommerce` |
| `token` | Conditional | JWT bearer token — required for `jwt` |

#### Auth Method Notes

- **`application_password` (recommended):** Generate on the remote site at **Users → Profile → Application Passwords**. Use the full 24-character generated password.
- **`woocommerce` (recommended for WooCommerce):** Generate REST API keys at **WooCommerce → Settings → Advanced → REST API**. Read-only permission is sufficient.
- **`basic_auth`:** Requires the [WP Basic Auth plugin](https://github.com/WP-API/Basic-Auth) on the remote site (not recommended for production).
- **`jwt`:** Requires a JWT authentication plugin on the remote site (e.g., [JWT Authentication for WP REST API](https://wordpress.org/plugins/jwt-authentication-for-wp-rest-api/)).
- **`none`:** Public REST API only — access is limited to publicly-available endpoints.

#### How to Obtain Credentials

- **Application Passwords:** Remote site admin → Users → Your Profile → scroll to "Application Passwords" → create new.
- **WooCommerce Keys:** Remote WooCommerce admin → Settings → Advanced → REST API → Add key. Set permissions to "Read".
- **JWT Token:** Follow the documentation for the JWT plugin installed on the remote site.

#### Available AI Tools

- `remote_wp_connection` — queries posts, pages, products, orders, users, taxonomies, and custom post types on the remote site.

#### Related Files

- `addons/pro/includes/tools/class-wp-mcp-ai-tool-remote-wp-connection.php`
- `addons/pro/includes/admin/class-wp-mcp-ai-pro-remote-sites-admin.php`
- `addons/pro/includes/admin/class-wp-mcp-ai-pro-remote-site-manager.php`

---

### 2. Mesh Peer (Distributed AI)

**Key:** `mesh_peer`  
**Category:** Federation  
**Badge:** `#7e57c2`

**Purpose:** Connect two NV oOS-powered WordPress sites to form a distributed AI mesh. The local assistant can query and delegate tasks to the remote site's AI system, enabling federated AI workflows across multiple sites.

#### Prerequisites

- The remote site must have NV oOS installed and active.
- Federation must be enabled on the remote site: **Settings → NV oOS → Advanced → Enable Federation**.
- An inbound API key must be generated on the remote site.

#### Credential Fields

| Field | Required | Description |
|-------|----------|-------------|
| `url` | ✅ | Full URL of the remote WordPress site running NV oOS |
| `api_key` | ✅ | Mesh inbound API key — obtained from the remote site's **Settings → Advanced → Federation** page |

#### How to Obtain Credentials

1. On the **remote** NV oOS site, go to **Settings → NV oOS → Advanced → Federation**.
2. Enable Federation and copy the generated Inbound API Key.
3. Paste the key into the `api_key` field of this connection on the **local** site.

#### Available AI Tools

- `remote_wp_connection` — cross-site tool invocation via the federation protocol.

#### Related Files

- `addons/pro/includes/tools/class-wp-mcp-ai-tool-remote-wp-connection.php`
- `addons/pro/includes/admin/class-wp-mcp-ai-mesh-peer-tester.php`

#### Notes

- Mesh peer connections are fully internal — no third-party API services are involved.
- Both sites must be accessible over HTTPS.

---

### 3. Generic REST API

**Key:** `generic`  
**Category:** API  
**Badge:** `#50575e`

**Purpose:** Connect to any third-party REST API not covered by a dedicated connection type. Useful for custom integrations, internal microservices, or APIs without a built-in connector.

#### Credential Fields

| Field | Required | Description |
|-------|----------|-------------|
| `url` | ✅ | Base URL of the API (e.g. `https://api.example.com/v2`) |
| `auth_type` | ✅ | One of: `bearer`, `custom_header`, `basic_auth`, `oauth2`, `none` |
| `token` | Conditional | Bearer token or JWT — required for `bearer` and `custom_header` |
| `username` / `password` | Conditional | Required for `basic_auth` |
| `test_endpoint` | Optional | Path appended to base URL for health check tests (e.g. `/health`) |

#### Available AI Tools

- `generic_rest` — executes GET/POST requests against the configured API with the stored credentials.

#### Related Files

- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-generic-rest.php`

---

### 4. iSAMS School Management

**Key:** `isams`  
**Category:** Business  
**Badge:** `#d63638`

**Purpose:** Connect to an iSAMS school management system to query student records, timetables, staff, exam results, and other school data via the iSAMS REST API.

#### Credential Fields

| Field | Required | Description |
|-------|----------|-------------|
| `url` | ✅ | iSAMS instance URL (e.g. `https://yourschool.isams.cloud`) |
| `api_key` | ✅ | iSAMS API key |
| `api_secret` | ✅ | iSAMS API secret |

#### How to Obtain Credentials

1. Log in to iSAMS as an admin.
2. Navigate to **Settings → API Management → API Keys**.
3. Create a new API key with the required read permissions.
4. Copy the API Key and API Secret.

#### API Reference

- **API Documentation:** https://developerdocs.isams.cloud/
- **Base URL format:** `https://{instance}.isams.cloud/api/`

#### Available AI Tools

- `isams_query` — query student, staff, timetable, and exam data.
- `sync_ecas_from_isams` — synchronise ECAS data from iSAMS.

#### Related Files

- `addons/pro/includes/tools/class-wp-mcp-ai-tool-isams-query.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-tool-sync-ecas-from-isams.php`

---

### 5. Flowhub POS / Retail

**Key:** `flowhub`  
**Category:** Business  
**Badge:** `#00a32a`

**Purpose:** Connect to Flowhub, a cannabis retail point-of-sale platform, to query real-time inventory, product listings, and location data.

#### Credential Fields

| Field | Required | Description |
|-------|----------|-------------|
| `api_key` | ✅ | Flowhub API key |
| `client_id` | ✅ | Flowhub client ID |
| `client_secret` | ✅ | Flowhub client secret |
| `location_id` | ✅ | Flowhub location ID |

**Note:** The API URL is automatically set to `https://api.flowhub.co` — do not override this.

#### How to Obtain Credentials

1. Log in to Flowhub at https://flowhub.com/.
2. Navigate to **Settings → API Access**.
3. Generate API credentials for your location.

#### API Reference

- **API Base URL:** `https://api.flowhub.co`
- **Developer Docs:** https://developers.flowhub.com/

#### Available AI Tools

- `flowhub_get_inventory` — retrieves current inventory levels and product details.

#### Related Files

- `addons/pro/includes/tools/class-wp-mcp-ai-tool-flowhub-get-inventory.php`

---

### 6. PayHere Payment Gateway

**Key:** `payhere`  
**Category:** Payments  
**Badge:** `#f0b849`

**Purpose:** Integrate with PayHere, a payment gateway popular in Sri Lanka, to verify payments, check transaction status, and query payment records.

#### Credential Fields

| Field | Required | Description |
|-------|----------|-------------|
| `url` | ✅ | PayHere API URL (`https://www.payhere.lk` for live, `https://sandbox.payhere.lk` for test) |
| `app_id` | ✅ | PayHere App ID |
| `app_secret` | ✅ | PayHere App Secret |
| `sandbox_mode` | Optional | Check to use the PayHere sandbox environment for testing |

#### How to Obtain Credentials

1. Log in to the [PayHere Merchant Portal](https://www.payhere.lk/account/merchant/).
2. Go to **Integration → Domains & Credentials**.
3. Copy the **App ID** and **App Secret** for your domain.

#### API Reference

- **API Documentation:** https://support.payhere.lk/api-&-mobile-sdk
- **Live URL:** `https://www.payhere.lk`
- **Sandbox URL:** `https://sandbox.payhere.lk`

#### Related Files

- Payment tools in `addons/pro/includes/tools/`

---

### 7. QuickBooks Accounting

**Key:** `quickbooks`  
**Category:** Business  
**Badge:** `#2c9f47`

**Purpose:** Connect to QuickBooks Online (Intuit) to query financial reports, invoices, expenses, accounts, customers, vendors, and other accounting data.

#### Credential Fields

| Field | Required | Description |
|-------|----------|-------------|
| `client_id` | ✅ | Intuit app Client ID |
| `client_secret` | ✅ | Intuit app Client Secret |
| `company_id` | ✅ | QuickBooks Company ID (also called Realm ID) |
| `refresh_token` | Auto | OAuth2 refresh token — stored automatically after OAuth flow |

**Note:** After entering Client ID, Client Secret, and Company ID, click **Connect to QuickBooks** to complete the OAuth2 flow. The refresh token is stored automatically.

#### How to Obtain Credentials

1. Go to the [Intuit Developer Portal](https://developer.intuit.com/).
2. Create a new app or select an existing one.
3. Under **Keys & credentials**, copy the **Client ID** and **Client Secret** for the production environment.
4. Your **Company ID** (Realm ID) is visible in the QuickBooks Online URL after `/app/homepage?realmId=`.

#### API Reference

- **OAuth2 URL:** `https://appcenter.intuit.com/connect/oauth2`
- **API Base:** `https://quickbooks.api.intuit.com/v3/company/{company_id}/`
- **Developer Docs:** https://developer.intuit.com/app/developer/qbo/docs/develop

#### Available AI Tools

- `get_quickbooks_report` — retrieves financial reports and accounting data.

#### Related Files

- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-get-quickbooks-report.php`

---

### 8. EZuite ERP

**Key:** `ezuite_erp`  
**Category:** Business  
**Badge:** `#8c50a7`

**Purpose:** Connect to EZuite ERP to query inventory levels, product data, warehouse information, and other enterprise resource planning data.

#### Credential Fields

| Field | Required | Description |
|-------|----------|-------------|
| `api_key` | ✅ | EZuite API key |
| `api_secret` | ✅ | EZuite API secret |

**Note:** The API URL is automatically set to `https://api.ezuite.com/api/External_Api/Action_Api/Invoke`.

#### How to Obtain Credentials

Contact EZuite support or your EZuite account manager to obtain API credentials.

- **Website:** https://www.ezuite.com/
- **API Endpoint:** `https://api.ezuite.com/api/External_Api/Action_Api/Invoke`

#### Available AI Tools

- `ezuite_erp` — queries inventory, products, and warehouse data.

#### Related Files

- `addons/pro/includes/tools/class-wp-mcp-ai-tool-ezuite-erp.php`

---

### 9. Gmail

**Key:** `gmail`  
**Category:** Google  
**Badge:** `#ea4335`

**Purpose:** Connect to a Gmail account to search and read emails via the Gmail API. Uses OAuth2 with read-only scope — no emails can be sent or deleted through this connection.

#### Credential Fields

| Field | Required | Description |
|-------|----------|-------------|
| `client_id` | ✅ | Google OAuth2 Client ID (format: `*.apps.googleusercontent.com`) |
| `client_secret` | ✅ | Google OAuth2 Client Secret (encrypted at rest) |
| `refresh_token` | Auto | OAuth2 refresh token — stored automatically after OAuth flow |
| `user_email` | Auto | Connected Gmail address — auto-filled after OAuth |

**Note:** The API URL is automatically set to `https://gmail.googleapis.com`. After entering Client ID and Client Secret, click **Connect Gmail** to complete the OAuth2 consent flow.

#### How to Obtain Credentials

1. Go to the [Google Cloud Console](https://console.cloud.google.com/).
2. Create a project (or select an existing one).
3. Enable the **Gmail API** under APIs & Services → Library.
4. Go to **APIs & Services → Credentials → Create Credentials → OAuth client ID**.
5. Choose **Web application**, set the authorised redirect URI to: `{your-site}/wp-json/mcp-ai/v1/oauth/callback/gmail`
6. Copy the **Client ID** and **Client Secret**.

#### API Reference

- **API Base URL:** `https://gmail.googleapis.com`
- **OAuth Scope:** `https://www.googleapis.com/auth/gmail.readonly`
- **API Documentation:** https://developers.google.com/gmail/api

#### Available AI Tools

- `search_gmail` — searches Gmail for messages matching a query.

#### Related Files

- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-search-gmail.php`

---

### 10. Google Drive

**Key:** `google_drive`  
**Category:** Google  
**Badge:** `#4285f4`

**Purpose:** Connect to a Google Drive account to search, list, and read files. Uses OAuth2 with read-only metadata and file content scopes.

#### Credential Fields

| Field | Required | Description |
|-------|----------|-------------|
| `client_id` | ✅ | Google OAuth2 Client ID |
| `client_secret` | ✅ | Google OAuth2 Client Secret (encrypted at rest) |
| `refresh_token` | Auto | OAuth2 refresh token — stored automatically after OAuth flow |
| `folder_id` | Optional | Google Drive folder ID to limit access scope |
| `user_email` | Auto | Connected Google account email — auto-filled after OAuth |

**Note:** The API URL is automatically set to `https://www.googleapis.com/drive/v3`.

#### How to Obtain Credentials

Same process as Gmail (see above). Enable the **Google Drive API** instead of (or in addition to) Gmail API. Use the same OAuth client credentials for both if needed. Set the authorised redirect URI to: `{your-site}/wp-json/mcp-ai/v1/oauth/callback/google_drive`

#### API Reference

- **API Base URL:** `https://www.googleapis.com/drive/v3`
- **OAuth Scopes:** `https://www.googleapis.com/auth/drive.readonly`, `https://www.googleapis.com/auth/drive.metadata.readonly`
- **API Documentation:** https://developers.google.com/drive/api/v3/reference

#### Available AI Tools

- `search_drive` — searches Google Drive files by name, content, or MIME type.

#### Related Files

- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-search-drive.php`

---

### 11. Upwork

**Key:** `upwork`  
**Category:** Freelance  
**Badge:** `#14a800`

**Purpose:** Connect to the Upwork freelance marketplace to search job postings, score job fit, and draft proposals — primarily used with the CRM toolkit.

#### Credential Fields

| Field | Required | Description |
|-------|----------|-------------|
| `client_id` | ✅ | Upwork app Client ID |
| `client_secret` | ✅ | Upwork app Client Secret (encrypted at rest) |
| `refresh_token` | Auto | OAuth2 refresh token — stored automatically after OAuth flow |
| `user_email` | Auto | Connected Upwork username — auto-filled after OAuth |

**Note:** The API URL is automatically set to `https://api.upwork.com/graphql`.

#### How to Obtain Credentials

1. Go to the [Upwork Developer Portal](https://www.upwork.com/developer/).
2. Create a new app and request the **"Read marketplace Job Postings"** permission.
3. Set the OAuth2 callback URL to: `{your-site}/wp-json/mcp-ai/v1/oauth/callback/upwork`
4. Copy the **Client ID** and **Client Secret**.

#### API Reference

- **GraphQL API:** `https://api.upwork.com/graphql`
- **OAuth2 Token URL:** `https://www.upwork.com/api/v3/oauth2/token`
- **OAuth2 Auth URL:** `https://www.upwork.com/ab/account-security/oauth2/authorize`
- **Required Permission:** Read marketplace Job Postings

#### Available AI Tools

- `search_upwork_jobs` — searches Upwork for relevant job postings.
- `score_upwork_job` — scores a job posting for fit against a profile.
- `draft_upwork_proposal` — drafts a proposal for a specific job posting.

#### Related Files

- `addons/pro/includes/tools/class-wp-mcp-ai-tool-search-upwork-jobs.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-tool-score-upwork-job.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-tool-draft-upwork-proposal.php`

---

### 12. Telegram

**Key:** `telegram`  
**Category:** Chat  
**Badge:** `#0088cc`

**Purpose:** Connect a Telegram bot to receive and send messages via Telegram channels, groups, and direct messages. The webhook allows the AI assistant to auto-reply to incoming Telegram messages.

#### Credential Fields

| Field | Required | Description |
|-------|----------|-------------|
| `api_key` | ✅ | Telegram Bot Token (format: `123456:ABCdef…`) — from @BotFather |
| `bot_username` | Optional | Bot username (for display/reference) |
| `secret_token` | Optional | Webhook secret token (A–Z, a–z, 0–9, `_`, `-`, 1–256 chars) — for webhook verification |
| `enable_groups` | Optional | Allow the bot to receive messages in groups |
| `enable_web_login` | Optional | Enable [Telegram Login Widget](https://core.telegram.org/widgets/login) |
| `web_login_redirect_url` | Optional | URL to redirect to after Telegram login |
| `auto_create_wp_user` | Optional | Automatically create a WordPress user from an authenticating Telegram user |
| `new_user_role` | Optional | WordPress role assigned to auto-created users |
| `allowed_chat_ids` | Optional | Comma-separated list of chat IDs allowed to interact with the bot |
| `welcome_message` | Optional | Message sent to new users who start a conversation |
| `parse_mode` | Optional | Message formatting: `HTML`, `Markdown`, or `MarkdownV2` |
| `assigned_assistant_ids` | Optional | Assistant(s) that auto-reply to incoming messages |

#### How to Obtain Credentials

1. Open Telegram and start a chat with [@BotFather](https://t.me/BotFather).
2. Send `/newbot` and follow the prompts to create a bot.
3. Copy the **Bot Token** provided.

#### API Reference

- **API Base URL:** `https://api.telegram.org`
- **Bot API Docs:** https://core.telegram.org/bots/api

#### Webhook

```
POST {your-site}/wp-json/mcp-ai/v1/webhooks/telegram/{connection_id}
```

#### Available AI Tools

- `send_telegram_message` — sends a message to a Telegram chat.
- `get_telegram_updates` — retrieves recent updates/messages from Telegram.
- `manage_telegram_commands` — manages bot command menus.
- `manage_telegram_webhook` — registers/unregisters the webhook with Telegram.
- `add_telegram_message_reaction` — adds a reaction to a Telegram message.

#### Related Files

- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-send-telegram-message.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-get-telegram-updates.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-manage-telegram-commands.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-manage-telegram-webhook.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-add-telegram-message-reaction.php`

---

### 13. WhatsApp Business

**Key:** `whatsapp`  
**Category:** Chat  
**Badge:** `#25d366`

**Purpose:** Connect to WhatsApp Business via the Meta Cloud API to send and receive WhatsApp messages. Enables AI assistants to auto-reply to customer messages on a WhatsApp Business number.

#### Credential Fields

| Field | Required | Description |
|-------|----------|-------------|
| `api_key` | ✅ | WhatsApp Cloud API Access Token |
| `api_secret` | ✅ | Facebook App Secret (for webhook signature verification) |
| `app_id` | ✅ | Facebook App ID |
| `phone_number_id` | ✅ | WhatsApp Business Phone Number ID |
| `business_account_id` | Optional | WhatsApp Business Account ID |
| `display_phone_number` | Optional | Display phone number (for reference) |
| `verify_token` | ✅ | Webhook verify token (you define this; Meta will send it during verification) |
| `graph_api_version` | Optional | Meta Graph API version (default: `v21.0`) |

#### How to Obtain Credentials

1. Go to [Meta for Developers](https://developers.facebook.com/) and create an app of type "Business".
2. Add the **WhatsApp** product to your app.
3. In the WhatsApp setup panel, note the **Phone Number ID** and **WhatsApp Business Account ID**.
4. Generate a permanent **System User Access Token** in the Meta Business Suite.
5. Use your **App ID** and **App Secret** from the app dashboard.

#### API Reference

- **API Base URL:** `https://graph.facebook.com/{version}` (default: `v21.0`)
- **Cloud API Docs:** https://developers.facebook.com/docs/whatsapp/cloud-api

#### Webhook

```
POST {your-site}/wp-json/mcp-ai/v1/webhooks/whatsapp/{connection_id}
```

#### Available AI Tools

- `send_whatsapp_message` — sends a WhatsApp message.
- `get_whatsapp_messages` — retrieves recent messages.

#### Related Files

- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-send-whatsapp-message.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-get-whatsapp-messages.php`

---

### 14. Slack

**Key:** `slack`  
**Category:** Chat  
**Badge:** `#4a154b`

**Purpose:** Connect to a Slack workspace to read and send messages across channels, allowing AI assistants to participate in Slack conversations.

#### Credential Fields

| Field | Required | Description |
|-------|----------|-------------|
| `api_key` | ✅ | Slack Bot Token (format: `xoxb-…`) |
| `signing_secret` | ✅ | Slack Signing Secret (for webhook request verification) |
| `workspace_id` | Optional | Slack Workspace ID (for reference) |
| `slack_bot_user_id` | Optional | Bot User ID (for mention detection) |

#### How to Obtain Credentials

1. Go to [api.slack.com/apps](https://api.slack.com/apps) and create a new app.
2. Under **OAuth & Permissions**, add the following Bot Token Scopes: `channels:read`, `chat:write`, `groups:read`, `im:read`, `mpim:read`, `users:read`.
3. Install the app to your workspace and copy the **Bot User OAuth Token** (`xoxb-…`).
4. Under **Basic Information**, copy the **Signing Secret**.

#### API Reference

- **API Base URL:** `https://slack.com/api`
- **API Docs:** https://api.slack.com/methods

#### Webhook

```
POST {your-site}/wp-json/mcp-ai/v1/webhooks/slack/{connection_id}
```

#### Available AI Tools

- `send_slack_message` — sends a message to a Slack channel.
- `get_slack_messages` — retrieves messages from a channel.
- `get_slack_channels` — lists channels in the workspace.
- `create_slack_channel` — creates a new Slack channel.

#### Related Files

- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-send-slack-message.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-get-slack-messages.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-get-slack-channels.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-create-slack-channel.php`

---

### 15. Discord

**Key:** `discord`  
**Category:** Chat  
**Badge:** `#5865f2`

**Purpose:** Connect a Discord bot to read and send messages in Discord servers (guilds), manage channels, and react to messages.

#### Credential Fields

| Field | Required | Description |
|-------|----------|-------------|
| `api_key` | ✅ | Discord Bot Token |
| `application_id` | ✅ | Discord Application ID |
| `guild_id` | Optional | Default Guild (Server) ID |
| `public_key` | ✅ | Discord Public Key (for webhook signature verification) |

#### How to Obtain Credentials

1. Go to the [Discord Developer Portal](https://discord.com/developers/applications).
2. Create a new application, then go to the **Bot** section.
3. Click **Reset Token** and copy the **Bot Token**.
4. Copy the **Application ID** and **Public Key** from the **General Information** section.
5. Invite the bot to your server using the OAuth2 URL generator with the `bot` scope and required permissions.

#### API Reference

- **API Base URL:** `https://discord.com/api/v10`
- **API Docs:** https://discord.com/developers/docs/reference

#### Webhook

```
POST {your-site}/wp-json/mcp-ai/v1/webhooks/discord/{connection_id}
```

#### Available AI Tools

- `send_discord_message` — sends a message to a Discord channel.
- `get_discord_messages` — retrieves messages from a channel.
- `get_discord_channels` — lists channels in a guild.
- `get_discord_voice_channel_members` — lists members in a voice channel.
- `add_discord_message_reaction` — adds a reaction emoji to a message.
- `create_discord_channel` — creates a new channel in a guild.

#### Related Files

- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-send-discord-message.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-get-discord-messages.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-get-discord-channels.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-get-discord-voice-channel-members.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-add-discord-message-reaction.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-create-discord-channel.php`

---

### 16. Microsoft Teams

**Key:** `microsoft_teams`  
**Category:** Chat  
**Badge:** `#6264a7`

**Purpose:** Connect an Azure Bot Service bot to Microsoft Teams to read messages and enable AI assistants to participate in Teams channels and chats.

#### Credential Fields

| Field | Required | Description |
|-------|----------|-------------|
| `client_id` | ✅ | Azure AD App Client ID (also called App ID) |
| `client_secret` | ✅ | Azure AD App Client Secret |
| `tenant_id` | ✅ | Azure AD Tenant ID |
| `signing_secret` | Optional | Teams outgoing webhook security token (for verification) |
| `assigned_assistant_ids` | Optional | Assistant(s) to auto-reply to incoming Teams messages |

#### How to Obtain Credentials

1. Go to the [Azure Portal](https://portal.azure.com/) → **Azure Active Directory → App registrations → New registration**.
2. Register a new app; copy the **Application (client) ID** and **Directory (tenant) ID**.
3. Under **Certificates & secrets**, create a new client secret and copy the value.
4. Register the bot in [Azure Bot Service](https://portal.azure.com/#create/Microsoft.AzureBot) using the same App ID.
5. Set the messaging endpoint to your webhook URL (below).
6. Add the Microsoft Teams channel in the bot's Channels section.

#### API Reference

- **Bot Framework URL:** `https://smba.trafficmanager.net/apis`
- **Microsoft Graph API:** `https://graph.microsoft.com/v1.0`
- **Azure Bot Docs:** https://docs.microsoft.com/en-us/azure/bot-service/

#### Webhook

```
POST {your-site}/wp-json/mcp-ai/v1/webhooks/teams/{connection_id}
```

#### Available AI Tools

- `get_teams_channels` — lists channels in a Teams team.
- `get_teams_messages` — retrieves messages from a Teams channel.

#### Related Files

- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-get-teams-channels.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-get-teams-messages.php`

---

### 17. Facebook Messenger

**Key:** `facebook_messenger`  
**Category:** Chat  
**Badge:** `#0084ff`

**Purpose:** Connect a Facebook Page to receive and send Messenger messages via the Meta Graph API. AI assistants can auto-reply to customers who message your Facebook Page.

#### Credential Fields

| Field | Required | Description |
|-------|----------|-------------|
| `api_key` | ✅ | Facebook Page Access Token |
| `api_secret` | ✅ | Facebook App Secret (for webhook signature verification) |
| `app_id` | ✅ | Facebook App ID |
| `page_id` | ✅ | Facebook Page ID |
| `verify_token` | ✅ | Webhook verify token (you define this; Meta uses it during webhook verification) |
| `graph_api_version` | Optional | Meta Graph API version (default: `v21.0`) |

#### How to Obtain Credentials

1. Go to [Meta for Developers](https://developers.facebook.com/) → create or select an app.
2. Add the **Messenger** product.
3. In Messenger settings, generate a **Page Access Token** for your Facebook Page.
4. Copy the **App ID** and **App Secret** from the app dashboard.

#### API Reference

- **API Base URL:** `https://graph.facebook.com/{version}` (default: `v21.0`)
- **Messenger Docs:** https://developers.facebook.com/docs/messenger-platform

#### Webhook

```
POST {your-site}/wp-json/mcp-ai/v1/webhooks/messenger/{connection_id}
```

#### Available AI Tools

- `send_messenger_message` — sends a message to a Messenger conversation.
- `get_messenger_conversations` — retrieves Messenger conversations.
- `create_messenger_broadcast` — sends a broadcast message to multiple recipients.

#### Related Files

- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-send-messenger-message.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-get-messenger-conversations.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-create-messenger-broadcast.php`

---

### 18. WebChat P2P

**Key:** `webchat`  
**Category:** Chat  
**Badge:** `#ff6b6b`

**Purpose:** An internal peer-to-peer web chat connection. Provides a real-time chat channel between your site and visitors without any third-party API. All communication is handled entirely within your WordPress installation.

#### Credential Fields

| Field | Required | Description |
|-------|----------|-------------|
| `p2p_connection_id` | Auto | Unique P2P connection identifier — auto-generated on save |
| `api_secret` | Optional | Encryption key for securing the P2P channel |

**Note:** The URL is automatically set to `{your-site}/wp-json/mcp-ai/v1/webhooks/webchat`. No external credentials are required — this connection is fully internal.

#### Notes

- No third-party API calls are made.
- Does not require registering with any external platform.
- Suitable for embedding a real-time AI chat widget on your site.

---

### 19. Google Chat

**Key:** `google_chat`  
**Category:** Chat  
**Badge:** `#1a73e8`

**Purpose:** Connect to Google Chat (Google Workspace) to send and receive messages in Spaces, manage Space members, and interact with Google Chat bots.

#### Credential Fields

| Field | Required | Description |
|-------|----------|-------------|
| `connection_method` | ✅ | One of: `service_account`, `oauth`, `webhook` |
| `api_key` | Conditional | Service Account JSON key (for `service_account` method) |
| `client_id` | Conditional | OAuth Client ID (for `oauth` method) |
| `client_secret` | Conditional | OAuth Client Secret (for `oauth` method) |
| `refresh_token` | Conditional | OAuth refresh token (for `oauth` method) — stored after OAuth flow |
| `google_chat_space` | Optional | Space ID or name (e.g. `spaces/AAAA`) |
| `verify_token` | Optional | Audience URL for OIDC webhook verification |
| `reply_webhook_url` | Optional | Incoming webhook URL for sending to a specific Space |

**Note:** The API URL is automatically set to `https://chat.googleapis.com/v1`.

#### How to Obtain Credentials

**For Service Account:**
1. In [Google Cloud Console](https://console.cloud.google.com/), create a Service Account.
2. Enable the **Google Chat API**.
3. Grant the service account access to the relevant Chat Spaces.
4. Download the JSON key file and paste its contents into the `api_key` field.

**For OAuth:**
Same process as Gmail — create OAuth2 credentials, enable the Google Chat API, and use the Connect button.

#### API Reference

- **API Base URL:** `https://chat.googleapis.com/v1`
- **API Docs:** https://developers.google.com/chat/api/reference/rest

#### Available AI Tools

- `send_google_chat_message` — sends a message to a Google Chat Space.
- `get_google_chat_messages` — retrieves messages from a Space.
- `get_google_chat_spaces` — lists Google Chat Spaces.
- `create_google_chat_space` — creates a new Space.
- `add_google_chat_space_member` — adds a member to a Space.
- `list_google_chat_space_members` — lists members of a Space.
- `remove_google_chat_space_member` — removes a member from a Space.

#### Related Files

- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-send-google-chat-message.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-get-google-chat-messages.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-get-google-chat-spaces.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-create-google-chat-space.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-add-google-chat-space-member.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-list-google-chat-space-members.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-remove-google-chat-space-member.php`

---

### 20. Twitter / X

**Key:** `twitter`  
**Category:** Social  
**Badge:** `#000000`

**Purpose:** Connect to the Twitter/X API v2 to read direct messages and manage webhook subscriptions for real-time DM notifications.

#### Credential Fields

| Field | Required | Description |
|-------|----------|-------------|
| `api_key` | ✅ | Bearer Token or OAuth 2.0 access token |
| `twitter_user_id` | Optional | Twitter User ID (required for DM access) |

**Note:** The API URL is automatically set to `https://api.twitter.com/2`.

#### How to Obtain Credentials

1. Go to the [Twitter Developer Portal](https://developer.twitter.com/) and create an app.
2. Under **Keys and Tokens**, generate a **Bearer Token** for v2 API access.
3. For DM access, you need **Elevated** API access level.
4. Find your Twitter User ID via `GET /2/users/by/username/:username`.

#### API Reference

- **API Base URL:** `https://api.twitter.com/2`
- **API v2 Docs:** https://developer.twitter.com/en/docs/twitter-api
- **Developer Portal:** https://developer.twitter.com/

#### Available AI Tools

- `get_twitter_dms` — retrieves Twitter/X direct messages.
- `manage_twitter_webhook` — manages Twitter webhook subscriptions for real-time DM events.

#### Related Files

- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-get-twitter-dms.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-manage-twitter-webhook.php`

---

### 21. Apple Messages for Business

**Key:** `apple_messages`  
**Category:** Chat  
**Badge:** `#555555`

**Purpose:** Connect to Apple Messages for Business to communicate with customers through the Apple Messages app. Requires an approved Message Service Provider (MSP).

#### Credential Fields

| Field | Required | Description |
|-------|----------|-------------|
| `url` | ✅ | MSP API URL (varies by provider — user-configured) |
| `api_key` | ✅ | MSP API key |
| `api_secret` | ✅ | Webhook secret (for message verification) |
| `business_id` | ✅ | Apple Business Chat Business ID |
| `verify_token` | Optional | Webhook verify token |

#### Prerequisites

- Register your business at [Apple Business Register](https://register.apple.com/).
- Sign up with an approved [Apple Message Service Provider (MSP)](https://register.apple.com/msp/).
- The MSP provides the API URL and credentials for their platform.

#### How to Obtain Credentials

1. Register at https://register.apple.com/ and get approved.
2. Choose and sign up with an MSP.
3. The MSP will provide: API URL, API key, API secret, and your Business ID.

#### API Reference

- **API Docs:** https://register.apple.com/resources/messages-for-business/MSP_Spec.pdf
- **Apple Business Register:** https://register.apple.com/

#### Webhook

```
POST {your-site}/wp-json/mcp-ai/v1/webhooks/apple/{connection_id}
```

#### Available AI Tools

- `send_apple_message` — sends a standard Apple Messages reply.
- `send_apple_message_group` — sends a group Apple Messages reply.
- `send_apple_message_interactive` — sends an interactive Apple Messages reply (with buttons/pickers).
- `get_apple_messages` — retrieves messages from Apple Messages for Business.

#### Related Files

- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-send-apple-message.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-send-apple-message-group.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-send-apple-message-interactive.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-get-apple-messages.php`

---

### 22. Office 365

**Key:** `office365`  
**Category:** Microsoft  
**Badge:** `#d83b01`

**Purpose:** Connect to Microsoft Office 365 via the Microsoft Graph API to read and send Outlook emails, and list/read files in OneDrive.

#### Credential Fields

| Field | Required | Description |
|-------|----------|-------------|
| `client_id` | ✅ | Azure AD App Client ID |
| `client_secret` | ✅ | Azure AD App Client Secret |
| `tenant_id` | ✅ | Azure AD Tenant ID |
| `token` | Conditional | Microsoft Graph Bearer Token (for direct token entry instead of OAuth flow) |
| `enabled_services` | Optional | Which services to enable: `outlook_mail`, `onedrive`, etc. |
| `outlook_mailbox_folder` | Optional | Outlook mailbox folder to read from (default: Inbox) |
| `onedrive_folder_path` | Optional | OneDrive folder path to limit file access |

**Note:** The API URL is automatically set to `https://graph.microsoft.com/v1.0`.

#### How to Obtain Credentials

1. Go to the [Azure Portal](https://portal.azure.com/) → **Azure Active Directory → App registrations → New registration**.
2. Copy the **Application (client) ID** and **Directory (tenant) ID**.
3. Under **Certificates & secrets**, create a new client secret.
4. Under **API permissions**, add the required Microsoft Graph permissions:
   - `Mail.ReadWrite` (for Outlook)
   - `Files.ReadWrite` (for OneDrive)
5. Grant admin consent for the permissions.

#### API Reference

- **API Base URL:** `https://graph.microsoft.com/v1.0`
- **Graph API Docs:** https://learn.microsoft.com/en-us/graph/api/overview
- **Azure AD Docs:** https://docs.microsoft.com/en-us/azure/active-directory/

#### Webhook

```
POST {your-site}/wp-json/mcp-ai/v1/webhooks/office365/{connection_id}
```

#### Available AI Tools

- `send_outlook_mail` — sends an email via Outlook.
- `get_outlook_messages` — retrieves Outlook messages.
- `list_onedrive_files` — lists files and folders in OneDrive.
- `get_onedrive_file` — retrieves a file from OneDrive.

#### Related Files

- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-send-outlook-mail.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-get-outlook-messages.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-list-onedrive-files.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-get-onedrive-file.php`

---

### 23. iCloud Drive

**Key:** `icloud`  
**Category:** Apple  
**Badge:** `#3693f5`

**Purpose:** Connect to iCloud Drive to list and read files. Apple does not provide a direct third-party REST API for iCloud Drive — this connection requires a self-hosted or third-party gateway that bridges the CloudKit framework.

#### Credential Fields

| Field | Required | Description |
|-------|----------|-------------|
| `gateway_api_url` | ✅ | HTTPS URL of the iCloud gateway (user-configured — must be HTTPS) |
| `api_key` | ✅ | Gateway API key or bearer token |
| `enabled_services` | Optional | Which iCloud services to enable (e.g. `icloud_drive`) |
| `icloud_default_folder_id` | Optional | Default iCloud Drive folder ID to scope file access |

#### Notes

- There is no official Apple REST API for third-party iCloud Drive access.
- You must deploy a compatible gateway (e.g. a self-hosted CloudKit proxy) and configure its HTTPS URL here.
- HTTP gateway URLs are rejected for security.
- Privacy and legal terms depend on the gateway service configured by the site administrator.

#### API Reference

- **Apple iCloud Terms:** https://www.apple.com/legal/internet-services/icloud/
- **Apple CloudKit:** https://developer.apple.com/icloud/

#### Webhook

```
POST {your-site}/wp-json/mcp-ai/v1/webhooks/icloud/{connection_id}
```

#### Available AI Tools

- `list_icloud_drive_files` — lists files and folders in iCloud Drive.
- `get_icloud_drive_file` — retrieves a file from iCloud Drive.

#### Related Files

- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-list-icloud-drive-files.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-get-icloud-drive-file.php`

---

### 24. Shopify

**Key:** `shopify`  
**Category:** E-commerce  
**Badge:** `#96bf48`

**Purpose:** Connect to a Shopify store to query products, customers, orders, and inventory. Supports two API modes: Admin API (for store management) and Catalog API (for public catalog browsing).

#### Credential Fields

| Field | Required | Description |
|-------|----------|-------------|
| `shopify_api_mode` | ✅ | API mode: `admin_api` or `catalog_api` |

**Admin API mode:**

| Field | Required | Description |
|-------|----------|-------------|
| `url` | ✅ | Shopify store URL (format: `https://{store}.myshopify.com`) |
| `api_key` | ✅ | Admin API Access Token (format: `shpat_…` for custom apps or `shpca_…` for public apps) |
| `api_secret` | Optional | Storefront API token (for frontend access) |
| `shopify_api_version` | Optional | API version (default: `2025-01`) |

**Catalog API mode:**

| Field | Required | Description |
|-------|----------|-------------|
| `api_key` | ✅ | Catalog API Client ID |
| `api_secret` | ✅ | Catalog API Client Secret (format: `shpss_…`) |

**Note:** The URL is automatically set to:
- Admin API: `https://{store}.myshopify.com`
- Catalog API: `https://discover.shopifyapps.com`

For Catalog API, a JWT bearer token is dynamically fetched from `https://api.shopify.com/auth/access_token` using the Client ID and Secret.

#### How to Obtain Credentials

**Admin API:**
1. In your Shopify admin, go to **Settings → Apps and sales channels → Develop apps**.
2. Create a custom app with the required Admin API access scopes.
3. Install the app to reveal the **Admin API access token** (`shpat_…`).

**Catalog API:**
1. Register as a Shopify Partner at https://partners.shopify.com/.
2. Create an app and enable Catalog API access.
3. Note the **Client ID** and **Client Secret**.

#### API Reference

- **Admin API Docs:** https://shopify.dev/docs/api/admin-rest
- **Catalog API Docs:** https://shopify.dev/docs/api/usage/authentication
- **Shopify Partners:** https://partners.shopify.com/

#### Available AI Tools

- `shopify_products` — lists and searches products.
- `shopify_customers` — queries customer records.
- `shopify_orders` — retrieves orders.
- `shopify_inventory` — checks inventory levels.

#### Related Files

- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-shopify-products.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-shopify-customers.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-shopify-orders.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-shopify-inventory.php`

---

## Webhook Reference

Chat channel connections register webhooks so external platforms can push incoming messages to your WordPress site in real time. All webhook URLs follow the pattern:

```
{your-site}/wp-json/mcp-ai/v1/webhooks/{type}/{connection_id}
```

| Connection Type | Webhook Path |
|-----------------|--------------|
| `telegram` | `/wp-json/mcp-ai/v1/webhooks/telegram/{id}` |
| `whatsapp` | `/wp-json/mcp-ai/v1/webhooks/whatsapp/{id}` |
| `slack` | `/wp-json/mcp-ai/v1/webhooks/slack/{id}` |
| `discord` | `/wp-json/mcp-ai/v1/webhooks/discord/{id}` |
| `microsoft_teams` | `/wp-json/mcp-ai/v1/webhooks/teams/{id}` |
| `facebook_messenger` | `/wp-json/mcp-ai/v1/webhooks/messenger/{id}` |
| `webchat` | `/wp-json/mcp-ai/v1/webhooks/webchat` |
| `google_chat` | N/A — pushed via Google Chat bot endpoint |
| `apple_messages` | `/wp-json/mcp-ai/v1/webhooks/apple/{id}` |
| `office365` | `/wp-json/mcp-ai/v1/webhooks/office365/{id}` |
| `icloud` | `/wp-json/mcp-ai/v1/webhooks/icloud/{id}` |

**Security:** Each webhook verifies the incoming request using a platform-specific signature (HMAC-SHA256 for most platforms) or a shared secret token. Requests failing verification are rejected with HTTP 403.

---

## Authentication Methods Reference

| Method | Used By | Description |
|--------|---------|-------------|
| `application_password` | WordPress | WordPress Application Passwords (recommended) |
| `woocommerce` | WordPress (WC) | WooCommerce consumer key + secret via query params |
| `basic_auth` | WordPress, Generic | HTTP Basic Authentication (username + password) |
| `jwt` | WordPress, Generic | JSON Web Token bearer authentication |
| `bearer` | Generic | Bearer token in `Authorization` header |
| `custom_header` | Flowhub, EZuite, Generic | API key in custom request header |
| `oauth2` | QuickBooks, Gmail, Google Drive, Upwork, Teams, Office 365 | OAuth2 Authorization Code Grant with refresh tokens |
| `bot_token` | Telegram, Slack, Discord | Platform-issued bot authentication token |
| `api_key_secret` | iSAMS, PayHere, EZuite | API key + secret pair |
| `page_access_token` | Facebook Messenger | Meta Graph API page-level access token |
| `service_account` | Google Chat | Google Service Account JSON key |
| `access_token` | Shopify (Admin) | Shopify Admin API access token header |
| `jwt_dynamic` | Shopify (Catalog) | JWT fetched dynamically from Shopify token endpoint |
| `msp_api_key` | Apple Messages | Message Service Provider API key |
| `mesh_api_key` | Mesh Peer | NV oOS federation inbound API key |
| `none` | WordPress (public), WebChat | No authentication (public endpoints or internal) |
