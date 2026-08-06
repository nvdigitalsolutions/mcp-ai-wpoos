# Abilities API Selective Adoption — Implementation Plan

**Date:** 2026-08-06 | **Status:** Draft | **Version:** 1.0
**Related:** [`019-abilities-api-selective-adoption-proposal.md`](019-abilities-api-selective-adoption-proposal.md) (architecture analysis) | [`../ABILITIES_REGISTRATION_PLAN.md`](../ABILITIES_REGISTRATION_PLAN.md) (long-term vision)

---

## 1. Research Synthesis: Industry Standards

### 1.1 WordPress Abilities API — Core Rules

| Principle | Standard | Application |
|---|---|---|
| Identifier format | `namespace/ability-name` (kebab-case) | `nvoos/{tool-slug-with-hyphens}` |
| Registration hook | `wp_abilities_api_init` (never `init`) | All bridge registrations |
| Categories prerequisite | `wp_abilities_api_categories_init` before abilities | Register 5–8 discovery categories first |
| Permission callback | Required; never `__return_true` for writes | Object-level `current_user_can()` |
| Schemas | `input_schema` + `output_schema` even when optional | Generic envelope Phase 1, precise Phase 2 |
| MCP exposure | Opt-in: `meta.mcp.public = true` | Set on discovery tools only |
| Backward compat | `function_exists('wp_register_ability')` guard | All registration code |
| Pre-6.9 sites | Composer `wordpress/abilities-api` or feature plugin | Plugin works without either |

### 1.2 MCP Tool Annotations — Industry Consensus

