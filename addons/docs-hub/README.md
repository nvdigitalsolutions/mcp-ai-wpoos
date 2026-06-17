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
| **Shortcode** | `[nvoos_docs]` embeds the SPA on any page or post — works for guests by default |
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

## Guest / Public Access

The shortcode is designed for **public-facing pages** — no login is required by default.

### How it works

- The **Allow Public (Guest) Access** setting (**Settings → NV oOS Docs Hub**) controls whether unauthenticated visitors can reach the REST endpoints (`/manifest`, `/pages/`, `/search`).
- When **enabled** (default): all visitors see the documentation browser. The React SPA makes requests without an authentication header so third-party REST auth plugins cannot accidentally block guests.
- When **disabled**: guests receive an HTTP 401 from the REST API and the SPA displays an error state. Logged-in users of any role are always allowed through.

### `.context/` files are always admin-only

Even when public access is enabled, pages whose `source` is `context` (i.e. files from the `.context/` directory) are **never** served to non-administrators:
- The manifest strips those groups for non-admins.
- Direct page requests for context slugs return HTTP 403.

This ensures internal agent-context documentation is never exposed to the public regardless of the public access setting.

### Restricting access programmatically

Use the `nvoos_docs_hub_can_render` filter to suppress the shortcode entirely:

```php
// Only show docs to logged-in users.
add_filter( 'nvoos_docs_hub_can_render', function( $can ) {
    return is_user_logged_in();
} );
```

Use the `nvoos_docs_hub_can_read_section` filter to restrict individual sections:

```php
// Hide the "internal" section from non-admins.
add_filter( 'nvoos_docs_hub_can_read_section', function( $can, $slug ) {
    if ( str_starts_with( $slug, 'internal/' ) ) {
        return current_user_can( 'manage_options' );
    }
    return $can;
}, 10, 2 );
```

### Admin notice

When an administrator views a page containing `[nvoos_docs]` while **Allow Public (Guest) Access** is disabled, a dismissible admin notice appears with a direct link to the settings page.

---

## REST API

Base: `GET /wp-json/nvoos-docs/v1`

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/manifest` | Public | Full manifest (tree, slug_map) |
| GET | `/pages/{slug}` | Public | Rendered page content + TOC |
| GET | `/search?q=…` | Public | Full-text search results |
| POST | `/rebuild` | `manage_options` + nonce | Enqueue a chunked rebuild (HTTP 202). Pass `?sync=1` for inline rebuild. |
| GET | `/rebuild/status` | `manage_options` | Current rebuild progress snapshot (phase, processed/total, errors). |
| POST | `/rebuild/cancel` | `manage_options` + nonce | Cancel an in-flight rebuild. |
| POST | `/rebuild/resume` | `manage_options` + nonce | Resume a stalled or failed rebuild. |
| GET | `/health` | `manage_options` | Index statistics + last rebuild summary. |

> Since v0.2.0, `POST /rebuild` is **asynchronous by default** — it returns immediately with HTTP 202 and a `{ job_id, status: "queued" }` body. The job runs in WP-Cron ticks; poll `/rebuild/status` to display progress. Pass `?sync=1` (legacy) to run the entire rebuild inline in one request.

---

## WP-CLI

```bash
# Async chunked rebuild (default, runs across WP-Cron ticks)
wp nvoos-docs rebuild

# Inline rebuild (legacy single-request behaviour, used by tests)
wp nvoos-docs rebuild --sync
# or the back-compat alias
wp nvoos-docs sync

# Resume a previously failed or canceled rebuild
wp nvoos-docs rebuild --resume

# Cancel an in-flight rebuild
wp nvoos-docs rebuild --cancel

# Clear all cached data
wp nvoos-docs clear

# Show index statistics + current rebuild phase / cursor
wp nvoos-docs status
```

---

## Settings

**Settings → NV oOS Docs Hub**

| Setting | Description |
|---------|-------------|
| Enable Docs Hub | Master on/off switch |
| **Allow Public (Guest) Access** | When on (default), all visitors can read docs without logging in. When off, a WordPress account is required. |
| Sources | Which sources to scan (base, addons, root, custom) |
| Context files | Whether to include `.context/*.md` files (always admin-only in REST responses) |
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
| `nvoos_docs_hub_excluded_globs` | Array of glob patterns to exclude. Defaults to a built-in list (`vendor/`, `node_modules/`, `bower_components/`, `.git/`, `.github/`, `dist/`, `build/`, `coverage/`, `tests/fixtures/`, `LICENSE.md`, `LICENSE.txt`, `CODE_OF_CONDUCT.md`, `THIRD_PARTY_NOTICES.md`). Return `[]` to opt out of all defaults. |
| `nvoos_docs_hub_force_include_globs` | Array of glob patterns that override `nvoos_docs_hub_excluded_globs` (re-admit specific vendored docs). |
| `nvoos_docs_hub_pruned_dir_names` | Array of directory basenames pruned during recursive scans (default: `vendor`, `node_modules`, `bower_components`, `.git`, `.github`, `.svn`, `dist`, `build`, `coverage`). |
| `nvoos_docs_hub_source_priority` | Map of source key → priority integer used by the indexer to award canonical slugs (`root` outranks `addons` so the plugin-root README wins the `readme` slug). |
| `nvoos_docs_hub_rebuild_chunk_size` | Number of files processed per chunked-rebuild tick (default `25`). |
| `nvoos_docs_hub_rebuild_tick_budget` | Per-tick wall-clock budget in seconds (default `15`). |
| `nvoos_docs_hub_max_files_total` | Aggregate cap on total indexed files per rebuild (default `5000`). |
| `nvoos_docs_hub_can_read_section` | Return `false` or `WP_Error` to restrict public REST access to a specific slug |
| `nvoos_docs_hub_can_render` | Return `false` to suppress the shortcode/block output entirely (e.g. for role-gating) |
| `nvoos_docs_hub_manifest` | Filter the final manifest array before caching |
| `nvoos_docs_hub_page` | Filter a page payload array before caching |
| `nvoos_docs_hub_page_content` | Filter the raw Markdown string before caching |

### Actions

| Action | Description |
|--------|-------------|
| `nvoos_docs_hub_before_rebuild` | Fired before a full rebuild starts |
| `nvoos_docs_hub_after_rebuild` | Fired after a full rebuild completes. Receives `$result` array |
| `nvoos_docs_hub_rebuild_phase` | Fired on every phase transition of the chunked rebuild pipeline. Receives `$phase, $state` so dashboards can hang off it. |

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
