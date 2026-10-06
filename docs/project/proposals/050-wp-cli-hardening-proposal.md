# Proposal 050: WP-CLI Parity & Hardening — Gap Remediation

**Status:** ✅ Implemented (Phases A–D) — see [implementation plan](./050-wp-cli-hardening-implementation-plan.md)
**Author:** AI Agent Review (2026-10-02)
**Target:** mcp-ai-wpoos v1.2.x (P0/P2) → v1.3.x (P1/P3)
**Scope:** Base + Pro + addon WP-CLI command surface
**Input:** [050-wp-cli-gap-analysis.md](./050-wp-cli-gap-analysis.md) · Predecessor: [Proposal 005](./005-wp-cli-infrastructure-hardening.md)

---

## 1. Problem

Since Proposal 005 (2026-06-26) the plugin added entire subsystems — Media Studio (fashion stack, IPTC/C2PA, marketplace pipeline), assistant portability across five surfaces, 10-class security infrastructure, Pro toolkits (CRM, security ops, schedules, workflows, vault, communications), and four integration CLIs — while the WP-CLI surface grew to ~70 subcommands without a matching conventions pass. The gap analysis identifies 10 clusters:

- **P0 safety:** zero capability gating in the legacy dispatcher + fleet-operator credential CLI (G1); no one-shot tool execution (G2); `assistant create --model` / `assistant get` silently write/read the wrong meta keys (G3).
- **P1 coverage:** 11 subsystems with admin/REST/MCP parity but no CLI (G4); missing standard `--fields`/`--format=count`/`--porcelain` handling and a `confirm()` that never prompts (G6).
- **P2 consistency:** broken namespaces (`mcp calendar`, top-level `ezuite`/`flowhub`/`shopify-sync`) (G5); missing `@when`/class docs (G7); no operator CLI reference doc and a stale root README (G8).
- **P3 hardening:** no command-tree regression test (G9); no min-version/deprecation policy (G10).

This proposal defines the design decisions and phased plan to close them.

---

## 2. Research Synthesis

### 2.1 WP-CLI Handbook (primary standard)

The handbook's governing principle is parity with wp-admin: *"WP-CLI's goal is to offer a complete alternative to the WordPress admin; for any action you might want to perform in the WordPress admin, there should be an equivalent WP-CLI command."* The [Commands Cookbook](https://make.wordpress.org/cli/handbook/guides/commands-cookbook/) adds:

- Commands must have "a very specific scope, clearly reflected in the command's name"; **one class per command tree**; conditional load on `defined('WP_CLI') && WP_CLI`.
- PHPDoc contract: shortdesc <50 chars; `## OPTIONS` with `---` default/options YAML blocks; `## EXAMPLES` with **≥2 examples**; **class-level `## EXAMPLES`**; tags `@subcommand`, `@alias`, `@when after_wp_load`.
- Class registration (vs per-method callables) preserves class-level help, `@alias`, and default `__invoke` behavior.
- Tests should be Behat-style, exercising the same interface users use.

