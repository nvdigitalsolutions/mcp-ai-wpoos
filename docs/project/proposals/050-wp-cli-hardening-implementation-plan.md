# Proposal 050 — WP-CLI Parity & Hardening: Implementation Plan

**Status:** ✅ Phases A–D implemented (branch `feat/wp-cli-parity-hardening`, base `sync/alpha-working-2026-10-02` ← `origin/alpha-working` @ `ddf27edec`)
**Proposal:** [050-wp-cli-hardening-proposal.md](./050-wp-cli-hardening-proposal.md) · **Gap analysis:** [050-wp-cli-gap-analysis.md](./050-wp-cli-gap-analysis.md)

> **Remaining follow-ups:** full CRUD verbs for the new Pro entities (crm
> create/update/delete, schedule create/update/delete, etc.) are deliberately
> read-first in this pass; the docblock example sweep (C3 second examples) is
> partial. Both are tracked for the next release cycle.

---

## 0. Execution rules

- One commit per logical unit; imperative subjects ≤50 chars.
- Every PHP change passes `php -l`; new/changed CLI commands get PHPUnit coverage where the harness allows.
- All commands obey folder conventions: extend `WP_MCP_AI_CLI_Base_Command` (Pro: `WP_MCP_AI_Pro_CLI_Base_Command`), `WP_CLI` guard at top, `@when after_wp_load`, output via `WP_CLI::*` only, mutations call `require_capability()`.
- Two-gate sanitisation applies to any user input rendered back (`tool call` output).
- Base PHP target 7.4; Pro PHP target 8.1+.

## 1. Phase A — P0 safety & parity

| # | Task | Files | Notes |
|---|---|---|---|
| A1 | **Extract legacy dispatcher classes** into `includes/cli/` (extends `WP_MCP_AI_CLI_Base_Command`; add `@when after_wp_load`, capability gating, class-level docs). Add a thin shim in the dispatcher that `require`s the new files so direct loader references don't fatal. | NEW: `includes/cli/class-wp-mcp-ai-cli-root-command.php` (status/cleanup-cct/remote), `class-wp-mcp-ai-cli-plugins-command.php`, `class-wp-mcp-ai-cli-queue-command.php`, `class-wp-mcp-ai-cli-token-command.php`, `class-wp-mcp-ai-cli-rabbitmq-command.php`, `class-wp-mcp-ai-cli-stdio-command.php`, `class-wp-mcp-ai-cli-health-command.php`, `class-wp-mcp-ai-cli-cache-command.php`, `class-wp-mcp-ai-cli-version-command.php`. EDIT: `includes/class-wp-mcp-ai-cli-command.php` | G1, G7. `plugins` helper function `wp_mcp_ai_get_supported_plugins` stays in dispatcher or moves with the class — move with class, guarded by `function_exists`. |
| A2 | **Gate fleet-operator CLI** on `manage_options` | EDIT: `addons/fleet-operator/includes/class-wp-mcp-ai-operator-cli.php` | G1b |
| A3 | **`wp mcp-ai tool call <slug>`** — resolve via `WP_MCP_AI_Tool_Registry`, per-tool `required_capability`, `--args` JSON, canonical envelope output | EDIT: `includes/cli/class-wp-mcp-ai-cli-tool-command.php` | G2. Sanitize at entry (tool args validated by tool), escape output. |
| A4 | **Assistant meta-key fidelity** — `WP_MCP_AI_Assistant_Meta_Map` translating CLI keys to runtime `_wp_mcp_ai_*` keys; wire `create|update|get`; add `assistant tools list|add|remove` (array-serialized) | NEW: `includes/cli/class-wp-mcp-ai-assistant-meta-map.php` (helper, WP_CLI-independent). EDIT: `includes/cli/class-wp-mcp-ai-cli-assistant-command.php` | G3 |
| A5 | **Tests** — `tool call` (capability + envelope), assistant meta fidelity, dispatcher gating | NEW/EDIT: `tests/test-wp-cli-tool.php`, NEW `tests/test-wp-cli-assistant-meta.php`, NEW `tests/test-wp-cli-dispatcher-gating.php` | G9 partial |

**Definition of done:** `grep manage_options` hits every mutating command incl. extracted ones + `mcp-ai operator`; `wp mcp-ai tool call` works; `assistant create --model` → `assistant get` round-trips the runtime key; `assistant tools add` stores an array.

## 2. Phase B — P1 coverage & flag centralization