The MCP specification defines four canonical annotations ([spec blog](https://blog.modelcontextprotocol.io/posts/2026-03-16-tool-annotations/), [Stacklok analysis](https://stacklok.com/blog/tool-annotations-are-becoming-the-risk-vocabulary-for-agentic-systems-that-matters-more-than-it-might-seem)):

| Annotation | Default | Meaning When `true` | Set On |
|---|---|---|---|
| `readOnlyHint` | `false` | Tool does not modify environment | `get_*`, `list_*`, `search_*` |
| `destructiveHint` | `false` | May perform destructive updates | `delete_*`, `trash_*`, `purge_*` |
| `idempotentHint` | `false` | Same input → same output; safe to retry | Read-only, upserts, PUT updates |
| `openWorldHint` | `false` | Interacts with external entities | External API calls, webhooks |

**Key findings from industry research:**

1. **Set all four hints explicitly.** The spec defaults pessimistically. Unset annotations = maximum caution from agents. Explicit annotations improve agent UX and governance.
   — *[sunpeak.ai testing](https://sunpeak.ai/blogs/testing-mcp-tool-annotations/)*

2. **Annotations are UX hints, not security.** A malicious server can lie. They inform confirmation dialogs and auto-approval, never replace `permission_callback`.
   — *[ChatForest analysis](https://chatforest.com/guides/mcp-tool-annotations-explained/)*

3. **Combinatorial risk matters.** `search_emails` (read-only) + `openWorldHint` communication tool = data exfiltration risk. Annotations enable session-level policy.
   — *[Stacklok](https://stacklok.com/blog/tool-annotations-are-becoming-the-risk-vocabulary-for-agentic-systems-that-matters-more-than-it-might-seem)*

4. **Description is the AI's selector.** The most important field for agent tool-choice decisions. Write for a reader who doesn't know your plugin.
   — *[Red Hat MCP lifecycle](https://developers.redhat.com/articles/2026/01/08/building-effective-ai-agents-mcp)*

5. **Registry governance is emerging.** Red Hat and Stacklok both emphasize verifying tool metadata against source code before trusting annotations for policy.
   — *[Red Hat](https://developers.redhat.com/articles/2026/01/08/building-effective-ai-agents-mcp), [Stacklok](https://stacklok.com/blog/tool-annotations-are-becoming-the-risk-vocabulary-for-agentic-systems-that-matters-more-than-it-might-seem)*

---

## 2. Architecture: Component Design

### 2.1 System Diagram

```
wp_abilities_api_init (hook)
        │
        ├──► Category Registrar     → registers 5–8 discovery categories
        │
        └──► Ability Registrar      → iterates tool registry
                │
                └──► Ability Bridge (per eligible tool)
                        ├── Reads tool metadata (slug, name, desc, schema, flags)
                        ├── Maps capability flags → MCP annotations
                        ├── Wraps execute() with $context population
                        ├── Wraps WP_Error → structured error
                        └── Calls wp_register_ability()
                                │
                                ▼
                        WP_Ability in core registry
                                │
                    ┌───────────┼───────────┐
                    ▼           ▼           ▼
            MCP Adapter    REST /run     NV oOS MCP
            (auto-disc)    endpoint      (unchanged)
```

### 2.2 Key Classes

| Class | File | Responsibility |
|---|---|---|
| `WP_MCP_AI_Ability_Category_Registrar` | `includes/abilities/class-category-registrar.php` | Register categories on `wp_abilities_api_categories_init` |
| `WP_MCP_AI_Ability_Bridge` | `includes/abilities/class-ability-bridge.php` | Wrap one tool → one Ability: schemas, annotations, context, errors |
| `WP_MCP_AI_Ability_Registrar` | `includes/abilities/class-ability-registrar.php` | Iterate tool registry, bridge each eligible tool |
| `WP_MCP_AI_Tool_Ability_Interface` | `includes/interfaces/interface-ability.php` | Optional: tool self-declares as Ability |

### 2.3 Context Mapping

**Problem:** Abilities API `execute_callback` receives only validated input — no `$context`. NV oOS tools expect `$context` with `user_id`, `assistant_id`, etc.

**Solution — tiered population:**

```php
// Tier 1: Always available (from request state)
$context = array(
    'user_id'         => get_current_user_id(),
    'ability_context' => true,
);

// Tier 2: Only in NV oOS MCP path (not Abilities)
// Tools check ability_context before requiring these
$context += array(
    'assistant_id'    => null,
    'channel_id'      => null,
);
```

Tools requiring `assistant_id` check `$context['ability_context']` and return `WP_Error('ability_context_limited', ...)` if unavailable.

---

## 3. Annotation Mapping: Capability Flags → MCP

NV oOS tools declare 50+ capability flags. These map to MCP's four annotations:

### 3.1 Core Mappings

| NV oOS Flag(s) | MCP Annotation | Value |
|---|---|---|
| `read-only` | `readOnlyHint` | `true` |
| `write`, `state-changing` | `readOnlyHint` | `false` |
| `irreversible`, `data-destruction` | `destructiveHint` | `true` |
| `reversible` | `destructiveHint` | `false` |
| `idempotent` | `idempotentHint` | `true` |
| `non-deterministic` | `idempotentHint` | `false` |
| `external-api`, `network-dependent` | `openWorldHint` | `true` |
| `local-only` | `openWorldHint` | `false` |
| `long-running`, `async` | `openWorldHint` | `true` (results may not be immediate) |

### 3.2 Special Flags Without Direct MCP Equivalents

These flags inform tool filtering/gating but have no MCP annotation:

| Flag | Usage |
|---|---|
| `financial-impact` | Necessity Gate Layer J — requires elevated risk score |
| `external-communication` | Combinatorial policy: block with PII-access tools |
| `access-control-change` | Always requires human approval |
| `pii-data` | Session-level policy: block open-world tools when active |
| `cacheable` | Performance: cache Ability execution results |
| `requires-credentials` | Registration gate: don't register if credentials missing |

### 3.3 Annotation Logic (Pseudocode)

```php
function map_flags_to_annotations( array $flags ): array {
    return array(
        'readOnlyHint'      => in_array( 'read-only', $flags, true ),
        'destructiveHint'   => in_array( 'irreversible', $flags, true )
                            || in_array( 'data-destruction', $flags, true ),
        'idempotentHint'    => in_array( 'idempotent', $flags, true )
                            || in_array( 'read-only', $flags, true ),
        'openWorldHint'     => in_array( 'external-api', $flags, true )
                            || in_array( 'network-dependent', $flags, true )
                            || in_array( 'long-running', $flags, true ),
    );
}
```

---

## 4. Phased Implementation Plan

### Phase 0: Foundation (Week 1–2)

**Goal:** Prove the pattern with 5 pilot tools. Zero user-facing change.

**Tasks:**

| # | Task | Owner | Est. |
|---|---|---|---|
| 0.1 | Create `includes/abilities/` directory with README.md | — | 0.5h |
| 0.2 | Implement `WP_MCP_AI_Ability_Bridge` — wraps one tool → one Ability | — | 4h |
| 0.3 | Implement `WP_MCP_AI_Ability_Category_Registrar` — registers 5 categories | — | 2h |
| 0.4 | Implement `WP_MCP_AI_Ability_Registrar` — iterates tools, bridges eligible ones | — | 3h |
| 0.5 | Register 5 pilot tools as Abilities: `get_post`, `get-site-summary`, `list-cron-jobs`, `search-content`, `get-post-types` | — | 3h |
| 0.6 | Write PHPUnit tests: registration, execution, permissions, WP_Error handling | — | 6h |
| 0.7 | Benchmark: measure `wp_abilities_api_init` time for 5 tools | — | 1h |
| 0.8 | Manual smoke test: connect Claude Desktop via MCP Adapter, verify discovery | — | 2h |

**Deliverable:** Working PoC, tests green, benchmarks measured, external MCP client discovers tools.

**Acceptance gate:**
- [ ] `wp_has_ability('nvoos/get-post')` returns `true`
- [ ] `wp_get_ability('nvoos/get-post')->execute(['post_id' => 1])` returns correct post
- [ ] `permission_callback` denies subscriber-level user on `create_post`
- [ ] Existing NV oOS MCP endpoint unaffected (tools/list still works)
- [ ] `function_exists` guard: no fatal errors on WP 6.8
- [ ] Registration overhead <5ms for 5 tools

### Phase 1: Discovery Tools Registration (Week 3–4)

**Goal:** Register 20–50 high-value discovery/meta tools. These are the tools external AI agents need to interrogate the site's capabilities before initiating a chat session.

**Category hierarchy:**

| Category Slug | Label | Example Tools |
|---|---|---|
| `nvoos-site` | Site Information | `get-site-summary`, `get-post-types`, `get-taxonomies`, `get-themes`, `get-plugins`, `get-users` |
| `nvoos-content` | Content & Publishing | `get-post`, `search-posts`, `get-comments`, `get-menus` |
| `nvoos-media` | Media & Images | `search-media`, `get-attachment`, `optimize-image` |
| `nvoos-system` | System & Diagnostics | `list-cron-jobs`, `get-server-info`, `check-plugin-status` |
| `nvoos-discovery` | AI Model Discovery | `list-tools`, `get-tool-schema`, `get-providers`, `get-models` |

**Tasks:**

| # | Task | Est. |
|---|---|---|
| 1.1 | Finalize tool list for each category (collaborate with product) | 2h |
| 1.2 | Implement `WP_MCP_AI_Tool_Ability_Interface` — optional interface for tools | 2h |
| 1.3 | Add `get_output_schema()` to high-value tools that lack it | 4h |
| 1.4 | Extend Registrar to use toolkit-flag guards (skip tools whose dependencies aren't met) | 2h |
| 1.5 | Register all Phase 1 tools as Abilities with explicit annotations | 4h |
| 1.6 | Write tests: verify every Phase 1 ability is registered, executable, permission-gated | 6h |
| 1.7 | Benchmark: measure overhead for 50 tools | 1h |
| 1.8 | Generate `docs/reference/abilities-registry.md` — auto-generated ability catalog | 2h |

**Deliverable:** 20–50 discovery tools discoverable via Abilities API + MCP Adapter.

**Acceptance gate:**
- [ ] All Phase 1 abilities registered and executable
- [ ] All have `input_schema`, `output_schema`, and explicit MCP annotations
- [ ] All have `meta.mcp.public = true`
- [ ] Registration overhead <25ms for 50 tools
- [ ] Claude Desktop / VS Code Copilot can discover and invoke
- [ ] Zero regressions in existing chat UI, orchestrator, or REST endpoints

### Phase 2: Gradual Tool Expansion (Week 5–8)

**Goal:** Expand to ~100-150 tools by onboarding toolkits one at a time in priority order.

**Priority order (highest value first):**

| Priority | Toolkit/Source | ~Tools | Rationale |
|---|---|---|---|
| 1 | Embedded addon (already done) | 6 | Pattern is proven |
| 2 | Content & Media (read-only subset) | 20 | High external agent value |
| 3 | WooCommerce (read-only) | 10 | High demand, well-tested |
| 4 | System & Diagnostics | 15 | Useful for site management agents |
| 5 | SEO & Analytics | 8 | Content workflow value |
| 6 | Users & Permissions | 5 | Site management |
| 7 | Email & Messaging (read-only) | 5 | Integration value |
| 8 | CRM (read-only) | 15 | Business workflow |
| 9 | Support Tickets | 10 | Support workflow |

**Per-toolkit workflow:**
1. Audit toolkit tools for Ability eligibility (has clear schema? stateless? safe to expose?)
2. Add `output_schema` where missing
3. Register via Bridge with explicit annotations
4. Write toolkit-specific tests
5. MCP Adapter smoke test

**Acceptance gate:**
- [ ] 100+ tools registered as Abilities
- [ ] Per-toolkit tests passing
- [ ] Registration overhead <50ms for 150 tools
- [ ] No degradation in chat UI / orchestrator performance

### Phase 3: Hardening (Week 9–12, ongoing)

**Goal:** Rich output schemas, execution hooks, observability, developer docs.

**Tasks:**

| # | Task | Est. |
|---|---|---|
| 3.1 | Add precise `output_schema` to top-20 most-used tools (get_post, search_content, woo_get_products, etc.) | 8h |
| 3.2 | Implement execution hooks in Bridge: `wp_mcp_ai_before_ability_execute`, `wp_mcp_ai_after_ability_execute` | 3h |
| 3.3 | Integrate with Necessity Gate Layer J: check risk score before allowing destructive Ability execution | 4h |
| 3.4 | Add audit logging for Ability executions (via existing audit logger) | 3h |
| 3.5 | Performance benchmarks published in `docs/developer/performance/` | 2h |
| 3.6 | Developer guide: "How to make your NV oOS tool an Ability" | 3h |
| 3.7 | Update `docs/reference/abilities-registry.md` with precise output schemas | 2h |

---

## 5. Performance Design

### 5.1 Design Decisions

| Decision | Rationale |
|---|---|
| Register on `wp_abilities_api_init` only | Abilities aren't needed on frontend page views; no wasted work |
| Lazy tool instantiation | Store class name + slug, instantiate on first `execute()` via registry lookup |
| Toolkit-flag guards | Skip tools whose dependencies aren't met (reduces registered count on typical sites) |
| Object cache support | `wp_cache_get_salted()` for ability list on sites with persistent object cache |
| `function_exists` gate | Zero overhead on pre-6.9 sites |

### 5.2 Benchmarks

| Tool Count | Target Overhead | Measurement Method |
|---|---|---|
| 5 (Phase 0) | <5ms | `microtime(true)` around `wp_abilities_api_init` |
| 50 (Phase 1) | <25ms | Same; compare with plugin active vs inactive |
| 150 (Phase 2) | <50ms | Same; also profile with Query Monitor |
| 500+ (future) | <100ms | Requires deferred instantiation + object cache |

### 5.3 Lazy Instantiation Pattern

```php
// Instead of instantiating tool at registration time:
'execute_callback' => function ( array $input = array() ) use ( $class_name, $slug ) {
    $registry = WP_MCP_AI_Tool_Registry::get_instance();
    $tool     = $registry->get_tool( $slug );
    if ( ! $tool ) {
        return new WP_Error( 'tool_not_found', 'Tool not available.' );
    }
    $context = array(
        'user_id'         => get_current_user_id(),
        'ability_context' => true,
    );
    return $tool->execute( $input, $context );
},
```

---

## 6. Testing Strategy

### 6.1 Test Pyramid

```
        ┌──────────┐
        │ E2E: MCP  │  ← Manual: Claude Desktop / Copilot smoke test (Phase 0, 1, 2)
        │ Adapter   │
        ├──────────┤
        │Integration│  ← PHPUnit: full ability lifecycle (register→execute→permission→error)
        │  Tests    │     tests/abilities/test-ability-bridge.php
        ├──────────┤
        │  Unit     │  ← PHPUnit: Bridge, Registrar, annotation mapper, context builder
        │  Tests    │     tests/abilities/test-category-registrar.php
        └──────────┘
```

### 6.2 Test Cases

| Test File | Covers |
|---|---|
| `tests/abilities/test-category-registrar.php` | Categories registered on correct hook; collision handling; label/description |
| `tests/abilities/test-ability-bridge.php` | Tool→Ability mapping; schema translation; annotation mapping; context population; WP_Error handling |
| `tests/abilities/test-ability-registrar.php` | Bulk registration; toolkit-flag guards; capability filtering; backward compat |
| `tests/abilities/test-ability-execution.php` | `execute()` via `WP_Ability`; input validation; permission denial; output validation |
| `tests/abilities/test-ability-backward-compat.php` | `function_exists` guard; pre-6.9 no-op; existing MCP endpoint unaffected |

### 6.3 Test Fixtures

```php
// Mock tool for testing
class WP_MCP_AI_Tool_Test_ReadOnly extends WP_MCP_AI_Tool_Base {
    public function get_slug() { return 'test_read_only'; }
    public function get_name() { return 'Test Read Only'; }
    public function get_description() { return 'A test read-only tool.'; }
    public function get_parameters_schema() {
        return array(
            'type' => 'object',
            'properties' => array( 'id' => array( 'type' => 'integer' ) ),
            'required' => array( 'id' ),
        );
    }
    public function get_required_capability() { return 'read'; }
    public function get_capability_flags() {
        return array( 'read-only', 'idempotent', 'local-only' );
    }
    public function execute( $args, $context ) {
        return array( 'success' => true, 'data' => array( 'id' => $args['id'] ) );
    }
}
```

---

## 7. Risk Register & Mitigation Matrix

| # | Risk | Probability | Impact | Mitigation | Contingency |
|---|---|---|---|---|---|
| R1 | Performance regression from bulk registration | Medium | High | Lazy instantiation, toolkit guards, benchmarks at each phase | Roll back registration; investigate object cache |
| R2 | WP_Error not natively handled by Abilities API | Medium | Medium | Bridge wraps WP_Error in structured array; test in Phase 0 | Convert to thrown exception if needed |
| R3 | Permission mismatch: Ability gate vs tool gate | High | High | Use exact same capability string; test every tool | Audit script to compare both checks |
| R4 | Schema skew: tool schema changes, Ability stale | Low | Low | Registration on every request reads fresh from tool | CI check that compares schemas |
| R5 | MCP Adapter not stable/available when we ship | Low | Low | NV oOS MCP endpoint unaffected; Abilities additive | Wait for adapter; no code removal needed |
| R6 | Naming collision: another plugin uses `nvoos/get-post` | Low | Medium | Namespace `nvoos` matches plugin slug; `wp_has_ability_category()` check | Rename if collision detected |
| R7 | Double-execution: tool called via both paths concurrently | Low | Low | Both paths are idempotent by design; Layer J scores risk | Rate limiting on both paths |
| R8 | WP <6.9 users blocked from updating plugin | None | — | All registration guarded by `function_exists` | Plugin works identically without Abilities |

---

## 8. Success Criteria & Acceptance Gates

### Phase 0 Gate
- [ ] 5 pilot tools registered as Abilities with valid schemas
- [ ] `permission_callback` correctly denies unauthorized users
- [ ] Existing NV oOS MCP endpoint unaffected
- [ ] External MCP client can discover and execute at least 1 tool
- [ ] Registration overhead <5ms

### Phase 1 Gate
- [ ] 20–50 discovery tools registered with explicit MCP annotations
- [ ] All have `input_schema`, `output_schema`, `meta.mcp.public = true`
- [ ] Claude Desktop / VS Code Copilot can discover all Phase 1 tools
- [ ] Registration overhead <25ms
- [ ] Zero regressions in chat UI, orchestrator, or REST

### Phase 2 Gate
- [ ] 100+ tools registered across multiple toolkits
- [ ] Per-toolkit tests passing with >80% coverage
- [ ] Registration overhead <50ms
- [ ] Developer documentation published

### Phase 3 Gate
- [ ] Top-20 tools have precise `output_schema`
- [ ] Execution hooks integrated with Layer J and audit logger
- [ ] Performance benchmarks published
- [ ] Third-party developer guide available

---

## 9. What We Explicitly Do NOT Build

- ❌ A wholesale migration of all ~1,000 tools — tools that require `$context.assistant_id`, rich metadata (model requirements, safety profiles), or dynamic schemas stay in the custom registry
- ❌ A second class of tools — the `WP_MCP_AI_Tool_Ability_Interface` is optional and additive, not a fork
- ❌ `__return_true` permission callbacks on any state-changing tool
- ❌ A replacement for the NV oOS MCP endpoint — Abilities registration is additive, never subtractive

---

## 10. References

### WordPress Core
- [Abilities API Documentation](https://developer.wordpress.org/apis/abilities-api/)
- [Introducing the Abilities API (Nov 2025)](https://developer.wordpress.org/news/2025/11/introducing-the-wordpress-abilities-api/)
- [From Abilities to AI Agents: MCP Adapter (Feb 2026)](https://developer.wordpress.org/news/2026/02/from-abilities-to-ai-agents-introducing-the-wordpress-mcp-adapter/)
- [Make WordPress Core AI Team](https://make.wordpress.org/ai/)
- [`wordpress/abilities-api` Composer package](https://packagist.org/packages/wordpress/abilities-api)
- [`WordPress/abilities-api` GitHub](https://github.com/WordPress/abilities-api)

### MCP Specification
- [MCP Tool Annotations (Mar 2026)](https://blog.modelcontextprotocol.io/posts/2026-03-16-tool-annotations/)
- [MCP Specification](https://modelcontextprotocol.io/)
- [MCP Best Practices](https://mcp-best-practice.github.io/mcp-best-practice/best-practice/)

### Industry
- [Stacklok: Tool Annotations as Risk Vocabulary](https://stacklok.com/blog/tool-annotations-are-becoming-the-risk-vocabulary-for-agentic-systems-that-matters-more-than-it-might-seem)
- [Red Hat: Building Effective AI Agents with MCP](https://developers.redhat.com/articles/2026/01/08/building-effective-ai-agents-mcp)
- [sunpeak.ai: Testing MCP Tool Annotations](https://sunpeak.ai/blogs/testing-mcp-tool-annotations/)

### Project-Internal
- `.agents/skills/wp-abilities-api/SKILL.md` — Abilities API agent skill
- [`docs/project/ABILITIES_REGISTRATION_PLAN.md`](../ABILITIES_REGISTRATION_PLAN.md) — Full ~1,000-tool plan
- [`addons/embedded/includes/abilities/class-nvoos-embedded-abilities.php`](../../addons/embedded/includes/abilities/class-nvoos-embedded-abilities.php) — Working reference implementation
- `includes/interfaces/interface-wp-mcp-ai-tool.php` — Tool capability flags (50+)
