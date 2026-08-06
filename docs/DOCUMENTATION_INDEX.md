# NV oOS Documentation Index

**Last Updated:** August 5, 2026  
**Plugin Version:** 1.1.46  
**MCP Version:** 2026-07-28

This document provides a comprehensive index of all documentation available for the Open Operator System (NV oOS) plugin.

**Total Documentation:** 1,600+ files across docs/, root, and archive directories


> **AUGUST 6, 2026 UPDATE (v1.1.46):** COMPREHENSIVE BACKUP & RESTORE (11 EXPORT PROVIDERS), GITHUB-BASED PLUGIN UPDATER (772 LINES), ABILITIES API SELECTIVE ADOPTION (includes/abilities/ FRAMEWORK, 5 CLASSES, 5 TEST FILES), STATUS PAGE FIXES (REST FATAL ERROR, JS, i18n), KNOWLEDGE BASE AUTO-BUILD CI, PHPCS CLEANUP (100+ FILES)
>
> **AUGUST 5, 2026 UPDATE (v1.1.45):** SELF-HOSTED OCR (UNLIMITED-OCR + DEEPSEEK-OCR, 17 FILES, +4,087 LINES), EMBEDDED ADDON v0.2.0 (VOICE, OPENMED, MCP ABILITIES), AI TRANSPARENCY & SGI COMPLIANCE, COMIC READER v0.2.0, GRAPHIFY STANDALONE PLUGINS v1.0.1, BUILD/RELEASE AUTOMATION
>
> **AUGUST 4, 2026 UPDATE (v1.1.44):** CCT STABILITY (4 PRs), API KEY RESOLUTION (2 PRs), PROPOSAL 016 ARCHITECTURE HARDENING (ALL WAVES), PROPOSAL 017 POLLING/QUEUE/LOAD-BALANCING, DEFERRED SECURITY #5755, DOCS (FOR_REVIEWERS v1.1.43, 16 BROKEN LINKS FIXED, GRAPHIFY ECOSYSTEM AUDIT)
>
> **AUGUST 1, 2026 UPDATE (v1.1.43):** MCP 2026-07-28 STATELESS CORE, SECURITY v1.1.43 HARDENING (SSRF/CSRF/SQL/XSS), OKF v0.2 TRUST-SIGNALS, ICP SYSTEM (PRO CRM PHASE G), PRO MODULE REGISTRY PSR-4, HEXAGONAL ARCHITECTURE PURITY, 7 PLAYBOOK/PROFESSION SYNC FIXES, PHASE 3 OPERATIONAL SECURITY, WPCS 3.4.1 (CVE-2026-45293)
>
> **JULY 29, 2026 UPDATE (v1.1.42):** SECURITY INFRASTRUCTURE (7 CLASSES), FRAMEWORK-AGNOSTIC CORE (nvoos/core), STATUS PAGE AND INCIDENT COMMUNICATION (PRO), 21 AGENT SKILLS + 6 BMAD AGENTS, ALGORAVE ADDON, SECURITY HARDENING (12 FIXES)
>
> **JULY 16, 2026 UPDATE (v1.1.40):** PHASE 8 PRO TOOLKIT MCP SERVERS (33 total, +4: Pro Scheduler, FlowHub, Shopify Sync, EZuite). OAUTH 2.0 MCP AUTHENTICATION (PKCE, hierarchical scopes, token management UI). PER-TOOLKIT MCP SETTINGS SLUG FIX. CONTENT FORMAT AWARENESS. RESEARCH→PAPER STORE→DRAFT PIPELINE. SETTINGS CREDENTIAL SPLIT. DEMO VIDEO PIPELINE. KIMI & DEEPSEEK PARITY. JULY 2026 MODEL CATALOG. OOS SCHEMASTORE + 45 TESTS. SSE HTTP/2 FIXES. SETTINGS IMPORT/EXPORT BATCH. CREDENTIAL BATCH FIXES.
>
> **JULY 10, 2026 UPDATE (v1.1.38):** PAGE AGENT ADDON v0.1.0, PRO SPA v2 MAJOR PARITY & POLISH, PER-USER CHAT MEMORY TOGGLE, CREATE_POST/SAVE_POST MARKDOWN-TO-HTML + TAXONOMY SUGGESTIONS, WORKFLOW BLUEPRINT EXISTING-CONTENT AWARENESS, SPA ACCESSIBILITY, ZAP TRIAGE
>
> **JULY 8, 2026 UPDATE (v1.1.37):** JETENGINE META HELPER UNIVERSAL, PLACES ENRICHMENT, RABBITMQ + QUEUE INFRASTRUCTURE, MULTI-TENANT DB ISOLATION PHASE 0-4, DSPARK ADMIN UI, CROCOBLOCK DS ADDON, TEST COVERAGE (329 TOOLS / 28 TOOLKITS), DOCS HUB BROKEN LINK ENGINE, OWASP ZAP DAST, 30+ BUG FIXES
>
> **JULY 4, 2026 UPDATE (v1.1.36):** EZUITE INVENTORY SYNC, RALPH LOOP ORCHESTRATION, JETBOOKING/JETAPPOINTMENT, MOONSHOT/Z.AI PARITY, SYNC LOG MANAGER, TOOL PRESETS AUTO-SELECT, HTTRACK CACHE, 45+ BUG FIXES
>
> **JUNE 29, 2026 UPDATE (v1.1.35):** FLOWHUB INVENTORY SYNC, SHOPIFY SYNC, NECESSITY GATE LAYER J, LOCAL VOICE STT, REMOTE SITE ADMINISTRATOR BLUEPRINT, BULK IMPORT TOOLS, FIXES
> - **FlowHub Inventory Sync Pro Toolkit (PR #5501)** — 6-tool cannabis dispensary management: products, inventory, locations, sync, analytics, alerts. P1 proxy support + P2 CCT auto-registration (PR #5502). Auth, decryption, location_id, null-guard fixes (PRs #5500, #5503, #5507, #5510).
> - **Shopify Sync Pro Toolkit (PR #5502)** — 5-tool bi-directional sync: products, orders, inventory, analytics, settings. Dashboard widget. Tool reference docs.
> - **Necessity Gate Layer J** — irreversibility-weighted safety profiles scoring tool calls by risk before execution. Request context crash fix.
> - **Local Voice Embedded STT (PR #5498)** — three pluggable browser-side STT backends (Web Speech, Whisper.cpp WASM, Vosk WASM). Offline-first.
> - **Remote Site Administrator Blueprint** — 22-tool assistant blueprint for full remote/local WP/WooCommerce site management with JetEngine, JetFormBuilder, and REST API control.
> - **Places & Calendar Bulk Import (PR #5509)** — batch import tools for Places and Calendar Booking toolkits.
> - **CLI site-import** — multi-phase HTML mirror import subcommand for migrating static sites.
> - **Voice Realtime Auto-Detect (PR #5508)** — WebRTC/WebSocket auto-selection, duplicate message fix, VAD improvements.
> - **Remote Connections Fixes (PR #5499)** — WordPress case handling, FlowHub/Printful credential storage, Printful connection type.
> - **Bug Fixes** — token-scoped assistant resolution (PR #5497), user_id empty fallback (PR #5495), credential token mapping (PR #5493), post type name lengths (PR #5484), OpenAI image cleanup (PRs #5489–#5491).
> - **Agent Context Sync** — AGENTS.md, CLAUDE.md, .context/pro-vs-base.md updated for v1.1.35.
> - **Versioning** — bumped to 1.1.35 placeholder.
>
> **JUNE 27, 2026 UPDATE (v1.1.34):** GPT-REALTIME-2 VOICE MODELS, MULTI-CHANNEL RESULT DELIVERY UI, GRAPHIFY ECOSYSTEM, BUG FIXES
> - **GPT-Realtime-2 Voice Models (PR #5479)** - GA Realtime API, WebRTC transport, Translate + Whisper models, 128K context, reasoning effort. wait_for_user tool.
> - **Multi-Channel Result Delivery UI (PR #5465)** - Telegram, Discord, WhatsApp, Google Chat in schedule modal.
> - **Pro Scheduler AI Delivery (PR #5466)** - AI responses routed through scheduler delivery pipeline.
> - **Graphify Ecosystem (PRs #5475-#5480)** - Remote drivers with Bridge class. WP 7.0 Connectors. wp.org compliance.
> - **3 Reasoning-Tool Fatal Bugs Fixed** - success() -> format_chat_response(). Plus trim() on array and count() on null guards.
> - **CRM Pipeline (PRs #5469, #5474, #5473)** - Deal import/Gmail fix. Multi-source auto-import. Upwork/LinkedIn toggle.
> - **Security (PRs #5464, #5463, #5461)** - CVE-2026-55602, Gemini cache, GPT image routing.
> - **Bug Fixes (PRs #5472, #5470, #5468)** - Docs Hub REST/permalink. nv-cloud-init guard. Docs Hub sync.
> - **Documentation** - GPT-Realtime-2 proposal + plan. FastAPI porting plan (PR #5467).
> - **Versioning** - bumped to 1.1.34.
>
> **JULY 16, 2026 UPDATE (v1.1.40):**
> - **WP 7.0 Connectors Credential Integration (PR #5458)** — Credential_Resolver integrated into all 17 AI client get_api_key() methods. Fallback chain: WP 7.0 Connectors → plugin settings → env vars → PHP constants. get_key_source() / get_key_source_label() added. Credential source badges and WP 7.0 Connectors hints rendered in admin settings UI. All 13 provider API key field descriptions updated. Provider diagnostics show key source column. Settings health check counts credentials via resolver. 17 Pro addon files updated to use Credential_Resolver::has_credentials() / get_api_key().
> - **nvoos-graphify v1.0.0 Release (PR #5456)** — Standalone nvoos-graphify plugin released at v1.0.0 (Plugin Check compliant). nvoos-graphify-ai released at v1.0.0-dev. Fixed 8 output-escaping errors, critical prepare() spread-operator bug, vector → embedding_vector column rename (MariaDB/MySQL conflict), snake_case→camelCase method calls across tools/controllers/cross-plugin integrations. Documented REST access model: read + guest token for reads, manage_options for export/write.
> - **Security Dependencies (PR #5457)** — guzzlehttp/guzzle 7.10.0 → 7.12.1 (CVE-2026-55568, CVE-2026-55767). guzzlehttp/psr7 2.11.0 → 2.12.1 (CVE-2026-55766). guzzlehttp/promises 2.3.0 → 2.5.0. undici npm override >=7.28.0 → >=8.5.0.
> - **npm Security (PR #5438)** — 29 npm alerts resolved across 14 packages: undici (CVE-2026-9697, CVE-2026-9678), http-proxy-middleware (CVE-2026-55603), nodemailer (GHSA-p6gq-j5cr-w38f), webpack-dev-server (CVE-2026-9595), dompurify (GHSA-cmwh-pvxp-8882). Fixed critical duplicate overrides key in root package.json.
> - **Bug Fix — WP All Import/Export Pro Tool Paths** — Fixed four require_once paths in the Pro bootstrap missing -pro- in filenames, causing fatal errors when those tools loaded.
> - **Bug Fix — Tool Status Label Loader** — Replaced @file_get_contents() with set_error_handler('__return_true') in both copies of load_tool_status_labels() to prevent leaked warnings from corrupting MCP JSON-RPC responses.
> - **Dependencies** — 15 Dependabot bumps across Composer, npm (stripe, zod, p-queue, csv-parse, react-query, puppeteer, vitest, wrangler, eslint-plugin, workers-types, types/node), and GitHub Actions (codecov 4→7, action-gh-release 2→3).
> - **Housekeeping** — Stale 1.1.31 and 1.1.32 build zips removed (PR #5437). SPA addon ZIPs rebuilt.
> - **Versioning** — bumped to 1.1.33 across all manifests. Tool count: ~195 base + ~795 Pro (~990 total; live registry is authoritative).
>
> **📌 JUNE 19, 2026 UPDATE (v1.1.32):** 📝📬⏱️🔧 **CONTENT FORMAT TEMPLATES, RESULT DELIVERY PIPELINE, FEATURED IMAGE SERVICE, PROVIDER TIMEOUT FIXES, SCHEDULE TRIGGER STABILITY**
> - **Content Format Templates & Featured Image Generation (PR #5433)** — Content Format Template CPT (`mcp_ai_content_format_template`). `WP_MCP_AI_Content_Template_Engine` with Anthropic-optimised AI prompts. `WP_MCP_AI_Featured_Image_Service` with 3-provider fallback (DALL-E/Gemini/Cloudflare) and 5 image styles. Image Generation Provider dropdown on CPT metabox. Provider image settings respected instead of hardcoded `dall-e-3`/`hd`. 5 workflow presets updated with image gen nodes. `resolve_node_template_variables` for cross-node variable passthrough. 5 default templates seeded on activation.
> - **Result Delivery Pipeline (PR #5425)** — `WP_MCP_AI_Result_Delivery_Service` (1,056 lines) routes schedule results to 8 channels (email, Slack, Discord, Telegram, SMS, Paper Store, WordPress post, webhook). Both success and failure paths deliver. Pre-configured for 8 presets.
> - **ECA Document Generation (PR #5423)** — ECA Consolidate & Add page with document generation tools.
> - **Duplicate Posts & Provider Image Fixes (PR #5434)** — WordPress delivery removed from blog presets to prevent duplicates. Systemic guard skips WordPress delivery when AI tool calls include `create_post`/`save_post`.
> - **6 Provider Clients Timeout Fix (PR #5431)** — DeepSeek, Baseten, DigitalOcean, OpenRouter, Kimi, Cloudflare now respect global `request_timeout` setting instead of hardcoding 60s.
> - **Schedule Trigger Stability (PRs #5429, #5430)** — Trigger crash fixed. Save persistence restored for delivery channels and display fields.
> - **Paper Store Delete Fix (PR #5432)** — Hidden POST inputs added to delete confirmation form.
> - **ECA Settings & Attachment Fix (PR #5421)** — Menu placement and upload crash resolved.
> - **npm CI & Jest Resilience (PR #5428)** — Babel ESM override for Node 18/20. Jest graceful fallback. npm ci lockfile sync for 5 addons.
> - **Dependencies** — 14 safe Dependabot bumps. 8 npm audit CVEs. phpspreadsheet 5.7.0→5.8.0.
> - **Versioning** — bumped to 1.1.32 across all manifests. Tool count: ~195 base + ~795 Pro (~990 total; live registry is authoritative).
>
> **📌 JUNE 12, 2026 UPDATE (v1.1.29):** ⚡🧠🥧🛡️ **PRO TOOLKIT OPTIMIZATIONS, CHAT TRANSCRIPT & MEMORY RETENTION, DIETPI PRO TOOLKIT, LAYER I GUARDRAILS, CONTEXT WINDOW MANAGEMENT, LIBRECHAT, SCHEDULE ANYTHING SAAS, VECTOR SEARCH, CRM ENHANCEMENTS**
> - **Pro Toolkit Optimizations Phase 1–3** — Autoload control, query caching, and lazy loading across 6 Pro toolkits (Chat Channels, Social Media, Healthcare, Ecommerce, Calendar/Orchestration, Document Generation). Full doc: [`docs/features/pro-toolkit-optimization.md`](features/pro-toolkit-optimization.md).
> - **Chat Transcript & Memory Retention** — `WP_MCP_AI_Transcript_Retention` (Base, 437 lines) and `WP_MCP_AI_Memory_Retention` (Pro, 358 lines) with configurable TTL-based cleanup. Full doc: [`docs/features/chat-transcript-retention.md`](features/chat-transcript-retention.md).
> - **DietPi Pro Toolkit Phases 0–3** — 19+ tools for DietPi server management (system info, backup, provisioning, SSH proxy). MCP server integration. Full doc: [`docs/features/dietpi-pro-toolkit.md`](features/dietpi-pro-toolkit.md).
> - **Layer I Guardrails — Jailbreak Prevention** — Pre-provider jailbreak detection and capability boundary enforcement. Full doc: [`docs/features/layer-i-guardrails.md`](features/layer-i-guardrails.md).
> - **Context Window Management** — Pre-flight validation across all 13 providers via shared `validate_context_window()` helper with tiktoken integration, estimator metabox, and token-budget tool capping. Full doc: [`docs/features/context-window-management.md`](features/context-window-management.md).
> - **LibreChat Addon** — Code interpreter, speech services, and web search reranker.
> - **Schedule Anything SaaS Platform** — Full SaaS booking platform with Stripe integration.
> - **Vector Search Enhancements** — HNSW index, content/context embedding stores, hybrid semantic search.
> - **CRM Enhancements** — Email import with scheduled polling, lead pruning, inline tag editing, email priority/exclude actions.
> - **OAuth/API Disconnect Buttons** — One-click disconnect with automatic token clearing.
> - **Bug Fixes** — 25+ fixes: CPT slug length limits, agentic loop tool persistence, slider/range rendering, well-known endpoints, tool-status guard, docs hub rebuild stalls, chat addon endpoints, provider validation, playbook orphans, and more.
> - **Security** — guzzlehttp/psr7 CVE-2026-49214, shell-quote CVE-2026-9277, dependabot alerts #211–#213.
> - **Versioning** — bumped to 1.1.29 across all manifests. Tool count: ~195 base + ~795 Pro (~990 total; live registry is authoritative).
> - **Real-Time SSE Streaming** — Enabled for OpenAI, DeepSeek, and all OpenAI-compatible providers. "Disable Native Streaming" toggle in Advanced → System tab. `wp_mcp_ai_disable_native_streaming` filter.
> - **35 New OOS Core Tools** — Data tools (GetPostTaxonomies, CountPosts, GetPostMeta, TruncateText, MergeArrays), format tools (FormatDate, TimeAgo, ParseCsv, MathEval, ColorConvert), infrastructure (EventDispatcher, Queue), cache tools, and OOS/core test infrastructure.
> - **Extended Cognition Vision Recognition** — Visual product/brand recognition. Camera viewfinder UI with detection overlays, consent gate.
> - **Graphify Tools Capability Fence** — Missing `WP_MCP_AI_Tool_Default_Capability` trait and `get_required_capability()` added to all Graphify tools.
> - **JetFormBuilder Submission Tools — 8 Fixes** — Empty results for non-admin users, form discovery pipeline, PHPCS warnings, REST route matching, form-type auto-detection, plugin detection. Integration reference docs added.
> - **DeepSeek Agentic Tool Handling** — Tool message filtering and payload normalisation for agentic workflows.
> - **Documentation Link Fixes** — Broken links after Unix-theory reorganization resolved.
> - **June 2026 Model Pricing** — All 13 provider pricing updated.
> - **Plugin Restructuring Proposals v3.0** — Graphify-centric architecture spec and roadmap.
> - **Versioning** — bumped to 1.1.27 across all manifests.
>
> **📌 MAY 31, 2026 UPDATE (v1.1.25):** 🧩☁️🏢💬📂 **UNIFIED BLUEPRINT SYSTEM, CLOUDWAYS TOOLKIT, CRM TOOLKIT (PHASES A–E), CHAT UI ENHANCEMENTS, UNIX-THEORY REORG PHASE 4–5**
> - **Unified Blueprint System** — 55 pre-built AI assistants across 25 toolkits with one-click import. Shared `WP_MCP_AI_Blueprint_Installer`. JSON Schema validation. Healthcare blueprint import tool with HIPAA-aware templates. 4 Aerlinn blueprints. Full doc: [`docs/features/unified-blueprint-system.md`](features/unified-blueprint-system.md).
> - **Cloudways Pro Toolkit** — 60 AI tools for server/app management via Cloudways API v2. `WP_MCP_AI_Cloudways_Client` OAuth singleton. Tool categories: Server Mgmt, App Mgmt, Security, Backups, Team, DNS. Admin dashboard with real-time status. Full doc: [`docs/features/cloudways-toolkit.md`](features/cloudways-toolkit.md).
> - **CRM Toolkit Phases A–E Complete** — 70+ tools: Lead Management (A), Multi-channel Triage (B), Integration Hooks (C), Extensibility Hooks (D), Compliance (E). Command Center, per-CPT research pages, analytics dashboard, REST API at `/mcp-ai-pro/v1/crm/`. Full doc: [`docs/features/crm-toolkit.md`](features/crm-toolkit.md).
> - **Chat UI Enhancements** — 7 features: profile card, stop generation, feedback widget, code copy, dark mode, saved prompts, prompt search. Base + Pro support. Full doc: [`docs/features/chat-ui-enhancements.md`](features/chat-ui-enhancements.md).
> - **Unix-Theory Reorg Phase 4–5** — Pro tools reorganised into modular Unix-theory folders. Stale `require_once` paths + hardcoded paths fixed.
> - **Pro Toolkit MCP Server Settings** — Phases A–C admin page.
> - **Build Infrastructure** — `workflow_dispatch` commits, `build/.gitkeep` restored, WSL auto-detection.
> - **Versioning** — bumped to 1.1.25 across all manifests. Tool count: ~195 base + ~765 Pro (~960 total; live registry is authoritative).

> **📌 MAY 21, 2026 UPDATE (v1.1.21):** 🛡️🔒🧠⚡ **WP.ORG COMPLIANCE COMPLETE, CANONICAL RETURN ENVELOPE, SEMANTIC COMPRESSION, AI PROMPT CACHING, MEMORY LAYER PHASES 3–7**
> - **WordPress.org Compliance — All 10 Findings (F1–F10) Resolved.** 53 files converted from inline scripts/styles to WP enqueue APIs; 11 PHP parse errors fixed. Re-audit verified: zero dangerous functions, all superglobals sanitized, all HTTP calls timed out, zero bare phpcs:ignore. Full evidence: [`docs/compliance/WORDPRESS_ORG_COMPLIANCE_2026_05_19.md`](operations/compliance/WORDPRESS_ORG_COMPLIANCE_2026_05_19.md).
> - **Canonical Return Envelope — Unix Theory P0/P1 Complete.** 191 non-canonical returns → `WP_Error` across 105 files. `WPMCPAI.Tools.CanonicalReturnEnvelope` + `SanitizeAtEntry` sniffs clean.
> - **Semantic Caveman Compression** — `WP_MCP_AI_Semantic_Compressor` (1,988+ lines + 1,156 test lines). Opt-in prompt token optimization. Settings in Orchestration tab.
> - **AI Prompt Caching** — `WP_MCP_AI_Chat_Response_Cache` + `WP_MCP_AI_Prompt_Optimizer` across all 5 providers. Cache Performance dashboard.
> - **Memory Layer 2026 Phases 3–7** — Auto-capture (P3), RRF fusion (P4), confidence decay (P5), provenance tracer (P6), Memory Health + Retrieval Waterfall + Session Replay (P7). CCT migrator disabled by default.
> - **Model Catalog May 2026** — DeepSeek V4, Gemini consolidation, pricing refresh.
> - **@wordpress/env** dev dependency.
> - **Domain migration** — nvoos.com → nvoos.pro / nvoos.cloud.
> - **Versioning** — bumped to 1.1.21 across all manifests.

> **📌 MAY 18, 2026 UPDATE (v1.1.20):** 🧠🎛️ **MEMORY LAYER 2026 PHASE 7 UI/UX COMPLETE**
> - **Phase 7a — Memory Health subtab** — Orchestration Memory Health view with live status, threshold policy, and chat-memory availability.
> - **Phase 7b — Retrieval Waterfall panel** — Memory Drawer retrieval-path visibility (RRF + legacy breakdown + path metadata).
> - **Phase 7c — Session Replay tab + route** — `GET /mcp-ai/v1/chat-memory/sessions/{session_id}` endpoint + drawer integration.
> - **Coverage** — REST + JS tests for session replay route, service, and drawer behavior.
> - **Versioning** — bumped to 1.1.20 across all manifests.

> **📌 MAY 17, 2026 DOCUMENTATION REFRESH:** `docs/getting-started/USE_CASES_AND_QUICKSTARTS.md` was refreshed to independent doc revision 2.0 and now cites `docs/getting-started/_USE_CASES_FACT_SHEET.md` as the companion source for point-in-time counts. Current public framing is ~830 tools (~195 base + ~635 Pro), ~190 profession templates, 10 GA SPA-manifested Pro toolkits, and model catalog `2026.05.04`; the live registry remains authoritative.

> **📌 MAY 18, 2026 UPDATE (v1.1.19):** 📡🔒🧩💬 **KIMI PROVIDER, ACP SERVER, MCP BRIDGE, P7 FOLDER READMES, SECURITY HARDENING, CHAT BUBBLE SWEEP**
> - **Kimi (Moonshot AI) provider** — 10th first-class language-model provider. `WP_MCP_AI_Kimi_Client` at `https://api.moonshot.cn/v1`. Models: kimi-k2.6 (256K, default), kimi-k2-thinking (CoT), moonshot-v1-*. Settings → Providers → Kimi subtab.
> - **ACP Server** — Full Agent Client Protocol implementation. `WP_MCP_AI_ACP_Server` + `WP_MCP_AI_ACP_JSONRPC_Dispatcher` + `WP_MCP_AI_ACP_Session_Manager` + `WP_MCP_AI_ACP_Session_Bridge` + `WP_MCP_AI_ACP_Transport_HTTP`. JSON-RPC 2.0 over HTTP/SSE. `/.well-known/ai-peer` extended. `enable_acp_server` + `acp_require_approval` toggles in Orchestration → Settings. PHPUnit coverage in `tests/acp/`. Reference: [`docs/features/acp-server.md`](features/acp-server.md).
> - **MCP Bridge** — `bin/mcp-bridge.js` stdio-to-HTTP relay for Claude Desktop, Cursor, Zed.
> - **Unix Theory P7** — Folder README convention complete. Every `includes/` subdir ships a `README.md`. Enforced by `composer run docs:check-folder-readmes`. Convention: [`docs/guides/developer/folder-readme-convention.md`](developer/folder-readme-convention.md).
> - **GDPR** — JetEngine privacy exporters for CCT data (transcripts, memory, approvals).
> - **Security hardening** — Settings-key encryption + admin-UI masking; webhook secret enforcement; SSRF via `wp_safe_remote_get`; attachment URL scheme allowlist; client-log debug-gate.
> - **Chat Bubble sweep (13 PRs)** — Self-init via `wpMcpAiChatInit`; `kses_chat_output()` preserves form controls; Test Model fully restored; panel-fit + scoped CSS.
> - **Docs** — `USE_CASES_AND_QUICKSTARTS.md` Rev 2.0; `_USE_CASES_FACT_SHEET.md` as source of truth.
> - **Versioning** — bumped to 1.1.19 across `mcp-ai-wpoos.php`, `constants.php`, `package.json`, `package-lock.json`, `readme.txt`, `CHANGELOG.md`, `README.md`.

> **📌 MAY 14, 2026 UPDATE (v1.1.18):** 🧠⚙️📡🛡️ **UNIX THEORY P0–P6, DIGITALOCEAN PROVIDER, ASYNC CHAT CONTINUATION, JOBS/TASKS DRAWER, TOOLKIT MCP SERVERS PHASE 7**
> - **Unix Theory Compliance Phases P0–P6** — canonical return envelope + `WPMCPAI.Tools.CanonicalReturnEnvelope` PHPCS sniff (P0/P1); capability-fence audit (P2); `WP_MCP_AI_Tool_Data_Contract_Interface` (P3); tool-lifecycle descriptor 5th arg + OTel `nvoos.tool.data_type` / `duration_ms` (P4); back-compat alias infrastructure on the tool registry (P5); `WPMCPAI.Tools.SanitizeAtEntry` PHPCS sniff (P6). Master proposal: [`docs/proposals/UNIX_THEORY_COMPLIANCE_ENHANCEMENT_PROPOSAL.md`](project/proposals/UNIX_THEORY_COMPLIANCE_ENHANCEMENT_PROPOSAL.md). Codification doc: [`docs/proposals/audits/P6-sanitize-escape-codification-2026-05.md`](project/proposals/audits/P6-sanitize-escape-codification-2026-05.md).
> - **DigitalOcean Serverless Inference provider** (9th provider) — `WP_MCP_AI_DigitalOcean_Client` + OpenAI-compatible chat / tool calls / streaming / embeddings; default embedding model `gte-large-en-v1.5`; Provider Diagnostics card. Reference: [`docs/features/ai-providers/digitalocean.md`](features/ai-providers/digitalocean.md).
> - **Async chat continuation** (slices 1–6) — durable store, dispatcher, LLM re-entry, SSE frame buffer, Pro webhook notifier, OTel hooks + Jest tests. Plan: [`docs/features/chat/async-continuation.md`](features/chat/async-continuation.md).
> - **Jobs/Tasks Drawer + cron-status** (PRs A–G) — inline job-progress card subscribing to `wpMcpAiJobBus`; `POST /mcp-ai/v1/cron-status/{job_id}/cancel|retry` routes; Tasks Drawer + toasts (default-on via `wp_mcp_ai_chat_tasks_drawer` filter); 5 OTel hooks → `nvoos.chat.jobs.*` spans. Docs: [`docs/features/chat/cron-status-integration.md`](features/chat/cron-status-integration.md), [`docs/guides/developer/tool-development/registering-a-job-source.md`](developer/tool-development/registering-a-job-source.md).
> - **Toolkit MCP Servers Phase 7 admin UI** — `WP_MCP_AI_Pro_Toolkit_MCP_Servers_Page` (slug `nvoos-pro-toolkit-mcp-servers`) — 5-tab admin page (Servers / Detail / Audit / Discovery / Help).
> - **JetEngine CCT memory mirror** — `retrieve_agent_memory` + `recall` hydrate from the CCT mirror when available.
> - **Security / maintenance** — langsmith `>=0.6.0` (GHSA-3644-q5cj-c5c7); `wp_read_video_metadata` guard in Veo/Sora tools; production autoload restoration.
> - **Agent surfaces refreshed** — `.bmad/`, `.codex/`, `.context/`, `.devcontainer/`, `.github/agents/`, `.vscode/`, `.zed/` all reviewed and brought in lockstep. `toolkit-spa-maintainer` now mirrored from `examples/agents/` into both `.github/agents/` and `.zed/settings.json` (13 profiles).
> - **UI/UX Pro Max Skill Bundle** — New bundled Agent Skill `ui-ux-pro-max` with comprehensive UI/UX Design Pack. Includes 25+ CSV data files covering color palettes, typography scales (including Google Fonts), icon libraries, UI components, design patterns, and stack-specific guidelines for React, Vue, Angular, Laravel, Astro, Svelte, Flutter, SwiftUI, Jetpack Compose, and more. Python utility scripts for design system search and core operations. New `ui-ux-design` skill pack. See `includes/bundled-skills/ui-ux-pro-max/`.
> - **Versioning** — bumped to 1.1.18 across `mcp-ai-wpoos.php`, `constants.php`, `package.json`, `package-lock.json`, `readme.txt`, `CHANGELOG.md`, `README.md`.

> **📌 MAY 10, 2026 UPDATE (v1.1.17):** 🛡️💬📚🧪 **WP.ORG COMPLIANCE, CHAT SPA PHASES 1–7, DOCS HUB v0.3.8, TOOLKIT SPA PHASES 5–12, COVERAGE CAMPAIGN**
> - **WP.org Compliance Hardening** (PRs #4892, #4902) — B1/B2/B3/B5/B8/B10/B11/B12/B13 reviewer findings resolved. `WP_MCP_AI_User_Context_Helper` centralises privileged-op hardening. Full evidence: [`docs/compliance/WORDPRESS_ORG_COMPLIANCE_2026_05_09.md`](operations/compliance/WORDPRESS_ORG_COMPLIANCE_2026_05_09.md). Reviewer response table: [`SUBMISSION.md`](../SUBMISSION.md).
> - **Build-pipeline split** — `--wp-org` flag in `bin/build-plugin-zip.sh` produces a WP.org-submission ZIP; full GitHub Release ZIP built separately. `addons/` + `.zed` + root `*.md` excluded from the WP.org artifact.
> - **Chat SPA addon** (`addons/chat-spa/`) — all 7 phases complete (v0.6.0): SSE adapter, tool-call cards, transcripts sidebar, memory drawer, HITL approval bar, file attachments + regenerate, `WP_MCP_AI_LEGACY_CHAT_JS` opt-out constant.
> - **Docs Hub addon** (`addons/docs-hub/`) — evolved from v0.1.0 to v0.3.8: remote-first defaults, tree-picker UX, chunked rebuild + CLI, a11y hardening, syntax highlighting (rehype-highlight), `NV_oOS_Docs_Hub_Sitemap_Provider`, PageFooter, SSRF hardening.
> - **Toolkit SPA Blueprint Phases 5–12** — a11y, i18n, PHPUnit tests, bundle-size CI, scaffolder auto-patch, 10 Tier-A manifests complete; canvas-toolkit v0.2.0 (whiteboard/bpmn/mermaid), document-editor v0.2.0 (GrapesJS), media-studio Phase 4.
> - **PHPUnit + Vitest coverage campaign** (PRs #1–#11) — 271 AJAX handlers covered; PHPUnit baseline + non-regression CI gate; Vitest scaffolding for all 6 SPA addons.
> - **Dependabot security sweep** — 33 alerts resolved across all npm manifests.
> - **Versioning** — bumped to 1.1.17 across `mcp-ai-wpoos.php`, `constants.php`, `package.json`, `package-lock.json`, `readme.txt`, `CHANGELOG.md`, `README.md`.

> **📌 MAY 6, 2026 UPDATE (v1.1.16):** 🛠️ **SAAS CONTROLLER ADDON (v0.1.0) + STRUCTURED LOGGING INTEGRATION**
> - **SaaS Controller Addon** (`addons/saas-controller/`, v0.1.0) — operator-side WordPress admin toolkit for provisioning and managing the NV oOS Cloud control plane. All 11 phases shipped. New reference doc: [`docs/saas-controller.md`](operations/deployment/saas-controller.md).
> - **Structured Logging Integration** (PR #4849) — `WP_MCP_AI_Logger` integrated across `WP_MCP_AI_Agent_Memory_CCT_Bridge`, `WP_MCP_AI_Transcript_Mining_Job`, Algorave, Canvas, Webchat, Fantasy Football, Graphify, SaaS Controller addons, and core admin/service components. New test classes: `test-agent-memory-cct-bridge-logging.php`, `test-transcript-mining-job-logging.php`.
> - **Versioning** — bumped to 1.1.16 across `mcp-ai-wpoos.php`, `constants.php`, `package.json`, `package-lock.json`, `readme.txt`, `CHANGELOG.md`, `README.md`.

> **📌 MAY 5, 2026 UPDATE (v1.1.15):** 🔌 **NEW PROVIDERS (OPENROUTER + DEEPSEEK), ORCHESTRATION PHASES 1–7, LLM HARNESSING GA, OBSERVABILITY UI, GRAPHIFY DATA-SOURCE BRIDGE**
> - **New AI providers** — OpenRouter (`WP_MCP_AI_OpenRouter_Client`), DeepSeek (`WP_MCP_AI_DeepSeek_Client`), Kimi K2.6, Qwen 3.6 added. LM Studio gains native cURL SSE streaming.
> - **Orchestration Phases 1–7 re-landed** — HITL approval queue, prompt-injection guardrail (Layer I), structured output, OTel span exporter, DAG builder, durable runs, triggers/webhooks, sub-agents, Pro vector-store adapter, Pro team budget manager. All JetEngine CCT bootstraps now on `init` priority 11+.
> - **Observability UI** — Observability dashboard under **Orchestration** tab; OTLP endpoint + token configurable in **Tools → Connections**.
> - **LLM Harnessing Subsystem (Layers A–H)** ships GA — seven opt-in epistemic layers. Reference: [`docs/llm-harness.md`](features/llm-harness.md).
> - **Chat-client Memory Bridge G-series** — complete with site-wide admin toggle in **Orchestration → Settings**.
> - **Retroactive Transcript Mining** — three stuck-job root causes fixed (PRs #4804 #4826). Troubleshooting guide added to [`docs/features/memory/transcript-mining.md`](features/memory/transcript-mining.md).
> - **Graphify NV oOS data-source bridge** — private CPTs, CCT resolvers, MemPalace edges, external `$wpdb` tables. New **Sources (CPT / CCT)** tab on Knowledge Graph settings page.
> - **Stability sweep** — workflow-not-found, JetEngine CCT prefix, credential nonces, multi-agent dashboard TypeError, site-health polyfill, README TOC anchors.
> - **Versioning** — bumped to 1.1.15 across `mcp-ai-wpoos.php`, `constants.php`, `package.json`, `package-lock.json`, `readme.txt`, `CHANGELOG.md`, `README.md`, `docs/QUICK_REFERENCE.md`, `docs/orchestration-reference.md`.
> - **April 2026 Security Audit Summary** (v1.1.10) — New [`docs/compliance/SECURITY_AUDIT_2026_04.md`](operations/compliance/SECURITY_AUDIT_2026_04.md) publishes the consolidated summary of the nine audit deliverables under [`docs/audit/2026-04/`](project/audits/2026-04/). Headline verdict: no Critical findings; 5 High (3 Fixed, 2 Partially Fixed); 14 Medium (all Fixed); 21 Low (14 closed); 10 Informational. Standards: WP Plugin Handbook, WP.org Plugin Directory Guidelines, OWASP Top 10 / API Top 10, WPCS 3.3, PHPCompatibilityWP, GDPR/CCPA, MCP/SSE.
> - **Production-Ready Vendor Autoload** (v1.1.10) — `vendor/` regenerated with `composer install --no-dev --classmap-authoritative`; plugin is deployable from a clean clone (PR #4733).
> - **Veo 3.1 `generate_veo_video` Fix** (v1.1.10) — `seed` parameter now sent only to Veo 2.0; Veo 3.1 rejects it (PR #4735).
> - **README.md / CHANGELOG.md / readme.txt / QUICK_REFERENCE.md** — Updated to v1.1.10.

> **📌 APRIL 25, 2026 UPDATE:** 📊 **MEASUREMENT SUBSYSTEM GA, PHPUNIT 11 UPGRADE (CVE FIX), CHART.JS HANDLE NORMALIZATION, GRAPHIFY RESTORE**
> - **Measurement Subsystem GA** (v1.1.9) — 12 sequenced PRs delivered the full measurement / evals / reward stack: stock metrics (tool-execution, chat-loop, agentic-loop, SSE), persistent metric event store with `wp_mcp_ai_metric_retention_days` filter, eval harness with verifier-independence enforcement, Pro rubric presets + counterfactual runner, OTel JSON exporter, budget envelopes, Measurement dashboard under **Tools → Measurement**, and `wp mcp-ai measurement run|alert-check|list-runs` WP-CLI runner with regression-aware exit codes. New stock metrics `eval.suite.pass_rate` (gauge) and `eval.suite.regression.count` (counter).
> - **PHPUnit 11 Upgrade** 🔒 (v1.1.9) — PHPUnit upgraded to 11.x with WordPress-compat patches to resolve argument-injection CVE **GHSA-qrr6-mg7r-m243**. CI PHP bumped 8.1 → 8.2. `@dataProvider` docblocks migrated to attributes.
> - **Chart.js Handle Normalization** (v1.1.9) — All admin dashboards (ECA, Schedule Manager, Agent Command Center, Measurement) now enqueue a single `wp-mcp-ai-chartjs` handle.
> - **Graphify Knowledge Graph Addon v0.5.0** (v1.1.9) — Optional WordPress Knowledge Graph addon restored under `addons/graphify/`.
> - **New Doc**: [`docs/ORCHESTRATION_REFERENCE.md`](reference/orchestration/ORCHESTRATION_REFERENCE.md) and full [`docs/measurement/`](measurement/) reference set (README, rollout-plan, goodhart-checklist, verifier/reward authoring, eval-harness, persistent-store, dashboard, SSE/stream, tool-execution, chat-turn, budgets, OTel exporter, privacy-matrix, conventions).
> - **README.md / CHANGELOG.md / readme.txt / QUICK_REFERENCE.md** — Updated to v1.1.9 with a new Latest Updates section.

> **📌 APRIL 16, 2026 UPDATE:** 🧠 **PSO ADAPTIVE OPTIMIZATION & ORCHESTRATION REFERENCE**
> - **PSO Optimizer Service** (v1.2.0) — Particle Swarm Optimization for adaptive AI parameter tuning. 7-dimension search space, inertia decay, per-assistant particle state, global swarm convergence. New `pso_adaptive` workflow preset.
> - **Comprehensive Orchestration Reference** (NEW) — [`docs/ORCHESTRATION_REFERENCE.md`](reference/orchestration/ORCHESTRATION_REFERENCE.md) — Single authoritative reference for the entire orchestration layer: all 10 workflow presets, all 13 resource presets (with full settings comparison matrices), PSO algorithm documentation, tool execution orchestrator, load balancer, reasoning controller, multi-agent system, health monitoring, budget enforcement, hooks/filters, data storage keys, admin UI, and service file index.

> **📌 APRIL 15, 2026 UPDATE:** 📋 **ERLANG C TOOLS & FULL TOOL-REFERENCE AUDIT**
> - **Erlang C Queuing Theory Tools** (v1.1.8) — 4 new workforce-management tools: `calculate_erlang_c`, `erlang_c_concurrency_advisor`, `erlang_c_staffing_advisor`, `erlang_c_queue_health`. New `wp_mcp_ai_queue_alert` action hook for SLA breach notifications.
> - **New Feature Doc**: [features/erlang-c-staffing-tools.md](features/erlang-c-staffing-tools.md)
> - **tool-reference.md fully audited** — historical April audit; headline counts are now superseded by the May 17 Rev 2.0 fact sheet (~830 tools, live registry authoritative). Added 14 new sections: OpenAI file/model management, embeddings & vector stores, multi-agent orchestration, agent memory management, reasoning & code analysis, deep research, browser-native AI, Yahoo Fantasy Football toolkit, Newsletter integration, WP All Import/Export, Flowhub, PayHere, and Erlang C tools.
> - **hooks-reference.md** — Added `wp_mcp_ai_queue_alert` action with full parameter schema and usage examples.
> - **QUICK_REFERENCE.md** — Updated to v1.1.8 with new Recent Updates section.

> **📌 APRIL 8, 2026 UPDATE:** 📐 **ARCHITECTURE REFRESH & REQUEST FLOW WALKTHROUGH**
> - **Architecture Overview Refreshed** – Updated from Dec 2025 (3 providers, 133 tools) to current state: **9 providers**, **837 tool classes** (227 base + 610 pro), **34 REST controllers** (16 base + 18 pro), **64 service classes**, accurate directory structure with file counts, v1.1.6 version history entry.
- **Request Flow Walkthrough** (NEW) – End-to-end trace of a chat message through every layer: `sendChat()` → `POST /chat-client` → Authentication (5 methods) → Assistant resolution → SSE setup → Language Model Router (9 providers) → Agentic loop (up to 15 iterations) → Token budget validation (auto-model switch) → SSE events → Frontend render. Covers provider routing table, agentic loop mechanics, tool execution internals, SSE event types, key source file reference, and hooks in the request path.
> - **README.md** – Architecture section updated with current statistics; documentation section fixed stale tool/doc counts, added walkthrough link.
> - **See**: [ARCHITECTURE.md](developer/architecture/ARCHITECTURE.md), [REQUEST-FLOW-WALKTHROUGH.md](developer/architecture/REQUEST-FLOW-WALKTHROUGH.md), [ACP_INTEGRATION.md](project/ACP_INTEGRATION.md)

> **📌 APRIL 2–6, 2026 UPDATE:** 🤝 **A2A PROTOCOL, JETENGINE MCP, AGENT COMMAND CENTER, CHAT BUBBLE, IMAGE VALIDATION**
> - **JetEngine 3.8 MCP Server Integration** (PR #4608) – JSON-RPC 2.0 client bridging into JetEngine's native MCP Server. 7 new Pro tools: `jetengine_mcp`, `jetengine_create_post_type`, `jetengine_create_taxonomy`, `jetengine_create_meta_field`, `jetengine_manage_relations`, `jetengine_site_context`, `jetengine_prompts`. MCP-first dispatch with REST v2 fallback.
> - **Agent-to-Agent (A2A) Protocol** (PR #4578) – Full A2A implementation: `/.well-known/agent.json` discovery, JSON-RPC 2.0 server with task state machine, A2A client, `delegate_to_a2a_agent` tool, push notification webhooks. 60+ tests.
> - **Agent Command Center** (PR #4575) – 7-tab Pro dashboard: Overview, Activity Log, Active Tasks, Approvals, Analytics (Chart.js), Uptime & Health, Strategy. Real per-agent metrics (PR #4593).
> - **Floating Chat Bubble Widget** (PR #4566) – Elementor widget + Gutenberg block. 4 positions, 3 sizes, dark mode, WCAG focus states, `window.wpMcpAiChatBubble` API.
> - **Anthropic & Gemini Subscription Tiers** (PR #4567) – Centralized headers/endpoints, custom base URLs, API key type selectors (standard/team/enterprise). Filter hooks for header injection.
> - **ECA Pro Toolkit — 24 New Tools** (PR #4568) – Attendance, waitlist, scheduling, notifications, analytics, integration, workflow tools. 4 existing tools upgraded.
> - **Image Validation Tools** (PR #4585) – `validate_image_for_product` (9 product types) and `validate_image_for_vehicle` (cleaning/repair). AI Vision–powered, industry-standard A–F ratings.
> - **Agent Workflow Presets** (PR #4580) – 5 new presets: supervisor, pipeline, swarm, hierarchical, review QA. Chat UI sub-agent panel with agent cards and workflow tracker.
> - **Enterprise TMA Templates** (PR #4586) – 5 inline templates upgraded to 5-tab architecture (E-Commerce, CRM, Analytics, Booking, AI Chat).
> - **Shopify Shop TMA** (PR #4602) – New React SPA with catalog, cart, checkout, orders, and AI chat. Critical fixes to Shopify Jewelry TMA.
> - **Per-Connection TMA URLs** (PR #4588) – Multi-bot Telegram support via `/telegram-mini-app/{connection_id}`.
> - **Security** – SQL hardening (PR #4574), guest token TTL fix, output escaping, lodash vulnerability patch (PR #4564).
> - **Bug Fixes** – `execute()` signatures (PR #4609), analytics hooks (PR #4593), TMA auth/imports/white screen (PRs #4590-#4607), model pricing (PR #4565).
> - **Tool Count** – 166 base + 402 pro = **568 total tools** (was 533).
> - **See**: [README.md Latest Updates](../README.md#-latest-updates-marchapril-2026), [CHANGELOG.md](../CHANGELOG.md)

> **📌 MARCH 29–31, 2026 UPDATE:** 🚗 **VEHICLE ESTIMATION TOOLS, SHOPIFY AUTO-RESOLVE, QUICKBOOKS DESKTOP, IMAGE DOWNLOADS, WEBHOOK STATUS**
> - **Vehicle Estimation Tools** – 3 always-available Pro tools (`vin_decode`, `vehicle_repair_estimate`, `vehicle_cleaning_estimate`). VIN decode via NHTSA vPIC, image-to-repair-estimate pipeline, car wash package pricing engine.
> - **Shopify Connection Auto-Resolve** – All 5 Shopify tools auto-resolve `connection_id` from assistant context. New `remote_shopify_connection` tool. `WP_MCP_AI_Shopify_Connection_Resolver` trait.
> - **Webhook Status Admin Page** – Centralized webhook monitoring for 9 connection types under NV oOS Pro Dashboard. Live Telegram checks, set/remove webhook actions.
> - **QuickBooks Desktop Sync** – New `quickbooks_desktop_sync` Pro tool via QODBC relay API. New `quickbooks_desktop` remote connection type.
> - **Listing Image Download Tools** – 3 new Pro tools: `download_google_maps_images`, `download_facebook_page_images`, `download_instagram_page_images`.
> - **Transformers.js v3.8.1** – CDN upgraded from `@xenova/transformers@2.17.2` to `@huggingface/transformers@3.8.1`. WebGPU acceleration, 4 Qwen3 models.
> - **15 Schedule Presets** – CRM Email Correspondence (5), Document Management (5), Upwork Freelancer (5). Total: 100 → 115.
> - **Medical Vitals Dashboard** – Recent reading card, configurable trend range (7D/14D/30D/90D).
> - **Registration Product Research Page** – Quick Import, Guided Entry tabs, product selector sidebar, 3 AJAX handlers.
> - **Copyright Attribution** – `@author`/`@copyright`/`@license` tags across 2,535 PHP + 159 JS/CSS files.
> - **Bug Fixes** – Telegram webhook 403, chat inbox bot names, message pagination, connection_id scoping, workflow presets.
> - **Security** – brace-expansion (CVE-2026-33750) and serialize-javascript (CVE-2026-34043) patched.
> - **Tool Count** – Approximately 800+ tools (≈220 base + ≈580 pro). Counts are approximate and evolve with each release; `WP_MCP_AI_Tool_Registry::get_tools()` is the authoritative source.
> - **See**: [README.md Latest Updates](../README.md#-latest-updates-marchapril-2026), [CHANGELOG.md](../CHANGELOG.md)

> **📌 MARCH 28, 2026 UPDATE:** 🚀 **ONBOARDING WIZARD ENHANCEMENT — PRESET ASSISTANT SEEDING & ACCESSIBILITY**
> - **8 Use-Case Presets** – Content Creator (12 tools), Customer Support (8 tools), E-commerce (11 tools), SEO & Research (12 tools), Developer Copilot (12 tools), Media & Creative Studio (11 tools), Site Administrator (13 tools), General Purpose (12 tools)
> - **Assistant Seeding** – Selecting a preset creates a fully-configured `mcp_ai_assistant` CPT post with tools, system prompt, provider, model, and temperature. First assistant auto-set as default.
> - **WCAG 2.1 Accessibility** – WAI-ARIA tablist/tab/tabpanel for provider tabs, keyboard navigation, `aria-current="step"` progress, `aria-live="polite"` feedback, `focus-visible` outlines
> - **External JavaScript** – Inline scripts extracted to `assets/js/onboarding-wizard.js` with `wp_localize_script()` for i18n (CSP-compliant)
> - **Explicit Completion** – Step 4 no longer auto-completes; uses "Mark Setup Complete" button via AJAX
> - **New Hooks** – `wp_mcp_ai_onboarding_presets` filter, `wp_mcp_ai_onboarding_presets_seeded` action
> - **22 PHPUnit Tests** – Preset validation, assistant seeding, duplicate prevention, model resolution
> - **See**: [README.md Getting Started Wizard](../README.md#-installation), [CHANGELOG.md](../CHANGELOG.md)

> **📌 MARCH 19, 2026 UPDATE:** 🏥 **HEALTHCARE DICOM IMAGING VIEWER — FULL MANAGER REBUILD**
> - **Black Images Fixed** — `csDicomImageLoader.external.cornerstone` link now correctly wired; auto-VOI from pixel min/max for studies without `WindowCenter`/`WindowWidth` tags
> - **Multi-Study Upload Fixed** — All uploaded studies now appear in the browser (was: only last study shown after a multi-file batch)
> - **4-Tab Manager** — Studies (search/filter bar) · AI Tools · Audit Log · Documentation
> - **W/L Clinical Presets** — 11 presets for CT (Soft Tissue / Lung / Brain / Bone / Abdomen / Liver / Mediastinum), MR (Brain / Spine / Soft Tissue), PET (SUV Max)
> - **Extra Viewer Tools** — Flip H/V, Rotate CW/CCW, PNG screenshot export; keyboard shortcuts (Arrow keys, R reset, I invert)
> - **AI Interpretation** — `POST /imaging/interpret` REST endpoint; Tools tab form auto-fills UID from open study
> - **Accessible Delete** — Inline confirmation row replaces non-accessible `window.confirm`
> - **Stats Bar** — Total studies, modality breakdown, storage used — populated from `GET /imaging/stats`
> - **In-app Documentation Tab** — Keyboard shortcut reference, W/L presets table, DICOM modality codes, full REST API reference, Privacy & HIPAA notes
> - **New Doc**: [features/healthcare-imaging-viewer.md](features/healthcare-imaging-viewer.md)

> **📌 MARCH 16, 2026 UPDATE:** 🗂️ **QUICK TOOL SELECTION PRESETS – BROAD REGISTRY COVERAGE**
> - **Quick Tool Presets Expanded** – historical preset expansion on the assistant CPT edit page; exact coverage should now be verified against the live registry
> - **New Preset** – `📋 Registration & Compliance`: registration lifecycle, regulated products, compliance certificates, authority submission, NMRA/MOHAP sync
> - **20+ Updated Presets** – E-commerce (Shopify), Communication (Discord/Slack/Teams/Apple/Telegram/WhatsApp/Messenger/Google Chat), Development (tool scaffolding), Files (cloud storage/PDF/Excel), SEO (social listening/competitor analysis), Site Management (page builder sections), Healthcare (health metrics/vitals import), Sales/CRM, Finance, and more
> - **Current Count Note**: May 2026 public framing is ~830 tools (~195 base + ~635 Pro); live registry remains authoritative

> **📌 MARCH 15, 2026 UPDATE:** 🔒 **SECURITY HARDENING + CHANNEL FIXES + v1.1.4**
> - **Security** – AES-256-GCM encryption, finfo fail-closed, Discord replay protection, HTTPS enforcement, ZIP bomb guard, OCR info-disclosure fix
> - **Chat Channels** – Fixed Slack @mentions, Google Chat routing/OIDC, Teams multi-connection + OAuth 1-click, Telegram typing indicator + slash commands
> - **Telegram Mini App** – Connection-assigned assistant for doctor tab, Markdown HTML rendering for AI replies, vitals log import improved
> - **AI Providers** – Gemini embedding-001 + 9 task types + output_dimensionality; AI-powered product actualization (Gemini/OpenAI)
> - **PDF Generation** – pdfkit/cheerio/docx/exceljs bundled; no server-side node_modules needed
> - **WordPress.org** – .gitattributes excluded from ZIPs, composer.json included with vendor/, languages/ directory created
> - **Version:** 1.1.4 (released March 2026)

> **📌 MARCH 2, 2026 UPDATE:** 📱 **TELEGRAM MINI APP ENHANCEMENT PROPOSAL** ⭐⭐
> - **Comprehensive Telegram Mini App Enhancement Proposal** - Full integration with Telegram Bot Platform features
> - **Monetization Strategy** - Stars payments, subscriptions, third-party payments (WooCommerce/Stripe)
> - **All 23 Pro Toolkits** mapped to Mini App interface with visual toolkit browser and tool execution forms
> - **New Telegram Features** - Inline mode, deep linking, payment webhooks, full-screen mode
> - **10 New Tools** proposed - Payment, inline mode, and enhanced bot management tools
> - **4-Phase Roadmap** - 14 weeks, 200 estimated hours
> - **See**: [TELEGRAM_MINI_APP_ENHANCEMENT_PROPOSAL.md](project/proposals/TELEGRAM_MINI_APP_ENHANCEMENT_PROPOSAL.md)

> **📌 FEBRUARY 17, 2026 UPDATE:** 🧹 **ROOT MARKDOWN FILES CONSOLIDATION** ⭐
> - **Markdown File Review Complete** - Reviewed and consolidated all root markdown files
> - **Files Moved to docs/architecture/**: 2 technical analysis documents
>   - Canvas Packaging Analysis (304 lines) - Pre-packaging size impact analysis
>   - Pro Plugin Size Optimization (185 lines) - Size reduction from 87MB to 33MB
> - **Root Directory Cleaned**: Maintains only 3 essential files (README.md, CHANGELOG.md, CONTRIBUTING.md)
> - **Documentation Updated**: Architecture README updated with new Technical Analyses section
> - **Naming Standardized**: Converted to lowercase-with-hyphens for consistency
> - **No Broken Links**: Verified all references, no external links to moved files
> - **See**: [MARKDOWN_CONSOLIDATION_2026-02.md](history/2026/implementations/MARKDOWN_CONSOLIDATION_2026-02.md)
> - **Architecture Docs**: [Canvas Analysis](developer/architecture/canvas-packaging-analysis.md) | [Size Optimization](developer/architecture/pro-plugin-size-optimization.md)

> **📌 FEBRUARY 13, 2026 UPDATE:** 🎯 **TOOL ENHANCEMENT ANALYSIS** ⭐⭐⭐
> - **Research Pattern Enhancement Analysis Complete** - Comprehensive review of all 533+ tools for multi-step orchestration
> - **Pattern: Web Search → Source Collection → AI Synthesis → Report Generation**
> - **Key Findings:**
>   - 14 tools already using pattern (research_product, research_post, etc.)
>   - 15 high-priority candidates identified (content_recommendation_engine, seo_meta_optimizer, suggest_best_model)
>   - 20+ medium-priority candidates for future enhancement
>   - 80+ tools not suitable for this pattern (CRUD, calculations, specific APIs)
> - **Expected Impact:**
>   - 40% improvement in content recommendation relevance
>   - Better SERP performance with current keyword trends
>   - Always up-to-date model recommendations
>   - Positive ROI within 5 months
> - **Implementation Plan:**
>   - Phase 1: 3 critical tools (2 weeks)
>   - Phase 2: 3 high-value tools (2 weeks)
>   - Phase 3: Content tools (2 weeks)
>   - Phase 4: Refinement (2 weeks)
>   - Total effort: 8 weeks, $23,000 estimated cost
> - **New Documentation:**
>   - [RESEARCH_PATTERN_EXECUTIVE_SUMMARY.md](history/2026/implementations/RESEARCH_PATTERN_EXECUTIVE_SUMMARY.md) - Executive summary (12KB)
>   - [RESEARCH_PATTERN_ENHANCEMENT_ANALYSIS.md](history/2026/implementations/RESEARCH_PATTERN_ENHANCEMENT_ANALYSIS.md) - Full analysis (32KB)
>   - [RESEARCH_PATTERN_IMPLEMENTATION_GUIDE.md](developer/research-pattern-implementation-guide.md) - Developer guide (29KB)
> - **Reference Implementation:** `/addons/pro/includes/tools/class-wp-mcp-ai-tool-research-product.php`
> - **Recommendation:** Proceed with phased implementation

> **📌 FEBRUARY 12, 2026 UPDATE:** 🧹
> - **Root Directory Organization Complete** - Final cleanup and consolidation
> - **3 Fix Summaries Archived**: Product Research fixes and Variable Product fix moved to `/archive/2025/fixes/`
> - **Root Now Contains**: Only 3 essential files (README.md, CHANGELOG.md, CONTRIBUTING.md)
> - **Archive Updated**: Created comprehensive README for `/archive/2025/fixes/` (22 files total)
> - **References Updated**: README.md and CHANGELOG.md now point to detailed docs in `/docs/fixes/`
> - **No Content Lost**: All fix summaries preserved in archive with full cross-references to detailed documentation
> - **Improved Navigation**: Clear separation between current docs and historical summaries
> - **See**: [Archive Fixes README](../archive/2025/fixes/README.md)

> **📌 FEBRUARY 6, 2026 UPDATE (LATEST):** ⭐⭐⭐⭐
> - **🔍 COMPREHENSIVE GAP ANALYSIS COMPLETE** - Full plugin review identifying security, architecture, and quality improvements
> - **📊 Overall Grade: A- (92/100)** - Production-ready with minor recommendations
> - **🔐 Security Analysis:**
>   - ✅ Clarified: REST endpoints with `__return_true` are intentional (CORS preflight, federation discovery)
>   - ⚠️ High Priority: Missing rate limiting on Federation Directory endpoints (allows enumeration)
>   - ⚠️ Medium Priority: CORS wildcard policy (acceptable with authentication, enhance in v1.2.0)
>   - ✅ Strong Foundation: Encryption, input sanitization, SQL injection prevention, file upload security
> - **🏗️ Architecture Findings:**
>   - 695 PHP files in `/includes/` - recommend organization into domain subdirectories
>   - Heavy singleton usage - gradual migration to DI Container recommended
>   - 50+ REST routes - recommend centralized permission registry
> - **✅ Testing: 85% coverage** - 753 test files, security tests exist, integration tests comprehensive
> - **📚 Documentation: 95/100** - Excellent (120+ markdown files) with minor gaps (threat model needed)
> - **🎯 Priority Actions for v1.1.1:**
>   - 🔴 Add rate limiting to Federation Directory (4-6h)
>   - 🔴 Document threat model in SECURITY.md (4-6h)
>   - 🔴 Add security tests for rate limiting (4-6h)
> - **New Documentation:**
>   - [GAP_ANALYSIS_COMPREHENSIVE_2026-02-06.md](project/code-reviews/GAP_ANALYSIS_COMPREHENSIVE_2026-02-06.md) - Full gap analysis (18KB)
>   - [IMPLEMENTATION_GUIDE_GAP_ANALYSIS_2026-02-06.md](project/code-reviews/IMPLEMENTATION_GUIDE_GAP_ANALYSIS_2026-02-06.md) - Actionable implementation guide (20KB)
> - **See Previous Update Below** for February 6 security code review
>
> **📌 FEBRUARY 6, 2026 UPDATE (EARLIER):** ⭐⭐⭐
> - **Slash Commands & Workflow System Complete** - Phase 1 & 2 with 21 commands, 7 workflows, REST API integration
> - **Chat Channels Toolkit Production Ready** - 21 tools across 6 platforms (Telegram, WhatsApp, Slack, Discord, Teams, Messenger)
> - **WebChat Rooms Implemented** - Real-time collaborative rooms with AI assistant assignment, message persistence, WebRTC support
> - **Pro Toolkit Commands** - 21 specialized slash commands for E-commerce, Social Media, and Video Production toolkits
> - **Automated Workflows** - 7 pre-built workflow templates for cart recovery, social campaigns, video marketing, inventory management
> - **Documentation Updated** - README.md and CHANGELOG.md updated with all new features
> - **See**: [SLASH_COMMANDS_GUIDE.md](user-guides/slash-commands/SLASH_COMMANDS_GUIDE.md), [PRO_TOOLKIT_SLASH_COMMANDS.md](user-guides/slash-commands/PRO_TOOLKIT_SLASH_COMMANDS.md), [CHAT_CHANNELS_TOOLKIT.md](../addons/pro/docs/CHAT_CHANNELS_TOOLKIT.md)
> - **Complete Security Code Review & Verification** - All critical security vulnerabilities resolved!
> - **Security Status**: ✅ EXCELLENT (95/100) - Up from 93/100 in January
> - **4 Critical/High Issues FIXED**: SSRF, CSRF, XSS, Authorization
> - **2 Medium Issues DOCUMENTED**: CORS wildcard and rate limiting (acceptable with current authentication)
> - **Grade Improvement**: A- (93/100) → A (95/100)
> - **Production Status**: ✅ **APPROVED FOR IMMEDIATE DEPLOYMENT**
> - **New Documentation**: 
>   - [CODE_REVIEW_2026-02-06.md](history/2026/CODE_REVIEW_2026-02-06.md) - Comprehensive review (23KB)
>   - [SECURITY_FIXES_2026-02-06.md](history/2026/implementation-summaries/SECURITY_FIXES_2026-02-06.md) - Security fix details (13KB)
> - **Roadmap Updated**: Future security enhancements added to v1.2.0
> - **Response Time**: ~1 week from identification to fix ✅ **Excellent**

> **📌 FEBRUARY 5, 2026 UPDATE:** ⭐⭐⭐
> - **Major Documentation Reorganization Complete** - Root directory and docs/ cleaned and consolidated
> - **Root Directory**: 46 markdown files → 3 essential files (README.md, CHANGELOG.md, CONTRIBUTING.md)
> - **Archived**: 50+ files from root → `/archive/2025/` (Phase 6, fixes, status reports, slash commands, workflow builder, production)
> - **Docs Consolidated**: 19 duplicate/outdated files → `/docs/archive/2025/` (DeepSeek V4, WordPress.org, Pro Dashboard, Pro Toolkit)
> - **Archive Indexes Created**: Comprehensive navigation in both archive directories
> - **No Content Lost**: All information preserved with organized indexes for reference
> - **Improved Navigation**: Focus on current, maintained documentation
> - **See**: [Root Archive](../archive/README.md), [Docs Archive](history/archive/2025/README.md)

> **📌 FEBRUARY 5, 2026 UPDATE (EARLIER):** ⭐
> - **NPM Package Distribution Documentation Complete** - Comprehensive analysis for extracting JavaScript components as standalone NPM packages
> - **4 Strategic Documents**: Strategy blueprint, extraction guide, component analysis, quick reference (1,582 lines)
> - **67 JavaScript Files Analyzed**: 15+ extractable components identified with priority rankings
> - **Key Finding**: YES - Multiple components ready for NPM distribution with minimal/zero modifications
> - **High Priority Components**: Markdown renderer (0/10 WP dependency), event system (0-2/10), storage utilities (1/10)
> - **Timeline**: 8-12 weeks for first three packages, 2-3 weeks for pilot
> - **Start Here**: [NPM_PACKAGE_DISTRIBUTION.md](project/releases/NPM_PACKAGE_DISTRIBUTION.md) → [Full Documentation](project/releases/npm-packages/README.md)

> **📌 FEBRUARY 1, 2026 UPDATE:** ⭐
> - **PHPCS Documentation Suite Complete** - Comprehensive PHPCS compliance documentation added
> - **7 PHPCS Documents**: Master index, flowchart, implementation plan, quick reference, detailed analysis, summaries
> - **667 Violations Analyzed**: 91 fixable, 127 to suppress, 469 intentional
> - **Implementation Status**: Phase 1 complete (file upload security, privacy compliance verified)
> - **January 2026 Fixes**: 16 critical security errors resolved
> - **Start Here**: [PHPCS_DOCUMENTATION_INDEX.md](history/archive/2026/fixes/phpcs/PHPCS_DOCUMENTATION_INDEX.md)

> **📌 JANUARY 31, 2026 UPDATE:** 
> - **Root Directory Organization Complete** - 5 documentation files moved from root to appropriate docs/ subdirectories
> - **Files Moved**: FEDERATION_SETUP_GUIDE.md → docs/guides/admin/, FEDERATION_DIRECTORY_DEBUG.md → docs/fixes/federation/, README-MULTI-AGENT-SYSTEM.md → docs/features/multi-agent/, PRODUCTION_COMPOSER.md → docs/deployment/
> - **Root Now Contains**: Only 3 essential documentation files (README.md, CHANGELOG.md, CONTRIBUTING.md)
> - **WordPress.org Plugin Check Review Complete** - Comprehensive compliance audit completed, plugin READY for WordPress.org submission
> - **Overall Status**: ✅ COMPLIANT - All WordPress.org requirements met
> - **New Documentation**: [WORDPRESS_ORG_PLUGIN_CHECK_REPORT.md](operations/compliance/WORDPRESS_ORG_PLUGIN_CHECK_REPORT.md) - Complete review report
> - **Key Findings**: All 12 compliance categories PASS, no blocking issues, comprehensive external services disclosure
> - **Recommendation**: Plugin can be confidently submitted to WordPress.org plugin directory

> **📌 JANUARY 30, 2026 UPDATE:** 
> - **Repository Organization Complete** - Root directory cleaned, 7 implementation docs moved to `docs/implementation-history/2026/january/`
> - **Files Moved**: IMPLEMENTATION_COMPLETE.md, IMPLEMENTATION_SUMMARY.md, PR_SUMMARY.md, ORCHESTRATION_MODES_ENHANCEMENT_SUMMARY.md, REGULATORY_TOOLKIT_ENHANCEMENT.md, TOOLKIT_ENHANCEMENT_README.md, VISUAL_GUIDE.md
> - **Test Files Organized** - test-toolkit-registry.php → tests/manual/, wpcode-snippet.php → examples/
> - **Proposals Status Tracking** - New comprehensive tracking document: [PROPOSALS_COMPLETION_STATUS.md](project/proposals/PROPOSALS_COMPLETION_STATUS.md)
> - **Repository Documentation** - New guide: [REPOSITORY_ORGANIZATION.md](project/REPOSITORY_ORGANIZATION.md)
> - **64 Proposals Tracked**: 18 complete (28%), 6 in progress (9%), 40 pending (63%)
> - **Root Now Contains**: Only 8 essential files (README, CHANGELOG, CONTRIBUTING, SECURITY, LICENSE, BUILD, DEPENDENCIES_BUNDLING, CODEOWNERS)

> **📌 JANUARY 23, 2026 UPDATE:** 
> - **Tool Count Documentation Updated** - Historical January 2026 count sweep; superseded by the May 17, 2026 Rev 2.0 fact sheet
> - **Current Framing**: ~830 tools total (~195 base + ~635 Pro); live registry remains authoritative
> - **Files Updated in Rev 2.0 follow-up**: README.md, docs/DOCUMENTATION_INDEX.md, docs/getting-started/USE_CASES_AND_QUICKSTARTS.md, docs/getting-started/_USE_CASES_FACT_SHEET.md
> - **Verified**: Current counts reconcile against readme.txt 1.1.18 and live-code sanity checks

> **📌 JANUARY 22, 2026 UPDATE:** 
> - **Documentation Consolidation Complete** - Menu-related documentation consolidated
> - **Menu Fixes Consolidated** - 6 files merged into comprehensive guide at `docs/fixes/menu-fixes/MENU_FIXES_CONSOLIDATED.md`
> - **Files Removed from Root**: MENU_FIX_SUMMARY.md, MENU_REORGANIZATION_SUMMARY.md, MENU_STRUCTURE_VISUAL.md, REMOTE_SITES_MENU_FIX.md, REMOTE_SITES_MENU_FIX_VISUAL.md, PR_SUMMARY.md
> - **Feature Docs Organized** - TOOLKIT_MEMORY_TRACKING.md moved to `docs/features/`
> - **Root Directory Even Cleaner** - Only 6 essential documentation files remain in root

> **📌 JANUARY 18, 2026 UPDATE:** 
> - **PR #2990 - Tool Preset Multiplier Fix** - Fixed broken "Apply Preset" button on Token Manager page
> - **Root Cause**: Tool registry returned empty array during preset application
> - **Solution**: Refactored to iterate through tool categories first (200+ tools), then check registry
> - **Documentation**: Complete fix details and testing plan in `docs/fixes/`
> - **Root Directory Cleanup** - Removed duplicate FIX_SUMMARY.md from root, consolidated into docs/fixes/

> **📌 JANUARY 13, 2026 UPDATE:** 
> - **PR #2883 Review & Integration** - Gmail OAuth UX enhancement documented, auto-display redirect URI in admin
> - **Root Directory Organization** - 14 MD files moved from root to proper subdirectories
> - **Migration Reports Organized** - 9 files moved to `docs/implementation-history/2026/migrations/`
> - **Fix Documentation Consolidated** - Gmail OAuth files consolidated, FlowHub fixes moved to `docs/fixes/`
> - **Root Now Clean** - Only 6 essential files remain in repository root

> **📌 JANUARY 8, 2026 UPDATE (WEEK 2):** 
> - **Complete Code Review & Gap Analysis** - Comprehensive audit completed. Grade: A- (93/100). Production ready! See [CODE_REVIEW_AND_GAP_ANALYSIS_2026-01-08.md](project/code-reviews/CODE_REVIEW_AND_GAP_ANALYSIS_2026-01-08.md)
> - **Development Dependencies Removed** - Repository now production-ready with `composer install --no-dev` completed
> - **Root Directory Reorganization** - 19 temporary fix documents consolidated. See [Root Directory Consolidation](history/2026/ROOT-DOCS-REORGANIZATION.md)

> **📌 JANUARY 6, 2026 UPDATE (WEEK 2)**: 
> - **Compliance posture documentation added** - historical ISO 27001, SOC 2, and HIPAA framework work landed; current docs avoid unbacked percentage claims and point operators to posture references
> - **Pro CPT Documentation** - Events, Quizzes, and Places (21 tools total)
> - **Root Directory Organization** - Moved 25 files to organized subdirectories
> - **Pro Dashboard Modernization** - Singleton pattern with industry standards
> - **PM Assistant Fixes** - 6 critical modal and chat fixes
> - **WordPress 6.7+ Compatibility** - Translation timing fixes
> - **Text Domain Migration** - Complete migration to mcp-ai-wpoos (12,773 instances)
> - **Production Ready** - Dev dependencies removed from vendor

> **📌 JANUARY 3, 2026 UPDATE**: Gap Documentation Review completed - 100% high-priority completion achieved! All 10 critical items complete including Code Coverage Dashboard. Overall quality score: 98/100 (up from 95/100). Production ready with A+ grade. See [Gap Documentation Review Summary](history/2026/implementations/GAP_DOCUMENTATION_REVIEW_SUMMARY.md) and [Full Status Update](history/2025/summaries/GAP_DOCUMENTATION_STATUS_UPDATE_2026-01-03.md) for details.

> **📌 JANUARY 2, 2026 UPDATE**: Code Review and Root Directory Organization completed. Historical tool count verification completed; current May 2026 framing is ~830 tools (~195 base + ~635 Pro). Repository organization improved - moved 8 fix/implementation files from root to proper docs/ subdirectories. See [Code Review Summary](history/2025/code-reviews/CODE_REVIEW_SUMMARY_2026-01-02.md) and [Organization Summary](history/2025/documentation/ROOT_DIRECTORY_ORGANIZATION_2026-01-02.md) for details.

> **📌 DECEMBER 29, 2025 UPDATE**: Comprehensive documentation review completed - 659 files reviewed, 100% feature coverage verified, zero significant gaps found. Overall documentation grade A (95/100). See [Documentation Review Summary](history/2026/implementations/DOCUMENTATION_REVIEW_SUMMARY.md) and [Full Review](history/2025/documentation/DOCUMENTATION_REVIEW_2025-12-29.md) for details.

> **📌 DECEMBER 25, 2025 UPDATE**: Complete codebase review performed - Full PHP/JS linting, security scan, architecture assessment. Overall grade A- (92/100) - Production Ready. See [Comprehensive Code Review](history/2025/code-reviews/COMPREHENSIVE_CODE_REVIEW_2025-12-25.md) and [Summary](history/2025/code-reviews/CODE_REVIEW_SUMMARY_2025-12-25.md) for details.

---

## 🆕 May 2026 — Phase 7 Gap-Fill: Orchestration Hub, Platform Comparison, Quickstart Workflow

### New documents (Phase 7 gap-fill)

- **[docs/orchestration-reference.md](reference/orchestration/orchestration-reference.md)** ⭐ **NEW** — Canonical orchestration documentation hub. 2-paragraph NV oOS overview, links to every orchestration-relevant doc, full Phase 1 Observability reference (Run Timeline, Layer I Prompt Injection Detector, Structured Output Guardrail, OTel span exporter), full Phase 2 HITL reference (`request_user_approval` tool, `WP_MCP_AI_Approval_Queue`, REST `/mcp-ai/v1/approvals/*`, admin Approvals page), and Phase 3–6 roadmap table. Distinct from [`docs/ORCHESTRATION_REFERENCE.md`](reference/orchestration/ORCHESTRATION_REFERENCE.md) (the deep infrastructure reference).
- **[docs/comparisons/orchestration-platform-comparison.md](project/proposals/orchestration-platform-comparison.md)** ⭐ **NEW** — NV oOS vs LangGraph vs CrewAI vs AutoGen vs OpenAI Agent Builder vs n8n-AI. 15-row feature matrix covering platform type, LLM provider support, tool count, MCP, A2A, HITL, visual DAG, durable execution, OTel, prompt injection detection, structured output, memory, deployment, WordPress/WooCommerce integration, and license. Includes an honest "where others lead" assessment. Canonical path: `docs/comparisons/orchestration-platform-comparison.md` — see the file's footer note for the one-command `git mv` to its permanent home once `docs/comparisons/` is created.
- **[docs/quickstart-workflow.md](getting-started/quickstart-workflow.md)** ⭐ **NEW** — "Build your first workflow in 10 minutes" tutorial. Creates an assistant, enables `write_post` + `request_user_approval`, writes a system prompt, triggers the HITL loop from the chat UI, reviews the approval request in **NV oOS → Approvals**, approves, and watches the post publish. Welcoming tone; H2/H3 headings, numbered steps, code blocks.

---

## 🆕 May 2026 — LLM Harnessing GA, Slash Commands, Chat Memory Drawer, Transcript Mining, Pro Packages Tier 5

### New and updated documents (May 3–4, 2026)

- **[docs/llm-harness.md](features/llm-harness.md)** ⭐ **UPDATED** — LLM Harnessing Subsystem Layers A–H end-to-end reference, Quick Start, harness profile schema, all hooks, Pro Layer H curriculum export.
- **[docs/features/memory/transcript-mining.md](features/memory/transcript-mining.md)** ⭐ **NEW** — Retroactive Transcript Mining: background job, REST API (3 endpoints), `mine_agent_memory` `transcripts` source, provenance metadata, de-duplication, filters, admin UI, PHPUnit coverage.
- **[docs/features/memory/chat-client-integration.md](features/memory/chat-client-integration.md)** ⭐ **UPDATED** — G-series completion (G2 audit tab, G3 badge, G6 pagehide, G8 SSE frame, G11 export); two-gates section (`wp_mcp_ai_chat_memory_enabled` filter + per-user meta).
- **[docs/features/slash-commands-guide.md](features/slash-commands-guide.md)** ⭐ **NEW** — Complete reference for all 24 base + 8 Pro slash commands with capability, aliases, and description. Replaces the dated Phase 1 guide for day-to-day reference.

---

## 🆕 July 2026 — Security Infrastructure, Framework-Agnostic Core, Status Page, Agent Skills, Algorave

### New and updated documents (July 23–29, 2026)

- **[docs/operations/production-hardening-guide.md](operations/production-hardening-guide.md)** ⭐ **NEW** — Step-by-step production hardening checklist: WAF, OAuth lockdown, DICOM requirements. (July 28, 2026)
- **[docs/developer/api-key-encryption.md](developer/api-key-encryption.md)** ⭐ **NEW** — API key encrypted-at-rest storage with key rotation. (July 28, 2026)
- **[docs/developer/dicom-phi-handling.md](developer/dicom-phi-handling.md)** ⭐ **NEW** — DICOM/healthcare PHI auto-detection and redaction. (July 28, 2026)
- **[docs/reference/admin/security-settings.md](reference/admin/security-settings.md)** ⭐ **NEW** — Security admin settings reference. (July 28, 2026)
- **[docs/project/proposals/014-status-page-maintenance-incident-communication-implementation-plan.md](project/proposals/014-status-page-maintenance-incident-communication-implementation-plan.md)** ⭐ **NEW** — Status page, maintenance announcements, and incident communication workflow plan. (July 27, 2026)
- **[SECURITY.md](../SECURITY.md)** ⭐ **UPDATED** — Production hardening guide link, security documentation index. (July 28, 2026)
- **[SECURITY_AUDIT_REPORT.md](../SECURITY_AUDIT_REPORT.md)** ⭐ **UPDATED** — 13-layer security audit findings (27 issues: 2 Critical, 7 High, 11 Medium, 7 Low). (July 28, 2026)
- **[README.md](../README.md)** ⭐ **UPDATED** — v1.1.42 latest updates section, repository map updated.
- **[CHANGELOG.md](../CHANGELOG.md)** ⭐ **UPDATED** — v1.1.42 section with full PR-level detail.
- **[CLAUDE.md](../CLAUDE.md)** ⭐ **UPDATED** — Security infrastructure section, framework-agnostic core section, context files, troubleshooting.
- **[AGENTS.md](../AGENTS.md)** ⭐ **UPDATED** — Zed agent skills, coding-time vs runtime skills distinction, context-loading updates.
- **[.github/copilot-instructions.md](../.github/copilot-instructions.md)** ⭐ **UPDATED** — Repository structure, key technologies, documentation count.
- **[.context/security-checklist.md](../.context/security-checklist.md)** ⭐ **UPDATED** — New security infrastructure class references.
- **[.context/conventions.md](../.context/conventions.md)** ⭐ **UPDATED** — lib/core naming patterns.
- **[MAINTAINER_MAP.md](../MAINTAINER_MAP.md)** ⭐ **UPDATED** — New directories, security docs, lib/core section.
- **[docs/QUICK_REFERENCE.md](QUICK_REFERENCE.md)** ⭐ **UPDATED** — v1.1.42 entry.
- **[docs/ROADMAP.md](ROADMAP.md)** ⭐ **UPDATED** — v1.1.42 released section.

---

## 🆕 July 2026 — OKF Integration, Security Compliance, Playbook Sync, Dependency Bumps

### New and updated documents (July 20–22, 2026)

- **[docs/features/okf-integration.md](features/okf-integration.md)** ⭐ **NEW** — OKF v0.1 engine, 6 MCP tools, skill conformance. (July 20, 2026)
- **[docs/features/compliance-security-fixes-2026-07.md](features/compliance-security-fixes-2026-07.md)** ⭐ **NEW** — 11 HIGH/P0 security fixes from compliance review: HMAC policy tokens, health endpoint auth-gating, ZIP validation, CSRF nonces, SRI hashes. (July 20, 2026)
- **[docs/project/proposals/OKF_INTEGRATION_PROPOSAL.md](project/proposals/OKF_INTEGRATION_PROPOSAL.md)** ⭐ **NEW** — Open Knowledge Format integration proposal (Phases 1–8). (July 20, 2026)
- **[docs/project/proposals/compliance-security-fixes-2026-07-19.md](project/proposals/compliance-security-fixes-2026-07-19.md)** ⭐ **NEW** — Compliance security implementation plan with 15 issues. (July 19, 2026)
- **[docs/QUICK_REFERENCE.md](QUICK_REFERENCE.md)** ⭐ **UPDATED** — v1.1.41 entries for OKF, security, playbook sync, dependency bumps.
- **[docs/ROADMAP.md](ROADMAP.md)** ⭐ **UPDATED** — v1.1.41 released section with full delivery list.
- **[CHANGELOG.md](../CHANGELOG.md)** ⭐ **UPDATED** — v1.1.41 section with full PR-level detail.
- **[README.md](../README.md)** ⭐ **UPDATED** — v1.1.41 latest updates section.
- **[CLAUDE.md](../CLAUDE.md)** ⭐ **UPDATED** — OKF engine section, security patterns, tool count bump.
- **[AGENTS.md](../AGENTS.md)** ⭐ **UPDATED** — OKF skill conformance, cross-references.
- **[.context/tool-registry.md](../.context/tool-registry.md)** ⭐ **UPDATED** — OKF tools, count bump.
- **[.context/security-checklist.md](../.context/security-checklist.md)** ⭐ **UPDATED** — HMAC tokens, realpath containment, admin-post CSRF patterns.
- **[.context/conventions.md](../.context/conventions.md)** ⭐ **UPDATED** — directory tree, tool counts.
- **[.github/copilot-instructions.md](../.github/copilot-instructions.md)** ⭐ **UPDATED** — OKF architecture, tool counts.


## 🆕 July 2026 — Content Format Awareness, Research Pipeline, Settings Credential Split, Model Catalog, Provider Parity, SSE Fixes

### New and updated documents (July 15, 2026)

- **[docs/features/content-format-awareness.md](features/content-format-awareness.md)** ⭐ **NEW** — Content format detection and preservation across post-modifying tools.
- **[docs/features/research-paper-store-pipeline.md](features/research-paper-store-pipeline.md)** ⭐ **NEW** — Research → Paper Store → WordPress Draft pipeline architecture.
- **[docs/features/demo-video-pipeline.md](features/demo-video-pipeline.md)** ⭐ **NEW** — Automated demo video pipeline with AI narration.
- **[docs/features/settings-credential-split.md](features/settings-credential-split.md)** ⭐ **NEW** — Two-option credential isolation architecture.
- **[docs/QUICK_REFERENCE.md](QUICK_REFERENCE.md)** ⭐ **UPDATED** — v1.1.40 entries expanded with all post-release changes.
- **[docs/ROADMAP.md](ROADMAP.md)** ⭐ **UPDATED** — v1.1.40 released section with full delivery list.
- **[CHANGELOG.md](../CHANGELOG.md)** ⭐ **UPDATED** — v1.1.40 section with full PR-level detail across 8 categories.
- **[README.md](../README.md)** ⭐ **UPDATED** — v1.1.40 latest updates section, provider count fixes, consolidated sections.
- **[CLAUDE.md](../CLAUDE.md)** ⭐ **UPDATED** — version bump, settings credential split guidance.
- **[⁠.context/settings-storage.md](../.context/settings-storage.md)** ⭐ **NEW** — Agent context for settings storage architecture.

---

## 🆕 July 2026 — Meta-Harness Auto-Optimization, Agent Delegation Rework, Pro SPA v2 Polish, Tool Presets Refactor, CRM Enhancements

### New and updated documents (July 10–13, 2026)

- **[docs/features/meta-harness-auto-optimization.md](features/meta-harness-auto-optimization.md)** ⭐ **NEW** — Meta-Harness auto-optimization system: Trace Store, Trace Capture, Harness Search Engine, Pro Coding-Agent Proposer, Cues, Population, Auto-Deploy, DSpark, and test infrastructure across all 7 phases.
- **[docs/features/agent-delegation-system.md](features/agent-delegation-system.md)** ⭐ **NEW** — Agent delegation architecture: inline execution, REST chat dispatch, cron resilience with retry, `spawn_cron()` deferred execution, name-based agent resolution, tasks drawer wire-up.
- **[docs/features/pro-spa-v2.md](features/pro-spa-v2.md)** ⭐ **UPDATED** — Post-v1.1.38 Pro SPA v2 fixes and polish: vector store, autocomplete, cost badges, `allowSensitiveTools`, tool result rendering, auto-save transcripts, attachments + storage, tasks drawer toolbar, speech/audio, capability flags, usage badges, sidebar/media, system prompt, layout/mobile.
- **[docs/features/tool-presets-system.md](features/tool-presets-system.md)** ⭐ **NEW** — Tool presets system: essentials layers, deduplication, auto-upgrade for validated variants, SSE adapter fixes, `tool_call_id` fallback handling.
- **[docs/QUICK_REFERENCE.md](QUICK_REFERENCE.md)** ⭐ **UPDATED** — v1.1.39 entries added covering all post-release changes.
- **[docs/ROADMAP.md](ROADMAP.md)** ⭐ **UPDATED** — v1.1.39 released section with capability snapshot update.
- **[CHANGELOG.md](../CHANGELOG.md)** ⭐ **UPDATED** — v1.1.39 section with full PR-level detail across 7 categories.
- **[README.md](../README.md)** ⭐ **UPDATED** — v1.1.39 latest updates section, version bump, duplicate section cleanup.

---

## 🆕 Latest Updates - January 2026 ⭐ **LATEST UPDATES**

### WordPress.org Plugin Check Review Complete (January 31, 2026) ✅ **LATEST** ⭐

**NEW:** Comprehensive WordPress.org Plugin Check review completed - **READY FOR SUBMISSION**

- **[WORDPRESS_ORG_PLUGIN_CHECK_REPORT.md](operations/compliance/WORDPRESS_ORG_PLUGIN_CHECK_REPORT.md)** ⭐ **NEW (Jan 31)**
  - Complete compliance audit against WordPress.org plugin directory requirements
  - **Overall Status: ✅ COMPLIANT - Ready for WordPress.org submission**
  - Documentation & Metadata: ✅ PASS (readme.txt, headers, licensing)
  - Code Quality & Security: ✅ PASS (text domain, security practices, file protection)
  - WordPress.org Requirements: ✅ PASS (external services disclosure, GPL licensing, no phone-home)
  - Best Practices: ✅ PASS (activation hooks, database operations, enqueuing)
  - Vendor Dependencies: ✅ PASS with justification (all GPL-compatible)
  - Multisite Support: ✅ PASS (network activation, per-site settings)
  - **Key Findings:**
    - All translation functions use correct text domain 'mcp-ai-wpoos'
    - 16 external services comprehensively documented
    - Patent notice with GPL protection commitment
    - No security concerns or obfuscated code
    - Proper WordPress API usage throughout
    - Comprehensive privacy policy section
  - **Recommendation:** Plugin can be submitted to WordPress.org plugin directory with confidence

- **[WORDPRESS_ORG_SUBMISSION_CHECKLIST.md](operations/compliance/WORDPRESS_ORG_SUBMISSION_CHECKLIST.md)** ⭐ **NEW (Jan 31)**
  - Complete submission checklist and process guide
  - Pre-submission requirements verification (all ✅ complete)
  - Step-by-step submission process
  - SVN setup and initial commit instructions
  - Build instructions for WordPress.org ZIP
  - Post-submission tasks and timeline
  - Expected review questions and answers
  - Success metrics and monitoring
  - Related documentation links

### WordPress Integration Enhancement Proposal (January 28, 2026) 🔧 ⭐

**NEW:** Comprehensive WordPress integration gap analysis and enhancement proposal:

- **[WORDPRESS_INTEGRATION_ENHANCEMENT_PROPOSAL.md](project/proposals/WORDPRESS_INTEGRATION_ENHANCEMENT_PROPOSAL.md)** ⭐ **NEW (Jan 28)**
  - Complete technical specification for 24 WordPress integration improvements
  - Current state: 82/100 → Target: 95/100 integration score
  - **Critical gaps identified:** 82 output escaping issues, 97 enqueue violations
  - 4-phase implementation roadmap (6-7 weeks)
  - Phase 1: WordPress.org compliance (100% approval)
  - Phase 2: Core features (Site Health, Blocks, Privacy API, WP-CLI, Widgets)
  - Phase 3: Developer experience (Taxonomies, Multisite)
  - Phase 4: Testing & documentation
  - Complete code examples and acceptance criteria
  - Risk assessment and resource requirements

- **[WORDPRESS_INTEGRATION_GAPS_EXECUTIVE_SUMMARY.md](history/2026/implementations/WORDPRESS_INTEGRATION_GAPS_EXECUTIVE_SUMMARY.md)** ⭐ **NEW (Jan 28)**
  - Executive overview for decision makers
  - Business impact and ROI analysis
  - Quick wins: Site Health (6-8h), Privacy API (1d), Dashboard widgets (1-2d)
  - Visual progress indicators and score breakdown
  - Timeline and resource requirements
  - Decision framework for stakeholders

- **[WORDPRESS_INTEGRATION_IMPLEMENTATION_CHECKLIST.md](history/2026/implementations/WORDPRESS_INTEGRATION_IMPLEMENTATION_CHECKLIST.md)** ⭐ **NEW (Jan 28)**
  - Step-by-step developer implementation guide
  - Phase-by-phase checklist with testing requirements
  - Code examples and testing commands
  - Success metrics and validation checklist
  - Known issues and gotchas
  - Support resources and references

**Key Findings:**
- 🔴 **WordPress.org Status:** 82% compliant (14/17 requirements met)
- 🔴 **Critical Security:** 82 unescaped output statements across 35+ files
- 🟡 **Standards Violation:** 97 direct script/style tags need enqueue compliance
- ✅ **Quick Wins Available:** 4-5 high-impact improvements (1-2 days each)
- 📊 **Target Improvement:** +13 points overall integration score

**Expected Benefits:**
- ✅ 100% WordPress.org compliance (submission approved)
- ✅ Enhanced security with complete output escaping
- ✅ Better UX with native WordPress patterns (Blocks, Site Health, Widgets)
- ✅ GDPR compliance via Privacy API
- ✅ Professional monitoring via Site Health integration
- ✅ Expanded WP-CLI (1 → 15+ commands)
- ✅ Enterprise-ready multisite improvements

---

### Documentation Organization & Proposals Consolidation (January 28, 2026) 📁 ⭐

**NEW:** Major reorganization of root documentation files and proposals directory:

- **Root Documentation Files Organized:**
  - ✅ **WPCODE_QUICK_START.md** → `docs/guides/developer/wpcode-snippet-activation-tracking-quick-start.md`
  - ✅ **GMAIL_OAUTH_FIX_SUMMARY.md** → Consolidated with `docs/fixes/gmail-oauth-fix-summary.md` (added Jan 27 Google API Client library fix)

- **Proposals Directory Consolidated (37 files → 37 files with 5 consolidated master documents):**
  - **[proposals/README.md](project/proposals/README.md)** ⭐ **NEW** - Comprehensive index and organization
  - **[DEEPSEEK-V4-STATUS-AND-ROADMAP.md](project/proposals/DEEPSEEK-V4-STATUS-AND-ROADMAP.md)** ⭐ **NEW** - Consolidates 8 DeepSeek files into single status document
  - **[WEBLLM-ROADMAP-AND-STATUS.md](project/proposals/WEBLLM-ROADMAP-AND-STATUS.md)** ⭐ **NEW** - Consolidates 5 WebLLM files
  - **[PLAYWRIGHT-WEB-BROWSER-PRO-TOOL-STATUS.md](project/proposals/PLAYWRIGHT-WEB-BROWSER-PRO-TOOL-STATUS.md)** ⭐ **NEW** - Consolidates 3 Playwright files
  - **[BITWARDEN-VAULTWARDEN-INTEGRATION-PROPOSAL.md](project/proposals/BITWARDEN-VAULTWARDEN-INTEGRATION-PROPOSAL.md)** ⭐ **NEW** - Consolidates 3 Bitwarden files
  - **[RALPH-WIGGUM-CCT-ORCHESTRATION.md](project/proposals/RALPH-WIGGUM-CCT-ORCHESTRATION.md)** ⭐ **NEW** - Consolidates 4 Ralph/CCT files

**Key Improvements:**
- 📊 Clear status tracking (✅ Implemented, ⏳ Active, 🔮 Pending, 📚 Reference)
- 🎯 Consolidated status documents as primary references
- 📁 Better organization by feature area and priority
- 🔗 Improved cross-referencing between related documents
- ⏱️ Implementation timeline and decision framework

**Proposals Status Overview:**
- **Implemented:** Playwright Web Browser, WebLLM Phases 1-3, DeepSeek V4 (85-90%)
- **Active:** DeepSeek V4 completion (13-17h), Ralph Wiggum (80-120h)
- **Pending:** Bitwarden/Vaultwarden, WebLLM Phases 4-8
- **Reference:** Size reduction, NPM packages, CLI integration

---

### Web Browser Pro Tool Proposal (January 9, 2026) 🌐 **NEW PROPOSAL** ⭐

**NEW:** Evaluation and decision to create Playwright-based browser automation Pro tool:

- **[PLAYWRIGHT_INTEGRATION_EVALUATION.md](project/proposals/PLAYWRIGHT_INTEGRATION_EVALUATION.md)** ⭐ **NEW (Jan 9)**
  - Full technical evaluation of Playwright integration options
  - Analysis: Enhance web_search vs Create new Pro tool
  - **Decision: Create new Pro tool `web_browser`**
  - Architecture design (external Playwright service)
  - Security considerations and mitigations
  - 8-week implementation roadmap
  - Complete capability flags and parameter schema

- **[WEB_BROWSER_PRO_TOOL_SUMMARY.md](project/proposals/WEB_BROWSER_PRO_TOOL_SUMMARY.md)** ⭐ **NEW (Jan 9)**
  - Executive decision summary for stakeholders
  - **Key rationale: Base version size management** (118 tools, 17MB)
  - Playwright would add ~200MB dependencies (10x increase)
  - Pro tool justification: Resource-intensive, advanced features
  - Clear use case comparison: web_search vs web_browser
  - Implementation plan and success metrics

**Decision Factors:**
- ⚠️ Base version already large (118 tools, 17MB includes)
- Browser automation requires 200-500MB RAM per instance
- Advanced use cases justify Pro tier pricing
- External service architecture (proven with Crawl4AI)

### Complete Code Review & Gap Analysis (January 8, 2026) 🔍 **LATEST** ⭐

**NEW:** Comprehensive code review and plugin gap analysis - **Grade: A- (93/100)**

- **[audit/2026-04/](project/audits/2026-04/README.md)** ⭐ **NEW (Apr 26)** — Complete security & compliance code review of base plugin + all addons. 0 Critical, 5 High, 14 Medium findings; full inventory, findings register, remediation roadmap, WP.org submission checklist.
- **[CODE_REVIEW_2026-02-06.md](history/2026/CODE_REVIEW_2026-02-06.md)** ⭐ **PREVIOUS (Feb 6)** - Complete security code review. Grade A (95/100). All critical vulnerabilities fixed!
- **[CODE_REVIEW_AND_GAP_ANALYSIS_2026-01-08.md](project/code-reviews/CODE_REVIEW_AND_GAP_ANALYSIS_2026-01-08.md)** ⭐ **PREVIOUS (Jan 8)** - Comprehensive audit. Grade A- (93/100). Production ready.
  - Complete codebase audit covering code quality, security, features, documentation
  - **Production Ready Status: ✅ APPROVED**
  - Overall Grade: A- (93/100)
  - Code Quality: 95/100 (Excellent)
  - Security: 100/100 (Perfect)
  - Documentation: 95/100 (Excellent)
  - Feature Completeness: 92/100 (Good)
  - Test Coverage: 85/100 (Good)
  - **Key Finding:** Development dependencies removed for production deployment
  - Tool Analysis: 215 tools total (96% mature, 3 buggy tools auto-disabled)
  - Documentation Analysis: 838 files reviewed
  - Gap Prioritization: High/Medium/Low with effort estimates
  - Production Readiness Checklist
  - Recommendations for short-term and long-term improvements

### Root Directory Reorganization (January 8, 2026) 📁 **CLEANUP COMPLETE**

Complete consolidation and reorganization of temporary documentation files:

- **[Root Directory Consolidation](history/2026/ROOT-DOCS-REORGANIZATION.md)** ⭐ **NEW (Jan 8)**
  - Cleaned up 19 temporary fix documentation files from root
  - Consolidated Chart.js and Pro Dashboard fixes into single document
  - Root directory now contains only 6 essential files
  - All historical information preserved in proper hierarchy
  
- **[CHART-JS-PRO-DASHBOARD-CONSOLIDATION.md](history/2026/fixes/CHART-JS-PRO-DASHBOARD-CONSOLIDATION.md)** ⭐ **NEW (Jan 8)**
  - Comprehensive consolidation of all Chart.js and Pro Dashboard fixes
  - Covers 15 root MD files → 1 consolidated reference
  - Technical architecture, testing procedures, and lessons learned
  - Cross-referenced with detailed docs in `docs/fixes/` and `docs/troubleshooting/`
  
- **[pro-dashboard-visual-test-guide.md](developer/testing-docs/pro-dashboard-visual-test-guide.md)** ⭐ **MOVED (Jan 8)**
  - Visual testing guide for Pro Dashboard charts
  - Moved from root to proper testing documentation location
  - Complete visual reference with expected chart appearances

**Files Consolidated:**
- 8 Chart.js fix summaries
- 4 Pro Dashboard fix summaries  
- 2 Pull request summaries
- 3 Implementation summaries
- 1 Visual test guide
- 1 Monitoring tab summary

### Dead Letter Queue & SLA Prioritization (January 3, 2026) ⭐ **NEW FEATURES**

Enterprise-grade failure handling and intelligent job prioritization for WordPress-native cron:

- **[dead-letter-queue.md](developer/architecture/dead-letter-queue.md)** ⭐ **NEW (Jan 3)**
  - Persistent storage for failed webhooks, cron jobs, async tools, and queue items
  - Automatic retry with exponential backoff + jitter (prevents thundering herd)
  - Max 1000 items with 30-day retention and weekly cleanup cron
  - Admin UI at `wp-admin/admin.php?page=wp-mcp-ai-dlq-manager`
  - WP-CLI commands: `wp mcp-ai dlq list/stats/retry/dismiss/delete/purge/clear`
  - Integration: Job Queue Manager, Job Notifier, Crawl4AI
  
- **[sla-prioritization.md](developer/architecture/sla-prioritization.md)** ⭐ **NEW (Jan 3)**
  - Three-tier SLA system: Real-time (<1s), Near real-time (1-30s), Batch (>30s)
  - Little's Law capacity planning (`L = λ × W`) for optimal worker allocation
  - Per-tier concurrency limits (realtime: 5, near-realtime: 3, batch: 2)
  - Automatic tier inference from tool capabilities
  - Tuning recommendations and queue health monitoring
  - WP-CLI commands: `wp mcp-ai sla status/tune/analyze/enable/disable`

- **Enhanced Admin UI:**
  - DLQ Manager page with filters, bulk actions, and statistics
  - Cron Manager shows DLQ stats and SLA tier configuration
  - WordPress Dashboard widget for at-a-glance queue health
  - Color-coded status indicators (green/yellow/red)

- **Updated Documentation:**
  - `ORCHESTRATION-LAYER-ARCHITECTURE.md` - New DLQ & SLA section (20KB → 30KB)
  - `job-notification-system.md` - Webhook retry and DLQ integration
  - Complete API reference and troubleshooting guides

**Impact:** Transforms WordPress cron from "fire-and-forget" to production-grade orchestration with failure recovery, SLA guarantees, and capacity management.

### Gap Documentation Review (January 3, 2026) 🎉 **100% HIGH-PRIORITY COMPLETE**

Comprehensive review of all gap documentation with exceptional results:

- **[GAP_DOCUMENTATION_REVIEW_SUMMARY.md](history/2026/implementations/GAP_DOCUMENTATION_REVIEW_SUMMARY.md)** ⭐ **NEW (Jan 3)**
  - **🎉 100% high-priority completion achieved** (10 of 10 items complete)
  - Quality Score: 98/100 (up from 95/100)
  - Production Readiness: 98/100 (up from 95/100)
  - All critical gaps addressed including:
    - Output Escaping (66 systematic fixes) ✅
    - CI/CD Quality Gates (GitHub Actions active) ✅
    - Code Coverage Dashboard (Codecov integration) ✅
    - OpenAI Batch API (50% cost reduction) ✅
    - OpenAI Moderation API (11 violation categories) ✅
    - Gemini Batch Embeddings, Safety Settings, Thinking Mode ✅
  - Medium-Priority: 67% complete (8 of 12 items)
  - Status: **Production Ready** ✅

- **[GAP_DOCUMENTATION_STATUS_UPDATE_2026-01-03.md](history/2025/summaries/GAP_DOCUMENTATION_STATUS_UPDATE_2026-01-03.md)** ⭐ **NEW (Jan 3)**
  - Master status document (19KB) with detailed analysis
  - All 6 gap documents reviewed and updated
  - Complete implementation progress tracking
  - Phase completion status for OpenAI and Gemini APIs
  - Consolidated priority tracking and recommendations

### Code Coverage Dashboard (January 3, 2026) ⭐ **NEW FEATURE**

Complete code coverage tracking and visualization system:

- **[CODE_COVERAGE_DASHBOARD.md](developer/testing-docs/CODE_COVERAGE_DASHBOARD.md)** ⭐ **NEW (Jan 3)**
  - Codecov integration with badge ✅
  - Automatic coverage reporting in CI/CD
  - Interactive online dashboard at codecov.io
  - Local HTML dashboard generation
  - Component-based coverage tracking
  - Coverage targets: 70%+ core, 80%+ REST API
  - PHPUnit with Xdebug for PHP coverage
  - Jest for JavaScript coverage
  - Instructions for viewing and generating reports

### Code Review and Repository Organization (January 2, 2026)

Post-December 23 changes review with historical tool count verification (superseded by the May 17, 2026 Rev 2.0 fact sheet):

- **[CODE_REVIEW_2026-01-02.md](history/2025/code-reviews/CODE_REVIEW_2026-01-02.md)** ⭐ **NEW (Jan 2)**
  - Comprehensive code review (A- grade, 92/100)
  - Security: 10/10 (zero vulnerabilities)
  - JavaScript: 10/10 (ESLint clean)
  - Architecture: 9.5/10 (excellent design)
  - Historical tool count verification completed
    - Superseded current framing: ~830 tools (~195 base + ~635 Pro)
    - Live registry remains authoritative
  - PHP linting results: 1,083 errors, 1,294 warnings
  - 235 auto-fixable issues identified
  - Production ready status confirmed

- **[CODE_REVIEW_SUMMARY_2026-01-02.md](history/2025/code-reviews/CODE_REVIEW_SUMMARY_2026-01-02.md)** ⭐ **NEW (Jan 2)**
  - Quick summary of code review findings
  - Score breakdown by category
  - Actions taken and documentation updates
  - Tool inventory verification details
  - Linting results and recommendations

- **[ROOT_DIRECTORY_ORGANIZATION_2026-01-02.md](history/2025/documentation/ROOT_DIRECTORY_ORGANIZATION_2026-01-02.md)** ⭐ **NEW (Jan 2)**
  - Root directory cleanup: 15 → 7 files
  - Moved 6 fix documents to `docs/fixes/`
  - Moved 2 implementation summaries to `docs/implementation-summaries/`
  - Updated all cross-references
  - Created `docs/implementation-summaries/README.md`
  - Improved repository organization and documentation discovery

---

## 🆕 Weekly Summaries

### Weekly Commits Summary (December 16-23, 2025)
Comprehensive consolidation of all commits and changes from the past week:

- **[WEEKLY_COMMITS_SUMMARY_2025-12-23.md](history/2025/WEEKLY_COMMITS_SUMMARY_2025-12-23.md)** ⭐ **NEW (Dec 23)**
  - **COMPLETE WEEKLY REVIEW** of all commits from December 16-23, 2025
  - Primary PR #2364: Profession model architecture improvements
  - 2 commits reviewed, 300+ files changed, ~100,000 lines
  - Major changes: Profession test model re-architecture, model settings merge fix
  - New features: 6 AI integrations (Gemini Geospatial, OpenAI Batch, Moderation, GPT-5.2, GPT-Image-1.5, Symfony Process)
  - Bug fixes: 11 major fixes (async tools, SSE streaming, chat UI, security)
  - Documentation: Status updates, consolidation, organization (549 files)
  - Code quality: Improved to 98/100 (97.5% issue reduction)
  - Testing: 40+ new test cases, comprehensive coverage
  - Breaking changes: Pro tool reorganization (6 tools moved)
  - Migration guide included
  - Statistics and next steps documented
  - Zero information loss - everything preserved

---

## 🆕 Master Consolidation Documents (December 20-22, 2025) ⭐ **START HERE**

### Complete Consolidation
Comprehensive consolidation of ALL fixes, summaries, code reviews, and improvements from 2025:

- **[CONSOLIDATED_IMPLEMENTATION_SUMMARIES_2025.md](history/2025/summaries/CONSOLIDATED_IMPLEMENTATION_SUMMARIES_2025.md)** ⭐ **NEW (Dec 22)**
  - **CENTRAL INDEX** of all major implementations, fixes, and improvements completed in 2025
  - Quick navigation to all feature implementations, API enhancements, bug fixes
  - Includes Gemini Geospatial, OpenAI Batch/Moderation, IGCSE Teams, code quality improvements
  - Complete cross-references to detailed implementation documents
  - Statistics and quality metrics summary
  - [2025 Implementation History Index](history/2025/INDEX.md) - Directory structure navigation

- **[MASTER_CONSOLIDATION_2025.md](history/2025/summaries/MASTER_CONSOLIDATION_2025.md)** ⭐ **PRIMARY REFERENCE (Dec 20)**
  - **SINGLE SOURCE OF TRUTH** for all 2025 work
  - Complete consolidation of 55+ source documents
  - Zero information loss - everything preserved
  - Organized by: Code Reviews, Fixes, Features, Security, Testing
  - 1,000+ lines, comprehensive reference
  - Cross-references to all original documents

- **[CONSOLIDATION_MAP.md](history/2025/summaries/CONSOLIDATION_MAP.md)** ⭐ **NAVIGATION GUIDE**
  - Detailed map showing what was consolidated from where
  - Section-by-section source document mapping
  - Verification checklist ensuring zero information loss
  - Document relationship diagrams
  - Usage guidelines for finding information

**What's Consolidated:**
- ✅ All 5 December 2025 code reviews (CODE_REVIEW_2025-12-*.md)
- ✅ IMPROVEMENTS_SUMMARY.md (Dec 19, A grade 98/100)
- ✅ CONSOLIDATED_BUGS_AND_FIXES.md (689 lines, all bugs)
- ✅ CONSOLIDATED_SESSION_SUMMARIES.md (469 lines, all sessions)
- ✅ All fix documentation from docs/ and docs/fixes/
- ✅ References to 87 archived summaries
- ✅ Security, testing, performance information
- ✅ Pro addon tools (38 tools documented)
- ✅ GPT-5.2 model family (6 variants)

**Key Achievements in 2025:**
- Overall Quality Score: 98/100 (Excellent)
- Code Standards: 96% reduction in violations (20,000+ → <1,000)
- Security: 100/100 (zero critical vulnerabilities)
- Documentation: 535+ files (100/100 quality score)
- Testing: 504 test files, ~70% coverage

---

---

## 🆕 Documentation Quality Review (December 29, 2025) ⭐ **NEW**

### Comprehensive Documentation Review and Assessment
Complete review of all implementations vs. documentation coverage:

- **[DOCUMENTATION_REVIEW_SUMMARY.md](history/2026/implementations/DOCUMENTATION_REVIEW_SUMMARY.md)** ⭐ **NEW (Dec 29)**
  - **Grade A (95/100)** - Excellent documentation quality
  - 659 documentation files reviewed
  - 100% major feature coverage verified
  - Zero significant documentation gaps found
  - All 2025 implementations fully documented
  - 717 broken links fixed in December 2025 (100% success)
  - Detailed recommendations for minor enhancements
  - [Full Detailed Review](history/2025/documentation/DOCUMENTATION_REVIEW_2025-12-29.md)

---

## 🆕 Project Management System Documentation (December 24, 2025) ⭐ **NEW**

### Comprehensive Project Management Gap Analysis and Strategy
Complete review and enhancement of project management capabilities for the NV oOS repository:

- **[PROJECT_MANAGEMENT_GAP_ANALYSIS.md](project/PROJECT_MANAGEMENT_GAP_ANALYSIS.md)** ⭐ **NEW (Dec 24)**
  - Comprehensive gap analysis of internal WordPress PM features and GitHub repository management
  - 14 identified gaps with priority-based recommendations and effort estimates
  - 4-phase implementation roadmap (13-17 business days)
  - Success metrics and ROI analysis

- **[ROADMAP.md](ROADMAP.md)** ⭐ **NEW (Dec 24)**
  - Public product roadmap with vision and strategic direction
  - Release planning through v2.0.0 (Sep 2026) and beyond
  - Community priority tracking and 2026 release calendar

- **[MILESTONE_STRATEGY.md](project/MILESTONE_STRATEGY.md)** ⭐ **NEW (Dec 24)**
  - Milestone management strategy for release planning
  - Complete milestone lifecycle and automation integration

- **[LABEL_STRATEGY.md](project/LABEL_STRATEGY.md)** ⭐ **NEW (Dec 24)**
  - Complete label taxonomy: 50 labels across 7 categories
  - Automatic labeling workflows and integration with GitHub Projects

- **[RELEASE_PROCESS.md](project/releases/RELEASE_PROCESS.md)** ⭐ **NEW (Dec 24)**
  - Complete release management process with checklists
  - Emergency hotfix and rollback procedures

## 🆕 Latest Additions (December 20, 2025)

### Documentation Status Updates (CURRENT) ⭐ **NEW**
Comprehensive review and update of documentation completion status across repository:

- **[DOCUMENTATION_UPDATE_STATUS_2025-12-20.md](history/2025/documentation/DOCUMENTATION_UPDATE_STATUS_2025-12-20.md)** ⭐ **NEW**
  - Systematic tracking document for reviewing all 549 documentation files
  - Progress tracker: 10 of 549 documents updated (1.8%)
  - Prioritized 5-tier review structure
  - Completion methodology documented
  - Quality score improvements tracked (95→98/100)
  
- **Updated Documentation Files (December 20, 2025):**
  - [GAP_ANALYSIS_EXECUTIVE_SUMMARY.md](history/2025/summaries/GAP_ANALYSIS_EXECUTIVE_SUMMARY.md) - Marked 4 of 5 high-priority gaps complete
  - [ACTION_ITEMS.md](history/2025/summaries/ACTION_ITEMS.md) - Output escaping, input sanitization, JSDoc marked complete
  - [QUICK_WINS_GAP_FIXES.md](history/2025/summaries/QUICK_WINS_GAP_FIXES.md) - CI/CD gates, error documentation complete
  - [PLUGIN_GAP_ANALYSIS.md](history/2025/summaries/PLUGIN_GAP_ANALYSIS.md) - PHP and JavaScript sections marked resolved
  - [REMAINING_ISSUES.md](history/2025/summaries/REMAINING_ISSUES.md) - 97.5% PHPCS reduction documented
  - [CONSOLIDATED_BUGS_AND_FIXES.md](history/2025/summaries/CONSOLIDATED_BUGS_AND_FIXES.md) - Status updated to 98/100
  - README.md - Tool count corrected (71→95 core, 109→133 total)
  - CHANGELOG.md - Gemini integration and documentation updates added

**Key Updates Documented:**
- ✅ Output escaping: 66 systematic fixes completed
- ✅ CI/CD quality gates: GitHub Actions with PHPCS, ESLint, PHPUnit, CodeQL
- ✅ Security scanning: CodeQL active
- ✅ Input sanitization: Security score 100/100
- ✅ JavaScript documentation: JSDoc added, ESLint passing
- ✅ CodeSniffer: 97.5% reduction (4,410 → ~40 issues)
- ✅ Tool count methodology: Validated variants counted as same tool

### Gemini API Integration Gap Analysis (December 20, 2025)
Comprehensive analysis and documentation of Gemini integration capabilities:

- **[GEMINI_INTEGRATION_GAP_ANALYSIS.md](features/ai-providers/gemini/GEMINI_INTEGRATION_GAP_ANALYSIS.md)** ⭐ **NEW**
  - 14 enhancement opportunities identified across 5 categories
  - Missing API endpoints: Batch embeddings, context caching, thinking mode
  - Incomplete features: Enhanced image editing with masks, video analysis
  - Tool enhancements and developer experience improvements
  - Cost savings potential: 68% with context caching

- **[GEMINI_INTEGRATION_EXECUTIVE_SUMMARY.md](features/ai-providers/gemini/GEMINI_INTEGRATION_EXECUTIVE_SUMMARY.md)** ⭐ **NEW**
  - Current state: 15 of 30 major endpoints implemented (50%)
  - Top 5 enhancement opportunities with ROI analysis
  - Implementation roadmap with 4 phases (78-108 hours)
  - Cost savings analysis and production recommendations

- **Related Gemini Documentation:**
  - [GEMINI_CAPABILITIES_MATRIX.md](reference/api/gemini/GEMINI_CAPABILITIES_MATRIX.md) - Feature comparison matrix
  - [GEMINI_INTEGRATION_ANALYSIS_INDEX.md](features/ai-providers/gemini/GEMINI_INTEGRATION_ANALYSIS_INDEX.md) - Navigation guide
  - [GEMINI_OPENAI_TOOLS_ARCHITECTURE.md](features/ai-providers/gemini/GEMINI_OPENAI_TOOLS_ARCHITECTURE.md) - Architecture docs
  - [GEMINI_TOOL_SANITIZATION_FIX.md](history/2025/fixes/gemini/GEMINI_TOOL_SANITIZATION_FIX.md) - Implementation details

### Hugging Face Integration (December 23, 2025)
Complete integration of Hugging Face capabilities including Inference API and Dataset Viewer:

#### Hugging Face Datasets - Dataset Viewer API Integration ⭐ **NEW (Dec 23)**
Access 50+ popular datasets directly from WordPress without downloading:

- **[HUGGINGFACE_DATASETS_HOW_TO.md](features/HUGGINGFACE_DATASETS_HOW_TO.md)** ⭐ **NEW**
  - Complete step-by-step how-to guide with real examples
  - Setup and configuration walkthrough
  - All 11 dataset tools explained with expected outputs
  - WordPress integration patterns (comments, posts, WooCommerce)
  - Advanced patterns (chaining tools, caching, batch processing)
  - REST API usage with cURL, JavaScript, jQuery
  - Troubleshooting guide with solutions
  - Best practices for production use
  - 1,139 lines, comprehensive guide

- **[huggingface-datasets-code-examples.md](examples/huggingface-datasets-code-examples.md)** ⭐ **NEW**
  - 30+ complete, working code examples
  - Basic PHP usage of all dataset tools
  - AI Assistant conversation examples
  - REST API examples (cURL, Fetch, jQuery)
  - WordPress shortcodes and widgets
  - WooCommerce product review sentiment analysis
  - Complete comment moderation plugin example
  - Copy-paste ready code snippets
  - 962 lines, production-ready examples

- **[HUGGINGFACE_DATASETS_QUICK_START.md](features/ai-providers/huggingface/HUGGINGFACE_DATASETS_QUICK_START.md)**
  - Quick reference guide for fast access
  - Browse datasets in admin UI
  - AI Assistant usage examples
  - Available tools reference
  - Top datasets by use case
  - Tips and troubleshooting

- **[HUGGINGFACE_TOP_DATASETS.md](features/ai-providers/huggingface/HUGGINGFACE_TOP_DATASETS.md)**
  - 50+ curated free datasets catalog
  - Organized by category (NLP, Vision, Audio, Multimodal)
  - Use cases and integration priority
  - Dataset descriptions and sizes
  - WordPress-specific applications

**Dataset Tools (11 Total):**
- `huggingface_dataset_is_valid` - Check dataset availability
- `huggingface_dataset_get_info` - Get metadata and description
- `huggingface_dataset_list_splits` - List train/test/validation splits
- `huggingface_dataset_preview_rows` - Preview first N rows
- `huggingface_dataset_get_rows` - Get paginated rows
- `huggingface_dataset_search` - Search within dataset content
- `huggingface_dataset_filter` - Filter with SQL-like WHERE clauses
- `huggingface_dataset_get_statistics` - Get column statistics
- `huggingface_dataset_get_size` - Get dataset size info
- `huggingface_dataset_get_parquet` - Get Parquet download URLs
- `huggingface_recommended_datasets` - Smart dataset recommendations

**Key Features:**
- Query 50+ datasets without downloading
- Search, filter, and paginate dataset rows
- Caching with configurable TTL (default: 1 hour)
- Rate limiting (60 requests/hour, upgradeable)
- WordPress integration patterns
- AI Assistant ready
- Admin UI for browsing datasets

#### Hugging Face Inference API (December 22, 2025)
Complete integration of Hugging Face Inference API as a provider for open-source models:

- **[HUGGINGFACE_SETUP.md](features/ai-providers/huggingface/HUGGINGFACE_SETUP.md)** ⭐ **NEW**
  - Step-by-step setup guide for Hugging Face provider
  - API token generation and configuration
  - Model selection guide (Llama, Mistral, Phi, Qwen, and more)
  - Inference Endpoints setup for production workloads
  - Troubleshooting common issues
  - Cost optimization and best practices
  - Comparison with other providers

**Key Features:**
- OpenAI-compatible API interface
- Access to thousands of open-source models
- Flexible pricing (free tier, Pro, Inference Endpoints)
- Support for custom fine-tuned models
- Privacy-friendly (can self-host)
- Integrated with provider priority and fallback system

### NVIDIA NIM Integration (April 2026)
Cloud AI inference via NVIDIA's optimized model platform with 40+ models:

- **[NVIDIA_NIM_SETUP.md](features/ai-providers/nvidia/NVIDIA_NIM_SETUP.md)** ⭐ **NEW**
  - Step-by-step setup guide for NVIDIA NIM provider
  - API key generation via build.nvidia.com
  - Getting Started wizard and Settings page configuration
  - Self-hosted NIM container support
  - Available models catalog (Llama, Mistral, Nemotron, Gemma 4/3/2, Qwen, DeepSeek)
  - Troubleshooting common issues

**Key Features:**
- OpenAI-compatible API interface
- 40+ optimized models (Llama 3.x, Mistral, Nemotron, Gemma 4/3/2, Qwen, DeepSeek R1)
- Cloud inference via `integrate.api.nvidia.com` or self-hosted NIM containers
- Integrated with provider priority and fallback system
- Getting Started wizard support for first-time setup

### GPT-5.2 Model Support (December 16, 2025)
Complete OpenAI GPT-5.2 model family integration with comprehensive testing and documentation:

- **[CODE_REVIEW_2025-12-16.md](history/2025/code-reviews/CODE_REVIEW_2025-12-16.md)** ⭐ **NEW**
  - Complete code review of PR #2144 (GPT-5.2 model support)
  - 6 model variants documented: base, pro, instant, thinking, and dated versions
  - 400K token context window analysis (2x GPT-5.1)
  - Rate limits, pricing, and fallback chains verified
  - 100% test coverage confirmed
  - Security and performance analysis (A+ grades)
  - Ready for production deployment

**Key Achievements:**
- ✅ 6 new GPT-5.2 model variants added to model config
- ✅ 400K context window support (2x previous generation)
- ✅ Comprehensive PHPUnit test coverage (100%)
- ✅ CHANGELOG.md updated with complete specifications
- ✅ README.md model table updated
- ✅ Zero security concerns identified
- ✅ Backward compatible implementation
- 📊 Impact: Enhanced AI capabilities, larger document processing, better reasoning

## Previous Additions (December 9, 2025)

### Symfony Phase 2B: Process Integration (COMPLETE)
Symfony Process component integration for Pro addon tools completed:

- **[SYMFONY_PHASE2B_PROCESS_INTEGRATION.md](history/2025/implementations/symfony-phases/SYMFONY_PHASE2B_PROCESS_INTEGRATION.md)** ⭐ **NEW**
  - Complete migration guide for Symfony Process integration
  - 6 Pro tools migrated from direct exec() calls
  - 2 services migrated (Jukebox, Video Frame Extractor)
  - Process Service wrapper implementation details
  - Enhanced security, timeout management, error handling

- **[SYMFONY_SESSION_2025-12-09.md](history/2025/implementations/symfony-phases/SYMFONY_SESSION_2025-12-09.md)**
  - December 9 session summary
  - Symfony Phase 2A completion (tool validation)
  - 10 tools migrated to Symfony Validator pattern

**Key Achievements:**
- ✅ COMPLETE: 14 exec() calls replaced with Symfony Process
- ✅ Enhanced security with proper argument escaping
- ✅ Configurable timeouts and graceful error handling
- ✅ WordPress-friendly wrappers with WP_Error integration
- 📊 Impact: Safer process execution, better error reporting, improved maintainability

## Previous Updates (December 8, 2025)

### Symfony Integration Analysis
Comprehensive evaluation of Symfony framework components for NV oOS enhancement:

- **[SYMFONY_INTEGRATION_EXECUTIVE_SUMMARY.md](history/2025/implementations/integrations/SYMFONY_INTEGRATION_EXECUTIVE_SUMMARY.md)** ⭐ **START HERE**
  - Decision-maker focused overview
  - Financial analysis: $63-84K investment, 275% ROI over 5 years
  - Clear recommendations and next steps
  - 14KB, quick read (~15 minutes)

- **[SYMFONY_AI_INTEGRATION_ANALYSIS.md](history/2025/implementations/integrations/SYMFONY_AI_INTEGRATION_ANALYSIS.md)**
  - Deep-dive on Symfony AI capabilities vs NV oOS features
  - Semantic search/RAG implementation proposal
  - Phase-based rollout plan (Q1-Q3 2026)
  - 26KB, technical analysis

- **[SYMFONY_UTILITIES_RECOMMENDATIONS.md](history/2025/implementations/integrations/SYMFONY_UTILITIES_RECOMMENDATIONS.md)**
  - Component-by-component evaluation (8 Symfony components)
  - Validator, Cache, Filesystem integration strategies
  - Detailed migration plans with code examples
  - 30KB, implementation guide

**Key Findings:**
- ✅ RECOMMENDED: Symfony Validator, Cache, Filesystem, AI Embeddings, Process
- ❌ NOT RECOMMENDED: Full AI framework, MCP SDK replacement, Form, Console, Serializer
- 📊 Impact: Remove 6,000-8,000 lines of code, 40-60% performance improvement
- 💰 Investment: $63-84K over 6 months | ROI: 275% over 5 years

## Previous Updates (December 6-7, 2025)

### Comprehensive Gap Analysis Suite
- **[GAP_ANALYSIS_EXECUTIVE_SUMMARY.md](history/2025/summaries/GAP_ANALYSIS_EXECUTIVE_SUMMARY.md)** - Executive overview of comprehensive gap analysis
- **[PLUGIN_GAP_ANALYSIS.md](history/2025/summaries/PLUGIN_GAP_ANALYSIS.md)** - Full gap analysis across 9 categories (23KB, 35 gaps identified)
- **[QUICK_WINS_GAP_FIXES.md](history/2025/summaries/QUICK_WINS_GAP_FIXES.md)** - Step-by-step quick fix implementations with code examples (22KB)

**📋 Documentation Reorganization (November 18, 2025):**
- 95+ historical documents moved to organized archive structure
- Root directory now contains only 5 essential documents (README, CONTRIBUTING, SECURITY, CHANGELOG, BUILD)
- All documentation preserved in [docs/archive/](history/archive/) with logical organization:
  - [implementations/](history/archive/#implementations) - Feature implementation details
  - [phases/](history/archive/#phases) - Development phase documents
  - [fixes/](history/archive/#fixes) - Bug fixes and issue resolutions
  - [features/](history/archive/#features) - Feature specifications
  - [code-reviews/](history/archive/#code-reviews) - Historical code reviews
  - [testing/](history/archive/#testing) - Test infrastructure documentation
- **NEW (December 3, 2025):** [CONSOLIDATED_BUGS_AND_FIXES.md](history/2025/summaries/CONSOLIDATED_BUGS_AND_FIXES.md) - Comprehensive bugs and fixes report with Pro addon updates
- **NEW (December 4, 2025):** [CODE_REVIEW_2025-12-04.md](history/2025/code-reviews/CODE_REVIEW_2025-12-04.md) - Complete code review and enhancement update
- **NEW (December 6, 2025):** [CODE_REVIEW_2025-12-06.md](history/2025/code-reviews/CODE_REVIEW_2025-12-06.md) - Comprehensive code review with 96/100 score - security audit, quality analysis, documentation assessment
- Consolidated report: [TESTING_AND_QUALITY_REPORT.md](developer/testing-docs/TESTING_AND_QUALITY_REPORT.md) - Complete testing and code quality analysis

---

## 📚 Quick Navigation

### For New Users
1. [README.md](../README.md) - Start here for overview and installation
2. **Getting Started Wizard** ⭐ — Activate the plugin and follow the 4-step wizard (`NV oOS → Getting Started`) to connect a provider and create your first assistant in under 2 minutes
3. [mcp-ai-plugin-setup-checklist.md](getting-started/installation-setup/mcp-ai-plugin-setup-checklist.md) - Step-by-step setup guide
4. [google-oauth-setup.md](getting-started/installation-setup/google-oauth-setup.md) - Google OAuth setup for Gmail integration
5. [remote-client-quickstart.md](getting-started/quick-starts/remote-client-quickstart.md) - Quick start for remote clients
6. [BEST_PRACTICES.md](developer/best-practices/BEST_PRACTICES.md) - Best practices and recommendations

### For Developers
1. **[CONSOLIDATED_BUGS_AND_FIXES.md](history/2025/summaries/CONSOLIDATED_BUGS_AND_FIXES.md)** - **NEW:** Comprehensive bugs and fixes report (Pro addon, async execution, SSE streaming, code quality)
2. **[TESTING_AND_QUALITY_REPORT.md](developer/testing-docs/TESTING_AND_QUALITY_REPORT.md)** - Complete testing and quality analysis (2,106 tests, code quality, security audit)
3. [CODE-REVIEW-MASTER.md](developer/best-practices/CODE-REVIEW-MASTER.md) - Comprehensive code review (95/100 score)
4. [DEVELOPMENT-HISTORY.md](visual-guides/misc/DEVELOPMENT-HISTORY.md) - Consolidated development history and milestones
5. [TECHNICAL-REFERENCE.md](reference/technical/TECHNICAL-REFERENCE.md) - Consolidated technical fixes and implementations
6. [ACTION_ITEMS.md](history/2025/summaries/ACTION_ITEMS.md) - Prioritized development tasks
7. [tool-reference.md](reference/tools/tool-reference.md) - Complete tool catalog
8. [tool-grouping.md](reference/tools/tool-grouping.md) - Tool categorization system
9. [rest-api.md](reference/api/rest-api.md) - REST API reference

### For System Administrators
1. [deployment-troubleshooting.md](getting-started/installation-setup/deployment-troubleshooting.md) - Troubleshooting guide
2. [mcp-server-authentication.md](reference/api/mcp-server-authentication.md) - Authentication setup
3. [google-oauth-setup.md](getting-started/installation-setup/google-oauth-setup.md) - Google OAuth setup for Gmail integration
4. [tools-manager.md](admin-guides/tools/tools-manager.md) - Tools Manager admin interface guide
5. [rate-limit-protection.md](features/performance/rate-limit-protection.md) - Rate limiting configuration
6. [multisite-support.md](getting-started/installation-setup/multisite-support.md) - Multisite considerations
7. [mesh-compute-pooling.md](features/federation/mesh-compute-pooling.md) - Distributed compute pooling across sites

### Historical Documentation
- **[Archive Directory](history/archive/README.md)** - 95+ historical documents organized by category
  - Implementation summaries, phase documents, fix reports, features, code reviews, testing docs

---

## 📖 Core Documentation

### NPM Package Distribution Documentation ⭐ **NEW (Feb 5, 2026)**

**Can parts of this plugin be distributed as NPM packages? YES!**

- **[NPM_PACKAGE_DISTRIBUTION.md](project/releases/NPM_PACKAGE_DISTRIBUTION.md)** ⭐ **NEW**
  - Quick start guide for NPM package extraction
  - Executive summary with key findings (67 JS files analyzed, 15+ extractable components)
  - Timeline: 8-12 weeks for first three packages, 2-3 weeks for pilot
  - Resource requirements: 1-2 developers at 50-75% time
  - [Full Documentation →](project/releases/npm-packages/README.md)

**Complete NPM Package Documentation Suite** (1,582 lines, 42KB):
- **[npm-packages/README.md](project/releases/npm-packages/README.md)** - Index and quick reference
- **[npm-packages/STRATEGY_BLUEPRINT.md](project/releases/npm-packages/STRATEGY_BLUEPRINT.md)** - Decision-making framework
- **[npm-packages/EXTRACTION_GUIDE.md](project/releases/npm-packages/EXTRACTION_GUIDE.md)** - Technical implementation
- **[npm-packages/COMPONENT_ANALYSIS.md](project/releases/npm-packages/COMPONENT_ANALYSIS.md)** - Detailed component evaluation

**Key Findings:**
- ✅ Tier 1 Components (Ready Now): Markdown renderer (0/10 WP dep), event system (0-2/10), storage utilities (1/10)
- ⚙️ Tier 2 Components (Minor Changes): Audio toolkit, transcription, file uploads
- ❌ Not Suitable: Admin interfaces, REST endpoints, WordPress-specific code
- 💰 Low risk, high value opportunity for brand building and community engagement

---

### 🆕 Key Reference Documents - MASTER CONSOLIDATION

**🎯 START HERE - Consolidated Master Documents:**

| Document | Description | Audience |
|----------|-------------|----------|
| **[CONSOLIDATED_BUGS_AND_FIXES.md](history/2025/summaries/CONSOLIDATED_BUGS_AND_FIXES.md)** | **⭐ MASTER:** ALL bugs, fixes, improvements (Dec 7, 2025) - Pro tools, async execution, output escaping, site creator, SSE streaming, CodeSniffer cleanup | Developers/QA |
| **[CONSOLIDATED_SESSION_SUMMARIES.md](history/2025/summaries/CONSOLIDATED_SESSION_SUMMARIES.md)** | **⭐ MASTER:** ALL development sessions consolidated - December 2025, November 2025, archived sessions with complete work history | Developers/PM |
| **[CODE-REVIEW-MASTER.md](developer/best-practices/CODE-REVIEW-MASTER.md)** | **⭐ MASTER:** Consolidated code reviews - 96/100 score, all categories, review history | Developers/QA |
| **[TESTING_AND_QUALITY_REPORT.md](developer/testing-docs/TESTING_AND_QUALITY_REPORT.md)** | Comprehensive testing analysis - 2,106 tests, 73.4% pass rate, code quality metrics | Developers/QA |

**Individual Code Reviews (For Reference):**

| Document | Description | Audience |
|----------|-------------|----------|
| [CODE_REVIEW_2025-12-06.md](history/2025/code-reviews/CODE_REVIEW_2025-12-06.md) | Latest comprehensive review (Dec 6, 2025) - 96/100 score, 21KB analysis | Developers/QA |
| [CODE_REVIEW_2025-12-04.md](history/2025/code-reviews/CODE_REVIEW_2025-12-04.md) | Previous review - enhancements and improvements | Developers/QA |
| [REMAINING_ISSUES.md](history/2025/summaries/REMAINING_ISSUES.md) | Active code quality issues tracking | Developers |
| [DEVELOPMENT-HISTORY.md](visual-guides/misc/DEVELOPMENT-HISTORY.md) | Chronological development history | Developers |
| [TECHNICAL-REFERENCE.md](reference/technical/TECHNICAL-REFERENCE.md) | Consolidated technical documentation | Developers |
| [CODE-REVIEW-MASTER.md](developer/best-practices/CODE-REVIEW-MASTER.md) | Comprehensive code review (95/100 score) | Developers |

### Getting Started

| Document | Description | Audience |
|----------|-------------|----------|
| [README.md](../README.md) | Main plugin documentation with features, installation, and usage | Everyone |
| [USE_CASES_AND_QUICKSTARTS.md](getting-started/USE_CASES_AND_QUICKSTARTS.md) | **NEW:** Comprehensive use cases and quickstart guides covering 7 major categories (41KB) | Everyone |
| [QUICK_START_5_MINUTES.md](getting-started/QUICK_START_5_MINUTES.md) | 5-minute quick start guide from zero to first chat | Beginners |
| [mcp-ai-plugin-setup-checklist.md](getting-started/installation-setup/mcp-ai-plugin-setup-checklist.md) | Complete setup checklist for new installations | Admins |
| [BEST_PRACTICES.md](developer/best-practices/BEST_PRACTICES.md) | Recommended practices for using NV oOS | All Users |
| **Onboarding Wizard** (built-in) | ⭐ **NEW:** 4-step Getting Started wizard at `/wp-admin/admin.php?page=wp-mcp-ai-getting-started`. 8 use-case presets seed fully-configured assistants with tools, system prompts, and temperatures. WCAG 2.1 accessible. See [README.md](../README.md#-installation). | Everyone |

### Architecture & Design

| Document | Description | Audience |
|----------|-------------|----------|
| [ARCHITECTURE.md](developer/architecture/ARCHITECTURE.md) | **UPDATED (Apr 2026):** High-level architecture overview — 13 providers, ~990 tool classes, 34 REST controllers, 64 services, full directory structure with file counts | Everyone |
| [REQUEST-FLOW-WALKTHROUGH.md](developer/architecture/REQUEST-FLOW-WALKTHROUGH.md) | **NEW (Apr 2026):** End-to-end chat request lifecycle trace — authentication → assistant → SSE → provider routing → agentic loop → tool execution → token budget → response | Everyone |
| [AGENTIC-WORKFLOW-VISUAL-SUMMARY.md](visual-guides/workflow/AGENTIC-WORKFLOW-VISUAL-SUMMARY.md) | **NEW:** Quick visual reference showing agentic workflow flow (print-friendly diagrams) | Everyone |
| [CURRENT-STATE-AGENTIC-WORKFLOW.md](developer/architecture/core/CURRENT-STATE-AGENTIC-WORKFLOW.md) | **NEW:** Current state documentation showing how assistants and processing work together for agentic workflows (comprehensive guide with examples) | Everyone |
| [agentic-workflow-architecture.md](developer/architecture/core/agentic-workflow-architecture.md) | Detailed agentic workflow architecture, optimizations, and testing | Developers |
| [ORCHESTRATION-LAYER-ARCHITECTURE.md](developer/architecture/orchestration/ORCHESTRATION-LAYER-ARCHITECTURE.md) | Novel orchestration layer differentiators vs standard SSE/MCP (20KB) | Developers |
| [dead-letter-queue.md](developer/architecture/dead-letter-queue.md) | **NEW (Jan 2026):** Dead Letter Queue for persistent failure handling (12KB) | Developers/Admins |
| [sla-prioritization.md](developer/architecture/sla-prioritization.md) | **NEW (Jan 2026):** SLA-based job prioritization with Little's Law capacity planning (16KB) | Developers/Admins |
| [ORCHESTRATION-DASHBOARD-IMPLEMENTATION.md](developer/architecture/orchestration/ORCHESTRATION-DASHBOARD-IMPLEMENTATION.md) | Complete implementation guide with code examples and PR #852 enhancements | Developers |
| [ORCHESTRATION-DASHBOARD-SUMMARY.md](developer/architecture/orchestration/ORCHESTRATION-DASHBOARD-SUMMARY.md) | User-friendly feature overview, use cases, and quick start guide | Users/Admins |
| [ORCHESTRATION-DASHBOARD-VISUAL-GUIDE.md](visual-guides/orchestration/ORCHESTRATION-DASHBOARD-VISUAL-GUIDE.md) | Visual walkthrough with UI layouts and component details | All Users |
| [ORCHESTRATION-DASHBOARD-FINDINGS.md](developer/architecture/orchestration/ORCHESTRATION-DASHBOARD-FINDINGS.md) | Documentation search findings and gap analysis | Developers/Admins |
| [RESOURCE-MANAGEMENT.md](features/performance/RESOURCE-MANAGEMENT.md) | Computer-implemented resource management system | Developers |
| [erlang-c-staffing-tools.md](features/erlang-c-staffing-tools.md) | **NEW (Apr 2026):** Erlang C queuing theory tools — staffing calculator, concurrency advisor, multi-channel staffing advisor, real-time queue health monitoring, `wp_mcp_ai_queue_alert` hook | Admins/Developers |
| [orchestration-budget-enforcement.md](developer/architecture/orchestration/orchestration-budget-enforcement.md) | Orchestration layer budget prediction and enforcement | Developers |
| [ORCHESTRATION_REFERENCE.md](reference/orchestration/ORCHESTRATION_REFERENCE.md) | **NEW (Apr 2026):** Complete orchestration reference — 10 workflow presets, 13 resource presets, PSO optimizer, tool execution, load balancer, reasoning, multi-agent, health, budget, hooks, storage keys | Developers/Admins |
| [assistant-storage-cpt-vs-cct.md](developer/architecture/integrations/assistant-storage-cpt-vs-cct.md) | CPT vs CCT storage architecture (20KB) | Developers |
| [assistant-tool-shortcuts.md](getting-started/first-steps/assistant-tool-shortcuts.md) | Prompt shortcuts system | Users/Devs |
| [base-vs-full-comparison.md](reference/technical/base-vs-full-comparison.md) | Base vs Full version comparison | Admins |
| [BUILD-ARTIFACTS-CLARIFICATION.md](operations/troubleshooting/common/BUILD-ARTIFACTS-CLARIFICATION.md) | Build artifacts and base vs core terminology clarification | Developers/Admins |
| [memory-limits.md](features/memory/memory-limits.md) | Memory management and limits | Developers |

### Proposals & Planning

| Document | Description | Audience |
|----------|-------------|----------|
| [PLAYWRIGHT_INTEGRATION_EVALUATION.md](project/proposals/PLAYWRIGHT_INTEGRATION_EVALUATION.md) | **NEW (Jan 2026):** Full technical evaluation of Playwright browser automation integration - Decision to create Pro tool `web_browser` (15KB) | Developers/Decision Makers |
| [WEB_BROWSER_PRO_TOOL_SUMMARY.md](project/proposals/WEB_BROWSER_PRO_TOOL_SUMMARY.md) | **NEW (Jan 2026):** Executive summary for web_browser Pro tool decision - Base version size management rationale (7KB) | Stakeholders/Admins |
| [TELEGRAM_MINI_APP_ENHANCEMENT_PROPOSAL.md](project/proposals/TELEGRAM_MINI_APP_ENHANCEMENT_PROPOSAL.md) | **NEW (Mar 2026):** Comprehensive Telegram Mini App enhancement - Stars payments, inline mode, deep linking, all 23 Pro toolkits integration, monetization strategy (41KB) | Developers/Stakeholders |
| **[GPT-REALTIME-2-UPGRADE-PROPOSAL.md](project/proposals/GPT-REALTIME-2-UPGRADE-PROPOSAL.md)** | **NEW (Jun 2026):** GPT-Realtime-2 upgrade proposal — beta→GA migration, WebRTC transport, Translate/Whisper models, reasoning effort, structured prompts | Developers/Decision Makers |
| **[GPT-REALTIME-2-IMPLEMENTATION-PLAN.md](project/proposals/GPT-REALTIME-2-IMPLEMENTATION-PLAN.md)** | **NEW (Jun 2026):** GPT-Realtime-2 implementation plan — 1,166-line comprehensive engineering specification with testing strategy | Developers |
| **[MCP_ASSISTANT_TOOLKIT_SCOPE_ENHANCEMENT.md](project/proposals/MCP_ASSISTANT_TOOLKIT_SCOPE_ENHANCEMENT.md)** | **NEW (Jun 2026):** MCP initialize assistant-scoped instructions & model preferences — architecture audit, industry research, implementation plan | Developers |
| **[fastapi-porting-implementation-plan.md](project/proposals/fastapi-porting-implementation-plan.md)** | **NEW (Jun 2026):** FastAPI porting implementation plan — Python FastAPI migration from legacy microservices | Developers |
| **[multi-channel-result-delivery-enhancement.md](project/proposals/multi-channel-result-delivery-enhancement.md)** | **NEW (Jun 2026):** Multi-channel result delivery UI — Telegram, Discord, WhatsApp, Google Chat in schedule modal | Developers |

### Integration Guides

| Document | Description | Audience |
|----------|-------------|----------|
| [google-oauth-setup.md](getting-started/installation-setup/google-oauth-setup.md) | Google OAuth 2.0 setup for Gmail integration | Admins/Users |
| [oauth-settings-architecture.md](developer/architecture/integrations/oauth-settings-architecture.md) | OAuth settings architecture and hybrid system design | Developers |
| [chatkit-integration.md](developer/integration/chatkit-integration.md) | ChatKit module integration | Developers |
| [elementor-widgets.md](developer/architecture/integrations/elementor-widgets.md) | Elementor widget documentation | Users/Devs |
| [jet-engine-rest-routes.md](reference/api/jet-engine-rest-routes.md) | JetEngine REST API reference | Developers |
| [jetengine-api-compatibility.md](developer/architecture/integrations/jetengine-api-compatibility.md) | JetEngine API compatibility layer (v3.3+ support) | Developers |
| [jukebox-integration.md](developer/integration/jukebox-integration.md) | **NEW:** OpenAI Jukebox music generation integration (music with vocals, local installation) | Admins/Devs |

### Remote Client Setup

| Document | Description | Audience |
|----------|-------------|----------|
| [remote-client-quickstart.md](getting-started/quick-starts/remote-client-quickstart.md) | Quick start guide for remote clients | Admins |
| [remote-client-setup.md](getting-started/installation-setup/remote-client-setup.md) | Detailed remote client configuration (14KB) | Admins |
| [cloudflare-tunnel-setup.md](getting-started/installation-setup/cloudflare-tunnel-setup.md) | Cloudflare Tunnel setup for exposing local AI services securely (16KB) | Admins |
| [REMOTE_CLIENT_TESTING_SUMMARY.md](history/2025/summaries/REMOTE_CLIENT_TESTING_SUMMARY.md) | Testing results for remote clients | Developers |
| [lm-studio-testing.md](getting-started/quick-starts/lm-studio-testing.md) | LM Studio specific testing | Developers |

### SaaS Setup (NV oOS Cloud)

| Document | Description | Audience |
|----------|-------------|----------|
| [saas-controller.md](operations/deployment/saas-controller.md) | **NEW (v1.1.16):** SaaS Controller Addon reference — all 11 phases, admin UI, REST API (19 routes), filters, data storage, build instructions | Operators/Devs |
| [SAAS_SETUP_GUIDE.md](operations/deployment/SAAS_SETUP_GUIDE.md) | Industry-standard SaaS install/setup guide for NV oOS Cloud — prerequisites, account provisioning, connect tokens, billing, security, runbook | Operators/Admins |
| [features/nv-cloud.md](features/nv-cloud.md) | NV oOS Cloud feature spec (plugin contract, hooks, constants) | Developers |
| [../addons/cloud-worker/README.md](../addons/cloud-worker/README.md) | Cloudflare Worker source-of-truth (deploy commands, D1 schema) | Operators |

### Security & Authentication

| Document | Description | Audience |
|----------|-------------|----------|
| [mcp-server-authentication.md](reference/api/mcp-server-authentication.md) | Authentication methods and setup (13KB) | Admins/Devs |
| [authentication.md](reference/api/authentication.md) | Authentication overview | Admins |
| [rate-limit-protection.md](features/performance/rate-limit-protection.md) | Rate limiting configuration | Admins |
| [../SECURITY.md](../SECURITY.md) | Security policies and vulnerability reporting | Everyone |

### Compliance Frameworks

| Document | Description | Audience |
|----------|-------------|----------|
| [compliance/README.md](operations/compliance/README.md) | **NEW:** Compliance overview and framework index | Admins/Compliance |
| [compliance/MULTI-FRAMEWORK-COMPLIANCE-SUMMARY.md](operations/compliance/MULTI-FRAMEWORK-COMPLIANCE-SUMMARY.md) | Multi-framework implementation summary with dashboard integration | Admins/Auditors |
| [compliance/iso27001/](operations/compliance/iso27001/) | **100% Compliant** - ISO/IEC 27001:2022 (83 of 83 controls, ~90KB docs) | Admins/Auditors |
| [compliance/soc2/](operations/compliance/soc2/) | **100% Compliant** - SOC 2 Trust Services (54 of 54 criteria) | Admins/Auditors |
| [compliance/hipaa/](operations/compliance/hipaa/) | **98% Compliant** - HIPAA Security Rule (42 of 43 safeguards) | Healthcare/Admins |

**Pro Dashboard:** `wp-admin/admin.php?page=nvoos-pro-dashboard-multi-framework` for real-time compliance scores.

### Mesh Networking

| Document | Description | Audience |
|----------|-------------|----------|
| [mesh-compute-pooling.md](features/federation/mesh-compute-pooling.md) | Mesh compute pooling for anonymous and authenticated users (22KB) | All Users |

### API & Tools Reference

| Document | Description | Audience |
|----------|-------------|----------|
| [rest-api.md](reference/api/rest-api.md) | Complete REST API documentation (15KB) | Developers |
| [tool-reference.md](reference/tools/tool-reference.md) | All 105 built-in tools catalog (24KB) | Users/Devs |
| [tool-grouping.md](reference/tools/tool-grouping.md) | Tool categorization system (WordPress Core, Plugins, External) | Users/Admins |
| [tools-manager.md](admin-guides/tools/tools-manager.md) | Tools Manager admin interface guide | Admins/Users |
| [gemini-api-enhancements.md](reference/api/gemini/gemini-api-enhancements.md) | Gemini API enhancements (list_models, count_tokens, embeddings, streaming) | Developers |
| **[wait-for-user-tool.md](reference/tools/wait-for-user-tool.md)** ⭐ **NEW (v1.1.34)** | **wait_for_user voice tool reference** — no-op tool for silence/noise handling in GPT-Realtime-2 voice sessions, prompt integration, capability requirements | Users/Devs |
| [send-group-email-usage.md](features/tools/communication/send-group-email-usage.md) | Complete usage guide for Send Group Email tool | Users/Devs |
| [tool-image-download.md](developer/tool-development/tool-image-download.md) | Image download tool specifics | Developers |
| **[CRAWL4AI_SERVICE_IMPLEMENTATION.md](features/tools/crawl4ai/CRAWL4AI_SERVICE_IMPLEMENTATION.md)** ⭐ **NEW (Jan 2026)** | **Complete Crawl4AI remote service implementation** - Copy-paste ready Python/FastAPI code, Docker deployment, browser pool management (30KB) | Developers |
| **[CRAWL4AI_SERVICE_REFERENCE.md](features/tools/crawl4ai/CRAWL4AI_SERVICE_REFERENCE.md)** ⭐ **NEW (Jan 2026)** | **Crawl4AI service API reference** - REST endpoints, deployment guides, integration examples, troubleshooting (32KB) | Developers/Admins |
| [CRAWL4AI-JOB-TRACKING.md](features/tools/crawl4ai/CRAWL4AI-JOB-TRACKING.md) | Job tracking enhancement for Crawl4AI (all job types tracked) | Developers |

### Chat Features

| Document | Description | Audience |
|----------|-------------|----------|
| **[SLASH_COMMANDS_GUIDE.md](user-guides/slash-commands/SLASH_COMMANDS_GUIDE.md)** ⭐ **NEW (Feb 2026)** | **Complete slash commands system** - Phase 1 with 8 commands, workflow orchestrator, command chaining, REST API integration (32KB) | Users/Admins |
| **[PRO_TOOLKIT_SLASH_COMMANDS.md](user-guides/slash-commands/PRO_TOOLKIT_SLASH_COMMANDS.md)** ⭐ **NEW (Feb 2026)** | **Pro toolkit slash commands** - Phase 2 with 21 commands across 3 toolkits, 7 automated workflows (47KB) | Users/Admins |
| [CROSS-WIDGET-COMMUNICATION.md](user-guides/chat/CROSS-WIDGET-COMMUNICATION.md) | Load sessions between User Chat History and Chat widgets | Users/Devs |
| [chat-history-persistence.md](user-guides/chat/chat-history-persistence.md) | Chat history persistence system | Users/Devs |
| [chat-history-persistence-quickstart.md](getting-started/quick-starts/chat-history-persistence-quickstart.md) | Quick guide for chat persistence | Users |

### Voice Features ⭐ **NEW (v1.1.34 — June 2026)**

| Document | Description | Audience |
|----------|-------------|----------|
| **[GPT-REALTIME-2-VOICE-MODELS.md](features/voice/gpt-realtime-2-voice-models.md)** ⭐ **NEW (Jun 2026)** | **GPT-Realtime-2 voice models** — GA Realtime API migration, WebRTC transport, Translate + Whisper clients, reasoning effort, structured prompts, PTT mode, admin settings reference | Users/Admins |
| **[features/voice/README.md](features/voice/README.md)** | Voice feature guide index — scope, what belongs/doesn't belong | Admins/Devs |
| **[voice-chat-troubleshooting.md](operations/troubleshooting/chat/voice-chat-troubleshooting.md)** | Voice chat 404 error diagnosis and fixes | All Users |

### Chat Channels & WebChat ⭐ **NEW (Feb 2026)**

| Document | Description | Audience |
|----------|-------------|----------|
| **[CHAT_CHANNELS_TOOLKIT.md](../addons/pro/docs/CHAT_CHANNELS_TOOLKIT.md)** ⭐ **NEW (Feb 2026)** | **Complete chat channels integration** - 21 tools across 6 platforms (Telegram, WhatsApp, Slack, Discord, Teams, Messenger), unified broadcasting (48KB) | Users/Admins |
| **[CHAT_CHANNELS_README.md](../addons/pro/docs/CHAT_CHANNELS_README.md)** ⭐ **NEW (Feb 2026)** | **Chat channels quick start** - Platform setup, example usage, tool list (5KB) | Users |
| **[CHAT_CHANNELS_IMPLEMENTATION_SUMMARY.md](../addons/pro/docs/CHAT_CHANNELS_IMPLEMENTATION_SUMMARY.md)** ⭐ **NEW (Feb 2026)** | **Implementation details** - Architecture, security, testing results (15KB) | Developers |
| **[WEBCHAT_ASSISTANT_ASSIGNMENT.md](../addons/pro/docs/WEBCHAT_ASSISTANT_ASSIGNMENT.md)** ⭐ **NEW (Feb 2026)** | **WebChat rooms setup** - AI assistant assignment, room management, CPT configuration (12KB) | Admins |
| **[WEBCHAT_TROUBLESHOOTING.md](../addons/pro/docs/WEBCHAT_TROUBLESHOOTING.md)** ⭐ **NEW (Feb 2026)** | **WebChat troubleshooting** - Common issues, WebRTC debugging, message persistence (8KB) | Admins/Devs |
| **[WEBCHAT-SELF-HOSTED-SIGNALING.md](../addons/pro/docs/WEBCHAT-SELF-HOSTED-SIGNALING.md)** ⭐ **NEW (Feb 2026)** | **WebRTC signaling** - Self-hosted WordPress REST API signaling implementation (6KB) | Developers |

### Pro Features ⭐ **PRO ADDON**

| Document | Description | Audience |
|----------|-------------|----------|
| **[addons/pro/docs/CRM_TOOLKIT_ENHANCEMENT_PLAN.md](../addons/pro/docs/CRM_TOOLKIT_ENHANCEMENT_PLAN.md)** ⭐ **UPDATED (Jun 2026)** | **CRM Toolkit Enhancement Plan** — Phases A–E complete: Customer CPT (5 tools + Customer 360), Support Ticket module (10 tools + SLA + Zendesk), inbound multichannel (IMAP/SMS/WhatsApp/Gmail), TF-IDF + BM25 relevance search. 89 CRM tools total. | Developers/Admins |
| **[features/pro-schedule-manager.md](features/pro-schedule-manager.md)** ⭐ **NEW (Mar 2026)** | **Pro Schedule Manager** — 5 schedule types (task / workflow / assistant_run / channel_broadcast / workflow_builder), Symfony Cache & Validator, MJML email, ical + CSV export, chart.js sparkline, retry logic, JetEngine CCT history, 6 AI tools, full admin UI | Developers/Admins |
| **[features/healthcare-imaging-viewer.md](features/healthcare-imaging-viewer.md)** ⭐ **NEW (Mar 2026)** | **Healthcare DICOM Imaging Viewer** — Full manager: upload, study browser with search/filter, Cornerstone3D viewer, W/L presets, flip/rotate, AI interpretation, audit log, REST API reference, HIPAA notes | Admins/Clinical Staff |
| [PRO_CPT_OVERVIEW.md](features/pro-cpt/PRO_CPT_OVERVIEW.md) | **NEW:** Events, Quizzes, and Places CPT overview (21 tools) | Users/Admins |
| [telegram-mini-app-templates.md](features/telegram-mini-app-templates.md) | **NEW:** Health & Wellness and Medical Vitals Telegram Mini App templates — member selection, auth flow, role-based access, offline-first sync, custom template API | Developers/Users |
| **[features/media-command-center.md](features/media-command-center.md)** ⭐ **NEW (Jun 2026)** | **Media Command Center** — Top-level NV Media admin menu, templates, presets, blueprints, scheduler. Manages media generation workflows. | Admins/Users |
| **[features/pro-spa-v2.md](features/pro-spa-v2.md)** ⭐ **NEW (Jun 2026)** | **Pro SPA v2** — Next-gen React chat UI with rich markdown rendering, assistant scoping, agent selector. Conversations primary, threads read-only. v2.0.1. | Developers/Admins |
| **[toolkits/ezuite-inventory-sync.md](toolkits/ezuite-inventory-sync.md)** ⭐ **NEW (Jul 2026)** | **EZuite Inventory Sync Pro Toolkit** — ERP-integrated inventory sync: product pull, inventory query, item CRUD, ERP API, CLI commands, field mapping, sync log. | Admins/Devs |
| **[toolkits/flowhub-integration.md](toolkits/flowhub-integration.md)** ⭐ **UPDATED (Jul 2026)** | **FlowHub Integration** — Updated with Remote Sites connection selector, per-connection sync, proxy support, sync log manager. | Admins/Devs |
| **[toolkits/shopify-sync-integration.md](toolkits/shopify-sync-integration.md)** ⭐ **UPDATED (Jul 2026)** | **Shopify Sync Integration** — Updated with Catalog API guard, CCT registration lifecycle, sync log manager. | Admins/Devs |
| **[features/sync-log-manager.md](features/sync-log-manager.md)** ⭐ **NEW (Jul 2026)** | **Sync Log Manager** — Unified per-item audit trail across EZuite, FlowHub, and Shopify sync toolkits. Status dashboards, WP-CLI, configurable retention. | Admins/Devs |
| **[features/ralph-loop-orchestration.md](features/ralph-loop-orchestration.md)** ⭐ **NEW (Jul 2026)** | **Ralph Loop CCT Migration & Orchestration** — Circuit breaker pattern, execution logger, CCT migration tools, multi-step workflow orchestration. | Developers |
| **[features/jetbooking-jetappointment.md](features/jetbooking-jetappointment.md)** ⭐ **NEW (Jul 2026)** | **JetBooking/JetAppointment Integration** — 8 new AI tools + 4 enhanced calendar tools for Crocoblock booking/appointment plugins. | Admins/Devs |

**Pro Custom Post Types:**
- **Customers** (5 tools) — Customer CPT with full CRUD, Customer 360 dashboard, lead-to-customer conversion
- **Support Tickets** (10 tools) — Ticket lifecycle, SLA enforcement, AI classification, Zendesk sync
- **Events** (5 tools) - Calendar management, Google Calendar integration
- **Quizzes** (9 tools) - Assessments, grading, analytics, JetEngine CCT
- **Places** (7 tools) - Location management, Google Places API integration

**New Addons (July 2026):**
- **EZuite Inventory Sync** (`addons/pro/includes/tools/erp-ezuite/`) — ERP-integrated inventory sync toolkit bridging EZuite ERP with WooCommerce/WP
- **Ralph Loop Orchestration** — Circuit breaker, execution logger, and CCT migration tools for cross-environment data operations
- **JetBooking/JetAppointment** (`addons/pro/includes/tools/calendar-booking/`) — 8 new AI tools + 4 enhanced calendar tools for Crocoblock plug-ins
- **Sync Log Manager** — Unified per-item audit trail across EZuite, FlowHub, and Shopify sync toolkits

**New Addons (June 2026):**
- **Media Command Center** (`admin.php?page=nv-media`) — Top-level NV Media admin menu with templates, presets, blueprints, and scheduler
- **Pro SPA v2** (`addons/pro-spa-v2/`) — Next-gen React chat SPA with rich markdown rendering, assistant scoping, agent selector, v2.0.1
- **Funiq Bridge** (`addons/funiq-bridge/`) — Payload-to-WordPress bridge with React admin SPA, REST controllers, transformers, post types (Product, Promocode, Promotion), taxonomies (Brand, Category, Color, Status)
- **NV Platform** (`addons/ai-platform/`) — Top-level admin dashboard + CPTs (Project, Resource, Template)

**Standalone Graphify Plugins:**
- **NVOOS Graphify** (`plugins/nvoos-graphify/`) — Visual knowledge graph (Cytoscape.js), 14 graph tools, remote sources, REST API
- **NVOOS Graphify AI** (`plugins/nvoos-graphify-ai/`) — AI addon: 13 providers, streaming chat, RAG, embeddings, 14 AI tools
- **NVOOS Graphify AI Platform** (`plugins/nvoos-graphify-ai-platform/`) — Platform addon: Agents, A2A, ACP, Blueprints, Federation, Harness, Measurement, Professions, Skills, Slash Commands

**Telegram Mini App Health Templates:**
- **Health & Wellness** (`health_wellness`) – Daily metrics (steps, sleep, hydration, sodium, mood), streak gamification, Chart.js charts, AI coach
- **Medical Vitals** (`medical_vitals`) – Vitals + kidney lab tracking (BP, HR, SpO₂, eGFR, creatinine, BUN, K⁺, Na⁺), 7-day trends, medication dosage, AI doctor assistant

### Performance & Optimization

| Document | Description | Audience |
|----------|-------------|----------|
| [PERFORMANCE-OPTIMIZATION.md](features/performance/PERFORMANCE-OPTIMIZATION.md) | **NEW:** Comprehensive guide to caching system and database optimizations (Phase 1) | Developers/Admins |
| [DYNAMIC-CONFIGURATION-FILTERS.md](developer/architecture/DYNAMIC-CONFIGURATION-FILTERS.md) | WordPress filters for dynamic configuration (18 filters) | Developers |
| [message-bundling-feature.md](user-guides/chat/message-bundling-feature.md) | Client-side message bundling optimization | Developers |
| [high-token-tool-handling.md](features/tools/presets/high-token-tool-handling.md) | Agentic loop token management | Developers |
| [token-counting.md](reference/technical/token-counting.md) | Token counting tool for budget management | Users/Devs |
| [analytics-engine.md](features/analytics/analytics-engine.md) | **NEW:** Analytics Engine for trend analysis, patterns, and anomaly detection (Phase 7) | Developers/Admins |
| [TOKEN-MANAGER-ENHANCEMENT-PLAN.md](features/analytics/TOKEN-MANAGER-ENHANCEMENT-PLAN.md) | Comprehensive enhancement plan for token usage manager (Phases 1-6) | Developers/Stakeholders |
| [PHASE-7-ANALYTICS-PLAN.md](features/analytics/PHASE-7-ANALYTICS-PLAN.md) | **NEW:** Phase 7 plan - Advanced analytics, visualization, and cost tracking | Developers/Stakeholders |
| [QUICK-REFERENCE-TOKEN-ENHANCEMENTS.md](features/memory/QUICK-REFERENCE-TOKEN-ENHANCEMENTS.md) | Quick reference guide for token manager enhancements (Phases 1-6) | All Users |
| [QUICK-REFERENCE-PHASE-7.md](features/analytics/QUICK-REFERENCE-PHASE-7.md) | **NEW:** Quick reference guide for Phase 7 analytics features | All Users |
| [job-notification-system.md](features/async-jobs/job-notification-system.md) | Real-time async job notifications | Developers |
| [chat-performance-optimizations.md](features/chat/chat-performance-optimizations.md) | Complete performance tuning guide | Developers |
| [tpm-limit-validation.md](features/performance/tpm-limit-validation.md) | Token-per-minute limit handling | Developers |
| [tool-llm-sanitization.md](developer/tool-development/tool-llm-sanitization.md) | Tool output sanitization | Developers |

### MCP Protocol

| Document | Description | Audience |
|----------|-------------|----------|
| [mcp-endpoint.md](reference/api/mcp-endpoint.md) | MCP JSON-RPC 2.0 endpoint documentation (2024-11-05 spec) | Developers |
| [MCP-AND-SSE.md](reference/api/MCP-AND-SSE.md) | MCP and SSE protocol details (updated for 2024-11-05) | Developers |
| [mcp-server-authentication.md](reference/api/mcp-server-authentication.md) | Authentication methods with OAuth 2.1 enhancements (13KB) | Admins/Devs |
| [mcp-client-configurations.md](reference/api/mcp-client-configurations.md) | Client configuration guide for MCP 2024-11-05 | Admins |
| [ENABLE-SSE-STREAMING.md](features/streaming/ENABLE-SSE-STREAMING.md) | SSE streaming setup guide | Admins |

### Troubleshooting & Support

| Document | Description | Audience |
|----------|-------------|----------|
| [deployment-troubleshooting.md](getting-started/installation-setup/deployment-troubleshooting.md) | Common deployment issues | Admins |
| [voice-chat-troubleshooting.md](operations/troubleshooting/chat/voice-chat-troubleshooting.md) | **NEW:** Voice chat 404 error diagnosis and fixes | All Users |
| [console-testing.md](getting-started/first-steps/console-testing.md) | **NEW:** Browser console testing utility for chat transcripts API | Developers |
| [CRON_TESTING_GUIDE.md](developer/testing-docs/CRON_TESTING_GUIDE.md) | **NEW:** Complete guide for testing cron jobs and verifying visibility in admin UI | All Users |
| [php-version-compatibility.md](reference/technical/php-version-compatibility.md) | PHP version compatibility and defensive guards | Developers/Admins |
| [multisite-support.md](getting-started/installation-setup/multisite-support.md) | Multisite configuration | Admins |
| [REMAINING_ISSUES.md](history/2025/summaries/REMAINING_ISSUES.md) | Known minor issues | Developers |

---

## 🔧 Development Documentation

### Code Quality & Review

| Document | Description | Audience |
|----------|-------------|----------|
| **[../PHPCS_DOCUMENTATION_INDEX.md](history/archive/2026/fixes/phpcs/PHPCS_DOCUMENTATION_INDEX.md)** | **⭐ NEW (Feb 1, 2026):** Complete PHPCS documentation suite - 7 files covering 667 violations, implementation plan | Developers/QA |
| **[GAP_DOCUMENTATION_REVIEW_SUMMARY.md](history/2026/implementations/GAP_DOCUMENTATION_REVIEW_SUMMARY.md)** | **⭐ NEW (Jan 3, 2026):** Gap documentation review - 100% high-priority completion! Quality: 98/100 | Everyone |
| **[GAP_DOCUMENTATION_STATUS_UPDATE_2026-01-03.md](history/2025/summaries/GAP_DOCUMENTATION_STATUS_UPDATE_2026-01-03.md)** | **NEW (Jan 3, 2026):** Master status update - all 6 gap documents reviewed, phase tracking | Developers/PM |
| **[CODE_REVIEW_2026-01-02.md](history/2025/code-reviews/CODE_REVIEW_2026-01-02.md)** | **NEW (Jan 2, 2026):** Comprehensive code review - historical tool-count verification superseded by Rev 2.0 fact sheet, Grade A- (92/100) | Developers/QA |
| **[CODE_REVIEW_SUMMARY_2026-01-02.md](history/2025/code-reviews/CODE_REVIEW_SUMMARY_2026-01-02.md)** | **NEW (Jan 2, 2026):** Quick summary of January 2 code review findings and actions | Everyone |
| **[ROOT_DIRECTORY_ORGANIZATION_2026-01-02.md](history/2025/documentation/ROOT_DIRECTORY_ORGANIZATION_2026-01-02.md)** | **NEW (Jan 2, 2026):** Root cleanup - moved 8 files to proper docs/ locations | Developers |
| **[GAP_ANALYSIS_EXECUTIVE_SUMMARY.md](history/2025/summaries/GAP_ANALYSIS_EXECUTIVE_SUMMARY.md)** | Executive summary of comprehensive gap analysis (Dec 6, 2025) | Everyone |
| **[PLUGIN_GAP_ANALYSIS.md](history/2025/summaries/PLUGIN_GAP_ANALYSIS.md)** | Full gap analysis - 365 files, 9 categories, 35 gaps identified (Dec 6, 2025) | Developers/QA |
| **[QUICK_WINS_GAP_FIXES.md](history/2025/summaries/QUICK_WINS_GAP_FIXES.md)** | Step-by-step quick fixes with code examples (Dec 6, 2025) | Developers |
| [CODE-REVIEW-MASTER.md](developer/best-practices/CODE-REVIEW-MASTER.md) | Master Code Review - Comprehensive assessment (95/100 score, consolidates 6 reviews) | Developers |
| [archive/](history/archive/README.md) | Historical code reviews (archived for reference) | Developers |
| [SETTINGS_PAGE_CODE_REVIEW.md](history/2025/code-reviews/SETTINGS_PAGE_CODE_REVIEW.md) | Settings page architecture review (debugging-focused) | Developers |
| [ACTION_ITEMS.md](history/2025/summaries/ACTION_ITEMS.md) | Prioritized development tasks (9KB) | Developers |

#### PHPCS Compliance & Fixes ⭐ **NEW (Feb 2026)**

**Complete documentation suite for WordPress Coding Standards (PHPCS) compliance - 667 violations analyzed**

| Document | Description | Size | Audience |
|----------|-------------|------|----------|
| **[../PHPCS_DOCUMENTATION_INDEX.md](history/archive/2026/fixes/phpcs/PHPCS_DOCUMENTATION_INDEX.md)** | Master index for all PHPCS documentation - Start here! | 11 KB | Everyone |
| [../PHPCS_VIOLATIONS_FLOWCHART.md](history/archive/2026/fixes/phpcs/PHPCS_VIOLATIONS_FLOWCHART.md) | Visual decision-making guide with ASCII flowchart | 8.6 KB | Developers |
| [../SAFE_PHPCS_FIX_PLAN.md](history/archive/2026/fixes/phpcs/SAFE_PHPCS_FIX_PLAN.md) | 4-phase implementation plan with examples | 13 KB | Developers |
| [../PHPCS_FIX_QUICK_REFERENCE.md](history/archive/2026/fixes/phpcs/PHPCS_FIX_QUICK_REFERENCE.md) | Quick lookup reference for development | 5.9 KB | Developers |
| [../PHPCS_IGNORE_ANALYSIS.md](history/archive/2026/fixes/phpcs/PHPCS_IGNORE_ANALYSIS.md) | Detailed analysis of 149 phpcs:ignore instances | 76 KB | Developers |
| [PHPCS_IGNORE_REVIEW_SUMMARY.md](history/2026/implementations/PHPCS_IGNORE_REVIEW_SUMMARY.md) | Executive summary of unused parameter analysis | 6.2 KB | Developers/QA |
| [HIGH_PRIORITY_PHPCS_IMPLEMENTATION.md](history/2026/implementations/HIGH_PRIORITY_PHPCS_IMPLEMENTATION.md) | Phase 1 implementation status (4 of 7 complete) | 5.3 KB | Developers |
| [development/PHPCS_FIXES_NEEDED.md](developer/PHPCS_FIXES_NEEDED.md) | Historical record of January 2026 fixes (16 security errors) | 8.7 KB | Developers |

**Key Statistics:**
- 667 total violations analyzed
- 91 violations can be fixed safely (71 low-risk + 20 review-required)
- 127 violations should be suppressed with documentation
- 469 violations are intentional (interface/hook requirements)
- Phase 1 implementation: ✅ Complete (file upload security, privacy compliance verified)
- January 2026 fixes: ✅ Complete (16 critical security errors resolved)

---

### Testing & Code Coverage

| Document | Description | Audience |
|----------|-------------|----------|
| **[CODE_COVERAGE_DASHBOARD.md](developer/testing-docs/CODE_COVERAGE_DASHBOARD.md)** | **⭐ NEW (Jan 3, 2026):** Code coverage dashboard with Codecov integration, auto-reporting in CI/CD | Developers/QA |
| [TESTING_AND_QUALITY_REPORT.md](developer/testing-docs/TESTING_AND_QUALITY_REPORT.md) | Comprehensive testing analysis - 2,106 tests, 73.4% pass rate, code quality metrics | Developers/QA |
| [CRON_TESTING_GUIDE.md](developer/testing-docs/CRON_TESTING_GUIDE.md) | Complete guide for testing cron jobs and verifying visibility in admin UI | All Users |

### Technical Documentation

| Document | Description | Audience |
|----------|-------------|----------|
| **[ADR_001_module_boundaries.md](project/architecture-decisions/ADR_001_module_boundaries.md)** | **⭐ NEW (Mar 2026):** Architecture Decision Record — four-layer module boundaries, interface catalogue, enforcement rules | Developers |
| **[BUILD_MATRIX.md](project/releases/BUILD_MATRIX.md)** | **⭐ NEW (Mar 2026):** Complete build script reference — every `npm run build:*` script, its config file, output, and when to run it | Developers |
| [gmail-oauth-fix-summary.md](history/2026/fixes/gmail-oauth-fix-summary.md) | Gmail OAuth integration fix (400 Bad Request) | Developers/Admins |
| [OPENAI-STABILIZATION.md](features/ai-providers/openai/OPENAI-STABILIZATION.md) | OpenAI integration stability (12KB) | Developers |
| [TRANSCRIPT_RECONSTRUCTION_FIX.md](history/2025/fixes/chat/TRANSCRIPT_RECONSTRUCTION_FIX.md) | Transcript reconstruction fix | Developers |
| [GET_OPEN_METEO_FIX.md](history/2026/fixes/GET_OPEN_METEO_FIX.md) | Weather forecast chart iframe rendering fix | Developers |
| [BEFORE_AFTER_COMPARISON.md](history/2026/fixes/BEFORE_AFTER_COMPARISON.md) | Visual comparison of get_open_meteo_forecast chart rendering fix | Developers |
| [CHART_BUBBLE_WIDTH_FIX.md](history/2026/fixes/CHART_BUBBLE_WIDTH_FIX.md) | CSS fix for chart bubble width display | Developers |
| [CHART_WIDTH_FIX_SUMMARY.md](history/2026/fixes/CHART_WIDTH_FIX_SUMMARY.md) | Summary of chart width-related fixes | Developers |
| [CHART_FIX_TESTING.md](history/2026/fixes/CHART_FIX_TESTING.md) | Comprehensive testing guide for chart fixes | Developers/QA |
| [IMPLEMENTATION_SUMMARY_GET_OPEN_METEO.md](history/2026/implementation-summaries/IMPLEMENTATION_SUMMARY_GET_OPEN_METEO.md) | Implementation summary for weather forecast fix | Developers |
| [PR_SUMMARY_GET_OPEN_METEO.md](history/2025/PR_SUMMARY_GET_OPEN_METEO.md) | PR summary for weather forecast iframe rendering fix | Developers |

---

## 📊 Documentation Statistics

### Coverage
- **Total Documentation Files:** 659+ files (654+ in docs/, 5 in root)
- **Total Documentation Size:** ~408KB+
- **Main README Size:** 1,400+ lines
- **Average Doc Size:** ~8.8KB

### Root Directory (Cleaned January 6, 2026)
Essential files only (reduced from 30 to 5 files):
1. README.md - Main plugin documentation
2. CHANGELOG.md - Version history
3. CONTRIBUTING.md - Contributor guidelines
4. SECURITY.md - Security policy
5. BUILD.md - Build instructions

**Note:** readme.txt is in root for WordPress.org, and tool-status.txt has been moved to docs/ for better organization.

### Categories
- **Setup & Installation:** 4 documents
- **Architecture & Design:** 6 documents
- **Integration Guides:** 4 documents
- **Security & Auth:** 4 documents
- **API & Tools:** 4 documents
- **Performance & Optimization:** 6 documents
- **MCP Protocol:** 3 documents
- **Development:** 9 documents (updated with testing section)
- **Troubleshooting:** 4 documents
- **Technical Details:** 2 documents

### Completeness Score (Updated January 3, 2026)
- ✅ **User Documentation:** 90% - Excellent coverage
- ✅ **Developer Documentation:** 98% - Comprehensive (up from 95%)
- ✅ **Testing Documentation:** 90% - Very Good (new category)
- ✅ **API Documentation:** 85% - Very good
- ✅ **Security Documentation:** 100% - Complete (up from 90%)
- ⚠️ **Video/Visual Guides:** 0% - Opportunity for improvement

### Quality Metrics (January 2026)
- **Overall Quality Score:** 98/100 (up from 95/100)
- **Documentation Grade:** A+ (up from A)
- **Gap Completion:** 100% high-priority (10 of 10 items)
- **Production Readiness:** 98/100 ✅ Approved

---

## 🎯 Documentation Recommendations

### Immediate Priorities (January 2026 Status)
1. ✅ Create this documentation index (completed)
2. ✅ Add quick reference card for common tasks (completed)
3. ✅ Complete code coverage dashboard (completed January 3, 2026)
4. ✅ Review and update all gap documentation (completed January 3, 2026)
5. ✅ Organize root directory (completed January 2, 2026)
6. [ ] Create troubleshooting flowcharts
7. [ ] Add video walkthroughs for setup

### Short-term Improvements
1. [ ] Add more code examples to tool documentation
2. [ ] Create architecture diagrams
3. [ ] Expand troubleshooting scenarios
4. [ ] Add performance tuning guide
5. [ ] Complete end-to-end test suite (10-13 hours planned)
6. [ ] Complete parameter documentation (2-3 hours planned)

### Long-term Goals
1. [ ] Create interactive documentation site
2. [ ] Add video tutorials
3. [ ] Create plugin showcase examples
4. [ ] Build community wiki
5. [ ] Implement Gemini Phase 2 features (22-28 hours, Q1 2026)
6. [ ] Advanced PM features for v2.0.0 (30-40 hours, Q3-Q4 2026)

---

## 📝 Contributing to Documentation

### How to Update Documentation
1. Follow the WordPress documentation standards
2. Use clear, concise language
3. Include code examples where appropriate
4. Update this index when adding new documents
5. Keep documentation synchronized with code changes

### Documentation Style Guide
- Use Markdown formatting
- Include table of contents for documents >100 lines
- Add "Last Updated" dates to all documents
- Use consistent heading hierarchy
- Include code examples in fenced blocks with language tags

### Review Process
1. Update documentation with code changes
2. Review for accuracy and clarity
3. Check all internal links
4. Update documentation index
5. Submit with pull request

---

## 🔗 External Resources

### WordPress Development
- [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/)
- [WordPress Plugin Handbook](https://developer.wordpress.org/plugins/)
- [WordPress REST API Handbook](https://developer.wordpress.org/rest-api/)

### AI & MCP Resources
- [OpenAI API Documentation](https://platform.openai.com/docs)
- [Model Context Protocol](https://modelcontextprotocol.io/)
- [Claude Desktop Documentation](https://docs.anthropic.com/)

### Plugin Dependencies
- [JetEngine Documentation](https://crocoblock.com/knowledge-base/jetengine/)
- [Elementor](https://be.elementor.com/visit/?bta=229888&brand=elementor)
- [Elementor Developer Documentation](https://developers.elementor.com/)
- [WooCommerce Documentation](https://woocommerce.com/documentation/)

---

## 📞 Support & Community

### Getting Help
1. Review relevant documentation above
2. Check [deployment-troubleshooting.md](getting-started/installation-setup/deployment-troubleshooting.md)
3. Search existing GitHub issues
4. Create new GitHub issue with details

### Contributing
1. Read [../CONTRIBUTING.md](../CONTRIBUTING.md)
2. Review code quality guidelines in [CODE-REVIEW-MASTER.md](developer/best-practices/CODE-REVIEW-MASTER.md)
3. Follow security practices in [../SECURITY.md](../SECURITY.md)
4. Submit pull requests with documentation updates

---

**Maintained by:** NV Digital Solutions  
**Documentation Repository:** https://github.com/nvdigitalsolutions/mcp-ai-wpoos  
**License:** GPLv3 or later
