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
| `WP_MCP_AI_Pro_Tool_Send_Mailgun_Email` | `send_mailgun_email` | Mailgun transactional email |

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
