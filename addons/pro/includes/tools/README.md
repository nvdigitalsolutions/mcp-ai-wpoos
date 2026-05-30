# Pro Tools

## Purpose

Houses every Pro tool implementation — domain-specific tool classes (healthcare, ECA, CRE/debt, architectural design, financial planning, calendar/booking, social media, image/video production, multilingual, document generation, site-creator, regulatory registration, etc.) that the LLM, REST `/tools` endpoint, WP-CLI `mcp-ai tool`, and chat surfaces can invoke once the Pro addon is loaded.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | `wp_mcp_ai_pro_register_tools()` in [`addons/pro/mcp-ai-wpoos-pro.php`](../../mcp-ai-wpoos-pro.php) (hooked to `wp_mcp_ai_register_pro_tools` / `plugins_loaded`); per-toolkit init files in [`addons/pro/includes/*-toolkit-init.php`](../) wire category-scoped registrations and gate them on the matching toolkit setting |
| **Optional dependencies** | WooCommerce, JetEngine, Elementor, Shopify, QuickBooks, Mailjet/Brevo/Mailgun, Telegram/Slack/Discord/Teams/WhatsApp/Messenger/Apple Messages, Google (Chat/Drive/Calendar/Analytics/Business), LinkedIn, Tiktok, GitHub, Tesseract OCR, Remotion, plus the third-party libraries pinned in [`addons/pro/composer.json`](../../composer.json) |

## Public Surface

