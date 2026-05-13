# NV oOS — Unix Theory Compliance Enhancement Proposal

**Date:** May 2026  
**Status:** 🟡 PROPOSED  
**Plugin Version:** 1.1.15+  
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

**Implementation:** Add a `WP_MCP_AI_Tool_Base::format_success()` helper and a PHPCS sniff that warns when `success => false` is returned directly.

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

---

### 2.5 Transparency via Structured Lifecycle Hooks

**Problem:** The existing hooks (`wp_mcp_ai_before_tool_execution`, `wp_mcp_ai_after_tool_execution`) carry the tool slug and context, but not the return shape or error state.

**Proposal:** Augment the `after_tool_execution` hook payload with a normalised result descriptor:

```php
do_action(
    'wp_mcp_ai_after_tool_execution',
    $slug,
    $context,
    array(
        'success'       => ! is_wp_error( $result ),
        'error_code'    => is_wp_error( $result ) ? $result->get_error_code() : null,
        'data_type'     => is_array( $result ) ? ( $result['produces'] ?? 'generic' ) : null,
        'duration_ms'   => $duration_ms,
    )
);
```

This lets observability subscribers (OTel, audit log) record richer metrics without coupling to the tool's internal return shape.

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

| Phase | Description | Effort | Target |
|-------|-------------|--------|--------|
| **P0** | Document canonical return envelope in `CLAUDE.md` + `docs/CODE_REVIEW.md` | XS | v1.2.0 |
| **P1** | Add `format_success()` helper to `WP_MCP_AI_Tool_Base`; add PHPCS warning for `success => false` arrays | S | v1.2.0 |
| **P2** | Audit all Base tools for optional-dependency guards; add missing `function_exists()` fences | M | v1.2.1 |
| **P3** | Add `produces` / `consumes` fields to tool definition schema; update agentic loop to forward hints | M | v1.2.1 |
| **P4** | Augment `wp_mcp_ai_after_tool_execution` hook payload | S | v1.2.1 |
| **P5** | Action-split audit: identify multi-action tools with > 4 values; begin decomposition in Base | L | v1.3.0 |
| **P6** | Codify sanitize-at-entry / escape-at-exit convention; PHPCS sniff or pre-commit hook | M | v1.3.0 |

---

## 4. Acceptance Criteria

- [ ] `WP_MCP_AI_Tool_Base::format_success()` exists and all new tools use it.
- [ ] No new Base tool calls an optional integration without a `function_exists()` guard.
- [ ] `wp_mcp_ai_after_tool_execution` hook includes `success`, `error_code`, `data_type`, `duration_ms`.
- [ ] `produces` / `consumes` metadata fields are documented in `docs/tool-reference.md`.
- [ ] The canonical return envelope is documented in `CLAUDE.md` and enforced in `docs/CODE_REVIEW.md`.
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

- [`CLAUDE.md`](../../CLAUDE.md) — Coding conventions and tool pattern
- [`docs/CODE_REVIEW.md`](../CODE_REVIEW.md) — Code quality standards
- [`docs/BEST_PRACTICES.md`](../BEST_PRACTICES.md) — Usage recommendations
- [`docs/tool-reference.md`](../tool-reference.md) — Tool documentation
- [`docs/hooks-reference.md`](../hooks-reference.md) — Plugin lifecycle hooks
- [`FEATURE_GAP_ANALYSIS_PROPOSAL_2026_03.md`](./FEATURE_GAP_ANALYSIS_PROPOSAL_2026_03.md) — Prior gap analysis

---

*Proposal status: **PROPOSED** — awaiting review by project maintainers.*
