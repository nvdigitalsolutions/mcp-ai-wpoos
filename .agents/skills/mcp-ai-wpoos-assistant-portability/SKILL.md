---
type: Skill
name: mcp-ai-wpoos-assistant-portability
description: Operational guide for the NV oOS assistant export/import track — the canonical nvoos-assistant bundle format (v1), the shared portability engine, its five surfaces (WP-CLI, REST, admin UI, AI tools, backup provider), the Pro blueprint exporter, credential-redaction rules, legacy/blueprint import compatibility, test conventions, and the Content Graph port checklist. Use when extending or debugging assistant export/import, adding a surface, changing the bundle schema, fixing round-trip fidelity, or continuing the portability track ("continue the portability plan", "add assistant export to X").
license: Proprietary. See LICENSE.txt
metadata:
  plugin: mcp-ai-wpoos
  plugin-version: "1.1.80"
  plugin-version-tested: "1.1.80"
  last-updated: "2026-09-15"
---
# NV oOS Assistant Portability — Export/Import Track

Operational playbook for the assistant export/import feature shipped in
v1.1.80. One engine (`WP_MCP_AI_Assistant_Portability`), five surfaces, one
Pro blueprint bridge. Read this before touching any export/import code.

## When to use this skill

- Extending or debugging assistant export/import (`export_assistant`,
  `import_assistant`, `duplicate_assistant`, CLI `wp mcp-ai assistant
  export|import`, the Import / Export admin page, REST
  `/mcp-ai/v1/assistants/export|import`).
- Changing the bundle schema (format_version 1) or the meta denylist.
- Fixing round-trip fidelity bugs (a meta key missing from exports).
- Adding a new export surface (must delegate to the engine).
- Porting this track into the Content Graph ecosystem (see the port
  checklist below).

## Architecture map

```
WP_MCP_AI_Assistant_Portability            includes/assistants/class-wp-mcp-ai-assistant-portability.php
  ├─ export_assistants( ids|all, opts )    → canonical bundle array
  ├─ parse_import( json )                  → normalised, schema-validated bundle
  ├─ import_bundle( bundle, opts )         → report {created,updated,skipped,errors,items}
  ├─ export_a2a_card( id )                 → A2A agent card
  ├─ to_blueprint_json( assistant )        → Pro blueprint dialect
  ├─ get_export_meta( id )                 → denylist-filtered meta (shared with backup provider)
  └─ get_bundle_schema()                   → JSON-Schema-style validation definition

Surfaces (all delegate to the engine — never re-implement):
  CLI     includes/cli/class-wp-mcp-ai-cli-assistant-command.php      (export, import, write_export_file)
  REST    includes/rest/class-wp-mcp-ai-rest-assistant-portability-controller.php
  Admin   includes/admin/class-wp-mcp-ai-admin-assistant-portability.php
  Tools   includes/tools/class-wp-mcp-ai-tool-{export,import,duplicate}-assistant.php
  Backup  includes/admin/export/class-wp-mcp-ai-export-provider-assistants.php (shares get_export_meta)

Pro:
  addons/pro/includes/tools/orchestration/class-wp-mcp-ai-tool-export-assistant-blueprint.php
```

## Non-negotiable rules

1. **Credentials never move.** `_wp_mcp_ai_credentials` is in the default
   denylist and every import path strips denylisted keys even from
   hand-edited files. Never weaken this — the tests assert it explicitly.
2. **Every surface delegates to the engine.** If a surface builds its own
   meta loop, it silently drifts from the denylist. The backup provider was
   refactored to `get_export_meta()` for exactly this reason.
3. **Array meta stays array.** `_wp_mcp_ai_tools`, `_wp_mcp_ai_memory_files`,
   `_wp_mcp_ai_primary_roles`, `_wp_mcp_ai_skills`, `_wp_mcp_ai_mcp_apps`,
   shortcuts and role rules are array/object typed. Never flatten them
   through `sanitize_text_field` on the way out (the pre-1.1.80 CLI did this
   — that was the fidelity bug). **MCP App credentials:** since 1.1.85 the
   engine redacts the `token` / `oauth_data` fields inside
   `_wp_mcp_ai_mcp_apps` on export and strips them from import payloads by
   default; overwriting an assistant preserves the stored credentials of
   matching apps (matched on `server_url` + `auth_type` + `header_name`,
   falling back to position). Both directions are filterable for trusted
   migrations: `wp_mcp_ai_assistant_export_redact_mcp_app_tokens` and
   `wp_mcp_ai_assistant_import_redact_mcp_app_tokens` (default `true`).
   Imported app entries are also structurally sanitized mirroring the Pro
   registry's `sanitize_app_config()` — keep all three layers in sync when
   the Pro sanitizer changes. **Reference entries:** since 1.1.85 entries may
   carry `connection_ref` (a Remote Sites `mcp_server` connection ID) instead
   of inline credentials; the engine keeps reference entries despite the empty
   `server_url` and exports them as non-secret pointers. The Pro layer
   validates imported references and disables unresolvable ones
   (`wp_mcp_ai_mcp_apps_validate_imported_refs`). See the
   `design-elementor-mcp-connection` skill for the MCP Apps subsystem.
