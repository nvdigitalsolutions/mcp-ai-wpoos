# NV oOS (Open Operator System) — Claude Code Context

> This file is loaded every turn by Claude Code. Keep it focused and actionable.
> Last reviewed: **September 2, 2026** · Version: **2.20**

### Related Files

| File | Purpose |
|------|---------|
| [`MAINTAINER_MAP.md`](MAINTAINER_MAP.md) | Boot flow, directory map, build commands, Pro vs Base, canonical docs |
| [`AGENTS.md`](AGENTS.md) | Full AI agent inventory, BMAD roles, context-loading strategy |
| [`CONTRIBUTING.md`](CONTRIBUTING.md) | PR process, quality gates, GSD × BMAD methodology |
| [`CODEOWNERS`](CODEOWNERS) | Auto-review assignment per path |
| [`.github/copilot-instructions.md`](.github/copilot-instructions.md) | GitHub Copilot repo-level context |

---

## What This Is

NV oOS is a **WordPress plugin** providing an AI Assistant framework with ~1,565 tools (~303 base + ~1,262 Pro; live count via `WP_MCP_AI_Tool_Registry::get_tools()`), **33 per-toolkit MCP JSON-RPC servers** (including Phase 8: Pro Scheduler, FlowHub, Shopify Sync, EZuite), **OAuth 2.0 MCP authentication** (PKCE, hierarchical scopes, browser-based login), MCP protocol support, multi-provider AI (OpenAI, Gemini, Anthropic, Ollama, LM Studio, DeepSeek, OpenRouter, DigitalOcean Serverless Inference, HuggingFace, NVIDIA, Baseten, Kimi, Cloudflare), multi-provider voice/realtime (OpenAI Realtime, Gemini Live), ACP (Agent Client Protocol), Layer I jailbreak guardrails, Layer J Necessity Gate (irreversibility-weighted safety profiles), and Server-Sent Events streaming.

## PHP Compatibility — Critical

| Distribution | Minimum PHP | Location |
|-------------|-------------|----------|
| **Base plugin** (`includes/`, root `*.php`) | **PHP 7.4+** | WordPress.org compatible |
| **Pro addon** (`addons/pro/`) | **PHP 8.1+** | Enums, fibers, readonly, named args OK |

**Base plugin:** No enums, no `readonly`, no union types, no named arguments, no match expressions.

## Naming Conventions

| Type | Pattern | Example |
|------|---------|---------|
| PHP Classes | `WP_MCP_AI_{Feature}_{Component}` | `WP_MCP_AI_Tool_Manage_Redirects` |
| Tool Classes | `WP_MCP_AI_Tool_{Name}` | `WP_MCP_AI_Tool_Web_Search` |
| Functions | `wp_mcp_ai_{name}()` | `wp_mcp_ai_get_assistant()` |
| Action Hooks | `wp_mcp_ai_{name}` | `wp_mcp_ai_before_tool_execution` |
| Filter Hooks | `wp_mcp_ai_{name}` | `wp_mcp_ai_tool_output` |
| Options | `wp_mcp_ai_{name}` | `wp_mcp_ai_settings` |
| CPT Slugs | `mcp_ai_{type}` | `mcp_ai_assistant` |
| Nonces | `wp_mcp_ai_{context}_{action}` | `wp_mcp_ai_assistant_save` |

## File Structure

```
mcp-ai-wpoos.php                        ← Plugin entry point
mcp-ai-wpoos-base.php                   ← Base-only entry point
includes/
├── bootstrap/                          ← Boot: constants → autoload → hooks → loader
├── class-wp-mcp-ai-plugin.php          ← Main singleton + DI container
├── class-wp-mcp-ai-rest.php            ← Core REST API + agentic loop
├── class-wp-mcp-ai-tool-registry.php   ← Tool registry singleton (~1,565 tools total; live count is authoritative)
├── class-wp-mcp-ai-transcript-retention.php ← Chat transcript retention (base)
├── rest/                                ← REST controllers incl. class-wp-mcp-ai-sse-session-store.php (legacy MCP HTTP+SSE session store, v1.1.55)
├── security/                           ← Security infrastructure (7 classes: request guard, posture, destructive ops gate, URL guard, concurrency guard, cost tracker, API key store)
├── tools/                              ← base tool implementations (~303 classes; live count is authoritative)
│   ├── okf/                            ← OKF knowledge tools (10 tools)
├── services/                           ← 30+ service classes
├── admin/                              ← WordPress admin UI
├── blueprints/                         ← Unified blueprint installer + import tools
├── slash-commands/                     ← /help, /ship, /compact, /context, etc.
├── integrations/                       ← JetEngine, Elementor, Auth0
├── a2a/                                ← Agent-to-Agent protocol
├── okf/                                ← OKF engine (parser, reader, writer)
├── harness/                            ← LLM Harnessing subsystem (Layers A–H)
└── interfaces/                         ← PHP interfaces
lib/core/                               ← Framework-agnostic AI engine (nvoos/core, PHP 8.1+)
├── src/Domain/Contract/                ← 32 domain interfaces (ports)
├── src/Application/                    ← ChatOrchestrator, ProviderRouter, ToolRegistry, SkillRegistry
├── src/Infrastructure/                 ← 12 AI provider clients, SSE handler, cost calculator
└── src/Tool/                           ← 109 framework-agnostic tool classes
addons/pro/
├── mcp-ai-wpoos-pro.php                ← Pro entry (auto-loaded, no WP plugin header)
├── includes/
│   ├── class-wp-mcp-ai-memory-retention.php ← Agent memory retention (Pro)
│   ├── tools/                          ← ~800+ pro tools
│   │   ├── cloudways/                  ← Cloudways Toolkit (60 tools + API v2 client)
│   │   ├── crm/                        ← CRM Toolkit (70+ tools, 5 phases)
│   │   ├── dietpi/                     ← DietPi Pro Toolkit (19+ tools, 3 phases)
│   │   └── ...
│   ├── mcp-servers/                    ← Per-toolkit MCP JSON-RPC servers (33 total)
│   │   ├── servers/                    ← Server implementations (Pro Scheduler, FlowHub, Shopify, EZuite, etc.)
│   │   ├── class-wp-mcp-ai-oauth-server.php  ← OAuth 2.0 Authorization Server (MCP Auth Spec)
│   │   ├── class-wp-mcp-ai-oauth-rest.php    ← OAuth REST endpoints
│   │   └── trait-wp-mcp-ai-scheduled-toolkit-server.php
│   ├── cloudways/                      ← Cloudways API v2 OAuth client + helpers
│   └── ...                             ← Pro admin, REST, services
addons/
├── fleet-operator/                      ← Hermes Fleet Operator addon (scoped op_ credentials, MCP tools/list scoping, WP-CLI)
├── media-worker/                        ← Media Worker sidecar (v3.0.0, multi-tenant + security-hardened)
├── librechat/                          ← LibreChat addon (code interpreter, speech)
├── funiq-bridge/                       ← Funiq Bridge addon (v1.0.0)
├── checkout-api/                        ← Checkout API addon (v0.1.0, vendor-side Stripe checkout/licensing)
├── schedule-anything/                  ← Schedule Anything SaaS platform
└── ...                                 ← 27 addons total
```

## Security — Non-Negotiable

Every code change must:
- **Sanitize input**: `sanitize_text_field()`, `absint()`, `sanitize_email()`, `wp_kses_post()`
- **Escape output**: `esc_html()`, `esc_attr()`, `esc_url()`, `wp_json_encode()`
- **Check capabilities**: `current_user_can()` before every privileged operation
- **Verify nonces**: `check_ajax_referer()` or `wp_verify_nonce()` for state changes
- **ABSPATH guard**: Every non-root PHP file starts with `if ( ! defined( 'ABSPATH' ) ) { exit; }`
- **Prepared queries**: Always `$wpdb->prepare()` — never string-concatenate SQL
- **HMAC-signed policy tokens**: When server-controlled config crosses a client boundary (e.g. shortcode attrs via JS AJAX), sign it with `wp_hash()` + short expiry. Client sends opaque token; server verifies HMAC and expiry. Canonical impl in `class-wp-mcp-ai-professional-selector-shortcode.php`.
- **Path traversal prevention**: Before recursive filesystem ops, `realpath()` the target and validate containment within the allowed base directory via `strpos()`. Abort + log security event if containment fails.
- **Admin-post CSRF**: `admin-post.php` endpoints must call `check_admin_referer()` at entry with a per-toolkit nonce action.

## Third-Party Attribution

When a file is **derived from**, **heavily inspired by**, or **wraps** an upstream open-source project, add `@link` and `@credit` tags to the file-level PHPDoc:

```php
/**
 * Class summary.
 *
 * @link    <upstream URL>
 * @credit  <upstream project name> by <author> (<license>)
 * @package WP_MCP_AI
 */
```

The full repo-wide attribution index — every Composer package, npm dependency, vendored asset, bundled skill, font, and methodology — lives in [`CREDITS.md`](CREDITS.md) at the repo root. When you add or update a dependency, also update `CREDITS.md`, `docs/project/THIRD_PARTY_ASSETS.md` (for JS), and the relevant per-addon `README.md` Credits section. For Pro npm packages, the `get_package_definitions()` array in `addons/pro/includes/admin/class-wp-mcp-ai-pro-packages-settings-page.php` powers the in-product Credits surface — keep its `homepage` / `license` / `copyright` fields in sync.

## Tool Implementation Pattern

```php
class WP_MCP_AI_Tool_Example extends WP_MCP_AI_Tool_Base {
    public function get_slug() { return 'example_tool'; }
    public function get_definition() {
        return array(
            'name'                => 'Example Tool',
            'description'         => 'LLM-facing description.',
            'required_capability' => 'edit_posts',
            'parameters'          => array(
                'type'       => 'object',
                'properties' => array( /* ... */ ),
                'required'   => array( 'action' ),
            ),
        );
    }
    public function execute( $arguments, $context ) {
        if ( ! current_user_can( $this->get_required_capability() ) ) {
            return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos' ) );
        }
        // Sanitize, implement, return array or WP_Error
    }
}
```

## Tool Return Format — Canonical Envelope

Every tool's `execute()` method returns **exactly one of two shapes**. This is the canonical envelope enforced repo-wide (see [Unix Theory Compliance Proposal §2.2](docs/project/proposals/UNIX_THEORY_COMPLIANCE_ENHANCEMENT_PROPOSAL.md#22-canonical-return-envelope)).

```php
// SUCCESS — array with success/message/data:
return array(
    'success' => true,
    'message' => __( 'Done.', 'mcp-ai-wpoos' ),  // Translated, human-readable.
    'data'    => $results,                        // Serialisable via wp_json_encode().
);

// FAILURE — ALWAYS WP_Error, never an array with 'success' => false:
return new WP_Error( 'error_code', __( 'Error message.', 'mcp-ai-wpoos' ), $extra_data );
```

