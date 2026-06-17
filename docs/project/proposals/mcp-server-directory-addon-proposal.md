# MCP Server Directory Addon — Comprehensive Implementation Proposal

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Industry Standards & Best Practices](#2-industry-standards--best-practices)
   - 2.1 [MCP Specification Metadata Schemas](#21-mcp-specification-metadata-schemas)
   - 2.2 [Discovery Standards (SEPs)](#22-discovery-standards-seps)
   - 2.3 [Competitive Analysis — Existing Directories](#23-competitive-analysis--existing-directories)
3. [Addon Structure](#3-addon-structure)
   - 3.1 [Directory Layout](#31-directory-layout)
   - 3.2 [File Listing with Class Names](#32-file-listing-with-class-names)
4. [Data Model](#4-data-model)
   - 4.1 [Custom Post Type: `mcp_server`](#41-custom-post-type-mcp_server)
   - 4.2 [Post Meta Fields](#42-post-meta-fields)
   - 4.3 [Taxonomy: `mcp_server_category`](#43-taxonomy-mcp_server_category)
5. [REST API](#5-rest-api)
   - 5.1 [Endpoints](#51-endpoints)
   - 5.2 [Response Shapes](#52-response-shapes)
6. [Shortcode API](#6-shortcode-api)
   - 6.1 [Attributes](#61-attributes)
   - 6.2 [Rendering Strategy](#62-rendering-strategy)
7. [Key Features](#7-key-features)
   - 7.1 [Server Cards](#71-server-cards)
   - 7.2 [AI-Friendly Discovery](#72-ai-friendly-discovery)
   - 7.3 [Caching Strategy](#73-caching-strategy)
   - 7.4 [Settings Page](#74-settings-page)
8. [Alignment with Existing Addon Patterns](#8-alignment-with-existing-addon-patterns)
9. [Implementation Phases](#9-implementation-phases)
10. [Appendix A: Comparison — Why CPT Over Alternatives](#appendix-a-comparison--why-cpt-over-alternatives)

---

## 1. Executive Summary

**Goal:** Build a self-hosted MCP (Model Context Protocol) server directory as a WordPress addon (`addons/mcp-directory/`) that showcases the user's protoolkit MCP servers. The directory is embeddable on any page via `[nvoos_mcp_directory]` shortcode, exposes a REST API for AI client discovery, and follows the same architectural conventions as existing addons (`graphify`, `docs-hub`, `chat-spa`).

**Why WordPress CPT:** A Custom Post Type gives us admin CRUD, REST API auto-exposure, taxonomy filtering, WordPress caching, and capability-based access control — all with minimal code. The data model aligns with the official MCP specification's `Implementation`, `Tool`, and `ServerCapabilities` schemas.

**Outcome:** Users can browse, search, and filter MCP servers from the frontend. AI clients (Claude, Cursor, etc.) can discover servers programmatically via REST and `.well-known/servers.json` endpoints.

---

## 2. Industry Standards & Best Practices

### 2.1 MCP Specification Metadata Schemas

The MCP specification defines canonical schemas that every server directory should align with:

| MCP Schema | Key Fields | Usage in Directory |
|---|---|---|
| `Implementation` | `name`, `title`, `version`, `description`, `websiteUrl`, `icons` | Server card identity |
| `Tool` | `name`, `title`, `description`, `inputSchema`, `outputSchema`, `annotations` (readOnlyHint, destructiveHint, idempotentHint, openWorldHint), `icons` | Tool listing per server |
| `ServerCapabilities` | `tools`, `resources`, `prompts`, `completions`, `extensions` | Capability badges |
| `Annotations` | `audience`, `priority`, `lastModified` | Sorting/filtering metadata |

Reference: <https://modelcontextprotocol.io/specification/draft/schema>

### 2.2 Discovery Standards (SEPs)

Two emerging standards for MCP server discovery should be supported to future-proof the directory:

- **SEP-1649** (Server Cards): Serve a structured metadata document at `/.well-known/mcp/server-card.json` per server. Enables clients to discover capabilities, transports, and auth before connecting. Reference: <https://github.com/modelcontextprotocol/modelcontextprotocol/issues/1649>
- **SEP-1960**: Endpoint enumeration at `/.well-known/mcp.json` for transport/authentication discovery. Reference: <https://github.com/modelcontextprotocol/modelcontextprotocol/issues/1960>
- **Official MCP Registry API**: Paginated `GET /servers`, cursor-based, with `cacheScope`/`ttlMs` headers. Reference: <https://nordicapis.com/getting-started-with-the-official-mcp-registry-api/>

### 2.3 Competitive Analysis — Existing Directories

| Directory | Submission Method | Data Model | Key Differentiator |
|---|---|---|---|
| **mcpmarket.com** | Web form + GitHub | Server → tools, deployable, paid tiers | Deployment platform + directory |
| **mcp.so** | GitHub issue | 22k+ servers, lightweight listing | Scale, community-driven |
| **Cline marketplace** | GitHub PR (`cline/mcp-marketplace`) | Review process, one-click install | IDE-native discoverability |
| **mcpservers.org** | GitHub/curated | Awesome-list style, category-focused | Simplicity |
| **Official MCP Registry** | npm/PyPI package verification | Registry API, verified servers | Authority, security |

**Gap our addon fills:** None of these are WordPress-native or self-hosted. Ours can be deployed alongside the NV oOS base plugin, inherit WordPress auth/caching, and expose servers directly to the NV oOS AI assistant toolchain.

---

## 3. Addon Structure

### 3.1 Directory Layout

```
addons/mcp-directory/
├── nvoos-mcp-directory.php                  # Main plugin file (header, constants, boot)
├── README.md
├── uninstall.php                             # Cleanup on deletion
│
├── includes/
│   ├── class-nvoos-mcp-directory-plugin.php  # Core singleton (hooks, init, is_enabled)
│   ├── class-nvoos-mcp-directory-cpt.php     # CPT registration + meta boxes
│   ├── class-nvoos-mcp-directory-schema.php  # JSON Schema validation for server/tool shapes
│   ├── class-nvoos-mcp-directory-repository.php  # Data access layer (get servers, search)
│   ├── class-nvoos-mcp-directory-cache.php   # Transient-based caching
│   ├── class-nvoos-mcp-directory-well-known.php  # .well-known endpoints
│   │
│   ├── rest/
│   │   └── class-nvoos-mcp-directory-rest.php    # REST API controller
│   │
│   ├── shortcode/
│   │   └── class-nvoos-mcp-directory-shortcode.php   # [nvoos_mcp_directory] shortcode
│   │
│   ├── block/
│   │   └── class-nvoos-mcp-directory-block.php       # Gutenberg block (Phase 5)
│   │
│   └── admin/
│       └── class-nvoos-mcp-directory-settings.php    # Settings page
│
├── assets/
│   ├── css/
│   │   └── mcp-directory.css                  # Responsive grid, card styles, dark mode
│   └── js/
│       └── mcp-directory.js                   # Vanilla JS filtering/search
│
├── templates/
│   ├── directory-grid.php                     # Grid view template
│   ├── server-single.php                      # Single server detail template
│   └── server-card.php                        # Reusable card component
│
└── languages/
    └── .gitkeep
```

**Future SPA upgrade path (Phase 5+):** If a React frontend is desired, add `src/` with TypeScript components, `esbuild.config.js`, `tsconfig.json`, `package.json`, and `vitest.config.ts` — matching the `docs-hub` and `chat-spa` patterns exactly.

### 3.2 File Listing with Class Names

| File | Class | Purpose |
|---|---|---|
| `nvoos-mcp-directory.php` | — | Header, ABSPATH guard, constants, boot |
| `includes/class-nvoos-mcp-directory-plugin.php` | `NV_oOS_MCP_Directory_Plugin` | Singleton, hook registration, lifecycle |
| `includes/class-nvoos-mcp-directory-cpt.php` | `NV_oOS_MCP_Directory_CPT` | CPT + taxonomy + meta box registration |
| `includes/class-nvoos-mcp-directory-schema.php` | `NV_oOS_MCP_Directory_Schema` | JSON Schema validation against MCP spec |
| `includes/class-nvoos-mcp-directory-repository.php` | `NV_oOS_MCP_Directory_Repository` | Query methods (list, get, search, filter) |
| `includes/class-nvoos-mcp-directory-cache.php` | `NV_oOS_MCP_Directory_Cache` | Transient get/set/invalidate |
| `includes/class-nvoos-mcp-directory-well-known.php` | `NV_oOS_MCP_Directory_Well_Known` | `/.well-known/` discovery endpoints |
| `includes/rest/class-nvoos-mcp-directory-rest.php` | `NV_oOS_MCP_Directory_REST` | REST route registration + callbacks |
| `includes/shortcode/class-nvoos-mcp-directory-shortcode.php` | `NV_oOS_MCP_Directory_Shortcode` | Shortcode render + asset enqueue |
| `includes/admin/class-nvoos-mcp-directory-settings.php` | `NV_oOS_MCP_Directory_Settings` | Settings page registration + fields |

---

## 4. Data Model

### 4.1 Custom Post Type: `mcp_server`

```php
register_post_type( 'mcp_server', array(
    'label'         => __( 'MCP Servers', 'nvoos-mcp-directory' ),
    'public'        => true,
    'show_in_rest'  => true,       // Auto-exposes WP REST endpoints
    'supports'      => array( 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields' ),
    'has_archive'   => true,
    'rewrite'       => array( 'slug' => 'mcp-servers' ),
    'menu_icon'     => 'dashicons-networking',
    'show_in_menu'  => true,
) );
```

**Field mapping:**
- `post_title` → MCP server `name` (programmatic identifier)
- `post_content` → Full description / usage docs
- `post_excerpt` → Short description (tooltip/card preview)
- `thumbnail` → Server icon (maps to MCP `Implementation.icons[0]`)

### 4.2 Post Meta Fields

Every meta field is registered with `show_in_rest => true` and `sanitize_callback`.

| Meta Key | Type | MCP Spec Field | Example |
|---|---|---|---|
| `_mcp_server_version` | string | `Implementation.version` | `1.2.0` |
| `_mcp_server_website` | url | `Implementation.websiteUrl` | `https://github.com/nvdigitalsolutions/protoolkit-wc` |
| `_mcp_server_transport` | string (enum) | Transport type | `stdio`, `sse`, `streamable-http` |
| `_mcp_server_command` | string | Install command (stdio) | `npx @protoolkit/wc-mcp` |
| `_mcp_server_env_vars` | JSON | Required env vars | `{"API_KEY":"Description of key"}` |
| `_mcp_server_endpoint_url` | url | HTTP endpoint (sse/http) | `https://mcp.example.com/mcp` |
| `_mcp_server_auth` | string (enum) | Auth method | `none`, `oauth2`, `api_key`, `bearer` |
| `_mcp_server_tools_json` | JSON | Pre-fetched tool list (cached) | Array of MCP `Tool` objects |
| `_mcp_server_capabilities` | JSON | `ServerCapabilities` | `{"tools":{},"resources":{},"prompts":{}}` |
| `_mcp_server_status` | string (enum) | Operational status | `active`, `beta`, `deprecated` |
| `_mcp_server_rating` | float | Community rating (0-5) | `4.5` |
| `_mcp_server_install_count` | int | Popularity metric | `1240` |
| `_mcp_server_repo_url` | url | Source repository | `https://github.com/...` |

### 4.3 Taxonomy: `mcp_server_category`

```php
register_taxonomy( 'mcp_server_category', 'mcp_server', array(
    'label'        => __( 'Categories', 'nvoos-mcp-directory' ),
    'rewrite'      => array( 'slug' => 'mcp-server-category' ),
    'show_in_rest' => true,
    'hierarchical' => true,
) );
```

**Default terms (seeded on activation):**

`AI/LLM`, `WordPress`, `E-commerce`, `Developer Tools`, `Database`, `File System`, `Browser`, `Communication`, `Finance`, `Productivity`, `Search`, `Media`, `Security`, `Analytics`

---

## 5. REST API

### 5.1 Endpoints

```
Namespace: nvoos-mcp-directory/v1
```

| Method | Route | Permission | Description |
|---|---|---|---|
| `GET` | `/servers` | Public | List servers (paginated, filterable) |
| `GET` | `/servers/{slug}` | Public | Single server detail |
| `GET` | `/servers/{slug}/tools` | Public | Tools for a specific server |
| `GET` | `/categories` | Public | All categories with server counts |
| `GET` | `/search` | Public | Full-text search (servers + tools) |
| `GET` | `/servers.json` | Public | Machine-readable manifest (AI discovery) |

**Query parameters for `GET /servers`:**
- `category` — Filter by category slug
- `status` — Filter by status (`active`, `beta`, `deprecated`)
- `transport` — Filter by transport type
- `search` — Full-text search string
- `per_page` — Items per page (default 20, max 100)
- `page` — Page number

### 5.2 Response Shapes

**`GET /servers`:**

```json
{
  "servers": [
    {
      "slug": "protoolkit-woocommerce",
      "name": "Protoolkit WooCommerce",
      "title": "WooCommerce MCP Server",
      "version": "1.2.0",
      "description": "Exposes WooCommerce store data as MCP tools for AI assistants.",
      "websiteUrl": "https://github.com/nvdigitalsolutions/protoolkit-woocommerce",
      "icons": [
        {
          "src": "https://example.com/icon.png",
          "mimeType": "image/png",
          "sizes": ["48x48"]
        }
      ],
      "transport": "stdio",
      "command": "npx @protoolkit/wc-mcp",
      "envVars": { "WOO_COMMERCE_URL": "Your store URL" },
      "auth": "none",
      "capabilities": {
        "tools": {},
        "resources": {}
      },
      "categories": ["E-commerce", "WordPress"],
      "status": "active",
      "toolCount": 12,
      "rating": 4.8,
      "installCount": 340,
      "_links": {
        "self": "/wp-json/nvoos-mcp-directory/v1/servers/protoolkit-woocommerce",
        "tools": "/wp-json/nvoos-mcp-directory/v1/servers/protoolkit-woocommerce/tools"
      }
    }
  ],
  "total": 42,
  "totalPages": 3,
  "currentPage": 1
}
```

**`GET /servers/{slug}/tools`:**

```json
{
  "server": "protoolkit-woocommerce",
  "tools": [
    {
      "name": "get_products",
      "title": "Get Products",
      "description": "Retrieve WooCommerce products with filtering and pagination.",
      "inputSchema": {
        "type": "object",
        "properties": {
          "category": { "type": "string", "description": "Product category slug" },
          "limit": { "type": "integer", "default": 10 }
        }
      },
      "annotations": {
        "readOnlyHint": true,
        "destructiveHint": false,
        "idempotentHint": true,
        "openWorldHint": false
      }
    }
  ]
}
```

---

## 6. Shortcode API

### 6.1 Attributes

**`[nvoos_mcp_directory]`**

| Attribute | Values | Default | Description |
|---|---|---|---|
| `view` | `grid`, `list`, `single` | `grid` | Display mode |
| `category` | Category slug | `all` | Filter by category |
| `search` | `true`, `false` | `true` | Show search bar |
| `server` | Server slug | — | Show single server (overrides view) |
| `per_page` | int | `12` | Servers per page |
| `theme` | `auto`, `light`, `dark` | `auto` | Color scheme |

**Usage examples:**
```
[nvoos_mcp_directory]
[nvoos_mcp_directory category="wordpress" view="list"]
[nvoos_mcp_directory server="protoolkit-woocommerce"]
[nvoos_mcp_directory search="false" theme="dark"]
```

### 6.2 Rendering Strategy

**Phase 1–4 (v1):** PHP server-rendered templates with vanilla JavaScript for client-side filtering/search. No build toolchain required. Assets enqueued via `wp_enqueue_style`/`wp_enqueue_script`.

**Phase 5+ (v2 SPA upgrade):** React/TypeScript frontend in `src/`, built with esbuild (matching `docs-hub` pattern). The shortcode emits a mount `<div>` with `data-config` attribute, and the React bundle hydrates it. REST endpoints serve the data.

---

## 7. Key Features

### 7.1 Server Cards

Each server card (`templates/server-card.php`) displays:

1. **Icon** — Post thumbnail (64x64), falls back to category-based dashicon
2. **Name + version badge** — `Protoolkit WC v1.2.0`
3. **Category chips** — Clickable taxonomy links
4. **Transport badge** — `stdio` / `SSE` / `HTTP` pill
5. **Tool count** — e.g. "12 tools"
6. **Status indicator** — Green (active), yellow (beta), gray (deprecated)
7. **Short description** — Post excerpt (max 150 chars)
8. **"View Details" link** → single server page

### 7.2 AI-Friendly Discovery

Two endpoints for programmatic AI client discovery:

1. **`GET /.well-known/mcp/servers.json`** — Machine-readable manifest of all published servers, following SEP-1649 Server Card format
2. **`GET /wp-json/nvoos-mcp-directory/v1/servers.json`** — Same data via REST

This enables Claude, Cursor, and NV oOS's own assistant to discover servers without human browsing.

### 7.3 Caching Strategy

| Cache Key | TTL | Invalidated By |
|---|---|---|
| `nvoos_mcp_dir_servers_{hash}` | 1 hour | CPT save/delete, taxonomy change, settings update |
| `nvoos_mcp_dir_server_{slug}` | 24 hours | Post save for that server |
| `nvoos_mcp_dir_categories` | 1 hour | Taxonomy term change |
| `nvoos_mcp_dir_search_{hash}` | 15 minutes | Any CPT change |

All cache keys use WordPress transients (`set_transient`/`get_transient`/`delete_transient`).

### 7.4 Settings Page

```
Settings → MCP Directory

[✓] Enable directory                    (toggle on/off)
[  ] Public submissions                 (allow frontend submissions)
[  ] Default view: [grid ▾]             (grid / list)
[  ] Default theme: [auto ▾]            (auto / light / dark)
[  ] Servers per page: [12]
[  ] Cache TTL (minutes): [60]

Featured Servers:
[protoolkit-woocommerce] [×]
[protoolkit-analytics] [×]
[Add server...]
```

---

## 8. Alignment with Existing Addon Patterns

Every design decision follows conventions established by `graphify`, `docs-hub`, and `chat-spa`:

| Pattern | How This Addon Follows It |
|---|---|
| **Plugin header** | Standard WP header with ABSPATH guard, Text Domain, Domain Path |
| **Constants** | `NVOOS_MCP_DIRECTORY_VERSION`, `_FILE`, `_PATH`, `_URL` |
| **Naming convention** | `nvoos-mcp-directory`, `NV_oOS_MCP_Directory_*` |
| **Plugin class** | Static singleton with `init()`, `is_enabled()`, `get_settings()` |
| **Settings storage** | Single `get_option()` with `wp_parse_args()` defaults |
| **Shortcode pattern** | `static::register()`, `static::render()`, `static::enqueue_assets()`, `static::localize_once()` |
| **REST pattern** | `register_rest_route()` on `rest_api_init`, namespace constant, `permission_callback` on every route |
| **Base plugin optional** | `nvoos_mcp_directory_is_base_active()` — works standalone or with NV oOS |
| **Lifecycle hooks** | `register_activation_hook`, `register_deactivation_hook`, `uninstall.php` |
| **i18n** | `load_plugin_textdomain()` on `plugins_loaded`, all strings wrapped in `__()`/`esc_html__()` |

---

## 9. Implementation Phases

### Phase 1 — Scaffold & Core (est. 3–4 hours)
- [ ] Create `addons/mcp-directory/` directory structure
- [ ] Main plugin file `nvoos-mcp-directory.php` (header, constants, requires, boot)
- [ ] Plugin core class `NV_oOS_MCP_Directory_Plugin` (singleton, hooks)
- [ ] `README.md` with installation instructions

### Phase 2 — Data Model (est. 3–4 hours)
- [ ] CPT registration (`NV_oOS_MCP_Directory_CPT`)
- [ ] Taxonomy registration (`mcp_server_category`)
- [ ] Post meta registration with `show_in_rest => true`
- [ ] Admin meta boxes for server details
- [ ] Default category terms seeded on activation
- [ ] Schema validation class (`NV_oOS_MCP_Directory_Schema`)

### Phase 3 — Data Access (est. 2–3 hours)
- [ ] Repository class (`NV_oOS_MCP_Directory_Repository`) — query methods
- [ ] Cache class (`NV_oOS_MCP_Directory_Cache`) — transient management
- [ ] Settings page with all options

### Phase 4 — REST API (est. 3–4 hours)
- [ ] REST controller with all 6 endpoints
- [ ] Proper `permission_callback` on every route
- [ ] Argument validation/sanitization
- [ ] `.well-known/mcp/servers.json` discovery endpoint
- [ ] Caching headers (`Cache-Control`, `ttlMs`/`cacheScope` in response)

### Phase 5 — Frontend (est. 4–5 hours)
- [ ] Shortcode class with all attributes
- [ ] `server-card.php` template
- [ ] `directory-grid.php` grid template
- [ ] `server-single.php` detail template
- [ ] `mcp-directory.css` (responsive grid, cards, dark mode)
- [ ] `mcp-directory.js` (vanilla JS filtering/search, no framework)
- [ ] Gutenberg block (optional)

### Phase 6 — Polish (est. 2–3 hours)
- [ ] WP-CLI command for bulk import (`wp mcp-directory import`)
- [ ] i18n audit — text domain consistency, `.pot` generation
- [ ] `uninstall.php` with full data cleanup
- [ ] Integration test against NV oOS base plugin
- [ ] Documentation completed

**Total estimated effort: ~17–23 hours**

### Success Criteria
- [ ] `[nvoos_mcp_directory]` renders a responsive grid of server cards on any page
- [ ] `GET /wp-json/nvoos-mcp-directory/v1/servers` returns valid JSON with all server metadata
- [ ] `GET /.well-known/mcp/servers.json` returns SEP-1649-compatible server cards
- [ ] All REST routes have proper permission callbacks (no `__return_true` on write endpoints)
- [ ] CSS is responsive (mobile → desktop) with dark mode support
- [ ] All user-facing strings are translatable
- [ ] Plugin works standalone (without NV oOS base) and integrated (with base)

---

## Appendix A: Comparison — Why CPT Over Alternatives

| Approach | Pros | Cons | Verdict |
|---|---|---|---|
| **WordPress CPT** | Free admin UI, REST API, taxonomies, caching, capabilities | WordPress-only | ✅ **Best fit** — minimal code, maximum leverage |
| **Custom DB table** | Full schema control, no WP bloat | No admin UI, no REST auto-exposure, manual CRUD | ❌ Over-engineered for a directory |
| **Static JSON file** | Zero server overhead, CDN-cacheable | No dynamic filtering, manual updates | ❌ Only good for <10 servers |
| **External SaaS (MCP Market)** | Built-in discovery, no maintenance | No WordPress integration, dependency on 3rd party | ❌ Defeats the self-hosted goal |
| **Headless CMS** | API-first, flexible frontend | Extra infrastructure, overkill | ❌ Added complexity for no benefit |