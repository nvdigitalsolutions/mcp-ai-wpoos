# Assistant Metaboxes

## Purpose

Houses 10 metabox classes that render and save configuration sections on the `mcp_ai_assistant` custom post type editor — covering credentials, knowledge base, datasets, defaults, harness profiles, MCP apps, mesh routing, primary roles, and agent skills.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ |
| **Loaded by** | `includes/assistants/class-wp-mcp-ai-assistant-cpt.php` via `add_meta_box()` calls |
| **Optional dependencies** | none |

## Public Surface

| Symbol | File | Description |
|---|---|---|
| `WP_MCP_AI_Metabox_Base` | `class-wp-mcp-ai-metabox-base.php` | Abstract base with common render/save/permission patterns |
| `WP_MCP_AI_Metabox_Base_Knowledge` | `class-wp-mcp-ai-metabox-base-knowledge.php` | Base knowledge configuration |
| `WP_MCP_AI_Metabox_Credentials` | `class-wp-mcp-ai-metabox-credentials.php` | Credential issuance, revocation, deletion |
| `WP_MCP_AI_Metabox_Datasets` | `class-wp-mcp-ai-metabox-datasets.php` | Dataset assignment |
| `WP_MCP_AI_Metabox_Defaults` | `class-wp-mcp-ai-metabox-defaults.php` | Default assistant settings |
| `WP_MCP_AI_Metabox_Harness_Profile` | `class-wp-mcp-ai-metabox-harness-profile.php` | LLM harness profile tuning |
| `WP_MCP_AI_Metabox_Mcp_Apps` | `class-wp-mcp-ai-metabox-mcp-apps.php` | MCP application bindings |
| `WP_MCP_AI_Metabox_Mesh_Routing` | `class-wp-mcp-ai-metabox-mesh-routing.php` | Mesh routing configuration |
| `WP_MCP_AI_Metabox_Primary_Roles` | `class-wp-mcp-ai-metabox-primary-roles.php` | Primary role assignment |
| `WP_MCP_AI_Metabox_Skills` | `class-wp-mcp-ai-metabox-skills.php` | Agent Skills selection and progressive disclosure |

All concrete metaboxes extend `WP_MCP_AI_Metabox_Base`. Each declares `get_id()`, `get_title()`, and `render($post)`.

## Inputs / Outputs / Neighbors

- **Reads from:** post meta on `mcp_ai_assistant` CPT, `WP_MCP_AI_Credentials`, `WP_MCP_AI_Skill_Registry`, `WP_MCP_AI_Assistant_CPT` constants
- **Writes to:** post meta, credential issuance via `admin-post.php` actions
- **Upstream callers:** `includes/assistants/class-wp-mcp-ai-assistant-cpt.php`
- **Downstream collaborators:** `includes/credentials/`, `includes/skills/`, `includes/harness/`
- **Events fired:** inline JavaScript for credential actions, skill search filtering
- **Events listened to:** WordPress `save_post` (via CPT registration)

## Conventions

- All metaboxes extend `WP_MCP_AI_Metabox_Base` which provides `can_view()`, `render_permission_denied()`, and `render_documentation_link()`.
- Credential actions (issue, revoke, delete) use `admin-post.php` with nonce verification and confirmation prompts via inline JS.
- The `Skills` metabox supports progressive disclosure (`wp_mcp_ai_skills_progressive` meta) and client-side search filtering.
- PHP < 7.4 guard is present in `class-wp-mcp-ai-metabox-credentials.php` via `version_compare()` early return.
- Documentation URLs are provided via `get_documentation_url()` for GitHub-hosted reference docs.

## Tests

```bash
vendor/bin/phpunit tests/test-assistant-metaboxes.php
```

Coverage targets: metabox registration, capability gating, nonce verification, credential lifecycle, and skill selection persistence.

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — security
- [`.context/testing.md`](../../.context/testing.md) — testing patterns
