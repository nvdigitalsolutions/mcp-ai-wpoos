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
