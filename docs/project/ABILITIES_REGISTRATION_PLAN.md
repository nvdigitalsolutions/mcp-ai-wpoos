# Plan: Register ~1,000 NV oOS Tools as WordPress Abilities

> **Version:** 1.0 · **Date:** 2026-07-02 · **Status:** Draft for Review
>
> **Goal:** Make every NV oOS tool discoverable by any MCP client through the
> official WordPress Abilities API → MCP Adapter pipeline, while keeping the
> existing NV oOS MCP endpoint fully operational as a superset.

---

## 1. Why This Matters

### The strategic shift (mid-2026)

WordPress core is systematically building the **AI Building Blocks** stack:

1. **Abilities API** (WP 6.9+) — a standardized registry for plugin operations with
   JSON Schema contracts, permission callbacks, and REST exposure.
2. **Official MCP Adapter** (`wordpress/mcp-adapter`) — bridges every registered
   Ability to the Model Context Protocol, so Claude Desktop, VS Code Copilot,
   Cursor, and any MCP-compliant agent can discover and invoke them.
3. **WordPress.com already ships a built-in MCP server** that exposes Abilities to
   agents. Automattic's own `wordpress-mcp` plugin is deprecated in favor of the
   adapter.

**The implication for NV oOS:** The MCP-server plumbing that our plugin provides
today (`tools/list`, `tools/call` on the JSON-RPC endpoint) is **table stakes on
a countdown timer.** Once every WP 6.9+ site can install the official MCP Adapter
and get all registered Abilities for free, our MCP endpoint is no longer a
differentiator — it's overhead.

**The durable value** is the *runtime*: assistants, memory, multi-agent
orchestration, guardrails (Layer I/J), blueprints, skills, workflow presets,
attention routing, and the ~1,000-tool library itself. If those tools are also
registered as Abilities, then:

- Any MCP client (including ones that have never heard of NV oOS) discovers them.
- The official MCP Adapter becomes a **free distribution channel** for our tools.
- NV oOS's own MCP endpoint becomes a *superset* with assistant-scoping,
  guardrails, and orchestration — i.e., the Adapter handles basic exposure, NV oOS
  adds runtime intelligence.

### The WordPress core adoption timeline

| Milestone | Date | Impact |
|---|---|---|
| Abilities API ships in core | WP 6.9 (March 2026) | Server-side registry available |
| MCP Adapter feature plugin | Active development | Bridges Abilities → MCP |
| WP 6.9+ adoption curve | ~12-18 months from release | Growing percentage of sites can use Abilities natively |
| WordPress.com MCP server | Live now | Abilities-based MCP already in production on .com |

**Recommendation:** Start registration now so NV oOS is ready when the adapter
graduates and core adoption reaches critical mass (~Q4 2026–Q1 2027).

---

## 2. Current Architecture vs. Target Architecture

### 2.1 Current state

```
┌──────────────────────────────────────────┐
│  NV oOS MCP JSON-RPC Endpoint            │
│  POST /wp-json/mcp/v1/mcp                │
│                                          │
│  tools/list  →  mcp_tools_list()         │
│    ├─ Reads WP_MCP_AI_Tool_Interface     │
│    ├─ Builds MCP tool entries inline     │
│    └─ Assistant-scoped filtering         │
│                                          │
│  tools/call  →  mcp_tools_call()         │
│    ├─ Looks up tool in registry          │
│    ├─ Checks capability                  │
│    └─ Calls execute()                    │
└──────────────────────────────────────────┘
         ▲
         │ Only NV oOS-aware MCP clients
         │ (Claude Desktop w/ manual config,
         │  LM Studio w/ custom setup, etc.)
```

### 2.2 Target state

