# NV oOS Content Graph — Platform

## Purpose

Platform layer for NV oOS Content Graph — provides namespace-bridged admin UI for the base plugin's agent, skill, slash-command, harness, measurement, profession, A2A, ACP, federation, and blueprint subsystems.

**This is a migration-in-progress.** The Platform addon currently bridges to business logic in the base `mcp-ai-wpoos` plugin's `includes/` directory. As extraction progresses, each subsystem's logic will move from `includes/` into this addon's `src/`.

## Tier

| | |
|---|---|
| **Distribution** | Addon plugin — requires `nvoos-content-graph` + `nvoos-content-graph-ai` |
| **PHP target** | 8.1+ |
| **License** | Proprietary (commercial license required) |
| **Loaded by** | `nvoos-content-graph-ai-platform.php` → `plugins_loaded` priority 10 (after AI addon at priority 5) |
| **Requires Plugins** | `nvoos-content-graph`, `nvoos-content-graph-ai` (WP 6.5+ header) |
| **Hard dependency** | `mcp-ai-wpoos` base plugin (contains all business logic) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraphAiPlatform\Plugin` | `src/Plugin.php` | Bootstrap (singleton composition root) |
| `NvoosContentGraphAiPlatform\Skills\SkillBridge` | `src/Skills/SkillBridge.php` | Platform code needing skill data |
| `NvoosContentGraphAiPlatform\SlashCommands\SlashCommandBridge` | `src/SlashCommands/SlashCommandBridge.php` | Platform code executing commands |
| `NvoosContentGraphAiPlatform\Agents\CptBridge` | `src/Agents/CptBridge.php` | Platform code reading agent configs |
| `NvoosContentGraphAiPlatform\Admin\PlatformDashboard` | `src/Admin/PlatformDashboard.php` | Admin menu + settings |
| `NvoosContentGraphAiPlatform\Admin\PlatformSettingsRegistry` | `src/Admin/PlatformSettingsRegistry.php` | Tab/section registration |

## Inputs / Outputs / Neighbors

- **Reads from:** `nvoos_content_graph_settings` option, `ai_platform_settings` option, core `NvoosContentGraph\ToolRegistry`, base plugin CPTs (`mcp_ai_assistant`, `mcp_ai_profession`)
- **Writes to:** Admin UI sections, REST responses, `ai_platform_settings` option
- **Upstream callers:** `nvoos-content-graph-ai-platform.php` (bootstrap), WordPress admin
- **Downstream collaborators:** `nvoos-content-graph` core, `nvoos-content-graph-ai` addon, `mcp-ai-wpoos` base plugin (via bridges)
- **Events listened to:** `nvoos_content_graph/admin/register_sections`, `nvoos_content_graph/register_tools`, `rest_api_init`, `nvoos_content_graph_ai_platform_admin_register_sections`

## Subsystem Migration Status

Each subsystem has three possible states:
- **🟢 Extracted** — Logic lives in this addon's `src/`. The base plugin `includes/` copy is the legacy version.
- **🟡 Bridged** — Admin UI lives here. Business logic still in base plugin `includes/`. Bridge classes delegate.
- **🔴 Gap** — Planned but neither extracted nor present in base plugin.

| Subsystem | Admin UI | Business Logic | Bridge | Status |
|---|---|---|---|---|
| **Agents** | `src/Agents/Admin/` — AgentsAdmin, DashboardSection, Add/Build/Test/Create pages | `includes/assistants/class-wp-mcp-ai-assistant-cpt.php` | `CptBridge.php` → `WP_MCP_AI_Assistant_CPT` | 🟡 Bridged |
| **Skills** | `src/Skills/Admin/` — SkillsAdmin, DashboardSection | `includes/class-wp-mcp-ai-skill-registry.php`, `skill-parser.php`, `skill-pack-registry.php` | `SkillBridge.php` → `WP_MCP_AI_Skill_Registry` | 🟡 Bridged |
| **Slash Commands** | `src/SlashCommands/Admin/` — SlashCommandsAdmin, DashboardSection | `includes/slash-commands/` — handler, parser, validator, audit, toolkit-manager, performance-optimizer, workflow-orchestrator | `SlashCommandBridge.php` → `wp_mcp_ai_*` functions | 🟡 Bridged |
| **Harness** | `src/Harness/Admin/` — HarnessAdmin, DashboardSection | `includes/harness/` — guardrails, auto-deploy, eval-scheduler, population, profile, prompt-injector, search-engine, trace-capture/store, necessity-gate, pii-filter, prompt-cue-library, reasoning-trace, retrieval-harness, self-refine-loop, tool-router-harness | Implicit (class_exists checks) | 🟡 Bridged |
| **Measurement** | `src/Measurement/Admin/` — MeasurementAdmin, DashboardSection | `includes/measurement/` — metrics, observers, budgets, eval, verifiers, rewards, event-store, persister, retention | Implicit (class_exists checks) | 🟡 Bridged |
| **Professions** | `src/Professions/Admin/` — ProfessionAdmin, DashboardSection | `includes/professions/` — CPT, seeders, orchestration, playbook, dataset-mappings | Implicit (class_exists checks) | 🟡 Bridged |
| **A2A** | `src/A2A/Admin/` — A2AAdmin, DashboardSection | `includes/a2a/` — agent-card, client, message-translator, push-notifications, task-manager, webhook-handler, wellknown | Implicit (class_exists checks) | 🟡 Bridged |
| **ACP** | `src/ACP/Admin/` — ACPAdmin, DashboardSection | `includes/acp/` — jsonrpc-dispatcher, server, session-bridge, session-manager | Implicit (class_exists checks) | 🟡 Bridged |
| **Federation** | `src/Federation/Admin/` — FederationAdmin, DashboardSection | **❌ Does not exist in `includes/`** — only a remote source driver in `addons/content-graph/` for graph federation | `FederationService.php` — stub only | 🔴 Gap |
| **Blueprints** | `src/Blueprints/Admin/` — BlueprintAdmin, DashboardSection | **❌ Does not exist in `includes/`** — only CRM-specific blueprints in Pro addon | `BlueprintService.php` — stub only | 🔴 Gap |

### Gap Details

#### 🔴 Federation

**What it should do:** Allow agents on one WordPress instance to discover and communicate with agents on another instance. Cross-site agent-to-agent communication with capability negotiation, trust establishment, and task delegation.

**What exists:**
- Platform addon has `FederationService.php` (50-line stub that only registers admin pages)
- Platform addon has `FederationAdmin.php` and `FederationDashboardSection.php` (dashboard UI shells)
- The legacy `addons/graphify/` addon has `NV_oOS_Graphify_Remote_OOS_Federation` — a remote source driver for importing graph nodes from another oOS site. This is graph-level federation, not agent-level.
- **No `includes/federation/` directory exists in base plugin**
- **No federation business logic classes exist anywhere**

**What's needed:**
1. Create `includes/federation/` with: `class-wp-mcp-ai-federation-registry.php`, `class-wp-mcp-ai-federation-client.php`, `class-wp-mcp-ai-federation-handshake.php`
2. Create `FederationBridge.php` in Platform addon delegating to base classes
3. Wire into the Platform dashboard

#### 🔴 Blueprints

**What it should do:** Reusable, shareable agent configuration templates. Users create an agent once, save it as a blueprint, and deploy new agents from that blueprint. Supports import/export for sharing between sites.

**What exists:**
- Platform addon has `BlueprintService.php` (20-line stub that only registers admin pages)
- Platform addon has `BlueprintAdmin.php` and `BlueprintsDashboardSection.php` (dashboard UI shells)
- Pro addon has `class-wp-mcp-ai-crm-blueprints-page.php` — CRM-specific blueprint browser for pre-built CRM assistant templates
- **No `includes/blueprints/` directory exists in base plugin**
- **No general-purpose blueprint system exists**

**What's needed:**
1. Create `includes/blueprints/` with: `class-wp-mcp-ai-blueprint-registry.php`, `class-wp-mcp-ai-blueprint-exporter.php`, `class-wp-mcp-ai-blueprint-importer.php`
2. Create `BlueprintBridge.php` in Platform addon delegating to base classes
3. Wire into the Platform dashboard
4. Integrate with Agent creation flow (agent → save-as-blueprint)

## Three-Plugin Dependency Chain

```
┌──────────────────────────────────────────────────────────────────┐
│  nvoos-content-graph-ai-platform  (this plugin)                       │
│                                                                  │
│  Admin UI:  ✅ Platform dashboard, settings, CPTs                │
│  Bridges:    ✅ 8/10 subsystems bridged (Agents→ACP)              │
│  Gaps:       🔴 Federation, 🔴 Blueprints (no base logic yet)    │
│                                                                  │
│  Requires:   nvoos-content-graph + nvoos-content-graph-ai                  │
│  Hard deps:  mcp-ai-wpoos (base plugin — all business logic)     │
├──────────────────────────────────────────────────────────────────┤
│  nvoos-content-graph-ai  (AI addon)                                   │
│                                                                  │
│  CoreBridge: ✅ nvoos/core framework, 13 providers, SSE          │
│  Tools:      ✅ 13 AI tools (summarize, translate, analyze…)     │
│  Embeddings: ✅ EmbeddingService, RagRetriever, AgentMemory      │
│  Chat:       ✅ ChatController (REST), ChatOrchestrator          │
│  Admin:      ✅ AiSettingsPage (injects into Content Graph settings)  │
│                                                                  │
│  Requires:   nvoos-content-graph                                      │
├──────────────────────────────────────────────────────────────────┤
│  nvoos-content-graph  (core — wp.org candidate)                       │
│                                                                  │
│  Graph:      ✅ Builder, Detector, Db, Analyzer, Exporter        │
│  REST:       ✅ 14 endpoints, proper auth                        │
│  Tools:      ✅ 14 graph tools, extensible registry              │
│  Frontend:   ✅ Shortcode, Block, SchemaOrg, RelatedContent      │
│  Remote:     ✅ 7 drivers, HttpClient with SSRF guard            │
│                                                                  │
│  Requires:   Nothing (zero deps)                                 │
└──────────────────────────────────────────────────────────────────┘
```

## Conventions

- Namespace: `NvoosContentGraphAiPlatform\` — PSR-4 mapped to `src/`.
- `Plugin.php` is the composition root — wires platform subsystems incrementally.
- Admin tabs and sections register via `PlatformSettingsRegistry` (own) and `SettingsRegistry` (Content Graph courtesy).
- Bridge pattern: `XxxBridge.php` classes provide namespace-friendly access to base plugin's `WP_MCP_AI_*` classes.
- Service pattern: `XxxService.php` classes are singletons that register hooks. Currently admin-only — runtime logic stays in base plugin until extraction completes.

## Migration Roadmap

### Phase 1 (Current) — Bridge Everything
- ✅ Platform dashboard with tabbed settings
- ✅ Admin UI for all 10 subsystems
- ✅ Bridge classes for Agents, Skills, Slash Commands
- ✅ CPTs: Project, Resource, Template
- 🔴 Federation business logic (create in base plugin `includes/federation/`)
- 🔴 Blueprints business logic (create in base plugin `includes/blueprints/`)
- 🔴 Bridge classes for Federation, Blueprints

### Phase 2 — Extract Business Logic
Move business logic from `includes/` into Platform `src/`:
- Agents → `src/Agents/` (agent registry, config management, template creation)
- Skills → `src/Skills/` (skill registry, parser, pack registry)
- Slash Commands → `src/SlashCommands/` (handler, parser, validator)
- Harness → `src/Harness/` (guardrails, traces, eval scheduler)

### Phase 3 — Extract Advanced Subsystems
- Measurement → `src/Measurement/`
- Professions → `src/Professions/`
- A2A → `src/A2A/`
- ACP → `src/ACP/`

### Phase 4 — Build New Subsystems
- Federation → greenfield in `src/Federation/`
- Blueprints → greenfield in `src/Blueprints/`

## Tests

```bash
vendor/bin/phpunit tests/
```

## Also Load

- [`../../.context/conventions.md`](../../.context/conventions.md) — naming + style
- [`../../.context/security-checklist.md`](../../.context/security-checklist.md) — security
- [`../../CLAUDE.md`](../../CLAUDE.md) — PHP compat + tool patterns

## See Also

- Required parent: [`../nvoos-content-graph/`](../nvoos-content-graph/) — core knowledge graph plugin
- Required addon: [`../nvoos-content-graph-ai/`](../nvoos-content-graph-ai/) — AI chat assistant addon
- Base plugin: root `includes/` — all business logic currently lives here
- [`src/`](src/) — source code root
- [`MIGRATION-GAPS.md`](MIGRATION-GAPS.md) — detailed gap analysis
