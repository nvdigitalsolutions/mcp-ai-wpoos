# Abilities API Selective Adoption — Architectural Analysis & Recommendation

**Date:** 2026-08-06
**Status:** Draft
**Author:** AI Agent (architectural analysis)
**Related:** [`docs/project/ABILITIES_REGISTRATION_PLAN.md`](../ABILITIES_REGISTRATION_PLAN.md)
**Version:** 1.0

---

## 1. Executive Summary

WordPress 6.9+ ships the Abilities API (`wp_register_ability()`), a native mechanism for declaring machine-readable plugin operations with JSON Schema contracts, permission callbacks, and optional REST exposure. The NV oOS plugin already maintains a custom tool registry with ~1,000+ tools (Base+Pro), rich metadata (8 optional interfaces), and a mature execution pipeline (orchestrator, attention router, SSE streaming).

**The question:** Should these ~1,000+ tools be registered as WordPress Abilities?

**The recommendation:** **Yes, but surgically.** Register ~20–50 "discovery" and "meta" tools as Abilities for standardized AI agent discovery via the MCP adapter. Leave the ~1,000+ specialized tools in the custom registry where the orchestrator's rich metadata and execution context are essential. Do not attempt a wholesale migration.

---

## 2. Current State

### 2.1 Existing Abilities Usage

The plugin already uses the Abilities API in one place:

- **Embedded addon** (`addons/embedded/includes/abilities/class-nvoos-embedded-abilities.php`) registers 6 abilities:
  - `transcribe-audio`
  - `get-llm-backends`
  - `get-model-list`
  - `get-stt-config`
  - `analyze-image`
  - `ocr-document`

Registration is guarded with `function_exists('wp_register_ability')` for WP < 6.9 backward compatibility. This establishes a working pattern.

### 2.2 Custom Tool Registry

The main tool system uses a separate architecture:

| Component | Details |
|---|---|
| **Interface** | `WP_MCP_AI_Tool_Interface` — 7 required methods |
| **Registry** | `WP_MCP_AI_Tool_Registry` singleton, hook-based registration (`wp_mcp_ai_register_tools`) |
| **Optional metadata** | 8 interfaces: `Capability_Flags_Interface`, `Model_Requirements_Interface`, `Rules_Interface`, `Flow_Stage_Interface`, `Data_Contract_Interface`, `Context_Restrictions_Interface`, `Safety_Profile_Interface`, `Shortcuts_Interface` |
| **Execution** | `execute($args, $context)` — `$context` carries `user_id`, `assistant_id`, `channel_id`, etc. |
| **REST** | Custom endpoints at `/wp-json/mcp-ai/v1/chat`, `/tools`, `/sse` with streaming, multi-auth |

---

## 3. Mapping Between the Two Systems

| Tool Interface | → | Ability Contract | Notes |
|---|---|---|---|
| `get_slug()` (`get_post`) | → | identifier (`nvoos/get-post`) | Needs `nvoos/` namespace prefix |
| `get_name()` | → | `label` | 1:1 |
| `get_description()` | → | `description` | 1:1 |
| `get_parameters_schema()` | → | `input_schema` | 1:1 mapping |
| `get_required_capability()` | → | `permission_callback` | Tool returns string; ability wants callable |
| `execute($args, $context)` | → | `execute_callback($input)` | **No `$context` param in Abilities API** |
| ✗ | → | `output_schema` | Tool system has no output schema concept |
| Capability flags, rules, etc. | → | ✗ | No equivalent in Abilities API |

---

## 4. Case FOR Registering Tools as Abilities

1. **AI Agent Discovery Is Core Purpose.** This plugin *is* an AI assistant framework. Every tool exists to be discovered and invoked by AI agents. The Abilities API's MCP adapter makes every registered Ability automatically discoverable by Claude, ChatGPT, Copilot, and any MCP-compatible agent — this is the plugin's value proposition, now standardized by WordPress core.

2. **Built-in REST Exposure.** Setting `meta.show_in_rest = true` gives every tool a REST endpoint at `/wp-json/wp-abilities/v1/abilities/{namespace}/{ability}/run` **for free** — no custom `register_rest_route` code, no permission plumbing, no schema duplication.

