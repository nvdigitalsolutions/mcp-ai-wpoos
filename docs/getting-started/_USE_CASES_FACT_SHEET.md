# USE_CASES_AND_QUICKSTARTS Rev 2.0 Fact Sheet

**Document revision supported:** 2.0  
**Refresh date:** May 17, 2026  
**Tested against plugin:** `1.1.19`  
**Purpose:** Permanent companion record for `docs/getting-started/USE_CASES_AND_QUICKSTARTS.md`. Future refreshes should update this file first, then update the public guide from these facts.

> Counts below are point-in-time sanity checks. The live tool registry (`WP_MCP_AI_Tool_Registry::get_tools()`) is authoritative for runtime availability because optional plugins, Pro addons, and site configuration can change the exposed tool list.

## 1. Version and release ground truth

| Measure | Value | Source |
|---|---:|---|
| `WP_MCP_AI_VERSION` | `1.1.19` | `includes/bootstrap/constants.php` |
| Plugin header `Version:` | `1.1.19` | `mcp-ai-wpoos.php` |
| Latest release date | May 18, 2026 | `CHANGELOG.md` |
| Readme stable tag | `1.1.19` | `readme.txt` |
| Model catalog version | `2026.05.04` | `includes/data/model-catalog.json` |
| Active model providers | 16 | Derived from active catalog entries |
| Active model entries | 186 | Derived from active catalog entries |

## 2. Reconciled tool and template counts

| Measure | Value | Source / methodology |
|---|---:|---|
| Reconciled base tools | ~195 | `readme.txt` 1.1.18 release note; live registry authoritative |
| Reconciled Pro tools | ~635 | `readme.txt` 1.1.18 release note; live registry authoritative |
| Reconciled total tools | ~830 | `readme.txt` 1.1.18 release note; live registry authoritative |
| Base tool class files | 226 | `find includes/tools -name "class-wp-mcp-ai-tool-*.php"` sanity check |
| Pro tool class files | 669 | `find addons/pro -name "class-wp-mcp-ai-tool-*.php"` sanity check |
| Profession knowledge documents | 190 | `find includes/knowledge-base/profession-documents -name "*.txt"` |
| GA SPA-manifested Pro toolkits | 10 | `addons/pro/config/spa-manifests/*.json` |
| Total Pro toolkit settings pages | 44 | `addons/pro/includes/admin/class-wp-mcp-ai-*-settings-page.php` |

### Count interpretation

- Use `~195 base`, `~635 Pro`, and `~830 total` in prose.
- Do not use old values such as `207`, `127`, `70+`, `175+`, `182`, `193`, or `13 toolkits` in the Rev 2.0 guide.
- File counts are sanity checks only. File counts can be higher than runtime registry counts because some classes are abstract, optional-integration gated, helper-oriented, or available only when Pro/third-party dependencies are active.
- Profession templates should be described as `~190 pre-built profession templates` or `~190 professions across 12 categories`.

## 3. Professional template categories

The Rev 2.0 guide keeps the existing 12-category user-facing grouping but updates the heading to say `~190 professions across 12 categories` and adds a methodology note.

| Category | User-facing examples to keep |
|---|---|
| Agriculture & Natural Resources | Agronomist, Environmental Scientist, Forester |
| Art, Media & Entertainment | Graphic Designer, Content Writer, Video Editor |
| Business & Finance | Accountant, Financial Advisor, Marketing Consultant |
| Education | Mathematics Tutor, Science Teacher, Academic Advisor |
| Healthcare & Medicine | Registered Nurse, Physician, Pharmacist |
| Law & Public Safety | Attorney, Paralegal, Mediator |
| Science & Engineering | Software Developer, Data Scientist, Chemical Engineer |
| Service Industry | Chef, Event Planner, Customer Service Representative |
| Technology | Web Developer, IT Support, Systems Administrator |
| Trades & Manual Labor | Electrician, Plumber, Carpenter |
| Transportation | Logistics Coordinator, Transportation Manager |
| Miscellaneous | Project Manager, Technical Writer, Translator |

### Team-size verification (completed)

| Team pattern | Verified member count | Source |
|---|---:|---|
| IGCSE teams | 2–5 members across 6 IGCSE team presets (canonical `igcse_academic_support_team` = 5) | `includes/knowledge-base/teams/education-extended-teams.json`, `includes/knowledge-base/teams/education-training-teams.json` |
| Engineering Team | 4 | `includes/knowledge-base/teams/tech-marketing-teams.json` |
| Pharmaceutical Development Team | 4 | `includes/knowledge-base/teams/tech-marketing-teams.json` |
| Research & Data Science Team | 5 | `includes/knowledge-base/teams/tech-marketing-teams.json` |
| Marketing & Growth Team | 4 | `includes/knowledge-base/teams/tech-marketing-teams.json` |

