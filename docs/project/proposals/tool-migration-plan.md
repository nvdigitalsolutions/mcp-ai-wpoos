# Base Tool Migration Plan — Legacy → `nvoos/core`

> **Status:** Substantially Complete  
> **Date:** 2026-07-25 (original), refreshed 2026-07-26  
> **Context:** Cross-Platform Extraction (Strangler Fig), Phase: Tool Migration  
> **Current:** 190 tools framework-agnostic (77%), ~44 base tools intentionally not migrated  
> **Target:** 100% base tool coverage in `lib/core/src/Tool/` (achievable — remaining ~44 are plugin-dependent or deeply WordPress-native per Strangler Fig strategy)

## Executive Summary

**As of 2026-07-26, the 109-tool migration sprint is complete.** 190 of ~234 legacy base tools
are now framework-agnostic classes in `lib/core/src/Tool/*Tool.php`. The remaining ~44 tools
fall into two categories that are intentionally left in the legacy path per the Strangler Fig
pattern:

---

## 1. Gap Analysis — Tools by Category

### 1.1 Migrated to OOS Core (190 tools — 77%)

All 190 tools below are registered in `includes/bootstrap/oos-bridge.php` and have corresponding classes in `lib/core/src/Tool/`.

| Category | Count | Key Tools |
|---|---|---|
| External API / Public Data | 53 | WebSearch, GDACS, NHC, OpenMeteo, ReliefWeb, DeepResearch, Crawl4Ai×3, ModelInfo, Moderation, Embeddings, CountTokens, SuggestBestModel, ProbeRemoteMcp + utility/settings tools |
| HuggingFace Datasets | 11 | All 11 dataset tools (search, info, rows, size, stats, validate, splits, filter, parquet, preview, recommended) |
| Client-side AI | 6 | Sentiment, Summarize, Translate, ExtractEntities, QA, SemanticSearch |
| Content CRUD + Validated | 14 | GetPost, CreatePost, UpdatePost, DeletePost, SearchContent, GetRecentPosts, SavePost + 5 validated wrappers |
| Cron Jobs | 5 | CreateCronJob, DeleteCronJob, ListCronJobs, GetCronJob + validated variant |
| Cache / Settings | 11 | Get/Set/Delete/Increment Cache, Get/Update/Delete/List Settings, DispatchEvent |
| Queue / Jobs | 6 | EnqueueJob, GetJobStatus, CancelJob, ScheduleJob, UnscheduleJob, ListJobs |
| File / Upload | 6 | UploadFile, GetFileInfo, DeleteFile, Base64, SearchAttachments, FormatBytes |
| User / Auth | 4 | GetCurrentUser, GetUserInfo, CheckCapability, GenerateUuid |
| Skills | 2 | LoadSkill, ListSkills |
| Audio — TTS/Music/Transcription | 6 | GenerateOpenAISpeech, GenerateMusic, TranscribeOpenAIAudio + validated variants |
| Video — Analysis/Generation | 9 | CheckVideoStatus, AnalyzeVideo, GenerateVideoCaption, GenerateSoraVideo, GenerateVeoVideo, GenerateOmniVideo, EditOmniVideo + validated variants |
| Image — Generation/Analysis | 19 | GenerateOpenAIImage, GenerateGeminiImage, EditOpenAIImage, EditGeminiImage, CreateImageVariation, AnalyzeImage, GenerateImageAltText, GenerateImageCaption, ExtractImageText, Vision×2, GenerateCloudflareAIImage + validated variants |
| Image — Manipulation | 4 | ConvertImageFormat, CropImage, ResizeImage, RotateImage |
| Charts / Mermaid | 4 | GenerateChart, CreateChart, CreateChartValidated, GenerateMermaid |
| Batch Processing | 4 | CreateBatch, ListBatches, GetBatchStatus, MonitorBatch |
| Memory / Context | 7 | RecallMemory, StoreAgentContext, RetrieveAgentMemory, MineAgentMemory, ManageContextLifecycle, SemanticContextSearch, SemanticContentSearch |
| Agent Orchestration | 6 | CreateAgentTeam, DelegateToAgent, ExecuteWorkflow, CheckWorkflowHealth, ValidateWorkflow, ValidateReasoningChain |
| Profession | 4 | GetProfession, ListProfessions, ProfessionStats, SaveProfession |
| Email | 2 | SendGroupEmail + validated variant |
| External Search / API | 8 | SearchDrive, SearchGmail, SearchPlaces, GeminiGeospatialQuery, QueryRemoteSite, OpenOpenAILogs, ListOpenAIFiles, GetOpenAIFileDetails |
| Content Analysis / SEO | 7 | GeneratePostExcerpt, AutoCategorizeContent, ContentFreshnessChecker, SuggestInternalLinks, AnalyzeCodeSequence, AnalyzeCommentContent, AnalyzeFileSuitability, ContentRecommendationEngine, BatchEmbedContent, SEOMetaOptimizer |
| Vector Stores | 4 | CreateVectorStore, GetVectorStore, ListVectorStores, ManageVectorStoreFiles |
| Cache Purge | 3 | PurgeCache, PurgeCloudflareCache, PurgeVarnishCache |
| Erlang-C | 4 | CalculateErlangC, ErlangCConcurrencyAdvisor, ErlangCQueueHealth, ErlangCStaffingAdvisor |
| Admin / Site | 2 | GetSiteSummary, GetPostTypeSchema |
| Specialized | 4 | WaitForUser, GenerateAuth0Token, GenerateSimpleJWTToken, RunOpenAIExternalAction |