3. **Reduced Infrastructure Maintenance.** The custom tool registry, interface hierarchy, envelope traits, and REST routing are ~3,500+ lines of custom code that partially duplicate what `wp_register_ability()` now does natively. Leaning into the core API reduces surface area to maintain over time.

4. **Standardized Validation.** JSON Schema validation is built into `WP_Ability::execute()` — inputs are validated before the callback runs, and outputs can be validated against `output_schema`. Today, each tool manually validates its own inputs.

5. **Precedent Already Exists.** The embedded addon already uses it successfully. The pattern is proven in the codebase.

---

## 5. Case AGAINST Full Migration (Right Now)

1. **WordPress 6.9+ Requirement.** The plugin currently requires WordPress 6.0+. Abilities API only ships in WP 6.9+. Full migration would require:
   - Bundling `wordpress/abilities-api` Composer package or feature plugin
   - A new dependency for a surface that already works
   - Blocking WP < 6.9 users from updates

2. **Scale — ~1,000+ Tools.** Each tool needs:
   - A unique `namespace/ability-name` identifier
   - Category registration (categories must exist before abilities)
   - An `output_schema` (optional but best practice)
   - A `permission_callback` callable wrapper around `get_required_capability()`
   - A `show_in_rest` decision
   
   Even with batch-registration helpers, this is a massive refactor.

3. **Missing Metadata the Orchestrator Depends On.** The 8 optional interfaces provide:
   - Capability flags (`read-only`, `write`, `async`, `cacheable`, `external-api`)
   - Data contracts (`produces`/`consumes`)
   - Flow stage eligibility
   - Model requirements
   - Safety profiles
   - Execution rules

   None of these have equivalents in the Abilities API. The orchestrator, attention router, and agentic loop use this metadata for routing, filtering, and safety decisions.

4. **No `$context` Parameter.** Every tool's `execute()` receives `$context` (with `user_id`, `assistant_id`, `channel_id`, etc.) that flows through the agentic loop. The Abilities API's `execute_callback` only receives validated input. This gap requires a workaround (globals, request-scoped singletons) that adds complexity.

5. **Dual Registration Risk.** During any transition, every tool would be registered in *both* systems — custom registry (orchestrator, chat UI, tool presets) AND Abilities registry (MCP adapter). If registries drift, confusing behavior results.

6. **Custom REST Already Exists.** The plugin's REST API is mature and well-tested with streaming, nonce auth, bearer tokens, guest tokens, etc. The Abilities REST endpoints don't support streaming or custom auth schemes.

---

## 6. Recommended Strategy: Selective, Not Wholesale

**Do not migrate all ~1,000+ tools.** Instead, follow the pattern the embedded addon already established.

### Phase 1: Register High-Value Discovery Tools as Abilities

Register tools that external AI agents would want to discover *about* the WordPress site before initiating a chat session — "meta" tools that describe the system's capabilities:

| Category | Example Abilities | Audience |
|---|---|---|
| `nvoos-discovery` | `list-tools`, `get-tool-schema`, `get-providers` | External MCP agents asking "What can this site do?" |
| `nvoos-site` | `get-site-info`, `get-post-types`, `get-taxonomies`, `get-themes`, `get-plugins` | Site introspection |
| `nvoos-content` | `get-post`, `search-posts`, `get-comments` (read-only subset) | Content retrieval |
| `nvoos-media` | `search-media`, `get-attachment`, `optimize-image` | Media operations |

For each, create a thin adapter that:
1. Registers the category on `wp_abilities_api_categories_init`
2. Registers the ability on `wp_abilities_api_init`, guarded by `function_exists`
3. Wraps the existing tool's `execute()` call, mapping `$context` from request state
4. Sets `meta.mcp.public = true` for MCP adapter discovery
5. Provides `output_schema`

### Phase 2: Add Optional `WP_MCP_AI_Tool_Ability_Interface`

For tools that *want* to declare themselves as abilities, add an optional interface:

```php
interface WP_MCP_AI_Tool_Ability_Interface {
    public function get_ability_identifier(): string;  // e.g. 'nvoos/get-post'
    public function get_ability_category(): string;     // e.g. 'nvoos-content'
    public function get_output_schema(): array;
    public function is_public_ability(): bool;           // meta.mcp.public
}
```