## 4. GA Pro toolkit inventory

### SPA-manifested GA toolkits

| Toolkit | Manifest |
|---|---|
| Analytics | `addons/pro/config/spa-manifests/analytics.json` |
| Calendar & Booking | `addons/pro/config/spa-manifests/calendar-booking.json` |
| CRE Debt | `addons/pro/config/spa-manifests/cre-debt.json` |
| CRM | `addons/pro/config/spa-manifests/crm.json` |
| E-commerce | `addons/pro/config/spa-manifests/ecommerce.json` |
| Financial Planner | `addons/pro/config/spa-manifests/financial-planner.json` |
| Law Firm | `addons/pro/config/spa-manifests/law-firm.json` |
| Multilingual | `addons/pro/config/spa-manifests/multilingual.json` |
| Regulatory Registrations | `addons/pro/config/spa-manifests/regulatory-registration.json` |
| Social Media | `addons/pro/config/spa-manifests/social-media.json` |

### Additional Pro settings-page toolkits / verticals

The settings-page inventory contains 44 files total. The Rev 2.0 guide should avoid presenting all 44 as GA SPA toolkits; instead it should say there are 10 GA SPA-manifested toolkits plus additional settings-page modules and verticals whose runtime availability depends on installed Pro features.

Additional settings pages observed: `architect-agent`, `architectural-design`, `architectural-drawing`, `architectural-project`, `architectural-specification`, `chat-channels`, `dj-management`, `document-generation`, `document-generation-cpt`, `eca`, `event`, `image-production`, `image-production-cpt`, `media`, `media-toolkit`, `member`, `nv-cloud`, `page`, `place`, `policy`, `post`, `pro-packages`, `pro-schedule-toolkit`, `product`, `project`, `project-management-toolkit`, `quiz`, `reg-product`, `registration`, `regulatory-product-cpt`, `regulatory-registration-toolkit`, `site-creator-toolkit`, `video-production`, plus CPT/settings variants.

## 5. Roadmap / in-development slots

| Item | Treatment in Rev 2.0 guide | Source |
|---|---|---|
| AI Tool Builder | Move out of main use cases and into `Roadmap & Upcoming Toolkits` with a 🚧 in-development banner | Phase 2.9 notice in `addons/pro/includes/admin/class-wp-mcp-ai-ai-tool-builder-settings-page.php` |
| Architectural verticals | Mention as reserved / specialised Pro verticals, not counted as GA SPA manifest toolkits | Settings-page inventory |
| Chat Channels | Mention as reserved / specialised Pro vertical, not a main Chat SPA use case | Settings-page inventory |
| DJ Management | Mention as reserved / specialised Pro vertical | Settings-page inventory |
| NV Cloud | Mention as reserved / specialised Pro vertical | Settings-page inventory |
| SaaS Controller | Do not add a full use case; cross-link only to `docs/SAAS_SETUP_GUIDE.md` | Locked decision |

## 6. Changelog features to reflect in `What's New`

Rev 2.0 should summarize changes from January 2026 through May 2026, especially `CHANGELOG.md` entries `1.1.9` through `1.1.18`: DigitalOcean Serverless Inference, Unix Theory P0–P6, Async Chat Continuation, Jobs / Tasks Drawer, Toolkit MCP Servers Phase 7 UI, inline-async-tick pattern across eight subsystems, Scheduled Result widgets, UI/UX Pro Max skill pack, WordPress.org compliance hardening, Chat SPA addon, Docs Hub addon, Toolkit SPA Blueprint Phases 5–12, and Orchestration Reference consolidation.

## 7. New or refreshed use cases required in Rev 2.0

| Section | Topic | Notes |
|---|---|---|
| §4.5 | Scheduled Result widgets | Six render modes: `summary-card`, `list`, `table`, `metric`, `timeline`, `raw`. REST family: `mcp-ai-pro/v1/schedules/*`. Tools: `get_schedule_latest_result`, `render_schedule_result`, `configure_schedule_widget_defaults`. |
| §6.0 | Toolkit MCP Servers | Per-toolkit credentials, scoped MCP endpoints, vendor integration rationale, ADR-002 / changelog basis. |
| §6.4 | Memory Mining from Transcripts | Inline-async-tick rationale, `DISABLE_WP_CRON` hosts, cooperative lock, self-healing REST endpoint, filters/actions. |
| §7.4 | Bundled Skill Packs | UI/UX Pro Max registry, data-file structure, framework guidelines, authoring notes. |
| §13 | Front-End Chat Delivery / Chat SPA | Use `[nvoos_chat_spa]`; mention `WP_MCP_AI_LEGACY_CHAT_JS` as legacy gate. Cover phases 1–7. |
| §14 | In-WP Documentation Viewer / Docs Hub | Remote repo picker, chunked rebuild + CLI, syntax highlighting, sitemap provider, SSRF hardening, a11y, edit-on-GitHub footer. |
| Appendix | Roadmap & Upcoming Toolkits | AI Tool Builder moved here; no GA implication. |

