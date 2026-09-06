# Healthcare Assistant Blueprints

> Curated mcp_ai_assistant preset files installable via the
> `import_healthcare_blueprint` tool and the shared `WP_MCP_AI_Blueprint_Installer`.

Each `.json` file is a self-contained Assistant blueprint — a preset configuration
of the mcp_ai_assistant CPT that wires together the appropriate healthcare tools,
PHI audit gating, and role/system prompts.

## Available blueprints

| Slug | Name | Sub-toolkits | PHI audit |
|---|---|---|---|
| `general-clinic` | General Clinic Front Desk | H&W, Imaging, Vitals | ✅ |
| `veterinary-practice` | Veterinary Practice | H&W, Vitals | ✅ |
| `personal-health-tracker` | Personal Health Tracker | H&W, Vitals | ✅ |
| `radiology-review` | Radiology Review | Imaging | ✅ |

## Blueprint format

Healthcare blueprints use the direct WordPress-style format (maps 1:1 to
`wp_insert_post` + `update_post_meta`):

```json
{
  "$schema": "https://schemas.nvdigitalsolutions.com/mcp-ai/assistant-blueprint.schema.json",
  "blueprint_id": "healthcare-{slug}",
  "name": "Display name",
  "description": "Short summary",
  "post_title": "Assistant title",
  "post_status": "publish",
  "post_content": "Post body / extended description",
  "meta_input": {
    "_wp_mcp_ai_model": "gpt-4o|gpt-4o-mini|claude-sonnet-4|...",
    "_wp_mcp_ai_temperature": 0.2,
    "_wp_mcp_ai_system_prompt": "Full system prompt with clinical guardrails.",
    "_wp_mcp_ai_tools": ["tool_slug", "..."],
    "mcp_ai_required_capability": "edit_others_posts",
    "_wp_mcp_ai_audit_phi": true
  }
}
```

**Key fields:**

| Field | Purpose |
|---|---|
| `blueprint_id` | Unique slug (must match `healthcare-{basename}` convention) |
| `post_title` | Becomes the master title for the `mcp_ai_assistant` CPT |
| `meta_input._wp_mcp_ai_audit_phi` | Boolean flag — when true, the import tool fires `wp_mcp_ai_healthcare_before_blueprint_install` which enforces BAA gating |
| `meta_input._wp_mcp_ai_system_prompt` | The LLM system prompt that defines behaviour and guardrails |
| `meta_input._wp_mcp_ai_tools` | Allowlisted tool slugs — the assistant can only call these |

## Tool

- **`import_healthcare_blueprint`** — [`class-wp-mcp-ai-tool-import-healthcare-blueprint.php`](class-wp-mcp-ai-tool-import-healthcare-blueprint.php)
  - Delegates to `WP_MCP_AI_Blueprint_Installer` (shared installer).
  - Parameters: `blueprint` (enum of slugs above), `overwrite` (bool).
  - Fires `wp_mcp_ai_healthcare_before_blueprint_install` when PHI audit is enabled.
  - Fires `wp_mcp_ai_healthcare_after_blueprint_install` on success.

## Usage

Via the chat interface:

```
import_healthcare_blueprint: general-clinic
```

Or via the REST `/tools` endpoint:

```json
{
  "tool": "import_healthcare_blueprint",
  "arguments": { "blueprint": "general-clinic", "overwrite": false }
}
```

## Compliance

- Blueprints with `_wp_mcp_ai_audit_phi: true` require a Business Associate Agreement
  (BAA) acknowledgement gate before installation.
- Every PHI-access tool in the allowlist must go through the shared
  `WP_MCP_AI_Healthcare_Audit` ledger.
- Deploy only on HIPAA-ready infrastructure (encrypted at rest, TLS in transit).

## Adding a new blueprint

1. Create a new `.json` file in this directory following the schema above.
   Use `blueprint_id = "healthcare-{basename}"`.
2. Add the slug to `BLUEPRINT_SLUGS` in the import tool class.
3. Add a row to the table in this README.

## Related

- [Healthcare Toolkit README](../README.md)
- [`WP_MCP_AI_Blueprint_Installer`](../../class-wp-mcp-ai-blueprint-installer.php)
- [CRM examples](../../crm/examples/)