```
┌────────────────────────────────────────────────────┐
│  WordPress Abilities Registry (core, WP 6.9+)      │
│                                                    │
│  wp_register_ability( 'mcp-ai-wpoos/get-post', … ) │
│  wp_register_ability( 'mcp-ai-wpoos/create-post',…)│
│  … ~1,000 abilities                                │
│                                                    │
│  Exposed via:                                      │
│  ├─ WP REST: /wp-json/wp-abilities/v1/abilities    │
│  ├─ JS:       wp.abilities.executeAbility()        │
│  ├─ Official MCP Adapter → any MCP client          │
│  └─ NV oOS MCP endpoint (superset)                 │
└──────────┬─────────────────────────────────────────┘
           │
           ▼
┌──────────────────────────────────────────────────────┐
│  Official MCP Adapter (wordpress/mcp-adapter)        │
│                                                      │
│  Auto-discovers all registered Abilities             │
│  Bridges them to MCP tools/list + tools/call         │
│  Zero code needed per-ability                        │
│                                                      │
│  Any MCP client can connect:                         │
│  • Claude Desktop  • VS Code / Cursor                │
│  • ChatGPT connector  • LM Studio  • Custom agents   │
└──────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────┐
│  NV oOS MCP Endpoint (maintained, superset)          │
│                                                      │
│  Same endpoint, but can now optionally:              │
│  • Delegate to Ability execution for basic tools     │
│  • Add assistant-scoping (not in core Abilities)     │
│  • Add guardrails (Necessity Gate Layer J)           │
│  • Add orchestration (workflows, blueprints, A2A)    │
│  • Add memory / context lifecycle                    │
│  • Add rate limiting, audit logging, cost tracking   │
└──────────────────────────────────────────────────────┘
```

**Key insight:** The official MCP Adapter handles the commodity problem
("expose WP operations to AI agents"). NV oOS provides the *runtime* on top:
identity-aware assistants, safety gates, multi-agent delegation, memory,
and vertical toolkits. **The two are complementary, not competitive.**

---

## 3. Mapping NV oOS Tools to WordPress Abilities

### 3.1 Identifier convention

```
NV oOS tool slug           →  Ability identifier
──────────────────────────────────────────────────
get_post                  →  mcp-ai-wpoos/get-post
create_post               →  mcp-ai-wpoos/create-post
search_content            →  mcp-ai-wpoos/search-content
woo_get_products          →  mcp-ai-wpoos/woo-get-products
crm_create_lead           →  mcp-ai-wpoos/crm-create-lead
flowhub_get_inventory     →  mcp-ai-wpoos/flowhub-get-inventory
```

**Rule:** `mcp-ai-wpoos/{original-slug-with-hyphens}` — the namespace is the
plugin slug, the ability name is the tool slug with underscores replaced by
hyphens. This keeps identifiers predictable for any AI agent or developer
who already knows the tool slug.

### 3.2 Category hierarchy

~1,000 tools need organization. We register ~20 categories, aligned with
the existing toolkit groupings:

| Category slug | Label | Tool count (approx.) |
|---|---|---|
| `mcp-ai-wpoos-content` | Content & Publishing | 40 |
| `mcp-ai-wpoos-media` | Media & Images | 35 |
| `mcp-ai-wpoos-seo` | SEO & Analytics | 15 |
| `mcp-ai-wpoos-users` | Users & Permissions | 10 |
| `mcp-ai-wpoos-system` | System & Diagnostics | 25 |
| `mcp-ai-wpoos-ai-models` | AI Model Management | 15 |
| `mcp-ai-wpoos-embeddings` | Embeddings & Vector Stores | 10 |
| `mcp-ai-wpoos-research` | Research & Web Search | 12 |
| `mcp-ai-wpoos-agents` | Agent Coordination | 10 |
| `mcp-ai-wpoos-memory` | Agent Memory & Context | 12 |
| `mcp-ai-wpoos-woocommerce` | WooCommerce | 15 |
| `mcp-ai-wpoos-crm` | CRM & Customer Management | 70 |
| `mcp-ai-wpoos-support` | Support Tickets | 15 |
| `mcp-ai-wpoos-jetengine` | JetEngine | 10 |
| `mcp-ai-wpoos-forms` | Forms & Submissions | 10 |
| `mcp-ai-wpoos-email` | Email & Messaging | 12 |
| `mcp-ai-wpoos-social` | Social & Chat Channels | 10 |
| `mcp-ai-wpoos-finance` | Finance & CRE | 60 |
| `mcp-ai-wpoos-infra` | Infrastructure & DevOps | 25 |
| `mcp-ai-wpoos-healthcare` | Healthcare (Pro) | 10 |

Categories are registered on `wp_abilities_api_categories_init` (before abilities).
Registrations use `wp_register_ability_category()` and are guard-checked with
`wp_has_ability_category()` to avoid collisions with other plugins.

### 3.3 Field mapping

