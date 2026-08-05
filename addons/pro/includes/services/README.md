# Pro Services

## Purpose

Hosts Pro-only business-logic / orchestration classes — Node-package bridges (FFmpeg, MJML, Nodemailer, Prettier, validator, OCR), the Skill Catalogue, Vector Store Adapter, Team Budget Manager, NV oOS Cloud service + billing observer, YFinance market data, language detection, contact importer, Pro Workflow Bridge, and the Pro chat-continuation notifier.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | Per-toolkit init files in [`../`](../) and the Pro bootstrap in [`addons/pro/mcp-ai-wpoos-pro.php`](../../mcp-ai-wpoos-pro.php). Phase-6 services (Vector Store Adapter, Team Budget Manager) are wired by [`services-init-phase6.php`](./services-init-phase6.php); the Skill Catalogue is wired by [`../skills-manager-init.php`](../skills-manager-init.php); NV oOS Cloud services are wired by [`../nv-cloud-init.php`](../nv-cloud-init.php); other services are lazy-`require_once`'d by their consumers |
| **Optional dependencies** | per-service: `node_modules/` packages (fluent-ffmpeg, MJML, nodemailer, prettier, validator, csv-parse/stringify), Tesseract / Ghostscript / Imagick (OCR), OpenAI / pgvector / Qdrant (vector store), GitHub raw + Git Tree APIs (skill catalogue), JetEngine (team budget meta), Action Scheduler (continuation notifier), Yahoo Finance (yfinance), NV oOS Cloud SaaS (cloud service + billing) |

## Public Surface

Services are consumed by Pro tools, Pro REST controllers, Pro admin pages, and slash commands. Most expose either a singleton (`get_instance()`) or are instantiated on demand.

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Skill_Catalogue_Service` | `class-wp-mcp-ai-skill-catalogue-service.php` | Pro Skills Manager REST controller, skill-catalogue cron, admin UI |
| `WP_MCP_AI_Vector_Store_Adapter` | `class-wp-mcp-ai-vector-store-adapter.php` | Pro vector-storage tools, Pro REST, Phase 6 init |
| `WP_MCP_AI_Team_Budget_Manager` | `class-wp-mcp-ai-team-budget-manager.php` | Pro Dashboard, agentic loop budget gates, cost-tracking observer, daily reset cron |
| `WP_MCP_AI_Pro_Chat_Continuation_Notifier` | `class-wp-mcp-ai-pro-chat-continuation-notifier.php` | Base chat-continuation dispatcher (via filter), Pro admin settings |
| `WP_MCP_AI_Pro_Workflow_Bridge` | `class-wp-mcp-ai-pro-workflow-bridge.php` | Pro schedule manager, tool-plan workflow runner, Base chat continuation re-entry |
| `WP_MCP_AI_NV_Cloud_Service` (+ `_Billing_Observer`) | `class-wp-mcp-ai-nv-cloud-service.php`, `class-wp-mcp-ai-nv-cloud-billing-observer.php` | NV oOS Cloud REST controller, settings page, [`../providers/`](../providers/) clients |
| `WP_MCP_AI_Fluent_FFmpeg_Service`, `WP_MCP_AI_MJML_Service`, `WP_MCP_AI_Nodemailer_Service`, `WP_MCP_AI_Prettier_Service`, `WP_MCP_AI_Validator_Service` | Node-bridge services | tools in [`../tools/document-generation/`](../tools/document-generation/), [`../tools/video-production/`](../tools/video-production/), and the document/email/code pipelines |
| `WP_MCP_AI_OCR_Service` | `class-wp-mcp-ai-ocr-service.php` | OCR / PDF-text tools, healthcare imaging |
| `WP_MCP_AI_Jukebox_Service` | `class-wp-mcp-ai-jukebox-service.php` | DJ-management toolkit |
| `WP_MCP_AI_YFinance_Service` | `class-wp-mcp-ai-yfinance-service.php` | Financial Planner toolkit + settings page |
| `WP_MCP_AI_Language_Detection_Service` | `class-wp-mcp-ai-language-detection-service.php` | Multilingual toolkit |
| `WP_MCP_AI_Contact_Importer_Service` | `class-wp-mcp-ai-contact-importer-service.php` | CRM toolkit (CSV import) |
| `WP_MCP_AI_Video_Frame_Extractor_Service` | `class-wp-mcp-ai-video-frame-extractor-service.php` | Video-production toolkit, OCR feeders |
| `WP_MCP_AI_Structured_Extraction_Service` | `class-wp-mcp-ai-structured-extraction-service.php` | Pro OCR tools (`pro_unlimited_ocr`) — parses `<\|det\|>` markers, extracts tables and form fields from self-hosted OCR output |
| `services-init-phase6.php` | bootstrap | Pro plugin loader |

Other classes here are internal helpers; depend on the ones listed above.

## Inputs / Outputs / Neighbors

- **Reads from:** WordPress options + post/term meta, transients, the contracts in [`../interfaces/`](../interfaces/), Pro CPT/CCT data via [`../data-stores/`](../data-stores/), credentials from [`../vault/`](../vault/), `node_modules/` payloads bundled with the Pro addon, third-party HTTP endpoints (NV oOS Cloud gateway, Yahoo Finance, GitHub).
- **Writes to:** WordPress options + meta + transients, Action Scheduler / WP-Cron queues (daily budget reset, continuation kicks), the uploads directory (Node bridges, OCR temp), outbound HTTP (signed webhooks, NV oOS Cloud calls, Yahoo Finance), Pro audit logs.
- **Upstream callers:** [`../tools/`](../tools/) (most domains), [`../rest/`](../rest/) controllers, [`../admin/`](../admin/) settings/AJAX, [`../slash-commands/`](../slash-commands/), [`../cli/`](../cli/), [`../mcp-servers/`](../mcp-servers/).
- **Downstream collaborators:** [`../providers/`](../providers/) (NV oOS Cloud client, billing observer), [`../data-stores/`](../data-stores/), [`../interfaces/`](../interfaces/), [`../vault/`](../vault/), Base [`../../../../includes/services/`](../../../../includes/services/) (Pro Workflow Bridge composes with Base async + chat-continuation services).
- **Events fired:** `wp_mcp_ai_team_budget_*` (limit checks, daily reset), `wp_mcp_ai_pro_continuation_*`, `wp_mcp_ai_nv_cloud_cost_calculated`, `wp_mcp_ai_skill_catalogue_*`, per-service filters documented in each class.
- **Events listened to:** `wp_mcp_ai_team_budget_reset_daily` (cron), `wp_mcp_ai_pro_continuation_notify`, `wp_mcp_ai_cost_calculated` (billing observer), `init` (Phase 6 singletons at priority 25–26).

## Conventions

Folder-specific deltas (canonical rules in [`../../../../.context/conventions.md`](../../../../.context/conventions.md) and [`../../../../.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md)):

