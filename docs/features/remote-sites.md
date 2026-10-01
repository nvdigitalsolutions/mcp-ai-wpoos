# Remote Sites & Connections

**Version:** 1.1.52+
**Category:** Pro Feature
**Classes:** `WP_MCP_AI_Pro_Remote_Sites_Admin`, Remote Site Manager, `remote_wp_connection` tool family

## Overview

The Remote Sites system enables NV oOS to manage and interact with remote WordPress sites through a unified connection manager. Each remote site is configured as a **connection** with credentials, allowed post types, and permission scopes. Once connected, AI assistants can read, create, update, and delete content on remote sites through the `remote_wp_connection` tool and other remote-aware tools.

The system supports managing WordPress posts, WooCommerce products/orders, JetEngine CCT records, and — as of v1.1.52 — Paper Store records on remote sites.

## Features

### Connection Management

- Configure connections to remote WordPress sites via URL + credentials
- Per-connection post type access controls — grant or restrict access to specific CPTs
- Connection health testing and status indicators
- Remote site discovery via the `list_connections` action

### MCP Server Connections (v1.1.85)

The Remote Sites screen also manages **MCP Server** connections — Elementor MCP,
WordPress MCP Adapter endpoints, or any JSON-RPC 2.0 Streamable HTTP MCP server:

- **Connection type `mcp_server`** with auth types `none`, `basic_auth` /
  `application_password` (Elementor MCP application passwords), `custom_header`
  (header name + value), `bearer`, and `oauth`.
- **Test Connection** performs a real MCP handshake (`server/discover` with the
  legacy `initialize` fallback) and reports protocol, server info, and tool count.
  Once a server is observed rejecting the stateless probe, a 24h dialect hint
  skips it on subsequent tests/connects (see the MCP Apps protocol-negotiation
  docs).
- **Discover Tools** (via `WP_MCP_AI_Pro_Remote_Site_Manager::discover_mcp_server_tools()`)
  enumerates the remote tools and persists the snapshot (`mcp_tool_count`,
  `mcp_discovered_at`, `mcp_last_test`) on the connection.
- Credentials are **encrypted at rest** like every other remote-site secret
  (the OAuth blob `mcp_oauth` is a credential field too).
- Per-assistant **MCP Apps** can reference a central connection
  (`connection_ref`) instead of duplicating credentials — see
  `docs/assistant-import-export.md` (redaction policy) and the MCP Apps
  metabox on the assistant editor.

### Upwork (Freelance Marketplace) Connection Modes (v1.1.88)

Upwork connections accept three operation modes (`upwork_mode`):

| Mode | Transport | Credentials |
|---|---|---|
| `api` | Upwork GraphQL API (`https://api.upwork.com/graphql`) | OAuth client ID + secret + refresh token |
| `web_search` | AI-powered web search (no Upwork access) | none |
| `mcp` | Official Upwork MCP gateway (`https://mcp.upwork.com/mcp`) | MCP OAuth 2.1 (DCR + PKCE, login button on the edit form) |

MCP mode routes the CRM Upwork tools (`search_upwork_jobs`,
`import_upwork_project`) through the MCP Apps client: a sessionful
`initialize` handshake against the gateway, `upwork__find_jobs`
(action `search`/`get`) for discovery and details, and
`upwork__list_accounts` for `org_uid` resolution (stored on the
connection when provided). Results normalize into the same job envelope
as API mode, so the CRM refresh pipeline (search → score → import) works
unchanged and imported deals/projects still carry `_external_source_id` /
`_external_source_platform = upwork` dedupe meta. The OAuth login flow
reuses the MCP Apps REST endpoints (`/mcp-apps/oauth/init` +
`/complete`) with `connection_ref` pointing at the Upwork connection, so
tokens persist to the encrypted central store (`mcp_oauth`, auto-refresh
via `update_mcp_oauth()`).

### FlowHub (POS/Retail) Connection Modes (v1.1.91)

FlowHub connections accept two operation modes (`flowhub_mode`):

| Mode | Transport | Meaning |
|---|---|---|
| `api` | Direct FlowHub POS API (`https://api.flowhub.co`, `clientId` + `key` headers) | Default — used for syncs and direct tool calls only |
| `mcp` | Same POS API, but the connection is the **designated backend for the FlowHub toolkit MCP server** | MCP-triggered services bind to this connection's credentials and proxy |

Unlike Upwork, FlowHub has no separate MCP gateway — the MCP toggle in
assistant settings (Toolkit MCP Servers → FlowHub Inventory Sync) exposes
FlowHub **tools**, whose live services (`refresh`, `sync_now`) still call
`api.flowhub.co`. MCP mode therefore marks *which* Remote Sites connection
serves those MCP-triggered calls:

- `WP_MCP_AI_FlowHub_Connection_Helper::get_mcp_connection_id()` resolves
  the first **enabled** FlowHub connection with `flowhub_mode = mcp` (and
  credentials).
