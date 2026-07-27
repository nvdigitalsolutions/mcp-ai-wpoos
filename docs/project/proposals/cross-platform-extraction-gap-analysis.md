# Cross-Platform Extraction — Implementation Status & Gap Analysis

**Date:** 2026-06-03 (original), refreshed 2026-07-26 (v2.1.0 — 204 tools, 49 contracts, 45 adapters, 17 providers)
**Status:** Live assessment — the extraction is operational behind a feature flag. Phases 0-2 complete, Phase 3 (tool migration) at **77% (190/~234 legacy files migrated)**. 109 tools migrated in single sprint (2026-07-26). **Key deltas since July 13:** 109 new tools (82→190), 2 new domain contracts (44→46), 2 new WordPress adapters (41→43), 2 new streaming provider clients (15→17), integration test suite created (385 tests), transcript recording contracts added, voice/realtime provider skeletons created.
**Feature Flag:** `?engine=oos`, `X-WP-MCP-AI-Engine: oos` header, `WP_MCP_AI_OOS_ENGINE` constant, or admin setting `enable_oos_engine`

**Key Files:**
- [`lib/`](../../lib/) — extracted core + platform adapter packages
- [`lib/core/`](../../lib/core/) — `nvoos/core` Composer package (PHP 8.1+, MIT)
- [`lib/wordpress-adapter/`](../../lib/wordpress-adapter/) — `nvoos/wordpress-adapter` (PHP 7.4+, GPL-3.0)
- [`lib/laravel-adapter/`](../../lib/laravel-adapter/) — `nvoos/laravel-adapter` (PHP 8.1+, MIT) — **complete, needs deployment wiring**
- [`includes/bootstrap/oos-bridge.php`](../../includes/bootstrap/oos-bridge.php) — WordPress DI wiring + feature flag
- [`cross-platform-extraction-architecture.md`](./cross-platform-extraction-architecture.md) — the vision proposal (2026-05-31)
- [`laravel-scale-deployment-architecture.md`](./laravel-scale-deployment-architecture.md) — Laravel Octane orchestrator deployment plan (2026-07-01)

---

## Executive Summary

The cross-platform extraction is **far more advanced** than the proposal alone suggests. A fully functional `nvoos/core` package with **46 domain contracts**, 13 entities, 5 error classes, 8 events, 4 application services, **17 provider clients** (15 text + 2 streaming), SSE streaming, cost calculation, and **190 migrated base tools** (77% of legacy surface) already exists at `lib/core/`. A companion `nvoos/wordpress-adapter` package with **43 adapter implementations** and a DI bridge in `includes/bootstrap/oos-bridge.php` wires everything into the WordPress plugin behind a feature flag (`?engine=oos`). The existing plugin path is completely unaffected — the extraction is additive.

**What's operational:** A complete Hexagonal Architecture inside `lib/` running the agentic loop via `ChatOrchestrator` with 190 framework-agnostic tools, 17 AI providers, and WordPress adapters behind every port.

**What's new since v1.1.29:** The Pro addon has grown significantly — from ~765 Pro tools to ~810+ (FlowHub +6, Shopify +5, DietPi +19, Cloudways +60, CRM expansions, new toolkit features). These tools remain in the WordPress-only path. Pro tool migration to the OOS engine is the next major frontier.

**What remains:** All 14 deeply WP-native tools migrated to `lib/wordpress-adapter/src/Tool/`. ~30 plugin-dependent legacy tools intentionally not migrated (Elementor/JetEngine/WooCommerce/Newsletter/SiteKit/FlowHub). Voice/realtime provider WebSocket integration needs platform-specific implementation. Pro tool migration not yet started (0/~810). Three new domain contracts extracted: `ChatServiceInterface`, `ToolLoadBalancerInterface`, `ToolAsyncExecutorInterface`.

---

## Layer-by-Layer: Proposal vs. Implementation

### ✅ Domain Contracts (9/9 — Complete)

