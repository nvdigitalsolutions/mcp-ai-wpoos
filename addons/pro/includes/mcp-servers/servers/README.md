# Per-Toolkit MCP Server Implementations

## Purpose

26 concrete MCP server classes, one per Pro toolkit, each implementing `WP_MCP_AI_Toolkit_Server_Interface` (via `WP_MCP_AI_Toolkit_Server_Base`) to expose its toolkit's tools as a first-class JSON-RPC endpoint under `/wp-json/mcp-ai-pro/v1/mcp/{slug}`.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | Each server self-registers on the `wp_mcp_ai_register_toolkit_servers` action (dispatched by [`../mcp-servers-init.php`](../mcp-servers-init.php) at `init` priority 12) |
| **Optional dependencies** | none — each server self-describes its toolkit tools; the WP REST API is the only required infrastructure |

## Public Surface

Every server class in this folder extends `WP_MCP_AI_Toolkit_Server_Base` and exposes four methods:

| Method | Contract |
|---|---|
| `get_slug()` | kebab-case toolkit slug (used in REST URL and option keys) |
| `get_name()` | translatable display name |
| `get_description()` | translatable one-liner |
| `candidate_tool_slugs()` | array of tool slugs this server is allowed to expose |

The 26 concrete classes:

| Server class | Toolkit |
|---|---|
| `WP_MCP_AI_AI_Tool_Builder_MCP_Server` | AI Tool Builder |
| `WP_MCP_AI_Analytics_MCP_Server` | Analytics |
| `WP_MCP_AI_Architect_Agent_MCP_Server` | Architect Agent |
| `WP_MCP_AI_Architectural_Design_MCP_Server` | Architectural Design |
| `WP_MCP_AI_Calendar_Booking_MCP_Server` | Calendar Booking |
| `WP_MCP_AI_Chat_Channels_MCP_Server` | Chat Channels |
| `WP_MCP_AI_Cre_Debt_MCP_Server` | CRE Debt |
| `WP_MCP_AI_CRM_MCP_Server` | CRM |
| `WP_MCP_AI_DJ_Management_MCP_Server` | DJ Management |
| `WP_MCP_AI_Document_Generation_MCP_Server` | Document Generation |
| `WP_MCP_AI_ECA_MCP_Server` | ECA |
| `WP_MCP_AI_Ecommerce_MCP_Server` | Ecommerce |
| `WP_MCP_AI_Extended_Cognition_MCP_Server` | Extended Cognition |
| `WP_MCP_AI_Financial_Planner_MCP_Server` | Financial Planner |
| `WP_MCP_AI_Healthcare_Imaging_MCP_Server` | Healthcare Imaging |
| `WP_MCP_AI_Healthcare_MCP_Server` | Healthcare |
| `WP_MCP_AI_Healthcare_Wellness_MCP_Server` | Healthcare Wellness |
| `WP_MCP_AI_Image_Production_MCP_Server` | Image Production |
| `WP_MCP_AI_Law_Firm_MCP_Server` | Law Firm |
| `WP_MCP_AI_Media_MCP_Server` | Media |
| `WP_MCP_AI_Multilingual_MCP_Server` | Multilingual |
| `WP_MCP_AI_Project_Management_MCP_Server` | Project Management |
| `WP_MCP_AI_Regulatory_Registration_MCP_Server` | Regulatory Registration |
| `WP_MCP_AI_Site_Creator_MCP_Server` | Site Creator |
| `WP_MCP_AI_Social_Media_MCP_Server` | Social Media |
| `WP_MCP_AI_Video_Production_MCP_Server` | Video Production |

## Inputs / Outputs / Neighbors

- **Reads from:** the global tool registry (each server resolves tool availability via `WP_MCP_AI_Tool_Registry`), per-server option `wp_mcp_ai_toolkit_mcp_server_{slug}` (enabled flag, tool allow-list, rate limits), JSON-RPC request bodies.
- **Writes to:** JSON-RPC response payloads via the toolkit MCP REST controller.
- **Upstream callers:** external MCP clients, the toolkit MCP REST controller ([`../class-wp-mcp-ai-toolkit-mcp-rest-controller.php`](../class-wp-mcp-ai-toolkit-mcp-rest-controller.php)), the `/mcp-server` slash command, the `wp pro mcp-server` CLI command.
- **Downstream collaborators:** [`includes/tools/`](../../../../../includes/tools/) and [`../tools/`](../../tools/) (all tool execution), [`includes/measurement/`](../../../../../includes/measurement/) (observability).
- **Events fired:** none directly — registration goes through the parent `WP_MCP_AI_Toolkit_Server_Base`.
- **Events listened to:** `wp_mcp_ai_register_toolkit_servers` (self-registration).

## Conventions

- **One server per toolkit. One slug per server.** Slugs are kebab-case and stable — used in URLs, option keys, and token prefixes. Never rename a slug without a migration in [`../migrations/`](../../migrations/).
- Every concrete server MUST extend `WP_MCP_AI_Toolkit_Server_Base`, not just implement the interface, unless the implementation has a documented reason to bypass the base capability/config plumbing.
- `candidate_tool_slugs()` returns the default allow-list. The admin page can further restrict this via the `tools_allowlist` config key. Apply the `wp_mcp_ai_toolkit_mcp_server_{slug}_candidate_tools` filter if you need to add/remove default candidates.
- Servers that have no ingestion surfaces return an empty array from `ingestion_surfaces()`. The base class already provides this default — only override when your toolkit has ingestion endpoints.
- Keep the server class body minimal: `get_slug()`, `get_name()`, `get_description()`, and `candidate_tool_slugs()` are typically the only methods needed. All JSON-RPC plumbing lives in the parent framework.

## Tests

```bash
vendor/bin/phpunit addons/pro/tests/test-toolkit-server-contract.php
vendor/bin/phpunit addons/pro/tests/test-toolkit-server-execution.php
vendor/bin/phpunit addons/pro/tests/test-toolkit-server-credentials.php
vendor/bin/phpunit addons/pro/tests/test-toolkit-server-limits.php
vendor/bin/phpunit addons/pro/tests/test-toolkit-mcp-audit-log.php
vendor/bin/phpunit addons/pro/tests/test-cross-toolkit-mounts.php
vendor/bin/phpunit addons/pro/tests/test-ingestion-surface-parity.php
vendor/bin/phpunit addons/pro/tests/test-phase5-toolkit-mcp-servers.php
vendor/bin/phpunit addons/pro/tests/test-phase6-toolkit-mcp-servers.php
```

## Also Load

- [`.context/conventions.md`](../../../../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../../../../.context/security-checklist.md) — REST permission callbacks, token storage (always)
- [`.context/rest-api.md`](../../../../../.context/rest-api.md) — namespace conventions, route layering
- [`.context/tool-registry.md`](../../../../../.context/tool-registry.md) — tools resolved by each server
- [`.context/pro-vs-base.md`](../../../../../.context/pro-vs-base.md) — Pro placement rationale
- [`CLAUDE.md`](../../../../../CLAUDE.md) — PHP-compat (8.1+) + canonical envelope

## See Also

- Parent framework: [`addons/pro/includes/mcp-servers/`](../) — registry, REST controller, audit log, token manager, observability card
- Sibling: [`../mcp-apps/`](../../mcp-apps/) — the inverse direction (consuming remote MCP servers)
- Admin surface: [`../admin/class-wp-mcp-ai-pro-toolkit-mcp-servers-page.php`](../../admin/class-wp-mcp-ai-pro-toolkit-mcp-servers-page.php)
