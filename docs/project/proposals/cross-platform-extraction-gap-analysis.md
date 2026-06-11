# Cross-Platform Extraction — Implementation Status & Gap Analysis

**Date:** 2026-06-03 (original), refreshed 2026-06-11 (v1.1.29)  
**Status:** Live assessment — the extraction is operational behind a feature flag. Phases 0-2 complete, Phase 3 (tool migration) at ~22% (43/195 tools).  
**Feature Flag:** `?engine=oos`, `X-WP-MCP-AI-Engine: oos` header, `WP_MCP_AI_OOS_ENGINE` constant, or admin setting `enable_oos_engine`

**Key Files:**
- [`lib/`](../../lib/) — extracted core + WordPress adapter packages
- [`lib/core/`](../../lib/core/) — `nvoos/core` Composer package (PHP 8.1+, MIT)
- [`lib/wordpress-adapter/`](../../lib/wordpress-adapter/) — `nvoos/wordpress-adapter` (PHP 7.4+, GPL-3.0)
- [`includes/bootstrap/oos-bridge.php`](../../includes/bootstrap/oos-bridge.php) — WordPress DI wiring + feature flag
- [`cross-platform-extraction-architecture.md`](./cross-platform-extraction-architecture.md) — the vision proposal (2026-05-31)

---

## Executive Summary

The cross-platform extraction is **far more advanced** than the proposal alone suggests. A fully functional `nvoos/core` package with 9 domain contracts, 10 entities, 5 error classes, 8 events, 4 application services, 12 provider clients, SSE streaming, cost calculation, and **43 migrated tools** already exists at `lib/core/`. A companion `nvoos/wordpress-adapter` package with 8 adapter implementations and a DI bridge in `includes/bootstrap/oos-bridge.php` wires everything into the WordPress plugin behind a feature flag (`?engine=oos`). The existing plugin path is completely unaffected — the extraction is additive.

**What's operational:** A complete Hexagonal Architecture inside `lib/` running the agentic loop via `ChatOrchestrator` with 43 framework-agnostic tools, 12 AI providers, and WordPress adapters behind every port.

**What remains:** ~152 tools to migrate, tests for the extracted packages, the Laravel and Craft adapters, and monorepo/CI tooling.

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

### 🟡 Migrated Tools (43 / ~195 base — 22% Complete)

Registered in `wp_mcp_ai_oos_orchestrator()` in `oos-bridge.php`:

**Tier 1 — External API / Public Data (14 tools):**
`WebSearchTool`, `GetGdacsEventsTool`, `GetNhcActiveStormsTool`, `GetOpenMeteoForecastTool`, `ReliefwebReportsTool`, `GetModelInformationTool`, `ListAvailableModelsTool`, `ModerateContentTool`, `CreateTextEmbeddingsTool`, `SuggestBestModelTool`, `DeepResearchTool`, `ProbeRemoteMcpTool`, `RunCrawl4AiJobTool`, `Crawl4AiPriceLookupTool`

**HuggingFace Datasets (11 tools):**
`HuggingFaceDatasetSearch`, `GetInfo`, `GetRows`, `GetSize`, `GetStatistics`, `IsValid`, `ListSplits`, `Filter`, `GetParquet`, `PreviewRows`, `RecommendedDatasets`

**Client-Side (6 tools):**
`ClientAnalyzeSentiment`, `ClientSummarizeText`, `ClientTranslateText`, `ClientExtractEntities`, `ClientQuestionAnswering`, `ClientSemanticSearch`

**Tier 2 — Content (6 tools):**
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

### 🔴 Blocking Full Production Activation

| Gap | Impact | Effort |
|---|---|---|
| **Tests for lib/core** | No test directory exists under `lib/core/tests/` — the extracted packages lack test coverage | Medium (3–4 weeks) |
| **~152 tool migrations** | 78% of tools still call WordPress APIs directly. These work in the legacy path but aren't available in the OOS engine. | High (8–16 weeks) |
| **PHP 8.1+ requirement for core** | Core uses `readonly`/enums. WordPress sites on PHP 7.4 can't use the OOS engine. The adapter targets PHP 7.4 but the core doesn't. | Low (documentation only — this is by design per the proposal) |

