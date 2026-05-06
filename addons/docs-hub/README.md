# NV oOS Docs Hub

A React-based documentation browser SPA bundled as a NV oOS addon.

It discovers, indexes, and renders Markdown documentation from the base plugin
and every installed addon, presenting it in a **GitBook-style three-column interface**.

---

## Features

| Feature | Details |
|---------|---------|
| **Auto-discovery** | Scans `docs/` folders in the base plugin, every addon, and any configured root paths |
| **React SPA** | Hash-router SPA with Sidebar → Content → Right-TOC three-column grid |
| **Full-text search** | REST-powered server search + FlexSearch client-side fallback |
| **Markdown rendering** | GFM tables, task lists, fenced code blocks, `:::note/tip/warning/danger` callouts |
| **Dark / light theme** | CSS custom-property tokens, togglable per user, respects `prefers-color-scheme` |
| **Caching** | Filesystem + WordPress transient two-layer cache; auto-invalidated on plugin updates |
| **Shortcode** | `[nvoos_docs]` embeds the SPA on any page or post |
| **Gutenberg block** | `nvoos/docs-hub` block (same output as shortcode) |
| **WP-CLI** | `wp nvoos-docs sync / clear / status` |
| **Cron rebuild** | Automatic nightly rebuild; also triggered on plugin activate/deactivate |

---

## Requirements

- **WordPress** 6.0+
- **PHP** 7.4+
- **NV oOS base plugin** active

---

## Installation

1. Copy `addons/docs-hub/` into `wp-content/plugins/nvoos-docs-hub/`.
2. Activate **NV oOS Docs Hub** in the WordPress plugins admin.
3. Go to **Settings → NV oOS Docs Hub** and click **Rebuild Index**.
4. Add `[nvoos_docs]` to any page to display the browser.

---

## Shortcode

```
[nvoos_docs section="base" theme="light" search="1" sidebar="1"]
```

| Attribute | Default | Description |
|-----------|---------|-------------|
| `section` | (all) | Filter to a single source (`base`, `addons`, addon slug) |
| `theme` | `auto` | `light`, `dark`, or `auto` (follows OS preference) |
| `search` | `1` | `0` to disable the search box |
| `sidebar` | `1` | `0` to hide the left sidebar |
| `home` | first page | Slug of the default landing page |

---

## REST API

Base: `GET /wp-json/nvoos-docs/v1`

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/manifest` | Public | Full manifest (tree, slug_map) |
| GET | `/pages/{slug}` | Public | Rendered page content + TOC |
| GET | `/search?q=…` | Public | Full-text search results |
| POST | `/rebuild` | `manage_options` + nonce | Trigger a full index rebuild |
| GET | `/health` | `manage_options` | Index statistics |

---

## WP-CLI

```bash
# Rebuild the index
wp nvoos-docs sync

# Clear all cached data
wp nvoos-docs clear

# Show index statistics
wp nvoos-docs status
```

---

## Settings

**Settings → NV oOS Docs Hub**

| Setting | Description |
|---------|-------------|
| Enable Docs Hub | Master on/off switch |
| Sources | Which sources to scan (base, addons, root, custom) |
| Context files | Whether to include `.context/*.md` files |
| Default theme | `light` or `dark` |
| Enable search | Toggle search box |
| Enable sidebar | Toggle left sidebar |
| Default home page | Slug shown when the user first opens the browser |
| GitHub repo URL | Optional link displayed in the header |
| Rebuild interval | Cron frequency (`hourly`, `twicedaily`, `daily`) |

---

## Filters & Actions

### Filters

| Filter | Description |
|--------|-------------|
| `nvoos_docs_hub_sources` | Array of source definitions (`type`, `root`, `plugin_name`) |
| `nvoos_docs_hub_excluded_globs` | Array of glob patterns to exclude |
| `nvoos_docs_hub_can_read_section` | Return `false` or `WP_Error` to restrict public REST access |
| `nvoos_docs_hub_can_render` | Return `false` to suppress the shortcode output |
| `nvoos_docs_hub_manifest` | Filter the final manifest array before caching |
| `nvoos_docs_hub_page` | Filter a page payload array before caching |
| `nvoos_docs_hub_page_content` | Filter the raw Markdown string before caching |

### Actions

| Action | Description |
|--------|-------------|
| `nvoos_docs_hub_before_rebuild` | Fired before a full rebuild starts |
| `nvoos_docs_hub_after_rebuild` | Fired after a full rebuild completes. Receives `$result` array |

---

## Caching

Pages are cached in two layers:

1. **Filesystem** — JSON files in `wp-content/uploads/nvoos-docs-hub/` (protected by `.htaccess` and `index.php` guards)
2. **WordPress transients** — fast key–value cache with 1-hour TTL

The filesystem cache acts as a warm-up source when transients expire.

Cache is automatically invalidated when any plugin is activated or deactivated.
Manual invalidation is available via the **Settings** page rebuild button, WP-CLI `wp nvoos-docs clear`, or the REST `POST /rebuild` endpoint.

---

## Development

```bash
# Install JS dependencies
cd addons/docs-hub && npm install

# Build (production)
npm run build

# Watch mode (development)
npm run watch

# Type check
npm run typecheck

# Lint
npm run lint
```

---

## File Structure

```
addons/docs-hub/
├── nvoos-docs-hub.php              ← Entry point
├── uninstall.php                   ← Cleanup on delete
├── composer.json
├── package.json
├── esbuild.config.js
├── includes/
│   ├── class-nvoos-docs-hub-plugin.php   ← Core singleton
│   ├── class-nvoos-docs-hub-scanner.php  ← File discovery
│   ├── class-nvoos-docs-hub-indexer.php  ← Manifest builder
│   ├── class-nvoos-docs-hub-cache.php    ← Two-layer cache
│   ├── class-nvoos-docs-hub-cli.php      ← WP-CLI commands
│   ├── admin/
│   │   └── class-nvoos-docs-hub-settings.php
│   ├── block/
│   │   ├── block.json
│   │   └── class-nvoos-docs-hub-block.php
│   ├── jobs/
│   │   └── class-nvoos-docs-hub-rebuild-job.php
│   ├── rest/
│   │   └── class-nvoos-docs-hub-rest.php
│   └── shortcode/
│       └── class-nvoos-docs-hub-shortcode.php
├── src/
│   ├── index.tsx                   ← React entry point
│   ├── App.tsx                     ← Root component
│   ├── api/manifest-client.ts      ← REST client + sessionStorage cache
│   ├── search/flexsearch-adapter.ts
│   ├── components/
│   │   ├── Breadcrumbs.tsx
│   │   ├── Callout.tsx
│   │   ├── CodeBlock.tsx
│   │   ├── ContentArea.tsx
│   │   ├── PrevNext.tsx
│   │   ├── RightTOC.tsx
│   │   ├── SearchBox.tsx
│   │   └── Sidebar.tsx
│   ├── routes/
│   │   ├── DocPage.tsx
│   │   └── NotFound.tsx
│   ├── styles/main.css
│   └── theme/tokens.ts
├── assets/dist/
│   ├── docs-hub.js                 ← Built bundle (git-ignored)
│   └── docs-hub.css                ← Built stylesheet (git-ignored)
├── tests/
│   ├── test-scanner.php
│   ├── test-indexer.php
│   ├── test-rest-manifest.php
│   └── test-shortcode.php
└── languages/
    └── .gitkeep
```

---

## License

GPL-3.0-or-later — see the root [`LICENSE`](../../LICENSE) file.