## 8. Cost model ground truth

All cost rows in Rev 2.0 should cite `includes/data/model-catalog.json` version `2026.05.04`. Prices are catalog seed values and may be overridden from Settings → Models, filters, or provider billing changes.

| Provider | Active model count |
|---|---:|
| Anthropic | 4 |
| Azure | 1 |
| Cloudflare Workers AI | 7 |
| DeepSeek | 3 |
| DigitalOcean Serverless Inference | 5 |
| Embedded MLC | 3 |
| Gemini | 4 |
| Google | 4 |
| Hugging Face | 7 |
| Kimi / Moonshot | 7 |
| LM Studio | 20 |
| NVIDIA | 56 |
| Ollama | 29 |
| OpenAI | 23 |
| OpenRouter | 8 |
| WebLLM | 5 |

Required treatment: OpenAI `gpt-5.x` and `gpt-4.1` families; Anthropic Claude Haiku 4.5 / Sonnet 4.6 / Opus 4.6 / Opus 4.7; DeepSeek canonical aliases; Google 2.5 separate from Gemini 3.1; DigitalOcean zero-price caveat; Kimi and Moonshot rows; Cloudflare actual LLM rows only; local providers as API-price `$0`; image-generation narrowed to `imagen-4` and `gemini-2.5-flash-image`; remove fabricated version-savings table.

## 9. Compliance and security edits

- Remove unbacked percentage claims such as `98% HIPAA`, `100% ISO`, and `100% SOC 2`.
- Replace with posture links: `docs/HIPAA_POSTURE.md`, `docs/03-wp-org-compliance.md`, and `docs/WORDPRESS_ORG_COMPLIANCE_FINAL_STATUS.md`.
- Add a Unix Theory P0–P6 callout in Custom Tool Development: `execute()` returns a canonical success array or `WP_Error`, never `array( 'success' => false, ... )`; sanitize at entry; escape at exit; name `WPMCPAI.Tools.CanonicalReturnEnvelope` and `WPMCPAI.Tools.SanitizeAtEntry`.

## 10. Link audit notes

Remove or avoid stale links to `../features/security/ISO_27001_COMPLIANCE.md`, `../features/security/SOC_2_COMPLIANCE.md`, `../features/security/HIPAA_COMPLIANCE.md`, `../guides/user/professionals/PROFESSION_KNOWLEDGE_BASE_SYSTEM.md`, and `../features/orchestration/MULTI_AGENT_ORCHESTRATION.md`.

Use current repo paths instead: `docs/ORCHESTRATION_REFERENCE.md`, `docs/architecture/inline-async-tick-pattern.md`, `docs/SAAS_SETUP_GUIDE.md`, `docs/HIPAA_POSTURE.md`, `docs/03-wp-org-compliance.md`, and `docs/WORDPRESS_ORG_COMPLIANCE_FINAL_STATUS.md`.

## 11. Troubleshooting entry required

Add `Background job stuck at queued, Progress: 0/1` with the common diagnosis (`DISABLE_WP_CRON` enabled or WP-Cron loopbacks blocked), mitigation (inline-async-tick self-healing; poll the job route once; verify logs), and reference `docs/architecture/inline-async-tick-pattern.md`.

## 12. Refresh procedure for future revisions

1. Check recent work first: `git --no-pager log -n 20 --oneline --decorate`.
2. Confirm versions in `includes/bootstrap/constants.php`, `mcp-ai-wpoos.php`, `readme.txt`, and `CHANGELOG.md`.
3. Recompute sanity counts for base tool classes, Pro tool classes, profession documents, SPA manifests, and settings pages.
4. Parse `includes/data/model-catalog.json` for `version`, active providers, active model counts, and seeded prices.
5. Search the target guide for stale values: `207`, `127`, `70+`, `182`, `193`, `175+`, `13 Pro Toolkits`, `1.3.0`, and fabricated compliance percentages.
6. Update the public guide only after this fact sheet has been refreshed.
7. Run Markdown link checks if available and review changed headings against the generated TOC.
