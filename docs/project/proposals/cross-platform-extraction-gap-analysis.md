# Cross-Platform Extraction — Implementation Status & Gap Analysis

**Date:** 2026-06-03 (original), refreshed 2026-07-13 (v1.1.35+ tools-verified audit)
**Status:** Live assessment — the extraction is operational behind a feature flag. Phases 0-2 complete, Phase 3 (tool migration) at **42% (82/~195 base tools)**. Pro tool migration not yet started (0/~810). **Key delta since July 1:** tool count was under-reported (43 → 82 verified in bridge), 17 test files now exist (was "none"), PHPStan config exists at level 5 (was "missing"), domain contracts fully decoupled from PSR/Symfony. Laravel-scale deployment architecture proposed with 4 new domain contracts, Graphify ecosystem integration.
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

The cross-platform extraction is **far more advanced** than the proposal alone suggests. A fully functional `nvoos/core` package with 9 domain contracts, 10 entities, 5 error classes, 8 events, 4 application services, 12 provider clients, SSE streaming, cost calculation, and **43 migrated base tools** already exists at `lib/core/`. A companion `nvoos/wordpress-adapter` package with 8 adapter implementations and a DI bridge in `includes/bootstrap/oos-bridge.php` wires everything into the WordPress plugin behind a feature flag (`?engine=oos`). The existing plugin path is completely unaffected — the extraction is additive.

**What's operational:** A complete Hexagonal Architecture inside `lib/` running the agentic loop via `ChatOrchestrator` with 43 framework-agnostic tools, 12 AI providers, and WordPress adapters behind every port.

**What's new since v1.1.29:** The Pro addon has grown significantly — from ~765 Pro tools to ~810+ (FlowHub +6, Shopify +5, DietPi +19, Cloudways +60, CRM expansions, new toolkit features). These tools remain in the WordPress-only path. Pro tool migration to the OOS engine is the next major frontier.

**What remains:** ~152 base tools to migrate (78%), ~810+ Pro tools to migrate (0%), tests for the extracted packages, the Laravel and Craft adapters, and monorepo/CI tooling.

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

### 🟡 Migrated Tools (82 / ~195 base — 42% Complete)

> **v1.1.35+ correction (2026-07-13):** The previous report under-counted at 43 tools. A line-by-line audit of `oos-bridge.php` confirms **82 `$tool_registry->register()` calls** — 42% of base tools are now framework-agnostic and running through the OOS engine. This includes a larger utility/cache/queue/file tool surface added in June 2026 batch migrations that were not reflected in the prior count.

Registered in `wp_mcp_ai_oos_orchestrator()` in `oos-bridge.php`:

**Tier 1 — External API / Public Data + Utility (53 tools):**
`WebSearchTool`, `GetGdacsEventsTool`, `GetNhcActiveStormsTool`, `GetOpenMeteoForecastTool`, `ReliefwebReportsTool`, `GetModelInformationTool`, `ListAvailableModelsTool`, `ModerateContentTool`, `CreateTextEmbeddingsTool`, `SuggestBestModelTool`, `CountTokensTool`, `GetPostTaxonomiesTool`, `CountPostsTool`, `TruncateTextTool`, `MathEvalTool`, `ColorConvertTool`, `GetSettingTool`, `UpdateSettingTool`, `ListSettingsTool`, `DeleteSettingTool`, `GenerateSlugTool`, `FormatBytesTool`, `StripHtmlTool`, `CheckCapabilityTool`, `GetCurrentUserTool`, `GenerateUuidTool`, `HashStringTool`, `ValidateJsonTool`, `EnqueueJobTool`, `GetJobStatusTool`, `CancelJobTool`, `ScheduleJobTool`, `UnscheduleJobTool`, `ListJobsTool`, `UploadFileTool`, `GetFileInfoTool`, `DeleteFileTool`, `Base64Tool`, `ExtractDomainTool`, `GetCacheTool`, `SetCacheTool`, `DeleteCacheTool`, `IncrementCacheTool`, `DispatchEventTool`, `GetPostMetaTool`, `FormatDateTool`, `TimeAgoTool`, `MergeArraysTool`, `ParseCsvTool`, `DeepResearchTool`, `ProbeRemoteMcpTool`, `RunCrawl4AiJobTool`, `Crawl4AiPriceLookupTool`