The contract is the **tool slug** registered with `WP_MCP_AI_Tool_Registry` — callers resolve tools by slug, never by class name. The live registry is authoritative for the catalogue and count; this README does not enumerate the ~635 Pro tools.

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Tool_Registry::get_tools()` | [`includes/class-wp-mcp-ai-tool-registry.php`](../../../../includes/class-wp-mcp-ai-tool-registry.php) (Base) | All callers — REST, CLI, agentic loop, chat |
| `WP_MCP_AI_Pro_Tool_*` and `WP_MCP_AI_Tool_*` classes (one per file) | `class-wp-mcp-ai-{pro-}tool-*.php` | Registry only — never instantiated directly |
| `WP_MCP_AI_Pro_Tool_CPT` | `class-wp-mcp-ai-pro-tool-cpt.php` (mirrored under `../src/Tools/`) | Generic CRUD/search for Pro toolkit CPTs |
| `Trait WP_MCP_AI_Tool_Research_Template_Analysis` | `trait-wp-mcp-ai-tool-research-template-analysis.php` | Research-* tools in this folder |
| `Trait WP_MCP_AI_Shopify_Connection_Resolver`, `WP_MCP_AI_Shopify_Smart_Search` | `../src/Tools/trait-wp-mcp-ai-shopify-*.php` | Shopify tools (lazy-required) |

Tool **categories** (each lives in its own subdirectory with its own topic-specific README):

| Subdir | Theme |
|---|---|
| `ai-tool-builder/` | LLM-assisted tool generation, install, manifest verification |
| `analytics/` | Multi-source analytics ingestion + reporting |
| `architect-agent/` | Architect-agent planning and execution wrappers |
| `architectural-design/` | AEC project, drawing, precedent, specification tools |
| `automotive/` | Vehicle estimation, VIN decoding, repair quotes |
| `calendar-booking/` | Bookable resources, slots, holds, ICS export |
| `capture/` | Webpage screenshots, frame extraction, OCR feeders, web browser |
| `cloudways/` | Cloudways server/application management |
| `comic-creation/` | Comic character, script, panel creation tools |
| `cre-debt/` | Commercial real-estate debt modelling |
| `crm/` | Upwork / generic CRM bridges |
| `developer/` | Code formatting, CLI checks, geospatial analysis, REST testing |
| `dj-management/` | Jukebox status, music generation, set planning |
| `document-generation/` | PDF/DOCX/XLSX rendering via PhpOffice + Dompdf + TCPDF |
| `eca-management/` | ECAs, students, attendance, iSAMS/SOCS sync |
| `ecommerce/` | Woo/Shopify product / order / customer / coupon tooling |
| `erp-ezuite/` | EZUite ERP product and data retrieval |
| `extended-cognition/` | Sensor session orchestration for the Ext Cog harness |
| `financial-planning/` | Accounts, projections, market-analysis tools |
| `healthcare/` | Members, allergies, prescriptions, vitals, FHIR export (HIPAA-sensitive) |
| `image-production/` | Sharp, background-removal, template application |
| `law-firm/` | Matters, regulatory registration, policies |
| `math/` | Calculus, equation solving, matrix ops, logic gates, graphing |
| `media/` | Media collections, templates, batch processing |
| `multilingual/` | Translation, locale routing |
| `orchestration/` | Pro schedules, task plans, templates, autonomous sessions |
| `paper-store/` | Markdown+YAML document store with Git sync |
| `places/` | Location/place CRUD with geospatial search |
| `project-management/` | Tasks, plans, dependencies, project CPT ops |
| `quiz-management/` | Quiz creation, grading, analytics, submissions |
| `regulatory-registration/` | ECA, ESG, compliance registries |
| `remote-connections/` | Remote WordPress/Shopify REST API connections |
| `research/` | Generic AI-assisted content research tools |
| `site-creator-toolkit/` | Plugin/theme install, options, full-site bootstrap |
| `social-media/` | Facebook / Instagram / LinkedIn / TikTok / Google Business publishing + insights |
| `vault/` | AES-256-GCM encrypted credential storage |
| `chat-channels/` | Slack, Discord, Teams, Messenger, Google Chat, Apple Messages, iCloud, Telegram, WhatsApp, Twitter, OneDrive, Outlook — ~50 messaging tools |
| `email-marketing/` | Brevo, Mailjet, Mailgun — send, contacts, analytics |
| `google-workspace/` | Gmail, Drive, Calendar, Analytics — search, create, report |
| `infrastructure/` | Cross-cutting Pro infrastructure (generic CPT CRUD) |
| `jetengine/` | JetEngine CCT, post-type, taxonomy, meta-field, relations, MCP bridge |
| `vector-storage/` | Pinecone, Chroma, pgvector adapters |
| `video-production/` | Remotion, transcoding, metadata extraction |
| `wp-all-import-export/` | WP All Import / WP All Export scheduled operations |

Authoritative catalogue: [`docs/reference/tools/tool-reference.md`](../../../../docs/reference/tools/tool-reference.md); live count via `WP_MCP_AI_Tool_Registry::get_tools()`.

## Inputs / Outputs / Neighbors

- **Reads from:** `$arguments` (LLM-provided, sanitised at entry); `$context` (user_id, assistant_id, request origin); Pro CPT/CCT meta in [`addons/pro/includes/data-stores/`](../data-stores/); credentials store; third-party HTTP clients in [`addons/pro/includes/services/`](../services/) and `addons/pro/includes/class-wp-mcp-ai-{shopify,upwork,erp-ezuite}-client.php`
- **Writes to:** Pro CPTs/CCTs (drawings, projects, ECAs, members, prescriptions, vitals, places, financial accounts, …); third-party APIs (Shopify, Brevo, Mailjet, QuickBooks, Google APIs, etc.); WordPress options/transients; the audit log for HIPAA-flagged tools
- **Upstream callers:** `WP_MCP_AI_Tool_Registry::execute_tool()` invoked by [`includes/rest/`](../../../../includes/rest/), [`includes/cli/`](../../../../includes/cli/), [`addons/pro/includes/cli/`](../cli/), the agentic loop, slash commands, per-toolkit MCP servers in [`addons/pro/includes/mcp-servers/`](../mcp-servers/), and the markup interceptor
- **Downstream collaborators:** [`addons/pro/includes/services/`](../services/), [`addons/pro/includes/data-stores/`](../data-stores/), [`addons/pro/includes/providers/`](../providers/), [`addons/pro/includes/vault/`](../vault/), [`addons/pro/includes/harness/`](../harness/), Base [`includes/validators/`](../../../../includes/validators/), Base [`includes/interfaces/`](../../../../includes/interfaces/), Base [`includes/traits/`](../../../../includes/traits/)
- **Events fired:** the standard `wp_mcp_ai_tool_before_execute` / `…_after_execute` / `…_error` triplet, plus per-domain hooks (`wp_mcp_ai_eca_*`, `wp_mcp_ai_healthcare_*`, `wp_mcp_ai_pro_schedule_*`, …)
- **Events listened to:** `wp_mcp_ai_register_pro_tools`, `wp_mcp_ai_register_tools`, each toolkit's `wp_mcp_ai_register_{toolkit}_tools` filter

## Conventions

Folder-specific deltas (canonical rules in [`.context/tool-registry.md`](../../../../.context/tool-registry.md) and [`.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md)):

- Every tool implements the same `WP_MCP_AI_Tool_Interface` as Base — there is no separate Pro interface. Capability flags use `WP_MCP_AI_Tool_Capability_Flags_Interface`.
- Class-name prefix is **`WP_MCP_AI_Pro_Tool_*`** for new Pro-only tools; legacy entries use `WP_MCP_AI_Tool_*` when they pre-date the Pro split. File names match `class-wp-mcp-ai-{pro-}tool-{slug-with-hyphens}.php`.
- `execute()` returns the canonical success array or `WP_Error` — never `array( 'success' => false, … )`. PHPCS sniff `WPMCPAI.Tools.CanonicalReturnEnvelope` is enforced.
- Sanitise every `$arguments[…]` value at entry; escape on every output path. PHPCS sniff `WPMCPAI.Tools.SanitizeAtEntry` is enforced.
- Every tool **must** be gated by its toolkit setting in `wp_mcp_ai_pro_register_tools()` so Base-mode sites never see Pro tools.
- HIPAA / PHI-touching tools (under `healthcare/`) MUST write to the imaging/PHI audit log (`WP_MCP_AI_Imaging_Audit_Log`) and gate on `WP_MCP_AI_Imaging_Capabilities`.
- Integration tools that previously lived under [`../src/Tools/`](../src/) (Shopify, Woo, JetEngine, social media, ChatChannels, Google Workspace, email, etc.) have been consolidated into their respective domain folders above as part of the Unix-theory migration (Phase 4). The `src/Tools/` directory no longer exists.

