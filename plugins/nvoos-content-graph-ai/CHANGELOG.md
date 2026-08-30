# NV oOS Content Graph — AI · Changelog

## 1.0.3 — 2026-08-30

### Fixes

- **Tool permission enforcement** — `CoreBridge` now wires the WordPress `AuthProvider` into the `ChatOrchestrator`. Previously the orchestrator never injected an auth provider, so the core tool registry failed closed and denied every tool that declares a required capability (all graph tools and AI tools), surfacing as "You do not have permission to execute '…'" in the chat tester even for administrators. A regression test covers the fix.
- **Chat tester: "no response comes back"** — the final SSE frame now renders the authoritative assistant text when token deltas never arrive (provider buffering, mid-stream failure, or connection drop), clearing the stuck "Thinking…" bubble and keeping it out of conversation history.
- **Chat tester: dead model selector** — the provider dropdown now reads the live provider registry instead of a hardcoded map, and the model selector queries the new transient-cached `/ai/models` endpoint.
- **Chat tester: Chat Settings tab** — temperature, max tokens, and default provider/model settings now actually drive tester requests.
- **Chat tester: cost badge** — renders from the `cost` payload of the final frame.
- **Embeddings reindex** — fixed the 500 error and the stuck progress UI; reindex now continues in batches and reports progress correctly.
- **Graph context without embeddings** — when no embeddings index exists, the chat context falls back to keyword search over node labels so the tester still grounds answers in the graph.

### Added

- **REST contract**: `GET /ai/chat/config` (providers, defaults, tool presets), `GET /ai/tools` (agentic-loop tool list), and `GET /ai/models` (transient-cached model catalogue) under `/wp-json/nvoos-content-graph/v1/`.
- **Graph tool bridge** — `Adapter\GraphToolAdapter` exposes all parent-plugin graph tools through the `nvoos/core` tool registry, so the agentic loop can call them directly.
- **Tool presets** — `none`, `graph`, and AI tool presets for the tester with server-side slug sanitization.
- **SSE parser** — extracted to `assets/js/content-graph-ai-sse.js`, aligned with the SPA-v2 wire contract (`delta`, `tool_start`, `tool_result`, `done`, `error` frames) with a raw SSE debug log.
- **Tests** — REST contract suite for the chat tester and a JS test suite for the SSE parser.

### Docs

- README documents the subtree sync to the standalone repository.

## 1.0.2 — 2026-08-18

### Rename

- Renamed from "NV oOS Graphify — AI" to "NV oOS Content Graph — AI" (slug `nvoos-content-graph-ai`), matching the parent plugin rename across the text domain, namespace, options, hooks, and REST namespace.

## 1.0.0 — 2026-06-05

### Initial Standalone Release

- AI chat assistant addon for the NV oOS Content Graph: chat orchestration via `nvoos/core` with 13 provider backends (OpenAI, Gemini, Anthropic, DeepSeek, Ollama, OpenRouter, Hugging Face, Cloudflare, LM Studio, NVIDIA NIM, DigitalOcean, Kimi, Baseten)
- SSE streaming, agentic tool-calling loop (max 5 iterations), and 13 AI tools (summarize, translate, sentiment, entities, Q&A, excerpts, alt text, image analysis, categorize, recommendations, freshness, semantic search, embeddings)
- Embeddings + RAG retrieval and agent conversation memory
- Admin chat tester and AI settings tab integrated with the parent plugin's settings registry
