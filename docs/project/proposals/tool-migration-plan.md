# Base Tool Migration Plan — Legacy → `nvoos/core`

> **Status:** Draft  
> **Date:** 2026-07-25  
> **Context:** Cross-Platform Extraction (Strangler Fig), Phase: Tool Migration  
> **Current:** 82 tools framework-agnostic (42%), ~113 base tools remaining  
> **Target:** 100% base tool coverage in `lib/core/src/Tool/`

## Executive Summary

Migrate the remaining ~113 legacy tools from `includes/tools/class-wp-mcp-ai-tool-*.php`
to framework-agnostic classes in `lib/core/src/Tool/*Tool.php`. Each migration replaces
direct WordPress function calls with domain-contract injections (`ContentStoreInterface`,
`SettingsStoreInterface`, `HttpClientInterface`, `FileStoreInterface`, etc.), swaps
`WP_Error` for `ErrorFactoryInterface`, and adopts the canonical success/failure envelope.

The Strangler Fig pattern stays intact throughout — legacy tools remain available in the
legacy engine path while migrated tools become available in both paths (via the bridge).

---

## 1. Gap Analysis — Tools by Category

### 1.1 Already Migrated (82 tools — 42%)

| Category | Count | Status |
|---|---|---|
| Content CRUD | 6 | ✅ Done (`GetPostTool`, `CreatePostTool`, `UpdatePostTool`, `DeletePostTool`, `SearchContentTool`, `GetRecentPostsTool`) |
| Content Metadata | 3 | ✅ Done (`GetPostMetaTool`, `GetPostTaxonomiesTool`, `CountPostsTool`) |
| Schema | 1 | ✅ Done (`GetPostTypeSchemaTool`) |
| External API / Public Data | 14 | ✅ Done (WebSearch, Geocode, GDACS, NHC, OpenMeteo, ReliefWeb, Crawl4Ai×3, DeepResearch, ProbeRemoteMcp) |
| HuggingFace Datasets | 11 | ✅ Done (all 11 dataset tools) |
| Client-side AI | 6 | ✅ Done (Sentiment, Summarize, Translate, Extract Entities, QA, Semantic Search) |
| Cache / Settings | 8 | ✅ Done (Get/Set/Delete/Increment Cache, Get/Update/Delete/List Settings) |
| Queue / Jobs | 6 | ✅ Done (Enqueue, GetStatus, Cancel, Schedule, Unschedule, List) |
| File / Upload | 5 | ✅ Done (Upload, GetInfo, Delete, Base64, SearchAttachments) |
| AI Provider / Models | 5 | ✅ Done (GetModelInfo, ListModels, ModerateContent, CreateTextEmbeddings, SuggestBestModel) |
| User / Auth | 3 | ✅ Done (GetCurrentUser, GetUserInfo, CheckCapability) |
| Skills | 2 | ✅ Done (LoadSkill, ListSkills) |
| Admin / Site | 1 | ✅ Done (GetSiteSummary) |
| Utility | 11 | ✅ Done (CountTokens, TruncateText, MathEval, ColorConvert, GenerateSlug, FormatBytes, StripHtml, GenerateUuid, HashString, ValidateJson, ParseCsv, ExtractDomain, FormatDate, TimeAgo, MergeArrays, DispatchEvent) |

### 1.2 Remaining to Migrate (~113 tools — 58%)

The remaining tools fall into 16 categories. Each category entry shows dependency
requirements and complexity drivers.

---

#### 🟢 Category A: Post Validated Wrappers (4 tools) — *Easy*

**Files:** `create-post-validated`, `save-post`, `save-post-validated`, `search-content-validated`, `get-recent-posts-validated`

**Description:** Thin wrappers around the already-migrated core content tools, adding extra
validation or sanitization layers. The underlying tool already exists in `lib/core/`.

**Dependencies:** `ContentStoreInterface`, `ErrorFactoryInterface`  
**Complexity:** Low — mostly parameter validation passthrough  
**Estimated effort:** 1–2 days (5 tools)