4. **Import matching is slug-first, then exact title.** Matches the backup
   provider and the Pro Blueprint Installer. Changing matching order changes
   skip/overwrite semantics across surfaces.
5. **State-changing surfaces are gated.** `import_assistant` and
   `duplicate_assistant` carry `write`/`state-changing` capability flags, so
   the destructive-ops gate (`confirm_destructive`) applies when enabled.
   REST and admin handlers require `manage_options` + nonce.
6. **PHP 7.4 in base.** The engine and all base surfaces must not use enums,
   named args, readonly, or union types. Pro-only code (blueprint exporter)
   may use PHP 8.1+.

## Bundle schema change procedure (format_version bump)

1. Bump `FORMAT_VERSION` and document the delta in
   `docs/assistant-import-export.md`.
2. Update `get_bundle_schema()` so `parse_import()` rejects malformed
   bundles (tests: `test_import_empty_bundle_errors` etc.).
3. Keep `normalise_payload()` accepting every previous version (legacy CLI
   shape + v1 + vN) — imports are version-tolerant, exports are
   version-pinned.
4. Add round-trip tests for the new field and a migration test from the
   previous version.
5. Validate: `php -l` the engine; PHPCS on changed files; run
   `tests/test-assistant-portability.php` +
   `tests/test-rest-assistant-portability.php` in Docker (see the test-suite
   skill for the runner commands).

## Import compatibility matrix

| Payload | Detected by | Transformation |
|---|---|---|
| Canonical v1 | `assistants` key | none |
| Legacy CLI file | `assistant` key with `title` | `mcp_ai_` prefix re-applied to meta keys |
| Healthcare blueprint | `post_title` | `meta_input` → meta verbatim |
| CRM/flat blueprint | `name` (+ no `assistant`) | `instructions`→system prompt, `available_tools`/`tools`→tools, provider/model/temperature passthrough |

The CRM mapping mirrors `WP_MCP_AI_Blueprint_Installer::remap_crm_meta_to_canonical()`
minus the settings-derived defaults (keep both in sync when the installer
changes).

## Admin surface notes

- Row action + bulk action stream downloads via
  `admin-post.php?action=wp_mcp_ai_export_assistant` (GET, nonce in URL).
- The Import / Export page is registered under the CPT menu
  (`edit.php?post_type=mcp_ai_assistant`, slug
  `mcp-ai-assistant-portability`). Import reports are carried via a 5-minute
  per-user transient (`wp_mcp_ai_assistant_import_report_{user_id}`) and
  rendered as an admin notice + item table.
- Import uploads are capped at 2 MB; file upload takes precedence over the
  paste-JSON textarea.

## Test conventions

- Engine: `tests/test-assistant-portability.php` (round-trip fidelity,
  redaction, modes, dry-run, legacy + blueprint imports, blueprint mapping,
  backup-provider denylist share).
- REST: `tests/test-rest-assistant-portability.php` (route registration,
  403 boundary, export bundle, A2A single-only rule, import + dry-run,
  payload validation).
- Tool tests follow `tests/test-create-assistant-tool.php` (registry
  presence + metadata); add to the same suite family when writing them.
- Remember test-suite skill patterns: registered-meta sanitizers don't run
  unless `init` re-fires; assert against `absint()`-wrapped reads; nonces
  after `wp_set_current_user()`.

## Content Graph port checklist (Phase 7)

When porting this track into `plugins/nvoos-content-graph-ai-platform`,
follow the ecosystem-port skill loop with these additions:

- Port `includes/assistants/class-wp-mcp-ai-assistant-portability.php`
  byte-identical (text domain stays `mcp-ai-wpoos` for base-owned D8 copies;
  swap to the addon domain if the platform convention requires it — check
  the port plan doc first).
- The REST controller and admin class are base-owned; wire standalone-only
  registration (platform bootstrap + `is_admin()` guards) mirroring the
  `AssistantPostType` precedent in the AI addon.
- Characterization tests must derive `FORMAT`, `FORMAT_VERSION`, and the
  denylist from the ported source — never hardcode (test-suite pattern 41).
- Update `docs/project/ecosystem-port-tracker.md` with the landed cluster.

## References

- Format spec + surfaces: `docs/assistant-import-export.md`
- Engine: `includes/assistants/class-wp-mcp-ai-assistant-portability.php`
- Folder contract: `includes/assistants/README.md`
- MCP Apps subsystem + token caveat: `.agents/skills/design-elementor-mcp-connection/SKILL.md`
- MCP App redaction tests: `test_export_redacts_mcp_app_tokens`,
  `test_import_redacts_mcp_app_tokens_on_create`,
  `test_import_overwrite_preserves_existing_mcp_app_tokens`,
  `test_import_sanitizes_mcp_app_structure` in
  `tests/test-assistant-portability.php`
- Test environment: `.agents/skills/mcp-ai-wpoos-test-suite/SKILL.md`
- Port loop: `.agents/skills/mcp-ai-wpoos-ecosystem-port/SKILL.md`
- Tool authoring rules: `CLAUDE.md` → "Tool Return Format — Canonical
  Envelope" and "Tool Sanitisation — Two-Gate Rule"
