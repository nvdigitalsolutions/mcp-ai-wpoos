# Ecosystem Port Tracker

**Living tracker for the additive base+pro → Content Graph ecosystem port.**
Last updated: 2026-09-01 · Plan: [`base-pro-ecosystem-port-plan.md`](plans/base-pro-ecosystem-port-plan.md) · Gaps: [`base-pro-ecosystem-port-gap-analysis.md`](proposals/base-pro-ecosystem-port-gap-analysis.md)

Locked constraints: **D-NOBASE** (zero changes to `mcp-ai-wpoos`), **D-NOCORE** (zero changes to `nvoos-content-graph`), **D-UI** (all UIs ported), **D-SPA** (Pro SPA v2 ported).

| Status | Meaning |
|---|---|
| ✅ Done | Ported, wired, tested in monolith + standalone matrices |
| 🟡 In progress | PR open or partially wired |
| 🔴 Gap | Planned, no implementation yet |
| ⏸️ Deferred | Decision-parked (see plan) |

## Phase 0 — Foundations

| Item | Status | Notes |
|---|---|---|
| Ownership discriminator (`! defined('WP_MCP_AI_PATH')`) | ✅ | Extraction-proven; no base changes |
| AI addon test suite + monolith/standalone CI matrices | ✅ | `plugins/nvoos-content-graph-ai/{phpunit.xml.dist,tests/}`, `.github/workflows/phpunit-ai.yml` |
| Ecosystem asset pipeline (npm workspace) | 🔴 | Phase 0.5 |
| Tracker (this file) | ✅ | Phase 0.6 |

## Wave D — AI runtime → `nvoos-content-graph-ai`

