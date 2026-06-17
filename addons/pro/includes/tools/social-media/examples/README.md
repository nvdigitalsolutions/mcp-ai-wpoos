# Social Media Blueprint Examples

Curated assistant blueprints for the Social Media Toolkit. Each JSON file defines a
ready-to-install AI assistant with a tailored system prompt, model configuration, and
tool assignments.

## Blueprints

| File | Blueprint | Focus | Temperature |
|---|---|---|---|
| `content-strategist.json` | Social Media Content Strategist | Content calendars, post creation, scheduling, video scripts, cross-platform analytics | 0.7 |
| `community-manager.json` | Community Manager | Comment moderation, DM auto-response, influencer identification, brand mentions, crisis escalation | 0.5 |
| `analytics-reporter.json` | Social Media Analytics Reporter | Cross-platform reports, competitor tracking, trend analysis, dashboards, ROI calculations | 0.2 |

## Import Tool

**Slug:** `import_social_media_blueprint`

Use the `import_social_media_blueprint` tool to install any blueprint as a WordPress
assistant CPT post. Provide a `blueprint` parameter matching one of the slugs above
(`content-strategist`, `community-manager`, `analytics-reporter`). Set `overwrite`
to `true` to replace an existing assistant with the same name.

The tool delegates to the shared `WP_MCP_AI_Blueprint_Installer` for file loading,
JSON parsing, duplicate detection, post insertion, and meta population.

### Availability

The import tool is only available when the Social Media Toolkit is enabled in plugin
settings (`enable_social_media_toolkit`).

### Requirements

- **Pro addon** must be active (`requires_base_pro`)
- User must hold the `edit_posts` capability
- The `WP_MCP_AI_Blueprint_Installer` class must be loadable from
  `includes/tools/orchestration/class-wp-mcp-ai-blueprint-installer.php`

## Schema

All blueprints conform to the schema at
`https://schemas.nvdigitalsolutions.com/mcp-ai/assistant-blueprint.schema.json`.
