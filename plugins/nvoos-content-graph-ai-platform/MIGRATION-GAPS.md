# NV oOS Content Graph Platform — Migration Gap Analysis

**Date:** 2026-08-03
**Scope:** End-to-end audit of what exists vs. what's planned across all 10 Platform subsystems.

---

## Methodology

For each subsystem, we checked three locations:

| Layer | Location | What it contains |
|---|---|---|
| **Platform addon** | `plugins/nvoos-content-graph-ai-platform/src/{Subsystem}/` | PSR-4 admin UI + bridge classes |
| **Base plugin** | `includes/{subsystem}/` or `includes/class-wp-mcp-ai-*.php` | Business logic (legacy procedural/OOP) |
| **Old Content Graph addon** | `addons/content-graph/` | Pre-extraction Content Graph code (partially migrated to core) |

---

## Subsystem-by-Subsystem Analysis

### 1. Agents 🟡 Bridged

**Platform addon (`src/Agents/`):**
- `Agents.php` — Singleton service, registers admin hooks ✅
- `CptBridge.php` — Delegates to `WP_MCP_AI_Assistant_CPT::get_assistant_configuration()` ✅
- `MetaKeys.php` — Agent meta key constants ✅
- `Admin/AgentsAdmin.php` — Registers tabs in Platform + Content Graph dashboards ✅
- `Admin/AgentsDashboardSection.php` — Dashboard overview panel ✅
- `Admin/AddAgentPage.php`, `BuildAgentPage.php`, `TestAgentPage.php` — Submenu pages ✅
- `Admin/CreateAgentButton.php` — Quick-create button ✅

**Base plugin (`includes/assistants/`):**
- `class-wp-mcp-ai-assistant-cpt.php` — CPT registration, config management ✅
- `metaboxes/` — Meta box rendering ✅

**Gap:** None. Bridge is complete. Business logic extraction (Phase 2) would move CPT logic from `includes/assistants/` into `src/Agents/`.

---

### 2. Skills 🟡 Bridged

**Platform addon (`src/Skills/`):**
- `SkillService.php` — Singleton, registers admin hooks ✅
- `SkillBridge.php` — Delegates to `WP_MCP_AI_Skill_Registry`, `WP_MCP_AI_Skill_Pack_Registry` ✅
- `Admin/SkillsAdmin.php` — Registers tabs in both dashboards ✅
- `Admin/SkillsDashboardSection.php` — Dashboard overview panel ✅

**Base plugin (`includes/`):**
- `class-wp-mcp-ai-skill-registry.php` — Skill storage, retrieval, management ✅
- `class-wp-mcp-ai-skill-parser.php` — Markdown → skill object parser ✅
- `class-wp-mcp-ai-skill-pack-registry.php` — Pack management ✅
- Loaded in `includes/bootstrap/loader.php` L226-232 ✅
- Admins via `addons/pro/includes/skills-manager-init.php` (Pro addon)

**Gap:** None. Bridge is complete. The Pro addon's skill manager admin pages (`skill-manager-admin-page.php`, `skill-research-admin-page.php`, `skill-settings-admin-page.php`) are NOT yet bridged — they still live in `addons/pro/`.

---

### 3. Slash Commands 🟡 Bridged

**Platform addon (`src/SlashCommands/`):**
- `SlashCommandService.php` — Singleton, registers admin hooks ✅
- `SlashCommandBridge.php` — Delegates to `wp_mcp_ai_execute_slash_command()`, `wp_mcp_ai_register_slash_command()`, etc. ✅
- `Admin/SlashCommandsAdmin.php` — Registers tabs in both dashboards ✅
- `Admin/SlashCommandsDashboardSection.php` — Dashboard overview panel ✅

**Base plugin (`includes/slash-commands/`):**
- `class-wp-mcp-ai-slash-command-handler.php` — Core command execution engine ✅
- `class-wp-mcp-ai-slash-command-parser.php` — Input parsing ✅
- `class-wp-mcp-ai-slash-command-validator.php` — Validation ✅
- `class-wp-mcp-ai-slash-command-audit.php` — Audit logging ✅
- `class-wp-mcp-ai-slash-command-toolkit-manager.php` — Toolkit management ✅
- `class-wp-mcp-ai-slash-command-performance-optimizer.php` — Caching ✅
- `class-wp-mcp-ai-slash-command-workflow-orchestrator.php` — Workflow chaining ✅
- `slash-commands-init.php` — Bootstrap ✅
- `commands/` — Built-in command implementations ✅

**Gap:** None. Bridge is complete. This is the most mature subsystem — the base plugin implementation is 14.5K+ lines.

