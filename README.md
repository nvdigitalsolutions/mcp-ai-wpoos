# NV Digital Open Operator System (NV oOS)

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

**Version:** 1.1.29  
**Release Date:** 2026-06-11

**Latest Updates:** June 11, 2026 (v1.1.29) — See [§ Latest Updates (v1.1.29 — June 2026)](#-latest-updates-v1129--june-2026) (Bug-fix & stabilisation sweep: Chat Bubble assistant dropdown, context-window pre-flight validation across all 13 providers, OpenAI SSE streaming fix, stale provider validation lists, playbook orphan cleanup, CRM activity fixes, chat debug console fixes, OOS bridge & embedding fixes, shell-quote CVE-2026-9277 patch, chat transcript tests from 4% to 87% pass rate, and more).

**Previous Updates (v1.1.27):** June 5, 2026 (v1.1.27) — See [§ Latest Updates (v1.1.27 — June 2026)](#-latest-updates-v1127--june-2026) (Real-time SSE streaming for OpenAI, DeepSeek, and all OpenAI-compatible providers. 35 new OOS core tools migrated. JetFormBuilder submission tools: 8 fixes for empty results, capability ordering, and form discovery. Extended Cognition vision recognition. Graphify tools capability compliance. DeepSeek agentic tool result handling. Documentation link fixes. June 2026 model pricing update. Plugin restructuring proposals v3.0).

**Previous Updates (v1.1.26):** June 3, 2026 (v1.1.26) — See [§ Latest Updates (v1.1.26 — June 2026)](#-latest-updates-v1126--june-2026) (Cross-Platform Extraction Engine Phases 0–2, Site-Builder Node-Graph Pipeline, SPA a11y Hardening, Screenshot & Docs Overhaul).

**Previous Updates (v1.1.25):** May 31, 2026 (v1.1.25) — See [§ Latest Updates (v1.1.25 — May 2026)](#-latest-updates-v1125--may-2026) (Unified Blueprint System, Cloudways Toolkit, CRM Toolkit Phases A–E, Chat UI enhancements, Unix-theory tool reorg Phase 4–5, Pro Toolkit MCP Server settings, Build infrastructure hardening).

**MCP Specification:** 2024-11-05 (Full Compliance)  
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
> - [Addon Inventory](docs/project/ADDON_INVENTORY.md) — what each of 18 addons does and its status
> - [Security Posture](docs/operations/security/SECURITY_POSTURE.md) — current state of all 50 audit findings
> - [Compliance Traceability](docs/operations/compliance/TRACEABILITY.md) — every .org rejection reason → commit → verification command
> - [AI-Assisted Development](docs/developer/AI_ASSISTED_DEVELOPMENT.md) — methodology, transparency, and what to scrutinize
> - [Architecture Overview](docs/developer/architecture/ARCHITECTURE.md) — component diagram and data flow

## 📑 Table of Contents

### Getting Started
- [🆕 Latest Updates (v1.1.29 — June 2026)](#-latest-updates-v1129--june-2026)
- [🆕 Latest Updates (v1.1.28 — June 2026)](#-latest-updates-v1128--june-2026)
- [🆕 Latest Updates (v1.1.27 — June 2026)](#-latest-updates-v1127--june-2026)
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

## 🧩 Overview

Real-time AI Orchestration Toolkit for Wordpress - **NV oOS** is a modular AI framework (Object-Oriented System) for WordPress that connects your site's data with OpenAI, Gemini, Anthropic, DeepSeek, OpenRouter, Baseten, Kimi, DigitalOcean, NVIDIA NIM, Cloudflare Worker AI, Ollama, LM Studio, and Hugging Face.  It allows you to create and manage AI Assistants that can interact with users, access WordPress data, and perform custom tool functions.

### ✨ What's New at a Glance (v1.1.29)

- 🪲 **Bug-Fix & Stabilisation Sweep.** 15+ fixes across the stack: chat bubble assistant dropdown UX, context-window pre-flight validation for all 13 providers, OpenAI real-time SSE streaming, stale provider validation lists, playbook orphan cleanup, CRM activity titles/due dates, chat debug console display, OOS bridge & embedding fatal errors, memory cookie-check nonce, and more.
- 🔧 **Chat Bubble Assistant Dropdown.** Fixed settings UX: `chat_bubble_assistant_id` field on the Chat Bubble settings page changed from a plain number input to a proper select dropdown populated with all published assistants — matching the Default Assistant UX in General → Core Settings.
- 🧠 **Context-Window Pre-Flight Validation.** Added pre-flight context-window validation across all 13 AI providers (OpenAI, Anthropic, Gemini, DeepSeek, OpenRouter, Baseten, Kimi, DigitalOcean, NVIDIA NIM, Cloudflare, Hugging Face, LM Studio, Ollama). Shared `validate_context_window()` helper with tiktoken integration, token-budget tool capping, and estimator metabox.
- ⚡ **OpenAI SSE Streaming Fix.** Fixed `stream_options` payload flag that prevented OpenAI real-time SSE streaming from triggering — streaming now works correctly for OpenAI and all compatible providers.
- 📋 **Playbook Orphan Cleanup & Batching.** Fixed orphan playbook accumulation, unsafe deletion without JetEngine validation, sync timeouts for large playbook sets, and batch processing for deletion operations.
- 🏷️ **Stale Provider Validation Lists.** Fixed provider validation lists rejecting DeepSeek and newer providers — validation now uses dynamic provider discovery instead of hardcoded lists.
- 🛡️ **Security Patch.** Bumped `shell-quote` to >=1.8.4 via npm overrides to fix CVE-2026-9277.
- 🧪 **Chat Transcript Tests.** Fixed chat transcript REST controller tests — pass rate improved from ~4% to 87%.
- 🔧 **Chat Debug Console.** Fixed `chatDebugMode` string coercion (PHP `'1'` vs JS `true`) and legacy debug console not displaying when enabled.
- 📡 **OOS Bridge & Embedding Fixes.** Fixed OOS bridge initialization, embedding service fatal error on missing API key, and SSE header warnings.
- 🗂️ **Missing Asset Files.** Added missing `.min.js` assets for voice and embedded LLM scripts.

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

### ✨ What's New at a Glance (v1.1.23)

- 🖥️ **Pro React SPA — Zed-Inspired Admin Interface.** Threads Sidebar with scope-grouped agent conversations. Agent Panel with SSE streaming chat and tool call cards. Cmd+K Command Palette with fuzzy search across 830+ tools. Hash-based routing with 7 pages (Chat, Settings, Tools, Assistants, Workflows, Analytics).
- 🔒 **Agent Profiles — Write/Ask/Minimal/Custom.** Built-in tool permission profiles with per-tool allow/deny/confirm patterns and glob-style matching. Filter tools sent to LLM by profile.
- 💬 **@-mention Context System.** Type `@` to mention posts, tools, skills, threads, files, users, terms, and settings. Debounced autocomplete with keyboard navigation.
- 📸 **Checkpoints & Diff Review.** Automatic state snapshots on every agentic turn. One-click restore to any checkpoint. Accept/reject individual change hunks with before/after diff visualization.
- ✏️ **Inline Assistant — Gutenberg AI Text Transform.** Select text in block editor → describe transformation → model rewrites in place or inserts after. Supports GPT-4o, Claude, Gemini.
- 🔄 **Multi-Model Comparison.** Send same prompt to multiple AI models simultaneously and compare responses side-by-side with timing badges and "Use This Response" selection.
- 👥 **Collaborative Presence.** Real-time user presence tracking via WordPress Heartbeat API. Avatar stack showing other editors with activity descriptions.
- 🏗️ **Base Thread Management Infrastructure.** 6 PHP manager classes + 5 REST controllers + 4 DB tables (PHP 7.4 compatible). Shared by jQuery chat UI and Pro React SPA.
- 🤖 **Antigravity Interactions API Rewrite.** Gemini Managed Agent service rewritten for the real Antigravity Interactions API with code execution, Google Search grounding, and sandbox environments.
- 📦 **TypeScript Upgrade + Orchestration Toggles.** Shared TS types, services, and React SPA builds. "Use TypeScript-Compiled Assets" toggle + Antigravity managed agents toggle in Orchestration settings.
- 📚 **Comic Reader Addon.** New React-based CBR/CBZ/CB7/CBT comic reader with dual-page modes, zoom, keyboard nav, touch, fullscreen, and drag-drop upload.
- 🎨 **Media Studio v0.3.0.** Zoom/pan, drawing tools (brush, eraser, shapes, text), and save-to-WP-Media-Library. Image editor now feature-complete.
- 🔧 **30+ Reliability Fixes.** Cron status diagnostics (HTTP status + body in errors), PHP 8.2+ compatibility ($namespace/$rest_base), PHPUnit 11 (6 batches), WP.org compliance re-audit, PHPCS 0 errors (353→0), qs CVE-2026-8723, docs-hub fixes, DeepSeek fallback, test suite stability.

### ✨ What's New at a Glance (v1.1.22)

- ✅ **Baseten AI — 11th first-class provider.** Full OpenAI-compatible integration with chat, tools, streaming, and reasoning passthrough at `api.baseten.co/v1`.
- ✅ **CoSAI Secure-by-Design Agentic System.** Four new `includes/agents/` classes: capability boundary with per-session tool allow-lists + rate limiting, cryptographic SHA-256 audit trails (CPT + options dual storage), risk-tiered approval gate (low/medium/high/critical), and isolated code sandbox (Python/Node.js/Bash/PHP). All provider-agnostic.
- ✅ **Gemini I/O 2026 Model Refresh.** Gemini 3.5 Flash as recommended model (4x faster, dynamic thinking, $1.50/M input). Gemini Omni Flash as new video default (10s, native audio, AI avatars). 3.1 Flash deprecated.
- ✅ **Continual Harness P5 — Self-Improving Agent System.** Agents learn from execution history and improve tool selection through feedback loops. CoSAI audit trail integration.
- ✅ **SaaS Controller Phase 2 & 4 complete.** Stripe deployment editor + OpenRouter deployment editor from WP-Admin.
- ✅ **npm packages — nvoos-vad, nvoos-chat-bubble, nvoos-chat-memory-ui.** Voice Activity Detection, floating chat bubble widget, chat memory drawer component. All with TypeScript declarations.
- ✅ **WordPress Studio test environment.** Auto-detection of Studio's database, ABSPATH, and site URL in `tests/bootstrap.php`.
- ✅ **Security fixes.** UUID buffer bounds check (saas-controller), `map_meta_cap=false` for audit trail CPT (WP 6.1+ compat), AV false positives in test suite.
- ✅ **Allowed providers list expanded.** DeepSeek, OpenRouter, DigitalOcean, Kimi, Baseten added to provider validation gate.
- ✅ **LM Studio URLs fixed.** All `lmstudio.ai` references → GitHub org after upstream HTTP 500 errors.
- ✅ **Addons PHPCS — 93% reduction (1,143 → 82 errors).** Two-batch cleanup across all addons with 12 new `bin/` helper scripts.
- ✅ **v1.1.21 items:** WP.org Compliance Complete (F1–F10 resolved). Canonical Return Envelope (191 `WP_Error` conversions). Semantic Caveman Compression. AI Prompt Caching (all providers). Capability Fence P2b full rollout (~830 tools). Security Center (5-tab). Memory Layer 2026 Phases 3–8. Kimi provider (10th). ACP Server. MCP Bridge. Unix Theory P0–P7. DigitalOcean Serverless Inference. Async Chat Continuation. Jobs/Tasks Drawer. Model Catalog May 2026 refresh. GDPR JetEngine Privacy Exporters. `@wordpress/env` dev dependency. Domain migration (nvoos.com → nvoos.pro/cloud). Addons/pro security scan fixes.

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

The plugin works standalone with **~195 base tools** and optionally extends through the **Pro addon**, which adds **~795 Pro tools** for advanced integrations (WooCommerce, JetEngine, social media APIs, GitHub, Google services, Shopify, QuickBooks Desktop, Yahoo Fantasy Sports, ESPN Fantasy, ECA management, CRE Debt & Securitization, Cloudways server management, CRM lead/deal/customer lifecycle, support ticket management, multichannel inbound/outbound messaging) and exec-based tools (FFmpeg, WP-CLI, Python rembg, Jukebox), bringing the total to **~990 built-in tools** (~195 base + ~795 Pro; live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative).

> **Note on Tool Count:** Tools include base WordPress operations, content management, media generation, research capabilities, and optional third-party integrations. The base version (~195 tools) works standalone. The full version requires the Pro addon and provides ~990 total tools including specialized toolkits for e-commerce, social media, analytics, document generation, vehicle estimation, image validation, JetEngine MCP, A2A agent delegation, CRE Debt & Securitization, Cloudways infrastructure management, CRM lead/deal/customer lifecycle + support tickets + multichannel, MCP Apps, and more. Live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative.

**Addon Ecosystem:** NV oOS ships a growing family of installable addons: **Chat SPA** (`addons/chat-spa/` — React chat replacement), **Docs Hub** (`addons/docs-hub/` — in-site documentation SPA), **SaaS Controller** + **Cloud Worker** (`addons/saas-controller/` + `addons/cloud-worker/` — NV oOS Cloud control plane), **Toolkit Shell / Canvas Toolkit / Document Editor / Media Studio** (`addons/toolkit-shell/` etc. — Toolkit SPA Blueprint Tier A–D), **Graphify** (`addons/graphify/` — knowledge graph), **Comic Reader** (`addons/comic-reader/` — CBR/CBZ/CB7/CBT reader), **Funiq Bridge** (`addons/funiq-bridge/` — Payload-to-WordPress bridge with React SPA), **NV Platform** (`addons/ai-platform/` — AI platform admin dashboard + CPTs), **Algorave**, **Cornerstone3D**, **Embedded**, **Fantasy Football**. Separate standalone plugins: **NVOOS Graphify** (`plugins/nvoos-graphify/` — visual knowledge graph), **NVOOS Graphify AI** (`plugins/nvoos-graphify-ai/` — AI providers + chat + RAG), **NVOOS Graphify AI Platform** (`plugins/nvoos-graphify-ai-platform/` — agents, A2A, blueprints, skills). See [`docs/developer/addons/toolkit-spa-blueprint.md`](docs/developer/addons/toolkit-spa-blueprint.md) for the blueprint all SPA addons follow.

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

- **10 language-model providers** — OpenAI, Gemini, Anthropic, NVIDIA NIM, Hugging Face, Cloudflare, Ollama, LM Studio, Kimi (Moonshot AI), Embedded
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

## 🆕 Latest Updates (v1.1.29 — June 2026)

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
- 📦 **Versioning** — bumped to **1.1.29** across `mcp-ai-wpoos.php`, `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `package.json`, `readme.txt` Stable tag, `README.md`, and `docs/DOCUMENTATION_INDEX.md`. Provider count: **13** first-class language-model providers (OpenAI, Gemini, Anthropic, DeepSeek, OpenRouter, Baseten, Kimi, DigitalOcean, NVIDIA NIM, Cloudflare, Hugging Face, LM Studio, Ollama). Tool count: ~195 base + ~795 Pro (~990 total; live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative).

---

## 🆕 Latest Updates (v1.1.28 — June 2026)

### June 5–9, 2026 — CRM Phase C Complete, Support Tickets, Customer 360, Attention Routing, Funiq Bridge, and NVOOS Graphify Ecosystem 🏢🎫👥🧠🔌🕸️

- ✅ **CRM Phase C Complete — Inbound Multichannel Ingestion (PRs #5292, #5297).** **Gmail Bridge:** `import_gmail_to_crm` tool with OAuth 2.0 authentication via `WP_MCP_AI_CRM_Gmail_Client` (`addons/pro/includes/services/class-wp-mcp-ai-crm-gmail-client.php`). Imports email threads, extracts contacts, classifies intent, and creates/updates lead records. **IMAP Client:** `WP_MCP_AI_CRM_IMAP_Client` — cron-driven email polling via PHP IMAP extension, connects to any IMAP server, triages through CRM Classifier. **SMS Webhook:** `WP_MCP_AI_CRM_SMS_Webhook_Listener` — Twilio inbound SMS receiver at `/wp-json/mcp-ai-pro/v1/crm/sms-webhook` with X-Twilio-Signature validation. **WhatsApp Webhook:** `WP_MCP_AI_CRM_WhatsApp_Webhook_Listener` — Meta WhatsApp Cloud API receiver with X-Hub-Signature-256 validation and 24-hour session window handling. All inbound messages triaged and routed to the Workflow Command Center.
- ✅ **Customer CPT + Customer 360 Dashboard (PRs #5298, #5299).** `mcp_ai_customer` custom post type (`addons/pro/includes/class-wp-mcp-ai-customer-cpt.php`) with 5 CRUD AI tools (`addons/pro/includes/tools/crm/customers/`): `create_customer`, `get_customer`, `update_customer`, `delete_customer`, `list_customers`. Customer Research & Add page with Customer 360 dashboard (`addons/pro/includes/admin/class-wp-mcp-ai-customer-research-page.php`). Customer admin settings page (`addons/pro/includes/admin/class-wp-mcp-ai-customer-settings-page.php`). `convert_lead_to_customer` workflow updated with Customer CPT support, deal promotion to `closed_won`, and blueprint preset integration. CRM blueprints updated across all 8 industry verticals (Agency Account Manager, B2B SaaS SDR, Bespoke Concierge, Business Advisory, Career Coach, Luxeseek, Real Estate, Wholesale Distributor).
- ✅ **Support Ticket Module — 10 AI Tools + SLA + Zendesk Sync (PR #5297).** `mcp_ai_support_ticket` custom post type (`addons/pro/includes/class-wp-mcp-ai-support-ticket-cpt.php`) with 10 AI tools (`addons/pro/includes/tools/crm/support/`): `create_support_ticket`, `get_support_ticket`, `update_support_ticket`, `list_support_tickets`, `classify_support_ticket` (AI-powered intent classification with confidence scoring), `escalate_support_ticket` (priority bump + SLA recalculation + assignee notification), `resolve_support_ticket`, `reopen_support_ticket`, `merge_support_tickets` (deduplication with child→parent linking), `get_ticket_sla_report` (SLA compliance with per-priority thresholds: P1=4h, P2=8h, P3=24h, P4=72h). `WP_MCP_AI_CRM_Ticket_Automation` — auto-classification on creation + SLA breach cron detection (`wp_mcp_ai_crm_ticket_check_sla`). `WP_MCP_AI_CRM_Ticket_Notifications` — email notifications on status changes, assignment, and escalation. Support Ticket admin settings page (`addons/pro/includes/admin/class-wp-mcp-ai-support-ticket-settings-page.php`). Optional Zendesk sync via `wp_mcp_ai_crm_toolkit_settings['integrations']['zendesk_enabled']`.
- ✅ **TF-IDF + BM25 Relevance Search (PR #5277).** Dual-algorithm relevance ranking added to CRM email search tools (`crm_email_search_leads`, `crm_email_search_accounting`, `crm_email_search_correspondence`), healthcare search tools, and base content search tools. `WP_MCP_AI_CRM_Relevance_Search` trait in `addons/pro/includes/traits/trait-wp-mcp-ai-relevance-search.php` (Pro). `WP_MCP_AI_Relevance_Search` trait in `includes/traits/trait-wp-mcp-ai-relevance-search.php` (Base). BM25 relevance ranking alongside TF-IDF for improved term saturation handling. Configurable `orderby` parameter across all search tools. Gmail OAuth client extracted from CRM leads tool into dedicated service (`addons/pro/includes/services/class-wp-mcp-ai-crm-gmail-client.php`).
- ✅ **Transformer-Inspired Attention Routing + RRF Fusion (PR #5290).** QKV multi-head attention router (`includes/data/class-wp-mcp-ai-tool-attention-router.php`) with 5 attention heads: semantic (cosine similarity), capability (user_can check), recency (audit trail rates), dependency (plugin/API available), risk (approval gate tier). Fused via weighted sum into final attention score. Sliding-window conversation compressor (`includes/data/class-wp-mcp-ai-conversation-compressor.php`) — summarizes older messages as Decisions/Facts/Questions while keeping last N messages at full fidelity. Persistent tool embedding store (`includes/data/class-wp-mcp-ai-tool-embedding-store.php`) — Float32-packed vectors in `wp_mcp_ai_tool_embeddings` table, pre-computed asynchronously via WP-Cron. RRF (Reciprocal Rank Fusion) integration with harness scoring pipeline at k=60. Graceful degradation when dependencies are unavailable (no vector service → returns all tools; no embedding API key → semantic head scores neutral). Data layer init wire-up (`includes/data/data-init.php`).
- ✅ **Funiq Bridge Addon — New (PR #5294).** Complete Payload-to-WordPress bridge addon (`addons/funiq-bridge/`). React admin SPA with REST controllers: BannerController, BrandsController, CarouselController, CategoriesController, ColorsController, ProductsController, PromocodesController, PromotionsController, StatusesController. Transformers: BrandTransformer, CategoryTransformer, ColorTransformer, ProductTransformer, PromocodeTransformer, PromotionTransformer, StatusTransformer, TermTransformer. Post types: Product, Promocode, Promotion. Taxonomies: Brand, Category, Color, Status. Schema definition, admin page, plugin bootstrap, uninstall handler. PHPUnit tests for REST controllers and transformers.
- ✅ **NVOOS Graphify Standalone Plugin (PRs #5266, #5268, #5271, #5272, #5273, #5274, #5275).** Complete standalone WordPress plugin (`plugins/nvoos-graphify/`) independent of NV oOS. Visual knowledge graph with Cytoscape.js rendering, 14 graph tools (BuildGraph, ContentGaps, GetCommunity, GetNeighbors, GetNode, GodNodes, GraphStats, ListRemoteSources, QueryGraph, ResolveExternal, RetrieveContext, ShortestPath, SuggestLinks, SyncRemoteSource), 6 admin sections, remote source infrastructure with Wikidata driver, custom DB tables (`nvoos_graphify_nodes`, `_edges`, `_meta`, `_remote_sources`, `_embeddings`), REST API controller, shortcode + block + Schema.org frontend, memory bridge with embeddings-on-ingest. Build workflow with vendor bundling. PHPUnit test suite (unit + integration). Folder READMEs at every level.
- ✅ **NVOOS Graphify AI Addon (PRs #5278, #5279, #5280).** AI addon plugin (`plugins/nvoos-graphify-ai/`) — providers (OpenAI, Gemini, Anthropic, DeepSeek, OpenRouter, Baseten, Kimi, DigitalOcean, NVIDIA NIM, Cloudflare, Hugging Face, LM Studio, Ollama), streaming chat with SSE controller, RAG retriever, embedding service, agent memory with auto-summarization and context injection, 14 AI tools (AnalyzeImage, AnalyzeSentiment, CategorizeContent, ContentFreshness, ContentRecommendation, CreateTextEmbeddings, ExtractEntities, GenerateExcerpt, GenerateImageAltText, QuestionAnswering, SemanticSearch, SummarizeText, TranslateText). AI admin page with 4 sections (API Keys, Provider Selection, Chat Settings, Chat Interface). CoreBridge for WordPress integration. `lib/core` domain contracts and `lib/wordpress-adapter` packages with PSR-4 autoloading.
- ✅ **NVOOS Graphify AI Platform Addon (PR #5282).** Platform addon plugin (`plugins/nvoos-graphify-ai-platform/`) — Agents subsystem (Add/Edit/Build/Test agent pages, CPT bridge, meta keys), A2A service + admin, ACP service + admin, Blueprints service + admin, Federation service + admin, Harness service + admin, Measurement service + admin, Professions service + admin, Skills bridge + admin, Slash Commands bridge + admin. Admin dashboard with overview and general sections. CPTs: Project, Resource, Template. Integration tests (Priority 1.6).
- ✅ **NV Platform AI Addon (PR #5288).** Top-level admin dashboard + CPTs (Project, Resource, Template) in `addons/ai-platform/`.
- ✅ **Automated Demo Video Pipeline Phases 1–3 (PR #5289).** Scripted scene recording, AI voiceover generation, and automated video assembly pipeline.
- ✅ **CRM Lead/Deal & Activity Enhancements (PRs #5293, #5295, #5296).** Enriched lead/company tables in Command Center with data completeness KPI. Dedicated Leads tab added to CRM navigation. Lead CPT admin expanded with contact details and remote channel link. Lead edit screen and CRM activity dashboard enhanced. Support correspondence lifecycle proposal added.
- ✅ **Graphify Related Content Leak Fix (PR #5291).** Fixed graphify related content leaking to wrong page sections — content isolation tightened.
- ✅ **Documentation & Unix Theory Updates.** Folder READMEs created for new CRM subdirectories (`addons/pro/includes/tools/crm/customers/`, `inbound/`, `support/`). Existing folder README errors resolved: `includes/data/README.md` (added Inputs/Outputs/Neighbors + Conventions), `addons/pro/includes/traits/README.md` (added Inputs/Outputs/Neighbors + Also Load). CRM Toolkit Enhancement Plan (`addons/pro/docs/CRM_TOOLKIT_ENHANCEMENT_PLAN.md`) updated to reflect completed Phases A–E with the new Support Ticket and Customer CPT modules. Folder README compliance check score: 62/62 (100%).
- 📦 **Versioning** — bumped to **1.1.28** across `mcp-ai-wpoos.php`, `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `package.json`, `readme.txt` Stable tag, and `README.md`. Provider count: **13** first-class language-model providers (OpenAI, Gemini, Anthropic, DeepSeek, OpenRouter, Baseten, Kimi, DigitalOcean, NVIDIA NIM, Cloudflare, Hugging Face, LM Studio, Ollama). Tool count: ~195 base + ~795 Pro (~990 total; live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative).

---

## 🆕 Latest Updates (v1.1.27 — June 2026)

### June 4–5, 2026 — Real-Time SSE Streaming, 35 OOS Core Tools, JFB Fixes, and Model Pricing Update ⚡🧰🔧💰

- ✅ **Real-Time SSE Streaming (PRs #5240, #5243, #5244).** Real-time SSE streaming enabled for OpenAI, DeepSeek, and all OpenAI-compatible providers. New "Disable Native Streaming" toggle added to Advanced → System tab with per-site control. `wp_mcp_ai_disable_native_streaming` filter for programmatic override. WPCS violations fixed across all streaming provider clients.
- ✅ **OOS Core Tool Migration — 35 New Tools (PR #5246).** 35 tools migrated from the OOS core framework with full test coverage and documentation. **Data Tools:** GetPostTaxonomies, CountPosts, GetPostMeta, TruncateText, MergeArrays. **Format Tools:** FormatDate, TimeAgo, ParseCsv, MathEval, ColorConvert. **Infrastructure:** EventDispatcher (5 tools), Queue tools (5 tools, 5 tests). **Cache:** 5 cache-management tools + DeleteSettingTool (6 tests). OOS/core test infrastructure established with 20 migrated base tools.
- ✅ **Extended Cognition Vision Recognition (PR #5237).** Visual product/brand recognition added to Extended Cognition toolkit. Camera viewfinder UI with real-time detection overlays, consent gate for privacy compliance, camera switcher, torch control, scan region, and file upload support (`addons/pro/includes/ext-cog/`).
- ✅ **Graphify Tools — Capability Fence Compliance (PRs #5237, #5238).** Added missing `WP_MCP_AI_Tool_Default_Capability` trait to all Graphify tools. Added explicit `get_required_capability()` method to every Graphify tool class for full Capability Fence P2b compliance.
- ✅ **JetFormBuilder Submission Tools — 8 Fixes (PRs #5244, #5245, #5247, #5248, #5249, #5250, #5251, #5253).** Fixed `get_all_form_submissions` returning empty results — root cause was a field mismatch and capability gate ordering. JFB submissions now correctly return results for non-admin users via a direct DB fallback query. Form discovery pipeline improved with unified fallback for local form detection. JFB REST routes corrected to match actual JetFormBuilder plugin endpoints (`/wp-json/jet-form-builder/v1/`). Form-type auto-detection fixed to correctly distinguish JetFormBuilder vs Elementor submissions. JetFormBuilder plugin detection fixed to use the namespaced class `Jet_Form_Builder\Plugin` and added to the plugin status list. All PHPCS warnings resolved across JFB tool files (trailing commas, unused variables, Yoda conditions). JetFormBuilder integration reference documentation added at `docs/features/integrations/jetformbuilder-integration-guide.md`.
- ✅ **DeepSeek Agentic Tool Result Handling (PR #5247).** Added tool message filtering and payload normalisation to the DeepSeek AI client to support agentic multi-turn tool-calling workflows. Prevents malformed tool result payloads from breaking subsequent turns.
- ✅ **Documentation Link Fixes (PR #5239).** Fixed broken internal documentation links across the codebase that were introduced during the Unix-theory `docs/` directory reorganization.
- 💰 **Model Pricing — June 2026 Update (PR #5256).** Updated model pricing across all 13 AI providers (OpenAI, Gemini, Anthropic, DeepSeek, OpenRouter, Baseten, Kimi, DigitalOcean, NVIDIA NIM, Cloudflare, Hugging Face, LM Studio, Ollama) to reflect June 2026 rates.
- 📋 **Plugin Restructuring Proposals v3.0 (PRs #5252, #5255).** Added graphify-core specification and base plugin restructuring roadmap. Restructuring proposals updated to v3.0 Graphify-centric architecture.
- 📦 **Versioning** — bumped to **1.1.27** across `mcp-ai-wpoos.php`, `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `package.json`, `readme.txt` Stable tag, and `README.md`. Provider count: **13** first-class language-model providers (OpenAI, Gemini, Anthropic, DeepSeek, OpenRouter, Baseten, Kimi, DigitalOcean, NVIDIA NIM, Cloudflare, Hugging Face, LM Studio, Ollama). Tool count: ~195 base + ~765 Pro (~960 total; live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative).

---

## 🆕 Latest Updates (v1.1.26 — June 2026)

### June 1–3, 2026 — Cross-Platform Extraction Engine, Site-Builder Node-Graph Pipeline, SPA a11y Hardening, Screenshot & Docs Overhaul 🔌🏗️♿📸📁

- ✅ **Cross-Platform Extraction Engine — Phases 0–2 (`lib/`).** Framework-agnostic OOS core extracted from WordPress into a standalone PHP library. **Phase 0** — Monorepo foundations: `composer.json` with PSR-4 `OOS\` namespace, library structure (`src/Domain/`, `src/App/`, `src/Infra/`, `src/Skills/`), and `lib/` excluded from all plugin build artifacts (`.distignore`, `.gitattributes`, build scripts). **Phase 1** — WordPress adapters for all 8 domain interfaces: `WpAiServiceRepository`, `WpConfigRepository`, `WpEventDispatcher`, `WpHttpClient`, `WpLogger`, `WpSanitizer`, `WpToolRepository`, `WpUserRepository`. **Phase 2** — Core application layer: `AiService` orchestrator, `ConfigService`, `ToolExecutionPipeline`, `ChatSessionManager`, `SkillRegistry`, `AbstractTool` base class. All 12 AI provider clients migrated with domain injection (OpenAI, Gemini, Anthropic, DeepSeek, OpenRouter, Baseten, Kimi, DigitalOcean, NVIDIA NIM, Cloudflare, Hugging Face, Ollama/LM Studio). 33 tools migrated: 8 Tier 1 (pure external API, zero WP deps), 12 Tier 1+ (client-side, research, crawling), 11 HuggingFace dataset tools + shared base. OOS bridge wired into WordPress with feature flag (`WP_MCP_AI_OOS_ENGINE_ENABLED`). Full architecture proposal: [`docs/project/proposals/cross-platform-extraction-architecture.md`](docs/project/proposals/cross-platform-extraction-architecture.md).
- ✅ **Site-Builder Subsystem: Node-Graph Pipeline Phases 1–4.** Visual site construction subsystem with node-graph architecture for building complete WordPress sites programmatically through AI tool chains (`addons/pro/includes/site-builder/`). Nodes represent site components (pages, posts, CPTs, menus, widgets, themes, plugins), edges represent dependencies and data flow. Pipeline supports parallel execution, rollback on failure, and incremental builds. SPA blueprint v3.0 generated from the pipeline output.
- ✅ **SPA a11y Hardening Phase 5.** axe-core accessibility testing integrated across all SPA addons (`addons/chat-spa/`, `addons/comic-reader/`, `addons/docs-hub/`, `addons/canvas-toolkit/`, `addons/document-editor/`, `addons/media-studio/`, `addons/toolkit-shell/`). Keyboard navigation, ARIA labels, focus management, and screen-reader support reviewed and hardened. ComixReader `tabIndex` a11y lint resolved. CI type errors fixed (added `@types/node`, fixed comic-reader TS casts, pinned `@types/node` to `^22.15.17`). `declare const process` added to eliminate `@types/node` dependency for axe-core block.
- ✅ **Screenshot Overhaul (`docs/screenshots/`).** 137 automated Playwright screenshots captured across base + Pro admin and dashboard pages. Screenshot inventory (`INVENTORY.md` — 79 tracked pages), maintenance plan (`SCREENSHOT_MAINTENANCE.md`), and coverage checker. Organized into `admin/`, `dashboard/` (Pro CRM, toolkit, analytics), `chat/`, `frontend/`, `integrations/`, and `tools/` subdirectories. Pro dashboard, CRM, and toolkit screenshots captured with real data. Invalid access-denied screenshots removed.
- ✅ **Docs Reorganization — Unix-Theory Separation of Concerns.** `docs/` directory tree reorganized with clear separation: `admin-guides/`, `developer/`, `features/`, `getting-started/`, `history/`, `operations/`, `project/`, `reference/`, `user-guides/`, `visual-guides/`, `screenshots/`. Per-folder READMEs restored and added to all active doc directories. Lost files restored.
- ✅ **Form Submissions Data Source.** JetFormBuilder (JFB) + Elementor forms integration for AI-powered submission analysis (`addons/pro/includes/form-submissions/`). Admin dashboard with submission listing, filtering, and export. PHPUnit tests + lint fixes. PHPStan errors in `lib/core` fixed; `nyholm/psr7` dependency added.
- ✅ **Cloudways Dashboard SPA Addon v0.1.0.** New React SPA addon (`addons/cloudways-dashboard/`) for Cloudways server and application management with real-time status dashboard. WPCS lint errors resolved.
- ✅ **Laravel & Craft CMS Adapters.** OOS core extraction adapter packages for Laravel and Craft CMS, enabling the framework to run as a standalone service outside WordPress. Docker dev environments for both frameworks fixed and operational.
- ✅ **Blueprint Profession Roles.** 6 missing profession definitions added. Professional roles assigned to CRM and healthcare-style blueprint assistants for proper capability mapping and tool pre-selection.
- ✅ **Pro Toolkits Security Audit Phase 1.** 9 HIGH-severity security findings resolved across pro toolkits (`addons/pro/includes/tools/`). Fixes applied to input validation, authorization checks, and output escaping.
- ✅ **OOS Engine Stability Fixes.** PSR-4 event classes extracted from `DomainEvents.php` into individual files. Missing `ErrorFactoryInterface` import added to 8 ported tools. Provider client constructor interface imports fixed. `psr/event-dispatcher` bundled to fix activation fatal. Parse errors and `CacheStore` bool cast `TypeError` in OOS core lib resolved. OOS engine chat client errors when core framework is activated fixed. OOS Gemini chat client tools string format error fixed. OOS engine team/profession layer integrated into chat handler.
- ✅ **WP.org Compliance Audit v1.1.25.** Full re-audit against WordPress.org plugin guidelines completed. All prior compliance maintained.
- ✅ **Reviewer Onboarding Documentation Suite.** Complete reviewer documentation: [`docs/project/FOR_REVIEWERS.md`](docs/project/FOR_REVIEWERS.md) (project overview, quick answers, repository map, known issues, scoping advice). Audit data cross-referenced against May 2026 compliance docs. Stale Pro tree PHPCS data updated.
- ✅ **Docker Dev Environments Fix.** All Docker environments fixed and operational: WordPress (`docker-compose.yml`), Laravel (`docker/laravel/`), Craft CMS (`docker/craft/`). Docker directory excluded from PHPCS linting and plugin builds.
- ✅ **Test Infrastructure & PHPUnit Compat.** 95% of PHPUnit failures resolved across base, pro, and addon test suites. `class_exists` guards added for Pro tool require paths. Removed nonexistent base path for WP-CLI tool test. Fixed require path for moved video frame extractor tool.
- ✅ **Infrastructure Fixes.** TCPDF addon Composer autoloader class-name collision with main plugin resolved. Pro vendor files committed with production `classmap-authoritative` autoloader. `@puppeteer/browsers` detection path fixed on Pro Settings page. Pro vendor Composer install added to PHPUnit and Release CI workflows. NPM package status detection fixed on Pro Settings page. Pro addon vendor `.gitignore` fixed to unignore `symfony/yaml`. Shallow clone recommendation added (~500 MB vs ~10 GB).
- 📦 **Versioning** — bumped to **1.1.26** across `mcp-ai-wpoos.php`, `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `package.json`, `readme.txt` Stable tag, and `README.md`. Provider count: **13** first-class language-model providers (OpenAI, Gemini, Anthropic, DeepSeek, OpenRouter, Baseten, Kimi, DigitalOcean, NVIDIA NIM, Cloudflare, Hugging Face, LM Studio, Ollama). Tool count: ~195 base + ~765 Pro (~960 total; live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative).

---

## 🆕 Latest Updates (v1.1.25 — May 2026)

### May 29–31, 2026 — Unified Blueprint System, Cloudways Toolkit, CRM Toolkit (Phases A–E), Chat UI Enhancements, Unix-Theory Reorg Phase 4–5 🧩☁️🏢💬📂

- ✅ **Unified Blueprint System.** 55 pre-built AI assistant blueprints across 25 toolkits (`addons/pro/includes/blueprints/`). Shared `WP_MCP_AI_Blueprint_Installer` class with JSON Schema validation, slug-based deduplication, tool resolution, and capability mapping. Blueprint manifest format: JSON with slug, name, description, toolkit, system_prompt, model, temperature, tools array, capability_profile, icon, version. Post-import hook `wp_mcp_ai_blueprint_imported`. Programmatic import via `blueprint_import` tool (`manage_options`). Full doc: [`docs/features/unified-blueprint-system.md`](docs/features/unified-blueprint-system.md).
- ✅ **Cloudways Pro Toolkit.** 60 AI-powered tools (`addons/pro/includes/tools/cloudways/`) wrapping the Cloudways API v2. `WP_MCP_AI_Cloudways_Client` singleton — OAuth 2.0 token exchange, automatic refresh, token caching in `wp_mcp_ai_settings`, typed `WP_Error` mapping (401/429/404). Helper functions: `wp_mcp_ai_is_cloudways_toolkit_enabled()`, `wp_mcp_ai_cloudways_has_credentials()`, `wp_mcp_ai_cloudways_param_*()` shared schema fragments. Tool categories: Server Management (~15), Application Management (~15), Security (~10), Backups & Monitoring (~10), Team & Access (~5), DNS & Domains (~5). Admin dashboard: Server Overview, Application Grid, Quick Actions, Usage Charts, Alert Feed. Full doc: [`docs/features/cloudways-toolkit.md`](docs/features/cloudways-toolkit.md).
- ✅ **CRM Toolkit Phases A–E Complete.** 70+ tools (`addons/pro/includes/tools/crm/`). Phase A — Lead Management (~20 tools): capture, score, enrich, dedup, segment, import/export, timeline, assignment, status transitions. Phase B — Multi-channel Triage (~15 tools): email/SMS/chat routing, auto-responders, sentiment analysis, priority queue, channel health. Phase C — Integration Hooks (~10 tools): webhook receiver/dispatcher, external CRM sync (HubSpot, Salesforce), Zapier bridge, integration status. Phase D — Extensibility (~10 tools): custom field mapping, workflow triggers, plugin bridge, extension registration. Phase E — Compliance (~15 tools): GDPR export/delete, CCPA report, consent audit, retention check, data inventory. Admin interface: Command Center (dashboard widgets, quick actions, activity feed), per-CPT Research Pages (leads, deals, custom), Analytics Dashboard (pipeline waterfall, conversion funnel, sequence performance, team performance). REST API at `/mcp-ai-pro/v1/crm/`. Settings: `crm_leads_cpt`, `crm_deals_cpt`, `crm_default_pipeline`, `crm_email_provider`, `crm_sms_provider`, `crm_data_retention_days`. Full doc: [`docs/features/crm-toolkit.md`](docs/features/crm-toolkit.md).
- ✅ **Chat UI Enhancements — 7 Features (Base + Pro).** Profile card (collapsible, auto-hide on scroll, avatar/model/capability). Stop generation (aborts SSE, preserves partial response, `wp_mcp_ai_chat_stop_generation` event). Feedback widget (thumbs-up/down, post meta storage, Continual Harness integration). Code copy button (clipboard API + `execCommand` fallback). Dark mode toggle (`prefers-color-scheme` default, `localStorage` persistence, CSS custom properties). Saved prompts panel (star messages, categories, quick insert). Prompt search (debounced 150ms, `Ctrl+K` shortcut, Fuse.js in React SPA). All features available in both base jQuery (`assets/js/chat.js`) and Pro React SPA (`addons/chat-spa/`). Full doc: [`docs/features/chat-ui-enhancements.md`](docs/features/chat-ui-enhancements.md).
- ✅ **Unix-Theory Tool Reorganisation — Phase 4–5 Complete.** Pro tools (`addons/pro/includes/tools/`) reorganised into modular Unix-theory folders. Phase 4 (remaining tool categories) + Healthcare B–E + Phase 5 migration complete. Stale `require_once` paths fixed across tool registry and loader (PRs #5184, #5187, #5188). Hardcoded file paths in tool registry and tests resolved.
- ✅ **Pro Toolkit MCP Server Settings Pages — Phases A–C.** Per-toolkit MCP server configuration (`nvoos-pro-toolkit-mcp-servers` admin page): Servers tab (list + status), Detail tab (per-server config), Audit tab (connection logs), Discovery tab (endpoint management). Configurable at Settings → Toolkit MCP Servers.
- ✅ **Aerlinn + Healthcare Blueprints.** 4 Aerlinn assistant blueprints: Bespoke Concierge (luxury travel/lifestyle), Luxeseek (high-end shopping/procurement), Business Advisory (executive strategy/operations), Career Coach (professional development/transitions). Healthcare blueprint import tool with HIPAA-aware system prompt templates, medical record tool pre-selection, compliance audit trail initialization, and PHI handling configuration.
- ✅ **Build Infrastructure Hardening.** `workflow_dispatch` in `build-assets` workflow now commits built assets back to the calling branch (PR #5180). `build/.gitkeep` restored and build-assets workflow hardened (PR #5181). WSL auto-detection in build scripts for cross-platform compatibility (PR #5165). Alpha-working branch added to build-assets workflow triggers (PR #5179).
- 📦 **Versioning** — bumped to **1.1.25** across `mcp-ai-wpoos.php`, `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `package.json`, and `README.md`. Provider count: **11** first-class language-model providers. Tool count updated to reflect new Cloudways (+60) and CRM (+70) tools (~195 base + ~765 Pro; live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative).

---

## 🆕 Latest Updates (v1.1.24 — May 2026)

### May 27–28, 2026 — Bug-Fix & Stabilisation Sweep: Chat SPA Fixes, Unix Theory Refinements, CVE Patches, Paper Store Admin CRUD 🧹🛡️🔒📝

- ✅ **Chat SPA — Duplicate Messages & SSE Protocol Fix.** Fixed a bug where the React SPA could show duplicate message bubbles under rapid SSE streaming. Adapted the frontend to the OpenAI-compatible SSE format emitted by the server, resolving a protocol mismatch that caused silent message drops. Markdown rendering now enabled for assistant responses in the SPA chat surface via `marked` library (`addons/chat-spa/`). Rebuilt `nvoos-chat-spa.zip` artifact.
- ✅ **Skill Manager Canonical Return Envelope — Unix Theory P0/P1 Refinement.** Fixed the skill manager to return the canonical envelope (`WP_Error` on failure, success array on success) instead of the legacy `array('success' => false, ...)` pattern. This completes the skill-manager migration missed in the earlier P0/P1 sweep (v1.1.21). Added skills sync endpoint for idempotent import/export. Fixed YAML frontmatter parsing by quoting description fields containing colons.
- ✅ **Paper Store Pro Interface Load Order.** Deferred Pro Paper Store class loading to the `wp_mcp_ai_bootstrapped` hook to resolve a race condition where Pro interfaces were loaded before the base plugin had fully initialised its autoloader and constants.
- ✅ **Assistant Tool Presets — 24 Missing Tools.** Added 24 under-covered tools to assistant creation presets so new assistants get complete tool coverage by default. Fixed out-of-date tests asserting stale preset strings.
- ✅ **CVE Patches — Tmp & Symfony Cache.** Bumped `tmp` to >=0.2.6 (via npm overrides + composer) and `symfony/cache` to ^6.4.40 to resolve upstream CVEs. Fixed `composer.lock` source reference. Committed production vendor state. All release ZIPs rebuilt.
- ✅ **Paper Store Admin CRUD (`addons/pro/`).** Full Create/Read/Update/Delete admin UI for Paper Store collections and records, placed under the Assistants menu following the Skills admin convention.
- ✅ **CLI Coverage Enhancements.** Comprehensive WP-CLI command coverage improvements across the plugin toolchain (`includes/cli/`).
- ✅ **Folder README Convention — Unix Theory P7 Completion.** Every PHP-bearing subdirectory under `includes/` and `addons/pro/includes/` now ships a `README.md` — completing the Folder README Convention (Unix Theory Phase P7). CI enforcement via `composer run docs:check-folder-readmes`.
- ✅ **Agent Context Docs Synced.** `CLAUDE.md`, `AGENTS.md`, and agent files in `.github/agents/` synced with recent features (v2.4/v1.4).
- ✅ **Build & CI.** New `build-spa-addons` GitHub Actions workflow for automated SPA addon ZIP generation. Restored missing SPA addon ZIPs in `build/`. All SPA addon bundles and ZIPs rebuilt to current versions.
- 📦 **Versioning** — bumped to **1.1.24** across `mcp-ai-wpoos.php`, `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `package.json`, `readme.txt` Stable tag, and `CHANGELOG.md`. Provider count: **11** first-class language-model providers.

---

## 🆕 Latest Updates (v1.1.23 — May 2026)

### May 25–26, 2026 — Zed-Inspired SPA Architecture, Antigravity Interactions API Rewrite, TypeScript Upgrade, Comic Reader & Media Studio v0.3.0 🤖🖥️📚🎨🔧

- ✅ **Zed-Inspired SPA Architecture (Pro + Base).** 9-phase React SPA admin interface: Threads Sidebar, Agent Panel with SSE streaming, Cmd+K Command Palette (830+ tools), Agent Profiles (Write/Ask/Minimal/Custom with glob patterns), @-mention Context (8 entity types), Checkpoints & Diff Review with hunk-level accept/reject, Inline Assistant for Gutenberg, Multi-Model Comparison side-by-side, Collaborative Presence via Heartbeat API. ~75 files, ~10,800 lines across Base (PHP 7.4) and Pro (PHP 8.1 + React SPA). All existing jQuery chat UI and REST endpoints preserved.
- ✅ **Base Thread Management Infrastructure.** 6 PHP manager classes (`Thread_Manager`, `Profile_Manager`, `Checkpoint_Manager`, `Context_Mention_Resolver`, `Command_Registry`) + 5 REST controllers (Threads/Profiles/Checkpoints/Context/Commands) + 4 DB tables. Shared by jQuery chat UI and Pro React SPA.
- ✅ **Antigravity Interactions API Rewrite.** Gemini Managed Agent service rewritten for real Antigravity API (`POST /v1beta/interactions`) with code execution, Google Search grounding, and sandbox environments. `enable_managed_agents` toggle added to Settings → Orchestration.
- ✅ **TypeScript Upgrade + Orchestration Toggles.** Shared TS types, services, admin screens, chat drawer, and React SPA builds. "Use TypeScript-Compiled Assets" checkbox in Settings → Orchestration (`WP_MCP_AI_USE_TS_BUILD` constant fallback).
- ✅ **Comic Reader Addon (`addons/comic-reader/`).** React-based CBR/CBZ/CB7/CBT comic book reader with dual reading modes, zoom controls (25%–400%), keyboard navigation (arrows, A/D, +/-), touch support (swipe + pinch), fullscreen mode, progress persistence, drag-and-drop upload. Shortcode `[nvoos_comic_reader]` + Gutenberg block.
- ✅ **Media Studio v0.3.0.** Zoom/pan controls, drawing tools (Konva canvas: brush, eraser, shapes, text, undo/redo via history stack), save-to-WP-Media-Library integration (PNG/JPEG export). Drawing mode now shipped integrated into image-editor.
- ✅ **SPA Blueprint v3.0 + Adapter Automation.** External React template ingestion & gap analysis pipeline. API, i18n, CSS scope, and bundle optimizer adapters automated.
- ✅ **LLM Prompt Cache Optimization v1.5.1.**
- ✅ **30+ Reliability Fixes** — Cron status diagnostics (HTTP status code + up to 500 chars of response body in REST fetch errors). PHP 8.2+ compatibility (declared `$namespace`/`$rest_base` properties). PHP comments leaking into HTML output (moved `phpcs:ignore` + `translators` inside `<?php` tags). WordPress.org re-submission compliance (May 26 re-audit findings resolved, section 14 added). May 2026 audit findings (F-AUTHZ-05, F-AUTHZ-06, F-AGENT-01). PHPCS: 353 errors → 0 across 18 files. PHPUnit 11: 6 comprehensive fix batches across base, pro, and addon test suites (class-not-found, dynamic property, WPDieException errors). Docs Hub: browse-repo critical error fix, hierarchical folder tree picker, DNS resolution hardening, `autocomplete="off"` on settings inputs. Net worth calculator: removed stray `*/` causing parse errors. qs DoS vulnerability: CVE-2026-8723 resolved (qs >=6.15.2 via npm overrides). DeepSeek provider fallback: detects first enabled provider instead of hardcoding 'openai'. Test suite stability: wp_die handler at `PHP_INT_MAX`, `DOING_AJAX` defined in 9 test files, SQLite DB cleanup.
- 📦 **Versioning** — bumped to **1.1.23** across `mcp-ai-wpoos.php`, `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), and `CHANGELOG.md`. Provider count: **11** first-class language-model providers.

---

## 🆕 Latest Updates (v1.1.22 — May 2026)

### May 22–23, 2026 — Baseten Provider (11th), CoSAI Secure-by-Design Agentic System, Continual Harness P5, SaaS Controller P2/P4, npm VAD/Chat-Bubble/Memory-UI, Studio Test Env, Addons PHPCS Cleanup 🤖🔌🛡️📦🧹

- ✅ **Baseten AI — 11th first-class provider.** `WP_MCP_AI_Baseten_Client` with full OpenAI-compatible integration (chat, tools, streaming, reasoning passthrough) at `api.baseten.co/v1`. Settings → Providers → Baseten subtab; Provider Diagnostics card; Model Discovery `baseten` branch. Catalog entries, provider badges, CCT options seeded. Service documentation in [`docs/reference/EXTERNAL_SERVICES.md`](docs/reference/EXTERNAL_SERVICES.md).
- ✅ **CoSAI Secure-by-Design Agentic System.** Four new `includes/agents/` classes implementing all three CoSAI principles + MCP-T3/T5 sandbox: `WP_MCP_AI_Agent_Capability_Boundary` (immutable per-session tool allow-lists, rate limiting, budget exhaustion), `WP_MCP_AI_Agent_Audit_Trail` (cryptographic SHA-256 chain-of-custody audit trails, dual CPT+options storage, immutable events, OTel-compatible schema), `WP_MCP_AI_Agent_Approval_Gate` (risk-tiered approval low/medium/high/critical), and `WP_MCP_AI_Agent_Code_Sandbox` (isolated `proc_open`-based code execution for Python/Node.js/Bash/PHP with timeout enforcement and output caps). All provider-agnostic.
- ✅ **Gemini I/O 2026 Model Refresh.** Gemini 3.5 Flash added as recommended model (4x faster output, dynamic thinking, 1M context, $1.50/$9.00 per 1M tokens). Gemini Omni Flash as new video generation default (10s, native audio, multi-turn editing, AI avatars). Gemini 3.1 Flash deprecated (sunset 2026-09-01). All admin settings, onboarding wizard, Ext Cog, cost calculator, and model catalog migration updated.
- ✅ **Continual Harness — Self-Improving Agent System (P5).** Agents learn from execution history, refine strategies over successive runs, and improve tool selection accuracy through feedback loops. Integrated with the CoSAI audit trail for transparent improvement tracking.
- ✅ **SaaS Controller Phase 2 & 4 complete.** Stripe deployment editor (products, prices, webhook configs from WP-Admin). OpenRouter deployment editor (API key provisioning, model routing configs).
- ✅ **npm packages — nvoos-vad, nvoos-chat-bubble, nvoos-chat-memory-ui.** Browser-based Voice Activity Detection with configurable sensitivity. Floating chat bubble custom element with toggle/minimize/position. Standalone chat memory drawer React component for memory browsing/search/audit. All published with `dist/` TypeScript declarations.
- ✅ **WordPress Studio test environment.** `wp-tests-config.php` + `tests/bootstrap.php` auto-detect Studio's database, ABSPATH, and site URL. Complements Local by Flywheel, wp-env, and Codex configs.
- ✅ **Security fixes.** UUID `^9.0.0` override in saas-controller (buffer bounds check CVE). `map_meta_cap=false` for `mcp_ai_audit_event` CPT (WP 6.1+ `_doing_it_wrong` fix). Antivirus false positives resolved in `tests/test-skill-registry.php`.
- ✅ **Allowed providers list expanded.** DeepSeek, OpenRouter, DigitalOcean, Kimi, and Baseten added to the provider validation gate — previously functional but blocked in certain admin contexts.
- ✅ **LM Studio external service URLs fixed.** All `lmstudio.ai` references replaced with GitHub org URL after upstream started returning HTTP 500 on all paths.
- ✅ **Semantic compression settings** moved from Advanced → Orchestration tab (Settings view).
- ✅ **Overview dashboard inline CSS** fix — adopted render-time register/enqueue/add/print pattern matching the orchestration renderer.
- ✅ **Addons PHPCS — 93% reduction (1,143 → 82 errors).** 855 invalid comment fragments removed, 87 short ternaries expanded, 616 docblocks added, 444 `@param` tags, 39 Yoda fixes, 43 class docblocks. Batch 2 restored 6 files with parse errors from batch 1. `vendor/composer` files restored.
- 📦 **Versioning** — bumped to **1.1.22** across `mcp-ai-wpoos.php`, `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `package.json`, `package-lock.json`, `readme.txt` Stable tag, and `CHANGELOG.md`. Provider count: **11** first-class language-model providers.

## 🆕 Latest Updates (v1.1.21 — May 2026)

### May 20–21, 2026 — WP.org Compliance Complete, Canonical Return Envelope, Semantic Compression, AI Prompt Caching 🛡️🔒📊🧠⚡

- ✅ **WordPress.org Compliance — All 10 Findings Resolved (F1–F10).** All inline `<script>`/`<style>` echoes removed from 53 base-plugin files and converted to `wp_add_inline_style()` / `wp_print_inline_script_tag()`. Fixed PHP parse errors (duplicate `<?php`, spurious `?>`, missing `<?php` tags) across 11 files. F3 (bare `WP_PLUGIN_DIR`), F5 (tool HTML fragments), F6 (bare `phpcs:ignore` — ~50 annotated), F7b (logger path bounding), F8 (remaining `$_GET` `wp_unslash()` — 3 instances), F9 (remaining bare `phpcs:ignore` — 15 instances), and F10 (unguarded constants in `addons/` — 11 instances) all resolved. May 20 re-audit: dangerous-functions, superglobal-access, HTTP-timeout, inline-notice audits verified clean. Inline style handles registered properly (8 locations) to prevent silent CSS failures. Build pipeline hardened — `.codex-wordpress` and `phpcs` excluded from ZIPs; webpack-dev-server bumped to `>=5.2.4` for CVE-2026-6402. Pro Settings CSS loading restored. Full compliance evidence: [`docs/operations/compliance/WORDPRESS_ORG_COMPLIANCE_2026_05_19.md`](docs/operations/compliance/WORDPRESS_ORG_COMPLIANCE_2026_05_19.md).
- ✅ **Canonical Return Envelope — Unix Theory P0/P1 Complete.** Converted 191 non-canonical `array('success' => false, ...)` returns to `new WP_Error()` across 105 files (49 tool classes + 24 service/admin/rest/slash-command files). `WPMCPAI.Tools.CanonicalReturnEnvelope` sniff now clean; `SanitizeAtEntry` violation in `create-task-plan.php` resolved. Five justified exceptions remain (process utilities, not tool `execute()`). Caller sites hardened to test `is_wp_error()` instead of `$result['success']`.
- ✅ **Semantic Caveman Compression.** New `WP_MCP_AI_Semantic_Compressor` service (1,988 lines + 1,156 test lines + 44 unit tests) strips grammar and filler words while preserving facts, numbers, and technical terms. Opt-in via admin setting. Protects code blocks, JSON, URLs, emails, and HTML from compression. Settings moved from Advanced → Orchestration tab. See [`docs/features/semantic-compression.md`](docs/features/semantic-compression.md).
- ✅ **AI Prompt Caching — All Providers.** Comprehensive response caching across all five AI providers. New `WP_MCP_AI_Chat_Response_Cache` and `WP_MCP_AI_Prompt_Optimizer` classes. Cache eligibility: non-streaming, temperature=0, `cache_system_prompt` enabled. Keys use `sanitize_key()` + `absint()` + `md5()`. Invalidation on assistant config changes; TTL bounded 60s–3600s. Cache Performance dashboard in Token Manager section.
- ✅ **Capability Fence P2b — Full Rollout.** `get_required_capability()` added to `WP_MCP_AI_Tool_Interface` and deployed across all ~830 tool classes (base + Pro + all addons). `WP_MCP_AI_Tool_Default_Capability` trait for test stubs and anonymous classes. Central `WP_MCP_AI_Tool_Capability_Map` with sanitized values. New `WPMCPAI.Tools.RequiredCapabilityDeclared` PHPCS sniff at severity 5. Capability Fence Audit UI renders per-tool slug/capability/flags correctly.
- ✅ **Security Center.** New 5-tab admin page: Posture (live security scoring), Compliance Report (WP.org finding tracker), OTel Telemetry (`nvoos.tool.data_type` + `nvoos.tool.duration_ms` span attributes), Deprecated-Alias Tracking, MCP Token Inventory (per-assistant credential audit). REST endpoints + comprehensive PHPUnit coverage.
- ✅ **Memory Layer 2026 — Phases 3–7 Merged.** Auto-capture with SHA-256 dedup (Phase 3), RRF fusion retrieval — BM25 + vector + graph (Phase 4), confidence decay + contradiction detection (Phase 5), provenance tracer tool (Phase 6), Memory Health subtab + Retrieval Waterfall panel + Session Replay tab with `GET /mcp-ai/v1/chat-memory/sessions/{session_id}` endpoint (Phase 7). Memory CCT migrator disabled by default to stop infinite sanitize-loop log spam.
- ✅ **Model Catalog May 2026 Refresh.** DeepSeek V4 model family added (`deepseek-chat-v4`, `deepseek-reasoner-v4`). Gemini model entries consolidated and deduplicated. All provider pricing refreshed to May 2026 rates. 88 WPCS lint errors resolved.
- ✅ **Domain Migration.** All `nvoos.com` references migrated to `nvoos.pro` (documentation + ISO 27001) and `nvoos.cloud` (cloud-worker).
- ✅ **Cloud Worker Local Dev Setup.** New `README-LOCAL.md`, `scripts/seed-local.mjs`, and `wp-config-local.php` for local cloud-worker development.
- ✅ **@wordpress/env Dev Dependency.** Added `@wordpress/env` for zero-config local WordPress development environments via `wp-env`.
- ✅ **Translation Loading Fix.** Pre-populated `$l10n` with `NOOP_Translations` to prevent early-translation warnings. Deferred Security Audit CPT registration from `plugins_loaded` to `init`.
- ✅ **Unix Theory P5 Part 2.** Decomposed `git_operations` into `git_inspect` (read-only) + `git_change` (mutating).
- ✅ **Docs Hub + SaaS Controller fixes.** Non-JSON REST response guard for caching-plugin compatibility. Base-plugin detection corrected in SaaS Controller admin.
- ✅ **Folder README Convention Phase P7.** Every `includes/` and `addons/pro/includes/` PHP subdirectory now ships a `README.md` — completing Unix Theory P0–P7.
- ✅ **Addons/Pro Security Scan Fixes.** Fixed remote-sites admin pagination warnings, removed stale tags from project management, added `uninstall.php`.
- 📦 **Versioning** — bumped to **1.1.21** across `mcp-ai-wpoos.php`, `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `package.json`, `package-lock.json`, `readme.txt` Stable tag, and `CHANGELOG.md`.

## 🆕 Latest Updates (v1.1.20 — May 2026)

### May 18, 2026 — Memory Layer 2026 Phase 7 UI/UX complete 🧠🎛️

- ✅ **Phase 7a — Memory Health subtab.** Added an Orchestration Memory Health view with live memory status, threshold policy, and chat-memory availability indicators.
- ✅ **Phase 7b — Retrieval Waterfall panel.** Added Memory Drawer retrieval-path visibility (RRF + legacy breakdown + retrieval path metadata) using existing response metadata without REST shape breaks.
- ✅ **Phase 7c — Session Replay tab + route.** Added read-only replay endpoint `GET /mcp-ai/v1/chat-memory/sessions/{session_id}` and wired Session Replay in the Memory Drawer via localized `memoryEndpoints.sessionBase` and `chat-memory-service.sessionReplay()`.
- ✅ **Coverage added.** Added REST + JS tests for session replay route/service/drawer behavior.
- 📦 **Versioning** — bumped to **1.1.20** across `mcp-ai-wpoos.php`, `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `package.json`, `package-lock.json`, `readme.txt` Stable tag, and `CHANGELOG.md`.

## 🆕 Latest Updates (v1.1.19 — May 2026)

### May 18, 2026 — Kimi Provider, ACP Server, MCP Bridge, Unix Theory P7, Security Hardening, Chat Bubble Sweep 📡🔒🧩💬

- ✅ **Kimi (Moonshot AI) provider — 10th first-class language-model provider.** New `WP_MCP_AI_Kimi_Client` wrapping the OpenAI-compatible API at `https://api.moonshot.cn/v1`. Models: `kimi-k2.6` (256K context, multimodal, tool calling — default), `kimi-k2.5`, `kimi-k2` (reasoning), `kimi-k2-thinking` (chain-of-thought), legacy `moonshot-v1-8k/-32k/-128k`. Settings → Providers → **Kimi** subtab; Provider Diagnostics card. WP.org compliance docs updated with Kimi, OpenRouter, and DigitalOcean service disclosures.
- ✅ **Agent Client Protocol (ACP) Server.** Full ACP standard implementation enabling external AI clients (Zed, JetBrains, Neovim, Claude Desktop) to natively drive NV oOS assistants over JSON-RPC 2.0 + HTTP/SSE transport. Core: `WP_MCP_AI_ACP_Server`, `WP_MCP_AI_ACP_JSONRPC_Dispatcher`, `WP_MCP_AI_ACP_Session_Manager`, `WP_MCP_AI_ACP_Session_Bridge`, `WP_MCP_AI_ACP_Transport_HTTP`. `/.well-known/ai-peer` extended to advertise ACP endpoint, transports, and auth methods. **Orchestration → Settings** gains `enable_acp_server` + `acp_require_approval` toggles. PHPUnit coverage scaffolding in `tests/acp/`. See `docs/features/acp-server.md`.
- ✅ **MCP Bridge (`bin/mcp-bridge.js`).** Lightweight Node.js stdio-to-HTTP relay for local MCP clients (Claude Desktop, Cursor, Zed). Bridges the MCP stdio transport to the plugin's HTTP + SSE endpoint — no server-side changes required.
- ✅ **Unix Theory Phase P7 — Folder README convention.** Every PHP-bearing `includes/` subdirectory now ships a `README.md` declaring its purpose, public surface, and context-file links. Convention: `docs/developer/folder-readme-convention.md`; enforced by `composer run docs:check-folder-readmes`. Completes P0–P7 for the base plugin.
- ✅ **GDPR — JetEngine Privacy Exporters.** New privacy exporter classes for JetEngine CCT data (chat transcripts, agent memory, approval queue entries), registered via WordPress's `wp_privacy_personal_data_exporters` hook when JetEngine is active.
- ✅ **Security hardening (5 patches).** (1) Sensitive settings keys (API keys, tokens) encrypted at rest + masked in admin UI (#4990). (2) Webhook endpoints now reject requests missing the required shared secret (#4988). (3) User-configurable URLs in outgoing HTTP requests replaced with `wp_safe_remote_get`/`wp_safe_remote_post` to block SSRF (#4991). (4) Attachment URLs in tool results validated against `https`/`http` scheme allowlist to prevent `javascript:`/`data:` injection (#4975). (5) Sensitive console logs gated behind `WP_MCP_AI_DEBUG` + admin-only JS toggle (#4984).
- ✅ **Chat Bubble / Test Model UI sweep (13 PRs).** Chat bubble self-init via `wpMcpAiChatInit.init(scope)`; panel CSS scoped to bubble context; `kses_chat_output()` preserves interactive form/button/input controls; Test Model chat submission fully restored; professional selector AJAX render uses `kses_chat_output()`; bubble re-init isolated to its own ID/panel; submit button fixed inside outer page forms; unified team chat response normalization.
- ✅ **Asset Inventory.** Discover Assets button restored; `discover_assets` flow gains debug logging + Jest test coverage.
- ✅ **Docs — USE_CASES_AND_QUICKSTARTS Rev 2.0** refresh + stale-count sweep; `_USE_CASES_FACT_SHEET.md` is now the source of truth for point-in-time counts.
- 📦 **Versioning** — bumped to **1.1.19** across `mcp-ai-wpoos.php`, `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `package.json`, `package-lock.json`, `readme.txt` Stable tag, and `CHANGELOG.md`. Provider count: **10** first-class language-model providers.

## 🆕 Latest Updates (v1.1.18 — May 2026)

### May 14, 2026 — Unix Theory P0–P6, DigitalOcean Serverless Inference, Async Chat Continuation, Jobs/Tasks Drawer, Toolkit MCP Servers Phase 7 🧠⚙️📡🛡️

- ✅ **Unix Theory Compliance Phases P0–P6.** Cycle-spanning effort to bring tool authoring closer to single-responsibility, observable, composable Unix-style building blocks:
  - **P0/P1 — Canonical return envelope.** Tools return one of two shapes only. Tool-agnostic trait `WP_MCP_AI_Tool_Envelope` (composed by `WP_MCP_AI_Tool_Chat_Response`). New PHPCS sniff `WPMCPAI.Tools.CanonicalReturnEnvelope` flags forbidden `array( 'success' => false, ... )` shapes at severity 5 (visible under `composer run lint`, silent under `composer run lint:base`).
  - **P2 — Capability-fence audit.** All optional-dep touch-points verified fenced (Rank Math, WPCode, JetEngine, Elementor, WooCommerce); zero Base→Pro reach-throughs.
  - **P3 — Data-contract metadata.** Optional `WP_MCP_AI_Tool_Data_Contract_Interface` (`get_data_contract() => array{produces?, consumes?}`). The tool service appends `[Data contract: produces=X, consumes=A|B]` to the OpenAI function-calling description. Filter `wp_mcp_ai_tool_data_contract_description_suffix`.
  - **P4 — Tool lifecycle descriptor.** Optional 5th arg on `wp_mcp_ai_after_tool_execution`; `WP_MCP_AI_Tool_Lifecycle_Descriptor::build()` returns `{success, error_code, data_type, duration_ms}`. OTel spans gain `nvoos.tool.data_type` + `nvoos.tool.duration_ms`. 4-arg subscribers stay back-compat.
  - **P5 — Back-compat alias infrastructure.** Registry gains `register_deprecated_alias()`, `get_deprecated_aliases()`, `resolve_deprecated_alias()`, `reset_deprecated_alias_invocations()`. Action `wp_mcp_ai_tool_deprecated_alias_invoked` fires once per request per slug. Aliases live in a separate map invisible to `build_tools_payload`. Sets up Tier-A decompositions for v1.3.0.
  - **P6 — Sanitize-at-entry sniff.** New PHPCS sniff `WPMCPAI.Tools.SanitizeAtEntry` enforces Gate 1 of the two-gate sanitisation rule for `$arguments[...]` interpolation/concatenation. Codification: [`docs/project/proposals/audits/P6-sanitize-escape-codification-2026-05.md`](docs/project/proposals/audits/P6-sanitize-escape-codification-2026-05.md).
- ✅ **DigitalOcean Serverless Inference provider (new — 9th provider).** `WP_MCP_AI_DigitalOcean_Client` wraps the OpenAI-compatible API at `https://inference.do-ai.run/v1`. Chat completions, tool/function calling, JSON mode, SSE streaming, native `/embeddings`, model listing, reasoning passthrough. Settings → Providers → DigitalOcean subtab; Model Discovery `digitalocean` branch; Provider Diagnostics card with `GET /v1/models` probe; default embedding model `gte-large-en-v1.5`. DigitalOcean Agent endpoints (`*.agents.do-ai.run`) intentionally out of scope.
- ✅ **Async chat continuation (slices 1–6 complete).** Durable continuation store + dispatcher for async tool jobs, LLM re-entry, session frame buffer, SSE stream controller, `chat.js` client integration, Pro webhook notifier (`addons/pro/includes/services/class-wp-mcp-ai-pro-chat-continuation-notifier.php`), OTel hooks + Jest tests. Plan: [`docs/features/chat/async-continuation.md`](docs/features/chat/async-continuation.md).
- ✅ **Jobs/Tasks Drawer + cron-status integration (PRs A–G complete).** Inline job progress card (`.wp-mcp-ai-job-card__*` BEM block) with progress bar, ETA, step list, and Cancel/Retry buttons subscribed to `wpMcpAiJobBus` events. New REST routes `POST /mcp-ai/v1/cron-status/{job_id}/cancel` and `.../retry`; actions `wp_mcp_ai_job_cancelled`, `wp_mcp_ai_job_retried`. Tasks Drawer + toasts in chat shortcode (default-on via filter `wp_mcp_ai_chat_tasks_drawer`). Five new OTel hooks emit `nvoos.chat.jobs.*` OTLP spans. Docs: [`docs/features/chat/cron-status-integration.md`](docs/features/chat/cron-status-integration.md), [`docs/developer/tool-development/registering-a-job-source.md`](docs/developer/tool-development/registering-a-job-source.md).
- ✅ **Toolkit MCP Servers Phase 7 admin UI.** New `WP_MCP_AI_Pro_Toolkit_MCP_Servers_Page` (slug `nvoos-pro-toolkit-mcp-servers`) — 5-tab admin page (Servers / Detail / Audit / Discovery / Help). Action hook `wp_mcp_ai_toolkit_mcp_server_toggled`. Observability card + assistant metabox links updated. Pro plugin load order fix so the toolkit page registers before the admin block.
- ✅ **JetEngine CCT memory mirror.** `retrieve_agent_memory` + `recall` now hydrate directly from the JetEngine CCT mirror when available; pipeline deduped to avoid double-suppression.
- ✅ **Security / maintenance.** Bumped npm `langsmith` minimum to `>=0.6.0` (GHSA-3644-q5cj-c5c7). Added `wp_read_video_metadata` guard (`media.php`) in Veo and Sora video tools. Production autoload restored: `--no-dev --classmap-authoritative` with manual `dev: false` / `dev-package-names: []` patches.
- ✅ **Agent surfaces refreshed.** All hidden folders that host coding-agent context (`.bmad/`, `.codex/`, `.context/`, `.devcontainer/`, `.github/agents/`, `.vscode/`, `.zed/`) refreshed in this release so contributors get consistent guidance regardless of editor or agent. The `toolkit-spa-maintainer` agent is now mirrored from `examples/agents/` into both `.github/agents/` and `.zed/settings.json` (13 profiles).
- ✅ **UI/UX Pro Max Skill Bundle.** New bundled Agent Skill `ui-ux-pro-max` with comprehensive UI/UX Design Pack — 25+ CSV data files covering color palettes, typography scales (including Google Fonts), icon libraries, UI components, design patterns, and stack-specific guidelines for React, Vue, Angular, Laravel, Astro, Svelte, Flutter, SwiftUI, Jetpack Compose, and more. Includes Python utility scripts for design system search and core operations. Skill pack registry updated with new `ui-ux-design` pack. See `includes/bundled-skills/ui-ux-pro-max/`.
- 📦 **Versioning** — bumped to **1.1.18** across `mcp-ai-wpoos.php`, `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `package.json`, `package-lock.json`, `readme.txt` Stable tag, and `CHANGELOG.md`.

## 🆕 Latest Updates (v1.1.17 — May 2026)

### May 10, 2026 — WP.org Compliance, Chat SPA Phases 1–7, Docs Hub v0.3.8, Toolkit SPA Blueprint Phases 5–12, Coverage Campaign 🛡️💬📚🧪

- ✅ **WordPress.org Compliance Hardening (B-series, PRs #4892, #4902)** — resolved reviewer findings B1/B2/B3/B5/B8/B10/B11/B12/B13: inline `<script>`/`<style>` echoes removed, `WP_CONTENT_DIR` path replaced with `wp_upload_dir()`, central `WP_MCP_AI_User_Context_Helper::safe_set_current_user()` with `get_userdata()` + multisite `is_user_member_of_blog()` validation, `wp_unslash()` added to approval handler `$_POST` reads, 49/49 base AJAX handlers confirmed with `check_ajax_referer()`. Full evidence: [`docs/operations/compliance/WORDPRESS_ORG_COMPLIANCE_2026_05_09.md`](docs/operations/compliance/WORDPRESS_ORG_COMPLIANCE_2026_05_09.md).
- ✅ **Build-pipeline split (Track A)** — `bin/build-plugin-zip.sh --wp-org` flag produces a WP.org-compliant base-only ZIP; the full GitHub Release ZIP is a separate artifact. `addons/`, `.zed`, and root `*.md` files excluded from the submission ZIP.
- ✅ **Chat SPA addon (`addons/chat-spa/`) — all 7 phases complete (v0.6.0)** — React replacement for the legacy chat shortcode: Phase 1 (Vercel AI SDK UI + custom SSE→Data Stream Protocol adapter), Phase 2 (tool-call cards + memory pills + admin embed), Phase 3 (transcripts sidebar with load/save/delete), Phase 4 (memory drawer — Memories/Scope/Audit tabs), Phase 5 (HITL approval bar with 6 s polling), Phase 6 (file attachments + regenerate + message branching), Phase 7 (`WP_MCP_AI_LEGACY_CHAT_JS` opt-out constant + blueprint §20 migration guide). Bundle ~81.3 KB gzip.
- ✅ **Docs Hub addon (`addons/docs-hub/`) — v0.1.0 → v0.3.8** — remote-first defaults + tree-picker UX (v0.3.0), chunked rebuild + CLI subcommand (v0.2.x), anchor/scroll fixes, mobile sidebar toggle, GitHub subtree path fetch, RemoteAnchor, in-page link routing, defensive `remote_repos` coercion + REST/SSRF hardening (v0.3.6), a11y root attrs + skip-link + reduced-motion (v0.3.7), syntax highlighting via rehype-highlight, `PageFooter` (last_modified + edit-on-GitHub), `NV_oOS_Docs_Hub_Sitemap_Provider`, admin `repo-picker.js` extracted from inline script (v0.3.8).
- ✅ **Toolkit SPA Blueprint Phases 5–12** — a11y hardening (Phase 5), i18n pass with `wp.i18n` + `wp_set_script_translations` (Phase 6), expanded REST + shortcode PHPUnit tests for canvas-toolkit and media-studio (Phase 7), bundle-size CI guardrail workflow (Phase 8), scaffolder auto-patches `spa-a11y` + `spa-bundle-size` CI workflows (Phase 9), all 10 Tier-A manifests complete — crm, calendar-booking, financial-planner, analytics, regulatory-registration, law-firm, cre-debt, multilingual, ecommerce, social-media (Phase 10), `canvas-toolkit` v0.2.0 (whiteboard via tldraw v5, bpmn-js, mermaid modes), `document-editor` v0.2.0 (GrapesJS site-creator mode, ~485 KB gzip), `media-studio` Phase 4 (image-editor + media-player + audio-waveform).
- ✅ **PHPUnit + Vitest coverage campaign (PRs #1–#11)** — 11 PR-sized batches: tool registry smoke tests, harness/provider tests, REST controller tests (approval / cost-manager / slash-command), slash-command tests, 20 high-risk base tool tests, 20 high-risk Pro tool tests, 10 security-sensitive service class tests, hooks + security regression suite, Vitest scaffolding for all 6 SPA addons (~71 tests). PHPUnit coverage baseline + non-regression CI gate added.
- ✅ **AJAX handler coverage campaign (clusters 1–17)** — all 271 AJAX handlers covered; allowlist cleared to 0; CI guard added.
- ✅ **Dependabot security sweep (33 alerts)** — full backlog resolved across all npm manifests (axios, basic-ftp, ip-address, react-router-dom, wrangler/vitest/miniflare chain); Dependabot coverage extended to all addon manifests.
- 📦 **Versioning** — bumped to **1.1.17** across `mcp-ai-wpoos.php`, `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `package.json`, `package-lock.json`, `readme.txt` Stable tag, and `CHANGELOG.md`.

## 🆕 Latest Updates (v1.1.16 — May 2026)

### May 6, 2026 — SaaS Controller Addon (v0.1.0) + Structured Logging Integration 🛠️📊

- ✅ **SaaS Controller Addon (`addons/saas-controller/`, v0.1.0)** — operator-side WordPress admin toolkit for provisioning and managing the NV oOS Cloud control plane (Cloudflare Workers + D1 + KV + AI Gateway, Stripe billing, OpenRouter) from WP-Admin. The operator-facing counterpart to `addons/cloud-worker/`. All 11 phases shipped:
  - **Admin UI** (`WP-Admin → NV oOS SaaS`, `manage_options`) — four tabs: **Overview** (React Credentials Wizard + masked-credentials fallback), **Deployment** (topology editor + read-only Run Plan), **Operations** (HITL Apply + Drift Detector + Orphan Review + Webhook Events + Smoke Tests + Audit Log), **Packages** (credits surface).
  - **Encrypted credential store** — AES-256-CBC at rest, keyed from `AUTH_KEY + SECURE_AUTH_KEY`. Allowed keys: `cloudflare_account_id`, `cloudflare_api_token`, `stripe_secret_key`, `stripe_webhook_secret`, `openrouter_api_key`.
  - **Reconcile-plan generator** (`NVOOS_SaaS_Controller_Plan_Generator`) — diffs desired config vs live Cloudflare state; emits `creates / updates / noops / orphans / errors` with summary counts. Supports Stripe products/prices and OpenRouter keys alongside Cloudflare resources.
  - **HITL-gated Apply** — sync (`POST /apply/run`) and async background (`POST /apply/enqueue` + `NVOOS_SaaS_Controller_Apply_Job` cron worker, one row per tick, 6 h state transient).
  - **Drift detector** (`NVOOS_SaaS_Controller_Drift_Detector`) — compares deployed Worker against `worker/drift-manifest.json` fingerprint; returns `synced / drift / unknown / error`; auto-stamps manifest on `npm run build:worker`.
  - **Orphan cleanup** — separate single-use HITL token (`nvoos_saas_orphan_` namespace), `POST /apply/orphans/preview` + `POST /apply/orphans/run`.
  - **Stripe webhook verifier** (`NVOOS_SaaS_Controller_Stripe_Webhook_Verifier`) — HMAC-SHA256, constant-time `hash_equals`, 300 s replay guard, multi-`v1` rotation support.
  - **Webhook event store** (`NVOOS_SaaS_Controller_Webhook_Event_Store`) — 200-entry ring buffer, idempotent by `provider` + `event_id`, no PII.
  - **Audit log** (`NVOOS_SaaS_Controller_Audit_Log`) — 200-entry ring buffer across channels `cloudflare / stripe / openrouter / internal`.
  - **REST namespace** `/wp-json/nvoos-saas/v1/` — 19 routes, all requiring `manage_options` except `POST /webhooks/stripe` (signature-gated).
  - **Key filters:** `nvoos_saas_controller_apply_token_ttl`, `nvoos_saas_controller_audit_log_max_entries`, `nvoos_saas_controller_audit_log_record`, `nvoos_saas_controller_webhook_events_max_entries`, `nvoos_saas_controller_apply_job_state_ttl`, `nvoos_saas_controller_worker_dist_path`, `nvoos_saas_controller_worker_compatibility_date`, `nvoos_saas_controller_worker_upload_metadata`.
  - **Reference:** [`addons/saas-controller/README.md`](addons/saas-controller/README.md) · [`docs/operations/deployment/saas-controller.md`](docs/operations/deployment/saas-controller.md).

- ✅ **Structured Logging Integration (PR #4849)** — `WP_MCP_AI_Logger` calls added systematically across the plugin and all addons:
  - **`WP_MCP_AI_Agent_Memory_CCT_Bridge`** (Phase 4b-2) — all bridge writes, CCT mirror failures, filter-suppressed writes, and deletions logged at appropriate levels.
  - **`WP_MCP_AI_Transcript_Mining_Job`** — full job lifecycle logged: enqueue, tick, per-session success/error, completion, cancellation.
  - **Addons:** Algorave, Canvas, Webchat, Fantasy Football, Graphify, SaaS Controller.
  - **Core / Admin:** Run Timeline, Approvals, Settings Base, cost calculator, model catalog migration, workflow engine V2, DeepSeek client, harness layers (eval scheduler, retrieval harness, self-refine loop, tool-router harness).
  - **New PHPUnit test classes:** `tests/test-agent-memory-cct-bridge-logging.php`, `tests/test-transcript-mining-job-logging.php`.

- 📦 **Versioning** — bumped to **1.1.16** across `mcp-ai-wpoos.php`, `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `package.json`, `package-lock.json`, `readme.txt` Stable tag, and `CHANGELOG.md`.

### WordPress.org Compliance Hardening (May 9, 2026) 🛡️

**Five clusters of WordPress.org automated-review findings resolved.** Full evidence catalogued in [`docs/operations/compliance/WORDPRESS_ORG_COMPLIANCE_2026_05_09.md`](docs/operations/compliance/WORDPRESS_ORG_COMPLIANCE_2026_05_09.md). Per-finding reviewer-response table in [`SUBMISSION.md`](SUBMISSION.md).

- ✅ **B3 — Inline `<script>` / `<style>` removed** — dead WP < 5.7 fallback branches deleted; config blocks converted to `wp_print_inline_script_tag()` hooked on `admin_enqueue_scripts`; admin telemetry CSS moved to `wp_add_inline_style()`.
- ✅ **B8 — Filesystem cache path corrected** — cache base directory moved from `WP_CONTENT_DIR/cache/wp-mcp-ai` to `wp_upload_dir()['basedir']/wp-mcp-ai-cache` (`includes/cache/class-wp-mcp-ai-cache-service.php`).
- ✅ **B10 — `wp_set_current_user()` hardened** — new `WP_MCP_AI_User_Context_Helper::safe_set_current_user()` validates `get_userdata()` before touching global state; multisite adds `is_user_member_of_blog()` check. PHPUnit suite: `tests/test-user-context-helper.php`.
- ✅ **B13 — `wp_unslash()` + `phpcs:ignore` explanations** — `$_POST['approval_id|resolution|note']` in approvals handler now wrapped with `wp_unslash()`; bare `phpcs:ignore NonceVerification.Recommended` lines in DAG builder replaced with explanatory comments. 49/49 base AJAX handlers confirmed to carry `check_ajax_referer()`.
- ✅ **Production vendor autoload** — `composer install --no-dev --classmap-authoritative` drops all dev packages from `vendor/` (−6,761 lines); `setClassMapAuthoritative(true)` eliminates PSR-4 runtime fallback. 677 production-only classes in the submission ZIP.

## 🆕 Latest Updates (v1.1.15 — May 2026)

### May 3–5, 2026 — New Providers (OpenRouter + DeepSeek), Orchestration Phases 1–7, LLM Harnessing GA, Memory Bridge G-series, Graphify Data-source Bridge 🔌🧠🕸️💬⚡

- ✅ **New AI providers** — **OpenRouter** (`WP_MCP_AI_OpenRouter_Client`, PR #4840) provides a unified OpenAI-compatible gateway in front of OpenAI, Anthropic, Google, Meta, Mistral, and others via a single API key (selectable from **Settings → Providers**). **DeepSeek** (`WP_MCP_AI_DeepSeek_Client`, PR #4820) added as a first-class provider with `reasoning_content` passthrough. **Kimi K2.6 + Qwen 3.6** added to model catalog and provider dropdowns (PR #4810).

- ✅ **LM Studio parity + native cURL SSE streaming** — LM Studio now streams tokens in real time via native cURL SSE (PR #4839) and has been brought to full May 2026 capability parity: native `/api/v0` opt-in, embeddings, bearer-token auth, capability-aware tool gating, `reasoning_content` passthrough, argument repair, TTL + structured-output pass-through, and improved `test_connection()` fallback. New filters: `wp_mcp_ai_lm_studio_stream_request_args`, `wp_mcp_ai_lm_studio_native_endpoint`.

- ✅ **Orchestration roadmap Phases 1–7 (re-landed, PRs #4816 #4821)** — full orchestration feature set re-landed with a JetEngine CCT init-priority fix (all CCT bootstraps now register at `init` priority 11+):
  - **Phase 1 — Observability**: Run Timeline, Layer I Prompt-Injection Detector (`WP_MCP_AI_Prompt_Injection_Detector`), Structured Output Guardrail, OTel span exporter. Observability dashboard in the **Orchestration** tab (PR #4833); OTLP endpoint + token in **Tools → Connections** (PR #4837).
  - **Phase 2 — HITL**: `WP_MCP_AI_Approval_Queue` (CPT `mcp_ai_approval`; `pending`→`publish`=approved, `private`=denied), REST `/mcp-ai/v1/approvals/*`, `request_user_approval` tool, admin **Approvals** page.
  - **Phase 3 — Structured output**: schema-validated response pass-through.
  - **Phase 4 — DAG builder**: visual workflow DAG builder + DAG execution engine.
  - **Phase 5 — Durable runs**: `WP_MCP_AI_Durable_Run_Store` for pause/resume across HTTP boundaries.
  - **Phase 6 — Triggers + webhooks**: `WP_MCP_AI_Workflow_Trigger_CPT` fires workflows from WP events or inbound webhooks.
  - **Phase 7 — Sub-agents + Pro hardening**: sub-agent dispatch, Pro `WP_MCP_AI_Vector_Store_Adapter` (openai/pgvector/qdrant), Pro `WP_MCP_AI_Team_Budget_Manager` (per-team daily caps).

- ✅ **LLM Harnessing Subsystem (Layers A–H, `includes/harness/`)** — seven opt-in epistemic layers that improve response quality without modifying existing tool behaviour. All layers are off by default and activated per-assistant via the **LLM Harness** metabox on the assistant edit screen:
  - **Layer A** — Prompt / Cue Library: 7 named cue templates (CoT, Failure-Modes-First, Plan-Then-Solve, Cite-or-Abstain, Tool-or-Abstain, Clarify-First, State-Uncertainty). Extensible via `wp_mcp_ai_register_prompt_cues`.
  - **Layer B** — Reasoning / Rehearsal: canonical trace schema + self-consistency vote (best-of-N sampling).
  - **Layer C** — Tool Routing (`WP_MCP_AI_Tool_Router_Harness`): task-class-aware tool scoring with `preset_weights` map; filterable via `wp_mcp_ai_harness_tool_score`.
  - **Layer D** — Retrieval (`WP_MCP_AI_Retrieval_Harness`): unified fan-out to memory + semantic search; citation verification via `wp_mcp_ai_retrieval_claim_supported`.
  - **Layer E** — Feedback / Self-Refine: bounded `generate → critique → revise` loop with cost caps; Reflexion-style reflections persist to memory after PII scrubbing.
  - **Layer F** — Memory Scoping + PII Filter: task-class buckets, conservative regex sweep (emails, phones, SSNs, card-shaped digits, common API key prefixes); patterns filterable via `wp_mcp_ai_pii_filter_patterns`.
  - **Layer G** — Eval Scheduler: daily cron (`wp_mcp_ai_harness_eval_tick`) runs eval suites for all harness-enabled assistants via `wp_mcp_ai_harness_eval_generator`.
  - **Layer H** — Fine-tune Curriculum Export (Pro, `WP_MCP_AI_Tool_Export_Fine_Tune_Curriculum`): exports eval suites as OpenAI chat-format JSONL to `mcp-ai/harness-curriculum/` with `.htaccess`/`index.php` guards; per-case char cap filterable via `wp_mcp_ai_pro_curriculum_per_case_char_cap`.
  - **Harness profile** stored in `_wp_mcp_ai_harness_profile` post meta: `enabled`, `layers`, `cost_ceiling_usd`, `tools.router_mode`, `tools.preset_weights`, `evals_enabled`, `pii_filter`.
  - Reference: [`docs/features/llm-harness.md`](docs/features/llm-harness.md).

- ✅ **19 new slash commands (11 base + 8 Pro)** — the in-chat CLI nearly doubled, bringing base to 24 total. New base commands: `/jobs`, `/status`, `/cost`, `/diagnose`, `/tools`, `/skills`, `/preset`, `/model`, `/clear`, `/reset`, `/resume`. Pre-existing base commands: `/help`, `/next-task`, `/ship`, `/clean-content`, `/optimize-perf`, `/sync-docs`, `/workflow`, `/compact`, `/context`, `/remember`, `/forget`, `/scope`, `/markup-stats`. New Pro commands: `/schedule`, `/schedule-preset`, `/workflow-preset`, `/run`, `/agent`, `/mcp-app`, `/persona`, `/broadcast`. All registered from `includes/slash-commands/slash-commands-init.php` (base) and the `wp_mcp_ai_slash_commands_initialized` action (Pro). Reference: [`docs/features/slash-commands-guide.md`](docs/features/slash-commands-guide.md).

- ✅ **Chat-client ⇄ Memory Bridge — G-series completion** — the durable memory surface is now fully visible in the chat UI:
  - REST proxy (`WP_MCP_AI_REST_Chat_Memory_Controller`): 6 routes under `/mcp-ai/v1/chat-memory/` (preferences, wake-up, recall, store, audit, /{context_id}).
  - Memory Drawer (`assets/js/chat-memory-drawer.js`): three tabs — Memories, Scope, Audit. The Audit tab lazy-loads on first activation.
  - 🧠 auto-badge on every assistant message bubble that touched a memory tool call.
  - SSE `memory_event` frame — real-time 🧠 toast when a memory tool completes.
  - Pagehide auto-capture (with `mb_strcut` UTF-8 safety + LLM summarisation fallback).
  - Drawer export: downloads the visible memory set as a JSON archive.
  - **Three gates**: site-wide filter `wp_mcp_ai_chat_memory_enabled`; site-wide **Enable Chat-Client Memory** toggle in **Orchestration → Settings** (PR #4802); per-user meta `wp_mcp_ai_chat_memory_enabled`.
  - Reference: [`docs/features/memory/chat-client-integration.md`](docs/features/memory/chat-client-integration.md).

- ✅ **Retroactive Transcript Mining — stuck-job root causes fixed** — background job + REST controller + new `transcripts` source on `mine_agent_memory`:
  - `WP_MCP_AI_Transcript_Mining_Job`: transient state (prefix `wp_mcp_ai_tx_mine_job_`, 6h TTL), 500 session cap, 10 sessions/tick default. Cron hook: `wp_mcp_ai_transcript_mining_tick`. Sentinel `__auto__` delegates session discovery to the tool itself.
  - REST endpoints (`manage_options` gated): `POST /jobs`, `GET /jobs/{id}`, `POST /jobs/{id}/cancel`.
  - Three root causes of stuck-at-queued fixed (PRs #4804, #4826): future cron timestamp, missing `spawn_cron()`, transient key namespace collision.
  - Reference: [`docs/features/memory/transcript-mining.md`](docs/features/memory/transcript-mining.md).

- ✅ **Graphify NV oOS data-source bridge (PR #4834)** — private CPTs, JetEngine CCT resolvers, MemPalace `RECALLS` edges, and external `$wpdb` tables as first-class Graphify data sources. New **Sources (CPT / CCT)** tab on Knowledge Graph settings page (PR #4836).

- ✅ **Pro Packages — Tier 5 (Chat Service Utilities)** — five new browser-native NPM packages under `@nvdigitalsolutions/`: `nvoos-client-tools` (browser AI tool registry), `nvoos-chat-memory` (REST client for the chat memory bridge), `nvoos-attachments` (file attachment helpers), `nvoos-cron-status` (SSE-first job status monitor), `nvoos-transcription` (MediaRecorder + transcription pipeline). Surfaced on the **NV oOS → Pro Packages** admin screen.

- ✅ **DX** — custom devcontainer image for WordPress plugin development (PR #4811); `examples/agents/` roster mirrored as native Zed agent profiles in `.zed/settings.json` (PR #4808).

- ✅ **Stability sweep** — transcript-mining stuck-job (PRs #4804 #4826), workflow-not-found (#4803), workflow-cpt map_meta_cap (#4822), Graphify recursion + Sources tab + lint (#4835 #4836 #4838), multi-agent dashboard TypeError (#4823), credential nonce mismatches (#4824 #4825), JetEngine CCT prefix `jet-cct-` → `jet_cct_` + channel SQL backtick-quoting (#4827 #4828 #4830), site-health polyfill (#4832), README TOC (#4807).

- 📦 **Versioning** — bumped to **1.1.15** across plugin header (`mcp-ai-wpoos.php`), `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `package.json`, `package-lock.json`, `readme.txt` Stable tag, and `CHANGELOG.md`. Tool counts remain reconciled at **~195 base / ~635 Pro / ~830 total** — the live registry via `WP_MCP_AI_Tool_Registry::get_tools()` remains authoritative.

## 🆕 Latest Updates (v1.1.14 — May 2026)

### May 1–2, 2026 — Agent Skills v2 + Markup Subsystem (Base) + MemPalace Capture Framework + Graphify CPT/CCT integration suite + follow-up fixes 🧠📚🖌️🕸️🔧

- ✅ **Markup Subsystem (Base, PR #4778)** — a new in-the-loop image / document markup system that lets tools pause the agentic loop, surface a Konva canvas widget in the chat UI for the user to draw on, and resume the same tool call with the rasterised mask / crop / region polygon. Surfaces:
  - **Loop integration** — `WP_MCP_AI_Markup_Loop_Interceptor` short-circuits any tool that implements `WP_MCP_AI_Markup_Aware_Tool_Interface` and emits a `markup_elicitation` SSE frame instead of the tool result. Master toggle: `wp_mcp_ai_markup_enabled` filter.
  - **Markup-aware tools out of the box** — `edit_openai_image` (`mask`), `crop_image` (`crop`), `edit_gemini_image` (`region`).
  - **REST controller** — `/wp-json/mcp-ai/v1/markup/{request_id}` accepts a W3C Web Annotation envelope, runs `WP_MCP_AI_Markup_Validator` + `WP_MCP_AI_Markup_Rasterizer`, and re-invokes the source tool with the resulting artifacts in the execution context.
  - **Settings UI** toggle under **NV oOS → Settings → General**.
  - **Telemetry + admin dashboard** — bounded option `wp_mcp_ai_markup_telemetry` aggregates per-tool / per-mode counters; **NV oOS → Markup Telemetry** renders the summary as a server-rendered HTML table with a colour-coded completion-rate card and a nonce-protected `Reset counters` form.
  - **Slash command** `/markup-stats` (alias `/markup`) renders the same summary as Markdown with `--verbose`, `--json`, and `--reset` flags.
  - **Hooks** (4 actions + 4 filters): `wp_mcp_ai_markup_request_created`, `wp_mcp_ai_markup_submitted`, `wp_mcp_ai_markup_validated`, `wp_mcp_ai_markup_resolved`, `wp_mcp_ai_markup_enabled`, `wp_mcp_ai_markup_widget_payload`, `wp_mcp_ai_markup_mcp_elicitation`, `wp_mcp_ai_markup_rasterized_artifacts` — documented in [`docs/reference/hooks/hooks-reference.md`](docs/reference/hooks/hooks-reference.md).
  - **Daily cleanup** cron (`wp_mcp_ai_markup_cleanup`) prunes expired markup transients and orphan mask attachments.
  - Reference: [`docs/features/markup-subsystem.md`](docs/features/markup-subsystem.md).

- ✅ **Agent Skills Phases 1–4 (PR #4771)** — the Agent Skills surface (per the [agentskills.io](https://agentskills.io/specification) specification) is now end-to-end across base + Pro:
  - *Phase 1* — **Bundled WP-developer skills**: 28+ new `SKILL.md` files curated from the MIT-licensed [`Lonsdale201/wp-agent-skills`](https://github.com/Lonsdale201/wp-agent-skills) catalogue under `addons/pro/includes/bundled-skills/` (WooCommerce HPOS, payment gateways, REST API v4, shipping, Stripe, variations, customer & sessions, classic emails, coupons, product search/select; WooCommerce Memberships access discounts + subscriptions linkage + hooks; WooCommerce Subscriptions renewal scheduler + switching/gifting + hooks; JetEngine dynamic visibility + listings callbacks + query builder custom types; JetFormBuilder action events + external API + item decorator + messages + form actions + sidebar panels + settings tabs; WP Rocket cache invalidation + rejection filters). Base plugin gains a `wp-abilities-api` skill under `includes/bundled-skills/`. New `THIRD_PARTY_NOTICES.md` in both `bundled-skills/` directories carries upstream attribution and license text.
  - *Phase 2* — **Remote skill catalogues (Pro)**: new [`WP_MCP_AI_Skill_Catalogue_Service`](addons/pro/includes/services/class-wp-mcp-ai-skill-catalogue-service.php) discovers `SKILL.md` files in registered public Git repositories using the GitHub trees API, supports `catalogue.json` manifests when present, caches manifests in 24-hour transients, and refreshes them daily via the `wp_mcp_ai_skill_catalogue_refresh` WP-Cron job. Pre-seeded with `Lonsdale201/wp-agent-skills` and `anthropics/skills`. New [`WP_MCP_AI_Skill_Catalogue_REST_Controller`](addons/pro/includes/rest/class-wp-mcp-ai-skill-catalogue-rest-controller.php) exposes admin-only endpoints under the `mcp-ai-pro/v1` namespace (`/catalogues`, `/catalogues/{id}/skills`, `/catalogues/{id}/install`, `/catalogues/{id}/refresh`). All catalogue fetches reuse the SSRF-safe HTTPS-only helper that protects `/skills/install-url`, the existing extension allowlist, and the decompression-bomb cap.
  - *Phase 3* — **Progressive disclosure (`load_skill`)**: each assistant gains a "Use progressive disclosure" checkbox on its Skills metabox. When enabled, the system prompt receives only a short `# Available Skills` catalogue (skill name + description) and the model calls the new base-plugin `load_skill({ name })` tool when it decides a skill applies — at which point the full SKILL.md is returned in the tool result. This dramatically reduces baseline context cost for skill-heavy assistants.
  - *Phase 4* — **Skill packs**: curated, named collections of related skills addressable as a single unit ("WordPress Developer", "Document Authoring", etc.). The Skill Manager admin UI gains tabs for browsing catalogues, managing packs, and editing individual skills.
  - Filters: `wp_mcp_ai_skill_catalogue_manifest_ttl` (transient TTL), `wp_mcp_ai_skill_catalogue_refresh_cadence` (cron schedule).
  - Reference: [`docs/features/agent-skills.md`](docs/features/agent-skills.md) (full Phases 1–4 narrative).

- ✅ **MemPalace Capture Framework Phases A + B1** — a foundation pass plus the five highest-leverage capture tools layered onto the Phase 4a/4b durable agent-memory bridge shipped in 1.1.13:
  - *Phase A* — base capture interface, lifecycle hooks, and shared time-source / tier-logging utilities, with a follow-up review fix for time consistency and tier-logging payload shape.
  - *Phase B1* — five highest-leverage capture tools that write into the durable `ai_agent_memories` Custom Content Type through the Phase 4a/4b bridge.
  - See [`docs/features/memory/AGENT-MEMORY-COMPLETE-GUIDE.md`](docs/features/memory/AGENT-MEMORY-COMPLETE-GUIDE.md) for the unified MemPalace / Letta / Zep / mem0 / Cognee schema rationale that the capture tools target.

- ✅ **Graphify CPT/CCT integration suite** — JetEngine custom post types and Custom Content Types are now first-class citizens across every Graphify surface:
  - **Knowledge graph builds (#4779)** — JetEngine CPTs and CCTs are detected, structured (`cct_{slug}` nodes + `AUTHORED_BY` edges), and semantically embedded end-to-end through dedicated cache prefixes and cron actions.
  - **Graph Explorer type filter** — JetEngine CPTs and CCTs now appear alongside core post types in the Explorer's type filter.
  - **Related-content widget + recommendations** expanded to all CPTs (audit follow-up).
  - **Semantic extractor extended to JetEngine CCT items (#4781)**.
  - **Re-index All Nodes** button on the embeddings tab now triggers a full re-index of every Graphify node.
  - **Per-source detection counts + CCT skip reason** surfaced on the embeddings/diagnostics screens.
  - **Settings persistence on tabbed admin page (#4784)**, **JS escaping refactor (#4780)**, and **`ucwords` for multi-word post-type slug fallback** so `case_study` produces `Case Study` instead of `Case_study`.

- ✅ **Agent context system (`.github/agents/` + `examples/agents/`)** — `.github/agents/` is now a layered context surface; each `*.agent.md` carries only role-specific metadata + behavior and links to canonical sources (`AGENTS.md`, `CLAUDE.md`, `.context/`) for shared rules. New 10-agent roster under [`examples/agents/`](examples/agents/) covers the full NV oOS surface (REST reviewer, security reviewer, WP.org compliance auditor, PHP-compat reviewer, tool author, slash-command author, chat-UI author, PHPUnit test author, agent-skill curator, addon maintainer, release engineer, docs maintainer). Slim canonical template lives at `.context/templates/agent-file-template.md`. See [`AGENTS.md` §2](AGENTS.md) for the layering rule.

- ✅ **Fixed — Markup subsystem hardening** — server-side ownership check on the admin fallback page (a markup `request_id` issued for one user can no longer be opened by another), null-safe hex color, and best-effort hardening file writes on the rasteriser path.

- ✅ **Fixed — Graphify Memory Bridge stale "not installed" status (#4769) + orchestration dashboard JetEngine availability recompute (`#dabb3746`)** — `get_agent_memory_stats()` caches results for 5 min; both `bridge_active` (Graphify) and `persistent_storage.available` (JetEngine CCT) are now re-checked on cache hit so the dashboard no longer reports "not installed" for up to 5 minutes after the underlying plugin is activated. Regression covered by `tests/test-orchestration-dashboard-stale-cache.php` and `tests/test-orchestration-dashboard-memory-phase4a.php`.

- ✅ **Fixed — Pro Mini App Builder TMA bundle enqueue (`#53c64d49`)** — the Telegram Mini App bundle now enqueues correctly even when the build pipeline does not emit a sibling `asset.php` manifest, so the builder loads on a clean install.

- ✅ **Fixed — cURL SSL error 60 fetching remote skill catalogues (#4772)** — the new catalogue-fetcher (Phase 2) could fail with `cURL error 60: SSL certificate problem` on hosts with outdated CA bundles when reaching `api.github.com` and `raw.githubusercontent.com`. The HTTP layer now uses WordPress's `wp_remote_get()` certificate bundle path consistently and surfaces a structured `WP_Error` instead of a fatal request failure when verification still fails.

- ✅ **Fixed — "Dynamic require of dicom-parser" in Medical Imaging Viewer (#4773)** — the Pro Medical Imaging Viewer bundle could fail at runtime with `Dynamic require of "dicom-parser" is not supported` when loaded from the Pro `build/` directory. The viewer now imports `dicom-parser` statically so the esbuild output no longer relies on a runtime CommonJS shim.

- ✅ **Fixed — Stored embeddings display (#4787)** — the stored-embeddings admin display rendered incorrectly under certain dataset shapes; it now degrades gracefully and surfaces accurate counts.

- ✅ **Build — distribution ZIPs rebuilt + production autoloader reaffirmed (#4774, #4775, #4782)** — `bin/rebuild-all-zips.sh` regenerated the four original (`mcp-ai-wpoos-base|pro|combined|core`) and four WordPress.org (`nvdigital-open-operator-system-oos-*`) packages plus the standalone toolkit add-on ZIPs (19 toolkit ZIPs in the v1.1.13 rebuild pass); `vendor/composer/installed.json` and the autoload classmap were re-regenerated with `composer install --no-dev --classmap-authoritative` to confirm the production posture established in 1.1.13.

- 📦 **Versioning** — bumped to **1.1.14** across plugin header (`mcp-ai-wpoos.php`), `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `package.json`, `package-lock.json`, `readme.txt` Stable tag, and `CHANGELOG.md`. Tool counts remain reconciled at **~195 base / ~635 Pro / ~830 total** — the live registry via `WP_MCP_AI_Tool_Registry::get_tools()` remains authoritative.

## 🆕 Previous Updates (v1.1.13 — May 2026)

### April 30 – May 1, 2026 — OpenAI `gpt-image-2` (Images 2.0), Phase 4a/4b durable agent-memory bridge, AI Harmonization toolkit, production-only Composer autoloader 🎨🧠🛠️

- ✅ **OpenAI `gpt-image-2` (Images 2.0)** — first-class support added in [`WP_MCP_AI_OpenAI_Client`](includes/class-wp-mcp-ai-openai-client.php) and [`WP_MCP_AI_Tool_Generate_OpenAI_Image`](includes/tools/class-wp-mcp-ai-tool-generate-openai-image.php), now the default image model across the base plugin and Pro image tools. New 2K aspect-ratio sizes for `gpt-image-2`: `2048x2048` (square), `2048x1152` (16:9), `1152x2048` (9:16). Cost / token tables and admin model dropdowns ("Images 2.0 (Recommended)") updated to match. Pro tools `generate_architectural_drawing`, `product_actualization`, harmonization base, and `generate_scene_background` default to `gpt-image-2` as well. Existing sites with a saved `openai_image_model` setting are unaffected. Filters unchanged: `wp_mcp_ai_openai_image_models`, `wp_mcp_ai_openai_image_sizes`, `wp_mcp_ai_image_model_supports_response_format`, `wp_mcp_ai_image_model_supports_style`. New PHPUnit `test_gpt_image_2_is_recognized_and_default` covers the default, the `hd → high` quality remap, and the suppression of `response_format` on the wire.

- ✅ **Phase 4a/4b — durable agent-memory bridge (MemPalace-inspired)** — agent memory was the only persistent surface in the plugin still backed solely by transients (cache-evictable). With JetEngine active, every transient memory write is now mirrored into a durable `ai_agent_memories` Custom Content Type with an industry-standard schema combining ideas from **Letta / MemGPT** (memory tier, verbatim immutability flag, expires_at TTL anchor), **Zep** (bi-temporal validity, source provenance), **mem0** (importance, verbatim discipline, source tracking), **Cognee**, and [**MemPalace**](https://github.com/MemPalace/mempalace) (hierarchical scope via wing/room, verbatim-storage discipline). Transients remain the primary fast read path; the CCT is the durable backing store. Vector and graph references (`embedding_id`, `graph_node_id`) are nullable forward-compatibility hooks. New `wp_mcp_ai_memory_deleted` action fires from `manage_context_lifecycle` delete path with subscriber-driven CCT cleanup. The agent-memory dashboard now surfaces a **"Persistent (CCT) / Cache only"** stat card. Source files inspired by MemPalace now cite the upstream project in their file headers so attribution matches `docs/features/memory/AGENT-MEMORY-COMPLETE-GUIDE.md`. Tests: `tests/test-jetengine-agent-memories-cct.php` + `tests/test-agent-memory-cct-bridge.php` (13 new tests; 24 existing regression tests still pass).

- ✅ **AI Harmonization sub-toolkit (Pro)** — 14 new Pro tools for cross-model output reconciliation, registered alongside the existing orchestration toolkit with their own registry section and admin-doc presets. The Architectural Design Toolkit and other Pro toolkits' in-app docs were refreshed in the same pass to cross-link the new harmonization workflows.

- ✅ **Production-only Composer autoloader** — the repo can now be cloned and used as a production WordPress plugin without an extra build step. `composer install --no-dev --classmap-authoritative` (no separate `dump-autoload` invocation needed) regenerates the autoloader as part of `install`. `vendor/composer/installed.json` now reports `"dev": false` with an empty `dev-package-names` array — no dev references survive in the production tree. `vendor/composer/autoload_real.php` calls `setClassMapAuthoritative(true)`, so PSR-4 filesystem fallback lookups are skipped at runtime. Net classmap diff: −6,761 / +279 lines as `phpunit/`, `phpcs/`, `wp-phpunit/`, and other dev-only packages drop out of `vendor/`.

- ⚠️ **Deferred (still in `[Unreleased]`)**: the QMS (ISO 9001:2015 Clause 7.5) + PARA (Tiago Forte) Methodology Integration subsystems remain feature-flagged and pending a separate review window. They are not part of 1.1.13.

## 🆕 Previous Updates (v1.1.12 — April 2026)

### April 27–29, 2026 — Architectural Design Toolkit (Phases A–E), Graphify Federation/RAG, Tier 4 Browser-AI Runtime, Production Cleanup, Security Patches 🏗️🕸️🔒

- ✅ **Architectural Design Toolkit — five-phase rollout**:
  - *Phase A* — refactored toolkit foundations.
  - *Phase B* — regional-compliance + analysis tools, with a dedicated PHPUnit suite and regional fixtures.
  - *Phase C* — EDGE / LEED scoring, Bill-of-Quantities (BoQ), and Value-Engineering (VE) options.
  - *Phase D* — IFC and gbXML interop, BIM Execution Plan (BEP), and RFI / submittal logs.
  - *Phase E* — precedent library, semantic search across precedents, and curated regional examples.
- ✅ **Graphify Phases 1–5 — federation, remote sources, vector / RAG, mapping UI**:
  - *Phase 1* — connector foundations + Woo / CSV / Webhook drivers.
  - *Phase 3* — SaaS connectors HubSpot, GitHub, Slack, Google Drive, Jira, Zendesk, M365 / SharePoint, ServiceNow (Pro).
  - *Phase 4* — Generic GraphQL, Generic SQL (read-only), and S3 (and S3-compatible) remote drivers (Pro).
  - *Phase 5* — schema.org auto-typing helper, embeddings-on-ingest helper, field-mapping admin UI with validator + live AJAX feedback.
  - Cross-cutting: remote sources, federation, vector embeddings, and RAG retrieval. **Algorave** also gained safe guest access for the live coder shortcode.
- ✅ **`plan_schedules_from_workflow` tool + Research & Add Schedule admin page** — base-plugin path from arbitrary workflow descriptions to staffing/schedule plans.
- ✅ **Production Cleanup admin workflow** — new buttons under *Settings → Advanced → Data Management* clear test/runtime artefacts safely; cleanup handlers were tightened in response to code review.
- ✅ **Tier 4 browser-AI runtime packages** — `llm-worker`, `model-loader`, and `transformers-client` NPM packages for in-browser AI; `nvoos-transformers-client` now guards against undefined `transformersUrl` in dynamic imports.
- ✅ **Security patches** — `phpoffice/phpspreadsheet` bumped to **^5.7.0** to patch HTML Writer XSS; `uuid` overridden to **`>=14.0.0`** to fix `GHSA-w5hq-g745-h8pq`.
- ✅ **TCPDF extracted into `oos-toolkit-tcpdf` addon** — pruned from the combined ZIP with classmap cleanup; vendor-only supplement toolkits now require **PHP 8.1+**.
- ✅ **AV-clean deploy tooling** — new `bin/strip-dev-files.sh` plus expanded `.gitattributes` `export-ignore` rules so deploy archives no longer trip antivirus scanners on test fixtures. Test files containing AV-triggering payload literals were obfuscated to keep the regression suite scannable.
- ✅ **Formal `WARRANTY.md`** — warranty, liability, and safe-use notice referenced from `README.md` and `SECURITY.md`.

## 🆕 Previous Updates (v1.1.11 — April 27, 2026)

### WordPress.org Compliance Hardening 🛡️

- ✅ Dead support-forum URL fixed in `readme.txt` (now points at the canonical `nvdigital-open-operator-system-oos` slug).
- ✅ Tool-count consistency: historical WordPress.org hardening note; current public framing is ~830 tools (~195 base + ~635 Pro), with the live registry authoritative.
- ✅ Missing `mcp` tag added to `Tags:` line.
- ✅ `bin/build-wordpress-org-from-base.sh` and `bin/review-zips.sh` now verify the readme invariants and fail the build if a legacy `wp-mcp-ai` slug or unrewritten text-domain header survives.

## 🆕 Previous Updates (v1.1.10 — April 2026)

### April 27, 2026 — Security Audit Summary, Production Vendor Autoload, Veo 3.1 Fix 🛡️

- ✅ **April 2026 Security Audit Summary published** — New [`docs/operations/compliance/SECURITY_AUDIT_2026_04.md`](docs/operations/compliance/SECURITY_AUDIT_2026_04.md) consolidates the nine deliverables under [`docs/project/audits/2026-04/`](docs/project/audits/2026-04/) into a single reference for maintainers and operators. Headline verdict: **no Critical findings**; 5 High (3 Fixed, 2 Partially Fixed); 14 Medium (all Fixed); 21 Low (14 closed); 10 Informational; 50 total. Standards applied include WP Plugin Handbook, WP.org Plugin Directory Guidelines, OWASP Top 10 (2021), OWASP API Security Top 10 (2023), WPCS 3.3, PHPCompatibilityWP, GDPR/CCPA, MCP/SSE conformance.
- ✅ **Production-ready vendor autoload (PR #4733)** — `vendor/` regenerated with `composer install --no-dev --classmap-authoritative` (677 production classes); the plugin is now deployable from a clean clone without a separate `composer install` step. Local development still requires `composer install` for dev dependencies.
- ✅ **Veo 3.1 `generate_veo_video` fix (PR #4735)** — `seed` parameter is now sent only to Veo 2.0 (`veo-2.0-generate-001`); Veo 3.1 (`veo-3.1-generate-preview`) rejects the parameter and the tool now silently drops it on that model.

## 🆕 Previous Updates (v1.1.9 — April 25, 2026)

### Measurement Subsystem GA (April 24–25, 2026) ⭐ **NEW**

**End-to-end measurement / evals / reward stack shipped across 12 sequenced PRs** — stock metrics, persistent store, eval harness, Pro rubric presets, WP-CLI runner, regression alerting, and a full Measurement dashboard. See [`docs/reference/measurement/README.md`](docs/reference/measurement/README.md) and [`docs/reference/measurement/rollout-plan.md`](docs/reference/measurement/rollout-plan.md).

- ✅ **Stock metrics** — tool-execution, chat-loop, agentic-loop, and SSE/stream metrics all emitted through a single `wp_mcp_ai_register_metrics` registry. Every signal carries a privacy tier, a direction (`higher_is_better` / `lower_is_better` / `neutral`), and a paired counter-metric so dashboards cannot Goodhart a single dimension.
- ✅ **Persistent metric event store** — `{prefix}mcp_ai_metric_events` table with per-request persister, retention cron, `wp_mcp_ai_metric_retention_days` filter (default 30 days). Table is dropped on uninstall when *Delete data on uninstall* is enabled.
- ✅ **Eval harness** — suites + cases registered via `wp_mcp_ai_register_eval_suites`; runner enforces verifier-independence against the suite's `generator_context` so a judge cannot share provenance with the candidate.
- ✅ **Pro rubric presets** — `prompt_adherence`, `json_schema`, `citation_presence`. `WP_MCP_AI_Eval_Runner::run_counterfactual()` flags measurement invalidity when the verifier fails to prefer the candidate over a degraded variant.
- ✅ **WP-CLI runner & regression alerting** — `wp mcp-ai measurement run <suite>`, `wp mcp-ai measurement alert-check <suite> [--window=N] [--webhook=<url>]` (exits `2` on regression, webhook failures never mask the exit code), `wp mcp-ai measurement list-runs <suite>` (`table|json|yaml|csv`). New stock metrics `eval.suite.pass_rate` and `eval.suite.regression.count`.
- ✅ **Dashboard** — read-only + writable Measurement dashboard under **Tools → Measurement** with a time-range selector, persisted-metric panel, sparkline, and contextual help tabs (extensible via `wp_mcp_ai_measurement_help_tabs`).
- ✅ **Exporters** — Budget envelopes and an OTel JSON exporter. Reference snippets ship under `assets/examples/measurement/`.

### PHPUnit 11 Upgrade — Security (April 18–23, 2026) 🔒

**PHPUnit upgraded to 11 with WordPress-compatibility patches to resolve the argument-injection vulnerability GHSA-qrr6-mg7r-m243.**

- ✅ `@dataProvider` docblocks migrated to PHP attributes where required by PHPUnit 11's stricter parser.
- ✅ `patches.lock.json` regenerated to include phpunit and wp-phpunit-phpunit10 patches (American-English `normalized` naming).
- ✅ **CI PHP bumped 8.1 → 8.2** — PHPUnit 11 requires PHP ≥ 8.2.
- ✅ 83 WPCS lint errors fixed in `tests/test-provider-client-adapters.php`.

### Chart.js Handle Normalization (April 18, 2026)

All admin dashboards (ECA, Schedule Manager, Agent Command Center, Measurement) now enqueue a single `wp-mcp-ai-chartjs` script handle — eliminates duplicate registrations, version drift, and double-load warnings.

### Graphify Knowledge Graph Addon v0.5.0 Restored (April 18, 2026)

**NV oOS Graphify** — an optional WordPress Knowledge Graph addon — has been restored under `addons/graphify/` after a short revert cycle.

### Comprehensive Orchestration Reference (April 16, 2026)

New [`docs/reference/orchestration/ORCHESTRATION_REFERENCE.md`](docs/reference/orchestration/ORCHESTRATION_REFERENCE.md) is the single authoritative reference for the orchestration layer: all 10 workflow presets, all 13 resource presets (with full settings-comparison matrices), PSO algorithm, tool-execution orchestrator, load balancer, reasoning controller, multi-agent system, health monitoring, budget enforcement, hooks / filters, storage keys, admin UI, and service-file index.

---

## 🆕 Previous Updates (v1.1.8 — April 2026)

### Erlang C Workforce Management Tools (April 15, 2026) ⭐ **NEW**

**4 base-plugin tools that apply the Erlang C teletraffic formula to contact-centre staffing, AI session capacity planning, and real-time SLA monitoring** — no Pro addon or external dependencies required. See [`docs/features/erlang-c-staffing-tools.md`](docs/features/erlang-c-staffing-tools.md).

- ✅ **`calculate_erlang_c`** — General staffing solver. Given arrival rate and average handle time, returns minimum agents needed to meet a service-level target, plus probability of waiting, average wait time, and utilisation.
- ✅ **`erlang_c_concurrency_advisor`** — Reads the plugin's own session telemetry (arrival rate + transcript-duration averages) and returns a data-driven recommendation for the **Max Concurrent Sessions** setting.
- ✅ **`erlang_c_staffing_advisor`** — Multi-channel planner covering voice, chat, email, and bot-deflection queues. Integrates with WFM endpoints via `wp_mcp_ai_erlang_c_wfm_export` filter.
- ✅ **`erlang_c_queue_health`** — Real-time SLA monitoring. Fires the **`wp_mcp_ai_queue_alert`** action hook when a queue breaches its SLA threshold, enabling custom alerting integrations.
- ✅ **Shared `WP_MCP_AI_Erlang_C` class** at `includes/class-wp-mcp-ai-erlang-c.php` — pure-PHP Erlang C implementation usable by custom tools without external libraries.

### Full Tool-Reference Audit (April 15, 2026)

**`docs/reference/tools/tool-reference.md` was audited in April 2026 and is superseded for headline counts by the current ~830-tool framing**, with 14 sections covering previously-undocumented tool groups:

OpenAI file/model management · text embeddings & vector stores · multi-agent orchestration · agent memory management · reasoning & code analysis · deep research · browser-native AI (client-side NLP) · Yahoo Fantasy Football toolkit · Newsletter plugin integration · WP All Import/Export integration · Flowhub cannabis dispensary · PayHere payment gateway · Erlang C queue tools.

### WordPress.org Compliance Re-Audit (April 15, 2026) 🔒

All 13 WordPress.org Plugin Guidelines pass. Compliance evidence — 333 capability checks, 147 nonce verifications, 200+ sanitization instances, 500+ output-escaping instances — is catalogued in `docs/operations/compliance/WORDPRESS_ORG_COMPLIANCE_2026_04_15.md`.

A subsequent hardening pass on May 9, 2026 resolving findings B3, B8, B10, B13, and the production vendor remap is documented in [`docs/operations/compliance/WORDPRESS_ORG_COMPLIANCE_2026_05_09.md`](docs/operations/compliance/WORDPRESS_ORG_COMPLIANCE_2026_05_09.md).

---

### MCP Apps — Per-Assistant Remote MCP Server Connections (April 10, 2026) ⭐ **NEW**

**Each assistant can now connect to up to 10 remote MCP servers as "apps", discovering and bridging external tools into the local tool registry at chat time** (PR #4646, SEP-1865).

- ✅ **`WP_MCP_AI_MCP_App_Client`** — JSON-RPC 2.0 client over Streamable HTTP transport. Implements `initialize`, `tools/list`, `tools/call`, `resources/list`, `resources/read` per MCP 2025-03-26 spec.
- ✅ **`WP_MCP_AI_MCP_App_Registry`** — Singleton managing per-assistant app configs in `_wp_mcp_ai_mcp_apps` post meta. Transient-cached tool discovery (5min TTL). Max 10 apps per assistant.
- ✅ **`WP_MCP_AI_MCP_App_Tool_Bridge`** — Wraps each remote tool as a local `WP_MCP_AI_Tool_Interface` with `mcp_app_{label}_{tool}` slug. Extracts `ui://` resource URIs from `_meta.ui.resourceUri`.
- ✅ **REST endpoints**: `POST /mcp-apps/test`, `POST /mcp-apps/discover`, `GET /mcp-apps/{id}`.
- ✅ **Admin metabox** — Repeater-style UI on assistant editor: label, server URL, auth (none/bearer/header), timeout, SSL verify.
- ✅ Remote tools registered via `wp_mcp_ai_register_tools` at priority 50 when `assistant_id` is present in request context.

### CRE Debt & Securitization Pro Toolkit — 57 Tools (April 10–11, 2026) ⭐ **NEW**

**Complete commercial real estate debt toolkit with 57 tools across five modules, shared financial calculator engine, and admin dashboard with Chart.js** (PRs #4647, #4650).

- ✅ **Shared Calculator** (`WP_MCP_AI_CRE_Debt_Calculator`) — Static methods for amortization (IO/P&I/balloon), DSCR/LTV/debt yield, NPV, IRR (Newton's method), DCF with terminal value, multi-constraint loan sizing, equity waterfall, defeasance/yield maintenance.
- ✅ **Originations** (11 tools) — Deal pipeline CRUD, borrower profiling, quote generation, deal screening (100-pt scoring), rate locks, broker tracking, execution strategy, closing checklists.
- ✅ **Underwriting** (13 tools) — DCF, NOI, loan sizing, amortization, debt yield stress testing, cap rate sensitivity, rent roll analysis, opex benchmarking, leverage/return, 3-approach valuation, environmental risk, memo generation.
- ✅ **CMBS/Securitization** (10 tools) — Tranche sizing/subordination, bond cash flow (CDR/CPR/severity), pool analysis, surveillance, special servicing, CRE CLO (OC/IC tests), defeasance, rating agency methodology, CREFC Annex A reporting.
- ✅ **Debt Fund** (11 tools) — Portfolio dashboard (AUM/WA metrics), waterfall (pref/catch-up/promote), fund returns (IRR/MOIC/DPI/RVPI/TVPI), credit risk (PD/LGD/EL), concentration limits, warehouse lines, LP reporting.
- ✅ **Asset Management** (12 tools) — Property budgeting, lease rollover, capex reserves, tenant credit, hold/sell analysis, performance tracking, loan surveillance, watchlist management, workout scenarios.
- ✅ **CPT/CCT Infrastructure** — Custom Post Types for deals/loans, CCT for pipeline metrics, Chart.js dashboard with deal pipeline visualization.
- ✅ **Settings toggle**: `enable_cre_debt_toolkit` in admin settings. All outputs include `ANALYSIS ONLY — Not investment advice.`

### 36 Pro Toolkit Professions + 17 Teams (April 11, 2026) ⭐ **NEW**

**All 275+ pro toolkit tools across 15 toolkits now have dedicated profession and team mappings** (PR #4652). Total professions: **296** (was 259). Total teams: **100**.

- ✅ **`pro-cre-debt.json`** — 8 CRE debt roles (originator, underwriter, CMBS analyst, debt fund manager, asset manager, capital markets analyst, special servicer, compliance officer).
- ✅ **`pro-financial-services.json`** — 6 roles covering 32 financial planning tools.
- ✅ **`pro-digital-media.json`** — 7 roles spanning image, video, social, and document toolkit tools.
- ✅ **`pro-business-operations.json`** — 7 roles covering ecommerce, CRM, analytics, calendar/booking toolkits.
- ✅ **`pro-specialized-services.json`** — 8 roles for regulatory, multilingual, site creator, AI tool builder, architect, DJ management.
- ✅ **`cre-debt-teams.json`** — 7 teams with orchestration workflows (sequential/parallel/hybrid/swarm).
- ✅ **`pro-toolkit-teams.json`** — 10 cross-functional teams (wealth advisory, media production, ecommerce growth, website launch, global registration, etc.).

### WordPress.org Compliance Hardening (April 9–11, 2026) 🔒

**Multiple compliance passes addressing WordPress.org automated review findings** (PRs #4642, #4645, #4654, #4658).

- ✅ **AJAX capability checks** — `dismiss_directory_notice` and `dismiss_price_notice` handlers now require `manage_options` capability.
- ✅ **$_POST sanitisation** — Raw `$_POST` iteration in diagnostic logging now applies `sanitize_key()` on keys and `sanitize_text_field( wp_unslash() )` on values.
- ✅ **Capability flag corrections** — 13 base tools corrected from `local-only` to `external-api` (tools making external HTTP requests).
- ✅ **CLI export path** — `--file` parameter restricted to bare filename; all exports to `uploads/mcp-ai/exports/` with `sanitize_file_name( basename() )`.
- ✅ **Vision tool fixes** — Missing closing class braces in vision-object-localization and vision-product-search tools (ParseError fix).
- ✅ **404 URLs fixed** — Trade.gov privacy URL and Mailjet terms URL corrected to working endpoints.

### Algorave Audio & Visualizer Fixes (April 8–11, 2026) 🔧

**Multiple critical audio fixes for the Algorave addon** (PRs #4632, #4633, #4636, #4637, #4639, #4644, #4648, #4655).

- ✅ **AudioContext resume** — Synchronous `getAudioContext().resume()` within user-gesture handler before any async operations.
- ✅ **channelCount=0 proxy fix** — Data descriptor (value+writable) for `maxChannelCount` on proxy, clamped to [1,32]. Verification + accessor fallback. Eager `initializeAudioOutput()` call after proxy install.
- ✅ **Visualizer analyser connection** — AnalyserNode connection timing fixed across 5 PRs. Analyser now connects to correct audio output node.
- ✅ **Async aliasBank** — CDN redirect and unhandled rejection fixes for sample loading.

### Security Dependency Updates (April 8–11, 2026) 🔒

- ✅ **nodemailer** → 8.0.5 (SMTP CRLF injection fix) (PR #4643)
- ✅ **basic-ftp** → 5.2.1 (CRLF command injection fix) (PR #4634)
- ✅ **mathjs, langsmith** → latest (security vulnerability fixes) (PR #4649)

### JetEngine 3.8 MCP Server Integration (April 6, 2026) ⭐ **NEW**

**Bridges NV oOS into JetEngine 3.8's native MCP Server via JSON-RPC 2.0 with 7 new Pro tools for AI-powered site structure management** (PR #4608).

- ✅ **`WP_MCP_AI_JetEngine_MCP_Client`** — JSON-RPC 2.0 client with dual transport: `rest_do_request()` for same-site, `wp_remote_post()` for remote/multisite. Transient-based caching (configurable TTL, default 300s).
- ✅ **7 new Pro tools**: `jetengine_mcp` (bridge — discover/call any JetEngine MCP tool), `jetengine_create_post_type`, `jetengine_create_taxonomy`, `jetengine_create_meta_field` (16 field types), `jetengine_manage_relations` (1:1, 1:N, M:N), `jetengine_site_context`, `jetengine_prompts`.
- ✅ **MCP-first dispatch** — existing `WP_MCP_AI_JetEngine_Tool_Handlers::dispatch()` now tries MCP first with silent REST v2 fallback. `invoke_jetengine_route` gains `prefer_mcp` parameter.
- ✅ **Resource & prompt integration** — site structure auto-injected into AI prompts via `wp_mcp_ai_build_system_context` hook.
- ✅ **Admin UI** — MCP Server status panel (connection state, endpoint, tool count). 3 new settings: `jetengine_mcp_enabled`, `jetengine_mcp_cache_ttl`, `jetengine_mcp_context_injection`.
- ✅ **5 new test files** covering client, bridge/tools, resources, prompts, and integration.

### Agent-to-Agent (A2A) Protocol Integration (April 4, 2026) ⭐ **NEW**

**Full A2A protocol implementation — makes NV oOS assistants discoverable, addressable, and interoperable with any A2A-compliant agent** (PR #4578).

- ✅ **Agent Card & Discovery** — `/.well-known/agent.json` via rewrite rules + per-assistant cards at `GET /mcp-ai/v1/a2a/agent-card/{id}`. Builds A2A-compliant cards from assistant CPT metadata (tools → skills, auth → securitySchemes).
- ✅ **A2A Server** — JSON-RPC 2.0 dispatch for `message/send`, `message/stream`, `tasks/get`, `tasks/list`, `tasks/cancel`, push notification CRUD. State machine: `submitted → working → input-required → completed/failed/canceled/rejected`.
- ✅ **A2A Client** — discovers remote agents, sends JSON-RPC with bearer/apiKey auth, caches Agent Cards (1h TTL).
- ✅ **`delegate_to_a2a_agent` tool** — discovery → send → poll lifecycle for cross-agent delegation.
- ✅ **Push Notifications** — per-task webhook registration with exponential backoff (3 retries).
- ✅ **Admin UI** — settings for server/client enable, exposed assistants, push notifications, outbound auth. Gated by `enable_a2a_server` — zero overhead when disabled.
- ✅ **60+ unit tests** covering Agent Card generation, task state machine, message translation, push notifications, webhook handling, client utilities.

### Agent Command Center Dashboard (April 4, 2026) ⭐ **NEW**

**Unified agent management dashboard with 7 tabs for real-time monitoring, analytics, and agent strategy** (PR #4575).

- ✅ **Overview** — KPI cards (agents online, active tasks, pending approvals, tokens today, uptime %), agent cards with live online/idle/offline status dots, real-time activity feed.
- ✅ **Activity Log** — Filterable event stream (type/agent/timeframe/search) backed by `wp_mcp_ai_agent_activity_log` option (capped 1,000 events).
- ✅ **Active Tasks** — Sessions table with progress bars, workflow table from transients.
- ✅ **Approvals** — Human-in-the-loop approval cards with severity levels, approve/reject actions.
- ✅ **Analytics** — Chart.js 4.4.7: dual-axis usage timeline, token consumption doughnut, tool distribution bar, response time trends. Per-agent metrics aggregated from real hook data (PR #4593).
- ✅ **Uptime & Health** — System health card checking cron/DB/REST API/SSE, 30-day uptime bar chart.
- ✅ **Strategy** — Composite efficiency score (utilization, reliability, efficiency, coverage), recommendation engine, capacity gauges.

### Floating Chat Bubble Widget — Elementor + Gutenberg (April 3, 2026) ⭐ **NEW**

**Configurable floating chat bubble that opens a chat panel powered by the `[mcp_ai_chat]` shortcode** (PR #4566).

- ✅ **Elementor widget** — 5 control sections: Chat Settings, Bubble Settings (position/size/animation/tooltip/badge/auto-open), Panel Settings (title/width/height), Bubble Style, Panel Style.
- ✅ **Gutenberg block** — Dynamic block with 20 attributes, server-side render, `mcp-ai-wpoos` textdomain.
- ✅ **Assets** — BEM-structured CSS with 4 position variants, 3 sizes, bounce/pulse animations, notification badge, full-screen mobile (<480px), dark mode, `prefers-reduced-motion`, WCAG focus states. Vanilla JS IIFE with multi-instance registry, keyboard nav (Escape/Enter/Space), auto-open delay, sessionStorage persistence.

### Anthropic & Gemini Provider — Subscription Tier Support (April 3, 2026) ⭐ **NEW**

**Centralized request headers, custom base URLs, and API key type selectors for Anthropic and Gemini providers** (PR #4567).

- ✅ **Anthropic** — `build_request_headers()`, `resolve_endpoint()`, `get_base_url()`. Settings: `anthropic_api_key_type` (standard/team/enterprise), `anthropic_base_url`. Filter: `wp_mcp_ai_anthropic_request_headers`.
- ✅ **Gemini** — `build_request_headers()`, `resolve_endpoint()`, `get_custom_base_url()`. Settings: `gemini_api_key_type` (standard/business/enterprise), `gemini_base_url`. Filter: `wp_mcp_ai_gemini_request_headers`.
- ✅ **20 tests** covering header building, endpoint resolution, settings persistence, and filter hooks.

### ECA Pro Toolkit Enhancement — 24 New Tools (April 3, 2026) ⭐ **NEW**

**Comprehensive enhancement with 24 new tools across 8 categories plus 4 upgraded existing tools** (PR #4568).

- ✅ **Attendance & Participation** (3): `mark_eca_attendance`, `get_eca_attendance_report`, `get_student_participation_summary`.
- ✅ **Waitlist & Enrollment** (3): `manage_eca_waitlist`, `withdraw_student_eca`, `bulk_enroll_students`.
- ✅ **Scheduling & Conflicts** (3): `check_eca_conflicts`, `set_eca_schedule`, `get_eca_timetable`.
- ✅ **Notifications** (3): `send_eca_notification`, `configure_eca_notifications`, `send_eca_parent_report`.
- ✅ **Reporting & Analytics** (3): `generate_eca_analytics`, `generate_eca_participation_report`, `export_eca_data`.
- ✅ **Integration** (3): `sync_eca_enrollments_from_isams`, `sync_ecas_to_isams`, `sync_ecas_from_socs`.
- ✅ **Workflow & Lifecycle** (3): `manage_eca_term`, `create_eca_workflow_rule`, `import_ecas_csv`.
- ✅ **4 upgraded tools**: `get_eca`, `update_eca`, `delete_eca`, `list_ecas` with consistent fields, audit trails, and N+1 query fixes.

### Image Validation Tools — Product & Vehicle (April 4, 2026) ⭐ **NEW**

**AI Vision–powered image validation with industry-standard weighted quality ratings (0–100, A–F)** (PR #4585).

- ✅ **`validate_image_for_product`** — Two-pass validation (technical + AI vision) for 9 product types (watch, bracelet, ring, earring, necklace, glasses, hat, bag, general). 10-category weighted rating derived from VTO industry benchmarks.
- ✅ **`validate_image_for_vehicle`** — Supports cleaning and repair estimate types with separate weight profiles. Coverage completeness scoring tracks required/recommended views. Weights derived from Qapter/Solera, Ravin AI, CCC ONE protocols.
- ✅ **29 tests** covering schema, constants, auth, registration, views, and weights.

### Enterprise TMA Templates (April 4, 2026) ⭐ **NEW**

**5 inline Telegram Mini App templates upgraded to enterprise quality with standardized 5-tab architecture** (PR #4586).

- ✅ **E-Commerce** (166→758 lines) — Products with stock/sale badges, localStorage cart with qty controls, order history with Chart.js spending chart, AI Shopping Assistant, Settings.
- ✅ **CRM** (135→697 lines) — Contact list with search, horizontal-scroll kanban pipeline, Chart.js deal breakdown, AI Sales Coach, Settings.
- ✅ **Analytics** (111→649 lines) — KPIs with trend indicators, 7/14/30/90d period selector, content stats, traffic charts, AI Insights, Settings.
- ✅ **Booking** (204→741 lines) — Calendar/slot/form/confirm flow + Upcoming/History views, Chart.js bookings chart, AI scheduling assistant, Settings.
- ✅ **AI Chat** (136→560 lines) — Enhanced chat with markdown, Tools tab (browse/search available tools), Settings.

### New Shopify Shop TMA & Shopify Jewelry Fixes (April 5, 2026) ⭐ **NEW**

**New general-purpose Shopify e-commerce mini app + critical fixes to existing Shopify Jewelry TMA** (PR #4602).

- ✅ **`tma-shopify-shop`** (24 files) — Catalog with collection filters, debounced search, skeleton loading. Product detail with variant selector & image gallery. Cart with `useReducer` + sessionStorage. Checkout via AI assistant. Orders with status badges. Full AI chat interface.
- ✅ **Shopify Jewelry fixes** — `executeTool()` corrected from `{tool}` to `{slug}`, `extractProducts()`/`extractOrders()` fixed for `raw?.result?.products` response shape, `TMAContext.jsx` now calls `validateInitData()` with `authReady` gate.

### Per-Connection TMA URL Routing for Multi-Bot Telegram (April 5, 2026) ⭐ **NEW**

**Each Telegram bot gets its own Mini App URL — fixes HMAC validation failures in multi-bot setups** (PR #4588).

- ✅ Per-connection endpoints: `GET /wp-json/mcp-ai/v1/telegram-mini-app/{connection_id}` and all 11 sub-endpoints mirrored.
- ✅ Admin UI updated to show per-connection Mini App URLs.
- ✅ Global endpoint unchanged — single-bot setups unaffected.

### Agent Workflow Presets + Chat UI Sub-Agent Panel (April 4, 2026) ⭐ **NEW**

**5 new multi-agent orchestration presets and real-time sub-agent visibility in the chat UI** (PR #4580).

- ✅ **5 new presets**: `agent_supervisor`, `agent_pipeline`, `agent_swarm`, `agent_hierarchical`, `agent_review_qa` — aligned with standard multi-agent patterns.
- ✅ **Chat UI agent team panel** — Collapsible panel with agent cards (status dots, role, current task), workflow tracker (progress bar + ordered step list), delegation notices for `delegate_to_agent`/`delegate_to_a2a_agent`.

### Security Hardening (April 4, 2026) 🔒

**SQL query hardening, guest token expiration fix, output escaping improvements** (PR #4574).

- ✅ **SQL hardening** — `$wpdb->dbname` interpolation replaced with `$wpdb->prepare('%s', DB_NAME)`. `esc_sql()` on table names in 5 files. Pre-prepared `$where` fragment elimination.
- ✅ **Guest token TTL** — `guest_token_lifetime` setting now wired to actual token system. Absolute max TTL (7 days) prevents indefinite renewal.
- ✅ **Output escaping** — Shortcode `echo $assistant_content` → `echo wp_kses_post($assistant_content)`. Removed `urldecode()` after `sanitize_text_field()`.
- ✅ **Security hardening proposal** — `docs/project/proposals/SECURITY_HARDENING_PROPOSAL.md` with P1–P3 roadmap.

### Schedule Preset Install Overrides (April 5, 2026) 🔧 **UPDATED**

**`assistant_run` and `channel_broadcast` presets can now receive site-specific values at install time** (PR #4603).

- ✅ `install_preset()` accepts optional 3rd param `$overrides` (e.g., `assistant_id`, `credentials`).
- ✅ Frontend prompts for assistant selection or credentials JSON on install.
- ✅ 4 new tests for both types with and without overrides.

### Bug Fixes & Reliability Improvements (April 2–6, 2026) 🔧

- ✅ **`execute()` signature compatibility** (PR #4609): Fixed all 7 JetEngine MCP tool classes — added `= array()` defaults to match `WP_MCP_AI_Tool_Interface`.
- ✅ **Analytics tab real data** (PR #4593): Hook name mismatch (`wp_mcp_ai_tool_executed` → `wp_mcp_ai_after_tool_execution`) fixed. Per-agent metric tracking now aggregates real data instead of hardcoded zeros.
- ✅ **Activity log timestamp parsing** (PR #4579): Fixed empty activity tab in Agent Command Center.
- ✅ **Chart.js height bug** (PR #4576): Fixed chart rendering in Command Center analytics.
- ✅ **TMA React imports** (PRs #4596, #4598): React SPAs import from `react` directly instead of `@wordpress/element` to prevent crashes in Telegram WebView.
- ✅ **TMA e-commerce auth race condition** (PR #4590): Fixed woo-shop crash due to missing auth flow.
- ✅ **TMA session auth & param routing** (PR #4599): Fixed session authentication and remote connection parameter routing.
- ✅ **TMA haptic feedback API** (PRs #4592, #4595): Fixed haptic API misuse and tools/execute 500 errors.
- ✅ **Shopify data source config** (PRs #4605, #4606): Toggle visibility and save persistence for Shopify data source picker.
- ✅ **Shopify TMA white screen** (PR #4607): Fixed white screen in Shopify TMA templates.
- ✅ **TMA subscriber permissions** (PR #4604): TMA subscriber users can now access remote WooCommerce products.
- ✅ **Dashboard default page** (PR #4591): Dashboard is now the default page for ECA section.
- ✅ **Task plans in Command Center** (PR #4583): Fixed missing task plans from tasks tab.
- ✅ **Model pricing auto-update** (PR #4565): Fixed for models missing from CCT database.
- ✅ **Lodash security vulnerabilities** (PR #4564): Fixed in pro addon.
- ✅ **JS lint** (PR #4600): Re-applied JS lint fixes with audit-informed unused var handling.

### Vehicle Estimation Tools — VIN Decode, Repair & Cleaning Estimates (March 31, 2026) ⭐ **NEW**

**3 always-available Pro tools for automotive estimation** (PR #4526).

- ✅ **`vin_decode`** — ISO 3779 check-digit validation, NHTSA vPIC API decode with 24h transient cache, returns 28-field vehicle descriptor (year/make/model/trim/body/engine/ADAS features).
- ✅ **`vehicle_repair_estimate`** — 5-step pipeline: image intake → VIN identification → damage analysis → price-sheet mapping → estimate generation. 4-path vehicle ID (direct VIN, VIN image OCR, manual overrides, LLM visual recognition). Heuristic fallback costs for 20+ common parts; ADAS calibration auto-added for 2018+ windshield replacements.
- ✅ **`vehicle_cleaning_estimate`** — Car wash package & add-on pricing engine. Probabilistic LLM vision vehicle size classification into 3 tiers. 4 packages with size-tiered pricing, 7 specialty add-ons with severity/size/flat pricing modes. Custom menu support via JSON attachment and `wp_mcp_ai_vehicle_cleaning_menu` filter.
- ✅ **60 PHPUnit tests** covering all three tools, input validation, permission checks, domain constants, pricing scenarios.

### Shopify Connection Auto-Resolve + `remote_shopify_connection` Tool (March 31, 2026) ⭐ **NEW**

**Shopify tools no longer require manual `connection_id` — auto-resolved from assistant context** (PR #4521).

- ✅ **`WP_MCP_AI_Shopify_Connection_Resolver` trait** — reusable connection resolution logic (mirrors `remote_wp_connection` patterns): queries assistant's `_wp_mcp_ai_pro_remote_connections` meta, auto-selects if exactly one Shopify connection matches, returns helpful error with available connections if multiple.
- ✅ **All 5 Shopify tools updated** (`shopify_products`, `shopify_orders`, `shopify_customers`, `shopify_inventory`, `shopify_catalog`) — `connection_id` now optional, auto-resolved from assistant context.
- ✅ **New `remote_shopify_connection` tool** — list available Shopify connections and test connectivity via Admin GraphQL `{ shop { name } }` query.
- ✅ **14 PHPUnit tests** covering auto-resolution, explicit `connection_id`, assistant filtering, disabled/unauthorized connections.

### Webhook Status Admin Page (March 31, 2026) ⭐ **NEW**

**Centralized webhook monitoring for multi-bot Telegram setups and all 9 webhook-capable connection types** (PR #4517).

- ✅ **New admin page**: `Webhook Status` submenu under NV oOS Pro (`nvoos-pro-webhook-status`). Covers Telegram, WhatsApp, Slack, Discord, MS Teams, Messenger, Google Chat, Twitter/X, Apple Messages.
- ✅ **Summary cards** — total endpoints, active, inactive, Telegram bot count.
- ✅ **Connections table** — type badge, enabled state, health indicator, expected vs actual URL, error details, action buttons.
- ✅ **Telegram live checks** — calls `getMe` + `getWebhookInfo` APIs per-connection; reports URL match/mismatch, pending updates, last error with timestamp.
- ✅ **Actions** — Check single, Check All, Set Webhook (Telegram), Remove Webhook (Telegram), link to Edit Connection.
- ✅ **30 PHPUnit tests** covering helpers, URL generation, status checks, menu/AJAX registration, rendering.

### Transformers.js Upgrade — v3.8.1, WebGPU Acceleration & Qwen3 Models (March 30, 2026) ⭐ **NEW**

**CDN upgraded from deprecated `@xenova/transformers@2.17.2` to `@huggingface/transformers@3.8.1`; 4 new Qwen3 embedded models** (PR #4514).

- ✅ **WebGPU auto-detect**: `navigator.gpu.requestAdapter()` for up to 4× faster embeddings, WASM fallback.
- ✅ **Quantization API**: `quantized: true` (v2) → `dtype: 'q8'` (v3).
- ✅ **4 Qwen3 models** added to JS `AVAILABLE_MODELS` and PHP `get_embedded_models()`: Qwen3-8B (5 GB, function calling ✅), Qwen3-4B (2.5 GB ✅), Qwen3-1.7B (1.1 GB ✅), Qwen3-0.6B (400 MB, no function calling).

### QuickBooks Desktop Sync Tool via QODBC (March 30, 2026) ⭐ **NEW**

**New Pro tool for syncing QuickBooks Desktop data through a QODBC relay API** (PR #4507).

- ✅ **`quickbooks_desktop_sync`** — relay-based tool connecting to QuickBooks Desktop via QODBC DSN on a Windows relay server.
- ✅ **New `quickbooks_desktop` remote connection type** with relay URL, API key, and DSN name fields.
- ✅ **14 PHPUnit tests** covering tool contract, sanitization, and auth enforcement.

### Listing Image Download Tools (March 29, 2026) ⭐ **NEW**

**3 new always-on Pro tools for bulk-downloading business listing images into the Media Library or ZIP** (PR #4503).

- ✅ **`download_google_maps_images`** — Places API (New), resolves `place_id` via text search, stores author attribution as post meta, max 10 images.
- ✅ **`download_facebook_page_images`** — Graph API v21.0, cursor-based pagination across 5 album types, auto-selects highest resolution.
- ✅ **`download_instagram_page_images`** — Graph API v21.0, filters by media type (image/carousel/video), expands `CAROUSEL_ALBUM` children, handles ephemeral URLs.
- ✅ Shared patterns: `media_handle_sideload()` import, optional `ZipArchive` export, `output_mode` param (`media_library`/`zip`/`both`).
- ✅ **39 PHPUnit tests** covering slug/schema/flags/rules, auth enforcement, required param validation.

### Medical Vitals Dashboard — Recent Reading Card & Multi-Range Trends (March 31, 2026) ⭐ **NEW**

**At-a-glance recency info and configurable date ranges for the Medical Vitals Telegram Mini App** (PR #4523).

- ✅ **"Most Recent Reading" card** — relative timestamp (`5m ago`, `2h ago`) and colour-coded status dots (green/orange/red) per vital. Auto-hidden when no readings exist.
- ✅ **Configurable trend range** — segmented control bar: **7D · 14D · 30D · 90D** with dynamic title, ARIA `aria-pressed` state, auto-scaling chart density.

### Registration Product Research Page — Consolidation & Bulk Import (March 31, 2026) ⭐ **NEW**

**Feature-parity rebuild of the registration product research page** (PR #4519).

- ✅ **2 new workflow tabs**: Quick Import (file upload + text paste with structured parsing) and Guided Entry (record type selector with 5 types).
- ✅ **13 document processing tools** added to AI chat: `extract_pdf_text`, `pro_pdf_document`, `merge_pdfs`, `add_watermark_to_pdf`, `excel_data_import`, etc.
- ✅ **Sidebar additions**: Product selector dropdown with brand taxonomy labels, Document Tools Info panel.
- ✅ **3 new AJAX handlers**: `handle_bulk_import()`, `handle_document_upload()`, `handle_get_product_preview()` — all with `check_ajax_referer()`, `current_user_can()`, and proper sanitization/escaping.

### 15 New Schedule Presets — CRM Email, Document Management, Upwork Freelancer (March 30, 2026) ⭐ **NEW**

**Total schedule presets: 100 → 115** (PR #4509).

- ✅ **CRM Email Correspondence** (5 presets): email correspondence audit, SLA breach monitor, lead nurture digest, customer re-engagement check, deliverability report.
- ✅ **Document Management & Sharing** (5 presets): new document notification, sharing audit, version review, approval reminder, compliance check.
- ✅ **Upwork Freelancer** (5 presets, new `upwork_freelancer` toolkit): job discovery scan, job fit scoring, proposal pipeline report, client follow-up check, profile performance review.

### Author/Copyright/License Attribution (March 30, 2026) ⭐ **NEW**

**Consistent IP attribution across the entire codebase** (PR #4510).

- ✅ **2,535 PHP files**, **101 JS files**, **58 CSS files** — `@author`, `@copyright`, `@license` tags added to first docblock.
- ✅ Base plugin files: GPL-3.0-or-later; Pro addon files: Proprietary.
- ✅ Copyright year updated from `2025` → `2025-2026` in all 5 entry-point files.
- ✅ `composer.json` and `package.json` updated with structured author/homepage/support/repository fields.

### dvdoug/boxpacker Pre-Packaged in Pro Addon (March 31, 2026) 🔧 **UPDATED**

**Production autoload strategy aligned to `composer install` not `dump-autoload`** (PR #4527).

- ✅ `dvdoug/boxpacker` (`^3.12 || ^4.0`) now pre-packaged in `addons/pro/vendor/` — v3.12.1 + psr/log 3.0.2.
- ✅ All documentation updated: `dump-autoload` → `composer install --no-dev --classmap-authoritative --no-interaction`.

### Bug Fixes & Reliability Improvements (March 29–31, 2026) 🔧

- ✅ **Telegram webhook 403 on multi-bot setups** (PRs #4512, #4513, #4516, #4518): Fixed webhook URL to include `connection_id` in test/status endpoints; added REST auth bypass and admin-ajax fallback; direct array-key lookup for connection resolution.
- ✅ **Chat channels inbox — bot name display** (PRs #4522, #4524): Telegram bot `@bot_username` now shown as primary display in conversations list, thread header, and contacts table.
- ✅ **Chat channels inbox — message pagination** (PR #4525): Newest messages now appear on page 1 (was oldest-first).
- ✅ **Chat channels inbox — connection_id scoping** (PRs #4500, #4506): Message queries scoped by `connection_id` for Telegram to isolate multi-bot conversations; column migration and `SELECT *` fallback for backward compatibility.
- ✅ **Chat channels inbox — 404 on messages endpoint** (PR #4489): Fixed CCT and CPT store merge for conversations and messages.
- ✅ **Chat channels inbox — CCT/CPT store merge** (PR #4488): Merged CCT and CPT stores so conversations and messages from both backends display correctly.
- ✅ **Workflow preset data mapping** (PRs #4504, #4508): Fixed preset data so tools, arguments, and conditions populate correctly in the Pro Workflow Builder canvas.
- ✅ **Preset browser AJAX callbacks** (PR #4501): Fixed wrong callback signatures in schedule manager preset browser.
- ✅ **Quick Tool Selection Presets** (PR #4505): Added 8 missing tools to preset coverage.
- ✅ **Security — brace-expansion & serialize-javascript** (PR #4487): Fixed brace-expansion zero-step sequence DoS (CVE-2026-33750) and serialize-javascript CPU exhaustion (CVE-2026-34043); `npm audit` clean.

### Onboarding Wizard Enhancement — Preset Assistant Seeding & Accessibility (March 28, 2026) ⭐ **NEW**

**8 use-case presets that seed fully-configured assistants, WCAG 2.1 accessible, external JS, explicit completion flow.**

- ✅ **8 preset use cases**: Content Creator (✍️ 12 tools), Customer Support (🎧 8 tools), E-commerce (🛒 11 tools), SEO & Research (🔍 12 tools), Developer Copilot (💻 12 tools), Media & Creative Studio (🎨 11 tools), Site Administrator (🛡️ 13 tools), General Purpose (🤖 12 tools).
- ✅ **Assistant seeding**: Selecting presets creates real `mcp_ai_assistant` CPT posts with tools, system prompt, provider, model, and temperature — working system out of the box.
- ✅ **WCAG 2.1 accessibility**: WAI-ARIA tablist/tab/tabpanel for provider tabs, keyboard navigation (Arrow/Home/End), `aria-current="step"` progress, `aria-live="polite"` feedback regions, `focus-visible` outlines.
- ✅ **External JavaScript**: Inline scripts extracted to `assets/js/onboarding-wizard.js` with `wp_localize_script()` for i18n — CSP-compliant and cacheable.
- ✅ **Explicit completion**: Step 4 no longer auto-completes; users click "Mark Setup Complete" to finalize.
- ✅ **Copy shortcode**: One-click copy of `[mcp_ai_chat]` shortcode on the finish screen with clipboard fallback.
- ✅ **Filterable presets**: `wp_mcp_ai_onboarding_presets` filter lets addons add custom presets.
- ✅ **22 PHPUnit tests** for presets, seeding, model resolution, and accessibility.
- [Getting Started Wizard →](#-installation) · [Beginner 3-Step Install →](#-beginner-3-step-install-try-it-on-your-pc)

### Pro Schedule Manager — Full Cron-Backed Scheduler with AI Tools & Admin UI (March 26, 2026) ⭐ **NEW**

**Five schedule types, Symfony Cache/Validator, MJML email, ical + CSV export, chart.js sparkline** (PR branch `copilot/create-pro-schedule-manager`).

- ✅ **5 schedule types**: `task` (WP action hook), `workflow` (Tool Registry chain), `assistant_run` (fires `wp_mcp_ai_pro_scheduled_assistant_run`), `channel_broadcast` (Telegram/Slack/Discord/Teams/Messenger/WhatsApp), `workflow_builder` (runs a saved Pro Workflow Builder DAG).
- ✅ **Symfony Cache** — `load_schedules()` / `load_history()` cached via `WP_MCP_AI_Cache_Helper` (300 s / 60 s TTL); invalidated on every write.
- ✅ **Symfony Validator** — `notify_email` validated with `Constraints\Email`; returns `WP_Error` on invalid address before anything is persisted.
- ✅ **MJML email** — failure notifications compile a responsive MJML template via `WP_MCP_AI_MJML_Service`; falls back to inline HTML → Nodemailer → `wp_mail`.
- ✅ **iCalendar export** — `get_schedules_ical()` + `ajax_export_ical` AJAX; "Export to Calendar (.ics)" toolbar button; uses `wp_mcp_ai_ics_generate_calendar` filter (ical-generator Node service) with pure-PHP RFC 5545 fallback.
- ✅ **CSV history export** — `get_history_csv()` + `ajax_export_history_csv` AJAX; "Export CSV" button in history modal; uses `WP_MCP_AI_Contact_Importer_Service` (csv-stringify NPM) with `fputcsv` fallback.
- ✅ **chart.js sparkline** — stacked bar chart (green = success, red = failure) in run-history modal via `WP_MCP_AI_Chart_JS_Helper`; destroyed on modal close.
- ✅ **Retry logic** — 0–5 retries with configurable delay (≥ 60 s); failure notification (email + optional channel alert) after all retries exhausted.
- ✅ **JetEngine CCT persistence** — each run written to `WP_MCP_AI_Execution_History_CCT` when JetEngine is active.
- ✅ **6 new AI tools** — `create_pro_schedule`, `update_pro_schedule`, `delete_pro_schedule`, `list_pro_schedules`, `get_schedule_run_history`, `schedule_channel_broadcast`.
- ✅ **Full admin UI** — schedule table with enable toggles + manual trigger + "Export to Calendar" button; type-switching create form; run-history modal with chart + Export CSV; edit modal.
- [Pro Schedule Manager →](docs/features/pro-schedule-manager.md)

### NV oOS Canvas Addon — Platform-Specific ZIP for Tesseract PDF OCR (March 25, 2026) ⭐ **NEW**

**Canvas native binaries are now distributed as a separate, optional WordPress plugin — the Pro ZIP stays lean** (PR #4441, #4442).

- ✅ New `addons/canvas/nvoos-canvas` standalone plugin delivers the platform-specific `canvas` npm binary (Linux-only, ~50 MB compressed).
- ✅ Two platform ZIPs built by CI and committed to `build/`:
  - `nvoos-canvas-linux-x64.zip` — x86-64 Linux servers
  - `nvoos-canvas-linux-arm64.zip` — ARM64 (AWS Graviton, Raspberry Pi)
- ✅ OCR service detects the addon path via `NVOOS_CANVAS_PATH` env var; falls back to `node_modules` if the addon is absent.
- ✅ Base + Pro ZIP unchanged at ~33 MB; canvas is an optional post-install step, only needed for Tesseract PDF OCR on Linux servers.
- ✅ Canvas admin install hint updated to `npm install canvas@2` (canvas v3 requires Node ≥20.9.0; v2 supports Node 18+); includes EACCES workaround for shared hosts.

### Embedded LLM — Server-Side Client Moved to Pro Addon (March 25, 2026) 🔧 **UPDATED**

**`WP_MCP_AI_Embedded_Client` (llama.cpp/GGUF inference) is now a Pro-only class** (PR #4433, #4434).

- ✅ Relocated from `includes/` to `addons/pro/includes/` — class definitions no longer loaded by the base plugin.
- ✅ Base plugin's language model router uses `class_exists()` guard and falls back gracefully when Pro is absent.
- ✅ `enable_embedded` field shows "Auto-enabled with Pro" when Pro is active (no manual toggle required).
- ✅ Tests use `markTestSkipped()` when Pro addon is absent, keeping the base test suite clean.

### Embedded LLM — Gemma 2B Instruct & Shared Library Fixes (March 22–24, 2026) ⭐ **NEW**

**New GGUF model + comprehensive shared library reliability improvements.**

- ✅ **Gemma 2 2B Instruct** (`gemma-2-2b-it-q4_k_m`) added as the 4th server-side GGUF model; `gemma-2-2b-it-q4f16_1-MLC` set as the client-side WebLLM default.
- ✅ **SONAME symlinks**: `create_soname_symlinks()` creates `lib*.so.X → lib*.so.X.Y.Z` and `lib*.so → lib*.so.X` symlinks after binary extraction. Falls back to `copy()` when `symlink()` is blocked (Cloudways and similar managed hosts).
- ✅ **Auto-repair**: `get_shared_libs_status()` calls `create_soname_symlinks()` on every status check so existing installs self-repair on the next page load.
- ✅ **Custom filename sanitizer**: `sanitise_binary_filename()` uses `[A-Za-z0-9._-]` allowlist (not `sanitize_file_name()`) to preserve `.so.0.9.8`-style filenames that WordPress's filter could strip.
- ✅ **LD_LIBRARY_PATH**: `build_inference_command()` prepends `LD_LIBRARY_PATH` pointing to the binary directory so co-located `.so` files are always found.
- ✅ **`test_connection()` stderr fix**: Builds b8479+ write `--version` output to stderr; `run_binary()` now has a `$use_stderr_fallback` parameter so the binary is correctly detected.
- ✅ **Provider diagnostic page**: Now shows the resolved llama-cli binary path and all co-located shared library filenames.
- ✅ **Re-install button**: New **Re-install llama.cpp Binary** button in embedded provider settings for easy recovery after failed extractions.
- [Embedded LLM Setup Guide →](addons/embedded/docs/admin-guides/README.md)

### Embedded Chat Client — SSE Streaming Reliability (March 22–24, 2026) 🔧 **FIXED**

**Multiple root causes of SSE `ERR_HTTP2_PROTOCOL_ERROR` and related streaming failures resolved** (PR #4420–#4426).

- ✅ **Native fetch for SSE**: `chat.js` now uses `fetch + ReadableStream` for server-side embedded requests, bypassing Ky's 30 s AbortController timeout that was killing slow llama-cli inference.
- ✅ **PHP SSE headers**: `send_sse_headers()` now disables `zlib.output_compression`, calls `ob_end_clean()`, and uses `wp_die()` instead of bare `exit()` — prevents PHP-FPM/nginx from sending HTTP/2 `RST_STREAM` after the response.
- ✅ **`max_tokens` from orchestration layer**: Shortcode now injects `max_tokens` from `WP_MCP_AI_Resource_Manager`; the WebLLM path no longer falls back to a hardcoded `2048`.
- ✅ **Elementor streaming toggle**: `enable_streaming` attribute is always emitted (as `"true"` or `"false"`) so disabling streaming in the Elementor widget actually takes effect.
- ✅ **Message bubble interactions**: `disableForm()` now only disables the input area and send button during streaming — copy, speech, save, and delete buttons on already-rendered messages stay clickable.
- ✅ **WebLLM class definition deferred**: `WebLLMFunctionCallingClient` is now defined inside `waitForDependencies().then()` so `extends window.WP_MCP_AI_EmbeddedLLM` evaluates after the dependency loads.

### Five New Pro WP-CLI Command Groups (March 23, 2026) ⭐ **NEW**

**Pro addon now includes five additional WP-CLI command groups** (PR #4418).

| Command | Description |
|---------|-------------|
| `wp mcp-ai pro status` | Display Pro addon version, license status, and active toolkit summary |
| `wp mcp-ai toolkit list/enable/disable` | Manage Pro toolkits from the command line |
| `wp mcp-ai connection list/get/test/delete` | Manage Chat Channel connection entries |
| `wp mcp-ai project list/get/create/delete` | Manage AI project CPT entries |
| `wp mcp-ai task list/get/create/complete/delete` | Manage AI task CPT entries |

All commands share the `WP_MCP_AI_Pro_CLI_Base_Command` base class with assertion helpers. Tests in `addons/pro/tests/test-wp-cli-pro-commands.php`.

### Mailgun & Brevo Email Integrations (March 22, 2026) ⭐ **NEW**

**Two new Pro email toolkits for transactional and marketing email** (PR #4408).

**Mailgun** (`send_mailgun_email`):
- ✅ US endpoint: `api.mailgun.net/v3/{domain}/messages` | EU: `api.eu.mailgun.net/v3/{domain}/messages`
- ✅ Tags sent as separate `o:tag` form fields (array), per Mailgun's API requirement
- ✅ Settings: `mailgun_api_key`, `mailgun_domain`, `mailgun_region` (us/eu), `mailgun_from_email`, `mailgun_from_name`

**Brevo** (formerly Sendinblue):
- ✅ `send_brevo_email` — transactional email via `api-key` header auth
- ✅ `manage_brevo_contacts` — create, update, and list contacts
- ✅ `get_brevo_statistics` — campaign and contact analytics
- ✅ Base URL: `https://api.brevo.com/v3` | Settings: `brevo_api_key`, `brevo_from_email`, `brevo_from_name`

### Bug Fixes & Quality Improvements (March 22–25, 2026) 🔧

- ✅ **Agentic loop — orphaned `tool_calls`** (PR #4430): Fixed OpenAI error "tool_call_id did not have response messages" that appeared when `max_iterations` was reached mid-tool-call. Orphaned assistant messages with unexecuted `tool_calls` are now filtered out of history before the next user turn.
- ✅ **DICOM imaging — UID path sanitization** (PR #4406): `sanitize_uid_for_path()` now used for DICOM UIDs instead of WordPress's `sanitize_file_name()`, which applies a filterable hook that could strip dots and collapse distinct UIDs to the same directory.
- ✅ **Ollama client logging** (PR #4429): Added `WP_MCP_AI_Logger` to all 5 previously-unlogged Ollama client methods. All concrete AI chat clients now have full logging coverage.
- ✅ **Pro Workflow Builder assets** (PR #4443): Fixed `webpack.config.workflow.js` output path and entry name; fixed CI not committing freshly-built `workflow-builder` artifacts.
- ✅ **WP.org compliance — Pro addon confirmation** (PR #4435): Nine surface-level items that implied Pro "unlocks" base features were corrected; architecture confirmed as a genuine extension (all tools in `includes/tools/` register unconditionally).
- ✅ **Production classmap autoloader** (PR #4436): Regenerated `vendor/composer/` autoloader to match HEAD — production clones no longer require a manual `composer install` step.

### NPM Packages – Zero-Config Publish for All 9 Packages (March 2026) ⭐ **NEW**

**All nine standalone NPM packages extracted from the oOS chat UI can now be published to the NPM registry with zero per-package configuration** (PR #4364).

- ✅ **9 packages** under the `@nvdigitalsolutions` scope — `nvoos-storage`, `nvoos-markdown`, `nvoos-events`, `nvoos-http-client`, `nvoos-clipboard`, `nvoos-offline-sync`, `nvoos-slash-commands`, `nvoos-audio`, `nvoos-dom-batcher`
- ✅ **Two GitHub Actions workflows** for stable (`v*.*.*`) and alpha (`v*.*.*-alpha.*`) releases
- ✅ **Single source of truth** — the `PACKAGES` env var in each workflow controls all packages; adding a new one requires editing one line
- ✅ **CI steps per package**: version bump → build (`node adapt-for-npm.js`) → syntax check → publish
- ✅ **Setup**: only an `NPM_TOKEN` repository secret is required; no per-package config needed
- [NPM Packages →](packages/README.md) | [Quick Start →](packages/QUICK_START.md)

### Telegram Mini App – Member Loading Fix & Role-Based Access (March 2026) ⭐ **NEW**

**Health & Wellness and Medical Vitals templates now reliably load member CPT data**

- ✅ **Server-side member pre-selection**: When a logged-in WordPress subscriber opens a health mini app template, their linked `mcp_ai_member` post is resolved server-side and injected into the page. The member picker overlay is bypassed entirely — the dashboard loads immediately without the user having to select themselves.
- ✅ **Auto-select single member**: When `list_members` returns exactly one result (e.g. first Telegram session after creating a profile), the picker auto-selects that member and closes without requiring a manual tap.
- ✅ **Role-based member visibility** — `list_members` tool now enforces:
  - **Subscribers** (`read` only): see only members they authored — their own profiles.
  - **Authors / Editors / Admins** (`edit_posts`+): see **all** members across the site for care-team management.
- ✅ **Retry button on auth failure**: If the member list fetch fails (e.g. network error or unauthenticated first load outside Telegram), a **Retry** button replaces the infinite "Loading…" spinner so users are never stuck.
- ✅ `wp_mcp_ai_get_member_id_by_user_id()` updated to return `0` for users above subscriber level so higher-role users always get the full member picker.
- [Template developer reference →](docs/features/telegram-mini-app-templates.md)

### WordPress.org Compliance — Final Audit Complete (March 3, 2026) ✅ **FULLY COMPLIANT**

**All 20 compliance categories resolved (PR #4004 + compliance audit)**

- ✅ **Output escaping audit**: Added `esc_attr()` to 5 unescaped CSS class attribute echoes in admin pages
- ✅ **ABSPATH guards**: Added missing `if ( ! defined( 'ABSPATH' ) ) { exit; }` to 4 PHP files
- ✅ **Menu position**: Removed last hardcoded position (85 → null) from Pro Dashboard `add_menu_page()` 
- ✅ **PR #4004 review**: Telegram Mini App media tab changes confirmed fully compliant (pathinfo() safely cast, JS uses escHtml(), CSS-only layout changes)
- ✅ **WordPress.org submission status: 100% — READY** (was 82% in January 2026)
- [Full Compliance Report →](docs/operations/compliance/WORDPRESS_ORG_COMPLIANCE_FINAL_STATUS.md)

### Telegram Mini App: File-Type Extension Badges (March 2, 2026) ⭐ **NEW**

**PR #4004 – Media tab now shows extension badges for non-renderable files**

- ✅ `.TXT`, `.PDF`, `.DOCX` etc. extension badge overlaid on file-type icon thumbnail
- ✅ New `ext` field in `handle_media()` REST response (lowercase extension via pathinfo)
- ✅ New CSS: `.tma-media-ext-badge` (monospace pill, WCAG-compliant contrast in Telegram light/dark)
- ✅ Icon layout updated to `flex-direction:column` for icon/badge vertical stacking

### Office 365 & iCloud Drive Connection Types (March 1, 2026) ⭐ **NEW**

**8 new tools across 3 new integration platforms (PR #3971)**

- ✅ **Outlook Mail**: `send_outlook_mail` (HTML/plain-text, CC support) + `get_outlook_messages` (any folder, OData filter)
- ✅ **OneDrive**: `list_onedrive_files`, `get_onedrive_file`, `upload_onedrive_file` via Microsoft Graph API
- ✅ **iCloud Drive**: `list_icloud_drive_files`, `get_icloud_drive_file`, `upload_icloud_drive_file` via HTTPS gateway
- ✅ **Admin UI**: New Office 365 and iCloud Drive config panels in NV oOS → Chat Channels Toolkit
- ✅ Chat Channels Toolkit grows to **47 tools across 11 platforms**
- [Setup Guide →](addons/pro/docs/CHAT_CHANNELS_TOOLKIT.md#office-365-setup)

### Telegram Mini App CMS (February 28, 2026) ⭐ **NEW**

**PR #3959 – Transformed Telegram Mini App from chat shell into full WordPress CMS**

- ✅ New REST endpoints for WordPress CPTs, tools, and media within the Telegram WebView
- ✅ Redesigned Mini App UI with navigation panels for content management
- ✅ Fixed Mini App stuck on "Authenticating": session token fallback auth, infinite-loop prevention, subscriber-level permission (PR #3971)
- [Chat Channels Guide →](addons/pro/docs/CHAT_CHANNELS_TOOLKIT.md)

### Discord/Telegram Reactions + Discord Voice (February 27, 2026) ⭐ **NEW**

**OpenClaw Feb 2026 parity – 3 new tools**

- ✅ `add_discord_message_reaction` – add emoji reactions to Discord messages
- ✅ `add_telegram_message_reaction` – add emoji reactions to Telegram messages (Bot API 7.0+)
- ✅ `get_discord_voice_channel_members` – list users currently in a Discord voice channel
- ✅ **Elementor Telegram Login Widget** (PR #3940) – drag-and-drop `[mcp_ai_telegram_login]` shortcode integration

### Chat Channel Connection Fixes (February 19–28, 2026)

**Comprehensive stabilization sprint across WhatsApp, Messenger, Google Chat, and inbox storage**

- ✅ **WhatsApp** (PR #3818, #3819): Fixed 403 field-permission errors on test connection
- ✅ **WhatsApp** (PR #3840): Fixed auto-reply error #133010
- ✅ **WhatsApp** (PR #3841): Fixed assistant silently ignoring real messages when App Secret not configured
- ✅ **WhatsApp** (PR #3859): AI auto-replies to group messages now route to the group thread
- ✅ **Messenger** (PR #3840): Added App ID, token generator, Test Connection, API version dropdown
- ✅ **Messenger** (PR #3958): Fixed Test Connection button failure in Messenger settings
- ✅ **Google Chat** (PR #3879): Fixed HTTP 404 on test connection, improved OAuth UX, service account key indicator
- ✅ **Google Chat** (PR #3898): Fixed auto-reply silently dropped, added thread replies, fixed OAuth welcome message
- ✅ **Google Chat**: Fixed Audience URL field not clearable (verify_token preservation bug)
- ✅ **Inbox CCT** (PR #3860): Fixed `channel_messages` / `channel_contacts` CCTs never registering — inbox messages now persist and display correctly
- ✅ **Embedded Client** (PR #3878, #3880, #3899): Fixed system prompt + professional roles not sent to LLM; fixed HTML-in-prompt silently dropping entire prompt



**Version 1.1.2 Released: Critical WordPress.org compliance updates**

**Hardcoded Admin Menu Positions Removed:**
- ✅ Removed hardcoded menu positions from 5 locations (CPTs and main menu)
- ✅ Changed from fixed positions to null for automatic positioning
- ✅ Prevents conflicts with other plugins per WordPress.org guidelines
- ✅ Affects: Assistant CPT, Team CPT, Profession CPT, AI Peer CPT, Main Admin Menu

**Pro Integration Settings Architecture:**
- ✅ Moved pro-only integration settings to pro addon
- ✅ Mailjet, Google Analytics, Yahoo Fantasy, ESPN Fantasy settings relocated
- ✅ Base plugin now only includes settings for base tools
- ✅ Better architecture: Settings match tool location
- ✅ Still WordPress.org compliant: No gating, proper separation

### JetEngine CPT/Taxonomy AI Integration (February 12, 2026) ⭐ **NEW**

**Comprehensive AI assistance for all JetEngine custom post types and taxonomies**

- ✅ **AI Assistant Metaboxes**: Automatically adds AI assistant metabox to all JetEngine CPT and taxonomy edit screens
- ✅ **Research & Add Pages**: Dedicated submenu pages for each JetEngine CPT with AI-powered content creation
- ✅ **Automatic Field Mapping**: Dynamically maps all JetEngine meta fields (text, select, media, gallery, repeater, etc.)
- ✅ **Version Compatibility**: Full support for JetEngine 3.7+ with compatibility layer
- ✅ **Settings**: Two independent toggles for metaboxes and research pages
- ✅ **Testing**: Comprehensive test suite with 100% passing tests
- [Complete Integration Guide →](docs/features/integrations/jetengine-integration-guide.md)

### Package Pre-Bundling System (February 12, 2026) ⭐ **NEW**

**Major Improvement: Vendor directory pre-bundling eliminates npm install requirement**

NV oOS Pro now pre-bundles critical npm packages in the vendor directory, dramatically simplifying deployment:

**Benefits:**
- ✅ **No npm install required** on production servers
- ✅ **Faster deployment** - packages ready out-of-the-box
- ✅ **Reduced dependencies** on external npm registry
- ✅ **Backward compatible** - falls back to node_modules if present

**Pre-Bundled Packages:**
- **Document Generation**: pdf-lib ^1.17.1, pdfkit, docx, exceljs
- **Image Processing**: qrcode, cheerio, turndown
- **Optional Advanced**: puppeteer-core ^21.0.0 (HTML rendering)

**Implementation:**
- Enhanced `copy-dependencies.js` script with 8 new package definitions
- Updated Document Generation settings page to check vendor directory first
- Automatic fallback mechanism for backward compatibility

**Files Modified:**
- `addons/pro/scripts/copy-dependencies.js` - Added document generation utility packages
- `addons/pro/includes/admin/class-wp-mcp-ai-document-generation-cpt-settings-page.php` - Enhanced package detection

[Complete February 2026 Updates →](docs/history/2026/implementations/FEBRUARY_2026_UPDATES.md)

### Product Research Page Fixes (February 10-11, 2026) ⭐ **NEW**

**Critical Fixes: Resolved rendering and tab system issues**

Multiple fixes to ensure Product Research and Consolidate pages work reliably:

**1. Admin Hook Detection Fix (Feb 10)**
- Fixed CSS/JS not loading on Product Consolidate page
- Corrected hook pattern from CPT to custom menu format
- [Detailed Documentation →](docs/history/2026/fixes/product-page-admin-hook-detection-fix-2026-02-10.md) | [Summary →](archive/2025/fixes/PRODUCT_RESEARCH_FIX_SUMMARY.md)

**2. Tab System Fix (Feb 11)**
- Fixed all workflow tabs displaying simultaneously
- Added defensive inline styles and flexible hook matching
- Enhanced CSS specificity to prevent overrides
- [Detailed Documentation →](docs/history/2026/fixes/product-research-tab-system-fix-2026-02-11.md) | [Summary →](archive/2025/fixes/PRODUCT_RESEARCH_TAB_FIX_SUMMARY.md)

**3. Additional Fixes:**
- Improved asset enqueuing priority
- Removed duplicate "Research & Add" menu item
- Fixed CSS/JS loading reliability

### Pro Workflow Builder Stability (February 4-5, 2026) ⭐ **NEW**

**Multiple React-based fixes for reliability:**
- ✅ Fixed React asset loading and initialization
- ✅ Fixed double instantiation causing duplicate DOM elements
- ✅ Fixed initialization timing race conditions
- ✅ Fixed menu placement inconsistencies
- ✅ Fixed empty page display issue

[Quick Reference →](docs/history/2026/fixes/pro-workflow-builder-fix-quick-reference-2026-02-05.md)

### OAuth & API Connection Fixes (February 3, 2026) ⭐ **NEW**

**Improved third-party integrations:**
- Fixed Google OAuth approval prompt display
- Fixed Yahoo OAuth redirect URL construction
- Fixed Mailjet API authentication handling

### E-commerce Toolkit (February 10, 2026) ⭐ **NEW**

**Now enabled by default** for new installations to reduce setup friction

### Slash Commands & Workflow System - Phase 1 & 2 Complete (February 3-4, 2026) ⭐

**Major Feature: Comprehensive slash command system for content management and pro toolkits**

**Phase 1 - Core Commands (February 3, 2026):**
- **8 Base Commands**: `/help`, `/next-task`, `/ship`, `/clean-content`, `/optimize-perf`, `/sync-docs`, `/workflow`
- **Workflow Orchestrator**: Multi-step workflow execution with state management and human-in-the-loop checkpoints
- **Command Features**: Chaining, conditional logic, parameter validation, result passing, error handling
- **Integration**: JavaScript autocomplete, REST API endpoint, WP-CLI support
- **Security**: Capability-based authorization, rate limiting (10 commands/minute), comprehensive logging
- [Slash Commands Guide →](docs/user-guides/slash-commands/SLASH_COMMANDS_GUIDE.md)

**Phase 2 - Pro Toolkit Commands (February 4, 2026):**
- **21 Specialized Commands** across 3 pro toolkits:
  - **E-commerce (6)**: `/upsell-suggest`, `/abandoned-recover`, `/ecom-analytics`, `/discount-optimize`, `/inventory-forecast`, `/customer-segment`
  - **Social Media (6)**: `/hashtag-suggest`, `/social-analytics`, `/social-schedule`, `/content-calendar`, `/competitor-track`
  - **Video Production (6)**: `/video-subtitle`, `/video-template`, `/video-analytics`, `/video-merge`, `/video-thumbnail`, `/video-compress`
- **7 Automated Workflows**: Abandoned cart recovery, multi-platform campaigns, video marketing, inventory management, social planning, video post-production
- **Test Coverage**: 50+ test methods across 4 test files (100% passing)
- [Pro Toolkit Slash Commands →](docs/user-guides/slash-commands/PRO_TOOLKIT_SLASH_COMMANDS.md)

### Chat Channels & WebChat Integration (February 2026) ⭐ **NEW**

**Production-Ready: 11 platforms + collaborative rooms with AI assistants**

**Chat Channels Toolkit (47 Tools):**
- **Telegram (4)**: Send messages, get updates, manage webhooks, add message reactions
- **WhatsApp (4)**: Send template/interactive/media messages, get message history
- **Slack (4)**: Send messages, get channels/messages, create channels
- **Discord (6)**: Send messages, get channels/messages, create channels, add reactions, voice channel members
- **Microsoft Teams (3)**: Send messages, get channels/messages
- **Facebook Messenger (3)**: Send messages, get conversations, create broadcasts
- **Apple Messages for Business (4)**: Send text/interactive/group messages, retrieve conversation history
- **Google Chat / Spaces (7)**: Send messages, list/create spaces, manage members, retrieve history
- **Twitter/X (3)**: Send/receive Direct Messages, manage Account Activity webhooks
- **Office 365 – Outlook (2)** ⭐ **NEW**: Send and retrieve Outlook mail via Microsoft Graph API
- **Office 365 – OneDrive (3)** ⭐ **NEW**: List, download, and upload OneDrive files via Microsoft Graph API
- **iCloud Drive (3)** ⭐ **NEW**: List, download, and upload iCloud Drive files via a configurable gateway
- **WebChat (1)**: `send_webchat_message` - Send to P2P WebChat rooms
- **Unified Hub (1)**: `unified_channel_broadcast` - Simultaneous multi-platform messaging
- **Admin Interface**: Comprehensive settings at NV oOS → Chat Channels Toolkit, including Office 365 and iCloud Drive configuration
- [Chat Channels Guide →](addons/pro/docs/CHAT_CHANNELS_TOOLKIT.md)

**WebChat Rooms:**
- **Custom Post Type**: `mcp_ai_webchat` for room management
- **AI Assistant Assignment**: Dedicated metabox for assigning assistants to rooms
- **Message Persistence**: JetEngine CCT integration for permanent storage
- **WebRTC Support**: Self-hosted signaling via WordPress REST API
- **4 Core Tools**: Create rooms, get messages, save messages, send messages
- **Security**: Capability-based access, nonce verification, proper sanitization
- [WebChat Assistant Assignment →](addons/pro/docs/WEBCHAT_ASSISTANT_ASSIGNMENT.md)

### Repository Organization & Documentation Cleanup (January 31, 2026) ⭐ **NEW**

**Major Cleanup: Root directory fully organized + documentation consolidated**

**Root Directory Organization:**
- Moved 5 additional documentation files from root to appropriate docs/ subdirectories:
  - `FEDERATION_SETUP_GUIDE.md` → `docs/admin-guides/` (admin setup guide)
  - `FEDERATION_DIRECTORY_DEBUG.md` → `docs/history/2026/fixes/federation/` (bug fix documentation)
  - `README-MULTI-AGENT-SYSTEM.md` → `docs/features/multi-agent/` (feature documentation)
  - `PRODUCTION_COMPOSER.md` → `docs/operations/deployment/` (deployment guide)
  - Removed duplicate `FIX_SUMMARY.md` (multiple versions exist in docs/history/2026/fixes/)
- **Root now contains only 3 essential files**: README.md, CHANGELOG.md, CONTRIBUTING.md
- All configuration files remain in root (package.json, composer.json, phpunit.xml.dist, etc.)
- Supporting documentation organized by type: guides, fixes, features, deployment
- Complete organization documented in [REPOSITORY_ORGANIZATION.md](docs/project/REPOSITORY_ORGANIZATION.md)

**Previous Cleanup (January 30, 2026):**
- Moved 7 implementation files to `docs/history/2026/january/`
- Created [PROPOSALS_COMPLETION_STATUS.md](docs/project/proposals/PROPOSALS_COMPLETION_STATUS.md)
- **64 total proposals tracked**: 18 complete (28%), 6 in progress (9%), 40 pending (63%)

**Files Changed**: 5 files moved, 3 documentation files updated  
**Impact**: Cleaner repository structure, better documentation discovery, improved maintainability

### Security Hardening & Entity Tracking (January 29, 2026)

**Major Security Update: 4 vulnerabilities resolved + comprehensive entity tracking for multi-agent workflows**

**Security Fixes (4 Critical/High Issues):**

1. **SSRF Vulnerability in Webhook Registration (Critical)** - `includes/class-wp-mcp-ai-job-notifier.php`
   - Fixed webhook URL validation to block private IP ranges (127.0.0.0/8, 10.0.0.0/8, 192.168.0.0/16, 169.254.0.0/16)
   - Blocks AWS metadata endpoint access (169.254.169.254)
   - Restricts to http/https protocols only
   - Impact: Prevents Server-Side Request Forgery attacks on internal networks

2. **Broken CSRF Protection in Cron Delete (Critical)** - `assets/js/admin-cron-manager.js`
   - Fixed AJAX refresh rendering non-functional delete links without nonce validation
   - Solution: Render complete HTML form with hidden nonce field after refresh
   - Impact: Restores delete functionality with proper CSRF protection

3. **XSS in AJAX Error Messages (High)** - `assets/js/admin-cron-manager.js`, `assets/js/admin-crawl4ai-monitor.js`
   - Fixed unescaped error messages inserted into DOM
   - All error messages now escaped before insertion
   - Impact: Eliminates XSS attack vector in admin error handling

4. **Missing Job Authorization Checks (High)** - `includes/class-wp-mcp-ai-job-notifier-rest.php`
   - Fixed users accessing other users' job data via ID enumeration
   - Implemented comprehensive multi-level authorization
   - Impact: Prevents unauthorized job data access

**Comprehensive Entity Tracking (11 Types):**
- Added `ensure_tracking_ids()` helper capturing all entity IDs in job metadata
- **Tracked Entities**: user_id, assistant_id, team_id, profession_id, agent_id, agent_role, virtual_agent_id, virtual_id, workflow_id, parent_job_id, profession_slug
- **Benefits**: Complete audit trail for multi-agent workflows; critical for debugging complex orchestrations
- Applied to all job events: started, progress, completed, failed

**Multi-Level Authorization (7 Paths):**
- Implemented `is_user_authorized_for_job()` with comprehensive ownership checks
- **Authorization Paths**: Admin capability, direct user ownership, assistant ownership, team membership, profession ownership, agent ownership, virtual agent via team
- **Benefits**: Flexible multi-tenant access control; team collaboration support; proper data isolation
- Enforced in `handle_job_status()` and `handle_job_stream()` REST endpoints

**Documentation Consolidation:**
- Moved 23 files from root to organized subdirectories
- Root now contains 6 essential docs + 2 supporting files (down from 31)
- Created comprehensive security report: [CODE_REVIEW_SECURITY_FINDINGS_2026-01-29.md](docs/operations/security/CODE_REVIEW_SECURITY_FINDINGS_2026-01-29.md)
- All implementation summaries organized in `docs/history/2026/`

**Files Changed**: 6 modified (~400 lines added)  
**Security Posture**: 100% critical/high issues resolved (2 medium remain)

### DeepSeek V4 Multi-Agent Orchestration (January 2026) ⭐ **NEW**

**Major Feature: Comprehensive multi-agent coordination framework** inspired by DeepSeek V4's orchestration patterns:

**Key Components:**
- **Agent Role System** - Four specialized roles (Planner, Executor, Critic, Specialist) with role-specific capabilities and workflows
- **Agent Team Orchestrator** - Manages team composition, coordinated workflow execution, and performance tracking (921 lines)
- **Agent Communication Service** - Structured message passing and result aggregation with 5 aggregation strategies (consensus, weighted, hierarchical, first, best)
- **Agent Coordination Tools** - Three new MCP-compliant tools:
  - `create_agent_team` - Compose multi-agent teams based on task requirements
  - `delegate_to_agent` - Delegate subtasks to specialized agents
  - `aggregate_agent_results` - Combine results from multiple agents with configurable strategies
- **Profession CPT Integration** - 8 new orchestration meta fields for agent roles, capabilities, task patterns, and performance metrics
- **Team CPT Integration** - 3 new orchestration meta fields for execution modes (single/sequential/parallel/swarm), workflow templates (JSON), and aggregation strategies
- **Orchestration Seeder** - Intelligent agent role assignment for 296 professions with WP-CLI commands (`wp profession seed-orchestration`, `wp profession orchestration-stats`)
- **Multi-Agent Workflows** - Predefined team templates for research, content, e-commerce, and development workflows
- **Implementation Status** - 85-90% complete with comprehensive test suite (12 PHPUnit tests, 9 integration tests)
- **Documentation** - Complete documentation suite (55.3KB across 6 files):
  - [DEEPSEEK-V4-README.md](docs/reference/models/DEEPSEEK-V4-README.md) - Documentation suite overview
  - [DEEPSEEK-V4-USAGE-GUIDE.md](docs/reference/models/DEEPSEEK-V4-USAGE-GUIDE.md) - Practical examples and usage patterns
  - [Multi-Agent Orchestration](docs/developer/architecture/orchestration/ORCHESTRATION-LAYER-ARCHITECTURE.md#-6-multi-agent-orchestration-deepseek-v4-inspired-enhancement) - Complete technical documentation

### Pro Toolkit Memory-Based Tracking (January 22, 2026) ⭐ **NEW**

**User-Facing Change: Replaced hard toolkit limit with transparent memory-based tracking:**
- **Previous**: Hard limit of 5 pro toolkits; checkboxes disabled when limit reached; artificial restriction
- **New**: Memory-based tracking showing estimated MB usage; no hard limits; all toolkits can be enabled
- **UI Changes**: "Pro Toolkit Memory Usage" heading displays "X MB estimated memory usage (Y toolkits enabled)" with status badges (Low/Moderate/High Usage)
- **Memory Requirements**: 20 toolkits mapped to memory usage (24 MB - 256 MB range); total 1,844 MB if all enabled
- **Status Thresholds**: Low (<500MB), Moderate (500-799MB), High (≥800MB) - informational only, no enforcement
- **Benefits**: Transparency for resource planning; flexibility without artificial limits; informed decision-making
- [Documentation →](docs/features/TOOLKIT_MEMORY_TRACKING.md)

### Social Media Analytics Tools (January 15-22, 2026) ⭐ **NEW**

**4 New Analytics Tools Added to Social Media Toolkit:**
- **Get Cross-Platform Analytics** (`get_cross_platform_analytics`) - Unified metrics dashboard aggregating data from Facebook, Instagram, Twitter, LinkedIn, and YouTube (623 lines)
- **Track Hashtag Performance** (`track_hashtag_performance`) - Hashtag analysis with reach, engagement, and trend data across all platforms (586 lines)
- **Competitor Analysis** (`analyze_competitor_social`) - Track competitor metrics and compare performance (711 lines)
- **Influencer Identification** (`identify_influencers`) - Find brand influencers based on reach and engagement criteria (759 lines)
- All tools support built-in caching (12-hour default) and comprehensive error handling
- Social Media toolkit now includes **19 tools** (15 publishing/insights tools + 4 new analytics tools)

### Cloudflare Image Generation Models (January 11, 2026)

**3 New Cloudflare Workers AI Image Models:**
- **Flux-2 Dev** (`@cf/black-forest-labs/flux-2-dev`) - Advanced image generation model
- **Leonardo AI Models**:
  - Lucid Origin (`@cf/leonardo/lucid-origin`)
  - Phoenix 1.0 (`@cf/leonardo/phoenix-1.0`)
- All models support configurable dimensions (256-2048px), diffusion steps (1-20), and guidance parameters
- Compatible with existing `cloudflareai_text_to_image` tool

### Critical Fixes & Enhancements (January 15-22, 2026)

**7 Critical Fixes Implemented:**

1. **Token Manager Save Issue (Jan 21)** - Fixed tool settings not persisting despite success messages
   - Root Cause: Triple-sanitization causing data loss
   - Impact: All tool limits, multipliers, and model preferences now save correctly
   - [Details →](docs/history/2026/fixes/token-manager-save-issue-fix-2026-01-21.md)

2. **Provider Keys Clearing (Jan 20)** - Fixed API keys being cleared on tab navigation
   - Root Cause: Double-sanitization via WordPress Settings API
   - Impact: Provider configurations persist across admin tab navigation
   - [Details →](docs/history/2026/fixes/provider-keys-clearing-fix-2026-01-20.md)

3. **Unified Team Transcripts (Jan 18)** - Fixed transcript recording for team chats
   - Root Cause: Missing pattern recognition for team member assistant IDs
   - Impact: Transcripts save for all team chat types (unified_team_*, team_*_member_*)
   - [Details →](docs/history/2026/fixes/unified-team-transcript-recording-fix-2026-01-18.md)

4. **Tool Preset Multiplier (Jan 18)** - Fixed broken "Apply Preset" button on Token Manager
   - Root Cause: Tool registry query returning empty array
   - Impact: Preset application works correctly (Conservative/Balanced/Performance/Aggressive)
   - [Details →](docs/history/2026/fixes/TOOL_PRESET_MULTIPLIER_FIX.md)

5. **HuggingFace Token Limits (Jan 17)** - Fixed Qwen3-Coder exceeding max_completion_tokens
   - Root Cause: Using deprecated `max_tokens` parameter
   - Impact: Qwen models work correctly with proper token limits
   - [Details →](docs/history/2026/fixes/huggingface-max-completion-tokens-fix-2026-01-17.md)

6. **OAuth Redirect URI (Jan 17)** - Fixed Gmail OAuth redirect_uri_mismatch errors
   - Root Cause: Inconsistent URL construction
   - Impact: OAuth flows consistent across all WordPress installations
   - [Details →](docs/history/2026/fixes/oauth-redirect-uri-mismatch-fix-2026-01-17.md)

7. **Model Dropdown Base+Pro (Jan 16)** - Fixed dropdown when base & pro plugins both active
   - Root Cause: Script localization lost with multiple plugin instances
   - Impact: Model dropdown works in all deployment modes
   - [Details →](docs/history/2026/fixes/model-dropdown-base-pro-mode-fix-2026-01-16.md)

**Pro Toolkit Infrastructure - Phase 3 Complete:**
- ✅ All 13 Pro toolkit settings pages implemented
- ✅ **13 Active Toolkits**: E-commerce (20), Social Media (15), Analytics (12), Multilingual (10), Video Production (12), Financial Planner (24), Document Generation (15), Calendar Booking (15), DJ Management (18), Image Production (15), AI Tool Builder (10), Architectural Design (16), CRM (7)
- ✅ **Total: 189 Pro toolkit tools** across 13 specialized domains
- ✅ **All "planned" toolkits implemented**: Calendar Booking, DJ Management, Image Production, and AI Tool Builder are fully functional (not planned!)
- ✅ Multi-agent functionality: Each toolkit can have dedicated AI assistant (up to 13 concurrent specialized agents)
- [Phase 3 Details →](docs/history/2026/january/PHASE_3_IMPLEMENTATION_COMPLETE.md)

**Documentation Updates:**
- ✅ Code review completed (Jan 18) - All changes production ready
- ✅ Documentation consolidation (Jan 22) - Menu fixes consolidated, TOOLKIT_MEMORY_TRACKING moved to docs/features/
- ✅ 6 detailed fix documentation files created
- [Code Review →](docs/history/2026/CODE_REVIEW_DOCUMENTATION_UPDATE_2026-01-18.md)

### Composer Autoloader Optimization (January 22, 2026)

**CRITICAL FIX:** Resolved production fatal error caused by dev dependencies in Composer autoloader:

- **Problem:** Autoloader referenced `myclabs/deep-copy` and other PHPUnit dependencies missing in production
- **Solution:** Regenerated with `--no-dev --classmap-authoritative` flags
- **Results:**
  - ✅ Fatal error eliminated
  - ✅ 71% reduction in classmap size (2000+ → 565 classes)
  - ✅ 95% reduction in vendor directory (145 MB → 7 MB)
  - ✅ ~30% faster class loading with authoritative classmap
  - ✅ All distribution packages regenerated
  
- **Documentation:** [BUILD.md](docs/project/releases/BUILD.md#troubleshooting) includes troubleshooting section for this error
- **Implementation:** Root directory cleaned - 9 planning/implementation docs moved to [`docs/history/2026/january/`](docs/history/2026/january/)

> **Repository Maintenance:** Root now contains only 6 essential markdown files: README, CHANGELOG, CONTRIBUTING, SECURITY, BUILD, and DEPENDENCIES_BUNDLING.

### Settings Management System (January 20, 2026)

**NEW:** Production-ready settings management with comprehensive backup, validation, and diagnostic tools:

- **[Settings Management Guide](docs/admin-guides/settings-management.md)** - Complete feature documentation
  - 5 new management features (Health Check, Export, Import, Clear Cache, Reset)
  - 7-step robust save process with automatic backups
  - WordPress best practices for settings storage
  - Step-by-step usage instructions
  - Troubleshooting guide and best practices
  
- **[Quick Reference Card](docs/admin-guides/settings/SETTINGS_MANAGEMENT_QUICK_REFERENCE.md)** - At-a-glance guide
  - Feature summaries and workflows
  - Security checklist and backup strategy
  - Error message reference
  - Emergency recovery procedures
  
- **[Visual UI Guide](docs/visual-guides/settings-management-ui.md)** - Interface documentation
  - ASCII art mockups of all UI states
  - User flow diagrams
  - Accessibility features
  - Screenshot capture guide
  
- **[Pro Settings & Toolkits](docs/admin-guides/pro-settings-toolkits.md)** ⭐ **NEW**
  - All 8 Pro toolkits documented (650+ tools)
  - Media Toolkit, Document Generation, Project Management
  - Places, ECA, Health & Wellness, Cloudways, AI CPT Management
  - Enable/configure instructions and performance considerations

**Key Features:**
- ✅ Health Check with 6 diagnostic tests
- ✅ Export/Import settings (JSON format)
- ✅ Automatic backups (keeps last 5)
- ✅ Clear cache functionality
- ✅ Reset to defaults
- ✅ 3-layer data protection
- ✅ Security-hardened file validation

> **📌 JANUARY 18, 2026 UPDATE:** **PR #2990 - Tool Preset Multiplier Fix** - Fixed broken "Apply Preset" button on Token Manager page. The button was silently failing to update tool multipliers when users selected presets (Conservative, Balanced, Performance, Aggressive). Root cause: `get_all_recommendations()` only queried tool registry which returned empty array. Solution: Refactored to iterate through tool categories first (200+ tools), then check registry for dynamic tools. [Fix Details](docs/history/2026/fixes/TOOL_PRESET_MULTIPLIER_FIX.md) | [Testing Plan](docs/history/2026/fixes/TOOL_PRESET_MULTIPLIER_TESTING_PLAN.md)

> **📌 JANUARY 13, 2026 UPDATE:** **PR #2883 - Gmail OAuth UX Enhancement** - Added auto-display of OAuth redirect URI in Gmail/Google Drive connection settings, eliminating `redirect_uri_mismatch` errors. Users can now copy the exact URI directly from the admin interface. [Details](docs/history/2026/fixes/gmail-oauth-fix-summary.md)

> **📌 JANUARY 13, 2026 UPDATE:** **Root Directory Organization** - Consolidated 14 markdown files from root to organized subdirectories:
> - 9 migration/implementation reports → `docs/history/2026/migrations/`
> - 2 FlowHub fixes → `docs/history/2026/fixes/`
> - 2 Gmail OAuth files (consolidated into existing docs) → `docs/history/2026/fixes/gmail-oauth-fix-summary.md`
> - 1 settings change report → `docs/history/2026/settings/`
> - 1 PHPCS tracking file → `docs/developer/`
> 
> Repository root now contains only 6 essential files: README, CHANGELOG, CONTRIBUTING, SECURITY, LICENSE, and BUILD.

> **📌 JANUARY 8, 2026 UPDATE:** [Root Directory Consolidation](docs/history/2026/ROOT-DOCS-REORGANIZATION.md) - Organized 19 temporary fix documentation files into proper documentation hierarchy. Chart.js and Pro Dashboard fixes consolidated into [single reference document](docs/history/2026/fixes/CHART-JS-PRO-DASHBOARD-CONSOLIDATION.md). Repository root now contains only essential documentation files.

> **📌 JANUARY 6, 2026 UPDATE (WEEK 2):** [Weekly Summary (Dec 30 - Jan 6)](docs/history/2026/WEEKLY_SUMMARY_2026-01-06.md) - historical compliance-posture work, Pro Dashboard modernization, PM Assistant fixes, WordPress 6.7+ compatibility, and production-ready deployment. For current compliance posture, see `docs/operations/security/HIPAA_POSTURE.md`, `docs/operations/compliance/03-wp-org-compliance.md`, and `docs/operations/compliance/WORDPRESS_ORG_COMPLIANCE_FINAL_STATUS.md`.

> **📌 DECEMBER 23, 2025 UPDATE:** [Weekly Commits Summary (Dec 16-23)](docs/history/2025/WEEKLY_COMMITS_SUMMARY_2025-12-23.md) - Complete consolidation of all changes from the past week with zero information loss.

> **For complete implementation details, see [Consolidated Implementation Summaries 2025](docs/history/2025/summaries/CONSOLIDATED_IMPLEMENTATION_SUMMARIES_2025.md)**

### 📅 Weekly Summary (Jan 30, 2026) ⭐ **LATEST**
- **Repository Organization** - Root directory cleaned: 7 implementation docs moved to `docs/history/2026/january/`, test files to `tests/manual/`, examples to `examples/`
- **Proposals Status Tracking** - Comprehensive tracking document created: 64 proposals (18 complete, 6 in progress, 40 pending)
- **Documentation Consolidation** - Root now contains only essential documentation (down from 31 files to 8 essential files)
- **Dependencies Documentation** - DEPENDENCIES_BUNDLING.md confirmed in root per stakeholder request
- **Status Updates** - Identified discrepancies in DeepSeek V4 completion status (needs reconciliation)
- **Action Items Identified** - WordPress Integration completion (42-82%), Toolkit Enhancement approval decision, Firefly III review
- [Proposals Status →](docs/project/proposals/PROPOSALS_COMPLETION_STATUS.md)

### 📅 Weekly Summary (Dec 30 - Jan 6, 2026)
- **Compliance posture documentation** - historical ISO 27001, SOC 2, and HIPAA framework work landed; current docs avoid unbacked percentage claims and point operators to `docs/operations/security/HIPAA_POSTURE.md`, `docs/operations/compliance/03-wp-org-compliance.md`, and `docs/operations/compliance/WORDPRESS_ORG_COMPLIANCE_FINAL_STATUS.md`
- **Pro Dashboard Modernization** - Singleton pattern with industry standards (lazy loading, type-safe constants)
- **PM Assistant Fixes** - 6 critical modal and chat fixes (rendering, localization, validation, diagnostics)
- **WordPress 6.7+ Compatibility** - Translation loading timing fixes
- **Text Domain Migration** - Complete migration to mcp-ai-wpoos (12,773 instances)
- **Production Ready** - Dev dependencies removed from vendor, repository ready for production clones
- **Documentation Organization** - 25 files moved to organized subdirectories
- [Full Weekly Summary →](docs/history/2026/WEEKLY_SUMMARY_2026-01-06.md)

### 🏆 ISO 27001/SOC 2/HIPAA Multi-Framework Compliance (Jan 6, 2026)
- **ISO 27001:2022** - 100% compliance achieved (83 of 83 applicable controls)
  - Phase 6 & 7 implementation: 15 controls (82% → 100%)
  - Comprehensive procedures: Acceptable Use, NDA templates, Data Masking, Equipment disposal, etc.
  - Dynamic dashboard calculations replacing hardcoded values
- **SOC 2** - 100% compliant (54 Trust Services Criteria across 5 categories)
- **HIPAA** - 98% compliant (42 of 43 Security Rule safeguards)
- **Documentation** - ~90KB across 14 comprehensive procedures with complete control mappings
- [Compliance Documentation →](docs/operations/compliance/)

### 📅 Weekly Summary (Dec 16 - 23, 2025)
- **PR #2364:** Profession model architecture improvements - Major re-architecture for proper knowledge layering
- **2 Commits Reviewed:** 300+ files changed, ~100,000 lines added
- **6 New AI Integrations:** Gemini Geospatial, OpenAI Batch API, Moderation API, GPT-5.2, GPT-Image-1.5, Symfony Process
- **11 Major Bug Fixes:** Async tools, SSE streaming, chat UI, security, authentication
- **Code Quality:** Improved to 98/100 (97.5% issue reduction)
- **40+ New Test Cases:** Comprehensive test coverage for all changes
- [Full Weekly Summary →](docs/history/2025/WEEKLY_COMMITS_SUMMARY_2025-12-23.md)

### 🌍 Gemini Geospatial API Integration (Dec 22, 2025)
- **AI-Powered Location Queries** - Natural language queries about restaurants, attractions, routes, and local information
- **Google Maps Grounding** - Access to 250M+ places database for factual, context-aware responses
- **New Tool:** `gemini_geospatial_query` - Location-based AI queries with map visualization tokens
- **Reduced Hallucinations** - Factual grounding with real-time Google Maps data
- [See CHANGELOG →](CHANGELOG.md#gemini-geospatial-api-integration-december-22-2025)

### 📦 OpenAI Batch API Integration (Dec 21, 2025)
- **50% Cost Reduction** - Asynchronous bulk operations with dedicated quota and higher rate limits
- **4 New Client Methods** - `create_batch()`, `retrieve_batch()`, `cancel_batch()`, `list_batches()`
- **4 New Tools** - Create, monitor, list, and auto-monitor batch jobs
- **Automatic Monitoring** - WordPress cron integration with email notifications
- **Use Cases** - Bulk content generation, mass embeddings, large-scale moderation, dataset processing
- [See CHANGELOG →](CHANGELOG.md#openai-batch-api-integration-december-21-2025)

### 🛡️ OpenAI Moderation API Integration (Dec 21, 2025)
- **Content Safety & Compliance** - Automated moderation for text and images
- **14 Violation Categories** - Sexual content, hate speech, harassment, self-harm, violence, illicit content
- **New Tool:** `moderate_content` - Multimodal moderation with confidence scores
- **Free API** - No token costs for moderation
- [See CHANGELOG →](CHANGELOG.md#openai-moderation-api-integration-december-21-2025)

### 🎓 IGCSE Teams Implementation (Dec 2025)
- **6 Specialized Teams** - Mathematics, Science, Humanities, Languages & Technology, Year-Level, Academic Support
- **100% Coverage** - All 13 IGCSE professions utilized across modular team structure
- **Cambridge IGCSE Alignment** - Official syllabus codes for all subjects
- **Flexible Deployment** - Modular architecture for individual or combined team usage
- [Full Details →](docs/history/2025/summaries/IGCSE_IMPLEMENTATION_SUMMARY.md)

### 🔧 Recent Bug Fixes
- **Tool Toggle Fix** - Resolved nonce mismatch preventing tool enable/disable ([Details](docs/history/2025/fixes/BUGFIX_TOOL_TOGGLE.md))
- **IGCSE Professions Seeding** - Fixed database seeding for all 13 IGCSE professions ([Details](docs/history/2025/fixes/IGCSE_PROFESSIONS_SEEDING_FIX.md))
- **Documentation Links** - Fixed 717 broken links (100% success) ([Details](docs/history/2025/summaries/LINK_FIX_SUMMARY.md))

### 📊 Code Quality (Dec 19, 2025)
- **Grade: A (98/100)** - ✅ APPROVED FOR PRODUCTION
- **96% Reduction** - Code style violations reduced from 1,026 to 40
- **Zero Vulnerabilities** - Security score 100/100
- [Full Code Review →](docs/history/2025/summaries/IMPROVEMENTS_SUMMARY.md)

---

## 🚀 Features

> **Note:** Some features require third-party plugins (WooCommerce, JetEngine, Elementor, etc.). See [🔌 What You Lose Without Third-Party Plugins](#what-you-lose-without-third-party-plugins) for details.

### Assistant & conversation tools
- 🧠 Create AI Assistants via a custom post type (`mcp_ai_assistant`)
- 👔 **Professional & Team Templates** - Deploy assistants from ~190 pre-built profession templates spanning 12 industry categories, or create entire teams of specialists with one click. Includes backend testing for professions, teams, and assistants before public deployment.
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

1. **Install a local WordPress environment** — [Local by WP Engine](https://localwp.com/) is the easiest option (one-click install, no server config). Alternatives: [XAMPP](https://www.apachefriends.org/), [MAMP](https://www.mamp.info/), or [DevKinsta](https://kinsta.com/devkinsta/).

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
2. You now have the **complete version** with all ~960 tools (~195 base + ~765 Pro; live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative)

**What you get from the repository clone:**
- ✅ Complete plugin with base + Pro features combined
- ✅ All ~960 built-in tools ready to use (~195 base + ~765 Pro; live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative)
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
- ✅ ~195 base tools (more with optional third-party plugins)
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

**✅ Still Works:** All core features, MCP server, ~195 base tools, AI conversations

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
- **[Architecture Overview](docs/developer/architecture/ARCHITECTURE.md)** - System architecture (11 providers, ~960 tool classes, 36 REST controllers)
- **[Request Flow Walkthrough](docs/developer/architecture/REQUEST-FLOW-WALKTHROUGH.md)** - End-to-end chat request lifecycle trace
- **[Quick Reference Guide](docs/QUICK_REFERENCE.md)** - Fast access to common tasks and commands

### Essential References
- **[Tool Reference](docs/reference/tools/tool-reference.md)** - All ~960 tools documented (~195 base + ~765 Pro; live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative)
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

1. **Select from ~190 pre-built professional templates** spanning 12 industry categories
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

**Available Categories (~190 professions across 12 categories):**

Methodology note: the current sanity check counts 190 profession knowledge documents; runtime availability can vary with seeders, filters, and installed features.

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

Nine standalone browser-utility packages have been extracted from the oOS chat UI and published to the NPM registry under the `@nvdigitalsolutions` scope. Each package is independently usable in any JavaScript/TypeScript project (no WordPress required).

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

### Installation

```bash
# Tier 1 — Core utilities
npm install @nvdigitalsolutions/nvoos-storage
npm install @nvdigitalsolutions/nvoos-markdown marked dompurify
npm install @nvdigitalsolutions/nvoos-events @microsoft/fetch-event-source

# Tier 2 — Extended browser utilities
npm install @nvdigitalsolutions/nvoos-http-client ky
npm install @nvdigitalsolutions/nvoos-clipboard
npm install @nvdigitalsolutions/nvoos-offline-sync

# Tier 3 — Chat UI utilities
npm install @nvdigitalsolutions/nvoos-slash-commands
npm install @nvdigitalsolutions/nvoos-audio
npm install @nvdigitalsolutions/nvoos-dom-batcher
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