A single registration pass walks the tool registry and registers any tool implementing this interface as an Ability — zero boilerplate per tool.

### Phase 3: Long-Term (WP 6.9+ Becomes Minimum)

When the plugin eventually drops support for WP < 6.9, evaluate whether Abilities should become the *primary* registration mechanism with the custom registry as a backward-compatibility/adapter layer. At that point, the tool interface and registry could wrap the core Abilities API rather than duplicating it.

---

## 7. What NOT to Do

- ❌ **Don't register every tool as an Ability "just because."** The orchestrator, chat UI, SSE streaming, tool presets, attention router, and agentic loop all depend on the custom registry and its rich metadata. Replacing that wholesale would be a months-long effort with significant regression risk.

- ❌ **Don't fork the codebase into "Ability tools" vs "Registry tools."** That creates two classes of tools and confuses tool authors.

- ❌ **Don't use `__return_true` as a permission callback** for any state-changing tool — the `wp-security-audit` skill flags this as the most common plugin vulnerability.

---

## 8. Relationship to `ABILITIES_REGISTRATION_PLAN.md`

The existing [`docs/project/ABILITIES_REGISTRATION_PLAN.md`](../ABILITIES_REGISTRATION_PLAN.md) describes a comprehensive plan to register **all ~1,000 tools** as Abilities with a bridge/auto-registration architecture, phased over several months. This proposal recommends a more conservative, selective approach that:

- Registers ~20–50 high-value discovery tools first
- Preserves the custom registry for the orchestrator's rich metadata needs
- Uses the optional interface pattern for opt-in per-tool Ability registration
- Defers wholesale migration until WP 6.9+ is the minimum supported version

**These documents are complementary, not contradictory.** The full plan represents the long-term vision; this proposal represents a risk-managed initial step that validates the approach before committing to the full scope.

---

## 9. Risk Analysis

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Dual-registry drift | Medium | High | Single registration pass; optional interface pattern |
| WP < 6.9 users locked out | Low (guarded) | High | `function_exists` guard on all Ability registration |
| Missing `$context` breaks tools | Medium | Medium | Thin adapter layer stashes context in request-scoped state |
| Orchestrator can't find ability-registered tools | Low | Medium | Custom registry remains the source of truth; abilities are a secondary index |

---

## 10. Success Metrics

- [ ] 20–50 discovery/meta tools registered as Abilities with public MCP annotations
- [ ] External MCP agents (Claude, ChatGPT, Copilot) can discover and invoke these abilities
- [ ] Zero regressions in existing orchestrator, chat UI, or REST endpoints
- [ ] `function_exists('wp_register_ability')` guard passes on WP < 6.9
- [ ] Embedded addon pattern is generalized into a reusable bridge trait/class

---

## 11. Open Questions

1. **Namespace convention:** Should the namespace be `nvoos` (matching plugin branding) or `nv-oos` (matching REST namespace `mcp-ai`)?
2. **Category granularity:** Is `nvoos-content`, `nvoos-media`, `nvoos-site` the right split, or should categories mirror existing toolkit groupings?
3. **Context bridging:** What is the cleanest way to make `$context` (user_id, assistant_id, channel_id) available to Ability `execute_callback` without globals?
4. **Output schema generation:** Can `output_schema` be auto-generated from existing tool return types, or must each tool author it manually?
5. **Timeline:** When does the project expect to drop WP < 6.9 support, making Phase 3 viable?

---

## 12. References

- [`docs/project/ABILITIES_REGISTRATION_PLAN.md`](../ABILITIES_REGISTRATION_PLAN.md) — Comprehensive plan for registering all ~1,000 tools
- [`.agents/skills/wp-abilities-api/SKILL.md`](../../.agents/skills/wp-abilities-api/SKILL.md) — WordPress Abilities API coding skill
- [`addons/embedded/includes/abilities/class-nvoos-embedded-abilities.php`](../../addons/embedded/includes/abilities/class-nvoos-embedded-abilities.php) — Existing Abilities registration pattern
- WordPress Abilities API feature plugin / WP 6.9+ core

---

*Next step: Team review and decision on whether to proceed with Phase 1 scope (which discovery tools to register first).*