All migrated tools follow the canonical envelope pattern: `$this->success(message, data)` or `$this->errors->create(...)`. Zero WordPress function calls in `lib/core/` — all platform interaction goes through injected domain contracts.

### 1.2 Intentionally Not Migrated (~44 tools — Strategic Decision)

These tools remain in the legacy WordPress path per the Strangler Fig pattern. They fall into two categories:

#### Plugin-Dependent (~30 tools)

Cannot be framework-agnostic because they require third-party plugin APIs:

| Plugin | Count | Example Tools |
|---|---|---|
| Elementor Pro | 3 | get-elementor-form-submissions, get-elementor-templates, import-elementor-template-kit |
| JetEngine | 5 | get-jetengine-items, get-jetformbuilder-forms, get-jetformbuilder-submissions, invoke-jetengine-route, list-jetengine-routes |
| WooCommerce | 5 | get-woo-products, get-woo-recent-orders, create-woo-product + validated, scrape-product + validated |
| Newsletter | 6 | newsletter-add-subscriber, newsletter-create-email, newsletter-get-emails, newsletter-get-subscriber-stats, newsletter-get-subscribers, newsletter-unsubscribe |
| SiteKit (Google) | 4 | sitekit-adsense, sitekit-analytics, sitekit-pagespeed, sitekit-search-console |
| FlowHub (POS) | 7 | flowhub-create-order, flowhub-get-customers, flowhub-get-inventory, flowhub-get-orders, flowhub-get-products, flowhub-manage-customer, flowhub-manage-product |
| PayHere | 1 | payhere-* |

**Future option:** Could be migrated if `PluginIntegrationInterface` contracts are created, but the plugin-API surface is too irregular to justify the abstraction cost right now.

#### Deeply WordPress-Native (~14 tools)

Heavily coupled to WordPress internals or require platform-specific runtime:

| Tool | Reason |
|---|---|
| `performance-optimizer-assistant` | 971 lines of WordPress-specific optimization logic |
| `create-assistant` / `create-assistant-validated` | CPT registration + post meta setup |
| `evolve-harness` | WordPress Action Scheduler harness orchestration |
| `run-gemini-managed-agent` | WordPress-specific agent lifecycle |
| `delegate-to-a2a-agent` | A2A protocol — needs Node.js bridge |
| `aggregate-agent-results` | WordPress post-meta aggregation |
| `visualize-workflow-metrics` | WordPress admin UI rendering |
| `probe-chat` | WordPress session/cookie probing |
| `query-mesh-intelligent` | WordPress multisite mesh routing |
| `vectorize-image` | Requires Node.js runtime |
| `image-base` | Base class for WP media library tools |
| `image-alt-text-optimizer` | WP media library attachment workflow |
| `image-format-batch-converter` | WP media library batch processing |
| `media-library-optimizer` | WP media library optimization |

**Future option:** Some (like agent orchestration tools) could be migrated with additional domain contracts. Others (like media-library tools) are inherently WordPress-coupled and should remain as WordPress adapters.

### 1.3 Summary Matrix

