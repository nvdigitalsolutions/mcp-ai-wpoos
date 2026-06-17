# Toolkit MCP Servers Reference

> **Auto-generated** from the server registry.  
> Source: `addons/pro/includes/mcp-servers/servers/`  
> Phase: **Tier 1 (19 servers — Phases 1 + 2) + Tier 2 (7 servers — Phase 6) = 26 servers total**  
> Protocol version: `2025-06-18`

This document describes every per-toolkit **MCP server** shipped with NV oOS Pro. Each server exposes its toolkit's data as a Model Context Protocol endpoint, allowing AI assistants and external MCP clients to read resources, call tools, and fetch prompt templates scoped to that toolkit's domain.

---

## Architecture Overview

```
assistant / external MCP client
         │
         ▼
 REST /mcp-ai-pro/v1/mcp/{slug}/*   (WP_MCP_AI_Toolkit_MCP_REST_Controller)
         │
         ▼
 WP_MCP_AI_Toolkit_Server_Registry  (singleton, bootstrapped at init:12)
         │
         ├─ native surfaces  (owned by this server)
         └─ mounted surfaces (read-only cross-mount from other servers)
                │
                └─► audit hook: wp_mcp_ai_toolkit_mcp_cross_mount_read
                                (WP_MCP_AI_Toolkit_MCP_Audit_Log)
```

**Enabling a server** — per-server toggle at  
`Admin → NV oOS Settings → Orchestration → MCP Servers` or via the `/mcp-server enable <slug>` slash command.

**Assigning to an assistant** — on the assistant edit screen, the  
**Toolkit MCP Servers** metabox lets you restrict which servers that assistant may invoke (empty = allow all enabled servers).

**Audit log** — cross-mount reads are recorded in a 200-entry ring buffer  
(`wp_mcp_ai_toolkit_mcp_audit_log` option). Query via  
`GET /wp-json/mcp-ai-pro/v1/mcp-audit?limit=50`.

---

## Server Index

