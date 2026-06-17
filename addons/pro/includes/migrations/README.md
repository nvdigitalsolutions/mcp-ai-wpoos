# Pro Migrations

## Purpose

Holds versioned, idempotent one-shot migration scripts that move Pro CPT/CCT/custom-table data to a new schema — currently the medical-record and regulatory-requirement post-type renames forced by WordPress's 20-character post-type-name limit.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | Per-toolkit init files via `require_once` — currently [`../health-wellness-management-init.php`](../health-wellness-management-init.php) (medical-record migration) and [`../regulatory-registration-toolkit-init.php`](../regulatory-registration-toolkit-init.php) (requirement migration). Each init checks `::get_status()` on `admin_init` and runs the migration once |
| **Optional dependencies** | none — migrations talk to `$wpdb` directly so they work on any WordPress install |

## Public Surface

Every migration class exposes the same two-method contract: `get_status()` to probe whether work is needed, and `run()` to perform the (idempotent) move. State is recorded in `wp_options` so a second invocation is a no-op.

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Migrate_Medical_Record_Post_Type::run()` / `::get_status()` | `class-wp-mcp-ai-migrate-medical-record-post-type.php` | [`../health-wellness-management-init.php`](../health-wellness-management-init.php) (auto-runs once on `admin_init`) |
| `WP_MCP_AI_Migrate_Requirement_Post_Type::run()` / `::get_status()` | `class-wp-mcp-ai-migrate-requirement-post-type.php` | [`../regulatory-registration-toolkit-init.php`](../regulatory-registration-toolkit-init.php) (auto-runs once on `admin_init`) |

Class constants `OLD_POST_TYPE`, `NEW_POST_TYPE`, and `VERSION` are stable and may be referenced by tools that need to bridge legacy data; everything else is internal.

## Inputs / Outputs / Neighbors

- **Reads from:** the `$wpdb->posts` and `$wpdb->postmeta` tables (rows tagged with the legacy post-type slug), the per-migration status option (`wp_mcp_ai_migration_<name>`).
- **Writes to:** `$wpdb->posts.post_type` (renames the legacy slug to the compliant slug), the per-migration status option (marks the migration complete), the Pro activity log (via `wp_mcp_ai_log_activity()` when present in the caller).
- **Upstream callers:** the toolkit init files listed above, which gate execution behind `is_admin()` + status check.
- **Downstream collaborators:** the Pro CPTs the migration targets ([`../class-wp-mcp-ai-health-wellness-cpt.php`](../class-wp-mcp-ai-health-wellness-cpt.php), [`../class-wp-mcp-ai-regulatory-registration-cpt.php`](../class-wp-mcp-ai-regulatory-registration-cpt.php)). Post-migration, those CPT classes own the renamed post type.
- **Events fired:** none beyond optional `wp_mcp_ai_log_activity()` calls in the caller.
- **Events listened to:** none directly — the caller registers the `admin_init` hook.

## Conventions

Folder-specific deltas (canonical rules in [`../../../../.context/conventions.md`](../../../../.context/conventions.md) and [`../../../../.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md)):

- Migrations MUST be **idempotent**: `run()` checks the per-migration status option first and returns `array( 'status' => 'already_run', … )` if previously completed. Re-running must never re-process rows.
- Migrations MUST be **safe-by-default**: `get_status()` reports `needs_migration` only when legacy rows actually exist. Sites that never created the legacy data path skip work entirely.
- Each migration declares a `VERSION` constant and an option key of the form `wp_mcp_ai_migration_<slug>`. Bump the version (and pick a new option key) when introducing a breaking re-run; do not mutate the existing key.
- DB writes go through `$wpdb->update()` / `$wpdb->prepare()` with explicit placeholders. No raw string concatenation into SQL.
- Return shape is `array{ status: string, message: string, migrated?: int }` from `run()` and `array{ needs_migration: bool, migration_completed: bool, … }` from `get_status()`. Callers should not depend on additional keys.
- A migration covers one schema concern. If you need to move multiple post types, ship multiple files; do not batch unrelated changes into a single class.
- Capability gating belongs to the caller (the toolkit init or admin/REST handler); these classes assume an already-authorised context — see [`../../../../.context/security-checklist.md`](../../../../.context/security-checklist.md).

## Tests

No standalone `tests/test-migrate-*-post-type.php` files exist yet. The migrations are exercised indirectly by the toolkit activation paths:

```bash
vendor/bin/phpunit tests/test-jetengine-data-stores-activation.php
vendor/bin/phpunit addons/pro/tests/test-regulatory-toolkit-checkbox.php
vendor/bin/phpunit addons/pro/tests/test-regulatory-toolkit-research-flag.php
```

Add a direct unit test under `addons/pro/tests/test-migrate-<slug>-post-type.php` when introducing a new migration; assert (a) idempotency on second `run()`, (b) row-count parity before/after, and (c) `get_status()` reflects completion.

## Also Load

- [`../../../../.context/conventions.md`](../../../../.context/conventions.md) — naming + style (always)
- [`../../../../.context/security-checklist.md`](../../../../.context/security-checklist.md) — `$wpdb->prepare()`, capability checks (always)
- [`../../../../.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md) — Base/Pro placement rules
- [`../../../../CLAUDE.md`](../../../../CLAUDE.md) — PHP-compat
- [`../../../../.context/testing.md`](../../../../.context/testing.md) — test patterns

## See Also

- Upstream parent: [`../`](../) (Pro `includes/`)
- Callers (auto-run on `admin_init`): [`../health-wellness-management-init.php`](../health-wellness-management-init.php), [`../regulatory-registration-toolkit-init.php`](../regulatory-registration-toolkit-init.php)
- Target CPTs: [`../class-wp-mcp-ai-health-wellness-cpt.php`](../class-wp-mcp-ai-health-wellness-cpt.php), [`../class-wp-mcp-ai-regulatory-registration-cpt.php`](../class-wp-mcp-ai-regulatory-registration-cpt.php)
- Sibling folders: [`../data-stores/`](../data-stores/), [`../services/`](../services/), [`../interfaces/`](../interfaces/)
