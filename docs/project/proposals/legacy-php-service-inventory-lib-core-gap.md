# Legacy PHP Service Inventory — `lib/core` Migration Gap

**Date:** 2026-07-24
**Status:** Contract layer complete — 35 domain contracts, 21 WordPress adapters, 71 of 84 base services covered (85%)
**Related:** [`chat-client-typescript-migration-current-state.md`](./chat-client-typescript-migration-current-state.md), [`cross-platform-extraction-gap-analysis.md`](./cross-platform-extraction-gap-analysis.md), [`ai-orchestration-services-lib-core-migration-plan.md`](./ai-orchestration-services-lib-core-migration-plan.md) ← **Implementation plan**

---

## Executive Summary

A systematic audit of `includes/`, `addons/pro/includes/`, and their `services/` subdirectories reveals **84 base service files + ~40 Pro service classes** in the legacy PHP layer. The `lib/core` layer now contains **35 domain contracts** (11 original + 24 new), **21 WordPress adapters**, and **2 pure domain services** covering **71 of 84 base services (85%)**.

**Contract migration is complete.** The remaining 13 uncovered services are provider-specific implementations, infrastructure plumbing, or sub-200-LOC utilities that don't warrant domain contracts.

**Impact on the widget TS migration: LOW.** The widget communicates through REST endpoints and SSE streams whose contracts are stable regardless of backend implementation.

---

## Already Migrated (✅ `lib/core`)

| Category | Count | Location |
|---|---|---|
| Domain contracts | 11 interfaces | `lib/core/src/Domain/Contract/` |
| Domain entities | 13 entities | `lib/core/src/Domain/Entity/` |
| Domain errors | 5 classes | `lib/core/src/Domain/Error/` |
| Domain events | 8 classes | `lib/core/src/Domain/Event/` |
| Chat orchestrator | 1 class | `lib/core/src/Application/Chat/ChatOrchestrator.php` |
| Provider router | 1 class | `lib/core/src/Application/Provider/ProviderRouter.php` |
| Tool registry | 1 class | `lib/core/src/Application/Tool/ToolRegistry.php` |
| Skill registry | 1 class | `lib/core/src/Application/Skill/SkillRegistry.php` |
| SSE handler | 1 class | `lib/core/src/Infrastructure/Streaming/SseHandler.php` |
| Cost calculator | 1 class | `lib/core/src/Infrastructure/Cost/CostCalculator.php` |
| Token budget | 1 class | `lib/core/src/Infrastructure/Token/TokenBudgetManager.php` |
| Provider clients | 15 clients | `lib/core/src/Infrastructure/Provider/` (OpenAI, Gemini, Anthropic, DeepSeek, Ollama, Cloudflare, HuggingFace, Kimi, LM Studio, Nvidia NIM, Baseten, DigitalOcean, OpenRouter, OpenAiCompatible + AbstractProviderClient) |
| WordPress adapters | 8 adapters | Content, Auth, Settings, File, Cache, Queue, Event, Error |
| Migrated tools | ~82 tools | `lib/core/src/Tool/` |

**Total: ~58 classes**

---

## 🔴 Core Infrastructure (Duplicated — Legacy Versions Still Active)

These have equivalents in `lib/core` but the legacy versions handle unmigrated paths:

| Legacy Class | `lib/core` Equivalent | Why Still Active |
|---|---|---|
| `class-wp-mcp-ai-tool-registry.php` | `ToolRegistry.php` | ~113 unmigrated tools register here |
| `class-wp-mcp-ai-skill-registry.php` / `skill-pack-registry.php` | `SkillRegistry.php` | Skill packs use legacy registry |
| `class-wp-mcp-ai-language-model-router.php` | `ProviderRouter.php` | Feature-flag gated; non-`?engine=oos` traffic |
| `class-wp-mcp-ai-cost-calculator.php` | `CostCalculator.php` | Legacy cost tracking for unmigrated tools |
| `class-wp-mcp-ai-sse-stream.php` | `SseHandler.php` | Legacy SSE path for unmigrated tools |
| `class-wp-mcp-ai-encryption.php` | — (no equivalent) | Cross-cutting; used by credentials, tokens |
| `class-wp-mcp-ai-credentials.php` | `Credential.php` (entity) | Legacy credential storage |
| `class-wp-mcp-ai-logger.php` | — (no equivalent) | Debug, error, activity logging |
| `class-wp-mcp-ai-http.php` / `http-helper.php` | `HttpClientInterface.php` | WP HTTP layer wrapping `wp_remote_*` |
| `class-wp-mcp-ai-error-handler.php` | `Error/` (5 error classes) | Global handler + WP_Error bridge |
| `class-wp-mcp-ai-cache-helper.php` | `CacheStoreInterface.php` | WP transient/cache wrapper |
| `class-wp-mcp-ai-container.php` | — (no equivalent) | DI container |

