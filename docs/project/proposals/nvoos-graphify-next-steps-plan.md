# NV oOS Graphify — Next Steps Implementation Plan

> **Date**: 2026-06-07 | **Status**: Phase 1 Complete — AI addon ready to ship
>
> This document is the actionable next-steps plan derived from the [restructuring roadmap](./nvoos-base-restructuring-roadmap.md). It prioritises concrete, shippable milestones.
>
> **Architecture principle (Unix)**: Each addon depends only on what it actually uses. Core works alone with zero API keys. Tools and Extensions don't need AI — they never load a provider. AI is the gateway only for things that call an LLM.

---

## Architecture at a glance

```
nvoos-graphify (core — zero API keys, works alone)
│
├── nvoos-graphify-tools       ← needs core ONLY (165 tools, zero AI)
├── nvoos-graphify-extensions  ← needs core ONLY (widgets, zero AI)
│
└── nvoos-graphify-ai          ← needs core + API key
    ├── nvoos-graphify-platform  ← needs AI (agents, skills, measurement, federation)
    ├── nvoos-graphify-engine    ← needs AI (OOS engine, markup, paper-store, crawler)
    └── nvoos-graphify-pro-*     ← needs AI (10 enterprise addons)
```

| Tier | Addons | User story | Install count |
|---|---|---|---|
| Free | core + tools + extensions | "Build a graph, get more tools, integrate my plugins" | 1-3 plugins, zero keys |
| AI | + ai | "Add AI chat and content generation to my graph" | +1 plugin, 1 API key |
| Platform | + platform + engine | "Agents, skills, measurement, cross-platform engine" | +2 plugins |
| Enterprise | + pro-* | "CRM, legal, healthcare, e-commerce toolkits" | +1-10 plugins |

---

## Current state