| Proposal Interface | Oos\Core Implementation | Status |
|---|---|---|
| `ContentStoreInterface` | `lib/core/src/Domain/Contract/ContentStoreInterface.php` | ✅ |
| `AuthProviderInterface` | `lib/core/src/Domain/Contract/AuthProviderInterface.php` | ✅ |
| `SettingsStoreInterface` | `lib/core/src/Domain/Contract/SettingsStoreInterface.php` | ✅ |
| `FileStoreInterface` | `lib/core/src/Domain/Contract/FileStoreInterface.php` | ✅ |
| `CacheStoreInterface` | `lib/core/src/Domain/Contract/CacheStoreInterface.php` | ✅ (domain-owned — no longer extends PSR-6) |
| `QueueClientInterface` | `lib/core/src/Domain/Contract/QueueClientInterface.php` | ✅ |
| `EventDispatcherInterface` | `lib/core/src/Domain/Contract/EventDispatcherInterface.php` | ✅ (domain-owned — no longer extends PSR-14) |
| `ErrorFactoryInterface` | `lib/core/src/Domain/Contract/ErrorFactoryInterface.php` | ✅ |
| `ToolInterface` | `lib/core/src/Domain/Contract/ToolInterface.php` | ✅ (with sub-interfaces) |

### ✅ Domain Entities (10/10 — Complete)

| Proposal Entity | Implementation | Status |
|---|---|---|
| `ContentItem` | `final readonly class`, `JsonSerializable` | ✅ |
| `ContentQuery` | Builder-style query object | ✅ |
| `ContentCollection` | Paginated result wrapper | ✅ |
| `CreateContentCommand` | Immutable command DTO | ✅ |
| `UpdateContentCommand` | Partial-update command DTO | ✅ |
| `AuthContext` | Token + user context | ✅ |
| `Credential` | Issued credential value object | ✅ |
| `UserInfo` | User identity DTO | ✅ |
| `StoredFile` | File metadata value object | ✅ |
| `JobStatus` | Queue job status value object | ✅ |

### ✅ Domain Errors (5/5 — Complete)

| Proposal Error | Implementation | HTTP Status |
|---|---|---|
| `DomainError` | ✅ | — (serializable, framework-agnostic) |
| `AccessDeniedException` | ✅ | 403 |
| `NotFoundException` | ✅ | 404 |
| `ValidationException` | ✅ | 422 |
| `AuthenticationException` | ✅ | 401 |

All extend `\RuntimeException`, carry structured context via `public readonly` properties.

### ✅ Domain Events (8/8 — Complete)

| Event | Replaces WordPress Hook |
|---|---|
| `BeforeChatRequest` | `wp_mcp_ai_before_chat_request` |
| `AfterChatResponse` | `wp_mcp_ai_after_chat_response` |
| `BeforeToolExecution` | `wp_mcp_ai_before_tool_execution` |
| `AfterToolExecution` | `wp_mcp_ai_after_tool_execution` |
| `AgenticIterationComplete` | (new — iteration-level observability) |
| `AgenticLoopCompleted` | (new — loop-level summary) |
| +2 additional events | (full surface TBD from filesystem) |

Mapped to existing WordPress hooks in `oos-bridge.php` via `EventDispatcher::mapEventToHook()`.

### ✅ Application Services (4/4 — Complete)

| Proposal Service | Implementation | Location |
|---|---|---|
| `ChatOrchestrator` | `Nvoos\Core\Application\Chat\ChatOrchestrator` | `lib/core/src/Application/Chat/` |
| `ProviderRouter` | `Nvoos\Core\Application\Provider\ProviderRouter` | `lib/core/src/Application/Provider/` |
| `ToolRegistry` | `Nvoos\Core\Application\Tool\ToolRegistry` | `lib/core/src/Application/Tool/` |
| `SkillRegistry` | `Nvoos\Core\Application\Skill\SkillRegistry` | `lib/core/src/Application/Skill/` |

All injected with domain contracts — zero WordPress references.

### ✅ Infrastructure (Complete)

| Component | Implementation | Notes |
|---|---|---|
| 12 Provider Clients | OpenAI, Gemini, Anthropic, DeepSeek, OpenRouter, Kimi, Ollama, LM Studio, DigitalOcean, Nvidia Nim, Cloudflare, HuggingFace | All use domain-owned `HttpClientInterface::send()` |
| SSE Handler | `Nvoos\Core\Infrastructure\Streaming\SseHandler` | RFC 6202 compliant |
| Cost Calculator | `Nvoos\Core\Infrastructure\Cost\CostCalculator` | Per-model pricing for all 12 providers |

### ✅ WordPress Adapters (8/8 — Complete)