---

#### 🟡 Category B: Image Generation & Editing (16 tools) — *Medium*

**Files:** `generate-openai-image`, `generate-openai-image-validated`, `edit-openai-image`,  
`generate-gemini-image`, `generate-gemini-image-validated`, `edit-gemini-image`, `edit-gemini-image-validated`,  
`generate-cloudflareai-image`, `create-image-variation`, `generate-image-alt-text`, `generate-image-alt-text-validated`,  
`generate-image-caption`, `generate-image-caption-validated`, `analyze-image`, `extract-image-text`, `vectorize-image`

**Description:** AI-powered image tools calling OpenAI DALL·E, Gemini Vision, Cloudflare AI, etc.
All are external API tools that need no WordPress-specific data beyond API keys.

**Dependencies:** `SettingsStoreInterface` (API keys), `HttpClientInterface`, `FileStoreInterface` (save generated images), `ErrorFactoryInterface`  
**Complexity:** Medium — HTTP orchestration, base64 handling, file persistence  
**Potential new contract needed:** `VisionInferenceInterface` (already exists in `Domain/Contract/`) — check if usable for analyze/extract tools  
**Estimated effort:** 5–8 days (16 tools)

---

#### 🟡 Category C: Image Manipulation (7 tools) — *Medium*

**Files:** `convert-image-format`, `crop-image`, `resize-image`, `rotate-image`, `image-alt-text-optimizer`,  
`image-format-batch-converter`, `media-library-optimizer`, `responsive-image-validator`, `image-base` (base class)

**Description:** Server-side image processing (GD/Imagick). These are WordPress-media-library-aware
but the core operations (resize, crop, convert) are framework-agnostic.

**Dependencies:** `FileStoreInterface` (read/write attachments), `SettingsStoreInterface`  
**Potential new contract needed:** `ImageProcessingInterface` — abstract GD/Imagick operations  
**Complexity:** Medium — needs an image-processing abstraction layer  
**Estimated effort:** 4–6 days (8 tools)

---

#### 🟡 Category D: Video Tools (6 tools) — *Medium*

**Files:** `analyze-video`, `generate-omni-video`, `edit-omni-video`,  
`generate-sora-video`, `generate-sora-video-validated`, `generate-veo-video`, `generate-veo-video-validated`,  
`check-video-status`, `generate-video-caption`

**Description:** AI video generation via OpenAI Sora, Google Veo/Omni. External API calls only.

**Dependencies:** `SettingsStoreInterface`, `HttpClientInterface`, `ErrorFactoryInterface`  
**Complexity:** Medium — async job polling patterns  
**Estimated effort:** 3–5 days (6 tools)

---

#### 🟡 Category E: Audio Tools (5 tools) — *Medium*

**Files:** `generate-openai-speech`, `generate-openai-speech-validated`, `generate-music`, `generate-music-validated`,  
`transcribe-openai-audio`, `transcribe-openai-audio-validated`

**Description:** TTS, music generation, audio transcription. External API calls.

**Dependencies:** `SettingsStoreInterface`, `HttpClientInterface`, `FileStoreInterface`  
**Complexity:** Medium — binary audio handling  
**Estimated effort:** 3–4 days (5 tools)

---

#### 🔴 Category F: Email & Newsletter (8 tools) — *Hard*

**Files:** `newsletter-add-subscriber`, `newsletter-create-email`, `newsletter-get-emails`,  
`newsletter-get-subscriber-stats`, `newsletter-get-subscribers`, `newsletter-unsubscribe`,  
`send-group-email`, `send-group-email-validated`

**Description:** Email sending and newsletter-plugin integration. These tools touch WordPress
plugin APIs (Newsletter plugin) and `wp_mail()`.

**Dependencies:** `SettingsStoreInterface`, `HttpClientInterface`  
**Potential new contract needed:** `EmailServiceInterface` — already exists in `Domain/Contract/`  
**Complexity:** High — WordPress plugin dependency (Newsletter plugin), SMTP abstraction  
**Estimated effort:** 4–6 days (8 tools)

