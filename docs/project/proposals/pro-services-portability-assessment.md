# Pro Services — Portability Assessment for `lib/core` Migration

**Date:** 2026-07-24
**Status:** Assessment complete — 17 of ~106 Pro classes (16%) would benefit from `lib/core` contracts
**Related:** [`legacy-php-service-inventory-lib-core-gap.md`](./legacy-php-service-inventory-lib-core-gap.md), [`ai-orchestration-services-lib-core-migration-plan.md`](./ai-orchestration-services-lib-core-migration-plan.md)

---

## Executive Summary

A systematic review of the ~106 Pro addon classes (`addons/pro/includes/`) to determine which would benefit from `lib/core` domain contracts. Unlike the base services (85% portable), most Pro services are inherently WordPress-specific — CPT registrations, WP-Cron scheduling, Action Scheduler sync engines, and JetEngine CCT integrations.

**Finding:** Only 17 Pro services (16%) would benefit from contracts. The remaining 89 are either already platform adapters, deeply coupled to WordPress infrastructure, or CPT/CCT registrations that will never be portable.

---

## ✅ Portable (8 services — would benefit from `lib/core` contracts)

These contain pure logic or wrap external APIs with minimal WordPress coupling:

| Service | What It Does | WordPress Deps | LOC | Priority |
|---|---|---|---|---|
| `OcrService` | Image→text OCR via OpenAI/Gemini/Ollama/Tesseract | File path input only | ~500 | **High** |
| `LanguageDetectionService` | Text language detection; has PHP-native ISO 639-1 fallback map | None (pure text→language) | ~400 | **High** |
| `NodemailerService` | Email via Node.js nodemailer (SMTP, OAuth2, attachments) | `WP_MCP_AI_PRO_PATH` for node_modules; `wp_mail` fallback | ~300 | Medium |
| `ValidatorService` | Deep input validation rules (schema, business rules) | None (pure validation logic) | ~300 | Medium |
| `PrettierService` | Code formatting via Prettier CLI | File path + config | ~200 | Low |
| `JukeboxService` | Music generation via external API | Provider API keys from settings | ~300 | Low |
| `YfinanceService` | Financial data via Yahoo Finance API | HTTP client only | ~200 | Low |
| `HfVisionInferenceService` | HuggingFace vision model inference | HTTP client + API key | ~300 | Low |

### Proposed Contracts

```php
// lib/core/src/Domain/Contract/OcrServiceInterface.php
interface OcrServiceInterface {
    public function extractText(string $filePath, array $options = []): array;
    public function getAvailableProviders(): array;
}

// lib/core/src/Domain/Contract/LanguageDetectionInterface.php
interface LanguageDetectionInterface {
    public function detect(string $text): array; // {language: string, confidence: float}
    public function getLanguageName(string $isoCode): string;
}

// lib/core/src/Domain/Contract/EmailServiceInterface.php
interface EmailServiceInterface {
    public function send(array $message): array; // {to, subject, html, text, attachments}
    public function isAvailable(): bool;
}
```

---

## 🟡 Semi-Portable (9 services — core logic portable, persistence is WP-specific)

These have portable business logic but platform-specific storage/transport:

| Service | Portable Part | WP-Specific Part | LOC |
|---|---|---|---|
| `ResultDeliveryService` | Channel routing, envelope formatting, template modes | Channel implementations (Slack, Telegram, SMS, WhatsApp) + WP options for config | ~1,300 |
| `FluentFfmpegService` | FFmpeg command construction, media transcoding logic | File path resolution, WP media library integration | ~400 |
| `VideoFrameExtractorService` | Frame extraction + thumbnail generation logic | WP media library storage | ~300 |
| `VectorStoreAdapter` | Vector store abstraction interface (provider-agnostic) | WP-specific provider implementations | ~300 |
| `ContentTemplateEngine` | Template parsing, variable substitution, rendering | WP post meta for template storage | ~400 |
| `SkillCatalogueService` | Skill discovery, parsing, cataloguing | WP filesystem for SKILL.md loading from plugin dirs | ~300 |
| `CircuitBreaker` | Circuit breaker state machine (closed→open→half-open) | WP transients for state persistence | ~200 |
| `TeamBudgetManager` | Budget arithmetic, threshold enforcement | WP options for budget configuration | ~300 |
| `ContactImporterService` | CSV/JSON parsing, field mapping, validation | WP user/post creation (`wp_insert_user`, `wp_insert_post`) | ~300 |

### Approach for Semi-Portable

Create contracts for the **core logic only**, with platform adapters for persistence:

```php
// Example: CircuitBreakerInterface
interface CircuitBreakerInterface {
    public function isOpen(string $circuitId): bool;
    public function recordSuccess(string $circuitId): void;
    public function recordFailure(string $circuitId): void;
    // State persistence delegated to adapter
}
```

---

## 🔴 Not Portable (21 services — inherently WordPress-specific)

