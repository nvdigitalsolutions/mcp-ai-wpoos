# Action-Split Decomposition Plan (Phase P5 — Part 2)

**Date:** May 14, 2026  
**Auditor:** GitHub Copilot Agent  
**Branch:** `copilot/start-next-steps-unix-theory-compliance`  
**Proposal:** [Unix Theory Compliance Enhancement Proposal §2.1 / Phase P5](../UNIX_THEORY_COMPLIANCE_ENHANCEMENT_PROPOSAL.md#21-one-tool-one-responsibility)  
**Predecessor:** [Phase P5 — Part 1 Audit](P5-action-split-audit-2026-05.md)  
**Status:** 🟢 **Infrastructure landed** (May 2026); **Decomposition #1 (`git_operations`) landed** (May 2026) — remaining: `web_browser` (Order 2) → `batch_manage_memory` (Order 3) → `manage_context_lifecycle` (Order 4) → `remote_wp_connection` (Order 5), plus three Tier-C-overflow trims.

---

## 1. Scope

Part 1 identified five **Tier-A** dispatch-style tools that must be decomposed into focused sub-tools, plus three **Tier-C-overflow** CRUD tools (enum > 5) that must be trimmed. Part 2 is the executable plan for that decomposition.

Each decomposition lands as its own PR using the shared back-compat alias infrastructure introduced in this PR ([§3](#3-back-compat-alias-infrastructure)). No tool is decomposed without an alias, and no alias outlives one minor release cycle.

---

## 2. Targets and execution order

The five Tier-A tools are sequenced from **lowest blast-radius** to **highest**, so each PR validates the alias infrastructure incrementally against simpler splits before tackling the 20-action `remote_wp_connection` dispatcher.

| Order | Tool | Current enum | Proposed sub-tools | Estimated PR size | Notes |
|------:|------|-------------:|--------------------|-------------------|-------|
| 1 | `git_operations` | 10 | `git_inspect` (status, diff, log, show, blame, branch — read-only) + `git_change` (commit, add, checkout, stash — write) | M | Cleanest split — read vs. write boundary already implicit in the existing tool's per-action capability gating. Tier-A line item #2 in Part 1. |
| 2 | `web_browser` | 7 | `web_browser_navigate` (navigate, extract) + `web_browser_capture` (screenshot, pdf) + `web_browser_interact` (click, type, submit) | M | Three sub-tools share the same headless-browser session helper; no logic duplication. Tier-A #3. |
| 3 | `batch_manage_memory` | 7 | `memory_bulk_modify` (bulk_update, bulk_delete) + `memory_io` (export, import) + `memory_bulk_tag` (tag_add, tag_remove, tag_replace — naturally CRUD-canonical at 3) | M | Each sub-tool is internally cohesive and matches a single LLM intent. Tier-A #4. |
| 4 | `manage_context_lifecycle` | 7 | `context_window_optimize` (refresh, compress, prune) + `context_inspect` (analyze) + `manage_context` (update, delete, merge) | M | The `analyze` action has a fundamentally different return shape from the rest; isolating it removes a long-standing schema-shape hint in the system prompt. Tier-A #5. |
| 5 | `remote_wp_connection` | 20 | `remote_wp_query_content` + `remote_wp_query_woocommerce` + `remote_wp_manage_posts` + `remote_wp_manage_products` + `remote_wp_manage_orders` + `list_remote_connections` + `test_remote_connection` | L | The hardest split. Save for last so the alias mechanism is battle-tested by the four preceding PRs. Tier-A #1. |

### Tier-C overflow (separate, smaller PRs)

| Tool | Current enum | Trim plan |
|------|-------------:|-----------|
| `manage_care_plan` | 8 | Pull the three non-CRUD verbs (`assign`, `transition`, `escalate`) into a sibling `care_plan_workflow` tool; keep CRUD at 5 in `manage_care_plan`. |
| `track_vaccinations` | 7 | Move `schedule_reminder` + `record_administered` into a `vaccination_workflow` tool; keep `manage_vaccinations` (CRUD) at 5. |
| `log_vital_signs` | 6 | Move `bulk_import` into a `vital_signs_import` tool; keep `manage_vital_signs` (CRUD) at 5. |

Tier-C-overflow PRs are quicker (single non-CRUD verb extraction) and can interleave with Tier-A work.

---

## 3. Back-compat alias infrastructure

Landed in this PR as the prerequisite for all Part 2 work.

### 3.1 Registry API

[`includes/class-wp-mcp-ai-tool-registry.php`](../../../includes/class-wp-mcp-ai-tool-registry.php) gains three public methods:

```php
$registry->register_deprecated_alias(
    'git_operations',  // old slug
    'git_inspect',     // most-likely replacement; commit/add/checkout/stash callers re-route via per-action shim inside git_inspect
    array(
        'since'   => '1.3.0',
        'remove'  => '1.4.0',
        'message' => __( 'git_operations has been split into git_inspect (read) and git_change (write). Read-only callers are auto-rerouted; write callers should migrate to git_change.', 'mcp-ai-wpoos' ),
    )
);
$registry->get_deprecated_aliases();
$registry->resolve_deprecated_alias( $slug );
```

### 3.2 Resolution semantics

- `get_tool( $slug )` and `is_tool_registered( $slug )` and `get_tool_definition( $slug )` all transparently resolve a deprecated slug to its replacement. Callers anywhere in the codebase keep working without change.
- The alias is **invisible** to the LLM payload assembler (`WP_MCP_AI_Tool_Service::build_tools_payload()`) because aliases are stored in a separate `$deprecated_aliases` map, not in `$tools`. The model sees only the new sub-tools, so re-trained assistants migrate naturally.
- An alias may not shadow a registered tool slug — `register_deprecated_alias()` refuses such calls.

### 3.3 Observability hook

```php
do_action( 'wp_mcp_ai_tool_deprecated_alias_invoked', $old_slug, $new_slug, $entry );
```

Fires **exactly once per request per old slug** (throttle held on a private property; cleared by `reset_deprecated_alias_invocations()` for tests). The OTel exporter will subscribe to this in a follow-up PR to surface a `nvoos.tool.alias_invoked` span attribute; the activity log can subscribe to count migrations and prompt the operator to update their assistant configs.

### 3.4 Per-tool decomposition PR template

Every Part 2 PR must:

1. Add the new sub-tool classes under the existing directory (`includes/tools/` or `addons/pro/includes/tools/`).
2. Each sub-tool extends [`WP_MCP_AI_Tool_Interface`](../../../includes/interfaces/interface-wp-mcp-ai-tool.php) and `use`s the `WP_MCP_AI_Tool_Envelope` trait for the canonical return shape (Phase P1).
3. Register all new sub-tools in the corresponding `tools-init.php` (or via the relevant `wp_mcp_ai_register_tools` hook).
4. Register the back-compat alias **in the same hook**, pointing the old slug at the most semantically-appropriate sub-tool. If the old tool dispatches across multiple new sub-tools, the alias target should be a thin shim sub-tool that re-dispatches based on the legacy action enum.
5. Add tests covering: (a) the new sub-tools work, (b) the old slug still works through the alias, (c) the deprecation hook fires.
6. Add an entry to `CHANGELOG.md` under the v1.3.0 section listing the old → new slug map.
7. Update [`docs/tool-reference.md`](../../tool-reference.md) (or the live registry-driven equivalent) to list the new sub-tools and mark the old slug deprecated.
8. After v1.4.0 ships, a single follow-up PR removes all aliases registered with `'remove' => '1.4.0'`.

---

## 4. Sequencing constraint

Part 2 PRs land **into the v1.3.0 release branch** in the order above. They do **not** block each other (each alias is independent), but landing them in this order means the alias infrastructure validates against progressively more complex splits before the final 20-action `remote_wp_connection` decomposition.

---

## 5. Cross-references

- Phase P5 audit (Part 1): [`P5-action-split-audit-2026-05.md`](P5-action-split-audit-2026-05.md)
- Phase P1 (envelope discipline that every new sub-tool must follow): proposal §3 row P1
- Phase P4 (lifecycle descriptor — already correctly captures `tool_slug` so OTel spans automatically pick up the new sub-tool names): proposal §3 row P4
- Implementation surface: [`includes/class-wp-mcp-ai-tool-registry.php`](../../../includes/class-wp-mcp-ai-tool-registry.php) (`register_deprecated_alias` / `get_deprecated_aliases` / `resolve_deprecated_alias`)
- Tests: [`tests/test-tool-deprecated-alias.php`](../../../tests/test-tool-deprecated-alias.php)
