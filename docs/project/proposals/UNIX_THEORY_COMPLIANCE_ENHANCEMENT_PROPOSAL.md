# NV oOS — Unix Theory Compliance Enhancement Proposal

**Date:** May 2026 (originally drafted) · **Last reviewed:** May 2026  
> **Status:** ✅ Implemented (v1.1.29) — P0-P7 complete; folder README convention enforced via CI
**Plugin Version:** 1.1.17+  
**Reviewer:** GitHub Copilot Agent  
**Branch:** `copilot/enhance-plugin-compliance-unix-theory`

---

## Executive Summary

This proposal applies the foundational principles of Unix philosophy to the NV oOS plugin architecture. The goal is to make individual tools, services, and subsystems more composable, predictable, and maintainable by conforming to the same design disciplines that make Unix tools powerful and long-lived.

The Unix philosophy, as codified by Doug McIlroy and later by Eric S. Raymond, can be summarised in three rules:

> 1. Write programs that do **one thing** and do it well.  
> 2. Write programs to **work together**.  
> 3. Write programs to handle **text streams** (well-defined interfaces), because that is a universal interface.

Translated to a WordPress plugin context:

| Unix Principle | NV oOS Equivalent |
|----------------|-------------------|
| Do one thing well | Each tool class handles exactly one capability |
| Work together | Tools are composable via the agentic loop |
| Well-defined interfaces | Consistent `execute()` return shapes and JSON schemas |
| Modularity | Base-vs-Pro split, optional integrations guarded behind checks |
| Transparency | Lifecycle hooks fired at every key transition |
| Robustness | Sanitize early, escape late, return `WP_Error` on failure |

---

## 0. Current State (May 2026 audit)

This proposal was originally drafted against plugin v1.1.15. Two minor releases (v1.1.16, v1.1.17) have shipped since then, and several adjacent subsystems — the tools interface family, the agentic-loop hook payload, and the docs taxonomy — moved during that window. The recommendations below have been re-scoped to match what is **actually** in the tree at v1.1.17. Read this section before consuming §2–§4.

### 0.1 Tools are interface + traits, not a base class

The original proposal referenced `WP_MCP_AI_Tool_Base::format_success()`. **No such class exists.** Tools implement [`WP_MCP_AI_Tool_Interface`](../../includes/interfaces/interface-wp-mcp-ai-tool.php) directly and pull behaviour in via traits. The closest existing helper is:

- [`trait-wp-mcp-ai-tool-chat-response.php::format_success_response()`](../../includes/tools/trait-wp-mcp-ai-tool-chat-response.php) (line 119) — already returns `array( 'success' => true, 'message' => ..., 'data' => ... )` and is in use across base and Pro tools.

→ §2.2 and the Phase P1 item below have been re-targeted to either extend the trait or introduce a new shared trait/helper, rather than retrofitting an abstract base class. Either approach is acceptable; introducing an abstract base class is a much larger refactor and is **not** required to land the envelope discipline.

### 0.2 The agentic-loop hook signature has 4 args, not 3

The proposal's §2.5 sketched `do_action( 'wp_mcp_ai_after_tool_execution', $slug, $context, $descriptor )`. The hook actually fires with **four** arguments today:

```php
do_action( 'wp_mcp_ai_after_tool_execution', $tool_slug, $arguments, $context, $result );
```

…and is consumed at that arity by `WP_MCP_AI_Tool_Token_Limits`, `WP_MCP_AI_Enhanced_Token_Tracking`, the admin media-library columns, and the Gemini video service. The OTel exporter already registers it with `accepted_args = 5`, so it tolerates one optional extra parameter.

→ §2.5 / Phase P4 must be reframed as **adding an optional 5th descriptor argument** (`$descriptor`) without altering the first four positions; otherwise every existing subscriber breaks. Observability subscribers that want the normalised view should derive it from `$result` themselves until the 5th arg is shipped.

### 0.3 Several "produces/consumes"-style concerns are already covered

Since the original draft, the tools interface file gained two relevant siblings of `WP_MCP_AI_Tool_Interface`:

- `WP_MCP_AI_Tool_Capability_Flags_Interface` — `read-only`, `write`, `state-changing`, `cacheable`, `external-api`, `paginated`, `streaming-capable`, etc.
- `WP_MCP_AI_Tool_Rules_Interface` — `response_constraints`, `parameter_constraints`, `orchestration_hints`, `dependencies`.

