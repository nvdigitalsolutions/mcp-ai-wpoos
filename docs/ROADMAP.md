# NV oOS Roadmap

**Last Updated:** June 12, 2026  
**Version:** 1.1.29

---

## Vision

**NV oOS** aims to be the leading open-source AI orchestration platform for WordPress, enabling small to medium-sized businesses to modernize their websites with enterprise-grade AI capabilities without expensive custom development or middleware.

### Core Principles

1. **Security First** - Active monitoring and prevention of nefarious usage
2. **WordPress Native** - Leverages WordPress core capabilities, no reinventing wheels
3. **Open Standards** - MCP protocol compliance, extensible architecture
4. **Community Driven** - Open source with transparent development
5. **Production Ready** - Enterprise reliability with community accessibility

---

## Released: v1.1.24 — May 2026 ✅

**Release Date:** May 27–28, 2026

### What was delivered in v1.1.24

- ✅ **Bug-Fix & Stabilisation Sweep.** Paper Store Pro interface load order fix (deferred to `wp_mcp_ai_bootstrapped`). Chat SPA duplicate-message and SSE protocol-mismatch fixes. Markdown rendering enabled in Chat SPA responses via `marked`.
- ✅ **Skill Manager Canonical Return Envelope — Unix Theory P0/P1.** Skill manager fixed to return canonical envelope (`WP_Error` on failure) instead of legacy `array('success' => false, ...)`. Skills sync endpoint added. YAML frontmatter parsing hardened against colons.
- ✅ **Assistant Tool Presets — 24 Missing Tools Added.** Tool coverage gap filled in assistant creation presets. Out-of-date tests fixed.
- ✅ **CVE Patches.** `tmp` >=0.2.6, `symfony/cache` ^6.4.40. Composer vendor state committed. All release ZIPs rebuilt.
- ✅ **Paper Store Admin CRUD.** Full CRUD admin UI under Assistants menu, matching Skills admin convention.
- ✅ **CLI Coverage Enhancements.** Comprehensive WP-CLI command coverage improvements.
- ✅ **Folder README Convention — Unix Theory P7 Complete.** Every `includes/` and `addons/pro/includes/` PHP subdirectory now ships a `README.md`. CI enforcement via `composer run docs:check-folder-readmes`.
- ✅ **Agent Context Docs Synced.** `CLAUDE.md`, `AGENTS.md`, and `.github/agents/` synced with recent features (v2.4/v1.4).
- ✅ **Build & CI.** `build-spa-addons` workflow added. Missing SPA addon ZIPs restored. All SPA bundles rebuilt.

See [CHANGELOG.md](../CHANGELOG.md) for complete details.

---

## Released: v1.1.23 — May 2026 ✅

**Release Date:** May 25–26, 2026

### What was delivered in v1.1.23

- ✅ **Zed-Inspired SPA Architecture (Pro + Base).** 9-phase React SPA admin interface: Threads Sidebar, Agent Panel, Command Palette (Cmd+K), Agent Profiles (Write/Ask/Minimal/Custom), @-mention Context (8 entity types), Checkpoints & Diff Review, Inline Assistant (Gutenberg), Multi-Model Comparison, Collaborative Presence (Heartbeat API). ~75 files, ~10,800 lines. Base: 6 PHP manager classes + 5 REST controllers + 4 DB tables (PHP 7.4).
- ✅ **Antigravity Interactions API Rewrite.** Gemini Managed Agent service rewritten for real Antigravity API (`POST /v1beta/interactions`) with code execution, Google Search grounding, and sandbox environments. `enable_managed_agents` toggle in Orchestration settings.
- ✅ **TypeScript Upgrade + Orchestration Toggles.** Shared TS types, services, admin screens, chat drawer, React SPA builds. "Use TypeScript-Compiled Assets" checkbox in Settings → Orchestration.
- ✅ **Comic Reader Addon (`addons/comic-reader/`).** React-based CBR/CBZ/CB7/CBT reader with dual-page modes, zoom, keyboard nav, touch, fullscreen, shortcode + Gutenberg block.
- ✅ **Media Studio v0.3.0.** Zoom/pan, drawing tools (Konva canvas), save-to-WP-Media-Library. Image editor now feature-complete.
- ✅ **SPA Blueprint v3.0.** External React template ingestion & gap analysis pipeline. Adapter automation (API, i18n, CSS scope, bundle optimizer).
- ✅ **LLM Prompt Cache Optimization v1.5.1.**
- ✅ **30+ Reliability Fixes.** Cron status diagnostics (HTTP status + body), PHP 8.2+ compatibility ($namespace/$rest_base), PHPUnit 11 (6 batches across base+pro+addons), WP.org compliance re-audit & findings (section 14), May 2026 audit findings (F-AUTHZ-05/06, F-AGENT-01), PHPCS 353→0 errors across 18 files, Docs Hub fixes (browse-repo, folder tree picker, DNS, autocomplete), qs CVE-2026-8723, DeepSeek fallback, test suite stability (wp_die handler, AJAX fixes), net worth calculator parse error.
- ✅ **Dev Dependencies.** `wp-phpunit/wp-phpunit` 6.9.4→7.0.0, `php-stubs/wordpress-stubs` 6.9.1→7.0.0.

See [CHANGELOG.md](../CHANGELOG.md) for complete details.

---

## Released: v1.1.22 / v1.1.21 / v1.1.20 / v1.1.19 / v1.1.18 — May 2026 ✅

**Release Dates:** May 14–23, 2026

### What was delivered across these five releases

