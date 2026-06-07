# NV oOS Graphify — Next Steps Implementation Plan

> **Date**: 2026-06-07 | **Status**: Phase 1 In Progress
>
> This document is the actionable next-steps plan derived from the [restructuring roadmap](./nvoos-base-restructuring-roadmap.md). It prioritises concrete, shippable milestones.

---

## Current state

| Artifact | Status | Branch / PR |
|---|---|---|
| Core plugin (`nvoos-graphify`) | ✅ Complete | `alpha-working` |
| AI addon bootstrap | ✅ Done | [#5279](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/pull/5279) |
| Roadmap v4.0 | ✅ Done | [#5280](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/pull/5280) |
| Admin architecture (Section/Registry) | ✅ Done | [#5279](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/pull/5279) |

---

## Priority 1: Ship the AI addon (complete Phase 1)

**Goal**: A user installs `nvoos-graphify` + `nvoos-graphify-ai` and can chat with an AI agent that has access to the knowledge graph.

### 1.1 Provider clients (week 1)

Extract 13 provider client implementations from the base plugin's `includes/infrastructure/providers/` into the AI addon. These already exist as `Nvoos\Core\Infrastructure\Provider\*Client` classes in `lib/core/` — the task is wiring them with real HTTP requests and settings.

| # | Task | Source (base plugin) | Target (AI addon) |
|---|---|---|---|
| 1 | OpenAI client | `class-wp-mcp-ai-openai-client.php` | Wire `OpenAiClient` with API key from settings |
| 2 | Google Gemini client | `class-wp-mcp-ai-gemini-client.php` | Wire `GeminiClient` |
| 3 | Anthropic client | `class-wp-mcp-ai-anthropic-client.php` | Wire `AnthropicClient` |
| 4 | Ollama client | `class-wp-mcp-ai-ollama-client.php` | Wire `OllamaClient` |
| 5 | DeepSeek client | `class-wp-mcp-ai-deepseek-client.php` | Wire `DeepSeekClient` |
| 6 | OpenRouter client | `class-wp-mcp-ai-openrouter-client.php` | Wire `OpenRouterClient` |
| 7 | HuggingFace client | `class-wp-mcp-ai-huggingface-client.php` | Wire `HuggingFaceClient` |
| 8 | Cloudflare client | `class-wp-mcp-ai-cloudflare-client.php` | Wire `CloudflareClient` |
| 9 | LM Studio client | `class-wp-mcp-ai-lmstudio-client.php` | Wire `LmStudioClient` |
| 10 | NVIDIA NIM client | `class-wp-mcp-ai-nvidia-client.php` | Wire `NvidiaNimClient` |
| 11 | DigitalOcean client | `class-wp-mcp-ai-digitalocean-client.php` | Wire `DigitalOceanClient` |
| 12 | Kimi client | `class-wp-mcp-ai-kimi-client.php` | Wire `KimiClient` |
| 13 | Baseten client | `class-wp-mcp-ai-baseten-client.php` | Wire `BasetenClient` |

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

---

## Priority 2: Platform addon scaffold

Once the AI addon ships, scaffold the Platform addon. Most of this is existing code in `addons/pro/` — the task is extraction and namespace migration, not rewrite.

### 2.1 Scaffold (1 day)

```
plugins/nvoos-graphify-platform/
├── nvoos-graphify-platform.php     # Bootstrap
├── composer.json                    # Requires: nvoos-graphify-ai
├── src/
│   ├── Plugin.php                   # Composition root
│   └── Admin/
│       └── PlatformSettings.php     # Registers sections via registry
```

### 2.2 Extract subsystems (2 weeks)

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

**Deliverable**: Install Platform addon → agents, skills, slash-commands, measurement, and federation all work.

---

## Priority 3: Tools addon

165+ standalone tools. All independent — no internal dependencies between tools. Register via `nvoos_graphify/register_tools`.

### 3.1 Scaffold + extract (2 weeks)

| Category | Count | Source |
|---|---|---|
| Content tools | ~40 | `includes/tools/class-wp-mcp-ai-tool-get-*.php`, `create-*.php`, `update-*.php`, `delete-*.php` |
| Media tools | ~30 | `includes/tools/class-wp-mcp-ai-tool-media-*.php` |
| Dev tools | ~25 | `includes/tools/class-wp-mcp-ai-tool-web-search.php`, `shell-exec.php`, `wp-cli.php` |
| SEO tools | ~15 | `includes/tools/class-wp-mcp-ai-tool-rank-math-*.php` |
| Workflow tools | ~20 | `includes/tools/class-wp-mcp-ai-tool-workflow-*.php` |
| Misc | ~35 | Various specialized tools |

**Deliverable**: Install Tools addon → 165+ extra tools available in the tool registry.

---

## Priority 4: Extensions addon

Three WordPress plugin integrations in one addon. Each self-gates.

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

## Priority 5: Engine + Pro (deferred)

These are lower priority — extract when demand exists.

### Engine (1 week)

| Subsystem | Source |
|---|---|
| OOS Engine | `lib/core/` + `lib/wordpress-adapter/` |
| Markup subsystem | Base plugin markup classes |
| Paper-store | `addons/paper-store/` |
| Crawl4AI | `includes/crawler/` |

### Pro (2 weeks)

| Subsystem | Source |
|---|---|
| Enterprise SaaS drivers | `includes/remote/drivers/` (Jira, Slack, M365, HubSpot, etc.) |
| 765+ pro tools | `addons/pro/` |
| OAuth broker | `includes/remote/class-*-oauth-*.php` |

---

## Timeline summary

| Week | Deliverable |
|---|---|
| 1-2 | Provider clients working, SSE streaming, chat endpoint |
| 2-3 | Admin chat UI, embeddings + RAG |
| 3-4 | Agent memory, integration test → **AI addon ships** |
| 4-5 | Platform addon scaffold + extract subsystems |
| 5-6 | Tools addon scaffold + extract 165 tools |
| 6-7 | Extensions addon (Elementor + WooCommerce + JetEngine) |
| 7+ | Engine + Pro (as demand dictates) |

---

## Dependency order

```
nvoos-graphify (core) ← must ship first
│
├── nvoos-graphify-ai ← Priority 1 (in progress)
│   └── nvoos-graphify-platform ← Priority 2 (can start during AI Week 3)
│
├── nvoos-graphify-tools ← Priority 3 (independent — can parallel with AI)
├── nvoos-graphify-extensions ← Priority 4 (independent)
├── nvoos-graphify-engine ← Priority 5 (deferred)
└── nvoos-graphify-pro ← Priority 5 (deferred)
```

Tools and Extensions can be worked on in parallel with AI completion — they have no AI dependency and only need the core.