**Count: 12 legacy classes with lib/core equivalents**

---

## 🟡 Orchestration & AI Pipeline (Base — `includes/services/`)

| Category | Classes | Count |
|---|---|---|
| **Chat orchestration** | `ChatService`, `ChatContinuationDispatcher`, `ChatContinuationStore`, `ChatContinuationLlmReEntry`, `ChatSessionFrameBuffer` | 5 |
| **Tool execution** | `ToolExecutionOrchestrator`, `AsyncToolOrchestrator`, `SpeculativeToolExecutor`, `ToolAsyncExecutor`, `ToolArtifactHelper`, `ToolChainPredictor`, `ToolLifecycleDescriptor`, `ToolLoadBalancer`, `ToolLoadMonitor`, `ToolProfiler`, `ToolSettingsManager`, `HybridExecutor`, `HybridPlanGenerator`, `FunctionCallValidator` | 14 |
| **Agent/Team** | `AgentTeamOrchestrator`, `AgentContextManager`, `AgentCommunicationService` | 3 |
| **Memory pipeline** | `MemoryManager`, `MemoryAutoCaptureService`, `MemoryCaptureService`, `MemoryTierManager`, `MemoryContradictionDetector`, `MemoryRrfFusionService`, `MemoryPrivacyFilter` | 7 |
| **Token/cost** | `TokenBudgetService`, `TokenUsageService`, `TokenPerformanceService`, `CostTrackingService`, `DataBudgetTracker` | 5 |
| **Context/embedding** | `ContextCompressionService`, `ContextEmbeddingStore`, `ContentEmbeddingService`, `ContentEmbeddingStore`, `VectorContextService`, `SemanticCompressor`, `HNswIndex` | 7 |
| **Orchestration control** | `OrchestrationBudgetEnforcementService`, `OrchestrationDepthScheduler`, `OrchestrationHealthService`, `OrchestrationPresetService`, `OrchestrationPresets`, `ReasoningController`, `DSParkHooks`, `EfficiencyMonitor`, `CodeOptimizer`, `PSOOptimizerService` | 10 |
| **Monitoring/Observability** | `PerformanceMonitorService`, `PerformanceReportingService`, `ErrorTrackingService`, `TimeoutDetectionService`, `AsyncHealthMonitor`, `OTELSpanExporter`, `CronStatusService` | 7 |
| **Model/Provider** | `ModelService`, `ModelDiscoveryService`, `ModelSelector` (legacy), `ModelConfig` (legacy), `AssistantService`, `ProfessionService`, `ProcessService` | 7 |
| **File/Media** | `FileService`, `FileServiceFactory`, `FileOrchestrationService`, `FilePreprocessingHelper`, `GeminiFileService`, `GeminiManagedAgentService`, `GeminiMusicService`, `GeminiOmniService`, `GeminiVideoGenerationService`, `MubertMusicService`, `VideoAnalysisService`, `OpenAiFileService` | 12 |
| **Profession/Knowledge** | `ProfessionKnowledgeBaseLoader`, `ProfessionPlaybookLoader`, `ProfessionToolRecommender`, `TeamKnowledgeBaseLoader` | 4 |

**Subtotal: ~81 service classes**

---

## 🟡 Infrastructure Services (Base — `includes/` top-level)