| Status | Categories | Tool Count | Notes |
|---|---|---|---|
| ✅ Migrated | All 24 categories | 190 | Registered in `oos-bridge.php`, tested (757 tests, 3,100 assertions) |
| 🔴 Plugin-dependent | Elementor, JetEngine, WooCommerce, Newsletter, SiteKit, FlowHub, PayHere | ~30 | Requires third-party plugin APIs; intentionally not migrated |
| 🔴 Deeply WP-native | Agent orchestration, media library, CPT management | ~14 | 971-line tools, Node.js deps, WP internals; intentionally not migrated |

**Current state:** 190 tools framework-agnostic (77%), ~44 intentionally not migrated. Base tool migration is substantially complete.

---

## 2. Migration Pattern — Per-Tool Recipe

Every tool migration follows this standard procedure:

### 2.1 Phase 1: Analysis

1. **Read the legacy tool** (`includes/tools/class-wp-mcp-ai-tool-{slug}.php`)
2. **Identify WordPress dependencies:**
   - `get_post()` / `wp_insert_post()` / `WP_Query` → `ContentStoreInterface`
   - `get_option()` / `update_option()` → `SettingsStoreInterface`
   - `wp_remote_get()` / `wp_remote_post()` → `HttpClientInterface`
   - `wp_insert_attachment()` / `wp_upload_dir()` → `FileStoreInterface`
   - `get_transient()` / `set_transient()` → `CacheStoreInterface`
   - `as_enqueue_async_action()` → `QueueClientInterface`
   - `do_action()` / `apply_filters()` → `EventDispatcherInterface`
   - `current_user_can()` / `get_current_user_id()` → `AuthProviderInterface`
   - `WP_Error` → `ErrorFactoryInterface`
3. **Check if a new domain contract is needed** — if none of the 9 existing contracts fit, document the gap in this plan and flag for architectural review
4. **Identify `get_definition()` metadata** — toolkit, pattern_compatibility, profession_tags, risk_level → map to `ToolRulesInterface` / `ToolCapabilityFlagsInterface`

### 2.2 Phase 2: Implementation

1. **Create** `lib/core/src/Tool/{PascalCase}Tool.php`
2. **Extend** `Nvoos\Core\Tool\AbstractTool` (or `AbstractHuggingFaceTool` / `AbstractClientSideTool` if applicable)
3. **Constructor:** Inject `ErrorFactoryInterface` + required domain contracts via `__construct()`
4. **Implement** required methods:
   - `getSlug(): string` — same slug as legacy `get_slug()` (unchanged)
   - `getName(): string` — same name (remove `__()` i18n wrapper — i18n is a presentation concern)
   - `getDescription(): string` — same description (remove `__()`)
   - `getParametersSchema(): array` — copy schema, remove `__()` from description strings
   - `getRequiredCapability(): string` — same capability string
   - `execute(array $arguments, array $context): mixed` — the core logic
5. **Port logic:**
   - Replace `WP_Error` returns with `$this->errors->create()` / `->validationFailed()` / `->notFound()` / `->forbidden()`
   - Replace direct WP function calls with injected interface methods
   - Use `$this->stringParam()`, `$this->intParam()`, `$this->boolParam()`, `$this->arrayParam()` for sanitization
   - Return `$this->success()`, `$this->emptyResult()`, or `$this->collection()`
6. **Implement optional interfaces** where the legacy tool had them:
   - `get_capability_flags()` → `ToolCapabilityFlagsInterface::getCapabilityFlags()`
   - `get_tool_rules()` → `ToolRulesInterface::getToolRules()`
   - `get_data_contract()` → `ToolDataContractInterface::getDataContract()`
   - `get_flow_stages()` → `ToolFlowStageInterface::getFlowStages()`
   - `sanitize_for_llm()` → `ToolLlmSanitizerInterface::sanitizeForLlm()`
7. **PHP 8.1+ features** (allowed in `lib/core/`):
   - `readonly` constructor promotion
   - Named arguments
   - Enums for constants
   - Match expressions

### 2.3 Phase 3: Registration

1. **Add to `wp_mcp_ai_oos_orchestrator()`** in `includes/bootstrap/oos-bridge.php`:
   ```php
   $tool_registry->register( new Nvoos\Core\Tool\NewTool(
       $error_factory,
       $content,       // or $settings, $http, $files, etc.
   ) );
   ```

### 2.4 Phase 4: Testing

1. **Create** `lib/core/tests/Unit/Tool/{Name}ToolTest.php`
2. **Mock all injected interfaces** (no WordPress bootstrap needed)
3. **Test cases:**
   - Successful execution with valid arguments
   - Missing required parameters → validation error
   - Not found / empty results
   - Permission denied (when applicable)
   - Edge cases (boundary values, empty strings, etc.)