---

### 4. Harness 🟡 Bridged

**Platform addon (`src/Harness/`):**
- `HarnessService.php` — Singleton, registers admin hooks (admin-only) ✅
- `Admin/HarnessAdmin.php` — Registers tabs in both dashboards ✅
- `Admin/HarnessDashboardSection.php` — Dashboard overview panel ✅

**Base plugin (`includes/harness/`):**
- `class-wp-mcp-ai-guardrails.php` ✅
- `class-wp-mcp-ai-harness-auto-deploy.php` ✅
- `class-wp-mcp-ai-harness-eval-scheduler.php` ✅
- `class-wp-mcp-ai-harness-population.php` ✅
- `class-wp-mcp-ai-harness-profile.php` ✅
- `class-wp-mcp-ai-harness-prompt-injector.php` ✅
- `class-wp-mcp-ai-harness-search-engine.php` ✅
- `class-wp-mcp-ai-harness-trace-capture.php` ✅
- `class-wp-mcp-ai-harness-trace-store.php` ✅
- `class-wp-mcp-ai-necessity-gate.php` ✅
- `class-wp-mcp-ai-pii-filter.php` ✅
- `class-wp-mcp-ai-prompt-cue-library.php` ✅
- `class-wp-mcp-ai-reasoning-trace.php` ✅
- `class-wp-mcp-ai-retrieval-harness.php` ✅
- `class-wp-mcp-ai-self-refine-loop.php` ✅
- `class-wp-mcp-ai-tool-router-harness.php` ✅
- `harness-init.php` — Bootstrap ✅

**Gap:** No bridge class exists. `HarnessService::register()` only registers admin hooks — it doesn't call `class_exists()` guards or delegate to base plugin harness classes. The admin pages may reference harness functionality that the platform addon cannot directly call without a bridge.

**Action:** Create `HarnessBridge.php` similar to `SkillBridge.php` and `SlashCommandBridge.php`.

---

### 5. Measurement 🟡 Bridged

**Platform addon (`src/Measurement/`):**
- `MeasurementService.php` — Singleton, admin-only ✅
- `Admin/MeasurementAdmin.php`, `Admin/MeasurementDashboardSection.php` ✅

**Base plugin (`includes/measurement/`):**
- `class-wp-mcp-ai-measurement-bootstrap.php` ✅
- `class-wp-mcp-ai-measurement-registry.php` ✅
- `class-wp-mcp-ai-metric-collector.php` ✅
- `class-wp-mcp-ai-metric-event-store.php` ✅
- `class-wp-mcp-ai-metric-persister.php` ✅
- `class-wp-mcp-ai-metric-retention.php` ✅
- `class-wp-mcp-ai-chat-turn-metrics.php` ✅
- `class-wp-mcp-ai-chat-turn-observer.php` ✅
- `class-wp-mcp-ai-tool-execution-observer.php` ✅
- `class-wp-mcp-ai-tool-chain-acceptance-tracker.php` ✅
- `class-wp-mcp-ai-sse-metrics.php` + `class-wp-mcp-ai-sse-observer.php` ✅
- `class-wp-mcp-ai-reward-function-registry.php` ✅
- `class-wp-mcp-ai-verifier-registry.php` + `class-wp-mcp-ai-verifier-base.php` + `interface-wp-mcp-ai-verifier.php` ✅
- `class-wp-mcp-ai-stock-metrics.php` ✅
- `budgets/`, `eval/`, `exporters/`, `rewards/`, `verifiers/` subdirectories ✅

**Gap:** No bridge class. Same issue as Harness.

**Action:** Create `MeasurementBridge.php`.

---

### 6. Professions 🟡 Bridged

**Platform addon (`src/Professions/`):**
- `ProfessionService.php` — Singleton, admin-only ✅
- `Admin/ProfessionAdmin.php`, `Admin/ProfessionsDashboardSection.php` ✅

**Base plugin (`includes/professions/`):**
- `class-wp-mcp-ai-profession-cpt.php` — CPT registration ✅
- `class-wp-mcp-ai-profession-seeder.php` ✅
- `class-wp-mcp-ai-profession-base-knowledge-seeder.php` ✅
- `class-wp-mcp-ai-profession-orchestration-seeder.php` ✅
- `class-wp-mcp-ai-profession-orchestration-cli.php` ✅
- `class-wp-mcp-ai-profession-playbook-seeder.php` ✅
- `metaboxes/` ✅
- `profession-dataset-mappings.php` ✅
- `professions-init.php` ✅