**HuggingFace Datasets (11 tools):**
`HuggingFaceDatasetSearch`, `GetInfo`, `GetRows`, `GetSize`, `GetStatistics`, `IsValid`, `ListSplits`, `Filter`, `GetParquet`, `PreviewRows`, `RecommendedDatasets`

**Client-Side (6 tools):**
`ClientAnalyzeSentiment`, `ClientSummarizeText`, `ClientTranslateText`, `ClientExtractEntities`, `ClientQuestionAnswering`, `ClientSemanticSearch`

**Content (6 tools):**
`GetPostTool`, `GetRecentPostsTool`, `SearchContentTool`, `CreatePostTool`, `UpdatePostTool`, `DeletePostTool`

**User (1 tool):**
`GetUserInfoTool`

**Skill (2 tools):**
`LoadSkillTool`, `ListSkillsTool`

**File (1 tool):**
`SearchAttachmentsTool`

**Geo (1 tool):**
`GeocodeAddressTool`

**Site Admin (1 tool):**
`GetSiteSummaryTool`

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

> **v1.1.35+ Refresh (2026-07-13):** Base tool count is ~195 (unchanged). 82 tools are now framework-agnostic (42%, up from the previously under-reported 22%). Pro tool count has grown to ~810+ with FlowHub (6), Shopify Sync (5), DietPi (19+), Cloudways (60), and CRM expansions. A test suite exists (17 files) but coverage is thin. PHPStan runs at level 5 (target 8). Domain contracts fully decoupled from PSR/Symfony inheritance.

### 🔴 Blocking Full Production Activation

| Gap | Impact | Effort |
|---|---|---|
| **Test coverage is thin (17 files)** | 17 test files cover 141 source files — mainly tool unit tests. Domain entities (3/10 tested), errors (1/5 tested), and providers (1/15 tested) have minimal coverage. No integration tests exist. | Medium (3–4 weeks) |
| **~113 base tool migrations (58% remaining)** | 58% of base tools still call WordPress APIs directly. These work in the legacy path but aren't available in the OOS engine. | High (6–12 weeks — revised down from 8–16) |
| **~810+ Pro tool migrations (0% complete)** | All Pro tools (FlowHub, Shopify, DietPi, Cloudways, CRM, etc.) remain in the WordPress-only path. External API tools are easy to migrate; plugin-specific tools (WooCommerce, JetEngine) are harder. | High (ongoing) |
| **PHP 8.1+ requirement for core** | Core uses `readonly`/enums. WordPress sites on PHP 7.4 can't use the OOS engine. The adapter targets PHP 7.4 but the core doesn't. | Low (documentation only — this is by design per the proposal) |

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
| **Voice/realtime providers** | Not yet extracted. OpenAI Realtime and Gemini Live currently live in the WP plugin classes. |
| **Integration test suite** | `phpunit.xml.dist` defines an `Integration` testsuite but `tests/Integration/` directory does not exist yet. |

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

The original proposal estimated 52 weeks at ~5.75 FTE. With 43 of 195 tools migrated and all 8 adapters + the full service layer implemented, the current state is approximately **Week 24–28 equivalent** (end of Tier 1 migration, beginning of Tier 2).

| Milestone | Proposal Week | Actual Status | Delta |
|---|---|---|---|
| Monorepo setup | 2 | Done (lib/ structure) | On track |
| WordPress adapters | 8 | ✅ All 8 done | Ahead |
| Provider clients extracted | 12 | ✅ All 12 done | Ahead |
| Agentic loop extracted | 16 | ✅ ChatOrchestrator operational | Ahead |
| Core services complete | 20 | ✅ SSE, Cost, Events, Skills done | Ahead |
| Tier 1 tools migrated | 24 | ✅ 25+ Tier 1 tools done | On track |
| Tier 2 tools migrated | 28 | 🟡 6 content tools + 1 user tool done (started) | On track |
| Laravel adapter alpha | 34 | ❌ Not started | — |
| Tier 3 tools migrated | 40 | ❌ Not started | — |
| Craft adapter alpha | 48 | ❌ Not started | — |

