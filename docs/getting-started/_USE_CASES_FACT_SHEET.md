# USE_CASES_AND_QUICKSTARTS Rev 3.0 Fact Sheet

**Document revision supported:** 3.0  
**Refresh date:** September 8, 2026  
**Tested against plugin:** `1.1.72`  
**Purpose:** Permanent companion record for `docs/getting-started/USE_CASES_AND_QUICKSTARTS.md`. Future refreshes should update this file first, then update the public guide from these facts.

> Counts below are point-in-time sanity checks. The live tool registry (`WP_MCP_AI_Tool_Registry::get_tools()`) is authoritative for runtime availability because optional plugins, Pro addons, and site configuration can change the exposed tool list.

## 1. Version and release ground truth

| Measure | Value | Source |
|---|---:|---|
| `WP_MCP_AI_VERSION` | `1.1.72` | `includes/bootstrap/constants.php` |
| `WP_MCP_AI_PRO_VERSION` | `1.1.72` | `addons/pro/mcp-ai-wpoos-pro.php` |
| Plugin header `Version:` | `1.1.72` | `mcp-ai-wpoos.php` |
| Latest release date | September 7, 2026 | `CHANGELOG.md` |
| Readme stable tag | `1.1.72` | `readme.txt` |
| Model catalog version | `2026.09.05` | `includes/data/model-catalog.json` |
| Catalog provider keys | 16 (15 shipping providers; `google` key is empty) | Derived from catalog entries |
| Catalog model entries | 228 total / 215 active | Derived from catalog entries |

Sub-project versions (release-note ground truth, v1.1.72):

| Component | Version |
|---|---:|
| Pro addon | 1.1.72 |
| Media Worker | v3.2.0 |
| Docs Hub addon | 0.4.3 |
| Chat SPA addon | 0.7.0 |
| Checkout API addon | v0.1.0 |
| `nvoos-content-graph` | 1.0.4 |
| `nvoos-content-graph-ai` | 1.0.4 |
| `nvoos-content-graph-ai-platform` | 2.0.0 |

## 2. Reconciled tool and template counts

| Measure | Value | Source / methodology |
|---|---:|---|
| Reconciled base tools | ~303 | `CHANGELOG.md` v1.1.72 release note; live registry authoritative |
| Reconciled Pro tools | ~1,265 | `CHANGELOG.md` v1.1.72 release note; live registry authoritative |
| Reconciled total tools | ~1,568 | `CHANGELOG.md` v1.1.72 release note; live registry authoritative |
| Base tool class files | 273 | `find includes/tools -name "class-wp-mcp-ai-tool-*.php"` sanity check |
| Pro tool class files | 985 | `find addons/pro -name "class-wp-mcp-ai-tool-*.php"` sanity check |
| Profession knowledge documents | 190 | `find includes/knowledge-base/profession-documents -name "*.txt"` |
| GA SPA-manifested Pro toolkits | 10 | `addons/pro/config/spa-manifests/*.json` |
| Pro toolkit settings pages | 53 | `addons/pro/includes/admin/class-wp-mcp-ai-*-settings-page.php` |
| Toolkit MCP servers | 33 | v1.1.40 Phase 8 release note (4 new: Pro Scheduler, FlowHub, Shopify Sync, EZuite) |
| Pro addon toolkits | 31 | `docs/project/ADDON_INVENTORY.md` (Pro row) |
| Bundled skills | 74 base + 41 Pro | v1.1.72 release note |
| Coding-time agent skills | 55 | `.agents/skills/` directory count |
| Addons in monorepo | 27 | `addons/*/` directory count |

### Count interpretation

- Use `~303 base`, `~1,265 Pro`, and `~1,568 total` in prose.
- Do not use old values such as `~195`, `~635`, `~830`, `207`, `127`, `70+`, `175+`, `182`, `193`, or `13 toolkits` in the Rev 3.0 guide.
- File counts are sanity checks only. File counts can be higher than runtime registry counts because some classes are abstract, optional-integration gated, helper-oriented, or available only when Pro/third-party dependencies are active.
- Profession templates should be described as `~190 pre-built profession templates` or `~190 professions across 12 categories`.
- Pro tool counts include the standalone Content Graph ecosystem ports; runtime availability depends on the installed addon package, dependencies, and configured credentials.

## 3. Professional template categories

The Rev 3.0 guide keeps the existing 12-category user-facing grouping with the `~190 professions across 12 categories` heading and methodology note.

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

