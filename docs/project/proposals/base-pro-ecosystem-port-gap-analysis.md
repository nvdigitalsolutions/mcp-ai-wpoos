# Base+Pro → Content Graph Ecosystem Port — Gap Analysis

**Date:** 2026-09-01
**Status:** Proposed (awaiting approval)
**Scope:** Port the remainder of `mcp-ai-wpoos` (base) and `addons/pro` (Pro) into the Content Graph ecosystem — `nvoos-content-graph`, `nvoos-content-graph-ai`, `nvoos-content-graph-ai-platform` — including **all UIs** (admin pages, chat UI, blocks, shortcodes, Elementor widgets) and the **Pro SPA v2**.
**Builds on:** [`content-graph-platform-extraction-plan.md`](../plans/content-graph-platform-extraction-plan.md) (merged via PR #6123, 2026-09-01) and [`nvoos-base-restructuring-roadmap.md`](./nvoos-base-restructuring-roadmap.md) (§3, §5).
**Companion plan:** [`base-pro-ecosystem-port-plan.md`](../plans/base-pro-ecosystem-port-plan.md).

---

## 1. Goal and locked decisions

Port the **whole** base+pro surface into the ecosystem so the roadmap's "either/or" end state covers everything, not just the 10 operator subsystems already extracted. Four owner decisions are locked into this analysis:

- **D-UI (locked):** port **all UIs** — admin pages, dashboards, chat UI, chat bubble, shortcodes, blocks, Elementor widgets, assistant builder/test screens. (This overrides the CG-AI README's earlier "production chat UI not planned" note.)
- **D-SPA (locked):** port the **Pro SPA v2** (`addons/pro/assets/spa-v2/`, React 19 + `@ai-sdk/react`, esbuild/vitest toolchain) into the ecosystem with its build pipeline.
- **D-NOBASE (locked):** **zero code changes to `mcp-ai-wpoos`** — the port is additive. The base keeps working unmodified forever; its copies are never deleted, and no shims/meta-plugin are added to it.
- **D-NOCORE (locked):** **zero code changes to `nvoos-content-graph`** — the ecosystem consumes its existing public surfaces (SettingsRegistry injection, GraphToolAdapter, tool-registry hooks) only. Everything else lands in `nvoos-content-graph-ai` or `nvoos-content-graph-ai-platform`.

---

## 2. Baseline inventory (post-merge, 2026-09-01)

| Codebase | PHP files | Notes |
|---|---|---|
| Base (`includes/`) | 1,166 | 189 top-level classes, 264 tool files, 33 REST controllers, 20+ WP-CLI commands, 10-class security stack, 136 JS assets |
| Pro (`addons/pro/includes/`) | 1,839 | 154 top-level classes + 50 tool-category dirs (~1,256 tools), 25+ node services, spa-v2 |
| `nvoos/core` (lib/core) | 324 | 194 framework-agnostic tools, 15 providers, ChatOrchestrator, ProviderRouter, 9 contracts |
| `nvoos-content-graph` (core) | 78 | Graph engine, SettingsRegistry, admin shell (`Admin/`, `Frontend/`) |
| `nvoos-content-graph-ai` | 32 | 13 providers (via nvoos/core), 13 AI tools, chat tester, embeddings/RAG, AgentMemory, CredentialStore |
| `nvoos-content-graph-ai-platform` | 195 | **Extraction merged** — Waves A–C + Blueprints, both CI matrices green |

## 3. Already extracted (merged — not a gap)

A2A, ACP, Agents (role system), Federation + Mesh, Teams, Professions, Skills, Slash Commands, Harness, Measurement, Blueprints — standalone wiring, data-stable keys/hooks/slugs, 169 tests × 2 matrices green. Remaining deferred parity items tracked in `plugins/nvoos-content-graph-ai-platform/MIGRATION-GAPS.md` (A2A REST receive routes, assistant-CPT home).

---

## 4. Gaps in `nvoos-content-graph-ai` (AI engine layer)

| # | Component | Base source | Status |
|---|---|---|---|
| 4.1 | **Chat runtime core** — `EnhancedOpenAiClient`, `LanguageModelRouter`, `ModelSelector`, `ModelConfig`, `PromptOptimizer`, `ChatResponseCache`, `SemanticCache`, `ConversationSummarizer`, `ConversationRagBridge`, `ThreadManager`, `ChatTranscriptRecorder`, `TranscriptRetention`, `QuickActionsHandler`, `MessageAttachments`, `ResponseAttachments`, `SseStream`, `SseRateLimiter`, chat-continuation, chatkit integration | `includes/class-wp-mcp-ai-*.php` | 🔴 None ported; CG-AI's loop is `ChatOrchestrator` from nvoos/core only |
| 4.2 | **Provider clients beyond the 13** — Zai, FlowHub, PayHere, Printful, Google Maps, RabbitMQ, StdioTransport, OpenAI Realtime ×3 | `includes/class-wp-mcp-ai-*-client.php` | 🔴 (SaaS drivers like FlowHub/PayHere/Printful are Pro-tier per `pro-vs-base.md` — see 8.3) |
| 4.3 | **Model management** — catalog migration, integrity verifier, pricing checker, model rate-limits CCT | same dir | 🔴 |
| 4.4 | **Analytics / cost / token tracking** — `AnalyticsEngine`, `UsageTracker`, `CostCalculator`, `TokenTrackingDatabase`, `EnhancedTokenTracking`, `TokenDbOptimizer`, `ToolTokenLimits` | same dir | 🟡 `CostCalculator` exists in nvoos/core; the DB/usage stack doesn't |
| 4.5 | **Security stack (10 classes)** — request guard, security posture, destructive-ops gate, URL guard, concurrency guard, cost tracker, API-key store, CSP headers, audit logger, load guard, provider circuit breaker | `includes/security/` | 🔴 CG-AI has only `Security\CredentialStore` |
| 4.6 | **REST surface** — 33 controllers incl. `mcp-ai/v1` chat/tools/assistants, MCP methods, job-notifier, A2A receive | `includes/rest/`, `class-rest-endpoints.php`, `class-rest-mcp-methods.php` | 🔴 CG-AI has only `Rest\ChatController` |
| 4.7 | **WP-CLI surface** — 20+ commands (assistant, chat, content, provider, memory, settings, thread, measurement, cron, DLQ, log, approval, SLA, restriction, bulk, conversation-import) | `includes/cli/` | 🔴 CG-AI has only key-status/migrate-keys |
| 4.8 | **Agent memory CCT** — bridge/migrator/reader, JetEngine memories CCT | `class-wp-mcp-ai-agent-memory-cct-*.php` | 🔴 CG-AI `AgentMemory` is RAG-only |
| 4.9 | **UI: chat surfaces** (locked D-UI) — see §6 | `assets/js/chat.js` etc. | 🔴 |

## 5. Gaps in `nvoos-content-graph-ai-platform` (operator layer)

| # | Component | Base source | Status |
|---|---|---|---|
| 5.1 | **Workflow engine** — `WorkflowCpt`, `WorkflowRunCpt`, `WorkflowTriggerCpt`, `WorkflowDispatcher`, `WorkflowEngineV2`, `WorkflowTriggerRegistry`, pattern registry/constants/templates, agentic-workflow-optimizer | top-level + `includes/tools/workflow/` | 🔴 |
| 5.2 | **Queues / jobs / ops** — `AsyncJobQueue`, `JobQueueManager`, `QueueManager`, `DeadLetterQueue`, `RateLimitManager`, `SlaManager`, `CronManager`, `JobNotifier`(+REST), outbound webhook, webhook-context-manager (pro) | top-level | 🔴 |
| 5.3 | **Approvals** — `ApprovalQueue` + admin approvals UI | top-level + `includes/admin/` | 🔴 |
| 5.4 | **Tenancy** — `includes/tenant/` | `includes/tenant/` | 🔴 |
| 5.5 | **Integrations** — OAuth manager, Google Calendar, content-assistant, site-builder, conversation-import | `includes/integrations/`, `google/`, `site-builder/`, `conversation-import/` | 🔴 |
| 5.6 | **UI: operator admin** (locked D-UI) — dashboards, managers, approvals UI — see §6 | `includes/admin/` | 🟡 Measurement dashboard already ported; rest 🔴 |
| 5.7 | **A2A REST receive routes** | `includes/rest/class-wp-mcp-ai-rest-a2a-controller.php` | 🟡 Deferred parity gap in MIGRATION-GAPS.md — close it |

## 6. UI port matrix (all UIs — locked decision D-UI)

| UI surface | Source | Target plugin | Notes |
|---|---|---|---|
| Chat widget (`chat.js` + enqueue, accessibility-enhancements) | `assets/js/chat.js` | `content-graph-ai` | Chat-owned; SSE client already exists in CG-AI tester |
| Chat bubble frontend | `class-wp-mcp-ai-chat-bubble-frontend.php` | `content-graph-ai` | |
| Shortcodes — chat, status, professional-selector | `class-*-shortcode*.php` (4 files) | `content-graph-ai` | |
| Blocks — chat, chat-bubble, assistant-builder, assistant-selector, knowledge-base, professional-selector, tools-grid, scheduled-result, performance | `includes/blocks/` | `content-graph-ai` (chat-family); `tools-grid`/`scheduled-result` follow their owning subsystems | |
| Elementor widgets — 26 files (chat-bubble, chat-faq, chat-intro, chat-usage-timer, quick-actions, dashboard ×8, performance ×4, professional-selector, scheduled-result, system-health, telegram-login, test-results + base widget/trait) | `includes/elementor/` | `content-graph-ai` (chat-family), owning plugins for subsystem widgets; roadmap `extensions` addon is the alternative home (decision D3) | |
| Assistant builder + add-assistant + test-assistant/model/profession/team pages + create-assistant/team modals | `includes/admin/class-*-add-assistant*.php`, `-test-*.php` + `assets/js/admin-*.js` | `content-graph-ai` | **D-NOCORE resolves the old D2**: the core home is ruled out; the assistant CPT registers only when the base plugin is absent (`! defined('WP_MCP_AI_PATH')`) |
| Settings shell — settings base/renderer/registry/validator + sections, transparency settings, key rotation | `includes/admin/class-wp-mcp-ai-admin-settings*.php`, `sections/` | `content-graph-ai` | **D-NOCORE**: the shell lands in CG-AI; the platform registers its sections via a public hook. Core's `SettingsRegistry` is consumed as-is (the existing `AiSettingsPage` pattern) |
| Dashboards — analytics, multi-agent, orchestration, slash-commands, run timeline, crawl4ai monitor, markup telemetry | `includes/admin/class-*-dashboard*.php`, `-run-timeline.php`, `-crawl4ai-monitor.php`, `-markup-telemetry-page.php` | analytics → `content-graph-ai`; multi-agent/orchestration/slash-commands/run-timeline → `content-graph-ai-platform`; crawl4ai/markup follow engine decision D4 | |
| Managers — tool manager, token manager, cron manager, DAG builder, DLQ manager, approvals, media-library columns, asset inventory | `includes/admin/class-*-token-manager.php`, `-cron-manager.php`, `-dag-builder.php`, `-dlq-manager.php`, `-approvals.php`, `-media-library-columns.php` | `content-graph-ai-platform` (ops-owned); tool manager follows the tool registry's home | |
| Integrations screens — Elementor/JetEngine/WooCommerce/plugins-integration pages, profession/team research + settings | `includes/admin/class-*-integration.php`, `-profession-*.php`, `-team-*.php` | owning plugins (`profession/team` → platform) | |
| **Pro SPA v2** (React 19, `@ai-sdk/react`, esbuild + vitest, `IMMEDIATE_NEXT_STEPS.md`/`MIGRATION_PLAN.md` docs) | `addons/pro/assets/spa-v2/` (+ legacy `spa/`) | `content-graph-ai` (chat/admin SPA shell) with pro-toolkit slices gated on the pro addons; build pipeline ported as an npm workspace (decision in plan Phase F-UI) | Locked D-SPA |
| Pro admin — schedule manager, workflow presets, maintenance/imaging/incident screens, pro toolkit blocks/shortcodes/inline-assistant, CDN/SPA loaders | `addons/pro/includes/admin/`, `-pro-toolkit-blocks.php`, `-pro-toolkit-shortcodes.php`, `-pro-inline-assistant.php`, `-pro-spa-loader.php`, `-pro-cdn-loader.php` | pro addons (or consolidated pro addon — decision D5) | |

## 7. Pro SPA v2 port specifics (locked D-SPA)

- **Build toolchain:** `esbuild.config.cjs`, `tsconfig.json`, `vitest.config.ts`, `eslint.config.js`, `@nvoos/pro-spa-v2` package — move into the ecosystem plugin that owns the SPA and wire into the repo's npm workspaces/CI (`npm run build`, `typecheck`, `vitest`).
- **Runtime deps:** React 19 + `@ai-sdk/react` stay; the SPA's REST/SSE endpoints (`mcp-ai/v1/*`) must keep working after the REST surface port (§4.6) — the SPA is the characterization consumer for REST parity.
- **Bundle delivery:** `ProCdnLoader`/`ProSpaLoader` port alongside; asset URL constants change from `WP_MCP_AI_URL` to the ecosystem plugin's URL constant (data-stability exception: asset URLs are internal, not public surface — but record it).
- **Legacy `spa/`:** retire, not port (SPA v2 is the replacement; verify parity via `chat-spa-v2-parity-plan.md` before cutover).

## 8. Cross-cutting gaps

| # | Gap | Detail |
|---|---|---|
| 8.1 | **Ownership discriminator** | Ports gate on `defined('WP_MCP_AI_PATH')` (base absent = ecosystem owns wiring). This already works with zero base changes; an optional ecosystem-side `wp_mcp_ai_platform_owns()` helper may generalize it across plugins — no meta-plugin, no extra CI matrix needed |
| 8.2 | **Composition roots** | Base `includes/bootstrap/loader.php` (~900 lines of require blocks) has no ecosystem equivalent — each ported subsystem needs `Service::register()` wiring in its owning plugin |
| 8.3 | **Pro-tier placement** | SaaS/enterprise drivers (FlowHub, PayHere, Printful, Shopify, Upwork, LinkedIn, healthcare, legal, DietPi, vault…) stay Pro per `pro-vs-base.md` decision framework — they land in the pro addons, not the open tier |
| 8.4 | **Data stability** | Option keys, CPT slugs, post-meta keys, hook names byte-identical for every port (PR #6123 discipline); **asset handles and enqueue slugs** are treated as public surface where extensions may depend on them |
| 8.5 | **Characterization tests** | One contract suite per subsystem before moving (extraction principle 5); SPA v2 doubles as the REST characterization consumer |
| 8.6 | **Assets + i18n** | 136 base JS assets + `languages/` POTs; ecosystem plugins ship their own text domains and POTs from day one (no coexistence aliasing under D-NOBASE) — translations do not transfer from the base plugin, so the migration guide must call this out |
| 8.7 | **Base-version flag** | `WP_MCP_AI_BASE_VERSION` (~303 wp.org tools) vs Full — the open-source distribution must be mapped onto the ecosystem plugin set (decision D6) |
| 8.8 | **Subtree sync** | The ecosystem plugins sync to standalone repos (`sync-nvoos-content-graph*.yml`) — ported files must not introduce monorepo-only dependencies (nvoos/core rule 7) |

## 9. Not-started distribution tracks (per roadmap §5)

| Track | Contents | Status |
|---|---|---|
| `engine` pieces | OOS engine, markup, paper-store, OKF, Crawl4AI | 🔴 No plugin — `includes/oos/`, `markup/`, `paper-store/`, `okf/`, `crawler/` base-only (decision D4) |
| `tools` pieces | ~40 content + ~30 media + ~25 dev + ~15 SEO + ~20 workflow + ~35 misc base tools | 🔴 264 base tool files; 194 already in nvoos/core — remainder has no home |
| `extensions` pieces | Elementor, WooCommerce, JetEngine, Rank Math, WPCode integrations | 🔴 (decision D3) |
| **Pro consolidation** | pro-core + business + media + dev + healthcare + legal + education + content + data + platform | 🔴 0 of ~336K lines started; 1,839 pro PHP files untouched (decision D5) |
| Meta-plugin / bundle | — | 🟡 Optional **new** plugin with `Requires Plugins:` wrapping the ecosystem chain; **never** the base plugin (D-NOBASE). The per-plugin `Requires Plugins` headers already express the chain without it |

## 10. Blocking decisions (owner)

- **D1** — Ownership discriminator: **resolved by D-NOBASE** — keep the existing `! defined('WP_MCP_AI_PATH')` pattern (it already works without touching the base); an optional ecosystem-side helper may generalize it. No meta-plugin mode in the base.
- **D2** — Assistant CPT + builder/test UI home: **resolved by D-NOCORE → `content-graph-ai`**.
- **D3** — Elementor/Woo/JetEngine/RankMath/WPCode: dedicated `extensions` addon (roadmap) vs distribute to owning plugins. Recommendation: distribute to owning plugins (a dedicated addon is still possible later; either way no base/core change).
- **D4** — Engine pieces (OOS/markup/paper-store/OKF/crawler): separate `engine` addon (roadmap) vs fold into `content-graph-ai` (your two-plugin constraint). Recommendation: fold into CG-AI under an `Engine\` namespace.
- **D5** — Pro packaging: 10 addons immediately (roadmap) vs one consolidated `nvoos-content-graph-pro` addon first, split later. Recommendation: consolidated first.
- **D6** — Open-source distribution mapping: which ecosystem plugins constitute the wp.org "Base" tier (~303 tools, no third-party APIs). Docs/distribution only.
- **D7** — Base-copy deletion window: **resolved by D-NOBASE → never; legacy copies retained permanently**.

## 11. Bottom line

- **Platform side:** ~90% closed by the merged extraction. Remaining: workflow engine, queues/ops, approvals, tenant, integrations, admin-UI port, A2A receive routes.
- **AI side:** the largest gap — the entire chat/model/token/security/REST/CLI runtime plus the chat UI family has no port; CG-AI currently carries a 32-file layer over nvoos/core.
- **UI (locked):** everything in §6, including the Pro SPA v2 with its full build toolchain.
- **Pro:** completely unstarted (1,839 PHP files + spa-v2 + 25+ node services).
- **Constraints (locked):** the entire port is additive — `mcp-ai-wpoos` and `nvoos-content-graph` receive **no code changes** (D-NOBASE, D-NOCORE). The monolith keeps working forever as the legacy path; the ecosystem is the forward path.
- Sequencing, waves, exit gates, and estimates: see the companion [phased porting plan](../plans/base-pro-ecosystem-port-plan.md).