The [Documentation Standards](https://make.wordpress.org/cli/handbook/references/documentation-standards/) add output-message rules (capital start, trailing period, quoted slugs) and the `--format` option block format.

### 2.2 VIP & enterprise guidance

[WordPress VIP](https://docs.wpvip.com/vip-cli/wp-cli-with-vip-cli/write-custom-wp-cli-commands/) reinforces: register via `WP_CLI::add_command()` gated on the `WP_CLI` constant; parse args via `wp_parse_args()`; use `WP_CLI::success()/error()/log()/warning()`; **`WP_CLI::error()` exits non-zero** — the contract CI relies on. VIP also stresses that commands execute as a real WP user, so in-command `current_user_can()` checks are the correct auth boundary (the plugin's own `includes/cli/README.md` mandates `require_capability('manage_options')` on mutations).

### 2.3 Flagship plugin CLIs (industry baseline)

WooCommerce's WC-CLI (`wp wc <entity> list|get|create|update|delete`) is the reference shape for entity CRUD: `--field`/`--fields` for column control, `--format` (incl. `ids`, `count`), `--porcelain` for script-capturable IDs, `--user=<id>` for context. WP Rocket/Jetpack follow the same pattern. Our gaps G6 map directly onto this baseline.

### 2.4 What the research rejects

- **Per-method registration for groups** (`ezuite status` style): loses class help + aliases (S2). Migrate to class registration.
- **Silent `confirm()`:** WP-CLI's `WP_CLI::confirm()` prompt + `--yes` bypass is the standard (S6); our base-class override that returns `false` without prompting is an anti-pattern.
- **Blanket `manage_options` on tool execution:** tools already declare `required_capability`; `tool call` must honor per-tool capabilities (matches REST `POST /tools` behavior), not weaken or over-gate.

---

## 3. What Already Exists

- **Conventions in place:** `WP_MCP_AI_CLI_Base_Command` (progress bars, batch processing, `require_capability()`, `get_format()`, `format_output()`) and Pro's `WP_MCP_AI_Pro_CLI_Base_Command` (`assert_pro_loaded()`, `assert_toolkit_enabled()`). All `includes/cli/` commands already extend these and gate mutations correctly.
- **Reference implementations to copy:** Pro `mcp-server` (full token lifecycle), `WP_MCP_AI_CLI_OOS_Parity_Command` (class-level docs), `assistant export|import` on the portability engine (correct `_wp_mcp_ai_*` meta handling), `tool list --format=ids`, `connection create --porcelain`.
- **Proposal 005 status:** its Phase 1–4 items (plugins activate/deactivate, `get_format()`, `--yes` on tool disable, transcript alias, cache alias) are implemented; its Phase 5 (extract legacy dispatcher into `includes/cli/`, add test scaffolding, doc refresh) was **never done** — that is where G1/G7/G9 live. This proposal subsumes 005 Phase 5.

---

## 4. Design Decisions

### D1 — One canonical tree: `mcp-ai <noun> <verb>`; addons get `nvoos <addon>`; legacy names become `@alias`

Register every plugin command under `mcp-ai` (or `nvoos-*` for standalone addons). Rename `mcp calendar` → `mcp-ai calendar`, `mcp place` → `mcp-ai place`; move `ezuite`/`flowhub`/`shopify-sync`/`profession` under `mcp-ai <noun>` (e.g. `mcp-ai ezuite status`), keeping the old top-level names as `@alias` for one release. Rationale: S2 + `wp help mcp-ai` must discover the whole tree. Aliases preserve 005's backward-compat stance.

### D2 — Migrate the legacy dispatcher onto the base class

Move `status`, `cleanup-cct`, `remote`, `plugins`, `queue`, `token`, `rabbitmq`, `stdio`, `health`, `cache`, `version` out of `includes/class-wp-mcp-ai-cli-command.php` into `includes/cli/class-wp-mcp-ai-cli-<noun>-command.php`, each extending `WP_MCP_AI_CLI_Base_Command`. This is 005 Phase 5 and fixes G1 (capability gating), G7 (`@when`, class docs) mechanically in one pass. Keep a thin shim in the old file that `require`s the new files so any external code that loads the dispatcher directly doesn't fatal.

### D3 — New `tool call` verb (P0)

`wp mcp-ai tool call <slug> [--args='<json>'] [--assistant-id=<id>] [--user=<id>] [--format=json|table]`. Implementation: resolve the tool via `WP_MCP_AI_Tool_Registry`, check `current_user_can( $tool->get_definition()['required_capability'] ?? 'manage_options' )`, JSON-decode `--args` with a clear error on malformed JSON, then `execute()` and render the canonical envelope (success array or `WP_Error` — the two-gate sanitisation rules apply to any rendered values). Rationale: parity with REST `POST /mcp-ai/v1/tools` and MCP `tools/call` (G2).

### D4 — Assistant meta-key fidelity via the portability engine

Extend `WP_MCP_AI_Assistant_Portability` (or the key map it already uses) with a `WP_MCP_AI_Assistant_Meta_Map` helper that translates CLI-facing keys (`--model`, `--provider`, `--system-prompt`, `--tools`) to the runtime keys (`_wp_mcp_ai_model`, `_wp_mcp_ai_provider`, `_wp_mcp_ai_system_prompt`, `_wp_mcp_ai_tools`). `assistant create|update` write through it; `assistant get` renders both key families. Add `assistant tools list|add|remove` (JSON or CSV slugs, serialized as array — no string trap). Rationale: kills all three G3 defects with one shared mapping instead of three patches.

### D5 — Flag centralization in the base class

Add to `WP_MCP_AI_CLI_Base_Command`: `get_fields( $assoc_args, $defaults )` (parse `--fields`/`--field`, plumb into `format_output()`), a single `--format` allowlist `table|json|yaml|csv|ids|count` (update `get_format()`), `require_yes( $question, $assoc_args )` that calls `WP_CLI::confirm()` when `--yes` absent (fix the silent-`false` bug), and `emit_porcelain( $id )`. Roll out to list/create verbs across Base + Pro. Rationale: S4/S6 + WC-CLI parity (G6).

### D6 — New subsystem commands (P1), noun-by-noun, reusing existing services

Each new command class extends the Pro base and mirrors the `project`/`task` pattern (assert toolkit enabled → CRUD verbs → `--porcelain` on create → `require_capability` on mutations):

| Command | Backing layer | Verbs |
|---|---|---|
| `mcp-ai security` (Base) | `includes/security/` classes | `posture` (score + 21 signals), `audit` (audit logger tail/clear), `gate` (destructive-ops gate status/config), `keys` (API-key store status) |
| `mcp-ai crm` (Pro, CRM toolkit) | `*-cpt.php` repositories | `lead`, `deal`, `company`, `contact`, `customer`, `activity`, `ticket` × list/get/create/update/delete |
| `mcp-ai incident` / `mcp-ai maintenance` (Pro, security-ops) | CPT + existing REST controllers | list/get/create/update/resolve (+ cancel) |
| `mcp-ai schedule` (Pro) | `WP_MCP_AI_Pro_Schedule_Manager` | `list`, `get`, `create`, `update`, `delete`, `run` (dry-run), `history` |
| `mcp-ai workflow` (Pro) | Workflow Builder engine | `list`, `get`, `run`, `validate`, `presets` |
| `mcp-ai vault` (Pro) | vault data layer | `list`, `get`, `store`, `delete` (never render secrets; `--porcelain`/metadata only) |
| `mcp-ai remote-site` (Pro) | `WP_MCP_AI_Pro_Remote_Site_Manager` + mesh sync | `list`, `get`, `create`, `update`, `delete`, `test`, `peer-list`, `peer-sync` |
| `mcp-ai communication` (Pro) | channel-contacts/messages CPTs | `list`, `send`, `status` |
| `mcp-ai media-studio` (Pro) | Media Studio services | `list`, `get`, `batch-status`, `marketplace-status` (read-first; batch/export verbs deferred) |
| `mcp-ai model` (Base) | Model catalog + discovery service | `list`, `discover`, `suggestions`, `apply` |

Verbs stay **read-first**: every class lands with `list|get` + one operational verb in P1; full CRUD follows the same pattern already proven by `project`/`task`. Rationale: S1 (admin parity) without boiling the ocean.

### D7 — Operator docs generated, not hand-maintained

Create `docs/guides/operator/wp-cli.md` with the full tree, and add `wp mcp-ai commands` (or a `bin/gen-wp-cli-docs.sh`) that dumps the registered tree for doc refresh. Fix the root `README.md` CLI table (it currently documents `wp mcp-ai slash-command list`; the command is `wp mcp-ai slash list`). Rationale: G8 — the drift exists because docs are manual.

### D8 — Command-tree regression test + capability-gating tests (P3)

Add `tests/test-wp-cli-command-tree.php` that boots WP-CLI headless, calls `WP_CLI::get_root_command()`/runner introspection, and asserts the full expected `mcp-ai …` tree (fails on silent unregistration — the #6585 class of bug). Add gating tests asserting each mutating command exits with the "not allowed" error under a subscriber user. Rationale: S8 + G9.

### D9 — Version & deprecation policy

Declare `WP-CLI >= 2.9` in `composer.json` `suggest`/docs; add a "CLI" section to `CHANGELOG.md` entries; document the `@alias` deprecation window (legacy names emit `WP_CLI::warning` noting the rename, removed after one minor release). Rationale: G10.

---

## 5. Phased Implementation Plan

### Phase A — P0 safety & parity (v1.2.x, ~1.5 days)

1. **D2** — extract dispatcher classes into `includes/cli/`; add `require_capability('manage_options')` to every mutation (G1). Includes `cache clear`, `queue clear|retry`, `token migrate-providers`, `rabbitmq setup`, `plugins activate|deactivate`, `cleanup-cct`.
2. **G1b** — gate `mcp-ai operator` (fleet-operator addon) on `manage_options`.
3. **D3** — implement `tool call` (G2).
4. **D4** — meta-key map + `assistant tools list|add|remove` (G3).
5. Tests: extend `test-wp-cli-tool.php` for `tool call`; new `test-wp-cli-assistant-meta.php`.

### Phase B — P1 coverage (v1.3.x, ~4 days)

6. **D6** — `mcp-ai security` (Base) first (posture/audit/gate — pairs with Site Health and the 10 security classes).
7. **D6** — Pro: `crm` (highest-value subset: lead/deal/company), `schedule`, `workflow`, `vault` (read-first + one verb each), then `incident`/`maintenance`, `remote-site`, `communication`, `media-studio`, `model`.
8. **D5** — flag centralization rollout (`--fields` everywhere, `--format=count`, `confirm()` fix, `--porcelain` on creates) — do this **before** Phase B commands land so they inherit it.

### Phase C — P2 consistency (v1.3.x, ~1.5 days)

9. **D1** — namespace unification + `@alias` legacy shims (G5).
10. **D7** — `docs/guides/operator/wp-cli.md` + README fixes (G8).
11. **G7** — class-level `## EXAMPLES` + second example on every command; `@when after_wp_load` sweep.

### Phase D — P3 hardening (v1.3.x, ~1.5 days)

12. **D8** — command-tree + gating regression tests (G9).
13. **D9** — version/deprecation policy (G10).

---

## 6. Base vs Pro Placement

| Item | Base | Pro |
|---|---|---|
| Dispatcher extraction + gating (D2) | ✅ | — |
| `tool call` (D3) | ✅ (any registered tool, incl. Pro tools when Pro active) | — |
| Assistant meta fidelity (D4) | ✅ | — |
| `mcp-ai security` | ✅ | — |
| `mcp-ai model` (catalog/discovery) | ✅ | — |
| CRM / security-ops / schedule / workflow / vault / remote-site / communication / media-studio CLIs | — | ✅ (toolkit-gated via `assert_toolkit_enabled`) |
| Flag centralization (D5) | ✅ in `WP_MCP_AI_CLI_Base_Command` (inherited by Pro) | — |
| `operator` gating | — | fleet-operator addon |

Ecosystem port note: the Content Graph Pro port track copies Pro clusters byte-identical; Pro CLI classes land in `addons/pro/includes/cli/` and are picked up by the existing port loop per the [`mcp-ai-wpoos-ecosystem-port` skill](../../../.agents/skills/mcp-ai-wpoos-ecosystem-port/SKILL.md).

---

## 7. Security & wp.org Compliance

- **Capability gating everywhere** — G1 fixes align CLI with REST/tool gates (S5). No command mutates state without `current_user_can()`.
- **`tool call` uses per-tool `required_capability`** — never weaker than REST `POST /tools`; args flow through the existing two-gate sanitisation (sanitize at entry, escape at exit).
- **Vault CLI never prints secrets** — list/get return metadata (name, tags, timestamps) only; retrieval of a secret value stays in the encrypted data layer (MCP tool scope), avoiding a CLI log-leak vector.
- **No new auth bypasses** — all new commands inherit `require_capability()`; `--user` context respected.
- **Plugin Check gate** — CLI-only PHP files already pass the CI Plugin Check scan; new files follow the existing no-op-outside-WP-CLI guard convention.

---

## 8. Success Metrics / Acceptance Criteria

1. `grep` for `manage_options` returns hits in every mutating command class, including the extracted dispatcher commands and `mcp-ai operator`.
2. `wp mcp-ai tool call web_search --args='{"query":"wp-cli"}' --format=json` returns the canonical envelope; a subscriber user gets a capability error.
3. `wp mcp-ai assistant create --title=T --model=gpt-4o` then `wp mcp-ai assistant get <id>` shows the model under the runtime key; `assistant tools add` persists an array (not string).
4. `wp help mcp-ai` lists the full tree including `security`, `crm`, `schedule`, `workflow`, `vault`, `remote-site`, `model`, `calendar` (aliased from `mcp calendar`).
5. Every list verb supports `--fields` and `--format=csv|ids|count`.
6. A mutation without `--yes` **prompts** (base `confirm()` fixed); with `--yes` it proceeds.
7. `docs/guides/operator/wp-cli.md` exists and matches `wp help mcp-ai`.
8. `tests/test-wp-cli-command-tree.php` fails when any registered command is removed or renamed without an alias.

---

## 9. Open Questions

1. **Alias window length** — one minor release (D9) or two? (005 used one release for `list_` renames.)
2. **`mcp-ai calendar` vs `mcp-ai place`** — keep `mcp` as alias prefix, or accept a hard break for these two rarely-documented names?
3. **Vault write verbs** — defer `vault store` from CLI entirely (secrets-in-shell risk), or gate behind `--args='<json>'` with the same warnings as `credential issue`?
4. **Media Studio write verbs** — which batch/marketplace verbs deserve CLI parity vs staying admin-only?
5. Should `tool call` accept `--assistant-id` context resolution identical to REST (`/tools` uses assistant-scoped tool allowlists)?

---

## 10. References

- [050-wp-cli-gap-analysis.md](./050-wp-cli-gap-analysis.md) — evidence base for every gap
- [Proposal 005 — WP-CLI Infrastructure Hardening](./005-wp-cli-infrastructure-hardening.md) — predecessor (Phase 5 subsumed here)
- [WP-CLI Commands Cookbook](https://make.wordpress.org/cli/handbook/guides/commands-cookbook/)
- [WP-CLI Documentation Standards](https://make.wordpress.org/cli/handbook/references/documentation-standards/)
- [WordPress VIP — Write custom WP-CLI commands](https://docs.wpvip.com/vip-cli/wp-cli-with-vip-cli/write-custom-wp-cli-commands/)
- [WooCommerce WC-CLI Overview](https://developer.woocommerce.com/docs/wc-cli/cli-overview/)
- In-repo: [`includes/cli/README.md`](../../../includes/cli/README.md) · [`addons/pro/includes/cli/README.md`](../../../addons/pro/includes/cli/README.md) · [`CLAUDE.md`](../../../CLAUDE.md) (canonical envelope + two-gate sanitisation)
