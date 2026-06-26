# Proposal 005: WP-CLI Infrastructure Hardening

**Status:** Draft  
**Author:** AI Agent Review (2026-06-26)  
**Target:** mcp-ai-wpoos v1.2.x  
**Scope:** Base + Pro WP-CLI command surface

---

## 1. Summary

A systematic audit of all 33 `wp mcp-ai` subcommands across Base (20 files) and Pro (7 files) revealed structural inconsistencies, missing CRUD operations, inconsistent security posture, and UX friction. This proposal catalogues every gap and recommends a phased hardening plan.

---

## 2. Current State

### 2.1 Command Inventory

| # | Command | Tier | Files | Subcommands | Status |
|---|---------|------|-------|-------------|--------|
| 1 | `assistant` | Base | 1 | `list`, `update`, `import` | Missing `create`, `delete` |
| 2 | `approval` | Base | 1 | `list_`, `approve`, `reject` | list_ naming |
| 3 | `bulk` | Base | 1 | `audit`, `cleanup-artifacts`, `dispatch`, `retry-failed`, `status` | OK |
| 4 | `cache` | Base | * | `clear` (nested as `clear clear`) | Double-nesting UX |
| 5 | `chat` | Base | 1 | `__invoke` | Fixed: `route_to_provider` → `create_chat_completion` |
| 6 | `cleanup_cct` | Base | * | (single verb) | OK |
| 7 | `content` | Base | 1 | `auto-categorize` | OK |
| 8 | `credential` | Base | 1 | `list`, `issue`, `revoke` | Missing capability checks |
| 9 | `cron` | Base | 1 | `list_`, `run`, `delete`, `clear` | Fixed: mb_strimwidth; list_ naming |
| 10 | `dlq` | Base | 1 | `list_items`, `stats`, `retry`, `delete`, `dismiss`, `purge`, `clear` | OK |
| 11 | `health` | Base | * | (single verb) | Excellent |
| 12 | `log` | Base | 1 | `errors`, `activity`, `clear`, `prune` | OK |
| 13 | `measurement` | Base | 1 | `run`, `alert_check`, `list_runs` | CI-grade; OK |
| 14 | `memory` | Base | 1 | `recall`, `store`, `forget`, `stats`, `audit` | Fixed: agent_id + mb_strimwidth |
| 15 | `plugins` | Base | * | `list_` only | **Missing `activate`/`deactivate`** |
| 16 | `provider` | Base | 1 | `list_`, `test`, `models` | Fixed: method name + test_connection |
| 17 | `queue` | Base | * | `show`, `stats`, `process`, `retry`, `clear` | OK |
| 18 | `remote` | Base | * | (single verb) | Fixed: docblock flags; needs `--verify-ssl` |
| 19 | `settings` | Base | 1 | `get` only | **Missing `set`/`update`** |
| 20 | `sla` | Base | 1 | `status`, `tune`, `analyze`, `enable`, `disable` | Fixed: class loading |
| 21 | `slash` | Base | 1 | `execute`, `list` | list naming (correct) |
| 22 | `status` | Base | * | (single verb) | OK |
| 23 | `stdio` | Base | * | (single verb) | OK |
| 24 | `thread` | Base | 1 | `list_`, `get`, `delete`, `compact` | **Missing `create`/`export`** |
| 25 | `token` | Base | * | `migrate_providers` | OK |
| 26 | `tool` | Base | 1 | `list`, `enable`, `disable` | Missing `--yes` on disable |
| 27 | `transcript` | Base | 1 | `mine`, `status`, `cancel` | README says `list` but actual is `mine` |
| 28 | `version` | Base | * | (single verb) | OK |
| 29 | `pro status` | Pro | 1 | `__invoke` | OK |
| 30 | `connection` | Pro | 1 | `list`, `create`, `get`, `delete`, `test` | Fixed: `--url` → `--remote-url` |
| 31 | `mcp-server` | Pro | 1 | `list`, `get`, `enable`, `disable`, `tools`, `token-generate`, `token-list`, `token-revoke` | Excellent |
| 32 | `project` | Pro | 1 | `create`, `get`, `update`, `delete`, `list` | Full CRUD |
| 33 | `task` | Pro | 1 | `create`, `get`, `update`, `delete`, `list`, `complete`, `dependencies` | Full CRUD |
| 34 | `toolkit` | Pro | 1 | `list`, `enable`, `disable` | disable has `--yes` (working) |