| NV oOS Tool Interface Method | Ability Registration Field | Notes |
|---|---|---|
| `get_slug()` | identifier (after namespace prefix) | Replace `_` with `-` |
| `get_name()` | `label` | Already human-readable |
| `get_description()` | `description` | This is the AI's selector — already well-written |
| `get_parameters_schema()` | `input_schema` | Near-perfect match; the tool schema is already JSON Schema compliant |
| `get_required_capability()` | `permission_callback` | Wrap in a closure: `fn() => current_user_can($cap)` |
| `execute()` | `execute_callback` | Wrap in closure that calls `$tool->execute($input, $context)` |
| N/A (new) | `output_schema` | We add an `get_output_schema()` method to the base interface |
| `get_capability_flags()` | `meta.annotations` | Map to MCP annotations (`readOnlyHint`, `destructiveHint`, etc.) |

### 3.4 Output schema strategy

The Abilities API supports `output_schema` for response contracts. NV oOS tools
currently don't declare formal output schemas — the canonical return envelope is
`{success: true, message: string, data: mixed}` or `WP_Error`.

**Approach — progressive enhancement, not big-bang:**

1. **Phase 1 (this plan):** Register all tools with a *generic* output schema
   describing the canonical envelope. This satisfies the contract requirement
   without blocking on per-tool analysis.

   ```php
   'output_schema' => array(
       'type'       => 'object',
       'properties' => array(
           'success' => array( 'type' => 'boolean', 'description' => 'Whether the operation succeeded.' ),
           'message' => array( 'type' => 'string',  'description' => 'Human-readable summary.' ),
           'data'    => array( 'description' => 'Operation-specific result payload.' ),
       ),
   ),
   ```

2. **Phase 2 (per-toolkit follow-up):** Add precise output schemas
   (e.g., `get_post` returns a `post_object` with specific fields, `create_post`
   returns a `post_id`). This improves AI agent accuracy and enables schema-aware
   clients. Tracked per-toolkit, not blocking.

### 3.5 Capability flags → MCP annotations mapping

The tool interface already has `get_capability_flags()` returning strings like
`'read-only'`, `'write'`, `'irreversible'`, `'idempotent'`, etc.

The Abilities API `meta` field supports `annotations` that map directly to
MCP 2024-11-05 tool annotations:

| NV oOS Flag | MCP Annotation | Notes |
|---|---|---|
| `read-only` | `readOnlyHint: true` | |
| `write` | `readOnlyHint: false` | Default if not specified |
| `irreversible` | `destructiveHint: true` | |
| `reversible` | `destructiveHint: false` | Default |
| `idempotent` | `idempotentHint: true` | |
| `long-running` | `openWorldHint: true` | Signals result may not be immediate |

These go into `meta.annotations` on the ability registration and are
forwarded by the official MCP Adapter automatically.

### 3.6 Context mapping

NV oOS tools receive a `$context` array with keys like `user_id`, `assistant_id`,
`conversation_id`, etc. WordPress Abilities' `execute_callback` receives
*only* the validated input from `input_schema`. To pass context, we use one of:

**Option A (preferred): Closure captures registration-time state.**

```php
$tool_instance = $tool; // already instantiated
wp_register_ability(
    "mcp-ai-wpoos/{$slug}",
    array(
        // …
        'execute_callback' => function ( array $input = array() ) use ( $tool_instance ) {
            $context = array(
                'user_id'         => get_current_user_id(),
                'ability_context' => true, // signal this came via Ability
            );
            return $tool_instance->execute( $input, $context );
        },
    )
);
```

This works because tool instances are lightweight (they don't hold mutable state
between calls). The `$context` is still populated with what's available at
execution time.

**Option B: Implement a thin `WP_MCP_AI_Ability_Bridge` class** that implements
`__invoke()` and is registered as the `execute_callback`. This is cleaner if
we want to add logging, guardrails, or cost tracking to the Ability execution
path. Recommended for Phase 2.

### 3.7 Handling `WP_Error` returns

WordPress Abilities' `execute_callback` is expected to return data or throw.
`WP_Error` is not a native Ability concept. The WP 6.9.4 `WP_Ability::execute()`
doesn't natively convert `WP_Error` to an error response; that's handled at the
REST layer.

**Solution:** The bridge callback wraps the tool's `execute()` return:

```php
$result = $tool_instance->execute( $input, $context );
if ( is_wp_error( $result ) ) {
    // Convert to a structured error that the Ability REST endpoint can surface.
    // The MCP Adapter will translate this to an MCP error response.
    return new WP_Error(
        $result->get_error_code(),
        $result->get_error_message(),
        $result->get_error_data()
    );
}
return $result;
```

