# NV oOS Docs Hub — Changelog

## Unreleased (0.4.2)

### Fixed
- **Clicking internal links on local pages left the SPA.** ContentArea only resolved same-repo GitHub links to `#/slug` hash routes on remote-sourced pages. On local pages (base/addons/root sources) every `[text](other.md)` link rendered as a plain relative href, so clicking it navigated the browser away from the docs browser (usually to a 404). The SPA now resolves internal `.md` links against the page's `relative_path` and the manifest slug set, rewriting them to `#/slug` routes (heading anchors preserved via `#/slug#anchor`), and scrolls to the anchor after cross-page navigation.
- **TOC anchors silently did nothing on many pages.** The server-side heading anchors were generated with a bespoke slugifier that disagreed with github-slugger (rehype-slug) on underscores, hyphen runs and duplicate headings — so clicking a "On this page" link found no matching element id. `slugify_heading()` now mirrors github-slugger exactly (lowercase, keep `_`/`-`, spaces → hyphens without collapsing, `-1`/`-2` duplicate suffixes) and slugs the *rendered* text (inline Markdown stripped) instead of the raw line. Verified: 0 mismatches across ~63,000 headings in the full local index.
- **"Accept fix" wrote links that were still broken.** `suggest_fix()` returned slug-derived targets (e.g. `features/foo.md`) without accounting for the source page's directory, so ~87% of accepted fixes produced a path that still didn't resolve. Suggestions now compute the target relative to the source file's directory (preserving on-disk filename case), so the rewritten link resolves.
- **The fixer rejected every `../` link.** The old guard blocked *any* target containing `..`, which is exactly the shape of most genuinely-broken links (`../../moved.md`). It now validates target shape (no schemes, absolute paths, backslashes or null bytes) and containment-checks the *resolved* destination against the plugin/content roots (filterable via `nvoos_docs_hub_fixer_allowed_roots`).
- **"Accept All Suggestions → Fixed 0 link(s). 28 skipped" with no explanation.** Three changes:
  1. Broken-link entries now carry `slug` and `source_type`; the REST fixer resolves the source file by slug (relative paths like `README.md` exist in many addons, so relative-path matching could edit the wrong file).
  2. Remote-sourced rows no longer show an "Accept fix" button (remote repos are read-only); they display "Remote source — fix in the source repository" instead.
  3. The settings table now shows the server-provided reason next to each skipped row and reports error counts in the status line.
- **`wp nvoos-docs sync` / `POST /rebuild?sync=1` reported success when nothing was persisted.** The sync path ignored `promote_staging()`'s result, so an unwritable uploads directory produced "Rebuilt in 0ms. Pages: N" while the cache stayed empty. It now throws and surfaces "Atomic swap failed: staging cache empty or unwritable."
- **Unreachable 404 route.** `App.tsx` declared a `path="*"` fallback after `path="/*"`, which already matches everything; the dead route is removed (DocPage renders NotFound itself).

### Tests
- New `tests/test-link-fixer.php`: parent-relative fixes inside allowed roots, rejection of escapes/absolute targets, remote-source skips, end-to-end apply, and slug-vs-relative-path resolution ambiguity.
- New indexer cases: github-slugger anchor parity (underscores, hyphen runs, duplicate suffixes, link/bold headings), relative suggestion targets, remote slug-based targets, and `slug`/`source_type` on broken-link entries.
- New vitest cases for ContentArea local link resolution (hash-route rewrite, fragment preservation, parent-relative resolution, unindexed/absolute passthrough).
- Repaired `test-shortcode.php` drift (`init()` → `register()`, script-queue leak between tests).
- Made the suite runnable standalone and in a single PHPUnit invocation:
  - `test-rebuild-chunked.php` now pins local sources and bounds rebuilds via `nvoos_docs_hub_max_files_total` (previously failed with zero pages on a fresh test DB and left the exclusion test with no assertions); the sync rebuild path now honours that filter too.
  - `test-rest-manifest.php` now loads the rebuild job/pipeline classes it exercises.
  - `test-remote-tree-endpoint.php` loads `NV_oOS_Docs_Hub_Remote_Repo` at file scope (its stub class extends it, so `setUp()` was too late).
  - `test-fnmatch-polyfill.php` is a standalone script that used to `exit()` mid-run when PHPUnit discovered it — it now no-ops under PHPUnit.
  - Search REST responses always include the `query` key, even when the index is empty.