| # | Subsystem | Status | Notes |
|---|---|---|---|
| D1a | Prompt optimizer (cache-hit ordering, cache key, prompt split) | ✅ | `src/Chat/PromptOptimizer.php`; wired into `Rest\ChatController` via `cache_system_prompt` request arg; 10 characterization tests |
| D1b | Chat response cache + SSE rate limiter + semantic cache | ✅ | `src/Chat/{ChatResponseCache,SseRateLimiter,SemanticCache}.php`; wired into `Rest\ChatController` (cache lookup/store on the non-streaming path, 429 + register/release on the streaming path); 20 characterization tests; SemanticCache ships dormant (matches base — no call sites) |
| D1c | Conversation summarizer (BME context strategy) | ✅ | `src/Chat/ConversationSummarizer.php` (decoupled client contract) + `src/Chat/OrchestratorCompletionClient.php` (nvoos/core adapter); 12 characterization tests; wiring into the chat flow follows with the full BME strategy port |
| D1d | Thread manager (threads/messages/checkpoints CRUD + schema) | ✅ | `src/Chat/ThreadManager.php` (byte-identical tables/error codes/envelopes); schema created on CG-AI activation; 13 characterization tests with real DDL |
| D1e | Message attachments + response attachments (attachment pipeline) | ✅ | `src/Chat/{MessageAttachments,ResponseAttachments}.php` (byte-identical meta key `_wp_mcp_ai_openai_file`, filters, error codes, cache group) + `FileServiceBridge`/`FileClientBridge` collaborators; monolith delegates to base factory/client; standalone delegates to the ported `FileServiceFactory`/`OpenAiFileService` (D2f); cleanup/response hooks registered standalone-only via `Plugin.php`; 28 characterization tests |
| D1f | Chat transcript recorder + transcript retention | ✅ | `src/Chat/{ChatTranscriptRecorder,TranscriptRetention}.php` (byte-identical error codes/options, JetEngine CCT + logger gated behind `defined('WP_MCP_AI_PATH')`); recorder invoked on the non-streaming chat path; 4 `transcript_retention_*` setting defaults added; `TranscriptRetention::init()` standalone-only; 10 characterization tests |
| D1g | ChatKit integration (addon definition + registration surface) | ✅ | `src/Chat/ChatKitIntegration.php` (hook names byte-identical; `ADDON_ID` = `nvoos-content-graph-ai`; definition describes CG-AI's real surface — `nvoos-content-graph/v1`, `POST /ai/chat`, `GET /ai/tools`); standalone-only via `Plugin.php`; capability seam delegates to base helper in monolith / same `wp_mcp_ai_chat_capability` filter standalone; 9 characterization tests; documented deviations (download route, surfaces, assistant_id, guest_access) restored as D2/D5/D-UI land |
| D1 | Chat runtime core (clients, router, selector, caches, summarizer, RAG bridge, threads, transcripts, SSE, attachments, quick actions, chatkit) | ✅ | D1a–D1g done (116 ecosystem tests, 400 assertions); remote file-API uploads + ChatKit download route gated on D2 |
| D2a | Zai provider client | ✅ | `src/Provider/ZaiClient.php` extends nvoos/core `OpenAiCompatibleClient` (slug `zai`, default endpoint `https://api.z.ai/api/paas/v4`); registered in `CoreBridge`; `ai_api_key_zai` + `zai_base_url` defaults; 7 characterization tests |
| D2b | Google Maps client (geocode/reverse/nearby/text search) | ✅ | `src/Provider/GoogleMapsClient.php` (byte-identical endpoints/query args/error codes/normalization); settings seam per mode (`google_maps_api_key`); transport-error default branch standalone (full enrichment when `WP_MCP_AI_HTTP` ports); dormant until tools wave; 12 characterization tests |
| D2c | OpenAI Realtime ×3 (voice, translate, whisper) + voice-provider interface | ✅ | `src/Provider/{VoiceProviderInterface,OpenAiRealtimeClient,OpenAiRealtimeTranslateClient,OpenAiRealtimeWhisperClient}.php` (byte-identical endpoints/GA session payloads/token caching/headers/language tables/filters); tool-registry + assistant config monolith-only; dormant until D-UI voice surface; 18 characterization tests |
| D2d | RabbitMQ client | ✅ | `src/Provider/RabbitMqClient.php` (byte-identical topology constants/config keys/constant precedence/job transients/health shapes); `amqp` extension gating preserved; settings seam per mode; dormant until platform queue wave (E2); tests in shared suite |
| D2e | STDIO transport (MCP JSON-RPC server) | ✅ | `src/Provider/StdioTransport.php` (byte-identical protocol version/method routing/error codes/response shapes); tool registry + assistant config seams (base registry monolith / nvoos-core registry standalone); tests in shared suite |
| D2f | File service factory + OpenAI/Gemini file services | ✅ | `src/Chat/{FileServiceFactory,OpenAiFileService,GeminiFileService}.php` (byte-identical provider mapping/multipart uploads/polling/cache keys/error codes); bridges now use them standalone — closes the D1e file-API gap; 13 characterization tests |
| D2 | Providers beyond the 13 (Zai, Google Maps, RabbitMQ, Stdio, Realtime ×3) + file services | ✅ | D2a–D2f done (67 new ecosystem tests); SaaS drivers (FlowHub/PayHere/Printful) remain Pro-tier per gap §4.2/8.3 |
| D3 | Model management + analytics/token tracking | 🔴 | |
| D4 | Security stack (10 classes + circuit breaker) | 🔴 | |
| D5 | REST surface (mcp-ai/v1 chat/tools/assistants/MCP methods) | 🔴 | |
| D6 | WP-CLI surface (20+ commands) | 🔴 | |
| D7 | Agent memory CCT bridge/migrator/reader | 🔴 | |

## Wave D-UI — AI UI → `nvoos-content-graph-ai`

| # | Subsystem | Status | Notes |
|---|---|---|---|
| D-UI-1 | Chat widget (chat.js) + guest token flow | 🔴 | |
| D-UI-2 | Chat bubble + blocks + shortcodes | 🔴 | |
| D-UI-3 | Elementor chat-family widgets | 🔴 | |
| D-UI-4 | Assistant builder/test/add pages | 🔴 | |
| D-UI-5 | Settings shell + sections | 🔴 | |

## Wave E — Operator runtime → `nvoos-content-graph-ai-platform`

| # | Subsystem | Status | Notes |
|---|---|---|---|
| E1 | Workflow engine + CPTs + triggers | 🔴 | |
| E2 | Queues/ops (jobs, DLQ, SLA, cron, notifier) | 🔴 | |
| E3 | Approvals | 🔴 | |
| E4 | Tenant + integrations (OAuth, Google Calendar, site-builder, conversation-import) | 🔴 | |
| E5 | A2A REST receive routes | 🔴 | Closes MIGRATION-GAPS deferred gap |
| E6 | Engine pieces (OOS/markup/paper-store/OKF/crawler) per decision D4 | 🔴 | |

## Wave E-UI — Operator admin UI → platform

| # | Subsystem | Status | Notes |
|---|---|---|---|
| E-UI-1 | Dashboards (multi-agent, orchestration, slash-commands, run timeline) | 🔴 | |
| E-UI-2 | Managers (tool/token/cron/DAG/DLQ/approvals) | 🔴 | |
| E-UI-3 | Integrations screens | 🔴 | |

## Wave F — Pro runtime → pro addon(s) (decision D5)

| # | Subsystem | Status | Notes |
|---|---|---|---|
| F1 | pro-core (module registry, vault, vector-storage, skills-manager) | 🔴 | |
| F2–F6 | business / media / dev / healthcare / legal / education / content / data / platform toolkits | 🔴 | |
| F7 | Node-service bridges | 🔴 | |

## Wave F-UI — Pro UI + SPA v2

| # | Subsystem | Status | Notes |
|---|---|---|---|
| F-UI-1 | SPA v2 build pipeline in ecosystem npm workspace | 🔴 | |
| F-UI-2 | SPA v2 runtime port + loaders | 🔴 | |
| F-UI-3 | Pro toolkit blocks/shortcodes/inline-assistant + admin screens | 🔴 | |
| F-UI-4 | SPA v2 pro-toolkit slices | 🔴 | |

## Wave G — Release & distribution (release-gated)

| # | Item | Status | Notes |
|---|---|---|---|
| G1 | Ecosystem version bumps + `Requires Plugins` audit | 🔴 | |
| G2 | wp.org Base tier mapping (decision D6) | 🔴 | |
| G3 | Optional NEW bundle plugin | 🔴 | Never the base plugin (D-NOBASE) |
| G4 | Docs sweep + migration guide | 🔴 | |