**Reassessment:** The extraction is approximately 35–40% complete overall, with the most difficult infrastructure work already done. Remaining work is primarily tool migration (mechanical, per-tool) and new adapter implementations.

---

## Delta Since Last Refresh (2026-07-01 → 2026-07-13)

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
| Last commit to lib/core | 2026-07-01 | 2026-07-01 (unchanged in 12 days) | — |

## Recommended Next Steps (Updated 2026-07-13)

### Immediate (Next 2 Weeks)

1. **Expand test coverage for `lib/core`** — 17 files exist but cover only 3/10 entities, 1/5 errors, 1/15 providers. Priority: finish entity tests (ContentCollection, CreateContentCommand, Credential, HttpResponse, JobStatus, StoredFile, UpdateContentCommand, UserInfo), error tests (AccessDeniedException, AuthenticationException, NotFoundException, ValidationException), and top-5 provider clients (OpenAI, Gemini, Anthropic, DeepSeek, Ollama).

2. **Add missing provider client tests** — 14 of 15 provider clients have zero test coverage (only DeepSeekClient tested). Provider clients form the most critical path for AI requests.

3. **Create `tests/Integration/` directory** — the `phpunit.xml.dist` already declares an Integration testsuite but the directory doesn't exist. Add at least one end-to-end test: `ChatOrchestrator` + mocked provider + real tool through the full agentic loop.

### Short-Term (2–8 Weeks)

4. **Migrate the next batch of ~30 base tools** — target content-reading tools (`get-site-health`, `get-cron-job`, `list-cron-jobs`, `get-post-type-schema`), settings tools, and caching tools that already have adapter implementations. Batch migration pattern is proven (see June 4 commits: 5-tool batches with tests).

5. **Begin Laravel adapter** — implement `ContentStore` (Eloquent), `AuthProvider` (Sanctum), `SettingsStore` (Config), and `CacheStore` (Redis). Start with the ChatController.

6. **Document the feature flag** in `docs/features/oos-engine.md` — how to enable, what changes, migration path from legacy engine.

7. **Publish `nvoos/core` to Packagist** — composer.json is prepared. Even as `dev-master` to unblock Laravel adapter development.

### Medium-Term (2–6 Months)

8. **Complete Tier 2 + Tier 3 tool migration** — ~60 remaining content and state-changing tools.

9. **Promote PHPStan to level 8** — once adapter targets PHP 8.1+, add array shape/value types to all interface methods (currently blocked by ~251 bare `array` type errors).

10. **Extract voice/realtime providers** — OpenAI Realtime and Gemini Live into `lib/core/src/Infrastructure/Voice/`.

11. **Establish CI/CD for extracted packages** — separate PHPUnit/Stan runs for `lib/core` and `lib/wordpress-adapter`.

12. **Begin Craft CMS adapter** — element-type-backed implementations.

### Long-Term (6–12 Months)

13. **Sunset the legacy engine path** — once all tools are migrated and the OOS engine is default, remove the feature flag and legacy code paths.

14. **Publish `nvoos/laravel-adapter` and `nvoos/craft-adapter`** — documentation, Packagist, marketing.

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

The cross-platform extraction is **not a proposal gathering dust** — it's a fully operational, feature-flagged implementation with **82 tools** (42% of base), **15 provider clients**, **8 adapters**, and a complete Hexagonal Architecture. The Strangler Fig pattern is working exactly as described: the new engine coexists with the legacy path, and the bridge file (`oos-bridge.php`) is the single integration point. The hardest work — designing the interfaces, building the adapters, wiring the agentic loop — is done. What remains is volume: migrating tools one by one (58% of base + all Pro), building test coverage (17 files → target 60+), and building adapters for additional platforms (Laravel, Craft).

**The most impactful next action is test coverage.** With 17 test files for 141 source files (12% file coverage), any regression in the OOS engine path is invisible. Provider client tests alone (1 of 15 clients tested) would catch the most dangerous bugs — these are the classes that send API keys over the wire.
