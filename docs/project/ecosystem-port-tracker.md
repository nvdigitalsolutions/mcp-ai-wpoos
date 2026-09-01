# Ecosystem Port Tracker

**Living tracker for the additive base+pro → Content Graph ecosystem port.**
Last updated: 2026-09-01 · Plan: [`base-pro-ecosystem-port-plan.md`](plans/base-pro-ecosystem-port-plan.md) · Gaps: [`base-pro-ecosystem-port-gap-analysis.md`](proposals/base-pro-ecosystem-port-gap-analysis.md)

Locked constraints: **D-NOBASE** (zero changes to `mcp-ai-wpoos`), **D-NOCORE** (zero changes to `nvoos-content-graph`), **D-UI** (all UIs ported), **D-SPA** (Pro SPA v2 ported).

| Status | Meaning |
|---|---|
| ✅ Done | Ported, wired, tested in monolith + standalone matrices |
| 🟡 In progress | PR open or partially wired |
| 🔴 Gap | Planned, no implementation yet |
| ⏸️ Deferred | Decision-parked (see plan) |

## Phase 0 — Foundations

| Item | Status | Notes |
|---|---|---|
| Ownership discriminator (`! defined('WP_MCP_AI_PATH')`) | ✅ | Extraction-proven; no base changes |
| AI addon test suite + monolith/standalone CI matrices | ✅ | `plugins/nvoos-content-graph-ai/{phpunit.xml.dist,tests/}`, `.github/workflows/phpunit-ai.yml` |
| Ecosystem asset pipeline (npm workspace) | 🔴 | Phase 0.5 |
| Tracker (this file) | ✅ | Phase 0.6 |

## Wave D — AI runtime → `nvoos-content-graph-ai`

| # | Subsystem | Status | Notes |
|---|---|---|---|
| D1a | Prompt optimizer (cache-hit ordering, cache key, prompt split) | ✅ | `src/Chat/PromptOptimizer.php`; wired into `Rest\ChatController` via `cache_system_prompt` request arg; 10 characterization tests |
| D1b | Chat response cache + SSE rate limiter + semantic cache | ✅ | `src/Chat/{ChatResponseCache,SseRateLimiter,SemanticCache}.php`; wired into `Rest\ChatController` (cache lookup/store on the non-streaming path, 429 + register/release on the streaming path); 20 characterization tests; SemanticCache ships dormant (matches base — no call sites) |
| D1c | Conversation summarizer (BME context strategy) | ✅ | `src/Chat/ConversationSummarizer.php` (decoupled client contract) + `src/Chat/OrchestratorCompletionClient.php` (nvoos/core adapter); 12 characterization tests; wiring into the chat flow follows with the full BME strategy port |
| D1d | Thread manager (threads/messages/checkpoints CRUD + schema) | ✅ | `src/Chat/ThreadManager.php` (byte-identical tables/error codes/envelopes); schema created on CG-AI activation; 13 characterization tests with real DDL |
| D1 | Chat runtime core (clients, router, selector, caches, summarizer, RAG bridge, threads, transcripts, SSE, attachments, quick actions) | 🟡 | D1a–D1d done; attachments + transcript recorder/retention + chatkit next |
| D2 | Providers beyond the 13 (Zai, Google Maps, RabbitMQ, Stdio, Realtime ×3) | 🔴 | SaaS drivers → Pro (gap §4.2/8.3) |
| D3 | Model management + analytics/token tracking | 🔴 | |
| D4 | Security stack (10 classes + circuit breaker) | 🔴 | |
| D5 | REST surface (mcp-ai/v1 chat/tools/assistants/MCP methods) | 🔴 | |
| D6 | WP-CLI surface (20+ commands) | 🔴 | |
| D7 | Agent memory CCT bridge/migrator/reader | 🔴 | |

## Wave D-UI — AI UI → `nvoos-content-graph-ai`

| # | Subsystem | Status | Notes |
|---|---|---|---|
| D-UI-1 | Chat widget (chat.js) + guest token flow | 🔴 | |
| D-UI-2 | Chat bubble + blocks + shortcodes | 🔴 | |
| D-UI-3 | Elementor chat-family widgets | 🔴 | |
| D-UI-4 | Assistant builder/test/add pages | 🔴 | |
| D-UI-5 | Settings shell + sections | 🔴 | |

## Wave E — Operator runtime → `nvoos-content-graph-ai-platform`

| # | Subsystem | Status | Notes |
|---|---|---|---|
| E1 | Workflow engine + CPTs + triggers | 🔴 | |
| E2 | Queues/ops (jobs, DLQ, SLA, cron, notifier) | 🔴 | |
| E3 | Approvals | 🔴 | |
| E4 | Tenant + integrations (OAuth, Google Calendar, site-builder, conversation-import) | 🔴 | |
| E5 | A2A REST receive routes | 🔴 | Closes MIGRATION-GAPS deferred gap |
| E6 | Engine pieces (OOS/markup/paper-store/OKF/crawler) per decision D4 | 🔴 | |

## Wave E-UI — Operator admin UI → platform

| # | Subsystem | Status | Notes |
|---|---|---|---|
| E-UI-1 | Dashboards (multi-agent, orchestration, slash-commands, run timeline) | 🔴 | |
| E-UI-2 | Managers (tool/token/cron/DAG/DLQ/approvals) | 🔴 | |
| E-UI-3 | Integrations screens | 🔴 | |

## Wave F — Pro runtime → pro addon(s) (decision D5)

| # | Subsystem | Status | Notes |
|---|---|---|---|
| F1 | pro-core (module registry, vault, vector-storage, skills-manager) | 🔴 | |
| F2–F6 | business / media / dev / healthcare / legal / education / content / data / platform toolkits | 🔴 | |
| F7 | Node-service bridges | 🔴 | |

## Wave F-UI — Pro UI + SPA v2

| # | Subsystem | Status | Notes |
|---|---|---|---|
| F-UI-1 | SPA v2 build pipeline in ecosystem npm workspace | 🔴 | |
| F-UI-2 | SPA v2 runtime port + loaders | 🔴 | |
| F-UI-3 | Pro toolkit blocks/shortcodes/inline-assistant + admin screens | 🔴 | |
| F-UI-4 | SPA v2 pro-toolkit slices | 🔴 | |

## Wave G — Release & distribution (release-gated)

| # | Item | Status | Notes |
|---|---|---|---|
| G1 | Ecosystem version bumps + `Requires Plugins` audit | 🔴 | |
| G2 | wp.org Base tier mapping (decision D6) | 🔴 | |
| G3 | Optional NEW bundle plugin | 🔴 | Never the base plugin (D-NOBASE) |
| G4 | Docs sweep + migration guide | 🔴 | |