---

#### 🔴 Category G: FlowHub (7 tools) — *Hard*

**Files:** `flowhub-create-order`, `flowhub-get-customers`, `flowhub-get-inventory`,  
`flowhub-get-orders`, `flowhub-get-products`, `flowhub-manage-customer`, `flowhub-manage-product`

**Description:** WooCommerce dispensary POS integration. External REST API calls + WooCommerce
product/order mapping. These are Pro tools but listed in base; verify placement.

**Dependencies:** `HttpClientInterface`, `SettingsStoreInterface` (API keys)  
**Potential new contract needed:** `FinancialDataInterface` — may already exist  
**Complexity:** High — external API with complex data mapping  
**Estimated effort:** 5–7 days (7 tools)

---

#### 🟡 Category H: Memory & Context (8 tools) — *Medium*

**Files:** `recall-memory`, `mine-agent-memory`, `retrieve-agent-memory`, `memory-audit-trail`,  
`trace-memory-provenance`, `manage-context-lifecycle`, `prioritize-context`,  
`store-agent-context`, `wake-up-context`, `batch-manage-memory`,  
`semantic-context-search`, `semantic-content-search`

**Description:** Memory layer operations — CRUD on stored agent memories, context lifecycle
management, semantic search over stored embeddings.

**Dependencies:** `MemoryStoreInterface` (already exists), `SettingsStoreInterface`, `EmbeddingServiceInterface`  
**Complexity:** Medium — needs MemoryStore adapter completeness check  
**Estimated effort:** 5–7 days (10 tools)

---

#### 🟡 Category I: Agent & Orchestration (11 tools) — *Medium*

**Files:** `create-agent-team`, `delegate-to-agent`, `delegate-to-a2a-agent`,  
`run-gemini-managed-agent`, `aggregate-agent-results`, `evolve-harness`,  
`validate-reasoning-chain`, `execute-workflow`, `validate-workflow`, `check-workflow-health`,  
`visualize-workflow-metrics`, `probe-chat`, `query-mesh-intelligent`

**Description:** Multi-agent orchestration, workflow execution, agent delegation (A2A protocol).
These are the core of the agentic loop functionality.

**Dependencies:** `AgentOrchestrationInterface` (exists), `EventDispatcherInterface`, `SettingsStoreInterface`  
**Complexity:** Medium-High — complex orchestration logic, careful state management  
**Estimated effort:** 6–10 days (11 tools)

---

#### 🟢 Category J: Cron Jobs (5 tools) — *Easy*

**Files:** `create-cron-job`, `create-cron-job-validated`, `delete-cron-job`, `list-cron-jobs`, `get-cron-job`

**Description:** WP-Cron and Action Scheduler management. The QueueClient adapter already
wraps Action Scheduler; these tools add cron-specific operations.

**Dependencies:** `QueueClientInterface` (exists), `SettingsStoreInterface`  
**Potential new contract needed:** `CronStatusInterface` — already exists  
**Complexity:** Low — thin wrappers over existing adapter  
**Estimated effort:** 2–3 days (5 tools)

---

#### 🟢 Category K: Batch & Import/Export (7 tools) — *Easy–Medium*

**Files:** `create-batch`, `monitor-batch`, `get-batch-status`, `list-batches`,  
`trigger-all-export`, `trigger-all-import`, `list-all-export-templates`,  
`list-all-import-templates`, `get-all-import-status`, `get-all-form-submissions`

**Description:** OpenAI batch operations + WordPress import/export triggers.

**Dependencies:** `HttpClientInterface`, `SettingsStoreInterface`, `EventDispatcherInterface`  
**Complexity:** Low–Medium — mostly API passthrough  
**Estimated effort:** 3–4 days (7 tools)

---

#### 🔴 Category L: Elementor Integration (3 tools) — *Hard*