→ §2.4 should position `produces` / `consumes` as a **data-contract** layer (e.g., `post_object`, `attachment_id`, `term_id`) that complements — not duplicates — the existing capability flags and tool rules. The agentic-loop wiring is the only piece still genuinely missing.

### 0.4 Documentation paths moved

The Phase P0 / §6 references must be re-pointed:

| Old path | New path |
|----------|----------|
| `docs/CODE_REVIEW.md` | [`docs/archive/code-reviews/CODE_REVIEW.md`](../archive/code-reviews/CODE_REVIEW.md) (archived; consider promoting a new living doc instead) |
| `docs/BEST_PRACTICES.md` | [`docs/guides/developer/best-practices/BEST_PRACTICES.md`](../guides/developer/best-practices/BEST_PRACTICES.md) |
| `docs/tool-reference.md` | [`docs/reference/tools/tool-reference.md`](../reference/tools/tool-reference.md) |
| `docs/hooks-reference.md` | unchanged |

The canonical "where envelope discipline gets enforced" surface is now [`CLAUDE.md`](../../CLAUDE.md) + the relevant `.context/` files (`.context/conventions.md`, `.context/tool-registry.md`), not the archived `docs/CODE_REVIEW.md`.

### 0.5 Optional-dependency guards are already widespread

A spot audit of `function_exists( 'jet_engine' )` and similar guards shows 17+ call sites across `includes/`, including the JetEngine CCT bootstraps, the chat-transcript recorder, the performance monitor, the privacy controller, and the tool handlers. §2.3 / Phase P2 therefore reduces from a green-field rollout to a **gap audit** of the remaining direct integration touch-points (notably any new Pro→Base reach-throughs and the Rank Math / WPCode tools).

### 0.6 Summary of changes to this proposal

| § | Status after audit |
|---|--------------------|
| 2.1 — One tool, one responsibility | Unchanged. Still relevant. |
| 2.2 — Canonical return envelope | Re-targeted: extend the existing `format_success_response()` trait helper instead of inventing `WP_MCP_AI_Tool_Base::format_success()`. |
| 2.3 — Capability fence for optional deps | Reduced scope: it's mostly an audit-and-fill-gaps task now. |
| 2.4 — `produces` / `consumes` metadata | Still missing — but should be positioned as a **data contract** layer on top of the existing capability flags / rules interfaces. |
| 2.5 — Structured lifecycle hook payload | Reframed as an **additive** 5th argument so existing 4-arg subscribers keep working. |
| 2.6 — Sanitize-at-entry / escape-at-exit | Unchanged. |

---

## 1. Motivation

NV oOS has grown to ~830 tools across Base and Pro. As the tool count expands, several anti-patterns surface that diverge from Unix-theory ideals:

1. **Overloaded tools** — some tool classes handle multiple unrelated actions via a single `action` parameter dispatch table, making them hard to test, document, and compose.
2. **Implicit output shapes** — `execute()` can return raw strings, flat arrays, nested arrays, or `WP_Error`; consumers must branch-check the return type.
3. **Tight coupling to integrations** — a handful of Base tools reach directly into optional dependencies (e.g., JetEngine, Rank Math) without a clean capability-check fence, causing unexpected failures on vanilla installations.
4. **Opaque error paths** — some tools return `array('success' => false, 'message' => '...')` while others return `new WP_Error(...)`. The agentic loop tolerates both, but AI model reasoning suffers from inconsistent error semantics.
5. **Missing stream/pipe composition** — the current tool schema does not describe whether a tool produces output that another tool can consume as input, limiting autonomous chaining quality.

---

## 2. Proposed Enhancements

### 2.1 One Tool, One Responsibility

**Problem:** Multi-action dispatch tools (e.g., a single `manage_posts` tool that handles `create`, `update`, `delete`, `list`, `trash`) violate the "do one thing" rule.

**Proposal:** Introduce an **action-split guideline**: any tool whose `action` parameter has more than 4 values must be decomposed into focused sub-tools during the next major refactor cycle.

Guiding rule:

```
if count(action_enum_values) > 4:
    split into dedicated tool classes
```

**Benefit:** Simpler schemas → fewer tokens for the LLM to parse → higher tool-call accuracy.

---

### 2.2 Canonical Return Envelope

**Problem:** `execute()` returns multiple inconsistent shapes.

**Proposal:** Define and enforce a **canonical return envelope**:

