# NV oOS Docs Hub

**React-based documentation browser for NV oOS.**

The Docs Hub addon discovers, indexes, and renders Markdown documentation from the base plugin and every active addon in a GitBook-style single-page application. It exposes the corpus via a REST API and embeds the SPA on any page using the `[nvoos_docs]` shortcode.

---

## Contents

- [Installation](#installation)
- [Shortcode](#shortcode)
- [Gutenberg Block](#gutenberg-block)
- [REST API](#rest-api)
- [Admin Settings](#admin-settings)
- [WP-CLI](#wp-cli)
- [Filters and Actions](#filters-and-actions)
- [Security Model](#security-model)
- [Technical Architecture](#technical-architecture)
- [Changelog](#changelog)

---

## Installation

1. Upload or symlink the `nvoos-docs-hub` folder to `/wp-content/plugins/`.
2. Activate **NV oOS Docs Hub** through the WordPress admin (requires NV oOS base plugin).
3. Navigate to **Settings → NV oOS Docs Hub** to configure which sources are indexed.
4. Click **Rebuild Documentation Index** to perform the initial scan.
5. Add `[nvoos_docs]` to any page or post to embed the documentation browser.

### Build the React SPA (developers)

```bash
cd addons/docs-hub
npm install
npm run build
```

The compiled bundle is output to `assets/dist/docs-hub.js` and `assets/dist/docs-hub.css`.

---

## Shortcode

```
[nvoos_docs section="all" theme="auto" search="true" sidebar="true" home="readme"]
```

### Attributes

| Attribute | Default | Description |
|-----------|---------|-------------|
| `section` | `all` | Restrict to a documentation section slug (default: show all). |
| `theme` | `auto` | Color theme: `auto` (system), `light`, or `dark`. |
| `search` | `true` | Show/hide the search box. |
| `sidebar` | `true` | Show/hide the tree sidebar. |
| `home` | *(first page)* | Slug of the page to redirect to from `/`. |

### Example — user-facing docs only

```
[nvoos_docs section="base" theme="light" home="getting-started"]
```

---

## Gutenberg Block

Search for **NV oOS Docs Hub** in the Block Editor. The block wraps the same
shortcode logic with an editor settings panel for the same five attributes.

---

## REST API

All endpoints live under `/wp-json/nvoos-docs/v1/`.

### `GET /manifest`

Returns the full sidebar tree, slug map, and index metadata.

**Response:**

```json
{
  "version": "1.0.0",
  "built_at": 1746561600,
  "total_pages": 48,
  "broken_links": [],
  "tree": [ { "source": "base", "plugin_name": "NV oOS Base", "pages": [ ... ] } ],
  "slug_map": { "readme": { "path": "...", "title": "README", ... } }
}
```

Cache-Control: `public, max-age=300`.

---

### `GET /pages/{slug}`

Returns a single page payload. Slug may contain `/` to represent nested paths
(e.g. `/pages/features/chat`).

**Response:**

```json
{
  "slug": "features/chat",
  "title": "Chat Interface",
  "markdown": "# Chat Interface\n\n...",
  "toc": [ { "level": 2, "text": "Overview", "anchor": "overview" } ],
  "frontmatter": { "audience": "developer" },
  "breadcrumbs": [ { "label": "NV oOS Base", "slug": null }, { "label": "Chat Interface", "slug": "features/chat" } ],
  "prev": { "slug": "installation", "title": "Installation" },
  "next": { "slug": "features/tools", "title": "Tools" },
  "source": "base",
  "plugin_name": "NV oOS Base",
  "last_modified": 1746561600,
  "word_count": 1240,
  "languages": ["php", "javascript"]
}
```

Returns **404** for unknown slugs.

---

### `GET /search?q={query}&limit={n}`

Full-text search over the documentation corpus.

| Param | Type | Default | Max |
|-------|------|---------|-----|
| `q` | string | required | 100 chars |
| `limit` | integer | 20 | 50 |

**Response:**

```json
{
  "query": "installation",
  "total": 3,
  "results": [
    { "slug": "installation", "title": "Installation", "excerpt": "...", "plugin_name": "NV oOS Base", "source": "base", "score": 15 }
  ]
}
```

---

### `POST /rebuild`

Triggers a full documentation rebuild. Requires `manage_options` capability and a valid `X-WP-Nonce` header (value from `wp_create_nonce('wp_rest')`).

**Response on success:**

```json
{ "success": true, "pages": 48, "broken_links": 2, "duration_ms": 340 }
```

---

### `GET /health`

Returns index statistics. Requires `manage_options`.

```json
{ "total_pages": 48, "broken_links": 2, "last_built": 1746561600, "version": "1.0.0" }
```

---

## Admin Settings

Navigate to **Settings → NV oOS Docs Hub**.

| Setting | Description |
|---------|-------------|
| **Enable Addon** | Master on/off switch. |
| **Sources: Base Plugin** | Index `docs/` from the NV oOS base plugin. |
| **Sources: Addons** | Index `docs/` + `README.md` from every active addon. |
| **Sources: Repository Root** | Index top-level `README.md`, `CHANGELOG.md`, etc. Only available when `WP_DEBUG` is on. |
| **Sources: Context Files** | Index `.context/*.md` internal files. Requires `manage_options` — never exposed to public. |
| **Default Theme** | `auto` / `light` / `dark`. |
| **Search Enabled** | Enable the search box. |
| **Sidebar Enabled** | Enable the sidebar tree. |
| **Default Home Slug** | Slug of the landing page. |
| **GitHub Repo URL** | Base URL for "Edit on GitHub" links (e.g. `https://github.com/yourorg/yourrepo`). |

The **Rebuild Documentation Index** button runs a full rescan. The page shows:
- Last build timestamp.
- Total pages indexed.
- Broken internal links (expandable list).

---

## WP-CLI

```bash
# Full rebuild — exits non-zero if broken links are present with --strict.
wp nvoos-docs sync
wp nvoos-docs sync --strict

# Clear the documentation index cache.
wp nvoos-docs clear

# Print status information.
wp nvoos-docs status
```

---

## Filters and Actions

### Filters

#### `nvoos_docs_hub_sources`

Modify the list of enabled source keys before scanning.

```php
add_filter( 'nvoos_docs_hub_sources', function( $sources ) {
    // Add a custom source key.
    $sources[] = 'my_plugin';
    return $sources;
} );
```

---

#### `nvoos_docs_hub_excluded_globs`

Add glob patterns to exclude files from indexing.

```php
add_filter( 'nvoos_docs_hub_excluded_globs', function( $globs ) {
    $globs[] = 'docs/internal/*.md';
    $globs[] = 'DRAFT_*.md';
    return $globs;
} );
```

---

#### `nvoos_docs_hub_manifest`

Modify the built manifest before it is cached and served.

```php
add_filter( 'nvoos_docs_hub_manifest', function( $manifest ) {
    // Add custom metadata.
    $manifest['my_meta'] = 'value';
    return $manifest;
} );
```

---

#### `nvoos_docs_hub_page_payload`

Modify a per-page payload before it is served via the REST API.

```php
add_filter( 'nvoos_docs_hub_page_payload', function( $payload, $slug ) {
    if ( 'installation' === $slug ) {
        $payload['markdown'] .= "\n\n> **Tip:** Updated in v1.1.15.";
    }
    return $payload;
}, 10, 2 );
```

---

#### `nvoos_docs_hub_can_read_section`

Gate access to a documentation section by slug.

```php
// Require login for the "context" section.
add_filter( 'nvoos_docs_hub_can_read_section', function( $can_read, $slug ) {
    if ( str_starts_with( $slug, 'context/' ) ) {
        return current_user_can( 'manage_options' );
    }
    return $can_read;
}, 10, 2 );
```

---

#### `nvoos_docs_hub_search_results`

Post-process search results (e.g. to inject Algolia results in Phase 2).

```php
add_filter( 'nvoos_docs_hub_search_results', function( $results, $query ) {
    // Append results from another source.
    return $results;
}, 10, 2 );
```

---

#### `nvoos_docs_hub_edit_url`

Return a custom "Edit on GitHub" URL template. The placeholder `{path}` is
replaced with the relative file path.

```php
add_filter( 'nvoos_docs_hub_edit_url', function( $url, $relative_path ) {
    return 'https://github.com/yourorg/yourrepo/edit/main/' . $relative_path;
}, 10, 2 );
```

---

### Actions

#### `nvoos_docs_hub_before_rebuild`

Fires immediately before a full documentation rebuild starts.

```php
add_action( 'nvoos_docs_hub_before_rebuild', function() {
    // e.g. flush a CDN cache.
} );
```

---

#### `nvoos_docs_hub_after_rebuild`

Fires after a successful rebuild. Receives the result array.

```php
add_action( 'nvoos_docs_hub_after_rebuild', function( $result ) {
    error_log( 'Docs rebuilt: ' . $result['pages'] . ' pages, ' . $result['duration_ms'] . 'ms' );
} );
```

---

## Security Model

| Concern | Mitigation |
|---------|-----------|
| **Path traversal** | Every scanned file path is resolved with `realpath()` and must be strictly within an allowed root directory. |
| **File-type restriction** | Only `.md` and `.txt` extensions are accepted. |
| **File-size cap** | Files larger than 2 MB are skipped. |
| **XSS via markdown** | `react-markdown` does not render raw HTML by default. The `dangerouslySetInnerHTML` API is never used. |
| **Capability checks** | `/rebuild` and `/health` require `manage_options`. Context files require `manage_options` both at indexing time and read time. |
| **Nonce verification** | POST `/rebuild` verifies `X-WP-Nonce`. Admin rebuild button uses a form nonce. |
| **Cache directory** | `wp-content/uploads/nvoos-docs-hub/` includes `.htaccess` (Deny from all) and `index.php` guards. |
| **No shell execution** | No `shell_exec`, `exec`, `proc_open`, or `eval` calls anywhere in the addon. |
| **ABSPATH guard** | Every PHP file begins with `if ( ! defined( 'ABSPATH' ) ) { exit; }`. |

---

## Guest / Public Access (v0.3.8+)

The `[nvoos_docs]` shortcode supports guest/public access with configurable security controls:

### Public Access Setting

Navigate to **Settings → NV oOS Docs Hub → General Settings**:

- **Allow Public (Guest) Access** — When enabled, unauthenticated users can view documentation via the `[nvoos_docs]` shortcode. When disabled, only logged-in users can access the documentation.

### Context File Protection

`.context/` source pages (internal agent context files) are automatically protected:

- **Non-admin users** — Context pages are stripped from the manifest and return HTTP 403 if accessed directly.
- **Admin users** (`manage_options`) — Can view context pages when the "Sources: Context Files" setting is enabled.

### Filter Hooks

#### `nvoos_docs_hub_can_render`

Control whether the shortcode should render on a specific page:

```php
add_filter( 'nvoos_docs_hub_can_render', function( $can_render, $post_id ) {
    // Disable docs hub on specific pages
    if ( 123 === $post_id ) {
        return false;
    }
    return $can_render;
}, 10, 2 );
```

#### `nvoos_docs_hub_public_permission`

Customize the public access permission check:

```php
add_filter( 'nvoos_docs_hub_public_permission', function( $allowed ) {
    // Add custom logic (e.g., check for a specific cookie)
    return $allowed;
} );
```

### Admin Notice

When the `[nvoos_docs]` shortcode is present on a page but public access is disabled, administrators see a dismissible notice:

> **Docs Hub:** The `[nvoos_docs]` shortcode is active on this page, but public access is disabled in settings. Only logged-in users will see the documentation.

### REST API Permissions

| Endpoint | Guest (Public Access ON) | Guest (Public Access OFF) | Logged-in |
|----------|-------------------------|--------------------------|-----------|
| `GET /manifest` | ✅ | ❌ 401 | ✅ |
| `GET /page/{slug}` | ✅ (context pages excluded) | ❌ 401 | ✅ |
| `GET /search` | ✅ | ❌ 401 | ✅ |
| `POST /rebuild` | ❌ 403 | ❌ 403 | ❌ 403 (requires `manage_options`) |

---

## Technical Architecture

```
addons/docs-hub/
├── nvoos-docs-hub.php          ← Plugin entry (constants, require_once, boot)
├── includes/
│   ├── class-nvoos-docs-hub-plugin.php     ← Singleton, hooks, cron
│   ├── class-nvoos-docs-hub-scanner.php    ← File discovery (path-safe glob)
│   ├── class-nvoos-docs-hub-indexer.php    ← Frontmatter, TOC, slug, prev/next
│   ├── class-nvoos-docs-hub-cache.php      ← Transient + filesystem JSON cache
│   ├── class-nvoos-docs-hub-cli.php        ← WP-CLI commands
│   ├── shortcode/class-nvoos-docs-hub-shortcode.php
│   ├── block/class-nvoos-docs-hub-block.php
│   ├── rest/class-nvoos-docs-hub-rest.php  ← REST routes
│   ├── admin/class-nvoos-docs-hub-settings.php
│   └── jobs/class-nvoos-docs-hub-rebuild-job.php
├── src/                        ← React 19 + TypeScript SPA
│   ├── index.tsx               ← Mounts on .nvoos-docs-hub-root elements
│   ├── App.tsx                 ← HashRouter layout + manifest fetch
│   ├── api/manifest-client.ts  ← sessionStorage-cached REST calls
│   ├── components/             ← Sidebar, ContentArea, RightTOC, SearchBox, …
│   ├── routes/                 ← DocPage, NotFound
│   ├── search/                 ← FlexSearch adapter
│   └── styles/main.css         ← Scoped CSS under .nvoos-docs-hub-root
└── assets/dist/                ← Compiled bundle (docs-hub.js / .css)
```

### Data flow

1. On activation (or cache miss), `NV_oOS_Docs_Hub_Rebuild_Job::run()` calls the
   Scanner → Indexer pipeline.
2. The Indexer writes `manifest.json`, `pages/*.json`, and `search-index.json`
   into `wp-content/uploads/nvoos-docs-hub/`.
3. REST endpoints read from cache; the React SPA fetches via the REST API.
4. The SPA uses `react-router-dom` with `HashRouter` so it works on any URL
   without WordPress rewrite rules.
5. Search is handled by a FlexSearch Document index (client-side) seeded from
   the manifest data on first load.

### Cache invalidation

The cache is automatically cleared when:

- A plugin is activated or deactivated.
- A plugin is upgraded (`upgrader_process_complete`).
- The "Rebuild" admin button is clicked.
- The `wp nvoos-docs sync` CLI command is run.

---

## Changelog

### 1.0.0 (2026-05-06)

Initial release.

- Scanner discovers `.md` files from base plugin, addons, and optionally repo root / `.context/`.
- Indexer extracts frontmatter, heading TOC, internal links, word count, code languages.
- REST API: `/manifest`, `/pages/{slug}`, `/search`, `/rebuild`, `/health`.
- React 19 SPA with three-column GitBook-style layout; shortcode `[nvoos_docs]`; Gutenberg block.
- Admin settings page with rebuild button and broken-link report.
- WP-CLI: `wp nvoos-docs sync|clear|status`.
- PHPUnit tests for scanner, indexer, REST, shortcode.
- PHP 7.4+ compatible.