- ✅ **Baseten AI — 11th first-class provider.** OpenAI-compatible chat/tools/streaming/reasoning at `api.baseten.co/v1`. Settings → Providers → Baseten subtab. Service doc in `docs/EXTERNAL_SERVICES.md`.
- ✅ **CoSAI Secure-by-Design Agentic System.** Four new `includes/agents/` classes: capability boundary + per-session tool allow-lists, cryptographic audit trail (CPT + options, immutable events), risk-tiered approval gate (low/medium/high/critical), isolated code sandbox (Python/Node.js/Bash/PHP). All provider-agnostic.
- ✅ **Gemini I/O 2026 Model Refresh.** Gemini 3.5 Flash as recommended model (4x faster, dynamic thinking, $1.50/M input). Gemini Omni Flash as video default (10s, native audio, AI avatars). 3.1 Flash deprecated.
- ✅ **Continual Harness P5.** Self-improving agent system with execution history learning and tool selection feedback loops.
- ✅ **SaaS Controller Phase 2 & 4.** Stripe + OpenRouter deployment editors from WP-Admin.
- ✅ **npm packages — nvoos-vad, nvoos-chat-bubble, nvoos-chat-memory-ui.** VAD, chat bubble widget, memory drawer component with TypeScript declarations.
- ✅ **WordPress Studio Test Environment.** Auto-detect Studio DB/ABSPATH/site URL in test bootstrap.
- ✅ **Security fixes.** UUID buffer bounds (saas-controller). `map_meta_cap=false` for audit trail CPT (WP 6.1+). AV false positives in test suite.
- ✅ **Allowed providers expanded.** DeepSeek, OpenRouter, DigitalOcean, Kimi, Baseten added to validation gate.
- ✅ **LM Studio URLs fixed.** All `lmstudio.ai` → GitHub org after upstream 500 errors.
- ✅ **Addons PHPCS — 93% reduction (1,143 → 82).** Two-batch cleanup with 12 new `bin/` helper scripts.
- ✅ **v1.1.21/v1.1.20/v1.1.19/v1.1.18 — WordPress.org Compliance Complete (F1–F10).** All inline JS/CSS removed from 53 files and converted to WP enqueue APIs. 11 PHP parse errors fixed. All 10 reviewer findings resolved + re-audit verified. Build pipeline hardened. Zero bare `phpcs:ignore` comments. ⭐ **READY FOR RE-SUBMISSION**
- ✅ **Canonical Return Envelope — Unix Theory P0/P1 Complete.** 191 non-canonical `array('success' => false, ...)` returns converted to `new WP_Error()` across 105 files. `WPMCPAI.Tools.CanonicalReturnEnvelope` + `SanitizeAtEntry` PHPCS sniffs clean. Caller sites hardened.
- ✅ **Capability Fence P2b — Full Rollout.** `get_required_capability()` deployed to all ~830 tool classes (base + Pro + all addons) via `WP_MCP_AI_Tool_Interface`. Central `WP_MCP_AI_Tool_Capability_Map`. New `WPMCPAI.Tools.RequiredCapabilityDeclared` sniff.
- ✅ **Security Center.** 5-tab admin page: Posture (live security scoring), Compliance Report, OTel Telemetry, Deprecated-Alias Tracking, MCP Token Inventory.
- ✅ **Semantic Caveman Compression.** New `WP_MCP_AI_Semantic_Compressor` service (1,988 lines + 1,156 test lines). Opt-in prompt token optimization preserving facts/numbers/terms.
- ✅ **AI Prompt Caching — All Providers.** `WP_MCP_AI_Chat_Response_Cache` + `WP_MCP_AI_Prompt_Optimizer` across all 5 AI providers. Cache Performance dashboard.
- ✅ **Memory Layer 2026 — Phases 3–8 Merged.** Auto-capture with SHA-256 dedup (P3), RRF fusion retrieval — BM25 + vector + graph (P4), confidence decay + contradiction detection (P5), provenance tracer tool (P6), Memory Health + Retrieval Waterfall + Session Replay (P7), documentation + v1.1.20 bump (P8). CCT migrator disabled by default.
- ✅ **Kimi (Moonshot AI) provider — 10th first-class provider.** `WP_MCP_AI_Kimi_Client` at `api.moonshot.cn/v1` with kimi-k2.6 (256K, default), kimi-k2-thinking (CoT), moonshot-v1-*.
- ✅ **ACP Server** — Full Agent Client Protocol implementation (JSON-RPC 2.0 over HTTP/SSE). `/.well-known/ai-peer` extended.
- ✅ **MCP Bridge** — `bin/mcp-bridge.js` stdio-to-HTTP relay for Claude Desktop, Cursor, Zed.
- ✅ **Unix Theory P0–P6** — canonical return envelope (P0/P1), capability-fence audit (P2), data-contract metadata (P3), tool-lifecycle descriptor (P4), back-compat alias infrastructure (P5), sanitize-at-entry sniff (P6).
- ✅ **DigitalOcean Serverless Inference provider** (9th provider) — OpenAI-compatible chat/tools/streaming/embeddings.
- ✅ **Async Chat Continuation** (slices 1–6) — durable store, dispatcher, LLM re-entry, SSE frame buffer.
- ✅ **Jobs/Tasks Drawer + Cron-Status** — inline job-progress cards, cancel/retry routes, Tasks Drawer + toasts.
- ✅ **Model Catalog May 2026 Refresh.** DeepSeek V4 model family added. Gemini entries consolidated. All pricing refreshed.
- ✅ **GDPR — JetEngine Privacy Exporters.** CCT data exporters for transcripts, memory, and approvals.
- ✅ **Security Hardening (5 patches).** Settings-key encryption, webhook secret enforcement, SSRF via `wp_safe_remote_get`, attachment URL allowlist, client-log debug-gate.
- ✅ **Folder README Convention Phase P7.** Every `includes/` and `addons/pro/includes/` PHP subdirectory ships a `README.md` — completing Unix Theory P0–P7.
- ✅ **@wordpress/env Dev Dependency.** Zero-config local WP environments via `wp-env`.
- ✅ **Domain Migration.** All `nvoos.com` references → `nvoos.pro` / `nvoos.cloud`.
- ✅ **Addons/pro Security Scan Fixes.** Remote-sites pagination, uninstall.php, stale tag removal.