However, per the skill doc: if `permission_callback` returns `WP_Error`, core
logs via `_doing_it_wrong()` and returns a generic `ability_invalid_permissions`
error. For `execute_callback`, returning `WP_Error` may not be natively handled
by the Abilities API in 6.9.4. **We should verify this behavior in testing and
potentially wrap errors in a thrown exception or a structured return array.**

---

## 4. Performance Design for ~1,000 Abilities

### 4.1 Problem

Registering ~1,000 abilities means ~1,000 calls to `wp_register_ability()` on
every request (if registered eagerly). Each call instantiates a `WP_Ability`
object and stores it in the registry. For a site with 195 base tools, this is
manageable. For a Pro site with ~1,000, it's worth measuring.

### 4.2 Mitigations

#### A. Lazy registration (condition-based)

Only register abilities for tools whose dependencies are met:

```php
// Don't register Shopify tools if Shopify isn't configured
if ( $this->is_toolkit_enabled( 'enable_shopify_toolkit' ) ) {
    $this->register_toolkit_abilities( 'shopify' );
}
```

This mirrors the existing toolkit-flag pattern and means a typical site
registers 200–400 abilities, not 1,000.

#### B. Deferred instantiation

Instead of instantiating every tool class at registration time, store a
factory closure and instantiate on first `execute()`:

```php
wp_register_ability(
    "mcp-ai-wpoos/{$slug}",
    array(
        // …
        'execute_callback' => function ( array $input = array() ) use ( $class_name, $slug ) {
            $registry = WP_MCP_AI_Tool_Registry::get_instance();
            $tool     = $registry->get_tool( $slug );
            if ( ! $tool ) {
                return new WP_Error( 'tool_not_found', 'Tool not available.' );
            }
            return $tool->execute( $input, array( 'user_id' => get_current_user_id() ) );
        },
    )
);
```

This way, tool classes are only loaded when actually called, not when registered.

#### C. Object caching for the ability list

The `wp_get_abilities()` result can be cached via WordPress object cache
(Redis/memcached). Core does not do this automatically, but the MCP Adapter
likely will (or we can add a mu-plugin helper).

#### D. Registration on `wp_abilities_api_init` only

Per the skill: *always* register on `wp_abilities_api_init`, not `init`.
This ensures abilities are only registered when the API is active, avoiding
wasted work on frontend page views that never call abilities.

#### E. Benchmarks

Before full rollout, benchmark:

```
Site with 200 abilities registered → measure wp_abilities_api_init hook time
Site with 500 abilities registered → measure wp_abilities_api_init hook time
Site with 1,000 abilities registered → measure wp_abilities_api_init hook time
```

Target: <50ms additional load time for 500 abilities on PHP 8.1+ with opcache.

### 4.3 WP 6.9+ vs pre-6.9 compatibility

The Abilities API ships in WP 6.9. For sites on earlier versions:

- **Feature-detect** `function_exists( 'wp_register_ability' )` before registering.
- **Composer fallback:** add `wordpress/abilities-api` as a suggested dependency
  in `composer.json` for pre-6.9 sites that run Composer.
- **Feature plugin:** document that pre-6.9 sites can install the feature plugin
  from `https://github.com/WordPress/abilities-api`.

In all cases, the existing NV oOS MCP endpoint continues to work
independently — the Ability registration is additive.

---

## 5. Implementation Plan (Phased Rollout)

### Phase 0: Foundation (1–2 weeks)

**Goal:** Prove the pattern works with 5 representative tools. No user-facing change.

1. Create `includes/abilities/class-wp-mcp-ai-ability-bridge.php` — the bridge
   class that wraps a tool instance into an Ability-compatible callable.
2. Create `includes/abilities/class-wp-mcp-ai-ability-registrar.php` — the class
   responsible for bulk-registering tool categories and abilities.
3. Register 5 pilot tools as Abilities:
   - `mcp-ai-wpoos/get-post` (read-only, simple)
   - `mcp-ai-wpoos/create-post` (write, with input schema)
   - `mcp-ai-wpoos/get-site-summary` (no input params)
   - `mcp-ai-wpoos/search-content` (complex input schema)
   - `mcp-ai-wpoos/list-cron-jobs` (system tool)
4. Write tests verifying:
   - `wp_has_ability( 'mcp-ai-wpoos/get-post' )` returns `true`
   - `wp_get_ability( 'mcp-ai-wpoos/get-post' )->execute( ['post_id' => 1] )` works
   - `permission_callback` denies unauthorized users
   - The existing NV oOS MCP endpoint still lists and executes the tools
   - `WP_Error` returns are handled correctly
