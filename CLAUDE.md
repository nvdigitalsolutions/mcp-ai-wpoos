# NV oOS (Open Operator System) — Claude Code Context

> **This file is loaded every turn by Claude Code.** Keep it focused and actionable.
> Last reviewed: **May 31, 2026** · Version: **2.5**

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

NV oOS is a **WordPress plugin** providing an AI Assistant framework with ~960 tools (~195 base + ~765 Pro; live count via `WP_MCP_AI_Tool_Registry::get_tools()`), MCP protocol support, multi-provider AI (OpenAI, Gemini, Ollama, LM Studio, DeepSeek, OpenRouter, DigitalOcean Serverless Inference, Anthropic, HuggingFace, NVIDIA), multi-provider voice/realtime (OpenAI Realtime, Gemini Live), ACP (Agent Client Protocol), and Server-Sent Events streaming.

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
├── class-wp-mcp-ai-tool-registry.php   ← Tool registry singleton (~960 tools total)
├── tools/                              ← base tool implementations (~195 classes; live count is authoritative)
├── services/                           ← 20+ service classes
├── admin/                              ← WordPress admin UI
├── blueprints/                         ← Unified blueprint installer + import tools
├── slash-commands/                     ← /help, /ship, /compact, /context, etc.
├── integrations/                       ← JetEngine, Elementor, Auth0
├── a2a/                                ← Agent-to-Agent protocol
└── interfaces/                         ← PHP interfaces
addons/pro/
├── mcp-ai-wpoos-pro.php                ← Pro entry (auto-loaded, no WP plugin header)
└── includes/
    ├── tools/                          ← ~765+ pro tools
    │   ├── cloudways/                  ← Cloudways Toolkit (60 tools + API v2 client)
    │   ├── crm/                        ← CRM Toolkit (70+ tools, 5 phases)
    │   └── ...
    ├── cloudways/                      ← Cloudways API v2 OAuth client + helpers
    └── ...                             ← Pro admin, REST, services
```

## Security — Non-Negotiable

Every code change must:
- **Sanitize input**: `sanitize_text_field()`, `absint()`, `sanitize_email()`, `wp_kses_post()`
- **Escape output**: `esc_html()`, `esc_attr()`, `esc_url()`, `wp_json_encode()`
- **Check capabilities**: `current_user_can()` before every privileged operation
- **Verify nonces**: `check_ajax_referer()` or `wp_verify_nonce()` for state changes
- **ABSPATH guard**: Every non-root PHP file starts with `if ( ! defined( 'ABSPATH' ) ) { exit; }`
- **Prepared queries**: Always `$wpdb->prepare()` — never string-concatenate SQL

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
- **Constants:** `WP_MCP_AI_BASE_VERSION = true` (~195 base tool classes) or `false` (~830 total; live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative)
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

### Provider Clients

Ten providers supported. New across v1.1.15–v1.1.19:
- **OpenRouter** (`WP_MCP_AI_OpenRouter_Client`, v1.1.15) — unified gateway for OpenAI, Anthropic, Google, Meta, Mistral, and others via one API key
- **DigitalOcean Serverless Inference** (`WP_MCP_AI_DigitalOcean_Client`, v1.1.17) — OpenAI-compatible API at `https://inference.do-ai.run/v1`; Llama 3.3, DeepSeek-R1 distill, gpt-oss, plus native `/embeddings`
- **DeepSeek** (`WP_MCP_AI_DeepSeek_Client`, v1.1.15) — `reasoning_content` / `<think>…</think>` passthrough
- **LM Studio** (v1.1.15) — native cURL SSE streaming; native `/api/v0` opt-in; embeddings; bearer-token auth; capability-aware tool gating
- **Kimi (Moonshot AI)** (`WP_MCP_AI_Kimi_Client`, v1.1.19) — OpenAI-compatible API at `https://api.moonshot.cn/v1`; kimi-k2.6 (256K context, default), kimi-k2-thinking (CoT), moonshot-v1-8k/-32k/-128k

