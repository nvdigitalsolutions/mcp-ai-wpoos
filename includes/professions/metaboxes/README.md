# Profession Metaboxes

## Purpose

Houses 8 metabox classes that render and save configuration sections on the `mcp_profession` custom post type editor — covering agent orchestration, knowledge base, datasets, defaults, details, expertise, and playbook management.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 7.4+ |
| **Loaded by** | `includes/professions/class-wp-mcp-ai-profession-cpt.php` via `add_meta_box()` calls |
| **Optional dependencies** | `WP_MCP_AI_Profession_Playbook_Seeder` (for playbook deduplication) |

## Public Surface

| Symbol | File | Description |
|---|---|---|
| `WP_MCP_AI_Profession_Metabox_Base` | `class-wp-mcp-ai-profession-metabox-base.php` | Abstract base with common render/save/permission/nonce patterns |
| `WP_MCP_AI_Profession_Metabox_Agent_Orchestration` | `class-wp-mcp-ai-profession-metabox-agent-orchestration.php` | Agent orchestration rules |
| `WP_MCP_AI_Profession_Metabox_Base_Knowledge` | `class-wp-mcp-ai-profession-metabox-base-knowledge.php` | Base knowledge configuration |
| `WP_MCP_AI_Profession_Metabox_Datasets` | `class-wp-mcp-ai-profession-metabox-datasets.php` | Dataset assignment |
| `WP_MCP_AI_Profession_Metabox_Defaults` | `class-wp-mcp-ai-profession-metabox-defaults.php` | Default profession settings |
| `WP_MCP_AI_Profession_Metabox_Details` | `class-wp-mcp-ai-profession-metabox-details.php` | Profession detail fields |
| `WP_MCP_AI_Profession_Metabox_Expertise` | `class-wp-mcp-ai-profession-metabox-expertise.php` | Expertise and specialisation |
| `WP_MCP_AI_Profession_Metabox_Playbook` | `class-wp-mcp-ai-profession-metabox-playbook.php` | Playbook status, preview, and AJAX regeneration |

All concrete metaboxes extend `WP_MCP_AI_Profession_Metabox_Base`. Each declares `get_id()`, `get_title()`, and `render($post)`.

## Inputs / Outputs / Neighbors

- **Reads from:** post meta on `mcp_profession` CPT, playbook attachments, `WP_MCP_AI_Profession_Playbook_Seeder`
- **Writes to:** post meta, playbook regeneration via AJAX (`wp_ajax_wp_mcp_ai_regenerate_playbook`)
- **Upstream callers:** `includes/professions/class-wp-mcp-ai-profession-cpt.php`
- **Downstream collaborators:** `includes/knowledge-base/profession-playbooks/`, `includes/professions/`
- **Events fired:** AJAX handler for playbook regeneration
- **Events listened to:** WordPress `save_post` (via CPT registration)

## Conventions

- All metaboxes extend `WP_MCP_AI_Profession_Metabox_Base` which provides `can_view($post)`, `can_save($post_id)` with nonce verification, and `render_documentation_link()`.
- `can_save()` verifies a metabox-specific nonce (`{metabox_id}_nonce`), checks `edit_post` capability, and guards against autosaves.
- The base class defaults to `high` priority; individual metaboxes may override (e.g. `Playbook` uses `default`).
- Documentation URLs point to GitHub-hosted reference docs in `docs/guides/user/professionals/`.
- The `Playbook` metabox uses `WP_MCP_AI_Profession_Playbook_Seeder::remove_duplicate_playbooks()` for deduplication before display, and provides AJAX-based regeneration with spinner UI feedback.

## Tests

```bash
vendor/bin/phpunit tests/test-profession-metaboxes.php
```

Coverage targets: metabox registration, capability gating, nonce verification, playbook AJAX regeneration, and autosave guards.

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — security
- [`.context/testing.md`](../../.context/testing.md) — testing patterns