**Rules:**
- ✅ Success returns an array with at minimum `success => true` and `message`. `data` is the only pipeable payload — keep it serialisable.
- ✅ Failure **must** use `WP_Error`. The agentic loop already normalises `WP_Error` correctly for the model.
- ❌ Do **not** return `array( 'success' => false, 'message' => ... )` for errors. It defeats observability subscribers and produces inconsistent reasoning signals for the LLM.
- 🛠️ For success responses, compose [`trait-wp-mcp-ai-tool-envelope.php::format_success_response()`](includes/tools/trait-wp-mcp-ai-tool-envelope.php) — `use WP_MCP_AI_Tool_Envelope;` and call `$this->format_success_response( $message, $data )`. Tools that also need the broader chat-response helpers (`format_chat_response`, `format_collection_response`, `format_empty_result_response`, `ensure_response_message`) should `use WP_MCP_AI_Tool_Chat_Response;` instead — it composes the envelope trait, so `format_success_response()` is identical from both.

## Tool Sanitisation — Two-Gate Rule

Every tool's `execute()` method must satisfy two gates (Unix Theory Compliance §2.6, Phase P6):

- **Gate 1 — Sanitize at entry:** all `$arguments[...]` values are sanitised at the top of `execute()` **before** any business logic (use `absint`, `sanitize_text_field`, `sanitize_key`, `wp_kses_post`, `esc_url_raw`, etc.).
- **Gate 2 — Escape at exit:** every value returned in the canonical-envelope `data` array — and every value inserted into a database, redirect URL, response header, or rendered HTML — is escaped/prepared (use `esc_html`, `esc_attr`, `esc_url`, `wp_json_encode`, `$wpdb->prepare()` with placeholders).

The repo enforces the two highest-risk Gate-1 violations via the PHPCS sniff `WPMCPAI.Tools.SanitizeAtEntry` (severity 5 — visible under `composer run lint`, silent under `composer run lint:base`). The sniff warns when `$arguments[...]` is interpolated into a double-quoted string or concatenated with `.` outside a recognised safe wrapper. Full sanitiser / escaper allow-list and rationale: [`docs/project/proposals/audits/P6-sanitize-escape-codification-2026-05.md`](docs/project/proposals/audits/P6-sanitize-escape-codification-2026-05.md).

## Base vs Pro Decision

- **Base:** Core WordPress functionality, no third-party APIs, useful to any site
- **Pro:** Paid APIs (Shopify, Upwork), optional plugins (JetEngine, WooCommerce), healthcare, enterprise
- **Constants:** `WP_MCP_AI_BASE_VERSION = true` (~303 base tool classes) or `false` (~1,565 total; live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative)
- **Guard:** `if ( ! defined( 'WP_MCP_AI_BASE_VERSION' ) || ! WP_MCP_AI_BASE_VERSION ) { /* pro code */ }`

## Key Architecture Patterns

### Lifecycle Hooks (60+)

The plugin fires 60+ hooks. Key ones:
- `wp_mcp_ai_before_tool_execution` / `wp_mcp_ai_after_tool_execution` — tool lifecycle
- `wp_mcp_ai_before_chat_request` / `wp_mcp_ai_after_chat_response` — chat lifecycle
- `wp_mcp_ai_register_tools` — tool registration
- `wp_mcp_ai_cost_calculated` — token cost tracking
- `wp_mcp_ai_slash_commands_initialized` — slash command system ready

Full reference: `docs/reference/hooks/hooks-reference.md`

### Agentic Loop (REST API)

In `class-wp-mcp-ai-rest.php` (lines ~2578-2950):
- Iterates tool calls from AI response
- Executes tools sequentially
- Validates TPM budget between iterations
- Handles async pending results
- Strips orphaned tool calls before response
- Filterable max iterations: `wp_mcp_ai_max_agentic_iterations`

### MCP Endpoint (JSON-RPC 2.0)

`POST /mcp-ai/v1/mcp` is the MCP JSON-RPC 2.0 endpoint (Streamable HTTP). Coding rules (v1.1.55+):
- **JSON-RPC errors return HTTP 200** with the `{"jsonrpc","id","error"}` envelope (`mcp_error_response()`). Client SDKs drop non-2xx bodies, so a 500 turns a tool error into a silent agent hang. Auth/permission failures and pre-dispatch guards keep real HTTP statuses (401/403/429). Filter: `wp_mcp_ai_mcp_error_http_status`.
- **GET/HEAD must not consume the request rate-limit budget** (`check_rate_limit()` exempts them) — discovery/SSE probe retry loops would otherwise exhaust the hourly quota.
- **Tool rate limiting** is settings-driven (`tool_rate_limit_max` / `tool_rate_limit_window` / `tool_rate_limit_exempt_tokens`) and exempts credential-token traffic by default; constants remain fallbacks.
- **Legacy HTTP+SSE transport** (gated by `WP_MCP_AI_LEGACY_SSE_ENABLED`): SSE-only `Accept` or `?stream=true` on `GET /mcp` starts a credential-bound session via `WP_MCP_AI_SSE_Session_Store`; `POST` with `session_id` returns 202 and the response is delivered as `event: message` on the GET stream. `GET /sse` is a directory stream, never an MCP message channel.
- **Async tool waits stay under proxy timeouts** — `mcp_wait_for_async_tool()` defaults to ~45s (15 polls × 3s) and kicks stuck jobs inline. Do not raise past ~30 polls on Cloudflare-proxied sites (524 at ~100s).

Reference: `docs/developer/implementation-plan-mcp-agent-compat.md`, `docs/developer/legacy-sse-transport-plan.md`.

### Tool Registry

- Singleton: `WP_MCP_AI_Tool_Registry::get_instance()`
- Hook-based: `do_action( 'wp_mcp_ai_register_tools', $registry )`
- Optional interfaces: `WP_MCP_AI_Tool_Capability_Flags_Interface` (read-only, write, async, etc.), `WP_MCP_AI_Tool_Data_Contract_Interface` (`produces`/`consumes` payload hints — surfaced to the model as a `[Data contract: …]` description suffix)
- Capability flags: `'read-only'`, `'write'`, `'state-changing'`, `'cacheable'`, `'external-api'`

### Orchestration Phases (1–7)

All seven orchestration phases are active as of v1.1.15. The Unix Theory Compliance Phases P0–P7 (canonical return envelope + sniff, capability-fence audit, data-contract interface, tool-lifecycle descriptor, back-compat alias infrastructure, sanitize-at-entry sniff, folder README convention) landed across v1.1.16–v1.1.19 on top of these. Key components:
- **HITL** (`WP_MCP_AI_Approval_Queue`, CPT `mcp_ai_approval`, REST `/mcp-ai/v1/approvals/*`)
- **Prompt Injection Detector** (`WP_MCP_AI_Prompt_Injection_Detector`, harness profile key `injection_detector.enabled`, action `wp_mcp_ai_prompt_injection_detected`)
- **OTel** — OTLP endpoint + token configurable under **Tools → Connections**
- **Observability dashboard** — surfaced under the **Orchestration** tab
- **Sub-agents** (`WP_MCP_AI_Sub_Agent_Dispatcher`), **durable runs** (`WP_MCP_AI_Durable_Run_Store`), **triggers** (`WP_MCP_AI_Workflow_Trigger_CPT`)
- **JetEngine CCT init priority** — all CCT bootstraps must use `init` at priority 11+ to avoid racing JetEngine's table-cache hydration (priorities 1–10)
- Pro: `WP_MCP_AI_Vector_Store_Adapter` (openai/pgvector/qdrant), `WP_MCP_AI_Team_Budget_Manager`

### DSpark Execution Optimizations (v1.6.0)

Inspired by DeepSeek V4 DSpark confidence-scheduled speculative decoding, five services collaborate to reduce latency and API cost:

- **Speculative Tool Execution** (`WP_MCP_AI_Speculative_Tool_Executor`) — Drafts tool chains ahead of execution, verifies batch-style, stops at first rejection. Block size 2–6 tools; pauses when historical acceptance rate drops below threshold.
- **Orchestration Depth Scheduler** (`WP_MCP_AI_Orchestration_Depth_Scheduler`) — Graduated verification depth (Deep → Standard → Shallow → Minimal) based on system capacity and prediction confidence. Filter: `wp_mcp_ai_orchestration_depth_tier`.
- **Hybrid Plan Generator** (`WP_MCP_AI_Hybrid_Plan_Generator`) — Three-stage pipeline: parallel fan-out to planning agents → lightweight sequential merge → dependency-graph construction. Action: `wp_mcp_ai_hybrid_plan_generated`.
- **Tiered Model Routing** (`WP_MCP_AI_Language_Model_Router::route_with_tier()`) — Routes to draft (cheap/fast) or verification (capable) models per task. DRAFT tier: gpt-4o-mini, gemini-2.0-flash, claude-3-haiku. VERIFICATION tier: gpt-4o, gemini-2.5-pro, claude-3-opus. Filter: `wp_mcp_ai_tiered_model_selection`.
- **Chain Acceptance Tracker** (`WP_MCP_AI_Tool_Chain_Acceptance_Tracker`) — Records predicted-vs-actual tool usage; feeds weighted data back to the predictor. Stores rolling history in option `wp_mcp_ai_chain_acceptance_metrics`.

**Admin surface:** All five features are toggleable under **Orchestration → Settings → DSpark Optimizations**. Threshold sliders live under **Orchestration → Thresholds → Depth Scheduler Thresholds**. A real-time **DSpark Efficiency** dashboard tab (conditionally shown) exposes hit-rate gauges, tier distribution bars, cost-savings cards, merge-confidence charts, and acceptance insights. A "DSpark Speculative" orchestration preset ships all five features pre-enabled.

**Hooks:**
- Filter `wp_mcp_ai_orchestration_depth_tier` — overrides depth tier selection
- Filter `wp_mcp_ai_tiered_model_selection` — overrides tiered model routing decision
- Action `wp_mcp_ai_hybrid_plan_generated` — fires when a hybrid plan completes

**Data collectors** (`WP_MCP_AI_DSpark_Hooks`) — light-weight filters that increment tier counters (option `wp_mcp_ai_depth_tier_counts`) and estimate routing cost savings (transient `wp_mcp_ai_routing_cost_data`) for the admin dashboard without per-request overhead.

### Provider Clients