5. Benchmark registration overhead for 5 abilities.
6. Manual test: connect Claude Desktop via the official MCP Adapter and verify
   it discovers and can call the 5 pilot abilities.

**Deliverable:** Working proof-of-concept, tests passing, benchmarks measured.

### Phase 1: Base Tools Registration (2–3 weeks)

**Goal:** All ~195 base tools registered as Abilities. No Pro dependency.

1. Extend `WP_MCP_AI_Tool_Interface` (or add a companion interface) with
   an optional `get_output_schema(): array` method. Default implementation
   returns the generic canonical envelope schema.
2. Add ~15 categories to `wp_abilities_api_categories_init`.
3. In `includes/class-wp-mcp-ai-tool-registry.php`, add a
   `register_tools_as_abilities()` method that iterates over all registered
   tools and calls `WP_MCP_AI_Ability_Bridge::register( $tool )`.
4. Hook into `wp_abilities_api_init` at priority 10.
5. Add `WP_MCP_AI_BASE_VERSION` guard so Pro tools are excluded in base mode.
6. Add toolkit-flag guards so conditionally-loaded base tools only register
   when their dependencies are met.
7. Expand tests: verify all base abilities are registered, verify permissions,
   verify the MCP adapter discovers them.
8. Generate and commit a `docs/reference/abilities-registry.md` that lists
   every ability, its category, schema, and capability — auto-generated from
   the registry (similar to the existing tool-status.txt).

**Deliverable:** All ~195 base tools discoverable via Abilities API + official MCP Adapter.

### Phase 2: Pro Tools Registration by Toolkit (4–6 weeks)

**Goal:** Pro tools registered, one toolkit at a time, with per-toolkit testing.

Proceed toolkit by toolkit in priority order (highest-value, most-used first):

| Priority | Toolkit | Tools | Notes |
|---|---|---|---|
| 1 | WooCommerce | ~15 | High demand, well-tested |
| 2 | CRM | ~70 | Largest toolkit, complex |
| 3 | Support Tickets | ~15 | Well-defined schemas |
| 4 | Content/Media Pro | ~40 | Extends base tools |
| 5 | Cloudways/DietPi | ~60 | Infrastructure tools |
| 6 | Finance/CRE | ~60 | Specialized domain |
| 7 | Social/Chat Channels | ~20 | External API tools |
| 8 | Shopify/FlowHub | ~15 | E-commerce verticals |
| 9 | Healthcare | ~10 | HIPAA-sensitive |
| 10+ | Remaining toolkits | ~500 | Fantasy sports, comic reader, etc. |

Each toolkit gets:
- A registration class in `addons/pro/includes/abilities/`.
- Tests verifying all tools + permissions.
- Manual MCP Adapter smoke test.

**Deliverable:** All ~1,000 tools discoverable (cumulative with Phase 1).

### Phase 3: Hardening & Output Schemas (ongoing, 2–3 months)

**Goal:** Rich output schemas, performance validated, full observability.

1. Add precise `output_schema` to high-value tools (get_post, create_post,
   search_content, woo_get_products, crm_get_leads, etc.).
2. Implement `WP_MCP_AI_Ability_Bridge` with execution hooks:
   - `wp_mcp_ai_before_ability_execute` — pre-execution (guardrails, rate limiting)
   - `wp_mcp_ai_after_ability_execute` — post-execution (logging, cost tracking)
3. Integration with Necessity Gate Layer J: check risk scores before allowing
   Ability execution.
4. Performance benchmarks published in `docs/developer/performance/`.
5. Documentation: developer guide for third-party tools to also register as
   Abilities through NV oOS.

### Phase 4: NV oOS MCP Endpoint as Superset (2–3 weeks)

**Goal:** NV oOS's own MCP endpoint gains awareness of the Abilities registry,
optionally delegating to it.

1. In `mcp_tools_list()`, add an optional merge mode: combine NV oOS tools
   with any additional Abilities registered by other plugins. This makes
   NV oOS the "one MCP endpoint to rule them all" for a WordPress site.
2. In `mcp_tools_call()`, add fallback: if a tool slug isn't in the NV oOS
   registry, delegate to `wp_get_ability( $name )->execute()`.
3. Add `include_core_abilities` parameter to `tools/list` so clients can
   discover non-NV-oOS abilities through the same endpoint.