### Team-size verification (carried over from Rev 2.0)

| Team pattern | Verified member count | Source |
|---|---:|---|
| IGCSE teams | 2–5 members across 6 IGCSE team presets (canonical `igcse_academic_support_team` = 5) | `includes/knowledge-base/teams/education-extended-teams.json`, `includes/knowledge-base/teams/education-training-teams.json` |
| Engineering Team | 4 | `includes/knowledge-base/teams/tech-marketing-teams.json` |
| Pharmaceutical Development Team | 4 | `includes/knowledge-base/teams/tech-marketing-teams.json` |
| Research & Data Science Team | 5 | `includes/knowledge-base/teams/tech-marketing-teams.json` |
| Marketing & Growth Team | 4 | `includes/knowledge-base/teams/tech-marketing-teams.json` |

## 4. GA Pro toolkit inventory

### SPA-manifested GA toolkits (unchanged from Rev 2.0)

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

The settings-page inventory contains 53 files total. The Rev 3.0 guide should avoid presenting all 53 as GA SPA toolkits; instead it should say there are 10 GA SPA-manifested toolkits plus additional settings-page modules and verticals (31 toolkits in the Pro addon per `ADDON_INVENTORY.md`) whose runtime availability depends on installed Pro features.

New verticals shipped since Rev 2.0 include: Google Workspace (Calendar/Gmail/Drive), Vision Analysis, Composio Connect, FlowHub / Shopify Sync / EZuite inventory sync, self-hosted OCR, Page Agent, DietPi, and the Content Graph ecosystem ports.

## 5. Roadmap / in-development slots

| Item | Treatment in Rev 3.0 guide | Source |
|---|---|---|
| AI Tool Builder | Keep in `Roadmap & Upcoming Toolkits` with a 🚧 in-development banner | Still "Coming Soon - Phase 2.9" in `addons/pro/includes/admin/class-wp-mcp-ai-ai-tool-builder-settings-page.php` (verified 2026-09-08) |
| Architectural verticals | Mention as reserved / specialised Pro verticals, not counted as GA SPA manifest toolkits | Settings-page inventory |
| Chat Channels | Mention as reserved / specialised Pro vertical, not a main Chat SPA use case | Settings-page inventory |
| DJ Management | Mention as reserved / specialised Pro vertical | Settings-page inventory |
| NV Cloud | Mention as reserved / specialised Pro vertical | Settings-page inventory |
| SaaS Controller | Cross-link only to `docs/operations/deployment/saas-controller.md` | Locked decision carried over |

## 6. Changelog features to reflect in `What's New`

Rev 3.0 should summarize changes from May 2026 through September 2026 (`CHANGELOG.md` entries `1.1.20` through `1.1.72`), themed by capability:

- **September 2026 model catalog** (`2026.09.05`, 228 models): gpt-5.6 family, `gpt-6-astra`, `gpt-image-2`, `claude-opus-5`, gemini-3.6/3.7/3.8-flash, `kimi-k3`; retired DeepSeek `chat/reasoner/coder` (→ `deepseek-v4-pro`), `gemini-3.1-flash`, `imagen-4`; new defaults `gemini-3.6-flash` / `gpt-image-2` / `kimi-k3`.
- **Content Graph ecosystem** — standalone plugins `nvoos-content-graph` 1.0.4, `-ai` 1.0.4, `-ai-platform` 2.0.0, `-pro`; chat runtime, admin UI, queue stack, MCP toolkit-server core ported out of the base plugin.
- **Google Workspace tools** — shared `includes/google/` foundation, Calendar connection + 7 tools, Gmail/Drive read tools (destructive-ops gated).
- **Vision Analysis toolkit** — `analyze_image_objects` (OWLv2/Ollama detection + VLM mode, annotate), off by default, SSRF-guarded.
- **Workflow Builder + Pro Schedule Manager** — visual DAG builder (9 preset categories, 10 node types), 7 scheduler MCP tools, scheduled-result widgets.
- **Pro SPA v2** — `[nvoos_pro_spa]` front-end chat surface (threads, drawers, tool shortcuts, OKF drawer, guest mode).
- **Chat SPA 0.7.0** — Phase 8 message actions & content enrichment.
- **Docs Hub 0.4.3** — local-link hash routing, github-slugger anchors, link-fixer engine, wp.org submission pass.
- **Security & operations** — fixed-window REST rate limiter with Command Center Lift, session-nonce self-heal, destructive-ops gate, security posture with 21 signals, log redaction of credential-bearing params.
- **Provider & platform behavior** — fresh installs disable cloud providers by default; OpenAI reasoning-model parameter stripping; circuit breakers on all provider clients.
- **Toolchain** — Tool Presets system, OKF engine v0.2 + 6 MCP tools, self-hosted OCR, Abilities API, Backup & Restore (11 export providers), GitHub-based Plugin Updater, MCP protocol negotiation + OAuth 2.0 (PKCE) MCP auth, JSON-RPC HTTP-200 compat, 33 toolkit MCP servers.
- **Media Worker v3.2.0** — multi-tenant worker with per-site tokens/keys, optional Crawl4AI full-proxy, worker-routed packages.