* = registered in `includes/class-wp-mcp-ai-cli-command.php` (legacy dispatcher)

### 2.2 Inheritance Tree

```
WP_CLI_Command                              ← WordPress core
└── WP_MCP_AI_CLI_Base_Command (abstract)   ← Base: progress bars, batch, format_output, confirm
    ├── WP_MCP_AI_CLI_Assistant_Command
    ├── WP_MCP_AI_CLI_Approval_Command
    ├── WP_MCP_AI_CLI_Bulk_Command
    ├── WP_MCP_AI_CLI_Chat_Command
    ├── WP_MCP_AI_CLI_Content_Command
    ├── WP_MCP_AI_CLI_Credential_Command
    ├── WP_MCP_AI_CLI_Cron_Command
    ├── WP_MCP_AI_CLI_DLQ
    ├── WP_MCP_AI_CLI_Log_Command
    ├── WP_MCP_AI_CLI_Measurement_Command
    ├── WP_MCP_AI_CLI_Memory_Command
    ├── WP_MCP_AI_CLI_Provider_Command
    ├── WP_MCP_AI_CLI_Settings_Command
    ├── WP_MCP_AI_CLI_SLA
    ├── WP_MCP_AI_CLI_Slash_Command
    ├── WP_MCP_AI_CLI_Thread_Command
    ├── WP_MCP_AI_CLI_Tool_Command
    ├── WP_MCP_AI_CLI_Transcript_Command
    └── WP_MCP_AI_Pro_CLI_Base_Command (abstract)  ← Pro: assert_pro_loaded, assert_toolkit_enabled
        ├── WP_MCP_AI_Pro_CLI_Connection_Command
        ├── WP_MCP_AI_Pro_CLI_Mcp_Server_Command
        ├── WP_MCP_AI_Pro_CLI_Project_Command
        ├── WP_MCP_AI_Pro_CLI_Status_Command
        ├── WP_MCP_AI_Pro_CLI_Task_Command
        └── WP_MCP_AI_Pro_CLI_Toolkit_Command
```

---

## 3. Gap Catalogue

### 3.1 CRITICAL — Missing Commands (User-Facing)

#### GAP-001: `settings set` / `settings update`
**Severity:** High  
**Impact:** Operators cannot configure the plugin via CLI — must use admin UI for all writes. Blocks CI/CD automation and headless deployments.  
**Current:** `wp mcp-ai settings get [<key>]` (read-only).  
**Recommended:** Add `wp mcp-ai settings set <key> <value>` with type-aware validation (bool, int, string, array). Redact API keys on success.  
**Dependencies:** `WP_MCP_AI_Settings_Validator` already exists.

#### GAP-002: `plugins activate <slug>` / `plugins deactivate <slug>`
**Severity:** High  
**Impact:** Documented in README but not implemented. Operators must use `wp plugin activate` separately for supported integrations (WooCommerce, JetEngine, etc.).  
**Recommended:** Implement with `--yes` flag and pre-activation dependency checks.

#### GAP-003: `assistant create` / `assistant delete`
**Severity:** Medium  
**Impact:** Operators cannot provision or tear down assistants via CLI. Full lifecycle management requires admin UI.  
**Current:** Only `list`, `update`, `import` exist.  
**Recommended:** Add `assistant create --title --model --provider --system-prompt` and `assistant delete <id> --yes`. Mirror the REST endpoint shape.

#### GAP-004: `thread create` / `thread export`
**Severity:** Low  
**Impact:** Documented in README but not implemented. `thread compact` was added instead.  
**Recommended:** Either implement the documented subcommands or update the README to reflect actual available commands (`compact`, `delete`, `get`, `list_`).

---

### 3.2 HIGH — Naming & Consistency