| # | Task | Files | Notes |
|---|---|---|---|
| B1 | **Flag centralization (D5)** — `get_fields()`, `--format=count` in allowlist, fix `confirm()` (prompt via `WP_CLI::confirm()` when no `--yes`), `emit_porcelain()`; plumb `--fields` into `format_output()` | EDIT: `includes/cli/class-wp-mcp-ai-cli-base-command.php` (+ consumers in Phase C sweep) | G6. Backward-compatible: existing callers keep working. |
| B2 | **`wp mcp-ai security`** (Base) — `posture` (score + 21 signals), `audit` (tail/clear), `gate` (status), `keys` (API-key store status) | NEW: `includes/cli/class-wp-mcp-ai-cli-security-command.php` | G4. Read-only except `audit clear` (gated). |
| B3 | **`wp mcp-ai model`** (Base) — `list`, `suggestions`, `discover` | NEW: `includes/cli/class-wp-mcp-ai-cli-model-command.php` | G4. `discover` gated + `--dry-run`. |
| B4 | **Pro subsystem CLIs** — one class per noun, `project`/`task` pattern (assert toolkit → verbs → `--porcelain` → gated mutations): `crm` (lead/deal/company/contact/customer/activity/ticket), `schedule`, `workflow`, `vault` (read + metadata only), `incident`, `maintenance`, `remote-site`, `communication`, `media-studio` | NEW: `addons/pro/includes/cli/class-wp-mcp-ai-pro-cli-crm-command.php` et al. EDIT: `addons/pro/mcp-ai-wpoos-pro.php` (loader), `addons/pro/includes/cli/README.md` | G4. Read-first: `list|get` + one operational verb per class in this phase; remaining CRUD follows in follow-up PRs. |
| B5 | **Pro tests** — per-class metadata + arg-parsing tests following `test-pro-cli-mcp-server-command.php` | NEW: `addons/pro/tests/test-pro-cli-new-commands.php` | G9 |

## 3. Phase C — P2 consistency

| # | Task | Files | Notes |
|---|---|---|---|
| C1 | **Namespace unification** — `mcp calendar` → `mcp-ai calendar`, `mcp place` → `mcp-ai place` (keep old names registered as aliases); dual-register `ezuite`/`flowhub`/`shopify-sync`/`profession` under `mcp-ai <noun>` | EDIT: `addons/pro/includes/cli/class-wp-mcp-ai-pro-cli-calendar-command.php`, `-place-command.php`, `addons/pro/includes/class-wp-mcp-ai-ezuite-cli.php`, `-flowhub-cli.php`, `-shopify-sync-cli.php`, dispatcher `profession` block | G5. Zero-risk dual registration (both names → same callable). |
| C2 | **Operator docs** — `docs/guides/operator/wp-cli.md` full tree; fix root `README.md` CLI table (`slash-command` → `slash`) | NEW: `docs/guides/operator/wp-cli.md`. EDIT: `README.md` | G8 |
| C3 | **Docblock sweep** — class-level `## EXAMPLES` + ≥2 examples, `@when after_wp_load` on all DB-touching commands | EDIT: extracted files (A1) + `includes/cli/*` | G7 |

## 4. Phase D — P3 hardening

| # | Task | Files | Notes |
|---|---|---|---|
| D1 | **Command-tree regression test** — boot WP-CLI headless, assert full `mcp-ai` tree (fails on silent unregistration) | NEW: `tests/test-wp-cli-command-tree.php` | G9 |
| D2 | **Gating regression tests** — each mutating command errors under subscriber user | NEW: `tests/test-wp-cli-capability-gating.php` | G9 |
| D3 | **Version/deprecation policy** — `WP-CLI >= 2.9` note in `composer.json` suggest + docs; CLI section in `CHANGELOG.md`; alias deprecation window note in `includes/cli/README.md` | EDIT: `composer.json`, `includes/cli/README.md` | G10 |

## 5. Rollback

Every unit is an independent commit; reverting any commit restores prior behavior. The dispatcher shim (A1) guarantees `includes/class-wp-mcp-ai-cli-command.php` remains loadable by anything that references it directly.

## 6. Validation gates per unit

1. `php -l` on every touched PHP file.
2. `vendor/bin/phpunit` for the targeted test file(s) when the WP test harness is available in this environment; otherwise report the command + reason.
3. `composer run lint` on changed files (PHPCS) where feasible.
4. Manual `wp help mcp-ai <noun>` spot-check where a WP-CLI runtime is available.
