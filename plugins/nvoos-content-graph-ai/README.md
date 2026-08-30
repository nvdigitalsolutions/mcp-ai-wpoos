# NV oOS Content Graph — AI

## Purpose

AI chat assistant addon for NV oOS Content Graph — adds conversational AI with 13 provider backends, SSE streaming, tool-calling loop, 13 AI-powered tools, embeddings, RAG, and agent memory to the knowledge graph.

## Tier

| | |
|---|---|
| **Distribution** | Addon plugin — requires `nvoos-content-graph` |
| **PHP target** | 8.1+ |
| **License** | Proprietary (commercial license required) |
| **Loaded by** | `nvoos-content-graph-ai.php` → `plugins_loaded` priority 5 (before core's priority 10, to register settings early) |
| **Requires Plugins** | `nvoos-content-graph` (WP 6.5+ header) |
| **Framework deps** | `nvoos/core`, `nvoos/wordpress-adapter` (bundled via Composer) |

## Repository Sync

This directory is subtree-synced to its standalone repository [`nvdigitalsolutions/nvoos-content-graph-ai`](https://github.com/nvdigitalsolutions/nvoos-content-graph-ai) via `.github/workflows/sync-nvoos-content-graph-ai.yml` (push-triggered on `main`/`alpha-working`).

## Current State — Practical Assessment

### ✅ What Works

| Feature | Implementation | Notes |
|---|---|---|
| 13 AI providers | `CoreBridge::registerBuiltinProviders()` | OpenAI, Gemini, Anthropic, DeepSeek, Ollama, OpenRouter, HuggingFace, Cloudflare, LM Studio, NVIDIA NIM, DigitalOcean, Kimi, Baseten |
| Provider routing | `nvoos/core` `ProviderRouter` | Fallback chain, automatic model selection |
| SSE streaming | `SseHandler` via `ChatOrchestrator` | Real-time token streaming to chat UI |
| Tool-calling loop | `ChatOrchestrator` max 5 iterations | Prevents runaway loops |
| 13 AI tools | `CoreBridge::registerAiTools()` | Summarize, Translate, Sentiment, Entities, QA, Excerpts, Alt Text, Image Analysis, Categorize, Recommendations, Freshness, Semantic Search, Embeddings |
| Graph tools in chat | `Adapter\GraphToolAdapter` | Bridges all parent-plugin graph tools into the `nvoos/core` registry so the agentic loop can call them |
| Chat Tester | `ChatInterface` + `ChatController` | Admin tab with SSE streaming, live provider/model selectors, tool presets, graph-context toggle, cost badge, and raw SSE debug log |
| Tool capability enforcement | `CoreBridge` → `setAuthProvider()` | Per-tool `edit_posts`/`manage_options` checks are enforced for every agentic-loop tool call |
| Embeddings + RAG | `EmbeddingService` + `RagRetriever` | Vector storage in `nvoos_content_graph_embeddings` table |
| Agent memory | `AgentMemory` | RAG-based recall of prior conversations |
| AI settings | `addDefaultSettings()` filter | Merged into core's `nvoos_content_graph_settings` option |
| Admin UI | `AiSettingsPage` | Injects into Content Graph's SettingsRegistry |

### ⚠️ Practical Concerns

| # | Issue | Severity | Status |
|---|---|---|---|
| 1 | **AI keys bypass base plugin encryption** — `CredentialResolver` reads keys from `nvoos_content_graph_settings` via raw `get_option()` (L133). The base plugin has `WP_MCP_AI_Api_Key_Store` with AES-256-GCM encryption, but it operates on `wp_mcp_ai_settings`, not `nvoos_content_graph_settings`. When the base plugin IS active, keys entered through the base plugin's UI ARE encrypted; keys entered through Content Graph's own settings page go to `nvoos_content_graph_settings` unencrypted. The resolver's priority-2 fallback (L104-108) correctly reads encrypted keys from the base plugin, but priority-1 (Content Graph's own store) is plaintext. | 🟡 Medium | Consider encrypting Content Graph's own stored keys, or rely entirely on base plugin's `Api_Key_Store` |
| 2 | **Chat UI location** — The AI addon ships with an admin-only chat tester (`ChatInterface.php` + `content-graph-ai-chat.js` / `content-graph-ai-sse.js`). This is intentional per the [next-steps plan](../../docs/project/proposals/nvoos-graphify-next-steps-plan.md) L44: "Admin chat UI (JS client)" — a testing panel, not a production chat widget. Since v1.0.3 the tester is a full SSE client (live providers/models, tool presets, graph context, cost badge) but remains deliberately tester-grade. The base+pro plugin's production chat UIs (`assets/js/chat.js` / `addons/pro/assets/spa-v2/`) were NOT planned for migration into the Content Graph ecosystem. The Platform addon may later add a frontend shortcode/block. | 🟢 By design | No action needed |
| 3 | **`lib/` autoloader fallback** at L55-73 of `nvoos-content-graph-ai.php` goes up 2 dirs to find `lib/core/` and `lib/wordpress-adapter/`. Works in monorepo, fragile in distributed ZIP. Build workflow's `composer install` should bundle these into `vendor/`. | 🟡 Medium | Verify build workflow handles this |
| 4 | **No deactivation cleanup** — missing `register_deactivation_hook` for Action Scheduler jobs and cron events | 🟡 Low | Add cleanup hook |
| 5 | **"One install, one API key" claim** — readme says this but 13 provider fields exist. Default is OpenAI (`gpt-4o`) so user only needs one key for basic use, but claim is slightly misleading. | 🟡 Low | Clarify wording |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraphAi\Plugin` | `src/Plugin.php` | Bootstrap (singleton composition root) |
| `NvoosContentGraphAi\CoreBridge` | `src/CoreBridge.php` | Plugin, REST controllers, all subsystems |
| `NvoosContentGraphAi\Settings` | `src/Settings.php` | All subsystems |
| `Nvoos\Core\Application\Chat\ChatOrchestrator` | `lib/core/src/…` | REST ChatController |
| `Nvoos\Core\Application\Provider\ProviderRouter` | `lib/core/src/…` | CoreBridge |
| `Nvoos\Core\Application\Tool\ToolRegistry` | `lib/core/src/…` | CoreBridge, parent plugin |

## Inputs / Outputs / Neighbors

- **Reads from:** `nvoos_content_graph_settings` option (AI keys merged via `nvoos_content_graph/default_settings` filter), core `NvoosContentGraph\ToolRegistry`, core custom tables (`nvoos_content_graph_embeddings`)
- **Writes to:** REST responses, SSE streams, AI provider APIs (OpenAI, Gemini, Ollama, etc.), `nvoos_content_graph_embeddings` table
- **Upstream callers:** WordPress REST API, `nvoos-content-graph` core (tool registration hook)
- **Downstream collaborators:** `nvoos-content-graph` core (`ToolRegistry`, `Contracts\Tool`, `Settings`), `nvoos/core` framework, 13 provider APIs
- **Events fired:** `nvoos_content_graph_ai/continue_chat` (Action Scheduler)
- **Events listened to:** `nvoos_content_graph/register_tools`, `nvoos_content_graph/default_settings`, `rest_api_init`

## Source Structure

```
src/
├── Adapter/
│   ├── CredentialResolver.php        # Resolves provider API keys from settings
│   ├── ContentGraphSettingsStore.php     # nvoos/core SettingsStoreInterface → nvoos_content_graph_settings
│   ├── GraphToolAdapter.php          # Bridges parent graph tools into nvoos/core ToolRegistry
│   └── WordPressHttpClient.php       # PSR-18 HTTP client wrapping wp_remote_*
├── Admin/
│   └── AiSettingsPage.php            # AI settings tab in Content Graph dashboard
├── Embeddings/
│   ├── EmbeddingService.php          # OpenAI/Gemini embeddings generation
│   ├── EmbeddingsOnIngest.php        # Auto-embeds new graph nodes
│   └── RagRetriever.php             # Vector similarity search for RAG
├── Memory/
│   └── AgentMemory.php              # Agent conversation memory (RAG-based)
├── Rest/
│   └── ChatController.php           # /wp-json/nvoos-content-graph/v1/ai/chat, /ai/chat/config, /ai/tools, /ai/models
├── Tools/                            # 13 AI tools
│   ├── AbstractAiTool.php           # Base class (injects ErrorFactory)
│   ├── SummarizeText.php            # Text summarization
│   ├── TranslateText.php            # Multi-language translation
│   ├── AnalyzeSentiment.php         # Sentiment analysis
│   ├── ExtractEntities.php          # Named entity extraction
│   ├── QuestionAnswering.php        # Q&A over provided context
│   ├── GenerateExcerpt.php          # Auto-excerpt generation
│   ├── GenerateImageAltText.php     # Alt text for WordPress images
│   ├── AnalyzeImage.php             # Image content description
│   ├── CategorizeContent.php        # Auto-categorization
│   ├── ContentRecommendation.php    # Content suggestions
│   ├── ContentFreshness.php         # Content staleness analysis
│   ├── SemanticSearch.php           # Semantic (vector) search
│   └── CreateTextEmbeddings.php     # Embedding generation tool
├── CoreBridge.php                    # Composition root for nvoos/core services
├── Plugin.php                        # Bootstrap singleton
└── README.md
```

`assets/js/` ships the tester client: `content-graph-ai-chat.js` (DOM + session storage) and `content-graph-ai-sse.js` (SSE parser aligned with the SPA-v2 wire contract).

## How it Works

### Startup Sequence

```
plugins_loaded (priority 5)
  └─ nvoos-content-graph-ai.php checks: is nvoos-content-graph active?
     └─ Plugin::instance()->register()
        ├─ CoreBridge::instance() — wires nvoos/core:
        │   ├─ Creates WordPress adapters (ErrorFactory, SettingsStore, etc.)
        │   ├─ Creates ProviderRouter, ToolRegistry, ChatOrchestrator
        │   ├─ Registers 13 AI provider clients
        │   └─ Registers 13 AI tools (also in parent ToolRegistry for compat)
        ├─ registerAdmin() — AiSettingsPage
        ├─ add_filter('nvoos_content_graph/default_settings') — merges AI defaults
        ├─ rest_api_init → ChatController::registerRoutes()
        ├─ EmbeddingsOnIngest::register() — auto-embeds on graph build
        ├─ AgentMemory::register() — memory hooks
        └─ add_action('nvoos_content_graph_ai/continue_chat') — async chat handler
```

### Chat Flow

```
User sends message
  → ChatController (REST POST /chat)
  → CoreBridge->chat (ChatOrchestrator)
     → ProviderRouter selects best provider
     → SSE streaming starts
     → Tool-calling loop (max 5 iterations):
        → AI decides to call a tool
        → ToolRegistry executes tool
        → Result fed back to AI
        → AI generates next response
     → Response streamed to client
```

### Tool Dual-Registration

Each AI tool is registered in **two** registries:
1. `nvoos/core` `ToolRegistry` — used by `ChatOrchestrator` for the tool-calling loop
2. `nvoos-content-graph` `ToolRegistry` — used by the graph's REST API and admin UI

This ensures tools work in both the AI chat context and the graph query context.

## Conventions

- Namespace: `NvoosContentGraphAi\` — PSR-4 mapped to `src/`.
- All providers implement `Nvoos\Core\Infrastructure\Provider\AbstractProviderClient` for uniform routing.
- AI settings (API keys, models, temperatures) are merged into the core's grouped `nvoos_content_graph_settings` option via the `nvoos_content_graph/default_settings` filter — no separate options table.
- `ChatOrchestrator` implements a tool-calling loop with a max of 5 iterations to prevent runaway loops.
- `CoreBridge` is the **single source of truth** for all nvoos/core service instances — no duplicate wiring.

## Tests

```bash
vendor/bin/phpunit tests/
```

## Also Load

- [`../../.context/conventions.md`](../../.context/conventions.md) — naming + style
- [`../../.context/security-checklist.md`](../../.context/security-checklist.md) — API key handling, SSRF
- [`../../.context/rest-api.md`](../../.context/rest-api.md) — REST patterns
- [`../../CLAUDE.md`](../../CLAUDE.md) — PHP compat + tool patterns

## See Also

- Changelog: [`CHANGELOG.md`](CHANGELOG.md) — release notes per version
- Required parent: [`../nvoos-content-graph/`](../nvoos-content-graph/) — core knowledge graph plugin
- Platform layer: [`../nvoos-content-graph-ai-platform/`](../nvoos-content-graph-ai-platform/) — agents, skills, slash-commands
- Framework: `lib/core/` — `nvoos/core` hexagonal architecture
- [`src/`](src/) — source code root