```php
// Success
return array(
    'success'    => true,
    'data'       => $payload,   // serialisable, schema-described
    'message'    => __( 'Done.', 'mcp-ai-wpoos' ),
);

// Failure — ALWAYS WP_Error, never success:false
return new WP_Error( 'code', __( 'Human-readable message.', 'mcp-ai-wpoos' ), $extra_data );
```

Key constraints:
- `data` is the only pipeable field — it must be serialisable via `wp_json_encode()`.
- `message` is always a translated human-readable string.
- Failure **must** use `WP_Error`; `success => false` arrays are forbidden.
- The agentic loop already handles `WP_Error`; no loop changes are required.

**Implementation:** Extend the existing [`trait-wp-mcp-ai-tool-chat-response.php::format_success_response()`](../../includes/tools/trait-wp-mcp-ai-tool-chat-response.php) helper (or introduce a sibling `trait-wp-mcp-ai-tool-envelope.php`) so any tool can compose the canonical success shape without inheriting from a hypothetical base class. Add a PHPCS sniff that warns when a tool returns a literal `array( 'success' => false, ... )`. See [§0.1](#01-tools-are-interface--traits-not-a-base-class) for context.

---

### 2.3 Capability Fence for Optional Dependencies

**Problem:** Some Base tools call optional dependency APIs without checking whether the dependency is active.

**Proposal:** Require every integration touch-point to pass through a **capability guard**:

```php
// Before calling JetEngine CCT API:
if ( ! function_exists( 'jet_engine' ) ) {
    return new WP_Error( 'missing_dependency', __( 'JetEngine is not active.', 'mcp-ai-wpoos' ) );
}
```

For Pro tools that are gated behind `WP_MCP_AI_BASE_VERSION`, the existing constant check is sufficient. For Base tools that use *optional* integrations, an explicit active-plugin check must precede every call.

---

### 2.4 Tool Composability Metadata

**Problem:** The AI model has no schema signal that says "the output of tool A can be fed as input to tool B."

**Proposal:** Add an optional `produces` / `consumes` metadata block to the tool definition:

```php
public function get_definition() {
    return array(
        'name'        => 'Get Post',
        'description' => '...',
        'produces'    => 'post_object',   // named data contract
        'consumes'    => null,
        'parameters'  => array( ... ),
    );
}
```

The agentic loop can surface these hints in the tool list sent to the model, enabling richer autonomous chaining. Both fields are optional and default to `null` for backward compatibility.

> **Note (May 2026 audit):** This is a **data-contract** layer that complements — but does not replace — the existing [`WP_MCP_AI_Tool_Capability_Flags_Interface`](../../includes/interfaces/interface-wp-mcp-ai-tool.php) (which already covers `read-only` / `write` / `external-api` / `cacheable` / `streaming-capable` and similar operational flags) and [`WP_MCP_AI_Tool_Rules_Interface`](../../includes/interfaces/interface-wp-mcp-ai-tool.php) (which covers `response_constraints`, `parameter_constraints`, `orchestration_hints`). `produces` / `consumes` describes the *shape of the payload* (e.g., `post_object`, `attachment_id`), not the tool's runtime characteristics. See [§0.3](#03-several-producesconsumes-style-concerns-are-already-covered).

---

### 2.5 Transparency via Structured Lifecycle Hooks

**Problem:** The existing hooks (`wp_mcp_ai_before_tool_execution`, `wp_mcp_ai_after_tool_execution`) carry the tool slug, arguments, context, and raw result, but not a normalised view of the outcome. Observability subscribers each re-derive `success` / `error_code` / `duration_ms` from `$result` independently.

**Current signature** (see [§0.2](#02-the-agentic-loop-hook-signature-has-4-args-not-3)):

```php
do_action( 'wp_mcp_ai_after_tool_execution', $tool_slug, $arguments, $context, $result );
```

**Proposal:** Add an **optional 5th argument** — a normalised result descriptor — without changing the first four positions. Subscribers registered with `accepted_args = 4` keep working unchanged; subscribers that want the normalised view bump to `accepted_args = 5`.

```php
do_action(
    'wp_mcp_ai_after_tool_execution',
    $tool_slug,
    $arguments,
    $context,
    $result,
    array(
        'success'     => ! is_wp_error( $result ),
        'error_code'  => is_wp_error( $result ) ? $result->get_error_code() : null,
        'data_type'   => is_array( $result ) ? ( $result['produces'] ?? 'generic' ) : null,
        'duration_ms' => $duration_ms,
    )
);
```

This lets observability subscribers (OTel, audit log, token-tracking) record richer metrics without each rolling their own derivation logic, and without coupling to the tool's internal return shape. The OTel exporter already registers with `accepted_args = 5`, so it picks up the descriptor immediately on rollout.

---

### 2.6 Robustness: Sanitize-at-Entry, Escape-at-Exit

**Problem:** Sanitization occasionally happens mid-method or is skipped when the tool trusts upstream context.

**Proposal:** Codify a **two-gate rule** in the developer documentation and enforce it via code review:

1. **Gate 1 — Entry:** All `$arguments` values are sanitized at the top of `execute()`, before any business logic.  
2. **Gate 2 — Exit:** All values returned in the `data` array and all values inserted into the database are escaped/prepared regardless of where they came from.

Add a lint comment convention:

```php
// [sanitized] $post_id = absint( $arguments['post_id'] );
// [escaped]   $safe    = esc_html( $title );
```

---

## 3. Implementation Phases

| Phase | Description | Effort | Target | Status |
|-------|-------------|--------|--------|--------|
| **P0** | Document canonical return envelope in [`CLAUDE.md`](../../CLAUDE.md) and `.context/conventions.md` (the archived `docs/CODE_REVIEW.md` is no longer the canonical surface — see [§0.4](#04-documentation-paths-moved)) | XS | v1.2.0 | ✅ Landed (May 2026) — `CLAUDE.md` "Tool Return Format — Canonical Envelope" + `.context/conventions.md` "Tool Return Envelope (Canonical)" + `.context/tool-registry.md` cross-link |
| **P1** | Promote `format_success_response()` from the chat-response trait to a tool-agnostic trait or shared helper; add a PHPCS warning for `'success' => false` arrays (see [§0.1](#01-tools-are-interface--traits-not-a-base-class)) | S | v1.2.0 | ✅ Landed (May 2026) — new `WP_MCP_AI_Tool_Envelope` trait at [`includes/tools/trait-wp-mcp-ai-tool-envelope.php`](../../includes/tools/trait-wp-mcp-ai-tool-envelope.php); `WP_MCP_AI_Tool_Chat_Response` composes it for back-compat; custom sniff `WPMCPAI.Tools.CanonicalReturnEnvelope` at [`phpcs/WPMCPAI/Sniffs/Tools/CanonicalReturnEnvelopeSniff.php`](../../phpcs/WPMCPAI/Sniffs/Tools/CanonicalReturnEnvelopeSniff.php) wired into [`phpcs.xml.dist`](../../phpcs.xml.dist) (warning severity → silent under `lint:base`'s `--warning-severity=8`, visible under default `composer run lint`) |
| **P2** | **Audit-only** for the remaining direct-integration touch-points (Rank Math, WPCode, any new Pro→Base reach-throughs). Most JetEngine/JetFormBuilder paths are already guarded — see [§0.5](#05-optional-dependency-guards-are-already-widespread) | S | v1.2.1 | ✅ Landed (May 2026) — audit document [`docs/proposals/audits/P2-capability-fence-audit-2026-05.md`](audits/P2-capability-fence-audit-2026-05.md). Findings: all touch-points already fenced via canonical `is_available()` + `execute()` pattern, multi-provider SEO Meta Optimizer fenced per-branch with a safe built-in fallback, WPCode is fully encapsulated in Pro (zero Base reach-throughs), 32 `jet_engine` guards confirmed across `includes/`. No code changes required. |
| **P3** | Add `produces` / `consumes` fields to tool definition schema; update agentic loop to forward hints. Position as a data-contract layer on top of the existing capability flags / rules interfaces ([§0.3](#03-several-producesconsumes-style-concerns-are-already-covered)) | M | v1.2.1 | ✅ Landed (May 2026) — new optional interface `WP_MCP_AI_Tool_Data_Contract_Interface` in [`includes/interfaces/interface-wp-mcp-ai-tool.php`](../../includes/interfaces/interface-wp-mcp-ai-tool.php); registry helper `WP_MCP_AI_Tool_Registry::get_tool_data_contract()` + `get_tool_definition()` surfaces a normalised `data_contract` key when present (see [`includes/class-wp-mcp-ai-tool-registry.php`](../../includes/class-wp-mcp-ai-tool-registry.php)); `WP_MCP_AI_Tool_Service::build_tools_payload()` appends a `[Data contract: produces=…, consumes=…]` suffix to the description so the model sees the chaining hint without breaking OpenAI's strict function-schema (see [`includes/services/class-wp-mcp-ai-tool-service.php`](../../includes/services/class-wp-mcp-ai-tool-service.php)); new filter `wp_mcp_ai_tool_data_contract_description_suffix` for customisation; tests in [`tests/test-tool-data-contract.php`](../../tests/test-tool-data-contract.php) |
| **P4** | Add an **optional 5th descriptor argument** to `wp_mcp_ai_after_tool_execution` without changing positions 1–4 ([§0.2](#02-the-agentic-loop-hook-signature-has-4-args-not-3)); update [`docs/hooks-reference.md`](../hooks-reference.md) | S | v1.2.1 | ✅ Landed (May 2026) — new helper `WP_MCP_AI_Tool_Lifecycle_Descriptor::build( $result, $start_micros, $tool_slug, $context )` in [`includes/services/class-wp-mcp-ai-tool-lifecycle-descriptor.php`](../../includes/services/class-wp-mcp-ai-tool-lifecycle-descriptor.php) producing `{success, error_code, data_type, duration_ms}`; all six firing sites updated to capture `$wp_mcp_ai_tool_start = microtime( true )` and pass the descriptor as a 5th arg (sync paths in [`includes/class-wp-mcp-ai-rest.php`](../../includes/class-wp-mcp-ai-rest.php) and [`includes/rest/class-wp-mcp-ai-rest-tools-controller.php`](../../includes/rest/class-wp-mcp-ai-rest-tools-controller.php); async paths in [`includes/services/class-wp-mcp-ai-tool-async-executor.php`](../../includes/services/class-wp-mcp-ai-tool-async-executor.php) and [`includes/services/class-wp-mcp-ai-gemini-video-generation-service.php`](../../includes/services/class-wp-mcp-ai-gemini-video-generation-service.php) override `duration_ms` from their own bookkeeping); OTel exporter `on_after_tool` now accepts the descriptor and writes `nvoos.tool.data_type` + `nvoos.tool.duration_ms` span attributes; new filter `wp_mcp_ai_tool_lifecycle_descriptor` for descriptor enrichment; tests in [`tests/test-tool-lifecycle-descriptor.php`](../../tests/test-tool-lifecycle-descriptor.php) including a back-compat guard that subscribers with `accepted_args = 4` still receive exactly 4 args; [`docs/hooks-reference.md`](../hooks-reference.md) lists both the legacy 4-arg and new 5-arg subscriber forms |
| **P5** | Action-split audit: identify multi-action tools with > 4 values; begin decomposition in Base | L | v1.3.0 | 🟡 Part 1 landed (May 2026) — full audit at [`docs/proposals/audits/P5-action-split-audit-2026-05.md`](audits/P5-action-split-audit-2026-05.md). 30 candidates classified into three tiers: **Tier A** (5 tools, multi-domain dispatch — slated for decomposition in Part 2: `remote_wp_connection` 20→6, `git_operations` 10→2, `web_browser` 7→3, `batch_manage_memory` 7→3, `manage_context_lifecycle` 7→3), **Tier B** (5 tools, cohesive subsystem — stay bundled, pinned at current enum size), **Tier C** (20 CRUD-canonical tools — permitted at exactly 5 verbs; 3 currently overflow and are slated for trim in Part 2: `manage_care_plan`, `track_vaccinations`, `log_vital_signs`). Part 2 **infrastructure** landed (May 2026) — back-compat alias mechanism on the tool registry (`register_deprecated_alias` / `get_deprecated_aliases` / `resolve_deprecated_alias` + `wp_mcp_ai_tool_deprecated_alias_invoked` action) at [`includes/class-wp-mcp-ai-tool-registry.php`](../../includes/class-wp-mcp-ai-tool-registry.php), tests at [`tests/test-tool-deprecated-alias.php`](../../tests/test-tool-deprecated-alias.php), master decomposition plan at [`docs/proposals/audits/P5-action-split-part-2-plan-2026-05.md`](audits/P5-action-split-part-2-plan-2026-05.md). **Decomposition #1** (`git_operations` → `git_inspect` + `git_change`) landed (May 2026) — shared helpers trait at [`addons/pro/includes/tools/architect-agent/trait-wp-mcp-ai-tool-git-helpers.php`](../../../addons/pro/includes/tools/architect-agent/trait-wp-mcp-ai-tool-git-helpers.php); read-only sub-tool at [`class-wp-mcp-ai-tool-git-inspect.php`](../../../addons/pro/includes/tools/architect-agent/class-wp-mcp-ai-tool-git-inspect.php); write sub-tool at [`class-wp-mcp-ai-tool-git-change.php`](../../../addons/pro/includes/tools/architect-agent/class-wp-mcp-ai-tool-git-change.php); both use canonical `WP_Error` failures (not `success=>false` arrays); alias registered in [`architect-agent-toolkit-init.php`](../../../addons/pro/includes/architect-agent-toolkit-init.php); 23 tests at [`addons/pro/tests/test-git-split-tools.php`](../../../addons/pro/tests/test-git-split-tools.php). Remaining: `web_browser` → `batch_manage_memory` → `manage_context_lifecycle` → `remote_wp_connection`, plus three Tier-C-overflow trims, each with a one-cycle alias expiring in v1.4.0. |
| **P6** | Codify sanitize-at-entry / escape-at-exit convention; PHPCS sniff or pre-commit hook | M | v1.3.0 | ✅ Landed (May 2026) — codification document at [`docs/proposals/audits/P6-sanitize-escape-codification-2026-05.md`](audits/P6-sanitize-escape-codification-2026-05.md). New PHPCS sniff `WPMCPAI.Tools.SanitizeAtEntry` at [`phpcs/WPMCPAI/Sniffs/Tools/SanitizeAtEntrySniff.php`](../../phpcs/WPMCPAI/Sniffs/Tools/SanitizeAtEntrySniff.php) — narrow, high-signal: warns when `$arguments[...]` is interpolated into a double-quoted string or concatenated with `.` outside a recognised safe wrapper (sanitisers + escapers + `$wpdb->prepare`). Scoped to `includes/tools/` + `addons/pro/includes/tools/`. Warning severity 5 (silent under `lint:base`, visible under default `lint`). Baseline run: 2 warnings across 255 base tool files (real pre-existing smell in `create-task-plan`). `.context/security-checklist.md` + `CLAUDE.md` cross-link the two-gate rule. |
| **P7** | Folder-level transparency — every PHP-bearing subdirectory under `includes/` (and `addons/pro/includes/`) ships a `README.md` declaring its purpose, public surface, neighbors, and which `.context/*.md` files to load alongside it. Applies Unix theory's *rule of transparency* + *rule of representation* at the directory level (one folder = one responsibility, self-describing). | M | v1.2.0 | ✅ Landed (June 2026, Base + Pro) — canonical template at [`.context/templates/folder-readme-template.md`](../../.context/templates/folder-readme-template.md) (seven required H2 sections: Purpose / Tier / Public Surface / Inputs-Outputs-Neighbors / Conventions / Tests / Also Load). Enforcement script [`bin/check-folder-readmes.php`](../../bin/check-folder-readmes.php) wired as `composer run docs:check-folder-readmes` and added to `composer run ci:all`. All **58 PHP-bearing subdirectories** (33 under `includes/` + 25 under `addons/pro/includes/`) ship a compliant README (verified `OK: 58 / Errors: 0 / Warnings: 0` under `--scope=all --strict`). Layering rule mirrors `AGENTS.md` §2 — folder READMEs link to canonical naming/security/PHP-compat sources rather than restating them; drift heuristics in the check script flag any restating. Full convention doc at [`docs/guides/developer/folder-readme-convention.md`](../guides/developer/folder-readme-convention.md). |

---

## 4. Acceptance Criteria

- [ ] A shared `format_success_response()`-style helper is available to any tool (currently scoped to the chat-response trait) and all new tools use it.
- [ ] No new Base tool calls an optional integration without a `function_exists()` (or equivalent) guard.
- [ ] `wp_mcp_ai_after_tool_execution` exposes an optional 5th `$descriptor` argument with `success`, `error_code`, `data_type`, `duration_ms`; existing 4-arg subscribers continue to work unchanged.
- [ ] `produces` / `consumes` metadata fields are documented in [`docs/reference/tools/tool-reference.md`](../reference/tools/tool-reference.md) and positioned as a data-contract layer alongside the existing capability flags / rules interfaces.
- [ ] The canonical return envelope is documented in [`CLAUDE.md`](../../CLAUDE.md) and `.context/conventions.md`.
- [ ] PHPUnit tests updated to assert `WP_Error` on failure paths (no `success => false`).
- [ ] PHPCS config updated to warn on `'success' => false` returns.

---

## 5. Risks and Mitigations

| Risk | Impact | Mitigation |
|------|--------|-----------|
| Existing tools returning `success => false` arrays | Break AI parsing of existing sessions | Enforce only on *new* code; add compat shim in agentic loop during transition |
| Decomposing multi-action tools causes schema churn | Third-party integrations break | Deprecate old slugs with 1-minor-version notice; keep shim classes |
| `produces`/`consumes` metadata unused by older AI providers | Zero benefit in some environments | Fields are optional; no provider rejection risk |

---

## 6. Related Documents

- [`CLAUDE.md`](../../CLAUDE.md) — Coding conventions and tool pattern (canonical envelope target post-refactor)
- [`.context/conventions.md`](../../.context/conventions.md) — Naming conventions, PHP-compat, code style
- [`.context/tool-registry.md`](../../.context/tool-registry.md) — Tool registry and capability-flag reference
- [`docs/guides/developer/best-practices/BEST_PRACTICES.md`](../guides/developer/best-practices/BEST_PRACTICES.md) — Usage recommendations
- [`docs/reference/tools/tool-reference.md`](../reference/tools/tool-reference.md) — Tool documentation
- [`docs/hooks-reference.md`](../hooks-reference.md) — Plugin lifecycle hooks (current 4-arg signature for `wp_mcp_ai_after_tool_execution`)
- [`includes/interfaces/interface-wp-mcp-ai-tool.php`](../../includes/interfaces/interface-wp-mcp-ai-tool.php) — `WP_MCP_AI_Tool_Interface` and the capability-flag / rules / flow-stage / context-restriction sibling interfaces
- [`includes/tools/trait-wp-mcp-ai-tool-chat-response.php`](../../includes/tools/trait-wp-mcp-ai-tool-chat-response.php) — Existing `format_success_response()` helper
- [`docs/archive/code-reviews/CODE_REVIEW.md`](../archive/code-reviews/CODE_REVIEW.md) — Archived legacy code-review checklist (formerly `docs/CODE_REVIEW.md`)
- [`FEATURE_GAP_ANALYSIS_PROPOSAL_2026_03.md`](./FEATURE_GAP_ANALYSIS_PROPOSAL_2026_03.md) — Prior gap analysis

---

*Proposal status: **IN PROGRESS** — Phases P0 (canonical envelope docs, May 2026), P1 (`WP_MCP_AI_Tool_Envelope` trait + `WPMCPAI.Tools.CanonicalReturnEnvelope` PHPCS sniff, May 2026), P2 (capability-fence audit, May 2026 — see [`docs/proposals/audits/P2-capability-fence-audit-2026-05.md`](audits/P2-capability-fence-audit-2026-05.md)), P3 (`produces` / `consumes` data-contract metadata, May 2026), P4 (optional 5th-arg lifecycle descriptor on `wp_mcp_ai_after_tool_execution` via `WP_MCP_AI_Tool_Lifecycle_Descriptor`, May 2026), P5 Part 1 (action-split audit, May 2026 — see [`docs/proposals/audits/P5-action-split-audit-2026-05.md`](audits/P5-action-split-audit-2026-05.md)), P5 Part 2 infrastructure (back-compat alias mechanism on the tool registry + master decomposition plan, May 2026 — see [`docs/proposals/audits/P5-action-split-part-2-plan-2026-05.md`](audits/P5-action-split-part-2-plan-2026-05.md)), P6 (sanitize-at-entry / escape-at-exit codification + `WPMCPAI.Tools.SanitizeAtEntry` PHPCS sniff, May 2026 — see [`docs/proposals/audits/P6-sanitize-escape-codification-2026-05.md`](audits/P6-sanitize-escape-codification-2026-05.md)), and P7 (folder-level transparency — template + check script + CI wiring + all 58 PHP-bearing subdirectories (33 Base + 25 Pro) shipping compliant READMEs under `--scope=all --strict`, June 2026 — see [`docs/guides/developer/folder-readme-convention.md`](../guides/developer/folder-readme-convention.md)) landed. Only the per-tool Phase P5 Part 2 decomposition PRs (v1.3.0) remain.*
