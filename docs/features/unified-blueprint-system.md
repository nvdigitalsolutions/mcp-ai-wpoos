# Unified Blueprint System

**Added:** May 31, 2026 (v1.1.25)  
**Tier:** Pro  
**Location:** `addons/pro/includes/blueprints/`

## Overview

The Unified Blueprint System provides a pre-built library of **55 AI assistants** spanning **25 toolkits**, installable with a single click. Each blueprint is a complete assistant configuration — name, system prompt, model selection, tool assignments, and capability profile — that an admin can import and immediately put to work.

## Quick Start

1. Navigate to **NV oOS → Blueprints** in the WordPress admin
2. Browse the catalogue grouped by toolkit / profession
3. Click **Import** on any blueprint
4. The assistant is created as a draft — review its system prompt and tool assignments, then publish

## Included Blueprints (55 Assistants across 25 Toolkits)

### Aerlinn Assistants (4)
| Blueprint | Focus |
|-----------|-------|
| Bespoke Concierge | Luxury travel & lifestyle management |
| Luxeseek | High-end shopping & procurement |
| Business Advisory | Executive strategy & operations |
| Career Coach | Professional development & transitions |

### Healthcare (via shared blueprint installer)
| Blueprint | Focus |
|-----------|-------|
| Healthcare Blueprint Importer | Bulk-import medical record, compliance, and clinical workflow assistants |

### Cloudways Toolkit
| Blueprint | Focus |
|-----------|-------|
| Cloudways Server Manager | Server monitoring, scaling, backups |
| Cloudways App Manager | Application deployment & management |
| Cloudways Bot Protection | Security configuration & monitoring |

### CRM Toolkit
| Blueprint | Focus |
|-----------|-------|
| CRM Lead Manager | Lead capture, scoring, nurturing |
| CRM Deal Manager | Pipeline tracking & forecasting |
| CRM Outreach Sequencer | Multi-channel campaign automation |
| CRM Compliance Auditor | GDPR/CCPA audit & reporting |

### Content & Media
| Blueprint | Focus |
|-----------|-------|
| Content Strategist | Editorial calendar, SEO, distribution |
| Media Studio Operator | Image generation, editing, batch processing |
| Document Drafter | Legal, HR, financial document generation |

### E-Commerce (WooCommerce)
| Blueprint | Focus |
|-----------|-------|
| Store Manager | Inventory, orders, customers |
| Product Optimiser | Descriptions, images, SEO, pricing |
| Marketing Automator | Email campaigns, abandoned cart, coupons |

### Development & DevOps
| Blueprint | Focus |
|-----------|-------|
| WP-CLI Operator | Server-level WordPress management |
| Code Reviewer | PHPCS, security audit, performance |
| Deployment Manager | Git workflows, staging → production |

### And 20+ more toolkits covering analytics, social media, scheduling, education, real estate, finance, and more.

## Architecture

### Blueprint Installer (`class-wp-mcp-ai-blueprint-installer.php`)

The shared installer handles:
- **Validation** — JSON Schema validation of blueprint manifests
- **Deduplication** — detects existing assistants by slug to avoid duplicates
- **Tool resolution** — maps blueprint tool slugs to registered tool classes, skipping unavailable tools
- **Capability mapping** — applies the blueprint's capability profile or falls back to sensible defaults
- **Post-import hook** — `wp_mcp_ai_blueprint_imported` fires after each import

### Blueprint Manifest Format

Each blueprint is a JSON file:

```json
{
  "slug": "crm-lead-manager",
  "name": "CRM Lead Manager",
  "description": "Manages lead capture, scoring, and nurturing workflows",
  "toolkit": "crm",
  "system_prompt": "You are a CRM lead management specialist...",
  "model": "gpt-4o",
  "temperature": 0.3,
  "tools": ["crm_capture_lead", "crm_score_lead", "crm_nurture_sequence"],
  "capability_profile": "write",
  "icon": "dashicons-groups",
  "version": "1.0.0"
}
```

### Healthcare Blueprint Import Tool

The Healthcare Blueprint import tool is a dedicated importer for clinical/medical assistant blueprints. It includes:
- HIPAA-aware system prompt templates
- Medical record tool pre-selection
- Compliance audit trail initialization
- PHI handling configuration

## Hooks

| Hook | Type | Description |
|------|------|-------------|
| `wp_mcp_ai_blueprint_imported` | Action | Fires after a blueprint is successfully imported. Passes `$assistant_id` and `$blueprint_slug`. |
| `wp_mcp_ai_blueprint_validate` | Filter | Allows pre-import validation of blueprint manifests. Return `WP_Error` to block. |
| `wp_mcp_ai_blueprint_toolkit_manifest` | Filter | Allows registration of additional blueprint catalogues. |

## Blueprint Tool (`blueprint_import`)

A dedicated tool is available for agents to import blueprints programmatically:

```
blueprint_import({ slug: "crm-lead-manager" })
→ { success: true, assistant_id: 123, message: "CRM Lead Manager imported" }
```

Requires `manage_options` capability.

## Conventions

- Blueprints stored in `addons/pro/includes/blueprints/{toolkit}/`
- One JSON file per blueprint, named `{slug}.json`
- Blueprint slugs MUST match the filename (minus `.json`)
- All blueprints include `version` for migration support
- System prompts MUST NOT contain sensitive credentials

## See Also

- [Cloudways Toolkit](cloudways-toolkit.md)
- [CRM Toolkit](crm-toolkit.md)
- [Agent Skills](agent-skills.md)
