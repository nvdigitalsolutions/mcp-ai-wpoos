# Teams — Team CPT & Seeder

## Purpose

Registers the `mcp_ai_team` custom post type that groups assistants into orchestration-ready teams (single, sequential, parallel, swarm modes — with a driver assistant, workflow template, and result-aggregation strategy) and seeds default teams on first activation — and nothing else.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ |
| **Loaded by** | [`includes/teams/teams-init.php`](./teams-init.php) — pulled in from `includes/bootstrap/loader.php` (after `professions-init.php`) |
| **Optional dependencies** | none — orchestration runtime (BMAD, workflow runner) lives in adjacent folders and consumes this data, not the other way around |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Team_CPT` | `class-wp-mcp-ai-team-cpt.php` | [`includes/rest/`](../rest/) teams controller, [`includes/services/`](../services/) team orchestration, Pro multi-agent dashboard |
| `WP_MCP_AI_Team_CPT::POST_TYPE` + `META_*` constants | `class-wp-mcp-ai-team-cpt.php` | every caller that reads/writes team meta (use the constants, not bare strings) |
| `WP_MCP_AI_Team_Seeder` | `class-wp-mcp-ai-team-seeder.php` | `admin_init` (priority 20) one-shot seeder gated by the `wp_mcp_ai_teams_seeded` option |

## Inputs / Outputs / Neighbors

- **Reads from:** assistant CPT (members listed in `_wp_mcp_ai_team_members`), profession CPT (when a team is sourced from a profession definition), plugin option `wp_mcp_ai_teams_seeded`, `WP_MCP_AI_Team_Repository` (when present) during seeding.
- **Writes to:** team post meta (members, description, default provider/model/temperature, orchestration mode, workflow template, result-aggregation strategy, driver assistant), plugin option `wp_mcp_ai_teams_seeded` after first run.
- **Upstream callers:** [`includes/rest/`](../rest/) (`class-wp-mcp-ai-rest-teams-controller.php`), [`includes/admin/`](../admin/) list table actions, [`includes/services/`](../services/) team orchestration runtime, Pro multi-agent dashboard and scheduler.
- **Downstream collaborators:** [`includes/assistants/`](../assistants/) (members are assistant post IDs), [`includes/agents/`](../agents/) (the planner/executor/critic split underlies orchestration modes), [`includes/professions/`](../professions/) for default seeding, [`.bmad/`](../../.bmad/) workflow runtime when teams drive BMAD team plays.
- **Events fired:** standard `save_post_mcp_ai_team` (WordPress core), no custom actions registered from this folder.
- **Events listened to:** `init` (priority 5 — CPT registration and seeder init), `admin_init` (priority 20 — actual seeding pass).

## Conventions

- **Always reference team meta keys via the `WP_MCP_AI_Team_CPT::META_*` constants** — same rule as `assistants/`. The orchestration-mode and workflow-template keys are particularly fragile to typo drift.
- Orchestration modes are an enumerated set: `single`, `sequential`, `parallel`, `swarm`. Adding a mode requires updating both the CPT (validation + admin UI) and the consuming orchestration runtime — don't add one in isolation.
- `Team_Seeder` runs exactly once per site lifetime (gated by the `wp_mcp_ai_teams_seeded` option). Re-running it after edits would overwrite admin customizations — if you need to refresh defaults, version the seeder or expose an admin action; do not invert the gate.
- The `META_DRIVER_ASSISTANT` field is what makes a team chattable as a single unit. Validate that the driver is a member of the team before saving — otherwise downstream URL construction breaks (see `tests/test-cron-status-unified-team-id.php`).
- Workflow templates are stored as JSON in `META_WORKFLOW_TEMPLATE`. Sanitize on write, decode-on-read, and never trust the JSON for HTML output without escaping.

## Tests

```bash
vendor/bin/phpunit tests/test-team-tools.php
vendor/bin/phpunit tests/test-team-without-driver-assistant.php
vendor/bin/phpunit tests/test-create-team-modal.php
vendor/bin/phpunit tests/test-create-agent-team-delegation-guidance.php
vendor/bin/phpunit tests/test-create-agent-team-error-handling.php
vendor/bin/phpunit tests/test-enhanced-team-loading.php
vendor/bin/phpunit tests/test-profession-team-cpt-sanitization.php
vendor/bin/phpunit tests/test-cron-status-unified-team-id.php
vendor/bin/phpunit tests/test-unified-team-chat-fix.php
vendor/bin/phpunit tests/test-unified-team-transcript-recording.php
vendor/bin/phpunit tests/test-admin-test-team-url-construction.php
vendor/bin/phpunit tests/test-rest-teams-controller.php
```

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming, style (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — capability + nonce rules for team CRUD (always)
- [`.context/rest-api.md`](../../.context/rest-api.md) — REST teams controller
- [`AGENTS.md`](../../AGENTS.md) — BMAD methodology + how teams plug into team plays
- [`CLAUDE.md`](../../CLAUDE.md) — PHP-compat policy
- BMAD team plays: [`.bmad/teams/feature-development.yaml`](../../.bmad/teams/feature-development.yaml)

## See Also

- Sibling: [`assistants/`](../assistants/) — team members are assistant post IDs
- Sibling: [`agents/`](../agents/) — planner/executor/critic roles underlie orchestration modes
- Sibling: [`professions/`](../professions/) — seeds and team templates often originate from professions
- Sibling: [`rest/`](../rest/) — `class-wp-mcp-ai-rest-teams-controller.php`
