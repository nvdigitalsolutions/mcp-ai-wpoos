# NV oOS Roadmap

**Last Updated:** July 16, 2026
**Version:** 1.1.40

---

## Current Capability Snapshot (v1.1.40)

| Dimension | Count |
|-----------|-------|
| **Base tools** | ~195 (live registry authoritative) |
| **Pro tools** | ~830+ (live registry authoritative) |
| **Total tools** | ~1,025+ |
| **AI providers** | 15 (OpenAI, Gemini, Anthropic, DeepSeek, OpenRouter, Baseten, Kimi, DigitalOcean, NVIDIA NIM, Cloudflare, Hugging Face, LM Studio, Ollama, Moonshot/Kimi parity, Z.AI/GLM parity) |
| **Voice/realtime providers** | 2 (OpenAI Realtime, Gemini Live) |
| **Active Pro toolkits** | 22+ |
| **Addons** | 26 |
| **OOS cross-platform extraction** | Phase 3 at ~22% (43/195 base tools migrated; Pro tools pending) |
| **OOS platform adapters** | WordPress ✅, Laravel (planned), Craft CMS (planned) |
| **Test coverage** | ~500+ test files, CI-gated |
| **PHP compatibility** | Base: PHP 7.4+ · Pro: PHP 8.1+ · OOS Core: PHP 8.1+ |

> ℹ️ **Tool count note:** The live registry (`WP_MCP_AI_Tool_Registry::get_tools()`) is the authoritative source. Different documents may cite snapshot counts from different releases; when in doubt, query the registry.

---

## Vision

**NV oOS** aims to be the leading open-source AI orchestration platform for WordPress, enabling small to medium-sized businesses to modernize their websites with enterprise-grade AI capabilities without expensive custom development or middleware.

### Core Principles

1. **Security First** — Active monitoring and prevention of nefarious usage
2. **WordPress Native** — Leverages WordPress core capabilities, no reinventing wheels
3. **Open Standards** — MCP protocol compliance, extensible architecture
4. **Community Driven** — Open source with transparent development
5. **Production Ready** — Enterprise reliability with community accessibility

---

## Released: v1.1.40 — July 2026 ✅

**Release Date:** July 15, 2026

### What was delivered in v1.1.40

- ✅ **Content Format Awareness.** `WP_MCP_AI_Content_Format_Helper` detects and preserves content format (Markdown, HTML, plain text) in post-modifying and analysis tools. Full test coverage.
- ✅ **Research → Paper Store → WordPress Draft Pipeline.** All research tools support `save_to_paper_store` for staging. New `create_post_from_research` Pro tool bridges Paper Store to WordPress drafts. Proposals 013 implemented.
- ✅ **Demo Video Pipeline Complete (Phases 0–5).** Scripted scene recording with AI voiceover. GitHub Actions CI workflow. 14 narration scripts. Video catalog.
- ✅ **Settings Credential Split.** Two-option architecture: `wp_mcp_ai_settings` (autoload, non-sensitive) + `wp_mcp_ai_credentials` (non-autoload, sensitive). Transparent merge via `get_settings()`. One-time activation migration. Defense-in-depth with `wp_suspend_cache_addition` and dual cache clearing.
- ✅ **Kimi & DeepSeek Client Parity.** Full streaming, tool use, token tracking, and error handling parity across both providers.
- ✅ **Model Catalog Update (July 2026).** 24 files across base + pro. Default model bumps: Gemini `2.5-flash` → `3.5-flash`, NVIDIA `llama-3.1-8b` → `nemotron-3-nano-30b-a3b`, Gemini Live `2.5-flash-live` → `3.1-flash-live-preview`.
- ✅ **DeepSeek + 9 Missing Providers in Research Tools.** All research tools now support full provider range.
- ✅ **OOS Engine: SchemaStoreInterface + Test Coverage.** New domain contract, entities, tool, and WordPress adapter. 45 new unit + integration tests across domain, providers, and registry.
- ✅ **SSE HTTP/2 Protocol Fixes.** `ob_clean()` replaces `ob_flush()`. 524 timeout and HTTP/2 protocol errors in Pro SPA v2 resolved.
- ✅ **Vector Store Sync No Polling.** Status checked only on assistant change and page load — reduces API load.
- ✅ **Settings Import/Export Batch (4 PRs).** Credential merge, save key wipe, subtab sanitization, export consistency — all fixed.
- ✅ **Credential Fixes (2 PRs).** Credential wipe on save. Fatal error for undefined `is_sensitive_setting_key` method.
- ✅ **Validated Tool Slug Allowlist.** Tool slug matching fixed.
- ✅ **Phase 8 Pro Toolkit MCP Servers.** 4 new per-toolkit MCP JSON-RPC servers promoted from toolkits (33 total, up from 29): Pro Scheduler (14 tools), FlowHub Inventory Sync (6 tools), Shopify Sync (5 tools), EZuite ERP Sync (6 tools). Shared `ScheduledToolkitServerTrait` for Action Scheduler-backed sync servers. Per-tool scope annotations and default limits in MCP descriptors.
- ✅ **OAuth 2.0 for MCP Servers.** Full OAuth 2.0 Authorization Server compliant with MCP Authorization Specification 2025-06-18. PKCE flow, hierarchical scopes (`mcp:read`/`mcp:write`), browser-based login, dynamic client registration, and OAuth token management UI. Enables MCP clients (Codex, Claude Desktop, Zed) to authenticate without manual token copying.
- ✅ **Per-Toolkit MCP Server Settings Fix.** Pro Scheduler MCP tab slug mapping fixed (`pro_schedule`→`pro-scheduler` resolution).
- 📦 **Versioning** — bumped to **1.1.40** across all version-bearing files. Tool count: ~195 base + ~830+ Pro (~1,025+ total; live registry authoritative). Provider count: **15** first-class. Addon count: 26.

---

## Released: v1.1.39 — July 2026 ✅

**Release Date:** July 13, 2026

### What was delivered in v1.1.39

