# Repositories

## Purpose

Provides the plugin's data-access layer — one repository per persisted entity (assistant, credential, profession, settings, team, transcript) — encapsulating CRUD over WordPress posts, post meta, options, and the custom transcripts table.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ |
| **Loaded by** | `includes/bootstrap/loader.php`; instantiated by `includes/class-wp-mcp-ai-container.php` and by seeder callbacks in `professions/` / `teams/` |
| **Optional dependencies** | JetEngine (when present, transcript repository can also mirror to a CCT — gracefully degrades otherwise) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Assistant_Repository` | `class-wp-mcp-ai-assistant-repository.php` | `services/class-wp-mcp-ai-assistant-service.php`, admin AJAX handlers, REST |
| `WP_MCP_AI_Credential_Repository` | `class-wp-mcp-ai-credential-repository.php` | REST auth, admin credential UI, language-model router |
| `WP_MCP_AI_Profession_Repository` | `class-wp-mcp-ai-profession-repository.php` | `services/class-wp-mcp-ai-profession-service.php`, profession seeders, slash commands |
| `WP_MCP_AI_Settings_Repository` | `class-wp-mcp-ai-settings-repository.php` | `services/class-wp-mcp-ai-assistant-service.php`, admin settings page, REST |
| `WP_MCP_AI_Team_Repository` | `class-wp-mcp-ai-team-repository.php` | team seeder, agent team orchestrator, admin UI |
| `WP_MCP_AI_Transcript_Repository` | `class-wp-mcp-ai-transcript-repository.php` | `services/class-wp-mcp-ai-chat-service.php`, analytics engine, mine-memory tool, Pro Dashboard |

## Inputs / Outputs / Neighbors

- **Reads from:** custom post types (`mcp_ai_assistant`, profession/team CPTs), post meta, WordPress options, and the custom `wp_mcp_ai_transcripts` table.
- **Writes to:** the same surfaces — post inserts/updates, meta upserts, option updates, and direct `$wpdb` queries on the transcripts table.
- **Upstream callers:** `services/` (primary consumer), `admin/` AJAX handlers, `rest/` controllers, `professions/` and `teams/` seeders, the analytics engine, and selected `tools/` (e.g. mine-agent-memory).
- **Downstream collaborators:** `infrastructure/wp/` options-store adapter for cache invalidation; WordPress core directly for queries that have no interface yet. Some methods cache results in object cache / transients.
- **Events fired:** assistant / profession / team `save_post_*` hooks (delegated to WordPress core when the repo inserts/updates posts).
- **Events listened to:** repositories themselves are passive — cache invalidation is wired in `professions/professions-init.php` and similar bootstraps which call `$repository->clear_cache( $post_id )`.

## Conventions

- One file = one repository class = one entity. New entities get a new file; do not collapse multiple entities into one repository.
- Method shape is CRUD-centric: `find_by_id`, `find_all`, `save`, `update`, `delete`, `clear_cache`. Business logic lives in `services/`, not here.
- Direct `$wpdb` queries on the transcripts table are permitted (and necessary — `WP_Query` cannot target it). They must use `$wpdb->prepare()` for any user-supplied value and stay annotated with the `WordPress.DB.*` PHPCS justifications already present in the codebase.
- Repositories should accept WordPress / DB types and return plain PHP arrays or `WP_Error`. Don't leak `WP_Post` objects to callers when a normalized array works.
- Repositories are construction-free of optional plugins. When JetEngine is unavailable, the transcript repo falls back to its base table; never hard-require an optional dependency from this folder.

## Tests

```bash
vendor/bin/phpunit tests/test-transcript-repository.php
vendor/bin/phpunit tests/test-credentials.php
vendor/bin/phpunit tests/test-service-assistant.php
```

The assistant, profession, settings, and team repositories are exercised indirectly through their service-layer tests and the `professions/` / `teams/` seeder tests.

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming, style (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — sanitisation/escaping rules for DB I/O
- [`.context/testing.md`](../../.context/testing.md) — when adding repository tests
- [`docs/project/architecture-decisions/ADR_001_module_boundaries.md`](../../docs/project/architecture-decisions/ADR_001_module_boundaries.md) — where repositories sit between domain and infrastructure

## See Also

- Upstream parent: [`includes/`](../)
- Sibling folders: [`services/`](../services/) (primary caller), [`infrastructure/`](../infrastructure/) (adapters repositories consume), [`assistants/`](../assistants/), [`professions/`](../professions/), [`teams/`](../teams/) (entity-owning subsystems)