| Category | Services | Why Inherently WP |
|---|---|---|
| **Scheduling** | `ProScheduleManager`, `ProSchedulePresets`, `ProWorkflowPresets` | WP-Cron, Action Scheduler, `wp_mail`, WP options |
| **Asset/DOM** | `ProCdnLoader`, `ProSpaLoader`, `ProInlineAssistant`, `LangchainEnqueue` | WP enqueue system, DOM manipulation |
| **Toolkit** | `ProToolkitBlocks`, `ProToolkitIntegration`, `ProToolkitShortcodes` | WordPress block/shortcode API |
| **Federation** | `ProMeshPeerBidirectionalSync`, `ProRemoteSiteManager` | WordPress REST API, WP site management |
| **Hooks** | `ProWorkflowBridge`, `PmNotificationManager`, `ProChatContinuationNotifier`, `ProPrivacy` | WordPress action/filter hooks |
| **Data layer** | `ToolkitDataStoreFactory`, `ProCptMetaSchema`, `ExecutionHistoryCCT`, `MemoryRetention`, `WebhookContextManager`, `JetEngineMetaHelper` | JetEngine CCT, WP options, post meta |
| **Vendor-specific** | `NvCloudService`, `NvCloudBillingObserver` | NV Cloud proprietary API |
| **Other** | `ProCollaborativePresence`, `ProParallelModelDispatcher`, `ExecutionLogger` | WP user sessions, WP provider clients |

---

## ⚪ Already Adapters (8 external API clients)

These are platform adapters by nature — they wrap external APIs:

| Client | External API | Already Portable? |
|---|---|---|
| `CrmGmailClient` | Gmail API | Yes (HTTP client only) |
| `FlowHubClient` | FlowHub inventory API | Yes (HTTP client only) |
| `ShopifyClient` | Shopify Admin API | Yes (HTTP client only) |
| `LinkedInClient` | LinkedIn API | Yes (HTTP client only) |
| `UpworkClient` | Upwork API | Yes (HTTP client only) |
| `JetEngineMcpClient` | JetEngine MCP bridge | No (JetEngine-specific) |
| `FeaturedImageService` | AI image generation | Already covered by base `FileUploadServiceInterface` |
| `MjmlService` | MJML → HTML conversion | Used by Nodemailer pipeline |

---

## ⚪ Sync Engines (4 — inherently WordPress-specific)

| Engine | Depth of WordPress Coupling |
|---|---|
| `FlowHubSyncEngine` + `FlowHubCCTManager` + `FlowHubCli` + `FlowHubMigration` | Action Scheduler batch jobs, JetEngine CCT storage, WP-CLI commands |
| `ShopifySyncEngine` + `ShopifySyncCCTManager` + `ShopifySyncCli` + `ShopifySyncWebhookHandler` | Action Scheduler, JetEngine CCT, WP REST webhook endpoints |
| `EzUiteSyncEngine` + `EzUiteAlertManager` + `EzUiteCCTManager` + `EzUiteCli` + `EzUiteMigration` | JetEngine CCT, WP-CLI |
| `SyncLogManager` | WP options |

---

## ⚪ CPT/CCT Registrations (~40 classes)

All architectural (drawings, projects, specs, precedents), comic (characters, scripts, panels), CRM (contacts, activities, messages), ECA, events, financial accounts, healthcare/wellness, imaging studies, document templates, image templates, media templates/collections, places, projects, quizzes, regulatory registrations, sequences, support tickets, tasks/task-plans/task-templates, site templates, product brands, content format templates, CRE debt, DICOM metadata, autonomous sessions, execution history, channel contacts/messages, quizzes, vitals log — **all inherently WordPress post type / JetEngine CCT registrations**.

---

## Summary

| Category | Count | Portable? | Recommended Action |
|---|---|---|---|
| ✅ Portable | 8 | Yes | Phase 2 Pro contracts (8 new `lib/core` interfaces) |
| 🟡 Semi-portable | 9 | Partial | Contracts for core logic only; adapters for WP persistence |
| 🔴 Not portable | 21 | No | Leave as-is |
| ⚪ Already adapters | 8 | N/A | Already platform adapters |
| ⚪ Sync engines | 4 | No | Deeply coupled to WordPress + Action Scheduler |
| ⚪ CPT/CCT registrations | ~40 | No | WordPress by definition |
| **Total Pro classes** | **~106** | **17 (16%)** | |

### Recommendation

**Do NOT include Pro services in the current migration effort.** The 35 base contracts establish the pattern. Pro contracts should be a **Phase 2 initiative** after:

1. Base contract layer is production-proven (staging with `?engine=oos`)
2. Remaining ~113 base tools are migrated to `lib/core`
3. Pro-specific portability needs are validated (many Pro features may never need non-WP deployment)

When Phase 2 begins, start with the **8 portable services** (highest value, lowest risk), then the 9 semi-portable with narrow-scope contracts.
