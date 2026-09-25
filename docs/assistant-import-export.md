# Assistant Import / Export

Portable export/import for NV oOS AI assistants, powered by the canonical
portability engine `WP_MCP_AI_Assistant_Portability`
(`includes/assistants/class-wp-mcp-ai-assistant-portability.php`).

One engine, every surface:

| Surface | Entry point |
|---|---|
| **WP-CLI** | `wp mcp-ai assistant export|import` |
| **REST API** | `POST /wp-json/mcp-ai/v1/assistants/export` · `POST /wp-json/mcp-ai/v1/assistants/import` |
| **Admin UI** | "Export" row + bulk actions on the assistant list; **Assistants → Import / Export** submenu page |
| **AI tools** | `export_assistant`, `import_assistant`, `duplicate_assistant` (base) · `export_assistant_blueprint` (Pro) |
| **Backup & Restore** | `WP_MCP_AI_Export_Provider_Assistants` (Backup screen) |

## The bundle format (`nvoos-assistant`, version 1)

```json
{
  "format": "nvoos-assistant",
  "format_version": 1,
  "plugin_version": "1.1.80",
  "exported_at": "2026-09-15T10:00:00+00:00",
  "exported_by": 1,
  "assistants": [
    {
      "title": "Support Bot",
      "slug": "support-bot",
      "status": "publish",
      "content": "<p>Long description</p>",
      "excerpt": "Short description",
      "meta": {
        "_wp_mcp_ai_provider": "openai",
        "_wp_mcp_ai_model": "gpt-4.1",
        "_wp_mcp_ai_temperature": 0.7,
        "_wp_mcp_ai_system_prompt": "You are a support bot.",
        "_wp_mcp_ai_tools": ["web_search", "get_post"],
        "_wp_mcp_ai_memory_files": [101, 202],
        "_wp_mcp_ai_skills": [],
        "_wp_mcp_ai_mcp_apps": {},
        "mcp_ai_required_capability": "edit_posts"
      },
      "a2a": { "... A2A agent card (optional) ..." }
    }
  ]
}
```

- Bundles may carry **1–500 assistants**. Export one, many, or all.
- Meta values keep their native types: arrays (tools, memory files, roles,
  skills) stay arrays; scalars stay scalars.
- An optional `a2a` block per assistant embeds an
  [A2A protocol](https://a2a-protocol.org/) agent card (`name`, `skills`,
  `capabilities`, `securitySchemes`) so a bundle can seed other agent
  platforms. Use `--format=a2a` (CLI) or `format=a2a` (REST/tool) to emit a
  standalone card for one assistant.

### Redaction policy (never exported)

Credential material and site-specific pointers are excluded from every export
and stripped from every import:

- `_wp_mcp_ai_credentials` — hashed assistant bearer tokens
- `_wp_mcp_ai_external_action_id` / `_wp_mcp_ai_external_action_type`
- `_edit_lock`, `_edit_last`, `_wp_old_slug` (core bookkeeping)
- The `token` and `oauth_data` fields inside `_wp_mcp_ai_mcp_apps` entries —
  MCP App credentials are redacted from exports (the remaining config fields
  survive for round-trip fidelity) and stripped from imports by default. On
  overwrite, stored credentials are preserved for matching apps (matched on
  `server_url` + `auth_type` + `header_name`, falling back to position) so a
  redacted bundle cannot blank live connections. Both directions are
  filterable for explicitly trusted migrations:
  `wp_mcp_ai_assistant_export_redact_mcp_app_tokens` and
  `wp_mcp_ai_assistant_import_redact_mcp_app_tokens` (default `true`).
  Imported app entries are also structurally sanitized (auth type whitelist,
  tag stripping, timeout clamp, bool coercion, unknown-key drop) mirroring
  the Pro registry's `sanitize_app_config()`.

Only plugin-owned meta keys are eligible for export: `_wp_mcp_ai_*`,
`mcp_ai_*`, and (when Pro is active) `_wp_mcp_ai_pro_*`. Extend the rules
with the filters below.

## Import compatibility

The importer auto-detects three payload shapes:

| Shape | Example | Notes |
|---|---|---|
| Canonical bundle v1 | `{"format":"nvoos-assistant","assistants":[...]}` | Full fidelity |
| Legacy CLI export | `{"version":"…","assistant":{"title":"…","meta":{"model":"…"}}}` | `mcp_ai_`-stripped keys get the prefix re-applied |
| Blueprint JSON | `{"post_title":"…","meta_input":{…}}` or `{"name":"…","meta":{"instructions":"…","available_tools":[…]}}` | Same mapping as the Pro Blueprint Installer, so blueprint files import in base too |

Import modes:

- **skip** (default) — leave existing assistants (matched by slug, then
  title) untouched.
- **overwrite** — update the existing post in place; only imported
  plugin-owned meta is replaced, existing credentials on the target survive.
- **duplicate** — always create a new post.

`dry_run` validates and reports what *would* happen without writing.
`status_override` forces `draft|publish|private` on imported posts.

## WP-CLI

