# NV oOS Use Cases & Quickstart Guides

**Doc revision:** 2.0  
**Tested against plugin version:** 1.1.19 (May 18, 2026)  
**Last updated:** May 17, 2026  
**Estimated reading time:** 35 minutes

> Counts in this document are point-in-time. `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative for the tools exposed on a live site.

## 📑 Table of Contents

- [Overview](#overview)
- [What's New Since the Previous Doc Revision](#whats-new-since-the-previous-doc-revision-jan-2026--may-2026)
- [Professional & Team Templates](#professional--team-templates)
- [1. Content Creation & Management](#1-content-creation--management)
- [2. E-Commerce Automation](#2-e-commerce-automation)
- [3. Media Generation & Processing](#3-media-generation--processing)
- [4. Business Operations](#4-business-operations)
- [5. Research & Data Analysis](#5-research--data-analysis)
- [6. Developer & Technical Integration](#6-developer--technical-integration)
- [7. Education & Knowledge Management](#7-education--knowledge-management)
- [8. Multi-Agent Orchestration](#8-multi-agent-orchestration)
- [9. Advanced Workflow Automation](#9-advanced-workflow-automation)
- [10. Video Production & Transcoding](#10-video-production--transcoding)
- [11. Site Building Automation](#11-site-building-automation)
- [12. Regulatory Compliance](#12-regulatory-compliance)
- [13. Front-End Chat Delivery (Chat SPA)](#13-front-end-chat-delivery-chat-spa)
- [14. In-WP Documentation Viewer (Docs Hub)](#14-in-wp-documentation-viewer-docs-hub)
- [Pro Features & Toolkits](#pro-features--toolkits)
- [Cost Considerations](#cost-considerations)
- [Compliance Posture](#compliance-posture)
- [Troubleshooting](#troubleshooting)
- [Best Practices](#best-practices)
- [Next Steps](#next-steps)
- [Roadmap & Upcoming Toolkits](#roadmap--upcoming-toolkits)
- [Document History](#document-history)

---

## Overview

NV oOS (Open Operator System) is a WordPress AI assistant framework integrating OpenAI GPT models, Anthropic Claude, Google/Gemini, DeepSeek, Kimi/Moonshot, DigitalOcean Serverless Inference, Cloudflare Workers AI, Ollama, LM Studio, WebLLM, embedded MLC, and MCP-compatible tools.

The current reconciled inventory is **~830 tools (~195 base + ~635 Pro)**. The live registry exposed by `WP_MCP_AI_Tool_Registry::get_tools()` remains authoritative because optional plugins, Pro addons, provider configuration, and site capabilities can change runtime availability.

This guide focuses on practical use cases, setup paths, and operator checks. For inventory details behind this revision, see `docs/getting-started/_USE_CASES_FACT_SHEET.md`.

### What's New Since the Previous Doc Revision (Jan 2026 → May 2026)

- **DigitalOcean Serverless Inference provider** — OpenAI-compatible endpoint support, model discovery, provider diagnostics, native embeddings, and zeroed catalog pricing that operators must adjust from Gradient Platform billing.
- **Unix Theory P0–P6 tool hardening** — canonical return envelopes, sanitize-at-entry / escape-at-exit rules, and PHPCS sniffs for new and existing tools.
- **Async Chat Continuation and Jobs / Tasks Drawer** — longer-running interactions can continue without blocking the primary chat request.
- **Toolkit MCP Servers Phase 7 UI** — Pro toolkits can expose scoped MCP endpoints with per-toolkit credentials.
- **Inline-async-tick pattern** — shipped across eight background subsystems so first work ticks can run even when WP-Cron loopbacks are disabled or delayed.
- **Scheduled Result widget** — Pro scheduled results can be rendered as cards, lists, tables, metrics, timelines, or raw output through shortcode/block/widget surfaces.
- **UI/UX Pro Max skill pack** — bundled design-system knowledge, framework guidelines, component heuristics, and authoring patterns.
- **WordPress.org compliance hardening** — documentation and code paths aligned with current WordPress.org expectations.
- **Chat SPA addon** — modern front-end chat delivery with tool-call cards, transcripts, memory drawer, HITL approvals, file attachments, regenerate/edit flows, and a legacy-JS gate.
- **Docs Hub addon** — in-dashboard documentation viewer with remote repo rebuilds, chunked indexing, syntax highlighting, sitemap support, SSRF hardening, and accessibility improvements.
- **Toolkit SPA Blueprint Phases 5–12** and **Orchestration Reference consolidation** — Pro toolkit UI and orchestration docs were normalized for operators and implementers.

### Prerequisites

1. WordPress 6.0+ and PHP 7.4+ installed.
2. NV oOS plugin installed and activated.
3. At least one AI provider configured in Settings → NV oOS → Providers.
4. Optional Pro addon activated for Pro-only toolkits, Chat SPA, Docs Hub, scheduled widgets, and vertical toolkit features.
5. Administrative access for testing assistants, issuing credentials, and reviewing logs.

### Quick Reference

| Use Case | Time to Setup | Cost per Session | Difficulty | Templates / Surfaces |
|---|---:|---:|---|---|
| Content Writing | 3-5 min | $0.01-0.10 | Easy | Content Writer, Technical Writer |
| E-Commerce | 5-10 min | $0.05-0.20 | Medium | Marketing Consultant, E-commerce toolkit |
| Media Generation | 3-5 min | $0.02-0.50 | Easy | Graphic Designer, image/video tools |
| Business Operations | 8-15 min | $0.10-0.30 | Medium | Business Consultant, Project Manager |
| Scheduled Results | 10-15 min | Varies | Medium | Shortcode, Gutenberg block, Elementor widget |
| Research & Data | 3-5 min | $0.01-0.05 | Easy | Research Scientist, Data Scientist |
| Developer Integration | 20-30 min | Varies | Advanced | Software Developer, Systems Admin, MCP endpoints |
| Memory Mining | 10-20 min | Varies | Advanced | Transcript memory jobs |
| Education | 5-10 min | $0.05-0.15 | Medium | IGCSE and tutoring templates |
| Multi-Agent Teams | 10-20 min | $0.20-0.50 | Advanced | Research Team, Content Team |
| Video Production | 5-10 min | $0.10-0.30 | Medium | Video Editor, Pro media toolkits |
| Site Building | 15-25 min | $0.15-0.40 | Advanced | Web Developer, Site Creator toolkit |
| Front-End Chat SPA | 10-20 min | Varies | Medium | `[nvoos_chat_spa]` |
| Docs Hub | 10-20 min | Varies | Medium | Admin documentation viewer |

🔒 Pro-only items require the Pro addon and may also require provider/API credentials.

---

## Professional & Team Templates

NV oOS includes an enterprise-grade template system for rapid assistant deployment. Instead of manually configuring every assistant, you can deploy assistants from **~190 pre-built profession templates** spanning 12 user-facing categories.

### What Professional Templates Include

- Role descriptions and professional context.
- Curated default tool selections.
- Industry knowledge and guidance.
- Recommended AI provider/model/temperature defaults.
- Warnings, disclaimers, and operator notes for regulated or high-risk domains.

### Available Categories (~190 professions across 12 categories)

Methodology note: the current sanity check counts 190 profession knowledge documents. The runtime template list may vary with seeders, filters, or Pro features.

| Category | Examples |
|---|---|
| 🌾 Agriculture & Natural Resources | Agronomist, Environmental Scientist, Forester |
| 🎨 Art, Media & Entertainment | Graphic Designer, Content Writer, Video Editor |
| 💼 Business & Finance | Accountant, Financial Advisor, Marketing Consultant |
| 🎓 Education | Mathematics Tutor, Science Teacher, Academic Advisor |
| 🏥 Healthcare & Medicine | Registered Nurse, Physician, Pharmacist |
| ⚖️ Law & Public Safety | Attorney, Paralegal, Mediator |
| 🔬 Science & Engineering | Software Developer, Data Scientist, Chemical Engineer |
| 🍽️ Service Industry | Chef, Event Planner, Customer Service Representative |
| 💻 Technology | Web Developer, IT Support, Systems Administrator |
| 🔧 Trades & Manual Labor | Electrician, Plumber, Carpenter |
| 🚚 Transportation | Logistics Coordinator, Transportation Manager |
| 📋 Miscellaneous | Project Manager, Technical Writer, Translator |

### Quickstart: Create an Assistant from a Template (3 minutes)

1. Navigate to AI Assistants → Add New.
2. Browse the visual profession grid by category or search term.
3. Select a profession and click Create.
4. Confirm the assistant name, provider, model, and temperature.
5. Click Deploy Assistant.
6. Test in the admin before exposing the assistant publicly.

### Team Deployments

Pre-built team patterns include Engineering, Pharmaceutical Development, Research & Data Science, Marketing & Growth, and IGCSE teams. Team member counts should be verified from the live seeder when exact enumeration matters.

### Creating Custom Professions (15 minutes)

1. Navigate to Professions → Add New.
2. Add title, description, category, role description, and disclaimers.
3. Add profession-specific knowledge and references.
4. Browse the **~195 base tools** and any additional active Pro toolkit tools.
5. Select default tools, provider, model, and temperature.
6. Publish and test the custom profession in the admin.

---

## 1. Content Creation & Management

### Use Case: SEO Blog Drafting and Editorial Review

**Required Tools:** content generation, post creation, SEO/meta helpers, media tools, optional Rank Math integration.

**Quickstart Guide (5 minutes)**

1. Create or select a Content Writer or Technical Writer assistant.
2. Configure a low-to-medium temperature model for drafts and a stronger reasoning model for editorial review.
3. Ask for outline, target audience, search intent, and call-to-action before generating the draft.
4. Use post tools to create a draft rather than publishing directly.
5. Run a second pass for factual review, internal links, metadata, and accessibility.

**Operator checks:** keep editorial approval in the loop, require capabilities for publication, and treat model output as draft content.

---

## 2. E-Commerce Automation

### Use Case: Product Copy, Merchandising, and Support Triage

**Required Tools:** WooCommerce tools when WooCommerce is active, product/post tools, CRM or E-commerce Pro toolkit when available.

**Quickstart Guide (10 minutes)**

1. Enable WooCommerce and confirm NV oOS detects the integration.
2. Create a Marketing Consultant or E-commerce assistant.
3. Grant only the capabilities needed for draft product descriptions, categorization, or support summaries.
4. For Pro sites, enable the E-commerce SPA-manifested toolkit.
5. Test with one product, one support transcript, and one merchandising request before scaling.

**Best fit workflows:** product description drafts, variant summaries, FAQ generation, support triage, and merchandising reports.

---

## 3. Media Generation & Processing

### Use Case: Campaign Media Pipeline

**Required Tools:** image generation/editing providers, media library tools, video-generation tools, optional Pro media/video toolkits.

**Quickstart Guide (8 minutes)**

1. Configure a provider that supports the desired modality.
2. Create a Graphic Designer or Video Editor assistant.
3. Store prompt templates for brand voice, dimensions, accessibility, and alt text.
4. Generate media into the Media Library when possible.
5. Use human review before assigning assets to public posts or products.

**Cost note:** image and video tools may use provider-specific billing. See [Cost Considerations](#cost-considerations).

---

## 4. Business Operations

### Use Case: Operations Assistant

**Required Tools:** scheduling, post/page tools, CRM or calendar integrations, document-generation tools when active.

**Quickstart Guide (12 minutes)**

1. Create a Business Consultant or Project Manager assistant.
2. Define tasks the assistant can perform: summarize meetings, draft SOPs, create tasks, prepare reports.
3. Restrict write operations to drafts or admin-only contexts until reviewed.
4. Enable relevant Pro toolkits such as Calendar & Booking, CRM, Document Generation, or Project Management.
5. Create a recurring review cadence for generated operational artifacts.

### 4.5 Scheduled Result Widget + Gutenberg Block + Elementor Widget

**Required Tools:** `get_schedule_latest_result`, `render_schedule_result`, `configure_schedule_widget_defaults`; Pro scheduled-result routes under `mcp-ai-pro/v1/schedules/*`; shared renderer `WP_MCP_AI_Scheduled_Result_Renderer`.

The Scheduled Result feature turns the latest successful Pro Schedule output into a reusable dashboard tile. The same envelope and renderer power the Gutenberg dynamic block (`mcp-ai-wpoos/scheduled-result`), Elementor widget (`WP_MCP_AI_Elementor_Scheduled_Result_Widget`), REST responses, and assistant tools, so an operator can review the same result in the editor, front end, admin dashboards, or chat.

#### Architecture and data flow

1. A Pro schedule runs through `WP_MCP_AI_Pro_Schedule_Manager`.
2. The dispatcher records the run history and builds a structured result envelope with `summary`, `data`, `render`, `status`, `error`, and `generated_at`.
3. The result envelope is stored separately in `wp_mcp_ai_pro_schedule_results`, keeping it distinct from the lightweight run-history ring buffer.
4. The REST controller exposes picker/latest/history/preview endpoints under `mcp-ai-pro/v1/schedules`.
5. `WP_MCP_AI_Scheduled_Result_Renderer::render()` chooses one of six canonical render modes and escapes output for the current viewer.
6. The refresh enhancer in `assets/js/scheduled-result-refresh.js` can re-fetch `/{id}/latest-result` on a configured cadence.

#### Render modes

| Mode | Expected data shape | Best use |
|---|---|---|
| `summary-card` | Uses `summary` plus `status` | Default dashboard tile, executive summary, simple pass/fail status |
| `list` | `data.items[]` or `data.steps[]` | Priority email digests, top-N lists, workflow step summaries |
| `table` | `data.rows[]` and optional `data.columns[]` | Crawler results, sales by region, operations queues |
| `metric` | `data.value`, optional `data.label`, optional `data.delta` | KPI cards, revenue delta, conversion rate, queue size |
| `timeline` | Reads stored schedule result history | Run-health strip, recent status timeline, operator incident review |
| `raw` | `data.response` or `summary` | Free-form text; HTML-safe output is only honored for authenticated viewers |

#### REST routes

| Route | Method | Capability / auth | Purpose |
|---|---|---|---|
| `/wp-json/mcp-ai-pro/v1/schedules?selectable=1` | `GET` | Authenticated read via `wp_mcp_ai_pro_schedule_result_capability` | Lightweight picker for the block inspector and dashboards |
| `/wp-json/mcp-ai-pro/v1/schedules/{id}/latest-result` | `GET` | Authenticated read, or public when the schedule opts into `display.public_render` | Returns the latest envelope, with weak `ETag` and `Cache-Control: private, max-age=30` when available |
| `/wp-json/mcp-ai-pro/v1/schedules/{id}/results?limit=N` | `GET` | Authenticated read | Returns retained result envelopes, clamped by endpoint and schedule retention |
| `/wp-json/mcp-ai-pro/v1/schedules/{id}/preview` | `POST` | `manage_options`, `X-WP-Nonce`, and a per-user 10-second preview rate limit | Runs a one-shot preview and writes only the result store, not the normal history ring |

#### Assistant tools

| Tool | Capability flags / permissions | Use |
|---|---|---|
| `get_schedule_latest_result` | Read-only, local-only, requires `read_private_posts` or `manage_options` | Let an assistant discuss the latest structured schedule result without rendering HTML |
| `render_schedule_result` | Read-only, local-only, requires `read_private_posts` or `manage_options` | Return sanitized HTML for one of the six render modes, suitable for admin tiles or chat replies |
| `configure_schedule_widget_defaults` | Write, state-changing, local-only, requires `manage_options` | Configure `display.result_capture`, `display.public_render`, `display.public_fields`, `display.result_retention`, and `display.widget_defaults` |

#### Public rendering and redaction model

Public display is opt-in. If the viewer is not logged in with the configured read capability, `WP_MCP_AI_Scheduled_Result_Renderer` checks `display.public_render`. If public rendering is disabled, the viewer receives a not-public notice. If public rendering is enabled, `WP_MCP_AI_Pro_Schedule_Manager::redact_envelope_for_public()` copies only the allow-listed dotted paths in `display.public_fields` into the public envelope.

Use `summary` for public headline text and narrow `data.*` paths for anything else. Avoid putting secrets, customer data, raw transcripts, access tokens, private URLs, or unreviewed model output in public fields.

#### Hooks, filters, and integration points

| Hook / filter | Type | Source | Parameters | When to use |
|---|---|---|---|---|
| `wp_mcp_ai_pro_schedule_run_completed` | Action | `WP_MCP_AI_Pro_Schedule_Manager::dispatch()` | `$schedule_id`, `$result` containing `success`, `duration`, `error`, `action_log`, `schedule` | Observability, alerts, dashboards, webhooks after every run |
| `wp_mcp_ai_pro_schedule_result_envelope` | Filter | `WP_MCP_AI_Pro_Schedule_Manager::build_result_envelope()` | `$envelope`, `$schedule`, `$action_log`, `$success` | Shape the stored envelope; map assistant JSON into `data.items`, `data.rows`, `data.value`, etc. |
| `wp_mcp_ai_pro_schedule_result_retention` | Filter | `WP_MCP_AI_Pro_Schedule_Manager::store_result_envelope()` | `$retention`, `$schedule_id`, `$schedule` | Override retained envelope count; final value is clamped to 1-100 |
| `wp_mcp_ai_pro_schedule_result_recorded` | Action | `WP_MCP_AI_Pro_Schedule_Manager::store_result_envelope()` | `$schedule_id`, `$envelope`, `$schedule` | Cache busting, search indexing, OTel spans, downstream notifications |
| `wp_mcp_ai_pro_schedule_public_result` | Filter | `WP_MCP_AI_Pro_Schedule_Manager::redact_envelope_for_public()` | `$redacted`, `$envelope`, `$schedule` | Last-chance public redaction or public-summary normalization |
| `wp_mcp_ai_pro_schedule_result_capability` | Filter | `WP_MCP_AI_Pro_Schedule_Result_Controller::admin_capability()` | `$capability`, default `read_private_posts` | Delegate schedule-result viewing to a custom capability |
| `wp_mcp_ai_pro_schedule_capability` | Filter | Pro Scheduler settings page | `$capability`, default `manage_options` | Delegate Pro Scheduler settings-page access |
| `wp_mcp_ai_pro_schedule_max_concurrent_runs` | Filter | Pro Scheduler settings UI / consumers | Configured soft cap | Coordinate scheduler consumers that respect a site-specific concurrency hint |
| `wp_mcp_ai_ics_generate_calendar` | Filter | Schedule Manager iCalendar export | `$result`, calendar payload data | Use the Node `ical-generator` service or a custom ICS generator instead of fallback PHP output |
| `wp_mcp_ai_pro_workflow_completed` | Action | Pro Schedule workflow dispatcher | `$schedule_id`, `$schedule`, `$previous_results` | Observe multi-step workflow completion from scheduled workflows |
| `wp_mcp_ai_pro_workflow_builder_completed` | Action | Workflow Builder dispatch path | `$schedule_id`, `$schedule`, `$workflow_builder_id`, `$node_results` | Observe visual workflow-builder schedule completion |
| `wp_mcp_ai_pro_scheduled_assistant_run` | Action | Assistant-run dispatcher | `$assistant_id`, generated content / metadata, schedule context | Track assistant-generated schedule outputs before envelope rendering |
| `wp_mcp_ai_pro_channel_broadcast` | Action | Channel-broadcast dispatcher | `$message`, channel/config/context values | Integrate scheduled channel broadcasts with external messaging observability |
| `wp_mcp_ai_workflow_execute_action` | Filter | Workflow Builder action node | `$result`, `$command`, `$params`, `$context` | Provide custom execution for scheduled workflow action nodes |
| `wp_mcp_ai_workflow_execute_agent` | Filter | Workflow Builder agent node | `$result`, `$agent_id`, `$prompt`, `$context` | Provide custom agent execution for scheduled workflow agent nodes |

#### Admin AJAX endpoints around schedules

These are not public front-end APIs, but they matter for operators debugging scheduled-result setup:

| AJAX action | Purpose |
|---|---|
| `wp_mcp_ai_sm_get_schedules` | Load schedule rows for the Schedule Manager |
| `wp_mcp_ai_sm_create_schedule` | Create a schedule |
| `wp_mcp_ai_sm_update_schedule` | Update a schedule |
| `wp_mcp_ai_sm_delete_schedule` | Delete a schedule |
| `wp_mcp_ai_sm_toggle_schedule` | Enable or disable a schedule |
| `wp_mcp_ai_sm_trigger_schedule` | Run a schedule now |
| `wp_mcp_ai_sm_get_history` | Read run history |
| `wp_mcp_ai_sm_clear_history` | Clear run history |
| `wp_mcp_ai_preview_schedule_from_research` | Preview schedule plans from workflow text |
| `wp_mcp_ai_create_schedule_from_research` | Create schedules from researched workflow text |
| `wp_mcp_ai_dry_run_schedule_from_research` | Dry-run a planned schedule |
| `wp_mcp_ai_toggle_schedule_from_research` | Toggle a researched schedule |
| `wp_mcp_ai_run_now_schedule_from_research` | Run a researched schedule now |
| `wp_mcp_ai_run_history_from_research` | Read run history from the research page |

#### Quickstart Guide (15 minutes)

1. Create or identify a Pro schedule that produces a useful result, such as a daily sales summary, weekly content backlog, priority-email digest, or crawler report.
2. Run it once from the Schedule Manager or use the block inspector's preview action so the result store has an envelope.
3. Configure display defaults with `configure_schedule_widget_defaults`: set `result_capture` to `summary` or `full`, choose a `widget_defaults.render_mode`, set `result_retention`, and decide whether public rendering is allowed.
4. If public rendering is enabled, define `public_fields` as a strict allow-list. Start with `summary` only; add `data.value`, `data.label`, or specific `data.items` paths only after review.
5. Add the Gutenberg Scheduled Result block, Elementor widget, or assistant-rendered tile to the target surface.
6. Validate as an administrator, then as the lowest-privilege intended viewer, then as an anonymous visitor if public rendering is enabled.
7. Turn on auto-refresh only for dashboards that need it; keep refresh intervals conservative to avoid unnecessary REST traffic.
8. Subscribe to `wp_mcp_ai_pro_schedule_result_recorded` or `wp_mcp_ai_pro_schedule_run_completed` for observability, cache invalidation, or downstream notifications.

#### Worked examples

| Scenario | Recommended mode | Envelope shaping tip | Public rendering guidance |
|---|---|---|---|
| Daily sales KPI | `metric` | Populate `data.value`, `data.label`, and `data.delta` through `wp_mcp_ai_pro_schedule_result_envelope` | Public only if values are already approved for public dashboards |
| Priority email digest | `list` | Map assistant JSON into `data.items[]` with safe titles, not raw email bodies | Usually private; if public, expose only aggregate counts or redacted titles |
| Crawl4AI overnight report | `table` | Populate `data.columns[]` and `data.rows[]` with URL/status/summary fields | Public only for public URLs and non-sensitive summaries |
| Incident/run health tile | `timeline` | Let the renderer read recent retained envelopes | Safe for internal dashboards; do not expose operational failure details publicly |
| Editorial backlog | `summary-card` or `list` | Use `summary` for the headline and `data.items[]` for draft titles | Keep private until editorial review completes |

#### Troubleshooting

- **No result appears:** run the schedule once, trigger preview, or verify `result_capture` is not `disabled`.
- **Anonymous visitors see a notice:** set `display.public_render` intentionally, then add `display.public_fields`; never rely on deny-lists.
- **The wrong mode renders:** check `display.widget_defaults.render_mode` and any block/widget override.
- **Auto-refresh does nothing:** verify `data-mcp-ai-refresh-interval`, the REST nonce for authenticated dashboards, and `/latest-result` network responses.
- **Result is stale:** inspect the Schedule Manager run history, provider errors, WP-Cron, and the inline-async-tick troubleshooting entry below.
- **HTML appears stripped in raw mode:** unauthenticated viewers do not get HTML-safe raw rendering; authenticated output still runs through WordPress escaping/kses rules.

---

## 5. Research & Data Analysis

### Use Case: Research Briefs and Dataset Summaries

**Required Tools:** web/research tools, file tools, spreadsheet/data helpers, memory tools when enabled.

**Quickstart Guide (5 minutes)**

1. Create a Research Scientist or Data Scientist assistant.
2. Define source-quality rules and citation expectations.
3. Upload or link the dataset/source material.
4. Ask for a short hypothesis, analysis plan, and confidence notes before the final brief.
5. Save validated findings to posts, docs, or team memory only after review.

---

## 6. Developer & Technical Integration

### 6.0 Toolkit MCP Servers

**Required Tools / surfaces:** Pro toolkit MCP server framework, per-toolkit bearer tokens, MCP-compatible JSON-RPC 2.0 client, `/.well-known/mcp` discovery endpoint.

Toolkit MCP Servers let an operator expose a narrow, vertical-specific tool surface rather than the whole site registry. Each Pro toolkit can register as an independent MCP server with its own credentials, rate limits, audit trail, and scoped endpoint. External MCP clients, vendor integrations, or internal operators see only that toolkit's tools, resources, and prompts.

#### Architecture

1. Each toolkit implements `WP_MCP_AI_Toolkit_Server_Interface` and extends `WP_MCP_AI_Toolkit_Server_Base`.
2. `WP_MCP_AI_Toolkit_Server_Registry` bootstraps at `init:12` and fires `do_action( 'wp_mcp_ai_register_toolkit_servers', $registry )` to collect all server registrations.
3. `WP_MCP_AI_Toolkit_MCP_REST_Controller` exposes JSON-RPC 2.0 endpoints under `mcp-ai-pro/v1/mcp/{slug}`.
4. `WP_MCP_AI_Pro_Well_Known_MCP` serves the `/.well-known/mcp` discovery document via a WordPress rewrite rule.
5. `WP_MCP_AI_Toolkit_MCP_Audit_Log` records cross-mount reads and tool calls in a 200-entry ring buffer.
6. `WP_MCP_AI_Pro_Toolkit_Server_Token` manages per-server bearer tokens (format: `mcptk_{prefix}.{secret}`, max 10 per server).

#### REST routes (namespace `mcp-ai-pro/v1`)

| Method | Route | Auth | Purpose |
|---|---|---|---|
| `GET` | `/mcp` | Public | List all registered server descriptors |
| `GET` | `/mcp/{slug}` | Public | Single server descriptor |
| `POST` | `/mcp/{slug}` | Bearer token or authenticated session | JSON-RPC 2.0 dispatch |
| `GET` | `/mcp/{slug}/token` | `manage_options` | List tokens for a server |
| `POST` | `/mcp/{slug}/token` | `manage_options` | Generate a new bearer token |
| `DELETE` | `/mcp/{slug}/token/{prefix}` | `manage_options` | Revoke a token |
| `GET` | `/mcp-audit` | `manage_options` | Read the audit log |

#### JSON-RPC 2.0 methods (via `POST /mcp/{slug}`)

| Method | Purpose |
|---|---|
| `initialize` | Returns `protocolVersion: 2025-06-18` and server capabilities |
| `ping` | Liveness check |
| `tools/list` | Effective tools for the server |
| `tools/call` | Execute a tool within the server's scope |
| `resources/list` | Available resources |
| `resources/read` | Read a resource (may cross-mount from another server) |
| `prompts/list` | Available prompts |
| `prompts/get` | Retrieve a prompt template |

#### Authentication

Two paths for `POST /mcp/{slug}`:

1. **Per-server bearer token** — `Authorization: Bearer mcptk_{prefix}.{secret}`. The token hash is validated by `WP_MCP_AI_Pro_Toolkit_Server_Token::validate()`.
2. **WordPress session** — `is_user_logged_in()` with `current_user_can( 'read' )`.

Token management and audit routes require `manage_options`.

#### Discovery

`GET /.well-known/mcp` returns a JSON document listing all enabled servers with their endpoint URLs. Filterable via `wp_mcp_ai_well_known_mcp_document`. Cache-Control max-age defaults to 3600 seconds, adjustable via `wp_mcp_ai_well_known_mcp_cache_max_age`.

#### Rate limiting and payload caps

Per-server configurable via admin or option `wp_mcp_ai_toolkit_mcp_server_{slug}`:

| Setting | Default | Error code |
|---|---|---|
| `requests_per_minute` | 0 (unlimited) | `-32099` |
| `max_payload_bytes` | 0 (no cap) | `-32098` |
| `max_iterations` | 0 (inherit global) | — |

Probe methods (`initialize`, `ping`) bypass rate limiting.

#### Admin UI (Phase 7)

`WP_MCP_AI_Pro_Toolkit_MCP_Servers_Page` (slug `nvoos-pro-toolkit-mcp-servers`) provides five tabs: Servers, Detail, Audit, Discovery, and Help. Operators can enable/disable servers, manage tokens, adjust limits, and clear audit logs.

An assistant metabox (`WP_MCP_AI_Pro_Metabox_Toolkit_MCP_Servers`, meta key `_wp_mcp_ai_pro_allowed_mcp_servers`) lets operators allow-list which MCP servers a specific assistant may interact with.

#### WP-CLI

Registered as `wp mcp-ai mcp-server`:

| Command | Purpose |
|---|---|
| `list` | Table of all registered servers (filterable by `--status`) |
| `get <slug>` | Full descriptor for one server |
| `enable <slug>` / `disable <slug>` | Toggle server availability |
| `tools <slug>` | List effective tool slugs |
| `token-generate <slug>` | Generate a bearer token |
| `token-list <slug>` | List tokens |
| `token-revoke <slug> <prefix>` | Revoke a token |

#### Hooks, filters, and integration points

| Hook / filter | Type | Purpose |
|---|---|---|
| `wp_mcp_ai_register_toolkit_servers` | Action | Register custom servers into the registry |
| `wp_mcp_ai_toolkit_mcp_before_call` | Action | Fires before `tools/call` execution |
| `wp_mcp_ai_toolkit_mcp_after_call` | Action | Fires after `tools/call` execution |
| `wp_mcp_ai_toolkit_mcp_cross_mount_read` | Action | Fires on every cross-mount `resources/read` or `prompts/get` |
| `wp_mcp_ai_toolkit_mcp_audit_recorded` | Action | Fires after each audit entry is written |
| `wp_mcp_ai_toolkit_mcp_server_toggled` | Action | Fires when a server is enabled or disabled |
| `wp_mcp_ai_toolkit_mcp_server_limits` | Filter | Override effective rate/payload/iteration limits per server |
| `wp_mcp_ai_toolkit_mcp_audit_max_entries` | Filter | Override the audit ring-buffer size (default 200) |
| `wp_mcp_ai_well_known_mcp_document` | Filter | Customize the `/.well-known/mcp` discovery document |
| `wp_mcp_ai_well_known_mcp_cache_max_age` | Filter | Override Cache-Control max-age (default 3600) |
| `wp_mcp_ai_toolkit_mcp_server_{slug}_candidate_tools` | Filter | Override candidate tool slugs per server |

#### Quickstart Guide (20 minutes)

1. Activate the Pro addon and confirm the toolkit whose MCP server you want to expose is enabled.
2. Navigate to the Toolkit MCP Servers admin page and verify the server appears in the Servers tab.
3. Open the Detail tab for the target server. Generate a bearer token and copy it securely.
4. Configure the external MCP client with the server's JSON-RPC endpoint (`/wp-json/mcp-ai-pro/v1/mcp/{slug}`) and the bearer token.
5. Send an `initialize` request from the client to confirm the connection and protocol version.
6. Call `tools/list` to verify the client sees only that toolkit's tools.
7. Run a read-only tool first, then a low-risk write tool in a staging environment before production use.
8. Review the Audit tab to confirm calls are logged.

#### Why scoped endpoints matter

- **Reduced blast radius:** a leaked token exposes only one toolkit, not the entire site registry.
- **Vendor reviews:** external partners audit a narrow, well-defined tool surface.
- **Independent rate limiting:** each toolkit can have its own request and payload caps.
- **Cross-mount audit trail:** every cross-toolkit resource or prompt read is logged.

See also: `docs/ADR_002_toolkit_mcp_servers.md`, `docs/features/toolkit-mcp-servers.md`, `docs/mcp-servers.md`.

### 6.1 REST API and Webhook Integrations

**Required Tools:** REST endpoints under `/wp-json/mcp-ai/v1/`, assistant credentials, WordPress nonce or bearer-token authentication.

**Quickstart Guide (25 minutes)**

1. Create an assistant credential for the integration.
2. Store the token in the external system's secret manager.
3. Start with read-only endpoints and short prompts.
4. Add rate limits and retries in the caller.
5. Log request IDs and tool results for auditability.

### 6.2 Provider Routing and Local Models

Use provider settings and the model catalog to route workloads to hosted or local models. Local providers such as Ollama, LM Studio, WebLLM, and embedded MLC have API-price `$0` in the catalog but still require hardware, hosting, and operational support.

### 6.3 Custom Tool Development

**Unix Theory P0–P6 call-out:** custom tools must return the canonical envelope: a success array on success or `WP_Error` on failure. They must not return `array( 'success' => false, ... )`. Sanitize every `$arguments[...]` value at entry, then escape every value at exit. The two PHPCS sniffs enforcing this pattern are `WPMCPAI.Tools.CanonicalReturnEnvelope` and `WPMCPAI.Tools.SanitizeAtEntry`.

**Quickstart Guide (30 minutes)**

1. Create the tool class under `includes/tools/` or the appropriate addon namespace.
2. Implement slug, definition, capability requirements, and `execute()`.
3. Sanitize arguments immediately after reading them.
4. Return `WP_Error` for permission, validation, provider, or execution failures.
5. Escape output values before returning user-facing strings or HTML.
6. Register the tool and add tests covering success, capability failure, validation failure, and provider errors.

### 6.4 Memory Mining from Transcripts

**Required Tools:** `mine_agent_memory` tool, `WP_MCP_AI_Transcript_Mining_Job` service, inline-async-tick trait, transcript storage (JetEngine CCT or localStorage capture), admin logs.

Memory mining extracts reusable memory from chat transcripts so assistants can recall prior interactions. The `mine_agent_memory` tool processes batches of transcript sessions, extracting memories that are stored in the agent memory system.

#### Architecture

1. `WP_MCP_AI_Transcript_Mining_Job` manages the lifecycle: enqueue → tick → complete/cancel.
2. Job state is stored in transients (`wp_mcp_ai_tx_mine_job_{id}`) with a 6-hour TTL so jobs auto-evaporate.
3. Each tick processes a configurable batch (default 10 sessions, max 500 per job) by calling the `mine_agent_memory` tool.
4. The job composes `WP_MCP_AI_Inline_Async_Tick_Trait` so the first tick runs immediately during the request shutdown, even on hosts with `DISABLE_WP_CRON` or blocked loopbacks.
5. A two-layer cooperative lock (object cache + transient, TTL 60 s) prevents the inline kick and a delayed cron event from processing the same tick concurrently.
6. Under `DISABLE_WP_CRON`, the inline kick loops for up to 20 seconds (`INLINE_LOOP_BUDGET_SECONDS`) to drain as much work as possible in a single request.

#### The inline-async-tick pattern

The `WP_MCP_AI_Inline_Async_Tick_Trait` is composed by eight background subsystems across the base plugin and addons:

| # | Consumer | Tick hook | Lock TTL |
|---|---|---|---|
| 1 | Transcript Mining Job | `wp_mcp_ai_transcript_mining_tick` | 60 s |
| 2 | Tool Async Executor | `wp_mcp_ai_async_tool_execution` | — |
| 3 | Crawl4AI Poller | `wp_mcp_ai_crawl4ai_poll_task` | 30 s |
| 4 | Harness Eval Scheduler | `wp_mcp_ai_harness_eval_tick` | 120 s |
| 5 | Gemini Veo Video Poller | `wp_mcp_ai_poll_veo_video` | 30 s |
| 6 | SaaS Controller Apply Job | `nvoos_saas_controller_apply_tick` | 120 s |
| 7 | Docs Hub Rebuild Pipeline | `nvoos_docs_hub_rebuild_tick` | 45 s |
| 8 | Graphify Reindex | `nvoos_graphify_cron_build` | 60 s |

The trait provides six protected static primitives:

| Method | Purpose |
|---|---|
| `inline_async_detach_worker_from_client()` | Calls `ignore_user_abort()` and `fastcgi_finish_request()` if available |
| `inline_async_acquire_tick_lock()` | Two-layer lock: `wp_cache_add()` (atomic on Redis/Memcached) + `set_transient()` |
| `inline_async_release_tick_lock()` | Idempotent release via `wp_cache_delete()` + `delete_transient()` |
| `inline_async_should_loop()` | Returns `true` only when `DISABLE_WP_CRON` is set, work remains, and budget not exhausted |
| `inline_async_kick_enabled()` | Resolves the `wp_mcp_ai_inline_kick_enabled` filter |
| `inline_async_run_kick()` | Wraps the tick callable with try/catch, duration measurement, and the `wp_mcp_ai_inline_kick_completed` action |

#### Self-healing REST endpoint

When the admin polls `GET /mcp-ai/v1/transcript-mining/jobs/{id}` and the job has been `queued` for longer than `STALE_QUEUED_THRESHOLD_SECONDS` (5 s), the controller registers a `shutdown` action to call `kick_inline()`. This means the very next poll observes progress. The same self-healing pattern exists in the Crawl4AI and SaaS Controller REST routes.

#### REST routes (namespace `mcp-ai/v1`)

| Method | Route | Capability | Purpose |
|---|---|---|---|
| `POST` | `/transcript-mining/jobs` | `manage_options` | Enqueue a new mining job |
| `GET` | `/transcript-mining/jobs/{id}` | `manage_options` | Poll job progress (with self-healing kick) |
| `POST` | `/transcript-mining/jobs/{id}/cancel` | `manage_options` | Cancel a running job |

#### Hooks and filters

| Hook / filter | Type | Parameters | Purpose |
|---|---|---|---|
| `wp_mcp_ai_inline_kick_enabled` | Filter | `(bool $enabled, string $job_id, string $class)` | Global or per-class escape hatch to disable inline kicks |
| `wp_mcp_ai_inline_kick_completed` | Action | `(string $class, string $job_id, float $duration_ms, bool $success)` | Observability hook for OTel / Pro measurement bootstrap |
| `wp_mcp_ai_mine_transcripts_sessions` | Filter | `(array $sessions, array $args)` | Customize which transcript sessions are mined |
| `wp_mcp_ai_mine_transcripts_session_messages` | Filter | `(array $messages, string $session_key, array $args)` | Filter messages within a transcript session before mining |
| `wp_mcp_ai_mine_transcripts_dedupe_scan_limit` | Filter | `(int $limit, int $agent_id, string $field)` | Override the deduplication scan window |

#### Quickstart Guide (15 minutes)

1. Enable transcript capture and agent memory features in the plugin settings.
2. Confirm transcripts are being saved (JetEngine CCT or localStorage capture).
3. Start with a small batch: navigate to the transcript mining admin surface and queue a job for one assistant or a limited session range.
4. Watch the Jobs / Tasks Drawer for progress updates.
5. If progress remains `0/1` after a few seconds, poll the job REST endpoint once — the self-healing kick will start the first tick.
6. Review extracted memories in the agent memory surface before making them available to assistants.
7. For production runs, increase the batch size and monitor duration/success via `wp_mcp_ai_inline_kick_completed`.

#### Extension points

- Use `wp_mcp_ai_inline_kick_enabled` to disable inline kicks in controlled test environments.
- Subscribe to `wp_mcp_ai_inline_kick_completed` for OTel metrics and alerting.
- Use `wp_mcp_ai_mine_transcripts_sessions` to exclude specific sessions or time ranges.
- Use `wp_mcp_ai_mine_transcripts_session_messages` to redact sensitive messages before mining.

See also: `docs/architecture/inline-async-tick-pattern.md`, `docs/features/memory/transcript-mining.md`.

---

## 7. Education & Knowledge Management

### Use Case: Curriculum Tutor and Knowledge Coach

**Required Tools:** profession templates, file/document tools, knowledge-base features, optional Docs Hub.

**Quickstart Guide (10 minutes)**

1. Choose a Mathematics Tutor, Science Teacher, Academic Advisor, or other education template.
2. Add curriculum constraints and age-appropriate safety rules.
3. Load source documents into the selected knowledge surface.
4. Test with factual, explanatory, and refusal/safety prompts.
5. Keep teacher or administrator review for published learning materials.

### 7.4 Bundled Skill Packs (UI/UX Pro Max)

The UI/UX Pro Max skill pack bundles design-system guidance for colors, typography, icons, UI components, UX heuristics, and stack-specific guidance for common front-end frameworks.

**Quickstart Guide (12 minutes)**

1. Enable the skill pack registry or Pro skill-pack surface.
2. Attach UI/UX Pro Max to a Web Developer, Product Designer, or UX assistant.
3. Ask for design guidance in structured outputs: tokens, components, accessibility notes, and implementation caveats.
4. For custom skill packs, mirror the bundled data-file structure: colors, typography, icon rules, component patterns, UX guidelines, and framework-specific notes.
5. Test the skill pack with at least one layout, one accessibility review, and one stack-specific implementation request.

---

## 8. Multi-Agent Orchestration

### Use Case: Role-Based Research Team

**Required Tools:** orchestration features, team templates, provider routing, optional `docs/ORCHESTRATION_REFERENCE.md` guidance.

**Quickstart Guide (20 minutes)**

1. Create a team with planner, executor, critic, and specialist roles.
2. Assign tool scopes per role, not globally.
3. Use a stronger model for planning/critique and lower-cost models for routine execution.
4. Require intermediate summaries before write actions.
5. Review the final artifact and logs before publishing or handing off.

---

## 9. Advanced Workflow Automation

### Use Case: Multi-Step Operational Workflow

**Required Tools:** workflow/job tools, task drawer, provider routing, async continuation.

**Quickstart Guide (15 minutes)**

1. Break the workflow into named steps with dependencies.
2. Mark read-only, write, external-call, and approval steps separately.
3. Use async continuation for long-running model/tool work.
4. Monitor jobs from the Jobs / Tasks Drawer.
5. Add human approval for irreversible actions.

---

## 10. Video Production & Transcoding

### Use Case: Short-Form Video Campaign

**Required Tools:** video-generation provider, media tools, optional Pro video/media toolkits.

**Quickstart Guide (10 minutes)**

1. Configure a provider and confirm video-generation credentials.
2. Create a Video Editor assistant.
3. Provide script, aspect ratio, duration, brand constraints, and moderation rules.
4. Queue the video job and monitor async progress.
5. Review output before attaching it to posts, products, or campaigns.

---

## 11. Site Building Automation

### Use Case: Landing Page Builder

**Required Tools:** page/post tools, media tools, optional Elementor or Site Creator toolkit.

**Quickstart Guide (25 minutes)**

1. Create a Web Developer or Product Designer assistant.
2. Provide sitemap, content brief, style constraints, and conversion goal.
3. Generate page copy and structure as draft content first.
4. Use theme/page-builder tools only with appropriate capabilities.
5. Review accessibility, responsive behavior, SEO metadata, and forms before launch.

---

## 12. Regulatory Compliance

### Use Case: Compliance Evidence Assistant

**Required Tools:** document tools, knowledge-base references, audit-friendly logging, optional regulatory-registration toolkit.

**Quickstart Guide (20 minutes)**

1. Create a compliance-oriented assistant with restricted tools.
2. Load the organization's approved policy and evidence documents.
3. Ask for summaries, gap lists, and evidence maps, not final legal certification.
4. Store outputs as drafts with reviewer fields.
5. Keep legal/compliance staff as final approvers.

---

## 13. Front-End Chat Delivery (Chat SPA)

**Required Surface:** `[nvoos_chat_spa]` shortcode (or `nvoos/chat-spa` Gutenberg block), Chat SPA addon (`addons/chat-spa/`, v0.6.0), base plugin REST endpoints for chat, transcripts, memory, and approvals.

The Chat SPA addon is a React-based replacement for the legacy `[mcp_ai_chat]` shortcode. Built on Vercel AI SDK (`@ai-sdk/react`) with a custom SSE-to-Data-Stream adapter, it delivers tool-call cards, transcript management, memory controls, HITL approval flows, file attachments, and regenerate/edit capabilities in a modern single-page chat experience.

### Migration from legacy chat

Set `define( 'WP_MCP_AI_LEGACY_CHAT_JS', false );` in `wp-config.php` to disable the legacy `[mcp_ai_chat]` shortcode registration. The default is `true` (legacy mode active). When false, the base plugin no longer registers the legacy shortcode or enqueues `chat-bundle.min.js`, freeing the surface for the SPA.

### Shortcode attributes

| Attribute | Default | Notes |
|---|---|---|
| `assistant_id` | `''` | Post ID of the `mcp_ai_assistant` CPT |
| `theme` | `auto` | `auto`, `light`, or `dark` |
| `height` | `''` | CSS height (e.g. `600px`) |
| `guest` | `0` | `1` enables guest-token auth and disables sidebar + memory drawer |

### Phases 1–7

| Phase | Feature | Key components |
|---|---|---|
| 1 | SSE adapter + base chat | Custom `createChatFetch()` bridges NV oOS SSE frames to AI SDK Data Stream Protocol; `[nvoos_chat_spa]` shortcode + block |
| 2 | Tool-call cards + annotations | Collapsible `<details>` cards from `message.toolInvocations`; inline annotation pills for `memory_event` and other types; admin embed page |
| 3 | Transcripts sidebar | Load/save/delete via `mcp-ai/v1/chat-transcripts`; `useTranscriptSession` hook; guest mounts skip sidebar |
| 4 | Memory drawer | Three tabs: Memories (CRUD), Scope (wing/room persistence), Audit (read-only feed); `MemoryClient` API; disabled for guests |
| 5 | HITL approval bar | Polls `mcp-ai/v1/approvals` every 6 s during streaming; approve/deny buttons; `manage_options` only |
| 6 | File attachments + regenerate/edit | `useAttachments` hook (5 MB/file, 10 MB total, 10 files max); `↺` regenerate via `reload()`; `✏` edit via `setMessages` truncation |
| 7 | Legacy gate | `WP_MCP_AI_LEGACY_CHAT_JS` constant gates the legacy shortcode; migration guide |

### SSE frame mapping

| NV oOS SSE frame | AI SDK chunk | UI effect |
|---|---|---|
| `message_delta` / `text_delta` / `delta` | `0:` (text) | Streams text into message content |
| `tool_call_started` | `9:` (tool_call) | Adds a pending tool card |
| `tool_call_completed` | `a:` (tool_result) | Flips tool card to result state |
| `memory_event` / `annotation` | `8:` (message_annotations) | Renders annotation pills |
| `done` / `finish` | `e:` (finish) | Signals stream end |

### REST endpoints consumed

| Base-plugin route | Used by | Purpose |
|---|---|---|
| `mcp-ai/v1/chat-client` | `sse-adapter.ts` | Primary SSE streaming endpoint |
| `mcp-ai/v1/chat-transcripts` | `TranscriptsClient` | List, get, save, delete transcript sessions |
| `mcp-ai/v1/chat-memory/*` | `MemoryClient` | Recall, store, update, delete memories; audit log; preferences |
| `mcp-ai/v1/approvals` | `HitlClient` | List, approve, deny pending HITL approvals |

The addon also registers its own routes under `nvoos-chat-spa/v1`:

| Method | Route | Auth | Purpose |
|---|---|---|---|
| `GET` | `/health` | `manage_options` | Liveness probe |
| `GET` | `/manifest` | Public | Addon metadata and bundle URLs |
| `GET` | `/config` | Public | Endpoint map + feature-flag booleans |

### Filters

| Filter | Parameters | Purpose |
|---|---|---|
| `nvoos_chat_spa_can_render` | `(bool $can_render, array $atts)` | Gate shortcode output; return `false` to suppress |
| `nvoos_chat_spa_manifest` | `(array $payload)` | Modify the `/manifest` REST response |
| `nvoos_chat_spa_config` | `(array $payload)` | Modify the `/config` REST response |

### File attachment limits

| Limit | Value |
|---|---|
| Per-file size | 5 MB |
| Total aggregate | 10 MB |
| Max files per message | 10 |
| Allowed types | JPEG, PNG, GIF, WebP, PDF, plain text, Markdown, CSV, JSON, DOCX, DOC |

### Quickstart Guide (15 minutes)

1. Activate the Chat SPA addon.
2. Set `define( 'WP_MCP_AI_LEGACY_CHAT_JS', false );` in `wp-config.php` to disable the legacy chat shortcode.
3. Create or select the assistant to expose on the front end.
4. Add `[nvoos_chat_spa assistant_id="123"]` to the target page or use the Gutenberg block.
5. Configure guest-token or authenticated access: set `guest="1"` for public surfaces that should not see transcripts or memory.
6. Test as a guest, subscriber, and administrator. Verify:
   - Text streaming works (Phase 1).
   - Tool-call cards appear and collapse (Phase 2).
   - Transcript sidebar loads, saves, and deletes sessions (Phase 3).
   - Memory drawer shows recall, scope, and audit tabs (Phase 4).
   - HITL approval bar appears for admin users during streaming (Phase 5).
   - File attachments show thumbnails and respect size limits (Phase 6).
   - Regenerate and edit/branch work on the last assistant or any user message (Phase 6).
7. Confirm transcripts, memory controls, and approval flows do not expose private data across users.

### Operational notes

- Use HITL approvals for payments, publishing, account changes, and external writes.
- File attachments must observe upload/MIME validation rules on both client and server.
- Transcript retention should match your privacy policy and site settings.
- The admin embed page (Tools → NV oOS Chat, `manage_options`) renders the same shortcode for backend testing.
- Bundle size is approximately 81.3 KB gzip.

---

## 14. In-WP Documentation Viewer (Docs Hub)

**Required Surface:** Docs Hub addon (`addons/docs-hub/`, v0.3.9), optional remote repository configuration, optional WP-CLI for large rebuilds.

Docs Hub provides a GitBook-style documentation browser inside WordPress. It discovers, indexes, and renders Markdown documentation from the base plugin, addons, and remote GitHub repositories in a React SPA with full-text search, syntax highlighting, sitemap support, and accessibility features.

### Architecture

1. `NV_oOS_Docs_Hub_Scanner` discovers documentation files from local plugin directories and configured remote repositories.
2. `NV_oOS_Docs_Hub_Indexer` transforms Markdown into structured JSON payloads (navigation manifest + per-page content).
3. `NV_oOS_Docs_Hub_Cache` stores the built index in the WordPress uploads directory with a staging/live atomic swap.
4. `NV_oOS_Docs_Hub_Rebuild_Pipeline` processes rebuilds in chunks across multiple PHP requests via WP-Cron, with five pipeline phases: Scan → Pages → Links → Search → Finalize.
5. The pipeline composes `WP_MCP_AI_Inline_Async_Tick_Trait` so the first chunk runs immediately on request shutdown.
6. `NV_oOS_Docs_Hub_REST` exposes the manifest, page content, search, and rebuild control endpoints.
7. The React SPA (`src/`) renders the documentation with HashRouter navigation, flexsearch full-text search, rehype-highlight syntax highlighting, and responsive sidebar.

### Rebuild pipeline phases

| Phase | Constant | Description |
|---|---|---|
| 1 | `PHASE_SCAN` | Gather entries, build provisional manifest in staging cache |
| 2 | `PHASE_PAGES` | Process page payloads in configurable chunks (default 25 per tick) |
| 3 | `PHASE_LINKS` | Broken-link detection across staged pages |
| 4 | `PHASE_SEARCH` | Build search index in chunks |
| 5 | `PHASE_FINALIZE` | Atomic swap from staging to live cache |

Per-tick budget defaults to 15 seconds wall-clock and 80% of `memory_limit`, both filterable.

### REST routes (namespace `nvoos-docs/v1`)

| Method | Route | Auth | Purpose |
|---|---|---|---|
| `GET` | `/manifest` | Public (respects `public_access` setting) | Full navigation manifest |
| `GET` | `/pages/{slug}` | Public | Individual page payload |
| `GET` | `/search?q=...&limit=` | Public | Full-text search |
| `POST` | `/rebuild` | `manage_options` | Trigger rebuild (sync or async) |
| `GET` | `/rebuild/status` | `manage_options` | Poll rebuild progress |
| `POST` | `/rebuild/cancel` | `manage_options` | Cancel an in-flight rebuild |
| `POST` | `/rebuild/resume` | `manage_options` | Resume a failed/cancelled rebuild |
| `GET` | `/health` | `manage_options` | Diagnostic health check |
| `GET` | `/remote/tree` | `manage_options` | Fetch GitHub repo file tree for admin picker |

### SSRF hardening (5 layers)

Remote repository fetches go through `NV_oOS_Docs_Hub_Remote_Repo::safe_get()`:

| Layer | Protection |
|---|---|
| 1 | HTTPS-only enforcement |
| 2 | Domain allowlist (default: `api.github.com`, `raw.githubusercontent.com`; filterable via `nvoos_docs_hub_remote_allowed_hosts`) |
| 3 | DNS resolution with private-IP rejection (`FILTER_FLAG_NO_PRIV_RANGE`, `FILTER_FLAG_NO_RES_RANGE` on every A/AAAA record) |
| 4 | IP pinning via `CURLOPT_RESOLVE` to prevent DNS rebinding |
| 5 | Redirect disabled (`redirection => 0`) and response size cap (4 MB) |

### Sitemap integration

`NV_oOS_Docs_Hub_Sitemap_Provider` extends `WP_Sitemaps_Provider` (WordPress 5.5+) and registers at `wp_sitemaps_init`. It generates sitemap URLs as `{docs_page_url}/#/{slug}` with `<lastmod>` when available. Kill-switch: `nvoos_docs_hub_sitemap_enabled` filter.

### WP-CLI

Registered as `wp nvoos-docs`:

| Command | Purpose |
|---|---|
| `wp nvoos-docs sync` | Full synchronous rebuild; `--strict` exits non-zero on broken links |
| `wp nvoos-docs rebuild` | Chunked rebuild (`--async` default, `--sync`, `--resume`, `--cancel`) |
| `wp nvoos-docs clear` | Clear the documentation cache |
| `wp nvoos-docs status` | Table output: last built, total pages, broken links, rebuild phase, progress |

### Shortcode and block

| Surface | Tag / name | Attributes |
|---|---|---|
| Shortcode | `[nvoos_docs]` | `section`, `theme`, `search`, `sidebar`, `home` |
| Gutenberg block | `nvoos/docs-hub` | Delegates to shortcode renderer |

### Hooks and filters

| Hook / filter | Type | Purpose |
|---|---|---|
| `nvoos_docs_hub_manifest` | Filter | Shape the final manifest before caching |
| `nvoos_docs_hub_page_payload` | Filter | Modify per-page payload before caching |
| `nvoos_docs_hub_source_priority` | Filter | Override source-priority map for slug collisions |
| `nvoos_docs_hub_remote_max_files` | Filter | Max files per remote repo |
| `nvoos_docs_hub_excluded_globs` | Filter | Glob patterns to exclude from indexing |
| `nvoos_docs_hub_force_include_globs` | Filter | Force-include despite exclusions |
| `nvoos_docs_hub_remote_allowed_hosts` | Filter | SSRF domain allowlist |
| `nvoos_docs_hub_remote_cache_ttl` | Filter | TTL for per-file remote content cache |
| `nvoos_docs_hub_sources` | Filter | Which source keys are active |
| `nvoos_docs_hub_pruned_dir_names` | Filter | Directory basenames pruned during recursion |
| `nvoos_docs_hub_sitemap_enabled` | Filter | Kill-switch for sitemap entries |
| `nvoos_docs_hub_max_files_total` | Filter | Hard cap on total indexed files (default 5000) |
| `nvoos_docs_hub_rebuild_tick_budget` | Filter | Per-tick wall-clock budget (default 15 s) |
| `nvoos_docs_hub_rebuild_memory_ratio` | Filter | Fraction of memory_limit for tick budget (default 0.8) |
| `nvoos_docs_hub_rebuild_chunk_size` | Filter | Entries per tick (default 25) |
| `nvoos_docs_hub_can_read_section` | Filter | Per-slug read-access gate |
| `nvoos_docs_hub_can_render` | Filter | Shortcode render kill-switch |
| `nvoos_docs_hub_remote_fetch_error` | Action | Fires per-repo when a remote fetch fails |
| `nvoos_docs_hub_rebuild_phase` | Action | Fires on every rebuild phase transition |

### Quickstart Guide (20 minutes)

1. Activate the Docs Hub addon.
2. Navigate to the Docs Hub settings page.
3. For local documentation, the addon auto-discovers docs from the base plugin and active addons.
4. For remote repositories, use the admin repo picker to select a GitHub repository, branch, and path prefix. Configure allowed hosts if using a non-GitHub source.
5. Run a small rebuild first: click Rebuild or use `wp nvoos-docs rebuild --sync` via CLI.
6. Review the built documentation at the shortcode or block surface.
7. Check syntax highlighting, table rendering, internal links, and accessibility (skip-link, ARIA landmarks, RTL support, `prefers-reduced-motion`).
8. Enable sitemap output if documentation pages should be discoverable by search engines.
9. For large documentation sets (hundreds of files), use `wp nvoos-docs rebuild` (async) or the admin async rebuild to process in chunks without timing out.
10. Use the edit-on-GitHub footer (`PageFooter` component) to route corrections back to the source repository.

### Operator checks

- Remote repository access should be explicitly allowlisted via `nvoos_docs_hub_remote_allowed_hosts`.
- Rebuild jobs use the inline-async-tick pattern; see the troubleshooting section for stuck-job diagnosis.
- Keep private or internal documentation behind `nvoos_docs_hub_can_read_section` capability gates.
- The `nvoos_docs_hub_max_files_total` filter (default 5000) prevents runaway indexing.

---

## Pro Features & Toolkits

The Pro addon adds specialized vertical toolkits and SPA toolkit interfaces on top of the base plugin. Rev 2.0 distinguishes between **10 GA SPA-manifested toolkits** and the broader set of Pro settings-page modules.

### GA SPA-Manifested Toolkits

| Toolkit | Primary use |
|---|---|
| Analytics | Dashboards, measurement summaries, reporting workflows |
| Calendar & Booking | Appointment, availability, and booking operations |
| CRE Debt | Commercial real-estate debt workflows |
| CRM | Contacts, pipeline, account summaries, follow-up drafts |
| E-commerce | Product, order, merchandising, and support workflows |
| Financial Planner | Financial planning workflows and client prep |
| Law Firm | Matter, intake, drafting, and legal operations support |
| Multilingual | Translation and multilingual content workflows |
| Regulatory Registrations | Regulated product registration workflows |
| Social Media | Social planning, posting support, and analytics |

Additional Pro settings pages cover architectural verticals, document generation, image/media/video production, project management, site creator, and other modules. Runtime availability depends on the installed addon package, dependencies, and configured credentials. Do not use hardcoded total-tool claims here; use the live registry.

---

## Cost Considerations

All prices in this section are seeded from `includes/data/model-catalog.json` version `2026.05.04`. They are not a billing guarantee. Providers change prices, and site operators can override the catalog in Settings → Models or through filters. Always verify current pricing against provider billing dashboards before committing production workloads.

### OpenAI

| Model | Input $/1K tokens | Output $/1K tokens | Notes |
|---|---:|---:|---|
| `gpt-5-nano` | 0.00005 | 0.0004 | Lowest-cost GPT-5 family entry |
| `gpt-5-mini` | 0.00025 | 0.002 | Routine chat and support |
| `gpt-5` | 0.00125 | 0.010 | General production reasoning |
| `gpt-5.1` | 0.00125 | 0.010 | Current GPT-5.1 line |
| `gpt-5.2` | 0.00175 | 0.014 | Higher-cost newer line |
| `gpt-5-pro` | 0.021 | 0.168 | Expensive; reserve for high-value work |
| `gpt-4.1-nano` | 0.0004 | 0.0012 | Lightweight GPT-4.1 family |
| `gpt-4.1-mini` | 0.0015 | 0.0045 | Balanced GPT-4.1 family |
| `gpt-4.1` | 0.006 | 0.018 | Higher-cost GPT-4.1 family |
| `gpt-4o-mini` | 0.00015 | 0.0006 | Cheap legacy-compatible option |

### Anthropic

| Model | Input $/1K tokens | Output $/1K tokens | Notes |
|---|---:|---:|---|
| `claude-haiku-4-5` | 0.001 | 0.005 | Fast Claude tier |
| `claude-sonnet-4-6` | 0.003 | 0.012 | Recommended production balance |
| `claude-opus-4-6` | 0.005 | 0.025 | Flagship, large context |
| `claude-opus-4-7` | 0.005 | 0.025 | Flagship successor line |

### DeepSeek

| Model | Input $/1K tokens | Output $/1K tokens | Notes |
|---|---:|---:|---|
| `deepseek-chat` | 0.00027 | 0.00110 | General chat alias |
| `deepseek-reasoner` | 0.00055 | 0.00219 | Reasoning workflows |
| `deepseek-coder` | 0.00027 | 0.00110 | Coding workflows |

### Google Provider and Gemini Provider

| Provider | Model | Input $/1K tokens | Output $/1K tokens | Notes |
|---|---|---:|---:|---|
| Google | `gemini-2.5-flash-lite` | 0.0001 | 0.0004 | Low-cost Google 2.5 line |
| Google | `gemini-2.5-flash` | 0.0003 | 0.0025 | Balanced Google 2.5 line |
| Google | `gemini-2.5-pro` | 0.00125 | 0.010 | Higher-capability Google 2.5 line |
| Gemini | `gemini-3.1-flash-lite` | 0.000015 | 0.00006 | Low-cost Gemini 3.1 line |
| Gemini | `gemini-3.1-flash` | 0.000075 | 0.0003 | Fast Gemini 3.1 line |
| Gemini | `gemini-3.1-pro` | 0.00125 | 0.005 | Pro Gemini 3.1 line |

### DigitalOcean Serverless Inference

| Model | Input $/1K tokens | Output $/1K tokens | Notes |
|---|---:|---:|---|
| `llama3.3-70b-instruct` | 0 | 0 | Catalog seed is zeroed |
| `llama3.1-8b-instruct` | 0 | 0 | Catalog seed is zeroed |
| `deepseek-r1-distill-llama-70b` | 0 | 0 | Catalog seed is zeroed |
| `openai-gpt-oss-120b` | 0 | 0 | Catalog seed is zeroed |
| `gte-large-en-v1.5` | 0 | 0 | Embedding model; catalog seed is zeroed |

DigitalOcean pricing fields are intentionally zeroed in the seed catalog. Operators must populate prices from Gradient Platform billing through Settings → Models or the model-catalog filter.

### Kimi / Moonshot

| Model | Input $/1K tokens | Output $/1K tokens | Notes |
|---|---:|---:|---|
| `kimi-k2.6` | 0 | 0 | Catalog seed placeholder |
| `kimi-k2.5` | 0 | 0 | Catalog seed placeholder |
| `kimi-k2` | 0 | 0 | Catalog seed placeholder |
| `kimi-k2-thinking` | 0 | 0 | Catalog seed placeholder |
| `moonshot-v1-8k` | 0.012 | 0.012 | 8k context line |
| `moonshot-v1-32k` | 0.024 | 0.024 | 32k context line |
| `moonshot-v1-128k` | 0.060 | 0.060 | 128k context line |

### Cloudflare Workers AI

| Model | Input $/1K tokens | Output $/1K tokens |
|---|---:|---:|
| `@cf/meta/llama-3.2-1b-instruct` | 0.000027 | 0.000201 |
| `@cf/meta/llama-3.2-3b-instruct` | 0.000051 | 0.000335 |
| `@cf/meta/llama-3.3-70b-instruct-fp8-fast` | 0.000293 | 0.002253 |
| `@cf/meta/llama-4-scout-17b-16e-instruct` | 0.000270 | 0.000810 |
| `@cf/google/gemma-3-12b-it` | 0.000150 | 0.000450 |
| `@cf/mistralai/mistral-small-3.1-24b-instruct` | 0.000351 | 0.000555 |
| `@cf/deepseek-ai/deepseek-r1-distill-qwen-32b` | 0.000497 | 0.004881 |

### Local Providers

Ollama (29 active catalog models), LM Studio (20), WebLLM (5), and embedded MLC (3) are listed at API-price `$0`. That does not mean free operations: plan for hardware, memory, GPU/CPU time, hosting, support, and model-management costs.

### Image Generation

| Provider | Model | Input $/1K tokens | Output $/1K tokens | Notes |
|---|---|---:|---:|---|
| Gemini | `imagen-4` | 0 | 0 | Catalog placeholder; verify provider billing |
| Google | `gemini-2.5-flash-image` | 0 | 0.030 | $30/M output tokens equivalent seed |

### Cost Control Guidance

- Route routine tasks to lower-cost models and reserve flagship models for planning, critique, and high-value decisions.
- Use caching and result persistence, but measure savings on your own traffic rather than relying on universal percentage claims.
- Prefer local providers for privacy or predictable hardware costs when latency and hardware are acceptable.
- Put hard limits around image/video generation and external write tools.

---

## Compliance Posture

This guide no longer includes unbacked percentage claims for HIPAA, ISO, or SOC 2. Treat NV oOS as a configurable platform whose compliance posture depends on hosting, configuration, access controls, logging, retention, contracts, and your organization's review.

Current posture references:

- `docs/HIPAA_POSTURE.md`
- `docs/03-wp-org-compliance.md`
- `docs/WORDPRESS_ORG_COMPLIANCE_FINAL_STATUS.md`

Operational baseline:

1. Sanitize and validate all inputs.
2. Escape all outputs.
3. Enforce capabilities and nonces for privileged actions.
4. Protect provider credentials and assistant tokens.
5. Keep regulated workflows human-reviewed.
6. Document retention, deletion, and audit procedures.

---

## Troubleshooting

### Background job stuck at queued, Progress: 0/1

**Likely cause:** the host has `DISABLE_WP_CRON` enabled, blocks WP-Cron loopbacks, or delays loopbacks long enough that the first job tick never starts.

**What changed:** the inline-async-tick pattern lets selected jobs run the first tick during request shutdown. A cooperative lock prevents the inline kick and a delayed cron event from processing the same job concurrently. Several REST polling routes can self-heal stale queued jobs by scheduling a shutdown kick when the admin UI polls.

**Operator steps**

1. Confirm whether `DISABLE_WP_CRON` is set or loopbacks are blocked.
2. Poll the job status route once from the admin UI.
3. Check recent activity/error logs for inline-kick completion or failure events.
4. If the job remains queued, verify object-cache/transient behavior and hosting timeouts.
5. Review `docs/architecture/inline-async-tick-pattern.md` for subsystem-specific details.

### Provider returns no models or zero prices

1. Check provider credentials.
2. Run provider diagnostics when available.
3. Refresh model discovery.
4. For DigitalOcean, populate price fields from Gradient Platform billing because seed prices are zeroed.

### Chat SPA does not render

1. Confirm the Chat SPA addon is active.
2. Confirm the page uses `[nvoos_chat_spa]`.
3. Check whether `WP_MCP_AI_LEGACY_CHAT_JS` is forcing the older delivery path.
4. Inspect browser console and REST authentication errors.

---

## Best Practices

### 1. Start with a narrow assistant

Give each assistant the smallest useful tool set, then add tools after testing.

### 2. Test in the backend first

Use admin test screens before exposing assistants to public shortcodes, widgets, or external MCP clients.

### 3. Separate read and write flows

Read-only workflows can be broader. Write workflows should require capabilities, approval, and logging.

### 4. Use templates as starting points

Profession templates speed setup, but each production assistant still needs site-specific constraints and testing.

### 5. Content Quality

Require fact checks, source review, editorial approval, and accessibility checks for public content.

### 6. Cost Management

Use model routing, caching, token limits, and workload-specific budgets. Measure on your own traffic.

### 7. Pro Toolkit Selection

Enable only toolkits that map to active workflows. Scoped MCP endpoints are safer than exposing the whole registry.

### 8. Video & Media Workflows

Use explicit budgets, moderation, and human review for media generation. Keep generated assets traceable.

### 9. Compliance & Security

Keep regulated and irreversible actions human-approved. Store credentials in appropriate secret stores and audit token use.

---

## Next Steps

- Live tool inventory: inspect `WP_MCP_AI_Tool_Registry::get_tools()` on the target site.
- Fact sheet for this guide: `docs/getting-started/_USE_CASES_FACT_SHEET.md`.
- Orchestration reference: `docs/ORCHESTRATION_REFERENCE.md`.
- Inline async architecture: `docs/architecture/inline-async-tick-pattern.md`.
- SaaS Controller setup remains in `docs/SAAS_SETUP_GUIDE.md`.
- DigitalOcean provider docs: `docs/features/ai-providers/digitalocean.md`.
- Compliance posture: `docs/HIPAA_POSTURE.md`, `docs/03-wp-org-compliance.md`, `docs/WORDPRESS_ORG_COMPLIANCE_FINAL_STATUS.md`.

---

## Roadmap & Upcoming Toolkits

🚧 **In development:** the items in this appendix are not presented as GA use cases in this guide.

### AI Tool Builder

The AI Tool Builder was previously framed as a main use case. Rev 2.0 moves it here because the live settings page banner says **Coming Soon - Phase 2.9** and notes that tools and features are subject to change. The planned meta-toolkit is described as custom AI-tool creation support for scaffolding, code generation, testing, and documentation. When it graduates, update the fact sheet first, verify tool registration, then add a proper use case with current screenshots, permissions, and tests.

### Reserved / specialised Pro slots

The Pro settings-page inventory includes reserved or specialised verticals such as architectural design/drawing/project/specification, chat channels, DJ management, NV Cloud, and related modules. These should not be counted as GA SPA-manifested toolkits unless they have a manifest and shipped runtime surface.

---

## Document History

| Revision | Date | Notes |
|---|---|---|
| 2.0 | May 17, 2026 | Independent doc revision. Tested against plugin 1.1.18. Refreshed counts, added fact sheet, moved AI Tool Builder to roadmap, added Scheduled Results / Toolkit MCP Servers / Memory Mining / Skill Packs / Chat SPA / Docs Hub, rewrote costs from model catalog `2026.05.04`, removed unsupported compliance percentage claims, fixed stale links, regenerated TOC, and added inline-async troubleshooting. |
| 1.3.0 | Jan 31, 2026 | Superseded by 2.0 — counts and version references in that revision were already stale at publish. |