| Category | Classes | Count |
|---|---|---|
| **Queue/Job** | `QueueManager`, `DeadLetterQueue`, `JobQueueManager`, `AsyncJobQueue`, `AsyncSchedulerBridge`, `BatchIterator`, `JobNotifier`, `JobNotifierRest` | 8 |
| **Scheduling** | `CronManager`, `TranscriptMiningJob` | 2 |
| **Rate limiting** | `RateLimitManager`, `SSERateLimiter` | 2 |
| **Security** | `SecurityManager`, `SecurityAudit`, `SecurityTraining`, `SupplierSecurity`, `RootSecurityKey`, `Privacy`, `NefariousUsageMonitor` | 7 |
| **Federation/Mesh** | `Federation`, `FederationDirectoryRest`, `FederationPeerVerifier`, `FederationRateLimiter`, `FederationSettings`, `FederationWellknown`, `MeshRouter`, `MeshPeerSync` | 8 |
| **Transcript/History** | `TranscriptRetention`, `ChatTranscriptRecorder`, `ChatResponseCache`, `ConversationSummarizer`, `ConversationRagBridge`, `ThreadManager` | 6 |
| **Workflow** | `WorkflowEngineV2`, `WorkflowDispatcher`, `AgenticWorkflowOptimizer`, `ApprovalQueue`, `EnhancedWorkflowCoordinator` | 5 |
| **Token/Usage DB** | `TokenTrackingDatabase`, `EnhancedTokenTracking`, `TokenDbOptimizer`, `ModelRateLimitsCCT`, `ModelPricingChecker`, `ModelCatalogMigration`, `UsageTracker`, `ToolTokenLimits` | 8 |
| **Misc utilities** | `DocumentSummarizer`, `TextChunker`, `SLAManager`, `StdioTransport`, `ResourceManager`, `RetryStrategy`, `ErlangC`, `PerformanceBenchmark`, `MediaUrlUtils`, `ProxyUtils`, `IncidentLearning`, `InformationLabelling`, `PromptOptimizer` | 13 |
| **Integration/shortcode** | `ChatKitIntegration`, `MessageAttachments`, `ResponseAttachments`, `ElementorIntegration`, `Shortcode`, `Shortcodes`, `ChatBubbleFrontend`, `ProfessionalSelectorShortcode`, `QuickActionsHandler` | 9 |
| **Tooling support** | `ToolkitRegistry`, `ToolkitEnhancementIntegration`, `PatternRegistry`, `PatternConstants`, `PatternWorkflowTemplates`, `ToolRecommendations`, `ToolQueueProfiles`, `ToolResponseAdapter`, `GenericToolResponseImpl`, `TaskPlanSeeder`, `ToolTokenLimits` | 10 |
| **Asset loading** | `TransformersEnqueue`, `WebworkerEnqueue` | 2 |

**Subtotal: ~80 infrastructure/utility classes**

---

## 🟡 Data Layer (Base — CPT/CCT registrations)

| Category | Classes | Count |
|---|---|---|
| **CPT classes** | `AssistantCpt`, `WorkflowCpt`, `WorkflowRunCpt`, `WorkflowTriggerCpt`, `WorkflowTriggerRegistry`, `AiPeerCpt`, `DefaultAssistants` | 7 |
| **JetEngine CCT** | `JetEngineCct`, `JetEngineAgentMemoriesCct`, `JetEngineAiPeersCct`, `JetEngineAssistantsCct`, `JetEngineSubmissionsCct`, `JetEngineUsageLogsCct`, `JetEngineEndpointReport`, `JetEngineToolHandlers`, `JetFormBuilderToolHandlers` | 9 |
| **Agent memory** | `AgentMemoryCctBridge`, `AgentMemoryCctMigrator`, `AgentMemoryCctReader`, `AgenticWorkflowOptimizer` | 4 |
| **Other** | `AssetInventory`, `ActivationTracker`, `ModelRateLimitsCCT`, `ModelConfig`, `ModelCatalogMigration`, `OptionalComponents`, `UpgraderSkin`, `SiteHealth`, `SimpleJwtLoginIntegration`, `RequestContext` | 10 |

**Subtotal: ~30 data-layer classes**