- One service = one cohesive responsibility, identical to Base's services rule. Extract collaborators before bolting on methods.
- Node-package bridges MUST expose `is_available()` that probes both `assets/vendor/<pkg>/` (production) and `node_modules/<pkg>/` (development) and return a `WP_Error` with a clear "package missing" message rather than fatalling when called without the package.
- Services that talk to a paid SaaS (NV oOS Cloud, Yahoo Finance, OpenAI vector stores) MUST funnel HTTP through Base's HTTP client / provider client adapters where one exists rather than calling `wp_remote_*` directly. New cloud-fronting clients belong in [`../providers/`](../providers/); the corresponding service lives here and holds connection state.
- Singleton services use `get_instance()` and register their `init` hook from the bootstrap (`services-init-phase6.php`, `nv-cloud-init.php`, `skills-manager-init.php`), not from their constructor.
- Cron-driven services (Team Budget reset, Skill Catalogue refresh, Continuation Notifier) must remain idempotent and respect Base's data-budget tracker.
- The Skill Catalogue service performs HTTPS-only, SSRF-guarded fetches with a response-size cap and routes all writes through `WP_MCP_AI_Skill_Registry::install_skill()`. Do not bypass.

## Tests

```bash
vendor/bin/phpunit addons/pro/tests/test-vector-store-adapter.php
vendor/bin/phpunit addons/pro/tests/test-team-budget-manager.php
vendor/bin/phpunit addons/pro/tests/test-pro-chat-continuation-notifier.php
vendor/bin/phpunit addons/pro/tests/test-pro-workflow-bridge.php
vendor/bin/phpunit addons/pro/tests/test-nv-cloud.php
vendor/bin/phpunit addons/pro/tests/test-ocr-service.php
vendor/bin/phpunit addons/pro/tests/test-video-frame-extractor.php
vendor/bin/phpunit tests/test-skill-catalogue.php
vendor/bin/phpunit tests/test-validator-service.php
vendor/bin/phpunit tests/test-markup-validator.php
```

Coverage spans both `addons/pro/tests/` and the root `tests/` suite — historic services land in the root suite, newer Phase-6+ services land in `addons/pro/tests/`. Add new tests next to the closest neighbour.

## Also Load

- [`../../../../.context/conventions.md`](../../../../.context/conventions.md) — naming + style (always)
- [`../../../../.context/security-checklist.md`](../../../../.context/security-checklist.md) — sanitiser/escaper rules for service inputs
- [`../../../../.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md) — Base/Pro placement rules
- [`../../../../.context/testing.md`](../../../../.context/testing.md) — service test patterns
- [`../../../../CLAUDE.md`](../../../../CLAUDE.md) — PHP-compat, canonical envelopes
- [`../../../../docs/project/architecture-decisions/ADR_001_module_boundaries.md`](../../../../docs/project/architecture-decisions/ADR_001_module_boundaries.md) — service ↔ adapter layering

## See Also

- Upstream parent: [`../`](../) (Pro `includes/`)
- Base counterpart: [`../../../../includes/services/`](../../../../includes/services/) — ~70 core services + layering rules
- Sibling folders: [`../providers/`](../providers/) (HTTP clients these services delegate to), [`../data-stores/`](../data-stores/), [`../interfaces/`](../interfaces/), [`../vault/`](../vault/), [`../harness/`](../harness/)