## Tests

```bash
vendor/bin/phpunit addons/pro/tests/test-architectural-tools-phase-a.php
vendor/bin/phpunit addons/pro/tests/test-eca-tools-integration.php
vendor/bin/phpunit addons/pro/tests/test-healthcare-imaging-toolkit.php
vendor/bin/phpunit addons/pro/tests/test-media-toolkit-tools.php
vendor/bin/phpunit addons/pro/tests/test-financial-market-analysis-tools.php
vendor/bin/phpunit addons/pro/tests/test-quiz-tools.php
vendor/bin/phpunit addons/pro/tests/test-shipping-tools.php
vendor/bin/phpunit addons/pro/tests/test-vehicle-estimation-tools.php
vendor/bin/phpunit addons/pro/tests/test-shopify-connection-resolver.php
vendor/bin/phpunit addons/pro/tests/test-shopify-smart-search.php
vendor/bin/phpunit addons/pro/tests/tools/
```

Cross-cutting registry + envelope coverage lives in the root suite under [`tests/`](../../../../tests/) (e.g. `test-tool-registry.php`, `test-tool-envelope-trait.php`).

Implementation-status notes for in-progress tools live in [`IMPLEMENTATION_STATUS.md`](./IMPLEMENTATION_STATUS.md).

## Also Load

- [`.context/conventions.md`](../../../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../../../.context/security-checklist.md) — sanitiser/escaper rules (always)
- [`.context/tool-registry.md`](../../../../.context/tool-registry.md) — canonical return envelope, slug rules, capability gating
- [`.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md) — Base/Pro placement rules
- [`.context/testing.md`](../../../../.context/testing.md) — how to add a tool test
- [`CLAUDE.md`](../../../../CLAUDE.md) — two-gate sanitisation, PHP-compat
- [`docs/reference/tools/tool-reference.md`](../../../../docs/reference/tools/tool-reference.md) — authoritative tool catalogue

## See Also

- Per-category READMEs (topic-specific, not replaced by this top-level file): `addons/pro/includes/tools/<category>/README.md` — e.g. [`ai-tool-builder/`](./ai-tool-builder/), [`analytics/`](./analytics/), [`architect-agent/`](./architect-agent/), [`architectural-design/`](./architectural-design/), [`automotive/`](./automotive/), [`calendar-booking/`](./calendar-booking/), [`capture/`](./capture/), [`cloudways/`](./cloudways/), [`comic-creation/`](./comic-creation/), [`cre-debt/`](./cre-debt/), [`crm/`](./crm/), [`developer/`](./developer/), [`dj-management/`](./dj-management/), [`document-generation/`](./document-generation/), [`eca-management/`](./eca-management/), [`ecommerce/`](./ecommerce/), [`erp-ezuite/`](./erp-ezuite/), [`extended-cognition/`](./extended-cognition/), [`financial-planning/`](./financial-planning/), [`healthcare/`](./healthcare/), [`image-production/`](./image-production/), [`law-firm/`](./law-firm/), [`math/`](./math/), [`media/`](./media/), [`multilingual/`](./multilingual/), [`orchestration/`](./orchestration/), [`paper-store/`](./paper-store/), [`places/`](./places/), [`project-management/`](./project-management/), [`quiz-management/`](./quiz-management/), [`regulatory-registration/`](./regulatory-registration/), [`remote-connections/`](./remote-connections/), [`research/`](./research/), [`site-creator-toolkit/`](./site-creator-toolkit/), [`social-media/`](./social-media/), [`vault/`](./vault/), [`vector-storage/`](./vector-storage/), [`video-production/`](./video-production/), [`wp-all-import-export/`](./wp-all-import-export/), and the other sibling subdirectories listed above
- Base counterpart: [`includes/tools/`](../../../../includes/tools/) — same interface, ~195 tools
- Sibling surfaces: [`addons/pro/includes/rest/`](../rest/), [`addons/pro/includes/cli/`](../cli/), [`addons/pro/includes/slash-commands/`](../slash-commands/)
- Newer classmap-loaded sibling: [`addons/pro/includes/src/`](../src/) — integration-heavy Pro tools (Shopify, Woo, JetEngine, social, ChatChannels)
- Collaborators: [`addons/pro/includes/services/`](../services/), [`addons/pro/includes/data-stores/`](../data-stores/), [`addons/pro/includes/mcp-servers/`](../mcp-servers/)