## 0.4.1 — 2026-08-19

### Fixed
- **Rebuild not persisted after a plugin update.** The NV oOS base plugin's built-in updater replaces plugin files in place and never fires `upgrader_process_complete`, so the Docs Hub cache was never invalidated after an update — the index kept showing stale pages and a stale broken-link list. Three changes close the gap:
  1. The base plugin updater now fires a new `wp_mcp_ai_plugin_updated` action after every successful in-place update (core, Pro addon, and base→complete flows).
  2. The Docs Hub reacts to that action — and to NV-oOS plugin activation/deactivation — by clearing the cache **and enqueueing an async rebuild** (previously it only cleared, and only a later admin manifest request would re-enqueue). The cached remote file content is preserved during these rebuilds so a plugin update does not re-fetch every Markdown file from GitHub.
  3. A new `admin_init` guard compares the manifest's stored addon/base versions (`version`, new `base_version` field) with the installed versions and rebuilds on the first admin visit if they differ, covering any update path that skipped the hooks (manual file replacement, restores, etc.).
- **Broken links never showed suggestions.** The manifest predating the suggestion engine could not be regenerated because of the stale-rebuild bug above. The detection engine itself was also hardened so a rebuild actually produces useful results:
  - `detect_broken_links()` now resolves relative links against the indexed slug map before falling back to `realpath()`. Remote sources cache files under flat content-hash names, so the filesystem check flagged every repo-relative link as broken even when the target page existed — the primary cause of "626 broken links" on a small remote-only index.
  - `suggest_fix()` fuzzy matching is now case-insensitive and compares both the bare filename stem and the full normalized target path against full slugs and slug basenames, so files moved between directories (e.g. `features/pro-toolkit-optimization.md` → root) produce suggestions.
  - Suggestion confidence is clamped to [0.3, 1.0]; exact basename matches previously produced values above 1.0.

### Changed
- Plugin update/activation cache invalidation is now scoped to NV-oOS-related plugins (`mcp-ai-wpoos*`, `nvoos-docs-hub`) instead of firing for every plugin on the site.

### Tests
- New `test-rebuild-job.php` covers plugin-update scoping, cache clear + rebuild enqueueing on NV-oOS plugin events, the `wp_mcp_ai_plugin_updated` notice path, and the `admin_init` version-mismatch guard.
- New `test-indexer.php` cases cover slug-map link resolution (remote flat-cache layout), `../` parent-segment resolution, moved-file suggestions, and confidence clamping / case-insensitive matching.

## 0.4.0 — 2026-05-18

### Fixed
- **`Unexpected token '<', "<!DOCTYPE..."` error when opening docs pages.** Page-caching plugins (WP Rocket, W3 Total Cache, WP Super Cache, etc.) sometimes serve a cached HTML page in response to REST API requests, producing a 200 OK with an HTML body. `apiFetchPublic` and `apiFetchAuthed` previously called `res.json()` unconditionally once `res.ok` was true, so a cached HTML body caused the raw `SyntaxError: Unexpected token '<', "<!DOCTYPE..."` to surface — either in the "Could not load documentation" error banner (manifest fetch) or silently masked as a "404 Page Not Found" in `DocPage` (page fetch). Two changes close this gap:
  1. Both fetch helpers now send `Accept: application/json` so CDNs and caching layers know to vary on content type (and typically skip their HTML cache for API requests).
  2. Both fetch helpers now inspect the `Content-Type` response header before calling `res.json()`. When the content type is not `application/json` they throw a human-readable error: *"The REST API returned a non-JSON response … This is usually caused by a page-caching plugin … Please exclude REST API paths (/wp-json/) from caching."*