| Artifact | Status | Branch / PR |
|---|---|---|
| Core plugin (`nvoos-graphify`) | ✅ Complete | `alpha-working` |
| AI addon bootstrap | ✅ Done | [#5279](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/pull/5279) |
| Roadmap v4.0 (Unix honesty) | ✅ Done | [#5280](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/pull/5280) |
| Admin architecture (Section/Registry) | ✅ Done | [#5279](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/pull/5279) |
| **Provider clients (13 providers)** | ✅ **Complete** | [#5280](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/pull/5280) |
| **SSE streaming + chat orchestration** | ✅ **Complete** | [#5280](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/pull/5280) |
| **Admin chat UI (JS client)** | ✅ **Complete** | [#5280](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/pull/5280) |
| **Embeddings + RAG** | ✅ **Complete** | [#5280](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/pull/5280) |
| **Agent memory** | ✅ **Complete** | [#5280](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/pull/5280) |

---

## Priority 1: Ship the AI addon (complete Phase 1) ✅ DONE

**Goal**: A user installs `nvoos-graphify` + `nvoos-graphify-ai` and can chat with an AI agent that has access to the knowledge graph.

**Can parallel with**: Tools addon (Priority 3) and Extensions (Priority 4) — these don't need AI.

### 1.1 Provider clients ✅ Complete (2026-06-07)

All 13 provider clients are fully implemented in `lib/core/src/Infrastructure/Provider/`. Each supports `chat()`, `stream()`, and `listModels()`.

| # | Provider | Client class | Notes |
|---|---|---|---|
| 1 | OpenAI | `OpenAiClient` | Extends `OpenAiCompatibleClient` |
| 2 | Google Gemini | `GeminiClient` | Custom implementation — message/tool conversion, response normalization |
| 3 | Anthropic | `AnthropicClient` | Custom implementation — message/tool conversion, `x-api-key` auth |
| 4 | Ollama | `OllamaClient` | Local — no API key required (`requiresApiKey() → false`) |
| 5 | DeepSeek | `DeepSeekClient` | OpenAI-compatible |
| 6 | OpenRouter | `OpenRouterClient` | OpenAI-compatible |
| 7 | HuggingFace | `HuggingFaceClient` | OpenAI-compatible |
| 8 | Cloudflare | `CloudflareClient` | OpenAI-compatible — runtime `account_id` substitution |
| 9 | LM Studio | `LmStudioClient` | Local — no API key required (`requiresApiKey() → false`) |
| 10 | NVIDIA NIM | `NvidiaNimClient` | OpenAI-compatible |
| 11 | DigitalOcean | `DigitalOceanClient` | OpenAI-compatible |
| 12 | Kimi | `KimiClient` | OpenAI-compatible (Moonshot) |
| 13 | Baseten | `BasetenClient` | OpenAI-compatible — newly created |

**Implementation highlights**:
- `OpenAiCompatibleClient` base handles 10 providers with shared `chat()`, `stream()`, and `listModels()` implementations, real HTTP requests via domain-owned `HttpClientInterface::send()`
- `requiresApiKey()` hook lets local providers (Ollama, LM Studio) skip API key checks
- Cloudflare `getDefaultBaseUrl()` resolves `cloudflare_account_id` from settings at runtime
- All provider responses are normalized to an OpenAI-compatible shape (`choices[0].message.content`)

### 1.2 SSE streaming + chat orchestration ✅ Complete (2026-06-07)

| # | Task | Status |
|---|---|---|
| 1 | SSE handler | ✅ `SseHandler::sendHeaders()` centralizes PHP output buffering (`ini_set`, `wp_ob_end_flush_all`, `ob_end_clean`) |
| 2 | Chat REST endpoint | ✅ `POST /nvoos-graphify/v1/ai/chat` — `ChatController` delegates to `ChatOrchestrator` |
| 3 | Tool-calling loop | ✅ `ChatOrchestrator::handleChatStreaming()` — agentic loop with tool execution events |
| 4 | True token-by-token streaming | ✅ `ProviderRouter::stream()` → provider `stream()` with `$onChunk` callback → SSE `content_block_delta` events |
| 5 | Error handling | ✅ Provider errors → normalized WP_Error → SSE error event + `[DONE]` |

**Implementation highlights**:
- `ChatOrchestrator::handleChatStreaming()` uses `$this->providers->stream()` with a `$onChunk` callback that sends real-time SSE delta events to the browser — no more simulated chunking
- All 3 provider families (OpenAI-compatible, Gemini, Anthropic) support SSE streaming with proper protocol-specific parsing
- `SseHandler::sendHeaders()` is idempotent — safe to call from multiple layers — and handles PHP, WordPress, and nginx buffering
- `ChatController` is now a thin layer that delegates entirely to `ChatOrchestrator`

### 1.3 Admin chat UI ✅ Complete (2026-06-07)

| # | Task | Status |
|---|---|---|
| 1 | JS chat client | ✅ `assets/js/graphify-ai-chat.js` — SSE consumer using `ReadableStream`, `buildHistory()` for conversation context |
| 2 | Provider/model selector | ✅ Dropdowns in toolbar, populated from server config |
| 3 | Tool calls inline | ✅ Collapsible `<details>` elements with tool name + result |
| 4 | Cost display | ✅ Token count + dollar cost in toolbar |

**Implementation highlights**:
- `ChatInterface` section class extends core `Section` pattern — registered as `ai_chat_ui` tab on the NV Graphify settings page
- `render_wrapper()` override prevents wrapping in `<table class="form-table">`
- JS uses the Fetch API with `response.body.getReader()` for true SSE streaming — no polling
- Configuration passed via `wp_add_inline_script` includes REST URL, nonce, 13 providers, and i18n strings
- CSS follows WordPress admin design language — blue user bubbles, gray assistant bubbles, pulse animation for thinking state

**Files created**:
- `src/Admin/Sections/ChatInterface.php`
- `assets/js/graphify-ai-chat.js`
- `assets/css/graphify-ai-chat.css`

### 1.4 Embeddings + RAG ✅ Complete (2026-06-07)

| # | Task | Status |
|---|---|---|
| 1 | Vector embedding service | ✅ `EmbeddingService` — `embed()` single, `embedBatch()` batch, calls provider `/embeddings` API |
| 2 | Cosine similarity search | ✅ `RagRetriever` — full scan with `cosineSimilarity()`, JSON + binary vector decoding |
| 3 | RAG retrieval | ✅ `augmentMessages()` — prepends system message with relevant graph node context |
| 4 | Embeddings-on-ingest cron | ✅ `EmbeddingsOnIngest` — subscribes to `nvoos_graphify/after_build`, processes 20 nodes/batch, self-reschedules |

**Implementation highlights**:
- `EmbeddingService` resolves API keys per provider, falling back from local providers (Ollama, LM Studio) to OpenAI
- `RagRetriever` configurable `MIN_SIMILARITY` (default 0.3) and `topK` (default 5)
- Batch embedding reduces API round-trips — 20 texts in one request
- DB already has `nvoos_graphify_embeddings` table with `(node_id, model)` unique key — `Db::upsertEmbedding()` handles insert/update
- `EmbeddingsOnIngest::processBatch()` self-reschedules with backoff (300s) on API errors

**Files created**:
- `src/Embeddings/EmbeddingService.php`
- `src/Embeddings/RagRetriever.php`
- `src/Embeddings/EmbeddingsOnIngest.php`

### 1.5 Agent memory ✅ Complete (2026-06-07)

| # | Task | Status |
|---|---|---|
| 1 | Memory store | ✅ `AgentMemory::store()` — saves summaries as `agent_memory` type graph nodes with TTL |
| 2 | Memory recall | ✅ `recall()` — semantic search via `RagRetriever` + exponential decay scoring |
| 3 | Memory mining | ✅ `buildMiningPrompt()` — creates LLM prompt for retroactive transcript analysis |
| 4 | Memory decay | ✅ Exponential decay with 7-day half-life, floors at 10% of original similarity |
| 5 | Provenance tracking | ✅ `session_id` + `stored_at` stored in node properties, memory-stored action fired |

**Implementation highlights**:
- Memory nodes stored in the existing `nvoos_graphify_nodes` table with type `agent_memory` — no new tables needed
- Decay uses `pow(0.5, ageSeconds / DECAY_HALF_LIFE)` — after one week relevance halves, after two weeks quarters
- `purgeExpired()` cleans nodes past their TTL (default 30 days) — hooked to `after_build`
- `buildMemoryContext()` produces system messages like: `"1. (3 days ago) Discussed Q4 marketing strategy..."`

**Files created**:
- `src/Memory/AgentMemory.php`

### 1.6 Integration test

```
Given: nvoos-graphify + nvoos-graphify-ai active
When:  User clicks "Build Graph" then opens AI Chat
Then:  AI can answer "What are the most connected nodes in my graph?"
       AI can answer "Find content similar to [post title]"
       AI can answer "What did we discuss last time about [topic]?"
```

**Milestone**: AI addon ships. 2 plugins → full AI knowledge graph experience. ✅

---

## Priority 2: Platform + Engine (under AI)

These depend on AI for LLM access. Platform and Engine can be built in parallel with each other.

### 2.1 Platform scaffold (1 day)

```
plugins/nvoos-graphify-platform/
├── nvoos-graphify-platform.php     # Bootstrap, Requires Plugins: nvoos-graphify-ai
├── composer.json
├── src/
│   ├── Plugin.php
│   └── Admin/
│       └── PlatformSettings.php     # Registers sections via registry
```

### 2.2 Platform subsystems (2 weeks)

| Subsystem | Source (base plugin) | Target |
|---|---|---|
| Agent role system | `includes/assistants/` + `includes/admin/class-wp-mcp-ai-assistant-*.php` | `src/Agents/` |
| Skills | `includes/skills/` + `class-wp-mcp-ai-skill-*.php` | `src/Skills/` |
| Slash-commands | `includes/slash-commands/` | `src/SlashCommands/` |
| Harness | `includes/harness/` | `src/Harness/` |
| Measurement | `includes/measurement/` | `src/Measurement/` |
| Professions | `includes/professions/` | `src/Professions/` |
| A2A | `includes/a2a/` | `src/A2A/` |
| ACP | `includes/acp/` | `src/ACP/` |
| Federation | `includes/federation/` | `src/Federation/` |
| Blueprints | `includes/blueprints/` | `src/Blueprints/` |

### 2.3 Engine (1 week)

Engine is the AI addon's cross-platform foundation — it already autoloads `nvoos/core` and `nvoos/wordpress-adapter`. Formalising this as an addon adds markup, paper-store, and crawler.

| Subsystem | Source | Target |
|---|---|---|
| OOS Engine | `lib/core/` + `lib/wordpress-adapter/` | Already loaded by AI |
| Markup subsystem | Base plugin markup classes | `src/Markup/` |
| Paper-store | `addons/paper-store/` | `src/PaperStore/` |
| Crawl4AI | `includes/crawler/` | `src/Crawler/` |

**Milestone**: Platform + Engine addons ship. 4 plugins → full AI platform.

---

## Priority 3: Tools addon

165+ standalone tools. **Zero AI dependency** — only needs core. Can be built in parallel with AI.

### 3.1 Scaffold + extract (2 weeks)

| Category | Count | Source |
|---|---|---|
| Content tools | ~40 | `includes/tools/class-wp-mcp-ai-tool-get-*.php`, `create-*.php`, `update-*.php`, `delete-*.php` |
| Media tools | ~30 | `includes/tools/class-wp-mcp-ai-tool-media-*.php` |
| Dev tools | ~25 | `includes/tools/class-wp-mcp-ai-tool-web-search.php`, `shell-exec.php`, `wp-cli.php` |
| SEO tools | ~15 | `includes/tools/class-wp-mcp-ai-tool-rank-math-*.php` |
| Workflow tools | ~20 | `includes/tools/class-wp-mcp-ai-tool-workflow-*.php` |
| Misc | ~35 | Various specialized tools |

**Deliverable**: Install Tools addon → 165+ extra tools available. Zero API keys needed.

---

## Priority 4: Extensions addon

Three WordPress plugin integrations in one addon. **Zero AI dependency** — only needs core. Each self-gates. Can be built in parallel with AI.

### 4.1 Elementor (2 days)

| Task | Details |
|---|---|
| Graph explorer widget | Embed Cytoscape.js explorer in Elementor pages |
| Related content widget | "You might also like" widget |
| Register widgets | Via `elementor/widgets/register` action |
| Gate | `class_exists('Elementor\\Plugin')` check |

### 4.2 WooCommerce (1 day)

| Task | Details |
|---|---|
| Product nodes | Index products as graph nodes |
| Order graph | Order → product → category edges |
| Gate | `class_exists('WooCommerce')` check |

### 4.3 JetEngine (3 days)

| Task | Details |
|---|---|
| CCT nodes | Index Custom Content Types as graph nodes |
| Form submissions | Index JetFormBuilder submissions |
| Memory CCT bridge | Connect agent memory CCT to graph |
| Gate | `function_exists('jet_engine')` check |

**Deliverable**: Install Extensions addon → any active WP plugin integration activates automatically.

---

## Priority 5: Pro addons (under AI)

30 toolkits consolidated into 10 addons. All under AI because they call the LLM. Each installs independently — a user picks the toolkits they need.

### Pro core (infrastructure — week 1)

| Addon | Toolkits | Lines |
|---|---|---|
| `nvoos-graphify-pro-core` | Vault, vector-storage, skills-manager | ~2,500 |

All other pro addons depend on this.

### Pro addons (extract — 2 weeks each, parallelisable)

| # | Addon | Toolkits | Lines |
|---|---|---|---|
| 1 | `pro-core` | Vault, vector-storage, skills-manager (infrastructure) | 2,500 |
| 2 | `pro-business` | CRM (17K), E-commerce (26K), Project Management (4K), Financial Planner (13K), Calendar (6K), Social Media (15K) | 81,000 |
| 3 | `pro-media` | Image Production (10K), Video Production (4K), Comic Creation (4K), Media Toolkit (2K) | 20,000 |
| 4 | `pro-dev` | Architect Agent (4K), Architectural Design (16K), Site Creator (11K), AI Tool Builder (5K), Developer Tools (5K), Math & Logic (3K) | 44,000 |
| 5 | `pro-healthcare` | Health & Wellness (29K), Imaging, Medical Vitals | 29,000 |
| 6 | `pro-legal` | Law Firm (19K), CRE Debt (20K), Regulatory Registration (15K) | 54,000 |
| 7 | `pro-education` | Quiz System (5K), ECA Management (15K) | 20,000 |
| 8 | `pro-content` | Document Generation (11K), Multilingual (2K), Chat Channels (17K) | 30,000 |
| 9 | `pro-data` | Analytics (6K), Extended Cognition (3K), Places Management (3K) | 12,000 |
| 10 | `pro-platform` | Orchestration (10K), Research (8K), Cloudways (7K), Google Workspace (3K), Email Marketing (3K), Remote Connections (2K), Capture (2K), misc (~9K) | 44,000 |

**Deliverable**: Install any pro addon → that toolkit's tools appear in the AI chat.

---

## AI addon file inventory (what was built)

### lib/core changes (provider infrastructure)

| File | Change |
|---|---|
| `lib/core/.../Provider/OpenAiCompatibleClient.php` | `requiresApiKey()` hook; full SSE streaming in `stream()` |
| `lib/core/.../Provider/OllamaClient.php` | `requiresApiKey()` → false |
| `lib/core/.../Provider/LmStudioClient.php` | `requiresApiKey()` → false |
| `lib/core/.../Provider/CloudflareClient.php` | runtime `account_id` URL resolution |
| `lib/core/.../Provider/BasetenClient.php` | **New** — 13th provider |
| `lib/core/.../Provider/GeminiClient.php` | SSE streaming via `streamGenerateContent` |
| `lib/core/.../Provider/AnthropicClient.php` | SSE streaming with `content_block_delta` |
| `lib/core/.../Chat/ChatOrchestrator.php` | True token-by-token streaming via `stream()` + `$onChunk` |
| `lib/core/.../Streaming/SseHandler.php` | Centralized PHP buffering setup |
| `lib/core/.../Contract/HttpClientInterface.php` | **New** — domain-owned HTTP client contract (`send()`) |
| `lib/core/.../Entity/HttpResponse.php` | **New** — immutable HTTP response value object |

**Dependencies removed**: `lib/core/composer.json` now requires only `php: ^8.1`. Removed 9 unused packages: `psr/container`, `psr/log`, `psr/cache`, `psr/event-dispatcher`, `psr/http-client`, `psr/http-factory`, `psr/http-message`, `nyholm/psr7`, `symfony/validator`. All 13 providers, 14 tools, and 1 test updated to use domain-owned contracts. See [#5280](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/pull/5280).

### AI addon new files

| File | Purpose |
|---|---|
| `src/Embeddings/EmbeddingService.php` | Vector embedding generation |
| `src/Embeddings/RagRetriever.php` | Cosine similarity semantic search |
| `src/Embeddings/EmbeddingsOnIngest.php` | Cron-based auto-embedding |
| `src/Memory/AgentMemory.php` | Store, recall, decay, purge, mining |
| `src/Admin/Sections/ChatInterface.php` | Chat UI section |
| `assets/js/graphify-ai-chat.js` | SSE chat client |
| `assets/css/graphify-ai-chat.css` | Chat UI styles |

### AI addon modified files

| File | Change |
|---|---|
| `src/CoreBridge.php` | Added `$embeddings`, `$rag`, `$memory` services; registered Baseten |
| `src/Plugin.php` | Registers `EmbeddingsOnIngest` and `AgentMemory` hooks |
| `src/Admin/AiSettingsPage.php` | Added `ai_chat_ui` tab |
| `src/Rest/ChatController.php` | Cleaned up duplicate header logic |

---

## Timeline summary

| Week | Deliverable | Status |
|---|---|---|
| 1-2 | AI: provider clients, SSE streaming, chat endpoint | ✅ Complete (2026-06-07) |
| 2-3 | AI: admin chat UI, embeddings + RAG | ✅ Complete (2026-06-07) |
| 3-4 | AI: agent memory, integration test → **AI addon ships** | ✅ Complete (2026-06-07) |
| 4-5 | Platform: agents, skills, measurement, federation | ❌ Not started |
| 5-7 | Engine: markup, paper-store, crawler → **Platform + Engine ship** | ❌ Not started |
| 7+ | Pro addons: extract toolkits on demand | ❌ Not started |

---

## Dependency order

```
nvoos-graphify (core) ← must ship first (✅ done)
│
├── nvoos-graphify-tools ──────── ✅ no AI dep — can parallel with AI
├── nvoos-graphify-extensions ─── ✅ no AI dep — can parallel with AI
│
└── nvoos-graphify-ai ─────────── ✅ complete — ready to ship
    ├── nvoos-graphify-platform ── needs AI
    ├── nvoos-graphify-engine ──── needs AI
    └── nvoos-graphify-pro-* ───── needs AI
```

**Unix honesty**: Nothing loads what it doesn't use. A content manager installing `nvoos-graphify` + `nvoos-graphify-tools` never touches an AI provider class.