| Slug | Name | Type |
|------|------|------|
| [`ai-tool-builder`](#ai-tool-builder) | AI Tool Builder | Tools-only |
| [`architectural-design`](#architectural-design) | Architectural Design | Native + Cross-mount |
| [`calendar-booking`](#calendar-booking) | Calendar & Booking | Native |
| [`cre-debt`](#cre-debt) | CRE Debt | Native |
| [`crm`](#crm) | CRM & Email Marketing | Native |
| [`dietpi`](#dietpi) | DietPi Server Management | Native |
| [`dj-management`](#dj-management) | DJ Management | Tools-only |
| [`document-generation`](#document-generation) | Document Generation | Native |
| [`eca`](#eca) | ECA Management | Native |
| [`ecommerce`](#ecommerce) | E-commerce | Native |
| [`financial-planner`](#financial-planner) | Financial Planner | Native |
| [`health`](#health) | Health & Wellness | Native (canonical) |
| [`image-production`](#image-production) | Image Production | Native |
| [`law-firm`](#law-firm) | Law Firm | Native |
| [`media`](#media) | Media Toolkit | Native |
| [`multilingual`](#multilingual) | Multilingual | Tools-only |
| [`project-management`](#project-management) | Project Management | Native |
| [`regulatory-registration`](#regulatory-registration) | Regulatory Registration | Native |
| [`social-media`](#social-media) | Social Media | Tools-only |
| [`video-production`](#video-production) | Video Production | Tools-only |

---

## Server Details

### `ai-tool-builder`

**Name:** AI Tool Builder  
**Type:** Tools-only (no native ingestion surface)  
**Description:** Meta-toolkit that scaffolds, validates, and benchmarks NV oOS tools themselves. Tools-only server.

| Endpoint | URL |
|----------|-----|
| Tools | `GET /wp-json/mcp-ai-pro/v1/mcp/ai-tool-builder/tools/list` |
| Call tool | `POST /wp-json/mcp-ai-pro/v1/mcp/ai-tool-builder/tools/call` |

---

### `architectural-design`

**Name:** Architectural Design  
**Type:** Native + Cross-mount read  
**Description:** Architectural drawings, projects, and specifications. Mounts the Healthcare consolidation surface read-only so accessibility and aging-in-place reviews can pull member health context.

**Mounted surfaces:** `health` (read-only cross-mount — audit logged)

| Endpoint | URL |
|----------|-----|
| Tools | `GET /wp-json/mcp-ai-pro/v1/mcp/architectural-design/tools/list` |
| Resources | `GET /wp-json/mcp-ai-pro/v1/mcp/architectural-design/resources/list` |
| Read resource | `POST /wp-json/mcp-ai-pro/v1/mcp/architectural-design/resources/read` |
| Prompts | `GET /wp-json/mcp-ai-pro/v1/mcp/architectural-design/prompts/list` |
| Get prompt | `POST /wp-json/mcp-ai-pro/v1/mcp/architectural-design/prompts/get` |

---

### `calendar-booking`

**Name:** Calendar & Booking  
**Description:** Appointment and booking management — availability rules, scheduling, reminders, and Google/Outlook calendar sync.

| Endpoint | URL |
|----------|-----|
| Tools | `GET /wp-json/mcp-ai-pro/v1/mcp/calendar-booking/tools/list` |
| Resources | `GET /wp-json/mcp-ai-pro/v1/mcp/calendar-booking/resources/list` |
| Read resource | `POST /wp-json/mcp-ai-pro/v1/mcp/calendar-booking/resources/read` |

---

### `cre-debt`

**Name:** CRE Debt  
**Description:** Commercial real-estate debt origination, underwriting, asset management, CMBS, and debt-fund analytics. Owns the CRE Loan research surface.

| Endpoint | URL |
|----------|-----|
| Tools | `GET /wp-json/mcp-ai-pro/v1/mcp/cre-debt/tools/list` |
| Resources | `GET /wp-json/mcp-ai-pro/v1/mcp/cre-debt/resources/list` |

---

### `crm`

**Name:** CRM & Email Marketing  
**Description:** Customer relationship management — companies, contacts, places, and outbound posts/pages. Supports four AI-powered Research & Add ingestion surfaces.

| Endpoint | URL |
|----------|-----|
| Tools | `GET /wp-json/mcp-ai-pro/v1/mcp/crm/tools/list` |
| Resources | `GET /wp-json/mcp-ai-pro/v1/mcp/crm/resources/list` |
| Prompts | `GET /wp-json/mcp-ai-pro/v1/mcp/crm/prompts/list` |

---

---

### `dietpi`

**Name:** DietPi Server Management  
**Type:** Native (with SSH proxy tunneling)  
**Description:** AI-powered server management for DietPi single-board computers (Raspberry Pi, Odroid, etc.). 19+ tools for system info, package management, service control, backup/restore, storage, provisioning, and SSH proxy.

| Endpoint | URL |
|----------|-----|
| Tools | `GET /wp-json/mcp-ai-pro/v1/mcp/dietpi/tools/list` |
| Call tool | `POST /wp-json/mcp-ai-pro/v1/mcp/dietpi/tools/call` |

**Added:** v1.1.29 (Phases 0–3). See [`docs/features/dietpi-pro-toolkit.md`](dietpi-pro-toolkit.md) for full documentation.

---

### `dj-management`

**Name:** DJ Management  
**Type:** Tools-only  
**Description:** Event bookings, music libraries, equipment inventory, and client communication for DJ businesses.

| Endpoint | URL |
|----------|-----|
| Tools | `GET /wp-json/mcp-ai-pro/v1/mcp/dj-management/tools/list` |

---

### `document-generation`

**Name:** Document Generation  
**Description:** PDF, Word, and Excel document generation with QMS controls and OCR. Owns the Document Template research surface.

| Endpoint | URL |
|----------|-----|
| Tools | `GET /wp-json/mcp-ai-pro/v1/mcp/document-generation/tools/list` |
| Resources | `GET /wp-json/mcp-ai-pro/v1/mcp/document-generation/resources/list` |

---

### `eca`

**Name:** ECA Management  
**Description:** Extra-curricular activity scheduling, attendance, and reporting for schools. Owns the ECA research surface and integrates with iSAMS / SOCS.

| Endpoint | URL |
|----------|-----|
| Tools | `GET /wp-json/mcp-ai-pro/v1/mcp/eca/tools/list` |
| Resources | `GET /wp-json/mcp-ai-pro/v1/mcp/eca/resources/list` |

---

### `ecommerce`

**Name:** E-commerce  
**Description:** WooCommerce-backed product, order, and customer workflows. Owns Product research and Product consolidation surfaces.

| Endpoint | URL |
|----------|-----|
| Tools | `GET /wp-json/mcp-ai-pro/v1/mcp/ecommerce/tools/list` |
| Resources | `GET /wp-json/mcp-ai-pro/v1/mcp/ecommerce/resources/list` |

---

### `financial-planner`

**Name:** Financial Planner  
**Description:** Budgeting, retirement, investment, and net-worth planning. Owns the Financial Account research surface.

| Endpoint | URL |
|----------|-----|
| Tools | `GET /wp-json/mcp-ai-pro/v1/mcp/financial-planner/tools/list` |
| Resources | `GET /wp-json/mcp-ai-pro/v1/mcp/financial-planner/resources/list` |

---

### `health`

**Name:** Health & Wellness  
**Type:** Canonical consolidation surface  
**Description:** Family and pet member profiles, vitals, and consolidated health records. Owns the canonical health-records consolidation surface that other toolkits can mount read-only.

> **Note:** This server's consolidation surface is cross-mounted by `architectural-design`. All cross-mount reads are audit logged.

| Endpoint | URL |
|----------|-----|
| Tools | `GET /wp-json/mcp-ai-pro/v1/mcp/health/tools/list` |
| Resources | `GET /wp-json/mcp-ai-pro/v1/mcp/health/resources/list` |
| Read resource | `POST /wp-json/mcp-ai-pro/v1/mcp/health/resources/read` |

---

### `image-production`

**Name:** Image Production  
**Description:** AI-powered image generation, enhancement, and optimization. Owns the Image Template research surface.

| Endpoint | URL |
|----------|-----|
| Tools | `GET /wp-json/mcp-ai-pro/v1/mcp/image-production/tools/list` |
| Resources | `GET /wp-json/mcp-ai-pro/v1/mcp/image-production/resources/list` |

---

### `law-firm`

**Name:** Law Firm  
**Description:** Matter, billing, intake, document automation, and litigation support for law firms. Owns the Matter research surface.

| Endpoint | URL |
|----------|-----|
| Tools | `GET /wp-json/mcp-ai-pro/v1/mcp/law-firm/tools/list` |
| Resources | `GET /wp-json/mcp-ai-pro/v1/mcp/law-firm/resources/list` |

---

### `media`

**Name:** Media Toolkit  
**Description:** Cross-medium asset management, capture, collections, and template-driven media production.

| Endpoint | URL |
|----------|-----|
| Tools | `GET /wp-json/mcp-ai-pro/v1/mcp/media/tools/list` |
| Resources | `GET /wp-json/mcp-ai-pro/v1/mcp/media/resources/list` |

---

### `multilingual`

**Name:** Multilingual  
**Type:** Tools-only  
**Description:** Translation memory, content localization, and multilingual SEO tooling.

| Endpoint | URL |
|----------|-----|
| Tools | `GET /wp-json/mcp-ai-pro/v1/mcp/multilingual/tools/list` |

---

### `project-management`

**Name:** Project Management  
**Description:** Projects, tasks, and events. Multi-page Research & Add (project, task, event) plus the Event consolidation surface. PARA-method capture and review tools.

| Endpoint | URL |
|----------|-----|
| Tools | `GET /wp-json/mcp-ai-pro/v1/mcp/project-management/tools/list` |
| Resources | `GET /wp-json/mcp-ai-pro/v1/mcp/project-management/resources/list` |
| Prompts | `GET /wp-json/mcp-ai-pro/v1/mcp/project-management/prompts/list` |

---

### `regulatory-registration`

**Name:** Regulatory Registration  
**Description:** Cosmetic and pharmaceutical product registrations across regulatory authorities. Three-page Research & Add coverage (product, document, registration).

| Endpoint | URL |
|----------|-----|
| Tools | `GET /wp-json/mcp-ai-pro/v1/mcp/regulatory-registration/tools/list` |
| Resources | `GET /wp-json/mcp-ai-pro/v1/mcp/regulatory-registration/resources/list` |

---

### `social-media`

**Name:** Social Media  
**Type:** Tools-only  
**Description:** Cross-platform social media publishing, analytics, listening, and moderation.

| Endpoint | URL |
|----------|-----|
| Tools | `GET /wp-json/mcp-ai-pro/v1/mcp/social-media/tools/list` |

---

### `video-production`

**Name:** Video Production  
**Type:** Tools-only  
**Description:** Video transcoding, captioning, optimization, and Remotion-based generation.

| Endpoint | URL |
|----------|-----|
| Tools | `GET /wp-json/mcp-ai-pro/v1/mcp/video-production/tools/list` |

---

## Configuration

Each server is configured at  
`Admin → NV oOS Settings → Orchestration → MCP Servers → [toolkit] → MCP Server tab`.

Per-server options stored in WordPress option `wp_mcp_ai_toolkit_mcp_server_{slug}`:

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `enabled` | bool | `false` | Master on/off switch |
| `tools_allowlist` | string[] | `[]` | If non-empty, only these tool slugs are exposed |
| `disabled_surfaces` | string[] | `[]` | Surface slugs excluded from this server |
| `disabled_mounts` | string[] | `[]` | Cross-mount source slugs disabled for this server |
| `requests_per_minute` | int | `0` (unlimited) | Per-server rate limit |
| `max_payload_bytes` | int | `0` (no cap) | Max response body size |
| `max_iterations` | int | `0` (no cap) | Max agentic loop iterations |

---

## Audit Log

Cross-mount reads (a server reading resources/prompts from another server's surface) are recorded automatically.

**REST endpoint:** `GET /wp-json/mcp-ai-pro/v1/mcp-audit`  
**Capability required:** `manage_options`

Query parameters:

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `limit` | int | `50` | Max entries returned (1–200) |
| `consumer` | string | — | Filter by consuming server slug |
| `source` | string | — | Filter by source server slug |
| `summary_only` | bool | `false` | Return grouped summary instead of raw entries |

Filter to extend the ring-buffer size: `wp_mcp_ai_toolkit_mcp_audit_max_entries` (default 200).

---

## WP-CLI

```bash
# List all registered servers
wp mcp-server list

# Show details for a specific server
wp mcp-server show crm

# Enable a server
wp mcp-server enable crm

# Disable a server
wp mcp-server disable crm

# List exposed tools
wp mcp-server tools crm

# JSON output
wp mcp-server list --format=json
```

---

## Slash Command

Inside any NV oOS chat interface:

```
/mcp-server              # List all servers
/mcp-server show crm     # Show CRM server details
/mcp-server enable crm   # Enable CRM server (requires manage_options)
/mcp-server tools crm    # List tools exposed by CRM server
```

Aliases: `/mcp-servers`, `/toolkit-mcp`

---

*Last updated: Phase 6 (May 2026). For the `/.well-known/mcp` discovery endpoint, see the Phase 6 section below.*

---

## Tier-2 Servers (Phase 6)

Seven additional toolkits promoted to MCP servers. All are **tools-only** (no CPT-shaped ingestion surface); they expose no `resources/list` or `prompts/list` entries.

| Slug                  | Class                                       | Candidate tool count |
|-----------------------|---------------------------------------------|----------------------|
| `analytics`           | `WP_MCP_AI_Analytics_MCP_Server`            | 12                   |
| `architect-agent`     | `WP_MCP_AI_Architect_Agent_MCP_Server`      | 4                    |
| `chat-channels`       | `WP_MCP_AI_Chat_Channels_MCP_Server`        | 50+                  |
| `extended-cognition`  | `WP_MCP_AI_Extended_Cognition_MCP_Server`   | 7                    |
| `healthcare-imaging`  | `WP_MCP_AI_Healthcare_Imaging_MCP_Server`   | 8                    |
| `healthcare-wellness` | `WP_MCP_AI_Healthcare_Wellness_MCP_Server`  | 10                   |
| `site-creator`        | `WP_MCP_AI_Site_Creator_MCP_Server`         | 27                   |

### REST Endpoint

Same pattern as Tier-1: `POST /wp-json/mcp-ai-pro/v1/mcp/{slug}` for JSON-RPC.

### Configuration

Per-server option: `wp_mcp_ai_toolkit_mcp_server_{slug}`. Defaults:

| Field                | Default  | Description                                         |
|----------------------|----------|-----------------------------------------------------|
| `enabled`            | `true`   | Master on/off switch.                               |
| `tools_allowlist`    | `[]`     | Empty = all candidate tools exposed.                |
| `requests_per_minute`| `0`      | `0` = unlimited.                                    |
| `max_payload_bytes`  | `0`      | `0` = no limit.                                     |
| `max_iterations`     | `0`      | `0` = inherit global filter.                        |

---

## `/.well-known/mcp` Discovery Endpoint (Phase 6)

**URI:** `GET /.well-known/mcp`  
**Class:** `WP_MCP_AI_Pro_Well_Known_MCP`  
**Cache-Control:** `public, max-age=3600` (overridable via `wp_mcp_ai_well_known_mcp_cache_max_age` filter)

Returns a JSON discovery document listing every enabled toolkit server:

```json
{
  "mcpServers": [
    {
      "slug":        "crm",
      "name":        "CRM",
      "description": "...",
      "version":     "1.0.0",
      "endpoint":    "https://example.com/wp-json/mcp-ai-pro/v1/mcp/crm"
    }
  ]
}
```

Disabled servers are excluded. The document can be customised via the `wp_mcp_ai_well_known_mcp_document` filter.