### Voice / Realtime API

Multi-provider voice support via a pluggable voice controller (`WP_MCP_AI_REST_Voice_Controller`, `includes/rest/class-wp-mcp-ai-rest-voice-controller.php`). Two providers registered by default:
- **OpenAI Realtime** (`WP_MCP_AI_OpenAI_Realtime_Client`) — WebRTC/S2S realtime voice via OpenAI's Realtime API.
- **Gemini Live** (`WP_MCP_AI_Gemini_Live_Client`) — realtime voice via Google's Gemini Live API.

Provider registration pattern: `$voice_controller->register_provider( new ProviderClient() )`. REST routes registered under `mcp-ai/v1/voice/*`.

### Slash Commands

Pattern: class with `execute( $args, $flags, $context )` returning string/array/WP_Error.
Registration via `$handler->register( 'name', array( 'handler' => ..., 'capability' => ..., 'aliases' => ... ) )`.
Located in `includes/slash-commands/commands/`.

### Paper Store

Flat-file storage layer (`includes/paper-store/`) for structured content management with Markdown+YAML drivers. Provides a lightweight, Git-friendly alternative to CPT-based storage for documentation, knowledge bases, and configuration artifacts. Pro addon (`addons/pro/includes/paper-store/`) adds a Markdown+YAML driver (via `symfony/yaml`), Git sync (`WP_MCP_AI_Pro_Paper_Store_Git_Sync`), admin UI, and import/export tools.

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

- **Discovery — base bundled skills:** `includes/bundled-skills/{slug}/SKILL.md`. Copied to `wp-content/uploads/mcp-ai-skills/` on first activation.
- **Discovery — Pro bundled skills:** `addons/pro/includes/bundled-skills/{slug}/SKILL.md`. The 28+ WordPress-developer skills curated from `Lonsdale201/wp-agent-skills` live here.
- **Third-party attribution:** any new bundled skill curated from an upstream catalogue must add an entry to the corresponding `THIRD_PARTY_NOTICES.md` (`includes/bundled-skills/THIRD_PARTY_NOTICES.md` or `addons/pro/includes/bundled-skills/THIRD_PARTY_NOTICES.md`) with attribution + license text.
- **Progressive disclosure:** assistants with the "Use progressive disclosure" checkbox enabled receive only a short `# Available Skills` catalogue (name + description) in their system prompt. The base-plugin `load_skill({ name })` tool returns the full SKILL.md only when the model decides a skill applies.
- **Remote catalogues (Pro):** [`WP_MCP_AI_Skill_Catalogue_Service`](addons/pro/includes/services/class-wp-mcp-ai-skill-catalogue-service.php) discovers skills in registered public Git repos via the GitHub trees API. [`WP_MCP_AI_Skill_Catalogue_REST_Controller`](addons/pro/includes/rest/class-wp-mcp-ai-skill-catalogue-rest-controller.php) exposes `mcp-ai-pro/v1/catalogues/*` endpoints. SSRF-safe HTTPS-only fetcher; reuses the existing extension allowlist and decompression-bomb cap.
- **Filters:** `wp_mcp_ai_skill_catalogue_manifest_ttl`, `wp_mcp_ai_skill_catalogue_refresh_cadence`.
- **Reference:** `docs/features/agent-skills.md` (full Phases 1–4 narrative).

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
| `.context/rest-api.md` | Working on REST endpoints |
| `.context/chat-ui.md` | Working on frontend chat |
| `.context/testing.md` | Writing PHPUnit tests |
| `.context/pro-vs-base.md` | Base vs Pro decisions |
| `docs/reference/hooks/hooks-reference.md` | Working with plugin hooks |
| `docs/features/llm-harness.md` | Working on LLM Harnessing (Layers A–H) |
| `docs/features/memory/chat-client-integration.md` | Working on Chat-client Memory Bridge / Drawer |
| `docs/features/agent-skills.md` | Working on Agent Skills bundling / curation / catalogues |

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