Fifteen providers supported:
- **OpenRouter** (`WP_MCP_AI_OpenRouter_Client`, v1.1.15) — unified gateway for OpenAI, Anthropic, Google, Meta, Mistral, and others via one API key
- **DigitalOcean Serverless Inference** (`WP_MCP_AI_DigitalOcean_Client`, v1.1.17) — OpenAI-compatible API at `https://inference.do-ai.run/v1`; Llama 3.3, DeepSeek-R1 distill, gpt-oss, plus native `/embeddings`
- **DeepSeek** (`WP_MCP_AI_DeepSeek_Client`, v1.1.15) — `reasoning_content` / `<think>…</think>` passthrough
- **LM Studio** (v1.1.15) — native cURL SSE streaming; native `/api/v0` opt-in; embeddings; bearer-token auth; capability-aware tool gating
- **Kimi (Moonshot AI)** (`WP_MCP_AI_Kimi_Client`, v1.1.19) — OpenAI-compatible API at `https://api.moonshot.ai/v1`; kimi-k2.6 (256K context, default), kimi-k2-thinking (CoT), moonshot-v1-8k/-32k/-128k

### Voice / Realtime API

Multi-provider voice support via a pluggable voice controller (`WP_MCP_AI_REST_Voice_Controller`, `includes/rest/class-wp-mcp-ai-rest-voice-controller.php`). Two providers registered by default plus video generation:
- **OpenAI Realtime** (`WP_MCP_AI_OpenAI_Realtime_Client`) — WebRTC/S2S realtime voice via OpenAI's Realtime API.
- **Gemini Live** (`WP_MCP_AI_Gemini_Live_Client`) — realtime voice via Google's Gemini Live API.
- **Veo 2.0 deprecated (mid-2026).** Google deprecated Veo 2.0 and may restrict Veo 3.1. The replacement is **Gemini Omni Flash** (`gemini-omni-flash`) — 10s duration, native audio, multi-turn editing. Deprecation detection prevents wasteful 404 fallback loops.

Provider registration pattern: `$voice_controller->register_provider( new ProviderClient() )`. REST routes registered under `mcp-ai/v1/voice/*`.

### Slash Commands

Pattern: class with `execute( $args, $flags, $context )` returning string/array/WP_Error.
Registration via `$handler->register( 'name', array( 'handler' => ..., 'capability' => ..., 'aliases' => ... ) )`.
Located in `includes/slash-commands/commands/`.

### Paper Store

Flat-file JSON document store (`includes/paper-store/`) for structured content management with collection-based organization. Provides persistent, queryable storage for AI agents with full CRUD + search + import/export via 8 MCP tools. Records carry structured metadata (title, description, tags, status, body, meta) stored as JSON files under `wp-content/uploads/mcp-ai-paper-store/`.

- **8 MCP tools**: `paper_store_list`, `paper_store_read`, `paper_store_search`, `paper_store_write`, `paper_store_update`, `paper_store_delete`, `paper_store_import`, `paper_store_export`. All 8 tools accept an optional `connection_id` parameter to proxy operations through Remote Site Manager for cross-site Paper Store federation.
- **REST API** (`WP_MCP_AI_Paper_Store_REST`, 697 lines): Full CRUD controller at `mcp-ai/v1/paper-store` for remote site access — list collections, search, CRUD per collection, import/export endpoints. Requires standard NV oOS auth (nonce, bearer, or guest token).
- **Remote trait** (`WP_MCP_AI_Paper_Store_Remote`, 96 lines): Handles HTTP dispatch through Remote Site Manager when `connection_id` is provided. Connection validation, REST endpoint construction, response normalization, error passthrough.
- **`list_mcp_tools` discovery tool** (234 lines): Enables AI agent self-discovery of all available MCP tools. Returns tool names, descriptions, and JSON Schema parameter definitions. Filterable by toolkit namespace and search term.
- **Pro addon** (`addons/pro/includes/paper-store/`) adds a Markdown+YAML driver (via `symfony/yaml`), Git sync (`WP_MCP_AI_Pro_Paper_Store_Git_Sync`), admin UI, and import/export tools.
- **Remote Sites integration**: `remote_wp_connection` tool lists Paper Store actions as available operations on remote sites. CPT auto-discovery ensures `paper_store` post type appears in remote connection access controls.
- Reference: `docs/features/paper-store.md`, `docs/features/remote-sites.md`.

### Shared Analytics Service (Pro, v1.1.53)

Unified analytics subsystem (`addons/pro/includes/analytics/`, 1,489 lines) consumed by all NV oOS Pro toolkits. Provides a single, consistent analytics surface across social media, e-commerce, and infrastructure platforms.

- **7 platform adapters**: Meta (Facebook + Instagram), Twitter/X, LinkedIn, TikTok, WooCommerce, Google Analytics 4, Cloudways monitoring — all implementing `WP_MCP_AI_Analytics_Adapter_Interface`.
- **5 immutable DTOs**: Account, Post, Metric, TimeSeries, Report — strict coercion, `WP_Error` validation failures, no unchecked casts.
- **Cross-platform normalization**: `WP_MCP_AI_Metric_Normalizer` maps platform-specific metric names to a unified schema.
- **Smart caching**: `WP_MCP_AI_Analytics_Cache` — transient-based with per-data-type TTLs and cache stampede prevention via double-checked locking.
- **Rate limit coordination**: `WP_MCP_AI_Analytics_Rate_Limiter` — token-bucket per platform prevents API exhaustion.
- **Singleton facade**: `WP_MCP_AI_Analytics_Service::instance()` — register adapters, fetch cross-platform reports, invalidate caches.
- **Admin UI**: Settings page for per-platform connection configuration.
- **MCP server registration**: Analytics tools registered in the Pro toolkit MCP server fleet.
- **Site Health integration**: Analytics-specific health checks in the WordPress Site Health dashboard.
- **Entry point**: `init.php` bootstraps on `plugins_loaded` priority 20.
- Reference: `addons/pro/includes/analytics/README.md`.

### OKF Engine (Open Knowledge Format v0.1)

Google's vendor-neutral, Apache 2.0-licensed knowledge format (`includes/okf/`) for curated, authoritative knowledge with deterministic link-based navigation. Complementary to the vector store (RAG for unstructured data) and Paper Store (JSON flat-file).

- **Parser** (`WP_MCP_AI_OKF_Parser`) — pure-PHP YAML frontmatter parser for OKF's minimal subset (scalars, lists, kv-pairs). No external YAML dependency.
- **Reader** (`WP_MCP_AI_OKF_Reader`) — bundle navigation, concept reading, cross-link traversal (up to N hops), and search by type/tag.
- **Writer** (`WP_MCP_AI_OKF_Writer`) — atomic concept creation/deletion via `WP_MCP_AI_Filesystem_Service`, index.md regeneration, conformance validation per spec §9.
- **6 MCP tools** in `includes/tools/okf/`: `okf_read_concept`, `okf_browse`, `okf_traverse`, `okf_search`, `okf_write_concept` (`edit_posts`), `okf_delete_concept` (`delete_posts`). Follow the two-gate sanitisation rule and canonical return envelope.
- **Skill conformance:** All 74 bundled skills (`includes/bundled-skills/`) include `type: Skill` in YAML frontmatter — the single required field for OKF v0.1 conformance.
- **Bootstrap:** `includes/bootstrap/loader.php` loads `okf-init.php` at priority 32 (after Paper Store at 30).
- **Bundle root:** `wp-content/uploads/mcp-ai-wpoos/knowledge/` (skill-knowledge, site-knowledge, external-bundles).
- **Events:** `wp_mcp_ai_okf_bundle_initialized`, `wp_mcp_ai_okf_concept_saved`, `wp_mcp_ai_okf_concept_deleted`.
- **Reference:** `docs/features/okf-integration.md`.

### Agent Client Protocol (ACP)

