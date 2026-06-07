# NV oOS Graphify — Next Steps Implementation Plan

> **Date**: 2026-06-07 | **Status**: Phase 1 In Progress
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

---

## Priority 1: Ship the AI addon (complete Phase 1)

**Goal**: A user installs `nvoos-graphify` + `nvoos-graphify-ai` and can chat with an AI agent that has access to the knowledge graph.

**Can parallel with**: Tools addon (Priority 3) and Extensions (Priority 4) — these don't need AI.

### 1.1 Provider clients (week 1)

Extract 13 provider client implementations from the base plugin's `includes/infrastructure/providers/` into the AI addon. These already exist as `Nvoos\Core\Infrastructure\Provider\*Client` classes in `lib/core/` — the task is wiring them with real HTTP requests and settings.

| # | Task | Source (base plugin) |
|---|---|---|
| 1 | OpenAI client | `class-wp-mcp-ai-openai-client.php` |
| 2 | Google Gemini client | `class-wp-mcp-ai-gemini-client.php` |
| 3 | Anthropic client | `class-wp-mcp-ai-anthropic-client.php` |
| 4 | Ollama client | `class-wp-mcp-ai-ollama-client.php` |
| 5 | DeepSeek client | `class-wp-mcp-ai-deepseek-client.php` |
| 6 | OpenRouter client | `class-wp-mcp-ai-openrouter-client.php` |
| 7 | HuggingFace client | `class-wp-mcp-ai-huggingface-client.php` |
| 8 | Cloudflare client | `class-wp-mcp-ai-cloudflare-client.php` |
| 9 | LM Studio client | `class-wp-mcp-ai-lmstudio-client.php` |
| 10 | NVIDIA NIM client | `class-wp-mcp-ai-nvidia-client.php` |
| 11 | DigitalOcean client | `class-wp-mcp-ai-digitalocean-client.php` |
| 12 | Kimi client | `class-wp-mcp-ai-kimi-client.php` |
| 13 | Baseten client | `class-wp-mcp-ai-baseten-client.php` |

**Approach**: Each provider client extends `AbstractProviderClient` from `lib/core`. The AI addon's `CoreBridge::registerBuiltinProviders()` already instantiates them. The gap is the actual HTTP request logic — each client needs its `send()` method implemented.

**Deliverable**: All 13 providers respond to chat requests in the admin test UI.

### 1.2 SSE streaming + chat orchestration (week 1-2)

| # | Task | Details |
|---|---|---|
| 1 | SSE handler | Extract `SseHandler` from base plugin — sends `data: {...}\n\n` chunks to the browser |
| 2 | Context window management | Truncate conversation history when it exceeds model limits |
| 3 | Tool-calling loop | Agentic loop: AI responds → tool calls → execute → feed results back → AI responds |
| 4 | Chat REST endpoint | `POST /nvoos-graphify-ai/v1/chat` — accepts messages, returns SSE stream or JSON |
| 5 | Error handling | Provider errors → user-friendly messages, rate limit backoff |

**Deliverable**: `curl -X POST .../chat -d '{"messages":[{"role":"user","content":"Hello"}]}'` returns a streaming AI response.

### 1.3 Admin chat UI (week 2)

A "Chat" tab on the NVOOS AI page with a simple chat interface for testing.

| # | Task |
|---|---|
| 1 | JS chat client — text input + message list + SSE consumer |
| 2 | Provider/model selector dropdown at top of chat |
| 3 | Display tool calls inline (collapsible) |
| 4 | Display token usage / cost after each response |

**Deliverable**: Admin can type a message, select a provider, and get an AI response that can query the knowledge graph.

### 1.4 Embeddings + RAG (week 2-3)

| # | Task |
|---|---|
| 1 | Vector embedding service — generate embeddings for graph nodes |
| 2 | Cosine similarity search — find semantically similar nodes |
| 3 | RAG retrieval — inject relevant graph nodes into chat context |
| 4 | Embeddings-on-ingest cron — auto-embed new nodes |

**Deliverable**: AI can answer "What content is related to X?" by searching the graph semantically.

### 1.5 Agent memory (week 3)

| # | Task |
|---|---|
| 1 | Memory store — save conversation summaries as graph nodes |
| 2 | Memory recall — retrieve relevant memories for current conversation |
| 3 | Memory mining — retroactive transcript analysis |
| 4 | Memory decay — older memories lose relevance |
| 5 | Provenance tracking — which conversation produced which memory |

**Deliverable**: AI remembers past conversations and references them in new ones.

### 1.6 Integration test (week 3)

```
Given: nvoos-graphify + nvoos-graphify-ai active
When:  User clicks "Build Graph" then opens AI Chat
Then:  AI can answer "What are the most connected nodes in my graph?"
       AI can answer "Find content similar to [post title]"
       AI can answer "What did we discuss last time about [topic]?"
```

**Milestone**: AI addon ships. 2 plugins → full AI knowledge graph experience.

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

## Timeline summary

| Week | Deliverable | Parallel? |
|---|---|---|
| 1-2 | AI: provider clients, SSE streaming, chat endpoint | Tools start (week 1) |
| 2-3 | AI: admin chat UI, embeddings + RAG | Extensions start (week 2) |
| 3-4 | AI: agent memory, integration test → **AI addon ships** | Platform + Engine start (week 3) |
| 4-5 | Platform: agents, skills, measurement, federation | Engine continues |
| 5-7 | Engine: markup, paper-store, crawler → **Platform + Engine ship** | Pro-core starts (week 5) |
| 7+ | Pro addons: extract toolkits on demand | — |

---

## Dependency order

```
nvoos-graphify (core) ← must ship first (✅ done)
│
├── nvoos-graphify-tools ──────── ✅ no AI dep — can parallel with AI
├── nvoos-graphify-extensions ─── ✅ no AI dep — can parallel with AI
│
└── nvoos-graphify-ai ─────────── 🔄 in progress
    ├── nvoos-graphify-platform ── needs AI
    ├── nvoos-graphify-engine ──── needs AI
    └── nvoos-graphify-pro-* ───── needs AI
```

**Unix honesty**: Nothing loads what it doesn't use. A content manager installing `nvoos-graphify` + `nvoos-graphify-tools` never touches an AI provider class.