```bash
# Export one assistant to stdout.
wp mcp-ai assistant export 42

# Export every assistant to a protected file in uploads/mcp-ai/exports/.
wp mcp-ai assistant export all --file=all-assistants.json

# Emit a pure A2A agent card.
wp mcp-ai assistant export 42 --format=a2a

# Preview an import without writing.
wp mcp-ai assistant import --file=bundle.json --dry-run

# Overwrite the assistant matching by slug/title.
wp mcp-ai assistant import --stdin < assistant.json --overwrite

# Import and print only the new IDs.
wp mcp-ai assistant import --file=bundle.json --porcelain
```

File writes are restricted to `wp-content/uploads/mcp-ai/exports/`
(protected by `.htaccess` deny rules); only a basename is accepted.

## REST API

Both routes are admin-only (`manage_options`), nonce or bearer authenticated.

```bash
# Export assistants 3 and 7 with A2A cards.
curl -X POST "https://example.com/wp-json/mcp-ai/v1/assistants/export" \
  -H "X-WP-Nonce: <nonce>" -H "Content-Type: application/json" \
  -d '{"ids":[3,7],"include_a2a":true}'

# Import a bundle in dry-run mode.
curl -X POST "https://example.com/wp-json/mcp-ai/v1/assistants/import" \
  -H "X-WP-Nonce: <nonce>" -H "Content-Type: application/json" \
  -d '{"json":"{...}","mode":"skip","dry_run":true}'
```

`import` accepts either `json` (string, ≤ 2 MB) or `attachment_id` (a JSON
file in the Media Library). Responses wrap the bundle/report in
`{ "success": true, "data": … }`; errors return a `WP_Error` status.

## AI tools

| Tool | Tier | Capability | Flags | Notes |
|---|---|---|---|---|
| `export_assistant` | Base | `edit_posts` | read-only | `assistant_ids` (omitted = all), `include_a2a`, `format` (json/a2a/blueprint), `save_to_media` |
| `import_assistant` | Base | `manage_options` | write, state-changing | Routed through the destructive-ops gate (`confirm_destructive`) when enabled |
| `duplicate_assistant` | Base | `edit_posts` | write, state-changing | Clones without credentials; defaults to draft |
| `export_assistant_blueprint` | Pro | `edit_posts` | read-only, pro | Emits the exact dialect `WP_MCP_AI_Blueprint_Installer` consumes |

## Hooks

| Hook | Type | Purpose |
|---|---|---|
| `wp_mcp_ai_assistant_export_data` | filter | Modify the bundle before serialisation |
| `wp_mcp_ai_assistant_import_data` | filter | Normalise/migrate the parsed bundle before applying |
| `wp_mcp_ai_assistant_export_meta_denylist` | filter | Extend the never-exported meta key list |
| `wp_mcp_ai_assistant_export_meta_prefixes` | filter | Extend the eligible meta key prefixes |
| `wp_mcp_ai_assistant_export_redact_mcp_app_tokens` | filter | Opt out of MCP App token/oauth_data redaction on export (default `true` = redact) |
| `wp_mcp_ai_assistant_import_redact_mcp_app_tokens` | filter | Opt out of MCP App token/oauth_data stripping on import (default `true` = strip) |
| `wp_mcp_ai_assistant_to_blueprint_json` | filter | Enrich blueprint exports (Pro integrations) |
| `wp_mcp_ai_assistant_imported` | action | Fires per imported assistant `( $post_id, $assistant, $updated )` |

## Security

- Credential hashes are redacted on export **and** stripped from import
  payloads (defence in depth).
- MCP App credentials (`token` / `oauth_data` inside `_wp_mcp_ai_mcp_apps`)
  are redacted from exports and stripped from imports by default; overwriting
  an assistant preserves the stored credentials of matching apps so a
  redacted bundle cannot blank live connections. Opt out only for trusted
  migrations (see the Redaction policy above).
- Imports are validated against a JSON-Schema-style definition using
  WordPress' `rest_validate_value_from_schema()` — no external dependency.
- Every admin handler verifies the `wp_mcp_ai_assistant_portability` nonce
  and the `manage_options` capability.
- Import payloads are capped at 2 MB (REST, admin upload, and tool alike).
- Backup exports already route through the engine denylist, so the Backup &
  Restore screen exports assistants without credentials too.

## See also

- Engine: `includes/assistants/class-wp-mcp-ai-assistant-portability.php`
- Backup provider: `includes/admin/export/class-wp-mcp-ai-export-provider-assistants.php`
- Admin UI: `includes/admin/class-wp-mcp-ai-admin-assistant-portability.php`
- REST controller: `includes/rest/class-wp-mcp-ai-rest-assistant-portability-controller.php`
- CLI: `includes/cli/class-wp-mcp-ai-cli-assistant-command.php`
- Pro blueprint exporter: `addons/pro/includes/tools/orchestration/class-wp-mcp-ai-tool-export-assistant-blueprint.php`
- Skill: `.agents/skills/mcp-ai-wpoos-assistant-portability/SKILL.md`