4. Update admin settings: toggle for "Expose all WordPress Abilities via
   NV oOS MCP endpoint."

---

## 6. Risk Analysis & Mitigations

| Risk | Severity | Mitigation |
|---|---|---|
| **Performance regression** — 1,000 abilities slow down every request | Medium | Lazy registration, toolkit-flag guards, object caching, benchmark-gated rollout |
| **WP_Error compatibility** — Abilities API may not handle WP_Error returns natively | Medium | Test in Phase 0; if needed, wrap errors in structured arrays or exceptions |
| **Double-execution** — tool called via both Ability route and NV oOS MCP route concurrently | Low | Both paths are idempotent by design; the Necessity Gate already scores risk |
| **Permission mismatch** — Ability `permission_callback` stricter or looser than NV oOS tool's capability check | High | Use the exact same capability string; test every tool in Phase 1 |
| **Schema skew** — tool's `get_parameters_schema()` changes but Ability registration isn't updated | Low | Registration happens on every request via `wp_abilities_api_init`; schemas are read fresh from tool instances |
| **wp.org submission impact** — adding Abilities dependency might complicate the base plugin's wp.org review | Low | Feature-detect `function_exists('wp_register_ability')`; the plugin works without it |
| **MCP Adapter availability** — the official adapter might not be stable/available when we ship | Low | Our own MCP endpoint is unaffected; Abilities registration is additive |
| **Naming collisions** — another plugin uses `mcp-ai-wpoos/get-post` | Low | Our namespace `mcp-ai-wpoos/` matches our plugin slug and text domain, which is the recommended convention |

---

## 7. Success Metrics

| Metric | Current | Target (post-Phase 2) |
|---|---|---|
| Tools discoverable via official MCP Adapter | 0 | ~1,000 |
| Abilities registered with valid JSON Schema | 0 | ~1,000 |
| Performance overhead (wp_abilities_api_init) | N/A | <50ms for 500 tools |
| Ability permission tests passing | 0 | ~1,000 (1 per tool) |
| NV oOS MCP endpoint unaffected | ✅ | ✅ (backward compatible) |
| External MCP client can execute at least 1 tool | No | Yes (Phase 0) |

---

## 8. Open Questions

1. **Should the bridge execute callback re-use the tool instance or create a new one per call?**
   - *Recommendation:* Re-use the instance. Tools are stateless between calls.
     The `$context` array is built fresh each time from `get_current_user_id()`.
   - *Risk:* If a tool caches state internally (bug), it could leak across calls.
     Audit existing tools for accidental instance-state caching.

2. **How do we handle tools with dynamic schemas?**
   - Some tools (e.g., `get_post_type_schema`) generate their schema at runtime
     based on post type registration. The `input_schema` for the Ability should
     be the *static* envelope (action + post_type params); the dynamic part
     goes into the output.
   - *Recommendation:* The `input_schema` describes "how to call this tool."
     The `output_schema` can be generic for dynamic tools.

3. **Should the NV oOS `execute()` method be modified to accept a flag indicating "called via Ability"?**
   - This lets tools adapt behavior (e.g., different logging, different error
     format). Add `'ability_context' => true` to the `$context` array.
   - *Recommendation:* Yes, add the flag. It's minimal and provides flexibility.

4. **Do we need a `composer.json` dependency on `wordpress/abilities-api`?**
   - For pre-6.9 sites using Composer: yes, as a `suggest` (not `require`, since
     the plugin works without it).
   - *Recommendation:* Add to `suggest` in `composer.json` with a note in the
     installation docs.

5. **What happens when a tool is deactivated (toolkit flag off) but an Ability was already registered for it?**
   - `wp_register_ability()` is called on every `wp_abilities_api_init` hook.
     If the toolkit flag is off, the tool isn't registered → the Ability isn't
     registered → it disappears from the registry. This is correct behavior.
   - If a client cached the ability list, they'll get a "not found" on next
     call — which is the correct MCP behavior.

---

## 9. References