---

## 🟠 Pro Addon Services (`addons/pro/includes/services/`)

| Class | Purpose |
|---|---|
| `ResultDeliveryService` (1,343 LOC) | Multi-channel delivery (email, Slack, Paper Store, webhook) for schedule results |
| `ContactImporterService` | Bulk contact import from CSV/JSON |
| `ContentTemplateEngine` | Dynamic content generation with templates |
| `CrmGmailClient` | Gmail API integration for CRM |
| `FeaturedImageService` | AI-generated featured image pipeline |
| `FluentFfmpegService` | FFmpeg media processing wrapper |
| `HfVisionInferenceService` | HuggingFace vision model inference |
| `JukeboxService` | Music generation via AI |
| `LanguageDetectionService` | Multi-language text detection |
| `MjmlService` | MJML → HTML email template conversion |
| `NodemailerService` | Email sending abstraction |
| `NvCloudService` / `NvCloudBillingObserver` | NV Cloud API + billing monitoring |
| `OcrService` | Optical character recognition |
| `PrettierService` | Code formatting via Prettier |
| `ProChatContinuationNotifier` | Pro-specific SSE continuation notifications |
| `ProWorkflowBridge` | Workflow bridge to Pro tools |
| `SkillCatalogueService` | Skill catalogue management |
| `TeamBudgetManager` | Team-level token budget enforcement |
| `ValidatorService` | Pro input validation layer |
| `VectorStoreAdapter` | Vector store abstraction |
| `VideoFrameExtractorService` | Video frame extraction |

**Count: 22 Pro service classes**

---

## 🟠 Pro Addon Managers & Infrastructure (`addons/pro/includes/`)

| Category | Classes | Count |
|---|---|---|
| **Schedule/Result delivery** | `ProScheduleManager`, `ProSchedulePresets`, `ProWorkflowPresets`, `PmNotificationManager` | 4 |
| **External API clients** | `LinkedInClient`, `UpworkClient`, `ShopifyClient`, `FlowHubClient`, `JetEngineMcpClient` | 5 |
| **ERP/Sync engines** | `EzUiteSyncEngine`, `EzUiteAlertManager`, `EzUiteCCTManager`, `EzUiteCli`, `EzUiteMigration`, `FlowHubSyncEngine`, `FlowHubCCTManager`, `FlowHubCli`, `FlowHubMigration`, `ShopifySyncEngine`, `ShopifySyncCCTManager`, `ShopifySyncCli`, `ShopifySyncWebhookHandler`, `SyncLogManager` | 14 |
| **Infra components** | `ProCdnLoader`, `ProSpaLoader`, `ProCollaborativePresence`, `ProParallelModelDispatcher`, `ProMeshPeerBidirectionalSync`, `ProRemoteSiteManager`, `ProInlineAssistant`, `CircuitBreaker`, `ExecutionLogger`, `ExecutionHistoryCCT`, `MemoryRetention`, `ProWorkflowBridge`, `LangchainEnqueue`, `ProToolkitIntegration`, `ProToolkitBlocks`, `ProToolkitShortcodes`, `ProCptMetaSchema`, `ProPrivacy`, `ToolkitDataStoreFactory`, `WebhookContextManager`, `JetEngineMetaHelper` | 21 |

**Subtotal: ~44 Pro manager/infrastructure classes**

---

## 🟠 Pro Addon CPT/CCT Classes (`addons/pro/includes/`)

~40 domain-specific CPT/CCT registration classes for: architectural drawings/projects/specs/precedents, comic characters/scripts/panels, CRM contacts/activities/messages, companies, customers, deals, leads, ECA, events, financial accounts, healthcare/wellness, imaging studies, document templates, image templates, media templates/collections, places, projects, quizzes, regulatory registrations, sequences, support tickets, tasks/task-plans/task-templates, site templates, product brands, content format templates, CRE debt, DICOM metadata, autonomous sessions, execution history, channel contacts/messages, quizzes, vitals log, etc.

**Count: ~40 Pro CPT/CCT classes**

---

## 🟢 Paper Store (Separate Subsystem)

