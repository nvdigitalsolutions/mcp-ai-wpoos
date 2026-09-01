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
| D3a | Model catalog migration + integrity verifier | ✅ | `src/Model/{ModelCatalogMigration,ModelIntegrityVerifier}.php` + bundled `model-catalog.json`; legacy-id map/option/meta/settings rewriting, blocked models, vulnerability + self-hosted TLS enforcement byte-identical; `run_from_catalog` standalone-only via `Plugin.php`; 11 characterization tests |
| D3b | Model rate-limits CCT + pricing checker | ✅ | `src/Model/{ModelRateLimitsCct,ModelPricingChecker}.php` (JetEngine provisioning + cron byte-identical); both `bootstrap()` standalone-only; 9 characterization tests |
| D3c | Analytics engine | ✅ | `src/Analytics/AnalyticsEngine.php` (trends/statistics/Z-score anomaly/patterns/comparisons/transcript rebuild); usage seam delegates to base `WP_MCP_AI_Tool_Token_Limits` monolith / `ToolTokenLimits` standalone; 11 characterization tests |
| D3d | Usage tracker | ✅ | `src/Analytics/UsageTracker.php` (meta key/totals/normalization/filters/pricing byte-identical; fallback pricing map byte-identical); settings seam per mode; `init()` standalone-only; 8 characterization tests |
| D3e | Token tracking DB + enhanced tracking + optimizer | ✅ | `src/Analytics/{TokenTrackingDatabase,EnhancedTokenTracking,TokenDbOptimizer}.php` (schema/inserts/aggregations/retention/backfill/migration + usermeta index lifecycle byte-identical); `init()` standalone-only; 13 characterization tests |
| D3f | Tool token limits | ✅ | `src/Analytics/ToolTokenLimits.php` (constants/tiers/role map/multipliers/usage shape/session accounting/forecasting/anomaly detection/hooks byte-identical); settings + workload-tier + logging seams per mode (workload tier defaults `medium` standalone until Resource Manager ports in D4); `init()` standalone-only, deactivation hook monolith-skipped; 35 characterization tests |
| D3 | Model management + analytics/token tracking | ✅ | D3a–D3f done (87 new ecosystem tests); suite now 270 tests / ~1171 assertions green in both monolith + standalone matrices, PHPCS-clean |
| D4a | Provider circuit breaker | ✅ | `src/Security/ProviderCircuitBreaker.php` (transient keys/state constants/threshold+timeout filters byte-identical); dormant until providers adopt; 9 characterization tests |
| D4b | Encrypted API key store | ✅ | `src/Security/ApiKeyStore.php` (option prefix/managed keys/migration semantics byte-identical); crypto seam: base `WP_MCP_AI_Encryption` monolith / parent `Remote\Crypto` standalone; logging monolith-only; 10 characterization tests |
| D4c | Security audit logger | ✅ | `src/Security/SecurityAuditLogger.php` (table `wp_mcp_ai_security_log`, event constants, `wp_mcp_ai_security_event` action, REST endpoint `mcp-ai/v1/security/events`, purge cron byte-identical); `register()` + activation table creation standalone-only; 7 characterization tests with real DDL |
| D4d | Request guard | ✅ | `src/Security/RequestGuard.php` (SSE counter keys/REST priorities/body+JSON limits/verbosity stripping/asset-version stripping byte-identical); settings seam per mode; SSE slots delegate to base `WP_MCP_AI_SSE_Rate_Limiter` monolith / ported `SseRateLimiter` standalone; `register()` standalone-only; 11 characterization tests |
| D4e | URL guard (SSRF) | ✅ | `src/Security/UrlGuard.php` (blocked CIDRs/hostnames/error codes/filters byte-identical); loopback-hostname shortcut delegates to base `WP_MCP_AI_HTTP_Helper` monolith, DNS-based checks standalone; 11 characterization tests |
| D4f | Security posture scoring | ✅ | `src/Security/SecurityPosture.php` (23-signal set/weights/grades/quick wins/`wp_mcp_ai_security_posture_signals` filter byte-identical); settings + restriction-registry seams per mode; 7 characterization tests |
| D4g | Concurrency guard + subscriber + exception | ✅ | `src/Security/{ConcurrencyGuard,ConcurrencyGuardSubscriber}.php` + `Exceptions/ConcurrencyLimitReached.php` (slot table/limits map/filter/priorities/envelopes byte-identical); capability-flag + tool-resolution seams per mode; subscriber `register()` standalone-only; 10 characterization tests with real DDL |
| D4h | Cost tracker + subscriber + exception | ✅ | `src/Security/{CostTracker,CostTrackerSubscriber}.php` + `Exceptions/CostBudgetExceeded.php` (pricing map/estimate heuristics/budget meta/spend option/filters/envelopes byte-identical); subscriber `register()` standalone-only; 14 characterization tests |
| D4i | Destructive ops gate + exception | ✅ | `src/Security/DestructiveOpsGate.php` + `Exceptions/DestructiveConfirmationRequired.php` (flag vocabulary/confirmation values/preview payload/rejection action/428 envelope byte-identical); settings + flag + audit seams per mode; `register()` standalone-only; 8 characterization tests |
| D4j | CSP headers | ✅ | `src/Security/CspHeaders.php` (directive set/filters/header names byte-identical); `is_admin_context()`/`send_header()` protected seams for testability; `register()` standalone-only; 3 characterization tests |
| D4k | Load guard | ✅ | `src/Security/LoadGuard.php` (counter key/429 envelope/filter byte-identical); max-concurrency + job-queue aggregation monolith-only (standalone counts the transient counter until Resource Manager ports — tracked); `register()` standalone-only; 5 characterization tests |
| D4 | Security stack (10 classes + circuit breaker) | ✅ | D4a–D4k done (95 new ecosystem tests); suite now 365 tests / ~1585 assertions green in both monolith + standalone matrices, PHPCS-clean |
| D5a | Assistant directory REST controller | ✅ | `src/Rest/AssistantController.php` (routes `mcp-ai/v1/assistants` GET/POST/DELETE, directory contract: summary fields/rest links/capabilities/implementation/X-WP-Total headers, search/include/pagination/_fields, create/delete with meta persistence — byte-identical); auth = CG-AI caps; settings/config/cache/access-validation seams per mode; token scope deferred to D-UI; `registerRoutes()` standalone-only; 17 characterization tests |
| D5b | Tools listing REST controller | ✅ | `src/Rest/ToolsController.php` (route `mcp-ai/v1/tools` GET, `tools` contract name/description/inputSchema, assistant scoping, `_fields` filtering, cache — byte-identical); registry seam: base `WP_MCP_AI_Tool_Registry` monolith / nvoos-core registry via `CoreBridge` standalone (camelCase→snake_case wrapper); `registerRoutes()` standalone-only; 8 characterization tests |
| D5c | MCP JSON-RPC controller | ✅ | `src/Rest/McpController.php` (routes `mcp-ai/v1/mcp` GET/POST/OPTIONS + `/no-sse` + `/sse`; JSON-RPC 2.0 envelope: parse errors -32700, invalid request -32600, header mismatch -32020, method-not-found -32601, batching max 20, notifications 202, `_meta` serverInfo stamp, Mcp-Method/Mcp-Name headers; `server/discover` with version negotiation + inline tools + `wp_mcp_ai_mcp_discover_instructions`/`wp_mcp_ai_discover_include_tools` filters; `tools/list` delegates to `ToolsController` (composition) + annotations + `ttlMs`/`cacheScope`; `resources/list|read`, `prompts/list|get`, `completion/complete`, `logging/setLevel`, `notifications/cancelled`; CORS headers from `cors_allow_origin` settings — byte-identical); deviations documented: SSE sessions deferred (GET /mcp + /sse return discovery JSON, `sse.enabled=false`), `tools/call` answers `wp_mcp_ai_mcp_unavailable` (503) until tool execution ports (D-Tools), no OAuth scope enforcement (stub seam), no default-assistant resolution, no token scoping; auth = CG-AI caps; 33 characterization tests |
| D5d | Chat compat REST route | ✅ | `src/Rest/ChatCompatController.php` (route `mcp-ai/v1/chat` POST/GET, POST args byte-identical: assistant_id (int|string), messages (required + base validator rules), options (provider/model/temperature/stream/response_format), professional_prompt; handler translates the base options envelope → flat CG-AI params and delegates to `ChatController::handleChat()` — response envelope (`success`/`data`/`tool_results`/`iterations`/`cost`/`cache_metadata`) already matches the base; temperature clamp [0,2] base contract); deviations documented: `assistant_id`/`professional_prompt`/`options.response_format` accepted but not applied (assistant/profession runtimes not ported), content-part arrays JSON-encoded, GET SSE handshake → `wp_mcp_ai_sse_chat_deferred` (501, streaming via POST `options.stream`); auth = CG-AI caps; 19 characterization tests |
| D5 | REST surface (mcp-ai/v1 chat/tools/assistants/MCP methods) | 🟡 | D5a–D5d done (77 new ecosystem tests); remaining: file-download/cron-status endpoints — land with the queues wave |
| D6a | WP-CLI core commands | ✅ | `src/Cli/` (`StatusCommand` — environment summary with install mode + runtime facts, base `status` shape; `ToolsCommand` — registry inventory via the same monolith/standalone seam as `ToolsController`, `--filter`; `ProvidersCommand` — provider slugs + credential state via `CredentialResolver` + default flag; `SettingsCommand` — `nvoos_content_graph_settings` reader, secrets refused (list + get); `GraphCommand` — parent-schema row counts with honest `unavailable` for missing tables) + `src/Cli.php` hub registration; data logic in plain static methods (no WP-CLI dependency, testable), `run*()` wrappers thin; 12 characterization tests |
| D6 | WP-CLI surface (20+ commands) | 🟡 | D6a done (6 new commands: status / tools list / providers list / settings list|get / graph stats + existing migrate-keys/key-status = 8); remaining base commands (queue stats, token, plugins, stdio, DLQ/SLA/restrictions) land with their owning waves (queues/tokens/mesh) |
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