| Adapter | Implements | Wraps |
|---|---|---|
| `Nvoos\WordPress\Adapter\ContentStore` | `ContentStoreInterface` | `get_post`, `WP_Query`, `wp_insert_post`, `wp_update_post`, `wp_delete_post`, `get_post_meta`, `wp_get_post_terms` |
| `Nvoos\WordPress\Adapter\AuthProvider` | `AuthProviderInterface` | `get_current_user_id`, `current_user_can`, credential verification, `get_userdata` |
| `Nvoos\WordPress\Adapter\SettingsStore` | `SettingsStoreInterface` | `get_option`, `update_option`, `delete_option` |
| `Nvoos\WordPress\Adapter\FileStore` | `FileStoreInterface` | `get_attached_file`, `wp_upload_dir`, `wp_insert_attachment` |
| `Nvoos\WordPress\Adapter\CacheStore` | `CacheStoreInterface` | `get_transient`, `set_transient`, `wp_cache_*` |
| `Nvoos\WordPress\Adapter\QueueClient` | `QueueClientInterface` | Action Scheduler / WP-Cron |
| `Nvoos\WordPress\Adapter\EventDispatcher` | `EventDispatcherInterface` | `do_action`, `apply_filters` (with mapEventToHook bridge) |
| `Nvoos\WordPress\Adapter\ErrorFactory` | `ErrorFactoryInterface` | `WP_Error` ↔ domain exceptions |

### 🟢 Migrated Tools (190 / ~234 legacy files — 77% Complete)

> **v2.0.0 Refresh (2026-07-26):** A 109-tool migration sprint brings the total to **190 `$tool_registry->register()` calls** — 77% of base tools are now framework-agnostic and running through the OOS engine. 1 new domain contract (`ImageProcessingInterface`), 1 new WordPress adapter (`ImageProcessing`), 2 new streaming provider contracts, 1 transcript store contract, and 2 new provider clients were added.

Registered in `wp_mcp_ai_oos_orchestrator()` in `oos-bridge.php`:

**Tier 1 — External API / Public Data + Utility (53 tools):**
`WebSearchTool`, `GetGdacsEventsTool`, `GetNhcActiveStormsTool`, `GetOpenMeteoForecastTool`, `ReliefwebReportsTool`, `GetModelInformationTool`, `ListAvailableModelsTool`, `ModerateContentTool`, `CreateTextEmbeddingsTool`, `SuggestBestModelTool`, `CountTokensTool`, `GetPostTaxonomiesTool`, `CountPostsTool`, `TruncateTextTool`, `MathEvalTool`, `ColorConvertTool`, `GetSettingTool`, `UpdateSettingTool`, `ListSettingsTool`, `DeleteSettingTool`, `GenerateSlugTool`, `FormatBytesTool`, `StripHtmlTool`, `CheckCapabilityTool`, `GetCurrentUserTool`, `GenerateUuidTool`, `HashStringTool`, `ValidateJsonTool`, `EnqueueJobTool`, `GetJobStatusTool`, `CancelJobTool`, `ScheduleJobTool`, `UnscheduleJobTool`, `ListJobsTool`, `UploadFileTool`, `GetFileInfoTool`, `DeleteFileTool`, `Base64Tool`, `ExtractDomainTool`, `GetCacheTool`, `SetCacheTool`, `DeleteCacheTool`, `IncrementCacheTool`, `DispatchEventTool`, `GetPostMetaTool`, `FormatDateTool`, `TimeAgoTool`, `MergeArraysTool`, `ParseCsvTool`, `DeepResearchTool`, `ProbeRemoteMcpTool`, `RunCrawl4AiJobTool`, `Crawl4AiPriceLookupTool`

**HuggingFace Datasets (11):** ✅ All 11 tools

**Client-Side (6):** ✅ All 6 tools

**Content CRUD + Validated (14):** `GetPostTool`, `GetRecentPostsTool`, `SearchContentTool`, `CreatePostTool`, `UpdatePostTool`, `DeletePostTool`, `SavePostTool`, `SavePostValidatedTool`, `CreatePostValidatedTool`, `GetRecentPostsValidatedTool`, `SearchContentValidatedTool`, `GetPostMetaTool`, `GetPostTaxonomiesTool`, `CountPostsTool`

**Cron Jobs (5):** `CreateCronJobTool`, `CreateCronJobValidatedTool`, `DeleteCronJobTool`, `ListCronJobsTool`, `GetCronJobTool`

**Erlang-C (4):** `CalculateErlangCTool`, `ErlangCConcurrencyAdvisorTool`, `ErlangCQueueHealthTool`, `ErlangCStaffingAdvisorTool`

**Cache Purge (3):** `PurgeCacheTool`, `PurgeCloudflareCacheTool`, `PurgeVarnishCacheTool`

**Vector Stores (4):** `CreateVectorStoreTool`, `GetVectorStoreTool`, `ListVectorStoresTool`, `ManageVectorStoreFilesTool`

