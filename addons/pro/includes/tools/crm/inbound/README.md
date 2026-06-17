# Inbound — Multichannel Triage

## Purpose

Inbound message ingestion and triage for the CRM toolkit — IMAP email polling, SMS webhook listener (Twilio), WhatsApp webhook listener (Meta Cloud API), and Gmail-to-CRM import bridge, feeding the unified Workflow Command Center.

## Tier

| | |
|---|---|
| **Distribution** | Pro addon |
| **PHP target** | 8.1+ |
| **Loaded by** | `addons/pro/includes/tools/crm/init.php` |
| **Optional dependencies** | IMAP PHP extension (for email polling), Twilio SDK (for SMS), Meta WhatsApp Cloud API credentials (for WhatsApp), Gmail OAuth credentials (for Gmail bridge) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_CRM_IMAP_Client` | `class-wp-mcp-ai-crm-imap-client.php` | CRM engine, cron jobs, `import_gmail_to_crm` tool |
| `WP_MCP_AI_CRM_SMS_Webhook_Listener` | `class-wp-mcp-ai-crm-sms-webhook-listener.php` | REST API (`rest_api_init`), Twilio inbound webhook |
| `WP_MCP_AI_CRM_WhatsApp_Webhook_Listener` | `class-wp-mcp-ai-crm-whatsapp-webhook-listener.php` | REST API (`rest_api_init`), Meta WhatsApp inbound webhook |
| `WP_MCP_AI_Tool_Import_Gmail_To_CRM` | `class-wp-mcp-ai-tool-import-gmail-to-crm.php` | tool registry, assistant presets |
| `WP_MCP_AI_CRM_Gmail_Client` | `../../services/class-wp-mcp-ai-crm-gmail-client.php` | OAuth token exchange, Gmail API calls |

## Inputs / Outputs / Neighbors

- **Reads from:** IMAP mailboxes (via PHP IMAP extension), Twilio inbound SMS webhooks (POST to `/wp-json/mcp-ai-pro/v1/crm/sms-webhook`), Meta WhatsApp Cloud API webhooks (POST to `/wp-json/mcp-ai-pro/v1/crm/whatsapp-webhook`), Gmail API (via OAuth), `wp_mcp_ai_crm_toolkit_settings` option (carrier credentials, integration handles)
- **Writes to:** Lead/contact records, CRM audit ledger, Workflow Command Center inbox, consent ledger
- **Upstream callers:** Cron jobs (`wp_mcp_ai_crm_poll_imap`), Twilio/Meta webhook POST requests, tool registry
- **Downstream collaborators:** `WP_MCP_AI_CRM_Engine` (triage, scoring, routing), `WP_MCP_AI_CRM_Consent` (channel consent checks), `WP_MCP_AI_CRM_Audit` (PII logging), `WP_MCP_AI_CRM_Classifier` (intent/sentiment), outbound senders for auto-reply
- **Events fired:** `wp_mcp_ai_crm_inbound_received` (on every new inbound message), `wp_mcp_ai_crm_after_audit`
- **Events listened to:** `rest_api_init` (registers webhook routes), `wp_mcp_ai_crm_poll_imap` (cron action)

## Conventions

- Webhook listeners register REST routes on `rest_api_init` with no authentication — payload verification is done via provider-specific signature validation (Twilio X-Twilio-Signature, Meta X-Hub-Signature-256).
- IMAP polling runs via WP-Cron — `wp_mcp_ai_crm_poll_imap` action with a configurable interval (default: every 5 minutes).
- All inbound messages are triaged through `WP_MCP_AI_CRM_Classifier` for intent and sentiment before routing.
- Consent is checked per-channel before any outbound auto-reply — blocked sends return `WP_Error( 'crm_consent_required' )`.
- Gmail import requires OAuth 2.0 credentials stored as Password Vault handles (never in post meta or options as plaintext).

## Tests

```bash
vendor/bin/phpunit tests/pro/tools/crm/inbound/
```

## Also Load

- [`.context/conventions.md`](../../../../../../.context/conventions.md) — naming, style
- [`.context/security-checklist.md`](../../../../../../.context/security-checklist.md) — security
- [`.context/tool-registry.md`](../../../../../../.context/tool-registry.md) — tool registration
- [`.context/pro-vs-base.md`](../../../../../../.context/pro-vs-base.md) — Pro vs Base distribution
- [`../README.md`](../README.md) — parent CRM toolkit index
- [`../../../../docs/CRM_TOOLKIT_ENHANCEMENT_PLAN.md`](../../../../docs/CRM_TOOLKIT_ENHANCEMENT_PLAN.md) — Phase C details

## See Also

- Parent: [`../`](../) — CRM toolkit root
- Siblings: [`../outbound/`](../outbound/) — multichannel sends (Twilio/notify.lk/WhatsApp/email)
- Related service: [`../../services/class-wp-mcp-ai-crm-gmail-client.php`](../../services/class-wp-mcp-ai-crm-gmail-client.php)