- [WordPress Abilities API Documentation](https://developer.wordpress.org/apis/abilities-api/)
- [Introducing the Abilities API (Nov 2025)](https://developer.wordpress.org/news/2025/11/introducing-the-wordpress-abilities-api/)
- [`wordpress/abilities-api` Composer package](https://packagist.org/packages/wordpress/abilities-api)
- [WordPress/abilities-api on GitHub](https://github.com/WordPress/abilities-api)
- [Official MCP Adapter (`wordpress/mcp-adapter`)](https://github.com/wordpress/mcp-adapter)
- [From Abilities to AI Agents: Introducing the WordPress MCP Adapter](https://developer.wordpress.org/news/2026/02/from-abilities-to-ai-agents-introducing-the-wordpress-mcp-adapter/)
- [Make WordPress Core AI team blog](https://make.wordpress.org/ai/)
- [Model Context Protocol Specification](https://modelcontextprotocol.io/)
- `.agents/skills/wp-abilities-api/SKILL.md` — NV oOS agent skill for Abilities API
- `.context/tool-registry.md` — NV oOS tool registry architecture
- `includes/interfaces/interface-wp-mcp-ai-tool.php` — Tool interface definition
- `includes/class-wp-mcp-ai-tool-registry.php` — Tool registry implementation
- `includes/class-wp-mcp-ai-rest-mcp-methods.php` — MCP JSON-RPC handler

---

## Appendix A: Example — `get_post` as an Ability

### Current NV oOS tool registration

```php
// includes/class-wp-mcp-ai-tool-registry.php, load_default_tools()
'WP_MCP_AI_Tool_Get_Post' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-post.php',
```

### Proposed Ability registration (generated by bridge)

```php
add_action( 'wp_abilities_api_init', 'wp_mcp_ai_register_tool_abilities' );

function wp_mcp_ai_register_tool_abilities(): void {
    if ( ! function_exists( 'wp_register_ability' ) ) {
        return; // Pre-WP 6.9
    }

    $registry = WP_MCP_AI_Tool_Registry::get_instance();
    $tool     = $registry->get_tool( 'get_post' );

    if ( ! $tool ) {
        return;
    }

    wp_register_ability(
        'mcp-ai-wpoos/get-post',
        array(
            'label'             => $tool->get_name(),
            'description'       => $tool->get_description(),
            'category'          => 'mcp-ai-wpoos-content',
            'input_schema'      => $tool->get_parameters_schema(),
            'output_schema'     => wp_mcp_ai_get_default_output_schema(),
            'execute_callback'  => function ( array $input = array() ) use ( $tool ) {
                $context = array(
                    'user_id'          => get_current_user_id(),
                    'ability_context'  => true,
                );
                $result = $tool->execute( $input, $context );
                if ( is_wp_error( $result ) ) {
                    return array(
                        'success' => false,
                        'message' => $result->get_error_message(),
                        'code'    => $result->get_error_code(),
                    );
                }
                return $result;
            },
            'permission_callback' => function () use ( $tool ) {
                return current_user_can( $tool->get_required_capability() );
            },
            'meta' => array(
                'show_in_rest' => true,
                'annotations'  => array(
                    'readOnlyHint'    => true,   // from capability_flags: 'read-only'
                    'destructiveHint' => false,
                ),
            ),
        )
    );
}
```

### What changes for the existing codebase

**Zero changes to `WP_MCP_AI_Tool_Get_Post`.** The tool class doesn't know or
care whether it's being called via NV oOS MCP, NV oOS REST, or WordPress Ability.
The bridge handles the mapping.

### What the AI agent sees (via MCP Adapter)

```json
{
  "name": "mcp-ai-wpoos/get-post",
  "description": "Retrieves a single WordPress post by ID, including its content, metadata, and taxonomy terms.",
  "inputSchema": {
    "type": "object",
    "properties": {
      "post_id": {
        "type": "integer",
        "description": "The ID of the post to retrieve.",
        "minimum": 1
      },
      "include_meta": {
        "type": "boolean",
        "description": "Whether to include post meta fields in the response. Defaults to true.",
        "default": true
      },
      "include_taxonomies": {
        "type": "boolean",
        "description": "Whether to include taxonomy terms assigned to the post. Defaults to true.",
        "default": true
      }
    },
    "required": ["post_id"],
    "additionalProperties": false
  },
  "annotations": {
    "readOnlyHint": true,
    "destructiveHint": false
  }
}
```

This is exactly the same tool definition the AI already sees — now it's just
served through the official WordPress MCP Adapter instead of (or in addition to)
the NV oOS MCP endpoint.

---

## Appendix B: Bridge Class Design (sketch)

```php
/**
 * Bridges an NV oOS tool to the WordPress Abilities API.
 *
 * @since 1.4.0
 */
class WP_MCP_AI_Ability_Bridge {

    /**
     * Register a tool as a WordPress Ability.
     *
     * @param WP_MCP_AI_Tool_Interface $tool      Tool instance.
     * @param string                   $category  Ability category slug.
     * @return bool Whether registration succeeded.
     */
    public static function register( WP_MCP_AI_Tool_Interface $tool, string $category ): bool {
        if ( ! function_exists( 'wp_register_ability' ) ) {
            return false;
        }

        $slug   = $tool->get_slug();
        $hyphen = str_replace( '_', '-', $slug );

        $input_schema = $tool->get_parameters_schema();
        if ( ! is_array( $input_schema ) ) {
            $input_schema = array( 'type' => 'object', 'properties' => array() );
        }

        $annotations = self::build_annotations( $tool );

        return (bool) wp_register_ability(
            "mcp-ai-wpoos/{$hyphen}",
            array(
                'label'              => $tool->get_name(),
                'description'        => $tool->get_description(),
                'category'           => $category,
                'input_schema'       => $input_schema,
                'output_schema'      => self::get_output_schema( $tool ),
                'execute_callback'   => self::make_execute_callback( $tool ),
                'permission_callback' => self::make_permission_callback( $tool ),
                'meta'               => array(
                    'show_in_rest' => true,
                    'annotations'  => $annotations,
                ),
            )
        );
    }

    /**
     * Build MCP annotations from the tool's capability flags.
     *
     * @param WP_MCP_AI_Tool_Interface $tool
     * @return array<string, bool>
     */
    protected static function build_annotations( WP_MCP_AI_Tool_Interface $tool ): array {
        $annotations = array();
        if ( $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
            $flags = $tool->get_capability_flags();
            if ( in_array( 'read-only', $flags, true ) ) {
                $annotations['readOnlyHint'] = true;
            }
            if ( in_array( 'irreversible', $flags, true ) ) {
                $annotations['destructiveHint'] = true;
            }
            if ( in_array( 'idempotent', $flags, true ) ) {
                $annotations['idempotentHint'] = true;
            }
            if ( in_array( 'long-running', $flags, true ) ) {
                $annotations['openWorldHint'] = true;
            }
        }
        return $annotations;
    }

    /**
     * Create a closure that executes the tool.
     *
     * @param WP_MCP_AI_Tool_Interface $tool
     * @return callable
     */
    protected static function make_execute_callback( WP_MCP_AI_Tool_Interface $tool ): callable {
        return static function ( array $input = array() ) use ( $tool ) {
            $context = array(
                'user_id'         => get_current_user_id(),
                'ability_context' => true,
            );
            $result = $tool->execute( $input, $context );
            if ( is_wp_error( $result ) ) {
                // Convert WP_Error to structured error array for Ability compatibility.
                return array(
                    'success' => false,
                    'message' => $result->get_error_message(),
                    'code'    => $result->get_error_code(),
                );
            }
            return $result;
        };
    }

    /**
     * Create a permission callback from the tool's required capability.
     *
     * @param WP_MCP_AI_Tool_Interface $tool
     * @return callable
     */
    protected static function make_permission_callback( WP_MCP_AI_Tool_Interface $tool ): callable {
        $cap = $tool->get_required_capability();
        return static function () use ( $cap ): bool {
            return current_user_can( $cap );
        };
    }

    /**
     * Get output schema for the tool.
     *
     * Falls back to the generic canonical envelope.
     *
     * @param WP_MCP_AI_Tool_Interface $tool
     * @return array
     */
    protected static function get_output_schema( WP_MCP_AI_Tool_Interface $tool ): array {
        if ( method_exists( $tool, 'get_output_schema' ) ) {
            $schema = $tool->get_output_schema();
            if ( is_array( $schema ) && ! empty( $schema ) ) {
                return $schema;
            }
        }
        return self::get_default_output_schema();
    }

    /**
     * Default output schema matching the canonical NV oOS tool envelope.
     *
     * @return array
     */
    public static function get_default_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'success' => array(
                    'type'        => 'boolean',
                    'description' => __( 'Whether the operation succeeded.', 'mcp-ai-wpoos' ),
                ),
                'message' => array(
                    'type'        => 'string',
                    'description' => __( 'Human-readable summary of the result.', 'mcp-ai-wpoos' ),
                ),
                'data'    => array(
                    'description' => __( 'Operation-specific result payload. Structure varies by tool.', 'mcp-ai-wpoos' ),
                ),
            ),
        );
    }
}
```