**Audio — TTS/Music/Transcription (6):** `GenerateOpenAISpeechTool`, `GenerateMusicTool`, `TranscribeOpenAIAudioTool` + validated variants

**Video — Analysis/Generation (9):** `CheckVideoStatusTool`, `AnalyzeVideoTool`, `GenerateVideoCaptionTool`, `GenerateSoraVideoTool`, `GenerateVeoVideoTool`, `GenerateOmniVideoTool`, `EditOmniVideoTool` + validated variants

**Image — Generation/Analysis (19):** `GenerateOpenAIImageTool`, `GenerateGeminiImageTool`, `EditOpenAIImageTool`, `EditGeminiImageTool`, `CreateImageVariationTool`, `AnalyzeImageTool`, `GenerateImageAltTextTool`, `GenerateImageCaptionTool`, `ExtractImageTextTool`, `VisionObjectLocalizationTool`, `VisionProductSearchTool`, `GenerateCloudflareAIImageTool` + validated variants

**Image — Manipulation (4):** `ConvertImageFormatTool`, `CropImageTool`, `ResizeImageTool`, `RotateImageTool`

**Chart/Mermaid (4):** `GenerateChartTool`, `CreateChartTool`, `CreateChartValidatedTool`, `GenerateMermaidTool`

**Batch Processing (4):** `CreateBatchTool`, `ListBatchesTool`, `GetBatchStatusTool`, `MonitorBatchTool`

**Memory/Context (7):** `RecallMemoryTool`, `StoreAgentContextTool`, `RetrieveAgentMemoryTool`, `MineAgentMemoryTool`, `ManageContextLifecycleTool`, `SemanticContextSearchTool`, `SemanticContentSearchTool`

**Agent Orchestration (6):** `CreateAgentTeamTool`, `DelegateToAgentTool`, `ExecuteWorkflowTool`, `CheckWorkflowHealthTool`, `ValidateWorkflowTool`, `ValidateReasoningChainTool`

**Profession (4):** `GetProfessionTool`, `ListProfessionsTool`, `ProfessionStatsTool`, `SaveProfessionTool`

**Email (2):** `SendGroupEmailTool`, `SendGroupEmailValidatedTool`

**Search/API (8):** `SearchDriveTool`, `SearchGmailTool`, `SearchPlacesTool`, `GeminiGeospatialQueryTool`, `QueryRemoteSiteTool`, `OpenOpenAILogsTool`, `ListOpenAIFilesTool`, `GetOpenAIFileDetailsTool`

**Content Analysis (7):** `GeneratePostExcerptTool`, `AutoCategorizeContentTool`, `ContentFreshnessCheckerTool`, `SuggestInternalLinksTool`, `AnalyzeCodeSequenceTool`, `AnalyzeCommentContentTool`, `AnalyzeFileSuitabilityTool`, `ContentRecommendationEngineTool`, `BatchEmbedContentTool`, `SEOMetaOptimizerTool`

**Specialized (3):** `WaitForUserTool`, `GenerateAuth0TokenTool`, `GenerateSimpleJWTTokenTool`, `RunOpenAIExternalActionTool`

**User/Auth (3):** `GetUserInfoTool`, `GetCurrentUserTool`, `CheckCapabilityTool`

**Admin/Site (3):** `GetSiteSummaryTool`, `GetPostTypeSchemaTool`, `GetUserInfoTool`

### ✅ Feature Flag & Bridge

The extraction is activated through `oos-bridge.php` loaded at `wp_mcp_ai_bootstrapped` (priority 12):

```
Activation paths (checked in order):
1. Admin setting: wp_mcp_ai_settings['enable_oos_engine']
2. Constant:    define('WP_MCP_AI_OOS_ENGINE', true)
3. Header:      X-WP-MCP-AI-Engine: oos
4. Query param: ?engine=oos
```

The existing `handle_chat_request()` in `class-wp-mcp-ai-rest.php` checks `wp_mcp_ai_oos_engine_enabled()` and delegates to `handle_chat_request_oos()` when the flag is set. The old path is completely unaffected.

**PHP version guard:** The bridge returns early on PHP < 8.1 since the core package uses readonly/enums/fibers.

**lib/ directory guard:** The bridge returns early if `lib/core/src/` or `lib/wordpress-adapter/src/` are absent — the extraction is excluded from WordPress.org base builds.

---

## What Remains (Gap Summary)