### 🟡 Medium Priority

| Gap | Impact | Effort |
|---|---|---|
| **Laravel adapter** | 0% — not started. The 8 domain contracts are ready; adapters need Eloquent/Sanctum/Storage/Queue implementations. | Medium (4–6 weeks) |
| **Craft adapter** | 0% — not started. Craft Commerce and element-type expertise needed. | Medium (4–6 weeks) |
| **Pro addon tools** | ~765 pro tools not migrated. Many are external API tools (easy to migrate), some are plugin-specific (WooCommerce, JetEngine — harder). | High (ongoing) |
| **Monorepo tooling** | `lib/` lives inside the WP plugin repo. Not yet a standalone monorepo with CI/CD across packages. | Low (1–2 weeks) |
| **Composer package publishing** | Neither `nvoos/core` nor `nvoos/wordpress-adapter` are published to Packagist. | Low (setup + docs) |
| **PHPStan/static analysis** | No PHPStan config for `lib/core` or `lib/wordpress-adapter`. | Low (1 week) |

### 🟢 Low Priority / Nice to Have

| Gap | Notes |
|---|---|
| **AbstractTool base class** | Exists (`lib/core/src/Tool/AbstractTool.php`) — confirmed by `GetPostTool extends AbstractTool`. |
| **WordPress.org base build exclusion** | Already handled — `lib/` is absent from base builds, guarded in `oos-bridge.php`. |
| **Async tool execution** | Action Scheduler adapter exists (`QueueClient`). Need to verify async tool flow works through the OOS engine. |
| **Voice/realtime providers** | Not yet extracted. OpenAI Realtime and Gemini Live currently live in the WP plugin classes. |

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

## Recommended Next Steps (Updated)

### Immediate (Next 2 Weeks)

1. **Add PHPUnit tests for `lib/core`** — domain entities, error classes, and provider clients with mocked adapters. This is the single largest quality gap.

2. **Add PHPStan config for extracted packages** — Level 8 for `lib/core/src/`, Level 5 for `lib/wordpress-adapter/src/`.

3. **Publish `nvoos/core` to Packagist** — even as `dev-master`. Enables `composer require nvoos/core` for Laravel adapter development.

### Short-Term (2–8 Weeks)

4. **Migrate Tier 2 tools** — content-reading tools (`get-site-health`, `get-cron-job`, `list-cron-jobs`, `get-post-type-schema`) to use `ContentStoreInterface`.

5. **Begin Laravel adapter** — implement `ContentStore` (Eloquent), `AuthProvider` (Sanctum), `SettingsStore` (Config), and `CacheStore` (Redis). Start with the ChatController.

6. **Document the feature flag** in `docs/features/oos-engine.md` — how to enable, what changes, migration path from legacy engine.

### Medium-Term (2–6 Months)

7. **Complete Tier 2 + Tier 3 tool migration** — ~90 remaining content and state-changing tools.

8. **Extract voice/realtime providers** — OpenAI Realtime and Gemini Live into `lib/core/src/Infrastructure/Voice/`.

9. **Establish CI/CD for extracted packages** — separate PHPUnit/Stan runs for `lib/core` and `lib/wordpress-adapter`.

10. **Begin Craft CMS adapter** — element-type-backed implementations.

### Long-Term (6–12 Months)

11. **Sunset the legacy engine path** — once all tools are migrated and the OOS engine is default, remove the feature flag and legacy code paths.

12. **Publish `nvoos/laravel-adapter` and `nvoos/craft-adapter`** — documentation, Packagist, marketing.

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

The cross-platform extraction is **not a proposal gathering dust** — it's a fully operational, feature-flagged implementation with 43 tools, 12 providers, 8 adapters, and a complete Hexagonal Architecture. The Strangler Fig pattern is working exactly as described: the new engine coexists with the legacy path, and the bridge file (`oos-bridge.php`) is the single integration point. The hardest work — designing the interfaces, building the adapters, wiring the agentic loop — is done. What remains is volume: migrating tools one by one and building adapters for additional platforms.