**Files:** `get-elementor-form-submissions`, `get-elementor-templates`, `import-elementor-template-kit`

**Description:** Elementor Pro data access. These are plugin-specific and need Elementor's
internal APIs. Must be guarded by plugin-active checks.

**Dependencies:** Elementor Pro plugin  
**Potential new contract needed:** None — these may remain as WordPress-specific tools  
**Complexity:** High — deep Elementor Pro internal API dependency  
**Migration strategy:** Consider keeping as WordPress-only adapters or creating a `PluginIntegrationInterface`  
**Estimated effort:** 3–5 days (3 tools)

---

#### 🔴 Category M: JetEngine Integration (5 tools) — *Hard*

**Files:** `get-jetengine-items`, `get-jetformbuilder-forms`, `get-jetformbuilder-submissions`,  
`invoke-jetengine-route`, `list-jetengine-routes`

**Description:** JetEngine/CCT data access. Plugin-specific with complex internal APIs.

**Dependencies:** JetEngine plugin  
**Complexity:** High — deep JetEngine internal API dependency  
**Estimated effort:** 4–6 days (5 tools)

---

#### 🟡 Category N: WooCommerce (5 tools) — *Medium*

**Files:** `get-woo-products`, `get-woo-recent-orders`, `create-woo-product`, `create-woo-product-validated`,  
`scrape-product`, `scrape-product-validated`

**Description:** WooCommerce data read/write. These are plugin-dependent but WooCommerce has
well-documented APIs and REST endpoints.

**Dependencies:** WooCommerce plugin, `HttpClientInterface` (could use WC REST API)  
**Complexity:** Medium — well-documented API surface  
**Estimated effort:** 4–5 days (5 tools)

---

#### 🟡 Category O: Security & Site Health (8 tools) — *Medium*

**Files:** `check-site-security`, `login-security-monitor`, `password-strength-analyzer`,  
`user-activity-auditor`, `get-site-health`, `get-system-logs`, `get-system-logs-validated`,  
`get-environment-status`, `get-update-status`

**Description:** Site diagnostics and security auditing. Most are read-only aggregators of
WordPress site-info APIs.

**Dependencies:** `SettingsStoreInterface`  
**Complexity:** Medium — aggregates many WordPress-specific data sources  
**Estimated effort:** 4–6 days (8 tools)

---

#### 🟡 Category P: SiteKit (4 tools) — *Medium*

**Files:** `sitekit-adsense`, `sitekit-analytics`, `sitekit-pagespeed`, `sitekit-search-console`

**Description:** Google Site Kit data. External API + WordPress plugin integration.

**Dependencies:** `HttpClientInterface`, `SettingsStoreInterface`  
**Complexity:** Medium — needs SiteKit API abstraction  
**Estimated effort:** 3–4 days (4 tools)

---

#### 🟢 Category Q: Cache Purge & Performance (4 tools) — *Easy*

**Files:** `purge-cache`, `purge-cloudflare-cache`, `purge-varnish-cache`, `performance-optimizer-assistant`

**Description:** Cache invalidation for various backends. External API calls (Cloudflare, Varnish).

**Dependencies:** `HttpClientInterface`, `SettingsStoreInterface`, `EventDispatcherInterface`  
**Complexity:** Low — HTTP calls + event dispatch  
**Estimated effort:** 2–3 days (4 tools)

---

#### 🟢 Category R: SEO & RankMath (3 tools) — *Easy–Medium*

**Files:** `get-rankmath-seo`, `seo-meta-optimizer`, `suggest-internal-links`

**Description:** SEO analysis and optimization. RankMath plugin integration.

**Dependencies:** `HttpClientInterface` (could use internal APIs), `ContentStoreInterface`  
**Complexity:** Low–Medium — read-heavy, optional plugin dependency  
**Estimated effort:** 2–3 days (3 tools)

---

#### 🟡 Category S: Profession & User (6 tools) — *Medium*