> **v2.0.0 Refresh (2026-07-26):** 190 tools are now framework-agnostic (77%, up from 42%). 46 domain contracts (up from 44), 45 WordPress adapters (up from 41), 17 provider clients (up from 15). Integration test suite created (385 tests, 1,918 assertions). Transcript recording contracts and voice/realtime provider skeletons added. Pro tool count has grown to ~810+.

### 🔴 Blocking Full Production Activation

| Gap | Impact | Effort |
|---|---|---|
| **~44 legacy tools intentionally not migrated** | Plugin-dependent tools (Elementor 3, JetEngine 5, WooCommerce 5, Newsletter 6, SiteKit 4, FlowHub 7) cannot be framework-agnostic — they require third-party plugin APIs. ~14 deeply WordPress-native tools (performance-optimizer-assistant, vectorize-image, create-assistant). | N/A — intentional per Strangler Fig pattern |
| **Voice/realtime provider WebSocket integration** | `OpenAIRealtimeProvider` and `GeminiLiveProvider` skeletons exist but need platform-specific WebSocket implementation (WordPress doesn't natively support WebSockets). | Medium (requires sidecar service or Laravel Reverb) |

### 🟢 Resolved Gaps (since July 26 v2.0.0+)

| Gap | Resolution |
|---|---|
| **[HIGH] ~113 base tool migrations** | ✅ All 14 WP-native tools migrated to `lib/wordpress-adapter/src/Tool/`. 204 tools registered (190 core + 14 wp-adapter). Remaining ~30 intentionally plugin-dependent. |
| **[HIGH] Test coverage** | ✅ 757 tests, 3,100 assertions. Integration test verifies all registered tools. |
| **[MED] WP-native tool extraction** | ✅ 14 WP-native tools (probe_chat through performance_optimizer) now live in `lib/wordpress-adapter/src/Tool/` with canonical envelope pattern. |
| **[MED] Service contracts missing** | ✅ `ChatServiceInterface`, `ToolLoadBalancerInterface`, `ToolAsyncExecutorInterface` created (49 domain contracts total). |
| **[MED] Transcript recording** | ✅ `TranscriptStoreInterface` + `TranscriptStore` adapter (options + JetEngine CCT). |
| **[MED] Chat transcript persistence** | ✅ Covered by `TranscriptStoreInterface` + adapter. |
| **[MED] Image processing contract** | ✅ `ImageProcessingInterface` + `ImageProcessing` adapter (GD/Imagick). |
| **[MED] Voice/realtime providers** | 🟡 `StreamingProviderInterface` + 2 provider skeletons created. WebSocket integration pending. |

### 🟡 Medium Priority

| Gap | Impact | Effort |
|---|---|---|
| **PHPStan level 5 → 8** | `lib/core/phpstan.neon.dist` exists (✅) targeting level 5. Blocked from level 8 by ~251 "no value type specified in iterable type array" errors from `ToolInterface` and provider contracts. Unblocked once adapter requires PHP 8.1+ and array shape types can be added. | Medium (1–2 weeks after PHP 8.1 requirement) |
| **Pro addon tools (~810+)** | All ~810+ Pro tools not migrated. Many are external API tools (easy to migrate — FlowHub, Shopify, Cloudways), some are plugin-specific (WooCommerce, JetEngine — harder). This is the largest migration block. | High (ongoing — 16+ weeks) |
| **Laravel adapter deployment wiring** | The 8 adapters are **implemented** (ContentStore via Eloquent, AuthProvider via Sanctum/Gates, CacheStore via Redis, QueueClient via Redis/SQS/Database, etc.) but no Laravel application scaffold, Octane config, or Horizon setup exists yet. See [`laravel-scale-deployment-architecture.md`](./laravel-scale-deployment-architecture.md). | Medium (16 weeks for full deployment) |
| **New domain contracts for Laravel orchestrator** | 4 new interfaces needed for the scale deployment: `VectorStoreInterface` (pgvector adapter), `FederationClientInterface` (peer querying), `MeshRouterInterface` (intelligent peer selection), `StreamingInterface` (Reverb WebSocket adapter). These are framework-agnostic domain contracts in `lib/core/`. | Medium (2 weeks for contracts + Laravel adapters) |
| **Craft adapter** | 0% — implementations written but untested. Craft Commerce and element-type expertise needed. | Medium (4–6 weeks) |
| **Composer package publishing** | `composer.json` files prepared for Packagist (commit `6454d4938`) but neither `nvoos/core` nor `nvoos/wordpress-adapter` are published yet. | Low (setup + docs) |

### 🟢 Low Priority / Nice to Have

| Gap | Notes |
|---|---|
| **Monorepo CI/CD** | `lib/` lives inside the WP plugin repo. Separate CI runs for extracted packages not yet set up (PHPUnit/PHPStan per package). |
| **WordPress.org base build exclusion** | Already handled — `lib/` is absent from base builds, guarded in `oos-bridge.php`. |
| **Async tool execution verification** | Action Scheduler adapter exists (`QueueClient`). Need to verify async tool flow works through the OOS engine end-to-end. |
| **Voice/realtime providers — WebSocket integration** | 🟡 `StreamingProviderInterface` + 2 provider skeletons exist. Platform-specific WebSocket implementation still needed (WordPress doesn't natively support WebSockets). |
| **Integration test suite** | ✅ Created — 385 tests, 1,918 assertions in `lib/core/tests/Integration/`. Covers all 190 registered tools. |

---

## Graphify Ecosystem & Laravel Orchestrator Integration (2026-07-01)

The extraction is the foundation for a federated deployment architecture where:

1. **WordPress/Graphify nodes** serve as content + knowledge graph sources
2. **A central Laravel Octane orchestrator** handles all AI orchestration (chat, tool execution, streaming, vector search)
3. **Federation** routes queries intelligently across all nodes

### Graphify Standalone Plugins

| Plugin | Role | Status |
|---|---|---|
| `nvoos-graphify` | Knowledge graph core (14 tools, Cytoscape.js, 18 remote drivers, REST, Memory Bridge) | v1.0.0 |
| `nvoos-graphify-ai` | AI addon (13 providers, streaming chat, RAG, embeddings) | v1.0.0-dev |
| `nvoos-graphify-ai-platform` | Platform addon (Agents, A2A, ACP, Blueprints, Federation, Harness, Skills) | v1.0.0-dev |

### New Domain Contracts Required

For the Laravel orchestrator to fully integrate with the Graphify ecosystem, 4 new domain contracts are needed in `lib/core/src/Domain/Contract/`:

| Contract | Purpose | Priority |
|---|---|---|
| `VectorStoreInterface` | Abstract vector storage (pgvector in Laravel, MySQL float32 in WordPress) | High |
| `FederationClientInterface` | Peer discovery, querying, health checks, entity reconciliation | High |
| `MeshRouterInterface` | Intelligent peer selection with 6 routing strategies | Medium |
| `StreamingInterface` | Abstract streaming (Reverb WebSocket in Laravel, SSE in WordPress) | Medium |

All four mirror existing WordPress implementations (`WP_MCP_AI_Mesh_Router`, `NV_oOS_Graphify_Remote_OOS_Federation`) that are already production-grade.

### Key Documents

- [`laravel-scale-deployment-architecture.md`](./laravel-scale-deployment-architecture.md) — Full deployment plan
- [`nvoos-base-restructuring-roadmap.md`](./nvoos-base-restructuring-roadmap.md) — Graphify ecosystem architecture

---

## Architecture Fidelity: Proposal vs. Implementation

The implementation is **remarkably faithful** to the proposal's design:

| Aspect | Proposal | Implementation | Match |
|---|---|---|---|
| Hexagonal layers | Domain → Application → Infrastructure → Adapters | `lib/core/src/Domain/` → `Application/` → `Infrastructure/` → `lib/wordpress-adapter/src/Adapter/` | ✅ 100% |
| Domain contracts | PSR-based interfaces | `lib/core` uses domain-owned contracts — zero PSR/Symfony inheritance. `CacheStoreInterface` and `EventDispatcherInterface` no longer extend PSR-6/PSR-14. `HttpClientInterface` is domain-owned. | ✅ |
| PHP version split | Core 8.1+, Adapter 7.4+ | `nvoos/core`: `^8.1`, `nvoos/wordpress-adapter`: `^7.4` | ✅ |
| Value objects | Immutable `final readonly` | All 10 entities use `final readonly class` with constructor promotion | ✅ |
| Canonical error envelope | ErrorFactoryInterface normalization | `ErrorFactoryInterface::normalize()` → `{code, message, data}` | ✅ |
| Event bridge | Domain-owned event system with filter support | `EventDispatcherInterface` with `dispatch()`, `filter()`, `listen()`, `listenFilter()`. WordPress hook bridge via `mapEventToHook()`. | ✅ |
| Strangler Fig pattern | Incremental, additive migration | Feature flag (`?engine=oos`), legacy path untouched, tools migrated incrementally | ✅ |
| DI container | Factory pattern per platform | `wp_mcp_ai_oos_orchestrator()` factory wires all adapters and services | 🟡 Factory pattern works — no DI container dependency |
| Namespace mapping | `Nvoos\Core\` | `Nvoos\Core\Domain\Contract\*`, `Nvoos\Core\Application\*`, etc. | ✅ Exact match |

---

## Timeline Recalibration

The original proposal estimated 52 weeks at ~5.75 FTE. With **190 of ~234 tools migrated** (77%), all **45 WordPress adapters** done, and the full service layer implemented, the current state is approximately **Week 34–38 equivalent** (well into Tier 3, ahead of schedule on tool migration).

| Milestone | Proposal Week | Actual Status | Delta |
|---|---|---|---|
| Monorepo setup | 2 | Done (lib/ structure) | On track |
| WordPress adapters | 8 | ✅ All 45 done | Well ahead |
| Provider clients extracted | 12 | ✅ All 17 done (15 text + 2 streaming) | Ahead |
| Agentic loop extracted | 16 | ✅ ChatOrchestrator operational | Ahead |
| Core services complete | 20 | ✅ SSE, Cost, Events, Skills done | Ahead |
| Tier 1 tools migrated | 24 | ✅ 53 Tier 1 tools done | Ahead |
| Tier 2 tools migrated | 28 | ✅ 70+ tools (content, video, audio, image gen, batch, charts, memory, orchestration) | Well ahead |
| Tier 3 tools migrated | 40 | ✅ 60+ tools (external search, profession, email, vector stores, erlang-c, cache purge) | Ahead |
| Laravel adapter alpha | 34 | 🟡 8 adapters implemented, deployment wiring pending | Behind |
| Craft adapter alpha | 48 | 🟡 Implementations written but untested | Behind |

**Reassessment:** The extraction is approximately **77% complete** for base tools, with the most difficult infrastructure work done. Remaining work: ~44 intentionally-not-migrated tools (plugin-dependent), voice/realtime WebSocket integration, PHPStan hardening, Pro tool migration (~810+), and platform adapter deployment (Laravel, Craft).

---

## Delta: July 1 → July 13

| Metric | Previous (July 1) | Current (July 13) | Change |
|---|---|---|---|
| Registered tools in bridge | 43 (under-reported) | **82** (verified via `grep -c`) | +39 (correction + net new) |
| Tool migration % | 22% | **42%** | +20pp |
| Test files | 0 (reported "no test directory") | **17** (3 entity, 1 error, 1 provider, 11 tool, 1 bootstrap) | +17 (existed, not reflected) |
| PHPStan config | "No PHPStan config" | **Exists** (`phpstan.neon.dist`, level 5) | ✅ corrected |
| Domain contracts | Extended PSR/Symfony | **Domain-owned** (zero PSR/Symfony inheritance) | ✅ completed |
| Provider clients | 12 | **15** (+Baseten, +OpenAiCompatible, +AbstractProviderClient) | +3 |
| Infrastructure services | SSE, Cost | **+TokenBudgetManager** | +1 |
| Composer Packagist | Not prepared | **composer.json files prepared** (commit `6454d4938`) | ✅ ready for publish |

## Delta: July 13 → July 26 (109-Tool Sprint)

| Metric | Previous (July 13) | Current (July 26) | Change |
|---|---|---|---|
| Registered tools in bridge | **82** | **190** (verified via `grep -c`) | **+108** |
| Tool migration % | 42% | **77%** | +35pp |
| Remaining legacy-only tools | ~113 | **~44** (intentionally not migrated) | −69 resolved |
| Domain contracts | 44 | **46** (+ImageProcessingInterface, +TranscriptStoreInterface) | +2 |
| WordPress adapters | 41 | **43** (+ImageProcessing, +TranscriptStore) | +2 |
| Provider clients | 15 | **17** (+OpenAIRealtimeProvider skeleton, +GeminiLiveProvider skeleton) | +2 |
| Test files | 17 | **24** (including `tests/Integration/` suite) | +7 |
| Total tests | ~372 | **757** (3,100 assertions) | +385 |
| Integration tests | 0 | **385** (covers all 190 registered tools) | ✅ created |
| Image processing contract | Did not exist | ✅ `ImageProcessingInterface` + GD/Imagick adapter | ✅ new |
| Transcript recording | Not present | ✅ `TranscriptStoreInterface` + adapter (options + CCT) | ✅ new |
| Voice/realtime providers | Did not exist | 🟡 `StreamingProviderInterface` + 2 provider skeletons | 🟡 skeletons only |

## Recommended Next Steps (Updated 2026-07-26)

### Immediate (Next 2 Weeks)

1. **Voice/realtime provider WebSocket integration** — `OpenAIRealtimeProvider` and `GeminiLiveProvider` skeletons exist. Need platform-specific WebSocket implementation (WordPress: sidecar Node.js service or Ratchet; Laravel: Reverb). This is the last 🔴 blocking gap.

2. **Publish `nvoos/core` to Packagist** — composer.json is prepared. Even as `dev-master` to unblock Laravel adapter development and external contributions.

3. **Establish CI/CD for extracted packages** — separate PHPUnit/Stan runs for `lib/core` and `lib/wordpress-adapter`. Currently all tests run from the WordPress plugin context.

### Short-Term (2–8 Weeks)

4. **Promote PHPStan to level 8** — `phpstan.neon.dist` targets level 5. Blocked by ~251 bare `array` type errors. Unblocked once adapter requires PHP 8.1+ and array shape types can be added.

5. **Begin Pro tool migration** — ~810+ Pro tools remain WordPress-only. Start with easy external API tools (FlowHub, Shopify, Cloudways, DietPi). Use same batch migration pattern proven with base tools.

6. **Laravel adapter deployment wiring** — 8 adapters implemented but no application scaffold, Octane config, or Horizon setup. See `laravel-scale-deployment-architecture.md`.

7. **Document the feature flag** in `docs/features/oos-engine.md` — how to enable, what changes, migration path from legacy engine.

### Medium-Term (2–6 Months)

8. **Complete Pro tool migration** — ongoing batch migration of ~810+ Pro tools following same Strangler Fig pattern.

9. **New domain contracts for Laravel orchestrator** — `VectorStoreInterface`, `FederationClientInterface`, `MeshRouterInterface`, `StreamingInterface`.

10. **Verify async tool flow** — Action Scheduler adapter (`QueueClient`) exists. End-to-end verification of async tool execution through OOS engine.

11. **Begin Craft CMS adapter** — element-type-backed implementations (implementations written but untested).

### Long-Term (6–12 Months)

12. **Sunset the legacy engine path** — once all tools are migrated and OOS engine is default, remove the feature flag and legacy code paths.

13. **Publish `nvoos/laravel-adapter` and `nvoos/craft-adapter`** — documentation, Packagist, marketing.

---

## Documentation Actions Needed

| Document | Current State | Action |
|---|---|---|
| `cross-platform-extraction-architecture.md` | Proposal (v1.0.0, 2026-05-31) | Add banner: "Partially implemented — see gap analysis." Update version to 1.1.0 with implementation notes. |
| **This document** | New | ✅ Created as `cross-platform-extraction-gap-analysis.md` |
| `ARCHITECTURE_REFACTOR_PROPOSAL.md` | March 2026 | Update Phase 2 status — internal boundaries now feed into the extraction. Link to this analysis. |
| `ADR_001_module_boundaries.md` | Accepted | Update interface catalogue — `ContentStoreInterface`, `ErrorFactoryInterface`, etc. now exist in `lib/`. |
| `docs/ROADMAP.md` | Needs update | Add cross-platform extraction as an active milestone (not planned). |
| `docs/developer/` | New directory needed | Create `cross-platform-extraction.md` with architecture overview and how-to for `oss-bridge.php`. |
| `.context/` | New file needed | Create `.context/cross-platform-extraction.md` for AI agent awareness of the extraction state. |
| `lib/README.md`, `lib/core/README.md` | Exist and are current | ✅ No changes needed |

---

## Key Insight

The cross-platform extraction is **not a proposal gathering dust** — it's a fully operational, feature-flagged implementation with **204 tools** (190 core + 14 wp-adapter), **17 provider clients** (15 text + 2 streaming), **49 domain contracts**, **45 WordPress adapters**, **757 tests** (3,100 assertions), and a complete Hexagonal Architecture. The Strangler Fig pattern is working exactly as described: the new engine coexists with the legacy path, and the bridge file (`oos-bridge.php`) is the single integration point. The hardest work — designing the interfaces, building the adapters, wiring the agentic loop — is done. What remains: ~30 intentionally-not-migrated tools (plugin-dependent), voice/realtime WebSocket integration, PHPStan level 8 hardening, ~1,223 Pro tool files across 49 domains, platform adapter deployment (Laravel, Craft), and Composer package publishing.

**The most impactful next action is voice/realtime WebSocket integration** — the last 🔴 blocking gap for full production activation. Followed by Pro tool migration, which is the largest remaining volume (~810+ tools).
