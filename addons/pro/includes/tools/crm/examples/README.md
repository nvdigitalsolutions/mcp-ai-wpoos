# CRM Assistant Blueprints

> Curated mcp_ai_assistant preset files installable via the
> `import_crm_blueprint` tool and the shared `WP_MCP_AI_Blueprint_Installer`.

Each `.json` file is a self-contained Assistant blueprint — a preset configuration
of the mcp_ai_assistant CPT that wires together profession-specific instructions,
a qualification framework, an allowlisted subset of crm tools, and channel
preferences.

## Available blueprints

| Slug | Name | Profession | Framework |
|---|---|---|---|
| `b2b-saas-sdr` | B2B SaaS SDR | sdr | BANT |
| `agency-account-manager` | Agency Account Manager | account_executive | MEDDIC |
| `real-estate-buyer-agent` | Real Estate Buyer Agent | sales_manager | BANT |
| `wholesale-distributor` | Wholesale Distributor | business_development | BANT |
| `bespoke-concierge` | Bespoke Concierge | business_development | BANT |
| `luxeseek-sourcing-agent` | Luxeseek Sourcing Agent | account_executive | BANT |
| `business-advisory` | Aerlinn Business Advisory | business_development | MEDDIC |
| `career-coach` | Turbo Road Career Coach | sdr | BANT |

## Blueprint format

CRM blueprints use the abstract schema:

```json
{
  "name": "Assistant name (becomes post_title)",
  "description": "Short summary (becomes post_content)",
  "meta": {
    "profession": "sdr|account_executive|sales_manager|business_development|sales_ops|marketing_manager|marketing_ops",
    "framework": "bant|meddic|champ",
    "available_tools": ["tool_slug", "..."],
    "instructions": "Full system prompt / agent behaviour description",
    "channels": ["email", "sms", "phone_call", "whatsapp", "webchat", "linkedin_dm", "web_form"],
    "auto_reply_enabled": true|false,
    "deal_stages": ["stage1", "..."],      // optional: custom pipeline
    "tcpa_quiet_hours": true               // optional: enforce TCPA hours
  }
}
```

## Tool

- **`import_crm_blueprint`** — [`class-wp-mcp-ai-tool-import-crm-blueprint.php`](class-wp-mcp-ai-tool-import-crm-blueprint.php)
  - Delegates to `WP_MCP_AI_Blueprint_Installer` (shared installer).
  - Parameters: `blueprint` (enum of slugs above), `overwrite` (bool).
  - Creates or updates an `mcp_ai_assistant` CPT entry.

## Usage

Via the chat interface:

```
import_crm_blueprint: b2b-saas-sdr
```

Or via the REST `/tools` endpoint:

```json
{
  "tool": "import_crm_blueprint",
  "arguments": { "blueprint": "b2b-saas-sdr", "overwrite": true }
}
```

## Adding a new blueprint

1. Create a new `.json` file in this directory following the schema above.
2. Add the slug to `BLUEPRINT_SLUGS` in the import tool class.
3. Add a row to the table in this README.

The `enum` in the tool's parameter schema ensures the LLM only requests valid
blueprints.  No other registration is needed — the tool discovers files by
listing the directory.

## Related

- [CRM Toolkit README](../README.md)
- [`WP_MCP_AI_Blueprint_Installer`](../../class-wp-mcp-ai-blueprint-installer.php)
- [Healthcare examples](../../healthcare/examples/)
- [`addons/pro/docs/CRM_TOOLKIT_ENHANCEMENT_PLAN.md`](../../../../docs/CRM_TOOLKIT_ENHANCEMENT_PLAN.md)