#### GAP-005: `list` vs `list_` method naming
**Severity:** Medium  
**Impact:** Code readability and contributor confusion. WP-CLI resolves both correctly via `@subcommand list` annotation, but the inconsistency is jarring during code review and maintenance.  
**Affected (list_):** `approval`, `cron`, `provider`, `plugins`, `thread`, `credential`, `tool` (7 files)  
**Affected (list):** `assistant`, `tool`, `connection`, `mcp-server`, `project`, `task`, `toolkit`, `slash` (8 files)  
**Recommended:** Migrate all `list_` methods to `list` and add `@subcommand list` annotation. PHP 7.4+ allows `list` as a method name (it's only reserved as a language construct in specific contexts, not as a class method).

#### GAP-006: Inconsistent `@when after_wp_load`
**Severity:** Low  
**Impact:** Some commands may execute before WordPress is fully loaded, leading to subtle bugs with `get_option`, `get_post`, etc.  
**Missing:** `approval list_`, `approval approve`, `approval reject`, `bulk audit` (has it), `cron` (all methods), `provider` (all methods), `chat`, `memory` (most methods), `thread` (all methods), `transcript` (all methods).  
**Recommended:** Add `@when after_wp_load` to every subcommand that touches the database, options, or post types. This is the WP-CLI recommended practice.

#### GAP-007: Inconsistent `--yes` / confirmation on destructive ops
**Severity:** Medium  
**Impact:** Operators may accidentally disable tools without confirmation. Some destructive commands require `--yes`, others don't.  
**Has `--yes`:** `cron delete`, `cron clear`, `dlq delete`, `dlq purge`, `dlq clear`, `mcp-server disable`, `mcp-server token-revoke`, `project delete`, `task delete`, `transcript cancel`, `toolkit disable`  
**Missing `--yes`:** `tool disable`, `tool enable` (non-destructive but irreversible with scripts), `credential revoke`, `assistant delete` (not yet implemented)  
**Recommended:** Add `--yes` and confirmation prompt to `tool disable` and `credential revoke`. The `tool enable` command is non-destructive so `--yes` is optional.

---

### 3.3 MEDIUM — Security Gaps

#### GAP-008: Missing capability checks on mutating commands
**Severity:** Medium  
**Impact:** CLI commands bypass the capability gates that their REST/tool counterparts enforce. An operator with shell access but limited WordPress capabilities could modify assistants, credentials, or tool state.  
**Affected:** `assistant update`, `assistant import`, `credential issue`, `credential revoke`, `tool enable`, `tool disable`, `cron run`, `cron delete`, `settings set` (not yet implemented)  
**Recommended:** Add `current_user_can( 'manage_options' )` or the specific tool capability (`edit_posts`, etc.) to every mutating subcommand. Use the base-class pattern: `$this->require_capability( 'manage_options' )`.

#### GAP-009: `credential issue` and `credential revoke` lack access control
**Severity:** Medium  
**Impact:** Any WP-CLI user can issue or revoke assistant credentials (API tokens). These tokens grant access to the assistant's full tool set.  
**Recommended:** Gate `credential issue` on `manage_options` and `credential revoke` on the same capability + verify the credential belongs to a valid assistant.

---

### 3.4 LOW — UX Friction

#### GAP-010: `cache clear clear` double nesting
**Severity:** Low  
**Impact:** Confusing CLI UX. `wp mcp-ai cache` has one subcommand `clear` which itself has one action `clear`.  
**Recommended:** Support `wp mcp-ai cache clear` as an alias (treat as the default action when no further subcommand is specified).

#### GAP-011: No unified `--format` parsing
**Severity:** Low  
**Impact:** Every command manually reads `$assoc_args['format'] ?? 'table'`. The `format_output()` method in `Base_Command` is underused.  
**Recommended:** Add a `protected function get_format( $assoc_args, $default = 'table' )` helper to the base class and use it consistently.

#### GAP-012: `transcript list` → `transcript mine` naming
**Severity:** Low  
**Impact:** README documents `wp mcp-ai transcript list` but the actual subcommand is `mine`.  
**Recommended:** Add `list` as an alias subcommand that delegates to `mine`, or update the README.

#### GAP-013: README documents `--assistant-id` but CLI uses `--assistant`
**Severity:** Low  
**Impact:** User confusion when the documented flag name doesn't work. Affects `chat`, `memory recall`, `transcript mine`.  
**Recommended:** Add `--assistant-id` as an accepted alias in each affected command, or update README.

---

## 4. Architecture Quality Assessment

### Strengths
- **Clean file-per-class discipline** — Each CLI command is in its own file, self-registers via `WP_CLI::add_command()`, and gates on `WP_CLI` being defined.
- **Well-designed Pro base class** — `WP_MCP_AI_Pro_CLI_Base_Command` adds `assert_pro_loaded()` and `assert_toolkit_enabled()` without duplicating base functionality.
- **Excellent MCP server CLI** — The Pro `mcp-server` command group (8 subcommands, full token lifecycle) is a model for what the rest of the CLI should look like.
- **CI-grade measurement commands** — `measurement run`, `alert_check`, `list_runs` with `--no-persist`, `--webhook`, regression detection, and non-zero exit codes.
- **DLQ and SLA commands** — Robust operational tooling with filtering, stats, and bulk operations.

### Weaknesses
- **Legacy dispatcher fragmentation** — 9 commands are registered in `includes/class-wp-mcp-ai-cli-command.php` (a 1,800-line monolith) while 20 live in `includes/cli/`. This split has no technical justification.
- **Inconsistent error patterns** — Some commands use `$this->error()` (base class wrapper), others call `WP_CLI::error()` directly.
- **No test coverage for most CLI commands** — Only `tool` and "new commands" have dedicated test files. `approval`, `assistant`, `bulk`, `chat`, `content`, `credential`, `cron`, `dlq`, `log`, `measurement`, `memory`, `provider`, `settings`, `sla`, `slash`, `thread`, `transcript` have **zero PHPUnit coverage**.

---

## 5. Recommended Phased Plan

### Phase 1: Critical Gaps (v1.2.0) — ~3 days
1. **GAP-001** — Implement `settings set <key> <value>` with type-aware validation
2. **GAP-002** — Implement `plugins activate` / `plugins deactivate`
3. **GAP-003** — Implement `assistant create` / `assistant delete`

### Phase 2: Security Hardening (v1.2.0) — ~2 days
4. **GAP-008** — Add capability checks to all mutating commands (add `require_capability()` to base class)
5. **GAP-009** — Gate `credential issue` / `credential revoke` on `manage_options`

### Phase 3: Consistency Cleanup (v1.2.1) — ~2 days
6. **GAP-005** — Migrate all `list_` methods to `list`
7. **GAP-006** — Add `@when after_wp_load` to every subcommand
8. **GAP-007** — Add `--yes` to `tool disable` and `credential revoke`
9. **GAP-011** — Add `get_format()` helper to base class

### Phase 4: UX Polish (v1.2.1) — ~1 day
10. **GAP-010** — Support `wp mcp-ai cache clear` as alias
11. **GAP-012** — Add `transcript list` alias
12. **GAP-013** — Add `--assistant-id` alias where `--assistant` is used

### Phase 5: Legacy Modernization (v1.3.0) — ~2 days
13. Extract all commands from `includes/class-wp-mcp-ai-cli-command.php` into `includes/cli/` following the file-per-class convention
14. Add PHPUnit test scaffolding for all uncovered CLI commands
15. Update all README documentation to reflect actual CLI surface

---

## 6. Migration Impact

- **Backward compatibility:** All changes are additive except `list_` → `list` renaming. The `list_` methods can remain as deprecated aliases for one release cycle.
- **No database changes:** All changes are in CLI command handlers only.
- **No REST API changes:** The REST surface is unaffected.
- **Pro impact:** Pro commands already follow better patterns; only `connection create --url` was fixed. The Pro `mcp-server` group serves as the reference implementation.

---

## 7. Acceptance Criteria

1. `wp mcp-ai settings set openai_model gpt-5.2` works and validates the value type
2. `wp mcp-ai plugins activate woocommerce` works and shows clear error if WooCommerce is missing
3. `wp mcp-ai assistant create --title="Test" --model="gpt-4o" --provider="openai"` returns an ID
4. All mutating commands fail with "Sorry, you are not allowed to..." when run without `manage_options`
5. `wp mcp-ai tool disable search_posts` prompts for confirmation unless `--yes` is passed
6. `wp mcp-ai cache clear` works (without double nesting)
7. Every subcommand has `@when after_wp_load` in its docblock
8. All `list_` commands also respond to `list`
9. PHPUnit coverage exists for at least 50% of CLI commands (up from current ~10%)

---

## 8. References

- Base CLI README: `includes/cli/README.md`
- Pro CLI README: `addons/pro/includes/cli/README.md`
- Main dispatcher: `includes/class-wp-mcp-ai-cli-command.php`
- WP-CLI command cookbook: https://make.wordpress.org/cli/handbook/guides/commands-cookbook/
- Related proposals: (none yet — this is the first CLI infrastructure proposal)