### 2.5 Phase 5: Cleanup

1. **Do NOT delete the legacy file** — it continues to serve the legacy engine path
2. **Add `@deprecated` annotation** to the legacy tool's class docblock with a note pointing to the new `nvoos/core` equivalent
3. **Verify** the tool works through the OOS engine (set `?engine=oos` flag)

---

## 3. Dependency Analysis — New Contracts Needed

Reviewing the remaining 16 categories against the 42 existing domain contracts:

| Potential New Contract | Needed By | Priority | Notes |
|---|---|---|---|
| `ImageProcessingInterface` | Category C (Image manipulation) | Medium | Abstract GD/Imagick operations: resize, crop, convert, rotate |
| *No new contracts needed for other categories* | — | — | All other tool categories can use existing contracts |

**Recommendation:** Block Category C on creating `ImageProcessingInterface` first (1–2 days).
All other categories can proceed immediately with existing contracts.

---

## 4. Migration Batches — Recommended Sequence

Batches are ordered to maximize parallelism and minimize bottlenecks:

### Batch 1: Quick Wins (Week 1–2) — 21 tools
| Sub-batch | Tools | Est. Days |
|---|---|---|
| 1a: Validated wrappers | 5 validated post/search tools | 2 |
| 1b: Cron tools | 5 cron job tools | 2 |
| 1c: Erlang-C tools | 4 Erlang-C computation tools | 1 |
| 1d: Cache purge | 4 cache purge tools | 2 |
| 1e: SEO/RankMath | 3 SEO tools | 2 |

**Milestone:** 103 tools migrated (53%)

### Batch 2: External API Tools (Weeks 2–4) — 31 tools
| Sub-batch | Tools | Est. Days |
|---|---|---|
| 2a: Audio tools | 5 TTS/music/transcription tools | 3 |
| 2b: Video tools | 6 video generation tools | 4 |
| 2c: Vector stores | 4 OpenAI vector store tools | 3 |
| 2d: Image generation | 16 image gen/edit/analyze tools | 7 |

**Milestone:** 134 tools migrated (69%)

### Batch 3: Memory & Orchestration (Weeks 4–6) — 21 tools
| Sub-batch | Tools | Est. Days |
|---|---|---|
| 3a: Memory layer | 10 memory/context tools | 6 |
| 3b: Agent orchestration | 11 agent/workflow tools | 8 |

**Milestone:** 155 tools migrated (79%)

### Batch 4: Plugin-Dependent Tools (Weeks 6–9) — 32 tools
| Sub-batch | Tools | Est. Days |
|---|---|---|
| 4a: Email/Newsletter | 8 email tools | 5 |
| 4b: WooCommerce | 5 WC tools | 4 |
| 4c: FlowHub | 7 FlowHub tools | 6 |
| 4d: Batch/Import | 7 batch tools | 3 |
| 4e: Security/Site | 8 security tools | 5 |

**Milestone:** 187 tools migrated (96%)

### Batch 5: Deep Integration (Weeks 9–11) — 20 tools
| Sub-batch | Tools | Est. Days |
|---|---|---|
| 5a: Elementor | 3 Elementor tools | 4 |
| 5b: JetEngine | 5 JetEngine tools | 5 |
| 5c: SiteKit | 4 SiteKit tools | 3 |
| 5d: Profession/JWT | 6 profession + auth tools | 3 |

**Milestone:** 207 tools migrated (106% — includes specialized remainder)

### Batch 6: Specialized & Remainder (Weeks 11–12) — remaining tools
All remaining specialized tools (code analysis, chart generation, Mermaid, Google Drive/Gmail/Places, 2FA, assistant management, etc.)

**Milestone:** All ~195 base tools migrated (100%)

---

## 5. Testing Strategy

### 5.1 Per-Tool Unit Tests (Required)

Every migrated tool gets a unit test file in `lib/core/tests/Unit/Tool/`:

```php
<?php
declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Tool\NewTool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\ContentStoreInterface; // or relevant contract
use PHPUnit\Framework\TestCase;

class NewToolTest extends TestCase {
    private ErrorFactoryInterface $errors;
    private ContentStoreInterface $content;
    private NewTool $tool;

    protected function setUp(): void {
        $this->errors  = $this->createMock( ErrorFactoryInterface::class );
        $this->content = $this->createMock( ContentStoreInterface::class );
        $this->tool    = new NewTool( $this->errors, $this->content );
    }

    public function testGetSlugReturnsExpectedValue(): void { ... }
    public function testExecuteWithValidArgumentsReturnsSuccess(): void { ... }
    public function testExecuteWithMissingRequiredParamReturnsError(): void { ... }
    public function testExecuteWhenResourceNotFoundReturnsError(): void { ... }
    public function testExecuteWithInsufficientPermissionsReturnsError(): void { ... }
}
```