**Gap:** No bridge class. Same as Harness/Measurement.

**Action:** Create `ProfessionBridge.php`.

---

### 7. A2A (Agent-to-Agent) 🟡 Bridged

**Platform addon (`src/A2A/`):**
- `A2AService.php` — Singleton, admin-only ✅
- `Admin/A2AAdmin.php`, `Admin/A2ADashboardSection.php` ✅

**Base plugin (`includes/a2a/`):**
- `class-wp-mcp-ai-a2a-agent-card.php` ✅
- `class-wp-mcp-ai-a2a-client.php` ✅
- `class-wp-mcp-ai-a2a-message-translator.php` ✅
- `class-wp-mcp-ai-a2a-push-notifications.php` ✅
- `class-wp-mcp-ai-a2a-task-manager.php` ✅
- `class-wp-mcp-ai-a2a-webhook-handler.php` ✅
- `class-wp-mcp-ai-a2a-wellknown.php` ✅

**Gap:** No bridge class. Same as above.

**Action:** Create `A2ABridge.php`.

---

### 8. ACP (Agent Communication Protocol) 🟡 Bridged

**Platform addon (`src/ACP/`):**
- `ACPService.php` — Singleton, admin-only ✅
- `Admin/ACPAdmin.php`, `Admin/ACPDashboardSection.php` ✅

**Base plugin (`includes/acp/`):**
- `class-wp-mcp-ai-acp-jsonrpc-dispatcher.php` ✅
- `class-wp-mcp-ai-acp-server.php` ✅
- `class-wp-mcp-ai-acp-session-bridge.php` ✅
- `class-wp-mcp-ai-acp-session-manager.php` ✅
- `transport/` subdirectory ✅

**Gap:** No bridge class. Same as above.

**Action:** Create `ACPBridge.php`.

---

### 9. Federation 🔴 Gap

**Platform addon (`src/Federation/`):**
- `FederationService.php` — Singleton (50-line stub, admin-only) ⚠️
- `Admin/FederationAdmin.php`, `Admin/FederationDashboardSection.php` ✅

**Base plugin (`includes/`):**
- **❌ No `includes/federation/` directory exists**
- **❌ No federation business logic classes exist**
- **ℹ️** `addons/content-graph/includes/remote/drivers/class-nvoos-content-graph-remote-oos-federation.php` — This is a **graph-level** federation driver (imports nodes from another oOS site into the knowledge graph). It is NOT agent-level federation (agents discovering and communicating with agents on other instances).

**What's missing:**
1. `includes/federation/class-wp-mcp-ai-federation-registry.php` — Registry of federated instances
2. `includes/federation/class-wp-mcp-ai-federation-handshake.php` — Trust establishment, capability exchange
3. `includes/federation/class-wp-mcp-ai-federation-client.php` — Outbound agent task delegation
4. `includes/federation/class-wp-mcp-ai-federation-server.php` — Inbound agent task reception
5. `FederationBridge.php` in Platform addon
6. REST endpoints for federation (well-known, agent card, task accept)

**Action:** Greenfield development needed. This is a new subsystem.

---

### 10. Blueprints 🔴 Gap

**Platform addon (`src/Blueprints/`):**
- `BlueprintService.php` — Singleton (20-line stub, admin-only) ⚠️
- `Admin/BlueprintAdmin.php`, `Admin/BlueprintsDashboardSection.php` ✅

**Base plugin (`includes/`):**
- **❌ No `includes/blueprints/` directory exists**
- **❌ No general-purpose blueprints system exists**
- **ℹ️** `addons/pro/includes/admin/class-wp-mcp-ai-crm-blueprints-page.php` — CRM-specific blueprint browser for pre-built CRM assistant templates. NOT a general blueprint system.

**What's missing:**
1. `includes/blueprints/class-wp-mcp-ai-blueprint-registry.php` — CRUD for blueprint records
2. `includes/blueprints/class-wp-mcp-ai-blueprint-exporter.php` — Export agent → blueprint JSON
3. `includes/blueprints/class-wp-mcp-ai-blueprint-importer.php` — Import blueprint JSON → agent
4. `includes/blueprints/class-wp-mcp-ai-blueprint-validator.php` — Schema validation
5. `BlueprintBridge.php` in Platform addon
6. Integration with agent creation flow (save-as-blueprint, create-from-blueprint)
7. REST endpoints for blueprint CRUD

**Action:** Greenfield development needed. This is a new subsystem.

---

## Bridge Class Coverage Summary

