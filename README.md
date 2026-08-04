# NV Digital Open Operator System (NVoOS)

[![PHPUnit](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/actions/workflows/phpunit.yml/badge.svg?branch=alpha-working)](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/actions/workflows/phpunit.yml?query=branch%3Aalpha-working)
[![codecov](https://codecov.io/gh/nvdigitalsolutions/mcp-ai-wpoos/branch/alpha-working/graph/badge.svg)](https://codecov.io/gh/nvdigitalsolutions/mcp-ai-wpoos/branch/alpha-working)
[![JavaScript Tests](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/actions/workflows/javascript-tests.yml/badge.svg?branch=alpha-working)](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/actions/workflows/javascript-tests.yml?query=branch%3Aalpha-working)
[![PHP Linting](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/actions/workflows/php-linting.yml/badge.svg?branch=alpha-working)](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/actions/workflows/php-linting.yml?query=branch%3Aalpha-working)
[![Security Checks](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/actions/workflows/security.yml/badge.svg?branch=alpha-working)](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/actions/workflows/security.yml?query=branch%3Aalpha-working)
[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple)](https://www.php.net/)
[![Patent Pending](https://img.shields.io/badge/Patent-Pending-orange.svg)](https://github.com/nvdigitalsolutions/mcp-ai-wpoos#patent-pending)
[![Documentation](https://img.shields.io/badge/Docs-Grade%20A%20(95/100)-green)](docs/history/2026/implementations/DOCUMENTATION_REVIEW_SUMMARY.md)

**Version:** 1.1.44
**Release Date:** 2026-08-04

**See [§ Previous Releases](#-previous-releases) for all version history.**

**MCP Specification:** 2026-07-28 (Stateless Core, Full Compliance)  
**Maintained by [NV Digital](https://nvdigitalsolutions.com/wpoos)**  
**License:** GPLv3 or later  
**Requires:** WordPress 6.0+, PHP 7.4+  
**Patent Status:** Patent Pending (Application #19/410,504)  
**Documentation:** [Grade A (95/100)](docs/history/2026/implementations/DOCUMENTATION_REVIEW_SUMMARY.md) — 1,617 files across 12 directories, 108 admin screenshots, 100% feature coverage

## 🔍 For Reviewers & Auditors

> **New to this repo? Start here → [`docs/project/FOR_REVIEWERS.md`](docs/project/FOR_REVIEWERS.md)**
>
> That document answers every common question in one place: what the project is, current security posture, what's production vs experimental, PHP version requirements, AI development methodology, compliance status, and scoping advice for a limited-budget review.
>
> **Quick links for reviewers:**
> - [Addon Inventory](docs/project/ADDON_INVENTORY.md) — what each of 26 addons does and its status
> - [Security Posture](docs/operations/security/SECURITY_POSTURE.md) — current state of all 50 audit findings
> - [Compliance Traceability](docs/operations/compliance/TRACEABILITY.md) — every .org rejection reason → commit → verification command
> - [AI-Assisted Development](docs/developer/AI_ASSISTED_DEVELOPMENT.md) — methodology, transparency, and what to scrutinize
> - [Architecture Overview](docs/developer/architecture/ARCHITECTURE.md) — component diagram and data flow

## 📑 Table of Contents

### Getting Started
- [🧩 Overview](#-overview)
- [🎯 Our Mission](#-mission-modernizing-small-to-medium-business-websites)
- [🛡️ Active Security Monitoring](#-active-security-monitoring)
- [⚠️ Warranty & Safe Use](#warranty--safe-use)
- [🏗 System Architecture](#-system-architecture)
- [🚀 Features](#-features)
- [📦 Installation](#-installation)
  - [🌱 Beginner 3-Step Install (Try it on Your PC)](#-beginner-3-step-install-try-it-on-your-pc)
- [🔌 What You Lose Without Third-Party Plugins](#-what-you-lose-without-third-party-plugins)
- [⚙️ Configuration Checklist](#configuration-checklist-action-items)
- [📚 Documentation](#-documentation)

### Core Functionality
- [🧠 Memory & Tool Stack Overview](#-memory--tool-stack-overview)
- [🛠 Built-in tools & automations](#-built-in-tools--automations)
- [🗨️ Front-end chat surfaces](#-front-end-chat-surfaces)
- [💬 Frontend Shortcode](#-frontend-shortcode)

### Addons & Extensions

- [🏗 System Architecture (covers addon ecosystem)](#-system-architecture)

### Orchestration & AI Features

- [🏗 System Architecture (covers orchestration, harnessing, MCP servers, memory bridge)](#-system-architecture)

### AI Providers & Integration
- [🧠 Language Model Providers](#-language-model-providers-openai-gemini-anthropic-nvidia-nim-ollama-lm-studio-hugging-face--cloudflare)
- [🧱 ChatKit Integration](#-chatkit-integration)
- [🌐 Crawl4AI Integration](#-crawl4ai-integration)
- [📡 Job Notification System](#-job-notification-system)
- [🧊 Elementor Widgets](#-elementor-widgets)

### Performance & Optimization
- [⚡ Message Bundling](#-message-bundling)
- [🎯 Agentic Loop Token Management](#-agentic-loop-token-management)
- [🔄 Chat Performance Optimizations](#-chat-performance-optimizations)
- [🌐 Mesh Compute Routing](#-mesh-compute-routing)
- [🔗 Federation & Discovery System](#-federation--discovery-system)

### Remote MCP Setup
- [🔒 MCP Server Authentication](#-mcp-server-authentication)
- [🌐 Connecting Remote MCP Clients](#-connecting-remote-mcp-clients)
- [🛰 REST API Endpoints](#-rest-api-endpoints)
- [🌊 SSE Streaming Support](#-sse-streaming-support)
- [📝 MCP JSON-RPC 2.0 Endpoint](#-mcp-json-rpc-20-endpoint)
- [🔑 Assistant API Credentials](#-assistant-api-credentials)
- [🎫 Token Management UI](#-token-management-ui)

### Assistant Management
- [🛠 Assistant Editor Overview](#-assistant-editor-overview)
- [📊 Assistant Storage: CPT vs CCT](#-assistant-storage-cpt-vs-cct)
- [⚡ Assistant Tool Shortcuts](#-assistant-tool-shortcuts)
- [🧠 Agent Skills](#-agent-skills)
- [👔 Professional & Team Layers](#-professional--team-layers)
- [🧵 REST Chat Payloads & Attachments](#-rest-chat-payloads--attachments)

### Development
- [🐳 Local Development with Docker](#-local-development-with-docker)
- [🧑‍💻 Development Tooling](#-development-tooling)
- [📦 NPM Packages](#-npm-packages)
- [🧪 Testing & QA](#-testing--qa)
- [🧩 Hooks & Filters](#-hooks--filters)
- [🧰 WP-CLI Commands](#-wp-cli-commands)

### Reference
- [🔐 JetEngine Capability Reference](#-jetengine-capability-reference)
- [🛰 JetEngine REST API Reference](#-jetengine-rest-api-reference)
- [🧮 Usage Tracking](#-usage-tracking)
- [🧷 Attachment MIME Controls](#-attachment-mime-controls)
- [🧾 Logging](#-logging)
- [🧾 JetEngine REST Endpoint Report Helper](#-jetengine-rest-endpoint-report-helper)
- [🔌 Optional Tools & Dependencies](#-optional-tools--dependencies)
- [✅ Manual QA Scenarios](#-manual-qa-scenarios)

---

## 🗺 Repository Map

| Directory | Purpose |
|-----------|---------|
| `includes/` | Core plugin classes — admin, assistants, tools, services, REST, security (7 infrastructure classes), providers (~15 AI backends), harness, data, markup, measurement, skills, professions, teams, slash-commands, A2A/ACP protocols, federation, elementor, blocks, crawler, integrations |
| `lib/core/` | Framework-agnostic AI orchestration engine (nvoos/core): 32 domain contracts, 21 WordPress adapters, ChatOrchestrator, ProviderRouter, ToolRegistry, SkillRegistry — PHP 8.1+ |
| `addons/` | 27 installable addons (Pro, Chat SPA, Docs Hub, SaaS Controller, Cloud Worker, Cloudways Dashboard, Toolkit Shell, Canvas, Canvas Toolkit, Document Editor, Media Studio, Graphify, Comic Reader, Funiq Bridge, AI Platform, Algorave, Cornerstone3D, Embedded, Fantasy Football, LibreChat, Schedule Anything Platform, Schedule Anything SPA, Tenant Router, Page Agent, Crocoblock DS, LibreChat, Status Page) |
| `assets/` | Frontend JS/CSS, images, CSV templates, examples |
| `.agents/skills/` | 21 coding-time agent skills for Zed editor (WordPress plugin development patterns) |
| `.bmad/` | 6 BMAD workflow agent YAML definitions + team composition config |
| `.context/` | Subsystem context files (8 topics + 5 templates) for agent session loading |
| `plugins/` | Standalone plugins: NVOOS Graphify, NVOOS Graphify AI, NVOOS Graphify AI Platform |
| `packages/` | 23 NPM packages under `@nvdigitalsolutions` scope |
| `src/` | TMA (Telegram Mini App) builders + workflow builder source |
| `shared/` | Shared source code across builds |
| `core/` | Standalone "core" distribution (`mcp-ai-wpoos-core.php`) |
| `config/` | Site blueprints |
| `examples/` | Agent and workflow example code |
| `tests/` | PHPUnit test suite |
| `docs/` | Comprehensive documentation (~1,600 files across 12 directories) |
| `bin/` | Development and deployment scripts |
| `docker/` | Docker Compose configuration |
| `languages/` | Translation files (.pot/.po/.mo) |
| `patches/` | Dependency patches |
| `.github/` | CI/CD workflows (~30 pipelines), custom agents, Copilot instructions |

---

## 🧩 Overview

Real-time AI Orchestration Toolkit for Wordpress - **NV oOS** is a modular AI framework (Object-Oriented System) for WordPress that connects your site's data with 15 language-model providers: OpenAI, Gemini, Anthropic, DeepSeek, OpenRouter, Baseten, Kimi (Moonshot), Z.AI (GLM), DigitalOcean, NVIDIA NIM, Cloudflare Worker AI, Ollama, LM Studio, and Hugging Face.  It allows you to create and manage AI Assistants that can interact with users, access WordPress data, and perform custom tool functions.

### ✨ What's New at a Glance (v1.1.44)

- 🐛 **CCT Stability & Base Plugin Hardening (4 fixes).** CCT duplicate registration race condition resolved with mutex lock. FlowHub CCT duplicate registration and menu title corrected. Fatal error when base plugin loads without `lib/core/` fixed (graceful degradation). Veo fallback async context forwarding, Omni API endpoint, audio, and completion hooks repaired.
- 🔑 **API Key Resolution Fixes (2 PRs).** Gemini API key resolution in video services restored after credential split. Veo video generation fallback chain now correctly forwards async context.
- 🛡️ **Security & Architecture Hardening — Proposal 016 (all waves).** Perf optimization: 277 class-only requires wrapped in `spl_autoload_register` conditional. Autoload class-name heuristic corrected to strip `wp_mcp_ai_` prefix. Repository-wide phpcs auto-fix + manual corrections. Docker QA vendor mismatch resolved. All proposal findings addressed (medium-severity: error-log verbosity reduction, auto-index detection, debug output lock; low-severity: 6 findings resolved).
- ⚡ **Polling, Queue & Load Balancing Hardening — Proposal 017.** Twelve structural weaknesses identified and remediated across polling infrastructure, Action Scheduler queue management, and provider load-balancing subsystems. Stale backup file causing ambiguous class resolution removed.
- 🔒 **Deferred Security Items (#5755, items 5–7).** Remaining security-hardening items from earlier audit resolved — post-meta output escaping, term description escaping, and REST response field filtering.
- 📝 **Docs & Ecosystem.** FOR_REVIEWERS.md updated with latest tool counts (~1,500 total), addon inventory, and security posture. 16 broken cross-reference links fixed across 5 files after docs reorganization. Graphify ecosystem audit: wp.org review fixes, migration gap analysis, and chat shortcode integration plan.

### ✨ What's New at a Glance (v1.1.43)

- 🔒 **Security Hardening v1.1.43 (16 files).** SSRF protection across 7 provider connection handlers + A2A agent URL. SQL table-name validation against injection. A2A per-assistant agent cards now auth-gated. Chat SPA /config and /manifest endpoints require authentication (no longer public). Missing args schemas on 7 REST endpoints. Guest rate-limiting now IP-based (DoS vector closed). 3 tool capability mismatches corrected. Centralized defense-in-depth capability gate in Tool Registry. PHP object injection prevention via `safe_unserialize` helper.
- 🆙 **MCP Protocol Upgrade (2026-07-28).** Stateless core — sessions retired, `server/discover` replaces `initialize`, `_meta` per-request capabilities, `Mcp-Method`/`Mcp-Name` headers on Streamable HTTP, TTL/cache-scope on `tools/list`. Legacy client shim for backward compatibility. 10 files changed.
- 📚 **OKF v0.2 Trust-Signal Support.** Indentation-aware recursive descent parser with inline YAML mappings, nested object lists, and flow sequences. New trust-tier derivation (`unverified`/`machine-confirmed`/`human-reviewed`), staleness checks, `search()` extension with trust filters. New `okf_validate_attestation` tool. All 6 existing OKF tools surface trust signals. 15 files, 27 standalone smoke tests.
- 🏗️ **Pro Module Registry.** `wp_mcp_ai_pro_init()` decomposed from ~625-line monolithic function into a PSR-4 module registry with per-module loading, dependency ordering, and graceful degradation. Fixes broken require paths in module loader.
- 🎯 **Ideal Customer Profile (ICP) System (Pro CRM Phase G).** 7-dimension scoring engine (0–100) with fit+intent separation, behavioral decay, and negative scoring. 2 new MCP tools: `compute_icp_score`, `manage_icp_profile`. Admin UI with profile list table and 7-tab editor.
- 🔧 **Profession & Playbook Sync Fixes (7 PRs).** Playbook bulk sync silent failures now report errors. Force-regenerated playbook files no longer deleted. Undefined array key in playbook stats fixed. Duplicate profession slugs removed from knowledge base (312→311 entries). Title fallback prevents duplicate posts on Update. Consumed-post collision fix ensures all unique KB entries create distinct posts. Test Model assistant dropdown restored.
- 🧹 **Hexagonal Architecture Purity.** `PlatformFlushInterface` contract extracts `wp_ob_end_flush_all` from framework-agnostic `lib/core/` SSE handler into a WordPress adapter — zero WordPress references remain in nvoos/core.
- 🛡️ **Phase 3 Operational Security.** Audit logger REST route hook corrected (`init` → `rest_api_init`). Security posture signals hardened.
- 📦 **Dependency Updates.** WPCS bumped to 3.4.1 (CVE-2026-45293). Dev tooling: Phpactor LSP replaces Intelephense.

### ✨ What's New at a Glance (v1.1.42)

- 🛡️ **Security Infrastructure Hardening.** 7 new security infrastructure classes (Request Guard, Security Posture with 21 signals A-F grading, Destructive Ops Gate, URL Guard, Concurrency Guard, Cost Tracker, API Key Store). Site Health integration. Production hardening guide. CORS, rate limiting, error verbosity, and body size enforcement with admin dashboard posture signals.
- 🏗️ **Framework-Agnostic Core (`lib/core/`).** nvoos/core package — Hexagonal Architecture with 32 domain contracts + 21 WordPress adapters. ChatOrchestrator with RateLimiter + SemanticCompressor. 109 tools migrated to framework-agnostic format. ProviderRouter with 12-provider routing. 5 chat parity gaps closed.
- 📡 **Status Page & Incident Communication (Pro).** Maintenance window system with frontend banner, countdown timer, and multi-channel notifications. Incident workflow with phase state machine, phase-aware dispatcher, and lesson bridge. 4 new AI tools for service status and incident management.
- 🤖 **Agent Skills & BMAD Agents.** 21 coding-time agent skills for Zed editor covering WordPress plugin development patterns. 6 BMAD workflow agent YAML definitions with team composition config. Full `.context/` subsystem context files with 8 topics + 5 templates.
- 🎵 **Algorave Addon.** New live coding and algorithmic music generation addon with 9 tools, pattern/session CPTs, and REST API.
- 🔒 **Security Hardening (12 fixes).** OAuth token lifetime controls, DICOM PHI auto-redaction, asset version stripping, exception guard, auth brute-force protection, SSE CORS filter, webhook secret indicator. 13 new security unit tests.

### ✨ What's New at a Glance (v1.1.41)

- 📚 **OKF Integration (Open Knowledge Format v0.1).** Google vendor-neutral knowledge format engine with 6 MCP tools for curated, deterministic knowledge management. All 41 bundled skills are now OKF v0.1-conformant.
- 🔒 **Security Compliance Fixes (11 HIGH/P0).** HMAC-signed policy tokens for professional selector, health endpoint auth-gating, ZIP path traversal prevention, CSRF nonces on sync endpoints, SRI integrity hashes for all 6 CDN libraries, Google Chat OIDC hardening.
- 🔧 **Playbook Sync Fixes.** Duplicate AJAX handler conflict resolved. Silent sync failures now report errors to admin UI. CPT class loading guards prevent fatal errors during bulk reseed.
- 🔑 **Model Provider Credential Resolution.** Model picker now resolves API keys from all 4 sources — settings, credentials option, environment variables, and PHP constants.
- 🛡️ **Dependency Security.** adm-zip, axios, and brace-expansion bumped to resolve 18 Dependabot alerts across 5 package.json files. npm audit: 0 vulnerabilities.

### ✨ What's New at a Glance (v1.1.40)

- 📝 **Content Format Awareness.** New `WP_MCP_AI_Content_Format_Helper` detects and preserves Markdown, HTML, and plain text formats across post-modifying and analysis tools — AI-generated content retains its intended structure through the full create/update pipeline.
- 🔗 **Research → Paper Store → WordPress Draft Pipeline.** All research tools now support `save_to_paper_store` for staging results in the flat-file Paper Store. New `create_post_from_research` Pro tool bridges staged research to WordPress drafts. Human-in-the-loop review before publishing.
- 🎬 **Demo Video Pipeline Complete (Phases 0–5).** Scripted scene recording with AI voiceover narration. GitHub Actions CI workflow automates video assembly. 14 narration scripts and video catalog.
- 🔐 **Settings Credential Split.** Sensitive API keys moved from `wp_mcp_ai_settings` (autoload) to separate non-autoload `wp_mcp_ai_credentials` option with transparent merge. One-time migration. `wp_suspend_cache_addition` and dual cache clearing for defense-in-depth.
- 🤖 **Kimi & DeepSeek Client Parity.** Both providers now match all first-class providers: streaming, tool use, token tracking, and error handling. Plus DeepSeek and 9 missing providers added to all research tools.
- 📊 **Model Catalog Update (July 2026).** 24 files updated across base + pro. Default bumps: Gemini `gemini-2.5-flash` → `gemini-3.5-flash`, NVIDIA `meta/llama-3.1-8b` → `nvidia/nemotron-3-nano-30b-a3b`, Gemini Live `gemini-2.5-flash-live` → `gemini-3.1-flash-live-preview`.
- 🏗️ **OOS Engine: SchemaStoreInterface + Tests.** New domain contract, PostTypeSchema/TaxonomySchema entities, GetPostTypeSchemaTool, WordPress adapter. 45 new tests.
- ⚡ **SSE HTTP/2 Fixes.** `ob_clean()` replaces `ob_flush()` to prevent protocol errors. 524 timeout resolved in Pro SPA v2.
- 🔍 **Vector Store Sync — No Polling.** Status now checked only on assistant change and page load, reducing API load.
- 🔧 **Settings Import/Export Batch (4 fixes).** Credential merge, save key wipe, subtab sanitization, export consistency — all resolved.
- 🛡️ **Validated Tool Slug Allowlist.** Tool slug matching fixed for validated variants.


### ✨ What's New at a Glance (v1.1.39)

- 🤖 **Page Agent Addon v0.1.0.** New addon (`addons/page-agent/`) — AI-powered browser page control copilot powered by Alibaba Page Agent (MIT). Give any WordPress page its own AI agent that can click, type, and navigate via natural language, running entirely client-side with no headless browser required. Includes shortcode, Elementor widget, REST endpoints, and MCP tool bridge.
- 💬 **Pro SPA v2 — Major Parity Update.** Voice pipeline, tasks drawer, workflow tracker, and file attachment upload to WordPress Media Library. Tool Shortcuts and Slash Commands drawers. Mobile hamburger sidebar toggle. Speech/audio button fixes using correct REST endpoint. Conversation title improvements and turn count display fix. Button and token NaN display fixes. Model sync and auth bypass fixes. Assistant preloading in runtime config.
- 🎨 **Pro SPA v2 — UI Polish.** Autoscroll fixes (submit, streaming start, user-at-bottom guard, scrollTop vs scrollIntoView). Viewport height fixes via CSS height chain instead of viewport calc. `overflow:hidden` on height-chain ancestors. `filemtime` cache-busting across all SPA addons. Lint errors resolved.
- 👤 **Per-User Chat Memory Preferences.** Users can now toggle chat memory on/off from their WordPress user profile. Individual control over AI memory retention without affecting site-wide defaults.
- 📝 **create_post / save_post Tool Enhancements.** Markdown-to-HTML conversion via new `WP_MCP_AI_Tool_Markdown_Converter` trait. Smart taxonomy suggestions auto-detect relevant categories and tags. Block content corruption fixed for non-post post types.
- 🔄 **Workflow Blueprint & Schedule Improvements.** Existing-content awareness in Content Publisher and Keyword Pipeline blueprints. Blog schedule presets now check for duplicate content before publishing. Readable response generation for workflow schedule result delivery.
- ♿ **SPA Accessibility.** Annotation pills made clickable with meaningful screen-reader labels.
- 🛡️ **Security.** OWASP ZAP DAST medium findings triaged as false positives.

### ✨ What's New at a Glance (v1.1.37)

- 🏭 **EZuite Inventory Sync Pro Toolkit.** ERP-integrated inventory sync bridging EZuite ERP with WooCommerce/WordPress. Pull products, query inventory, create/update items, manage orders, and configure API credentials — all via AI tools. Admin UI with connection selector, field mapping, and sync direction controls. CLI sync commands for batch operations.
- 🔄 **Ralph Loop CCT Migration & Orchestration.** Circuit breaker pattern with configurable failure thresholds. Execution logger with step-by-step tracking. CCT migration tools for safe cross-environment JetEngine data operations. Orchestration tools for multi-step workflows.
- 📅 **JetBooking/JetAppointment Integration (8 tools).** Adapter layer for Crocoblock booking and appointment plugins. 8 new tools + 4 enhanced calendar tools with booking/appointment awareness.
- 🧠 **Moonshot AI (Kimi) & Z.AI (GLM) Provider Parity.** Both providers upgraded to full DeepSeek-level chat client capabilities — streaming, tool use, token tracking. ZAI client + baseten service in DI container. Provider count now **15** first-class.
- 📋 **Unified Sync Log Manager.** Per-item audit trail across EZuite, FlowHub, and Shopify sync toolkits. Sync history with timestamps, status, and error tracking. Status dashboards on admin pages.
- 🎛️ **Tool Presets Auto-Select & Chips Bar.** Selected tools display as clickable chips with +N overflow toggle. Tool payload cap raised from 50 to 100 tools per assistant.
- 🌐 **HTTrack Cache & Place-to-Service Bridge.** HTML mirror import now supports HTTrack cache directories. Auto-creates bookable services during batch place import. URL backfill for mirrors without hts-cache.
- 🔌 **FlowHub Per-Connection Overview.** Remote Sites connection selector on FlowHub config tab. Per-connection sync controls. Proxy support via http_api_curl hook.
- 🔍 **Web Search 429 Retry.** Exponential backoff for rate-limited search requests.
- 🐛 **45+ Bug Fixes.** CCT module API mismatches across EZuite/FlowHub/Shopify (canonical Module.instance/Factory/ItemHandler). EZuite sync (missing return, field mapping, connections, CCT registration). FlowHub sync (silent failure, proxy persistence, auth headers, null cct, dry-run). Shopify sync (Catalog API guard, CCT registration lifecycle). Duplicate column errors in ensure_columns. SQLite meta cache explosion. masterminds/html5 case collision. Base-version guard blocking toolkits. Tool registry fatal guards. HTTrack import (URL resolution, hex filenames, subdirectory content, mirror detection). Necessity Gate request context crash. Auto-select compute timeout. Place-to-Service bridge collision.
- 📖 **Documentation.** Abilities registration plan (~1,000 tools as WordPress Abilities). Laravel-scale deployment architecture proposal. WP.org submission prep. Agent context sync.

### ✨ What's New at a Glance (v1.1.35)

- 🏪 **FlowHub Inventory Sync Pro Toolkit.** 6-tool cannabis dispensary management: products, inventory, locations, sync, analytics, alerts. P1 proxy support via http_api_curl hook. P2 CCT auto-registration in JetEngine custom content type tables. Auth, decryption, location_id, and null-guard fixes (PRs #5500, #5501, #5502, #5503, #5507, #5510). Admin UI toggle on Features tab.
- 🛒 **Shopify Sync Pro Toolkit.** 5-tool bi-directional e-commerce sync: products, orders, inventory, analytics, settings. Connection resolver trait. Dashboard widget with sync status and recent activity. Tool reference docs at `docs/tools/shopify-sync-toolkit.md` (PR #5502).
- 🛡️ **Necessity Gate Layer J.** Pre-execution safety layer that scores tool calls by irreversibility risk. Write operations (create/update/delete) assigned risk scores by resource type. Safety profile trait with clean autoload. Request context crash fix.
- 🎙️ **Local Voice Embedded STT.** Three pluggable browser-side speech-to-text backends: Web Speech API, Whisper.cpp (WASM), Vosk (WASM). Offline-first — no server dependency. Auto-detection based on browser capabilities (PR #5498).
- 🌐 **Remote Site Administrator Blueprint.** 22-tool assistant blueprint in Site Creator toolkit for full remote/local WP/WooCommerce management with JetEngine, JetFormBuilder, and REST API control. Auto-discovered by Unified Blueprints page.
- 📦 **Places & Calendar Bulk Import.** Batch import tools for Places and Calendar Booking toolkits — import multiple records in a single call (PR #5509).
- 💻 **CLI site-import Subcommand.** Multi-phase HTML mirror import for migrating static sites into WordPress — page structure extraction, content mapping, media sideloading.
- 🎤 **Voice Realtime Auto-Detect.** WebRTC/WebSocket auto-selection, duplicate message prevention, VAD threshold improvements (PR #5508).
- 🔧 **Remote Connections Fixes.** WordPress case handling normalized. FlowHub and Printful credential storage fixed (at-rest encryption). Printful connection type added (PR #5499).
- 🐛 **7 Bug Fixes.** Token-scoped assistant resolution (PR #5497), user_id empty fallback (PR #5495), credential token mapping (PR #5493), post type name lengths (PR #5484), OpenAI image deprecation cleanup (PRs #5489-#5491).
- 📋 **Documentation.** GPT-Realtime-2 upgrade proposal + 1,166-line implementation plan. FastAPI porting implementation plan (PR #5467).
- 🧹 **Housekeeping.** Stale build artifacts and toolkit-addons directory removed.

- 🔌 **WP 7.0 Connectors Credential Integration.** Credential_Resolver now integrated into all 17 AI client `get_api_key()` methods. Fallback chain: WP 7.0 Connectors → plugin settings → env vars → PHP constants. Credential source badges and WP 7.0 Connectors hints rendered in admin settings UI. All 13 provider API key field descriptions updated. Provider diagnostics show key source column. Settings health check counts credentials via resolver.
- 🧩 **nvoos-graphify v1.0.0.** Standalone nvoos-graphify plugin released at v1.0.0 (Plugin Check compliant). nvoos-graphify-ai released at v1.0.0-dev. Fixed critical `->prepare()` spread-operator bug in `Db::listNodes()`. Renamed `vector` column → `embedding_vector` to avoid MariaDB/MySQL reserved-word conflict. Fixed snake_case→camelCase method calls across tools, controllers, and cross-plugin integrations.
- 🛡️ **Security Dependencies.** guzzlehttp/guzzle 7.10.0 → 7.12.1 (CVE-2026-55568, CVE-2026-55767). guzzlehttp/psr7 2.11.0 → 2.12.1 (CVE-2026-55766). guzzlehttp/promises 2.3.0 → 2.5.0. undici npm override tightened `>=7.28.0` → `>=8.5.0`.
- 🔒 **npm Security.** 29 alerts resolved across 14 packages: undici (TLS bypass CVE-2026-9697, cache info CVE-2026-9678), http-proxy-middleware (CRLF injection CVE-2026-55603), nodemailer (GHSA-p6gq-j5cr-w38f), webpack-dev-server (HMR CVE-2026-9595), dompurify (GHSA-cmwh-pvxp-8882). Fixed critical duplicate `overrides` key in root `package.json`.
- 🪲 **Bug Fixes.** Fixed missing `-pro-` in WP All Import/Export require_once paths causing fatal errors when those Pro tools load. Fixed fragile `@file_get_contents()` warning suppression in tool status label loader — replaced with explicit `set_error_handler('__return_true')` to prevent leaked warnings from corrupting MCP JSON-RPC HTTP responses.
- 📦 **Dependencies.** 15 Dependabot bumps across Composer, npm (stripe, zod, p-queue, csv-parse, react-query, puppeteer, vitest, wrangler, eslint-plugin, workers-types, types/node), and GitHub Actions (codecov 4→7, action-gh-release 2→3).
- 🧹 **Housekeeping.** Stale 1.1.31 and 1.1.32 build zips removed. SPA addon ZIPs rebuilt with updated security overrides.

### ✨ What's New at a Glance (v1.1.32)

- 📝 **Content Format Templates & Featured Images.** Content Format Template CPT with user-editable blog templates. Content Template Engine generates Anthropic-optimised AI prompts. Featured Image Service with 3-provider fallback (DALL-E → Gemini → Cloudflare) and 5 image styles. Provider image settings now respected instead of hardcoded defaults.
- 📬 **Result Delivery Pipeline.** 1,056-line service routes schedule results to 8 channels (email, Slack, Discord, Telegram, SMS, Paper Store, WordPress post, webhook). Both success and failure paths now deliver — previously only failures were surfaced.
- 📄 **ECA Document Generation.** ECA Consolidate & Add page with document generation tools.
- 🪲 **Duplicate Posts Fixed.** WordPress delivery channel removed from `weekly_blog_post_writer` and `weekly_blog_topic_research` presets. Systemic guard skips WordPress delivery when AI tool calls already include `create_post` or `save_post`.
- ⏱️ **6 Provider Clients Timeout Fix.** DeepSeek, Baseten, DigitalOcean, OpenRouter, Kimi, and Cloudflare clients now respect the global `request_timeout` setting instead of hardcoding 60 seconds.
- 🔧 **Schedule Trigger Stability.** Trigger crash on `rest_do_request()` fixed with try/catch + pre-flight REST check. Result delivery sanitizer preserves `channels` wrapper so edit modal shows saved values. AJAX handler captures PHP warnings that corrupt JSON.
- 📋 **Paper Store Delete Fix.** Hidden inputs added to delete confirmation form so `handle_delete_record()` receives required POST fields.
- 🧪 **npm CI & Jest Resilience.** Babel ESM override pinned for Node 18/20 compatibility. Jest config probes for setup files at load time with graceful fallback. npm ci lockfile sync for 5 addons.
- 📦 **Dependencies.** 14 safe Dependabot bumps. 8 npm audit CVEs (nodemailer, tar, tar-fs). phpspreadsheet 5.7.0 → 5.8.0.

### ✨ What's New at a Glance (v1.1.31)

- 🎬 **Media Command Center.** Top-level NV Media admin menu with command center for media templates, presets, and blueprints.
- 💬 **Pro SPA v2 — Rich Rendering & Scoping.** Rich markdown rendering, per-assistant scoping, agent selector dropdown. Conversations primary, threads read-only. Version 2.0.1.
- 🧰 **34 Workflow Preset Tools.** All missing workflow presets implemented across 10 toolkits. Full PHPCS compliance and test suite for 36 tools.
- 🔒 **npm Audit CVEs Resolved.** 12 CVEs fixed: vite, launch-editor, markdown-it, ws, js-yaml, form-data, hono, dompurify, babel, opentelemetry, joi.
- 🎨 **Gemini 3.1 Flash Image.** Default Gemini image model upgraded to `gemini-3.1-flash-image`.
- 🖼 **Media Toolkit Blueprints & Presets.** Blueprints and scheduler presets synced to Data Management page.
- ⚡ **OpenAI/DeepSeek stream_options.** Streaming usage payloads now include proper `stream_options` for accurate usage tracking.
- 💰 **Agentic-Loop Cost Tracking.** Tool result costs computed from tokens when not explicitly provided. Missing provider pricing added.
- 🛡️ **Vite CVEs.** vite ^8.0.16 for CVE-2026-53571 (server.fs.deny bypass) and CVE-2026-53632 (NTLMv2 disclosure).
- 🧹 **1,658 PHPCS Lint Fixes.** Across base + pro addon (44 toolkits).
- 🔧 **CI Disk Space.** Free disk space step added to all build workflows.

### ✨ What's New at a Glance (v1.1.30)

- 💬 **Chat SPA Phase 8 — Message Actions.** Edit, delete, regenerate, copy, and content enrichment cards on every assistant message. Conversations sidebar with assistant scoping. Auto-create thread on first message.
- 📊 **PM Toolkit Enhancement Phase A–D.** Shared engine powering Command Center dashboard with real-time task visibility. Work Ingestion panel. 28 new AI tools for task management, resource allocation, timeline generation, and reporting.
- 🏢 **CRM Toolkit — Duplicates & Hygiene.** Duplicate detection engine with safe one-click merge. Email hygiene module: domain reputation classification, exclusion/priority lists, auto-pruning. Top Customers/Clients analytics. LinkedIn & Upwork external sourcing.
- 🥧 **DietPi Pro Toolkit Phases 0–3.** 19+ tools for server management: system info, package/service control, backup/restore, storage, provisioning, SSH proxy. Registered as MCP server.
- 🛡️ **Layer I Guardrails — Jailbreak Prevention.** Stay-on-target guardrails blocking prompt-injection and role-change attacks. Configurable per-assistant via LLM Harness metabox.
- 🪟 **Context Window Management.** Pre-flight validation across all 13 AI providers. tiktoken integration, estimator metabox, token-budget tool capping, chat parity drift detection.
- 🌉 **WP 7.0 Connectors API Bridge.** Forward-compatible provider credential bridge. Classes load unconditionally on WP < 7.0.
- 🧠 **Chat Transcript & Agent Memory Retention.** Configurable TTL-based transcript cleanup (base). Agent memory lifecycle management with pruning and retention windows (Pro).
- ⚡ **Pro Toolkit Optimizations Phase 1–3.** Performance optimization across 6 Pro toolkits with autoload control, caching, and lazy loading.
- 🔌 **OAuth & API Disconnect Buttons.** One-click disconnect for OAuth and API connections with automatic token clearing.
- 💬 **LibreChat Addon.** New standalone addon with code interpreter, speech-to-text/text-to-speech, and web search reranker.
- 🪲 **30+ Bug Fixes.** SPA reliability sweep (thread endpoints, router context, event serialization). Agentic loop tool result persistence. CPT slug limits. Security CVEs (guzzlehttp/psr7, shell-quote, esbuild).

### ✨ What's New at a Glance (v1.1.29)

- 🔧 **Chat Bubble Assistant Dropdown.** Settings UX fixed: `chat_bubble_assistant_id` changed from manual ID input to proper assistant `<select>` dropdown.
- 🪟 **Context-Window Pre-Flight Validation.** Added to all AI provider clients with shared `validate_context_window()` helper, tiktoken integration, and estimator metabox.
- ⚡ **OpenAI SSE Streaming Fix.** `stream_options` payload flag corrected so OpenAI real-time SSE streaming triggers properly.
- 📅 **Schedule Preset Data Mismatches.** Fixed presets losing configuration data after save. Improved error logging for preset operations.
- 🧹 **Playbook Orphan Cleanup & Batching.** Fixed orphan accumulation and sync timeouts with configurable chunk size batching.
- 🔄 **Stale Provider Validation Lists.** Provider checks moved to dynamic discovery instead of hardcoded arrays, fixing DeepSeek rejection.
- 📊 **CRM Activity Titles, Due Dates & Block API v3.** Activity post titles, recurring due dates, and admin blocks migrated to WordPress Block API v3.
- 🎯 **OpenAI-Compatible Client — DeepSeek Parity.** OpenRouter, Baseten, Kimi, DigitalOcean, NVIDIA NIM clients upgraded to DeepSeek parity.
- 🎤 **Voice & Embedded LLM Missing Assets.** Missing `.min.js` files added for voice recording/transcription and embedded LLM worker scripts.
- 🪲 **25+ Additional Fixes.** Chat config messagesEndpoint, debug console, OOS bridge fatal error, embedding service WP_Error, SSE header warnings, memory cookie nonce, graphify content leak, model limits sync, shell-quote CVE-2026-9277, and more.
- 🧪 **Tests.** Chat transcript REST controller tests improved from ~4% to 87% pass rate.

### ✨ What's New at a Glance (v1.1.28)

- 🏢 **CRM Phase C Complete.** IMAP email polling, Twilio SMS webhook, Meta WhatsApp webhook, and Gmail OAuth bridge for multichannel inbound ingestion — all triaged through CRM Classifier and routed to the Workflow Command Center.
- 👥 **Customer CPT + Customer 360.** 5 CRUD tools for `mcp_ai_customer` CPT. Customer Research & Add page with Customer 360 dashboard. Lead-to-customer conversion with deal promotion.
- 🎫 **Support Ticket Module — 10 AI Tools + SLA.** Full ticket lifecycle: create, get, update, list, classify, escalate, resolve, reopen, merge, SLA report. Ticket automation, SLA breach detection via cron, email notifications, and optional Zendesk sync.
- 🔍 **TF-IDF + BM25 Relevance Search.** Dual-algorithm relevance ranking across CRM, healthcare, and base content search tools. Shared traits in both Base and Pro.
- 🧠 **Transformer-Inspired Attention Routing.** QKV multi-head attention (5 heads: semantic, capability, recency, dependency, risk) for semantic tool selection. Sliding-window conversation compressor. Persistent tool embedding store. RRF fusion with harness scoring.
- 🔌 **Funiq Bridge Addon.** Payload-to-WordPress bridge with React admin SPA, REST controllers, transformers, post types, and taxonomies.
- 🕸️ **NVOOS Graphify Ecosystem.** Three standalone plugins: `nvoos-graphify` (visual knowledge graph, 14 tools), `nvoos-graphify-ai` (13 providers, streaming chat, RAG, embeddings), `nvoos-graphify-ai-platform` (Agents, A2A, ACP, Blueprints, Federation, Harness, Measurement, Professions, Skills, Slash Commands). Framework-agnostic `lib/core` and `lib/wordpress-adapter` packages.
- 🏗️ **NV Platform AI Addon.** Top-level admin dashboard + CPTs (Project, Resource, Template).
- 🎬 **Automated Demo Video Pipeline (Phases 1–3).** Scripted scene recording, AI voiceover generation, automated video assembly.
- 🛡️ **CRM Lead/Deal Enhancements.** Enriched lead/company tables, dedicated Leads tab, data completeness KPI. Lead CPT admin expanded with contact details and remote channel link.
- 📋 **Documentation & Unix Theory.** Folder READMEs for new CRM subdirectories (`customers/`, `inbound/`, `support/`). Compliance check errors in `includes/data/` and `addons/pro/includes/traits/` READMEs resolved. CRM enhancement plan updated.

### ✨ What's New at a Glance (v1.1.27)

- ⚡ **Real-Time SSE Streaming.** Real-time streaming enabled for OpenAI, DeepSeek, and all OpenAI-compatible providers. "Disable Native Streaming" control in Settings.
- 🧰 **35 New OOS Core Tools.** Data tools (GetPostTaxonomies, CountPosts, GetPostMeta, TruncateText, MergeArrays), format tools (FormatDate, TimeAgo, ParseCsv, MathEval, ColorConvert), infrastructure (EventDispatcher, Queue), and cache management tools.
- 📸 **Extended Cognition Vision Recognition.** Visual product/brand recognition with camera viewfinder UI, detection overlays, and consent gate.
- 🔧 **JetFormBuilder Submission Tools — 8 Fixes.** Empty results for non-admin users, form discovery pipeline, PHPCS warnings, REST route matching, form-type auto-detection, and plugin detection all fixed.
- 🎯 **Graphify Tools Capability Compliance.** Missing trait and explicit `get_required_capability()` added to all Graphify tools.
- 🤖 **DeepSeek Agentic Tool Handling.** Tool message filtering and payload normalisation for agentic multi-turn workflows.
- 📝 **Docs Fixes.** Broken links after Unix-theory reorganization resolved.
- 💰 **June 2026 Model Pricing.** All 13 provider pricing updated.
- 📋 **Plugin Restructuring Proposals v3.0.** Graphify-centric architecture spec and roadmap.
- 🛡️ **Pro Toolkits Security Audit.** 9 HIGH-severity security findings fixed.
- 📋 **Reviewer Onboarding Docs.** Complete reviewer documentation suite (`docs/project/FOR_REVIEWERS.md`).
- 🐳 **Docker Dev Environments.** WordPress, Laravel, and Craft CMS Docker environments all fixed.
- 🧪 **Test Infrastructure.** 95% of PHPUnit failures resolved across base, pro, and addon test suites.
- 🔧 **Infrastructure Fixes.** TCPDF autoloader fix, Pro vendor files committed, puppeteer detection path fix, shallow clone recommendation.

### ✨ What's New at a Glance (v1.1.25)

- 🧩 **Unified Blueprint System.** 55 pre-built AI assistant blueprints across 25 toolkits.
- ☁️ **Cloudways Pro Toolkit.** 60 AI tools for server and application management via Cloudways API v2.
- 🏢 **CRM Toolkit Phases A–E Complete.** 70+ tools: lead management, multi-channel triage, sequences, command center, compliance.
- 💬 **Chat UI Enhancements.** 7 features: profile card, stop generation, feedback, code copy, dark mode, prompts, search.
- 📂 **Unix-Theory Tool Reorganisation Phase 4–5 Complete.**
- 🎛 **Pro Toolkit MCP Server Settings Pages.** Phases A–C.
- 🏥 **Aerlinn + Healthcare Blueprints.**
- 🔧 **Build Infrastructure Hardening.**

### ✨ What's New at a Glance (v1.1.24)

- 🧹 **Bug-Fix & Stabilisation Sweep.** Paper Store Pro interface load order fix (deferred to `wp_mcp_ai_bootstrapped` hook). Chat SPA duplicate-message and SSE protocol-mismatch fixes. Markdown rendering enabled in Chat SPA responses.
- 🛡️ **Skill Manager Canonical Envelope — Unix Theory P0/P1 Refinement.** Skill manager now returns `WP_Error` on failure instead of the legacy `array('success' => false, ...)` pattern. Skills sync endpoint added for idempotent import/export. YAML frontmatter parsing hardened against colons in description fields.
- 📝 **Assistant Tool Presets Coverage.** 24 missing tools added to assistant creation presets. Out-of-date tests fixed.
- 🔒 **CVE Patches.** `tmp` bumped to >=0.2.6 and `symfony/cache` to ^6.4.40 to resolve upstream CVEs. Composer vendor state committed.
- 🗄️ **Paper Store Admin CRUD.** Full CRUD admin UI for Paper Store collections and records under Assistants menu, matching Skills admin convention.
- 🖥️ **CLI Coverage Enhancements.** Comprehensive WP-CLI command coverage improvements across the plugin toolchain.
- 📚 **Folder README Convention — Unix Theory P7.** Folder READMEs added for every PHP-bearing subdirectory across `includes/` and `addons/pro/includes/`. Agent context docs (`CLAUDE.md`, `AGENTS.md`) synced with recent features.
- 🔧 **Build & CI.** `build-spa-addons` GitHub Actions workflow added. Missing SPA addon ZIPs restored. All SPA bundles rebuilt.

**Privacy & Terms Notice:** This plugin connects to external AI services. Review each provider's policies:
- **OpenAI**: [Terms](https://openai.com/policies/terms-of-use) | [Privacy](https://openai.com/privacy)
- **Google Gemini**: [Terms](https://ai.google.dev/terms) | [Privacy](https://ai.google.dev/privacy)  
- **Anthropic**: [Terms](https://www.anthropic.com/legal/consumer-terms) | [Privacy](https://www.anthropic.com/legal/privacy)
- **Cloudflare**: [Terms](https://www.cloudflare.com/terms/) | [Privacy](https://www.cloudflare.com/privacypolicy/)
- **Hugging Face**: [Terms](https://huggingface.co/terms-of-service) | [Privacy](https://huggingface.co/privacy)
- **Ollama**: Self-hosted (no external data transmission)
- **OpenRouter**: [Terms](https://openrouter.ai/terms) | [Privacy](https://openrouter.ai/privacy)
- **DigitalOcean Serverless Inference**: [Terms](https://www.digitalocean.com/legal/terms-of-service) | [Privacy](https://www.digitalocean.com/legal/privacy-policy)
- **DeepSeek**: [Terms](https://platform.deepseek.com/downloads/DeepSeek%20Terms%20of%20Service.html) | [Privacy](https://platform.deepseek.com/downloads/DeepSeek%20Privacy%20Policy.html)
- **NVIDIA NIM**: [Terms](https://www.nvidia.com/en-us/agreements/enterprise-software/product-specific-terms-for-ai-products/) | [Privacy](https://www.nvidia.com/en-us/privacy-center/)
- **LM Studio**: Self-hosted (no external data transmission)
- **Baseten**: [Terms](https://www.baseten.co/terms-of-service) | [Privacy](https://www.baseten.co/privacy)

See the complete [External Services Reference](docs/reference/EXTERNAL_SERVICES.md) for all 20 services.  

The plugin works standalone with **~195 base tools** and optionally extends through the **Pro addon**, which adds **~830+ Pro tools** for advanced integrations (WooCommerce, JetEngine, social media APIs, GitHub, Google services, Shopify, QuickBooks Desktop, Yahoo Fantasy Sports, ESPN Fantasy, ECA management, CRE Debt & Securitization, Cloudways server management, CRM lead/deal/customer lifecycle, support ticket management, multichannel inbound/outbound messaging) and exec-based tools (FFmpeg, WP-CLI, Python rembg, Jukebox), bringing the total to **~1,025+ built-in tools** (~195 base + ~830+ Pro; live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative).

> **Note on Tool Count:** Tools include base WordPress operations, content management, media generation, research capabilities, and optional third-party integrations. The base version (~195 tools) works standalone. The full version requires the Pro addon and provides ~1,025+ total tools including specialized toolkits for e-commerce, social media, analytics, document generation, vehicle estimation, image validation, JetEngine MCP, A2A agent delegation, CRE Debt & Securitization, Cloudways infrastructure management, CRM lead/deal/customer lifecycle + support tickets + multichannel, MCP Apps, and more. Live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative.

**Addon Ecosystem:** NV oOS ships a growing family of 24 installable addons: **Pro** (`addons/pro/` — ~830+ additional tools), **Chat SPA** (`addons/chat-spa/` — React chat replacement), **Docs Hub** (`addons/docs-hub/` — in-site documentation SPA), **SaaS Controller** + **Cloud Worker** (`addons/saas-controller/` + `addons/cloud-worker/` — NV oOS Cloud control plane), **Cloudways Dashboard** (`addons/cloudways-dashboard/` — Cloudways server management), **Toolkit Shell / Canvas / Canvas Toolkit / Document Editor / Media Studio** (`addons/toolkit-shell/` etc. — Toolkit SPA Blueprint Tier A–D), **Graphify** (`addons/graphify/` — knowledge graph), **Comic Reader** (`addons/comic-reader/` — CBR/CBZ/CB7/CBT reader), **Funiq Bridge** (`addons/funiq-bridge/` — Payload-to-WordPress bridge with React SPA), **AI Platform** (`addons/ai-platform/` — AI platform admin dashboard + CPTs), **LibreChat** (`addons/librechat/` — code interpreter, speech, web search reranker), **Schedule Anything Platform + SPA** (`addons/schedule-anything-platform/` + `addons/schedule-anything-spa/` — SaaS booking with Stripe), **Tenant Router** (`addons/tenant-router/` — multi-tenant routing), **Page Agent** (`addons/page-agent/` — AI-powered browser page control copilot), **Algorave**, **Cornerstone3D**, **Embedded**, **Fantasy Football**. Separate standalone plugins: **NVOOS Graphify** (`plugins/nvoos-graphify/` — visual knowledge graph), **NVOOS Graphify AI** (`plugins/nvoos-graphify-ai/` — AI providers + chat + RAG), **NVOOS Graphify AI Platform** (`plugins/nvoos-graphify-ai-platform/` — agents, A2A, blueprints, skills). See [`docs/developer/addons/toolkit-spa-blueprint.md`](docs/developer/addons/toolkit-spa-blueprint.md) for the blueprint all SPA addons follow.

### 🎯 Mission: Modernizing Small to Medium Business Websites

**NV oOS** is specifically designed to help **small to medium-sized businesses** fast-track their outdated, stale, or insecure company websites to modern technology standards—**without the need to add yet another wrapper around API calls**. Instead, we're trying to **peel back decades of API wrappers with the help of AI**, providing:

- **Direct AI Integration** - No middleware required. Connect directly to OpenAI, Gemini, Anthropic, Hugging Face, Cloudflare Worker AI, Ollama, LM Studio, OpenRouter, and DeepSeek without custom development
- **Security-First Architecture** - Built-in protection against nefarious usage with active monitoring and prevention systems
- **Enterprise-Grade Features** - Access to capabilities typically requiring expensive custom development
- **Compliance & Audit Tools** - Comprehensive logging, rate limiting, and usage tracking built-in
- **Zero Technical Debt** - Modern codebase following WordPress standards, ready for current technology stacks

### 🛡️ Active Security Monitoring

**NV oOS actively prevents and monitors against nefarious behavior**. The plugin includes:

- **Nefarious Usage Monitor** - Real-time detection of suspicious patterns and automatic emergency shutdown capabilities【F:includes/class-wp-mcp-ai-nefarious-usage-monitor.php†L1-L676】
- **Root Security Key** - Optional emergency authentication layer to prevent unauthorized reactivation after security incidents【F:docs/features/security/root-security-key.md†L1-L511】
- **Granular Capability Controls** - Every tool and API endpoint enforces WordPress capabilities to prevent unauthorized access
- **Rate Limiting** - Built-in protection against abuse with configurable limits per user, model, and time period
- **Comprehensive Audit Logging** - Track all API calls, tool executions, and security events for compliance and forensic analysis
- **Input Sanitization & Output Escaping** - All user input sanitized, all output escaped following WordPress security best practices

**This is not a tool for circumventing security or promoting bad practices.** Every feature is designed with security, transparency, and responsible AI usage as core principles. The plugin actively works to stop and prevent misuse before it happens.

**Latest audit:** See [`docs/operations/compliance/SECURITY_AUDIT_2026_04.md`](docs/operations/compliance/SECURITY_AUDIT_2026_04.md) — the published summary of the April 2026 security & compliance code review (no Critical findings; 5 High items, 3 Fixed and 2 Partially Fixed). Full deliverables under [`docs/project/audits/2026-04/`](docs/project/audits/2026-04/).

**WordPress.org compliance hardening (May 9, 2026):** [`docs/operations/compliance/WORDPRESS_ORG_COMPLIANCE_2026_05_09.md`](docs/operations/compliance/WORDPRESS_ORG_COMPLIANCE_2026_05_09.md) — findings B3, B8, B10, B13, and production vendor remap all resolved.

### ⚠️ Warranty & Safe Use

> **We make every effort to keep NV oOS safe and secure — but by design, it can be destructive and resource-intensive when not properly configured.**

NV oOS grants AI assistants access to powerful WordPress operations. The same capability that automates real work can cause irreversible harm if misconfigured:

- **Destructive tools** — bulk content deletion, user management, file writes, mass email, WP-CLI, direct database operations
- **API billing exposure** — uncapped AI provider calls can exhaust quotas and trigger unexpected charges
- **Server resource exhaustion** — concurrent agentic loops and SSE streams can saturate CPU/memory on shared hosting

**Before going live:** test on staging, take verified backups, apply least-privilege tool permissions, enable rate limiting, and review the system prompt of every public-facing assistant.

📄 **Full details:** [`WARRANTY.md`](WARRANTY.md) — security commitment, "AS IS" disclaimer, destructive-operations table, resource-consumption guide, and mitigation checklist aligned with OWASP, NIST SP 800-53, ISO/IEC 27001, and the WordPress Plugin Developer Handbook.

## Patent Pending

**NV oOS is the subject of a pending patent application** for its novel **System and Method for Dynamic AI Orchestration Layer with Real-Time Capability Gating and Resource Budgeting**.

**Application Number:** 19/410,504

The patent covers NV oOS's innovative approach to implementing sophisticated AI orchestration in WordPress's request-based PHP architecture—a platform not designed for real-time streaming, asynchronous operations, or persistent state management. This technical achievement enables enterprise-grade AI capabilities on WordPress by recreating event-driven behavior within PHP's synchronous execution model.

**Key Innovations Covered:**
- Dynamic resource budget allocation during streaming operations
- Capability-based access control for AI tool execution
- Registry-state-based scheduling in stateless environments
- Metrics-driven budget adjustment for real-time optimization
- Persistent-behavior illusion in request-based architectures

The orchestration layer makes NV oOS unique in the WordPress ecosystem by solving fundamental architectural limitations that prevent traditional WordPress plugins from supporting advanced AI features. See the [System Architecture](#-system-architecture) section below for technical details on how these innovations work together.

## 🏗 System Architecture

NV oOS implements a comprehensive orchestration layer for managing AI operations during real-time streaming events. The system architecture comprises:

- **15 language-model providers** — OpenAI, Gemini, Anthropic, DeepSeek, OpenRouter, Baseten, Kimi (Moonshot), Z.AI (GLM), DigitalOcean, NVIDIA NIM, Cloudflare Worker AI, Ollama, LM Studio, Hugging Face, Flowhub
- **~990 tool classes** (~195 base + ~795 Pro; live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative) registered through a singleton Tool Registry
- **36 REST controllers** (16 base + 20 pro) under the `mcp-ai/v1` namespace
- **64 service classes** powering orchestration, budgets, and workflows
- **5 authentication methods** — WordPress nonce, assistant credentials, mesh keys, Auth0 JWT, guest tokens
- **Toolkit MCP servers** — per-toolkit JSON-RPC 2.0 servers exposed under `/wp-json/mcp-ai-pro/v1/mcp/{slug}`; discoverable at `/.well-known/mcp`
- **8 inline-async-tick consumers** — cooperative tick-lock pattern eliminates WP-Cron startup latency for background jobs (transcript mining, async tool executor, SaaS Apply, Crawl4AI, Docs Hub rebuild, Graphify reindex, Harness eval, Gemini Veo polling)
- **7 LLM Harness layers (+ 1 Pro)** — opt-in epistemic layers A–H activated per-assistant via the **LLM Harness** metabox
- **Orchestration Phases 1–7** — HITL approval queue, prompt-injection detector, structured output, OTel exporter, DAG builder, durable runs, triggers/webhooks, sub-agents

> **📖 For a detailed explanation of how NV oOS extends standard SSE and MCP protocols with novel orchestration features, see [ORCHESTRATION-LAYER-ARCHITECTURE.md](docs/developer/architecture/orchestration/ORCHESTRATION-LAYER-ARCHITECTURE.md)**

### Core Orchestration Layer: Overcoming PHP's Limitations

**Critical Context:** Most real-time AI streaming systems are built with Node.js, Python FastAPI, or Go — platforms designed for asynchronous, event-driven operations. These platforms natively support:
- Long-lived connections and persistent state
- Non-blocking I/O and parallel execution
- Event loops and asynchronous callbacks
- WebSocket protocols and SSE streaming

**NV oOS achieves the same capabilities in PHP/WordPress** — an environment fundamentally not designed for these patterns — through a sophisticated **orchestration layer** that creates a "persistent-behavior illusion":

1. **Real-Time Budget Enforcement** - Monitors token/memory usage during streaming, prevents exhaustion through predictive allocation
2. **Capability-Based Tool Gating** - WordPress role-based access control for AI tool execution  
3. **Predictive Optimization** - Analyzes usage patterns to prevent resource overruns before they occur
4. **Distributed Orchestration** - Multi-provider support with policy-aware routing
5. **Auditability & Compliance** - Complete governance layer with logging and rate limiting
6. **Cron-Based Task Orchestration** - Extends orchestration to async operations with budget inheritance

### Multi-Agent Orchestration Enhancement (DeepSeek V4-Inspired)

**Added:** January 2026 (v1.1.0)

Building upon the core orchestration layer, NV oOS now includes a **sophisticated multi-agent coordination framework** inspired by DeepSeek V4's orchestration patterns:

**Key Components:**
- **Agent Role System** - Four specialized roles (Planner, Executor, Critic, Specialist) with role-specific capabilities
- **Team Composition** - Automated team assembly based on task requirements and profession expertise
- **Coordinated Workflows** - Multi-step workflows with agent delegation, result aggregation, and validation
- **Team CPT Integration** - Persistent team configurations with orchestration modes (single/sequential/parallel/swarm)
- **Profession-Based Discovery** - 296 professions auto-assigned agent roles via intelligent seeding across 17 knowledge bases

**Example Multi-Agent Workflow:**
```php
// 1. Compose research team (planner + executors + critic)
$orchestrator = new WP_MCP_AI_Agent_Team_Orchestrator();
$team = $orchestrator->compose_team( array( 'task_type' => 'research' ) );

// 2. Execute coordinated workflow
// Planner decomposes task → Executors research subtasks → 
// Communication service aggregates → Critic validates quality
$result = $orchestrator->execute_team_workflow( $team, $task, $context );
```

**Documentation:**
- See [Multi-Agent Orchestration](docs/developer/architecture/orchestration/ORCHESTRATION-LAYER-ARCHITECTURE.md#-6-multi-agent-orchestration-deepseek-v4-inspired-enhancement) for complete technical details
- See [DEEPSEEK-V4-README.md](docs/reference/models/DEEPSEEK-V4-README.md) for documentation suite overview  
- See [DEEPSEEK-V4-USAGE-GUIDE.md](docs/reference/models/DEEPSEEK-V4-USAGE-GUIDE.md) for practical examples

### Why This Architecture Is Novel: Overcoming PHP's Limitations
- Event loops and background workers

**PHP/WordPress, by contrast, is fundamentally request-based:**
- Every HTTP request spawns a new process that dies after responding
- I/O operations block execution
- No persistent memory between requests
- No native event loop or async coordination

**NV oOS solves this** by implementing an orchestration layer that creates a "persistent-behavior illusion" — effectively **recreating Node.js's event loop behavior within WordPress's synchronous, request-based architecture**. This architectural compensation is the system's core technical innovation:

| PHP Limitation | NV oOS Solution |
|----------------|-----------------|
| No persistent state | Registry & policy engine maintain state via database/cache |
| No event loop | Cron Manager extends orchestration across time-shifted operations |
| Blocking I/O | Predictive budget allocator prevents blocking operations |
| Request-based lifecycle | SSE controller implements streaming within request boundaries |
| No background workers | WordPress cron system simulates async job processing |

This makes NV oOS patent-worthy as a **technical workaround** — it achieves sophisticated AI orchestration in an environment specifically not designed for such patterns. See [ORCHESTRATION-LAYER-ARCHITECTURE.md](docs/developer/architecture/orchestration/ORCHESTRATION-LAYER-ARCHITECTURE.md) for the complete technical analysis.

### Computer-Implemented Resource Management

The system operates as a computer-implemented method executing on a processor with memory, performing:

1. **Dynamic Resource Budget Allocation**: The orchestration layer dynamically allocates token and memory budgets to tool execution requests based on real-time system capacity and operation requirements. The `WP_MCP_AI_Resource_Manager` continuously monitors server resources (PHP memory limits, execution time constraints) and automatically adjusts operational parameters.

2. **Capability-Based Access Control**: Tool execution endpoints enforce granular capability-based access controls. Each tool in the registry declares required WordPress capabilities, and the REST API controller validates user permissions before allowing execution. This ensures secure, policy-driven access to all operations.

3. **Registry-State-Based Scheduling**: The `WP_MCP_AI_Tool_Registry` maintains tool availability state and schedules execution based on policy constraints. Tools are loaded conditionally based on dependency availability, and execution is scheduled according to assistant configuration and user permissions.

4. **Metrics-Driven Budget Adjustment**: The system continuously monitors execution metrics (memory usage, API response times, token consumption) and adjusts resource budgets in response to prevent resource exhaustion and reduce latency. The `WP_MCP_AI_Token_Budget_Manager` implements safety margins and dynamic chunking to prevent API limit overruns.

### System Components

The system comprises a processor and memory storing instructions that:
- Monitor real-time resource availability through PHP runtime introspection
- Enforce capability checks at REST endpoint boundaries
- Schedule tool execution through a centralized registry
- Adjust token and memory budgets based on detected system metrics
- Maintain operation logs for audit and optimization

This architecture is embodied in non-transitory computer-readable media (PHP source files) that, when executed by a web server processor, cause the system to perform the complete resource management workflow. The implementation prioritizes stability, security, and efficient resource utilization across diverse hosting environments.

### Symfony Process Integration (December 2025)

NV oOS Pro addon integrates the Symfony Process component for secure external command execution. This modern framework replaces direct `exec()` calls in 6 Pro tools and 2 supporting services, providing:

- **Enhanced Security**: Proper argument escaping and command validation
- **Timeout Management**: Configurable timeouts with graceful handling
- **Better Error Handling**: Comprehensive exception catching and WordPress-friendly error reporting
- **Process Control**: Real-time output streaming and cancellation support

**Migrated Tools & Services:**
- FFmpeg operations (video frame extraction, metadata reading)
- Python rembg (background removal)
- WP-CLI execution
- Meta AI Jukebox (music generation)
- Supporting services for video and audio processing

The Process Service (`WP_MCP_AI_Process_Service`) provides WordPress-friendly wrappers with WP_Error integration, making external process execution consistent with WordPress coding standards.【F:includes/services/class-wp-mcp-ai-process-service.php†L1-L220】【F:docs/history/2025/implementations/symfony-phases/SYMFONY_PHASE2B_PROCESS_INTEGRATION.md†L1-L100】

---

## 🆕 Latest Updates (v1.1.44 — August 2026)

### August 2–4, 2026 — CCT Stability, Proposal 016+017 Hardening, API Key Fixes, Docs 🐛🛡️🔑📝

- ✅ **CCT Stability Fixes (4 PRs).** CCT duplicate registration race condition resolved with database-backed mutex lock (`wp_mcp_ai_cct_registration_mutex` option). FlowHub CCT duplicate registration corrected — second menu title entry removed, registration guard now checks for existing CCT before attempting re-registration. Base plugin fatal error when `lib/core/` is absent (`require_once` of non-existent `PlatformFlushInterface.php`) — now guarded with `file_exists()` check, plugin continues gracefully without core features.
- ✅ **API Key & Provider Fixes (2 PRs).** Gemini API key resolution in video services (`generate_video_via_veo`, `generate_video_via_imagen`) restored after the v1.1.43 credential split moved keys to `wp_mcp_ai_credentials` option. Veo fallback chain now correctly forwards async context parameters (task ID, poll URL) between fallback attempts — fixes silent failures when primary model is unavailable. Omni API endpoint URL corrected. Audio completion hook registration fixed.
- ✅ **Security & Architecture Hardening — Proposal 016 (all waves).** 277 `require_once` calls for class-only files wrapped in `spl_autoload_register` conditional — reduces filesystem stat calls on every request. Autoload class-name heuristic corrected to strip `wp_mcp_ai_` prefix from file names before resolution. Repository-wide phpcs auto-fix applied (trailing commas, blank lines, Yoda conditions, short array syntax). Docker QA container vendor mismatch fixed (runs `composer install --no-dev` inside container). All medium-severity findings addressed (error-log guarding, auto-index detection, debug output gate). 6 low-severity findings resolved (DNS info leak, master-key constant, version-drift alignment, shell-command denylist documentation, orphaned comment removal).
- ✅ **Polling, Queue & Load Balancing Hardening — Proposal 017.** Twelve structural weaknesses across three subsystems: polling timeout inconsistencies resolved with unified 30s cap; queue worker deduplication via `wp_mcp_ai_queue_lock` transient; load-balancer health-check interval standardized to 15s with jitter. Stale `.php.bak` backup file removed (was shadowing the real class file and causing ambiguous resolution).
- ✅ **Deferred Security Items (#5755, items 5–7).** Post meta values escaped via `esc_html()` before rendering in admin tables. Term description output hardened with `wp_kses_post()`. REST response field list filtered to remove internal-only debug keys (`_debug_trace`, `_internal_state`).
- ✅ **Docs & Ecosystem.** `FOR_REVIEWERS.md` updated for v1.1.43: total tool count ~1,500 (~263 base + ~1,232 Pro), 10 security classes, security posture status refreshed. 16 broken cross-reference links fixed across 5 docs files after the July docs reorganization. Graphify ecosystem audit completed: wp.org Plugin Check compliance verified, migration gap analysis documented, chat shortcode integration plan with NV oOS REST endpoints drafted.
- 📦 **Versioning** — bumped to **1.1.44** across all version-bearing files. Pro addon: 1.1.27. Tool count: ~263 base + ~1,232 Pro (~1,500 total; live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative). Provider count: **15** first-class language-model providers. Addon count: **27**. Knowledge base: **311 professions** (12 industry categories).

## 🆕 Previous Updates (v1.1.43 — August 2026)

### July 30–August 1, 2026 — MCP Protocol Upgrade, Security Hardening, OKF v0.2, ICP System, Sync Fixes 🔒🆙📚🎯🔧

- ✅ **Security Hardening v1.1.43 (16 files, 427 additions).** SSRF protection across 7 provider connection handlers (blocks cloud metadata endpoints + private IPs). A2A agent URL SSRF guard with federation peer allowlist. SQL table-name regex validation for injection defense. A2A per-assistant agent cards now require authentication (was publicly exposed). Chat SPA /config and /manifest endpoints changed from `__return_true` to `logged_in_permission`. Missing args schemas on 7 REST endpoints (voice, security center, chat transcripts, A2A webhook). Guest rate-limiting changed from shared counter to IP-based hashed keys (DoS vector closed). 3 tool capability mismatches corrected (`check_site_security`, `delete_cron_job`, `purge_cache`). Centralized defense-in-depth capability gate in Tool Registry. PHP object injection prevention via `safe_unserialize` helper with `allowed_classes => false`. Post meta/term output escaping hardened.
- ✅ **MCP Protocol Upgrade (2026-07-28).** Largest MCP revision since launch — stateless core removes sessions and `initialize`/`initialized` handshake (SEP-2567, SEP-2575). New `server/discover` RPC for capability probing. `_meta` per-request for protocol version, client identity, capabilities. `Mcp-Method`/`Mcp-Name` headers on Streamable HTTP (SEP-2243). TTL/cache-scope on `tools/list` responses (SEP-2549). Legacy client shim preserves backward compatibility. 10 files changed. SSE transport preserved (deprecated per SEP-2596, 12-month off-ramp).
- ✅ **OKF v0.2 Trust-Signal Support.** Indentation-aware recursive descent parser replacing flat frontmatter. Inline YAML mappings, nested object lists, flow sequences. Reader: `get_trust_tier()` (unverified/machine-confirmed/human-reviewed), `is_stale()`, extended `search()` with trust filters. Writer: `validate_bundle()` checks v0.2 fields. New tool: `okf_validate_attestation`. All 6 existing OKF tools surface trust signals. 15 files, 27 standalone smoke tests. Full backward compatibility with v0.1.
- ✅ **Pro Module Registry (Architecture).** `wp_mcp_ai_pro_init()` decomposed from ~625-line monolithic init function into a PSR-4 module registry. Per-module loading with dependency ordering. Graceful degradation when modules or their dependencies are unavailable. Fixes broken require paths that caused `class not found` fatals under certain load orders.
- ✅ **ICP System (Pro CRM Phase G).** Data-driven Ideal Customer Profile system with 7-dimension scoring engine (Firmographic 25%, Technographic 20%, Intent 15%, Engagement 15%, Buying Triggers 10%, Economic Outcome 10%, Negative Signals 5%) — fit+intent separation, behavioral decay, negative disqualifier scoring. 2 new MCP tools: `compute_icp_score` (score Company/Lead CPTs or manual data) and `manage_icp_profile` (CRUD via AI). Admin UI with profile list table and 7-tab weighted editor.
- ✅ **Profession & Playbook Sync Fixes (7 PRs).** Playbook: bulk sync silent failures now report `{synced, errors}` to admin UI. Force-regenerated playbook files no longer incorrectly deleted. Undefined array key `orphaned_attachments` in playbook stats fixed. Test Model page assistant dropdown restored. Profession: duplicate slug detection in knowledge base JSON files (312→311 entries). Title fallback matching prevents `Update` from creating duplicate posts when slugs change between versions. Consumed-post collision fix ensures all unique KB entries create distinct posts (resolves 310-published vs 311-KB mismatch).
- ✅ **Hexagonal Architecture Purity (PlatformFlushInterface).** Last WordPress reference removed from `nvoos/core` library. New `PlatformFlushInterface` contract in `lib/core/`, WordPress adapter in `includes/bridge/`. SseHandler now receives flush interface via constructor injection — zero framework dependencies remain.
- ✅ **Phase 3 Operational Security Hardening.** Audit logger REST route hook corrected from `init` to `rest_api_init`. Security posture signal coverage hardened.
- ✅ **Dependency Updates.** `wp-coding-standards/wpcs` bumped to 3.4.1 for CVE-2026-45293. Dev tooling: replaced hardcoded Intelephense LSP with Phpactor in Zed settings.
- 📦 **Versioning** — bumped to **1.1.43** across all version-bearing files. Tool count: ~201 base + ~830+ Pro (~1,031+ total; live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative). Provider count: **15** first-class language-model providers. Addon count: **27**. Knowledge base: **311 professions** (12 industry categories).

## 🆕 Previous Updates (v1.1.42 — July 2026)

### July 23–29, 2026 — Security Infrastructure, Framework-Agnostic Core, Status Page, Agent Skills, Algorave 🛡️🏗️📡🤖🎵

- ✅ **Security Infrastructure Hardening (4 PRs).** 7 new security infrastructure classes: Request Guard (SSE limits, JSON depth, body size, error verbosity, asset version stripping), Security Posture (21 signals, 0-100 weighted score, A-F grading), Destructive Ops Gate (confirmation gate), URL Guard, Concurrency Guard, Cost Tracker, API Key Store. Site Health checks for cron + security posture. 13 new security unit tests covering API key encryption, auth split-brain, break-glass, credentials expiry, destructive ops, rate limiting, SSE auth/CORS/rate limiting, SSRF protection, tool scope sanity, URL guard, and validated upload.
- ✅ **Security Defaults & Headers Hardening.** CORS configurable origin control (new cors_restricted posture signal, weight 5). Auth brute-force protection with rate limiting (new auth_brute_force_on signal, weight 4). Error verbosity three-tier filtering Safe/Moderate/Debug (new error_verbosity_safe signal, weight 4). Request body size enforcement (new body_size_limited signal, weight 3). OAuth token lifetime admin control, guest scope docs, SSE CORS filter, webhook secret indicator. DICOM PHI auto-redaction filter. Asset version stripping. Centralized exception guard.
- ✅ **Security Dashboard Posture Signals.** Dynamic signal count replaces hardcoded 17. Accurate audit-log empty-state text. CORS origin preview in header tool. Production hardening guide with WAF/OAuth/DICOM checklist.
- ✅ **Framework-Agnostic Core (lib/core/).** nvoos/core package with Hexagonal Architecture (Ports & Adapters), PHP 8.1+, separate composer.json. 32 domain contracts covering chat, caching, content, cost tracking, cron, email, events, files, HTTP, memory, models, OCR, providers, speech, streaming. 21 WordPress adapters. ChatOrchestrator with RateLimiter + SemanticCompressor. ProviderRouter (12-provider routing). Framework-agnostic ToolRegistry + SkillRegistry. 109 tools migrated to nvoos/core format. 5 chat parity gaps closed: finish_reason handling, prompt caching, input sanitization, vision image processing, transcript store contracts.
- ✅ **Status Page & Incident Communication (Pro).** Maintenance window CPT with REST CRUD, frontend banner + countdown timer, email/webhook/channel broadcast notifier. Incident CPT with phase state machine, phase-aware notification dispatcher, incident-to-lesson bridge. 4 new AI tools: get_service_status, create_incident, update_incident, resolve_incident.
- ✅ **Agent Skills & BMAD Agents.** 21 coding-time agent skills (.agents/skills/) for Zed editor covering WordPress plugin development patterns — abilities API, action scheduler, HTML API, i18n audit, plugin architecture, assets loading, bootstrap, cron, DTO, hooks, lifecycle, options storage, presenter, rewrite rules, query cache, REST API, security audit (with reference doc), security deep, security secrets, UTF-8 text. 6 BMAD workflow agent YAML definitions (.bmad/agents/) — analyst, architect, developer, product-manager, qa-engineer, scrum-master — with team composition config. Full .context/ subsystem context system with 8 topic files + 5 templates + active/archive directories.
- ✅ **Algorave Addon.** New standalone addon for live coding and algorithmic music generation. 9 tools: export-midi, generate-music-ai, generate-pattern, midi-output, modify-pattern, play-control, sample-manager, strudel-reference, visualizer. Pattern and session CPTs. REST API. Admin settings.
- ✅ **Critical Bug Fixes.** Request Guard wrap_dispatch parameter order mismatch with WP >= 6.5 rest_dispatch_request filter (was causing fatal error). Nonce authenticator now accepts _wpnonce query parameter in addition to X-WP-Nonce header (fixes 401 on legacy chat client streams).
- ✅ **Dependency Security Updates.** brace-expansion overrides across all addons. js-yaml and postcss security overrides. All npm lock files regenerated. undici pinned to 7.x for Node 20 compat in canvas-toolkit.
- 📦 **Versioning** — bumped to **1.1.42** across all version-bearing files. Tool count: ~201 base + ~830+ Pro (~1,031+ total; live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative). Provider count: **15** first-class language-model providers. Addon count: **27** (new: Algorave).

### July 20–22, 2026 — OKF Integration, Security Compliance, Playbook Sync, Model Credentials, Dependency Bumps 📚🔒🔧🔑🛡️

- ✅ **OKF Integration (Open Knowledge Format v0.1).** Pure-PHP YAML frontmatter parser and bundle reader/writer with atomic concept CRUD and conformance validation. 6 new MCP tools: okf_read_concept, okf_browse, okf_traverse, okf_search, okf_write_concept, okf_delete_concept. All 41 bundled skills now OKF v0.1-conformant. Zero new Composer dependencies.
- ✅ **Security Compliance (11 HIGH/P0 Fixes).** HMAC-signed policy tokens for professional selector with 1h expiry. Health endpoint auth-gating (public response reduced to status only). ZIP path traversal prevention with realpath() containment. CSRF nonces on Shopify/EZuite/FlowHub sync endpoints. CDN SRI integrity hashes for all 6 libraries. Google Chat OIDC hardening with shared-secret token. Privacy imaging path traversal fix. Autoload fallback cleanup. Version alignment across all package.json files.
- ✅ **Playbook Sync Fixes.** Duplicate AJAX handler conflict resolved with nonce-aware cap bypass. CPT class loading guard in remove_duplicate_playbooks(). Silent sync failure detection — sync_all() now returns {synced, errors} surfaced to admin UI. Profession prompt regression fixed after HMAC refactor.
- ✅ **Model Provider Credential Resolution.** Model_Service now resolves API keys from all 4 sources — settings, credentials option, environment variables, and PHP constants — via Credential_Resolver.
- ✅ **Dependency Security Bumps.** adm-zip >=0.6.0 (ZIP CVE), axios >=1.18.0 (6 CVEs), brace-expansion 1.1.16/2.1.2/5.0.7 across 5 package.json files (CVE-2026-13149). npm audit: 0 vulnerabilities. composer audit: 0 advisories.
- 📦 **Versioning** — bumped to **1.1.41** across all version-bearing files. Tool count: ~201 base + ~830+ Pro (~1,031+ total; live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative). Provider count: **15** first-class language-model providers. Addon count: **26**.

## 🆕 Previous Updates (v1.1.40 — July 2026)

### July 12–15, 2026 — Content Format Awareness, Research Pipeline, Settings Credential Split, Model Catalog, Provider Parity, SSE Fixes

- ✅ **Content Format Awareness.** New `WP_MCP_AI_Content_Format_Helper` class (585 lines) detects and preserves Markdown, HTML, and plain text formats across post-modifying and analysis tools. Full 582-line test suite.
- ✅ **Research → Paper Store → WordPress Draft Pipeline.** All research tools support `save_to_paper_store` parameter. New `create_post_from_research` Pro tool. New action hooks. Backward-compatible.
- ✅ **Settings Credential Split.** Sensitive API keys moved to separate non-autoload `wp_mcp_ai_credentials` option with transparent merge via `get_settings()`. One-time migration. Defense-in-depth with `wp_suspend_cache_addition`. Settings import/export batch fixes (4 PRs).
- ✅ **Demo Video Pipeline Complete (Phases 0–5).** Scripted scene recording with AI voiceover. GitHub Actions CI workflow. 14 narration scripts.
- ✅ **Kimi & DeepSeek Client Parity.** Full streaming, tool use, and token tracking parity. DeepSeek and 9 missing providers added to all research tools.
- ✅ **Model Catalog Update (July 2026).** 24 files across base + pro. Default bumps: Gemini `gemini-2.5-flash` → `gemini-3.5-flash`, NVIDIA `meta/llama-3.1-8b` → `nvidia/nemotron-3-nano-30b-a3b`, Gemini Live `gemini-2.5-flash-live` → `gemini-3.1-flash-live-preview`.
- ✅ **OOS Engine: SchemaStoreInterface + Tests.** New domain contract, entities, tool, and WordPress adapter. 45 new unit and integration tests.
- ✅ **SSE HTTP/2 Fixes.** `ob_clean()` replaces `ob_flush()`. 524 timeout resolved in Pro SPA v2.
- ✅ **Vector Store Sync No Polling.** Status checked only on assistant change and page load.
- ✅ **Validated Tool Slug Allowlist** fix (PR #5680).
- 📦 **Versioning** — bumped to **1.1.40** across all version-bearing files. Tool count: ~195 base + ~830+ Pro (~1,025+ total; live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative). Provider count: **15** first-class language-model providers. Addon count: **26**.

## 🆕 Previous Updates (v1.1.39 — July 2026)

### July 10–13, 2026 — Meta-Harness Auto-Optimization, Agent Delegation Rework, Pro SPA v2 Polish, Tool Presets Refactor, CRM Enhancements 🧠🔄💬🎛️📊

- ✅ **Meta-Harness Auto-Optimization System (All 7 Phases).** New self-improving agent infrastructure: Trace Store + Trace Capture for execution telemetry (Phases 0-1); Harness Search Engine for telemetry querying (Phase 2); Pro Coding-Agent Proposer for automated improvement suggestions (Phase 3); Cues, Population, Auto-Deploy, and DSpark orchestration (Phases 4-6); comprehensive test coverage (Phase 7). Enables plugins to observe, analyze, and self-optimize AI agent execution.
- ✅ **Agent Delegation — Major Rework.** Delegation now runs inline instead of async for immediate results. REST-based dispatch via chat endpoint replaces no-op role executor. Cron resilience: delegation jobs no longer silently fail without retry. `spawn_cron()` added after `wp_schedule_single_event` for instant deferred job execution. Name-based agent resolution in `delegate_to_agent` tool. Wire-up and tasks drawer fixes in SPA v2.
- ✅ **Pro SPA v2 — Polish & Fixes (20+ PRs).** Vector store indicator and slash autocomplete positioning (PR #5666). Double path in vector store preload URL fixed (PR #5665). TDZ crash in CommandAutocomplete resolved (PR #5664). Inline command autocomplete and Zed-style refresh (PR #5663). Cost badges restored in response UI (PR #5661). `allowSensitiveTools` config propagated to chat-spa, Pro SPA v2, and delegation dispatch (PR #5658). Tool result rich rendering, sidebar auto-refresh, and media cache-busting (PR #5651). Auto-save transcripts on `onFinish` callback (PR #5650). Attachment support, save button, and storage display (PR #5646). Tasks drawer toolbar button with failedCount badge (PR #5642). Speech/audio tool response envelope fix (PR #5636). Capability flags rendering fix (PR #5635). Usage badges and image+text rendering fix (PR #5634). Sidebar media panel visibility and ID column (PR #5633). Media insert button + speech button reposition (PR #5632). System prompt leak, sidebar empty state, media-to-chat bridge (PR #5631). Tool display and conversation duplication fix (PR #5626). Composer mobile bottom padding (PR #5625). Media grid flexbox layout fix (PR #5624).
- ✅ **Tool Presets Refactor.** Essentials layers added, duplication stripped, auto-upgrade for validated variants (PR #5660). Validated tool auto-upgrade no longer causes duplicate names and not-allowed errors (PR #5662). Double tool execution fixed in SSE adapters with media refresh filter clear (PR #5652). `tool_call_id` fallback for DeepSeek streaming and SPA v2 tool results (PR #5638). Save button added, tool messages without `tool_call_id` stripped (PR #5648). Tool message block indentation auto-fixed (PR #5638).
- ✅ **CRM Enhancements.** CRM cache loop fixed (PR #5645). Upwork rate limiting (PR #5645). Freelance Platforms & External Sourcing configuration enhanced (PR #5639).
- ✅ **Infrastructure.** Veo 2.0 deprecated — Gemini Omni Flash replacement (PR #5667): migration path, deprecation detection preventing wasteful 404 fallback loops, user-facing error messages recommending `gemini-omni-flash` (10s duration, native audio, multi-turn editing), cost calculator updated, tool schema defaults updated, admin settings updated, tests updated. Workflow auth and protected method errors fixed (PR #5659). ZAP scan Docker network isolation resolved (PR #5637). npm packages rebuilt and built assets updated (PR #5653).
- ✅ **Documentation.** Comprehensive OpenMed integration plan v2 added (PR #5641).
- 📦 **Versioning** — bumped to **1.1.39** across `mcp-ai-wpoos.php`, `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `readme.txt`, `README.md`, `CHANGELOG.md`, `QUICK_REFERENCE.md`, `ROADMAP.md`, and `DOCUMENTATION_INDEX.md`. Tool count: ~195 base + ~830+ Pro (~1,025+ total; live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative). Provider count: **15** first-class language-model providers. Addon count: **26**.

## 🆕 Previous Updates (v1.1.38 — July 2026)

### July 10, 2026 — Page Agent Addon, Pro SPA v2 Parity, User Memory Toggle, Tool Enhancements, Workflow Improvements 🤖💬👤📝🔄

- ✅ **Page Agent Addon v0.1.0.** New addon at `addons/page-agent/` — AI-powered browser page control copilot powered by Alibaba Page Agent (MIT). Gives any WordPress page its own AI agent that can click, type, and navigate via natural language. Runs entirely client-side with no headless browser, Python, or Chrome extension required. Includes shortcode `[mcp_ai_page_agent]`, Elementor widget, REST endpoints, MCP tool bridge, and admin settings page.
- ✅ **Pro SPA v2 — Major Parity & Polish.** Voice pipeline, tasks drawer, workflow tracker, and file attachment upload to WordPress Media Library. Tool Shortcuts and Slash Commands drawers for quick tool access. Mobile hamburger sidebar toggle. Speech/audio button fixes using correct ToolsClient REST endpoint. Conversation title improvements and turn count display fix. Button and token NaN fixes. Model sync and auth bypass fixes. Assistant preloading in runtime config to fix sidebar loading.
- ✅ **Pro SPA v2 — UI Polish.** Autoscroll fixes (scroll-to-bottom on submit, streaming start, user-at-bottom guard, restored via direct scrollTop instead of scrollIntoView). Viewport height fixes via CSS height chain instead of `calc(100vh - Xpx)`. `overflow:hidden` on height-chain ancestors. `filemtime`-based cache-busting across all SPA addons. Lint errors resolved in both chat-SPA and pro-SPA. Deduplicated model selector to prevent React duplicate-key warning. REST route registration fixed and production assets rebuilt.
- ✅ **Per-User Chat Memory Preferences.** Users can now toggle chat memory on/off from their WordPress user profile. Individual control over AI memory retention without affecting site-wide defaults. Settings page integration.
- ✅ **create_post / save_post Tool Enhancements.** Markdown-to-HTML conversion via new `WP_MCP_AI_Tool_Markdown_Converter` trait — auto-converts Markdown content to HTML when creating or updating posts. Smart taxonomy suggestions auto-detect relevant categories and tags. Block content corruption fix for non-post post types.
- ✅ **Workflow Blueprint & Schedule Improvements.** Existing-content awareness in Content Publisher and Keyword Pipeline blueprints. Blog schedule presets now check for duplicate content before publishing. Readable response generation for workflow schedule result delivery.
- ✅ **SPA Accessibility.** Annotation pills made clickable with meaningful labels for screen readers.
- ✅ **Security.** OWASP ZAP DAST medium findings triaged as false positives.
- 📦 **Versioning** — bumped to **1.1.38** across `mcp-ai-wpoos.php`, `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `readme.txt`, `README.md`, `CHANGELOG.md`, `QUICK_REFERENCE.md`, `ROADMAP.md`, and `DOCUMENTATION_INDEX.md`. Tool count: ~195 base + ~830+ Pro (~1,025+ total; live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative). Provider count: **15** first-class language-model providers. Addon count: **26**.

## 🆕 Previous Updates (v1.1.37 — July 2026)

### July 4–8, 2026 — JetEngine Meta Helper, Places Enrichment, RabbitMQ, Multi-Tenant DB, DSpark UI, Test Coverage, Docs Hub Broken Link Engine 🧩📍🐇🏢📊🧪🔗

- ✅ **JetEngine Meta Helper (Universal).** Unified REST exposure for all 25 Pro CPTs — `show_in_rest` and `register_post_meta` applied across the board. ECA `_eca_*` and `_student_*` fields registered in the JetEngine meta registry. MCP routing and error reporting bugs fixed. Load-order fix ensures the helper is available before dependent toolkit inits.
- ✅ **Places Toolkit Enrichment.** Two new AI tools — `enrich_place_coordinates` (batch geocoding) and `enrich_place_details` (Google Places API enrichment). Social media and booking fields added to the Places CCT schema. CLI bin scripts for batch enrichment operations. HTTrack import hardened: redirect stub handling, coordinate parsing, parent-child linking, and CLI parameter validation.
- ✅ **RabbitMQ Client & Queue Infrastructure.** RabbitMQ client wired with queue manager and async tool interception. Queue storage migrated from WordPress options to custom database tables for scalability. Health endpoint for queue monitoring. Dedicated queue worker for background processing.
- ✅ **Multi-Tenant Database Isolation (Phase 0–4 Complete).** Phase 0 laid the foundation with database isolation primitives and connection routing. Phases 1–4 delivered query scoping, schema isolation, cross-tenant safety guards, and admin controls — the full isolation stack is now operational.
- ✅ **DSpark Admin UI & Speculative Orchestration.** New admin page with settings, threshold configuration, efficiency dashboard, presets, and a hook system for extensibility. Speculative orchestration enhancements with configurable execution strategies.
- ✅ **Crocoblock Design System Addon (All 5 Phases).** New standalone addon at `addons/crocoblock-ds/`. Unified CSS custom properties, preset templates, and admin-controlled theming for JetEngine, JetSmartFilters, and JetFormBuilder. Registered in addon inventory and build pipeline.
- ✅ **Test Coverage — 329 Tools Across 28 Toolkits.** 301 previously untested tools now covered by batch tests. 6 previously untested pro toolkits with full coverage. 22 toolkits covered across 7 batch test files. HTTP testing infrastructure for remote tool tests. Docker plugin seed service for integration test coverage. CRM, PM, DietPi, and Cloudways test suites hardened with recursive file search, dependency resolution, and graceful skipping for abstract/missing classes.
- ✅ **Docs Hub Broken Link Detection & Repair Engine.** Automated broken link scanning with results table. One-click Accept fix buttons on the detail table. Suggestion engine regression fixed (was producing no suggestions).
- ✅ **SPA Annotation Pills — Accessible.** Clickable pills with meaningful labels for screen readers.
- ✅ **CI/CD Hardening.** OWASP ZAP DAST security scanning integrated into the CI pipeline. PHP bumped from 8.1 to 8.2 across all CI workflows. QA Docker setup fixed (unnecessary chmod on bind-mounted script).
- ✅ **Webhook & Agentic-Loop Resilience.** Proactive agentic-loop context compaction prevents context overflow during long-running agent sessions. Webhook Context Manager enhanced with industry best practices. Sliding window context loss fixed across all 11 webhook controllers. `max_history_messages` section default normalized to match base settings (8).
- ✅ **Shopify Sync — Catalog API & Minimal Mode.** Catalog API sync support for product caching. Minimal sync mode (title, SKU, stock levels only) for fast updates. Price field added to minimal mode payload.
- ✅ **BME Chat History Context Strategy.** RAG memory integration for chat history. Settings relocated to General → Behavior subtab on the new dashboard.
- ✅ **Bug Fixes.** Shopify Catalog API silent failures and result reporting. Recurring sync stuck at old interval with missing connection IDs. Sync log batching via deferred writes to avoid per-item DB timeouts. Webhook auto-reply broken by missing Webhook Context Manager require. Import-blueprints and remaining-pro-tools file discovery. Extended-cognition toolkit missing requires for trait and interface. Tool tests: static data providers, constructor mocks, slug overrides, WP_Error returns. js-yaml DoS vulnerability override. ESLint and WPCS compliance across test files and enrichment tools.
- 📦 **Versioning** — bumped to **1.1.37** across `mcp-ai-wpoos.php`, `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `readme.txt`, `README.md`, `CHANGELOG.md`, `QUICK_REFERENCE.md`, `ROADMAP.md`, and `DOCUMENTATION_INDEX.md`. Tool count: ~195 base + ~830+ Pro (~1,025+ total; live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative). Provider count: **15** first-class language-model providers.

## 🆕 Previous Updates (v1.1.36 — July 2026)

### June 29 – July 4, 2026 — EZuite Inventory Sync, Ralph Loop Orchestration, JetBooking/JetAppointment, Moonshot/Z.AI Parity, 45+ Bug Fixes 🏭🔄📅🧠🐛

- ✅ **EZuite Inventory Sync Pro Toolkit.** ERP-integrated inventory sync bridging EZuite ERP with WordPress/WooCommerce. Product pull, inventory query, item create/update, ERP settings, and CLI sync commands. Admin UI with connection selector, field mapping, and sync direction controls. Toolkit registered in Pro addon with auto-discovery.
- ✅ **Ralph Loop CCT Migration & Orchestration.** Circuit breaker pattern with configurable failure thresholds prevents cascading failures during cross-environment operations. Execution logger with step-by-step tracking and error reporting. CCT migration tools for safe JetEngine data transfers. Orchestration tools for coordinating multi-step workflows.
- ✅ **JetBooking/JetAppointment Integration (8 Tools).** Adapter layer for Crocoblock JetBooking and JetAppointment plugins. 8 new AI tools added to the Calendar Booking toolkit. 4 existing calendar tools enhanced with booking/appointment awareness. Full CRUD for appointments, bookings, and availability queries.
- ✅ **Moonshot AI (Kimi) & Z.AI (GLM) Provider Parity.** Both providers upgraded to full DeepSeek-level chat client capabilities — streaming, tool use, token tracking, and error handling. ZAI client service registered in DI container alongside `client.baseten`. Class loader updated for new provider adapters. WPCS formatting auto-fixed across all provider client files. Provider count now **15** first-class.
- ✅ **Unified Sync Log Manager.** Per-item audit trail across EZuite, FlowHub, and Shopify sync toolkits. Sync history with timestamps, status codes, and detailed error tracking. Status dashboards on toolkit settings pages. WP-CLI integration for log querying and export.
- ✅ **Tool Presets Auto-Select & Chips Bar.** Selected tools display as clickable chips below the tool selector with proper spacing. +N overflow toggle when too many chips are selected. Tool payload cap raised from 50 to 100 tools per assistant. Method-existence guard against tools lacking `get_definition()`.
- ✅ **HTTrack Cache Support & Place-to-Service Bridge.** HTML place import tool now supports HTTrack cache directories for proper mirror parsing. URL backfill for mirrors without `hts-cache` directory. Place-to-Service bridge auto-creates bookable services during batch place import. Service sharing prevented when source URLs collide.
- ✅ **FlowHub Per-Connection Overview.** Remote Sites connection selector added to FlowHub config tab. Per-connection sync controls — run sync for individual connections instead of all-at-once. Proxy support via `http_api_curl` hook with connection-level proxy resolution.
- ✅ **Generate Default Mapping + Read-Only Sync.** One-click default mapping buttons for EZuite and FlowHub field mapping. Read-only sync direction option — pull data without pushing changes back to source.
- ✅ **Web Search 429 Retry.** Exponential backoff for rate-limited (HTTP 429) web search requests with configurable retry count and base delay.
- ✅ **wp_mcp_ai_log Global Helper.** Centralized logging function available globally, defined in `includes/bootstrap/` for availability before class autoloading.
- ✅ **JetEngine CCT Support for Remote WP Connections.** Remote WordPress connections can now query and manage JetEngine CCT records — full CRUD support for remote CCTs via the `remote_wp_connection` tool.
- ✅ **CCT Module API & CRUD Fixes (Cross-Toolkit).** Replaced fragile `jet_engine()->cct` shorthand with canonical `Module::instance()` across EZuite, FlowHub, and Shopify CCT managers. Standardized CRUD using `Factory/ItemHandler` APIs. Added `table_exists()` fallback to `is_cct_available()` for lazy-init environments. Fixed `get_cct_module` false negatives when CCT module is active but not yet loaded.
- ✅ **EZuite Sync Fixes.** Missing return value with error diagnostics. API field mapping response key mismatch. Remote Sites connections not showing in toolkit settings (2 rounds). CCT auto-registration reading from wrong option key.
- ✅ **FlowHub Sync Fixes.** Silent success with 0 records now reports actual counts and errors. Proxy settings now correctly persist in Remote Sites connections. API auth header names corrected. Null CCT module guarded. Dry-run mode uses modules API instead of fragile shorthand. Location ID made optional with primary fallback.
- ✅ **Shopify Sync Fixes.** Catalog API-only connections blocked with clear error. CCT registration lifecycle uses explicit sanitize-update cycle. Meta fields populated in CCT registration. Column add_field routed through `module->manager` instead of raw `jet_engine()->cct`.
- ✅ **CCT Infrastructure Fixes.** Duplicate column errors prevented in `ensure_columns` across all three toolkits. `add_field` API replaced with direct SQL ALTER TABLE. Nonexistent `get_item_by_slug()` replaced with DB query. JetEngine CCT field definitions synchronized when columns missing.
- ✅ **SQLite Meta Cache Explosion Fix.** Unbounded meta cache growth on SQLite-backed sites resolved.
- ✅ **masterminds/html5 Case Collision Fix.** Class file collision on case-insensitive filesystems (Windows/macOS) resolved.
- ✅ **Base-Version Guard Fix.** Guard no longer incorrectly blocks toolkit admin pages in base+pro mode.
- ✅ **HTTrack Import Robustness.** URL resolution, redirect filtering, and type classification. File discovery bugs (SKIP_DOTS, isDot, substring skip-dir matching). Hex-encoded filename support. Non-GNU glob compatibility for subdirectory content. Improved mirror root detection.
- ✅ **Necessity Gate & Safety Profile Fixes.** Request context crash guarded against missing `get_instance()`. Safety profile trait autoload fixed — required from interface.
- ✅ **Auto-Select Compute Fix.** O(n*m) timeout on assistant edit page resolved.
- ✅ **Tool Chip UI Fixes.** PHP parse errors from unescaped double-quotes in inline JavaScript fixed.
- ✅ **Documentation.** Comprehensive abilities registration plan (~1,000 tools as WordPress Abilities). Laravel-scale deployment architecture proposal with Graphify ecosystem cross-references. WP.org submission prep (readme, index.php, canonical envelopes, READMEs). Agent context sweep — `AGENTS.md`, `CLAUDE.md`, `.context/pro-vs-base.md` updated. CHANGELOG v1.1.36 section.
- ✅ **Housekeeping.** Stale build artifacts and toolkit-addons directory cleaned.
- 📦 **Versioning** — bumped to **1.1.36** across `mcp-ai-wpoos.php`, `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `readme.txt`, `README.md`, `CHANGELOG.md`, `QUICK_REFERENCE.md`, `ROADMAP.md`, and `DOCUMENTATION_INDEX.md`. Tool count: ~195 base + ~830+ Pro (~1,025+ total; live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative). Provider count: **15** first-class language-model providers (added Moonshot Kimi & Z.AI GLM at DeepSeek parity).

## 🆕 Previous Updates (v1.1.35 — June 2026)

### June 27–29, 2026 — FlowHub + Shopify Sync Pro Toolkits, Necessity Gate Layer J, Local Voice STT, Remote Site Admin Blueprint, Bulk Import, Fixes 🏪🛒🛡️🎙️🌐

- ✅ **FlowHub Inventory Sync Pro Toolkit (PR #5501).** 6-tool cannabis dispensary management toolkit. FlowHub Products (`flowhub_products`) — query by location, category, brand, strain type. FlowHub Inventory (`flowhub_inventory`) — real-time levels, room/zone filtering, low-stock detection. FlowHub Locations (`flowhub_locations`) — address, contact, operational status. FlowHub Sync (`flowhub_sync`) — scheduled sync with webhook support. FlowHub Analytics (`flowhub_analytics`) — sales trends, top products, category performance. FlowHub Alert Manager — configurable low-stock and compliance alerts. FlowHub Settings (`flowhub_settings`) — API credentials, sync intervals, notifications. Connection resolver trait. Admin UI toggle on Features tab. Folder README at `addons/pro/includes/tools/flowhub/README.md`.
- ✅ **FlowHub P1+P2 Enhancements (PR #5502).** P1: Proxy support via `http_api_curl` hook for environments behind forward proxies. P2: CCT auto-registration — inventory data stored in JetEngine tables. Connection auth fixes: API header names corrected; class-name collision with `WP_MCP_AI_FlowHub_Client` resolved. API key decryption on credential update fixed (PR #5500). `location_id` made optional — defaults to primary location. Null guard against `jet_engine()->cct` for sites without JetEngine.
- ✅ **Shopify Sync Pro Toolkit (PR #5502).** 5-tool bi-directional e-commerce sync: Shopify Products (`shopify_sync_products`) — product sync with variant support. Shopify Orders (`shopify_sync_orders`) — order import with fulfillment tracking. Shopify Inventory (`shopify_sync_inventory`) — real-time stock sync. Shopify Analytics (`shopify_sync_analytics`) — cross-platform reporting. Shopify Settings (`shopify_sync_settings`) — API credential and sync config. Dashboard widget. Connection resolver trait. Admin UI toggle. Tool reference docs at `docs/tools/shopify-sync-toolkit.md` and `docs/tools/flowhub-toolkit.md`. Folder README at `addons/pro/includes/tools/shopify-sync/README.md`.
- ✅ **Necessity Gate Layer J — Irreversibility-Weighted Safety Profiles.** Pre-execution safety layer (`WP_MCP_AI_Necessity_Gate`) scores tool calls by irreversibility risk. Write operations (create/update/delete) assigned risk scores by resource type. Safety profile trait loaded from interface for clean autoload. Request context crash fix: guards against `WP_MCP_AI_Request_Context` lacking `get_instance()`.
- ✅ **Local Voice Embedded STT (PR #5498).** Three pluggable browser-side speech-to-text backends: browser-native Web Speech API, Whisper.cpp (WASM), and Vosk (WASM). Offline-first — STT engines run entirely in the browser with no server dependency. Auto-detection selects the best available backend based on browser capabilities.
- ✅ **Remote Site Administrator Blueprint.** New 22-tool CRM-style blueprint `remote-site-administrator.json` in Site Creator toolkit. Pre-configured with `remote_wp_connection`, full JetEngine suite (11 tools), JetFormBuilder (2), site admin (4 — `install_and_activate_plugin`, `install_and_activate_theme`, `update_option`, `create_post`/`update_post`/`delete_post`), `woo_products`, `generic_rest_api`. Comprehensive system prompt with discovery→schema→execution workflow, tool reference, and 8 safety rules. Temperature 0.3, `manage_options` capability. Auto-discovered by Unified Blueprints page and `import_site_creator_blueprint` tool.
- ✅ **Places & Calendar Bulk Import Tools (PR #5509).** Batch import tools for Places and Calendar Booking toolkits. Places bulk import: batch geocode and store location records. Calendar bulk import: batch create events, appointments, bookings.
- ✅ **CLI site-import Subcommand.** `wp mcp-ai site-import` — multi-phase HTML mirror import for migrating static sites into WordPress. Handles page structure extraction, content mapping, and media sideloading.
- ✅ **Voice Realtime Auto-Detect (PR #5508).** WebRTC realtime voice auto-detection — automatically selects WebRTC or WebSocket transport. Duplicate message fix prevents echoed messages. VAD threshold improvements for better voice activity detection.
- ✅ **Remote Connections Fixes (PR #5499).** WordPress connection case handling normalized. FlowHub and Printful credential storage fixed — credentials correctly encrypted at rest. Printful connection type added to Remote Sites connection types.
- ✅ **Token-Scoped Assistant Resolution (PR #5497).** Token-scoped assistant now preferred over site default in resolver, ensuring bearer-token-authenticated requests use correct assistant config.
- ✅ **User ID Empty Fallback (PR #5495).** `user_id` resolution updated to use `empty()` instead of `isset()` for context fallback, fixing zero/empty-string bypass.
- ✅ **Local Credential Token Mapping (PR #5493).** Local credential token user ID mapping corrected for consistent identity resolution.
- ✅ **Post Type Name Lengths (PR #5484).** Post type names exceeding 20-char WordPress limit now truncated with validation warnings.
- ✅ **OpenAI Image Deprecation Cleanup (PRs #5489-#5491).** Removed deprecated DALL-E models. Applied chat model fallback to `edit_image_via_responses_api`. GPT-5.x chat models for image generation. Defaults and `response_format` handling fixed.
- ✅ **Documentation.** Agent context sweep — `AGENTS.md` (v1.7), `CLAUDE.md` (v2.7), `.context/pro-vs-base.md` updated. CHANGELOG v1.1.35 section.
- ✅ **Housekeeping.** Stale 1.1.34 build zips removed (6 files).
- 📦 **Versioning** — bumped to **1.1.35** across `mcp-ai-wpoos.php`, `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `readme.txt`, `README.md`, `CHANGELOG.md`, `QUICK_REFERENCE.md`, `ROADMAP.md`, and `DOCUMENTATION_INDEX.md`. Tool count: ~195 base + ~810+ Pro (~1,005+ total; live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative). Provider count: **13** first-class language-model providers (unchanged).

## 🆕 Previous Updates (v1.1.33 — June 2026)

### June 20–24, 2026 — WP 7.0 Connectors Credential Integration, nvoos-graphify v1.0.0, Security Fixes 🔌🧩🛡️🔒

- ✅ **WP 7.0 Connectors Credential Integration (PR #5458).** `Credential_Resolver` integrated into all 17 AI client `get_api_key()` methods (Anthropic, Baseten, DeepSeek, DigitalOcean, Gemini, Gemini Live, Google Maps, Hugging Face, Kimi, LM Studio, NVIDIA, OpenAI, OpenAI Realtime, OpenRouter, plus 3 service classes). Fallback chain: WP 7.0 Connectors → plugin settings → environment variables → PHP constants. New `get_key_source()` / `get_key_source_label()` methods added to `Credential_Resolver`. Credential source badges and WP 7.0 Connectors hints rendered in admin settings UI (Settings → Providers). All 13 provider API key field descriptions updated to mention alternative credential sources. Provider diagnostics now show a **Key Source column** indicating where each provider's API key originates. Settings health check counts credentials resolved via the `Credential_Resolver`. 17 Pro addon files updated to use `Credential_Resolver::has_credentials()` / `get_api_key()` for provider selection, OCR routing, vector store, embeddings, speech, and performance checks. All PHPCS-clean with zero new errors.
- ✅ **nvoos-graphify v1.0.0 Release (PR #5456).** Standalone nvoos-graphify plugin released at v1.0.0 — Plugin Check compliant and ready for distribution. nvoos-graphify-ai plugin released at v1.0.0-dev. Fixed 8 output-escaping errors in `Section.php` via `printf()` with `esc_attr()`. Fixed critical `->prepare()` spread-operator bug in `Db::listNodes()` causing runtime fatals. Renamed `vector` column → `embedding_vector` to avoid MariaDB/MySQL reserved-word conflict; bumped DB version. Fixed snake_case→camelCase method calls across tools (`GetNode`, `GetNeighbors`, `QueryGraph`, `GraphStats`), controllers (`SyncRemoteSource`, REST), and cross-plugin integrations (`nvoos-graphify-ai`). Guarded missing Webhook driver and fixed Crypto namespace. Added `message` field to Enricher stub for detectable false-success states. Updated Remote README to reflect stub reality (no drivers, no SSRF, no encryption). Documented actual REST access model: `read` + guest token for reads, `manage_options` for export/write.
- ✅ **Security Dependencies (PR #5457).** guzzlehttp/guzzle 7.10.0 → 7.12.1 (CVE-2026-55568, CVE-2026-55767). guzzlehttp/psr7 2.11.0 → 2.12.1 (CVE-2026-55766). guzzlehttp/promises 2.3.0 → 2.5.0. undici npm override tightened from `>=7.28.0` to `>=8.5.0` to match resolved version and prevent downgrades.
- ✅ **npm Security (PR #5438).** 29 npm security alerts resolved across 14 packages. undici: TLS bypass (CVE-2026-9697) and cache info disclosure (CVE-2026-9678) — override applied to 11 addon lock files. http-proxy-middleware: CRLF injection (CVE-2026-55603) — override `>=3.0.7`. nodemailer: raw option bypass (GHSA-p6gq-j5cr-w38f) — bumped `^8.0.9` → `^9.0.1`. webpack-dev-server: HMR interception (CVE-2026-9595) — override `>=5.2.5`. dompurify: ALLOWED_ATTR pollution (GHSA-cmwh-pvxp-8882) — bumped `^3.4.9` → `^3.4.11`. Fixed critical duplicate `overrides` key in root `package.json` that silently discarded all 39 security overrides.
- ✅ **Bug Fix — WP All Import/Export Pro Tools (commit `0de3cdf`).** Four `require_once` paths in the Pro bootstrap map (`addons/pro/mcp-ai-wpoos-pro.php`) were missing `-pro-` in the filenames, referencing `class-wp-mcp-ai-tool-*.php` instead of the actual `class-wp-mcp-ai-pro-tool-*.php` files on disk, causing fatal errors when those tools loaded.
- ✅ **Bug Fix — Tool Status Label Loader (commit `749ffce`).** Replaced fragile `@file_get_contents()` with explicit `set_error_handler('__return_true')` / `restore_error_handler()` in both copies of `load_tool_status_labels()` (`includes/class-wp-mcp-ai-tool-registry.php` and `includes/admin/sections/class-wp-mcp-ai-section-tools.php`). The `@` operator does not reliably suppress warnings across all PHP 8 configurations (e.g. xdebug.scream, custom error handlers), and leaked warnings corrupt MCP JSON-RPC HTTP responses.
- ✅ **Dependency Updates.** 15 Dependabot version bumps across the monorepo: Composer (guzzlehttp/psr7 2.11.0→2.12.1). npm/Pro (stripe 14.25.0→22.2.3, csv-parse 5.6.0→7.0.0, p-queue 8.1.1→9.3.0, @puppeteer/browsers upgrade). npm/SaaS Controller (@wordpress/eslint-plugin 25.2.0→25.4.1, zod 3.x→4.4.3, @tanstack/react-query upgrade, wrangler upgrade). npm/Cloud Worker (stripe upgrade, vitest 2.x→4.1.9, @cloudflare/workers-types upgrade). npm/Docs Hub (@typescript-eslint 8.x→8.62.0, @types/node→26.0.0). GitHub Actions (codecov/codecov-action 4→7, softprops/action-gh-release 2→3).
- ✅ **Housekeeping.** Stale 1.1.31 build zips removed (PR #5437). Stale 1.1.32 build zips removed (6 files). SPA addon ZIPs rebuilt with updated security overrides and dependency bumps. nvoos-graphify standalone ZIP built at v1.0.0. nvoos-graphify-ai ZIP built at v1.0.0-dev.
- 📦 **Versioning** — bumped to **1.1.33** across `mcp-ai-wpoos.php`, `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `readme.txt`, `README.md`, and `CHANGELOG.md`. Provider count: **13** first-class language-model providers (unchanged). Tool count: ~195 base + ~795 Pro (~990 total; live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative).
- ✅ **MCP Initialize: Assistant-Scoped Instructions & Model Preferences (PR #5462).** Both the base `/mcp-ai/v1/mcp` endpoint and all 29 Pro toolkit MCP servers now carry the assistant's system prompt, professional role context, model preferences (`modelPreferences` — supported by Zed, Claude Desktop, Cursor), and knowledge base references in the `initialize` handshake instead of a generic WordPress site description. `serverInfo.name` becomes the assistant's display title when an `assistant_id` is provided. New filter `wp_mcp_ai_mcp_initialize_instructions` for integrator customization. Zero breaking changes — behavior is identical when no `assistant_id` is passed. Unix Theory P0-compatible: canonical return envelope and two-gate sanitisation rule unaffected (protocol-layer change only).

### June 17–19, 2026 — Content Format Templates, Result Delivery Pipeline, Featured Images, Provider Timeout Fixes 📝📬⏱️🔧

- ✅ **Content Format Templates & Featured Image Generation (PR #5433).** Content Format Template CPT (`mcp_ai_content_format_template`) with user-editable blog post templates. New `WP_MCP_AI_Content_Template_Engine` generates Anthropic-optimised XML-sectioned AI prompts. New `WP_MCP_AI_Featured_Image_Service` with 3-provider fallback (OpenAI DALL-E → Google Gemini → Cloudflare AI) and 5 image styles. Image Generation Provider dropdown on the CPT metabox. Provider settings (model, quality, aspect ratio) now respected instead of hardcoding `dall-e-3`/`hd`. AI prompts updated across all schedule presets to instruct image generation before post creation. 5 workflow presets updated with image gen nodes, node reorder, and `featured_image_id` wiring. Template resolution via Content Template Engine in `dispatch_assistant_run`. `resolve_node_template_variables` for cross-node variable passthrough. 5 default templates seeded on activation.
- ✅ **Result Delivery Pipeline (PR #5425).** New `WP_MCP_AI_Result_Delivery_Service` (1,056 lines) routes successful schedule results to 8 delivery channels: email, Slack, Discord, Telegram, SMS, Paper Store, WordPress post, webhook. Per-channel formatting and sending with `deliver_success()` and `deliver_failure()` pathways. `result_delivery` schema added to schedule CRUD. Result Delivery section in schedule edit modal UI. Delivery status appended to run history and result envelopes. Pre-configured Paper Store delivery for 8 research/audit presets. Both success and failure paths now deliver — previously only failures were surfaced.
- ✅ **ECA Document Generation (PR #5423).** ECA Consolidate & Add page with document generation tools. PHPCS alignment fixes across ECA admin pages.
- ✅ **Duplicate Posts & Provider Image Fixes (PR #5434).** WordPress delivery channel removed from `weekly_blog_post_writer` and `weekly_blog_topic_research` presets to prevent duplicate draft posts when the AI already calls `create_post` during assistant runs. Systemic guard in Result Delivery Service skips WordPress delivery when AI tool calls include `create_post` or `save_post`. Provider image settings now properly flow through Content Template Engine and Featured Image Service.
- ✅ **6 Provider Clients Now Respect Timeout (PR #5431).** DeepSeek, Baseten, DigitalOcean, OpenRouter, Kimi, and Cloudflare clients had hardcoded 60-second timeouts ignoring the admin **Request Timeout (seconds)** setting. All six `resolve_timeout()` methods now read `WP_MCP_AI_Admin_Settings`, matching the existing Anthropic/Gemini/Ollama pattern. Kimi retains its provider-specific `kimi_timeout` override.
- ✅ **Schedule Trigger Stability (PRs #5429, #5430).** Trigger crash on `rest_do_request()` wrapped in try/catch with pre-flight REST server check (PR #5429). User context restored and filters cleaned up in all error paths. `display` and `result_delivery` fields added to `ajax_get_schedules` response for edit modal persistence. `sanitize_result_delivery` preserves `channels` wrapper so all consumers read saved delivery config (PR #5430). Chat-channel `channel` field preserved through `sanitize_delivery_channels`. `ajax_trigger_schedule` wrapped in `ob_start` + try/catch to capture PHP warnings that corrupt JSON. Trigger debug output logged to browser console when `WP_DEBUG` is on.
- ✅ **Paper Store Delete Confirmation Fix (PR #5432).** Hidden inputs added to record delete confirmation form so `handle_delete_record()` receives required POST fields. `handle_delete_collection()` updated to read from `$_REQUEST` for GET-based admin-post.php links.
- ✅ **ECA Settings Menu & Attachment Fix (PR #5421).** ECA settings menu placement corrected. Attachment upload crash in ECA admin pages resolved.
- ✅ **npm CI & Jest Resilience (PR #5428).** `@babel/plugin-transform-modules-systemjs` override pinned to v7 (v8 is ESM-only, breaking eslint on Node 18/20). Root `package-lock.json` regenerated with override applied. `jest.config.js` probes for setup files at load time with graceful fallback to empty array. `jest.setup.js` at repo root. `|| exit 0` on `test:coverage` for CI. npm ci lockfile sync for chat-spa, media-studio, docs-hub, comic-reader, and saas-controller.
- ✅ **Dependency Updates.** 14 safe Dependabot version bumps across docs-hub, cloud-worker, saas-controller, Pro (react 19.2.6→19.2.7, vitest 4.1.5→4.1.9, stripe, workers-types, eslint 8.59.2→8.61.1, php-stubs 6.9.1→7.0.0, mailparser 3.7.1→3.9.9, validator 13.12.0→13.15.35). 8 npm audit CVEs fixed: nodemailer, tar, tar-fs. Security overrides added to 8 addon package.json files. `phpoffice/phpspreadsheet` lock synced 5.7.0→5.8.0.
- 📦 **Versioning** — bumped to **1.1.32** across `mcp-ai-wpoos.php`, `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `readme.txt`, `README.md`, and `CHANGELOG.md`. Provider count: **13** first-class language-model providers (unchanged). Tool count: ~195 base + ~795 Pro (~990 total; live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative).

### June 16–17, 2026 — Media Command Center, Pro SPA v2, Workflow Presets, npm CVEs, Gemini 3.1 Flash 🎬💬🧰🔒🎨

- ✅ **Media Command Center (PR #5402).** Top-level "NV Media" admin menu with command center for managing media workflows. Template and preset management. PHPCS cleanup and `implode()` WP_Error crash fix in the templates tab.
- ✅ **Pro SPA v2 Migration & Rich Rendering (PRs #5401, #5412, #5414).** Pro SPA v2 ported to feature parity with chat-spa and old pro-spa. Rich markdown rendering for assistant responses. Per-assistant scoping dropdown and agent selector. Conversations set as primary view; threads marked read-only after archival. CSS class-name mismatches fixed, causing previously unstyled UI to render correctly. Cache-bust version bump and admin layout fit within WordPress chrome (PR #5416). Bumped to v2.0.1.
- ✅ **34 Missing Workflow Preset Tools (PR #5418).** All missing workflow presets implemented across 10 toolkits (AI Tool Builder, Analytics, Architect Agent, Architectural Design, Calendar Booking, CRM, Document Generation, DJ Management, Social Media, Video Production). Orphaned media tools created and Upwork tool availability fixed. Full PHPCS compliance and comprehensive test suite validating all 36 new tools (class existence, interface implementation, slug resolution, metadata, capability flags).
- ✅ **npm Audit CVE Fixes (PR #5419).** 12 CVEs resolved across the dependency tree: vite (CVE-2026-53571 server.fs.deny bypass), launch-editor (CVE-2026-53632 NTLMv2 disclosure), markdown-it (CVE-2026-48988 smartquotes DoS), ws (memory exhaustion), js-yaml (quadratic DoS), form-data (CRLF injection), hono (path traversal, CORS, Set-Cookie), dompurify (XSS sanitization bypasses), @babel/core (arbitrary file read), @opentelemetry/core (unbounded memory), joi (RangeError DoS). ajv bump reverted to restore ESLint 8 compatibility. Root npm audit: 51→0 vulnerabilities.
- ✅ **Gemini 3.1 Flash Image Default (PR #5404).** Default Gemini image generation model upgraded to `gemini-3.1-flash-image`.
- ✅ **Media Toolkit Blueprints & Scheduler Presets (PRs #5398, #5411).** Blueprints and scheduler presets added to the media toolkit. Media Toolkit sync integrated into the Data Management page. Private `get_media_presets()` visibility fixed to prevent fatal error when called externally.
- ✅ **OpenAI/DeepSeek stream_options (PR #5400).** `stream_options` payload added to OpenAI and DeepSeek streaming API calls, enabling proper usage-tracking inclusion during streaming responses.
- ✅ **Tool Result Cost Calculation (PR #5395).** Tool result costs now computed from token counts when not explicitly provided by the provider, ensuring accurate cost attribution in agentic loops.
- ✅ **Agentic-Loop Cost Tracking (PR #5394).** Missing provider pricing entries added across the cost-tracking layer, fixing under-reporting in multi-turn agentic workflows.
- ✅ **Vite CVE Fixes (PR #5403).** vite pinned to ^8.0.16 in devDeps to resolve CVE-2026-53571 (server.fs.deny bypass) and CVE-2026-53632 (NTLMv2 disclosure via launch-editor middleware). Pro SPA v2 production assets built and dist output un-gitignored.
- ✅ **1,658 PHPCS Lint Fixes (PR #5397).** Full lint pass across base plugin and Pro addon (44 toolkits). Short ternaries converted to full, count() moved out of loops, docblock capitalisation normalized, require_once paths corrected for renamed optimization and tool files.
- ✅ **CI Disk Space Cleanup (PR #5417).** `Free disk space` step added to all build workflows to prevent runner disk exhaustion during large ZIP assembly.
- ✅ **Data Integrity Fixes.** PII pseudonymisation logic restored after being lost during a merge conflict resolution (PR #5398). CPT data store post type mismatch for CRM entities resolved (PR #5396).

### June 12–15, 2026 — Chat SPA Phase 8, PM Toolkit A–D, CRM Duplicates & Hygiene, DietPi Toolkit, LibreChat Addon, Context Windows, WP 7.0 Bridge 🪲💬📊🔧🛡️

- ✅ **Chat SPA Phase 8 — Message Actions & Content Enrichment (PRs #5381, #5383, #5390).** Conversations sidebar with assistant scoping in the Pro Chat SPA. Auto-create thread when posting to a non-existent thread. Message action cards on every assistant bubble: edit, delete, regenerate, copy, and content enrichment. Pro SPA chat routed through chat-client endpoint; threads marked read-only after archival.
- ✅ **Project Management Toolkit Enhancement Phase A–D (PR #5370).** Shared `WP_MCP_AI_PM_Engine` powering the new Command Center dashboard with real-time task visibility, status filters, and bulk actions. Work Ingestion panel on the tasks tab for importing external tasks. 28 new AI tools spanning task management, resource allocation, timeline generation, and reporting.
- ✅ **CRM Toolkit — Duplicates, Email Hygiene & Analytics (PRs #5362, #5367, #5368).** Duplicate detection engine with safe one-click merge and bulk merge from the new Duplicates tab. Email hygiene module: classify domains by reputation, manage exclusion/priority lists, and auto-prune. Top Customers and Top Clients analytics tools with dedicated Command Center tabs. Merged status filter and column on leads admin list. Inline tag editing and email priority/exclude actions. Repair CRM data tool fixing broken dates, generic titles, and impersonal sender names. Refresh All Sources button on Support tab. LinkedIn & Upwork external sourcing with LinkedIn as a Remote Sites OAuth 2.0 connection type.
- ✅ **DietPi Pro Toolkit Phases 0–3 (PRs #5346, #5348, #5350).** Foundations: CPT/CCT scaffolding, admin settings page, 19 core tools for system monitoring, package management, and service control. Phase 2: backup, update, storage, and dashboard tools. Phase 3: provisioning automation, infrastructure blueprints, and SSH proxy. Registered as an MCP server with Feature subtab toggle.
- ✅ **LibreChat Addon (PR #5336).** New `addons/librechat/` addon with code interpreter, speech-to-text/text-to-speech services, and web search reranker. Added to SPA build workflow and addon ZIP build pipeline.
- ✅ **Layer I Guardrails — Stay-on-Target Jailbreak Prevention (PRs #5340, #5344).** Harness Layer I intercepts prompt-injection and role-change attacks. Activated per-assistant via the LLM Harness metabox.
- ✅ **Context Window Management — All 13 Providers (PRs #5335, #5348, #5352).** Pre-flight context-window validation added to every AI provider client (OpenAI, Anthropic, Gemini, DeepSeek, OpenRouter, Baseten, Kimi, DigitalOcean, NVIDIA, Cloudflare, Hugging Face, LM Studio, Ollama). Shared `validate_context_window()` helper in the language model router. tiktoken integration for accurate token counting with estimator metabox on the Assistant editor. Token-budget tool capping — tools exceeding remaining budget are auto-excluded. Chat parity drift detection workflow. Model limits synced with June 2026 canonical catalog.
- ✅ **WP 7.0 Connectors API Bridge (PR #5387).** Bridge for provider credentials enabling forward-compatibility with the WordPress 7.0 Connectors API. Classes load unconditionally to prevent fatal errors on WP < 7.0.
- ✅ **Chat Transcript & Agent Memory Retention (PRs #5356, #5357).** `WP_MCP_AI_Transcript_Retention` — configurable retention policies and automatic cleanup cron. `WP_MCP_AI_Memory_Retention` — agent memory lifecycle management with pruning and retention windows.
- ✅ **Pro Toolkit Optimizations Phase 1–3 (PRs #5355, #5356, #5357).** Phase 1: Chat Channels and Social Media toolkits. Phase 2: Healthcare, Ecommerce, Calendar, and Orchestration. Phase 3: Document Generation and QMS. All classes wired into their toolkit init files with 767-line PHPUnit test suite.
- ✅ **OAuth & API Connection Management (PR #5351).** Disconnect buttons for OAuth and API connections. Auto-clear OAuth tokens when provider credentials change on save.
- ✅ **SPA Reliability Sweep (PRs #5371–#5379).** Register thread REST endpoints (fixes `createThread` crash). Fix SPA router context error. Fix `createThread` event serialization crash. Register POST `/threads/{id}/messages` endpoint. Pass model and profile to `createThread`. Fix SPA bootstrap tools, turn count, and REST route. Fix SPA enqueue paths and PM blueprints. Restore Zed-inspired SPA after accidental revert. Build SPA bundle and fix import paths.
- ✅ **Core & Provider Fixes.** 12 test suites fixed and SPL autoloader ordering corrected (#5392). Toolkit MCP tools returning empty `inputSchema` for image tools (#5391). OOS engine fatal error from undefined `sendRequest` (#5389). Agentic loop tool result persistence across all providers (#5354). OpenAI real-time SSE streaming (#5327). Bridge credential resolver on WP < 7.0 (#5388). Stale provider validation lists rejecting DeepSeek (#5323). Schedule preset data mismatches (#5329).
- ✅ **CRM & Data Fixes.** CRM configured sources count showing 0 for valid Gmail connections (#5386). Slider/range fields not rendering (#5361). 3 CPT slugs exceeding 20-character limit (#5361). Support ticket CPT rename `mcp_ai_support_ticket` → `mcp_ai_ticket` (#5360). Memory tools capability flags (#5364). Extended Cognition review issues (#5363). Playbook orphans and sync timeouts (#5322, #5325).
- ✅ **Addon Fixes.** Docs Hub: async rebuild stalls, browse critical error, broken Intuit privacy link (#5359). Cloudways Dashboard: removed `Requires Plugins` header (#5353); base-active check in `_is_ready()` (#5385). Pro Integrations subtab overwriting redirect (#5347). Performance monitor: buttons, reports, test execution restored (#5366). DietPi Toolkit PHPCS and PHPCompat fixes (#5346).
- ✅ **Security.** guzzlehttp/psr7 pinned to ^2.10.2 for CVE-2026-49214 (#5352). esbuild pinned to ^0.28.1; integrity check vulnerability in Pro addon (#5382). shell-quote bumped to >=1.8.4 for CVE-2026-9277 (#5330). vitest/vite/esbuild alerts in schedule-anything-spa (#5338).
- ✅ **Infrastructure.** `redirect_canonical` no longer breaks well-known endpoints (#5349). `tool-status.txt` reads guarded against missing files (#5348). WPCS lint in section-tools and tool-registry (#5348). ESLint `no-var` in performance-admin.js (#5366). PHPCS in CRM analytics (#5320). Chat parity workflow YAML and permissions fix (#5336).
- ✅ **Blueprint & Doc Updates.** 46 healthcare-style blueprints converted to CRM-style format (#5373). All legacy flat-format blueprints upgraded to canonical (#5372). v1.1.29 comprehensive documentation refresh across all surfaces (#5365). 66 proposal statuses audited (#5335). README updated with PM toolkit and repository map (#5370). 75 PHPCS violations auto-fixed in Pro bootstrap (#5321).
- 📦 **Versioning** — bumped to **1.1.31** across `mcp-ai-wpoos.php`, `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `readme.txt`, `README.md`, and `CHANGELOG.md`. Provider count: **13** first-class language-model providers (unchanged). Tool count: ~195 base + ~795 Pro (~990 total; live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative).

---

## 🆕 Previous Updates (v1.1.32 — June 2026)

### June 7–11, 2026 — Bug-Fix & Stabilisation Sweep 🪲🔧⚡

- ✅ **Chat Bubble Assistant Dropdown (PR #5333).** Fixed settings UX: `chat_bubble_assistant_id` field on the Chat Bubble settings page (`wp-admin/admin.php?page=wp-mcp-ai-dashboard&tab=general&subtab=chat_bubble`) changed from a plain `<input type="number">` to a proper `<select>` dropdown populated with all published assistants. Added `get_assistant_options()` helper method to `WP_MCP_AI_Section_Chat_Client` matching the pattern used by `default_assistant` in General → Core Settings. Users can now select assistants by name instead of manually typing numeric IDs.
- ✅ **Context-Window Pre-Flight Validation — All 13 Providers (PR #5328).** Added pre-flight context-window validation to all AI provider clients: OpenAI, Anthropic, Gemini, DeepSeek, OpenRouter, Baseten, Kimi, DigitalOcean, NVIDIA NIM, Cloudflare, Hugging Face, LM Studio, and Ollama. Shared `validate_context_window()` helper method in `WP_MCP_AI_AI_Client_Base`. Integrated `tiktoken` for accurate token counting with estimator metabox on the Assistant editor. Token-budget tool capping prevents exceeding model limits. Context-window management documentation at `docs/developer/architecture/context-window-management.md`.
- ✅ **OpenAI SSE Streaming Fix (PR #5327).** Fixed `stream_options` payload flag that prevented OpenAI real-time SSE streaming from triggering. The `include_usage` flag was incorrectly nested, causing the OpenAI API to ignore the streaming request and return a non-streamed response.
- ✅ **Schedule Preset Data Mismatches (PR #5329).** Fixed schedule preset data mismatches where presets would lose configuration data after save. Improved error logging for preset operations with structured log contexts.
- ✅ **Playbook Orphan Cleanup & Batching (PRs #5322, #5325).** Fixed orphan playbook accumulation where deleted parent records left unreachable children. Added JetEngine validation before deletion to prevent data corruption. Fixed sync timeouts for large playbook sets by implementing batch processing with configurable chunk sizes. `playbook_delete_batch_timeout` now uses iterative deletion instead of single-query operations.
- ✅ **Stale Provider Validation Lists (PR #5323).** Fixed provider validation lists in multiple locations that rejected DeepSeek and newer providers. Updated `WP_MCP_AI_Section_General::validate()` and `WP_MCP_AI_REST_Chat_Controller` provider checks to use dynamic provider discovery via `WP_MCP_AI_Admin_Settings::get_available_providers()` instead of hardcoded arrays. Also fixed default provider validation in CRM settings and Pro REST controllers.
- ✅ **CRM Activity Titles, Due Dates & Block API v3 (PR #5320).** Fixed CRM activity post titles not displaying correctly when created via AI tools. Fixed due date calculations for recurring activities. Migrated CRM admin blocks to WordPress Block API v3 (`apiVersion: 3`) for compatibility with WP 6.9+.
- ✅ **OpenAI-Compatible Client — DeepSeek Parity (PR #5315).** Enhanced all OpenAI-compatible chat clients (OpenRouter, Baseten, Kimi, DigitalOcean, NVIDIA NIM) to parity with the DeepSeek client: added `max_completion_tokens` support, temperature normalisation, `top_p` handling, and proper `stop` sequence forwarding.
- ✅ **Voice & Embedded LLM Missing Assets (PR #5319).** Added missing `.min.js` asset files for voice recording/transcription scripts and embedded LLM worker scripts. These files were referenced by `wp_register_script()` but missing from the build output, causing 404 errors on sites using minified assets.
- ✅ **Chat Config messagesEndpoint & Tool Count Guard (PR #5318).** Fixed chat config `messagesEndpoint` pointing to the wrong REST route in certain configurations. Added a tool count guard that returns a clear error message when an assistant has more tools configured than the provider's limit, preventing silent failures.
- ✅ **Chat Debug Console Fixes (PRs #5316, #5317).** Fixed `chatDebugMode` using loose equality (`==` instead of `===`) so PHP's string `'1'` is accepted alongside JavaScript's boolean `true`. Fixed legacy chat debug console not displaying when enabled via admin settings — the debug panel container was hidden by a CSS rule that only targeted the new SPA debug view.
- ✅ **OOS Bridge, Embedding Fatal & SSE Headers (PR #5313).** Fixed OOS bridge initialization failing when the core framework was loaded before WordPress user context was available. Fixed embedding service fatal error when API key was not configured — now returns a graceful `WP_Error`. Fixed SSE header warnings in PHP 8.1+ caused by `header_remove()` being called after output started.
- ✅ **Memory Cookie-Check Nonce Fix (PR #5312).** Fixed "Cookie check failed" error on the admin Test Assistant memory drawer. The memory REST controller was generating a nonce on `init` (before the user session was available), producing an invalid nonce for authenticated users. Nonce generation moved to the `wp` hook.
- ✅ **Chat Transcript Tests — 4% to 87% Pass Rate (PR #5310).** Fixed chat transcript REST controller tests. Root causes: test factories not creating posts with the correct `post_type`, missing `WP_REST_Server` initialization, session key normalisation mismatches, and permission callback assertions testing the wrong user role. Pass rate improved from ~4% (3/80) to 87% (70/80).
- ✅ **Graphify Related Content Leak (PR #5291).** Fixed graphify related content leaking to wrong page sections — content isolation tightened so graph nodes only render within their designated container element.
- ✅ **Model Limits — June 2026 Canonical Catalog (PR #5331).** Synced model limits (max tokens, max output tokens, rate limits) with the June 2026 canonical catalog across all providers.
- ✅ **Security — shell-quote CVE-2026-9277 (PR #5330).** Bumped `shell-quote` to >=1.8.4 via npm overrides to fix CVE-2026-9277 (command injection via insufficient escaping).
- 📦 **Versioning** — bumped to **1.1.29** across `mcp-ai-wpoos.php`, `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `package.json`, `readme.txt` Stable tag, `README.md`, and `docs/DOCUMENTATION_INDEX.md`. Provider count: **14** first-class language-model providers (OpenAI, Gemini, Anthropic, DeepSeek, OpenRouter, Baseten, Kimi, DigitalOcean, NVIDIA NIM, Cloudflare, Hugging Face, LM Studio, Ollama, Flowhub). Tool count: ~195 base + ~795 Pro (~990 total; live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative).

---

## 📋 Previous Releases

> For full details on all releases, see **[CHANGELOG.md](CHANGELOG.md)**.

| Version | Date | Highlights |
|---------|------|------------|
| **v1.1.44** | Aug 2026 | CCT stability (4 fixes: mutex lock, FlowHub duplicate, base-plugin fatal w/out lib/core, Veo async context), API key resolution (Gemini video + Veo fallback), Proposal 016 security & architecture hardening (all waves, 277 autoload optimizations, phpcs sweep), Proposal 017 polling/queue/load-balancing hardening (12 weaknesses), Deferred security items #5755 (post meta, term escaping, REST field filtering), Docs: FOR_REVIEWERS v1.1.43 update, 16 broken links fixed, Graphify ecosystem audit |
| **v1.1.43** | Aug 2026 | MCP 2026-07-28 stateless core upgrade, Security v1.1.43 hardening (SSRF/CSRF/SQL/XSS/info-disclosure across 16 files), OKF v0.2 trust-signal support, ICP System (Pro CRM Phase G, 7-dimension scoring), Pro Module Registry (PSR-4, 625-line monolithic init decomposed), Hexagonal architecture purity (PlatformFlushInterface), 7 playbook/profession sync fixes, Phase 3 operational security hardening, WPCS 3.4.1 (CVE-2026-45293) |
| **v1.1.42** | Jul 2026 | Security Infrastructure (7 classes, 21 posture signals A-F), Framework-Agnostic Core (lib/core/, 32 contracts, 21 adapters), Status Page & Incident Communication (Pro), 21 Agent Skills + 6 BMAD Agents, Algorave addon (9 tools), Security Hardening (12 fixes, 13 unit tests) |
| **v1.1.38** | Jul 2026 | Page Agent addon v0.1.0 (AI browser page control copilot), Pro SPA v2 major parity & polish (voice pipeline, tasks drawer, workflow tracker, file attachments, tool shortcuts, slash commands, mobile hamburger, autoscroll/viewport fixes, cache-busting, assistant preloading), Per-user chat memory toggle, create_post/save_post Markdown-to-HTML + taxonomy suggestions, Workflow blueprint existing-content awareness, SPA accessibility: annotation pills, ZAP medium findings triaged |
| **v1.1.37** | Jul 2026 | JetEngine Meta Helper universal (25 CPTs, REST, ECA fields), Places enrichment tools, RabbitMQ + queue infrastructure (custom DB tables, health endpoint, worker), Multi-tenant DB isolation Phase 0–4, DSpark admin UI + speculative orchestration, Crocoblock Design System addon (5 phases), Test coverage: 329 tools across 28 toolkits, Docs Hub broken link engine, OWASP ZAP DAST, 30+ bug fixes |
| **v1.1.36** | Jul 2026 | EZuite Inventory Sync Pro Toolkit, Ralph Loop CCT migration + circuit breaker, JetBooking/JetAppointment (8 tools), Moonshot/Z.AI provider parity (15 total), Unified Sync Log Manager, Tool Presets Auto-Select + Chips Bar, HTTrack Cache + Place-to-Service Bridge, Generate Default Mapping + read-only sync, 45+ bug fixes |
| **v1.1.35** | Jun 2026 | FlowHub Inventory Sync Pro Toolkit (6 tools), Shopify Sync Pro Toolkit (5 tools), Necessity Gate Layer J (irreversibility-weighted safety), Local Voice Embedded STT (3 backends, offline-first), Remote Site Administrator blueprint (22 tools), Places & Calendar bulk import, CLI site-import subcommand, voice realtime auto-detect, 7 bug fixes |
| **v1.1.34** | Jun 2026 | GPT-Realtime-2 voice models with WebRTC + Translate/Whisper + reasoning, multi-channel result delivery UI (11 channels, up from 4), pro scheduler AI/workflow delivery, Graphify ecosystem: remote drivers, WP 7.0 Connectors, wp.org compliance, 3 reasoning-tool fatal bugs fixed, CRM deal import + multi-source auto-import, Upwork/LinkedIn mode toggle, Docs Hub REST + settings sync fixes, http-proxy-middleware CVE, Gemini cache fix, GPT image routing fix, FastAPI porting plan |
| **v1.1.33** | Jun 2026 | WP 7.0 Connectors credential integration across all 17 AI clients with source badges, nvoos-graphify v1.0.0 release (Plugin Check compliant), 3 guzzlehttp CVEs + undici override, 29 npm alerts across 14 packages, 2 bug fixes (Pro tool paths, JSON-RPC warning leak), 15 dependabot bumps |
| **v1.1.32** | Jun 2026 | Content Format Templates + Featured Image Service (3-provider fallback), Result Delivery Pipeline (8 channels), ECA document generation, duplicate posts fix, 6 provider clients timeout fix, schedule trigger stability, Paper Store delete fix, ECA settings/attachment fix, npm CI & Jest resilience, 14 dependabot bumps + 8 npm audit CVEs |
| **v1.1.31** | Jun 2026 | Media Command Center, Pro SPA v2 (rich rendering, assistant scoping, agent selector, v2.0.1), 34 workflow preset tools, npm audit CVEs (12 resolved), Gemini 3.1 flash image default, Media toolkit blueprints & presets, stream_options, agentic-loop cost tracking, Vite CVEs, 1,658 PHPCS lint fixes, CI disk space, data integrity fixes |
| **v1.1.30** | Jun 2026 | Chat SPA Phase 8, PM Toolkit A–D, CRM duplicates/hygiene/analytics, DietPi Pro Toolkit, LibreChat Addon, Layer I Guardrails, Context Window Management, WP 7.0 Bridge, Pro Toolkit Optimizations, OAuth disconnect, 30+ fixes |
| **v1.1.28** | Jun 2026 | CRM Phase C complete (IMAP, SMS, WhatsApp ingestion), Customer CPT + 360 dashboard, Support Ticket module (10 AI tools + SLA), QKV Attention Routing, Funiq Bridge addon, NVOOS Graphify ecosystem (3 standalone plugins), NV Platform AI addon, automated demo video pipeline, TF-IDF + BM25 relevance search |
| **v1.1.27** | Jun 2026 | Real-time SSE streaming for all OpenAI-compatible providers, 35 new OOS core tools migrated, JFB submission tools — 8 fixes, Extended Cognition vision recognition, DeepSeek agentic tool handling, 9 HIGH-severity security findings fixed, 95% PHPUnit failures resolved |
| **v1.1.26** | Jun 2026 | Cross-Platform Extraction Engine Phases 0–2, Site-Builder Node-Graph Pipeline, SPA a11y hardening (WCAG 2.1 AA), 108 admin screenshots, docs reorganized into 12 directories |
| **v1.1.25** | May 2026 | Unified Blueprint System (55 blueprints across 25 toolkits), Cloudways Pro Toolkit (60 tools), CRM Toolkit Phases A–E (70+ tools), Chat UI 7-feature enhancement, Unix-theory Phase 4–5 |
| **v1.1.24** | May 2026 | Chat SPA fixes, Unix Theory P0/P1 refinement, CVE patches (tmp, symfony/cache), Paper Store admin CRUD, folder README convention |
| **v1.1.23** | May 2026 | Zed-inspired SPA architecture, Antigravity Interactions API rewrite, TypeScript upgrade, Comic Reader & Media Studio v0.3.0 |
| **v1.1.22** | May 2026 | Baseten provider (11th), CoSAI secure-by-design agentic system, Continual Harness P5, SaaS Controller P2/P4, npm VAD/Chat-Bubble/Memory-UI packages |
| **v1.1.21** | May 2026 | WP.org compliance complete (50/50 findings), canonical return envelope enforced, semantic compression, AI prompt caching layer |
| **v1.1.20** | May 2026 | Memory Layer 2026 Phase 7 — chat memory drawer UI complete |
| **v1.1.19** | May 2026 | Kimi provider (10th), ACP Server, MCP Bridge, Unix Theory P7, 9 HIGH security findings fixed, chat bubble sweep |
| **v1.1.18** | May 2026 | Unix Theory P0–P6, DigitalOcean Serverless Inference (9th provider), async chat continuation, jobs/tasks drawer, Toolkit MCP Servers Phase 7 |
| **v1.1.17** | May 2026 | WP.org compliance (42/50), Chat SPA Phases 1–7, Docs Hub v0.3.8, coverage campaign |
| **v1.1.16** | May 2026 | SaaS Controller Addon v0.1.0, structured logging integration, WP.org compliance hardening (B3, B8, B10, B13) |
| **v1.1.15** | May 2026 | OpenRouter + DeepSeek providers (7th & 8th), Orchestration Phases 1–7, LLM Harnessing GA, Memory Bridge G-series, Graphify data-source bridge |
| **v1.1.14** | May 2026 | Agent Skills v2 (45 skills), Markup Subsystem (Base), MemPalace Capture Framework, Graphify CPT/CCT suite |
| **v1.1.13** | May 2026 | OpenAI Images 2.0 (gpt-image-2), durable agent-memory bridge Phase 4a/4b, AI Harmonization toolkit, production Composer autoloader |
| **v1.1.12** | Apr 2026 | Architectural Design Toolkit Phases A–E, Graphify Federation/RAG, Tier 4 Browser-AI Runtime (Transformers.js v3.8.1), security patches |
| **v1.1.11** | Apr 2026 | WP.org compliance hardening |
| **v1.1.10** | Apr 2026 | Security audit summary (0 Critical, 5 High), production vendor autoload, Veo 3.1 fix |
| **v1.1.9** | Apr 2026 | Measurement Subsystem GA, PHPUnit 11 upgrade, Graphify v0.5.0 restored, orchestration reference |
| **v1.1.8** | Apr 2026 | Erlang C workforce tools, full tool-reference audit, WP.org compliance re-audit, MCP Apps per-assistant remote connections, CRE Debt toolkit (57 tools), 36 Pro professions + 17 teams, A2A protocol, Agent Command Center, floating chat bubble, JetEngine 3.8 MCP Server bridge, Anthropic/Gemini subscription tier support |

---

## 🚀 Features

> **Note:** Some features require third-party plugins (WooCommerce, JetEngine, Elementor, etc.). See [🔌 What You Lose Without Third-Party Plugins](#what-you-lose-without-third-party-plugins) for details.

### Assistant & conversation tools
- 🧠 Create AI Assistants via a custom post type (`mcp_ai_assistant`)
- 👔 **Professional & Team Templates** - Deploy assistants from ~311 pre-built profession templates spanning 12 industry categories, or create entire teams of specialists with one click. Includes backend testing for professions, teams, and assistants before public deployment.
- 🚀 **Getting Started Wizard** - Guided 4-step onboarding (`/wp-admin/admin.php?page=wp-mcp-ai-getting-started`) that walks new users through provider setup and use-case selection. Selecting a preset (Content Creator, Customer Support, E-commerce, SEO & Research, Developer Copilot, Media & Creative Studio, Site Administrator, or General Purpose) seeds a fully-configured assistant with tools, system prompt, and tuned temperature — ready to use immediately.【F:includes/admin/class-wp-mcp-ai-onboarding-wizard.php†L1-L53】【F:assets/js/onboarding-wizard.js†L1-L303】
- 🔄 Automatic synchronization to JetEngine Custom Content Types when available (CPT → CCT)
- 💬 Chat interface via `[mcp_ai_chat assistant="ID"]`
- 🧰 Per-assistant defaults for model, temperature, and system prompt baked into every chat request
- 🔍 Search Media Library knowledge attachments with permission-aware download URLs
- ⚡ Build reusable prompt shortcuts with optional tool targeting and inline descriptions so operators can trigger common tasks with one click.【F:includes/assistants/class-wp-mcp-ai-assistant-cpt.php†L893-L1048】【F:includes/class-wp-mcp-ai-shortcode.php†L430-L693】【F:assets/js/chat.js†L600-L666】
- 🧊 Elementor widgets for embedding chat surfaces, onboarding content, and MCP dashboards inside Elementor

### Language routing & knowledge management
- 🔁 Route conversations through OpenAI or Gemini using a provider-aware language model router
- 🎯 Enhanced Gemini API integration: list models dynamically, count tokens for budget management, create embeddings for RAG/semantic search, and streaming support for real-time responses【F:docs/reference/api/gemini/gemini-api-enhancements.md†L1-L100】
- 🧠 Assistant knowledge base management with Media Library files and optional vector store IDs
- 📚 **OKF (Open Knowledge Format v0.1)** engine with 6 MCP tools for curated, deterministic knowledge with cross-link navigation — complementary to vector/RAG stores
- 🔎 Perform lightweight web searches (DuckDuckGo or Brave) without leaving the assistant conversation
- 🌐 Crawl4AI job runner tool for large-scale content gathering workflows

### Media generation & transcription
- 🔊 Generate speech audio via OpenAI's Text-to-Speech API and save the result to the Media Library
- 🎵 Generate instrumental music using Google Gemini Lyria with controls for genre, mood, tempo, and instrumentation
- 🎨 Generate on-brand imagery with OpenAI's Images API, honouring the configured response format (including GPT-Image-1's `url` responses) and storing the files as WordPress attachments
- 🖼️ Generate images with **Cloudflare Workers AI** using Stable Diffusion, Flux-2 Dev, Leonardo AI (Lucid Origin, Phoenix 1.0), and other text-to-image models with configurable dimensions and generation parameters
- 🖼️ Vectorize raster images (PNG, JPEG, WebP, GIF) to SVG format using @neplex/vectorizer with configurable quality settings - perfect for logos and icons
- 🎨 Comprehensive graphic editing with Graphic Editor Plus combining local operations (logo overlay, smart resize) and AI-powered features (style transfer, background removal, enhancement)
- 🏗️ **Pro:** Generate professional architectural drawings (floor plans, elevations, sections) with building codes, dimensions, and material specifications - designed for construction professionals
- 🎧 Transcribe or translate uploaded audio with OpenAI's speech-to-text endpoints

### Commerce & finance workflows
- 🛍 WooCommerce-aware tools (fetch orders or products, requires WooCommerce)
- 📊 Finance-ready QuickBooks Online reporting tool for surfacing Profit and Loss, Balance Sheet, and other statements inside assistant conversations【F:includes/tools/class-wp-mcp-ai-tool-get-quickbooks-report.php†L15-L214】【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L906-L955】
- 🖥️ **Pro:** QuickBooks Desktop sync via QODBC relay API — connect to QuickBooks Desktop through a Windows relay server for data synchronization
- 🛒 **Pro:** Shopify integration with auto-resolved connections — `connection_id` auto-resolved from assistant context, covering products, orders, customers, inventory, and catalog tools
- 🚗 **Pro:** Vehicle estimation tools — VIN decode (NHTSA vPIC), image-to-repair-estimate pipeline, and car wash package pricing engine (always available)
- 📸 **Pro:** Listing image download tools — bulk-download Google Maps, Facebook, and Instagram business listing images into the Media Library or ZIP

### Slash Commands & Workflow Automation ⭐ **NEW**
- ⚡ **8 Core Commands**: `/help`, `/next-task`, `/ship`, `/clean-content`, `/optimize-perf`, `/sync-docs`, `/workflow` - Command-line style interface for content management
- 🔄 **Workflow Orchestrator**: Multi-step workflow execution with state management, conditional logic, and human-in-the-loop checkpoints
- 🛠️ **21 Pro Toolkit Commands**: Specialized commands for E-commerce (6), Social Media (6), and Video Production (6) toolkits
- 🎯 **7 Automated Workflows**: Pre-built workflow templates for abandoned cart recovery, social media campaigns, video marketing, inventory management, and more
- 🔐 **Security**: Capability-based authorization, rate limiting, comprehensive audit logging
- 💡 **Integration**: JavaScript autocomplete, REST API endpoint, WP-CLI support
- [Documentation →](docs/user-guides/slash-commands/SLASH_COMMANDS_GUIDE.md) | [Pro Commands →](docs/user-guides/slash-commands/PRO_TOOLKIT_SLASH_COMMANDS.md)

### Chat Channels & Messaging Integration ⭐ **NEW**
- 💬 **Chat Channels Toolkit (47 Tools)**: Integrate with 11 platforms - Telegram, WhatsApp, Slack, Discord, Microsoft Teams, Facebook Messenger, Apple Messages for Business, Google Chat/Spaces, Twitter/X, Office 365 (Outlook + OneDrive), iCloud Drive
- 📧 **Office 365 Integration** ⭐ **NEW**: Send and retrieve Outlook mail, list/download/upload OneDrive files via Microsoft Graph API (5 tools)
- ☁️ **iCloud Drive Integration** ⭐ **NEW**: List, download, and upload iCloud Drive files via a configurable gateway service (3 tools)
- 🌐 **Unified Broadcasting**: Send messages across multiple platforms simultaneously with `unified_channel_broadcast` tool
- 🏠 **WebChat Rooms**: Custom post type for real-time collaborative chat rooms with AI assistant assignment
- 📝 **Message Persistence**: JetEngine CCT integration for permanent message history
- 🔊 **WebRTC Support**: Self-hosted WebRTC signaling via WordPress REST API for voice/video
- 🤖 **AI-Powered Rooms**: Assign dedicated assistants to chat rooms for automated support
- [Chat Channels Guide →](addons/pro/docs/CHAT_CHANNELS_TOOLKIT.md) | [WebChat Setup →](addons/pro/docs/WEBCHAT_ASSISTANT_ASSIGNMENT.md)

### Communications & outreach
- ✉️ Mailjet-powered outbound email automation with granular capability enforcement and sender defaults configurable in the MCP settings.【F:includes/tools/class-wp-mcp-ai-tool-send-mailjet-email.php†L19-L405】【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L1008-L1054】
- 📅 Google Workspace automations for creating calendar events and searching connected Gmail inboxes directly from assistant workflows.【F:includes/tools/class-wp-mcp-ai-tool-create-google-calendar-event.php†L1-L200】【F:includes/tools/class-wp-mcp-ai-tool-search-gmail.php†L1-L200】
- 🧾 JetFormBuilder orchestration for listing forms, reviewing submissions, and proxying REST calls on behalf of assistants (requires JetFormBuilder)
- 📚 JetEngine REST route reference tool for surfacing endpoint metadata inside AI workflows
- 🧱 Ready for extension with ChatKit integration

### Integrations, security & controls
- 🔧 Tool Registry for registering PHP functions callable by the AI
- ⚙️ JetEngine integration for dynamic content queries (requires JetEngine)
- 🔌 **JetEngine 3.8 MCP Server Bridge** ⭐ **NEW** - JSON-RPC 2.0 client bridges NV oOS into JetEngine's native MCP Server with 7 new Pro tools for CPT/taxonomy/meta field creation, relations management, site context grounding, and prompt template access. MCP-first dispatch with REST v2 fallback.
- 🤝 **Agent-to-Agent (A2A) Protocol** ⭐ **NEW** - Full A2A protocol making NV oOS assistants discoverable and interoperable with any A2A-compliant agent. `/.well-known/agent.json` discovery, JSON-RPC 2.0 server with task state machine, A2A client for remote agent delegation, push notification webhooks.
- 📊 **Agent Command Center** ⭐ **NEW** - Unified agent management dashboard with 7 tabs: Overview (KPI cards, live status), Activity Log, Active Tasks, Approvals (human-in-the-loop), Analytics (Chart.js with real per-agent metrics), Uptime & Health, and Strategy (efficiency scoring with recommendations).
- 💬 **Floating Chat Bubble** ⭐ **NEW** - Configurable floating chat bubble widget for Elementor and Gutenberg. 4 position variants, 3 sizes, bounce/pulse animations, dark mode, WCAG focus states, sessionStorage persistence.
- 🧷 Granular control over allowed attachment MIME types for chat uploads
- 🔐 Secure REST API endpoints
- 🔑 **Root Security Key** - Optional wp-config.php constant that can be enabled during emergency shutdown to require authentication before re-initializing the plugin. Provides an additional layer of protection against unauthorized reactivation after security incidents.【F:docs/features/security/root-security-key.md†L1-L511】【F:includes/class-wp-mcp-ai-root-security-key.php†L1-L360】
- 🛰 Assistant directory endpoint that advertises MCP tool/resource capabilities and negotiates Server-Sent Events handshakes for clients such as LM Studio or Claude Desktop.【F:includes/class-wp-mcp-ai-rest.php†L520-L666】【F:includes/class-wp-mcp-ai-rest.php†L1690-L1772】
- 📝 Full JSON-RPC 2.0 MCP endpoint (`/mcp`) for standards-compliant remote client communication
- 🔑 Configurable API credentials and defaults for OpenAI, Gemini, and Anthropic (with subscription tier support for Team/Enterprise plans and custom base URLs)
- 🤖 ChatGPT’s connector beta currently requires an Auth0 tenant; the plugin’s assistant credentials are compatible with LM Studio, Claude, and other MCP clients that support bearer headers directly.【F:docs/reference/api/mcp-server-authentication.md†L22-L46】
- 🌐 **Mesh networking** for distributed compute pooling across multiple WordPress sites. Server-to-server architecture enables anonymous and authenticated users to benefit from shared AI resources, budget pooling, and workload distribution across 100+ trusted peer sites. Backend assistants coordinate mesh operations via secure inter-site keys while maintaining user attribution and audit trails for compliance.【F:docs/features/federation/mesh-compute-pooling.md†L1-L615】【F:includes/tools/class-wp-mcp-ai-tool-query-remote-site.php†L1-L237】
- 🔗 **Federation & Discovery** - Decentralized AI capability network allowing WordPress sites to publish their capabilities via well-known endpoints (`/.well-known/ai-peer`) and discover peer sites through directory services. Supports peer registration, health verification, search & ranking by capability/region/policy, and automatic cron-based health monitoring. Enable federation to join the network or run your own directory service for private peer discovery.【F:docs/features/federation/federation-discovery.md†L1-L511】【F:FEDERATION-IMPLEMENTATION-SUMMARY.md†L1-L381】
- 🧾 Optional logging of chat interactions, tool executions, and API errors
- 🧮 Built-in per-user usage tracking for provider/model billing summaries
- 🧩 Developer hooks and filters for integrating custom behaviours
- ⏱ Per-site request timeout control with sensible minimum enforcement
- 🗑 Toggleable uninstall cleanup to purge stored assistants and settings automatically

### Performance & reliability
- ⚡ Client-side message bundling (800ms window) to reduce API calls and server load【F:docs/user-guides/chat/message-bundling-feature.md†L1-L80】
- 🎯 Intelligent token overflow handling with automatic model switching (gpt-4.1-mini → Gemini 2.0 Flash)【F:docs/features/tools/presets/high-token-tool-handling.md†L1-L80】
- 📡 **Server-Sent Events (SSE) support** for real-time streaming responses and job notifications【F:docs/features/streaming/ENABLE-SSE-STREAMING.md†L1-L100】
- 🌊 Real-time job status updates via SSE streaming and webhook notifications for async operations【F:docs/features/async-jobs/job-notification-system.md†L1-L100】
- 🔧 **Symfony Process Component** - Modern process execution framework replacing direct `exec()` calls in Pro addon tools for enhanced security, timeout management, and error handling【F:includes/services/class-wp-mcp-ai-process-service.php†L1-L220】【F:docs/history/2025/implementations/symfony-phases/SYMFONY_PHASE2B_PROCESS_INTEGRATION.md†L1-L100】
- 🔄 Server-side WP-Cron polling for long-running tasks (Crawl4AI, background jobs)
- 💾 Chat history persistence with localStorage (24h) and optional JetEngine CCT storage【F:docs/user-guides/chat/chat-history-persistence.md†L1-L50】
- ⚙️ **Optimized settings page** with external CSS stylesheet (240 lines added to admin-settings.css) and request-level caching for improved admin performance【F:assets/css/admin-settings.css†L1-L984】【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L27-L32】

### Settings Management ⭐ **NEW**
- 🔧 **Robust Settings System** - 7-step save process with automatic backups, validation, and cache management ensures settings persist correctly across all tabs and subtabs【F:includes/admin/class-wp-mcp-ai-settings-dashboard.php†L262-L410】
- 🔍 **Health Check** - Run 6 diagnostic checks to verify settings integrity, provider configuration, and system status with GOOD/WARNING/CRITICAL status indicators【F:includes/admin/sections/class-wp-mcp-ai-section-advanced.php†L1500-L1650】
- 💾 **Export Settings** - Download all plugin settings as timestamped JSON files for backup or migration to other sites【F:docs/admin-guides/settings-management.md†L40-L80】
- 📤 **Import Settings** - Upload and validate settings from previously exported backups with automatic pre-import backup and 5-step validation【F:docs/admin-guides/settings-management.md†L85-L135】
- 🗑️ **Clear Cache** - One-click clearing of static cache, object cache, and transients when settings changes don't take effect【F:docs/admin-guides/settings-management.md†L140-L165】
- ↩️ **Reset to Defaults** - Safely reset all settings to default values with automatic backup before reset【F:docs/admin-guides/settings-management.md†L170-L200】
- 🔒 **Security** - File size validation (max 5MB), MIME type checking, JSON validation, and comprehensive input sanitization【F:includes/admin/class-wp-mcp-ai-settings-dashboard.php†L970-L1030】
- 📊 **Automatic Backups** - Every save operation creates a timestamped backup (keeps last 5) for emergency recovery【F:includes/admin/class-wp-mcp-ai-settings-dashboard.php†L285-L295】
- 🛡️ **Data Protection** - 3-layer protection (section filtering, merge strategy, sensitive key filtering) prevents accidental data loss when saving from tabs/subtabs【F:docs/admin-guides/settings-management.md†L230-L280】
- 📖 **Pro Toolkits** - Enable and configure 8 specialized Pro toolkits (650+ tools) including Project Management, Document Generation, Health & Wellness, CRE Debt & Securitization, and more【F:docs/admin-guides/pro-settings-toolkits.md†L1-L650】

➡️ **Complete Documentation:** [Settings Management Guide](docs/admin-guides/settings-management.md) | [Quick Reference](docs/admin-guides/settings/SETTINGS_MANAGEMENT_QUICK_REFERENCE.md) | [Visual UI Guide](docs/visual-guides/settings-management-ui.md) | [Pro Toolkits Guide](docs/admin-guides/pro-settings-toolkits.md)

## 🧠 Memory & Tool Stack Overview

### Model defaults

Global settings capture the default provider, model, and timeout used when assistants are created, ensuring every conversation inherits stable generation behaviour until explicitly overridden. These defaults ship with sensible values for OpenAI and Gemini out of the box and can be tailored from the NV oOS settings screen.【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L36-L77】

### Base knowledge

Each assistant can preload Media Library files and optionally link to an external vector store, giving the model persistent project context before a chat begins. Editors manage these knowledge sources from the assistant post type via the “Base Knowledge” meta box, which supports multiple attachments and vector store identifiers.【F:includes/assistants/class-wp-mcp-ai-assistant-cpt.php†L892-L1002】

### Available tools

The tool registry boots with a curated catalogue of content, commerce, automation, and research utilities, then exposes hooks so developers can register their own providers. During initialisation the registry loads each bundled tool class—ranging from JetEngine accessors to Crawl4AI jobs and Mailjet automations—and makes them callable within conversations.【F:includes/class-wp-mcp-ai-tool-registry.php†L74-L220】

### Chat-client Memory Drawer

The chat front-end exposes a persistent **Memory Drawer** (`assets/js/chat-memory-drawer.js`) with three tabs:

- **Memories** — browse, pin, and delete stored context items; **🧠 badge** auto-appears on any assistant message that used a memory tool.
- **Scope** — set the active wing/room scope for subsequent memory operations in the current session.
- **Audit** — lazy-loaded audit trail from `WP_MCP_AI_REST_Chat_Memory_Controller::audit()`.

The drawer is wired to the REST proxy at `/mcp-ai/v1/chat-memory/` and receives real-time updates via the `memory_event` SSE frame emitted by the agentic loop. Pagehide auto-capture stores the session state before tab close. Two gates control access: site-wide filter `wp_mcp_ai_chat_memory_enabled` and per-user meta `wp_mcp_ai_chat_memory_enabled`. Full reference: [`docs/features/memory/chat-client-integration.md`](docs/features/memory/chat-client-integration.md).

### Retroactive Transcript Mining

`WP_MCP_AI_Transcript_Mining_Job` retrospectively extracts memories from past chat transcripts. Enqueue a job via `POST /mcp-ai/v1/transcript-mining/jobs` (admin-only), poll progress with `GET /jobs/{id}`, or cancel with `POST /jobs/{id}/cancel`. Full reference: [`docs/features/memory/transcript-mining.md`](docs/features/memory/transcript-mining.md).

### LLM Harnessing Subsystem

Seven opt-in per-request layers (`includes/harness/`) improve response quality without changing existing tool behaviour. Activated per-assistant via the **LLM Harness** metabox. Layers: **A** Prompt/Cue → **B** Reasoning Trace → **C** Tool Routing → **D** Retrieval → **E** Self-Refine → **F** Memory Scoping + PII Filter → **G** Eval Scheduler. Pro Layer H exports fine-tune curricula as OpenAI JSONL. Full reference: [`docs/features/llm-harness.md`](docs/features/llm-harness.md).

### Workflow families & tool combos

The core plugin ships with a centrally registered tool catalogue that lets assistants mix and match capabilities into cohesive workflows without additional coding. Teams can chain authoring, media, research, commerce, marketing, and operational tools to deliver end-to-end outcomes inside a single conversation.

- **Content & knowledge production** – Combine `submit_document_prompt`, `search_content`, and `search_attachments` to gather source material, then follow up with `save_post`, `create_wpcode_snippet`, or `get_rankmath_seo` for structured drafting and optimisation.
- **Media generation & transcription** – Pair `generate_openai_image`, `generate_gemini_image`, `vectorize_image`, or `graphic_editor_plus` with `generate_openai_speech` and `transcribe_openai_audio` to build multimedia assets that flow into editorial or marketing outputs. Use `vectorize_image` to convert logos to scalable vectors, and `graphic_editor_plus` for comprehensive image editing with both local and AI-powered operations.
- **Research & situational awareness** – Chain discovery helpers like `web_search`, `run_crawl4ai_job`, `reliefweb_reports`, `get_gdacs_events`, and `get_nhc_active_storms` to assemble briefing packs before drafting follow-up actions.
- **Commerce & finance operations** – Use WooCommerce and finance tools such as `create_woo_product`, `get_woo_products`, `get_woo_recent_orders`, `crawl4ai_price_lookup`, `get_import_duty`, and `quickbooks_report` to coordinate merchandising, pricing, and bookkeeping reviews.
- **Marketing & analytics insights** – Combine measurement tools including `google_analytics_report`, `get_google_business_insights`, `get_facebook_instagram_insights`, `get_linkedin_insights`, and `get_tiktok_insights` to guide campaigns and reporting.
- **Publishing & outreach automations** – Trigger distribution via `post_facebook_instagram`, `post_google_business_update`, `post_linkedin_update`, `post_tiktok_video`, `send_group_email`, `send_mailjet_email`, `send_telegram_message`, `send_whatsapp_message`, and `schedule_notify_sms` once plans are ready.
- **Integrations & scheduling** – Connect external systems with `create_google_calendar_event`, `search_gmail`, `list_jetengine_rest_routes`, `invoke_jetengine_route`, and `run_openai_external_action` as part of larger automations.
- **Operations & diagnostics** – Close the loop with `create_cron_job`, `list_cron_jobs`, `get_cron_job`, `delete_cron_job`, `check_wp_cli`, `purge_cache`, `purge_cloudflare_cache`, `purge_varnish_cache`, `get_site_summary`, `get_site_health`, `get_system_logs`, `get_update_status`, and OpenAI usage/log review helpers for monitoring and maintenance.
- **Automation & scheduling workflows** – Agents can autonomously schedule background tasks with `create_cron_job`, monitor scheduled operations via `list_cron_jobs` and `get_cron_job`, and clean up outdated automations with `delete_cron_job`. Combine with cache management tools (`purge_cache`, `purge_cloudflare_cache`, `purge_varnish_cache`) to orchestrate content publishing workflows where agents schedule posts, then automatically invalidate caches at publication time.

---



## 🛠 Built-in tools & automations

The assistant registry ships with a comprehensive catalogue of editorial, marketing, commerce, and operational helpers. The tables below outline every bundled tool and the slug assistants call when orchestrating workflows.

### Content & knowledge workflows
| Tool | Slug | Summary |
| --- | --- | --- |
| Submit Document Prompt | `submit_document_prompt` | Uploads WordPress attachments or OpenAI file IDs alongside an instruction so multimodal prompts reach the Responses API with the required file context.【F:includes/tools/class-wp-mcp-ai-tool-submit-document-prompt.php†L20-L214】|
| Search Content | `search_content` | Queries public post types with optional taxonomy and meta filters to surface structured post metadata for the assistant.【F:includes/tools/class-wp-mcp-ai-tool-search-content.php†L12-L280】|
| Search Attachments | `search_attachments` | Scans the Media Library with keyword or MIME filters while honouring attachment capability checks and signed download URLs.【F:includes/tools/class-wp-mcp-ai-tool-search-attachments.php†L15-L207】|
| Get Recent Posts | `get_recent_posts` | Returns the latest entries for a given post type with titles, permalinks, excerpts, and timestamps for quick editorial summaries.【F:includes/tools/class-wp-mcp-ai-tool-get-recent-posts.php†L12-L104】|
| Get Elementor Templates | `get_elementor_templates` | Lists Elementor library templates with status, type, and edit links when Elementor is available and the caller has access.【F:includes/tools/class-wp-mcp-ai-tool-get-elementor-templates.php†L12-L239】|
| Get JetEngine Items | `get_jetengine_items` | Retrieves JetEngine-managed content with capability-aware access checks for each registered custom post type.【F:includes/tools/class-wp-mcp-ai-tool-get-jetengine-items.php†L12-L118】|
| Get JetFormBuilder Forms | `get_jetformbuilder_forms` | Proxies JetFormBuilder REST controllers to return paginated form metadata with automatic REST/HTTP fallbacks.【F:includes/tools/class-wp-mcp-ai-tool-get-jetformbuilder-forms.php†L15-L155】|
| Get JetFormBuilder Submissions | `get_jetformbuilder_submissions` | Lists recent JetFormBuilder entries with normalised field snapshots and capability enforcement.【F:includes/tools/class-wp-mcp-ai-tool-get-jetformbuilder-submissions.php†L15-L154】|
| Save Post | `save_post` | Drafts or updates posts and custom post types with sanitised Gutenberg content, slug/title overrides, and edit links.【F:includes/tools/class-wp-mcp-ai-tool-save-post.php†L15-L268】|
| Create WPCode Snippet 🌟 | `create_wpcode_snippet` | Provisions or updates WPCode-managed snippets, validating code types, insert locations, and activation status. **Pro addon tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-create-wpcode-snippet.php†L15-L224】|
| Get Rank Math SEO Overview | `get_rankmath_seo` | Surfaces Rank Math SEO scores, focus keywords, robots metadata, and schema details for a specific post when the plugin is active.【F:includes/tools/class-wp-mcp-ai-tool-get-rankmath-seo.php†L15-L220】|
| Get User Information | `get_user_info` | Inspects the acting user or a supplied account while respecting multisite membership and capability requirements.【F:includes/tools/class-wp-mcp-ai-tool-get-user-info.php†L12-L89】|

### Media generation & transcription
| Tool | Slug | Summary |
| --- | --- | --- |
| Generate OpenAI Image | `generate_openai_image` | Calls the OpenAI Images API with configurable defaults, saving the rendered asset to the Media Library with optional overrides.【F:includes/tools/class-wp-mcp-ai-tool-generate-openai-image.php†L17-L218】|
| Generate Gemini Image | `generate_gemini_image` | Uses Gemini’s multimodal image endpoint to render creative, aspect-ratio-aware visuals that are persisted as WordPress attachments.【F:includes/tools/class-wp-mcp-ai-tool-generate-gemini-image.php†L17-L200】|
| Generate Cloudflare AI Image | `cloudflareai_text_to_image` | Creates images using Cloudflare Workers AI text-to-image models including Stable Diffusion XL, Flux-2 Dev, Leonardo AI (Lucid Origin, Phoenix 1.0), and Dreamshaper with configurable dimensions, steps, and guidance parameters.|
| Vectorize Image | `vectorize_image` | Converts raster images (PNG, JPEG, WebP, GIF) to SVG vector format with configurable quality settings using @neplex/vectorizer. Perfect for logos, icons, and graphics. Requires Node.js 14+.【F:includes/tools/class-wp-mcp-ai-tool-vectorize-image.php†L1-L430】|
| Graphic Editor Plus | `graphic_editor_plus` | Comprehensive image editing with local operations (logo overlay, resize) and AI-powered features (style transfer, background removal, enhancement). Combines speed with intelligent transformations.【F:includes/tools/class-wp-mcp-ai-tool-graphic-editor-plus.php†L1-L784】|
| Generate Architectural Drawing 🌟 | `generate_architectural_drawing` | **[PRO]** Creates professional architectural drawings (floor plans, elevations, sections, details) for construction projects. Supports 10 drawing types, 6 presentation styles (technical, sketched, rendered), dimensional specifications, building codes (IBC, IRC, NBC, Eurocode), and material lists. Outputs PNG or SVG with automatic vectorization. Perfect for architects, engineers, and construction professionals.【F:addons/pro/includes/tools/class-wp-mcp-ai-tool-generate-architectural-drawing.php†L1-L1136】|
| Generate OpenAI Speech | `generate_openai_speech` | Converts text to audio via OpenAI’s text-to-speech models, honouring default voice/format selections and storing results in the Media Library.【F:includes/tools/class-wp-mcp-ai-tool-generate-openai-speech.php†L17-L199】|
| Generate Music | `generate_music` | Creates instrumental music from text descriptions using Google Gemini Lyria model with controls for genre, mood, duration, and tempo.|
| Transcribe OpenAI Audio | `transcribe_openai_audio` | Sends uploaded audio to OpenAI’s transcription/translation endpoints and returns structured transcripts with language and duration metadata.【F:includes/tools/class-wp-mcp-ai-tool-transcribe-openai-audio.php†L17-L195】|

### Research & situational awareness
| Tool | Slug | Summary |
| --- | --- | --- |
| Web Search | `web_search` | Performs lightweight lookups against DuckDuckGo or Brave, normalising related topics and enforcing per-user result caps.【F:includes/tools/class-wp-mcp-ai-tool-web-search.php†L12-L320】|
| Run Crawl4AI Job | `run_crawl4ai_job` | Executes Crawl4AI harvests locally or remotely, collecting Markdown, HTML, and error payloads for long-form content ingestion workflows.【F:includes/tools/class-wp-mcp-ai-tool-run-crawl4ai-job.php†L32-L745】|
| ReliefWeb Reports | `reliefweb_reports` | Queries ReliefWeb’s humanitarian dataset by country or disaster type and returns structured report metadata for situational updates.【F:includes/tools/class-wp-mcp-ai-tool-reliefweb-reports.php†L15-L234】|
| Get GDACS Events | `get_gdacs_events` | Fetches Global Disaster Alert and Coordination System events with optional date filters and capability checks for emergency planning.【F:includes/tools/class-wp-mcp-ai-tool-get-gdacs-events.php†L12-L200】|
| Get NHC Active Storms | `get_nhc_active_storms` | Retrieves the National Hurricane Center’s active storm feed, sanitising advisory data for assistant consumption.【F:includes/tools/class-wp-mcp-ai-tool-get-nhc-active-storms.php†L15-L146】|
| Get Open-Meteo Forecast | `get_open_meteo_forecast` | Pulls hourly weather data from Open-Meteo with coordinate, timezone, and variable controls for itinerary-aware responses.【F:includes/tools/class-wp-mcp-ai-tool-get-open-meteo-forecast.php†L15-L309】|
| Vision Product Search | `vision_product_search` | Searches for similar products using Google Cloud Vision API Product Search feature. Note: Requires proper Google Cloud authentication credentials to succeed.【F:includes/tools/class-wp-mcp-ai-tool-vision-product-search.php†L1-L200】|
| Vision Object Localization | `vision_object_localization` | Detects and localizes multiple objects in images using Google Cloud Vision API. Note: Requires proper Google Cloud authentication credentials to succeed.【F:includes/tools/class-wp-mcp-ai-tool-vision-object-localization.php†L1-L200】|

### Commerce & finance operations
| Tool | Slug | Summary |
| --- | --- | --- |
| Create WooCommerce Product Draft | `create_woo_product` | Builds draft WooCommerce products with merchandising copy, pricing, images, and brand metadata when WooCommerce is active.【F:includes/tools/class-wp-mcp-ai-tool-create-woo-product.php†L15-L258】|
| Get WooCommerce Products | `get_woo_products` | Surfaces catalogue listings with pricing, stock status, and optional SKU/status filters for merchandiser reviews.【F:includes/tools/class-wp-mcp-ai-tool-get-woo-products.php†L12-L140】|
| Get Woo Recent Orders | `get_woo_recent_orders` | Summarises recent WooCommerce orders with totals, billing details, and ISO timestamps for fulfilment teams.【F:includes/tools/class-wp-mcp-ai-tool-get-woo-recent-orders.php†L12-L117】|
| Wholesale Club Price Lookup | `crawl4ai_price_lookup` | Uses Crawl4AI’s web search endpoint to compare BJ’s, Sam’s Club, and Costco pricing for a given product query.【F:includes/tools/class-wp-mcp-ai-tool-crawl4ai-price-lookup.php†L17-L189】|
| Lookup Import Duty 🌟 | `get_import_duty` | Queries the ITA Tariff Rates API for HS codes or descriptions to surface import duty rates for supported countries. **Pro addon tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-get-import-duty.php†L15-L152】|
| QuickBooks Online Report 🌟 | `quickbooks_report` | Requests Profit & Loss, Balance Sheet, or custom QuickBooks Online reports with optional date ranges and accounting methods. **Pro addon tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-get-quickbooks-report.php†L15-L214】|

### Marketing & analytics insights
| Tool | Slug | Summary |
| --- | --- | --- |
| Google Analytics Report 🌟 | `google_analytics_report` | Runs GA4 Analytics Data API queries with metrics, dimensions, date ranges, and aggregation controls to monitor site performance. **Pro addon tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-get-google-analytics-report.php†L15-L158】|
| Google Business Insights | `get_google_business_insights` | Fetches Google Business Profile metrics for a location using OAuth tokens, time ranges, and timezone hints. **Pro addon tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-get-google-business-insights.php†L15-L149】|
| Meta Social Insights | `get_facebook_instagram_insights` | Pulls Facebook Page or Instagram business metrics via the Graph API with selectable periods and metric sets. **Pro addon tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-get-facebook-instagram-insights.php†L15-L146】|
| LinkedIn Insights | `get_linkedin_insights` | Queries LinkedIn organizational share statistics with optional timeframe and granularity filters. **Pro addon tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-get-linkedin-insights.php†L15-L138】|
| TikTok Insights | `get_tiktok_insights` | Calls the TikTok Open API to return account performance metrics across configurable windows and granularities. **Pro addon tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-get-tiktok-insights.php†L15-L136】|
| Get Cross-Platform Analytics 🌟 | `get_cross_platform_analytics` | **[NEW Jan 2026]** Unified social media metrics dashboard aggregating data from Facebook, Instagram, Twitter, LinkedIn, and YouTube. Provides engagement rates, follower growth, post performance, and comparative analytics across all platforms. Built-in 12-hour caching. **Pro addon tool** (623 lines).|
| Track Hashtag Performance 🌟 | `track_hashtag_performance` | **[NEW Jan 2026]** Comprehensive hashtag analysis tracking reach, engagement, impressions, and trend data across Facebook, Instagram, Twitter, LinkedIn, and YouTube. Identifies top-performing hashtags and provides optimization recommendations. **Pro addon tool** (586 lines).|
| Competitor Analysis 🌟 | `analyze_competitor_social` | **[NEW Jan 2026]** Track competitor social media metrics and benchmark performance against your profiles. Monitors follower growth, engagement rates, posting frequency, and content strategies across all major platforms. **Pro addon tool** (711 lines).|
| Influencer Identification 🌟 | `identify_influencers` | **[NEW Jan 2026]** Discover brand influencers and potential collaboration partners based on reach, engagement criteria, audience demographics, and content relevance. Searches across Facebook, Instagram, Twitter, LinkedIn, and YouTube. **Pro addon tool** (759 lines).|

### Publishing & outreach
| Tool | Slug | Summary |
| --- | --- | --- |
| Publish Meta Social Post 🌟 | `post_facebook_instagram` | Publishes Facebook Page or Instagram business posts through the Meta Graph API with message, caption, and media controls. **Pro addon tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-post-facebook-instagram.php†L15-L170】|
| Publish Google Business Update 🌟 | `post_google_business_update` | Creates Google Business Profile local posts with summaries, language codes, and optional call-to-action links. **Pro addon tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-post-google-business-update.php†L15-L168】|
| Publish LinkedIn Update 🌟 | `post_linkedin_update` | Sends LinkedIn UGC posts for members or organisations with optional share URLs via the LinkedIn Marketing API. **Pro addon tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-post-linkedin-update.php†L15-L160】|
| Publish TikTok Video 🌟 | `post_tiktok_video` | Submits hosted video assets to TikTok’s Open API share endpoint with optional captions. **Pro addon tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-post-tiktok-video.php†L15-L152】|
| Send Group Email | `send_group_email` | Orchestrates structured or free-form email campaigns with capability-based audience limits and logging hooks. [Full documentation](docs/features/tools/communication/send-group-email-usage.md).【F:includes/tools/class-wp-mcp-ai-tool-send-group-email.php†L16-L650】|
| Send Mailjet Email 🌟 | `send_mailjet_email` | Delivers transactional and marketing emails through Mailjet with sender defaults, CC/BCC routing, and response metadata. **Pro addon tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-send-mailjet-email.php†L19-L405】|
| Send Telegram Message 🌟 | `send_telegram_message` | Posts formatted updates to Telegram chats or channels with capability filters and audit logging. **Pro addon tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-send-telegram-message.php†L16-L232】|
| Send WhatsApp Message 🌟 | `send_whatsapp_message` | Sends WhatsApp Cloud API text messages with preview controls using phone-number specific access tokens. **Pro addon tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-send-whatsapp-message.php†L15-L178】|
| Schedule Notify.lk SMS 🌟 | `schedule_notify_sms` | Queues Notify.lk SMS messages for future delivery using the official SDK and site cron orchestration. **Pro addon tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-schedule-notify-sms.php†L15-L180】|

### Integrations & scheduling
| Tool | Slug | Summary |
| --- | --- | --- |
| Create Google Calendar Event | `create_google_calendar_event` | Builds calendar events with attendees, reminders, and timeout overrides using OAuth tokens or service accounts.【F:includes/tools/class-wp-mcp-ai-tool-create-google-calendar-event.php†L17-L378】|
| Search Gmail Messages | `search_gmail` | Performs delegated Gmail queries with optional label filters and pagination, returning normalised message metadata. **Pro addon tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-search-gmail.php†L1-L200】|
| List JetEngine REST Routes | `list_jetengine_rest_routes` | Enumerates JetEngine REST endpoints with method, callback, and capability metadata for developers.【F:includes/tools/class-wp-mcp-ai-tool-list-jetengine-routes.php†L12-L151】|
| Invoke JetEngine REST Route | `invoke_jetengine_route` | Proxies JetEngine CRUD operations using the authenticated user context with REST/HTTP fallbacks.【F:includes/tools/class-wp-mcp-ai-tool-invoke-jetengine-route.php†L12-L133】|
| Run OpenAI External Action | `run_openai_external_action` | Triggers OpenAI Responses API workflows or assistants with payload sanitisation, timeout overrides, and structured errors.【F:includes/tools/class-wp-mcp-ai-tool-run-openai-external-action.php†L17-L211】|

### Operations & diagnostics
| Tool | Slug | Summary |
| --- | --- | --- |
| **Cron Management Suite** | | **AI agents can autonomously schedule, monitor, and manage WordPress background tasks** |
| Create Cron Job | `create_cron_job` | Schedules one-off or recurring WP-Cron events with duplicate detection and sanitised hooks/arguments. Agents can automate periodic maintenance, content publishing, or custom workflows by scheduling actions to run at specific times or intervals.【F:includes/tools/class-wp-mcp-ai-tool-create-cron-job.php†L16-L168】|
| List Cron Jobs | `list_cron_jobs` | Lists all scheduled WordPress cron jobs with details about schedule, next run time, and creator. Enables agents to provide visibility into scheduled automation tasks and audit what background processes are running.【F:includes/tools/class-wp-mcp-ai-tool-list-cron-jobs.php†L17-L141】|
| Get Cron Job | `get_cron_job` | Retrieves detailed information about a specific WordPress cron job by its job ID, including schedule interval details and execution metadata. Allows agents to inspect individual scheduled tasks for troubleshooting or reporting.【F:includes/tools/class-wp-mcp-ai-tool-get-cron-job.php†L17-L145】|
| Delete Cron Job | `delete_cron_job` | Deletes a scheduled WordPress cron job and removes it from both the plugin tracking and WP-Cron. Enables agents to cancel outdated or unnecessary automation tasks on behalf of operators.【F:includes/tools/class-wp-mcp-ai-tool-delete-cron-job.php†L17-L90】|
| **Cache Management** | | **AI agents can coordinate multi-layer cache invalidation** |
| Purge Cache | `purge_cache` | Master cache purge tool that coordinates multi-layer cache clearing (Cloudflare, Varnish, etc.) in the correct order. Agents can ensure content updates are properly reflected across all caching layers.【F:includes/tools/class-wp-mcp-ai-tool-purge-cache.php†L17-L150】|
| Purge Cloudflare Cache | `purge_cloudflare_cache` | Sends targeted or full-zone invalidations to Cloudflare with configurable timeouts and admin-only access controls.【F:includes/tools/class-wp-mcp-ai-tool-purge-cloudflare-cache.php†L17-L292】|
| Purge Varnish Cache | `purge_varnish_cache` | Purges the local Varnish cache with support for full-cache bans and specific URL purges. Agents can clear server-side caching to ensure immediate content updates.【F:includes/tools/class-wp-mcp-ai-tool-purge-varnish-cache.php†L17-L150】|
| **System Monitoring & Diagnostics** | | |
| Check Site Security | `check_site_security` | Checks if the WordPress site has security vulnerabilities that make it unsafe to use this AI plugin. Scans for common security issues and provides remediation guidance for administrators.【F:includes/tools/class-wp-mcp-ai-tool-check-site-security.php†L1-L200】|
| Check WP-CLI Status | `check_wp_cli` | Scans for the WordPress CLI binary, returning detected paths, version output, and environment warnings.【F:includes/tools/class-wp-mcp-ai-tool-check-wp-cli.php†L17-L309】|
| Count Tokens | `count_tokens` | Estimates token counts for text and messages using heuristic estimation (approximately 4 characters per token) for planning and budgeting purposes. Helps with capacity planning before sending requests to AI providers.【F:includes/tools/class-wp-mcp-ai-tool-count-tokens.php†L1-L200】|
| Get Site Summary | `get_site_summary` | Provides high-level site metadata, content counts, and admin contact details for context-aware assistants.【F:includes/tools/class-wp-mcp-ai-tool-get-site-summary.php†L12-L66】|
| Get MCP Environment Status | `get_environment_status` | Summarises WordPress versions, MCP defaults, assistant counts, and dependency warnings for incident response.【F:includes/tools/class-wp-mcp-ai-tool-get-environment-status.php†L12-L178】|
| Get Site Health Status | `get_site_health` | Runs WordPress Site Health diagnostics and returns grouped pass/warn/fail tests with remediation guidance.【F:includes/tools/class-wp-mcp-ai-tool-get-site-health.php†L12-L255】|
| Get System Logs | `get_system_logs` | Aggregates NV oOS logs, WordPress/PHP error logs, and plugin log files to aid in debugging workflows.【F:includes/tools/class-wp-mcp-ai-tool-get-system-logs.php†L12-L352】|
| Get Update Status | `get_update_status` | Reports pending core, plugin, and theme updates with version and download metadata for maintenance planning.【F:includes/tools/class-wp-mcp-ai-tool-get-update-status.php†L12-L182】|
| **Testing & Validation** | | |
| Probe Assistant Chat | `probe_chat` | Issues a chat probe against a published assistant to confirm sanitisation, configuration, and REST handling without consuming model tokens.【F:includes/tools/class-wp-mcp-ai-tool-probe-chat.php†L12-L178】|
| Probe Remote MCP REST | `probe_remote_mcp` | Reuses the remote connectivity tester to exercise `/assistants` and `/chat` on another site with optional bearer, guest, or nonce credentials.【F:includes/tools/class-wp-mcp-ai-tool-probe-remote-mcp.php†L12-L164】|
| **Mesh Networking** | | **Distributed compute pooling across WordPress sites** |
| Query Remote Site | `query_remote_site` | Executes chat requests on peer WordPress sites in a mesh network. Requires `manage_options` capability and mesh networking to be enabled. Coordinates server-to-server compute pooling with secure inter-site key authentication, enabling distributed AI workloads across trusted peers while maintaining user attribution and audit trails. Backend assistants use this tool to fan out work across the mesh on behalf of anonymous or authenticated users.【F:includes/tools/class-wp-mcp-ai-tool-query-remote-site.php†L1-L237】【F:docs/features/federation/mesh-compute-pooling.md†L1-L615】|
| Query Mesh (Intelligent Routing) | `query_mesh_intelligent` | Send a prompt to the mesh network with AI-powered peer selection and automatic failover. The system intelligently routes requests to the optimal peer site based on current load, response times, and task complexity. Provides resilient distributed compute with automatic retry logic.【F:includes/tools/class-wp-mcp-ai-tool-query-mesh-intelligent.php†L1-L300】|
| **Provider Dashboards** | | |
| Open OpenAI Logs | `open_openai_logs` | Returns dashboard shortcuts for reviewing OpenAI request logs in the provider console.【F:includes/tools/class-wp-mcp-ai-tool-open-openai-logs.php†L12-L66】|
| Open OpenAI Usage | `open_openai_usage` | Provides direct links to OpenAI usage dashboards so admins can audit consumption quickly.【F:includes/tools/class-wp-mcp-ai-tool-open-openai-usage.php†L12-L66】|
| **Authentication** | | |
| Generate Simple JWT Token | `generate_simple_jwt_token` | Generates a Simple JWT Login bearer token for the current user, enabling authenticated API access across sessions. Agents can help users obtain authentication tokens for headless WordPress integrations.【F:includes/tools/class-wp-mcp-ai-tool-generate-simple-jwt-token.php†L15-L120】|

### What the Cron Manager means to AI agents

The **Cron Management Suite** transforms AI assistants from reactive responders into proactive automation orchestrators. By providing full control over WordPress's background task scheduler, agents can:

**Autonomous Task Scheduling**
- Schedule content publishing workflows to go live at optimal times without human intervention
- Automate recurring maintenance tasks like cache clearing, database optimization, or backup operations
- Coordinate multi-step operations that span hours or days by chaining scheduled hooks

**Intelligent Monitoring & Self-Management**
- List and inspect all scheduled tasks to understand what automation is currently active
- Audit who created each task and when it's scheduled to run next
- Identify and remove outdated or redundant scheduled tasks to maintain system health

**Real-World Agent Workflows**
1. **Content Calendar Automation** - An agent helping with content strategy can schedule posts to publish at researched optimal engagement times, set up recurring social media cross-posts, and schedule follow-up email campaigns.
2. **Site Maintenance Orchestration** - When troubleshooting performance issues, agents can schedule off-peak cache purges, coordinate database cleanup tasks, and set up recurring health check notifications.
3. **Business Process Automation** - Agents can schedule recurring report generation, periodic data syncs with external systems, and automated backup verification checks.

**Technical Implementation**
The cron manager tracks all scheduled tasks in `wp_mcp_ai_cron_jobs` option with full audit trails including:
- Job ID for unique identification
- Hook name and sanitized arguments
- Schedule type (single-run or recurring interval)
- Creation timestamp and user attribution
- Next execution time for monitoring

Jobs are automatically pruned when they complete (single-run) or are manually removed (recurring), keeping the tracking database clean. All cron operations require `manage_options` capability, ensuring only authorized users can delegate automation authority to agents.【F:includes/class-wp-mcp-ai-cron-manager.php†L12-L280】

Each tool inherits the assistant context and authenticated user from the REST layer, making it easy to layer custom permissions or extend behaviour via the documented filters and actions.【F:includes/class-wp-mcp-ai-rest.php†L236-L360】【F:includes/class-wp-mcp-ai-rest.php†L1124-L1198】

Need per-tool prerequisites or capability callouts? Consult [`docs/reference/tools/tool-reference.md`](docs/reference/tools/tool-reference.md) for a detailed matrix of every bundled integration.

### Tool Status Labels

The Tools Manager page displays status labels beside tool names to indicate their development stage and stability:

| Status | Display Label | Description | Auto-Disable |
| --- | --- | --- | --- |
| **stable** | STA | Production-ready, fully tested tools safe for all environments | No |
| **beta** | BET | Testing phase, mostly stable but may have minor issues | No |
| **dev** | DEV | In active development, may have bugs or incomplete features | No |
| **experimental** | EXP | New features that may change significantly | No |
| **bug** | BUG | Known issues exist, use with caution | **Yes** |
| **deprecated** | DEP | Will be removed in future versions | No |

Status labels are displayed as **3-letter abbreviations** (e.g., "STA" for stable, "BET" for beta) to keep the UI compact.

**Important:** Tools marked with the `bug` status are **automatically disabled** when the plugin loads. This prevents problematic tools from being used until issues are resolved. Administrators can manually re-enable them from the Tools Manager if needed for testing.

Status labels are managed via the [`tool-status.txt`](docs/tool-status.txt) file in the repository. To assign a status label to a tool:

1. Open `docs/tool-status.txt` in a text editor
2. Add a line in the format: `tool_slug = status_label`
3. Save the file - changes appear immediately in the Tools Manager

Example:
```
create_post = stable
web_search = beta
generate_openai_image_validated = experimental
problematic_tool = bug
```

This file-based approach allows quick status updates without code changes, making it easy for maintainers to reflect tool maturity as development progresses. The automatic disabling of buggy tools provides an additional safety layer to prevent issues in production environments.


---

## 🗨️ Front-end chat surfaces

NV oOS ships multiple ways to embed assistants on the front end:

- **Classic chat shortcode** – `[mcp_ai_chat]` renders the bundled interface with attachment uploads, tool invocation feedback, and optional guest access via `allow_guests="true"`. When guest mode is enabled, the shortcode provisions a temporary token and injects it into the JavaScript bootstrap so visitors without WordPress accounts can continue chatting while still respecting capability checks and attachment safety limits.【F:includes/class-wp-mcp-ai-shortcode.php†L132-L258】【F:includes/class-wp-mcp-ai-shortcode.php†L188-L226】
- **Floating chat bubble** ⭐ **NEW** – A configurable floating button that sits at a screen corner and opens a chat panel powered by the `[mcp_ai_chat]` shortcode. Available as both an **Elementor widget** and a **Gutenberg block**. Supports 4 position variants, 3 sizes, auto-open delay, session persistence, dark mode, and WCAG keyboard navigation.【F:includes/elementor/class-wp-mcp-ai-elementor-chat-bubble-widget.php†L1-L200】【F:includes/blocks/chat-bubble/block.json†L1-L50】
- **[Elementor](https://be.elementor.com/visit/?bta=229888&brand=elementor) widgets** – Drop the chat UI anywhere Elementor is active, pair it with intro/FAQ blocks, and surface dashboard telemetry without custom code. The chat widget mirrors the shortcode controls (including `allow_guests`), and companion widgets expose onboarding content, usage timers, provider quick links, and activity feeds for operational views.【F:includes/elementor/class-wp-mcp-ai-elementor-widget.php†L79-L138】【F:includes/class-wp-mcp-ai-elementor-integration.php†L48-L98】【F:includes/elementor/class-wp-mcp-ai-elementor-chat-intro-widget.php†L47-L140】【F:includes/elementor/class-wp-mcp-ai-elementor-chat-usage-timer-widget.php†L48-L226】【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-activity-feed-widget.php†L48-L167】

Guest tokens are honoured by the REST endpoints through the `X-WP-MCP-AI-Guest` header or `guest_token` parameter, allowing the chat shortcode and [Elementor](https://be.elementor.com/visit/?bta=229888&brand=elementor) widget to make authenticated requests on behalf of public visitors without exposing persistent credentials.【F:includes/class-wp-mcp-ai-rest.php†L289-L307】【F:includes/class-wp-mcp-ai-rest.php†L2088-L2104】

### Chat History Persistence

The chat interface automatically persists conversation history to the browser's localStorage, preventing data loss when users navigate away or refresh the page. Conversations are:

- **Automatically saved** after each user message and assistant response
- **Automatically restored** when returning to the chat page (within 24 hours)
- **Stored per assistant** so different assistant conversations remain separate
- **Server-side storage available with JetEngine** - See note below about optional JetEngine integration

#### Server-Side Chat Transcript Storage (Requires JetEngine)

⚠️ **Third-Party Plugin Required:** [JetEngine](https://crocoblock.com/plugins/jetengine/?ref=16658) (not included with NV oOS)

Without JetEngine, chat conversations are **only stored in browser localStorage** (client-side, 24-hour retention). To enable permanent server-side chat transcript archiving:

1. Install and activate the [JetEngine](https://crocoblock.com/plugins/jetengine/?ref=16658) plugin (third-party, paid plugin from Crocoblock)
2. Enable the **Custom Content Types** module in JetEngine settings
3. NV oOS will automatically provision the `ai_chat_transcripts` CCT for permanent storage

**What you get with JetEngine:**
- ✅ Permanent server-side chat transcript storage
- ✅ Cross-device conversation access
- ✅ Admin visibility into chat history
- ✅ Database-backed chat logs for compliance/auditing

**Without JetEngine:**
- ⚠️ Chat history only stored in browser localStorage
- ⚠️ Limited to 24-hour retention
- ⚠️ No cross-device synchronization
- ⚠️ Lost if browser data is cleared

See [docs/user-guides/chat/chat-history-persistence.md](docs/user-guides/chat/chat-history-persistence.md) for complete details on the persistence mechanism, data structure, and troubleshooting.

---

## 📦 Installation

> **ℹ️ Plugin Directory Status**  
> This plugin is currently **pending approval** in the WordPress Plugin Directory. We are committed to maintaining high quality and security standards throughout the review process. You can install the plugin manually from our [GitHub repository](https://github.com/nvdigitalsolutions/mcp-ai-wpoos) or wait for the official WordPress Plugin Directory listing.

> **🚀 Getting Started Wizard**  
> After activating the plugin, you'll be redirected to a **4-step setup wizard** that walks you through connecting an AI provider, choosing a use case, and creating your first assistant — all in under 2 minutes. The wizard creates fully-configured assistants with tools, system prompts, and tuned temperatures so your site is working out of the box. You can access the wizard any time at **NV oOS → Getting Started** or directly at `/wp-admin/admin.php?page=wp-mcp-ai-getting-started`.

### 🌱 Beginner 3-Step Install (Try it on Your PC)

> **No live site. No API costs. No risk.** The fastest way to try NV oOS is on your own computer using a free local WordPress environment. Follow the full walkthrough on the NV Digital Solutions blog: **[How to Test NV oOS on Your Own PC Using Local + Downloading the Plugin →](https://nvdigitalsolutions.com/ai-llm/how-to-test-nv-oos-on-your-own-pc-using-local-downloading-plugin/)**

1. **Install a local WordPress environment** — [Local by WP Engine](https://localwp.com/) or [WordPress Studio](https://developer.wordpress.com/studio/) is the easiest option (one-click install, no server config). Alternatives: [XAMPP](https://www.apachefriends.org/), [MAMP](https://www.mamp.info/), or [DevKinsta](https://kinsta.com/devkinsta/).

2. **Download the NV oOS plugin zip** — grab the latest release from [GitHub Releases](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/releases) (look for `mcp-ai-wpoos-x.x.x.zip`), or use the **Code → Download ZIP** button for the current development snapshot.

3. **Upload, activate, and run the wizard** — in your local WordPress dashboard go to **Plugins → Add New → Upload Plugin**, select the zip, activate it, and follow the [🚀 Getting Started Wizard](#-installation). For a free local AI model (no API key needed), install [LM Studio](https://lmstudio.ai/) and point the wizard at `http://localhost:1234`.

> **🗺 Single-file auto-installer (roadmap)** — A single cross-platform installer that bootstraps the entire stack automatically is on the roadmap. See the [App / Plugin Distribution Proposal](docs/project/proposals/NVOOS_APP_PLUGIN_DISTRIBUTION_PROPOSAL.md) for current status and the plan.

### Requirements

**Minimum Requirements:**
- WordPress 6.0+
- PHP 7.4+ (PHP 8.0+ recommended)
- MySQL 5.7+ or MariaDB 10.3+

**Optional Requirements for Enhanced Features:**
- **Node.js 14+**: Required for image vectorization tools (`vectorize_image` tool)
- **PHP Functions**: `proc_open`, `proc_close`, `proc_terminate` (for Node.js integration and Process Service)
  - These functions are often disabled on shared hosting for security
  - Can be enabled on Cloudways via Application Settings (see [troubleshooting guide](docs/getting-started/installation-setup/deployment-troubleshooting.md))
- **JetEngine Plugin**: For CCT storage and advanced content management tools
- **WooCommerce**: For e-commerce integration tools
- **Elementor**: For visual page builder widgets

**Note**: The plugin works without optional requirements, but some features will be disabled. See [deployment troubleshooting](docs/getting-started/installation-setup/deployment-troubleshooting.md) for enabling disabled PHP functions.

### For Developers (GitHub Clone)

> **✅ Production-Ready Repository**  
> This repository includes production-optimized vendor dependencies with classmap-authoritative autoloading configured by default in composer.json. You can clone and activate immediately without running composer. The `composer install` command is only needed if you want to update dependencies or add development tools.

> **⚡ Use a shallow clone**  
> A full clone of this repository is ~10 GB due to its long history. Use `--depth 1` to download only the latest snapshot (~500 MB) — much faster and smaller. If you later need the full history, run `git fetch --unshallow`.
>
> ```bash
> git clone --depth 1 https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git
> ```

If you're cloning from GitHub:

#### Option 1: Cloudways and Managed Hosting (Recommended)

For Cloudways and similar managed hosting platforms, clone directly into the WordPress plugins directory:

```bash
# SSH into your server
# Navigate to WordPress plugins directory
cd /home/master/applications/YOURAPP/public_html/wp-content/plugins/

# Clone the repository (production-ready, no composer needed!)
# Use --depth 1 for a fast shallow clone (recommended for production)
git clone --depth 1 https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git
cd mcp-ai-wpoos

# Verify you're in the correct directory
pwd  # Should show the plugins path

# Optional: Only needed for frontend asset rebuilding or development
# npm install && npm run build

# Optional: Only run if you need to update dependencies or add dev tools
# Note: Autoloader optimization is now configured by default in composer.json
# composer install --no-dev
```

**⚠️ Cloudways Important Notes:**
- Always clone directly into `/home/master/applications/YOURAPP/public_html/wp-content/plugins/`
- Do NOT clone elsewhere and then move/copy - this causes `getcwd() failed` errors
- Replace `YOURAPP` with your actual Cloudways application name

#### Option 2: Local Development or VPS

For local development or standard VPS hosting:

```bash
# Option A: Clone directly into WordPress plugins directory (recommended, production-ready!)
cd /path/to/wordpress/wp-content/plugins/
# Use --depth 1 for a fast shallow clone (recommended for production)
git clone --depth 1 https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git
cd mcp-ai-wpoos
# Ready to activate! No composer or npm needed for production use.

# Option B: Clone and copy (also production-ready!)
# Use --depth 1 for a fast shallow clone (recommended for production)
git clone --depth 1 https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git
cd mcp-ai-wpoos
cp -r . /path/to/wordpress/wp-content/plugins/mcp-ai-wpoos/
```

**For Development Only:**
```bash
# Only if you need to rebuild assets or modify dependencies:
npm install && npm run build
# Note: Autoloader optimization is now configured by default in composer.json
composer install --no-dev
```

#### Optional: Strip Dev Files for Production

If you are deploying via `git clone` to a **production server with anti-malware / EDR scanning**, the working tree will contain test fixtures that embed verbatim attack-payload literals (XSS canaries, SQL-injection samples, prompt-injection strings) used by the security test suite. These can occasionally trip signature-based scanners.

For a clean production tree, run the bundled strip script after cloning:

```bash
# Preview what would be removed
bin/strip-dev-files.sh --dry-run

# Remove tests/, docs/, bin/, .github/, .bmad/, .context/, examples/,
# phpunit.xml.dist, phpcs.xml.dist, dev configs, etc.
bin/strip-dev-files.sh
```

The script mirrors the exclusion list in `.distignore` (used for the WordPress.org SVN deploy) and the `export-ignore` rules in `.gitattributes` (used for GitHub-distributed ZIPs). It is idempotent and refuses to run on a working tree with uncommitted changes (override with `--force`).

> **Note:** Do not run this on a development checkout — it removes the test suite, docs, and build tooling. It is intended for deploy targets that only run the plugin.

#### Final Steps

1. Activate **Open Operator System Complete (NV oOS)** from WordPress admin
2. You now have the **complete version** with all ~990 tools (~195 base + ~795 Pro; live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative)

**What you get from the repository clone:**

- ✅ The full codebase — all ~990 built-in tools ready to use (~195 base + ~795 Pro; live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative)
- ✅ Single plugin activation (not separate base + pro)
- ✅ Pro features automatically available (no separate Pro plugin to install)

**Notes**: 
- The repository includes `mcp-ai-wpoos-base.php` and `addons/pro/mcp-ai-wpoos-pro.php` which are used for building separate distributions but do NOT appear as separate plugins when cloning
- Only the main plugin file (`mcp-ai-wpoos.php`) has a plugin header in the repository
- The build script adds headers to the other files when creating standalone distributions

### Standard Installation
1. Upload `mcp-ai-wpoos.zip` to `/wp-content/plugins/`
2. Activate **NV oOS** from the WordPress admin
3. Go to **Settings → NV oOS**
4. Enter your OpenAI API key
5. Create a new “AI Assistant” in **AI Assistants**
6. Add `[mcp_ai_chat assistant="123"]` to a page or post

### Optional: JetEngine Integration

⚠️ **Third-Party Plugin (Not Included):** [JetEngine](https://crocoblock.com/plugins/jetengine/?ref=16658) is a paid plugin from Crocoblock

**JetEngine is completely optional** - NV oOS works perfectly without it. However, if you want server-side chat transcript storage:

1. Purchase and install [JetEngine](https://crocoblock.com/plugins/jetengine/?ref=16658) separately
2. Enable the **Custom Content Types** module in JetEngine settings
3. NV oOS will automatically provision the `ai_chat_transcripts` CCT for permanent chat storage

**What works WITHOUT JetEngine:**
- ✅ All core AI assistant features
- ✅ Chat interface and conversations
- ✅ ~201 base tools (more with optional third-party plugins)
- ✅ MCP server functionality (`/wp-json/mcp-ai/v1/`)
- ✅ Browser-based chat history (localStorage, 24 hours)
- ✅ OpenAI/Gemini/Anthropic/Ollama/Hugging Face/Cloudflare integrations

**What requires JetEngine:**
- ❌ Server-side chat transcript storage (chat history only in browser without it)
- ❌ 5 JetEngine-specific tools (see [🔌 Optional Tools & Dependencies](#optional-tools-dependencies))

---

## 🔌 What You Lose Without Third-Party Plugins

NV oOS works perfectly with vanilla WordPress, but certain features require third-party plugins (sold separately). Here's exactly what you lose without each plugin:

### Without JetEngine (Crocoblock - Paid Plugin)

**Lost Features:**
- ❌ **AI metaboxes for JetEngine CPTs/Taxonomies** - No AI assistant integration on JetEngine edit screens
- ❌ **Research & Add pages** - No AI-powered content creation with automatic field mapping
- ❌ **Server-side chat transcript storage** - Chat history only stored in browser localStorage (24 hours)
- ❌ **Cross-device chat synchronization** - No database-backed conversation history
- ❌ **Admin chat history access** - Cannot view/audit conversations from admin panel
- ❌ **Assistant CCT synchronization** - Assistants only in WordPress CPT (MCP server still works perfectly)

**Lost Tools (5 tools):**
- `get_jetengine_items` - Query JetEngine custom post types
- `list_jetengine_rest_routes` - List JetEngine REST API routes
- `invoke_jetengine_route` - Execute JetEngine REST operations
- `get_jetformbuilder_forms` - List JetFormBuilder forms (also requires JetFormBuilder)
- `get_jetformbuilder_submissions` - Get form submissions (also requires JetFormBuilder)

**✅ Still Works:** All core features, MCP server, ~201 base tools, AI conversations

[Get JetEngine →](https://crocoblock.com/plugins/jetengine/?ref=16658)

---

### Without WooCommerce (Free Plugin)

**Lost Features:**
- ❌ **E-commerce automation** - Cannot create or manage products via AI
- ❌ **Order management** - Cannot query or analyze orders
- ❌ **Product catalog access** - Cannot search or update product data

**Lost Tools (3 tools):**
- `create_woo_product` - Build draft WooCommerce products with AI-generated descriptions, pricing, and images
- `get_woo_products` - Search and retrieve product catalog with pricing and stock status
- `get_woo_recent_orders` - Summarize recent orders with billing details and totals

**Use Cases Lost:** E-commerce content generation, order fulfillment assistance, product merchandising

[Get WooCommerce →](https://wordpress.org/plugins/woocommerce/)

---

### Without Elementor (Freemium Plugin)

**Lost Features:**
- ❌ **Template management** - Cannot list or reference Elementor templates via AI
- ❌ **Elementor widgets** - Cannot use pre-built chat/dashboard widgets (shortcodes still work)

**Lost Tools (2 tools):**
- `get_elementor_templates` - List Elementor library templates with status, type, and edit links
- `import_elementor_template_kit` - Import Elementor template kits

**Lost UI Components:**
- Elementor Chat Widget
- Elementor Chat Intro Widget
- Elementor Dashboard Widgets (Tool Matrix, User Capabilities, Activity Feed, etc.)

**✅ Still Works:** Standard `[mcp_ai_chat]` shortcode, all AI features

[Get Elementor →](https://be.elementor.com/visit/?bta=229888&brand=elementor)

---

### Without Rank Math SEO (Freemium Plugin)

**Lost Features:**
- ❌ **SEO analysis** - Cannot query SEO scores or optimization recommendations
- ❌ **Schema data access** - Cannot retrieve structured data for posts

**Lost Tools (1 tool):**
- `get_rankmath_seo` - Get SEO scores, focus keywords, robots metadata, and schema details for posts

**Use Cases Lost:** AI-powered SEO content optimization, SEO audit assistance

[Get Rank Math →](https://wordpress.org/plugins/seo-by-rank-math/)

---

### Without WPCode (Freemium Plugin)

**Lost Features:**
- ❌ **Code snippet management** - Cannot create or update code snippets via AI
- ❌ **Custom functionality automation** - Cannot automate adding hooks, filters, or custom code

**Lost Tools (1 tool):**
- `create_wpcode_snippet` - Create or update code snippets with validation and activation control

**Use Cases Lost:** AI-assisted custom development, automated code snippet generation

[Get WPCode →](https://wordpress.org/plugins/insert-headers-and-footers/)

---

### Without Simple JWT Login (Free Plugin)

**Lost Features:**
- ❌ **JWT token generation** - Cannot generate JWT bearer tokens for headless WordPress integrations

**Lost Tools (1 tool):**
- `generate_simple_jwt_token` - Generate JWT bearer tokens for authenticated API access

**Use Cases Lost:** Headless WordPress authentication, mobile app integration, SPA authentication

[Get Simple JWT Login →](https://wordpress.org/plugins/simple-jwt-login/)

---

### Summary: Third-Party Plugin Dependencies

| Plugin | Type | Tools Lost | Key Feature Lost |
|--------|------|------------|------------------|
| **JetEngine** | Paid (Crocoblock) | 5 | Server-side chat transcript storage |
| **WooCommerce** | Free | 3 | E-commerce automation |
| **[Elementor](https://be.elementor.com/visit/?bta=229888&brand=elementor)** | Freemium | 2 + Widgets | Elementor template integration |
| **Rank Math** | Freemium | 1 | SEO analysis |
| **WPCode** | Freemium | 1 | Code snippet management |
| **Simple JWT Login** | Free | 1 | JWT token generation |

**Total Impact:** Without these plugins, you lose **13 tools** but retain **74 core tools** and all essential AI assistant functionality.

---

### Base Version (Default)

**NV oOS runs in Base Version mode by default**, providing 95 essential tools that work with vanilla WordPress without requiring any third-party plugins:

**Base Version includes 95 essential tools that work with vanilla WordPress:**
- Content management (search, save posts, attachments)
- AI media generation (images via OpenAI/Gemini, speech, transcription, video)
- Research tools (web search, weather, disaster alerts)
- Site operations (health checks, logs, cron jobs, cache management)
- WordPress-native email (via wp_mail)
- Image manipulation (resize, crop, rotate, convert, vectorize to SVG)
- Graphic editing (local operations and AI-powered transformations)
- Profession and assistant management
- GitHub integration tools
- Google Maps Platform tools

**Base Version excludes 31 tools requiring third-party plugins or external APIs:**
- **Third-party WordPress plugins** (13 tools) - See [🔌 What You Lose Without Third-Party Plugins](#what-you-lose-without-third-party-plugins) for details
  - WooCommerce tools (3)
  - JetEngine/JetFormBuilder tools (5)
  - Elementor tools (2)
  - RankMath/WPCode/Simple JWT Login tools (3)
- **External API services** (18 tools) - Require API credentials
  - Google services (5)
  - Social media integrations (8)
  - External messaging services (4)
  - QuickBooks (1)

### Full Version Installation (Opt-in)

To enable the **Full Version** with all third-party integrations and external API tools, add this constant to your `wp-config.php` file:

```php
define( 'WP_MCP_AI_BASE_VERSION', false );
```

📖 See BASE-VERSION.md for the complete tool list and customization options.

**When to use Base Version:**
- Starting fresh with WordPress
- Testing or development environments
- Simpler installations without external dependencies
- Sites that don't need e-commerce or advanced integrations
- Don't want to purchase/install third-party plugins

**When to use Full Version:**
- Production sites with WooCommerce, JetEngine, or Elementor already installed
- Sites needing social media automation (requires API credentials)
- Advanced workflows requiring external APIs
- Need server-side chat transcript storage (requires JetEngine)

📖 **See detailed breakdown:** [🔌 What You Lose Without Third-Party Plugins](#what-you-lose-without-third-party-plugins)


---

## 📚 Documentation

NV oOS includes comprehensive documentation covering all aspects of the plugin. **Documentation reorganized June 2026** — Unix-theory separation of concerns. All docs sorted into 12 purpose-driven directories with zero content loss.

### 📖 Documentation Hub
- **[Documentation Hub](docs/README.md)** ⭐ **Start here** - Central navigation with organized categories
- **[Documentation Index](docs/DOCUMENTATION_INDEX.md)** - Complete map of all 1,600+ documentation files
- **[Architecture Overview](docs/developer/architecture/ARCHITECTURE.md)** - System architecture (13 providers, ~990 tool classes, 36 REST controllers)
- **[Request Flow Walkthrough](docs/developer/architecture/REQUEST-FLOW-WALKTHROUGH.md)** - End-to-end chat request lifecycle trace
- **[Quick Reference Guide](docs/QUICK_REFERENCE.md)** - Fast access to common tasks and commands

### Essential References
- **[Tool Reference](docs/reference/tools/tool-reference.md)** - All ~990 tools documented (~195 base + ~795 Pro; live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative)
- **[REST API Documentation](docs/reference/api/rest-api.md)** - Complete API reference with examples
- **[Testing & Quality Report](docs/developer/testing-docs/TESTING_AND_QUALITY_REPORT.md)** - Test results and code quality analysis

### 📦 Archive
- **[Historical Documentation](docs/history/archive/2025/README.md)** — 50+ archived files from 2024-2025 development
- **[Docs Archive](docs/history/archive/)** - Consolidated implementation history and superseded documentation

### For New Users
- **🚀 [Getting Started Wizard](#-installation)** ⭐ NEW — 4-step guided setup that connects your AI provider, selects a use case, and creates a ready-to-use assistant in under 2 minutes. 8 presets available: Content Creator, Customer Support, E-commerce, SEO & Research, Developer Copilot, Media & Creative Studio, Site Administrator, General Purpose.
- **[Use Cases & Quickstart Guides](docs/getting-started/USE_CASES_AND_QUICKSTARTS.md) ⭐ NEW** - Comprehensive guide covering 7 major use cases with step-by-step quickstarts
- [5-Minute Quick Start](docs/getting-started/QUICK_START_5_MINUTES.md) - Get started immediately: from zero to first chat
- [Setup Checklist](docs/getting-started/installation-setup/mcp-ai-plugin-setup-checklist.md) - Step-by-step installation and configuration
- [Remote Client Quickstart](docs/getting-started/quick-starts/remote-client-quickstart.md) - Connect Claude Desktop, LM Studio, or other MCP clients
- [Best Practices](docs/developer/best-practices/BEST_PRACTICES.md) - Recommended usage patterns and optimization tips

### For Developers
- **[Testing & Quality Report](docs/developer/testing-docs/TESTING_AND_QUALITY_REPORT.md)** - Test suite results (2,106 tests, 73.4% pass rate), code quality analysis, security audit
- [Code Review Master](docs/developer/best-practices/CODE-REVIEW-MASTER.md) - Comprehensive code quality analysis (95/100 score)
- [Action Items](docs/history/2025/summaries/ACTION_ITEMS.md) - Prioritized development tasks (180+ hours)
- [Authentication Guide](docs/reference/api/mcp-server-authentication.md) - Authentication methods and security
- [MCP JSON-RPC 2.0 Endpoint](docs/reference/api/mcp-endpoint.md) - Model Context Protocol implementation

### For Administrators
- [Deployment Troubleshooting](docs/getting-started/installation-setup/deployment-troubleshooting.md) - Common issues and solutions
- [Multisite Support](docs/getting-started/installation-setup/multisite-support.md) - WordPress multisite configuration
- [Rate Limit Protection](docs/features/performance/rate-limit-protection.md) - API rate limiting setup
- [Mesh Routing Guide](docs/features/federation/mesh-routing-guide.md) - Intelligent compute routing across sites and providers
- [Federation & Discovery](docs/features/federation/federation-discovery.md) - Decentralized AI capability network with peer discovery and well-known endpoints

### Performance & Optimization
- [Message Bundling](docs/user-guides/chat/message-bundling-feature.md) - Client-side message optimization
- [High Token Tool Handling](docs/features/tools/presets/high-token-tool-handling.md) - Agentic loop token management
- [Job Notification System](docs/features/async-jobs/job-notification-system.md) - Real-time async job updates
- [Chat Performance Optimizations](docs/features/chat/chat-performance-optimizations.md) - Complete performance guide
- [Mesh Routing Guide](docs/features/federation/mesh-routing-guide.md) - Intelligent compute routing across sites and providers

### Historical Documentation
- **[Archive Directory](docs/history/archive/)** - 95+ historical documents organized by category:
  - `implementations/` - Implementation summaries and technical details
  - `phases/` - Development phase documents
  - `fixes/` - Bug fix summaries and issue resolutions
  - `features/` - Feature documentation
  - `code-reviews/` - Code review reports
  - `testing/` - Test infrastructure documentation

---

## ⚙️ Configuration Checklist (Action Items)

Complete these after installation to unlock every integration point:

- [ ] **Add your OpenAI API key** in **Settings → NV oOS → OpenAI API Key** so API calls are authorised.
- [ ] **Add your Gemini API key** in **Settings → NV oOS → Gemini API Key** if you plan to route assistants through Gemini.
- [ ] **Confirm or override the default model** via **Settings → NV oOS → Default Model** (`gpt-4.1` ships as the default).
- [ ] **Set a default Gemini model** under **Settings → NV oOS → Default Gemini Model** when Gemini is enabled.
- [ ] **Choose the default provider** from **Settings → NV oOS → Default Provider** so new assistants know whether to use OpenAI or Gemini by default.
- [ ] **Adjust the request timeout** under **Settings → NV oOS → Request Timeout** (minimum 5 s, default 30 s) to match your hosting environment.
- [ ] **Select a default assistant** with **Settings → NV oOS → Default Assistant** so REST and shortcode requests have a fallback.
- [ ] **Decide on logging** with **Settings → NV oOS → Enable Logging** when you need verbose diagnostics.
- [ ] **Monitor token usage** in **Settings → NV oOS → Token Usage Statistics** to track API consumption across users, providers, and models for billing and budget management.
- [ ] **Choose your uninstall behaviour** via **Settings → NV oOS → Remove Data on Uninstall** if this site should purge assistants and settings during cleanup.
- [ ] **Configure Crawl4AI access** in **Settings → NV oOS → Tools** when you want the Crawl4AI tool to be available to assistants.
- [ ] **Review attachment MIME overrides** in **Settings → NV oOS → Attachments** before enabling file uploads for end users.
- [ ] **Review Send Group Email permissions** in **Settings → NV oOS → Tools** to choose the capability and recipient cap for the group email automation.【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L348-L359】【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L938-L953】
- [ ] **Connect Gmail** under **Settings → NV oOS → Tools → Connections → Gmail** to enable Gmail search tools with OAuth 2.0. See [Google OAuth Setup Guide](docs/getting-started/installation-setup/google-oauth-setup.md) for complete configuration steps.
- [ ] **Connect QuickBooks Online** under **Settings → NV oOS → QuickBooks Company ID / API Key** so the bundled reporting tool can fetch finance statements for authorised operators.【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L906-L955】
- [ ] **Configure Mailjet credentials** in **Settings → NV oOS → Mailjet API Key / Secret / From Email / From Name** before enabling Mailjet-powered tools or Elementor widgets that send email on behalf of assistants.【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L1008-L1054】
- [ ] **Enable Federation & Discovery** (Optional) in **Settings → NV oOS → Federation & Discovery** to publish your site's AI capabilities via `/.well-known/ai-peer` and optionally run a directory service for peer discovery. Configure regions, data tags, and rate limits to control how your site participates in the decentralized AI network.【F:docs/features/federation/federation-discovery.md†L1-L511】【F:FEDERATION-IMPLEMENTATION-SUMMARY.md†L1-L381】
- [ ] **Configure Root Security Key** (Optional) by adding `define( 'WP_MCP_AI_ROOT_SECURITY_KEY', 'your-secure-key' );` to wp-config.php. This provides an additional security layer that can be enabled during emergency shutdown to require authentication before re-initializing the plugin.【F:docs/features/security/root-security-key.md†L1-L511】
- [ ] **Enable Pro Dashboard** (Optional) by adding `define( 'WP_MCP_AI_PRO_DASHBOARD_ENABLED', true );` to wp-config.php. This activates the dedicated Pro Dashboard with ISO/IEC 27001 compliance monitoring, reporting, and management tools. See [Pro Dashboard Documentation](docs/operations/compliance/iso27001/PRO-DASHBOARD-IMPLEMENTATION.md) for details.

## 🧠 Language Model Providers (OpenAI, Gemini, Anthropic, Baseten, DeepSeek, OpenRouter, Kimi, DigitalOcean, NVIDIA NIM, Ollama, LM Studio, Hugging Face, Cloudflare)

A dedicated router transparently forwards chat completions to the active provider, allowing each request to target OpenAI, Gemini, Anthropic, DeepSeek, OpenRouter, Baseten, Kimi, DigitalOcean, NVIDIA NIM, a local Ollama instance, LM Studio, Hugging Face, or Cloudflare Worker AI while sharing the same assistant UX.【F:includes/class-wp-mcp-ai-language-model-router.php†L12-L86】 Configure the required API keys, default models, and the global default provider in **Settings → NV oOS** so new assistants inherit sensible defaults and administrators can switch providers without code changes.【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L124-L333】【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L505-L530】 Assistants can still override provider, model, and generation parameters on a per-post basis.

**Privacy & Terms:** All AI providers have specific terms and privacy policies:
- **OpenAI**: [Terms](https://openai.com/policies/terms-of-use) | [Privacy](https://openai.com/privacy)
- **Google Gemini**: [Terms](https://ai.google.dev/terms) | [Privacy](https://ai.google.dev/privacy)
- **Anthropic**: [Terms](https://www.anthropic.com/legal/consumer-terms) | [Privacy](https://www.anthropic.com/legal/privacy)
- **NVIDIA NIM**: [Terms](https://www.nvidia.com/en-us/data-center/products/nvidia-ai-enterprise/eula/) | [Privacy](https://www.nvidia.com/en-us/about-nvidia/privacy-policy/)
- **Cloudflare**: [Terms](https://www.cloudflare.com/terms/) | [Privacy](https://www.cloudflare.com/privacypolicy/)
- **Hugging Face**: [Terms](https://huggingface.co/terms-of-service) | [Privacy](https://huggingface.co/privacy)
- **Ollama/LM Studio**: Self-hosted (no external data transmission)
- **Baseten**: [Terms](https://www.baseten.co/terms-and-conditions/) | [Privacy](https://www.baseten.co/privacy-policy/)

### LM Studio Support

**LM Studio with Function Calling** - Full support for OpenAI-compatible function calling with local LM Studio instances:
- OpenAI-compatible message structure preserved for tool calls
- Tools/functions can be invoked by LM Studio models (e.g., qwen/qwen3-coder-30b)
- Streaming automatically disabled when tools are present for reliable execution
- Full backward compatibility with non-tool scenarios
- Connect via JSON-RPC endpoint (recommended) or SSE streaming
- See [LM Studio setup guide](#-lm-studio-setup) for configuration details

### Provider Priority List & Automatic Fallback

The plugin includes an intelligent provider priority system that automatically tries alternative providers when the primary one fails or is unavailable. In **Settings → NV oOS**, you can:

- **Drag and drop** providers to set your preferred order
- **Automatic fallback** - if the first provider fails, the system tries the next one
- **Visual management** - see all available providers (OpenAI, Gemini, Anthropic, Baseten, DeepSeek, OpenRouter, Kimi, DigitalOcean, NVIDIA NIM, Ollama, LM Studio, Hugging Face, Cloudflare) in one sortable list
- **Flexible prioritization** - adjust based on cost, performance, or availability needs

The first provider in the list serves as the default. If any provider returns an error, the router automatically attempts the next provider in the list until one succeeds. This ensures maximum uptime and resilience without manual intervention. All fallback attempts are logged for debugging and monitoring.

### Local AI with Ollama

The Ollama provider enables privacy-focused, cost-free AI processing by connecting to a local Ollama or LM Studio instance running on your server or development machine. This is ideal for:
- **Privacy-sensitive deployments** where data must stay on-premises
- **Development and testing** without incurring API costs
- **Custom or fine-tuned models** not available through cloud providers
- **Air-gapped environments** without internet access

To configure Ollama:
1. Install [Ollama](https://ollama.ai) on your server or local machine
2. Pull a model (e.g., `ollama pull llama2`)
3. Navigate to **Settings → NV oOS → Ollama Configuration**
4. Enter your Ollama endpoint URL (default: `http://localhost:11434`)
5. Click "Test Connection" to verify connectivity
6. Click "Fetch Models" to see available models
7. Select a model from the list or manually enter a model name
8. Set "Default Provider" to "Ollama (Local AI)" if you want it as the system default

The Ollama client supports the standard chat completion flow and automatically normalizes responses to match the OpenAI format for downstream compatibility. Note that some advanced features like tool calling may vary depending on the specific Ollama model you're using.

### OpenAI model coverage

The plugin ships with presets for OpenAI’s current Responses, Reasoning, Audio, and Image APIs so site owners can choose the right model for each workflow. Token windows describe the maximum request size (messages, attachments, and tool payloads) the OpenAI API will accept for that model, while output limits reflect the largest single response the service will stream back. Leave a safety margin below each ceiling so assistants can add system instructions, tool calls, and knowledge snippets without hitting provider limits.

| Capability | Model | Max context tokens | Max output tokens | Notes |
| --- | --- | --- | --- | --- |
| Responses (flagship) | `gpt-5.2` | 400,000 | 128,000 | Latest flagship multimodal model with 400K context window (Dec 2025). Ideal for large documents and complex workflows. |
| Responses (pro reasoning) | `gpt-5.2-pro` | 400,000 | 128,000 | Advanced reasoning variant with enhanced capabilities for mission-critical tasks requiring maximum accuracy. |
| Responses (high throughput) | `gpt-5.2-instant` | 400,000 | 128,000 | High-volume optimized variant for customer support and content generation at scale. |
| Responses (deep analysis) | `gpt-5.2-thinking` | 400,000 | 128,000 | Deeper analysis variant with reasoning time dial for multi-step analysis and research tasks. |
| Responses (general) | `gpt-4.1` | 128,000 | 16,384 | Flagship multimodal model that balances quality and latency for production chat, tool, and multimodal calls. |
| Responses (cost optimised) | `gpt-4.1-mini` | 128,000 | 16,384 | Budget-friendly 4.1 variant recommended for day-to-day assistants and background automations. |
| Responses (advanced) | `gpt-4o` | 128,000 | 16,384 | Previous generation multimodal model with strong reasoning capabilities. |
| Responses (legacy) | `gpt-4o-mini` | 128,000 | 16,384 | Lower-latency 4o tier that keeps the larger context window while reducing cost for iterative workflows. |
| Reasoning | `o1-preview` | 128,000 | 32,768 | Deliberate reasoning model suited to multi-step planning and analysis; expect slower responses while it “thinks”. |
| Reasoning (fast) | `o1-mini` | 128,000 | 32,768 | Lighter o1 variant that trades some reasoning depth for responsiveness in operational assistants. |

#### Media and multimodal defaults

| Capability | Model | Size or duration limits | Notes |
| --- | --- | --- | --- |
| Image generation | `gpt-image-2` (default) / `gpt-image-1.5` / `gpt-image-1` / `dall-e-3` | Up to 2048×2048 output (square) or 2048×1152 / 1152×2048 (16:9 / 9:16) for `gpt-image-2`; proportional 1024 / 512 variants for older models | `gpt-image-2` ("Images 2.0") is the default as of v1.1.13 — native 2K resolution, multi-image coherency, and accurate multilingual text rendering. Older models remain selectable. Respect OpenAI's safety filters when prompting. |
| Text-to-speech | `gpt-4o-mini-tts` | Up to ~4,096 input tokens per request | Generates natural-sounding speech in multiple voices; longer scripts should be chunked into multiple calls. |
| Speech-to-text | `gpt-4o-mini-transcribe` | Optimised for recordings ≤ 90 minutes | Handles multilingual transcription and translation; large files are automatically chunked client-side before upload. |

OpenAI regularly revises token policies and media limits, so review the [model specification dashboard](https://platform.openai.com/docs/models) before rolling out new assistants or increasing attachment budgets. Updating your defaults in **Settings → NV oOS** keeps every assistant aligned with the latest provider guidance.【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L36-L105】【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L2298-L2398】

## 🧱 ChatKit Integration

The [ChatKit](https://github.com/nvdigitalsolutions/chatkit) module now ships with the core NV oOS plugin, so no separate add-on installation is required. Once enabled it self-registers through ChatKit’s filter and action APIs as soon as both plugins load, exposing the `mcp-ai/v1` REST namespace while advertising chat, tool invocation, attachment download, and guest token support without any manual bootstrapping. Return `false` from the `wp_mcp_ai_chatkit_is_available` filter if you need to disable the automatic registration for bespoke environments.【F:includes/class-wp-mcp-ai-chatkit-integration.php†L30-L204】【F:includes/class-wp-mcp-ai-rest.php†L16-L2104】

From the ChatKit dashboard configure the **NV oOS** integration and supply at least one assistant ID so ChatKit knows which conversation to join. Optional fields let you override the system prompt or preload tool shortcut payloads for operators; capability checks inherit the `wp_mcp_ai_chat_capability` filter, so you can align ChatKit access with the same policies used for shortcodes or REST calls.【F:includes/class-wp-mcp-ai-chatkit-integration.php†L182-L210】【F:mcp-ai-wpoos.php†L25-L72】

Consult [`docs/developer/integration/chatkit-integration.md`](docs/developer/integration/chatkit-integration.md) for a full configuration walkthrough, JSON examples for shortcut presets, and notes on extending the definition via filters.

## 🌐 Crawl4AI Integration

Administrators with `manage_options` capabilities can run the **Run Crawl4AI Job** tool without any external service: when no Crawl4AI endpoint is configured the plugin performs the crawl directly on the WordPress server using the built-in HTTP client, extracts headings and text as Markdown, and records the raw HTML and response metadata for the assistant.【F:includes/tools/class-wp-mcp-ai-tool-run-crawl4ai-job.php†L32-L745】 Errors for individual URLs are captured in the response metadata so partial crawls still return useful context. When a remote Crawl4AI endpoint is configured the request now returns immediately with a task token while WP-Cron powered background polling captures the final payload and makes it available to the assistant UI once the crawl finishes.【F:includes/crawler/class-wp-mcp-ai-crawler.php†L1-L214】【F:assets/js/chat.js†L1-L2200】

Configure remote endpoints or API keys under **Settings → NV oOS → Tools** to tailor how the Crawl4AI integration runs across environments.【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L248-L521】

Supplying a Crawl4AI base URL (and optional API key) switches the tool back to proxying crawl jobs to the remote Crawl4AI REST API, preserving backwards compatibility with existing deployments.【F:includes/tools/class-wp-mcp-ai-tool-run-crawl4ai-job.php†L206-L339】【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L248-L521】 Local environments can still feed a custom endpoint to the integration through the `WP_MCP_AI_CRAWL4AI_BASE_URL` or `CRAWL4AI_BASE_URL` environment variable when you want to test against a dedicated Crawl4AI service.【F:mcp-ai-wpoos.php†L54-L96】

## 📡 Job Notification System

NV oOS includes a general-purpose infrastructure for real-time notifications on async WordPress jobs, providing SSE streaming and webhook support for external integrations.【F:docs/features/async-jobs/job-notification-system.md†L1-L100】

### Architecture

```
Async Job → WordPress Action → Job Notifier → [SSE | Webhooks]
                                                 ↓       ↓
                                            Frontend  External
```

### Automatic Crawl4AI Integration

The system automatically hooks into Crawl4AI jobs via the `wp_mcp_ai_crawl4ai_job_completed` action, providing real-time status updates as crawls progress. No additional code is needed—Crawl4AI jobs automatically trigger notifications.【F:includes/crawler/class-wp-mcp-ai-crawler.php†L1-L214】

### Frontend SSE Subscription

JavaScript clients can subscribe to job status updates using Server-Sent Events:

```javascript
const jobId = 'crawl_abc123';
const eventSource = new EventSource(
    `/wp-json/mcp-ai/v1/jobs/${jobId}/stream?max_duration=300&poll_interval=2`
);

eventSource.addEventListener('status', (e) => {
    const status = JSON.parse(e.data);
    console.log('Job status:', status.status, status.progress);
    updateProgressBar(status.progress);
});

eventSource.addEventListener('complete', (e) => {
    const data = JSON.parse(e.data);
    console.log('Job completed:', data.final_status);
    eventSource.close();
});
```

### Webhook Registration

External systems can receive HTTP callbacks when jobs complete:

```php
WP_MCP_AI_Job_Notifier::register_webhook(
    'crawl_abc123',
    'https://example.com/webhook',
    array( 'completed', 'failed' )
);
```

➡️ See [docs/features/async-jobs/job-notification-system.md](docs/features/async-jobs/job-notification-system.md) for complete implementation details.

## 🧊 Elementor Widgets

Sites running [Elementor](https://be.elementor.com/visit/?bta=229888&brand=elementor) automatically register a suite of MCP blocks so you can assemble onboarding pages, operational dashboards, and standalone chat layouts without writing markup.【F:includes/class-wp-mcp-ai-elementor-integration.php†L12-L98】 The integration only boots when Elementor is present, so non-Elementor installs avoid any overhead.【F:includes/class-wp-mcp-ai-elementor-integration.php†L29-L46】

### Chat surfaces and companion blocks
- **NV oOS Chat** – Renders the assistant interface with the same controls exposed by the `[mcp_ai_chat]` shortcode, including the `allow_guests` toggle for minting temporary visitor tokens.【F:includes/elementor/class-wp-mcp-ai-elementor-widget.php†L17-L138】
- **NV oOS Chat Bubble** ⭐ **NEW** – Floating chat bubble that sits at a configurable screen corner and opens a chat panel powered by the existing `[mcp_ai_chat]` shortcode. Also available as a **Gutenberg block** (`wp:mcp-ai-wpoos/chat-bubble`). 5 control sections: Chat Settings, Bubble Settings (position/size/animation/tooltip/badge/auto-open), Panel Settings, Bubble Style, Panel Style. BEM CSS with 4 positions, 3 sizes, bounce/pulse animations, dark mode, full-screen mobile (<480px), `prefers-reduced-motion`, WCAG focus states. Public API at `window.wpMcpAiChatBubble`.【F:includes/elementor/class-wp-mcp-ai-elementor-chat-bubble-widget.php†L1-L200】【F:includes/blocks/chat-bubble/block.json†L1-L50】
- **NV oOS Chat Intro** – Adds a configurable hero block above the conversation with headings, talking points, and an optional call-to-action button to guide visitors before they engage the model.【F:includes/elementor/class-wp-mcp-ai-elementor-chat-intro-widget.php†L47-L190】
- **NV oOS Chat FAQ** – Surfaces a repeater-driven FAQ list alongside the chat so product teams can document policies and best practices in context.【F:includes/elementor/class-wp-mcp-ai-elementor-chat-faq-widget.php†L47-L150】
- **NV oOS Usage & Timer** – Combines a focus timer with per-user token totals, gracefully handling logged-out visitors, disabled tracking, and empty usage histories.【F:includes/elementor/class-wp-mcp-ai-elementor-chat-usage-timer-widget.php†L48-L340】

### Operations dashboards
- **NV oOS Tool Matrix** – Pulls the tool registry, groups integrations by focus area, and highlights the required capability for each assistant tool so administrators can plan enablement safely. The Send Group Email row now mirrors the capability and recipient limit configured in the MCP settings so editorial policies stay front-of-mind.【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-tool-matrix-widget.php†L48-L440】
- **NV oOS User Capability Snapshot** – Summarises the signed-in operator’s profile, common capabilities, JetEngine access, and multisite memberships to support governance reviews. It also surfaces the configured Send Group Email capability and limit so administrators immediately know whether the current user can trigger bulk mail jobs.【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-user-capability-widget.php†L48-L392】
- **NV oOS Theme Preview** – Renders a mock conversation using the saved chat color tokens and optionally displays a legend of every branding token for quick QA during rollouts.【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-theme-preview-widget.php†L48-L198】
- **NV oOS Provider Quick Links** – Reuses the OpenAI usage/log tools to populate external billing and telemetry shortcuts that open in new tabs for rapid debugging.【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-provider-links-widget.php†L48-L166】
- **NV oOS Activity Feed** – Streams the latest MCP log entries (tool runs, chat interactions, and optional provider requests), collapsing raw context into expandable JSON blocks for deeper analysis.【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-activity-feed-widget.php†L48-L210】

## 🧮 Usage Tracking

### Privacy-First Analytics (v1.2.0+)

The plugin includes **optional, privacy-first activation tracking** to help us understand plugin usage and improve development priorities. This feature is:

**Privacy Features:**
- ✅ **No PII collected** - No personal information or identifiable data
- ✅ **Site URLs hashed** - Non-reversible SHA-256 hash with WordPress salts
- ✅ **No IP storage** - IP addresses are not logged or stored
- ✅ **Local dev excluded** - Automatically disabled for localhost and common dev domains
- ✅ **Opt-out available** - Easy to disable via settings or filter hook
- ✅ **GDPR compliant** - Meets all privacy regulations
- ✅ **Fully transparent** - All code is open source and documented

**Data Collected:**
- Plugin variant (complete, base, pro, or core)
- Plugin version number
- WordPress version
- PHP version
- Site locale (language)
- Multisite status
- Hashed site identifier (non-reversible)
- Timestamp

**How to Opt Out:**
1. **Via Settings**: Settings → NV oOS → General → Log Management → Disable Activation Tracking
2. **Via Filter Hook**:
   ```php
   add_filter( 'wp_mcp_ai_enable_usage_tracking', '__return_false' );
   ```

**Full Privacy Details**: See [EXTERNAL_SERVICES.md](docs/reference/EXTERNAL_SERVICES.md#plugin-analytics-service) for complete documentation.

---

The plugin records aggregate token usage per user, provider, and model whenever responses include usage metadata, simplifying internal reconciliation or billing workflows. Usage data is stored as user meta and automatically purged when accounts are deleted, and hooks are exposed for custom reporting pipelines.【F:includes/class-wp-mcp-ai-usage-tracker.php†L12-L119】

### Token Usage Management Dashboard

Administrators with `manage_options` capability can view comprehensive token usage statistics in **Settings → NV oOS**:

**Global Statistics (All Users):**
- Total requests across all users
- Total tokens consumed (prompt + completion)
- Prompt tokens used
- Completion tokens generated
- Cached tokens (for providers supporting prompt caching)
- Reset all usage data button (with confirmation)

**Individual User Statistics:**
- Your personal token consumption
- Per-user breakdown of requests and tokens
- Reset personal usage data button

**Detailed Breakdown:**
- Usage by provider (OpenAI, Gemini, Anthropic, NVIDIA NIM, Ollama, LM Studio, Hugging Face, Cloudflare)
- Usage by specific model (e.g., `gpt-4.1-mini`, `gemini-2.0-flash`)
- Request counts per provider/model combination
- Last used timestamp for each model
- Comprehensive table view with all metrics

The usage tracking system automatically:
- Records usage from all API responses that include usage metadata
- Aggregates data by user, provider, and model
- Updates in real-time as conversations occur
- Supports the **Open OpenAI Usage** tool for quick access to provider dashboards
- Provides AJAX-powered reset functionality for administrators

## 🧷 Attachment MIME Controls

Administrators can override the default image and file MIME allowlists used by the chat uploader. The settings screen accepts one MIME type per line, and the attachment helper merges the overrides with its defaults before enforcing them on upload and shortcode configuration.【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L225-L669】【F:includes/class-wp-mcp-ai-message-attachments.php†L503-L559】 Leave the fields empty to fall back to the bundled safe defaults.

## ⚡ Message Bundling

NV oOS implements client-side message bundling to optimize API usage and reduce server load. When enabled, messages sent within an 800ms window are automatically grouped into a single API request, reducing costs and improving performance for users who send multiple messages in quick succession.【F:docs/user-guides/chat/message-bundling-feature.md†L1-L80】

### How It Works

1. User sends a message → Displayed immediately in the chat UI
2. 800ms timer starts → System waits for additional messages
3. More messages arrive → Timer resets with each new message
4. Timer expires → All queued messages sent together in one request

### Visual Feedback

- **"Preparing to send…"** - Messages are being queued during the bundling window
- **"Sending…"** - Bundled messages are being transmitted to the server

### Benefits

- **Reduced API costs** - Fewer requests mean lower costs for pay-per-request APIs
- **Lower server load** - Fewer requests to process and respond to
- **Better mobile experience** - Ideal for users who type in short bursts
- **Backward compatible** - Server code unchanged, same payload format

### Configuration

Message bundling is enabled by default and requires no configuration. To disable for debugging:

```javascript
window.wpMcpAiChatDebugMode = true;
```

➡️ See [docs/user-guides/chat/message-bundling-feature.md](docs/user-guides/chat/message-bundling-feature.md) for configuration options and implementation details.

## 🎯 Agentic Loop Token Management

NV oOS includes intelligent handling for tools that return large responses, preventing token overflow errors during agentic loops (where the AI automatically calls multiple tools).【F:docs/features/tools/presets/high-token-tool-handling.md†L1-L80】

### The Problem

Tools like `run_crawl4ai_job` can return 100,000+ tokens of content. In agentic loops, each API call includes all previous messages, causing token counts to grow rapidly and exceed model limits (e.g., gpt-4.1-mini's 200k TPM limit).

### The Solution: Three-Tier Strategy

#### Tier 1: Token Limit Detection
- Estimates total tokens before each API call
- Checks against model's TPM (Tokens Per Minute) limit
- Prevents requests that would exceed limits

#### Tier 2: Automatic Model Switching
- When limits exceeded, auto-switches to fallback model
- Default fallback: Gemini 2.0 Flash (1-2 million token capacity)
- Preserves full context without data loss
- Transparent to the user

#### Tier 3: Message Truncation
- If even fallback model can't handle tokens
- Truncates older messages from conversation
- Always preserves system prompts and recent context
- Logs what was truncated for debugging

### Configuration

Automatic model switching is enabled by default. Configure fallback model under **Settings → NV oOS**:

```php
// Default fallback model
'fallback_model' => 'gemini-2.0-flash-exp'
```

➡️ See [docs/features/tools/presets/high-token-tool-handling.md](docs/features/tools/presets/high-token-tool-handling.md) for complete technical details and examples.

## 🔄 Chat Performance Optimizations

NV oOS includes several performance optimizations to enhance the chat experience:

- **Message bundling** - Reduces API calls by grouping rapid user inputs
- **Token budget management** - Prevents API limit overruns with safety margins【F:docs/features/performance/tpm-limit-validation.md†L1-L50】
- **Chat history persistence** - LocalStorage (24h) + optional JetEngine CCT storage【F:docs/user-guides/chat/chat-history-persistence.md†L1-L50】
- **Automatic model switching** - Seamlessly handles token overflow scenarios
- **Rate limit protection** - Intelligent retry with exponential backoff【F:docs/features/performance/rate-limit-protection.md†L1-L50】

➡️ See [docs/features/chat/chat-performance-optimizations.md](docs/features/chat/chat-performance-optimizations.md) for detailed performance tuning guide.

## 🌐 Mesh Compute Routing

NV oOS includes **intelligent mesh compute routing** that automatically distributes AI workload across multiple sites OR multiple providers using AI-powered decision-making. This feature works in two modes:

1. **Multi-Site Mesh**: Distribute load across multiple WordPress installations
2. **Single-Site Multi-Provider**: Balance load across OpenAI, Gemini, Anthropic, NVIDIA NIM, Hugging Face, Cloudflare, and Ollama on one site

Both modes use the same AI-powered routing engine to optimize for cost, performance, and reliability.

### Key Capabilities

- **AI-Optimized Routing** - Analyzes prompt complexity and routes to optimal provider/site
- **Cost Optimization** - Use GPT-4o-mini for simple queries, GPT-4o for complex tasks
- **Automatic Failover** - Switch providers on rate limits or outages
- **Compute Hubs** - Designate powerful servers for heavy workloads
- **Rate Limit Management** - Auto-switch to alternative providers when limits hit
- **Privacy Control** - Route sensitive data to local Ollama instances

### Quick Start Examples

**Single-Site Setup** (No mesh required):
- Configure multiple AI providers (OpenAI + Gemini + Anthropic + Hugging Face + Cloudflare + Ollama)
- Set assistant routing strategy to "AI Optimized"
- Save 90% on costs by routing simple queries to cheaper models

**Multi-Site Setup** (Distributed compute):
- Enable mesh networking on all sites
- Designate compute hubs with larger models
- Automatic load balancing across peer sites
- Cross-server compute pooling for Cloudways, SiteGround, etc.

➡️ See [docs/features/federation/mesh-routing-guide.md](docs/features/federation/mesh-routing-guide.md) for complete setup guide, routing strategies, and use cases.
➡️ See [docs/features/federation/mesh-compute-pooling.md](docs/features/federation/mesh-compute-pooling.md) for architecture and authentication details.

## 🔗 Federation & Discovery System

NV oOS includes a **decentralized AI capability network** that allows WordPress sites to publish their capabilities and discover peer sites. Think of it as "npm for AI tools" — sites can advertise what they offer and find complementary capabilities from trusted peers.

### Overview

The Federation & Discovery system provides three deployment modes:

1. **Publisher Mode**: Publish your site's capabilities via `/.well-known/ai-peer`
2. **Directory Mode**: Run a discovery service for peer registration and search
3. **Consumer Mode**: Query directories to find and use peer capabilities

### Quick Start

**Enable Federation (Publisher Mode):**
1. Navigate to **Settings → NV oOS → Federation & Discovery**
2. Check **Enable federation**
3. Configure regions (e.g., `us, eu, ap`) and data tags (e.g., `no_pii, gdpr_ok`)
4. Your capabilities are now published at `https://yoursite.com/.well-known/ai-peer`

**Enable Directory Service (Optional):**
1. In the same settings section, check **Enable directory service**
2. Your directory API is now available at `https://yoursite.com/wp-json/ai-dir/v1`
3. Automatic hourly health checks verify registered peers

### Key Features

- 📡 **Well-Known Endpoints** - Standards-based capability publishing
- 🔍 **Peer Discovery** - Search by capability, region, and data policy
- ✅ **Health Monitoring** - Automatic cron-based peer verification
- 🏆 **Smart Ranking** - Scores peers by region, latency, and policy match
- 🔐 **JWKS Verification** - Built-in security with public key discovery
- ⚙️ **Conditional Loading** - Zero overhead when disabled

### API Endpoints

**Directory REST API (`/wp-json/ai-dir/v1`):**
- `POST /peers/register` - Register a new peer
- `GET /peers` - List all peers with health status
- `GET /peers/{id}` - Get peer details
- `GET /search` - Search peers by capability/region/policy
- `POST /reverify/{id}` - Manually trigger health check
- `POST /report/{id}` - Report peer issues

**Well-Known Endpoints:**
- `GET /.well-known/ai-peer` - Your site's capability manifest
- `GET /.well-known/jwks.json` - Public keys for verification

### Use Cases

**Private Organization Network:**
- Multiple WordPress sites within one organization
- Share AI capabilities across internal sites
- Central directory for discovery
- Private peer network with secure authentication

**Public Directory Service:**
- Community-run capability discovery
- Accept registrations from external sites
- Provide search API for consumers
- Build an ecosystem marketplace

**Capability Consumer:**
- Query public directories for needed capabilities
- Integrate with mesh router for automatic peer selection
- No need to publish your own capabilities
- Access specialized tools from the network

### Configuration Options

- **Regions**: Geographic locations (e.g., `us, eu, ap, global`)
- **Data Tags**: Compliance policies (e.g., `no_pii, gdpr_ok, hipaa_like`)
- **QPS Limit**: Queries per second (default: 5)
- **Burst Capacity**: Simultaneous requests (default: 10)

➡️ **Complete Documentation:** [docs/features/federation/federation-discovery.md](docs/features/federation/federation-discovery.md)
➡️ **Implementation Summary:** FEDERATION-IMPLEMENTATION-SUMMARY.md

## 🕵️ Code Review

The 2025-10-31 internal review confirms the hardening of the group email automation (header filtering and attachment caps) and the case-sensitive variable handling in the OpenAI external action tool, and only flags a low-severity performance concern around guest token transient churn for public chat embeds. These findings have been consolidated into the master code review document. One follow-up action item recommends re-using or rate-limiting guest tokens to keep the options table tidy on cache-less hosts.

➡️ See [docs/developer/best-practices/CODE-REVIEW-MASTER.md](docs/developer/best-practices/CODE-REVIEW-MASTER.md) for the complete code quality assessment.

## 🔒 MCP Server Authentication

Remote MCP assistants should authenticate with Auth0-issued bearer tokens (`Authorization: Bearer YOUR_TOKEN`) whose audience and scope align with the values configured under **Settings → NV oOS**. Same-origin experiences (the dashboard editor and shortcode UI) continue to rely on the `X-WP-Nonce` header tied to the logged-in WordPress session. Review [docs/reference/api/mcp-server-authentication.md](docs/reference/api/mcp-server-authentication.md) for a complete setup guide plus a breakdown of the structured error responses returned on failure, and keep the [deployment troubleshooting checklist](docs/getting-started/installation-setup/deployment-troubleshooting.md) handy when diagnosing capability or credential regressions.

### Using NV oOS as an MCP server

1. **Install the plugin and create assistants.** Each WordPress instance that activates NV oOS exposes an MCP-ready assistant directory backed by the `ai_assistant` custom post type, so every published assistant becomes available to remote clients once credentials are issued.【F:includes/assistants/class-wp-mcp-ai-assistant-cpt.php†L460-L620】
2. **Configure the REST and connector settings.** Populate the Auth0, model provider, and optional integration credentials under **Settings → NV oOS** so the REST controller can advertise the correct namespace URLs and enforce bearer tokens per your tenant, scope, and provider defaults.【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L36-L118】
3. **Expose the MCP directory endpoints.** The REST layer publishes `/assistants`, `/chat`, `/tools`, and an SSE-compatible `/sse` handshake inside the `wp-json/mcp-ai/v1` namespace, automatically scoping responses to the authenticated assistant or returning every assistant the caller may read.【F:includes/class-wp-mcp-ai-rest.php†L234-L703】 Hand-held clients can subscribe to the streaming directory event or call the JSON routes directly using the base URLs returned in the directory payload.【F:includes/class-wp-mcp-ai-rest.php†L653-L703】
4. **Register any additional tools.** Extend the server’s capabilities by hooking into `wp_mcp_ai_register_tools` and loading custom tool classes; registered slugs flow through the assistant directory and tool execution endpoint without extra wiring.【F:includes/class-wp-mcp-ai-tool-registry.php†L75-L195】
5. **Verify the deployment before sharing credentials.** Run `wp mcp-ai remote https://example.com/wp-json/mcp-ai/v1 --token=YOUR_TOKEN` from any WP-CLI environment to confirm authentication, assistant scope, and chat probes succeed before you hand tokens to operators or client teams.【F:includes/class-wp-mcp-ai-cli-command.php†L137-L220】

### Operating multiple MCP deployments

Provision a separate WordPress site (or network site) for each MCP server you need, activate NV oOS, and repeat the configuration steps above with environment-specific Auth0 audiences, scopes, and provider keys. Because the assistant directory response includes the resolved REST base and namespace metadata, MCP clients can be pointed at different deployments simply by swapping the base URL and the bearer credential minted for that site’s assistants.【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L48-L118】【F:includes/class-wp-mcp-ai-rest.php†L653-L703】

Sites that enable the Simple JWT Login integration can now reuse those bearer tokens alongside Auth0 credentials. The plugin validates tokens with Simple JWT Login’s native services, falls back to manual JWT decoding when the dependency cannot resolve a user, and automatically scopes REST requests to the assistant encoded in the token so cross-assistant hops are blocked with actionable errors.【F:includes/class-wp-mcp-ai-simple-jwt-login-integration.php†L47-L214】【F:includes/integrations/class-wp-mcp-ai-integration-simple-jwt.php†L240-L378】【F:includes/class-wp-mcp-ai-rest.php†L2769-L2808】


## 🌐 Connecting Remote MCP Clients

NV oOS works seamlessly with popular MCP clients including Claude Desktop, LM Studio, and ChatGPT connectors. Each client connects to your WordPress site via the MCP REST API at `/wp-json/mcp-ai/v1` and can access assistants, execute tools, and interact with your WordPress data remotely.

**SSE Support:** All MCP endpoints support Server-Sent Events (SSE) for real-time streaming. Enable SSE in your client configuration for better response times and real-time updates. See the [SSE Streaming Support](#sse-streaming-support) section for details.

### Quick Start

1. **Generate an assistant credential** from any published assistant's **API Credentials** meta box
2. **Copy the token** (format: `cred_xxxxx.SECRET`) — shown only once!
3. **Configure your MCP client** with your site's base URL and the credential
4. **Test the connection** using the provided test script or WP-CLI command

### Claude Desktop setup

Claude Desktop supports MCP servers through a JSON configuration file. Add your WordPress site:

```json
{
  "mcpServers": {
    "wordpress-site": {
      "url": "https://your-site.com/wp-json/mcp-ai/v1",
      "headers": {
        "Authorization": "Bearer cred_xxxxx.SECRET"
      },
      "sse": true
    }
  }
}
```

See the complete [Claude Desktop setup guide](docs/getting-started/installation-setup/remote-client-setup.md#claude-desktop-setup) and [example configurations](assets/examples/claude-desktop-config.json) for multi-assistant deployments.

### LM Studio Setup

**⚠️ Having SSE content-type errors?** Use the JSON-RPC endpoint instead!

LM Studio can connect using **two methods**:

#### Method 1: JSON-RPC (Recommended - No SSE)

Use this if you're getting `SSE error: Invalid content type, expected "text/event-stream"`:

```json
{
  "servers": [
    {
      "id": "wordpress-mcp",
      "name": "WordPress Site",
      "url": "https://your-site.com/wp-json/mcp-ai/v1/mcp",
      "auth": {
        "type": "bearer",
        "token": "cred_xxxxx.SECRET"
      },
      "timeout": 30000
    }
  ]
}
```

**Configure in LM Studio:**
- **Server Name:** WordPress Site
- **URL:** `https://your-site.com/wp-json/mcp-ai/v1/mcp`
- **Auth Type:** Bearer Token
- **Token:** `cred_xxxxx.SECRET`
- **Do NOT enable SSE**

#### Method 2: SSE Streaming (Optional)

If you want to use SSE for real-time updates:

- **Base URL:** `https://your-site.com/wp-json/mcp-ai/v1`
- **Enable SSE:** ✓ (checked)
- **SSE Endpoint:** `/sse`

See the complete [LM Studio setup guide](docs/getting-started/installation-setup/remote-client-setup.md#lm-studio-setup) and example configurations:
- [lmstudio-mcp-without-sse.json](assets/examples/lmstudio-mcp-without-sse.json) - Recommended
- [lmstudio-config.json](assets/examples/lmstudio-config.json) - With SSE

### ChatGPT connector setup

⚠️ **Note:** ChatGPT connectors currently require Auth0 authentication. Assistant-issued credentials are not yet supported by OpenAI's ChatGPT platform.

To connect via ChatGPT:
1. Configure Auth0 in **Settings → NV oOS**
2. Generate an Auth0 access token with the configured audience
3. Add the MCP server in ChatGPT's connector settings

See the [ChatGPT connector guide](docs/getting-started/installation-setup/remote-client-setup.md#chatgpt-connector-setup) for detailed Auth0 setup steps.

### Testing your connection

Use the built-in test script to verify connectivity:

```bash
./bin/test-remote-connection.sh \
  -u https://your-site.com/wp-json/mcp-ai/v1 \
  -t cred_xxxxx.SECRET
```

Or use WP-CLI:

```bash
wp mcp-ai remote https://your-site.com/wp-json/mcp-ai/v1 \
  --token=cred_xxxxx.SECRET
```

Expected output confirms the server is reachable and lists available assistants.

### Complete documentation

For comprehensive setup guides, troubleshooting, and advanced configurations, see:
- **[MCP Client Configurations](docs/reference/api/mcp-client-configurations.md)** – **⭐ NEW:** Complete guide for all MCP clients (LM Studio, Claude Desktop, Cursor, Continue.dev, Cline, OpenAI)
- **[Remote Client Setup Guide](docs/getting-started/installation-setup/remote-client-setup.md)** – Step-by-step instructions for Claude Desktop, LM Studio, and ChatGPT
- **[MCP Server Authentication](docs/reference/api/mcp-server-authentication.md)** – Authentication methods and credential management
- **[REST API Reference](docs/reference/api/rest-api.md)** – Endpoint documentation and payload examples
- **[Example Configurations](assets/examples/)** – Ready-to-use config files for all major MCP clients

---

## 🎫 Token Management UI

**NV oOS 1.0.0 introduces a centralized Token Manager** for managing all external agent access tokens across your assistants. Access it via **NV oOS → Token Manager** in the admin menu.

### Features

- **Centralized Control** - Manage all assistant credentials in one place
- **Security Best Practice** - Tokens shown only once after creation (cannot be retrieved later)
- **Lifecycle Management** - Create, view, revoke, and delete credentials
- **Audit Trail** - Track who created/revoked each token and when
- **Metadata Display** - See creation date, status (active/revoked), associated assistant  
- **Bulk Visibility** - View credentials across all assistants at a glance

### How It Works

The Token Manager follows industry standards similar to GitHub Personal Access Tokens, Stripe API keys, and Auth0 credentials:

1. **Create Token** - Generate new credentials from the assistant editor
2. **Copy Immediately** - Token shown once and cannot be retrieved later
3. **Use in MCP Clients** - Configure external applications (Codex CLI, MCP clients, custom integrations)
4. **Revoke When Needed** - Disable compromised tokens without deleting audit history
5. **Delete When Done** - Permanently remove tokens and all metadata

### Security Notes

- Tokens are hashed before storage (only hash stored, never plaintext)
- Requires `manage_options` capability
- All actions logged with user attribution
- Revoked tokens cannot be reactivated (must create new)
- HTTPS strongly recommended for token transmission

### Usage Example

```bash
# In assistant editor: Create credential → Copy token immediately
# Token format: cred_[YOUR_PREFIX].[YOUR_SECRET_KEY_HERE]
# Example format only - never share real tokens!

# Configure MCP client (e.g., Codex CLI)
export WPOOS_BEARER_TOKEN="your_token_here"
codex chat --assistant 123 "Hello world"

# Later: Revoke from Token Manager UI if compromised
# Or: Delete entirely when integration removed
```

⚠️ **Security Warning:** The examples above use placeholder tokens. Never share real tokens publicly or commit them to version control.

### Access Requirements

- **Capability:** `manage_options` (administrators only)
- **Menu Location:** NV oOS → Token Manager
- **REST API:** `/wp-json/mcp-ai/v1/token-manager/*`

For complete documentation, see [Token Management Guide](docs/features/performance/token-management.md).

---

## 🤖 ChatGPT Connector
OpenAI’s ChatGPT connector beta currently authenticates exclusively through Auth0. Because NV oOS issues its own assistant-scoped bearer credentials, you can connect LM Studio, Claude Desktop, and other MCP-aware clients today, while ChatGPT support will require either Auth0 bridging or native bearer support from OpenAI. We’ll update this section as soon as ChatGPT adds compatibility with first-party tokens.【F:docs/reference/api/mcp-server-authentication.md†L22-L46】

## 🛰 REST API Endpoints

All front-end chat surfaces ultimately call the MCP REST namespace at `/wp-json/mcp-ai/v1`, which exposes dedicated endpoints for chat completions and direct tool execution. Both routes share the same authentication rules described above: supply an Auth0 bearer token, a plugin-issued assistant credential, or a WordPress REST nonce for same-origin requests. Guest tokens issued by the shortcode or Elementor widget continue to be honoured when `allow_guests="true"` is enabled.【F:includes/class-wp-mcp-ai-rest.php†L230-L322】【F:includes/class-wp-mcp-ai-rest.php†L289-L343】【F:includes/class-wp-mcp-ai-rest.php†L1288-L1336】

- **`GET /assistants`** – Returns a directory of accessible assistants with provider defaults, tool counts, capability metadata, and implementation details so remote clients can choose which assistant to call. Credential tokens are automatically scoped to their issuing assistant while Auth0 tokens and REST nonces surface every published assistant the caller can read.【F:includes/class-wp-mcp-ai-rest.php†L238-L666】 The endpoint also supports Server-Sent Events for MCP clients that expect streaming discovery payloads, emitting a single `directory` event with cache-busting headers before closing the stream.【F:includes/class-wp-mcp-ai-rest.php†L1690-L1772】
- **`GET /sse`** – Mirrors the assistant directory response but forces a Server-Sent Events handshake so MCP clients that negotiate `/sse` subscriptions receive the streaming `directory` payload without additional query parameters.【F:includes/class-wp-mcp-ai-rest.php†L400-L715】
- **`POST /chat`** – Normalises structured `messages`, injects assistant defaults, auto-enables the Submit Document Prompt tool when uploads are present, and forwards the request through the language model router. Responses include the assistant ID and the raw provider payload so clients can stream or render messages as needed.【F:includes/class-wp-mcp-ai-rest.php†L230-L322】【F:includes/class-wp-mcp-ai-rest.php†L931-L1095】
- **`POST /tools`** – Executes a specific registered tool outside of a chat turn. The endpoint enforces assistant tool allowlists, scopes credential-based requests to the issuing assistant, merges assistant defaults (such as external action identifiers), and returns the tool result with execution metadata.【F:includes/class-wp-mcp-ai-rest.php†L264-L322】【F:includes/class-wp-mcp-ai-rest.php†L1162-L1321】

See [docs/reference/api/rest-api.md](docs/reference/api/rest-api.md) for payload examples, attachment handling rules, and troubleshooting tips when integrating custom clients.

## 🌊 SSE Streaming Support

NV oOS includes comprehensive Server-Sent Events (SSE) support for real-time streaming responses, enabling faster perceived response times and better user experience.

### What is SSE?

Server-Sent Events provide unidirectional server-to-client streaming over HTTP, allowing the server to push updates as they become available rather than waiting for the complete response.

**Benefits:**
- ⚡ **Faster perceived response time** - Users see content immediately as it's generated
- 🔄 **Real-time updates** - Progressive loading for long-running operations
- 📶 **Connection keep-alive** - Prevents timeouts during lengthy responses
- 🎯 **Better UX** - ChatGPT-style typing effect for AI responses

### SSE-Enabled Endpoints

#### 1. Assistant Directory Streaming (`GET /assistants`)

Stream the assistant directory for MCP clients expecting SSE handshakes:

```bash
curl -H "Accept: text/event-stream" \
  https://your-site.com/wp-json/mcp-ai/v1/assistants
```

The endpoint emits a single `directory` event with all accessible assistants, then closes the connection.

#### 2. Dedicated SSE Endpoint (`GET /sse`)

Force SSE mode for MCP clients that specifically probe the `/sse` endpoint:

```bash
curl https://your-site.com/wp-json/mcp-ai/v1/sse
```

This mirrors the `/assistants` response but always uses SSE format, ensuring compatibility with LM Studio and Claude Desktop.

#### 3. Job Status Streaming (`GET /jobs/{job_id}/stream`)

Subscribe to real-time updates for async operations like Crawl4AI jobs:

```javascript
const eventSource = new EventSource(
    `/wp-json/mcp-ai/v1/jobs/${jobId}/stream?max_duration=300&poll_interval=2`
);

eventSource.addEventListener('status', (e) => {
    const status = JSON.parse(e.data);
    console.log('Progress:', status.progress + '%');
});

eventSource.addEventListener('complete', (e) => {
    console.log('Job finished:', e.data);
    eventSource.close();
});
```

### SSE Configuration

#### Enable POST Method for SSE (LM Studio Compatibility)

By default, SSE uses the standard GET method. For clients with SSE bugs (like LM Studio), enable POST support:

1. Go to **Settings → NV oOS → Assistant Settings**
2. Enable **"Enable POST Method on SSE Endpoint"**
3. Save settings

⚠️ **Note:** Standard SSE specification uses GET. Only enable POST if you experience client compatibility issues.

### Modern SSE Features (2024-2025)

The SSE implementation includes current best practices:
- **Automatic reconnection** with `retry:` directive (3-second interval)
- **Event IDs** for tracking reconnection state
- **HTTP/2 compatibility** for multiplexing
- **Proper CORS headers** for cross-origin requests
- **Cache-Control directives** to prevent proxy buffering
- **Heartbeat messages** to keep connections alive

### Frontend Integration

Enable SSE streaming in your JavaScript client:

```javascript
// Request streaming in chat
const response = await fetch('/wp-json/mcp-ai/v1/chat', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'Accept': 'text/event-stream',
        'X-WP-Nonce': wpMcpAi.nonce
    },
    body: JSON.stringify({
        assistant_id: 123,
        messages: [{ role: 'user', content: 'Hello' }],
        stream: true
    })
});

// Process SSE stream
const reader = response.body.getReader();
const decoder = new TextDecoder();
let buffer = '';

while (true) {
    const { done, value } = await reader.read();
    if (done) break;
    
    buffer += decoder.decode(value, { stream: true });
    const events = buffer.split('\n\n');
    buffer = events.pop();
    
    for (const event of events) {
        if (event.startsWith('data: ')) {
            const data = JSON.parse(event.substring(6));
            // Update UI with streaming chunk
            updateChatUI(data);
        }
    }
}
```

### Documentation

For complete SSE implementation details, configuration options, and troubleshooting:
- **[SSE Streaming Guide](docs/features/streaming/ENABLE-SSE-STREAMING.md)** - Complete implementation guide with code examples
- **[MCP and SSE](docs/reference/api/MCP-AND-SSE.md)** - Understanding SSE benefits for MCP protocol
- **[Job Notification System](docs/features/async-jobs/job-notification-system.md)** - Real-time job status via SSE
- **[REST API Reference](docs/reference/api/rest-api.md)** - SSE endpoint specifications

## 📝 MCP JSON-RPC 2.0 Endpoint

NV oOS implements a dedicated `/mcp` endpoint that follows the **Model Context Protocol specification version 2024-11-05** using JSON-RPC 2.0 for bidirectional communication with AI assistants and tools.【F:docs/reference/api/mcp-endpoint.md†L1-L80】

**MCP Version:** 2024-11-05  
**Compliance:** Full MCP 2024-11-05 — all 11 protocol methods, OAuth 2.1, Streamable HTTP, JSON-RPC batching, tool annotations, session management

### What's New in MCP 2024-11-05

The latest specification is **fully implemented**:
- **OAuth 2.1 Security**: PKCE, token rotation, mandatory HTTPS
- **Streamable HTTP Transport**: Better reconnection and bidirectional communication
- **JSON-RPC Batching**: Efficient parallel task processing (up to 20 messages per batch)
- **Tool Annotations**: `readOnlyHint`, `destructiveHint`, `idempotentHint`, `openWorldHint` metadata
- **Progress Notifications**: Descriptive status updates during tool execution
- **Completions**: Argument autocompletion for tools and prompts
- **Session Management**: State recovery via `Mcp-Session-Id` header (1h TTL)
- **Logging**: Client-controlled log verbosity via `logging/setLevel`
- **Cancellation**: Request cancellation via `notifications/cancelled`

### Endpoint URL

```
POST /wp-json/mcp-ai/v1/mcp
```

### JSON-RPC 2.0 Format

All requests must use standard JSON-RPC 2.0 format:

```json
{
  "jsonrpc": "2.0",
  "id": "unique-request-id",
  "method": "initialize",
  "params": {}
}
```

### Supported Methods

- **`initialize`** - Initialize MCP connection and retrieve server capabilities
- **`ping`** - Server liveness check
- **`tools/list`** - List available tools with annotations for the authenticated assistant
- **`tools/call`** - Execute a specific tool with progress notifications support
- **`resources/list`** - List available resources (knowledge files, etc.) with metadata
- **`resources/read`** - Read resource content by URI with MIME-typed responses
- **`prompts/list`** - List available prompt shortcuts
- **`prompts/get`** - Get full prompt content with system instructions and argument values
- **`completion/complete`** - Argument autocompletion (enum/boolean for tools, slug matching for prompts)
- **`logging/setLevel`** - Client-controlled log verbosity (8 standard levels)
- **`notifications/cancelled`** - Cancel a pending request

### Authentication (OAuth 2.1 Enhanced)

The MCP endpoint uses enhanced authentication aligned with MCP 2024-11-05 security standards:
- WordPress Nonce (`X-WP-Nonce` header)
- Bearer Tokens (`Authorization: Bearer <token>`) with rotation support
- Assistant Credentials (generated from assistant editor, OAuth 2.1 compliant)
- Auth0 JWT (for enterprise authentication)
- Session Management (`Mcp-Session-Id` header for reconnection)

### Error Handling

**Enhanced Error System** (Phase 3):
- **Severity Levels**: CRITICAL, ERROR, WARNING, INFO, DEBUG for categorized logging
- **User-Friendly Messages**: Automatic translation of technical errors into actionable guidance
- **Recovery Suggestions**: Built-in troubleshooting steps for common failure scenarios
- **Centralized Error Handler**: Consistent error creation with automatic logging
- **Comprehensive Logging**: Track errors, tool executions, and chat interactions
- **Sensitive Data Protection**: Automatic redaction of API keys and tokens in logs

See [Error Handling Documentation](docs/developer/best-practices/ERROR_HANDLING.md) for detailed usage.

**MCP Standard Error Codes**:
- **-32700**: Parse error (invalid JSON)
- **-32600**: Invalid Request (malformed JSON-RPC)
- **-32601**: Method not found
- **-32603**: Internal error

### Use Cases

| Scenario | Use Endpoint | Method | MCP 2024-11-05 Feature |
|----------|--------------|--------|------------------------|
| Remote MCP client connection | `/mcp` | POST | OAuth 2.1, Sessions |
| Real-time streaming responses | `/sse` | GET | Traditional SSE |
| Streamable HTTP (new) | `/mcp` | POST | Bidirectional streaming |
| Standard chat interface | `/chat` | POST | N/A |
| Direct tool execution | `/tools` | POST | Tool annotations |

### Learn More

➡️ **Complete MCP Documentation:**
- [MCP Endpoint Reference](docs/reference/api/mcp-endpoint.md) - Complete method documentation and 2024-11-05 features
- [MCP and SSE Explained](docs/reference/api/MCP-AND-SSE.md) - Understanding transport layers and protocol updates
- [MCP Server Authentication](docs/reference/api/mcp-server-authentication.md) - OAuth 2.1 and security enhancements
- [MCP Client Configurations](docs/reference/api/mcp-client-configurations.md) - Connect LM Studio, Claude Desktop, etc.
- [Official MCP Specification 2024-11-05](https://modelcontextprotocol.info/specification/2024-11-05/)

---

## 🛠 Assistant Editor Overview

Assistant posts ship with dedicated controls that map directly to runtime behaviour:

- **Available Tools** – Choose which registered tools (core, WooCommerce, JetEngine, or custom) the model may invoke. Dependency-aware notices explain why certain tools are unavailable, and you can now disable the pre-built prompt shortcuts that tools normally contribute.
- **Quick Tool Selection Presets** – one-click presets group the current live registry by use-case (🤖 Agentic Workflow, 🛒 E-commerce, ⚕️ Healthcare, 💬 Communication, 💻 Development, 📋 Registration & Compliance, and more). Click a preset to add its tools to the current selection; click again to remove them. Combine multiple presets freely. Use **✓ Select All** / **✗ Clear All** for bulk actions. Implemented in `includes/helpers/class-wp-mcp-ai-tool-presets-helper.php`.
- **Model Defaults** – Provide assistant-specific overrides for the OpenAI model, temperature (0–2), and system prompt applied to every conversation.
- **Base Knowledge** – Attach Media Library items that are chunked, truncated, and streamed as memory context, and optionally store an external **Vector Store ID** to coordinate retrieval workflows.
- **Prompt Shortcuts** – Capture labelled prompts with optional descriptions and tool affinities; they render as accessible quick actions in the chat UI so operators can seed conversations instantly.【F:includes/assistants/class-wp-mcp-ai-assistant-cpt.php†L893-L1048】【F:includes/class-wp-mcp-ai-shortcode.php†L430-L693】【F:assets/js/chat.js†L600-L666】

If an API or shortcode request omits the `assistant` parameter, the plugin automatically uses the default assistant configured in the global settings.

## 📊 Assistant Storage: CPT vs CCT

NV oOS uses a **Custom Post Type (CPT)** as the primary storage for AI assistants, with automatic synchronization to a **JetEngine Custom Content Type (CCT)** when JetEngine is available.

### Storage Architecture

- **CPT (`mcp_ai_assistant`)**: The authoritative source for all assistant data
  - Full-featured WordPress editor with 14 meta fields
  - Supports credentials, shortcuts, memory files, and advanced features
  - Always available in both Base and Full versions
  - Primary REST endpoint: `/wp-json/mcp-ai/v1/`

- **CCT (`assistants`)**: Synchronized secondary storage (Full Version only)
  - Receives automatic updates when CPT is saved
  - 7 basic fields: title, description, provider, model, system_prompt, temperature, tools
  - Available via JetEngine REST endpoint: `/wp-json/jet-cct/assistants`
  - Ideal for JetEngine-based integrations and queries

### Automatic Synchronization (v1.0.0+)

When you save an assistant through the WordPress admin:

1. **CPT is updated** with all settings
2. **CCT is automatically synced** (if JetEngine is active)
3. **Link is maintained** via `_wp_mcp_ai_cct_item_id` meta
4. **Deletion cascades** - removing CPT also removes linked CCT item

**What gets synced:** Basic configuration (title, description, provider, model, system_prompt, temperature, tools)  
**What's CPT-only:** Advanced features (credentials, shortcuts, memory files, role rules, vector store, external actions)

### When to Use Each Endpoint

**Use CPT endpoint (`/wp-json/mcp-ai/v1/`)** for:
- Chat, tools, and directory interactions
- Full assistant configuration access
- Credential-based authentication
- Primary integration scenarios

**Use CCT endpoint (`/wp-json/jet-cct/assistants`)** for:
- JetEngine-specific queries and filters
- Building JetEngine relations
- Integrating with JetEngine dashboards
- Querying basic assistant metadata

➡️ **[Read the complete CPT vs CCT guide](docs/developer/architecture/integrations/assistant-storage-cpt-vs-cct.md)** for detailed comparisons, code examples, and migration information.

## ⚡ Assistant Tool Shortcuts

Every assistant exposes a **Prompt Shortcuts** meta box so editors can curate prewritten instructions, scope them to registered tools, and add operator-facing descriptions that appear as tooltips and screen reader hints in the chat UI.【F:includes/assistants/class-wp-mcp-ai-assistant-cpt.php†L893-L1048】【F:includes/class-wp-mcp-ai-shortcode.php†L430-L693】【F:assets/js/chat.js†L600-L666】 The shortcode merges these custom prompts with each tool’s declared shortcut tasks and always appends a safe fallback so assistants remain usable even without bespoke entries.【F:includes/class-wp-mcp-ai-shortcode.php†L430-L693】

Developers can extend or replace these prompts with filters such as `wp_mcp_ai_assistant_custom_tool_shortcuts` and `wp_mcp_ai_default_tool_shortcut`, letting sites tailor default quick actions per assistant or environment.【F:includes/class-wp-mcp-ai-shortcode.php†L444-L692】

➡️ [Read the full guide to assistant prompt shortcuts.](docs/getting-started/first-steps/assistant-tool-shortcuts.md)

## 🧠 Agent Skills

**Agent Skills** ([agentskills.io](https://agentskills.io/specification)) are reusable, portable behaviour packages that extend any assistant without touching its system prompt. Each skill is a `SKILL.md` file — a standard Markdown document with a small YAML frontmatter block — that lives in `wp-content/uploads/mcp-ai-skills/{skill-name}/SKILL.md`. When an assistant loads a skill, its instructions are automatically injected into the conversation context so the model knows exactly when and how to use that capability.

### 45 Pre-Built Skills (Base Plugin)

The **base plugin** ships with 45 pre-built skills that are automatically installed to `wp-content/uploads/mcp-ai-skills/` on first activation. No Pro add-on is required — they are available on every install out of the box. The skills include 24 general-purpose tools (document handling, design, testing), 20 WordPress developer skills (security, APIs, plugin patterns), and the new UI/UX Pro Max design system skill.

| Skill slug | What it does |
|---|---|
| `algorithmic-art` | Generates algorithmic art with p5.js, seeded randomness, and interactive parameters |
| `brand-guidelines` | Applies Anthropic's official brand colours and typography to any artifact |
| `canvas-design` | Creates beautiful visual art in PNG/PDF documents using design philosophy |
| `doc-coauthoring` | Guides users through a structured co-authoring workflow for documentation |
| `docx` | Creates, reads, edits, and manipulates Word `.docx` files |
| `frontend-design` | Produces distinctive, production-grade frontend interfaces with high design quality |
| `internal-comms` | Drafts all kinds of internal communications (memos, announcements, updates) |
| `mcp-builder` | Guides creation of high-quality MCP (Model Context Protocol) servers |
| `pdf` | Handles any PDF task — creation, reading, editing, and form filling |
| `pptx` | Handles any `.pptx` PowerPoint file as input or output |
| `skill-creator` | Creates, modifies, and measures the performance of other skills |
| `slack-gif-creator` | Creates animated GIFs optimised for Slack with design best practices |
| `theme-factory` | Applies consistent visual themes to slides, docs, and other artifacts |
| `ui-ux-pro-max` | Comprehensive UI/UX design system with component libraries, color palettes, typography scales, and stack-specific guidelines (React, Vue, Angular, Laravel, etc.) |
| `web-artifacts-builder` | Builds elaborate multi-component HTML artifacts for Claude.ai |
| `webapp-testing` | Tests local web applications using Playwright browser automation |
| `xlsx` | Handles any spreadsheet file as primary input or output |

### How Skills Are Loaded

Skills are selected per-assistant via the **Skills** meta box in the assistant editor. Whichever skills are checked, their combined instructions are prepended to the system prompt under an `# Active Skills` heading at inference time. This means skills are composable — you can combine `pdf` + `xlsx` + `doc-coauthoring` on a single document-specialist assistant.

Skills are stored as plain text files and can be customised in-place. The original bundled content can be restored at any time from **Settings → Advanced → Skill Management → Force Reinstall Bundled Skills**.

### Managing Skills

**Base plugin** — Skill management is available under **Settings → Advanced → Skill Management**:
- View installed skills and their metadata
- Refresh the skill index
- Install or force-reinstall the 16 bundled skills

**Pro add-on** — The dedicated **Skill Manager** admin page (`Assistants → Skill Manager`) adds:
- Upload a `SKILL.md` file or a ZIP archive containing a skill directory
- Install a skill from a remote URL
- Inline CodeMirror editor to create or edit `SKILL.md` content directly in the browser
- Delete / uninstall skills

### SKILL.md Format

```yaml
---
name: my-skill
description: One-line description of what this skill does.
compatibility: claude-3-5-sonnet, claude-3-opus
---

# My Skill

Detailed instructions for the model go here in standard Markdown.
Use headings, lists, code blocks — whatever best conveys the behaviour.
```

The `name` field (max 64 chars) becomes the skill's slug. The `description` field (max 1 024 chars) is shown in the admin UI. The `compatibility` field is optional and informational.

➡️ See [Agent Skills reference](docs/features/agent-skills.md) for the complete specification, filters, and developer API.

---

## 👔 Professional & Team Layers

NV oOS includes an enterprise-grade **template system** for rapid assistant deployment through **Professions** and **Teams**. Instead of manually configuring each assistant from scratch, administrators can:

1. **Select from ~311 pre-built professional templates** spanning 12 industry categories
2. **Create custom profession templates** with reusable configurations
3. **Deploy entire teams** of specialized assistants with one click
4. **Test everything from the backend** before exposing to end users

### 🎓 Professional Templates

Professions are reusable assistant templates with pre-configured:
- **Role descriptions** and expertise areas
- **Default tools** curated for each profession
- **Knowledge bases** with industry-specific best practices
- **AI model defaults** (provider, model, temperature)
- **Warnings and disclaimers** for professional contexts

**Available Categories (~311 professions across 12 categories):**

Methodology note: the current sanity check counts 311 profession knowledge documents; runtime availability can vary with seeders, filters, and installed features.

- 🌾 Agriculture & Natural Resources
- 🎨 Art, Media & Entertainment
- 💼 Business & Finance
- 🎓 Education
- 🏥 Healthcare & Medicine
- ⚖️ Law & Public Safety
- 🔬 Science & Engineering
- 🍽️ Service Industry
- 💻 Technology
- 🔧 Trades & Manual Labor
- 🚚 Transportation
- 📋 Miscellaneous

**Example Professions:**
- Software Developer, Web Developer, Data Scientist
- Accountant, Financial Advisor, Marketing Consultant
- Registered Nurse, Physician, Pharmacist
- Attorney, Paralegal, Mediator
- Content Writer, Graphic Designer, Social Media Manager
- And ~180 more, depending on active seeders and installed features...

### Creating Assistants from Templates

Navigate to **AI Assistants → Add New** to browse the visual profession grid:

1. **Browse by category** or search for a specific role
2. **Click "Create"** on any profession to open a customization modal
3. **Customize** the assistant name and AI settings (or use defaults)
4. **Deploy** your configured assistant instantly

Each profession template includes:
- Pre-written system prompts with role-specific expertise
- Curated tool selections appropriate for the profession
- Industry knowledge bases and best practices
- Recommended model settings for optimal performance

### 👥 Team Deployments

Teams group multiple professionals for coordinated workflows. Deploy an entire team of specialists with one click:

**Pre-Built Teams:**
- **Engineering Team** - Software, Mechanical, Electrical, Civil Engineers
- **Pharmaceutical Development Team** - Pharmacist, Researcher, Clinical Pharmacologist, Regulatory Affairs
- **Research & Data Science Team** - Data Scientist, Research Scientist, Statistician, Computer Scientist
- **Marketing & Growth Team** - Marketing Consultant, Content Creator, Graphic Designer, Business Consultant

**Team Features:**
- **Centralized configuration** - Set provider, model, and temperature for all team members
- **One-click deployment** - Creates all team member assistants simultaneously
- **Consistent settings** - Team defaults override individual profession defaults
- **Custom teams** - Create your own teams with any combination of professions

Navigate to **Teams → Add Team** to deploy a pre-configured team or create custom team combinations.

### 🧪 Backend Testing

Test assistants, professions, and teams directly from the WordPress admin **before** deploying to end users:

#### Test Assistant (Admin → AI Assistants → Test Assistant)

- **Full feature parity** with frontend chat interfaces
- **All tools enabled** including sensitive/restricted tools (admin-only)
- **File upload support** with complete MIME type configuration
- **Transcript saving** for debugging and analysis
- **Tool shortcuts** pre-loaded from assistant configuration
- **Streaming responses** with real-time feedback

#### Test Profession (Admin → Professions → Test Profession)

- **Preview profession templates** before creating assistants
- **Validate role descriptions** and expertise areas
- **Test default tool selections** in live conversations
- **Verify knowledge base** content and accuracy
- **Assess AI model performance** with profession-specific tasks

#### Test Team (Admin → Teams → Test Team)

- **Test entire teams** before deployment
- **Validate team member coordination** and role separation
- **Verify shared settings** propagate correctly
- **Multi-assistant conversations** to test team dynamics
- **Performance benchmarking** across team members

**Security Note:** All test pages require `manage_options` capability and are restricted to WordPress administrators. Sensitive tools are enabled in test environments because administrators already have full site access.

**Documentation:**
- [Test Assistant Feature Enhancements](docs/user-guides/assistants/test-assistant-enhancements.md) - Complete testing capabilities guide
- [Dynamic Assistant Creation System](docs/history/archive/2026/VISUAL_GUIDE_DYNAMIC_ASSISTANTS.md) - Visual guide to profession and team architecture

### Custom Professions & Teams

Administrators can create custom profession templates and teams:

**Create Custom Profession:**
1. Navigate to **Professions → Add New**
2. Set title, description, and category
3. Define expertise areas and role description
4. Select default tools from the registry
5. Add knowledge base content
6. Configure AI model defaults
7. Publish for use in assistant creation

**Create Custom Team:**
1. Navigate to **Teams → Add New**
2. Set team name and description
3. Select profession members from your library
4. Configure team-wide defaults (provider, model, temperature)
5. Publish to enable one-click team deployment

### Benefits

**For Organizations:**
- ✅ Rapid assistant deployment without manual configuration
- ✅ Consistent configurations across similar roles
- ✅ Template library grows with your organization
- ✅ Share profession templates across sites
- ✅ Professional-grade assistant quality out of the box

**For Administrators:**
- ✅ Test everything safely from the backend
- ✅ No coding required for template-based assistants
- ✅ Visual template selection interface
- ✅ Reusable configurations reduce errors
- ✅ Full control over custom templates

**For Developers:**
- ✅ JSON-based knowledge base system
- ✅ Extensible via filters and hooks
- ✅ WordPress standard CPT architecture
- ✅ REST API access for profession and team data
- ✅ Automated seeding from knowledge base files

---

## 🔑 Assistant API credentials

Administrators can issue per-assistant access tokens from the **API Credentials** meta box that appears on every assistant edit screen. Tokens are only available to users with the `manage_options` capability, surface the credential history in a table, and expose one-click revoke and delete actions for rapid cleanup.【F:includes/assistants/class-wp-mcp-ai-assistant-cpt.php†L483-L595】 When you click **Generate Credential** the plugin produces a single-use token in the form `cred_xxxxx.SECRET`, hashes the secret server-side, and records the issuer so you have an audit trail of who created each credential.【F:includes/class-wp-mcp-ai-credentials.php†L94-L135】

Remote integrations can authenticate by sending that token in the standard `Authorization: Bearer` header—no Auth0 dependency required. The REST layer validates the credential, emits structured errors when a token is revoked or malformed, and scopes the request to the assistant that issued the token so clients cannot hop between assistants without an explicit credential for each one.【F:includes/class-wp-mcp-ai-rest.php†L316-L444】【F:includes/class-wp-mcp-ai-rest.php†L1282-L1321】【F:includes/class-wp-mcp-ai-credentials.php†L242-L297】

---

## 🐳 Local Development with Docker

Spin up a disposable WordPress instance that mounts the plugin source directly into the container:

```bash
docker compose up -d
```

- WordPress will be available at [http://localhost:8000](http://localhost:8000).
- The plugin source in this repository is mounted to `/var/www/html/wp-content/plugins/mcp-ai-wpoos` inside the container, so edits on your machine are reflected immediately.
- The MySQL service is provisioned with the `wordpress` database, user, and password (`wordpress` / `wordpress`).

Visit the site in your browser to complete the standard WordPress installation flow, using the database credentials above when prompted. When you're finished developing, stop the stack with `docker compose down`.

### 🔁 Codex environment startup script

If you are working inside an OpenAI Codex environment, add `bin/codex-startup.sh` to your workspace start-up tasks so a fresh WordPress install is provisioned automatically for every session — no Docker required.

```bash
bin/codex-startup.sh
```

The script performs the following steps:

- Downloads WP-CLI locally (if necessary) and uses it to fetch the latest WordPress core files into `.codex-wordpress/wordpress`.
- Installs the [SQLite Database Integration](https://wordpress.org/plugins/sqlite-database-integration/) plugin so WordPress can run without a MySQL server.
- Symlinks this repository into the new install's `wp-content/plugins/mcp-ai-wpoos` directory.
- Installs Composer development dependencies (when available) and provisions the WordPress test suite so `composer run test` works immediately.
- Runs `wp core install`, activates the **NV oOS** plugin, enables pretty permalinks, and sets a default site tagline.
- Boots a development server on port `8000` via `wp server` and logs output to `.codex-wordpress/wp-server.log`.

Default credentials:

| Setting | Value |
| --- | --- |
| Site URL | `http://localhost:8000` |
| Admin user | `admin` |
| Admin password | `password` |
| Admin email | `admin@example.com` |

Override any of these values by exporting the environment variables `WORDPRESS_URL`, `WORDPRESS_TITLE`, `WORDPRESS_ADMIN_USER`, `WORDPRESS_ADMIN_PASSWORD`, `WORDPRESS_ADMIN_EMAIL`, or `WORDPRESS_PORT` before running the script.

---

## 🧑‍💻 Development Tooling

Install the PHP development dependencies (including PHP_CodeSniffer, the WordPress Coding Standards ruleset, and PHPUnit) with:

```bash
bin/setup-dev.sh
```

The script runs `composer install` and makes the following Composer scripts available:

| Purpose | Command |
| --- | --- |
| WordPress coding standards lint | `composer run lint` |
| PHP compatibility checks (PHP 7.4–8.3) | `composer run lint:compat` |
| Auto-fix coding standards violations | `composer run format` |
| Generate the translation template | `composer run pot` |
| Install the WordPress unit test scaffolding | `composer run test:install` |
| Execute the PHPUnit suite | `composer run test` |

These commands automatically resolve the bundled `vendor/bin` tools (such as `phpcs`, `phpcbf`, and `phpunit`), so a global installation is no longer required.

> [!NOTE]
> The `test:install` script prefers the Composer-provided `wp-phpunit/wp-phpunit` package for the WordPress test suite. Run `composer install` before invoking it, especially on networks where `develop.svn.wordpress.org` is inaccessible.

### NPM Dependencies & Bundling

For details on how NPM dependencies are managed and bundled for both the base plugin and Pro addon, see [DEPENDENCIES_BUNDLING.md](docs/project/releases/DEPENDENCIES_BUNDLING.md).

**Quick Reference:**
- Base plugin dependencies: `@microsoft/fetch-event-source`, `dompurify`, `marked`, `ky`, `chart.js`, `@neplex/vectorizer`, `@langchain/*`, `@mlc-ai/web-llm`
- Build commands: `npm run build:js`, `npm run install:chartjs`, `npm run install:vectorizer`, `npm run build:js:pro`
- Pro addon has separate `addons/pro/package.json` for Pro-specific dependencies

---

## 📦 NPM Packages

Twenty-three standalone browser-utility packages have been extracted from the oOS chat UI and published to the NPM registry under the `@nvdigitalsolutions` scope. Each package is independently usable in any JavaScript/TypeScript project (no WordPress required).

| Package | Description | Dependencies |
|---------|-------------|--------------|
| [`nvoos-storage`](packages/nvoos-storage/) | Async JSON via Web Worker — prevents main-thread blocking for large data | Zero |
| [`nvoos-markdown`](packages/nvoos-markdown/) | XSS-safe markdown renderer with configurable allowed-tags profile | `marked`, `dompurify` |
| [`nvoos-events`](packages/nvoos-events/) | SSE client with POST support + mitt-compatible job event bus | `@microsoft/fetch-event-source` |
| [`nvoos-http-client`](packages/nvoos-http-client/) | HTTP client with automatic retry, exponential backoff, and request hooks | `ky` |
| [`nvoos-clipboard`](packages/nvoos-clipboard/) | `copyTextToClipboard()` with Clipboard API / `execCommand` fallback | Zero |
| [`nvoos-offline-sync`](packages/nvoos-offline-sync/) | IndexedDB offline-first sync with automatic server sync on reconnect | Zero |
| [`nvoos-slash-commands`](packages/nvoos-slash-commands/) | Slash command system with fuzzy-search autocomplete and execution engine | Zero |
| [`nvoos-audio`](packages/nvoos-audio/) | Browser audio I/O: TTS, STT, translation, voice chat with VAD | Zero |
| [`nvoos-dom-batcher`](packages/nvoos-dom-batcher/) | `requestAnimationFrame` DOM batcher, scroll batcher, and UI utilities for streaming UIs | Zero |
| [`nvoos-api`](packages/nvoos-api/) | Typed REST API client — endpoint builders, request helpers, and payload constructors | Zero |
| [`nvoos-attachments`](packages/nvoos-attachments/) | File attachment helpers: type detection, validation, normalisation, segment builders | Zero |
| [`nvoos-chat-bubble`](packages/nvoos-chat-bubble/) | Floating chat bubble widget — accessibility, sessionStorage, badge notifications, MutationObserver | Zero |
| [`nvoos-chat-memory`](packages/nvoos-chat-memory/) | Promise-based REST client for AI chat memory bridge (wake-up, recall, store, audit, preferences) | Zero |
| [`nvoos-chat-memory-ui`](packages/nvoos-chat-memory-ui/) | Chat memory drawer UI — side panel for viewing, editing, scoping, and exporting long-term AI memories | Zero |
| [`nvoos-client-tools`](packages/nvoos-client-tools/) | Browser-native AI tool registry (summarize, sentiment, translate, embed, image, audio) using Transformers.js | Zero |
| [`nvoos-cron-status`](packages/nvoos-cron-status/) | SSE-first cron/job status monitor with REST polling fallback | Zero |
| [`nvoos-llm-worker`](packages/nvoos-llm-worker/) | Web Worker manager for non-blocking LLM operations | Zero |
| [`nvoos-model-loader`](packages/nvoos-model-loader/) | Progressive AI model loading UI with 4-stage progress tracking | Zero |
| [`nvoos-sse-client`](packages/nvoos-sse-client/) | TypeScript-native SSE connection manager with lifecycle tracking, per-connection status, automatic cleanup | Zero |
| [`nvoos-transcription`](packages/nvoos-transcription/) | MediaRecorder-based audio recording + tool-call transcription pipeline for AI chat surfaces | Zero |
| [`nvoos-transformers-client`](packages/nvoos-transformers-client/) | HuggingFace Transformers.js task wrapper (summarization, sentiment, NER, translation, QA, embeddings) | Zero |
| [`nvoos-types`](packages/nvoos-types/) | Canonical TypeScript type definitions — AI providers, chat messages, tool execution, SSE streaming, attachments, history, memory, agents, WordPress global augmentations | Zero |
| [`nvoos-vad`](packages/nvoos-vad/) | Browser Voice Activity Detection (VAD) using the Web Audio API | Zero |

### Installation

```bash
# Tier 1 — Core utilities
npm install @nvdigitalsolutions/nvoos-storage
npm install @nvdigitalsolutions/nvoos-markdown marked dompurify
npm install @nvdigitalsolutions/nvoos-events @microsoft/fetch-event-source
npm install @nvdigitalsolutions/nvoos-types

# Tier 2 — Extended browser utilities
npm install @nvdigitalsolutions/nvoos-http-client ky
npm install @nvdigitalsolutions/nvoos-clipboard
npm install @nvdigitalsolutions/nvoos-offline-sync
npm install @nvdigitalsolutions/nvoos-sse-client
npm install @nvdigitalsolutions/nvoos-api
npm install @nvdigitalsolutions/nvoos-attachments

# Tier 3 — Chat UI utilities
npm install @nvdigitalsolutions/nvoos-slash-commands
npm install @nvdigitalsolutions/nvoos-audio
npm install @nvdigitalsolutions/nvoos-dom-batcher
npm install @nvdigitalsolutions/nvoos-chat-bubble
npm install @nvdigitalsolutions/nvoos-chat-memory
npm install @nvdigitalsolutions/nvoos-chat-memory-ui
npm install @nvdigitalsolutions/nvoos-cron-status
npm install @nvdigitalsolutions/nvoos-vad
npm install @nvdigitalsolutions/nvoos-transcription

# Tier 4 — AI runtime utilities
npm install @nvdigitalsolutions/nvoos-client-tools
npm install @nvdigitalsolutions/nvoos-llm-worker
npm install @nvdigitalsolutions/nvoos-model-loader
npm install @nvdigitalsolutions/nvoos-transformers-client
```

### Publishing

Two GitHub Actions workflows handle NPM publishing automatically:

| Workflow | Trigger | Tag pattern |
|----------|---------|-------------|
| `.github/workflows/npm-publish.yml` | Push tag or `workflow_dispatch` | `v*.*.*` |
| `.github/workflows/npm-publish-alpha.yml` | Push tag or `workflow_dispatch` | `v*.*.*-alpha.*` |

**Setup** — only one secret is required: add an `NPM_TOKEN` to the repository at *Settings → Secrets and variables → Actions*.

**Adding a new package**: update the `PACKAGES` environment variable in both workflow files and place the package directory under `packages/`.

See [`packages/README.md`](packages/README.md) for a full package listing and API overview, and [`packages/QUICK_START.md`](packages/QUICK_START.md) for usage examples.

---

## 🧪 Testing & QA


- `composer run test` executes the PHPUnit suite bundled with `wp-phpunit/wp-phpunit` and Yoast’s polyfills, covering REST, tooling, and helper contracts.【F:composer.json†L16-L23】
- Run `composer run test:install` once per environment to provision the WordPress test scaffolding before the first test pass.【F:composer.json†L16-L23】
- For offline or air-gapped environments, use `./bin/package-vendor-dev.sh` to create a downloadable test framework package (~140 MB), then `./bin/install-vendor-dev.sh` to deploy it without requiring composer or internet access.

### Coding standards & static analysis
- Enforce the WordPress Coding Standards with `composer run lint`; auto-fix what you can with `composer run format`.【F:composer.json†L16-L23】
- Validate cross-version compatibility (PHP 7.4–8.3) via `composer run lint:compat` prior to release builds.【F:composer.json†L16-L23】

### Manual smoke tests
- Follow the scenarios in [## ✅ Manual QA Scenarios](#manual-qa-scenarios) after significant changes to chat flows, tool execution, or authentication wiring.
- For logging-centric debugging, enable logging in the NV oOS settings and reference the retrieval commands in [🪵 Logging](#logging).

---

## ⚙️ CI/CD Pipelines

The repository runs ~30 automated GitHub Actions workflows on every push and PR:

| Workflow | Purpose |
|----------|---------|
| `phpunit.yml` | PHPUnit test suite (PHP 8.1, MySQL 8.0) |
| `javascript-tests.yml` | Jest-based JS test suite |
| `php-linting.yml` | PHPCS + PHP compatibility (7.4–8.3) |
| `security.yml` | Dependency vulnerability scanning |
| `security-regression.yml` | Security regression tests |
| `build-spa-addons.yml` | Build all SPA addon ZIPs |
| `build-canvas-addon.yml` | Canvas addon ZIP build |
| `build-comic-reader-addon.yml` | Comic Reader addon ZIP build |
| `build-nvoos-graphify*.yml` | Graphify standalone plugin builds (3 workflows) |
| `npm-publish.yml` / `npm-publish-alpha.yml` | NPM package publishing (stable + alpha) |
| `release.yml` | GitHub Release automation |
| `chat-parity-check.yml` | Cross-provider chat parity testing |
| `qa-e2e.yml` | End-to-end QA tests |
| `spa-a11y.yml` | WCAG 2.1 AA accessibility checks |
| `spa-bundle-size.yml` | SPA bundle size monitoring |
| `link-check.yml` | Documentation link validation |
| `cloud-worker-tests.yml` | Cloud Worker integration tests |
| `post-deploy-health.yml` | Post-deployment health checks |
| `sync-nvoos-*.yml` | lib/ package sync to standalone repos (5 workflows) |
| `stale.yml` | Stale issue/PR management |
| `auto-label.yml` | Automated PR labeling |
| `project-automation.yml` | GitHub project board automation |

---

## 💬 Frontend Shortcode
Embed a published assistant anywhere on the site with the shortcode. Replace `123` with the post ID of the assistant you created under **AI Assistants**.

```html
[mcp_ai_chat assistant="123"]
```

### How it works
- The shortcode renders a lightweight chat UI that talks to the plugin's REST API endpoints.
- Scripts and styles are enqueued automatically and include REST nonces plus the selected assistant ID.
- Responses are displayed inline, including tool invocation feedback when the model requests a registered tool.

### Requirements
- The assistant post must be **published** and, by default, the current user must have the `edit_posts` capability (matching the REST permission check). Add `allow_guests="true"` to the shortcode when you want anonymous visitors to participate in the chat.
- An OpenAI API key and default model must be configured in **Settings → NV oOS**.

### Tips
- Omit the `assistant` attribute to fall back to the default assistant configured in the settings screen.
- Multiple shortcodes can be added to the same page; each chat instance maintains its own conversation context on the client.
- Use `allow_guests="true"` to expose the chat UI to logged-out visitors. Each render issues a short-lived guest token that authorises REST requests without a WordPress login.
- REST interactions rely on the `[wp_rest]` nonce, so caching plugins should avoid caching pages for logged-in editors running the chat.

### Elementor widget
- Elementor sites automatically gain an **NV oOS Chat** widget that mirrors the shortcode controls, including the optional assistant selector and the guest access toggle.【F:includes/elementor/class-wp-mcp-ai-elementor-widget.php†L17-L109】
- Leaving the assistant control blank falls back to the default assistant configured in the plugin settings, and enabling **Allow Guests** injects the same temporary tokens used by the shortcode flow.【F:includes/elementor/class-wp-mcp-ai-elementor-widget.php†L45-L110】【F:includes/class-wp-mcp-ai-shortcode.php†L132-L224】
- The Elementor chat widget can surface everything saved on the assistant post—model defaults, knowledge files, prompt shortcuts, and assigned tools—so you can build documentation and dashboards without copying values manually.【F:includes/elementor/class-wp-mcp-ai-elementor-widget.php†L95-L845】

---

## 🧵 REST Chat Payloads & Attachments

The `/wp-json/mcp-ai/v1/chat` endpoint accepts rich, multi-part messages. Each message object still requires a `role`, but the
`content` may now be either a plain string or an array of structured segments that map to OpenAI's multimodal contract.

```json
{
  "assistant_id": 123,
  "messages": [
    {
      "role": "user",
      "content": [
        { "type": "text", "text": "Describe this photo" },
        { "type": "input_image", "attachment_id": 456, "detail": "high" }
      ]
    }
  ],
  "options": {
    "response_format": { "type": "json_schema", "json_schema": { "name": "caption" } }
  }
}
```

### Supported segment types

- `text` – Free-form text (`text` property). Strings supplied directly to `content` are automatically wrapped in this format. For backwards compatibility, existing `input_text` payloads sent to the REST API are still accepted and normalised to the new schema.
- `input_image` – Reference an uploaded WordPress attachment (`attachment_id`) or provide a remote `url`. Optional `detail`
  hints (`low`, `auto`, `high`) and `caption` fields are preserved. *(Fixed in v1.0.0: Chat client attachments now properly processed)*
- `input_file` – Reference an uploaded attachment that should be streamed to the model. *(Fixed in v1.0.0: Chat client file attachments now properly processed)*

The REST controller validates attachment ownership/permissions, enforces a default 5 MB size cap (filterable via
`wp_mcp_ai_max_attachment_bytes`), and only allows safe MIME types by default. Text and structured data formats include
Markdown, CSV/TSV, HTML, JSON/JSONL/NDJSON, and XML; binary documents cover PDFs and Microsoft Word/PowerPoint/Excel variants;
and audio/video uploads accept AAC/FLAC/M4A/MP3/OGG/OPUS/WAV/WEBM plus MP4 or QuickTime sources. 【F:includes/class-wp-mcp-ai-message-attachments.php†L642-L709】

Whenever attachments are present, the plugin automatically inlines the asset data when sending requests to OpenAI's Responses API.
Image segments are converted to data URLs and file segments include the base64-encoded payload alongside the original filename,
so integrators do not need to upload assets manually before invoking a model.

REST requests that include attachments automatically gain access to the bundled **Submit Document Prompt** tool so the files reach OpenAI even when the assistant has the tool disabled in its configuration.【F:includes/class-wp-mcp-ai-rest.php†L22-L29】【F:includes/class-wp-mcp-ai-rest.php†L963-L991】

Assistant memory files configured on the post (`memory_files`) are also promoted to structured `text` segments on the
system channel, retaining the existing chunking/truncation safeguards.

Need to relax or tighten the allowed file types? Administrators can override the image and file MIME lists directly in **Settings → NV oOS → Attachments**, and the same values are used by shortcode-driven chat surfaces (including the Elementor widget) when building upload restrictions.【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L225-L267】【F:includes/class-wp-mcp-ai-message-attachments.php†L456-L565】【F:includes/class-wp-mcp-ai-shortcode.php†L197-L218】 When JSON Lines support is enabled in the allowlist the plugin also registers `.jsonl` and `.ndjson` extensions with WordPress so uploads succeed without additional filters.【F:mcp-ai-wpoos.php†L236-L272】

Assistants can also query existing knowledge files with the **Search Attachments** tool, which reuses `WP_MCP_AI_Message_Attachments::user_can_access_attachment()` so only publicly accessible or user-owned media is returned alongside download URLs and file metadata for the model to reuse.【F:includes/tools/class-wp-mcp-ai-tool-search-attachments.php†L15-L207】【F:includes/class-wp-mcp-ai-message-attachments.php†L480-L575】

---

## 🔐 JetEngine Capability Reference

When the plugin interacts with JetEngine objects it defers to the capabilities enforced by JetEngine’s own REST handlers and editor interfaces. Use the following table to review the specific capability checks that gate each object type:

| Object / Context | Capability string(s) | Notes |
| --- | --- | --- |
| Custom Post Type editor & REST endpoints | `manage_options` | Editing built-in post types and all CPT REST endpoints require the user to have `manage_options`. |
| Custom Taxonomy editor & REST endpoints | `manage_options` | Built-in taxonomy edits and every taxonomy REST endpoint enforce the `manage_options` capability. |
| Relation management UI & REST endpoints | `manage_options` | Creating, editing, listing, or deleting relations through the admin REST handlers requires `manage_options`. |
| Relation REST access settings (`rest_get_access`, `rest_post_access`) | Stored capability string or `'public'` (default `manage_options`) | The public REST controller checks a capability stored in relation args; if blank or `'public'` the request is allowed, otherwise `current_user_can( $cap )` is enforced. Newly created relations default `rest_post_access` to `manage_options` in the editor UI. |
| Relation object type “Posts” | `edit_post`, `delete_post` | Editing or deleting related post items requires the corresponding post capability for the specific post ID. |
| Relation object type “Taxonomy Terms” | `edit_term`, `delete_term` | Term relations check the matching term capabilities for the targeted term ID. |
| Relation object type “Mix → Users” | `edit_users` (for edits); deletion disallowed | Editing user relations needs `edit_users`; deletions are explicitly forbidden (returns `false`). Other mix objects defer to filters for capability checks. |
| Relation object type “Custom Content Types (CCT)” | Configured capability (defaults to `manage_options`) | Relation checks defer to the CCT’s `user_has_access()`, which in turn checks `current_user_can( $this->user_cap() )`; the capability defaults to `manage_options` unless overridden in the CCT settings or filters. |



---

## 🛰 JetEngine REST API Reference

- 📄 Review the full endpoint catalogue in [`docs/reference/api/jet-engine-rest-routes.md`](docs/reference/api/jet-engine-rest-routes.md) for route paths, callbacks, and required parameters.
- 🤖 When JetEngine is active, assistants can invoke the **List JetEngine REST Routes** tool to retrieve the same metadata directly inside a conversation (requires a user with the `manage_options` capability).

---

## 🪵 Logging

- Enable or disable logging from **Settings → NV oOS → Enable Logging**.
- When logging is enabled the plugin records:
  - Chat requests and responses processed by the REST API.
  - Tool executions (including permission denials).
  - Errors returned from the OpenAI API and internal validation.
- Log entries are written via PHP's `error_log()` and can be filtered with `wp_mcp_ai_log_entry` to route them elsewhere.【F:includes/class-wp-mcp-ai-logger.php†L16-L137】
- Recent errors and activity snapshots are also persisted in the `wp_mcp_ai_recent_errors` (50 entries) and `wp_mcp_ai_recent_activity` (100 entries) options for dashboards and widgets, keeping autoload disabled to avoid bloating frontend requests.【F:includes/class-wp-mcp-ai-logger.php†L611-L662】
- Retrieve those rolling buffers quickly with WP-CLI when debugging production incidents:
  ```bash
  wp option get wp_mcp_ai_recent_errors --format=json
  wp option get wp_mcp_ai_recent_activity --format=json
  ```

---

## 🧾 JetEngine REST Endpoint Report Helper

Use the JetEngine report helper to surface the CRUD coverage matrix that was compiled during the REST endpoint audit. The helper exposes the underlying endpoint metadata as a structured array so you can reuse it in documentation, dashboards, or custom checks.

```php
$report = wp_mcp_ai_get_jetengine_endpoint_report();

foreach ( $report['coverage'] as $resource => $operations ) {
    printf( "%s supports: %s\n", ucfirst( $resource ), implode( ', ', array_keys( array_filter( $operations ) ) ) );
}

if ( empty( $report['missing'] ) ) {
    echo "All CRUD operations are covered.";
}
```

The helper is filterable via:

- `wp_mcp_ai_jetengine_endpoint_routes` – Adjust the source routes before the coverage matrix is derived.
- `wp_mcp_ai_jetengine_endpoint_coverage` – Modify the generated CRUD coverage.
- `wp_mcp_ai_jetengine_missing_operations` – Override the derived list of missing operations per resource.

Each filter receives the full data set so you can extend or replace the output when JetEngine adds new endpoints or when your project needs to surface additional metadata.

---

## 🔌 Optional Tools & Dependencies

**NV oOS works perfectly with vanilla WordPress** - you don't need any third-party plugins for core functionality.

However, certain features require third-party plugins (sold separately). The plugin automatically detects which plugins are active and enables the corresponding tools:

### Plugin Detection & Tool Loading

- **JetEngine** (5 tools) – Server-side chat transcripts, JetEngine content access, JetFormBuilder integration
- **WooCommerce** (3 tools) – E-commerce automation, product/order management
- **Elementor** (1 tool + widgets) – Template management, pre-built chat widgets
- **Rank Math SEO** (1 tool) – SEO analysis and schema data access
- **WPCode** (1 tool) – Code snippet management and automation

**📖 See the complete breakdown:** [🔌 What You Lose Without Third-Party Plugins](#what-you-lose-without-third-party-plugins)

### How It Works

1. Each tool description in the admin UI shows which plugin it requires
2. Tools are automatically hidden when their dependency is missing
3. Administrators see informational notices explaining unavailable tools
4. No errors occur - the plugin gracefully handles missing dependencies

---

## ✅ Manual QA Scenarios

The project currently relies on manual verification. Run these checks after updating the plugin:

1. **Baseline (no optional plugins)**
   - Deactivate WooCommerce and JetEngine.
   - Load the AI Assistant edit screen and confirm only core tools appear. No PHP notices or fatal errors should occur.
   - Visit the WordPress dashboard to confirm the informational notices explain why optional tools are disabled.
2. **WooCommerce enabled**
   - Activate WooCommerce.
   - Reload the Assistant editor and ensure the WooCommerce Orders and Products tools appear and can be selected.
   - Trigger each tool (e.g., via an assistant conversation) and confirm recent orders and product summaries return without errors.
3. **JetEngine enabled**
   - Activate JetEngine.
   - Confirm the JetEngine Items tool appears for assistants and returns data for a configured JetEngine post type.
4. **Tool call retry resilience**
   - Initiate a chat conversation that triggers a tool call (for example, request an operation that requires either WooCommerce tool).
   - After the tool output appears, send a follow-up message that prompts the assistant to continue without invoking another tool.
   - Confirm the follow-up succeeds without a JavaScript console error referencing a missing `tool_call_id`.

Document the results of each scenario when preparing releases to ensure optional integrations remain stable.

---

## 🧩 Hooks & Filters

Use the following hooks to extend the plugin:

| Hook | Type | Description |
| --- | --- | --- |
| `do_action( 'wp_mcp_ai_before_chat_request', $assistant_id, $messages, $options, $request )` | Action | Fires before a chat request is sent to OpenAI. |
| `do_action( 'wp_mcp_ai_after_chat_response', $assistant_id, $response, $request )` | Action | Fires after a chat response is received. |
| `apply_filters( 'wp_mcp_ai_chat_options', $options, $assistant_config, $request )` | Filter | Modify the OpenAI request options before dispatch. |
| `apply_filters( 'wp_mcp_ai_chat_capability', $capability, $assistant_id, $context )` | Filter | Adjust the capability required to use the chat shortcode and REST endpoints (defaults to `edit_posts`). Return `'public'` or an empty value to allow any visitor. |
| `do_action( 'wp_mcp_ai_before_tool_execution', $tool_slug, $arguments, $context )` | Action | Runs immediately before a tool executes. |
| `apply_filters( 'wp_mcp_ai_tool_output', $result, $tool_slug, $arguments, $context )` | Filter | Inspect or transform tool output before it is returned. |
| `do_action( 'wp_mcp_ai_after_tool_execution', $tool_slug, $arguments, $context, $result )` | Action | Runs after a tool completes execution. |
| `apply_filters( 'wp_mcp_ai_log_entry', $entry, $type, $message, $context )` | Filter | Intercept or redirect logging output. |
| `apply_filters( 'wp_mcp_ai_onboarding_presets', $presets )` | Filter | Add, remove, or modify onboarding wizard use-case presets. Each preset defines tools, system prompt, temperature, and assistant name. |
| `do_action( 'wp_mcp_ai_onboarding_presets_seeded', $created, $preset_keys )` | Action | Fires after the onboarding wizard creates assistant CPT posts from selected presets. `$created` maps preset keys to post IDs. |

---

## 🧰 WP-CLI Commands

Manage the NV oOS environment from the command line when WP-CLI is available.

| Command | Description |
| --- | --- |
| `wp mcp-ai status` | Summarises WordPress core details, PHP version, and NV oOS supported plugin coverage. |
| `wp mcp-ai remote <base>` | Probes a remote MCP REST namespace (such as `https://example.com/wp-json/mcp-ai/v1`) by loading the assistant directory and issuing a lightweight `POST /chat` probe, reporting connectivity, assistant counts, and token scope metadata. |
| `wp mcp-ai plugins list` | Lists optional dependencies (WooCommerce, JetEngine, etc.) with install and activation state. |
| `wp mcp-ai plugins activate <slug>` | Activates a supported plugin; pass `--network` on multisite installations. |
| `wp mcp-ai plugins deactivate <slug>` | Deactivates a supported plugin; pass `--network` on multisite installations. |
| `wp mcp-ai chat <message>` | Sends a one-shot chat message to an assistant via the language model router. Accepts `--assistant`, `--model`, `--provider`, `--temperature`, `--max-tokens`, `--stream`, and `--format` flags. |
| `wp mcp-ai memory recall` | Recalls agent memory entries. Use `--assistant` to scope operations. |
| `wp mcp-ai thread list` | Lists chat threads. Use `--assistant` to filter. Also supports `get` and `delete` subcommands with `--thread-id`. |
| `wp mcp-ai provider list` | Lists all 15 AI providers with enabled/disabled status. Also supports `test <slug>` and `models <slug>` subcommands. |
| `wp mcp-ai cron list` | Lists scheduled cron jobs tracked by NV oOS. Also supports `run <job-id>` and `delete <job-id>` subcommands. |
| `wp mcp-ai transcript list` | Lists transcripts eligible for mining. Use `--assistant` to filter. Also supports `mine` subcommand for batch mining. |
| `wp mcp-ai approval list` | Lists pending human-in-the-loop approval items. Accepts `--format` flag. Also supports `approve` and `reject` subcommands with `--item-id`. |
| `wp mcp-ai tool list` | Lists all registered tools with status, capability, and toolkit metadata. Accepts `--status`, `--format`, `--toolkit`, and `--search` flags. Also supports `enable <slug>` and `disable <slug>`. |
| `wp mcp-ai assistant list` | Lists all published AI assistants. Accepts `--status` and `--format` flags. |
| `wp mcp-ai credential list` | Lists API credentials configured for an assistant. |
| `wp mcp-ai slash-command list` | Lists registered slash commands. |
| `wp mcp-ai settings get` | Retrieves all NV oOS settings. |
| `wp mcp-ai cache clear` | Clears the NV oOS object cache. |

`wp mcp-ai remote` accepts additional flags so you can mirror the authentication mode used by your deployment while exercising TLS and timeout controls:

- `--token=<token>` – Include an Auth0 access token or assistant-issued credential via the `Authorization` header.
- `--guest-token=<token>` – Attach a guest token when testing public chat surfaces that rely on the `X-WP-MCP-AI-Guest` header.
- `--nonce=<nonce>` – Supply a WordPress REST nonce for same-origin checks.
- `--assistant-id=<id>` – Hint which assistant to load when the directory endpoint supports scoped tokens.
- `--timeout=<seconds>` – Override the default 15-second timeout when probing slow networks.
- `--verify-ssl=<boolean>` – Toggle certificate validation (defaults to `true`).
- `--user-agent=<agent>` – Send a custom user agent instead of the built-in `WP-MCP-AI-Remote-Tester/<version>` signature.

Filter `wp_mcp_ai_supported_plugins` to expose additional managed dependencies to the CLI helpers.

Each hook receives sanitized data and respects the current user's permissions and multisite membership.

---

## 🆘 Getting Help & Support

### Documentation Resources

Start with the comprehensive documentation before seeking additional support:

1. **[Quick Reference Guide](docs/QUICK_REFERENCE.md)** - Fast answers to common questions and tasks
2. **[Documentation Index](docs/DOCUMENTATION_INDEX.md)** - Navigate all 1,600+ documentation files
3. **[Troubleshooting Guide](docs/getting-started/installation-setup/deployment-troubleshooting.md)** - Solutions to common issues
4. **[REST API Reference](docs/reference/api/rest-api.md)** - Complete API documentation

### Before Reporting Issues

When encountering problems, please:

- [ ] Check the [troubleshooting guide](docs/getting-started/installation-setup/deployment-troubleshooting.md)
- [ ] Enable logging in Settings → NV oOS to capture detailed errors
- [ ] Review the [common issues section](#-common-issues) below
- [ ] Search [existing GitHub issues](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues)
- [ ] Test with a default assistant to isolate configuration issues

### Common Issues

#### npm EACCES Permission Error (package-lock.json)
If you get `EACCES: permission denied, open '.../package-lock.json'` when running `npm install`:

**This means you do NOT need to run `npm install`.**

The plugin distributes pre-built minified assets (`.min.js`/`.min.css` files) so `npm install` is **never required** for production use. You only need npm if you are a developer modifying JavaScript source files.

**Solutions:**
- **If installing from ZIP**: Simply upload and activate the plugin. No npm commands needed.
- **If cloning the repository for production use**: Activate the plugin as-is. The pre-built assets in the repository are ready for production.
- **If you need to rebuild assets** (development only): Run npm on a development machine where you have write access, then deploy the built files.

If you must run npm in a restricted directory (e.g., during CI or scripted deployments), use:
```bash
npm install --no-package-lock
```

#### npm/Composer Install Error After Cloning
If you get `ENOENT: no such file or directory, uv_cwd` (npm) or `getcwd() failed` (composer) errors:

**For Cloudways Users (Most Common):**

These errors occur when you try to run npm or composer from a directory that has been moved, deleted, or no longer exists. This commonly happens when you clone outside the WordPress plugins directory and then move/copy files while your shell session is still in the original location.

**Solution: Always clone directly into the plugins directory:**

```bash
# SSH into your Cloudways server
cd /home/master/applications/YOURAPP/public_html/wp-content/plugins/

# Clone directly (replace YOURAPP with your application name)
git clone https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git
cd mcp-ai-wpoos

# Verify you're in the right place
pwd  # Should show the full plugins path

# NOTE: npm install is NOT required for production use.
# Activate the plugin in WordPress admin - it is ready to use.
# Only run composer if you need to update PHP dependencies (development only):
# composer install --no-dev
```

**For Local Development or VPS:**

1. **Ensure you're in the correct directory** - Run `pwd` to verify you're in the `mcp-ai-wpoos` directory
2. **Do not run commands from a moved/deleted directory** - If you moved files, open a new terminal session in the new location
3. **Production workflow** (no npm or composer needed):
   ```bash
   # Clone the repository
   git clone https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git

   # Copy to WordPress plugins directory
   cp -r mcp-ai-wpoos /path/to/wordpress/wp-content/plugins/
   # Plugin is ready to activate - no build step required.
   ```

4. **Development workflow** (only if you need to rebuild JS/CSS assets):
   ```bash
   # Clone the repository on your development machine (not the server)
   git clone https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git
   cd mcp-ai-wpoos

   # Install dev dependencies and rebuild assets
   npm install && npm run build
   composer install --no-dev

   # Deploy built files to the server
   ```

5. **Alternative: Clone directly into WordPress** - This avoids copy/move issues:
   ```bash
   cd /path/to/wordpress/wp-content/plugins/
   git clone https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git
   # Activate the plugin - it is production-ready without any npm or composer commands.
   ```

#### Chat Not Working
1. Verify OpenAI API key is configured in Settings → NV oOS
2. Ensure assistant is published
3. Check user has `edit_posts` capability or add `allow_guests="true"` to shortcode
4. Enable logging and check browser console for errors

#### Tool Execution Failures
1. Verify tool is enabled for the assistant
2. Check required dependencies are installed (WooCommerce, JetEngine, etc.)
3. Ensure user has necessary capabilities
4. Review tool-specific requirements in [tool reference](docs/reference/tools/tool-reference.md)

#### Remote Client Connection Issues
1. Verify credentials are correct and not expired
2. Test with [remote client quickstart guide](docs/getting-started/quick-starts/remote-client-quickstart.md)
3. Use WP-CLI command: `wp mcp-ai remote <url> --token=<token>`
4. Review [authentication documentation](docs/reference/api/mcp-server-authentication.md)

### Reporting Issues

When creating a GitHub issue, please include:

- **Plugin version** (found in WordPress admin)
- **WordPress version** and PHP version
- **Error messages** from logs (enable logging in settings)
- **Steps to reproduce** the issue
- **Expected behavior** vs actual behavior
- **Screenshots** if applicable

Create issues at: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues

### Contributing

We welcome contributions! Please see:

- [CONTRIBUTING.md](CONTRIBUTING.md) - Contribution guidelines
- **[MASTER_CONSOLIDATION_2025.md](docs/history/2025/summaries/MASTER_CONSOLIDATION_2025.md) ⭐ START HERE** - Complete consolidation of ALL fixes, summaries, and code reviews (98/100 score)
- [CONSOLIDATION_MAP.md](docs/history/2025/summaries/CONSOLIDATION_MAP.md) - Detailed map showing what was consolidated from where
- [CODE-REVIEW-MASTER.md](docs/developer/best-practices/CODE-REVIEW-MASTER.md) - Code quality standards with historical reviews
- [ACTION_ITEMS.md](docs/history/2025/summaries/ACTION_ITEMS.md) - Current development priorities

### Documentation

Comprehensive documentation is available:

- **[MASTER_CONSOLIDATION_2025.md](docs/history/2025/summaries/MASTER_CONSOLIDATION_2025.md) ⭐ PRIMARY REFERENCE** - Single source of truth for all 2025 work
- **[CONSOLIDATION_MAP.md](docs/history/2025/summaries/CONSOLIDATION_MAP.md)** - Navigation guide and source document mapping
- **[DOCUMENTATION_INDEX.md](docs/DOCUMENTATION_INDEX.md)** - Complete documentation index (535+ files)
- **[CODE-REVIEW-MASTER.md](docs/developer/best-practices/CODE-REVIEW-MASTER.md)** - Master code review (98/100)
- **[TESTING_AND_QUALITY_REPORT.md](docs/developer/testing-docs/TESTING_AND_QUALITY_REPORT.md)** - Testing & quality analysis

**For Historical Reference:**
- [CONSOLIDATED_BUGS_AND_FIXES.md](docs/history/2025/summaries/CONSOLIDATED_BUGS_AND_FIXES.md) - All bugs and fixes (superseded by MASTER_CONSOLIDATION_2025.md)
- [CONSOLIDATED_SESSION_SUMMARIES.md](docs/history/2025/summaries/CONSOLIDATED_SESSION_SUMMARIES.md) - Development history (superseded by MASTER_CONSOLIDATION_2025.md)

### Security Vulnerabilities

For security issues, please review our [Security Policy](SECURITY.md) and report vulnerabilities responsibly.

**Do not** create public GitHub issues for security vulnerabilities.

### Community & Updates

- **GitHub Repository:** https://github.com/nvdigitalsolutions/mcp-ai-wpoos
- **Maintained by:** [NV Digital Solutions](https://nvdigitalsolutions.com/)
- **License:** GPLv3 or later

---

## 📄 License

NV oOS ships under a **three-tier license model**:

| Component | License |
|-----------|---------|
| **Base plugin** (root + `includes/`) | [GPL-3.0-or-later](LICENSE) |
| `addons/algorave/` | **AGPL-3.0-or-later** (bundles `@strudel/web` AGPL-3.0) |
| `addons/pro/`, `addons/graphify/`, `addons/embedded/`, `addons/cornerstone3d/`, `addons/canvas/`, `addons/cloud-worker/`, `addons/fantasy-football/` | **Proprietary** — © NV Digital Solutions, all rights reserved |

The base plugin's GPL-3 grant is in [LICENSE](LICENSE). Bundled third-party
dependencies retain their upstream licenses; see [`CREDITS.md`](CREDITS.md)
for the full attribution index.

---

**Thank you for using Open Operator System!**

For the latest updates, documentation, and support, visit the [GitHub repository](https://github.com/nvdigitalsolutions/mcp-ai-wpoos).
