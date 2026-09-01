# Teams

## Purpose

Team management subsystem — the `mcp_ai_team` custom post type, team metadata (members, defaults, orchestration, workflow template), a repository with cache handling, JSON knowledge-base loading, and one-time default-team seeding.

## Tier

| | |
|---|---|
| **Distribution** | Platform addon (`nvoos-content-graph-ai-platform`) — proprietary |
| **PHP target** | 8.1+ |
| **Loaded by** | `NvoosContentGraphAiPlatform\Teams\TeamsService` (via `Plugin::register()`) |
| **Optional dependencies** | Base plugin `mcp-ai-wpoos` (monolith mode — its team copy owns runtime wiring) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraphAiPlatform\Teams\TeamsService` | `TeamsService.php` | `Plugin::register()` |
| `NvoosContentGraphAiPlatform\Teams\TeamCpt` | `TeamCpt.php` | `TeamsService` (standalone mode) |
| `NvoosContentGraphAiPlatform\Teams\TeamRepository` | `TeamRepository.php` | `TeamSeeder`, admin surfaces |
| `NvoosContentGraphAiPlatform\Teams\TeamKnowledgeBaseLoader` | `TeamKnowledgeBaseLoader.php` | `TeamSeeder` |
| `NvoosContentGraphAiPlatform\Teams\TeamSeeder` | `TeamSeeder.php` | `TeamsService` (standalone mode) |
| `NvoosContentGraphAiPlatform\Schema\Sanitize` | `../Schema/Sanitize.php` | `TeamCpt::save_post()` (workflow template) |

## Inputs / Outputs / Neighbors

- **Reads from:** `mcp_ai_team` posts, team meta (`_wp_mcp_ai_team_*`), `mcp_ai_profession` posts (member-slug resolution), the `wp_mcp_ai_teams_seeded` option, JSON knowledge base (`data/knowledge-base/teams/` in standalone, `includes/knowledge-base/teams/` in monolith)
- **Writes to:** `mcp_ai_team` posts + meta, `wp_mcp_ai_teams_seeded` option, `wp_mcp_ai_teams` cache group
- **Upstream callers:** `Plugin::register()`, team admin surfaces
- **Downstream collaborators:** `TeamCpt` ↔ `TeamRepository` ↔ `TeamKnowledgeBaseLoader` ↔ `TeamSeeder`
- **Events listened to:** `init` (post type + meta registration), `add_meta_boxes`, `save_post_mcp_ai_team`, `admin_init` (seeder), admin column filters
- **Base-plugin degradation:** provider/model metabox fields resolve via `class_exists()` probes and degrade to empty lists in standalone mode (no model service available)

## Conventions

- Ported 1:1 from `mcp-ai-wpoos/includes/teams/`, `includes/repositories/class-wp-mcp-ai-team-repository.php`, and `includes/services/class-wp-mcp-ai-team-knowledge-base-loader.php` (extraction Wave A). Post type slug, meta keys, option key (`wp_mcp_ai_teams_seeded`), cache group, and hook names are unchanged — data stability is sacred (extraction plan §3).
- Standalone mode: `TeamsService` wires the ported `TeamCpt` + `TeamSeeder` on `init` (priority 5, mirroring the base `teams-init.php`). Monolith mode: the base plugin's own copy owns runtime wiring — never register twice.
- The knowledge base is bundled at `data/knowledge-base/teams/` (26 JSON files); the loader prefers the base plugin's directory in monolith mode.
- `Schema\Sanitize::recursive()` mirrors `wp_mcp_ai_sanitize_recursive()` byte-for-byte and delegates to the base function when present.
- Text domain: `nvoos-content-graph-ai-platform`.

## Tests

```bash
# Monolith matrix (base plugin loaded)
vendor/bin/phpunit -c plugins/nvoos-content-graph-ai-platform/phpunit.xml.dist --filter Teams

# Standalone matrix (base plugin skipped)
WP_MCP_AI_PLATFORM_STANDALONE=1 vendor/bin/phpunit -c plugins/nvoos-content-graph-ai-platform/phpunit.xml.dist --filter Teams
```

## See Also

- Plan: `docs/project/plans/content-graph-platform-extraction-plan.md`
- Tracker: `MIGRATION-GAPS.md`
- Base sources: `includes/teams/`, `includes/knowledge-base/teams/`