- ✅ **Meta-Harness Auto-Optimization (All 7 Phases).** Trace Store + Trace Capture, Harness Search Engine, Pro Coding-Agent Proposer, Cues/Population/Auto-Deploy/DSpark, and tests. Self-improving agent infrastructure for observing, analyzing, and self-optimizing AI agent execution.
- ✅ **Agent Delegation — Major Rework.** Inline execution with async timestamp fix. REST-based dispatch via chat endpoint. Cron resilience with retry. `spawn_cron()` for instant deferred job execution. Name-based agent resolution. SPA v2 wire-up and tasks drawer fixes.
- ✅ **Pro SPA v2 — Polish & Fixes (20+ PRs).** Vector store indicator and autocomplete positioning. TDZ crash fix in CommandAutocomplete. Inline command autocomplete + Zed-style refresh. Cost badges restored. `allowSensitiveTools` config propagation. Tool result rich rendering, sidebar auto-refresh, media cache-busting. Auto-save transcripts. Attachment support + save button + storage display. Tasks drawer toolbar with failedCount badge. Speech/audio response envelope fix. Capability flags, usage badges, image+text rendering fixes. Sidebar media panel, media insert, speech button fixes. System prompt leak, sidebar empty state, media-to-chat bridge fixes. Tool display and conversation duplication fix. Composer mobile padding. Media grid flexbox layout.
- ✅ **Tool Presets Refactor.** Essentials layers with duplication stripped. Auto-upgrade for validated variants (no duplicate names). Double tool execution fix in SSE. `tool_call_id` fallback for DeepSeek streaming. Save button and tool message cleanup.
- ✅ **CRM Enhancements.** Cache loop fix. Upwork rate limiting. Freelance Platforms & External Sourcing configuration.
- ✅ **Infrastructure.** Veo 2.0 deprecated — Gemini Omni Flash replacement with deprecation detection (PR #5667). Workflow auth fix. ZAP scan Docker isolation fix. npm packages rebuilt.
- ✅ **Documentation.** OpenMed integration plan v2.
- 📦 **Versioning** — bumped to **1.1.39** across all version-bearing files. Tool count: ~195 base + ~830+ Pro (~1,025+ total; live registry authoritative). Addon count: 26.

---

## Released: v1.1.38 — July 2026 ✅

**Release Date:** July 10, 2026

### What was delivered in v1.1.38

- ✅ **Page Agent Addon v0.1.0.** AI-powered browser page control copilot powered by Alibaba Page Agent (MIT). Client-side only — no headless browser required. Shortcode, Elementor widget, REST endpoints, MCP tool bridge.
- ✅ **Pro SPA v2 — Major Parity & Polish.** Voice pipeline, tasks drawer, workflow tracker, file attachment upload to Media Library. Tool Shortcuts and Slash Commands drawers. Mobile hamburger sidebar. Speech/audio button fixes. Autoscroll fixes (submit, streaming, user-at-bottom guard, scrollTop). Viewport height chain fixes. `filemtime` cache-busting. Assistant preloading. Model sync and auth bypass fixes. Conversation title improvements, turn count fix. Button and token NaN fixes. Deduplicated model selector. REST route registration fix.
- ✅ **Per-User Chat Memory Preferences.** Users can toggle chat memory on/off from WordPress user profile without affecting site-wide defaults.
- ✅ **create_post / save_post Enhancements.** Markdown-to-HTML conversion via new trait. Smart taxonomy suggestions. Block content corruption fix for non-post post types.
- ✅ **Workflow Blueprint & Schedule Improvements.** Existing-content awareness in Content Publisher and Keyword Pipeline blueprints. Blog schedule presets now check for duplicate content. Readable result delivery responses.
- ✅ **SPA Accessibility.** Annotation pills made clickable with meaningful screen-reader labels.
- ✅ **Security.** OWASP ZAP DAST medium findings triaged as false positives.
- 📦 **Versioning** — bumped to **1.1.38** across all version-bearing files. Tool count: ~195 base + ~830+ Pro (~1,025+ total; live registry authoritative). Addon count: 26.

---

## Released: v1.1.36 — July 2026 ✅

**Release Date:** July 4, 2026

### What was delivered in v1.1.36

- ✅ **EZuite Inventory Sync Pro Toolkit.** ERP-integrated inventory sync toolkit: product pull, inventory query, item create/update, ERP settings, CLI sync commands. Admin UI with connection selector on Features tab.
- ✅ **Ralph Loop CCT Migration & Orchestration.** Circuit breaker pattern, execution logger, and CCT migration tools for safe cross-environment data operations.
- ✅ **JetBooking/JetAppointment Integration (8 tools).** Adapter layer for Crocoblock booking/appointment plugins. 8 new tools + 4 enhanced calendar tools.
- ✅ **Moonshot AI (Kimi) & Z.AI (GLM) Provider Parity.** Both providers brought to DeepSeek-level chat client capabilities. ZAI client + baseten service registered in DI container. Class loader updated.
- ✅ **Unified Sync Log Manager.** Per-item audit trail with sync history, error tracking, and status dashboards across EZuite, FlowHub, and Shopify toolkits.
- ✅ **Tool Presets Auto-Select & Chips Bar.** Selected tools display as clickable chips with +N overflow toggle. Tool payload cap raised from 50 to 100.
- ✅ **HTTrack Cache Support & Place-to-Service Bridge.** HTML mirror import now supports HTTrack cache directories. Place-to-Service bridge auto-creates bookable services during batch import. URL backfill for mirrors without hts-cache.
- ✅ **FlowHub Per-Connection Overview.** Remote Sites connection selector, per-connection sync controls, proxy support via http_api_curl hook.
- ✅ **Generate Default Mapping + Read-Only Sync.** One-click default mapping buttons and read-only sync direction for EZuite & FlowHub toolkits.
- ✅ **Web Search 429 Retry.** Exponential backoff for rate-limited search requests.
- ✅ **wp_mcp_ai_log Global Helper.** Centralized logging function available globally.
- ✅ **JetEngine CCT Support for Remote WP Connections.** Remote WordPress connections can now query and manage JetEngine CCT records.
- ✅ **45+ Bug Fixes.** CCT module API mismatches (canonical Module.instance/Factory/ItemHandler across all toolkits). EZuite sync (missing return, field mapping, connections, CCT registration). FlowHub sync (silent failure, proxy persistence, auth headers, null cct module, dry-run). Shopify sync (Catalog API guard, CCT registration lifecycle, meta_fields). Duplicate column errors in ensure_columns. SQLite meta cache explosion. masterminds/html5 case collision. Base-version guard blocking toolkits. Tool registry fatal guards. HTTrack import (URL resolution, hex filenames, subdirectory content, mirror detection). Necessity Gate request context crash. Auto-select compute timeout. Place-to-Service bridge collision.
- ✅ **Documentation.** Abilities registration plan (~1,000 tools as WordPress Abilities). Laravel-scale deployment architecture proposal. WP.org submission prep. Agent context sync.
- 📦 **Versioning** — bumped to **1.1.36** across all version-bearing files. Tool count: ~195 base + ~830+ Pro (~1,025+ total; live registry authoritative). Provider count: 15 first-class (added Moonshot/Kimi & Z.AI/GLM at parity).

---

## Released: v1.1.35 — June 2026 ✅

**Release Date:** June 29, 2026

### What was delivered in v1.1.35

- ✅ **FlowHub Inventory Sync Pro Toolkit (PR #5501).** 6-tool cannabis dispensary management: products, inventory, locations, sync, analytics, alerts. P1 proxy support + P2 CCT auto-registration (PR #5502). Auth, decryption, location_id, null-guard fixes (PRs #5500, #5503, #5507, #5510).
- ✅ **Shopify Sync Pro Toolkit (PR #5502).** 5-tool bi-directional e-commerce sync: products, orders, inventory, analytics, settings. Dashboard widget. Tool reference docs.
- ✅ **Necessity Gate Layer J.** Irreversibility-weighted safety profiles scoring tool calls by risk before execution. Request context crash fix.
- ✅ **Local Voice Embedded STT (PR #5498).** Three pluggable browser-side STT backends (Web Speech, Whisper.cpp WASM, Vosk WASM). Offline-first architecture.
- ✅ **Remote Site Administrator Blueprint.** 22-tool assistant blueprint for full remote/local WP/WooCommerce site management with JetEngine, JetFormBuilder, and REST API control.
- ✅ **Places & Calendar Bulk Import (PR #5509).** Batch import tools for Places and Calendar Booking toolkits.
- ✅ **CLI site-import Subcommand.** Multi-phase HTML mirror import for migrating static sites into WordPress.
- ✅ **Voice Realtime Auto-Detect (PR #5508).** WebRTC/WebSocket auto-selection, duplicate message fix, VAD threshold improvements.
- ✅ **Remote Connections Fixes (PR #5499).** WordPress case handling, FlowHub/Printful credential storage, Printful connection type.
- ✅ **7 Bug Fixes.** Token-scoped assistant resolution (PR #5497), user_id empty fallback (PR #5495), credential token mapping (PR #5493), post type name lengths (PR #5484), OpenAI image deprecation cleanup (PRs #5489–#5491).
- ✅ **Documentation.** Agent context sync — AGENTS.md, CLAUDE.md, .context/pro-vs-base.md updated.
- 📦 **Versioning** — bumped to **1.1.35** placeholder across all manifests. Tool count: ~195 base + ~810+ Pro (~1,005+ total; live registry authoritative).

---

## Released: v1.1.34 — June 2026 ✅

**Release Date:** June 27, 2026

### What was delivered in v1.1.34

- ✅ **GPT-Realtime-2 Voice Models Upgrade (PR #5479).** GA Realtime API migration with WebRTC transport (WebSocket fallback). GPT-Realtime-2 with 128K context + reasoning. 2 new models: Translate (70+ in → 13 out) and Whisper (streaming STT). 12-section prompt template. Parallel tool calling. wait_for_user tool. 7 new admin settings.
- ✅ **Multi-Channel Result Delivery UI (PR #5465).** Telegram, Discord, WhatsApp, Google Chat channels in schedule modal. 11 channels now exposed in admin UI.
- ✅ **Pro Scheduler AI/Workflow Response Delivery (PR #5466).** AI responses routed through scheduler delivery pipeline.
- ✅ **Graphify Ecosystem (PRs #5475–#5480).** Remote source drivers with Bridge class. WP 7.0 Connectors credential resolution. wp.org compliance sweep. Plugin Sources tab and Remote UI fixes.
- ✅ **3 Reasoning-Tool Fatal Bugs Fixed.** enable_reasoning_mode, analyze_code_sequence, validate_reasoning_chain called non-existent success() method; replaced with format_chat_response(). Plus trim() on array fix and count() on null guard.
- ✅ **CRM Pipeline Fixes (PRs #5469, #5474, #5473).** Deal import + Gmail fix. Multi-source auto-import. Upwork/LinkedIn API/web_search mode toggle.
- ✅ **Docs Hub Fixes (PRs #5472, #5468).** REST fatal error + plain-permalink URL bug. Settings sync reliability + rebuild reliability + repo lookup.
- ✅ **Security (PRs #5464, #5463, #5461).** http-proxy-middleware CVE-2026-55602. Gemini cache fix. GPT image routing fix.
- ✅ **nv-cloud-init Guard (PR #5470).** file_exists() check before require_once.
- ✅ **wait_for_user Tool Fixes.** Base class loading and invalid extension resolved.
- ✅ **Documentation.** GPT-Realtime-2 upgrade proposal + 1,166-line implementation plan. FastAPI porting plan (PR #5467).
- ✅ **WP-CLI Smart Tool Test.** 409 tools tested; 28 confirmed working; 3 fatal bugs fixed.
- ✅ **Housekeeping.** Stale build artifacts and toolkit-addons directory removed.

> 💡 **This release completed the full v1.3.0 scope** (OpenAI Realtime GA, WebRTC transport, GPT-Realtime-2 reasoning, new voice models, voice tooling) — delivered early in the v1.1.x line.

See [CHANGELOG.md](../CHANGELOG.md) for complete details.

---

## Released: v1.1.33 — June 2026 ✅

**Release Date:** June 24, 2026

### What was delivered in v1.1.33

- ✅ **WP 7.0 Connectors Credential Integration (PR #5458).** Credential_Resolver integrated into all 17 AI client get_api_key() methods. Fallback chain: WP 7.0 Connectors → plugin settings → env vars → PHP constants. Credential source badges and hints in admin UI. All 13 provider API key descriptions updated. Provider diagnostics show key source column.
- ✅ **nvoos-graphify v1.0.0 Release (PR #5456).** Standalone plugin at v1.0.0 (Plugin Check compliant). nvoos-graphify-ai at v1.0.0-dev. Fixed 8 escaping errors, critical prepare() spread-operator bug, vector column rename.
- ✅ **Security Dependencies (PR #5457).** guzzlehttp/guzzle 7.10.0→7.12.1 (CVE-2026-55568, CVE-2026-55767). guzzlehttp/psr7 2.11.0→2.12.1 (CVE-2026-55766). undici npm override ≥8.5.0.
- ✅ **npm Security (PR #5438).** 29 alerts resolved across 14 packages. Fixed critical duplicate overrides key.
- ✅ **Bug Fixes.** WP All Import/Export Pro tool paths fixed. Tool status label loader hardened against leaked warnings.
- ✅ **Dependencies.** 15 Dependabot bumps across Composer, npm, and GitHub Actions.
- ✅ **Versioning.** Tool count: ~195 base + ~795 Pro (~990 total; live registry authoritative).

---

## Released: v1.1.32 — June 2026 ✅

**Release Date:** June 19, 2026

### What was delivered in v1.1.32

- ✅ **Content Format Templates & Featured Image Generation (PR #5433).** Content Format Template CPT. AI-powered template engine. Featured image service with 3-provider fallback (DALL-E/Gemini/Cloudflare) and 5 image styles. 5 default templates seeded on activation.
- ✅ **Result Delivery Pipeline (PR #5425).** Routes schedule results to 8 channels (email, Slack, Discord, Telegram, SMS, Paper Store, WordPress post, webhook). Both success and failure paths deliver.
- ✅ **ECA Document Generation (PR #5423).** ECA Consolidate & Add page with document generation tools.
- ✅ **Duplicate Posts & Provider Image Fixes (PR #5434).** WordPress delivery removed from blog presets to prevent duplicates. Systemic guard skips WordPress delivery when AI tool calls include create_post/save_post.
- ✅ **6 Provider Clients Timeout Fix (PR #5431).** DeepSeek, Baseten, DigitalOcean, OpenRouter, Kimi, Cloudflare now respect global request_timeout setting.
- ✅ **Schedule Trigger Stability (PRs #5429, #5430).** Trigger crash fixed. Save persistence restored.
- ✅ **npm CI & Jest Resilience (PR #5428).** Babel ESM override for Node 18/20. Jest graceful fallback.
- ✅ **Dependencies.** 14 safe Dependabot bumps. 8 npm audit CVEs. phpspreadsheet 5.7.0→5.8.0.
- ✅ **Versioning.** Tool count: ~195 base + ~795 Pro (~990 total; live registry authoritative).

---

## Released: v1.1.29 — June 2026 ✅

**Release Date:** June 12, 2026

### What was delivered in v1.1.29

- ✅ **Pro Toolkit Optimizations Phase 1–3.** Autoload control, query caching, and lazy loading across 6 Pro toolkits.
- ✅ **Chat Transcript & Memory Retention.** Configurable TTL-based cleanup (Base 437 lines + Pro 358 lines).
- ✅ **DietPi Pro Toolkit Phases 0–3.** 19+ tools for DietPi server management (system info, backup, provisioning, SSH proxy). MCP server integration.
- ✅ **Layer I Guardrails — Jailbreak Prevention.** Pre-provider jailbreak detection and capability boundary enforcement.
- ✅ **Context Window Management.** Pre-flight validation across all 13 providers via shared validate_context_window() helper with tiktoken integration.
- ✅ **LibreChat Addon.** Code interpreter, speech services, and web search reranker.
- ✅ **Schedule Anything SaaS Platform.** Full SaaS booking platform with Stripe integration.
- ✅ **Vector Search Enhancements.** HNSW index, content/context embedding stores, hybrid semantic search.
- ✅ **CRM Enhancements.** Email import with scheduled polling, lead pruning, inline tag editing, email priority/exclude actions.
- ✅ **Real-Time SSE Streaming.** Enabled for OpenAI, DeepSeek, and all OpenAI-compatible providers.
- ✅ **35 New OOS Core Tools.** Data, format, infrastructure, and cache tools.
- ✅ **Bug Fixes.** 25+ fixes across CPT slugs, agentic loop persistence, rendering, well-known endpoints, and more.
- ✅ **Security.** guzzlehttp/psr7 CVE-2026-49214, shell-quote CVE-2026-9277.
- ✅ **Versioning.** Tool count: ~195 base + ~795 Pro (~990 total; live registry authoritative).

---

## Released: v1.1.25 — May 2026 ✅

**Release Date:** May 31, 2026

### What was delivered in v1.1.25

- ✅ **Unified Blueprint System.** 55 pre-built AI assistants across 25 toolkits with one-click import. JSON Schema validation. Healthcare and Aerlinn blueprints.
- ✅ **Cloudways Pro Toolkit.** 60 AI tools for server/app management via Cloudways API v2. OAuth singleton.
- ✅ **CRM Toolkit Phases A–E Complete.** 70+ tools: Lead Management, Multi-channel Triage, Integration Hooks, Extensibility Hooks, Compliance.
- ✅ **Chat UI Enhancements.** 7 features: profile card, stop generation, feedback widget, code copy, dark mode, saved prompts, prompt search.
- ✅ **Unix-Theory Reorg Phase 4–5.** Pro tools reorganised into modular Unix-theory folders.
- ✅ **Versioning.** Tool count: ~195 base + ~765 Pro (~960 total; live registry authoritative).

---

## Released: v1.1.24 — May 2026 ✅

**Release Date:** May 27–28, 2026

### What was delivered in v1.1.24

- ✅ **Bug-Fix & Stabilisation Sweep.** Paper Store Pro interface load order fix. Chat SPA duplicate-message and SSE protocol-mismatch fixes. Markdown rendering enabled in Chat SPA.
- ✅ **Skill Manager Canonical Return Envelope — Unix Theory P0/P1.** Skills sync endpoint added. YAML frontmatter parsing hardened.
- ✅ **Assistant Tool Presets — 24 Missing Tools Added.**
- ✅ **CVE Patches.** tmp ≥0.2.6, symfony/cache ^6.4.40.
- ✅ **Paper Store Admin CRUD.** Full CRUD admin UI under Assistants menu.
- ✅ **CLI Coverage Enhancements.** Comprehensive WP-CLI command coverage improvements.
- ✅ **Folder README Convention — Unix Theory P7 Complete.** Every PHP subdirectory ships a README.md. CI enforcement via `composer run docs:check-folder-readmes`.
- ✅ **Agent Context Docs Synced.** CLAUDE.md, AGENTS.md, and .github/agents/ synced.

---

## Released: v1.1.23 — May 2026 ✅

**Release Date:** May 25–26, 2026

### What was delivered in v1.1.23

- ✅ **Zed-Inspired SPA Architecture (Pro + Base).** 9-phase React SPA admin interface with Threads Sidebar, Agent Panel, Command Palette, Agent Profiles, @-mention Context, Checkpoints & Diff Review, Inline Assistant, Multi-Model Comparison, Collaborative Presence. ~75 files, ~10,800 lines.
- ✅ **Antigravity Interactions API Rewrite.** Gemini Managed Agent service rewritten for real Antigravity API.
- ✅ **TypeScript Upgrade + Orchestration Toggles.** Shared TS types, services, admin screens, chat drawer, React SPA builds.
- ✅ **Comic Reader Addon.** React-based CBR/CBZ/CB7/CBT reader with dual-page modes, zoom, keyboard nav.
- ✅ **Media Studio v0.3.0.** Zoom/pan, drawing tools (Konva canvas), save-to-WP-Media-Library.
- ✅ **SPA Blueprint v3.0.** External React template ingestion & gap analysis pipeline.
- ✅ **30+ Reliability Fixes.** Cron status diagnostics, PHP 8.2+ compatibility, PHPUnit 11, WP.org compliance re-audit, DeepSeek fallback, test suite stability.
- ✅ **Dev Dependencies.** wp-phpunit 7.0.0, wordpress-stubs 7.0.0.

---

## Released: v1.1.22 / v1.1.21 / v1.1.20 / v1.1.19 / v1.1.18 — May 2026 ✅

**Release Dates:** May 14–23, 2026

### What was delivered across these five releases

- ✅ **Baseten AI — 11th first-class provider.** OpenAI-compatible chat/tools/streaming/reasoning.
- ✅ **CoSAI Secure-by-Design Agentic System.** Capability boundary, cryptographic audit trail, risk-tiered approval gate, isolated code sandbox (Python/Node.js/Bash/PHP).
- ✅ **Gemini I/O 2026 Model Refresh.** Gemini 3.5 Flash as recommended model. Gemini Omni Flash as video default.
- ✅ **Continual Harness P5.** Self-improving agent system with execution history learning.
- ✅ **SaaS Controller Phase 2 & 4.** Stripe + OpenRouter deployment editors from WP-Admin.
- ✅ **npm packages — nvoos-vad, nvoos-chat-bubble, nvoos-chat-memory-ui.**
- ✅ **WordPress Studio Test Environment.** Auto-detect Studio DB/ABSPATH/site URL in test bootstrap.
- ✅ **Security fixes.** UUID buffer bounds. map_meta_cap=false for audit trail CPT. AV false positives in test suite.
- ✅ **Allowed providers expanded.** DeepSeek, OpenRouter, DigitalOcean, Kimi, Baseten added to validation gate.
- ✅ **v1.1.21/v1.1.20/v1.1.19/v1.1.18 — WordPress.org Compliance Complete (F1–F10).** All inline JS/CSS removed from 53 files. 11 PHP parse errors fixed. All 10 reviewer findings resolved. ⭐ **READY FOR RE-SUBMISSION**
- ✅ **Canonical Return Envelope — Unix Theory P0/P1 Complete.** 191 non-canonical returns → WP_Error across 105 files.
- ✅ **Capability Fence P2b — Full Rollout.** get_required_capability() deployed to all ~830 tool classes.
- ✅ **Security Center.** 5-tab admin page with live security scoring, compliance report, OTel telemetry.
- ✅ **Semantic Caveman Compression.** 1,988 lines + 1,156 test lines. Opt-in prompt token optimization.
- ✅ **AI Prompt Caching — All Providers.** Chat response cache + prompt optimizer across all 5 AI providers.
- ✅ **Memory Layer 2026 — Phases 3–8 Merged.** Auto-capture (P3), RRF fusion retrieval (P4), confidence decay + contradiction detection (P5), provenance tracer (P6), Memory Health + Retrieval Waterfall + Session Replay (P7), documentation (P8).
- ✅ **Kimi (Moonshot AI) provider — 10th first-class provider.** kimi-k2.6 (256K, default), kimi-k2-thinking (CoT).
- ✅ **ACP Server** — Full Agent Client Protocol implementation (JSON-RPC 2.0 over HTTP/SSE).
- ✅ **MCP Bridge** — bin/mcp-bridge.js stdio-to-HTTP relay for Claude Desktop, Cursor, Zed.
- ✅ **Unix Theory P0–P6** — canonical return envelope, capability-fence audit, data-contract metadata, tool-lifecycle descriptor, back-compat alias infrastructure, sanitize-at-entry sniff.
- ✅ **DigitalOcean Serverless Inference provider** (9th provider).
- ✅ **Async Chat Continuation** (slices 1–6) — durable store, dispatcher, LLM re-entry, SSE frame buffer.
- ✅ **Jobs/Tasks Drawer + Cron-Status** — inline job-progress cards, cancel/retry routes.
- ✅ **Model Catalog May 2026 Refresh.** DeepSeek V4 model family added.
- ✅ **GDPR — JetEngine Privacy Exporters.** CCT data exporters for transcripts, memory, and approvals.
- ✅ **Security Hardening (5 patches).** Settings-key encryption, webhook secret enforcement, SSRF via wp_safe_remote_get.
- ✅ **Folder README Convention Phase P7.** Every PHP subdirectory ships a README.md — completing Unix Theory P0–P7.
- ✅ **@wordpress/env Dev Dependency.** Zero-config local WP environments via wp-env.
- ✅ **Domain Migration.** All nvoos.com references → nvoos.pro / nvoos.cloud.

---

## Released: v1.1.17 / v1.1.16 / v1.1.15 / v1.1.14 — May 2026 ✅

**Release Dates:** May 1–10, 2026

### What was delivered across these four releases

- ✅ **Orchestration Phases 1–7** — HITL approval queue, prompt-injection detector, structured output + OTel span exporter, DAG builder, durable runs, triggers/webhooks, sub-agents, Pro vector-store adapter, Pro team budget manager
- ✅ **LLM Harnessing Subsystem (Layers A–H)** — seven opt-in per-assistant epistemic layers (prompt cues, reasoning/self-consistency, tool router, retrieval+citation, self-refine, memory scoping+PII, eval scheduler) + Pro Layer H fine-tune curriculum export
- ✅ **Toolkit MCP Servers — all 7 phases complete** — 26 toolkits (19 Tier-1 + 7 Tier-2) exposed as per-toolkit MCP servers; `/.well-known/mcp` discovery endpoint
- ✅ **New AI providers** — OpenRouter, DeepSeek, LM Studio native cURL SSE streaming, Kimi K2.6 + Qwen 3.6 in catalog
- ✅ **Chat SPA addon (v0.6.0, Phases 1–7)** — React replacement for legacy chat shortcode
- ✅ **SaaS Controller addon (v0.1.0, 11 phases)** + **Cloud Worker (Phase 3)**
- ✅ **Docs Hub addon (v0.1.0 → v0.3.8)** — remote-first React SPA documentation portal
- ✅ **Toolkit SPA Blueprint Phases 5–12** — a11y, i18n, PHPUnit tests, bundle-size CI guardrail
- ✅ **Inline-async-tick pattern** — 8 Tier-1 consumers
- ✅ **WordPress.org Compliance Hardening (B1–B13)** — all reviewer findings resolved
- ✅ **PHPUnit + Vitest coverage campaign** — 271 AJAX handlers covered
- ✅ **Dependabot security sweep** — 33 alerts resolved
- ✅ **Agent Skills Phases 1–4** — 28+ bundled Pro WP-developer skills
- ✅ **Markup Subsystem (Base)** — in-loop Konva canvas widget for image annotation
- ✅ **MemPalace Capture Framework Phases A + B1** — five capture tools onto the durable memory bridge

---

## Released: v1.1.3 — March 2026 ✅

**Release Date:** March 3, 2026
**Focus:** WordPress.org compliance final audit, Telegram Mini App media tab, Office 365 & iCloud Drive, Gemini Corpus RAG, Tavily web search

### What Was Delivered in v1.1.3

- ✅ 165 built-in tools (base version) — up from 127 in v1.1.0
- ✅ 354 Pro tools (full version) = 519 total tools — up from 197 in v1.1.0
- ✅ WordPress.org compliance 100% complete
- ✅ **Gemini Corpus Native RAG** — Semantic Retrieval API integration
- ✅ **Web Search: Tavily provider** — AI-first search with geo/freshness parameters
- ✅ **Office 365 & iCloud Drive** — 8 new Chat Channels Toolkit tools
- ✅ **Telegram Mini App Media Badges** — File-type extension badges with WCAG-compliant styling
- ✅ **Chat Channels Toolkit** — Now 47 tools across 11 platforms

---

## Released: v1.1.2 — February 2026 ✅

**Release Date:** February 16, 2026
**Focus:** WordPress.org compliance, Pro integration settings architecture

### What Was Delivered

- ✅ Hardcoded Menu Position Fix — Removed hardcoded positions from 5 CPT/menu registrations
- ✅ Pro Settings Architecture — Moved Mailjet, Google Analytics, Yahoo Fantasy, ESPN Fantasy settings to pro addon
- ✅ JetEngine CPT/Taxonomy AI Integration — AI assistant metaboxes for all JetEngine CPTs
- ✅ Package Pre-Bundling — pdf-lib, pdfkit, docx, exceljs, qrcode, cheerio pre-bundled
- ✅ TMA CMS Overhaul — Telegram Mini App transformed into full WordPress CMS interface
- ✅ Discord/Telegram Reactions
- ✅ WhatsApp & Messenger Fixes — Group routing, auto-reply, webhook processing
- ✅ Google Chat Fixes — HTTP 404 fix, OAuth UX, thread routing
- ✅ OAuth Improvements — Google, Yahoo, Mailjet credential handling
- ✅ Pro Workflow Builder Stability — React asset loading, initialization timing

---

## Released: v1.1.1 — February 2026 ✅

**Release Date:** February 6, 2026
**Focus:** Security fixes, Chat Channels Toolkit, Slash Commands, WebChat Rooms

### What Was Delivered

- ✅ **SSRF Protection** — Webhook registration blocks private IPs and internal networks
- ✅ **CSRF Protection** — Cron job deletion secured with nonce
- ✅ **XSS Prevention** — Error messages double-escaped
- ✅ **Authorization System** — Comprehensive multi-entity job access control
- ✅ **Chat Channels Toolkit** — 21 tools across 6 platforms (Telegram, WhatsApp, Slack, Discord, Teams, Messenger)
- ✅ **Slash Commands System** — Phase 1 (8 core commands) + Phase 2 (21 Pro toolkit commands); 7 automated workflow templates
- ✅ **WebChat Rooms** — Real-time collaborative rooms, JetEngine CCT message persistence, WebRTC support
- ✅ **E-commerce Toolkit** — Now enabled by default for new installations

---

## Released: v1.1.0 — January 2026 ✅

**Release Date:** January 28, 2026
**Focus:** Multi-agent orchestration, social media analytics expansion, stability improvements

### What's Included

**Core Features:**
- ✅ 127 built-in tools (base version) — up from 95
- ✅ 70 Pro tools (full version) = 197 total tools — up from 133
- ✅ OpenAI GPT integration, Google Gemini integration, Ollama integration
- ✅ MCP protocol implementation (2024-11-05 spec)
- ✅ Server-Sent Events (SSE) streaming
- ✅ Assistant management (CPT + optional CCT)
- ✅ REST API with authentication (Bearer tokens, Auth0)
- ✅ Project management system (Pro)
- ✅ Comprehensive documentation (659 files, 100% feature coverage)

**NEW in v1.1.0:**
- ✅ **DeepSeek V4 Multi-Agent Orchestration** — 4 specialized agent roles, 5 aggregation strategies, 200+ profession orchestration
- ✅ **Social Media Analytics Toolkit Expansion** — 4 new analytics tools (15→19 total)
- ✅ **Pro Toolkit Memory-Based Tracking** — Replaced hard 5-toolkit limit
- ✅ **7 Critical Bug Fixes**

**AI Integrations:**
- ✅ OpenAI Batch API (50% cost savings)
- ✅ OpenAI Moderation API (content safety)
- ✅ Gemini Geospatial API (location queries)
- ✅ Hugging Face integration (multiple models)
- ✅ Cloudflare Workers AI (image generation)
- ✅ Crawl4AI integration (web scraping)

---

## ✅ Previously Planned v1.3.0 & v1.2.0 Scope — Delivered Early

The following features were originally planned for v1.2.0 (Q2 2026) and v1.3.0 (Q2 2026). **All items below are now delivered** across the v1.1.x release line (v1.1.1 through v1.1.35).

### v1.3.0 Scope — Delivered in v1.1.34 (June 27, 2026) ✅

| Feature | Status | Delivered In |
|---------|--------|-------------|
| OpenAI Realtime API GA Migration | ✅ | v1.1.34 |
| WebRTC Transport (WebSocket fallback) | ✅ | v1.1.34 |
| GPT-Realtime-2 Reasoning (128K context) | ✅ | v1.1.34 |
| GPT-Realtime-Translate (70+ in → 13 out) | ✅ | v1.1.34 |
| GPT-Realtime-Whisper (streaming STT) | ✅ | v1.1.34 |
| Voice Tooling (wait_for_user, PTT, commentary display) | ✅ | v1.1.34 |

### v1.2.0 Scope — Delivered across v1.1.1–v1.1.35 ✅

| Feature | Status | Delivered In |
|---------|--------|-------------|
| Federation Directory Rate Limiting (60 req/min) | ✅ | v1.1.1 (Feb 2026) |
| SSE Connection Rate Limiting (per-user + global) | ✅ | v1.1.x (Mar 2026) |
| CORS Origin Allowlist (configurable) | ✅ | v1.1.x (Mar 2026) |
| Automated Security Tests (SSRF/XSS/CSRF/auth) | ✅ | v1.1.x (Mar 2026) |
| Task Dependencies (parent-child, cycle detection) | ✅ | v1.1.x (Mar 2026) |
| PM Notification System (email + cron) | ✅ | v1.1.x (Mar 2026) |
| Anthropic Claude Integration (Claude 3/4, streaming, tools, vision) | ✅ | v1.1.3 (Mar 2026) |
| Gemini Context Caching / RAG | ✅ | v1.1.3 (Mar 2026) |
| Transformer-Inspired Attention Routing (QKV semantic tool selection) | ✅ | v1.1.x (Jun 2026) |
| GitHub Project Management (labels, milestones, automation) | ✅ | v1.1.x |
| Threat Model in SECURITY.md | ✅ | v1.1.x |

---

## Pro Toolkits Roadmap 🎨

### Active Toolkits (21+) ✅ **ALL IMPLEMENTED**

**Core Business Toolkits (7):**
1. ✅ **E-commerce** (20+ tools) — Product management, orders, cart recovery, WooCommerce integration
2. ✅ **Social Media** (19+ tools) — Cross-platform analytics, hashtag tracking, competitor analysis
3. ✅ **Analytics** (12+ tools) — Reporting, dashboards, data insights
4. ✅ **Multilingual** (10+ tools) — Translation, localization, multi-language content
5. ✅ **Video Production** (12+ tools) — Video generation, transcription, editing
6. ✅ **Financial Planner** (24+ tools) — Budgeting, forecasting, financial analysis
7. ✅ **Document Generation** (3+ tools) — PDF, Word, Excel generation

**Advanced Professional Toolkits (6):**
8. ✅ **Calendar Booking** (15+ tools) — Appointments, availability, calendar sync (Google, Outlook)
9. ✅ **DJ Management** (18+ tools) — Music library, playlist generation, event booking
10. ✅ **Image Production** (15+ tools) — AI image generation, editing, optimization, batch processing
11. ✅ **AI Tool Builder** (10+ tools) — Meta-toolkit for creating custom tools
12. ✅ **Architectural Design** (16+ tools) — Floor plans, 3D models, construction drawings
13. ✅ **CRM** (70+ tools) — Lead management, multi-channel triage, compliance, analytics dashboard

**Added Since v1.1.0 (8+ toolkits):**
14. ✅ **Chat Channels** (47+ tools, 11 platforms) — Telegram, WhatsApp, Slack, Discord, Teams, Messenger, Google Chat, Instagram, YouTube, Office 365/OneDrive, iCloud Drive
15. ✅ **Regulatory Registration** (59 tools) — Multi-country product registration, MOHAP & NMRA sync, compliance certificates
16. ✅ **Site Creator** (27 tools) — Landing pages, hero/CTA sections, theme scaffolding, template kit export
17. ✅ **Vector Storage** — Prepare files for OpenAI vector stores; file_search RAG workflows
18. ✅ **DietPi Pro** (19+ tools) — Server management, backup, provisioning, SSH proxy
19. ✅ **Cloudways** (60+ tools) — Server/app management via Cloudways API v2, DNS, security, backups
20. ✅ **FlowHub Inventory Sync** (6 tools) — Cannabis dispensary management: products, inventory, locations, analytics
21. ✅ **Shopify Sync** (5 tools) — Bi-directional e-commerce sync: products, orders, inventory, analytics

**Added Since v1.1.36 (4+ toolkits):**
22. ✅ **EZuite ERP Sync** (6 tools) — ERP inventory → WooCommerce sync, CCT cache, low-stock alerts

> ℹ️ **Tool counts are approximate.** The live tool registry (`WP_MCP_AI_Tool_Registry::get_tools()`) is the authoritative source. Total Pro tools: ~810+ across 22+ toolkits. Combined with ~195 base tools = ~1,005+ total.

### Toolkit MCP Server Fleet (ADR 002)

Currently **29 toolkit MCP servers** across Phases 1, 2, 6, and DietPi — each with its own JSON-RPC endpoint under `/wp-json/mcp-ai-pro/v1/mcp/{slug}`, discovery descriptor, and per-server admin governance.

**Phase 8 (Proposed — v1.5.0):** Promote 4 additional toolkits to MCP servers:
- Pro Scheduler (14 tools, R&A surface)
- FlowHub Inventory Sync (6 tools)
- Shopify Sync (5 tools)
- EZuite ERP Sync (6 tools)

→ See [`docs/project/proposals/pro-toolkit-mcp-servers-expansion-plan.md`](project/proposals/pro-toolkit-mcp-servers-expansion-plan.md) for the full proposal.

### Future Expansion Opportunities 💡

Potential future toolkits based on community demand:
- Healthcare Management (appointment scheduling, patient records, HIPAA compliance)
- Legal Practice Management (case tracking, document automation, billing)
- Education Management (course creation, student tracking, gradebook)
- Real Estate (property listings, showings, client management)
- Hospitality & Events (venue management, catering, RSVPs)

*These are conceptual only — no active development planned.*

---

## Next Minor (v1.4.0) — Q3 2026 ⚡

**Target:** July–August 2026
**Focus:** Cross-platform extraction progress, tool migration acceleration, Pro toolkit hardening

### Major Features

#### 1. Cross-Platform Extraction — Phase 3 Acceleration
- [ ] Migrate remaining ~152 base tools to OOS engine (currently 43/195 — 22%)
- [ ] Begin Pro tool OOS migration (0/~810)
- [ ] Establish `lib/core` test suite (currently 0%)
- [ ] Monorepo CI/CD pipeline for lib/ packages
- [ ] Publish `nvoos/core` and `nvoos/wordpress-adapter` to Packagist

**Estimated Effort:** 8–16 weeks
**Priority:** High
**Rationale:** The OOS extraction is operational behind a feature flag but cannot be activated in production without tool parity and test coverage. Closing the gap unlocks cross-platform deployment (Laravel, Craft CMS, standalone PHP).

#### 2. Laravel Adapter Spike
- [ ] Implement Eloquent-based ContentStore adapter
- [ ] Implement Sanctum-based AuthProvider adapter
- [ ] Implement Laravel Storage-based FileStore adapter
- [ ] Proof-of-concept: run OOS engine inside a Laravel application

**Estimated Effort:** 4–6 weeks
**Priority:** Medium

#### 3. Pro Toolkit Performance Hardening
- [ ] Extend autoload control to remaining Pro toolkits
- [ ] Query caching for frequently-accessed toolkit data
- [ ] Lazy-loading for large toolkit dependency trees
- [ ] Admin performance dashboard for toolkit load times

**Estimated Effort:** 3–4 weeks
**Priority:** Medium

#### 4. Documentation & Developer Experience
- [ ] PM REST API documentation (cURL/JS examples, Postman collection)
- [ ] SDK guide for third-party tool development
- [ ] Interactive API playground
- [ ] Tool reference docs for new toolkits (DietPi, Cloudways, FlowHub, Shopify)

**Estimated Effort:** 2–3 weeks
**Priority:** Medium

---

## Next Major (v2.0.0) — Q4 2026 🚀

**Release Date:** December 2026 (revised from September 2026)
**Focus:** Enterprise features, cross-platform maturity, advanced workflow automation

### Major Features

#### 1. Cross-Platform Extraction — Production Release 🟡
- [ ] Complete all ~195 base tool migrations
- [ ] Pro tool migration at ≥50%
- [ ] Full test suite for extracted packages
- [ ] Laravel adapter production-ready
- [ ] Craft CMS adapter
- [ ] Monorepo CI/CD + Packagist publishing
- [ ] Deprecation plan for WordPress-only tool paths

See [`docs/project/proposals/cross-platform-extraction-gap-analysis.md`](project/proposals/cross-platform-extraction-gap-analysis.md) for detailed status.

#### 2. Advanced Workflow Automation
- [ ] Custom workflow builder UI
- [ ] Trigger-action automation system
- [ ] Conditional logic for workflows
- [ ] Integration with WordPress hooks
- [ ] Workflow templates library
- [ ] Visual workflow editor

**Estimated Effort:** 60–80 hours
**Value:** High — Enables no-code automation

#### 3. Team Collaboration Features
- [ ] Team workspaces
- [ ] Shared assistants and tools
- [ ] Team chat and comments
- [ ] @mentions and notifications
- [ ] Activity feeds
- [ ] Team analytics dashboard

**Estimated Effort:** 40–50 hours
**Value:** High — Enables team adoption

#### 4. Custom Role-Based Permissions
- [ ] Granular capability system
- [ ] Custom roles (beyond WordPress defaults)
- [ ] Per-assistant permissions
- [ ] Per-tool permissions
- [ ] Permission inheritance
- [ ] Audit logs for permission changes

**Estimated Effort:** 30–40 hours
**Value:** High — Enterprise security

#### 5. Time Tracking and Reporting
- [ ] Log time on tasks
- [ ] Estimated vs actual hours
- [ ] Time reports by project/user
- [ ] Billable hours tracking
- [ ] Export to CSV/PDF

**Estimated Effort:** 16–20 hours
**Value:** Medium — Professional PM feature

#### 6. Advanced Analytics Dashboard
- [ ] AI usage analytics
- [ ] Cost tracking and forecasting
- [ ] Performance metrics
- [ ] Custom report builder
- [ ] Scheduled reports (email)

**Estimated Effort:** 40–50 hours
**Value:** High — Data-driven decisions

### Breaking Changes

#### Tool Interface Refactoring
- **Current:** `WP_MCP_AI_Tool_Interface`
- **New:** Enhanced interface with async support
- **Migration:** Automated migration tool provided
- **Impact:** All custom tools need updating

#### Settings Restructure
- **Current:** Flat options array
- **New:** Hierarchical settings API
- **Migration:** Automatic migration on upgrade

#### REST API v2
- **Current:** `/wp-json/mcp-ai/v1/`
- **New:** `/wp-json/mcp-ai/v2/`
- **Changes:** Improved error handling, pagination
- **Migration:** v1 deprecated but supported for 6 months

### Additional Features

**AI Provider Enhancements:**
- [ ] Advanced streaming controls
- [ ] Multi-provider fallback
- [ ] Cost optimization engine

**Performance:**
- [ ] Caching layer improvements
- [ ] Database query optimization
- [ ] Lazy loading for tools
- [ ] Background job queue

**Developer Tools:**
- [ ] Plugin API documentation site
- [ ] SDK for third-party integrations
- [ ] Webhook system

**Timeline (revised):**
- Alpha: September 2026
- Beta: October 2026
- RC: November 2026
- Release: December 2026
- Migration period: 6 months (v1 API support until June 2027)

---

## Future Considerations (Post v2.0.0) 🔮

### Under Evaluation

#### Mobile App Integration
- Native iOS/Android apps
- Progressive Web App (PWA)
- Mobile-optimized chat UI
- Push notifications

#### Third-Party PM Tool Integrations
- Jira, Asana, Trello, Linear integration
- Bidirectional task sync

#### Advanced AI Features
- ✅ Multi-agent workflows — **DELIVERED in v1.1.0 (January 2026)** 🎉
- [ ] AI-powered project insights
- [ ] Predictive analytics
- [ ] Automated task assignment
- [ ] Smart scheduling

#### Multi-Language Support Expansion
- RTL language support
- Professional translations (10+ languages)
- Regional AI provider support

#### Visual Builder
- Drag-and-drop assistant builder
- No-code tool creation
- Visual workflow designer
- Template marketplace

#### Enterprise Features
- SAML/SSO authentication
- Advanced audit logging
- Compliance certifications
- SLA monitoring
- White-label options

---

## Community Priorities

### Top Community Requests

Based on GitHub issues and community feedback (as of June 2026):

1. **Task Dependencies** — ✅ **DELIVERED** in v1.1.x (Mar 2026)
2. **Notification System** — ✅ **DELIVERED** in v1.1.x (Mar 2026)
3. **Time Tracking** — 📋 Planned for v2.0.0
4. **Claude Integration** — ✅ **DELIVERED** in v1.1.3 (Mar 2026)
5. **Gantt Charts** — 📋 Planned for v2.0.0
6. **Mobile App** — 🔮 Under evaluation
7. **Jira Integration** — 🔮 Under evaluation
8. **Multi-language UI** — 🔮 Future consideration

---

## Release Calendar

### 2026 Release Schedule (Actual + Projected)

| Version | Type | Release Date | Focus |
|---------|------|-------------|-------|
| v1.0.1 | Patch | Jan 15, 2026 | Stability |
| v1.1.0 | Minor | Jan 28, 2026 | Multi-Agent Orchestration |
| v1.1.1 | Patch | Feb 6, 2026 | Security + Chat Channels |
| v1.1.2 | Patch | Feb 16, 2026 | WP.org Compliance |
| v1.1.3 | Patch | Mar 3, 2026 | Gemini RAG + Media Tab |
| v1.1.14–v1.1.17 | Minor | May 1–10, 2026 | Providers + Orchestration + Coverage |
| v1.1.18–v1.1.22 | Minor | May 14–23, 2026 | WP.org Compliance Final + Compression + Memory |
| v1.1.23 | Minor | May 25–26, 2026 | SPA Architecture + Antigravity API |
| v1.1.24 | Patch | May 27–28, 2026 | Stabilisation + CVE Patches |
| v1.1.25 | Minor | May 31, 2026 | Blueprints + Cloudways + CRM Phases A–E |
| v1.1.29 | Minor | Jun 12, 2026 | Pro Toolkit Optimizations + DietPi + Layer I + Retention |
| v1.1.32 | Minor | Jun 19, 2026 | Content Templates + Result Delivery Pipeline |
| v1.1.33 | Patch | Jun 24, 2026 | WP 7.0 Connectors + Graphify v1.0 + Security |
| v1.1.34 | Minor | Jun 27, 2026 | GPT-Realtime-2 + Graphify Ecosystem |
| v1.1.35 | Patch | Jun 29, 2026 | FlowHub + Shopify + Necessity Gate + Local Voice STT |
| v1.4.0 | Minor | Jul–Aug 2026 | OOS Extraction Acceleration + Laravel Adapter |
| v2.0.0 | Major | Q4 2026 | Enterprise Features + Cross-Platform GA |

**Note:** Dates are targets and may shift based on development progress and community feedback.

---

## FAQ

**Q: How do I request a feature?**
A: Create a [Feature Request](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues/new?template=feature_request.md) issue.

**Q: When will feature X ship?**
A: Check this roadmap and linked GitHub milestones. Dates are estimates and may change.

**Q: Can I implement a feature myself?**
A: Yes! Comment on the issue first, then submit a PR following our contributing guidelines.

**Q: How often is the roadmap updated?**
A: Monthly, or more frequently if major changes occur.

---

## Change Log

| Date | Version | Changes |
|------|---------|---------|
| 2026-06-29 | 2.0 | Major rewrite: added Current Capability Snapshot; merged v1.2.0 & v1.3.0 into "Delivered Early" section; updated Pro Toolkit counts to ~810+/21+ toolkits; added v1.1.25–v1.1.35 releases; extended release calendar; refreshed community priorities; updated v2.0.0 timeline; restructured for clarity |
| 2026-05-21 | 1.1.21 | Added v1.1.18–v1.1.21 releases; updated release calendar |
| 2025-12-24 | 1.0 | Initial roadmap published |

---

## See Also

- [CHANGELOG.md](../CHANGELOG.md) — Complete release-by-release change log
- [DOCUMENTATION_INDEX.md](DOCUMENTATION_INDEX.md) — Full documentation map
- [project/proposals/](project/proposals/) — Feature proposals and status tracking
- [project/proposals/cross-platform-extraction-gap-analysis.md](project/proposals/cross-platform-extraction-gap-analysis.md) — OOS extraction status
- [CONTRIBUTING.md](../CONTRIBUTING.md) — How to contribute
