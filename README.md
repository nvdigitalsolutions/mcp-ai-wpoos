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

[![Demo NV oOS Complete](https://img.shields.io/badge/Demo_NV_oOS_Complete-Playground-blueviolet?style=for-the-badge&logo=wordpress&logoColor=white)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/nvdigitalsolutions/mcp-ai-wpoos/alpha-working/blueprints/ollama-demo.json)

**Version:** 1.1.97
**Release Date:** 2026-10-06

**See [§ Release History](#-release-history) for the 12 most recent releases; every older release is in the [Previous Releases table](#-previous-releases). Full detail: [CHANGELOG.md](CHANGELOG.md).**

**🆕 v1.1.97 Highlights:** ECC-inspired agent-harness enhancements, OOS parity gaps closed, and gateway protocol negotiation. **Harness enhancements** (#6912, Proposal 056 — all inert by default) — cascade model routing on both engines (the framework-core `CascadeRouter` + the legacy `WP_MCP_AI_Cascade_Executor` gate with Pro Jev classifier wiring), the `run_assistant_eval` + `scan_assistant_security` base tools, hook profiles (minimal/standard/strict gating), the session distiller's canonical `wp_mcp_ai_memory_stored` event, and the Pro `suggest_workflows_from_history` trace-store miner. **OOS parity** (#6913) — the destructive-ops gate no longer becomes an HTTP 500 on the OOS path (canonical 428/429 envelopes), the Output Guardrail + Citation Verifier run at the final payload on all three chat surfaces, and `bin/check-chat-parity.php` (35 → 38 features) gains a `parity-check` CI job. **Fixed** (#6914) — the MCP Gateway negotiates protocol versions (`2024-11-05` fallback), so Zed and other strict MCP clients connect. **npm** (#6907/#6908) — all 23 `packages/` are live on the public registry and the bridge's bare `npx` form resolves. Tool count: ~352 base + ~1,313 Pro (~1,665 total; +2 base +1 Pro). Stale 1.1.95 build ZIPs removed (30 files).

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
> - [Addon Inventory](docs/project/ADDON_INVENTORY.md) — what each of 30 addons does and its status
> - [Security Posture](docs/operations/security/SECURITY_POSTURE.md) — current state of all 50 audit findings
> - [Compliance Traceability](docs/operations/compliance/TRACEABILITY.md) — every .org rejection reason → commit → verification command
> - [AI-Assisted Development](docs/developer/AI_ASSISTED_DEVELOPMENT.md) — methodology, transparency, and what to scrutinize
> - [Architecture Overview](docs/developer/architecture/ARCHITECTURE.md) — component diagram and data flow

## 📑 Table of Contents

### Getting Started
- [🧩 Overview](#-overview)
- [🎯 Our Mission](#-mission-modernizing-small-to-medium-business-websites)
- [🛡️ Active Security Monitoring](#-active-security-monitoring)
- [⚠️ Warranty & Safe Use](#-warranty--safe-use)
- [🏗 System Architecture](#-system-architecture)
- [🚀 Features](#-features)
- [📦 Installation](#-installation)
  - [🌱 Try It on Your PC](#-try-it-on-your-pc)
- [🔌 What You Lose Without Third-Party Plugins](#-what-you-lose-without-third-party-plugins)
- [⚙️ Configuration Checklist](#-configuration-checklist-action-items)
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
- [🧠 Language Model Providers](#-language-model-providers-openai-gemini-anthropic-baseten-deepseek-openrouter-kimi-digitalocean-nvidia-nim-ollama-lm-studio-hugging-face-cloudflare)
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

### Changelog
- [📜 Release History](#-release-history)
- [📋 Previous Releases](#-previous-releases)

---

## 🗺 Repository Map

| Directory | Purpose |
|-----------|---------|
| `includes/` | Core plugin classes — admin, assistants, tools, services, REST, security (10 infrastructure classes), providers (~15 AI backends), harness, data, markup, measurement, skills, professions, teams, slash-commands, A2A/ACP protocols, federation, elementor, blocks, crawler, integrations |
| `lib/core/` | Framework-agnostic AI orchestration engine (nvoos/core): 32 domain contracts, 21 WordPress adapters, ChatOrchestrator, ProviderRouter, ToolRegistry, SkillRegistry — PHP 8.1+ |
| `addons/` | 28 installable addons (Pro, Chat SPA, Docs Hub, SaaS Controller, Cloud Worker, Cloudways Dashboard, Toolkit Shell, Canvas, Canvas Toolkit, Document Editor, Media Studio, Media Worker, Graphify, Comic Reader, Funiq Bridge, Fleet Operator, Algorave, Cornerstone3D, Crocoblock DS, Embedded, Fantasy Football, LibreChat, Schedule Anything Platform, Schedule Anything SPA, Tenant Router, Page Agent, Checkout API, mcp-wordpress-gateway) |
| `assets/` | Frontend JS/CSS, images, CSV templates, examples |
| `.agents/skills/` | 62 coding-time agent skills for Zed editor (20 wp-* WordPress plugin development patterns + 33 design-* skills + mcp-ai-wpoos-plugin operational guide + mcp-ai-wpoos-test-suite repair guide + mcp-ai-wpoos-updates maintenance guide + mcp-ai-wpoos-wporg-submission wp.org readiness guide + mcp-ai-wpoos-ecosystem-port port-loop guide + mcp-ai-wpoos-assistant-portability export/import guide + mcp-ai-wpoos-playground-demos Playground demo blueprint guide + mcp-ai-wpoos-dependabot-loop alert-triage guide + mcp-ai-wpoos-toolkit-audit per-toolkit hardening audit guide) |
| `.bmad/` | 6 BMAD workflow agent YAML definitions + team composition config |
| `.context/` | Subsystem context files (10 topics + 5 templates) for agent session loading |
| `plugins/` | Standalone plugins: NVOOS Content Graph, NVOOS Content Graph AI, NVOOS Content Graph AI Platform |
| `packages/` | 23 NPM packages under `@nvdigitalsolutions` scope |
| `src/` | TMA (Telegram Mini App) builders + workflow builder source |
| `shared/` | Shared source code across builds |
| `core/` | Standalone "core" distribution (`mcp-ai-wpoos-core.php`) |
| `config/` | Site blueprints |
| `examples/` | Agent and workflow example code |
| `extensions/` | Hermes WebUI dashboard extensions (fleet monitoring/control plane, backup-download, external-app-tab, mcp-tool-shortcuts) |
| `tests/` | PHPUnit test suite |
| `docs/` | Comprehensive documentation (~1,600 files across 12 directories) |
| `bin/` | Development and deployment scripts |
| `docker/` | Docker Compose configuration |
| `languages/` | Translation files (.pot/.po/.mo) |
| `patches/` | Dependency patches |
| `.github/` | CI/CD workflows (~30 pipelines), custom agents, Copilot instructions |

---

## 🧩 Overview

Real-time AI Orchestration Toolkit / Harness for Wordpress - **NV oOS** is a modular AI framework (Object-Oriented System) for WordPress that connects your site's data with 18 language-model providers: OpenAI, Gemini, Anthropic, DeepSeek, OpenRouter, Baseten, Kimi (Moonshot), Z.AI (GLM), DigitalOcean, NVIDIA NIM, Cloudflare Worker AI, Azure, Ollama, LM Studio, Hugging Face, and TypeSafe Jev (decision-only), plus local WebLLM and embedded engines.  It allows you to create and manage AI Assistants that can interact with users, access WordPress data, and perform custom tool functions.

### ✨ What's New at a Glance (v1.1.97)

- 🤖 **Agent-harness enhancements (PR #6912, Proposal 056 — all inert by default).** Cascade model routing on both engines (the framework-core `CascadeRouter` extends `ProviderRouter` and injects into the OOS engine via WordPress `CascadeClassifier`/`CascadeValidator` adapters; `WP_MCP_AI_Cascade_Executor` gates the legacy `create_chat_completion()` path — one `wp_mcp_ai_cascade_enabled` switch + Jev classifier govern both, with post-call validation + escalation completing the previously dormant tier machinery); the `run_assistant_eval` base tool (three trajectory judges + deterministic checks, +1 base); hook profiles (minimal/standard/strict gating, meta → filter → option → constant resolution, master switch + kill list); the `scan_assistant_security` base tool (OWASP-informed config audit, masked secret evidence, posture score, +1 base); the session distiller (session-end transcript → canonical `wp_mcp_ai_memory_stored` event, zero provider cost by default); and the `suggest_workflows_from_history` Pro tool (harness trace-store mining, frequency × recency × success, +1 Pro). ECC MIT attribution in `CREDITS.md`.
- 🛡 **OOS parity gaps closed (PR #6913).** The four Proposal 029 G2 guardrail gaps that blocked promoting the OOS engine switch: the destructive-ops gate no longer becomes an HTTP 500 on the OOS path (typed catch → canonical 428/429 envelopes via `WP_MCP_AI_REST::translate_gate_exception()`); `apply_pre_response_render()` runs the Output Guardrail + Citation Verifier at the final payload on all three chat surfaces; the OOS bridge fires `wp_mcp_ai_agentic_iteration_complete`; the before-tool-execute waterfall bridge gains regression tests; and `bin/check-chat-parity.php` now tracks all four surfaces (35 → 38 features, 100% parity) with a `parity-check` CI job.
- 🔧 **Gateway protocol negotiation fixed (PR #6914).** The MCP Gateway's `initialize` now negotiates the protocol version (`negotiateProtocolVersion()`, `2024-11-05` fallback) — Zed and other strict MCP clients stop aborting with "Unsupported protocol version" against the live fleet endpoint.
- 📦 **npm publish wave (PRs #6898, #6904–#6909, #6911).** All 23 `packages/` are live on the public npm registry (0.1.0-alpha.3; nvoos-api/nvoos-sse-client 0.1.0-alpha.4 after the TypeScript was stripped from their shipped JS; nvoos-mcp-bridge `latest` = 0.1.0-alpha.3 — the v1.1.96-deferred publish executed). GitHub Packages parity + the new dry-run-by-default `npm-maintenance.yml`.
- 📦 **Versioning** — bumped to **1.1.97** across all version-bearing files. Pro addon: 1.1.97. **MCP Gateway: 0.1.0** (unchanged — the negotiation fix ships on the existing line). **nvoos-mcp-bridge: 0.1.0-alpha.3** (published, `latest`). Media Worker: **3.4.0** (unchanged). Media Studio: **0.6.1** (unchanged). SaaS Controller: **0.3.0** (unchanged). Design System addon: **0.3.0** (unchanged). ChatGPT Plugin addon: **0.1.0** (unchanged). Fleet Operator: **1.0.0** (unchanged). nvoos-content-graph: **1.0.8** (unchanged). nvoos-content-graph-ai: **1.0.4** (unchanged). nvoos-content-graph-ai-platform: **2.0.0** (unchanged). nvoos-content-graph-pro: **1.0.0** (unchanged — zero `plugins/` diff in-window). Checkout API: **0.1.2** (unchanged). Docs Hub addon: **0.5.1** (unchanged). Comic Reader addon: **0.5.0** (unchanged). Model catalog: **v2026.10.03** (unchanged). Tool count: **~352 base + ~1,313 Pro (~1,665 total — +2 base +1 Pro)** — `run_assistant_eval` + `scan_assistant_security` (base) and `suggest_workflows_from_history` (Pro) from #6912. Provider count: **18** chat providers (unchanged). Addon count: **30** (unchanged). Bundled skills: **75** base + **41** Pro (unchanged). Coding-time agent skills: **62** (unchanged). Stale build ZIPs removed: the 1.1.95 oOS set (9 root + 2 optional-components + 19 toolkit-addons = 30 files).

### 🎯 Mission: Modernizing Small to Medium Business Websites

**NV oOS** is specifically designed to help **small to medium-sized businesses** fast-track their outdated, stale, or insecure company websites to modern technology standards—**without the need to add yet another wrapper around API calls**. Instead, we're trying to **peel back decades of API wrappers with the help of AI**, providing:

- **Direct AI Integration** - No middleware required. Connect directly to OpenAI, Gemini, Anthropic, Hugging Face, Cloudflare Worker AI, Ollama, LM Studio, OpenRouter, and DeepSeek without custom development
- **Security-First Architecture** - Built-in protection against nefarious usage with active monitoring and prevention systems
- **Enterprise-Grade Features** - Access to capabilities typically requiring expensive custom development
- **Compliance & Audit Tools** - Comprehensive logging, rate limiting, and usage tracking built-in
- **Zero Technical Debt** - Modern codebase following WordPress standards, ready for current technology stacks

### 🛡 Active Security Monitoring

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

### ⚠ Warranty & Safe Use

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

- **18 language-model providers** — OpenAI, Gemini, Anthropic, DeepSeek, OpenRouter, Baseten, Kimi (Moonshot), Z.AI (GLM), DigitalOcean, NVIDIA NIM, Cloudflare Worker AI, Azure, Ollama, LM Studio, Hugging Face, TypeSafe Jev (decision-only), plus local WebLLM + embedded engines
- **~1,665 tool classes** (~352 base + ~1,313 Pro; live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative) registered through a singleton Tool Registry
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

## 📜 Release History

<!-- Maintenance rule: this section keeps the 12 most recent releases in full. When a new release lands, add it here, demote the oldest entry into a one-line row of the Previous Releases table, and let CHANGELOG.md remain the complete per-release record. -->

> The 12 most recent releases are documented in full below. Every older release has a one-line summary in the [Previous Releases](#-previous-releases) table, and complete per-release detail lives in **[CHANGELOG.md](CHANGELOG.md)**.

### v1.1.97 — October 6, 2026

#### ECC-Inspired Harness Enhancements, OOS Parity Closure & Gateway Protocol Negotiation

- 🤖 **Agent-harness enhancements (PR #6912, Proposal 056 — all inert by default).** Cascade model routing on both engines — the framework-core `CascadeRouter` (extends `ProviderRouter`, injected into the OOS engine via the WordPress `CascadeClassifier`/`CascadeValidator` adapters) and the live legacy base+Pro path (`WP_MCP_AI_Cascade_Executor` gating `WP_MCP_AI_Language_Model_Router::create_chat_completion()`, with Pro Jev classifier wiring via the proposal-045 routing signal); one `wp_mcp_ai_cascade_enabled` switch + classifier/validator seam govern both paths, completing the previously dormant tier machinery with post-call validation + escalation. `run_assistant_eval` (**+1 base**) — three trajectory judges (tool selection / use / response quality) + deterministic checks + the `wp_mcp_ai_eval_judge` seam. **Hook profiles** — minimal/standard/strict gating, meta → filter → option → constant resolution, master switch + kill list (`includes/hooks/`). `scan_assistant_security` (**+1 base**) — OWASP-informed config audit with masked secret evidence + posture score. **Session distiller** — session-end transcript → canonical `wp_mcp_ai_memory_stored` event (MemPalace/CCT/Graphify/recall hydrate; zero provider cost by default). `suggest_workflows_from_history` (**+1 Pro**) — mines the harness trace store for recurring tool chains (frequency × recency × success). ECC MIT attribution in `CREDITS.md` + `@credit` tags.
- 🛡 **OOS parity gaps closed (PR #6913).** The four Proposal 029 G2 guardrail gaps that blocked promoting the OOS engine switch: the destructive-ops gate no longer becomes an HTTP 500 on the OOS path (typed catch → canonical 428 confirmation / 429 limits envelopes via `WP_MCP_AI_REST::translate_gate_exception()`); `apply_pre_response_render()` runs the Output Guardrail + Citation Verifier at the final payload on all three surfaces (legacy non-streaming, legacy streaming's final SSE event — blocked → `error` event, and the OOS handler); the OOS bridge fires `wp_mcp_ai_agentic_iteration_complete` with the same `(iteration, assistant_id)` pair; the before-tool-execute waterfall bridge gains regression tests. `bin/check-chat-parity.php` now tracks all four surfaces (**35 → 38 features, 100% parity**) with a `parity-check` CI job.
- 🔧 **Gateway protocol negotiation fixed (PR #6914).** The MCP Gateway's `initialize` no longer hardcodes `2026-07-28` — `negotiateProtocolVersion()` echoes the highest mutually supported version (`2026-07-28` → `2024-11-05`), reads `protocolVersion` + `supportedProtocolVersions`, and defaults to `2024-11-05` — so Zed and other strict MCP clients stop aborting with "Unsupported protocol version".
- 📦 **npm publish wave (PRs #6898, #6904–#6909, #6911).** The v1.1.96-deferred public npm publish is executed: all **23 `packages/`** live on the public registry at 0.1.0-alpha.3 (the bridge's `latest` = 0.1.0-alpha.3 with the bare-`npx` bin alias; `nvoos-api`/`nvoos-sse-client` republished at 0.1.0-alpha.4 after the TypeScript was stripped from their shipped JS). The publish workflow loops over all 23 packages with an optional subset input, GitHub Packages covers all 23, and the new `npm-maintenance.yml` adds dry-run-by-default `unpublish`/`dist-tag-add`.
- 📦 **Versioning** — bumped to **1.1.97** across all version-bearing files. Pro addon: 1.1.97. **MCP Gateway: 0.1.0** (unchanged — the negotiation fix ships on the existing line). **nvoos-mcp-bridge: 0.1.0-alpha.3** (published on the public npm registry, `latest`; nvoos-api/nvoos-sse-client at 0.1.0-alpha.4; the other 21 packages at 0.1.0-alpha.3). Media Worker: **3.4.0** (unchanged). Media Studio: **0.6.1** (unchanged). SaaS Controller: **0.3.0** (unchanged). Design System addon: **0.3.0** (unchanged). ChatGPT Plugin addon: **0.1.0** (unchanged). Fleet Operator: **1.0.0** (unchanged). nvoos-content-graph: **1.0.8** (unchanged). nvoos-content-graph-ai: **1.0.4** (unchanged). nvoos-content-graph-ai-platform: **2.0.0** (unchanged). nvoos-content-graph-pro: **1.0.0** (unchanged — zero `plugins/` diff in-window). Checkout API: **0.1.2** (unchanged). Docs Hub addon: **0.5.1** (unchanged). Comic Reader addon: **0.5.0** (unchanged). Model catalog: **v2026.10.03** (unchanged — zero catalog diff in-window). Tool count: **~352 base + ~1,313 Pro (~1,665 total — +2 base +1 Pro)** — `run_assistant_eval` + `scan_assistant_security` (base) and `suggest_workflows_from_history` (Pro) from #6912 (live registry authoritative; the dual-layer `lib/core` `CascadeRouter` is never counted). Provider count: **18** chat providers (unchanged). Addon count: **30** (unchanged). Bundled skills: **75** base + **41** Pro (unchanged). Coding-time agent skills: **62** (unchanged). Stale build ZIPs removed: the 1.1.95 oOS set (9 root + 2 optional-components + 19 toolkit-addons = 30 files).

### v1.1.96 — October 5, 2026

#### NV oOS MCP Bridge npx Package, MCP Gateway Addon & Per-Request Memory Cut

- 🔌 **nvoos-mcp-bridge npx package (PR #6897, Proposals 054/055).** `@nvdigitalsolutions/nvoos-mcp-bridge` — a zero-dependency stdio↔HTTP relay npm package (`nvoos-mcp` + `nvoos-mcp-ssh` bins) that connects Zed / Claude Desktop / Cursor / Codex to any NV oOS site with one `npx` command; byte-identity CI drift gate against the repo `bin/`, hermetic `node:test` suites (14/14), opt-in Docker E2E, and the public-npm publish workflow. **Fleet Operator config generators** — `generate_zed_json()` / `generate_claude_json()` emit copy-paste `context_servers` / `mcpServers` blocks through the npx package (the one-time operator result + admin page offer Zed / VS Code / Claude Desktop alongside the Hermes YAML). The public npm publish itself is deferred on the scope claim + `NPM_TOKEN`.
- 🚪 **MCP Gateway addon (PR #6897, Proposal 055 — addon 0.1.0, inventory #31).** `addons/mcp-gateway/` exposes the whole fleet as one public MCP endpoint — a media-worker-style Express service speaking stateless streamable HTTP (MCP 2026-07-28) with public API-key auth + rotation, per-key rate limits, `<site-slug>.<tool>` tool namespacing, graceful per-site degradation, and fail-closed env config (39/39 node:test suites; subtree-mirror workflow; Velocity deployment guide). The mirror repo, Velocity deployment, and directory submissions are deferred on maintainer credentials.
- 🧠 **Per-request memory cut + bootstrap hardening (PR #6896).** Plain front-end requests no longer load ~350 tool classes — the registry defers defaults until first tool access (`wp_mcp_ai_is_plugin_runtime_context()`: REST/admin/AJAX/cron/CLI load eagerly; third-party tools win slug conflicts via `ensure_default_tools_loaded()`), the OOS engine pre-warm (12 adapters + 12 provider clients + ~140 core tools) is gated off page views, and the model-catalog migration reads the ~250–400 KB JSON only on `filemtime` change. Pro blob options (`wp_mcp_ai_pro_remote_sites`, workflows, per-workflow execution logs, Ezuite SKUs, approvals, media-worker secrets, pricing buffers capped at 500, slash-command history, license key) write `autoload=false` with the one-time `wp_mcp_ai_pro_repair_option_autoload()` repair. A bootstrap-integrity guard + fail-soft loader chain (`wp_mcp_ai_require_if_exists()`) make missing files after a partial update degrade instead of fataling — skipped files recorded in a non-autoloaded option, one-time admin notice, self-healing prune; updater `VERIFY_FILES` +17 paths; new Site Health `wp_mcp_ai_file_integrity` test. Nine dead FF/Yahoo registry entries removed and the `Inline_Async_Tick_Trait` double-declaration activation fatal fixed (`trait_exists()` guard). **A/B probe: 42 MB deferred vs 74 MB eager per front-end render (~32 MB saved).**
- 🔧 **Media Worker runtime version strings fixed (PR #6895).** A deployed 3.4.0 worker no longer reports 3.3.0 — both `/api/health` `version:` fields and the `WORKER_VERSION` heartbeat constant now match `package.json` (the five-spot version-sync checklist lands in `.context/media-worker.md`).
- 📦 **Versioning** — bumped to **1.1.96** across all version-bearing files. Pro addon: 1.1.96. **Media Worker: 3.4.0** (unchanged). **Fleet Operator: 1.0.0** (unchanged — inventory-row correction from the stale 0.1.0). **MCP Gateway: 0.1.0 (new — inventory #31)**. Media Studio: **0.6.1** (unchanged). SaaS Controller: **0.3.0** (unchanged). Design System addon: **0.3.0** (unchanged). ChatGPT Plugin addon: **0.1.0** (unchanged). nvoos-content-graph: **1.0.8** (unchanged). nvoos-content-graph-ai: **1.0.4** (unchanged). nvoos-content-graph-ai-platform: **2.0.0** (unchanged). nvoos-content-graph-pro: **1.0.0** (unchanged — zero `plugins/` diff in-window). Checkout API: **0.1.2** (unchanged). Docs Hub addon: **0.5.1** (unchanged). Comic Reader addon: **0.5.0** (unchanged). Model catalog: **v2026.10.03** (unchanged). Tool count: **~350 base + ~1,312 Pro (~1,662 total — unchanged)** — no registrations in-window (live registry authoritative). Provider count: **18** chat providers (unchanged). Addon count: **30** (+1 — the MCP Gateway addon). Bundled skills: **75** base + **41** Pro (unchanged). Coding-time agent skills: **62** (unchanged). Stale build ZIPs removed: the 1.1.94 oOS set (9 root + 2 optional-components + 19 toolkit-addons = 30 files).

### v1.1.95 — October 4, 2026

#### Toolkit Hardening Wave, Real Image Processing, Toolkit Labels & SSE Keepalives

- 🖼 **Real image processing (PR #6881, issue #6877 — Media Worker 3.3.0 → 3.4.0).** The four placeholder image tools now run real processing through the media worker sidecar: Wave 1 Sharp-native `POST /api/image/enhance` + `/api/image/upscale` (factor 2/4/8, lanczos3, 8192px cap) behind the new Pro `WP_MCP_AI_Sharp_Image_Processing` trait drives `enhance_image_quality`/`upscale_image_ai` (honest `wp_mcp_ai_sharp_unavailable` fallback); Wave 2 `POST /api/image/edit` (`colorize` | `style_transfer`, Gemini → OpenAI → Replicate auto-resolution) behind the new `WP_MCP_AI_Provider_Image_Edit` trait drives `colorize_image`/`apply_artistic_style` — success meta written only on real success. All six ported files mirrored byte-identical to CG Pro; Wave 3 (Real-ESRGAN) deferred per the plan.
- 🔨 **Nine-toolkit hardening wave (PRs #6875–#6893).** Confirmed production fatals fixed across ten clusters under the new `mcp-ai-wpoos-toolkit-audit` skill: document-generation (unguarded `shell_exec()`, the nonexistent Gemini `generate_content()`, array-content flattening, the repaired fenced-JSON regex, the Anthropic OCR provider); image-production (prompt-optimizer provider bypass, Stability/sidecar guards); CRM (the Gemini `trim()` fatal, the dead `wp_mcp_ai_chat_completion()` AI path); ECA management (the `research_eca` `preg_match()` fatal); ecommerce (unguarded `exec()` → `WP_MCP_AI_ALLOW_SHELL_TOOLS` + Process Service); media (nested `WP_Error` reads, **the `year_month` directory traversal closed**, the unreferenced-media meta fail-safe); healthcare (HIPAA audit-trail rewire + OpenMed guards); shopify-sync (the `count(null)` failure-path fatal + MCP-server descriptor wiring); orchestration (envelope + parser flattening, cross-tool `is_wp_error()` guards); and the loose incident tools + shared services (never-loadable `execute()` signatures, the nonexistent Paper Store `maybe_commit()`, Qdrant/gmail/roboflow/data-quality guards). Email-marketing verified clean (tests only). All mirrors byte-identical to CG Pro where they exist.
- 🏷 **Toolkit labels (PR #6874).** `list_mcp_tools` derives toolkits for previously-unlabelled Pro tools via the new `WP_MCP_AI_Tool_Registry::get_tool_toolkit()` resolver + `wp_mcp_ai_tool_toolkit` filter; the Newsletter 9.4.6+ bootstrap fatal is fixed (loader requires it on `plugins_loaded`), and `create_company` moves to the canonical `write`/`state-changing` flags.
- 📡 **SSE keepalives (PR #6879).** Inter-step comment frames at four chat-stream boundaries keep long agentic runs alive through proxies with idle-read timeouts; the Phase 4b deadline-triggered offload ships as the new proposal doc.
- 📦 **Versioning** — bumped to **1.1.95** across all version-bearing files. Pro addon: 1.1.95. **Media Worker: 3.3.0 → 3.4.0**. Media Studio: **0.6.1** (unchanged). SaaS Controller: **0.3.0** (unchanged). Design System addon: **0.3.0** (unchanged). ChatGPT Plugin addon: **0.1.0** (unchanged). nvoos-content-graph: **1.0.8** (unchanged). nvoos-content-graph-ai: **1.0.4** (unchanged). nvoos-content-graph-ai-platform: **2.0.0** (unchanged). nvoos-content-graph-pro: **1.0.0** (unchanged — in-window byte-identical mirrors keep the sub-project track). Checkout API: **0.1.2** (unchanged). Docs Hub addon: **0.5.1** (unchanged). Comic Reader addon: **0.5.0** (unchanged). Model catalog: **v2026.10.03** (unchanged). Tool count: **~350 base + ~1,312 Pro (~1,662 total — unchanged)** — no registrations in-window. Provider count: **18** chat providers (unchanged). Addon count: **29** (unchanged). Bundled skills: **75** base + **41** Pro (unchanged). Coding-time agent skills: **62** (+1 — `mcp-ai-wpoos-toolkit-audit`). Stale build ZIPs removed: the 1.1.93 oOS set (9 root + 2 optional-components + 19 toolkit-addons = 30 files).

### v1.1.94 — October 3, 2026

#### ChatGPT Plugin Addon, Decision-Scope Guard & MCP Grant Enforcement

- 🤖 **ChatGPT plugin addon + OAuth 2.1 resource-server (PR #6871).** `addons/chatgpt-plugin/` (0.1.0) packages the site's native MCP bridge for OpenAI agent surfaces — `plugin.json` + `mcp.json` with a `.codex-plugin` overlay, three runtime skills, `bin/` stamp/package/validate scripts, subtree-split sync to the `nvoos-chatgpt-plugin` mirror. Base ships the RFC 9728 contract: `WP_MCP_AI_OAuth_Resource_Server`, `/.well-known/oauth-protected-resource` + `/.well-known/openai-apps-challenge`, 401 `WWW-Authenticate` on the MCP route, per-tool `securitySchemes` in `tools/list`, the **`nvoos_get_profile` tool (+1 base)**, and RFC-9728-resource-identifier token acceptance in the REST authenticator.
- 🛡 **Decision-scope guard (PR #6866, Proposal 052).** Every Jev dispatch declares a **domain** (`advisory`/`content`/`operations`/`verification`) and an **authority ceiling** (`inform` → `suggest` → `act`); banned domains fail closed to the deterministic fallback; `act` authority requires the `wp_mcp_ai_decision_act_domains` filter. The **`WPMCPAI.Decisions.ScopeDeclared`** sniff (severity 5) makes undeclared dispatches a CI build failure — six existing sites declared with zero behavior change.
- 🔐 **Toolkit MCP grants enforced + exposed (PR #6872).** The v1.1.88 deny-by-default grant gate now actually runs outside wp-admin (the grant-lookup class definition loads in every context) — non-granted assistant-scoped `tools/call` returns `-32601`; granted servers' tools flow onto the `wp_mcp_ai_chat_effective_tools` seam (chat payload, `tools/list`, direct execution) and the estimator counts them via `wp_mcp_ai_prompt_window_toolkit_tool_slugs`.
- ⚖ **OOS engine chat parity (PR #6872).** The OOS chat path runs the same `wp_mcp_ai_attention_tool_slugs` → `wp_mcp_ai_chat_effective_tools` pipeline as the legacy path, and the WordPress `EventDispatcher` translates domain events into the documented legacy `wp_mcp_ai_*` argument tuples — fixing the `ArgumentCountError` → HTTP 500 on every OOS chat.
- 🔌 **FlowHub MCP OAuth login proxy (PR #6867).** `get_oauth_client_options()` resolves the connection proxy (via `connection_ref` or gateway-URL match) and persists it in the OAuth flow state — discovery, DCR, token exchange, refresh, and revoke all ride the proxy.
- 🔍 **Safe-mode REST error masking fixed (PR #6869, closes #6860).** Admins get `?verbose_errors=1` per-request detail; every masked response carries a 10-char correlation `ref` backed by a server-side Recent Errors entry; the original HTTP status is preserved. Ported byte-identical to the Content Graph AI `RequestGuard`.
- 🏥 **Site Health fatal + false positives fixed (PR #6870).** `get_site_health` pre-loads `wp-admin/includes/misc.php` before the `wp_check_php_version()` polyfill (no more redeclare fatal on WP 6.9); the database-schema check verifies `wp_mcp_ai_activated_version`; cold analytics caches no longer warn at 0% hit rate. Docker dev environment hardening (in-container port 8000, `DISABLE_WP_CRON` + cron loop, PHP 8.3 images, `WP_DEBUG_LOG` outside the webroot).
- 📦 **Versioning** — bumped to **1.1.94** across all version-bearing files. Pro addon: 1.1.94. **ChatGPT Plugin addon: 0.1.0 (new — inventory #30)**. Media Worker: **3.3.0** (unchanged). Media Studio: **0.6.1** (unchanged). SaaS Controller: **0.3.0** (unchanged). Design System addon: **0.3.0** (unchanged). nvoos-content-graph: **1.0.8** (unchanged). nvoos-content-graph-ai: **1.0.4** (unchanged — in-window RequestGuard/GetSiteHealthTool/OosShadowRunner ports keep the sub-project track). nvoos-content-graph-ai-platform: **2.0.0** (unchanged). nvoos-content-graph-pro: **1.0.0** (unchanged — no port waves in-window). Checkout API: **0.1.2** (unchanged). Docs Hub addon: **0.5.1** (unchanged). Comic Reader addon: **0.5.0** (unchanged). Model catalog: **v2026.10.03** (unchanged — #6865 is test-drift-only). Tool count: **~350 base + ~1,312 Pro (~1,662 total — +1 base)** — `nvoos_get_profile` (live registry authoritative). Provider count: **18** chat providers (unchanged). Addon count: **29** (+1 — the ChatGPT Plugin addon). Bundled skills: **75** base + **41** Pro (unchanged). Coding-time agent skills: **61** (unchanged). Stale build ZIPs removed: the 1.1.92 oOS set (9 root + 2 optional-components + 19 toolkit-addons = 30 files).

### v1.1.93 — October 3, 2026

#### Fleet Status Monitoring, WP-CLI Parity, FlowHub MCP Apps & October Model Catalog

- 📡 **Media worker fleet status monitoring (PR #6849 — worker 3.2.0 → 3.3.0).** Opt-in (`STATUS_ENABLED=1`) status module: heartbeat validation, `at_risk` → `major_outage` state machine, Redis/in-memory store, dead man's switch sweeper, synthetic HTTP/TLS checks through the SSRF guard, HMAC-signed webhook + email alerts, OpenMetrics, public status page. Plugin half: `WP_MCP_AI_Media_Worker_Config`, heartbeat emitter, alert poller, `remote_monitor` source, **2 new base tools** (`get_fleet_status`, `get_site_uptime`), `GET /mcp-ai/v1/status/sites`, `/status fleet` slash sub-command; Pro incident auto-create/resolve + Status Dashboard Fleet section.
- 🖥 **WP-CLI parity & hardening (PR #6852, Proposal 050).** `includes/cli/` extraction with `manage_options` gating, `wp mcp-ai tool call`, new `security`/`model` Base commands + nine Pro command trees (`crm`, `incident`, `maintenance`, `schedule`, `workflow`, `vault`, `remote-site`, `communication`, `media-studio`), `--fields`/`--format=count`, canonical `mcp-ai` namespaces with legacy aliases, `docs/operations/wp-cli.md`, WP-CLI 2.9+.
- 🔌 **FlowHub MCP Apps (PRs #6854, #6861).** FlowHub MCP connections join the assistant MCP Apps "Add from Remote Sites" dropdown with the OAuth login flow and chat-time `connection_ref` resolution; the connection's proxy now carries into every MCP Apps gateway request (`proxy_url`/`proxy_auth` via `http_api_curl`, OAuth call sites included) — no direct egress needed on geo-blocked deployments.
- 💹 **Financial Planner OpenStock parity (PR #6857, Proposal 051, B1–B3).** `WP_MCP_AI_Finnhub_Provider` (BYO-key, rotation on 401/403/429, SWR cache, `cached`/`realtime` modes) at the yfinance filter seam; **+3 Pro tools** (`watchlist_sync`, `market_overview_widget`, the previously orphaned `import_financial_planning_blueprint` registered); `price_alerts` data-mode parity; `openstock-market-analyst` blueprint; masked key inputs.
- 🧠 **October 2026 model catalog (PR #6863).** Catalog **v2026.10.03** — 238 models, **18 providers (Z.AI joins)**: `gpt-6-sol`/`gpt-6-luna`, `claude-sonnet-5-5`/`claude-opus-5-5`/`claude-mythos-5-1`, `gemini-3.8-live`, `gemini-omni-1.1-flash`, Cloudflare Workers AI refresh, Z.AI `glm-5.3` family; codex-line/Kimi-k2 removals with migration entries; deprecations with sunset dates; defaults refreshed; Cloudflare pricing fixes.
- 🐞 **Schedules, Media Studio, and validators fixed (PRs #6853, #6855, #6856, #6858, #6859).** Fashion transforms pass the resolved Gemini image model (no more `/ai/generate` 500); tool-rule validation failures return HTTP 400; `required_settings` falls back to the credential resolver; **Media Studio 0.6.0 → 0.6.1**. Schedules no longer trigger on create/save (timing-change-only reschedules; recurring starts one interval out); the edit modal stops sending `workflow_steps` on non-workflow schedules. `aspect_ratio: 'auto'` passes the validated Gemini tools.
- 🔒 **Advisory + preset/manifest repairs (PRs #6850, #6851).** undici 7.30.0 across 8 addon lockfiles (GHSA-w293-vg96-wgc3); the fleet pair joins `site_management` preset + coverage manifests.
- 📦 **Versioning** — bumped to **1.1.93** across all version-bearing files. Pro addon: 1.1.93. **Media Worker: v3.2.0 → v3.3.0**. **Media Studio: 0.6.0 → 0.6.1**. SaaS Controller: **0.3.0** (unchanged). Design System addon: **0.3.0** (unchanged). nvoos-content-graph: **1.0.8** (unchanged). nvoos-content-graph-ai: **1.0.4** (unchanged). nvoos-content-graph-ai-platform: **2.0.0** (unchanged). nvoos-content-graph-pro: **1.0.0** (unchanged — no port waves in-window). Checkout API: **0.1.2** (unchanged). Docs Hub addon: **0.5.1** (unchanged). Comic Reader addon: **0.5.0** (unchanged). Model catalog: **v2026.10.03** (238 models; Z.AI joins). Tool count: **~349 base + ~1,312 Pro (~1,661 total — +2 base +3 Pro)** — the fleet pair + the financial trio (live registry authoritative). Provider count: **18** chat providers (Z.AI joins). Addon count: **28** (unchanged). Bundled skills: **75** base + **41** Pro (unchanged). Coding-time agent skills: **61** (unchanged). Stale build ZIPs removed: the 1.1.91 oOS set (6 files) + the superseded media-studio (0.1.0/0.2.0/0.6.0) and saas-controller (0.1.0/0.2.0) addon ZIPs (5 files — 11 total).

### v1.1.92 — October 2, 2026

#### Media Studio AI Fashion Production, Inline Vision Data URLs & Provider-Content Fixes

- 👗 **Media Studio fashion production suite (PRs #6839, #6844 — addon 0.1.0 → 0.6.0).** The `fashion-studio` AI mode lands in Media Studio: `NV_oOS_Media_Studio_AI_Service` drives eight industry transforms (on-model, model-swap, face-swap, background, recolor, packshot, detail-repair, try-on) through Gemini via `edit_gemini_image`; REST `/ai/*` routes carry the D-1 consent/acknowledgment gate + D-3 cost tripwires ($0.25 per-image / $10 per-job review, $100 hard cap, unknown pricing → review); a lazy-loaded SPA mode (media-library picker, presets, review-confirm dialogs, compliance chips); forced disclosure watermark on face outputs, metadata disclosure floor. **Phase 2 (Pro)** — `mcp_ai_fashion_model` identity CPT + consent gating, `mcp_ai_fashion_job` batch/review queue (approve/reject/reroll, WooCommerce + media collection export), the `fashion_studio` Pro module, fashion presets. **Phase 3 (base)** — marketplace output pipeline (amazon/woocommerce/social/web profiles, GD upscaling ≤2×, format conversion, alt text, white-bg validation). **Phase 4 (base)** — IPTC 2025.1 XMP provenance into JPEG/PNG/WebP + best-effort C2PA. **Phase 5** — sidecar-backed fashion `video` transform + **8 new Pro tools** (`fashion_onmodel_generate`, `fashion_model_swap`, `fashion_background_generate`, `fashion_recolor`, `fashion_packshot`, `fashion_virtual_tryon`, `fashion_batch_job`, `fashion_identity_manage` — registered in the main `$pro_tools` map, self-gate via `is_available()`) + the Workflow Builder's 10th `fashion` preset category.
- 🖼 **Inline vision data URLs + payload optimization (PR #6846).** `submit_document_prompt` and every OpenAI/DeepSeek vision path no longer hand remote image URLs to provider servers — that fetch failed on hotlink-protected, CDN-fronted, staged, or freshly uploaded media (`Failed to download image from …`). The new `WP_MCP_AI_Image_Data_Url` helper converts image segments to base64 data URLs with the WordPress server as fetcher (local attachments read off disk, remote URLs downloaded server-side); both OpenAI payload paths + DeepSeek prefer inline bytes with URL fallback; logs truncate data URLs. Oversized JPEG/PNG downscaled to a 2048px vision-tile cap, opaque PNGs re-encoded JPEG q82 (alpha/GIF/WebP preserved), header-only dimension probe, originals never modified, optimized bytes only when smaller. New **Chat Client → Features → Inline Image Optimization** toggle (default on, three settings layers) + `wp_mcp_ai_image_inline_*` filters.
- 🐞 **Provider content + credential edge cases fixed (PR #6845).** The six research tools fataled on array AI content (Gemini/OpenAI-compatible gateways return `message.content` as parts → `preg_match(): Argument #2 must be of type string, array given`) — the new `WP_MCP_AI_Tool_Research_Content_Normalization` trait flattens parts arrays at every choke point and repairs `research_project`'s uncompilable JSON-extraction regexes; `WP_MCP_AI_DeepSeek_Client::normalize_response()` flattens array content blocks (chat, transcripts, tools); `create_post` taxonomy auto-apply matches term names on word boundaries and never auto-applies single-word tags (still suggested); the vision tools see the configured OpenAI key via the merged settings view + `WP_MCP_AI_Credential_Resolver` (the dashboard stores keys in the separate `wp_mcp_ai_credentials` option they never read).
- 🧩 **Pro tool coverage manifest (PR #6847).** Regenerated with the 9 fashion-stack tool classes — the `test_tool_class_manifest_is_up_to_date` CI failure is closed.
- 📦 **Versioning** — bumped to **1.1.92** across all version-bearing files. Pro addon: 1.1.92. **Media Studio: 0.1.0 → 0.6.0** (own track — the fashion production suite; the in-window inventory row is already 0.6.0). Media Worker: **v3.2.0** (unchanged). SaaS Controller: **0.3.0** (unchanged). Design System addon: **0.3.0** (unchanged). nvoos-content-graph: **1.0.8** (unchanged). nvoos-content-graph-ai: **1.0.4** (unchanged). nvoos-content-graph-ai-platform: **2.0.0** (unchanged). nvoos-content-graph-pro: **1.0.0** (unchanged — no port waves in-window). Checkout API: **0.1.2** (unchanged). Docs Hub addon: **0.5.1** (unchanged). Comic Reader addon: **0.5.0** (unchanged). Model catalog: **v2026.09.22** (unchanged — zero `includes/data/` diff in-window). Tool count: **~347 base + ~1,309 Pro (~1,656 total — +8 Pro)** — the fashion set (live registry authoritative). Provider count: **15** chat providers (unchanged). Addon count: **28** (unchanged). Bundled skills: **75** base + **41** Pro (unchanged). Coding-time agent skills: **61** (unchanged). Stale build ZIPs removed: the 1.1.90 build set (30 files: 9 in `build/` incl. 3 `.sha256`, 2 in `build/optional-components/`, 19 in `build/toolkit-addons/`).

### v1.1.91 — October 1, 2026

#### FlowHub MCP Mode, OAuth Discovery & JSON Envelope Protection

- 🔌 **FlowHub Connection MCP mode (PR #6836).** FlowHub Remote Sites connections gain a `flowhub_mode` selector (`api` default | `mcp`) — MCP mode designates the connection as the backend for the FlowHub toolkit MCP server. `WP_MCP_AI_FlowHub_Connection_Helper::get_mcp_connection_id()` resolves the first enabled MCP-mode connection; the toolkit MCP REST controller injects its `connection_id` into tool arguments only when the caller passes none — MCP-triggered refresh/sync calls route through the explicit-connection path so decrypted credentials and the connection's encrypted proxy apply via `http_api_curl`. Explicit `connection_id` always wins; without an MCP-designated connection, behavior is byte-for-byte unchanged; the seam is generic (`WP_MCP_AI_Toolkit_Server_Base::get_mcp_connection_id()`).
- 🔐 **MCP App OAuth discovery per MCP spec (PR #6835).** `WP_MCP_AI_MCP_App_OAuth_Client::discover_metadata()` walks the full chain — RFC 8414 metadata (+ §3.2 path insertion for path-scoped servers), RFC 9728 protected-resource metadata (trying every `authorization_servers` entry), the 401 `WWW-Authenticate: Bearer resource_metadata` probe, OIDC fallback, and WordPress REST fallback. RFC 8414 documents are accepted only with both endpoints; every attempt is recorded (URL → HTTP status / transport error) and the metabox failure alert surfaces the real transport error; server-advertised `default_scope` is honored; `parse_www_authenticate()` splits multiple challenges per RFC 7235 §2.1; 10 s per-probe cap.
- 🔗 **OAuth redirect allowlists (PRs #6831, #6832).** LinkedIn, QuickBooks, Mailjet, and Yahoo Sports connect buttons no longer silently bounce to wp-admin — each flow allowlists its consent-page host (`www.linkedin.com`, `appcenter.intuit.com`, `app.mailjet.com`, `api.login.yahoo.com`) via the `allowed_redirect_hosts` filter, deriving the host from the provider's authorize-endpoint filter; matching ports land in the Content Graph AI platform.
- 🛡 **JSON envelope protection (PR #6827).** Orchestration CCT reads gate on the physical table — `table_exists()` probe → `is_storage_ready()` (table present **and** every required column; schema drift trips the gate → transients fallback, logged; per-request cache with a `reset_storage_cache()` seam). The new `WP_MCP_AI_Db_Output_Guard::run()` suppresses `$wpdb` error output around callbacks (logging failures with the last query) and wraps the central tool dispatch — `execute_tool()` + both REST tool handlers — so no surface can leak database error HTML into a JSON response again; the four agent-command-center read AJAX handlers gain `with_suppressed_db_errors()`.
- 🐞 **Security events display + double-render fixed (PRs #6829, #6830).** The Security tab's "Recent Security Events (last 10)" table and the compliance CSV exporter now read the canonical `event_type`/`ip_address` keys (with legacy fallbacks), label known event slugs, and show user display names (Guest for unauthenticated) — stored entries were always correct, only the display keys were wrong. The orchestration dashboard no longer renders twice: the self-instantiating class gained a duplicate `new` in the bootstrap loader, registering two distinct callbacks on the same page hook (duplicate DOM IDs breaking the auto-refresh JS) — the redundant `new` is removed.
- 🧩 **RF-DETR presets + coverage manifest (PR #6828).** `rfdetr_catalog_search` joins the `ecommerce` preset ("Product operations"); `rfdetr_detect` stays deliberately out of presets (gated behind `enable_vision_analysis_toolkit`, matching its siblings); the Pro tool coverage manifest is regenerated with the two missing class basenames.
- 🔒 **Dependency advisories (PRs #6833, #6834).** `nodemailer` `^9.1.1` → `^10.0.9` in `addons/pro` + `addons/media-worker` (GHSA-g57g-f23g-4646, alerts #977/#978 — quoted local-part envelope injection) with the vendor copy spec switched to `dist/` (nodemailer 10 ships no top-level `lib/`) and the vendor bundle refreshed 8.0.5 → 10.0.9; `fast-uri` override floor → `>=4.1.5` across the four alert-bearing trees (GHSA-jvvf-x445-j334, alerts #979–#982 — mailto header injection), verified additive-only via npm-pack diff.
- 🌐 **Ecosystem port (PR #6825).** The RF-DETR vision cluster ports byte-identical into `nvoos-content-graph-pro` — the Roboflow inference service (three trust tiers, fail-closed credentials, SSRF/HTTPS discipline), `WP_MCP_AI_Tool_Rfdetr_Detect`, the vision count normalizer, the e-commerce catalog search (44-tool e-commerce registration), the D8-compat image-dHash copy, and the slim standalone init with the `toolkit_vision_analysis` module gate — dual-matrix green with the documented deviations; tracker row F3 appended (sub-cluster 1 complete).
- 📦 **Versioning** — bumped to **1.1.91** across all version-bearing files. Pro addon: 1.1.91. Media Worker: **v3.2.0** (unchanged — the #6833 nodemailer bump carries no version change). SaaS Controller: **0.3.0** (unchanged). Design System addon: **0.3.0** (unchanged). nvoos-content-graph: **1.0.8** (unchanged). nvoos-content-graph-ai: **1.0.4** (unchanged). nvoos-content-graph-ai-platform: **2.0.0** (unchanged — OAuth ports + build refresh). nvoos-content-graph-pro: **1.0.0** (unchanged — two in-feature ports landed: the RF-DETR cluster (#6825) and the FlowHub MCP-mode mirrors (#6836)). Checkout API: **0.1.2** (unchanged). Docs Hub addon: **0.5.1** (unchanged). Comic Reader addon: **0.5.0** (unchanged). Chat SPA addon: **0.7.0** + Canvas Toolkit addon: **0.2.0** (unchanged). Model catalog: **v2026.09.22** (unchanged — no model PRs in-window). Tool count: **~347 base + ~1,301 Pro (~1,648 total — unchanged)** — no tool registrations in-window (FlowHub MCP mode is a connection-binding seam; #6828 only wires the existing RF-DETR pair into presets/manifest; live registry authoritative). Provider count: **15** chat providers (unchanged). Addon count: **28** (unchanged). Bundled skills: **75** base + **41** Pro (unchanged). Coding-time agent skills: **61** (unchanged). Stale build ZIPs removed: the 1.1.89 build set (30 files: 9 in `build/` incl. 3 `.sha256`, 2 in `build/optional-components/`, 19 in `build/toolkit-addons/`).

### v1.1.90 — September 30, 2026

#### RF-DETR Vision Cognition, Strict MCP Scope & Memory Identity Closure

- 🔍 **RF-DETR vision cognition (PR #6824, Proposal 049).** New Pro `WP_MCP_AI_Roboflow_Inference_Service` — one HTTP client across three trust tiers (key-less self-hosted Docker Inference server on loopback/private hosts; dedicated deployments; Serverless Cloud API) with fail-closed credentials (`va_roboflow_api_key` as the raw `Authorization` header for serverless/dedicated, HTTPS enforced off-loopback, every URL passes the SSRF guard, operator-allowlist seam). **Two new Pro tools** — `rfdetr_detect` (`task=detect|segment|keypoints`: ranked boxes, instance-segmentation mask polygons, 17-COCO person keypoints) and `rfdetr_catalog_search` (fine-tuned catalog models by alias or workspace/project/version, per-model + dHash 5-min transient cache) — returning the canonical `{label, confidence, box, mask_points?, keypoints?}` shape. The `roboflow` provider joins `analyze_image_objects` (detector-owns-the-count invariant, no new slug); `identify_image` rung 3c reports `rfdetr_detections` as an additional source (class-guarded — Base installs skip with `pro_addon_required`/`not_configured`, zero behavior change). Apache-2.0 aliases by default; XL/2XL (PML 1.0) behind the `va_roboflow_allow_pml` consent toggle. RF-DETR settings section (Vision Analysis) + deployment guide (`docs/operations/deployment/roboflow-inference-server-setup.md`).
- 💼 **Upwork MCP as first-class MCP Apps references (PR #6823).** Upwork-in-MCP-mode Remote Sites connections now appear in the assistant **MCP Apps** metabox "Add from Remote Sites" dropdown (labelled `Name (Upwork MCP — https://mcp.upwork.com/mcp)`) with the full OAuth login UI (web login / loopback paste-back / authenticated state). `resolve_connection_ref()` resolves Upwork refs at chat time via `build_upwork_mcp_app_config()` (official gateway + decrypted central `mcp_oauth`); the import validator no longer auto-disables Upwork refs; `finalize_oauth_flow()` persists the reference entry onto the assistant so it survives the post-login reload.
- 🧠 **Memory identity resolution + cross-agent access closure (PR #6815).** All eight memory tools (`retrieve_agent_memory`, `store_agent_context`, `recall_memory`, `wake_up_context`, `semantic_context_search`, `mine_agent_memory`, `manage_context_lifecycle`, `batch_manage_memory`) accept an optional `agent_id` and otherwise resolve the caller's identity from the execution context via `WP_MCP_AI_Agent_Identity_Resolver`. Cross-agent access gated behind `manage_options` (403 `mcp_ai_memory_scope_denied` — the IDOR closed); 400 `mcp_ai_memory_no_agent` when no identity resolves; every success envelope echoes `resolved_agent_id` + `resolution_source`. `store_agent_context` scans for credential patterns (incl. the plugin's own `cred_…<secret>` format) flagged `sensitive_patterns`/`contains_sensitive`; `retrieve_agent_memory` carries expiry signalling (`expires_in`, `expires_soon` ≤7 days).
- 🛡 **Strict MCP assistant-scope toggle (PR #6819).** Opt-in `mcp_require_assistant_scope` (Security → Access & Identity, default OFF): MCP `tools/list` + `tools/call` fail closed with HTTP 403 (`wp_mcp_ai_assistant_scope_required`) when no assistant resolves (no explicit `assistant_id`, no token-bound assistant, no default assistant). Shared `maybe_enforce_strict_scope()` gates `mcp_tools_list()` after the resolve/scope chain and `mcp_tools_call()` before the tool executor; the 403 special-case is scoped to this one error code (all other errors keep the HTTP 200 JSON-RPC envelope). Closes #6769.
- ✉️ **Letterhead email personalization (PR #6816).** The letterhead template renders `Dear {{to_name}},`; the renderer gains `{{#to_name}}…{{/to_name}}` conditional blocks (bare addresses never emit `Dear ,`); `get_builtin_html()` prefers the `direct` filesystem transport with a plain local-read fallback — bundled templates no longer silently no-op on hosts where `direct` is unavailable.
- 🔒 **Dependency advisories (PR #6817).** `js-yaml` overrides → `>=5.4.1` across 13 package trees (lockfiles → 5.4.2) + scoped `webpack-dev-middleware@^8` → `>=8.3.0` (root + `addons/pro/assets/spa`) — 15 of 17 open Dependabot alerts resolved; 13 lockfiles regenerated + `npm ci --dry-run` verified; the AI SDK 2.x-line advisory stays open intentionally (sync→async signature change would break `useChat`) — tracked as #6818.
- 📦 **Versioning** — bumped to **1.1.90** across all version-bearing files. Pro addon: 1.1.90. Media Worker: **v3.2.0** (unchanged). SaaS Controller: **0.3.0** (unchanged). Design System addon: **0.3.0** (unchanged — the letterhead/registry fix ships on the 0.3.0 line). nvoos-content-graph: **1.0.8** (unchanged). nvoos-content-graph-ai: **1.0.4** (unchanged). nvoos-content-graph-ai-platform: **2.0.0** (unchanged). nvoos-content-graph-pro: **1.0.0** (unchanged — the RF-DETR ecosystem port is open as #6825). Checkout API: **0.1.2** (unchanged). Docs Hub addon: **0.5.1** (unchanged). Comic Reader addon: **0.5.0** (unchanged). Chat SPA addon: **0.7.0** + Canvas Toolkit addon: **0.2.0** (builds refreshed in-window). Model catalog: **v2026.09.22** (unchanged — Roboflow is a vision inference service outside the catalog). Tool count: **~347 base + ~1,301 Pro (~1,648 total; +2 Pro)** — the RF-DETR pair; the `roboflow` provider is no new slug and `identify_image` rung 3c is additive-only (live registry authoritative). Provider count: **15** chat providers (unchanged). Addon count: **28** (unchanged). Bundled skills: **75** base + **41** Pro (unchanged). Coding-time agent skills: **61** (+1 — the dependabot-loop skill). Stale build ZIPs removed: the 1.1.88 build set (30 files: 9 in `build/` incl. 3 `.sha256`, 2 in `build/optional-components/`, 19 in `build/toolkit-addons/`).

### v1.1.89 — September 29, 2026

#### Google Classroom ECA, Design System Rename + Email Templates & Upwork MCP Mode

- 🎓 **Google Classroom integration for the ECA toolkit (PR #6809, Proposal 046).** Shared `includes/google/` Classroom foundation mirroring the Calendar stack — `Scopes` (restricted-scope discipline, granular-consent implication checks), `Client` (REST v1, repeated-parameter building, page-token pagination, `RESOURCE_EXHAUSTED` backoff, terminal `@MissingGrant`, `updateMask` PATCH), `Credentials` (Pro Remote Sites resolution, decrypt-on-read, quota-user attribution), `Push` (Pub/Sub webhook with shared-secret verification, ack-fast cron deferral, weekly renewal). New `google_classroom` Remote Sites connection type (OAuth on the shared service, real `courses.list` test probe with `needs_reconnect`). **12 new ECA tools** (`list_classroom_courses`, `sync_classroom_roster_to_students`, `sync_classroom_courses_to_ecas`, `link_classroom_course_to_eca`, `create_classroom_course`, `update_classroom_course`, `post_classroom_announcement`, `create_classroom_coursework`, `list_classroom_submissions`, `classroom_course_analytics`, `list_classroom_guardians`, `manage_classroom_push_watch`) behind `enable_eca_classroom_integration` (default off) with a nightly jittered sync engine + push-delta roster/course reconciliation. New base REST route `mcp-ai/v1/google-classroom/webhook`.
- 🎨 **Design System addon rename + token-driven email templates (PR #6810, Proposals 047 + 048).** `addons/crocoblock-ds/` → `addons/nvoos-design-system/` (**0.1.0 → 0.3.0**, zero-breakage: `--cds-*`/`.cds-*` aliases, one-way option migration, `class_alias`, legacy admin-post action). Token-driven email module: 5 built-in accessible templates, global `wp_mail` wrapper (sentinel double-wrap guard, full-document + text/plain skips), 15-token `emails` group with dark-mode pairs, WCAG/EMC audit gates (contrast math, BLOCK gates, 100 KB budget), multipart `AltBody` pairing, opt-in WooCommerce rebrand, Paper Store mirror/import bridge. **8 admin-gated `nds_*` tools** (generate/list/preview/audit/set-active/test-send/export/import) — addon-provided via `wp_mcp_ai_register_tools`, not counted in base/Pro totals. Two latent addon bugs fixed (double-prefixed CSS vars that never matched component CSS; a broken integration autoloader that silently disabled all four integrations).
- 💼 **Upwork MCP connection mode (PR #6804).** Third `upwork_mode` (`mcp`) alongside `api` and `web_search` — talks to the official Upwork MCP gateway (`https://mcp.upwork.com/mcp`, 51 `upwork__*` tools) through a sessionful `WP_MCP_AI_Upwork_MCP_Bridge` (`upwork__find_jobs`/`upwork__list_accounts`, defensive normalization); `search_upwork_jobs`/`import_upwork_project` branch to the bridge with `_external_source_id`/`_external_source_platform` dedupe meta; real-handshake Test Connection; `upwork_org_uid` resolution; OAuth login reuses the MCP Apps flow (`connection_ref` on `/oauth/init`) with tokens in the encrypted central `mcp_oauth` store.
- 🖼 **Vision + remote reliability + EZuite integrity (PR #6805).** `submit_document_prompt` routes image attachments through the `input_image` vision segment path (previously rejected on every provider) with provider-bound client selection; `WP_MCP_AI_DeepSeek_Client` converts `input_image` to OpenAI-compatible `image_url` blocks for `deepseek-flash` (the `wp_mcp_ai_deepseek_supports_vision` filter gates the payload); DeepSeek gains a `vision` lane. Remote connections check access controls against canonical slugs (`page`/`attachment`) and surface actionable `rest_no_route` guidance; EZuite enforces the requested `item_code` client-side (no more wrong-product stock/pricing) and fixes "Untitled Product" cards; the model catalog migration now rewrites per-provider model settings (`deepseek_model`, `default_gemini_model`, `anthropic_model`, `kimi_model`) at the next catalog bump.
- ⚡ **MCP legacy-dialect handshake cache (PR #6802).** `WP_MCP_AI_MCP_App_Client::handshake()` caches a 24h per-URL legacy hint (`wp_mcp_ai_mcp_app_legacy_<md5(url)>`) so strict 2025-era gateways skip the doomed `server/discover` probe; stale hints self-heal on stateless rejections; `test_connection()` + registry `discover_tools()` delegate to it. Upwork Test Connection: ~24.9s → ~8.5s steady state.
- 🛡 **Security (PRs #6807 + #6806).** All 38 CodeQL alerts closed — **0 open code scanning alerts** (DOM XSS escaping in the diagnostic page, CSPRNG OAuth1 nonces in the twitter-api-v2 vendor bundle, `health-consolidate.js` hardening, yfinance cleanup); `ip-address` → 10.7.2 (NAT64 SSRF), `undici` floors 7.29.1/8.10.2/6.28.1 (WebSocket DoS), `multer` → 2.4.0 (orphaned writes) across every affected tree.
- 📦 **Versioning** — bumped to **1.1.89** across all version-bearing files. Pro addon: 1.1.89. Media Worker: **v3.2.0** (unchanged). SaaS Controller: **0.3.0** (unchanged). Design System addon: **0.1.0 → 0.3.0** (own track — the in-window inventory row said 0.2.0, corrected this pass). nvoos-content-graph: **1.0.8** (unchanged). nvoos-content-graph-ai: **1.0.4** (unchanged). nvoos-content-graph-ai-platform: **2.0.0** (unchanged). nvoos-content-graph-pro: **1.0.0** (unchanged — one byte-identical in-feature port: the product-card trait, #6805). Checkout API: **0.1.2** (unchanged). Docs Hub addon: **0.5.1** (unchanged). Comic Reader addon: **0.5.0** (unchanged). Model catalog: **v2026.09.22** (unchanged). Tool count: **~347 base + ~1,299 Pro (~1,646 total)** — +12 Pro (the classroom tools; the 8 `nds_*` tools are addon-provided and not counted; live registry authoritative). Provider count: **15** chat providers (unchanged). Addon count: **28** (unchanged — rename, not addition). Bundled skills: **75** base + **41** Pro (unchanged). Coding-time agent skills: **60** (unchanged — four updated in place). Stale build ZIPs removed: the 1.1.87 build set (30 files: 9 in `build/` incl. 3 `.sha256`, 2 in `build/optional-components/`, 19 in `build/toolkit-addons/`).

### v1.1.88 — September 28, 2026

#### Decision-Model Orchestration, MCP Apps Hardening & SaaS Controller 0.3.0

- 🧠 **Decision-model orchestration Phase A (PR #6792, Proposal 045).** The TypeSafe Jev decision model wires into the router and orchestration layers for the first time. Base: `WP_MCP_AI_Verification_Cascade` implements the SDE-cascade pattern (`build_battery()` per-field nouls framed bad=true, one batched decision call chunked at 50, max aggregation, single-rung escalation, injectable `Interface_WP_MCP_AI_Decision_Client`, filterable `wp_mcp_ai_cascade_escalate_threshold` default 0.7) plus the new `wp_mcp_ai_execution_depth_confidence` filter seam in `execute_with_depth()`. Pro: opt-in `enable_jev_tier_routing` tier routing (code-owned `(2 − complexity)/2` confidence, neutral 0.65 fallback, caller signals always win, `decision_model` metadata) and opt-in `enable_jev_citation_escalation` citation cascade (two-head battery per claim, `escalate_flagged_citations()` verification-tier revision capped via `wp_mcp_ai_citation_escalation_max`) wired into `research_eca` + `generate_research_report`. Fail-open everywhere, off by default, only the first user message ever reaches Jev, thresholds stay in PHP. Gap review: `docs/project/audits/2026-09/decision-model-orchestration-gap-review.md`. Deferred: extraction cascade + G2/G3/G4/G6–G8.
- 🔐 **MCP Apps OAuth + credential hardening (PRs #6794/#6795/#6798).** Upwork accepts only loopback redirect URIs — `initiate_oauth()` falls back to `redirect_mode: manual_loopback` on DCR `invalid_redirect_uri` and the admin pastes the localhost callback into the new `POST mcp-ai/v1/mcp-apps/oauth/complete` endpoint (10-minute state TTL, shared `finalize_oauth_flow()`, JSON-first with form-encoded retry on 415). `client_id` is sent in token-exchange/refresh bodies (Upwork requires it) and the paste-back box survives reloads. Inline MCP App secrets (`token`, `oauth_data`) + Remote Sites `verify_token`/`verification_token` are now **encrypted at rest** (AES-256-CBC) and masked in forms; rotated tokens persist (inline meta, `update_mcp_oauth()`, tool-bridge re-hydration); Test/Discover restores stored OAuth credentials (fixes "No OAuth access token available"); `mcp_oauth_refresh` activity events carry metadata only.
- 🛡 **Toolkit MCP servers gated on assistant grants (PR #6796).** Deny-by-default: `handle_jsonrpc()` requires an explicit grant for assistant-scoped requests (`params.assistant_id`) — non-granted servers return `-32601 "Server not granted to this assistant"`; `initialize`/`ping` stay ungated and `toolkitServers` always reflects the exact grant list (empty allowlist = no grants, per the corrected UI copy); assistant_id-less requests bypass the gate; the server-level toggle is unchanged. CG Pro mirror byte-identical.
- 🔌 **MCP App transport fixes (PRs #6799 + #6800).** Strict 2025-era gateways that reject the `server/discover` probe with a bare HTTP 400 (no JSON-RPC envelope) now trigger the legacy `initialize` fallback (400/404/405/501), and legacy sessions omit the 2026-only `MCP-Protocol-Version`/`Mcp-Method` headers + `_meta` envelope; HTTP-error `WP_Error`s carry a 400-char body snippet. SSE-answering gateways (Envoy AI Gateway / Agent Router) no longer fail enumeration with "invalid JSON" — `send_request()` detects SSE via Content-Type or `data:` sniffing and `parse_sse_payload()` extracts the `message` event (dedicated `wp_mcp_ai_mcp_app_empty_sse` for empty streams).
- 🔎 **Upwork job-link canonicalisation round-trip (PRs #6790 + #6793).** The canonical marketplace form is restored to `https://www.upwork.com/jobs/<slug>_~<jobId>/` (per vanooo/upwork-mcp's live-SPA scraper — #6790's `/freelance-jobs/apply/` form still produced wrong links); login-walled SPA surfaces (`/nx/…`, `/o/jobs/…`, `/r/…`) are dropped as non-listings (except `/nx/search/jobs/details/~<jobId>` → bare-id form); tracking query strings dropped; MCP OAuth web login surfaces real errors and the callback route renders the friendly error page for auth-server denials.
- 🩹 **PayHere/Flowhub guarded requires (PR #6801).** A production install missing `includes/class-wp-mcp-ai-payhere-client.php` fatalled on **every request** during `plugins_loaded` — all 8 affected tool files now `file_exists()`-guard their dependency requires so `is_available()` self-reports unavailable, and the 7 Flowhub tools return canonical `WP_Error`s (`wp_mcp_ai_flowhub_client_missing`/`wp_mcp_ai_flowhub_helper_missing`) instead of fatals.
- ☁️ **SaaS Controller 0.1.0 → 0.3.0 (PRs #6791 + #6797) + gateway express bump (#6787).** 0.2.0 ships the production NV oOS Cloud worker (byte-identical port from `addons/cloud-worker/src/`, `diff -q` verified; `compatibility_flags: ['nodejs_compat']`; `worker:dryrun` fixed; jest + flat eslint repaired). 0.3.0 (Phase 12) folds Worker secrets + D1 schema into Plan/Apply (`worker_secret` rows resolve from the encrypted credential store at Apply time, values never in plans or audit logs; comment/PRAGMA-stripped idempotent `CREATE TABLE IF NOT EXISTS` via the D1 `/raw` endpoint) — the whole NV oOS Cloud deployment runs from WP-Admin. The mcp-wordpress gateway pins `express@4.22.3`, closing Dependabot alerts #897/#899/#900/#902 (0 vulnerabilities, 22/22 tests).
- 📦 **Versioning** — bumped to **1.1.88** across all version-bearing files. Pro addon: 1.1.88. Media Worker: **v3.2.0** (unchanged). SaaS Controller: **0.1.0 → 0.3.0** (own track). nvoos-content-graph: **1.0.8** (unchanged). nvoos-content-graph-ai: **1.0.4** (unchanged). nvoos-content-graph-ai-platform: **2.0.0** (unchanged). nvoos-content-graph-pro: **1.0.0** (unchanged — three byte-identical in-feature ports: Upwork URL round-trip #6790/#6793, toolkit grant gating #6796). Checkout API: **0.1.2** (unchanged). Docs Hub addon: **0.5.1** (unchanged). Comic Reader addon: **0.5.0** (unchanged). Model catalog: **v2026.09.22** (unchanged). Tool count: **~347 base + ~1,287 Pro (~1,634 total)** — unchanged (no tools added or removed; live registry authoritative). Provider count: **15** chat providers (unchanged). Addon count: **28** (unchanged). Bundled skills: **75** base + **41** Pro (unchanged). Coding-time agent skills: **60** (unchanged — `design-crm` + `design-elementor-mcp-connection` reconciled in place). Stale build ZIPs removed: the 1.1.86 build set (30 files: 9 in `build/` incl. 3 `.sha256`, 2 in `build/optional-components/`, 19 in `build/toolkit-addons/`).

### v1.1.87 — September 27, 2026

#### Parity Suite, Image Identification & Outbound Booking

- 🔧 **mcp-wordpress parity suite — 30 native base tools (PR #6777).** Comment CRUD (`list_comments`/`get_comment`/`create_comment`/`update_comment`/`delete_comment`), user CRUD (`list_users`/`create_user`/`update_user`/`delete_user`), content/media/terms (`get_post_revisions`, `get_term`, `delete_term`, `get_media`, `upload_media`, `update_media`, `delete_media`), site settings + application passwords (`get_site_settings`/`update_site_settings` allowlist-only, `list_application_passwords`/`create_application_password` log-masked/`delete_application_password`), and a 9-tool SEO toolkit (`seo_analyze_content` through `seo_keyword_research`). Capability checks, multisite guards, chat-client restriction trait on sensitive write tools, and new `comment_id`/`user_id`/`attachment_id` ID-handoff contracts (gap matrix: `docs/developer/mcp-wordpress-tool-parity.md`).
- 🖼️ **Non-LLM image identification ladder (PRs #6780 + #6785, Proposal 043).** `identify_image` orchestrates a cheap-first deterministic ladder — WordPress metadata → pure-PHP dHash media lookup → classic Cloud Vision detection → deterministic layout description → optional reverse-image web search — and never calls a vision LLM (confidence + escalation hint). Five new base tools join the Media Generation preset (`identify_image`, `get_image_metadata`, `find_similar_media`, `detect_image_content`, `describe_image_layout`); two new Pro tools (`ocr_image_classic` tesseract-only OCR, `search_similar_images` Bing Visual Search / SerpApi Google Lens) sit on the shared `WP_MCP_AI_Cloud_Vision_Client`. Every external rung is key-gated and fails closed — missing credentials = skipped, never an error, never an HTTP request; SSRF guards on server-side fetches.
- 📅 **Outbound appointment booking toolkit (PR #6786, Proposal 044).** New Pro toolkit gated by `enable_outbound_booking_toolkit`: ICP list building (CSV → `mcp_ai_lead`, ICP-scored, sequence enrollment), an hourly cron engine over `mcp_ai_sequence` steps with send windows / daily caps / consent attestation, email + LinkedIn/Instagram DM channels behind a human approval board, the `mcp_ai_oa_angle` angle bank with champion/challenger A/B + weekly promotion, booking links + `[nvoos_oa_booking]` shortcode + a rate-limited honeypotted consent-gated public booking endpoint, a pipeline dashboard + Slack notifications + daily digest, and **three new Pro tools** (`outbound_get_pipeline_stats`, `outbound_import_leads`, `outbound_manage_angle`).
- 🌐 **mcp-wordpress gateway addon (PR #6778).** `addons/mcp-wordpress-gateway/` — an auth-gated Streamable HTTP MCP server on pinned docdyhr/mcp-wordpress internals for Cloudways Velocity (timing-safe `X-MCP-Token` ≥32 chars with rotation overlap, 1 MB body cap, deny-wins `MCP_TOOLS_ALLOW`/`MCP_TOOLS_DENY` registration-time policy), deployed via subtree split to the `nvoos-mcp-wordpress` mirror.
- 🚦 **Per-session budget warnings (PR #6776).** Blocked-session messages report session state + the real reset path (Restricted Users / `wp mcp-ai restrictions lift`); the dormant 75% `wp_mcp_ai_session_limit_approaching` hook is wired to a one-shot warning on the next chat turn; new `wp_mcp_ai_chat_messages` filter on both chat paths; Token Manager reset semantics documented. The two untracked Elementor bundled skills are committed — the 75-skill base bundle is now genuinely tracked.
- 🧹 **Restriction admin notice fixed (PR #6779).** One queue entry per user (re-flag refreshes), render-time sweep of expired windows + unrestricted users + legacy dedupe, lift pruning, and a new `count_active_users()` distinct-user counter — the Pro command-center banner is fixed too.
- 🔎 **Upwork search + in-place workflow editing (PR #6784).** `update_pro_schedule` gains `workflow_steps` (replace semantics — no more delete-and-recreate); `search_upwork_jobs` gains `exclude_keywords`, an `all` sentinel, quoted-OR fallback skills, tier + budget parsing, and criteria echoing; `upwork_job_discovery_scan` preset carries academic-noise exclusions; byte-identical CG Pro port; the `design-pro-schedule-manager` + `design-crm` coding-time skills updated.
- 📦 **Versioning** — bumped to **1.1.87** across all version-bearing files. Pro addon: 1.1.87. Media Worker: **v3.2.0** (unchanged). nvoos-content-graph: **1.0.8** (unchanged — explorer motion/UX + remote-source driver fixes landed in-window on its own track). nvoos-content-graph-ai: **1.0.4** (unchanged). nvoos-content-graph-ai-platform: **2.0.0** (unchanged). nvoos-content-graph-pro: **1.0.0** (unchanged — one byte-identical tool port in-window). Checkout API: **0.1.2** (unchanged). Docs Hub addon: **0.5.1** (unchanged). Comic Reader addon: **0.5.0** (unchanged). Model catalog: **v2026.09.22** (unchanged — no model PRs in-window). Tool count: **~347 base + ~1,287 Pro (~1,634 total)** — +35 base (30 parity + 5 image) and +5 Pro (2 image + 3 outbound); live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative. Provider count: **15** chat providers (unchanged). Addon count: **27 → 28** (new `mcp-wordpress-gateway`). Bundled skills: **75** base + **41** Pro (counts unchanged — now genuinely tracked). Coding-time agent skills: **60** (unchanged). Stale build ZIPs removed: the 1.1.85 build set (30 files: 9 in `build/` incl. 3 `.sha256`, 2 in `build/optional-components/`, 19 in `build/toolkit-addons/`).

### v1.1.86 — September 25, 2026

#### MCP Server Connections, Higgsfield Media Provider, Log Filters & Delivery Templates

- 🔌 **MCP Apps as a Remote Sites connection type — PR #6761 (Proposal 041).** New `mcp_server` connection type with AES-256-CBC-encrypted central credentials (including the `mcp_oauth` blob), a real JSON-RPC handshake Test Connection, tool discovery with persisted snapshots, restricted-host enforcement, and activity logging; auth mapping `basic_auth`/`application_password` → MCP `basic` (Elementor application passwords), `custom_header` → `header`, `bearer`, `oauth`. **Reference mode** — assistant entries carry a `connection_ref` resolved decrypt-on-use at chat time (credentials never written back to post meta); missing refs skip with error-status snapshots; imported bundles with missing refs auto-disable with a warning (`wp_mcp_ai_mcp_apps_validate_imported_refs`). **Portability hardening** — `token`/`oauth_data` redacted on export + stripped on import (opt-out filters); stored credentials survive overwrite imports; the metabox gains "Add from Remote Sites" reference rows + read-only "Managed in Remote Sites" rows. New coding-time skill `design-elementor-mcp-connection`.
- 🎬 **Higgsfield video & image provider — PR #6772 (Proposal 042).** Four new base tools: `generate_higgsfield_video` (five verified models — Cinema Studio 4.0, Seedance 2.5/2.0, Wan 3.0, Kling 3.0 — behind one `model` parameter with per-model payload mapping/clamping, image/video/audio references, cinematic controls), `generate_higgsfield_image` (SOUL V2 + SOUL Cinema workflows, batch 1|4, `style_id` gating), `check_higgsfield_request`, `cancel_higgsfield_request`. Shared `WP_MCP_AI_Higgsfield_Client` (two-part `Key ID:SECRET` auth, submit/status/cancel lifecycle, backoff+jitter polling 2s→10s, immediate download against the 7-day retention; settings → env → constants credential chain) + provider settings section + 480 s async-executor timeout override; `check_video_status` resolves `async_*` job IDs; `lib/core` dual-layer wrappers via `oos-bridge`. Also fixes the TypeSafe tool schemas + Pro coverage manifest and suppresses a benign Sora phpcs warning.
- 🔍 **`get_system_logs` since/levels/search filters — PR #6768.** Optional AND-combined `since` (relative "2h"/"30m"/"3d"/"45 minutes" or absolute ISO 8601 / `Y-m-d H:i:s` UTC; bare number = minutes; malformed → `wp_mcp_ai_invalid_since`), `levels` (critical/error/warning/notice/deprecated), and `search` (case-insensitive, 200-char cap, mb-safe) filters over the structured NV oOS buffers + file logs; responses carry a filters summary (resolved UTC cutoff) and per-file `filtered_out` counts; timestamp-less lines kept conservatively; `parse_since()` is the shared canonical parser; CG AI ports base-identical.
- 📨 **Action-items template + smart excerpts — PR #6773.** Scheduled digests no longer blind-trim the first 80 words: the new `action_items` delivery template (email + chat) sends only the actionable section with graceful fallbacks, and the `summary` excerpt prefers the response's own distillation ("Summary"/"TL;DR"/"Key points"/"Results"), then the action block, then centroid-ranked sentences (MEAD-style centrality × positional decay), with a lead trim as final fallback.
- 💰 **Tool costs reach the final response label — PR #6771.** New `build_tool_cost_envelope()` emits the top-level `toolResult.cost` shape (`cost_usd`/`is_estimated`/`provider`/`model`) on both the agentic loop and the SSE streaming path; the client's shared `aggregateToolUsageBadgeData()` aggregates it with legacy-shape fallbacks and carries tool model/provider into the badge; tool bubbles render the same usage/cost badges.
- 🩺 **Environment status fixed — PR #6767.** The always-on "no assistants published" warning and the never-firing default-assistant branch were an envelope-key mismatch (`summarise_assistants()` wraps under `environment`, `build_warnings()` read flat keys) — both repaired, base + CG port in sync; DeepSeek joins the provider warning maps; new `plugin.default_provider_model` resolves the effective per-provider model; the default assistant entry carries its provider/model meta.
- 📦 **Versioning** — bumped to **1.1.86** across all version-bearing files. Pro addon: 1.1.86. Media Worker: **v3.2.0** (unchanged). nvoos-content-graph: **1.0.8** (unchanged). nvoos-content-graph-ai: **1.0.4** (unchanged — ZIP rebuilt in-window). nvoos-content-graph-ai-platform: **2.0.0** (unchanged). nvoos-content-graph-pro: **1.0.0** (unchanged — no port waves in-window). Checkout API: **0.1.2** (unchanged). Docs Hub addon: **0.5.1** (unchanged). Comic Reader addon: **0.5.0** (unchanged). Model catalog: **v2026.09.22** (unchanged — no model PRs in-window). Tool count: **~312 base + ~1,282 Pro (~1,594 total)** — +4 base (the Higgsfield quartet, #6772); the `lib/core` wrappers are the core registry's own tools and are not counted; live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative. Provider count: **15** chat providers (unchanged — Higgsfield is a media-generation provider, not a model-catalog provider). Addon count: **27**. Bundled skills: **75** base + **41** Pro (unchanged). Coding-time agent skills: **59 → 60** (new `design-elementor-mcp-connection`, #6761). Stale build ZIPs removed: the 1.1.84 wp.org package set (6 files: 3 ZIPs + 3 `.sha256`).

### 📋 Previous Releases

| Version | Date | Highlights |
|---------|------|------------|
| **v1.1.85** | Sep 2026 | MCP Apps connection + exposure wave (per-row diagnostics, token masking, negotiated protocol header, bridge tools on every chat surface, in-process same-site bridge); Docs Hub 0.5.1; README consolidation (single 12-entry Release History + Previous Releases table, anchor-validator CI) |
| **v1.1.84** | Sep 2026 | TypeSafe Jev enhancement wave (Phase 0 API fidelity — noul criteria, structured EntryType fields, bounded retries honouring `retry-after`, advisory decision cache, `jev-preview` alias; Phase 1 base `typesafe_guardrail`; Phase 2 Pro Jev integrations + `typesafe_rerank`/`typesafe_eval`/`typesafe_skill_select`; `typesafe_decide` capability alignment; Proposal 040) |
| **v1.1.83** | Sep 2026 | Tool guidance everywhere (~1,487 tools, the severity-5 guidance sniff); TypeSafe Jev decision provider + Pro classifier (`typesafe_decide`, +1 base); the Assistant Builder meta-assistant; P3 ID-handoff data contracts; usage-monitor save fix; webchat SQL + guidance close-out; Pro bootstrap guard + Telegram chunking; Upwork & workflow delivery polish; canonical-envelope migrations |
| **v1.1.82** | Sep 2026 | WordPress Playground demos (Project Asteria + NV oOS Complete × Ollama blueprints); Pro SPA cron_monitor flag + model-store seeding; token-tracking table hardening (CG AI port); result-delivery dedupe + metadata redaction; `[ollama_status]` banner fix; portability coverage guards; `mcp-ai-wpoos-playground-demos` skill (#59) + updates Track C; Docs Hub 0.4.7 |
| **v1.1.81** | Sep 2026 | Shopify UCP mode-aware tools + image cards; FlowHub connection resolution + proxy; JobNavigator CRM + Gmail poller (5 tools); OpenTerminal Financial toolkit (8 tools); multi-recipient result-delivery email |
| **v1.1.80** | Sep 2026 | Assistant export/import everywhere (WP-CLI, REST, admin page, tools; credential hashes never exported); Security Center Usage Monitor sub-tab; Shopify UCP catalog modes replace the deprecated REST API; WP-CLI repairs + native `chat --stream`; WhatsApp webhook self-tests; new `design-brand-assistant-provisioning` + `mcp-ai-wpoos-assistant-portability` skills |
| **v1.1.79** | Sep 2026 | Imaging symlink-deletion hardening (`isLink()`-first removal + `realpath()` containment, byte-identical CG Pro port); Content Graph 1.0.8 Stripe Checkout fixes (billing address + already-licensed gate); Checkout API 0.1.2 license emails + statement descriptor; Docs Hub 0.4.6 wp.org re-upload ready (0 blocking PCP errors); toolkit slash-test repair |
| **v1.1.78** | Sep 2026 | Slash commands as declarative tool wrappers (~10,400 → ~1,000 lines, 36 commands mapped to real slugs, `slash.*` MCP prompts); DeepSeek V4 Pro restored to active ($0.66/$1.98 off-peak) with Content Graph AI mirror catching up; checkout hardening (mtime asset cache-busting, modal price sync, DEFAULT_PRICE_CENTS 3499); Docs Hub 0.4.4 (0 blocking PCP errors); legal consolidation (two-entity seller model); Content Graph 1.0.7 released |
| **v1.1.77** | Sep 2026 | DeepSeek V4.1 Flash refresh (`deepseek-flash` + peak/off-peak cost calculator); knowledge-graph companion onboarding preset; base+pro regression matrix + toolkit gating fix; Pro WP-CLI load-order guard; agent-memory CCT phantom-slug fix; npm bumps; build publish-race fix; Checkout API 0.1.1 + Content Graph 1.0.7 launch; Wave G port clusters complete |
| **v1.1.76** | Sep 2026 | Chat delivery full-report + per-channel formats (Telegram HTML/MarkdownV2 escaping, WhatsApp/Slack/Discord/Teams Markdown, Messenger/Google Chat plain); comic-creation toolkit toggle; NV oOS Complete checkout consent + buyer email + EU billing + Stripe metadata; manual-install primary + legal docs (privacy/AUP/ToS/compliance); playbook-seeder idempotency; security sweeps (tiptap/multer/csv-parse/react-router-dom/svgo/hono/vitest); Docs-Hub ZIP markdown exclusion; Wave F2 port clusters complete |
| **v1.1.75** | Sep 2026 | Telegram delivery fixes (credential normalization + tier-4 Remote Sites fallback); Content Graph memory bridge (write/read graph projection) + NV oOS Complete checkout (conflict-guarded, chunked); Wave F2 financial-planning/social-media/MCP-server/slice-batch ports; nvoos-content-graph 1.0.4 → 1.0.6 |
| **v1.1.74** | Sep 2026 | Google Calendar query encoding fix (raw `+` in RFC3339 offsets → `rawurlencode()`); Result Delivery email formats (Markdown converter + `html`/`markdown`/`both`); Schedule Manager assistant-prompt editing; Wave F2 PM + calendar-booking toolkit ports; MCP-abilities test-infra fix |
| **v1.1.73** | Sep 2026 | Woo tool upgrades (`bulk_update_products` variable scope, `update_woo_product_qty` notify flag); async job-queue table bootstrap fix; Comic Reader 0.5.0 (Komga parity); Docs Hub 0.4.3; Wave F2 + new `nvoos-content-graph-pro` v1.0.0 |
| **v1.1.72** | Sep 2026 | Woo price/qty tools (`update_woo_product_price`/`update_woo_product_qty`) on a shared updater trait; scheduled EZuite/FlowHub sync connection-ID fix; Pro update vendor-integrity check; `gpt-image-2` defaults; ecosystem port Waves D8/E6 + platform Waves E1–E5 + E-UI 1–3 |
| **v1.1.71** | Sep 2026 | REST rate-limit fixed-window rework + Restrictions-tab unlock; MemPalace wing-scope enforcement; September model catalog (217 → 228 models); Content Graph 1.0.4 visual experience; platform E2 queue layer; new `mcp-ai-wpoos-updates` skill |
| **v1.1.70** | Sep 2026 | exec-disabled host shell-call hardening; fifth PHPUnit repair wave (~29 test PRs) with production fixes across transcripts, charts, presets, mesh, memory, REST, and K16 |
| **v1.1.69** | Sep 2026 | Vision Analysis toolkit (Pro); tagDiv admin compat + metabox crash fix; DeepSeek empty-schema 400 fix; ZipSlip guard revival; fourth PHPUnit repair wave |
| **v1.1.68** | Sep 2026 | Pro SPA v2 shortcode + embedded mode; Hermes fleet extensions (`extensions/`); chat-bubble stale-nonce self-heal; Docs Hub 0.4.2; providers disabled by default on fresh installs; third PHPUnit repair wave |
| **v1.1.67** | Sep 2026 | Content Graph Platform extraction v2.0.0 standalone; ecosystem port Wave D + D-UI (Content Graph AI 1.0.4); Google Workspace Gmail/Drive read tools (6 new); ~100-PR PHPUnit repair wave |
| **v1.1.66** | Aug 2026 | ~100-PR PHPUnit suite repair campaign + new `mcp-ai-wpoos-test-suite` skill; Checkout API addon v0.1.0 + Content Graph paid checkout; Content Graph AI 1.0.3 |
| **v1.1.65** | Aug 2026 | OpenAI reasoning-model parameter fix; Media Worker full-Crawl4AI proxy; security-posture findings closed (#5972); chat/REST/transcript hardening; Content Graph wp.org refresh |
| **v1.1.64** | Aug 2026 | Google Calendar connection + shared `includes/google/` foundation (6 new tools); Composio account health + hardening; tool-declared non-loggable result fields; log-hygiene compaction + secret redaction |
| **v1.1.63** | Aug 2026 | Artifact Evolution Phases A–G (gated Darwinian self-improvement loop); Pro Addons install page; chat storage worker offload; test-suite exit-trap sweep (`bin/sweep-tests.php`) |
| **v1.1.62** | Aug 2026 | OKF Bundle Management Phases A–H (10-tool surface + Bundle Manager screen); Pro OKF skills drawer; vector-store tools migrated to the OpenAI Responses API |
| **v1.1.61** | Aug 2026 | Agent identity bridging in memory store/recall; OKF skill-knowledge bundle generator; undici ^7.29.0 jsdom pin; Content Graph wp.org review reply |
| **v1.1.60** | Aug 2026 | Restricted-user flagging + unblocking (Restriction Registry, Restrictions tab, WP-CLI); conversation import to transcript CCT; tool schema normalization |
| **v1.1.59** | Aug 2026 | Media Worker crawling + Crawl4AI facade (v3.2.0); research tools multi-provider hardening; Docs Hub 0.4.1 rebuild + broken-link fixes; ~32 orphaned tools re-registered |
| **v1.1.58** | Aug 2026 | Composio Connect subsystem (6 beta tools); OOS runtime consolidation Phases 0–5.8; standalone plugins renamed to Content Graph |
| **v1.1.57** | Aug 2026 | Plugin updater base-only in-place install rework; Hermes async chat submit/poll; Fleet Operator agent context; provider detection via Credential_Resolver |
| **v1.1.56** | Aug 2026 | Media Worker v3.0.0 multi-tenant shared-worker mode + per-site provider keys; worker routing expansion; Hermes WebUI MCP server + SSH bridge + skill sync |
| **v1.1.55** | Aug 2026 | MCP agent compatibility (HTTP 200 error envelopes, legacy HTTP+SSE transport, settings-driven tool rate limiter); Fleet Operator addon; Media Worker v2.2.0 hardening; DB connection pooling (Proposal 023) |
| **v1.1.54** | Aug 2026 | MCP async tool-response fix; updater integrity check v2; API-key merged-settings fix across 20 research tools; design-skills audit (44 coding-time skills); README TOC anchor fixes |
| **v1.1.53** | Aug 2026 | Shared Analytics Service (7 platform adapters, DTOs, caching, rate limiter); circuit-breaker + backpressure hardening on all 15 providers; agent-skills sync; SSE backoff fixes |
| **v1.1.52** | Aug 2026 | Paper Store remote-site support + REST controller; remote-connection CPT auto-discovery; design-system tool preset (72 tools); post-install integrity check |
| **v1.1.51** | Aug 2026 | Orchestration/harness gap remediation (OWASP LLM Top 10 20% → 60%, EU AI Act 17% → 67%); MCP protocol version negotiation; Media Worker dependency security bumps |
| **v1.1.50** | Aug 2026 | Media Worker sidecar addon (Docker Node.js, 11 service handlers, queue module); Site Health redeclaration fix; npm security fixes; BMAD agent conventions |
| **v1.1.49** | Aug 2026 | Gemini model-resolution fix; update reactivation + release-ZIP cleanup |
| **v1.1.48** | Aug 2026 | Shopify Sync toolkit 7 fixes; PHPCS 3.13.6 (CVE-2026-67434); default skill catalogues; 3 Graphify standalone plugin ZIPs |
| **v1.1.47** | Aug 2026 | MySQL connection exhaustion fix (Cloudways cron overhaul); update-checker cache-bust; Mermaid CVE fixes in canvas-toolkit |
| **v1.1.46** | Aug 2026 | Backup & Restore (Proposal 020, 11 export providers); GitHub-based plugin updater; Abilities API (Proposal 019); status-page fixes; PHPCS cleanup |
| **v1.1.44** | Aug 2026 | CCT stability (4 fixes: mutex lock, FlowHub duplicate, base-plugin fatal w/out lib/core, Veo async context), API key resolution (Gemini video + Veo fallback), Proposal 016 security & architecture hardening (all waves, 277 autoload optimizations, phpcs sweep), Proposal 017 polling/queue/load-balancing hardening (12 weaknesses), Deferred security items #5755 (post meta, term escaping, REST field filtering), npm security (undici >=8.10.0, fast-uri >=3.1.4, ip-address >=10.4.0 across 11 pkg), Docs: FOR_REVIEWERS v1.1.43 update, 16 broken links fixed, Graphify ecosystem audit |
| **v1.1.43** | Aug 2026 | MCP 2026-07-28 stateless core upgrade, Security v1.1.43 hardening (SSRF/CSRF/SQL/XSS/info-disclosure across 16 files), OKF v0.2 trust-signal support, ICP System (Pro CRM Phase G, 7-dimension scoring), Pro Module Registry (PSR-4, 625-line monolithic init decomposed), Hexagonal architecture purity (PlatformFlushInterface), 7 playbook/profession sync fixes, Phase 3 operational security hardening, WPCS 3.4.1 (CVE-2026-45293) |
| **v1.1.42** | Jul 2026 | Security Infrastructure (7 classes, 21 posture signals A-F), Framework-Agnostic Core (lib/core/, 32 contracts, 21 adapters), Status Page & Incident Communication (Pro), 21 Agent Skills + 6 BMAD Agents, Algorave addon (9 tools), Security Hardening (12 fixes, 13 unit tests) |
| **v1.1.41** | Jul 2026 | OKF v0.1 integration (6 tools); security compliance (11 HIGH/P0 fixes); playbook sync fixes; model credential resolution; dependency security bumps |
| **v1.1.40** | Jul 2026 | Content format awareness helper; research → Paper Store → draft pipeline; settings credential split; July model catalog; SSE HTTP/2 fixes |
| **v1.1.39** | Jul 2026 | Meta-Harness auto-optimization (7 phases); agent delegation rework; Pro SPA v2 polish (20+ PRs); tool presets refactor; Veo 2.0 → Gemini Omni Flash |
| **v1.1.38** | Jul 2026 | Page Agent addon v0.1.0 (AI browser page control copilot), Pro SPA v2 major parity & polish (voice pipeline, tasks drawer, workflow tracker, file attachments, tool shortcuts, slash commands, mobile hamburger, autoscroll/viewport fixes, cache-busting, assistant preloading), Per-user chat memory toggle, create_post/save_post Markdown-to-HTML + taxonomy suggestions, Workflow blueprint existing-content awareness, SPA accessibility: annotation pills, ZAP medium findings triaged |
| **v1.1.37** | Jul 2026 | JetEngine Meta Helper universal (25 CPTs, REST, ECA fields), Places enrichment tools, RabbitMQ + queue infrastructure (custom DB tables, health endpoint, worker), Multi-tenant DB isolation Phase 0–4, DSpark admin UI + speculative orchestration, Crocoblock Design System addon (5 phases), Test coverage: 329 tools across 28 toolkits, Docs Hub broken link engine, OWASP ZAP DAST, 30+ bug fixes |
| **v1.1.36** | Jul 2026 | EZuite Inventory Sync Pro Toolkit, Ralph Loop CCT migration + circuit breaker, JetBooking/JetAppointment (8 tools), Moonshot/Z.AI provider parity (15 total), Unified Sync Log Manager, Tool Presets Auto-Select + Chips Bar, HTTrack Cache + Place-to-Service Bridge, Generate Default Mapping + read-only sync, 45+ bug fixes |
| **v1.1.35** | Jun 2026 | FlowHub Inventory Sync Pro Toolkit (6 tools), Shopify Sync Pro Toolkit (5 tools), Necessity Gate Layer J (irreversibility-weighted safety), Local Voice Embedded STT (3 backends, offline-first), Remote Site Administrator blueprint (22 tools), Places & Calendar bulk import, CLI site-import subcommand, voice realtime auto-detect, 7 bug fixes |
| **v1.1.34** | Jun 2026 | GPT-Realtime-2 voice models with WebRTC + Translate/Whisper + reasoning, multi-channel result delivery UI (11 channels, up from 4), pro scheduler AI/workflow delivery, Graphify ecosystem: remote drivers, WP 7.0 Connectors, wp.org compliance, 3 reasoning-tool fatal bugs fixed, CRM deal import + multi-source auto-import, Upwork/LinkedIn mode toggle, Docs Hub REST + settings sync fixes, http-proxy-middleware CVE, Gemini cache fix, GPT image routing fix, FastAPI porting plan |
| **v1.1.33** | Jun 2026 | WP 7.0 Connectors credential integration across all 17 AI clients with source badges, nvoos-graphify v1.0.0 release (Plugin Check compliant), 3 guzzlehttp CVEs + undici override, 29 npm alerts across 14 packages, 2 bug fixes (Pro tool paths, JSON-RPC warning leak), 15 dependabot bumps |
| **v1.1.32** | Jun 2026 | Content Format Templates + Featured Image Service (3-provider fallback), Result Delivery Pipeline (8 channels), ECA document generation, duplicate posts fix, 6 provider clients timeout fix, schedule trigger stability, Paper Store delete fix, ECA settings/attachment fix, npm CI & Jest resilience, 14 dependabot bumps + 8 npm audit CVEs |
| **v1.1.31** | Jun 2026 | Media Command Center, Pro SPA v2 (rich rendering, assistant scoping, agent selector, v2.0.1), 34 workflow preset tools, npm audit CVEs (12 resolved), Gemini 3.1 flash image default, Media toolkit blueprints & presets, stream_options, agentic-loop cost tracking, Vite CVEs, 1,658 PHPCS lint fixes, CI disk space, data integrity fixes |
| **v1.1.30** | Jun 2026 | Chat SPA Phase 8, PM Toolkit A–D, CRM duplicates/hygiene/analytics, DietPi Pro Toolkit, LibreChat Addon, Layer I Guardrails, Context Window Management, WP 7.0 Bridge, Pro Toolkit Optimizations, OAuth disconnect, 30+ fixes |
| **v1.1.29** | Jun 2026 | Bug-fix & stabilisation sweep: chat bubble assistant dropdown, context-window pre-flight validation (13 providers), OpenAI SSE `stream_options` fix, schedule-preset + playbook-orphan fixes, CRM activity/due-date fixes |
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

> **Note:** Some features require third-party plugins (WooCommerce, JetEngine, Elementor, etc.). See [🔌 What You Lose Without Third-Party Plugins](#-what-you-lose-without-third-party-plugins) for details.

### Assistant & conversation tools
- 🧠 Create AI Assistants via a custom post type (`mcp_ai_assistant`)
- 👔 **Professional & Team Templates** - Deploy assistants from ~311 pre-built profession templates spanning 12 industry categories, or create entire teams of specialists with one click. Includes backend testing for professions, teams, and assistants before public deployment.
- 🚀 **Getting Started Wizard** - Guided 4-step onboarding (`/wp-admin/admin.php?page=wp-mcp-ai-getting-started`) that walks new users through provider setup and use-case selection. Selecting a preset (Content Creator, Customer Support, E-commerce, SEO & Research, Developer Copilot, Media & Creative Studio, Site Administrator, or General Purpose) seeds a fully-configured assistant with tools, system prompt, and tuned temperature — ready to use immediately.【F:includes/admin/class-wp-mcp-ai-onboarding-wizard.php†L1-L53】【F:assets/js/onboarding-wizard.js†L1-L303】
- 🔄 Automatic synchronization to JetEngine Custom Content Types when available (CPT → CCT)
- 💬 Chat interface via `[mcp_ai_chat assistant="ID"]`
- 🧰 Per-assistant defaults for model, temperature, system prompt, and knowledge attachments with permission-aware download URLs
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
| Update WooCommerce Product Price 🌟 | `update_woo_product_price` | Updates regular/sale prices across all product types (simple, variable via variations, grouped, external) with sale-date scheduling, validation, and automatic variable-parent sync. **Pro addon tool**.【F:addons/pro/includes/tools/ecommerce/class-wp-mcp-ai-tool-update-woo-product-price.php†L1-L50】|
| Update WooCommerce Product Quantity 🌟 | `update_woo_product_qty` | Updates stock quantity across all stock-managed types with set/increase/decrease operations, canonical low-stock notifications (suppressible per call via `notify: false`), and stock-status sync. **Pro addon tool**.【F:addons/pro/includes/tools/ecommerce/class-wp-mcp-ai-tool-update-woo-product-qty.php†L1-L50】|
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

## 🗨 Front-end chat surfaces

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

### 🌱 Try It on Your PC

> **No live site. No API costs. No risk.** The primary way to test NV oOS is a one-command demo that boots WordPress and the Complete bundle locally via WordPress Playground and wires the chat to your **local Ollama** — no API key, no server, and no prompts leave your machine.

#### ⚡ Fastest: One Command (Playground + Local Ollama)

**One-time prerequisites:**

1. Install [Ollama](https://ollama.com/download) and pull a model:
   ```bash
   ollama pull llama3.1:8b
   ```
2. Allow the local origin, then restart Ollama:
   - **Windows:** `setx OLLAMA_ORIGINS "https://playground.wordpress.net,http://localhost,http://127.0.0.1"`, then quit Ollama from the system tray and relaunch it.
   - **macOS / Linux:** `OLLAMA_ORIGINS="https://playground.wordpress.net,http://localhost,http://127.0.0.1" ollama serve`

Then run this command (requires [Node.js](https://nodejs.org/) 20+):

```bash
npx -y @wp-playground/cli@3.1.54 server --blueprint=https://raw.githubusercontent.com/nvdigitalsolutions/mcp-ai-wpoos/alpha-working/blueprints/ollama-demo.json --login
```

Open the printed local URL (e.g. `http://127.0.0.1:9400/ollama-test-lab/`). The first boot takes a few minutes while it downloads and installs the whole stack — after that you land on the **Ollama Test Lab**: a live status banner plus a chat that answers from your local model. Admin login: `admin` / `password`.

Full walkthrough, browser quick-preview link, and troubleshooting: **[docs/user-guides/playground-demo.md](docs/user-guides/playground-demo.md)**.

#### 🧰 Classic 3-Step Install (Local, Studio, XAMPP, etc.)

Prefer a traditional local WordPress install? Follow the full walkthrough on the NV Digital Solutions blog: **[How to Test NV oOS on Your Own PC Using Local + Downloading the Plugin →](https://nvdigitalsolutions.com/ai-llm/how-to-test-nv-oos-on-your-own-pc-using-local-downloading-plugin/)**

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
2. You now have the **complete version** with all ~1,665 tools (~352 base + ~1,313 Pro; live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative)

**What you get from the repository clone:**

- ✅ The full codebase — all ~1,665 built-in tools ready to use (~352 base + ~1,313 Pro; live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative)
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
- ✅ ~300 base tools (more with optional third-party plugins)
- ✅ MCP server functionality (`/wp-json/mcp-ai/v1/`)
- ✅ Browser-based chat history (localStorage, 24 hours)
- ✅ OpenAI/Gemini/Anthropic/Ollama/Hugging Face/Cloudflare integrations

**What requires JetEngine:**
- ❌ Server-side chat transcript storage (chat history only in browser without it)
- ❌ 5 JetEngine-specific tools (see [🔌 Optional Tools & Dependencies](#-optional-tools--dependencies))

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

**✅ Still Works:** All core features, MCP server, ~300 base tools, AI conversations

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

**Total Impact:** Without these plugins, you lose **13 tools** but retain **~300 base tools** and all essential AI assistant functionality.

---

### Base Version (Default)

**NV oOS runs in Base Version mode by default**, providing ~300 essential tools that work with vanilla WordPress without requiring any third-party plugins:

**Base Version includes ~300 essential tools that work with vanilla WordPress:**
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
- **Third-party WordPress plugins** (13 tools) - See [🔌 What You Lose Without Third-Party Plugins](#-what-you-lose-without-third-party-plugins) for details
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

📖 **See detailed breakdown:** [🔌 What You Lose Without Third-Party Plugins](#-what-you-lose-without-third-party-plugins)


---

## 📚 Documentation

NV oOS includes comprehensive documentation covering all aspects of the plugin. **Documentation reorganized June 2026** — Unix-theory separation of concerns. All docs sorted into 12 purpose-driven directories with zero content loss.

### 📖 Documentation Hub
- **[Documentation Hub](docs/README.md)** ⭐ **Start here** - Central navigation with organized categories
- **[Documentation Index](docs/DOCUMENTATION_INDEX.md)** - Complete map of all 1,600+ documentation files
- **[Architecture Overview](docs/developer/architecture/ARCHITECTURE.md)** - System architecture (18 providers, ~1,665 tool classes, 36 REST controllers)
- **[Request Flow Walkthrough](docs/developer/architecture/REQUEST-FLOW-WALKTHROUGH.md)** - End-to-end chat request lifecycle trace
- **[Quick Reference Guide](docs/QUICK_REFERENCE.md)** - Fast access to common tasks and commands

### Essential References
- **[Tool Reference](docs/reference/tools/tool-reference.md)** - All ~1,665 tools documented (~352 base + ~1,313 Pro; live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative)
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

## ⚙ Configuration Checklist (Action Items)

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
- See [LM Studio setup guide](#lm-studio-setup) for configuration details

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

## 🕵 Code Review

The 2025-10-31 internal review confirms the hardening of the group email automation (header filtering and attachment caps) and the case-sensitive variable handling in the OpenAI external action tool, and only flags a low-severity performance concern around guest token transient churn for public chat embeds. These findings have been consolidated into the master code review document. One follow-up action item recommends re-using or rate-limiting guest tokens to keep the options table tidy on cache-less hosts.

➡️ See [docs/developer/best-practices/CODE-REVIEW-MASTER.md](docs/developer/best-practices/CODE-REVIEW-MASTER.md) for the complete code quality assessment.

## 🔒 MCP Server Authentication

Remote MCP assistants should authenticate with Auth0-issued bearer tokens (`Authorization: Bearer YOUR_TOKEN`) whose audience and scope align with the values configured under **Settings → NV oOS**. Same-origin experiences (the dashboard editor and shortcode UI) continue to rely on the `X-WP-Nonce` header tied to the logged-in WordPress session. Review [docs/reference/api/mcp-server-authentication.md](docs/reference/api/mcp-server-authentication.md) for a complete setup guide plus a breakdown of the structured error responses returned on failure, and keep the [deployment troubleshooting checklist](docs/getting-started/installation-setup/deployment-troubleshooting.md) handy when diagnosing capability or credential regressions.

**MCP transports (v1.1.55+):** `POST /wp-json/mcp-ai/v1/mcp` speaks JSON-RPC 2.0 over **Streamable HTTP** by default. Legacy **HTTP+SSE** clients (SSE-only `Accept: text/event-stream` or `?stream=true`) get a credential-bound session handshake from `GET /mcp` and receive responses as `event: message` on the GET stream — enable with `WP_MCP_AI_LEGACY_SSE_ENABLED`. JSON-RPC **errors return HTTP 200** with the `{"jsonrpc","id","error"}` envelope so agent SDKs that drop non-2xx bodies relay tool errors instead of hanging; auth/permission failures keep real HTTP statuses. Assistant credential headers may be sent as `Authorization: Bearer cred_xxxxx.SECRET` or raw `Authorization: cred_xxxxx.SECRET`. Tool-call traffic is governed by the settings-driven tool rate limiter (credential tokens exempt by default), and GET/HEAD discovery probes never consume the request quota. See [`docs/developer/implementation-plan-mcp-agent-compat.md`](docs/developer/implementation-plan-mcp-agent-compat.md) and [`docs/developer/legacy-sse-transport-plan.md`](docs/developer/legacy-sse-transport-plan.md) for the full rationale.

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

**SSE Support:** All MCP endpoints support Server-Sent Events (SSE) for real-time streaming. Enable SSE in your client configuration for better response times and real-time updates. See the [SSE Streaming Support](#-sse-streaming-support) section for details.

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

### 67 Pre-Built Skills (Base Plugin)

The **base plugin** ships with 67 pre-built skills that are automatically installed to `wp-content/uploads/mcp-ai-skills/` on first activation. No Pro add-on is required — they are available on every install out of the box. The skills include 24 general-purpose tools (document handling, design, testing), 22 WordPress developer skills (security, APIs, plugin patterns), 21 design-* skills (analytics through video creation), and the bundled mcp-ai-wpoos-plugin skill.

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
- Follow the scenarios in [## ✅ Manual QA Scenarios](#-manual-qa-scenarios) after significant changes to chat flows, tool execution, or authentication wiring.
- For logging-centric debugging, enable logging in the NV oOS settings and reference the retrieval commands in [🪵 Logging](#-logging).

---

## ⚙ CI/CD Pipelines

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
| `sync-nvoos-*.yml` | Monorepo subtree sync to standalone repos (9 workflows) |
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

**📖 See the complete breakdown:** [🔌 What You Lose Without Third-Party Plugins](#-what-you-lose-without-third-party-plugins)

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
| `wp mcp-ai provider list` | Lists all AI providers with enabled/disabled status. Also supports `test <slug>` and `models <slug>` subcommands. |
| `wp mcp-ai cron list` | Lists scheduled cron jobs tracked by NV oOS. Also supports `run <job-id>` and `delete <job-id>` subcommands. |
| `wp mcp-ai transcript list` | Lists transcripts eligible for mining. Use `--assistant` to filter. Also supports `mine` subcommand for batch mining. |
| `wp mcp-ai approval list` | Lists pending human-in-the-loop approval items. Accepts `--format` flag. Also supports `approve` and `reject` subcommands with `--item-id`. |
| `wp mcp-ai tool list` | Lists all registered tools with status and capability. Accepts `--status`, `--format`, and `--fields` flags. Also supports `enable <slug>`, `disable <slug>`, and `call <slug> --args='{"…":…}'`. |
| `wp mcp-ai assistant list` | Lists all AI assistants. Accepts `--status` and `--format` flags. |
| `wp mcp-ai credential list` | Lists API credentials configured for an assistant. |
| `wp mcp-ai slash list` | Lists registered slash commands. Also supports `execute <command>` and `help <command>`. |
| `wp mcp-ai settings get` | Retrieves all NV oOS settings. |
| `wp mcp-ai cache clear` | Clears the NV oOS object cache. |
| `wp mcp-ai security posture` | Security posture score with every signal. Also supports `audit`, `purge-audit`, `gate`, and `keys`. |
| `wp mcp-ai model list --provider=<slug>` | Lists catalog models per provider. Also supports `suggestions` and `discover`. |

> **Full reference:** see [`docs/operations/wp-cli.md`](docs/operations/wp-cli.md) for the
> complete Base + Pro + addon command tree, capability matrix, and scripting
> patterns.

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
- [ ] Review the [common issues section](#common-issues) below
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


