# ADR 002 — Per-Toolkit MCP Servers

> Status: **Accepted (Phase 0 + Phase 1 + Phase 2 + Phase 3a/3c + Phase 3d + Phase 4 + Phase 5 + Phase 6 + Phase 7)** · Date: 2026-05-08; Phase 2 added 2026-05-10; Phase 3a/3c added 2026-05-11; Phase 3d added 2026-05-12; Phase 4 added 2026-05-12; Phase 5 added 2026-05-12; Phase 6 added 2026-05-12; Phase 7 added 2026-05-13
>
> Supersedes: none.
> Related: `docs/ADR_001_module_boundaries.md`, `docs/features/toolkit-mcp-servers.md`.

## Context

NV oOS exposes a single MCP JSON-RPC endpoint at `/mcp-ai/v1/mcp` that surfaces every registered tool, resource, and prompt across every Pro toolkit. As the Pro addon grew past 19 distinct toolkits — each with one or more **Research & Add (R&A)** ingestion pages, several with **Consolidate & Add (C&A)** pages, and at least one cross-toolkit dependency (Architectural Design's three research pages link to Healthcare's `health-records-consolidate`) — the monolithic surface became:

1. Hard to scope per-customer. A site that only licenses Healthcare receives the full firehose.
2. Hard to govern. Disabling a single page or tool requires editing a global allowlist.
3. Hard to discover. MCP clients must filter the full inventory client-side.
4. Hard to model cross-toolkit reads. Architectural Design needs read access to a Healthcare surface, but no explicit contract exists for that.

## Decision

Promote each Pro toolkit to a first-class MCP server with its own JSON-RPC endpoint, descriptor, and configuration page — **without** disturbing the existing monolithic endpoint.

### Architecture

- **Interface** — `WP_MCP_AI_Toolkit_Server_Interface` declares `get_slug()`, `get_name()`, `get_version()`, `ingestion_surfaces()`, `mounted_surfaces()`, `candidate_tool_slugs()`, `is_enabled()`.
- **Base class** — `WP_MCP_AI_Toolkit_Server_Base` stores per-server config in option `wp_mcp_ai_toolkit_mcp_server_{slug}` (enabled flag, tool allowlist, disabled native surfaces, disabled mounts) and computes effective surfaces / prompts / resources.
- **Registry** — `WP_MCP_AI_Toolkit_Server_Registry` is a singleton populated via the `wp_mcp_ai_register_toolkit_servers` action fired at `init` priority 12 (after toolkit init at 10/11).
- **REST controller** — `WP_MCP_AI_Toolkit_MCP_REST_Controller` registers three routes under namespace `mcp-ai-pro/v1`:
  - `GET  /mcp-ai-pro/v1/mcp` — descriptor for every registered server.
  - `GET  /mcp-ai-pro/v1/mcp/{slug}` — single-server descriptor.
  - `POST /mcp-ai-pro/v1/mcp/{slug}` — JSON-RPC 2.0 entry point (`initialize`, `ping`, `tools/list`, `resources/list`, `prompts/list` in this phase).

### Multi-page ingestion surfaces

Most R&A toolkits own multiple research pages (CRM has 4, Project Management has 3, Architectural Design has 3, Regulatory Registration has 3). `ingestion_surfaces()` therefore returns an array of descriptors keyed by `page_slug`, and `tools/list` / `resources/list` / `prompts/list` iterate the set.

CRM's MCP server, for example, surfaces 4 R&A prompts (`crm.research_add.company`, `crm.research_add.post`, `crm.research_add.page`, `crm.research_add.place`) and one resource entry per entity type.

### Cross-toolkit mounts

Healthcare's `health-records-consolidate` is referenced from Architectural Design's three research pages. The framework supports mounting a foreign ingestion surface as a **read-only** capability:

- Consumer servers return mount descriptors from `mounted_surfaces()`. Each mount carries `source_toolkit_slug` and `read_only: true`.
- The source toolkit retains write authority. If the source disables its server **or** the underlying native surface, the consumer's effective view drops the mount automatically.
- Consumer admins can disable individual mounts in the MCP Server tab without affecting the source.
- Mounted prompts appear under a `_mounted/` namespace; mounted resource URIs use the form `nvoos://{consumer_slug}/_mounted/{source_slug}/{entity_type}`.

### Settings page

`WP_MCP_AI_Toolkit_Settings_Base` automatically grows an "MCP Server" tab whenever a server is registered for that toolkit. The tab has four sections: master switch, Tools matrix, Native surfaces matrix, and Mounted-from-other-toolkits matrix.

## Backwards Compatibility

- The monolithic `/mcp-ai/v1/mcp` endpoint is **untouched**. Existing MCP clients keep working.
- New per-toolkit endpoints are additive. Servers are registered through a hook so other toolkits can opt in incrementally.
- Servers default to `enabled: true` so already-active toolkits become reachable without admin intervention.

## Phasing

This ADR ships **Phase 0 (foundation)**, **Phase 1 (three pilot servers — CRM, Healthcare, Architectural Design)**, and **Phase 2 (the remaining 16 Tier-1 promotions — see "Tier 1 candidates" below)**. Subsequent phases land in follow-up PRs:

- ~~Phase 2 — promote remaining 16 Tier-1 toolkits.~~ **(landed 2026-05-10)**
- Phase 3 — toolkit-scoped credentials, rate-limit overrides, CLI, slash commands, `tools/call` / `resources/read` / `prompts/get`.
  - ~~3a — `tools/call`, `resources/read`, `prompts/get` JSON-RPC dispatch.~~ **(landed 2026-05-11)**
  - ~~3b — `/mcp-server` slash command (list / show / enable / disable / tools).~~ **(landed 2026-05-12)**
  - ~~3c — Per-server limits (requests_per_minute, max_payload_bytes, max_iterations).~~ **(landed 2026-05-11)**
  - ~~3d — Toolkit-scoped credentials.~~ **(landed 2026-05-12)**
  - ~~3e — WP-CLI commands.~~ **(landed 2026-05-12)**
- Phase 4 — cross-mount audit trail in observability. **(landed 2026-05-12)**
- Phase 5 — assistant "mounted MCP servers" UI, observability dashboard card, auto-generated `docs/mcp-servers.md`. ✅ **Landed.**
- Phase 6 — Tier 2 toolkits and `/.well-known/mcp` externalisation. ✅ **Landed.**

### Phase 2 server inventory

| Slug | Class | Native surfaces | Mounts | Notes |
|---|---|---|---|---|
| `ai-tool-builder` | `WP_MCP_AI_AI_Tool_Builder_MCP_Server` | — | — | Tools-only. Meta-toolkit that scaffolds NV oOS tools themselves. |
| `calendar-booking` | `WP_MCP_AI_Calendar_Booking_MCP_Server` | research-appointment | — | |
| `cre-debt` | `WP_MCP_AI_CRE_Debt_MCP_Server` | research-cre-debt | — | |
| `dj-management` | `WP_MCP_AI_DJ_Management_MCP_Server` | — | — | Tools-only. |
| `document-generation` | `WP_MCP_AI_Document_Generation_MCP_Server` | research-document-template | — | Includes QMS controlled-document tools. |
| `eca` | `WP_MCP_AI_ECA_Management_MCP_Server` | research-eca | — | |
| `ecommerce` | `WP_MCP_AI_Ecommerce_MCP_Server` | research-product, product-consolidate | — | Dual-surface on the WooCommerce `product` CPT. |
| `financial-planner` | `WP_MCP_AI_Financial_Planner_MCP_Server` | research-financial-account | — | |
| `image-production` | `WP_MCP_AI_Image_Production_MCP_Server` | research-image-template | — | |
| `law-firm` | `WP_MCP_AI_Law_Firm_MCP_Server` | research-law-firm | — | |
| `media` | `WP_MCP_AI_Media_Toolkit_MCP_Server` | design-media (C&A) | — | C&A-only on the WordPress `attachment` post type. |
| `multilingual` | `WP_MCP_AI_Multilingual_MCP_Server` | — | — | Tools-only. |
| `project-management` | `WP_MCP_AI_Project_Management_MCP_Server` | research-project, research-task, research-event, event-consolidate | — | Multi-page R&A + dual-surface on `mcp_ai_event`. |
| `regulatory-registration` | `WP_MCP_AI_Regulatory_Registration_MCP_Server` | wp-mcp-ai-reg-product-research, wp-mcp-ai-reg-document-research, wp-mcp-ai-registration-research | — | Three-page R&A. |
| `social-media` | `WP_MCP_AI_Social_Media_MCP_Server` | — | — | Tools-only. |
| `video-production` | `WP_MCP_AI_Video_Production_MCP_Server` | — | — | Tools-only (no R&A page on disk). |

## Tier 1 candidates (19)

CRM, Calendar Booking, Financial Planner, Project Management, Document Generation, Image Production, Video Production, Media Toolkit, Social Media, Multilingual, AI Tool Builder, DJ Management, Ecommerce, Architectural Design, Healthcare, Law Firm, Regulatory Registration, ECA Management, CRE Debt.

## Tier 2 candidates (deferred to Phase 6)

Site Creator, Analytics, Healthcare Imaging, Healthcare Wellness/Vitals dashboards, Chat Channels, Architect Agent, Extended Cognition. These either expose pure dashboards or are workflow plumbing without a CPT-shaped ingestion surface.

## Tier 3 — never promoted

JetEngine compatibility shim and integration helpers — no coherent domain.

## Special cases

- **AI Skills** (`skill-research-admin`) — internal authoring tool, not customer-facing. Excluded from auto-promotion.
- **Quizzes** (`quiz-research`) — folded into whichever toolkit owns the quiz CPT (likely Social Media or Document Generation) rather than a standalone server.

## Test surface

- `Test_Toolkit_Server_Contract` — generic assertions every Tier-1 server must pass.
- `Test_Ingestion_Surface_Parity` — covers R&A-only, C&A-only, dual-surface, and multi-page shapes.
- `Test_Cross_Toolkit_Mounts` — asserts mount visibility, source-disable propagation, consumer-side suppression, and assistant-binding ownership.
