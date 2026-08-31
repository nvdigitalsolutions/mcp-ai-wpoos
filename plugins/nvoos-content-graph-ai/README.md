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
| 1 | ~~**AI keys bypass base plugin encryption**~~ — **Fixed in 1.0.4.** `Security\CredentialStore` now stores all provider keys encrypted at rest (AES-256-GCM via the parent's `Remote\Crypto`) in a separate non-autoload option (`nvoos_content_graph_ai_credentials`), with masked rendering, a save-path strip filter, automatic legacy-plaintext migration, and WP-CLI tooling (`wp nvoos-cg-ai key-status`). See `CREDENTIALS-PLAN.md`. | ✅ Fixed | 1.0.4 |
| 2 | **Chat UI location** — The AI addon ships with an admin-only chat tester (`ChatInterface.php` + `content-graph-ai-chat.js` / `content-graph-ai-sse.js`). This is intentional per the [next-steps plan](../../docs/project/proposals/nvoos-graphify-next-steps-plan.md) L44: "Admin chat UI (JS client)" — a testing panel, not a production chat widget. Since v1.0.3 the tester is a full SSE client (live providers/models, tool presets, graph context, cost badge) but remains deliberately tester-grade. The base+pro plugin's production chat UIs (`assets/js/chat.js` / `addons/pro/assets/spa-v2/`) were NOT planned for migration into the Content Graph ecosystem. The Platform addon may later add a frontend shortcode/block. | 🟢 By design | No action needed |
| 3 | **`lib/` autoloader fallback** at L55-73 of `nvoos-content-graph-ai.php` goes up 2 dirs to find `lib/core/` and `lib/wordpress-adapter/`. Works in monorepo, fragile in distributed ZIP. Build workflow's `composer install` should bundle these into `vendor/`. | 🟡 Medium | Verify build workflow handles this |
| 4 | **No deactivation cleanup** — missing `register_deactivation_hook` for Action Scheduler jobs and cron events | 🟡 Low | Add cleanup hook |
| 5 | **"One install, one API key" claim** — readme says this but 13 provider fields exist. Default is OpenAI (`gpt-4o`) so user only needs one key for basic use, but claim is slightly misleading. | 🟡 Low | Clarify wording |

## Credential Security (1.0.4+)

Provider API keys are handled the same way as the base+pro plugin:

- **Encrypted at rest** — keys live in `nvoos_content_graph_ai_credentials` (non-autoload), encrypted with AES-256-GCM via the parent plugin's `NvoosContentGraph\Remote\Crypto` (key derived from WordPress salts; `gcm:` prefix).
- **Separate from settings** — keys are never written into `nvoos_content_graph_settings`. A `pre_update_option` filter routes any secret field arriving in a save into the encrypted store and strips it from the settings option.
- **Masked rendering** — stored keys are never echoed back into admin forms; a `**************` placeholder is shown. Submitting the placeholder keeps the stored key; a blank field deletes it.
- **Automatic migration** — plaintext keys saved by ≤ 1.0.3 are encrypted and removed from the settings option on activation, on first admin load, or on first read.
- **Resolution chain** — `CredentialResolver`: encrypted store → base plugin (`wp_mcp_ai_credentials` + WP 7.0 Connector DB) → `{PROVIDER}_API_KEY` env var → PHP constant.
- **Tooling** — `wp nvoos-cg-ai migrate-keys`, `wp nvoos-cg-ai key-status`, and a Credential Status table on the AI Providers tab.

> **Salts caveat:** because the encryption key derives from WordPress salts, rotating `AUTH_KEY`/`SECURE_AUTH_KEY` makes stored keys undecryptable. The store detects this and treats the key as missing (admin re-enters it) rather than sending garbage to providers.

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

- **Reads from:** `nvoos_content_graph_ai_credentials` option (encrypted provider keys), `nvoos_content_graph_settings` option (non-secret AI config merged via `nvoos_content_graph/default_settings` filter), core `NvoosContentGraph\ToolRegistry`, core custom tables (`nvoos_content_graph_embeddings`)
- **Writes to:** `nvoos_content_graph_ai_credentials` option (autoload=false), REST responses, SSE streams, AI provider APIs (OpenAI, Gemini, Ollama, etc.), `nvoos_content_graph_embeddings` table
- **Upstream callers:** WordPress REST API, `nvoos-content-graph` core (tool registration hook)
- **Downstream collaborators:** `nvoos-content-graph` core (`ToolRegistry`, `Contracts\Tool`, `Settings`), `nvoos/core` framework, 13 provider APIs
- **Events fired:** `nvoos_content_graph_ai/continue_chat` (Action Scheduler)
- **Events listened to:** `nvoos_content_graph/register_tools`, `nvoos_content_graph/default_settings`, `rest_api_init`, `pre_update_option_nvoos_content_graph_settings` (secret routing), `nvoos_content_graph/section_field_value` (render masking)

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
├── Security/
│   └── CredentialStore.php         # Encrypted API key storage + migration + save/render hardening
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
├── Cli.php                           # WP-CLI: wp nvoos-cg-ai migrate-keys / key-status
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