- **Non-404 page-load failures shown as "404 Page Not Found".** `DocPage` now tracks a separate `pageError` state. A genuine REST 404 still shows the "404 Page Not Found" component (with the slug displayed). Any other error (non-JSON response, network failure, etc.) now renders a "Could not load page" error block with the full diagnostic message instead of the misleading 404 heading.

## 0.3.9 — 2026-05-08

### Fixed
- **Fatal error registering the WP sitemap provider.** `nvoos_docs_hub_register_sitemap_provider()` was hooked into `wp_sitemaps_add_provider`, whose first argument is the provider being registered (e.g. `WP_Sitemaps_Posts`) — not the sitemap registry. Calling `add_provider()` on that object produced `Fatal error: Uncaught Error: Call to undefined method WP_Sitemaps_Posts::add_provider()` during `wp-settings.php` boot, taking the whole site down. The hook is now `wp_sitemaps_init`, which passes the `WP_Sitemaps` server instance, and the provider is registered against `$wp_sitemaps->registry` with an explicit `'nvoos-docs'` name as required by `WP_Sitemaps_Registry::add_provider()`.

## 0.3.8 — 2026-05-08

### Added
- **Syntax highlighting (§5).** Code blocks are now token-coloured by [`rehype-highlight`](https://github.com/rehypejs/rehype-highlight) (using `lowlight` / `highlight.js`). A scoped GitHub-inspired CSS theme ships inside `docs-hub.css` — both light and dark variants — so the colours stay inside the SPA root and cannot bleed into the host WordPress page. The `CodeBlock` component now accepts pre-tokenised React nodes from rehype while still correctly stringifying the raw code for the copy-to-clipboard button.
- **Last-modified date (§5).** `DocPage` now renders a `<footer>` bar below the prev/next navigation. When `page.last_modified` is present, it shows a human-readable, locale-aware date (e.g. "Last updated: May 8, 2026") in a `<time>` element with an ISO-8601 `dateTime` attribute.
- **Edit on GitHub (§5).** The same footer bar shows a ✏ "Edit on GitHub" link when a URL can be derived:
  - Remote pages: `page.remote_url` is already the GitHub blob URL → used directly.
  - Local pages: the admin-configured "Edit on GitHub base URL" setting (`github_repo_url`) is combined with `page.relative_path` (new field added to the page payload by the indexer).
  The base URL is now also passed to the SPA via `NVOOS_DOCS_HUB.githubRepoUrl` in `wp_localize_script`.
- **`relative_path` in page payload.** The indexer now includes `relative_path` (e.g. `docs/getting-started.md`) in the serialised page payload so the SPA can construct the edit-on-GitHub URL for locally-sourced pages.
- **`assets/admin/repo-picker.js` — inline script extracted (§7).** The 220-line inline `<script>` block that powered the remote-repo "add row / browse / tree picker" on the settings page has been extracted to a proper static asset at `assets/admin/repo-picker.js`. It is registered and enqueued via `wp_enqueue_script` + `wp_localize_script` (config object: `window.NVOOS_DH_REPO_PICKER`), making it inspectable, cacheable, and verifiable with a CSP `script-src` allowlist. The translatable strings are now also available to WP-CLI's `wp i18n make-pot` extractor.
- **WordPress Sitemap integration (§8).** A new `NV_oOS_Docs_Hub_Sitemap_Provider` class extends `WP_Sitemaps_Provider` (WordPress 5.5+) to include all indexed documentation pages in the site's auto-generated sitemap under `/wp-sitemap-nvoos-docs-*.xml`. Each entry carries a `<lastmod>` timestamp when available. The provider respects the existing "enabled" toggle and can be fully disabled via the new `nvoos_docs_hub_sitemap_enabled` filter.
- **Bundle-size CI guardrail for docs-hub (§7).** `docs-hub` is now included in `.github/workflows/spa-bundle-size.yml` (limit 250 KB gzip, current size ≈ 204 KB) so accidental dependency bloat is caught in CI.

### Changed
- `NV_oOS_Docs_Hub_Settings::init()` now registers an `admin_enqueue_scripts` hook (`enqueue_admin_assets`) that conditionally loads `nvoos-dh-repo-picker` only on the Docs Hub settings page.

### Internal
- `NV_oOS_Docs_Hub_Sitemap_Provider` caches the docs-page URL scan result in a one-hour transient (`nvoos_docs_hub_sitemap_page_url`) to avoid scanning all published pages on every sitemap request.

## 0.3.7 — 2026-05-08

### Added
- **A11y root attributes (§K).** The shortcode and block now render the SPA mount as `<div role="application" aria-label="Documentation browser" …>`, so screen readers and a11y test runners (axe, Lighthouse) can describe the region before React hydrates. The same attributes are kept on the React-side wrapper so they persist after mount.
- **Skip-link to main content.** `<a class="dh-skip-link" href="#nvoos-dh-main">Skip to main content</a>` is rendered as the first focusable child of the SPA. It is visually hidden until focused (Tab) and pops into the top-left as a high-contrast pill, letting keyboard users bypass the sidebar to land on the article body. `DocPage` and `NotFound` now wrap the content cell in `<main id="nvoos-dh-main" tabIndex="-1">` so the skip-link target is a true landmark.
- **`prefers-reduced-motion` support (WCAG 2.1 SC 2.3.3).** `assets/dist/docs-hub.css` now ships an `@media (prefers-reduced-motion: reduce)` block that neutralises animations / transitions inside the SPA root and renders the loading spinner as a static disc.
- **RTL mirror.** PHP detects `is_rtl()` and adds a `nvoos-docs-hub-rtl` class to the SPA root so Hebrew / Arabic / Persian sites mirror the three-column layout (sidebar borders flip; grid direction follows automatically). `NVOOS_DOCS_HUB.isRtl` is also localized for any future React-side branching.

### Changed
- **`wp_localize_script` and asset registration are now deduped (§F).** Multiple `[nvoos_docs]` shortcodes / blocks on the same page used to re-register the bundle and re-emit identical inline `<script>NVOOS_DOCS_HUB = {…}` tags on every render. The new `NV_oOS_Docs_Hub_Shortcode::localize_once()` runs at most once per page-load, and `enqueue_assets()` short-circuits its `wp_register_*` calls after the first invocation. Each instance still gets a unique `id="nvoos-docs-hub-root-N"` so future per-instance config (when we move it server-side) won't collide.

## 0.3.6 — 2026-05-08

### Fixed
- **Critical error on Settings → NV oOS Docs Hub.** A malformed `remote_repos` row (string / null / scalar) saved by a partial migration would fatal the settings page on PHP 7.4 once the renderer hit `is_array($r['selected_paths'])`. `NV_oOS_Docs_Hub_Plugin::get_settings()` now coerces `remote_repos` to a list of array rows at the source, and `render_remote_repos()` defensively falls back to defaults (with a clear inline notice) for any row that survives as non-array — matching the §A hardening from the docs-hub review.
- **REST `remote_tree` index bounds-check.** `index` is now validated against `count($repos)` and the row's array shape before its persisted token is reused, so a tampered request can't reach into an unrelated array key.

### Added
- **Force-refresh now clears the per-file content cache.** When the admin "Refresh" button calls `/remote/tree?force=true`, the matching files in `wp-content/uploads/nvoos-docs-hub/remote/` are deleted for the resolved ref so the next "Rebuild Documentation Index" re-fetches fresh blob content. New public method `NV_oOS_Docs_Hub_Remote_Repo::clear_local_cache_for_files()`.
- **Defensive `coerce_path_list()`** helper on the settings class so a stored path list that arrived as a flat string round-trips correctly through the textarea.

### Changed
- **SSRF helper now resolves IPv4 + IPv6.** `safe_get()` previously called `gethostbyname()` which is A-record only and silently failed on AAAA-only hosts. The new `resolve_public_ip()` helper queries `dns_get_record($host, DNS_A | DNS_AAAA)`, validates *every* candidate against `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE` (defence in depth against rebinding tricks where one record is public and another is private), prefers IPv4, and pins the chosen IP via `CURLOPT_RESOLVE` (with the bracket syntax IPv6 needs). Falls back to `gethostbyname()` when `dns_get_record()` is unavailable.

### Documentation
- This CHANGELOG entry catches up the v0.3.3 → v0.3.5 gap implicitly: those tags shipped no user-facing changes beyond the v0.3.2 PHPCS pass and version-string churn. v0.3.6 is the first substantive release after v0.3.2.

### Tests
- New `test-remote-repos-defensive.php` covers the §A regression (settings render does not fatal on a malformed row, and `get_settings()` filters non-array rows out).
- New `test-remote-tree-force-clears-cache.php` covers the `force=true` per-file cache invalidation.
- New `test-remote-repo-ssrf.php` covers AAAA / mixed-record rejection in `resolve_public_ip()`.

## 0.3.2 — 2026-05-07

### Fixed
- **PHPCS lint compliance.** Resolved 96 errors / 38 warnings flagged on the v0.3.0/0.3.1 changes: associative-array spacing, missing function/class docblocks in the new test files, inline-comment punctuation, parameter-comment full stops, blank-line-after-class-comment, and a Yoda-condition violation in `Remote_Repo::fetch_tree()`'s SHA lookup. Auto-fixable issues fixed via `phpcbf`; remaining ones fixed by hand. Pre-existing `NV_oOS_*` class-name warnings (intentional addon-wide convention) are left untouched.

## 0.3.1 — 2026-05-07

### Fixed
- **Rebuild Documentation Index → 404.** The admin rebuild panel was building its REST URL from the literal string `nvoos-docs-hub/v1`, but the actual REST namespace is `nvoos-docs/v1`. Switched to `NV_oOS_Docs_Hub_REST::NAMESPACE` so all four rebuild routes (`/rebuild`, `/rebuild/status`, `/rebuild/cancel`, `/rebuild/resume`) resolve correctly.

### Changed
- **Remote Repositories section** now shows a highlighted call-out explaining the "Browse files in repo…" picker and the "Selected files / folders only" mode, so the per-file selection workflow is discoverable on first visit.

## 0.3.0 — 2026-05-07

### Changed
- **Default to remote-first.** Fresh installs now ship with `sources = ['remote']`. Local filesystem sources (`base`, `addons`, `root`, `context`) remain available but are off by default. Existing installs are not migrated — saved settings are preserved.
- Settings UI now groups local filesystem sources under a collapsed **"Advanced — local filesystem (legacy)"** section.

### Added
- **Lookup-and-select tree picker** for remote repositories. Each configured repo gets a "Browse files in repo…" button that calls a new admin REST endpoint and renders the `.md` / `.txt` file tree as a checkbox list.
- New per-repo **`selection_mode`** field with three values:
  - `all` — index every Markdown / `.txt` file (default; back-compat).
  - `prefix` — restrict to the existing `path` prefix.
  - `selected` — index only the explicit list configured via the picker.
- New per-repo **`selected_paths`** and **`excluded_paths`** lists. Trailing `/` denotes a directory (recursive). `excluded_paths` is always honoured, useful with `all` mode.
- New REST endpoint `GET /wp-json/nvoos-docs/v1/remote/tree` (admin-only). Cached for 10 minutes per `owner/repo/ref/path`.
- New `NV_oOS_Docs_Hub_Remote_Repo::fetch_tree_for_admin()` public helper.
- First-run admin notice on the settings page.
- One-time, dismissible notice for installs running all three legacy sources (`base` + `addons` + `root`) with zero remote repos configured.

### Documentation
- New `docs/remote-repos.md` describing the picker workflow.

### Out of scope (follow-ups)
- GitLab / Bitbucket / arbitrary Git hosts (current SSRF allowlist is GitHub-only).
- Background pre-fetch of the tree on save.