| Subsystem | Bridge Class | Status |
|---|---|---|
| Agents | `CptBridge.php` | ✅ Complete |
| Skills | `SkillBridge.php` | ✅ Complete |
| Slash Commands | `SlashCommandBridge.php` | ✅ Complete |
| Harness | — | ❌ Missing |
| Measurement | — | ❌ Missing |
| Professions | — | ❌ Missing |
| A2A | — | ❌ Missing |
| ACP | — | ❌ Missing |
| Federation | — | ❌ Missing (subsystem doesn't exist yet) |
| Blueprints | — | ❌ Missing (subsystem doesn't exist yet) |

**Priority order for bridge creation:**
1. Harness, Measurement (most complex base plugin implementations)
2. A2A, ACP (smaller, well-defined APIs)
3. Professions (CPT-based, simpler to bridge)

---

## Files That Need Creation

### Immediate (Bridges — unblock admin pages referencing base plugin classes)

| File | Lines (est.) | Complexity |
|---|---|---|
| `src/Harness/HarnessBridge.php` | ~80 | Low (delegation pattern) |
| `src/Measurement/MeasurementBridge.php` | ~80 | Low |
| `src/Professions/ProfessionBridge.php` | ~60 | Low |
| `src/A2A/A2ABridge.php` | ~60 | Low |
| `src/ACP/ACPBridge.php` | ~60 | Low |

### Phase 2 (Greenfield — build new subsystems in base plugin)

| File | Lines (est.) | Complexity |
|---|---|---|
| `includes/federation/class-wp-mcp-ai-federation-registry.php` | ~300 | High |
| `includes/federation/class-wp-mcp-ai-federation-handshake.php` | ~200 | Medium |
| `includes/federation/class-wp-mcp-ai-federation-client.php` | ~250 | Medium |
| `includes/federation/class-wp-mcp-ai-federation-server.php` | ~250 | Medium |
| `includes/blueprints/class-wp-mcp-ai-blueprint-registry.php` | ~200 | Medium |
| `includes/blueprints/class-wp-mcp-ai-blueprint-exporter.php` | ~150 | Low |
| `includes/blueprints/class-wp-mcp-ai-blueprint-importer.php` | ~200 | Medium |
| `includes/blueprints/class-wp-mcp-ai-blueprint-validator.php` | ~100 | Low |

### Phase 3 (Bridges once subsystems exist)

| File | Lines (est.) |
|---|---|
| `src/Federation/FederationBridge.php` | ~80 |
| `src/Blueprints/BlueprintBridge.php` | ~60 |

---

## AI Addon Practical Notes

The AI addon is in a stronger position than the Platform addon:

**What works:**
- 13 AI providers registered via `CoreBridge` ✅
- 13 AI tools registered in both nvoos/core ToolRegistry and Content Graph ToolRegistry ✅
- Embeddings + RAG + AgentMemory wired ✅
- REST ChatController with SSE streaming ✅
- Settings injected into core's grouped option ✅

**Key storage note — not a gap, but worth understanding:**

The base plugin (`mcp-ai-wpoos`) has `WP_MCP_AI_Api_Key_Store` in `includes/security/class-wp-mcp-ai-api-key-store.php` — AES-256-GCM encryption with transparent plaintext migration for API keys stored in `wp_mcp_ai_settings`. This is production-grade and handles the migration path.

The Content Graph AI addon's `CredentialResolver` has a two-tier approach:
1. **Priority 1** (L133): Reads keys from `nvoos_content_graph_settings` via raw `get_option()` — plaintext
2. **Priority 2** (L104-108): Falls back to base plugin's `WP_MCP_AI_Credential_Resolver::get_api_key()` which uses the encrypted `Api_Key_Store`

**Practical implication:** When the base plugin is active AND the user enters keys through the base plugin's admin, keys are encrypted. When the user enters keys through Content Graph's own AI settings page, they go to `nvoos_content_graph_settings` unencrypted. The resolver's fallback chain means it will find the encrypted key from the base plugin if both exist.

**Recommendation:** Either (a) wrap Content Graph's `set()` calls to use `wp_mcp_ai_set_api_key()` when the base plugin is active, or (b) back the Content Graph settings store with its own encryption if the base plugin is absent. Not urgent since the base plugin provides encrypted fallback, but worth addressing before the AI addon ships standalone.

**Other practical concerns:**
- 🟢 Chat UI — by design per next-steps plan: admin-only tester (`ChatInterface.php`), not a production widget. The base+pro plugin's SPA-v2 was never planned for Content Graph migration.
- 🟡 `lib/` autoloader fallback fragile in production ZIPs
- 🟡 No deactivation hook cleanup