| Class | Location | `lib/core` Status |
|---|---|---|
| `PaperStoreManager` (singleton) | `includes/paper-store/` | 🔴 Not migrated |
| `PaperJsonDriver` | `includes/paper-store/` | 🔴 Not migrated |
| `PaperIndex` | `includes/paper-store/` | 🔴 Not migrated |
| `PaperQuery` | `includes/paper-store/` | 🔴 Not migrated |
| `PaperRepository` | `includes/paper-store/` | 🔴 Not migrated |
| `PaperDriver` (interface) | `includes/paper-store/` | 🔴 Not migrated |
| Pro: `PaperMarkdownYamlDriver` | `addons/pro/includes/paper-store/` | 🔴 Not migrated |
| Pro: `PaperGitSync` | `addons/pro/includes/paper-store/` | 🔴 Not migrated |

**Count: 8 Paper Store classes**

---

## Summary: Total Legacy Footprint

### Contract Layer — Complete (2026-07-24)

| Category | Count |
|---|---|
| Domain contracts (original) | 11 |
| Domain contracts (new — this session) | **24** |
| **Total domain contracts** | **35** |
| Pure domain services | 2 (`DataBudgetTracker`, `ErlangC`) |
| WordPress adapters (original) | 10 |
| WordPress adapters (new — this session) | **21** |
| **Total adapters** | **31** |
| Base service files covered | 71 of 84 (85%) |
| Base service files uncovered | 13 (15%) — provider-specific or utility |

### Remaining (Non-Contract) Work

| Layer | Classes | lib/core Status |
|---|---|---|
| `lib/core` (migrated) | ~58 classes + 35 contracts + ~82 tools | ✅ Infrastructure complete |
| Base orchestrator/services — contracts | 71 of 84 services | ✅ 85% contract coverage |
| Base orchestrator/services — uncovered | 13 (provider/utility) | 🟢 Don't need contracts |
| Base duplicated infrastructure | ~12 classes | Duplicated — bridged |
| Base infrastructure/utility | ~80 classes | 🔴 Not migrated |
| Base data-layer CPT/CCT | ~30 classes | 🔴 Not migrated |
| Pro services | ~22 classes | 🔴 Not migrated |
| Pro managers/infrastructure | ~44 classes | 🔴 Not migrated |
| Pro CPT/CCT registrations | ~40 classes | 🔴 Not migrated |
| Paper Store | ~8 classes | 🔴 Not migrated |
| Legacy tools (base) | ~113 tools | 🔴 Not migrated |
| Legacy tools (pro) | ~830 tools | 🔴 Not migrated |

**Contract layer: COMPLETE.** The remaining PHP work is tool migration (~943 tools), Pro services (see [`pro-services-portability-assessment.md`](./pro-services-portability-assessment.md) — 17 of ~106 portable), and infrastructure utility classes.

---

## Impact on Widget TypeScript Migration

**Rating: LOW — No blocking dependency.**

The widget TS migration is a **frontend-only** effort that communicates with the server through stable contracts:

1. **REST endpoints** (`/mcp-ai/v1/chat`, `/mcp-ai/v1/chat-client`, `/mcp-ai/v1/tools`, etc.)
2. **SSE event streams** (tool_call_started, tool_call_completed, annotation, done, memory_event)
3. **`window.wpMcpAi*` service namespace** (local JS services)

None of these contracts change based on whether the backend uses `lib/core` or legacy PHP. The widget sees:
- Standardized chat messages (role, content, tool_calls)
- Standardized tool results (strings, arrays, or objects)
- Standardized job events (job:started, job:progress, job:completed)

**Indirect benefits as services migrate to `lib/core`:**
- Cleaner SSE frame types → less normalization code in `streaming.ts`
- Standardized error shapes → less defensive parsing in `tool-execution.ts`
- Documented contracts → future TypeScript type generation from JSON Schema
- Consistent behavior → fewer edge cases to test

**Bottom line:** The 230+ legacy services and ~943 tools are a **PHP migration concern** for future phases of the cross-platform extraction. The widget TS migration can proceed independently — its 16-module extraction plan is unaffected by backend service migration status.