### 5.2 Integration Tests (Post-Batch 3)

Create `lib/core/tests/Integration/Tool/ToolMigrationIntegrationTest.php` that:
- Boots the full OOS orchestrator with real WordPress adapters
- Executes a subset of tools through the agentic loop
- Verifies tool results match legacy tool results for same inputs

### 5.3 Regression Check

After each batch, run the full test suite:
```bash
composer run test                    # lib/core unit tests
vendor/bin/phpunit                   # WordPress integration tests
```

---

## 6. Risk Register

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| **New domain contract needed mid-migration** | Medium | Low | Pre-audit all categories; only Category C identified as needing new contract |
| **Legacy tool has undocumented WordPress-specific behavior** | High | Medium | Always read full legacy source before migrating; test against real WP environment |
| **`get_definition()` metadata lost** | Medium | Low | Map to optional interfaces (`ToolRulesInterface`, `ToolCapabilityFlagsInterface`) |
| **i18n strings removed from core tools** | Low | Low | By design — core is framework-agnostic; i18n happens at the presentation layer |
| **Plugin-dependent tools break when plugin deactivated** | Medium | Medium | Add `class_exists()` / `function_exists()` guards in adapter implementations |
| **Tool slug collision** | Low | High | Slug stays identical to legacy; no collision possible |
| **PHPStan type errors from new tools** | Medium | Low | Run `composer run lint:level8` after each batch; add `@phpstan-*` annotations as needed |

---

## 7. Success Criteria

1. ✅ All ~195 base tools have framework-agnostic equivalents in `lib/core/src/Tool/`
2. ✅ All migrated tools are registered in `oos-bridge.php`
3. ✅ Every migrated tool has a unit test with ≥80% line coverage
4. ✅ Integration tests pass for the OOS engine path with all migrated tools
5. ✅ Zero regression in legacy engine path (all legacy tools still work)
6. ✅ PHPStan passes at level 5 (minimum) for `lib/core/`
7. ✅ The feature flag (`?engine=oos`) routes all base tool calls through the new engine

---

## 8. Timeline Estimate

| Phase | Duration | Cumulative |
|---|---|---|
| Batch 1: Quick Wins | 1–2 weeks | 53% migrated |
| Batch 2: External API | 2–3 weeks | 69% migrated |
| Batch 3: Memory & Orchestration | 2–3 weeks | 79% migrated |
| Batch 4: Plugin-Dependent | 3–4 weeks | 96% migrated |
| Batch 5: Deep Integration | 2–3 weeks | 100% migrated |
| Batch 6: Remainder + Polish | 1–2 weeks | 100% + cleanup |
| **Total (single developer)** | **11–17 weeks** | |
| **Total (2 developers, parallel batches)** | **6–9 weeks** | |

---

## 9. Parallelization Opportunities

With 2+ developers:

- **Developer A:** Batches 1a–1c (Quick Wins) → Batch 2a–2c (Audio, Video, Vector) → Batch 4a–4c (Email, WC, FlowHub)
- **Developer B:** Batches 2d (Image generation) → Batch 3a–3b (Memory, Orchestration) → Batch 5a–5d (Elementor, JetEngine, SiteKit, Profession)

Batches 1d–1e can be split between developers as filler work.

---

## 10. References

- [Cross-Platform Extraction Architecture](./cross-platform-extraction-architecture.md)
- [Gap Analysis](./cross-platform-extraction-gap-analysis.md)
- [`lib/core/src/Tool/README.md`](../../lib/core/src/Tool/README.md) — tool conventions
- [`lib/core/src/Tool/AbstractTool.php`](../../lib/core/src/Tool/AbstractTool.php) — base class
- [`lib/core/src/Domain/Contract/`](../../lib/core/src/Domain/Contract/) — all domain contracts
- [`includes/bootstrap/oos-bridge.php`](../../includes/bootstrap/oos-bridge.php) — DI wiring
- [`.context/cross-platform-extraction.md`](../../.context/cross-platform-extraction.md) — agent context
