# Email Marketing Tools

> Pro integration tools for email marketing platforms — Brevo (Sendinblue), Mailjet, and Mailgun.

## Purpose

Sending, contact management, and analytics for email marketing services. Each tool self-reports `is_available()` based on configured API keys.

## Tools

| Tool class | Slug | Domain |
|---|---|---|
| `WP_MCP_AI_Pro_Tool_Send_Brevo_Email` | `send_brevo_email` | Brevo transactional email |
| `WP_MCP_AI_Pro_Tool_Manage_Brevo_Contacts` | `manage_brevo_contacts` | Brevo contact CRUD |
| `WP_MCP_AI_Pro_Tool_Get_Brevo_Statistics` | `get_brevo_statistics` | Brevo campaign analytics |
| `WP_MCP_AI_Pro_Tool_Send_Mailjet_Email` | `send_mailjet_email` | Mailjet transactional email |
| `WP_MCP_AI_Pro_Tool_Manage_Mailjet_Contacts` | `manage_mailjet_contacts` | Mailjet contact CRUD |
| `WP_MCP_AI_Pro_Tool_Get_Mailjet_Statistics` | `get_mailjet_statistics` | Mailjet campaign analytics |
| `WP_MCP_AI_Pro_Tool_List_Mailjet_Connections` | `list_mailjet_connections` | Mailjet account discovery |
| `WP_MCP_AI_Pro_Tool_Send_Mailgun_Email` | `send_mailgun_email` | Mailgun transactional email |

## Credential resolution (Mailjet)

`WP_MCP_AI_Pro_Mailjet_Credentials` is the single resolver the three Mailjet
tools share. It reads the same `_wp_mcp_ai_pro_remote_connections` allowlist as
Gmail, Drive, Upwork, Shopify and QuickBooks, and follows the same contract:

1. An explicit `connection_id` argument, validated (type, enabled, assigned) —
   never silently replaced.
2. The single enabled `mailjet_mcp` connection the assistant may access.
3. Site-wide `mailjet_api_*` settings, only when the assistant is unrestricted
   or has the synthetic `settings` grant.

Two candidates is an **error**, not a guess: each Mailjet API key is a separate
subaccount with its own validated senders and its own statistics, so choosing
wrong reports the wrong numbers or fails a send. The error names the candidates
and points at `list_mailjet_connections`.

A `mailjet_mcp` connection with no `mailjet_mcp_url` is credential-only; with a
bridge URL it also exposes the read-only MCP tool surface.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **Loaded by** | `wp_mcp_ai_pro_register_tools()` in `addons/pro/mcp-ai-wpoos-pro.php` |
| **Optional dependencies** | Brevo API key, Mailjet API key, Mailgun API key |

## Conventions

- Canonical return envelope enforced (`WP_Error` on failure, success array on success).
- Two-gate sanitisation rule applies.
- Every tool implements `WP_MCP_AI_Tool_Interface` and uses `is_available()` to gate on configured credentials.

## See Also

- Sibling: [`tools/social-media/`](../social-media/)
- Sibling: [`tools/google-workspace/`](../google-workspace/)
- [`.context/security-checklist.md`](../../../../../.context/security-checklist.md)