Implements the [Agent Client Protocol](https://agentclientprotocol.com/) specification (`includes/acp/`) — a JSON-RPC 2.0 server that maps ACP `initialize`, `session/*`, and `tool_call` requests to the core NV oOS tool registry. Includes cancellation semantics, capability negotiation, and federation discovery advertisement. Bridges to the existing chat pipeline without duplicating LLM driver logic.

### LLM Harnessing Subsystem

Seven opt-in per-request layers (`includes/harness/`) that improve response quality without modifying existing tool behaviour. All layers are off by default and activated per-assistant via the **LLM Harness** metabox on the assistant edit screen. Harness profile stored in `_wp_mcp_ai_harness_profile` post meta (keys: `enabled`, `layers`, `cost_ceiling_usd`, `tools.router_mode`, `tools.preset_weights`, `evals_enabled`, `pii_filter`). Pro Layer H (`addons/pro/includes/harness/`) exports fine-tune curricula as OpenAI JSONL — loaded via `addons/pro/includes/harness-init.php`. Key hooks: `wp_mcp_ai_register_prompt_cues`, `wp_mcp_ai_harness_profile`, `wp_mcp_ai_harness_tool_score`, `wp_mcp_ai_harness_eval_generator`, `wp_mcp_ai_harness_eval_tick`. Reference: `docs/features/llm-harness.md`.

### Chat-client Memory Bridge

REST proxy (`WP_MCP_AI_REST_Chat_Memory_Controller`) exposes `/mcp-ai/v1/chat-memory/` (6 routes: preferences, wake-up, recall, store, audit, /{context_id}). JS service (`assets/js/chat-memory-service.js`) and Memory Drawer (`assets/js/chat-memory-drawer.js` — three tabs: Memories / Scope / Audit). The agentic loop emits `memory_event` SSE frames; the drawer handles them in real time. Three gates: (1) site-wide admin toggle in **Orchestration → Settings** (`Enable Chat-Client Memory`); (2) site-wide filter `wp_mcp_ai_chat_memory_enabled`; (3) per-user meta `wp_mcp_ai_chat_memory_enabled`. Endpoints localized via `window.wpMcpAiChat.memoryEndpoints`. Reference: `docs/features/memory/chat-client-integration.md`.

### SSE Streaming

RFC 6202-compliant: `STREAMING_CHUNK_SIZE = 50`, `RETRY_INTERVAL_MS = 3000`.
Client can close connection to interrupt. Job cancellation supported.

### Agent Skills

Portable behaviour packages (`SKILL.md` files) that any NV oOS assistant can load on demand. Per the [agentskills.io](https://agentskills.io/specification) spec: a Markdown body with a small YAML frontmatter (`name`, `description`, optional metadata).

- **Discovery — base bundled skills:** `includes/bundled-skills/{slug}/SKILL.md`. **74 bundled skills** (up from 67 in v1.1.54): 29 design-* skills (7 new in v1.1.54: ai-assistant-admin, crm, project-management, communications, services, team-management, vault, security-ops), plus new `mcp-ai-wpoos-plugin` operational guide skill. Copied to `wp-content/uploads/mcp-ai-skills/` on first activation.
- **Discovery — Pro bundled skills:** `addons/pro/includes/bundled-skills/{slug}/SKILL.md`. The 28+ WordPress-developer skills curated from `Lonsdale201/wp-agent-skills` live here.
- **Third-party attribution:** any new bundled skill curated from an upstream catalogue must add an entry to the corresponding `THIRD_PARTY_NOTICES.md` (`includes/bundled-skills/THIRD_PARTY_NOTICES.md` or `addons/pro/includes/bundled-skills/THIRD_PARTY_NOTICES.md`) with attribution + license text.
- **Progressive disclosure:** assistants with the "Use progressive disclosure" checkbox enabled receive only a short `# Available Skills` catalogue (name + description) in their system prompt. The base-plugin `load_skill({ name })` tool returns the full SKILL.md only when the model decides a skill applies.
- **Remote catalogues (Pro):** [`WP_MCP_AI_Skill_Catalogue_Service`](addons/pro/includes/services/class-wp-mcp-ai-skill-catalogue-service.php) discovers skills in registered public Git repos via the GitHub trees API. [`WP_MCP_AI_Skill_Catalogue_REST_Controller`](addons/pro/includes/rest/class-wp-mcp-ai-skill-catalogue-rest-controller.php) exposes `mcp-ai-pro/v1/catalogues/*` endpoints. SSRF-safe HTTPS-only fetcher; reuses the existing extension allowlist and decompression-bomb cap.
- **Filters:** `wp_mcp_ai_skill_catalogue_manifest_ttl`, `wp_mcp_ai_skill_catalogue_refresh_cadence`.
- **Reference:** `docs/features/agent-skills.md` (full Phases 1–4 narrative).

### Context Window Management

Pre-flight validation across all 13 AI providers before every chat request:
- **Shared helper:** `validate_context_window( $model, $messages, $tools, $max_tokens )` in the base plugin — estimates token count, caps tools when near budget, and returns actionable guidance.
- **tiktoken integration** for accurate token counting via `gpt-tokenizer` npm package and PHP port.
- **Estimator metabox** on the assistant edit screen showing real-time token budget breakdown (system prompt, tools, messages, reserved).
- **Token-budget tool capping** — when the estimated prompt size exceeds 80% of the context window, tools are pruned by priority (least-capable first).
- **Chat parity drift detection** in `lib/core` — compares response quality across providers to detect model degradation.
- All 13 provider clients (OpenAI, Anthropic, Gemini, DeepSeek, OpenRouter, Baseten, Kimi, DigitalOcean, NVIDIA NIM, Cloudflare, Hugging Face, LM Studio, Ollama) call the shared helper before sending requests.

### Transcript & Memory Retention

Two-tier retention system for chat data lifecycle management:
- **Base — Transcript Retention** (`WP_MCP_AI_Transcript_Retention`, `includes/class-wp-mcp-ai-transcript-retention.php`): Configurable TTL-based cleanup of chat transcripts stored in the database. Admin-configurable retention periods. Automatic cleanup via WordPress cron.
- **Pro — Agent Memory Retention** (`WP_MCP_AI_Memory_Retention`, `addons/pro/includes/class-wp-mcp-ai-memory-retention.php`): Agent memory lifecycle management with pruning and retention windows. Manages the persistent memory store used by the chat-client memory bridge.
- Both classes enforce TTL-based cleanup and expose admin settings for retention period configuration.

### Layer I Guardrails

Stay-on-target jailbreak prevention that runs before every AI provider request:
- **Jailbreak detection** — pattern-based and heuristic analysis of user messages for prompt injection, role-play manipulation, and boundary-testing attempts.
- **Capability boundary enforcement** — configurable thresholds that limit what the assistant can access or modify.
- **Agent capability boundary** (`WP_MCP_AI_Agent_Capability_Boundary`) — enforces per-assistant guardrails at the framework level, before the prompt reaches the provider.
- All guardrails are opt-in per assistant and configurable in the Orchestration → Guardrails admin tab.

### Security Infrastructure (v1.1.42+, hardened v1.1.54, extended v1.1.55)

Seven security infrastructure classes in `includes/security/` that operate across the full option set, plus circuit breaker protection on all 15 AI provider clients:
- **Request Guard** (`WP_MCP_AI_Request_Guard`) — SSE connection slot limits, JSON depth enforcement (configurable max), request body size enforcement (configurable cap), error verbosity filtering (Safe/Moderate/Debug tiers), asset version stripping (`?ver=` query string removal). Hooks into `rest_dispatch_request` (WP >= 6.5 signature: 5 params).
- **Security Posture** (`WP_MCP_AI_Security_Posture`) — computes a weighted 0-100 security posture score from 21 signals (HTTPS, HSTS, root key, audit log, rate limiting, security headers, CORS restricted, error verbosity safe, auth brute-force protection, body size limited, 2FA consistency, IP-whitelist consistency, prompt-injection detector, PII filter, and more). Returns A-F grade + top-3 quick wins. Cached (5-min TTL). Filter: `wp_mcp_ai_security_posture_signals`.
- **Destructive Ops Gate** (`WP_MCP_AI_Destructive_Ops_Gate`) — confirmation gate for bulk-delete, mass-email, and other irreversible operations. Credential token expiry enforcement.
- **URL Guard** (`WP_MCP_AI_URL_Guard`) — validates and sanitizes URLs before outbound requests.
- **Concurrency Guard** (`WP_MCP_AI_Concurrency_Guard`) — prevents overlapping destructive operations within the same session. Wired into the execution pipeline (v1.1.53) to auto-throttle tool calls when system load is high.
- **Cost Tracker** (`WP_MCP_AI_Cost_Tracker`) — per-operation cost estimation and budget enforcement. Now enforced at the execution pipeline level, not just the provider client boundary (v1.1.53).
- **API Key Store** (`WP_MCP_AI_Api_Key_Store`) — encrypted at-rest storage for third-party API keys with key rotation support.
- **Circuit breaker protection** (v1.1.53) — all 15 first-class AI provider clients now have circuit breaker protection with configurable failure thresholds and cooldown periods, preventing cascading failures during provider outages.
- **Backpressure** (v1.1.53) — signals from the concurrency guard and cost tracker feed into the agentic loop's iteration budget, automatically throttling tool calls when system load is high.
- **PostCSS CVE-2026-69153** (v1.1.54) — `postcss` minimum bumped to 8.5.23 to resolve high-severity CVE in the CSS post-processor chain.
- **PostCSS GHSA-6g55-p6wh-862q** (v1.1.55) — `postcss` minimum bumped to >=8.5.26 in `addons/schedule-anything-spa/package.json`.
- **MCP tool rate limiter** (v1.1.55) — settings-driven (`tool_rate_limit_max` / `tool_rate_limit_window` / `tool_rate_limit_exempt_tokens`) with credential-token exemption; GET/HEAD exempt from the request quota.
- **Connection-pooling stance** (v1.1.55, Proposal 023) — atomic concurrency-guard slot tracking (Redis `wp_cache_incr` + InnoDB upsert fallback, `mcp_ai_concurrency_slots` table), RabbitMQ gating of Action Scheduler fallback and DB polling cron, PDO persistent connections in the Content Graph standalone plugins (formerly Graphify), Site Health checks for MySQL pool / queue depth / RabbitMQ.
- **Media Worker v2.2.0** (v1.1.55) — sidecar hardening: timing-safe `X-Site-Token` auth, SSRF guard, sandboxed Puppeteer, rate limiting, Helmet headers; `WP_MEDIA_WORKER_TOKEN` constant in the plugin client trait.
- **Media Worker v2.4.0 → v3.0.0** (v1.1.56) — multi-tenant shared worker mode (`SITE_TOKENS` per-site isolation, per-site rate limits, `SITE_TOKENS_PREVIOUS` rotation); Phase 2 per-site provider keys (`SITE_PROVIDER_KEYS`, `PROVIDER_KEYS_STRICT`), usage counters, grouped temp TTLs, cluster warnings + k6 kit; Phase 3 scale (multisite per-blog tokens, usage reporter, `SITE_TOKEN_<SLUG>` env merges, opt-in Redis rate-limit store `RATE_LIMIT_REDIS=1`, `PROVIDER_KEYS_FILE` hot-reload); zero-downtime rotation (`WORKER_API_TOKEN_PREVIOUS`), Canvas v3 napi prebuilds; worker routing for document generation, OCR, frames, charts, email, QR/translate/PDF, vectorize with local fallbacks.
- **API key merged-settings enforcement** (v1.1.54) — 20 research tools now use `get_merged_credentials()` instead of reading the global API key option directly, ensuring per-assistant and per-provider API key overrides take effect.
- **Post-install integrity check** (v1.1.52) — `WP_MCP_AI_Plugin_Updater` verifies 15 critical file paths after every update before reporting success. Prevents silent corruption on distributed filesystems (Cloudways).
- **Plugin updater rework** (v1.1.57) — copy-in-place install with backup snapshot and automatic rollback replaces `Plugin_Upgrader` (the live plugin directory is never renamed); new base-only update flow for wp.org installs; Pro version read from the plugin header instead of the drifted constant.
- **Service Status provider detection** (v1.1.57) — AI-provider detection now resolves credentials through `WP_MCP_AI_Credential_Resolver` (plugin settings, WP 7.0 Connectors, env vars, PHP constants) with a merged-settings fallback for early bootstrap.
- **OOS runtime consolidation** (v1.1.58, Proposal 029 Phases 0–5.8) — the OOS engine reaches parity with the legacy path: tool-surface + security-gate parity (`ToolGuardInterface`), event-sourced session log (`SessionLog`/`SessionEvent`/`SessionTelemetry`), opt-in shadow mode (`includes/oos/`, `wp_mcp_ai_oos_shadow_enabled()` default off) + `wp mcp-ai oos parity` CLI, canary routing (`wp_mcp_ai_oos_canary` default off), scoped tools (`ToolScope`/`ToolRestriction`) + compaction seam (`CompactionProvider`), Pro composition & child binding (`addons/pro/includes/composition/`), telemetry single-path. See `.context/oos-engine.md`.
- **Composio Connect** (v1.1.58) — Pro subsystem `addons/pro/includes/composio/` (OAuth auth handler with state nonce, API client, trigger bridge, signed webhook controller) + 6 beta `composio_*` tools + remote-sites admin/metabox panels.
- **deepmerge-ts CVE-2026-40345** (v1.1.58) — override in `addons/media-worker/package.json` + `addons/pro/package.json`.
- **Security Center wp-api fix** (v1.1.58) — `wp-api` enqueued on the security tab (its inline scripts call `wp.apiRequest()`).
- **Graphify standalone plugins → Content Graph** (v1.1.58) — `plugins/nvoos-graphify*` renamed to `plugins/nvoos-content-graph*` (v1.0.2); `addons/graphify/` (knowledge-graph addon) unchanged.
- **Media Worker crawling & Crawl4AI facade** (v1.1.59, worker v3.2.0) — new `/api/crawl/*` endpoints (markdown, markdown-batch, links) with static-first two-tier extraction and the shared SSRF guard; `/api/crawl4ai/*` facade for the plugin's `run_crawl4ai_job` remote mode; `src/utils/llm-extract.js` structured extraction. See `docs/project/proposals/031-media-worker-crawl4ai-integration-plan.md` and `.context/media-worker.md`.
- **Research tools multi-provider** (v1.1.59) — `semantic_content_search` embeddings resolve through the shared provider abstraction (new Gemini embedding provider in `includes/services/embedding/`); keyword fallback when unconfigured; model-mismatch vector skipping. `deep_research` retries across the provider chain and never caches empty reports. New base tools `list_terms` + `list_taxonomies`.
- **`wp_mcp_ai_plugin_updated` action** (v1.1.59) — the copy-in-place plugin updater fires this after every successful update (core, Pro, base→complete); **addons that cache plugin files must subscribe** because `upgrader_process_complete` never fires for these updates (Docs Hub 0.4.1 does, plus an `admin_init` version-mismatch rebuild guard).
- **Tool registration fixes** (v1.1.59) — legacy-format tool classes (pre-interface) are transparently wrapped (`WP_MCP_AI_Legacy_Tool_Wrapper` + `WP_MCP_AI_Tool_Legacy_Definition` trait); ~32 previously-orphaned base tools are registered; new `wp_mcp_ai_tools_init` action for late side-loading; registry tracks skipped tools in `unavailable_tool_slugs`.
- **Restricted-user flagging** (v1.1.60) — `WP_MCP_AI_Restriction_Registry` (`includes/class-wp-mcp-ai-restriction-registry.php`) turns rate-limit / token-budget blocks into persistent, audit-logged restriction records with auto-expiry; REST routes (`/mcp-ai/v1/restrictions`, `/users/{id}/restrictions`), AJAX lift actions, `wp mcp-ai restrictions` CLI, Token Manager panel + Pro Command Center Restrictions tab; IETF `RateLimit-*` headers on rate-limited responses; chat rate limits filterable (`wp_mcp_ai_chat_rate_limit` / `wp_mcp_ai_chat_rate_limit_window`); posture signal `restriction_registry_on`. See `docs/features/security/user-restrictions.md`.
- **Conversation import to transcript CCT** (v1.1.60) — `includes/conversation-import/` imports ChatGPT / Gemini Takeout / Claude / ShareGPT / OpenAI JSONL into the JetEngine `ai_chat_transcripts` CCT (4 new JetEngine-gated tools, admin page, `wp mcp-ai conversation-import` CLI, GDPR + memory mining). See `docs/user-guides/conversation-import.md`.
- **Tool schema normalization** (v1.1.60) — tool argument schemas are normalized before provider payloads (DeepSeek client, REST `/tools` output, `WP_MCP_AI_Tool_Service`, `ChatOrchestrator`); the registry logs registrations skipped for a missing tool contract.
- **WP_Error fatals fixed** (v1.1.60) — memory tools, request guard, and the REST chat-memory controller now handle `WP_Error` returns before array/object access.
- **nvoos-content-graph v1.0.3** (v1.1.60) — wp.org resubmission: WPCS + review fixes, screenshot assets, standalone build ZIP.
- **Agent identity bridging in memory** (v1.1.61) — `WP_MCP_AI_Agent_Identity_Resolver` (`includes/services/class-wp-mcp-ai-agent-identity-resolver.php`) canonicalises virtual agent keys (e.g. `nvoos-pro-spa-memory-drawer`, `virtual_planner_1`) to the canonical assistant post ID; alias map in `wp_mcp_ai_agent_id_aliases` (bounded 200, never autoloaded, sanitised). `store_agent_context` resolves on store (envelope + `wp_mcp_ai_memory_stored` carry `original_agent_id`/`agent_id_resolved`); REST chat-memory recall merges alias buckets (`stored_under` per record, `merged_sources`, default limit 25); drawers show scope chips, agent-ID diagnostic, show-all-scopes toggle, store-triggered refresh. `wake_up_context` graph-bridge failures fall back to the transient path; scoped wake-up/recall retries unscoped.
- **OKF skill-knowledge bundle generator** (v1.1.61) — `WP_MCP_AI_OKF_Skill_Knowledge_Generator` (`includes/okf/`) auto-generates the `skill-knowledge` bundle from `includes/bundled-skills/` on bootstrap (priority 32, when missing or version changed) and after admin bundled-skill reinstall — fixes "OKF bundle not found: skill-knowledge" on every okf_* tool.
- **undici ^7.29.0 pin** (v1.1.61) — jsdom 29.1.1 breaks on undici 8 (removed `lib/handler/wrap-handler.js`); override pinned to ^7.29.0 (newest 7.x, CVE fixes retained) across seven addon manifests.
- **OKF bundle management** (v1.1.62, PR #5914) — `WP_MCP_AI_OKF_Bundle_Manager` (`includes/okf/`) is the single authority for OKF bundle paths + lifecycle (create/rename/archive/delete, ZipSlip-safe ZIP import/export, stats, log maintenance); `skill-knowledge` is auto-generated and protected (`okf_protected_bundle`); 3 new base tools (`okf_list_bundles`, `okf_validate_bundle`, `okf_import_bundle`) + `okf_write_concept` provenance schema (OKF surface = 10 tools); Bundle Manager admin screen (`wp-mcp-ai-okf-bundle-manager`) with nonce + `manage_options` AJAX. See `docs/features/okf-integration.md`.
- **OKF Pro knowledge routing** (v1.1.62, PR #5914) — `pro_okf_skill_bridge` (`WP_MCP_AI_OKF_Skill_Bridge`): `load_skill` resolves `bundle:concept_id` via `wp_mcp_ai_load_skill_external`, gated by per-assistant grants (`_wp_mcp_ai_okf_concepts`, fail-closed), draft status, trust tier; `WP_MCP_AI_OKF_Enrichment_Agent` + `okf_enrich_site_content`, `WP_MCP_AI_Hybrid_Knowledge_Router` + `route_knowledge_query` (`addons/pro/includes/okf/`).
- **Pro SPA v2 OKF drawer + REST** (v1.1.62, PRs #5915/#5916) — read-only `mcp-ai-pro/v1/okf` surface (`WP_MCP_AI_Pro_REST_OKF`, `addons/pro/includes/rest/`) powers the `OkfDrawer`; concept routes allow `%` + `rawurldecode()` in the handler (%2F IDs); `WP_MCP_AI::$admin_okf_bundle_manager` declared (PHP 8.2 dynamic-property fix).
- **Vector store Responses API migration** (v1.1.62, PR #5917) — OpenAI removes the Assistants API 2026-08-26: all vector-store call sites drop the `OpenAI-Beta: assistants=v2` header; ingestion via `file_batches` (bounded polling, single-file fallback); `filter`/`ranking_options` support. Do not reintroduce the beta header.
- **Artifact evolution Phases A–G** (v1.1.63, PRs #5923/#5925) — `includes/harness/class-wp-mcp-ai-artifact-*.php` + `class-wp-mcp-ai-evolution-governor.php` turn the Continual Harness Evolver + Meta-Harness into a gated Darwinian loop for skills/prompts/roles: populations + parent sampling, failure replay + post-mutation verification, pre-commit admission gate (three critics), holdout-gated deployment with shadow A/B + drift rollback, governor (budget/rate/cap) + human approval queue + lineage. `evolve_harness` ↔ `WP_MCP_AI_Agent_Harness_Evolver` contract repaired (`analyze_failures()`, component-scoped `evolve()`, enforced `dry_run`); `WP_MCP_AI_Evolved_Prompt_Resolver` consumption is opt-in. **Every layer defaults off**; switches live in Settings → Orchestration Layer via `WP_MCP_AI_Evolution_Settings_Bridge` (priority 5). See `docs/project/proposals/007-artifact-evolution.md`.
- **Pro Addons admin page** (v1.1.63, PR #5924) — `WP_MCP_AI_Addons_Page` (`addons/pro/includes/admin/`) installs/activates standalone addons from `build/` ZIPs; nonce + `install_plugins` + allowlist.
- **Chat storage worker offload** (v1.1.63, PR #5928) — saves ≥ `wp_mcp_ai_storage_worker_threshold` (default 10,000 chars) offload `JSON.stringify` to `storage-worker.js` via `wpMcpAiStorageUtil`; sync fallback for small saves/unload/worker failure; kill switch filter (0 = off); localized via `WP_MCP_AI_Shortcode::get_storage_worker_inline_script()`. See `docs/project/proposals/032-chat-web-workers-wiring-implementation-plan.md`.
- **DeepSeek empty-schema fix** (v1.1.63, PR #5926) — tool schema `properties` must encode as `{}`, never `[]` (DeepSeek 400s otherwise); enforced at REST / Tool Service / ChatOrchestrator boundaries; legacy tools wrapped BEFORE first register attempt so the fail-loud registry log only fires for genuine failures.
- **Test-suite exit traps** (v1.1.63, PR #5929) — `bin/sweep-tests.php` parallel sweep; AJAX test contracts in `tests/bootstrap.php` (`tests/AJAX_TESTS_README.md`); bare-exit test seams (`WP_MCP_AI_TESTS_RUNNING`) in 4 admin handlers; SSE/Veo polling filters; remaining work in `docs/developer/testing-docs/TEST-SUITE-REMAINING-FIXES-PLAN.md`.
- **Google Calendar connection & shared Google services** (v1.1.64, PR #5959) — `includes/google/` is the single Google OAuth/Calendar foundation (`WP_MCP_AI_Google_OAuth_Service`, `_Calendar_Client`, `_Calendar_Scopes`, `_Calendar_Credentials`, `_Calendar_Sync`, `_Calendar_Push`); it replaces four drifted OAuth start/callback copies — new Google integrations must build on it, not copy-paste a fifth. New `google_calendar` connection type on both surfaces; 6 new Pro tools in `addons/pro/includes/tools/google-workspace/`; every Calendar write passes scope enforcement. See `docs/developer/architecture/integrations/google-calendar-connection.md`.
- **Composio account health + hardening** (v1.1.64, PRs #5932–#5936, #5953, #5958) — `WP_MCP_AI_Composio_Account_Health` ledger + live catalog-discovered probes; new `composio_manage_accounts` (7th tool, validate/reconnect/delete/prune); in-band failures converted to `WP_Error`; proxied provider 401/403 → `wp_mcp_ai_composio_account_auth_required`; zero-arg `arguments` encode as `{}`; `from_connection()` identity binding; Health column + Verify/Reconnect in Remote Sites.
- **Logger hygiene** (v1.1.64, PRs #5952/#5954) — persistence-path byte budgets (fingerprint `assistant_config`/`system_prompt`, truncate, drop largest, 12 diagnostic keys) keep `wp_mcp_ai_recent_errors`/`wp_mcp_ai_recent_activity` bounded; `previous_results` no longer embedded (O(N²) workflow fix); `wp_mcp_ai_maintain_log_buffers` AJAX (Compact/Delete); shared redactor masks 26 credential-bearing URL query-param names. `wp_mcp_ai_log_entry` + `error_log()` still get full sanitized context.
- **Non-loggable result fields** (v1.1.64, PR #5961) — `WP_MCP_AI_Tool_Sensitive_Result_Interface::get_sensitive_result_fields()` lets tools declare dot-notation/`*`/subtree paths the logger masks with `[redacted]` before preview truncation; logging-only (caller/model payloads untouched); `wp_mcp_ai_tool_sensitive_result_fields` filter additive-only; legacy wrapper forwards it.
- **Symfony 5.4 validator restore** (v1.1.64, PR #5960) — constraint mapping must use `enableAnnotationMapping( true )` on ^5.4 (not `enableAttributeMapping`, a ≥6.1 API): the old guard silently skipped validation for every validated tool. Also: vision tools accept a 5–300s `timeout` (`WP_MCP_AI_Vision_Request_Timeout`, PR #5964); `/mcp` `jsonrpc`/`method` not `required` at the REST layer (PR #5957).
- **OpenAI reasoning-model parameter constraints** (v1.1.65, PR #5985) — `lib/core` `OpenAiCompatibleClient::applyModelConstraints()` strips `max_tokens` (and o-series `temperature`) from o-series/gpt-5 payloads before sending, and `sendWithParameterCorrection()` retries 400 rejections with corrected payloads on both sync and streaming paths. Do not re-add blanket `max_tokens` for reasoning models.
- **Content Graph AI embeddings resilience** (v1.1.65, PR #5985) — `EmbeddingService` resolves the embedding provider deliberately (OpenAI preferred when keyed — most chat providers lack `/embeddings`), honors the configured `embeddings_model`, and catches `\Throwable` into `WP_Error`; the chat API reports `graph_context_mode` with keyword-search fallback when no embeddings index exists.
- **Media Worker full-Crawl4AI proxy** (v1.1.65, PR #5983) — env-gated `POST /api/crawl/full` + `GET /api/crawl/full/task/:id` forward to `CRAWL4AI_FULL_URL` after SSRF-validating every target (`routes/crawl.js` → `submitFullCrawl()`/`getFullTaskStatus()`); `TEMP_ROOT` is the strict-path sandbox root (028 Q5). Worker stays **v3.2.0** — `package.json` is authoritative over proposal 031's stale "3.2.1+" note.
- **Security-posture closure** (v1.1.65, PR #5981, issue #5972) — Algorave Tone.js raw eval requires per-session confirmation + warning banner (capability-scoped via `nvoosAlgoraveConfig`); TMA source map removed; webhook `__return_true` callbacks carry justification comments. `SECURITY_POSTURE.md` updated.
- **Chat/REST/transcript hardening** (v1.1.65, PRs #5991/#5993/#5994/#5987) — chat route declares no top-level `attachments` arg (legacy clients tolerated); role enum validation lives in the sanitize layer (`wp_mcp_ai_allowed_message_roles`); orphaned tool messages silently discarded via `filter_tool_messages_without_matching_calls()`; attachment prep errors propagate; sign-preserving pagination sanitizer; legacy `input_text` segments normalize to `text`. Also: TPM falls back to the bundled model catalog without JetEngine (PR #6005); slash-command handler re-resolution + CSV parsing (PR #6000); idempotent block registration (PR #5997); WP 7.0 bridge `WP_Connector_Registry::is_registered()` guard (PR #6006).
- **PHPUnit suite repair campaign** (v1.1.66, PRs #6008/#6009/#6012–#6107) — ~100 cluster PRs (Aug 28–31) repaired the single-process suite; the recurring root causes are catalogued in `.agents/skills/mcp-ai-wpoos-test-suite/SKILL.md` (16 patterns: hook resets, singleton interference, WP_Error envelope drift, nonce/user binding, WP 6.9 queue memoization, anonymous-class visibility, …) with the standing tracker `docs/developer/testing-docs/TEST-SUITE-REMAINING-FIXES-PLAN.md`. Production seams the suites now depend on: `validate_assistant_access()` caches `WP_Error` results and the cache-disable path is the `wp_mcp_ai_assistant_access_cache_enabled` filter (never a persisted define, #6008); attachment-segment validation errors carry `status => 400` (#6009); `WP_MCP_AI_Job_Notifier::update_status()` exists and async job IDs preserve dots (#6036/#6037); progress events promote cached status to running (#6039).
- **REST/auth hardening** (v1.1.66, PRs #6014–#6019) — token-tier endpoint + tier-change audit logging (#6018); REST permission-callback allowlist refresh (#6019); bearer-auth context sync across the Simple JWT and assistant-access paths (#6014/#6016); guest tokens are origin-bound (F-AUTHZ-04); REST analytics endpoints fixed (#6015).
- **Content Graph AI 1.0.3** (v1.1.66, PR #6056) — the standalone `nvoos-content-graph-ai` plugin bumped to 1.0.3 with a tool permission-check fix; `nvoos-content-graph` stays 1.0.3 and Media Worker stays v3.2.0.
- **Content Graph platform extraction v2.0.0** (v1.1.67, PR #6123) — the `nvoos-content-graph-ai-platform` addon now carries its own business logic (Waves A–C + Blueprints): namespace-bridged admin UI (`PlatformDashboard`, `PlatformSettingsRegistry`), skill/slash-command/agent bridges, harness `ToolRouterHarness`, a 74-skill bundled-skills pack, knowledge base, and `.github/workflows/phpunit-platform.yml`. Plan: `docs/project/plans/content-graph-platform-extraction-plan.md`; gaps: `plugins/nvoos-content-graph-ai-platform/MIGRATION-GAPS.md`.
- **Base+Pro → Content Graph ecosystem port, Wave D + D-UI** (v1.1.67, PR #6142) — the AI runtime now lives in `nvoos-content-graph-ai` (chat core D1, providers D2, model management + analytics/token tracking D3, security guards D4, assistant admin pages + blocks/widgets/guest tokens/memory/CLI/MCP JSON-RPC D-UI) with `.github/workflows/phpunit-ai.yml`; **Content Graph AI bumps 1.0.3 → 1.0.4**. Tracker: `docs/project/ecosystem-port-tracker.md`.
- **Google Workspace Gmail + Drive read tools** (v1.1.67, PRs #6151/#6152) — 6 new Pro tools in `addons/pro/includes/tools/google-workspace/` (`get_gmail_message`, `get_gmail_thread`, `list_gmail_connections`, `modify_gmail_message` — destructive-ops gated — and `get_drive_file`, `list_drive_connections`) with new `WP_MCP_AI_Pro_Gmail_Client` / `WP_MCP_AI_Pro_Google_Drive_Client` clients on the shared `includes/google/` foundation. Pro count +6 → ~1,262.
- **PHPUnit repair campaign continuation** (v1.1.67, PRs #6114–#6208) — ~95 cluster PRs (Aug 31 – Sep 2) kept the suite green through the extraction/port work and PHPUnit 11 / WP 7.1 drift; the test-suite skill was refreshed to 26 patterns (#6154). Production seams the suites now depend on: `WP_MCP_AI_Settings_Registry::unregister_section()` (#6144); `WP_MCP_AI_Tool_Token_Limits::get_tool_multiplier()` is public (#6189); REST tool-error reporting tolerates a null request (#6186); WhatsApp webhook signature validation rejects a missing app secret (#6192); the destructive-ops gate reads the canonical combined-settings array with a legacy-repository fallback (#6151); crawler `base_url` URL validation + `wp_mcp_ai_crawl4ai_auto_spawn_cron` filter (#6124/#6125).
- **REST require_once guards** (v1.1.52) — all REST controller `require_once` calls in `class-wp-mcp-ai-rest.php` now guarded with `file_exists()` checks.
- **Site Health integration** — WordPress Site Health checks for cron configuration and security posture.
- **13 security unit tests** in `tests/security/` covering API key encryption, auth split-brain, break-glass, credentials expiry, destructive ops gate, rate limiting, SSE auth/CORS/rate limiting, SSRF protection, tool scope sanity, URL guard, and validated upload.

Reference: `docs/operations/production-hardening-guide.md`, `docs/developer/api-key-encryption.md`.

### MCP Protocol (2026-07-28 Spec, v1.1.43)

Stateless core — sessions/initialize/initialized retired (SEP-2567, SEP-2575). server/discover RPC. _meta per-request. Mcp-Method/Mcp-Name headers on Streamable HTTP. Legacy client shim. SSE preserved (deprecated per SEP-2596, 12-month off-ramp).

### OKF v0.2 Trust-Signals (v1.1.43)

Recursive descent parser with inline YAML, nested objects, flow sequences. Trust tiers: unverified / machine-confirmed / human-reviewed. New okf_validate_attestation tool. Full v0.1 backward compat.

### Security Hardening v1.1.43–v1.1.46

SSRF across 7 providers. SQL table-name validation. Auth gating (A2A cards, Chat SPA endpoints). IP-based guest rate-limiting. safe_unserialize helper. Proposal 016 (277 autoload optimizations, phpcs sweep, 8 findings). Proposal 017 (12 polling/queue/load-balancing weaknesses). Deferred items #5755 (post meta, term, REST filtering). Phase 3 operational (audit logger, CSP, posture signals).

### Architecture (v1.1.43–v1.1.46)

Pro Module Registry (625-line init decomposed to PSR-4). PlatformFlushInterface (last WP ref extracted from nvoos/core). ICP System (7-dimension scoring). CCT stability (mutex lock, FlowHub guard, base-plugin graceful degradation). Veo/Gemini fixes (async context, API key resolution).

### Self-Hosted OCR — Unlimited-OCR + DeepSeek-OCR (v1.1.45)

Unified client (`WP_MCP_AI_Self_Hosted_OCR_Client`, 640 lines in `includes/`) supporting Baidu Unlimited-OCR (3B, MIT, 93.23% OmniDocBench) and DeepSeek-OCR (~3B, MIT) via self-hosted vLLM with OpenAI-compatible REST APIs. Both models share NGramPerReqLogitsProcessor + `<image>` prompt pattern. Pro tools: `pro_unlimited_ocr` (long-horizon, structured output — text/structured/raw, table/form-field extraction, Paper Store persistence) and `pro_batch_ocr` (Action Scheduler, sync up to 10, async up to 100). Structured extraction service (`WP_MCP_AI_Structured_Extraction_Service`) for `<|det|>` marker parsing. Embedded backend (`NV_oOS_Embedded_Self_Hosted_OCR_Backend` implementing `NV_oOS_Embedded_LLM_Backend`) + OCR health dashboard. Settings: `unlimited_ocr_endpoint_url` / `deepseek_ocr_endpoint_url` with AJAX Test Connection buttons.

Reference: `docs/project/proposals/018-unlimited-ocr-integration.md`, `docs/project/proposals/018-unlimited-ocr-integration-implementation-plan.md`.

### Embedded Addon v0.2.0 (v1.1.45)

Backend registry expanded with voice tool calling, OpenMed healthcare tools, and new MCP abilities registered via `wp_register_ability()` for AI agent discovery through the WordPress Abilities API.

### AI Transparency & SGI Compliance (v1.1.45)

AI transparency and SGI compliance infrastructure (Proposal 017) integrated into the plugin framework — part of ongoing regulatory alignment for AI-powered operations.

### Backup & Restore — Modular Export System (v1.1.46)

New comprehensive backup and restore subsystem in `includes/admin/export/` with an export manager (`WP_MCP_AI_Export_Manager`, 512 lines) orchestrating JSON-based export/import across 11 modular export providers (8 base + 3 Pro). Each provider implements `WP_MCP_AI_Export_Provider_Interface` with `export()` and `import()` methods. Base providers: Core Settings (258 lines), Assistants (285 lines), CPTs (319 lines), Custom Tables (394 lines), Federation (253 lines), Addon Options (337 lines), Toolkit Options (282 lines). Pro providers: JetEngine CCTs (329 lines), License keys (190 lines), Remote Sites (324 lines). Admin UI in Settings → Advanced with provider checkboxes, export/import buttons, and progress feedback. Subsystem README at `includes/admin/export/README.md` (202 lines). Proposal 020 reference: `docs/project/proposals/020-comprehensive-backup-restore-proposal.md`.

### Plugin Updater — GitHub-Based Auto-Update (v1.1.46)

New auto-update system for full-build (GitHub-sourced) distributions via `WP_MCP_AI_Plugin_Updater` (772 lines in `includes/`). Fetches release metadata from GitHub Releases API, compares semantic versions, downloads ZIP artifacts, and installs via WordPress `Plugin_Upgrader`. Base-to-complete upgrade path: Settings → Advanced offers one-click upgrade from base-only to complete package. Pro addon awareness: detects bundled Pro, updates it alongside base, hides redundant Pro updater UI. Safe update pattern: `Plugin_Upgrader` for core, direct copy with rollback for Pro. Nonce-scoped update actions with `current_user_can( 'install_plugins' )` capability checks. Initialised in `includes/bootstrap/loader.php` after the updater class is loaded.

### Abilities API — Machine-Readable Plugin Operations (v1.1.46)

New framework in `includes/abilities/` for registering machine-readable plugin operations with JSON Schema contracts, enabling AI agent and MCP tool discovery:
- `WP_MCP_AI_Ability_Registrar` (188 lines) — ability registration, discovery, and lifecycle.
- `WP_MCP_AI_Ability_Bridge` (235 lines) — bridges abilities to the tool registry for MCP/AI agent discovery.
- `WP_MCP_AI_Ability_Category_Registrar` (92 lines) — hierarchical ability grouping.
- `WP_MCP_AI_Ability_Security_Bridge` (289 lines) — capability-based access control per ability.
- `WP_MCP_AI_Tool_Ability_Interface` (61 lines, `includes/interfaces/`) — contract for tools exposing discoverable abilities.
- Bootstrap via `abilities-init.php` (49 lines). Test suite in `tests/abilities/` (5 files, 862 lines total).
- Reference: `docs/reference/abilities-registry.md`, Proposal 019 in `docs/project/proposals/019-abilities-api-selective-adoption-*.md`.

### Status Page Fixes (v1.1.46)

Pro status REST endpoint fatal error resolved (missing methods in `class-wp-mcp-ai-pro-status-ajax.php`). JS errors and i18n text domain consistency fixed on the status dashboard (`pro-status-page.js`, `pro-status-dashboard-page.php`).

### Framework-Agnostic Core — lib/core (v1.1.42)

Framework-agnostic AI orchestration engine extracted as `nvoos/core` (PHP 8.1+, MIT license, separate `composer.json`):
- **Hexagonal Architecture** — Ports & Adapters pattern with 32 domain contracts (interfaces) in `lib/core/src/Domain/Contract/`, 21 WordPress adapters in bridge layer, plus Laravel and Craft CMS adapters.
- **ChatOrchestrator** — framework-agnostic agentic loop with integrated RateLimiter and SemanticCompressor.
- **ProviderRouter** — 12-provider routing with automatic fallback.
- **ToolRegistry + SkillRegistry** — tool/skill registration decoupled from WordPress.
- **109 tools migrated** to framework-agnostic format in `lib/core/src/Tool/`.
- **5 chat parity gaps closed** — finish_reason handling, prompt caching, input sanitization, vision image processing, transcript store contracts.
- **Streaming provider contracts** and voice/realtime provider abstractions.
- **WordPress bridge** (`includes/bridge/`) — adapts nvoos/core contracts to WP primitives (wpdb, options, cron, REST, etc.).

### Meta-Harness Auto-Optimization System (v1.1.40)

Self-improving agent infrastructure that observes, analyzes, and self-optimizes AI agent execution across 7 phases (`includes/harness/`):

- **Trace Store** (`WP_MCP_AI_Harness_Trace_Store`) — Persistent storage for execution telemetry with queryable indexes by agent, tool, outcome, timing, and provider.
- **Trace Capture** (`WP_MCP_AI_Harness_Trace_Capture`) — Hooks into the tool execution pipeline to record tool calls, arguments, duration, tokens, errors, and outcomes.
- **Harness Search Engine** (`WP_MCP_AI_Harness_Search_Engine`) — Full-text search and faceted filtering across execution traces. WP-CLI integration (`wp mcp-ai harness search`).
- **Auto-Deploy** (`WP_MCP_AI_Harness_Auto_Deploy`) — Pushes approved optimizations (prompt refinements, tool selection, parameter tuning) to production with rollback capability.
- **Population** (`WP_MCP_AI_Harness_Population`) — Batch-processes historical traces through the proposer. Chunked, resumable.
- **Pro Coding-Agent Proposer** (`addons/pro/includes/harness/`) — Analyzes traces and generates structured optimization proposals with confidence scoring. Human-in-the-loop review.
- **Cues + DSpark** — Threshold-based auto-triggers and speculative orchestration coordinating the full optimization lifecycle.

**Key hooks:** `wp_mcp_ai_harness_trace_captured`, `wp_mcp_ai_harness_proposal_generated`, `wp_mcp_ai_harness_before_deploy`, `wp_mcp_ai_harness_after_deploy`.

Reference: `docs/features/meta-harness-auto-optimization.md`.

### Agent Delegation Rework (v1.1.40)

Delegation subsystem underwent a major rework for reliability and performance:

- **Inline execution** — delegation now runs synchronously instead of async, with immediate result delivery.
- **REST-based dispatch** — delegated tasks dispatched via `/wp-json/mcp-ai/v1/chat` instead of role executor, supporting streaming and uniform auth.
- **Cron resilience** — retry logic (3 attempts, exponential backoff), stuck-job detection, `spawn_cron()` for instant deferred job execution.
- **Name-based agent resolution** — `delegate_to_agent` tool now supports `agent_name` in addition to `agent_id`.
- **SPA v2 Tasks Drawer** — toolbar button with `failedCount` badge, retry mechanism for failed tasks.
- **`allowSensitiveTools`** — config flag propagated through delegation dispatch chain.

Reference: `docs/features/agent-delegation-system.md`.

### Tool Presets System (v1.1.41)

Curated tool groupings organized in a layered hierarchy:

- **Essentials layers** — Base → Essentials → Extended → Specialist. Additive; assigning essentials auto-includes base.
- **Essentials Internal (v1.1.41)** — now includes 7 read-only knowledge access tools: paper_store_read, paper_store_list, paper_store_search (Paper Store) and okf_read_concept, okf_browse, okf_traverse, okf_search (OKF). All base-tier, no external API deps.
- **Files & Documents (v1.1.41)** — now includes all 6 OKF tools alongside the 6 Paper Store tools (full CRUD for curated + structured flat-file knowledge).
- **Deduplication** — within-layer, cross-layer, and assistant-level dedup.
- **Auto-upgrade** — validated tool variants automatically replace non-validated versions (no duplicate names).
- **Chips Bar UI** — selected tools shown as clickable chips with +N overflow toggle.
- **Tool payload cap** raised from 50 to 100 tools per assistant.
- **SSE adapter fix** — double tool execution eliminated in streaming mode.
- **`tool_call_id` handling** — DeepSeek streaming now includes `tool_call_id`; messages without it stripped.

Reference: `docs/features/tool-presets-system.md`.

### Pro Toolkit Optimizations

Performance optimization classes for 6 Pro toolkits:
- **Pattern:** `WP_MCP_AI_{Toolkit}_Optimization` classes that implement autoload control, query caching, lazy loading, and retention policies.
- **Toolkits optimized:** Chat Channels (`WP_MCP_AI_CC_Optimization`), Social Media (`WP_MCP_AI_SM_Optimization`), Healthcare (`WP_MCP_AI_HC_Optimization`), Ecommerce (`WP_MCP_AI_EC_Optimization`), Calendar/Orchestration (`WP_MCP_AI_Cal_Orch_Optimization`), Document Generation (`WP_MCP_AI_DG_Optimization`).
- Each class is wired into its toolkit's `init.php` file and activated when the toolkit is enabled.
- Full test suite at `tests/test-pro-toolkit-optimization.php`.

### DietPi Pro Toolkit

Server management toolkit for DietPi single-board computers (Raspberry Pi, Odroid, etc.):
- **19+ tools** spanning system info, package management, service control, network diagnostics, backup/restore, system update, storage management, provisioning, and SSH proxy tunneling.
- **MCP server integration** — DietPi toolkit registered as an MCP server for remote management.
- **Admin toggle** in Features → Pro Toolkits subtab.
- **SSH proxy** for secure tunneling to DietPi devices behind NAT/firewalls.
- Pro-only, requires the Pro addon.

## Build & Test Commands

```bash
# ── Docker (recommended on Windows) ──
bash bin/run-tests-docker.sh                             # all tests
bash bin/run-tests-docker.sh tests/test-foo.php           # single file
bash bin/run-tests-docker.sh --filter='test_bar'          # filter by name

# ── Local PHP + MySQL ──
# Before every PR:
composer run lint:base && composer run test

# Full CI check:
composer run ci:all && npm run build

# Quick checks:
composer run lint          # PHPCS full codebase
composer run format        # PHPCBF auto-fix
composer run lint:compat   # PHP 7.4-8.3 compatibility
npm run lint:js            # ESLint
npm test                   # Jest
```

## Commit Convention

```
feat(scope): brief description
fix(scope): brief description
docs(scope): brief description
test(scope): brief description
```

## Context Engineering Files

| File | When to Load |
|------|-------------|
| `.context/conventions.md` | Always — naming, style, PHP compat |
| `.context/security-checklist.md` | Always — security requirements |
| `.context/tool-registry.md` | Working on tools |
| `.context/settings-storage.md` | Working on settings, credentials, import/export |
| `.context/rest-api.md` | Working on REST endpoints |
| `.context/chat-ui.md` | Working on frontend chat |
| `.context/testing.md` | Writing PHPUnit tests |
| `.context/pro-vs-base.md` | Base vs Pro decisions |
| `docs/reference/hooks/hooks-reference.md` | Working with plugin hooks |
| `docs/features/llm-harness.md` | Working on LLM Harnessing (Layers A–J) |
| `docs/features/memory/chat-client-integration.md` | Working on Chat-client Memory Bridge / Drawer |
| `docs/features/agent-skills.md` | Working on Agent Skills bundling / curation / catalogues |
| `docs/features/context-window-management.md` | Working on context-window validation / tiktoken / tool capping |
| `docs/features/paper-store.md` | Working on Paper Store tools, REST API, or remote dispatch |
| `docs/features/remote-sites.md` | Working on Remote Site Manager, connections, or CPT auto-discovery |
| `docs/reference/mcp-protocol-version-negotiation.md` | Working on MCP protocol compatibility / client negotiation |
| `docs/features/pro-toolkit-optimization.md` | Working on Pro toolkit optimization classes |
| `docs/features/dietpi-pro-toolkit.md` | Working on DietPi server management tools |
| `docs/operations/production-hardening-guide.md` | Working on production security hardening (WAF, OAuth, DICOM) |
| `docs/developer/api-key-encryption.md` | Working on API key storage/encryption |
| `docs/developer/dicom-phi-handling.md` | Working on DICOM/healthcare PHI handling |
| `docs/reference/admin/security-settings.md` | Working on security admin settings |
| `addons/pro/includes/analytics/README.md` | Working on Shared Analytics Service / platform adapters |

## OpenAI Schema Compatibility

- OpenAI rejects `'mixed'` type and multi-type arrays `type:['string','number']` — use `anyOf` instead
- Array types **must** include `'items'`
- The `sanitize_parameters_for_openai()` method provides a safety net but tools should declare correct schemas

## Guest Tool Permissions

Guest execution requires `guest_request` flag in tool context. Pattern:
```php
if ( ! empty( $context['guest_request'] ) && ! empty( $context['assistant_id'] ) ) {
    // Allow guest bypass
}
```

---

## Coding Behavior Guidelines
<!-- Derived from Andrej Karpathy's observations on LLM coding pitfalls — https://github.com/forrestchang/andrej-karpathy-skills -->

**Tradeoff:** These guidelines bias toward caution over speed. For trivial tasks, use judgment.

### 1. Think Before Coding

**Don't assume. Don't hide confusion. Surface tradeoffs.**

Before implementing:
- State your assumptions explicitly. If uncertain, ask.
- If multiple interpretations exist, present them — don't pick silently.
- If a simpler approach exists, say so. Push back when warranted.
- If something is unclear, stop. Name what's confusing. Ask.

### 2. Simplicity First

**Minimum code that solves the problem. Nothing speculative.**

- No features beyond what was asked.
- No abstractions for single-use code.
- No "flexibility" or "configurability" that wasn't requested.
- No error handling for impossible scenarios.
- If you write 200 lines and it could be 50, rewrite it.

Ask yourself: "Would a senior engineer say this is overcomplicated?" If yes, simplify.

### 3. Surgical Changes

**Touch only what you must. Clean up only your own mess.**

When editing existing code:
- Don't "improve" adjacent code, comments, or formatting.
- Don't refactor things that aren't broken.
- Match existing style, even if you'd do it differently.
- If you notice unrelated dead code, mention it — don't delete it.

When your changes create orphans:
- Remove imports/variables/functions that YOUR changes made unused.
- Don't remove pre-existing dead code unless asked.

The test: Every changed line should trace directly to the user's request.

### 4. Goal-Driven Execution

**Define success criteria. Loop until verified.**

Transform tasks into verifiable goals:
- "Add validation" → "Write tests for invalid inputs, then make them pass"
- "Fix the bug" → "Write a test that reproduces it, then make it pass"
- "Refactor X" → "Ensure tests pass before and after"

For multi-step tasks, state a brief plan:
```
1. [Step] → verify: [check]
2. [Step] → verify: [check]
3. [Step] → verify: [check]
```

Strong success criteria let you loop independently. Weak criteria ("make it work") require constant clarification.

---

## Multi-Agent Ecosystem

This repository is developed by multiple AI coding agents. You (Claude Code) are one of them.

| Agent | Context File | Overlap with CLAUDE.md |
|-------|-------------|----------------------|
| **Claude Code** | This file (`CLAUDE.md`) | — |
| **GitHub Copilot** | `.github/copilot-instructions.md` | Shares coding standards, tool patterns |
| **GitHub Custom Agents** | `.github/agents/*.agent.md` | Role-specific only — defers to `AGENTS.md` / `CLAUDE.md` / `.context/` for shared rules (see layering rule below) |
| **OpenAI Codex** | `.codex/startup.sh` | Sandbox bootstrap only |
| **BMAD Agents** | `.bmad/agents/*.yaml` | Specialized workflow roles (6 agents) |

**Layering rule for `.github/agents/`:** Those files hold only agent-specific metadata + behavior (frontmatter, scope, examples, refusals). They MUST NOT restate naming/security/PHP-compat/architecture rules — those live in `AGENTS.md`, `CLAUDE.md`, and `.context/`. If you (Claude Code) are asked to author or edit a `*.agent.md` file, keep it slim and link to the canonical sources. See [`AGENTS.md` §2 "Layering rule"](AGENTS.md) for the full rule.

> **GSD Core upstream:** The GSD context-engineering methodology used by this project is now standardised as [`open-gsd/gsd-core`](https://github.com/open-gsd/gsd-core) (`npx @opengsd/gsd-core@latest`). NV oOS was an early adopter and implementation proving ground for the Discuss→Plan→Execute→Verify→Ship phase loop that gsd-core productises. The `.bmad/` agent definitions and `.context/` files in this repo remain NV oOS-specific instantiations of those patterns.

**Key points for Claude Code sessions:**
- Load `.context/conventions.md` + `.context/security-checklist.md` at minimum for every session.
- Load subsystem-specific context files (listed in "Context Engineering Files" above) only when working on that subsystem.
- **When editing files inside `includes/<folder>/`, also read `includes/<folder>/README.md` first.** Folder READMEs declare each folder's purpose, public surface, and neighbors, and tell you which `.context/*.md` files to also load. See [`docs/developer/folder-readme-convention.md`](docs/developer/folder-readme-convention.md). The same applies to `addons/pro/includes/<folder>/README.md`.
- If a BMAD workflow is active, the orchestrator agent coordinates — follow the phase gates documented in `CONTRIBUTING.md`.
- Do **not** duplicate work that another agent has already completed. Check `git log` for recent commits by other agents.

Full agent inventory: [`AGENTS.md`](AGENTS.md)

---

## Troubleshooting

### Common Claude Code pitfalls in this repo

| Symptom | Cause | Fix |
|---------|-------|-----|
| `str_contains()` lint failure | Base plugin requires PHP 7.4+ | Use `strpos( $haystack, $needle ) !== false` instead |
| `match` expression lint failure | Base plugin requires PHP 7.4+ | Use `switch`/`case` instead |
| Tool schema rejected by OpenAI | `'mixed'` type or missing `'items'` on arrays | Use `anyOf` for unions; always include `'items'` on arrays |
| PHPCS error on `shell_exec()` | WordPress.org compliance | Use `proc_open()` for external processes |
| Tests fail with "table not found" | Test DB not bootstrapped | Run `composer run test:install` first |
| Tests fail with "Class not found" in Docker | Bind mount cached stale `vendor/` | Run `docker compose down && docker compose up -d` |
| Paths mangled on Git Bash (`C:/Program Files/Git/...`) | MSYS path conversion | Use `bash bin/run-tests-docker.sh` (auto-handles it) |
| Pro tools missing at runtime | `WP_MCP_AI_BASE_VERSION` is `true` | Set to `false` or remove the constant |
| Context window too large | Loading all `.context/` files | Load only the subsystem files you need (GSD 30% rule) |
| Fatal error in `rest_dispatch_request` | `wrap_dispatch` param order mismatch with WP >= 6.5 | WP 6.5+ filter passes 5 params: `($result, $wp_rest_server, $request, $route, $handler)`. Ensure handler matches. See `includes/security/class-wp-mcp-ai-request-guard.php` for canonical pattern. |

### Useful debug commands

```bash
# Run unified health diagnostics (WP/PHP version, tool registry, DLQ, async queue, providers)
wp mcp-ai health
wp mcp-ai health --format=json

# Quick version info
wp mcp-ai version

# Check if plugin is active and which mode
wp option get wp_mcp_ai_settings --format=json | grep -i version

# List recently registered tools
wp eval "echo count(WP_MCP_AI_Tool_Registry::get_instance()->get_tools());"

# View recent plugin errors
wp option get wp_mcp_ai_recent_errors --format=json

# Clear all NV oOS caches (settings, tool registry, WP object cache)
wp mcp-ai cache clear

# Clear ring-buffer logs
wp mcp-ai log clear
wp mcp-ai log clear --type=errors --yes

# Retry a failed async job
wp mcp-ai queue retry <job-id>

# Show job details
wp mcp-ai queue show <job-id>

# Bulk retry failed jobs (with dry-run preview)
wp mcp-ai bulk retry-failed
wp mcp-ai bulk retry-failed --dry-run --limit=10

# Update an assistant from CLI
wp mcp-ai assistant update 42 --title="New Name" --model=gpt-4o

# Import an assistant from JSON
wp mcp-ai assistant import --file=assistant-42.json
```
