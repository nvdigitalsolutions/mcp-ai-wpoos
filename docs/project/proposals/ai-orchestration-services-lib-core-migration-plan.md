# AI Orchestration Services → `lib/core` Migration — Implementation Plan

**Date:** 2026-07-24
**Status:** Contract layer complete — Waves 1–4 contracts done (35 total, 85% coverage)
**Related:** [`legacy-php-service-inventory-lib-core-gap.md`](./legacy-php-service-inventory-lib-core-gap.md), [`cross-platform-extraction-gap-analysis.md`](./cross-platform-extraction-gap-analysis.md), [`cross-platform-extraction-architecture.md`](./cross-platform-extraction-architecture.md)

---

## Table of Contents

1. [Research & Best Practices](#1-research--best-practices)
2. [Dependency Analysis](#2-dependency-analysis)
3. [Domain Contracts Required](#3-domain-contracts-required)
4. [Architecture: How Services Fit into `lib/core`](#4-architecture-how-services-fit-into-libcore)
5. [Wave-Based Migration Plan](#5-wave-based-migration-plan)
6. [Bridge Pattern: Feature-Flag Activation](#6-bridge-pattern-feature-flag-activation)
7. [Testing Strategy](#7-testing-strategy)
8. [Risk Assessment](#8-risk-assessment)
9. [Timeline & Effort Estimate](#9-timeline--effort-estimate)
10. [Success Criteria](#10-success-criteria)

---

## 1. Research & Best Practices

### 1.1 Strangler Fig + Hexagonal Architecture (Proven Pattern)

The same pattern that successfully migrated `ChatOrchestrator`, `ProviderRouter`, `ToolRegistry`, and ~82 tools to `lib/core` applies here. Key principles:

| Principle | Application |
|---|---|
| **Domain-first** | Every service starts with a domain contract (`lib/core/src/Domain/Contract/`) defining WHAT, not HOW |
| **Adapter-per-platform** | WordPress-specific implementations live in `lib/wordpress-adapter/src/`, not in core |
| **Feature-flag routing** | `?engine=oos` routes to new implementation; absence routes to legacy — both paths stay operational |
| **Dependency inversion** | Services consume interfaces, not concrete implementations — they don't know which platform they're on |
| **Incremental delivery** | Each wave ships independently with full backward compatibility |

### 1.2 Industry References

| Source | Key Principle | Application |
|---|---|---|
| **Sam Newman, "Monolith to Microservices"** (2019) | Extract services by dependency level — leaf services first, orchestrators last | Wave 1 extracts stateless utilities; Wave 4 extracts the orchestrators that depend on them |
| **Eric Evans, DDD** (2003) | Bounded contexts, anti-corruption layers, domain events | Each service category is a bounded context; WordPress adapter acts as ACL |
| **Martin Fowler, Strangler Fig** (2004) | Incremental replacement, route-by-route | Feature flag `?engine=oos` routes traffic incrementally |
| **Vernon Vaughn, "Implementing DDD"** (2013) | Application services coordinate domain services; domain services contain business logic | `lib/core/src/Application/` for coordinators; `lib/core/src/Domain/Service/` for business rules |
| **Google SRE Workbook** | Gradual rollout with canary testing | Each wave activates via feature flag; monitor error rates before proceeding |
| **Project conventions** (oOS) | `WP_MCP_AI_` prefix, `@since` tags, capability checks, nonce verification, two-gate sanitize | All migrated services follow project conventions |

### 1.3 Key Design Decisions

| Decision | Rationale |
|---|---|
| **New `lib/core/src/Domain/Service/` namespace** | Houses pure business-logic services (no framework dependencies). Distinct from `Application/` (orchestrators) and `Infrastructure/` (external adapters). |
| **One interface per service category** | e.g., `EmbeddingServiceInterface` covers all embedding providers (OpenAI, Gemini, HuggingFace). Concrete providers are adapter implementations. |
| **WordPress adapters for platform-specific concerns** | `wp_insert_post`, `get_option`, WP-Cron, Action Scheduler, `WP_Query` — all wrapped behind domain contracts |
| **Feature flag per service wave** | Each wave gets its own flag (e.g., `?engine=oos&wave=memory`) so failures can be isolated |
| **Event-driven communication between services** | Services communicate via `EventDispatcherInterface` (not direct calls) where practical, enabling future async/queue patterns |
| **PHP 8.1+ in `lib/core`; PHP 7.4+ compat preserved in adapter** | The core uses readonly properties, enums, fibers; the WordPress adapter stays PHP 7.4+ compatible |

---

## 2. Dependency Analysis

### 2.1 Service Categories by Dependency Level

Analyzing the ~81 orchestration/AI services in `includes/services/` reveals 5 dependency tiers:

```
Tier 0: Zero dependencies (pure computation, no WordPress APIs)
Tier 1: Only depends on Tier 0 + domain contracts already in lib/core
Tier 2: Depends on Tier 1 + provider clients already in lib/core
Tier 3: Depends on Tier 2 + WordPress adapters
Tier 4: Orchestrators — depend on everything
```

### 2.2 Dependency Graph (Simplified)

```
                                    ┌─────────────────────┐
                                    │ ChatService          │ Tier 4
                                    │ ToolExecutionOrch    │
                                    │ AgentTeamOrchestrator│
                                    └──────────┬──────────┘
                                               │
                    ┌──────────────────────────┼──────────────────────────┐
                    │                          │                          │
        ┌───────────▼───────────┐  ┌──────────▼──────────┐  ┌───────────▼───────────┐
        │ Memory Pipeline       │  │ Token/Cost Pipeline  │  │ Embedding Pipeline     │ Tier 3
        │ (MemoryManager et al) │  │ (TokenBudget, Cost)  │  │ (ContentEmbedding)     │
        └───────────┬───────────┘  └──────────┬──────────┘  └───────────┬───────────┘
                    │                          │                          │
        ┌───────────▼───────────┐  ┌──────────▼──────────┐  ┌───────────▼───────────┐
        │ Context Compression   │  │ Token DB / Tracking  │  │ Vector Context         │ Tier 2
        │ Semantic Compressor   │  │ Model Config         │  │ Embedding Providers    │
        └───────────┬───────────┘  └──────────┬──────────┘  └───────────┬───────────┘
                    │                          │                          │
        ┌───────────▼──────────────────────────▼──────────────────────────▼───────────┐
        │                                                                              │
        │  Tier 1: File Services, Model Discovery, Rate Limiting, Cron Status          │
        │                                                                              │
        └──────────────────────────────────┬───────────────────────────────────────────┘
                                           │
        ┌──────────────────────────────────▼───────────────────────────────────────────┐
        │                                                                              │
        │  Tier 0: Text Chunker, Data Budget Tracker, PSO Optimizer, Erlang C,        │
        │          Code Optimizer, Function Call Validator, Semantic Compressor        │
        │          (pure computation — zero WordPress, zero HTTP)                       │
        │                                                                              │
        └──────────────────────────────────────────────────────────────────────────────┘
```

### 2.3 Service Inventory by Tier

#### Tier 0 — Pure Computation (No Dependencies)

These are stateless algorithms. They compute results from inputs only — no WordPress, no HTTP, no database.

| Service | What It Computes | LOC |
|---|---|---|
| `TextChunker` / `SemanticCompressor` | Text splitting with semantic boundaries, chunk overlap | ~400 |
| `PSOOptimizerService` | Particle Swarm Optimization for parameter tuning | ~300 |
| `ErlangC` | Erlang C staffing calculations | ~200 |
| `CodeOptimizer` | Code quality analysis, linting rule application | ~200 |
| `FunctionCallValidator` | JSON Schema + parameter validation for tool calls | ~300 |
| `DataBudgetTracker` | Budget arithmetic (tokens, cost, time, storage) | ~200 |
| `ToolChainPredictor` | Markov-chain prediction of tool sequences | ~200 |
| `ToolLifecycleDescriptor` | Tool lifecycle state machine (idle → executing → done) | ~150 |

**Total: ~8 services, ~1,950 LOC**

#### Tier 1 — Depends Only on Tier 0 + `lib/core` Contracts

These need domain contracts (`HttpClientInterface`, `CacheStoreInterface`, `SettingsStoreInterface`) already defined in `lib/core`.

| Service | Depends On | LOC |
|---|---|---|
| `ModelDiscoveryService` | `HttpClientInterface` (fetch model lists) | ~300 |
| `ModelService` | `HttpClientInterface`, `CacheStoreInterface` | ~400 |
| `AssistantService` | `ContentStoreInterface`, `SettingsStoreInterface` | ~500 |
| `ProfessionService` / `ProfessionKnowledgeBaseLoader` / `ProfessionPlaybookLoader` / `ProfessionToolRecommender` / `TeamKnowledgeBaseLoader` | `ContentStoreInterface`, `FileStoreInterface` | ~1,200 |
| `ProcessService` | `SettingsStoreInterface` | ~200 |
| `TokenBudgetService` | `SettingsStoreInterface`, `CacheStoreInterface` | ~500 |
| `CostTrackingService` | `CacheStoreInterface`, `EventDispatcherInterface` | ~300 |
| `ErrorTrackingService` | `EventDispatcherInterface`, `ErrorFactoryInterface` | ~300 |
| `RateLimitManager` / `SSERateLimiter` | `CacheStoreInterface`, `SettingsStoreInterface` | ~400 |
| `FileService` / `FileServiceFactory` / `FileOrchestrationService` | `FileStoreInterface`, `HttpClientInterface` | ~800 |
| `FilePreprocessingHelper` | `FileStoreInterface` | ~200 |

**Total: ~18 services, ~5,100 LOC**

#### Tier 2 — Depends on Tier 1 + Provider Clients

These need AI provider clients (already in `lib/core`) for embedding, LLM calls, etc.

| Service | Depends On | LOC |
|---|---|---|
| `ContentEmbeddingService` / `ContentEmbeddingStore` | `FileService`, `HttpClientInterface`, provider clients, `CacheStoreInterface` | ~800 |
| `ContextEmbeddingStore` | `ContentEmbeddingService`, `CacheStoreInterface` | ~400 |
| `ContextCompressionService` | `TokenBudgetService`, `TextChunker` | ~500 |
| `VectorContextService` | `ContentEmbeddingStore`, `ContextEmbeddingStore` | ~500 |
| `TokenUsageService` / `TokenPerformanceService` | `CostTrackingService`, `EventDispatcherInterface` | ~400 |
| `GeminiFileService` / `GeminiManagedAgentService` / `GeminiMusicService` / `GeminiOmniService` / `GeminiVideoGenerationService` | `HttpClientInterface`, `FileStoreInterface`, Gemini provider client | ~1,500 |
| `MubertMusicService` | `HttpClientInterface`, `CacheStoreInterface` | ~300 |
| `VideoAnalysisService` | `HttpClientInterface`, `FileStoreInterface`, provider clients | ~400 |
| `OpenAiFileService` | `HttpClientInterface`, OpenAI provider client | ~200 |

**Total: ~15 services, ~5,000 LOC**

#### Tier 3 — Depends on Tier 2 + Memory/Context Pipeline

These build on the context and embedding pipelines for memory and chat functionality.

| Service | Depends On | LOC |
|---|---|---|
| `MemoryManager` / `MemoryAutoCaptureService` / `MemoryCaptureService` / `MemoryTierManager` / `MemoryContradictionDetector` / `MemoryRrfFusionService` / `MemoryPrivacyFilter` | `ContextCompressionService`, `VectorContextService`, `EventDispatcherInterface`, `CacheStoreInterface` | ~2,500 |
| `CronStatusService` (+ 3 job source adapters) | `CacheStoreInterface`, `SettingsStoreInterface` | ~600 |
| `AsyncHealthMonitor` / `AsyncSchedulerBridge` | `QueueClientInterface`, `EventDispatcherInterface` | ~500 |
| `PerformanceMonitorService` / `PerformanceReportingService` | `CostTrackingService`, `TokenUsageService`, `EventDispatcherInterface` | ~600 |
| `TimeoutDetectionService` | `EventDispatcherInterface`, `CacheStoreInterface` | ~300 |
| `OTELSpanExporter` | `HttpClientInterface`, `EventDispatcherInterface` | ~400 |
| `EfficiencyMonitor` | `CostTrackingService`, `EventDispatcherInterface` | ~300 |
| `DSParkHooks` | Multiple Tier 2 services (dashboard wiring) | ~200 |

**Total: ~18 services, ~5,400 LOC**

#### Tier 4 — Orchestrators (Depend on Everything)

| Service | Depends On | LOC |
|---|---|---|
| `ChatService` | ToolExecutionOrchestrator, Memory Pipeline, Token Budget, Context Compression, Cost Tracking | ~2,000 |
| `ToolExecutionOrchestrator` | AsyncToolExecutor, SpeculativeExecutor, ToolLoadBalancer, LoadMonitor, DepthScheduler, HybridPlanGenerator, FunctionCallValidator | ~1,500 |
| `AsyncToolOrchestrator` / `ToolAsyncExecutor` | QueueClientInterface, EventDispatcherInterface, ToolRegistry | ~800 |
| `SpeculativeToolExecutor` | ToolExecutionOrchestrator, ToolChainPredictor, EventDispatcherInterface | ~500 |
| `ToolLoadBalancer` / `ToolLoadMonitor` / `ToolProfiler` / `ToolSettingsManager` | ToolRegistry, CacheStoreInterface, EventDispatcherInterface | ~1,000 |
| `HybridExecutor` / `HybridPlanGenerator` | ToolExecutionOrchestrator, EventDispatcherInterface | ~600 |
| `AgentTeamOrchestrator` / `AgentContextManager` / `AgentCommunicationService` | ChatService, Memory Pipeline, EventDispatcherInterface | ~1,200 |
| `ChatContinuationDispatcher` / `ChatContinuationStore` / `ChatContinuationLlmReEntry` / `ChatSessionFrameBuffer` | ChatService, TokenBudget, EventDispatcherInterface | ~1,200 |
| `EnhancedWorkflowCoordinator` | ToolExecutionOrchestrator, AgentTeamOrchestrator, EventDispatcherInterface | ~600 |
| `OrchestrationBudgetEnforcementService` / `OrchestrationDepthScheduler` / `OrchestrationHealthService` / `OrchestrationPresetService` / `OrchestrationPresets` | ChatService, ToolExecutionOrchestrator, SettingsStoreInterface | ~1,500 |
| `ReasoningController` | ChatService, TokenBudget, EventDispatcherInterface | ~400 |
| `BatchIterator` | QueueClientInterface | ~200 |
| `TranscriptMiningJob` | ContentEmbeddingService, MemoryManager, QueueClientInterface | ~400 |

**Total: ~22 services, ~11,900 LOC**

### 2.4 Grand Total

| Tier | Services | LOC |
|---|---|---|
| Tier 0 (Pure computation) | ~8 | ~1,950 |
| Tier 1 (lib/core contracts) | ~18 | ~5,100 |
| Tier 2 (Provider clients) | ~15 | ~5,000 |
| Tier 3 (Memory/Context) | ~18 | ~5,400 |
| Tier 4 (Orchestrators) | ~22 | ~11,900 |
| **Total** | **~81** | **~29,350** |

---

## 3. Domain Contracts Required

The existing 11 domain contracts in `lib/core` cover most infrastructure needs. New contracts are needed for service-specific boundaries:

### 3.1 New Domain Contracts

| Contract | Purpose | Replaces |
|---|---|---|
| `EmbeddingServiceInterface` | Generate embeddings from text | Direct OpenAI/Gemini API calls in ContentEmbeddingService |
| `TextChunkerInterface` | Split text into semantic chunks | Inline chunking logic across 4+ services |
| `MemoryStoreInterface` | Store/retrieve agent memories (CRUD + semantic search) | Direct `get_option`/`update_option` + CCT calls |
| `TokenCounterInterface` | Count tokens in text (model-aware) | Inline `str_word_count` / `mb_strlen` estimates |
| `ModelCatalogInterface` | Discover, list, and validate AI models | Direct `get_option` + provider API calls |
| `ProfessionRepositoryInterface` | Load profession definitions, playbooks, tool recommendations | Direct file reads from `professions/` directory |
| `OrchestrationPresetInterface` | Load/save orchestration presets (depth, budget, tool chains) | Direct `get_option` calls |
| `CronStatusInterface` | Query cron/Action Scheduler job status | Direct Action Scheduler API calls |

### 3.2 WordPress Adapters Needed

Each new domain contract needs a WordPress adapter:

| Adapter | Wraps |
|---|---|
| `WordPress\Adapter\EmbeddingService` | `wp_remote_post()` to OpenAI/Gemini embedding endpoints |
| `WordPress\Adapter\TextChunker` | Pure PHP — no WordPress deps (same implementation, different namespace) |
| `WordPress\Adapter\MemoryStore` | `get_option`/`update_option` + JetEngine CCT bridge |
| `WordPress\Adapter\TokenCounter` | Pure PHP — tiktoken-compatible counter (no WordPress deps) |
| `WordPress\Adapter\ModelCatalog` | `get_option('wp_mcp_ai_model_catalog')` + provider discovery |
| `WordPress\Adapter\ProfessionRepository` | File reads from `includes/professions/` |
| `WordPress\Adapter\OrchestrationPreset` | `get_option('wp_mcp_ai_orchestration_presets')` |
| `WordPress\Adapter\CronStatus` | Action Scheduler `as_get_scheduled_actions()` + WP-Cron inspection |

---

## 4. Architecture: How Services Fit into `lib/core`

### 4.1 Target Directory Structure

```
lib/core/src/
├── Domain/
│   ├── Contract/           (existing 11 + new 8 = 19 interfaces)
│   │   ├── EmbeddingServiceInterface.php      ← NEW
│   │   ├── TextChunkerInterface.php           ← NEW
│   │   ├── MemoryStoreInterface.php           ← NEW
│   │   ├── TokenCounterInterface.php          ← NEW
│   │   ├── ModelCatalogInterface.php          ← NEW
│   │   ├── ProfessionRepositoryInterface.php  ← NEW
│   │   ├── OrchestrationPresetInterface.php   ← NEW
│   │   └── CronStatusInterface.php            ← NEW
│   ├── Entity/             (existing 13)
│   ├── Error/              (existing 5 + domain-specific errors)
│   ├── Event/              (existing 8)
│   └── Service/            ← NEW: Pure business-logic services
│       ├── Text/           Tier 0
│       │   ├── TextChunker.php
│       │   └── SemanticCompressor.php
│       ├── Optimization/   Tier 0
│       │   ├── PsoOptimizer.php
│       │   ├── ErlangC.php
│       │   └── CodeOptimizer.php
│       ├── Validation/     Tier 0
│       │   └── FunctionCallValidator.php
│       ├── Budget/         Tier 0
│       │   └── DataBudgetTracker.php
│       ├── Tool/           Tier 0
│       │   ├── ToolChainPredictor.php
│       │   └── ToolLifecycleDescriptor.php
│       └── Memory/         Tier 3 (when memory pipeline extracted)
│           ├── MemoryManager.php
│           └── ...
├── Application/
│   ├── Chat/               (existing ChatOrchestrator)
│   ├── Provider/           (existing ProviderRouter)
│   ├── Tool/               (existing ToolRegistry)
│   ├── Skill/              (existing SkillRegistry)
│   ├── Model/              ← NEW: Model discovery and catalog
│   │   ├── ModelDiscoveryService.php
│   │   └── ModelCatalogService.php
│   ├── Embedding/          ← NEW: Embedding orchestration
│   │   └── EmbeddingOrchestrator.php
│   ├── Memory/             ← NEW: Memory pipeline orchestrator
│   │   └── MemoryOrchestrator.php
│   └── Orchestration/      ← NEW: Orchestration control
│       ├── OrchestrationBudgetEnforcer.php
│       ├── OrchestrationDepthScheduler.php
│       └── OrchestrationHealthMonitor.php
└── Infrastructure/
    ├── Provider/           (existing 15 clients)
    ├── Streaming/          (existing SseHandler)
    ├── Cost/               (existing CostCalculator)
    ├── Token/              (existing TokenBudgetManager)
    ├── Embedding/          ← NEW: Embedding provider adapters
    │   ├── OpenAiEmbeddingProvider.php
    │   ├── GeminiEmbeddingProvider.php
    │   └── HuggingFaceEmbeddingProvider.php
    ├── Chunking/           ← NEW: Text chunking implementations
    │   └── SemanticChunker.php
    └── Monitoring/         ← NEW: Observability
        ├── PerformanceMonitor.php
        ├── OtelSpanExporter.php
        └── HealthCheckService.php
```

### 4.2 Service Migration Pattern (Example)

**Legacy (Tier 0):**
```php
// includes/services/class-wp-mcp-ai-text-chunker.php
class WP_MCP_AI_Text_Chunker {
    const CHUNK_SIZE = 500;
    public static function chunk_text( string $text, int $maxTokens = 500 ): array {
        // ... pure PHP computation
    }
}
```

**After migration:**
```php
// lib/core/src/Domain/Contract/TextChunkerInterface.php
namespace Nvoos\Core\Domain\Contract;
interface TextChunkerInterface {
    /** @return list<string> */
    public function chunk( string $text, int $maxTokens = 500 ): array;
}

// lib/core/src/Domain/Service/Text/TextChunker.php
namespace Nvoos\Core\Domain\Service\Text;
use Nvoos\Core\Domain\Contract\TextChunkerInterface;
final readonly class TextChunker implements TextChunkerInterface {
    private const CHUNK_SIZE = 500;
    public function chunk( string $text, int $maxTokens = 500 ): array {
        // ... migrated logic, zero WordPress dependencies
    }
}

// lib/wordpress-adapter/src/Adapter/TextChunker.php (pass-through)
namespace Nvoos\WordPress\Adapter;
use Nvoos\Core\Domain\Service\Text\TextChunker as CoreChunker;
use Nvoos\Core\Domain\Contract\TextChunkerInterface;
final class TextChunker implements TextChunkerInterface {
    public function __construct( private readonly CoreChunker $chunker ) {}
    public function chunk( string $text, int $maxTokens = 500 ): array {
        return $this->chunker->chunk( $text, $maxTokens );
    }
}
```

### 4.3 Feature-Flag Bridge Pattern

Each migrated service gets a bridge function in `includes/bootstrap/oos-bridge.php`:

```php
/**
 * Get the TextChunker — framework-agnostic when engine=oos, legacy otherwise.
 *
 * @return Nvoos\Core\Domain\Contract\TextChunkerInterface
 */
function wp_mcp_ai_text_chunker(): TextChunkerInterface {
    static $instance = null;
    if ( $instance !== null ) {
        return $instance;
    }

    // Check feature flag
    $engine = $_GET['engine'] ?? $_SERVER['HTTP_X_WP_MCP_AI_ENGINE'] ?? '';
    $is_oos = ( $engine === 'oos' ) || ( defined( 'WP_MCP_AI_ENGINE' ) && WP_MCP_AI_ENGINE === 'oos' );

    if ( $is_oos && PHP_VERSION_ID >= 80100 ) {
        $instance = new \Nvoos\WordPress\Adapter\TextChunker(
            new \Nvoos\Core\Domain\Service\Text\TextChunker()
        );
    } else {
        // Legacy fallback — wraps the old class in the new interface
        $instance = new class implements TextChunkerInterface {
            public function chunk( string $text, int $maxTokens = 500 ): array {
                return \WP_MCP_AI_Text_Chunker::chunk_text( $text, $maxTokens );
            }
        };
    }

    return $instance;
}
```

---

## 5. Wave-Based Migration Plan

Each wave ships independently with full backward compatibility.

### Wave 1 — Stateless Utilities (Weeks 1–3)

**Goal:** Migrate Tier 0 services — no dependencies, no risk.

| Service | New Location | Effort |
|---|---|---|
| `TextChunker` / `SemanticCompressor` | `Domain/Service/Text/` | 3 days |
| `PSOOptimizerService` | `Domain/Service/Optimization/` | 1 day |
| `ErlangC` | `Domain/Service/Optimization/` | 0.5 days |
| `CodeOptimizer` | `Domain/Service/Optimization/` | 1 day |
| `FunctionCallValidator` | `Domain/Service/Validation/` | 2 days |
| `DataBudgetTracker` | `Domain/Service/Budget/` | 1 day |
| `ToolChainPredictor` | `Domain/Service/Tool/` | 1 day |
| `ToolLifecycleDescriptor` | `Domain/Service/Tool/` | 1 day |
| New contracts | `Domain/Contract/` (TextChunkerInterface, TokenCounterInterface) | 1 day |

**Deliverable:** 8 migrated services + 2 new contracts. `TextChunkerInterface` consumed by Tier 2 services.

### Wave 2 — Model & File Services (Weeks 4–7)

**Goal:** Migrate Tier 1 services that depend on existing `lib/core` contracts.

| Service Group | Services | Effort |
|---|---|---|
| Model discovery & catalog | `ModelDiscoveryService`, `ModelService`, `AssistantService` | 5 days |
| Profession & knowledge loading | `ProfessionService`, 3 loaders, 1 recommender, `TeamKnowledgeBaseLoader` | 5 days |
| Token & cost tracking | `TokenBudgetService`, `CostTrackingService`, `TokenUsageService` | 4 days |
| Error tracking | `ErrorTrackingService` | 1 day |
| Rate limiting | `RateLimitManager`, `SSERateLimiter` | 2 days |
| File services | `FileService`, `FileServiceFactory`, `FileOrchestrationService`, `FilePreprocessingHelper` | 5 days |
| New contracts | `ModelCatalogInterface`, `ProfessionRepositoryInterface`, `TokenCounterInterface` | 1 day |

**Deliverable:** ~18 migrated services + 3 new contracts. Model catalog and file services unblock Tier 2.

### Wave 3 — Embedding & Context (Weeks 8–12)

**Goal:** Migrate Tier 2 — embedding pipeline, context compression, provider-specific services.

| Service Group | Services | Effort |
|---|---|---|
| Embedding pipeline | `ContentEmbeddingService`, `ContentEmbeddingStore`, `ContextEmbeddingStore` | 5 days |
| Context compression | `ContextCompressionService` | 3 days |
| Vector context | `VectorContextService` | 3 days |
| Gemini-specific services | 5 services (File, ManagedAgent, Music, Omni, VideoGeneration) | 5 days |
| Other provider services | `MubertMusicService`, `VideoAnalysisService`, `OpenAiFileService` | 3 days |
| New contracts | `EmbeddingServiceInterface`, `MemoryStoreInterface` | 1 day |

**Deliverable:** ~15 migrated services + 2 new contracts. Embedding pipeline unblocks memory Tier 3.

### Wave 4 — Memory & Monitoring (Weeks 13–17)

**Goal:** Migrate Tier 3 — memory pipeline, cron status, health monitoring.

| Service Group | Services | Effort |
|---|---|---|
| Memory pipeline | 7 services (Manager, AutoCapture, Capture, Tier, Contradiction, RRF Fusion, Privacy) | 7 days |
| Cron status | `CronStatusService` + 3 job source adapters | 3 days |
| Health & monitoring | `AsyncHealthMonitor`, `AsyncSchedulerBridge`, `TimeoutDetectionService` | 3 days |
| Performance | `PerformanceMonitorService`, `PerformanceReportingService`, `EfficiencyMonitor` | 3 days |
| Telemetry | `OTELSpanExporter`, `DSParkHooks` | 2 days |
| New contracts | `CronStatusInterface` | 1 day |

**Deliverable:** ~18 migrated services + 1 new contract. Memory pipeline unblocks orchestrators.

### Wave 5 — Orchestrators (Weeks 18–26)

**Goal:** Migrate Tier 4 — the complex orchestrators that depend on everything.

| Service Group | Services | Effort |
|---|---|---|
| Chat orchestration | `ChatService`, `ChatContinuationDispatcher/Store/LlmReEntry/FrameBuffer` | 8 days |
| Tool execution | `ToolExecutionOrchestrator`, `AsyncToolOrchestrator`, `ToolAsyncExecutor`, `SpeculativeToolExecutor` | 6 days |
| Tool management | `ToolLoadBalancer`, `ToolLoadMonitor`, `ToolProfiler`, `ToolSettingsManager` | 4 days |
| Hybrid execution | `HybridExecutor`, `HybridPlanGenerator` | 3 days |
| Agent team | `AgentTeamOrchestrator`, `AgentContextManager`, `AgentCommunicationService` | 5 days |
| Orchestration control | `OrchestrationBudgetEnforcementService`, `OrchestrationDepthScheduler`, `OrchestrationHealthService`, `OrchestrationPresetService`, `OrchestrationPresets` | 5 days |
| Other | `ReasoningController`, `EnhancedWorkflowCoordinator`, `BatchIterator`, `TranscriptMiningJob` | 4 days |
| New contracts | `OrchestrationPresetInterface` | 1 day |

**Deliverable:** ~22 migrated services + 1 new contract. All 81 services migrated.

### Wave 6 — Cleanup & Legacy Bridge Removal (Weeks 27–30)

| Task | Effort |
|---|---|
| Remove legacy fallback code paths (once Wave 5 is production-proven) | 5 days |
| Deprecate legacy service classes (`@deprecated since 2.0.0`) | 3 days |
| Update all call sites to use bridge functions | 3 days |
| Performance benchmarking (lib/core vs legacy) | 2 days |
| Documentation (architecture diagrams, service map) | 2 days |
| Migration of Pro services (Schedule Manager, Result Delivery, etc.) — scoping only | 2 days |

**Deliverable:** Clean architecture. Legacy classes deprecated. Pro service migration scope defined.

---

## 6. Bridge Pattern: Feature-Flag Activation

### 6.1 Per-Wave Flags

Each wave gets its own activation flag for isolated canary testing:

```php
// Wave 1 activation
?engine=oos&wave=services-tier-0

// Wave 1+2 activation
?engine=oos&wave=services-tier-1

// Full service migration activation
?engine=oos&wave=services-all
```

### 6.2 Monitoring Dashboard

Add a WordPress admin page showing per-wave migration status:

```
┌─────────────────────────────────────────────────────────┐
│ oOS Service Migration Dashboard                          │
│                                                          │
│ Wave 1 (Stateless Utils)        ████████████ 100%       │
│ Wave 2 (Model & File)           ██████░░░░░░  50%       │
│ Wave 3 (Embedding & Context)    ░░░░░░░░░░░░   0%       │
│ Wave 4 (Memory & Monitoring)    ░░░░░░░░░░░░   0%       │
│ Wave 5 (Orchestrators)          ░░░░░░░░░░░░   0%       │
│                                                          │
│ Active engine: legacy (default)                          │
│ Error rate (oos): N/A                                    │
│ Error rate (legacy): 0.02%                               │
└─────────────────────────────────────────────────────────┘
```

### 6.3 Automatic Fallback

If the OOS engine throws an unhandled exception, the bridge catches it and falls back to legacy:

```php
function wp_mcp_ai_execute_with_fallback( callable $oos, callable $legacy, string $service_name ) {
    try {
        return $oos();
    } catch ( \Throwable $e ) {
        wp_mcp_ai_log_error( "OOS service {$service_name} failed, falling back to legacy", $e );
        return $legacy();
    }
}
```

---

## 7. Testing Strategy

### 7.1 Test Pyramid per Wave

```
       ┌──────────┐
       │ E2E      │ 5%  — Full chat flow with OOS engine
       ├──────────┤
       │ Integr.  │ 20% — Service + adapter + WordPress
       ├──────────┤
       │ Unit     │ 75% — Pure domain logic (no WP bootstrap)
       └──────────┘
```

### 7.2 Unit Tests (Domain Services)

Every Tier 0–2 service gets unit tests with **zero WordPress dependencies**:

```php
// tests/lib/core/Domain/Service/Text/TextChunkerTest.php
class TextChunkerTest extends TestCase {
    public function test_chunk_respects_max_tokens(): void {
        $chunker = new TextChunker();
        $chunks = $chunker->chunk( str_repeat( 'word ', 1000 ), 100 );
        foreach ( $chunks as $chunk ) {
            $this->assertLessThanOrEqual( 100, $chunker->countTokens( $chunk ) );
        }
    }
}
```

### 7.3 Integration Tests (Adapter + WordPress)

Adapted services get integration tests with WordPress loaded:

```php
// tests/lib/wordpress-adapter/MemoryStoreAdapterTest.php
class MemoryStoreAdapterTest extends WP_UnitTestCase {
    public function test_store_and_retrieve_memory(): void {
        $store = new MemoryStore( new SettingsStore() );
        $store->save( 'agent-1', [ 'key' => 'value' ] );
        $this->assertEquals( [ 'key' => 'value' ], $store->load( 'agent-1' ) );
    }
}
```

### 7.4 Existing Test Preservation

Legacy tests continue to pass throughout migration. No existing test is modified. New tests are additive.

### 7.5 Test Coverage Goals

| Wave | Target Coverage |
|---|---|
| Wave 1 (Tier 0) | 95%+ (pure computation, easy to test) |
| Wave 2 (Tier 1) | 85%+ (contract-dependent, mockable) |
| Wave 3 (Tier 2) | 80%+ (provider-dependent, mockable) |
| Wave 4 (Tier 3) | 75%+ (multi-service interactions) |
| Wave 5 (Tier 4) | 70%+ (complex orchestrators) |

---

## 8. Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Service behavior drift between legacy and OOS | Medium | High | Side-by-side comparison tests for every migrated service |
| Breaking WordPress-specific edge cases | Medium | High | Integration tests with WP_UnitTestCase for adapter layer |
| Performance regression in OOS path | Low | Medium | Benchmark before/after; OOS uses readonly props + fibers for parity |
| PHP version incompatibility (8.1+ in core, 7.4 in adapter) | Low | Medium | Adapter layer stays PHP 7.4; core is guarded by PHP_VERSION_ID check |
| ChatService migration destabilizes main chat flow | Medium | **Critical** | Migrate ChatService LAST (Wave 5). Only activate after all dependencies proven in Waves 1–4 |
| Memory pipeline data loss | Low | High | Read-only migration test (compare OOS vs legacy memory outputs); never delete legacy data until Wave 6 |
| Pro addon incompatibility | Medium | Medium | Pro services are out of scope for this plan; bridge assumes legacy fallback for Pro-only paths |

### 8.1 Critical Path

```
Wave 1 → Wave 2 → Wave 3 → Wave 4 → Wave 5 (ChatService)
                                        ↑
                                   Blocked until
                                   Waves 1–4 stable
```

**ChatService cannot migrate until all 59 services in Waves 1–4 are production-proven.** This is the single biggest risk — rushing Wave 5 before dependencies are stable.

---

## 9. Timeline & Effort Estimate

### 9.1 Summary

| Wave | Duration | Services | Effort (person-weeks) |
|---|---|---|---|
| Wave 1 — Stateless Utils | Weeks 1–3 | 8 services | 2 PW |
| Wave 2 — Model & File | Weeks 4–7 | 18 services | 4 PW |
| Wave 3 — Embedding & Context | Weeks 8–12 | 15 services | 4 PW |
| Wave 4 — Memory & Monitoring | Weeks 13–17 | 18 services | 4 PW |
| Wave 5 — Orchestrators | Weeks 18–26 | 22 services | 6 PW |
| Wave 6 — Cleanup | Weeks 27–30 | N/A | 2 PW |
| **Total** | **30 weeks** | **81 services** | **22 PW** |

### 9.2 Resource Model

Assumes **1 dedicated engineer** per wave. With 2 engineers, timeline compresses to ~18 weeks (Waves 1–4 parallelized, Wave 5 serial).

### 9.3 Canary Deployment

Each wave deploys behind feature flag to staging → 5% production → 50% production → 100% production over 1 week:

```
Week 1: Code complete + tests
Week 2: Staging validation
Week 3: 5% canary → 50% → 100% (monitor error rates)
Week 4: Begin next wave
```

---

## 10. Success Criteria

### Wave Completion Gates

Each wave must pass before the next begins:

- [ ] All unit tests pass (target coverage met)
- [ ] All integration tests pass (WP_UnitTestCase)
- [ ] Legacy test suite passes (no regressions)
- [ ] Side-by-side comparison: OOS output matches legacy output for 100 test scenarios
- [ ] Error rate in canary deployment ≤ legacy error rate
- [ ] P99 latency ≤ 1.1× legacy latency
- [ ] Memory usage ≤ 1.2× legacy usage
- [ ] PHPStan level 8 passes on all new code
- [ ] WPCS passes on all new adapter code

### Final Success Criteria (Wave 6)

- [ ] All 81 services migrated to `lib/core`
- [ ] Zero legacy service classes instantiated when `?engine=oos&wave=services-all`
- [ ] Legacy classes marked `@deprecated` with migration path documented
- [ ] Full test suite passes with coverage ≥ 80% across all service tiers
- [ ] Production error rate unchanged after full cutover
- [ ] Documentation updated: architecture diagram, service map, migration guide
- [ ] Pro service migration scope defined (next initiative)

---

## Appendices

### A. Files Created by Wave

| Wave | New Files |
|---|---|
| 1 | `Domain/Contract/TextChunkerInterface.php`, `Domain/Contract/TokenCounterInterface.php`, 8 `Domain/Service/*` classes, 2 adapter classes, 10 test files |
| 2 | `Domain/Contract/ModelCatalogInterface.php`, `Domain/Contract/ProfessionRepositoryInterface.php`, `Domain/Contract/TokenCounterInterface.php`, ~18 `Domain/Service/*` + `Application/Model/*`, ~10 adapter classes, ~28 test files |
| 3 | `Domain/Contract/EmbeddingServiceInterface.php`, `Domain/Contract/MemoryStoreInterface.php`, ~15 `Application/Embedding/*` + `Infrastructure/Embedding/*`, ~5 adapter classes, ~20 test files |
| 4 | `Domain/Contract/CronStatusInterface.php`, ~18 `Domain/Service/Memory/*` + `Infrastructure/Monitoring/*`, ~10 adapter classes, ~28 test files |
| 5 | `Domain/Contract/OrchestrationPresetInterface.php`, ~22 `Application/*` classes, ~15 adapter classes, ~37 test files |
| 6 | Documentation, deprecation notices, cleanup |

### B. Files Modified

| File | Change |
|---|---|
| `includes/bootstrap/oos-bridge.php` | Add bridge functions for each service wave |
| `includes/class-wp-mcp-ai-container.php` | Wire adapters through DI container |
| `includes/services-init.php` | Add feature-flag checks for service resolution |
| `lib/core/composer.json` | Update autoload for new namespaces |
| `CLAUDE.md` | Update service architecture section |
| `AGENTS.md` | Add service migration to context-loading table |
| Legacy service files | Add `@deprecated` tags (Wave 6 only) |

### C. References

1. Cockburn, A. (2005). *Hexagonal Architecture (Ports & Adapters)*. https://alistair.cockburn.us/hexagonal-architecture/
2. Evans, E. (2003). *Domain-Driven Design: Tackling Complexity in the Heart of Software*.
3. Fowler, M. (2004). *Strangler Fig Application*. https://martinfowler.com/bliki/StranglerFigApplication.html
4. Newman, S. (2019). *Monolith to Microservices*.
5. Vernon, V. (2013). *Implementing Domain-Driven Design*.
6. Project: Cross-Platform Extraction Architecture. [`cross-platform-extraction-architecture.md`](./cross-platform-extraction-architecture.md)
7. Project: Cross-Platform Extraction Gap Analysis. [`cross-platform-extraction-gap-analysis.md`](./cross-platform-extraction-gap-analysis.md)
8. Project: Legacy PHP Service Inventory. [`legacy-php-service-inventory-lib-core-gap.md`](./legacy-php-service-inventory-lib-core-gap.md)
9. Google TypeScript Style Guide (for comparable service design patterns). https://google.github.io/styleguide/tsguide.html
10. Project: `lib/core/src/Application/README.md` — existing application layer conventions
