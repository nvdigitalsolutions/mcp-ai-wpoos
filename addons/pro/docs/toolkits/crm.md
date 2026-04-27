# CRM & Email Marketing Toolkit

> Lightweight CRM for contacts, companies, leads, and pipelines, with built-in email
> search and Upwork lead-generation tools.

| | |
|---|---|
| **Activation setting** | `enable_crm_toolkit` |
| **Admin location** | NV oOS → Settings → Pro Features → CRM |
| **Tools** | 10 |
| **Custom Post Types** | 1 (Companies) |
| **Available since** | Pro v1.1.0 |

---

## What it provides

The CRM toolkit registers a **Company** custom post type, a Research & Add admin page
(`WP_MCP_AI_Company_Research_Page`), and a Settings page
(`WP_MCP_AI_CRM_Settings_Page`). It ships ten tools that cover four workflows:

### Contact & company management

- `manage_crm_contact` — create / update / delete contacts (people)
- `create_company` — create a Company CPT entry
- `get_companies` — query the Company CPT
- `research_company` — AI-augmented enrichment of a company record

### Email correspondence search

Searches the configured mailbox(es) using IMAP/Gmail/Outlook integrations:

- `crm_email_search_leads` — search for prospect / lead conversations
- `crm_email_search_correspondence` — search general client correspondence
- `crm_email_search_accounting` — search invoice / billing email threads

### Upwork lead generation

- `search_upwork_jobs` — search the Upwork job feed
- `score_upwork_job` — rank a job against your firm's ideal-client profile
- `draft_upwork_proposal` — generate a tailored proposal

The Upwork tools require a configured Upwork API client
(`WP_MCP_AI_Upwork_Client`); see the per-toolkit settings.

---

## Custom post type

| CPT slug | Purpose |
|---|---|
| `mcp_ai_company` | Company records (independent of a contact list) |

CPT class: `addons/pro/includes/class-wp-mcp-ai-company-cpt.php`.

---

## Activation

1. Activate the Pro add-on.
2. Toggle **CRM Toolkit** under **NV oOS → Settings → Pro Features**.
3. Configure mailbox credentials and Upwork API tokens under **NV oOS → Settings → CRM**.
   Store secrets in the [Password Vault](password-vault.md) where possible.

---

## Related docs

- [Pro Toolkits index](README.md)
- [`addons/pro/docs/CRM_EMAIL_MARKETING_GUIDE.md`](../CRM_EMAIL_MARKETING_GUIDE.md) — deeper email-marketing usage