See [CHANGELOG.md](../CHANGELOG.md) for complete details.

---

## Released: v1.1.17 / v1.1.16 / v1.1.15 / v1.1.14 — May 2026 ✅

**Release Dates:** May 1–10, 2026

### What was delivered across these four releases

- ✅ **Orchestration Phases 1–7** — HITL approval queue, prompt-injection detector, structured output + OTel span exporter, DAG builder, durable runs, triggers/webhooks, sub-agents, Pro vector-store adapter, Pro team budget manager
- ✅ **LLM Harnessing Subsystem (Layers A–H)** — seven opt-in per-assistant epistemic layers (prompt cues, reasoning/self-consistency, tool router, retrieval+citation, self-refine, memory scoping+PII, eval scheduler) + Pro Layer H fine-tune curriculum export
- ✅ **Toolkit MCP Servers — all 7 phases complete** — 26 toolkits (19 Tier-1 + 7 Tier-2) exposed as per-toolkit MCP servers; `/.well-known/mcp` discovery endpoint; toolkit-scoped credentials; cross-mount audit trail; `/mcp-server` slash command; WP-CLI token management
- ✅ **New AI providers** — OpenRouter (unified gateway for 100+ models), DeepSeek (reasoning_content passthrough), LM Studio native cURL SSE streaming + full May 2026 parity, Kimi K2.6 + Qwen 3.6 in catalog
- ✅ **Chat SPA addon (v0.6.0, Phases 1–7)** — React replacement for legacy chat shortcode (Vercel AI SDK UI, SSE adapter, tool-call cards, memory drawer, HITL bar, attachments, branching, legacy opt-out constant)
- ✅ **SaaS Controller addon (v0.1.0, 11 phases)** + **Cloud Worker (Phase 3)** — full NV oOS Cloud control plane management from WP-Admin
- ✅ **Docs Hub addon (v0.1.0 → v0.3.8)** — remote-first React SPA documentation portal
- ✅ **Toolkit SPA Blueprint Phases 5–12** — a11y, i18n, PHPUnit tests, bundle-size CI guardrail, scaffolder auto-patch; 10 Tier-A manifests; canvas-toolkit v0.2.0; document-editor v0.2.0; media-studio Phase 4
- ✅ **Inline-async-tick pattern** — 8 Tier-1 consumers (transcript mining, async tool executor, SaaS Apply, Crawl4AI, Docs Hub rebuild, Graphify reindex, Harness eval, Gemini Veo polling)
- ✅ **WordPress.org Compliance Hardening (B1–B13)** — all reviewer findings resolved; build-pipeline split (--wp-org flag)
- ✅ **PHPUnit + Vitest coverage campaign** (PRs #1–#11) — 271 AJAX handlers covered; non-regression CI gate; Vitest for all 6 SPA addons
- ✅ **Dependabot security sweep** — 33 alerts resolved across all manifests
- ✅ **Agent Skills Phases 1–4** — 28+ bundled Pro WP-developer skills, remote catalogues, progressive disclosure, skill packs
- ✅ **Markup Subsystem (Base)** — in-loop Konva canvas widget for image annotation
- ✅ **MemPalace Capture Framework Phases A + B1** — five capture tools onto the durable memory bridge

See [CHANGELOG.md](../CHANGELOG.md) for complete details.

---

---

## Next Minor (v1.3.0) - Q2 2026 ⚡

**Target:** June 2026  
**Focus:** GPT-Realtime-2 voice models upgrade, WebRTC transport, translation & transcription

### Major Features

#### 1. OpenAI Realtime API GA Migration ✅
- Migrated from deprecated beta endpoints to GA Realtime API
- Nested session format: `session.type`, `audio.input/output` structure
- Removed deprecated `OpenAI-Beta` header; added `OpenAI-Safety-Identifier`
- Updated default model: `gpt-realtime` → `gpt-realtime-2`
- Updated voice list: added `cedar`, removed deprecated `fable`/`nova`/`onyx`

#### 2. WebRTC Transport ✅
- New `chat-webrtc-service.js` — browser WebRTC peer connection manager
- Ephemeral token flow: `POST /v1/realtime/client_secrets` → browser RTCPeerConnection
- Unified interface flow: SDP relay via `POST /v1/realtime/calls`
- New REST endpoints: `POST /mcp-ai/v1/realtime/token`, `POST /mcp-ai/v1/realtime/session`
- WebSocket fallback preserved in updated `chat-voice-realtime-service.js`

#### 3. GPT-Realtime-2 Reasoning ✅
- Configurable reasoning effort: `minimal` / `low` / `medium` / `high` / `xhigh`
- 12-section structured prompt template (role, tone, reasoning, preambles, tools, etc.)
- Per-section filter hooks for prompt customization
- Parallel tool calling enabled by default

#### 4. New Models ✅
- **GPT-Realtime-Translate**: 70+ input → 13 output languages; dedicated translation endpoint
- **GPT-Realtime-Whisper**: Streaming speech-to-text with configurable latency
- Translation + transcription JS services with WebRTC transport
- Provider registration with voice controller

#### 5. Voice Tooling ✅
- `wait_for_user` no-op tool for silence/background noise handling
- PTT (Push-to-Talk) mode support for WebRTC connections
- Commentary phase display (preambles / tool progress) in chat UI
- WebRTC-specific state classes and CSS

---

## Next Minor (v1.2.0) - Q2 2026 ⚡

---

## Released: v1.1.3 - March 2026 ✅

**Release Date:** March 3, 2026  
**Focus:** WordPress.org compliance final audit, Telegram Mini App media tab, Office 365 & iCloud Drive, Gemini Corpus RAG, Tavily web search

### What Was Delivered in v1.1.3

**Core Features:**
- ✅ 165 built-in tools (base version) - up from 127 in v1.1.0
- ✅ 354 Pro tools (full version) = 519 total tools - up from 197 in v1.1.0
- ✅ WordPress.org compliance 100% complete (output escaping, ABSPATH guards, menu positions)
- ✅ **Gemini Corpus Native RAG** – Semantic Retrieval API integration for grounded Gemini responses
- ✅ **Web Search: Tavily provider** – AI-first search with geo/freshness parameters and snippet grounding
- ✅ **Office 365 & iCloud Drive** – 8 new Chat Channels Toolkit tools (Outlook, OneDrive, iCloud Drive)
- ✅ **Telegram Mini App Media Badges** – File-type extension badges with WCAG-compliant styling
- ✅ **Chat Channels Toolkit** – Now 47 tools across 11 platforms (was 39 at v1.1.1 launch)

**See** [CHANGELOG.md](../CHANGELOG.md) for the complete v1.1.3 change log.

---

## Released: v1.1.2 - February 2026 ✅

**Release Date:** February 16, 2026  
**Focus:** WordPress.org compliance, Pro integration settings architecture

### What Was Delivered

- ✅ **Hardcoded Menu Position Fix** – Removed hardcoded positions from 5 CPT/menu registrations
- ✅ **Pro Settings Architecture** – Moved Mailjet, Google Analytics, Yahoo Fantasy, ESPN Fantasy settings to pro addon; base plugin now only contains settings for base tools
- ✅ **JetEngine CPT/Taxonomy AI Integration** – AI assistant metaboxes and Research & Add pages for all JetEngine CPTs (Feb 12)
- ✅ **Package Pre-Bundling** – pdf-lib, pdfkit, docx, exceljs, qrcode, cheerio pre-bundled in vendor; no `npm install` required in production
- ✅ **TMA CMS Overhaul** – Telegram Mini App transformed into a full WordPress CMS interface (CPTs, tools, media)
- ✅ **Discord/Telegram Reactions** – `add_discord_message_reaction`, `add_telegram_message_reaction`, `get_discord_voice_channel_members`
- ✅ **WhatsApp & Messenger Fixes** – Group routing, auto-reply error #133010, webhook processing, Messenger test connection
- ✅ **Google Chat Fixes** – HTTP 404 fix, OAuth UX, thread routing, OAuth welcome message
- ✅ **OAuth Improvements** – Google OAuth approval prompt, Yahoo redirect URL, Mailjet credential handling
- ✅ **Pro Workflow Builder Stability** – React asset loading, double instantiation, initialization timing, empty page display

---

## Released: v1.1.1 - February 2026 ✅

**Release Date:** February 6, 2026  
**Focus:** Security fixes, Chat Channels Toolkit, Slash Commands, WebChat Rooms

### What Was Delivered

**Security (all critical/high resolved):**
- ✅ **SSRF Protection** – Webhook registration blocks private IPs and internal networks
- ✅ **CSRF Protection** – Cron job deletion secured with nonce after AJAX refresh
- ✅ **XSS Prevention** – Error messages double-escaped (input + output) for defense in depth
- ✅ **Authorization System** – Comprehensive multi-entity job access control

**New Features:**
- ✅ **Chat Channels Toolkit** – 21 tools across 6 platforms (Telegram, WhatsApp, Slack, Discord, Teams, Messenger) + Unified Hub broadcast
- ✅ **Slash Commands System** – Phase 1 (8 core commands) + Phase 2 (21 Pro toolkit commands); 7 automated workflow templates; REST API + WP-CLI integration
- ✅ **WebChat Rooms** – Real-time collaborative rooms (`mcp_ai_webchat` CPT), JetEngine CCT message persistence, WebRTC support
- ✅ **E-commerce Toolkit** – Now enabled by default for new installations

---

## Released: v1.1.0 - January 2026 ✅

**Release Date:** January 28, 2026  
**Focus:** Multi-agent orchestration, social media analytics expansion, stability improvements

### What's Included

**Core Features:**
- ✅ 127 built-in tools (base version) - up from 95
- ✅ 70 Pro tools (full version) = 197 total tools - up from 133
- ✅ OpenAI GPT integration (GPT-4, GPT-4 Turbo, GPT-4o, GPT-4o-mini, GPT-Image-1.5)
- ✅ Google Gemini integration (Gemini 1.5 Pro/Flash, Gemini 2.0 Flash, Imagen 3)
- ✅ Ollama integration (local AI models)
- ✅ MCP protocol implementation (2024-11-05 spec)
- ✅ Server-Sent Events (SSE) streaming
- ✅ Assistant management (CPT + optional CCT)
- ✅ REST API with authentication (Bearer tokens, Auth0)
- ✅ Project management system (Pro - Projects, Tasks, Events, Calendar)
- ✅ Comprehensive documentation (659 files, 100% feature coverage)

**NEW in v1.1.0 (January 2026):**
- ✅ **DeepSeek V4 Multi-Agent Orchestration** - Comprehensive multi-agent coordination framework
  - 4 specialized agent roles (Planner, Executor, Critic, Specialist)
  - Agent Team Orchestrator with 5 aggregation strategies
  - 3 new MCP-compliant agent coordination tools
  - 200+ profession orchestration with intelligent role assignment
  - Multi-agent workflow templates (research, content, e-commerce, development)
- ✅ **Social Media Analytics Toolkit Expansion** - 4 new analytics tools (15 → 19 tools total)
  - Cross-platform analytics dashboard
  - Hashtag performance tracking
  - Competitor analysis
  - Influencer identification
- ✅ **Pro Toolkit Memory-Based Tracking** - Replaced hard 5-toolkit limit with transparent memory usage display
- ✅ **Cloudflare Workers AI provider work** - historical roadmap item; current catalog-backed docs list active Cloudflare LLM rows rather than fabricated image-model prices
- ✅ **7 Critical Bug Fixes** - Settings persistence, API keys, transcripts, OAuth, model dropdowns
- ✅ **Compliance posture documentation** - historical framework work; current public docs avoid unbacked percentage claims and point operators to posture references

**AI Integrations:**
- ✅ OpenAI Batch API (50% cost savings)
- ✅ OpenAI Moderation API (content safety)
- ✅ Gemini Geospatial API (location queries)
- ✅ Hugging Face integration (multiple models)
- ✅ Cloudflare Workers AI (image generation)
- ✅ Crawl4AI integration (web scraping)

**Third-Party Integrations (Optional):**
- ✅ JetEngine (CCT storage, 5 additional tools)
- ✅ WooCommerce (e-commerce tools)
- ✅ Elementor (widgets and integrations)
- ✅ Rank Math (SEO analysis)
- ✅ WPCode (code snippet management)

**Pro Toolkits (13 Active - ALL IMPLEMENTED):**
- ✅ E-commerce (20 tools)
- ✅ Social Media (19 tools)
- ✅ Analytics (12 tools)
- ✅ Multilingual (10 tools)
- ✅ Video Production (12 tools)
- ✅ Financial Planner (24 tools)
- ✅ Document Generation (3 tools)
- ✅ Calendar Booking (15 tools) ⭐ Previously listed as "planned"
- ✅ DJ Management (18 tools) ⭐ Previously listed as "planned"
- ✅ Image Production (15 tools) ⭐ Previously listed as "planned"
- ✅ AI Tool Builder (10 tools) ⭐ Previously listed as "planned"
- ✅ Architectural Design (16 tools)
- ✅ CRM (1 tool)
- **Total: 175 Pro toolkit tools**

**Quality & Testing:**
- ✅ 500+ test files
- ✅ ~70% code coverage
- ✅ CI/CD pipeline (PHPUnit, PHPCS, ESLint, CodeQL)
- ✅ Security score: 100/100
- ✅ Code quality: 98/100

---

## Next Minor (v1.2.0) - Q2 2026 ⚡

**Release Date:** May 31, 2026  
**Focus:** Enhanced project management, security enhancements, testing, and GitHub infrastructure
**Note:** Originally planned as v1.1.0 for February 2026, moved to accommodate v1.1.0 early delivery with multi-agent features

### Major Features

#### 1. Security Enhancements (NEW)

**Federation Directory Rate Limiting:**
- [ ] Add per-IP rate limiting to `/ai-dir/v1/peers` endpoint (60 req/min)
- [ ] Add per-IP rate limiting to `/ai-dir/v1/peers/{id}` endpoint
- [ ] Add per-IP rate limiting to `/ai-dir/v1/search` endpoint
- [ ] Use WordPress transients for rate limit counters
- [ ] Return HTTP 429 with human-readable message when limit exceeded

**Estimated Effort:** 4-6 hours  
**Priority:** High  
**Rationale:** Public peer discovery endpoints currently have no rate limiting, allowing unlimited enumeration attacks

**SSE Rate Limiting:**
- [ ] Implement per-user connection limits (3-5 concurrent)
- [ ] Add global SSE connection limit (50-100 total)
- [ ] Use WordPress transients for connection tracking
- [ ] Return HTTP 429 when limits exceeded
- [ ] Admin override for manage_options capability

**Estimated Effort:** 4-6 hours  
**Priority:** Medium  
**Rationale:** Prevents resource exhaustion from excessive SSE connections

**CORS Origin Allowlist:**
- [ ] Add settings page option for allowed origins
- [ ] Support wildcard subdomains (*.example.com)
- [ ] Default to current site URL if unconfigured
- [ ] Allow localhost in WP_DEBUG mode
- [ ] Graceful fallback for backwards compatibility

**Estimated Effort:** 4-6 hours  
**Priority:** Low  
**Rationale:** Enhanced security while maintaining authentication requirements

**Security Testing:**
- [ ] Automated SSRF testing
- [ ] Automated authorization testing
- [ ] Automated XSS testing
- [ ] Automated CSRF testing
- [ ] Integration with CI/CD pipeline

**Estimated Effort:** 6-8 hours  
**Priority:** Medium

#### 2. Task Dependencies and Subtasks
- [ ] Add parent-child task relationships
- [ ] Implement dependency tracking (blocks/blocked by)
- [ ] Add dependency validation (prevent circular)
- [ ] Create `get_task_dependencies` tool
- [ ] Update UI to show task hierarchy

**Estimated Effort:** 12-16 hours  
**Priority:** Medium

#### 2. Notification System
- [ ] Email notifications for task assignments
- [ ] Event reminders (1 day, 1 hour before)
- [ ] Deadline approaching warnings
- [ ] Project status change notifications
- [ ] User notification preferences
- [ ] WordPress cron integration
- [ ] Create `get_notifications` tool

**Estimated Effort:** 20-24 hours  
**Priority:** High

#### 3. Enhanced PM Testing
- [ ] Create task tool tests
- [ ] Create event tool tests
- [ ] Create calendar view tests
- [ ] Integration test suite
- [ ] Tool execution tests
- [ ] Performance tests (large datasets)

**Estimated Effort:** 8-12 hours  
**Priority:** High

#### 4. GitHub Project Management
- [ ] GitHub Projects (v2) configuration
- [ ] Project automation workflows
- [ ] Label strategy implementation (50 labels)
- [ ] Milestone strategy documentation
- [ ] Stale issue automation
- [ ] Auto-label workflows
- [ ] PR review assignment

**Estimated Effort:** 10-12 hours  
**Priority:** High

#### 5. REST API Documentation
- [ ] PM tools REST API documentation
- [ ] Code examples (cURL, JavaScript)
- [ ] Postman/Insomnia collection
- [ ] Integration guide

**Estimated Effort:** 4-6 hours  
**Priority:** Medium

### Additional Improvements

**Documentation:**
- [ ] PM best practices guide
- [ ] Troubleshooting guide for PM features
- [ ] Workflow examples
- [ ] Team collaboration patterns

**AI Provider Enhancements:**
- [ ] Anthropic Claude integration (Claude 3 Haiku/Sonnet, Claude 4 Sonnet — community demand confirmed)
- [ ] Gemini context caching (68% cost savings)
- [ ] Gemini thinking mode support
- [ ] OpenAI batch embeddings
- [x] Transformer-inspired attention routing — semantic tool selection via QKV embedding similarity, 5-head multi-head scoring, pre-computed tool embedding KV cache, sliding-window conversation compression, Reciprocal Rank Fusion (RRF) into harness Layer C (delivered June 2026, PR #5290)

**Developer Experience:**
- [ ] Improved error messages
- [ ] Better debugging tools
- [ ] Enhanced logging options

**Timeline:**
- Feature freeze: May 15, 2026
- Beta: May 22, 2026
- Release: May 31, 2026
- Testing period: 2 weeks

**Success Metrics:**
- 80%+ PM test coverage
- GitHub Projects active with 20+ issues
- 50+ labels in use
- REST API documentation complete
- Zero critical bugs

---

## Pro Toolkits Roadmap 🎨

### Active Toolkits (17) ✅ **ALL IMPLEMENTED**

**Core Business Toolkits (7):**
1. ✅ E-commerce (20 tools) - Phase 1 Complete
2. ✅ Social Media (19 tools) - Phase 1 & 2 Complete (expanded January 2026 with 4 analytics tools)
3. ✅ Analytics (12 tools) - Phase 1 Complete
4. ✅ Multilingual (10 tools) - Phase 1 Complete
5. ✅ Video Production (12 tools) - Phase 1 Complete
6. ✅ Financial Planner (24 tools) - Phase 1 Complete
7. ✅ Document Generation (3 tools) - Phase 1 Complete (PDF, Word, Excel)

**Advanced Professional Toolkits (6):**
8. ✅ **Calendar Booking** (15 tools) - Phase 2.6 Complete ⭐
   - Appointment management, availability scheduling, calendar sync (Google, Outlook)
   - Previously listed as "planned", actually fully implemented
9. ✅ **DJ Management** (18 tools) - Phase 2.7 Complete ⭐
   - Music library, playlist generation, event booking, equipment tracking
   - Previously listed as "planned", actually fully implemented
10. ✅ **Image Production** (15 tools) - Phase 2.8 Complete ⭐
    - AI image generation, editing, optimization, batch processing
    - Previously listed as "planned", actually fully implemented
11. ✅ **AI Tool Builder** (10 tools) - Phase 2.9 Complete ⭐
    - Meta-toolkit for creating custom tools, testing, documentation
    - Previously listed as "planned", actually fully implemented
12. ✅ **Architectural Design** (16 tools) - Phase 2.5 Complete
    - Floor plans, 3D models, construction drawings, building code compliance
13. ✅ **CRM** (1 tool) - Phase 2.4 Complete
    - Contact management integration

**Added Since v1.1.0 (4 new toolkits):**
14. ✅ **Chat Channels** (47 tools, 11 platforms) - Added February 2026 ⭐
    - Telegram, WhatsApp, Slack, Discord, Microsoft Teams, Facebook Messenger, Google Chat, Instagram, YouTube, Office 365 / OneDrive, iCloud Drive
    - WebChat Rooms with JetEngine CCT persistence
15. ✅ **Regulatory Registration** (59 tools) - Added early 2026 ⭐
    - Multi-country product registration management, MOHAP & NMRA sync, compliance certificates, dossier generation, workflow automation
16. ✅ **Site Creator** (27 tools) - Added early 2026 ⭐
    - Landing pages, hero/CTA/testimonial/footer sections, blog layouts, theme scaffolding, template kit export
17. ✅ **Vector Storage** - Added early 2026
    - Prepare files for OpenAI vector stores; supports file_search RAG workflows

### Total Tool Count by Category
- **Core Business:** 100 tools (7 toolkits)
- **Advanced Professional:** 75 tools (6 toolkits)
- **New (v1.1.1+):** ~134 tools (Chat Channels 47 + Regulatory 59 + Site Creator 27 + Vector Storage 1)
- **Grand Total:** ~354 Pro toolkit tools across 17+ specialized domains (519 total including 165 base tools)

### Planned Toolkits (0) ⏳
**Status:** All previously planned toolkits have been implemented! 

The 4 toolkits that were marked as "planned" in earlier documentation (Calendar Booking, DJ Management, Image Production, AI Tool Builder) are **actually fully implemented** and have been for some time. Documentation has been updated to reflect reality.

### Future Expansion Opportunities 💡
Potential future toolkits based on community demand:
- Healthcare Management (appointment scheduling, patient records, HIPAA compliance)
- Legal Practice Management (case tracking, document automation, billing)
- Education Management (course creation, student tracking, gradebook)
- Real Estate (property listings, showings, client management)
- Hospitality & Events (venue management, catering, RSVPs)

*These are conceptual only - no active development planned.*

---

## Next Major (v2.0.0) - Q4 2026 🚀

**Release Date:** December 31, 2026  
**Focus:** Enterprise features, advanced workflows, team collaboration
**Note:** Multi-agent workflows delivered early in v1.1.0 (January 2026)

### Major Features

#### 1. Cross-Platform Extraction (🟡 In Progress)
- [x] Domain contracts (9 interfaces) — `lib/core/src/Domain/Contract/`
- [x] Domain entities (10 value objects) — `lib/core/src/Domain/Entity/`
- [x] Domain errors (5 typed exceptions) — `lib/core/src/Domain/Error/`
- [x] Domain events (8 events) — `lib/core/src/Domain/Event/`
- [x] Application services (ChatOrchestrator, ProviderRouter, ToolRegistry, SkillRegistry)
- [x] 12 provider clients (OpenAI, Gemini, Anthropic, DeepSeek, OpenRouter, Kimi, Ollama, LM Studio, DigitalOcean, Nvidia Nim, Cloudflare, HuggingFace)
- [x] SSE streaming handler + cost calculator
- [x] 8 WordPress adapter implementations
- [x] Feature flag activation (`?engine=oos`)
- [x] 43 migrated tools (Tier 1 + select Tier 2)
- [ ] ~152 remaining tool migrations
- [ ] Test suite for extracted packages
- [ ] Laravel adapter
- [ ] Craft CMS adapter
- [ ] Monorepo CI/CD + Packagist publishing

See [`docs/project/proposals/cross-platform-extraction-gap-analysis.md`](project/proposals/cross-platform-extraction-gap-analysis.md) for detailed status.

#### 2. Advanced Workflow Automation
- [ ] Custom workflow builder UI
- [ ] Trigger-action automation system
- [ ] Conditional logic for workflows
- [ ] Integration with WordPress hooks
- [ ] Workflow templates library
- [ ] Visual workflow editor

**Estimated Effort:** 60-80 hours  
**Value:** High - Enables no-code automation

#### 2. Team Collaboration Features
- [ ] Team workspaces
- [ ] Shared assistants and tools
- [ ] Team chat and comments
- [ ] @mentions and notifications
- [ ] Activity feeds
- [ ] Team analytics dashboard

**Estimated Effort:** 40-50 hours  
**Value:** High - Enables team adoption

#### 3. Custom Role-Based Permissions
- [ ] Granular capability system
- [ ] Custom roles (beyond WordPress defaults)
- [ ] Per-assistant permissions
- [ ] Per-tool permissions
- [ ] Permission inheritance
- [ ] Audit logs for permission changes

**Estimated Effort:** 30-40 hours  
**Value:** High - Enterprise security

#### 4. Time Tracking and Reporting
- [ ] Log time on tasks
- [ ] Estimated vs actual hours
- [ ] Time reports by project/user
- [ ] Billable hours tracking
- [ ] Time analytics dashboard
- [ ] Export to CSV/PDF

**Estimated Effort:** 16-20 hours  
**Value:** Medium - Professional PM feature

#### 5. Gantt Chart Visualization
- [ ] Gantt chart UI (D3.js or library)
- [ ] Timeline view for projects
- [ ] Critical path highlighting
- [ ] Dependency visualization
- [ ] Drag-and-drop rescheduling
- [ ] Export to image/PDF

**Estimated Effort:** 30-40 hours  
**Value:** Medium - Visual project planning

#### 6. Advanced Analytics Dashboard
- [ ] AI usage analytics
- [ ] Cost tracking and forecasting
- [ ] Performance metrics
- [ ] Custom report builder
- [ ] Data export options
- [ ] Scheduled reports (email)

**Estimated Effort:** 40-50 hours  
**Value:** High - Data-driven decisions

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
- **Impact:** Custom code reading settings directly

#### REST API v2
- **Current:** `/wp-json/mcp-ai/v1/`
- **New:** `/wp-json/mcp-ai/v2/`
- **Changes:** Improved error handling, pagination
- **Migration:** v1 deprecated but supported for 6 months

### Additional Features

**AI Provider Enhancements:**
- [ ] Claude 3 Opus integration
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
- [ ] Interactive API playground
- [ ] SDK for third-party integrations
- [ ] Webhook system

**Timeline:**
- Alpha: July 2026
- Beta: August 2026
- RC: September 15, 2026
- Release: September 30, 2026
- Migration period: 6 months (v1 API support until March 2027)

**Success Metrics:**
- 10,000+ active installations
- 50+ custom integrations built
- 90%+ user satisfaction
- Zero critical security issues
- <1% support ticket rate

---

## Future Considerations (Post v2.0.0) 🔮

### Under Evaluation

These features are being considered but not yet scheduled:

#### Mobile App Integration
- Native iOS/Android apps
- Progressive Web App (PWA)
- Mobile-optimized chat UI
- Push notifications

**Rationale:** Depends on community demand and resource availability

#### Third-Party PM Tool Integrations
- Jira integration
- Asana integration
- Trello integration
- Linear integration
- Sync tasks bidirectionally

**Rationale:** Niche need, complex implementation, maintenance burden

#### Advanced AI Features
- ✅ Multi-agent workflows - **DELIVERED in v1.1.0 (January 2026)** 🎉
- [ ] AI-powered project insights
- [ ] Predictive analytics
- [ ] Automated task assignment
- [ ] Smart scheduling

**Rationale:** Multi-agent workflows delivered 11 months ahead of schedule. Remaining features require additional AI research and may be computationally expensive.

#### Multi-Language Support Expansion
- RTL language support
- Professional translations (10+ languages)
- Locale-specific features
- Regional AI provider support

**Rationale:** Community-driven, depends on international adoption

#### Visual Builder
- Drag-and-drop assistant builder
- No-code tool creation
- Visual workflow designer
- Template marketplace

**Rationale:** Significant UI work, competes with core value prop

#### Enterprise Features
- SAML/SSO authentication
- Advanced audit logging
- Compliance certifications
- SLA monitoring
- White-label options

**Rationale:** Enterprise segment, may require licensing changes

---

## Community Priorities

### Top Community Requests

Based on GitHub issues and community feedback (as of December 2025):

1. **Task Dependencies** - 15 votes → Planned for v1.2.0
2. **Notification System** - 12 votes → Planned for v1.2.0
3. **Time Tracking** - 10 votes → Planned for v2.0.0 ✅
4. **Gantt Charts** - 8 votes → Planned for v2.0.0 ✅
5. **Claude Integration** - 7 votes → Planned for v2.0.0 ✅
6. **Mobile App** - 6 votes → Under evaluation
7. **Jira Integration** - 5 votes → Under evaluation
8. **Multi-language UI** - 4 votes → Future consideration

**How to Vote:**
- Add 👍 reaction to feature request issues
- Comment with your use case
- Contribute to discussions

---

## Development Philosophy

### Release Principles

1. **Ship Early, Iterate Fast** - Release MVPs, gather feedback, improve
2. **Backward Compatibility** - Breaking changes only in majors, with migration tools
3. **Community First** - Prioritize community requests over internal ideas
4. **Quality Over Speed** - No releases until tests pass and docs are ready
5. **Transparent Progress** - Public roadmap, weekly updates, open development

### Feature Prioritization Framework

We evaluate features using these criteria:

| Criteria | Weight | Description |
|----------|--------|-------------|
| User Impact | 40% | How many users benefit? |
| Strategic Alignment | 25% | Fits vision and mission? |
| Implementation Effort | 20% | Time and complexity? |
| Community Demand | 10% | How many requests/votes? |
| Technical Debt | 5% | Does it reduce or increase debt? |

**Scoring:**
- 90-100: High priority, schedule ASAP
- 70-89: Medium priority, schedule in next minor/major
- 50-69: Low priority, add to backlog
- <50: Decline or defer to future

---

## How to Contribute to the Roadmap

### Suggest a Feature

1. Check if feature already exists in [Issues](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues)
2. If not, create a [Feature Request](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues/new?template=feature_request.md)
3. Provide detailed use case and requirements
4. Community votes with 👍 reactions

### Vote on Features

- Add 👍 to issues you want
- Comment with your specific use case
- Help refine requirements through discussion

### Implement a Feature

1. Comment on issue expressing interest
2. Wait for approval from maintainers
3. Follow [CONTRIBUTING.md](../CONTRIBUTING.md) guidelines
4. Submit PR with tests and documentation

---

## Release Calendar

### 2026 Release Schedule

| Version | Type | Release Date | Focus |
|---------|------|--------------|-------|
| v1.0.1 | Patch | Jan 15, 2026 | Stability |
| v1.1.0 | Minor | Jan 28, 2026 | Multi-Agent Orchestration |
| v1.1.1 | Patch | Feb 6, 2026 | Security + Chat Channels |
| v1.1.2 | Patch | Feb 16, 2026 | WP.org Compliance |
| v1.1.3 | Patch | Mar 3, 2026 | Gemini RAG + Media Tab |
| v1.1.4 | Patch | Mar 15, 2026 | Security Hardening |
| v1.1.14–v1.1.17 | Minor | May 1–10, 2026 | Providers + Orchestration + Coverage |
| v1.1.18–v1.1.21 | Minor | May 14–21, 2026 | WP.org Compliance Final + P0/P1 + Compression |
| v1.2.0 | Minor | Jun 2026 | AI Provider Enhancements |
| v1.3.0 | Minor | Aug 2026 | Developer Experience |
| v2.0.0 | Major | Q4 2026 | Enterprise Features |

**Note:** Dates are targets and may shift based on development progress and community feedback.

---

## FAQ

**Q: How do I request a feature?**  
A: Create a [Feature Request](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues/new?template=feature_request.md) issue.

**Q: Will my feature be included?**  
A: Features are evaluated using our prioritization framework. Community demand is a factor.

**Q: When will feature X ship?**  
A: Check this roadmap and linked GitHub milestones. Dates are estimates and may change.

**Q: Can I implement a feature myself?**  
A: Yes! Comment on the issue first, then submit a PR following our contributing guidelines.

**Q: Is the roadmap binding?**  
A: No, it's a plan. Priorities may shift based on community feedback, security issues, or business needs.

**Q: How often is the roadmap updated?**  
A: Monthly, or more frequently if major changes occur.

**Q: What happens to features not on the roadmap?**  
A: They may be in the [Backlog](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/milestone/X) or [Future](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/milestone/Y) milestones.

---

## Change Log

| Date | Version | Changes |
|------|---------|---------|
| 2026-05-21 | 1.1.21 | Added v1.1.18–v1.1.21 releases; updated release calendar with actual dates; removed stale v1.1.4 "current release" section |
| 2025-12-24 | 1.0 | Initial roadmap published |

---

## See Also

- [PROJECT_MANAGEMENT_GAP_ANALYSIS.md](PROJECT_MANAGEMENT_GAP_ANALYSIS.md) - Detailed gap analysis
- [MILESTONE_STRATEGY.md](MILESTONE_STRATEGY.md) - Milestone management
- [LABEL_STRATEGY.md](LABEL_STRATEGY.md) - Issue labeling
- [CHANGELOG.md](../CHANGELOG.md) - Past changes
- [CONTRIBUTING.md](../CONTRIBUTING.md) - How to contribute