- The toolkit MCP REST controller injects that connection ID into tool
  arguments when the MCP caller did not pass an explicit `connection_id`,
  routing the call through the explicit-connection path — credentials and
  the connection's **proxy** (`proxy_url` / `proxy_username` /
  `proxy_password`, encrypted at rest) are applied via `http_api_curl`.
- An explicit `connection_id` argument always wins; when no connection is
  designated as MCP, behavior is unchanged (the tools' existing resolver
  chain: explicit → toolkit settings → sync connections → first enabled
  connection).

Set the mode on the FlowHub connection edit form (**Connection Mode**
select, mirroring the Upwork pattern). Designating one connection as MCP
is the recommended fix when FlowHub MCP services fail with auth errors
because egress must go through a forward proxy (a whitelisted egress IP
that FlowHub accepts).

### Post Type Access Controls (v1.1.52 Update)

The admin interface for remote connection post type access has been enhanced:

- **Auto-discovery**: Custom post types registered by plugins (including `paper_store`) are automatically discovered via `get_post_types()` and listed in the admin table.
- **No manual slug entry required**: Previously, users had to manually type each CPT slug, save, and reopen before granting access. Now all public CPTs appear automatically.
- **Form submission mirroring**: `resolve_post_type_access()` uses the same discovery logic on form submission.

### Remote Operations

The `remote_wp_connection` tool supports:

| Action | Description |
|--------|-------------|
| `list_connections` | Discover available connection IDs (always call this first) |
| `test_connection` | Verify connectivity and credentials |
| `get_posts` / `get_post` | Read WordPress posts/pages |
| `get_pages` | List pages |
| `get_media` | List media library items |
| `get_wc_products` / `get_wc_product` | Read WooCommerce products (with variation support) |
| `get_wc_orders` / `get_wc_order` | Read WooCommerce orders |
| `get_wc_customers` | Read WooCommerce customers |
| `get_wc_categories` | Read WooCommerce categories |
| `create_post` / `update_post` / `delete_post` | Write operations (require explicit enablement) |
| `create_wc_product` / `update_wc_product` / `delete_wc_product` | WooCommerce write operations |
| `update_wc_order` | Order status updates |
| `list_jetengine_ccts` | Discover JetEngine CCT types |
| `get_jetengine_cct_items` / `get_jetengine_cct_item` | Read CCT records |
| `create_jetengine_cct_item` / `update_jetengine_cct_item` / `delete_jetengine_cct_item` | CCT write operations |

### Remote-Aware Tools

Several other tool families accept an optional `connection_id` to operate on remote sites:

- **Paper Store** (8 tools): `paper_store_list`, `paper_store_read`, `paper_store_search`, `paper_store_write`, `paper_store_update`, `paper_store_delete`, `paper_store_import`, `paper_store_export`
- **Paper Store management tools**: `nv_oos_local_agent_paper_store_*`, `nv_oos_sophie_agent_paper_store_*`

When a `connection_id` is provided, the operation is proxied through the Remote Site Manager to the remote WordPress REST API.

## Architecture

### Connection Flow

```
AI Tool call with connection_id="conn_XXXX"
  │
  ├─ Remote Site Manager validates connection
  │   ├─ Credentials resolved
  │   ├─ Post type access checked
  │   └─ Operation scoped to allowed actions
  │
  ├─ HTTP request to remote WP REST API
  │   ├─ Authentication via configured method
  │   └─ Response parsed and normalized
  │
  └─ Result returned to AI tool
```

### CPT Auto-Discovery

```
Admin page renders
  │
  ├─ get_post_types(['public' => true]) fetches all public CPTs
  ├─ Plugin-registered CPTs (paper_store, etc.) appear automatically
  └─ Admin checks checkboxes for allowed types
```

### Security

- Connection credentials are encrypted at rest
- Write operations require explicit administrator enablement per connection
- Post type access is gated per connection
- All remote HTTP calls use `wp_safe_remote_*` functions
- Remote Site Manager validates operation scopes before dispatch

## Use Cases

- **Agency multi-site management**: One Hub site managing 50+ client spoke sites
- **Cross-site content syndication**: Publish posts/products across multiple sites
- **Centralized Paper Store**: Maintain a knowledge base on a hub site, access it from spoke sites
- **Remote e-commerce management**: Manage WooCommerce products and orders across stores
- **Unified analytics**: Gather site health, post counts, and metrics from all managed sites

## Future Roadmap

See [`docs/project/plans/multi-site-gateway-plan.md`](../project/plans/multi-site-gateway-plan.md) for the comprehensive multi-site federation plan targeting v1.5.0+. This plan covers:

- Hub-and-spoke federation architecture
- Single MCP Gateway endpoint for all spoke sites
- Cross-site tool call routing (< 500ms overhead)
- Centralized governance, auditing, and tool distribution
- Automated spoke provisioning (< 5 minutes)

## Related

- [Paper Store](paper-store.md) — Paper Store with remote connection support
- [PR #5834: Auto-discover CPTs in remote connections](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/pull/5834)
- [PR #5835: Paper Store remote connection_id](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/pull/5835)
