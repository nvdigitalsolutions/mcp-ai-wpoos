<?php
/**
 * Skills README — documents the Skills subsystem.
 *
 * @package NvoosContentGraphAiPlatform\Skills
 */

__halt_compiler();

# Skills/

## Purpose

Agent Skills subsystem for NV oOS Content Graph — manages Anthropic-compatible Agent Skills (SKILL.md files) with YAML frontmatter, bundled skill packs, and progressive-disclosure prompt construction.

## Tier

| | |
|---|---|
| **Distribution** | Platform addon — requires `nvoos-content-graph` + `nvoos-content-graph-ai` |
| **PHP target** | 8.1+ |
| **License** | Proprietary |
| **Loaded by** | `Plugin::registerSkills()` → `SkillService::instance()->register()` |
| **Source** | Bridges the base plugin's `WP_MCP_AI_Skill_Registry`, `WP_MCP_AI_Skill_Parser`, `WP_MCP_AI_Skill_Pack_Registry` |

## Public Surface

| Symbol | File | Purpose |
|---|---|---|
| `SkillService` | `SkillService.php` | Singleton service — wires hooks |
| `SkillBridge` | `SkillBridge.php` | Static bridge — delegates to base plugin classes |
| `Admin/SkillsAdmin` | `Admin/SkillsAdmin.php` | SettingsRegistry tab registration |

## Extraction Status

| Source file (base plugin) | Target | Status |
|---|---|---|
| `class-wp-mcp-ai-skill-registry.php` | `SkillBridge.php` | ✅ Bridged |
| `class-wp-mcp-ai-skill-parser.php` | `SkillBridge.php` | ✅ Bridged |
| `class-wp-mcp-ai-skill-pack-registry.php` | `SkillBridge.php` | ✅ Bridged |
| `includes/bundled-skills/` (40 skill packs) | — | Data, stays in base plugin |

## SkillBridge API

```php
use NvoosContentGraphAiPlatform\Skills\SkillBridge;

$all     = SkillBridge::getAll();           // name→data map
$skill   = SkillBridge::get('wp-rest-api'); // single skill
$index   = SkillBridge::getIndex();         // name+description only
$prompt  = SkillBridge::buildPrompt(['wp-rest-api']);
$iprompt = SkillBridge::buildIndexPrompt(['wp-rest-api']); // progressive
$result  = SkillBridge::installBundled();   // install from base plugin
```

## Also Load

- `../../../.context/conventions.md` — naming + style
- `../../../.context/security-checklist.md` — file upload security (skill ZIP handling)
