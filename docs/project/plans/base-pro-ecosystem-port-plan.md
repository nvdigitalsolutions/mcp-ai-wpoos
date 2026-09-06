# Base+Pro → Content Graph Ecosystem — Phased Porting Plan

**Date:** 2026-09-01
**Status:** Proposed (awaiting approval)
**Scope:** Complete the base+pro port into `nvoos-content-graph` / `nvoos-content-graph-ai` / `nvoos-content-graph-ai-platform`, including **all UIs** and the **Pro SPA v2** (locked decisions D-UI, D-SPA) — **purely additively**: zero code changes to `mcp-ai-wpoos` (base) and zero code changes to `nvoos-content-graph` (core) (locked decisions D-NOBASE, D-NOCORE).
**Depends on:** [`base-pro-ecosystem-port-gap-analysis.md`](../proposals/base-pro-ecosystem-port-gap-analysis.md) — the gap inventory this plan executes.
**Supersedes:** nothing (first plan for this scope). The extraction plan [`content-graph-platform-extraction-plan.md`](content-graph-platform-extraction-plan.md) is **complete and merged** (PR #6123); this plan is its successor.
**Source of truth for the target architecture:** [`nvoos-base-restructuring-roadmap.md`](../proposals/nvoos-base-restructuring-roadmap.md) §3/§5.

---

## 1. Guiding principles (carried over from the extraction plan)

0. **Additive-only port** — zero code changes to `mcp-ai-wpoos` (base) and `nvoos-content-graph` (core). The ecosystem plugins consume existing public surfaces (settings injection, tool registry, adapters) and stand down whenever the base is active (`! defined('WP_MCP_AI_PATH')`). Legacy base copies are retained permanently; no base/core version bumps, deletions, or shims are ever made for the port.
1. **Data stability is sacred** — option keys, table names, CPT slugs, post-meta keys, REST routes, hook names stay byte-identical. Asset handles/enqueue slugs are also public surface; only internal asset URLs may change.
2. **Hook names are public surface** — `wp_mcp_ai_*` hooks keep firing with identical payloads in monolith installs (base plugin present).
3. **Behaviour-preserving, not behaviour-improving** — no "fix while moving"; bug fixes ship separately.
4. **Shim-free, retain-forever** — the base keeps its copy untouched (D-NOBASE); the ecosystem plugin owns wiring only when the base has not booted (`! defined('WP_MCP_AI_PATH')`). Two copies coexist permanently; the discriminator is the only guard needed.
5. **One subsystem per PR** — characterization tests before the move, green CI after.
6. **PHP floors** — ecosystem plugins PHP 8.1+. No base-side shims are ever added (D-NOBASE), so the base's PHP 7.4 floor is unaffected.
7. **Never break the monolith suites** — root `composer run test` and every ecosystem suite green at every merge.
8. **Docs move with code** — folder READMEs, `AGENTS.md` inventory, and the new ecosystem tracker update in every PR.
9. **UI ports are contracts too** — the SPA v2 and chat UI act as characterization consumers for the REST/SSE surface they consume (REST parity is proven by the UI working unmodified).

---

## 2. Distribution map (who owns what after the port)

| Layer | Plugin | Runtime + UI ownership |
|---|---|---|
| Graph + admin base | `nvoos-content-graph` | **Unchanged (D-NOCORE)** — graph engine, SettingsRegistry, `GraphToolAdapter`, tool-registry hooks are consumed as-is through their existing public surfaces |
| AI engine | `nvoos-content-graph-ai` | Chat runtime, providers, model mgmt, analytics/token/cost, **security stack**, REST chat/tools surface, WP-CLI, **chat UI family** (widget, bubble, shortcodes, chat blocks, chat Elementor widgets), **assistant CPT + builder/test pages**, **settings shell + sections**, **SPA v2 shell**, engine pieces per decision D4 |
| Operator platform | `nvoos-content-graph-ai-platform` | Extracted subsystems (done) + workflow engine, queues/ops, approvals, tenant, integrations, A2A receive routes, **operator admin UI** (dashboards, managers, approvals); its settings sections register into the CG-AI shell via a public hook |
| Pro | pro addon(s) per decision D5 | ~1,256 pro tools, 50 toolkits, node-service bridges, pro admin screens, SPA v2 pro-toolkit slices |
| Extensions | per decision D3 | Elementor/Woo/JetEngine/RankMath/WPCode integration layers |

---

## 3. Phased execution

### Phase 0 — Foundations and decision closure (1–2 weeks)

**Goal:** resolve the blocking decisions, generalize the ownership mechanism, stand up the CI/asset infrastructure the waves need.

| # | Item |
|---|---|
| 0.1 | Close the remaining decisions D3–D6 (gap analysis §10); D1/D2/D7 are resolved by the locked constraints D-NOBASE/D-NOCORE |
| 0.2 | Ownership: keep the existing `! defined('WP_MCP_AI_PATH')` discriminator (works with zero base changes); optionally add an ecosystem-side `wp_mcp_ai_platform_owns()` helper in CG-AI consumed by the platform |
| 0.3 | Ecosystem composition roots: define `Service::register()` wiring conventions for CG-AI and the platform mirroring `MeasurementService`/`TeamsService` |
| 0.4 | CI: extend `phpunit-platform.yml` → ecosystem matrix jobs; add PHPUnit suites for `nvoos-content-graph-ai` (none exist today); the core plugin needs no suite changes (D-NOCORE) |
| 0.5 | Asset pipeline: establish the ecosystem npm workspace (`chat.js` build, SPA v2 esbuild/vitest) and asset-handle naming convention |
| 0.6 | Living tracker: create `docs/project/ecosystem-port-tracker.md` (extends the platform's `MIGRATION-GAPS.md` scope to the whole ecosystem) |

**Exit gate:** decisions recorded, monolith + standalone matrices green with the ownership pattern unchanged, tracker live, asset pipeline builds a smoke bundle in CI.

### Wave D — AI runtime → `nvoos-content-graph-ai` (4–6 weeks)

One PR per sub-wave; characterization tests against the base implementation first.

| Sub-wave | Scope (base source) |
|---|---|
| D1 | Chat runtime: `EnhancedOpenAiClient`, `LanguageModelRouter`, `ModelSelector`, `ModelConfig`, `PromptOptimizer`, `ChatResponseCache`, `SemanticCache`, `ConversationSummarizer`, `ConversationRagBridge`, `ThreadManager`, `ChatTranscriptRecorder`, `TranscriptRetention`, `QuickActionsHandler`, `MessageAttachments`, `ResponseAttachments`, `SseStream`, `SseRateLimiter`, chat-continuation, chatkit integration |
| D2 | Providers: Zai, Google Maps, RabbitMQ, StdioTransport, OpenAI Realtime ×3 (SaaS drivers FlowHub/PayHere/Printful → Pro, gap §4.2) |
| D3 | Model management + analytics: catalog migration, integrity verifier, pricing checker, rate-limits CCT, `AnalyticsEngine`, `UsageTracker`, token tracking (DB, enhanced, optimizer, limits) |
| D4 | Security stack: the 10 `includes/security/` classes + provider circuit breaker — CG-AI owns the runtime guard; keep CSP/audit hooks byte-identical |
| D5 | REST surface: port the `mcp-ai/v1` controllers (chat, tools, assistants, MCP methods) onto CG-AI's auth; A2A receive route lands in the platform (sub-wave E5) |
| D6 | WP-CLI: 20+ commands under `wp nvoos-cg-ai *` (base command names aliased during transition) |
| D7 | Agent memory CCT bridge/migrator/reader |
| D8 | Tool execution: port the base tool inventory and the `tools/call` execution path — cluster plan: `docs/project/plans/d8-tool-execution-port-plan.md` (execution spine, registration of the 148 pre-ported `lib/core` tools, port of the ~46 self-contained missing tools, harness tools in the platform, deferred plugin/SaaS/engine buckets); scope: `includes/tools/` (259 tool class files + sub-directories; 271 unique slugs) + the 24 `*_validated` wrappers + the 2 side-loader tools + the 8 harness tools, onto the nvoos-core registry seam (`CoreBridge`); wire `tools/call` dispatch in `McpController` (today 503 `wp_mcp_ai_mcp_unavailable` — D5c deviation), assistant tool scoping, and the canonical envelope + two-gate sanitisation contract per tool |

**Exit gate:** monolith + standalone + meta matrices green; root suite untouched; a live chat turn through CG-AI matches the base path output for the same assistant config.

### Wave D-UI — AI UI → `nvoos-content-graph-ai` (+ core) (2–3 weeks)

| Sub-wave | Scope (source) |
|---|---|
| D-UI-1 | Chat widget: `assets/js/chat.js` + enqueue + accessibility-enhancements, guest-token flow, localStorage transcript persistence |
| D-UI-2 | Chat bubble frontend + `chat-bubble` / `chat` blocks + shortcodes (chat, status, professional-selector) |
| D-UI-3 | Elementor chat-family widgets (chat-bubble, chat-faq, chat-intro, chat-usage-timer, quick-actions, professional-selector) — gated on `elementor/loaded`; other widgets follow their subsystems (decision D3) |
| D-UI-4 | Assistant builder/test/add pages + create-assistant modal + assistant-selector/builder blocks → `content-graph-ai` (D-NOCORE resolves the old core-home option); the CPT registers only when the base plugin is absent |
| D-UI-5 | Settings shell + sections → `content-graph-ai` (shell, renderer, registry, validator + sections); the platform registers its sections via a public hook; core's `SettingsRegistry` is consumed, never modified |
| D-UI-6 | Assistant editor + metadata: port the base's 14 assistant metaboxes (4 inline + 10 dedicated classes in `includes/assistants/metaboxes/` — Credentials, Defaults, Primary_Roles, Base_Knowledge, Mesh_Routing, Datasets, Skills, MCP_Apps, Harness_Profile, Artifact_Governance); complete `register_meta` parity for the base's ~25 meta keys; wire assistant-config consumption into the chat flow (assistant_id → system prompt/model/temperature/tools); credential issuance or an explicit token-scoping deferral |

**Exit gate:** the same shortcode/block markup renders in the ecosystem plugin with identical DOM handles; Elementor widgets register under identical widget IDs.

### Wave E — Operator runtime → `nvoos-content-graph-ai-platform` (3–4 weeks)

| Sub-wave | Scope (base source) |
|---|---|
| E1 | Workflow engine: `WorkflowEngineV2`, workflow/run/trigger CPTs, dispatcher, trigger registry, pattern registry/constants/templates, agentic-workflow-optimizer |
| E2 | Queues/ops: `AsyncJobQueue`, `JobQueueManager`, `QueueManager`, `DeadLetterQueue`, `RateLimitManager`, `SlaManager`, `CronManager`, `JobNotifier` + REST, outbound webhook |
| E3 | Approvals: `ApprovalQueue` + audit wiring |
| E4 | Tenant + integrations: `includes/tenant/`, OAuth manager, Google Calendar, content-assistant, site-builder, conversation-import |
| E5 | A2A REST receive routes (closes the MIGRATION-GAPS deferred gap) |
| E6 | Engine pieces per decision D4 (OOS/markup/paper-store/OKF/crawler) if folded into CG-AI |

**Exit gate:** cron/queue jobs scheduled by the platform fire and complete in standalone mode; workflow runs round-trip through the ported engine.

### Wave E-UI — Operator admin UI → platform (2–3 weeks)

| Sub-wave | Scope (base source) |
|---|---|
| E-UI-1 | Dashboards: multi-agent, orchestration, slash-commands, run timeline (analytics dashboard → CG-AI per §2) |
| E-UI-2 | Managers: tool manager, token manager, cron manager, DAG builder, DLQ manager, approvals UI, media-library columns, asset inventory |
| E-UI-3 | Integrations screens: profession/team research + settings, Elementor/JetEngine/WooCommerce admin pages (follow D3) |
| E-UI-4 | Pro platform-tier UI: schedule manager + presets, workflow presets (pro addon — Wave F) |

**Exit gate:** every ported admin page renders and saves state in standalone mode; no dead menus (extraction end-state C extended to UI).

### Wave F — Pro runtime → pro addon(s) (8–12 weeks, parallelizable)

Packaging per decision D5 (recommended: one consolidated `nvoos-content-graph-pro` first, split into the roadmap's 10 later).

| Sub-wave | Scope (`addons/pro/includes/`) |
|---|---|
| F1 | pro-core: module registry + PSR-4 autoload, vault, vector-storage, skills-manager, toolkit data-store factory, privacy |
| F2 | pro-business: CRM, e-commerce, project management, financial, calendar-booking, social (50 tool dirs) |
| F3 | pro-media + pro-dev: image/video/comic/media toolkits, architect agent, architectural design, site creator, AI tool builder, developer tools, math |
| F4 | pro-healthcare + pro-legal: health & wellness, imaging (DICOM), vitals, law firm, CRE debt, regulatory |
| F5 | pro-education + pro-content: quiz, ECA, document generation, multilingual, chat channels (Slack/Teams/Discord/WhatsApp/Telegram) |
| F6 | pro-data + pro-platform: analytics, extended cognition, places, orchestration, research (research-add), cloudways, Google Workspace, email marketing, remote connections, capture, DietPi, Composio, mcp-apps/mcp-servers, schedule-anything |
| F7 | Services bridges: the 25+ node/python sidecars stay external; port their PHP bridges (`services/`, `nv-cloud`, `cloudways`) and health checks |

**Exit gate:** pro toolkit flags (`enable_crm_toolkit`, …) reproduce tool counts in the ecosystem registry; every pro CPT registers with byte-identical slugs.

### Wave F-UI — Pro UI + SPA v2 (3–5 weeks)

| Sub-wave | Scope (source) |
|---|---|
| F-UI-1 | **SPA v2 build pipeline**: move `@nvoos/pro-spa-v2` (esbuild/tsc/vitest/eslint) into the ecosystem npm workspace; CI build + typecheck + unit tests |
| F-UI-2 | **SPA v2 runtime port**: React app against the ported REST/SSE surface (REST parity gate from D5); `ProCdnLoader`/`ProSpaLoader` equivalents in the owning plugin; retire legacy `spa/` after `chat-spa-v2-parity-plan.md` verification |
| F-UI-3 | Pro toolkit UI: blocks/shortcodes/inline assistant, pro admin screens (schedule manager, workflow presets, maintenance/imaging/incident screens) |
| F-UI-4 | Pro toolkit slices inside SPA v2 gated on the pro addon being active |

**Exit gate:** SPA v2 built from the ecosystem workspace serves a chat turn end-to-end against a standalone install (no base plugin present).

### Wave G — Release & distribution (1 week, release-gated)

**No base/core changes happen here — the monolith is simply left as the legacy product (D-NOBASE, D-NOCORE).**

| # | Item |
|---|---|
| G1 | Ecosystem version bumps + `Requires Plugins` chain audit (core ← AI ← platform ← pro/bundle) |
| G2 | wp.org "Base" tier mapping (decision D6) — documentation/distribution only; the open-source tier ships as a subset of the ecosystem plugins |
| G3 | Optional **new** bundle plugin (never the base): a `Requires Plugins` wrapper for the ecosystem chain |
| G4 | Docs sweep: roadmap §7 status, `AGENTS.md` inventory, folder READMEs, migration guide (base → ecosystem switch-over), retirement of stale proposals |

**Exit gate:** a fresh install of the ecosystem chain passes all suites with the base plugin absent; the base plugin (untouched) and its suite remain green; migration guide reviewed.

---

## 4. Parallelization

Disjoint write scopes (max 4 agents):

- Wave D sub-waves D2/D3/D6 can run concurrently (different top-level files); D1 must precede D5 (REST depends on the runtime).
- Wave E-UI depends on Wave E runtime per subsystem, but E-UI dashboards can start after E2/E3 land.
- Wave F sub-waves are disjoint by toolkit dirs — F2–F6 parallelize freely; F1 (pro-core) first.
- D-UI and E-UI share no files; run concurrently with D2/D3 and E2/E4.
- No base-side coordination points exist under D-NOBASE — ecosystem plugins only read `defined('WP_MCP_AI_PATH')`, so waves can land in any order.

## 5. Risks

| Risk | Mitigation |
|---|---|
| REST parity drift breaks the SPA | SPA v2 doubles as the characterization consumer (D-UI/E-UI gates); contract tests pin route/response shapes before D5 |
| Chat UI port breaks guest tokens/auth | Port `GuestToken` + nonce flow in D-UI-1 with the existing chat-UI test file; keep header names identical |
| Dual live copies (base + ecosystem) of CPTs/settings when both are activated | The `! defined('WP_MCP_AI_PATH')` discriminator prevents double registration (extraction-proven); monolith + standalone matrices stay in CI per wave |
| Pro node services outlive the PHP port | F7 keeps sidecars external; bridges are the portable seam (`services/` already factored) |
| Text-domain split breaks translations | Ecosystem plugins ship their own text domains from day one (no coexistence aliasing needed under D-NOBASE); note in the migration guide that translations are per-plugin |
| Monolith suite regressions | Base code is never modified (D-NOBASE) — root-suite regression risk is near zero; the monolith matrix still runs per wave as a coexistence check |
| Standalone-repo sync breakage | No monorepo-only deps in ported plugin files (gap §8.8); verify sync workflows per wave |

## 6. Timeline (estimate)

| Phase | Work | Est. |
|---|---|---|
| 0 | Foundations + decisions | 1–2 wks |
| D | AI runtime | 4–6 wks |
| D-UI | AI UI | 2–3 wks |
| E | Operator runtime | 3–4 wks |
| E-UI | Operator admin UI | 2–3 wks |
| F | Pro runtime | 8–12 wks (parallel) |
| F-UI | Pro UI + SPA v2 | 3–5 wks |
| G | Release & distribution | 1 wk (release-gated) |
| | **Total** | **~24–36 weeks** (≈6–9 months, partially parallelizable) |

## 7. Docs the plan updates

- New: `docs/project/ecosystem-port-tracker.md` (living tracker, Phase 0.6).
- Updated per wave: `docs/project/proposals/base-pro-ecosystem-port-gap-analysis.md` (statuses), `plugins/*/README.md` + folder READMEs, `AGENTS.md` inventory, `RELATED_PROPOSALS_INDEX.md` entries.
- Superseded at release: the extraction plan's Phase 5 (base-copy deletion, meta-plugin, base 2.0.0) is **cancelled** by D-NOBASE/D-NOCORE — the tracker and roadmap §7 status must say so explicitly.
