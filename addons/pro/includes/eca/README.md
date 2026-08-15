# eca/

## Purpose

Houses the two custom-table accessors for the ECA (Extracurricular Activities) management toolkit — enrollments and attendance — and nothing else.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ (Pro addon minimum) |
| **Loaded by** | `addons/pro/includes/tools/eca-management/init.php` → `includes/eca/init.php` (creates tables via `dbDelta()` and wires hooks) |
| **Optional dependencies** | none |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_ECA_Enrollments_DB` | `class-wp-mcp-ai-eca-enrollments-db.php` | ECA management tools (enroll, bulk enroll, iSAMS sync) |
| `WP_MCP_AI_ECA_Attendance_DB` | `class-wp-mcp-ai-eca-attendance-db.php` | ECA management tools (attendance report, mark attendance) |

Anything not listed here is internal and may change without notice.

## Inputs / Outputs / Neighbors

- **Reads from:** the ECA enrollments/attendance custom tables (via `$wpdb`).
- **Writes to:** the same custom tables (CRUD via `dbDelta()`-created schema).
- **Upstream callers:** `addons/pro/includes/tools/eca-management/` tools — `class-wp-mcp-ai-tool-get-eca-attendance-report.php`, `class-wp-mcp-ai-tool-mark-eca-attendance.php`, `class-wp-mcp-ai-tool-sync-eca-enrollments-from-isams.php`, and the toolkit `init.php`.
- **Downstream collaborators:** `$wpdb` only — no other plugin subsystem is called.
- **Events fired:** none public.
- **Events listened to:** none public.

## Conventions

- Table creation is idempotent (`dbDelta()`) and runs from `init.php` — never from individual tools.
- The accessors are thin data-access layers: no business rules, no capability checks, no output formatting here (those live in the tools).
- Both classes follow the same static `init()` + instance-method shape as the tenant database accessor.

## Tests

```bash
vendor/bin/phpunit tests/test-tool-pro-import-ecas-csv.php
vendor/bin/phpunit tests/test-regulatory-eca-cre-ajax.php
```

There is no dedicated unit suite for the DB accessors; they are exercised through the ECA toolkit tool tests. Add `tests/` coverage when the schema changes.

## Also Load

- [`.context/conventions.md`](../../../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../../../.context/security-checklist.md) — security (always)
- [`.context/settings-storage.md`](../../../../.context/settings-storage.md) — custom-table storage decisions
- [`.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md) — Pro-only subsystem boundaries

## See Also

- Upstream parent: [`addons/pro/includes/`](../)
- Siblings worth knowing about: [`../tools/eca-management/`](../tools/eca-management/) (consumer toolkit)
