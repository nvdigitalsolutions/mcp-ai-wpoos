<?php
/**
 * Agent system README — documents the extracted agent subsystem.
 *
 * @package NvoosContentGraphAiPlatform\Agents
 */

// This file is intentionally a markdown document.
// See the @package tag for namespace context.
__halt_compiler();

# Agents/

## Purpose

Agent role system for NV oOS Content Graph — registers and manages AI agents (formerly "assistants" in the base plugin). Agents have providers, models, system prompts, tools, skills, datasets, credentials, and harness profiles.

## Tier

| | |
|---|---|
| **Distribution** | Platform addon — requires `nvoos-content-graph` + `nvoos-content-graph-ai` |
| **PHP target** | 8.1+ |
| **License** | Proprietary |
| **Loaded by** | `NvoosContentGraphAiPlatform\Plugin::register()` → `Agents::instance()->register()` |
| **Source** | Extracted from base plugin `includes/assistants/` + `includes/admin/class-wp-mcp-ai-assistant-*.php` |

## Public Surface

| Symbol | File | Purpose |
|---|---|---|
| `NvoosContentGraphAiPlatform\Agents\Agents` | `Agents.php` | Singleton service — wires agent hooks |
| `NvoosContentGraphAiPlatform\Agents\Admin\AgentsAdmin` | `Admin/AgentsAdmin.php` | Admin menu + SettingsRegistry integration |
| `NvoosContentGraphAiPlatform\Agents\Agents::POST_TYPE` | `Agents.php` | Post type constant (`mcp_ai_assistant`) |

## Extraction Status

| Source file (base plugin) | Target | Status |
|---|---|---|
| `includes/assistants/class-wp-mcp-ai-assistant-cpt.php` | `MetaKeys.php` + `CptBridge.php` | ✅ Bridged |
| `includes/admin/class-wp-mcp-ai-add-assistant-page.php` | `Admin/AddAgentPage.php` | ✅ Extracted |
| `includes/admin/class-wp-mcp-ai-build-assistant-page.php` | `Admin/BuildAgentPage.php` | ✅ Extracted |
| `includes/admin/class-wp-mcp-ai-admin-test-assistant.php` | `Admin/TestAgentPage.php` | ✅ Extracted |
| `includes/admin/class-wp-mcp-ai-admin-create-assistant-button.php` | `Admin/CreateAgentButton.php` | ✅ Extracted |
| `includes/assistants/metaboxes/` | `Metaboxes/` | ⚠️ Bridged (delegates to base plugin) |

## Dependencies

- **Core**: `NvoosContentGraph\ToolRegistry` (tool availability for agents)
- **AI addon**: Provider client registry (model/provider selection)
- **Professions**: `mcp_ai_profession` CPT (agent templates)
- **Skills**: Future Platform subsystem
- **Harness**: Future Platform subsystem

## Conventions

- Agent post type constant: `Agents::POST_TYPE` (references `mcp_ai_assistant`)
- All meta keys accessed via constants (not bare strings)
- Admin pages register via `SettingsRegistry` + standalone `admin_menu` hooks