**Files:** `get-profession`, `list-professions`, `save-profession`, `profession-stats`,  
`get-user-info-validated`, `generate-auth0-token`, `generate-simple-jwt-token`

**Description:** Profession taxonomy CRUD + JWT token generation.

**Dependencies:** `ProfessionRepositoryInterface` (exists), `AuthProviderInterface`, `SettingsStoreInterface`  
**Complexity:** Medium — auth token generation needs careful security review  
**Estimated effort:** 3–4 days (6 tools)

---

#### 🟡 Category T: OpenAI Vector Stores (4 tools) — *Medium*

**Files:** `create-vector-store`, `get-vector-store`, `list-vector-stores`, `manage-vector-store-files`

**Description:** OpenAI Vector Store API operations for RAG.

**Dependencies:** `HttpClientInterface`, `SettingsStoreInterface`, `FileStoreInterface`  
**Complexity:** Medium — file upload + vector store management  
**Estimated effort:** 3–4 days (4 tools)

---

#### 🟡 Category U: Erlang-C & Specialized Math (4 tools) — *Easy*

**Files:** `calculate-erlang-c`, `erlang-c-concurrency-advisor`, `erlang-c-queue-health`, `erlang-c-staffing-advisor`

**Description:** Pure mathematical computation tools. No external dependencies.

**Dependencies:** `ErlangCInterface` (exists)  
**Complexity:** Low — pure computation  
**Estimated effort:** 1–2 days (4 tools)

---

#### 🟡 Category V: Remaining Specialized (10+ tools) — *Mixed*

**Files:** `analyze-code-sequence`, `analyze-comment-content`, `analyze-file-suitability`,  
`auto-categorize-content`, `batch-embed-content`, `content-freshness-checker`,  
`content-recommendation-engine`, `create-chart`, `create-chart-validated`,  
`generate-chart`, `generate-mermaid`, `gutenberg-block-pattern-generator`,  
`pro-excel`, `graphic-editor-plus`, `query-remote-site`,  
`search-drive`, `search-gmail`, `search-places`, `wait-for-user`,  
`web-search-validated`, `2fa-setup-assistant`, `create-assistant`,  
`create-assistant-validated`, `submit-document-prompt`,  
`gemini-geospatial-query`, `run-openai-external-action`

**Description:** Miscellaneous specialized tools. Some are AI-powered (code analysis, content
categorization), some are external API wrappers (Google Drive, Gmail, Places), some are
admin utilities (2FA, assistant creation).

**Dependencies:** Varied — most need `HttpClientInterface` + `SettingsStoreInterface`  
**Complexity:** Low–Medium (most are API passthrough)  
**Estimated effort:** 5–8 days (all remaining)

---

### 1.3 Summary Matrix

| Priority | Categories | Tool Count | Est. Days | Key Risk |
|---|---|---|---|---|
| 🔴 P0 — Unblocks others | A (Validated wrappers), J (Cron) | 10 | 3–5 | None — dependencies exist |
| 🟠 P1 — High-value, low-risk | U (Erlang-C), Q (Cache purge), R (SEO) | 11 | 5–8 | None |
| 🟡 P2 — External API tools | B (Image gen), D (Video), E (Audio), T (Vector stores) | 31 | 14–21 | Rate limiting, API key requirements |
| 🟡 P3 — Memory & Orchestration | H (Memory), I (Agent/Orch) | 21 | 11–17 | Complex state management |
| 🟢 P4 — Plugin-integration | F (Email), G (FlowHub), N (WooCommerce) | 20 | 13–18 | Plugin dependency breakage |
| 🔵 P5 — Deep plugin integration | L (Elementor), M (JetEngine), P (SiteKit) | 12 | 10–15 | Plugin internal API fragility |
| ⚪ P6 — Specialized remainder | K (Batch), O (Security), S (Profession), V (Remaining) | 30+ | 12–18 | Varied |

**Totals:** ~113 tools | ~58–102 person-days (2–4 person-months)

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