## 7. New or refreshed use cases required in Rev 3.0

| Section | Topic | Notes |
|---|---|---|
| §2.x | Google Workspace automation | Calendar connection (Settings → Integrations + Pro Remote Sites), `list_google_calendars`, `list_google_calendar_events`, `update_google_calendar_event`, `delete_google_calendar_event`, `check_google_calendar_availability`, `quick_add_google_calendar_event`, `create_google_calendar_event`, `sync_google_calendar`; Gmail (`get_gmail_message`, `get_gmail_thread`, `list_gmail_connections`, `modify_gmail_message`) and Drive (`get_drive_file`, `list_drive_connections`) read tools are destructive-ops gated. |
| §3.x | Vision Analysis | `analyze_image_objects` behind the NV oOS → Vision Analysis settings page (`enable_vision_analysis_toolkit`, off by default, SSRF-guarded); `annotate=true` returns a labeled bounding-box attachment. |
| §4.x | Workflow Builder + Pro Schedule Manager | Visual ReactFlow DAG builder (9 preset categories, 10 node types, Kahn's-algorithm execution, template variables); 7 scheduler MCP tools (create/update/delete/list/dry-run/history/plan-from-workflows); links `docs/toolkits/` + workflow docs. |
| §5.x | Deep Research | 9 Pro research tools with per-provider fallback routing, citations; research → Paper Store → WordPress draft pipeline (`create_post_from_research`). |
| §6.0 | Toolkit MCP Servers refresh | 33 servers; OAuth 2.0 MCP auth (PKCE, hierarchical scopes, browser-based login, token management UI); JSON-RPC errors return HTTP 200 (SDK compat); legacy HTTP+SSE transport; toolkit-server core ported to `nvoos-content-graph-pro`. |
| §6.x | Content Graph ecosystem | Four standalone plugins: `nvoos-content-graph` (visual knowledge graph), `-ai` (chat runtime + admin), `-ai-platform` (queues, HITL, workflows, tenant isolation), `-pro`; link `docs/project/ecosystem-port-tracker.md`. |
| §13.x | Pro SPA v2 | `[nvoos_pro_spa]` shortcode: chat-first embedded mode, threads, drawers, tool shortcuts, OKF drawer, guest mode. |
| §13 | Chat SPA refresh | v0.7.0, Phase 8 (message actions toolbar, native JSON/chart/video/image rendering, code-block copy buttons). |
| §14 | Docs Hub refresh | v0.4.3 (wp.org pass: no inline scripts, External Services readme section, plugin-check CI); 0.4.2 link-fixer engine. |

## 8. Cost model ground truth

All cost rows in Rev 3.0 cite `includes/data/model-catalog.json` version `2026.09.05`. Prices are catalog seed values and may be overridden from Settings → Models, filters, or provider billing changes.

| Provider | Total / active model count |
|---|---:|
| Anthropic | 14 / 10 |
| Azure | 1 / 1 |
| Baseten | 4 / 4 |
| Cloudflare Workers AI | 7 / 7 |
| DeepSeek | 3 / 3 |
| DigitalOcean Serverless Inference | 5 / 5 |
| Embedded MLC | 3 / 3 |
| Gemini (Google) | 17 / 15 |
| Hugging Face | 11 / 9 |
| Kimi / Moonshot | 9 / 9 |
| LM Studio | 20 / 20 |
| NVIDIA | 56 / 56 |
| Ollama | 33 / 29 |
| OpenAI | 32 / 31 |
| OpenRouter | 8 / 8 |
| WebLLM | 5 / 5 |

Required treatment: OpenAI `gpt-5.x`/`gpt-5.6`/`gpt-4.1` families + `gpt-4o` pins; Anthropic Haiku 4.5 / Sonnet 4.6 / Opus 4.7 / Opus 5; DeepSeek v4 family only (`chat/reasoner/coder` retired 2026-07-24); Gemini 2.5 + 3.x lines merged under the `gemini` provider; DigitalOcean zero-price caveat; Kimi `kimi-k3` default + Moonshot rows; Cloudflare actual LLM rows only; local providers as API-price `$0`; image generation narrowed to `gemini-3.1-flash-image(-preview)` and `gpt-image-2` (remove retired `imagen-4` and deprecated `gemini-2.5-flash-image`).

Prices re-derived from the catalog on 2026-09-08 and differ from Rev 2.0 in several rows (e.g. `gpt-5-pro` 0.015/0.12, `gpt-4.1` 0.002/0.008, `gemini-3.1-pro` 0.002/0.012, `claude-sonnet-4-6` output 0.015).

## 9. Compliance and security edits

- Keep the no-percentage-claims stance.
- Posture links for Rev 3.0: `docs/operations/security/SECURITY_POSTURE.md`, `docs/operations/compliance/WORDPRESS_ORG_COMPLIANCE_2026_05_19.md`, `docs/operations/compliance/` directory.
- Keep the Unix Theory P0–P6 callout in Custom Tool Development; add the newer guarantees: tools can declare non-loggable result fields (`WP_MCP_AI_Tool_Sensitive_Result_Interface`), credential-bearing URL query params are redacted from logs, destructive-ops gate, fixed-window REST rate limiter with Command Center Lift.

## 10. Link audit notes

Use current repo paths (verified 2026-09-08):

- `docs/features/toolkit-mcp-servers.md` ✓
- `docs/features/memory/transcript-mining.md` ✓
- `docs/features/ai-providers/digitalocean.md` ✓
- `docs/developer/architecture/inline-async-tick-pattern.md` (was `docs/architecture/...`)
- `docs/operations/deployment/saas-controller.md` (was `docs/SAAS_SETUP_GUIDE.md`)
- `docs/operations/security/SECURITY_POSTURE.md`
- `docs/operations/compliance/WORDPRESS_ORG_COMPLIANCE_2026_05_19.md` (was `docs/WORDPRESS_ORG_COMPLIANCE_FINAL_STATUS.md`)
- `docs/project/ecosystem-port-tracker.md`
- `docs/composio-connect.md` ✓
- `docs/toolkits/vision-analysis-toolkit.md` ✓
- `docs/developer/architecture/integrations/google-calendar-connection.md` ✓

Removed files — do not link: `docs/ADR_002_toolkit_mcp_servers.md`, `docs/mcp-servers.md`, `docs/ORCHESTRATION_REFERENCE.md`, `docs/HIPAA_POSTURE.md`, `docs/03-wp-org-compliance.md`.

## 11. Troubleshooting entries required

- Keep `Background job stuck at queued, Progress: 0/1` with the updated link (`docs/developer/architecture/inline-async-tick-pattern.md`).
- Add `REST requests blocked by rate limiting` — blocked users appear in Command Center → Restrictions with a Lift button (fixed-window accounting; guest IP-keyed blocks expire on their own).
- Add `Chat SPA / Pro SPA "Cookie check failed" 403s` — the `/mcp-ai/v1/session/nonce` endpoint self-heals stale nonces under full-page caching or session-token rotation.

## 12. Refresh procedure for future revisions

1. Check recent work first: `git --no-pager log -n 20 --oneline --decorate`.
2. Confirm versions in `includes/bootstrap/constants.php`, `mcp-ai-wpoos.php`, `addons/pro/mcp-ai-wpoos-pro.php`, `readme.txt`, and `CHANGELOG.md`.
3. Recompute sanity counts for base tool classes, Pro tool classes, profession documents, SPA manifests, settings pages, toolkit MCP servers, and `.agents/skills/`.
4. Parse `includes/data/model-catalog.json` for `version`, provider keys, active model counts, and seeded prices.
5. Search the target guide for stale values: `~195`, `~635`, `~830`, `2026.05.04`, `1.1.19`, `v0.6.0`, `v0.3.9`, `207`, `127`, `70+`, `182`, `193`, `175+`, `13 Pro Toolkits`, `1.3.0`, and fabricated compliance percentages.
6. Update the public guide only after this fact sheet has been refreshed.
7. Run Markdown link checks if available and review changed headings against the generated TOC.
