=== NV oOS Docs Hub ===
Contributors: nvdigitalsolutions
Tags: documentation, markdown, github
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.4.3
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

A self-contained React documentation browser for WordPress. Index Markdown from any public GitHub repository — no other plugin required.

== Description ==

NV oOS Docs Hub discovers, indexes, and renders Markdown documentation in a
GitBook-style single-page app: sidebar navigation, content area, and a
per-page table of contents. Embed it anywhere on your WordPress site via a
shortcode or Gutenberg block.

Out of the box it indexes documentation from **any public GitHub repository**
you configure in the settings — no other plugin required. Configure a repo,
pick the files or folders to include, and rebuild the index.

**Key features:**

* Index Markdown from public GitHub repositories with per-repo file/folder selection
* Full-text search via the REST API with FlexSearch client-side fallback
* GitHub Flavored Markdown: tables, task lists, fenced code blocks
* Custom `:::note`, `:::tip`, `:::warning`, `:::danger` callout blocks
* Syntax-highlighted code blocks, last-modified dates, and "Edit on GitHub" links
* Broken-link detection with one-click fix suggestions (local sources)
* Light and dark themes with CSS custom properties
* Two-layer cache (filesystem + WordPress transients)
* Async chunked rebuilds with a live progress panel — no more long single-request builds
* WP-CLI support: `wp nvoos-docs rebuild / clear / status`
* Nightly cron rebuild; also triggered on plugin activate/deactivate
* Indexed pages included in the WordPress sitemap
* `[nvoos_docs]` shortcode and `nvoos/docs-hub` Gutenberg block

**Optional NV oOS integration.** If the NV oOS base plugin
(https://github.com/nvdigitalsolutions/mcp-ai-wpoos) is active, the addon
additionally auto-discovers the `docs/` folders of the base plugin and every
installed addon, and surfaces rebuild progress in the base plugin's cron-status
drawer.

== Installation ==

1. Upload the `nvoos-docs-hub` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** admin screen.
3. Go to **Settings → NV oOS Docs Hub**, add a public GitHub repository, and click **Rebuild Index**.
4. Add `[nvoos_docs]` to any page to display the documentation browser.

To also index local NV oOS documentation, activate the NV oOS base plugin and
enable the `base`, `addons`, or `root` sources under **Settings → NV oOS Docs
Hub → Advanced — local filesystem (legacy)**.

== Shortcode ==

    [nvoos_docs section="base" theme="light" search="1" sidebar="1"]

Attributes:

* `section` – Filter to one source: `base`, `addons`, or an addon slug.
* `theme` – `light`, `dark`, or `auto` (follows OS preference).
* `search` – Set to `0` to disable the search box.
* `sidebar` – Set to `0` to hide the left sidebar.
* `home` – Slug of the default landing page.

== WP-CLI ==

    wp nvoos-docs rebuild            # Chunked rebuild (default, runs across WP-Cron ticks)
    wp nvoos-docs rebuild --sync     # Inline rebuild in a single request (legacy)
    wp nvoos-docs sync               # Back-compat alias for --sync
    wp nvoos-docs rebuild --resume   # Resume a stalled or failed rebuild
    wp nvoos-docs rebuild --cancel   # Cancel an in-flight rebuild
    wp nvoos-docs clear              # Clear all cached data
    wp nvoos-docs status             # Show index statistics and rebuild phase

== Frequently Asked Questions ==

= Does this plugin require the NV oOS base plugin? =

No. Out of the box it indexes documentation from any public GitHub repository
you configure in the settings. If the NV oOS base plugin is active, additional
local sources (the base plugin's `docs/` folder and installed addons) are
discovered automatically.

= Can I index docs from a GitHub repository? =

Yes. Add a repository under **Settings → NV oOS Docs Hub → Remote Repositories**,
then either index the whole repository, restrict it to a folder prefix, or use
the "Browse files in repo…" picker to select individual files and folders.

= Which file types are indexed? =

Only `.md` and `.txt` files up to 2 MB in size (local sources) or 4 MB (remote
sources).

= How do I exclude specific files? =

Use the `nvoos_docs_hub_excluded_globs` filter to return an array of glob
patterns (relative to each source root) that should be excluded.

= Can I restrict public access to the docs? =

Yes. Use the `nvoos_docs_hub_can_read_section` filter. Return a `WP_Error` or
`false` to block unauthenticated REST access. You can also restrict at the
shortcode level with the `nvoos_docs_hub_can_render` filter. Alternatively,
turn off **Allow Public (Guest) Access** in the settings to require a
WordPress login for all docs.

= How is the cache invalidated? =

Automatically when any plugin is activated or deactivated, after NV oOS plugin
updates, on a nightly scheduled cron job, and via a version-mismatch guard that
rebuilds when the installed plugin versions no longer match the cached index.
You can also rebuild manually from the settings page, via WP-CLI, or via the
REST API (requires `manage_options`).

== Screenshots ==

1. Settings — documentation index status and rebuild panel.
2. Settings — remote repositories with the GitHub file/folder tree picker.
3. Frontend `[nvoos_docs]` embed — sidebar, content, and table of contents.
4. Frontend `[nvoos_docs]` embed — full-text search.

== External Services ==

When you configure a remote documentation repository, this plugin contacts
GitHub's public API — **server-side only, over HTTPS**, and only the hosts
listed below. Every request is restricted to these hosts (all others are
rejected, including private and reserved IP addresses), carries a bounded
timeout and response-size cap, and only happens after an administrator
configures a repository and triggers a rebuild (or the nightly cron runs).
No requests are made if no remote repository is configured, and the plugin
does not send any personal data to these services — it only fetches the
public repository content exactly as GitHub serves it.

* `api.github.com` — repository tree metadata used by the file/folder
  picker and the indexer.
  Terms: https://docs.github.com/en/site-policy/github-terms/github-terms-of-service
  Privacy: https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement
* `raw.githubusercontent.com` — raw Markdown file content fetched during
  index rebuilds.
  Terms: https://docs.github.com/en/site-policy/github-terms/github-terms-of-service
  Privacy: https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement

== Changelog ==

= 0.4.3 =
* Added: WordPress.org listing screenshots and a Playwright capture script (`bin/capture-nvoos-docs-hub-screenshots.js`).
* Added: WordPress.org submission preparation — External Services section in the readme, a translation template (languages/nvoos-docs-hub.pot), and a WordPress.org Plugin Check gate in CI.
* Changed: settings-page scripts moved to a static asset (no inline <script> blocks), text-domain consistency fix, dev files excluded from distribution ZIPs.
* Changed: readme tags trimmed to directory-standard tags.

= 0.4.2 =
* Fixed: clicking internal links on local pages left the SPA — links now resolve to `#/slug` hash routes with heading anchors preserved.
* Fixed: "On this page" TOC anchors now match rendered headings exactly (github-slugger parity verified across ~63,000 headings).
* Fixed: "Accept fix" suggestions now resolve targets relative to the source page and validate the resolved destination against plugin/content roots.
* Fixed: sync rebuilds (`wp nvoos-docs sync`, `POST /rebuild?sync=1`) now report an error when the atomic staging-cache swap fails.
* Fixed: skipped broken-link rows in the settings table now show the server-provided reason; remote-sourced rows explain they must be fixed upstream.

= 0.4.1 =
* Fixed: index went stale after in-place NV oOS plugin updates — cache now invalidates on the new `wp_mcp_ai_plugin_updated` action plus an `admin_init` version-mismatch guard.
* Fixed: broken-link detection on remote-only indexes no longer flags repo-relative links; fix suggestions are case-insensitive with clamped confidence.

= 0.4.0 =
* Fixed: "Unexpected token '<'" errors when page-caching plugins serve HTML for REST requests — clients now send `Accept: application/json` and validate the response content type.
* Fixed: non-404 page-load failures no longer masquerade as "404 Page Not Found"; they show a diagnostic error block.

= 0.3.9 =
* Fixed: fatal error during WordPress sitemap provider registration (now hooked into `wp_sitemaps_init`).
* Added: `fnmatch()` polyfill for Windows PHP environments.

= 0.3.8 =
* Added: syntax highlighting via `rehype-highlight` with a scoped light/dark theme.
* Added: last-modified footer date and "Edit on GitHub" links.
* Added: indexed docs pages included in the WordPress sitemap.
* Added: admin repo-picker script extracted to a static, CSP-friendly asset.

= 0.3.7 =
* Added: accessibility improvements — ARIA region for the SPA, skip-link to main content, `prefers-reduced-motion` support, RTL layout mirroring.

= 0.3.6 =
* Fixed: fatal error on the settings page with malformed `remote_repos` rows.
* Added: hardened SSRF protection for remote GitHub fetches (IPv6-aware resolution, per-record validation).
* Added: force-refresh clears the per-file remote content cache.

= 0.3.2 =
* Fixed: PHPCS compliance pass (96 errors / 38 warnings resolved).

= 0.3.1 =
* Fixed: admin rebuild panel 404 (wrong REST namespace).
* Changed: remote-repositories settings section now explains the tree-picker workflow.

= 0.3.0 =
* Changed: fresh installs default to remote-first sources (`remote` only); local filesystem sources moved under "Advanced — local filesystem (legacy)".
* Added: lookup-and-select tree picker for remote repositories with per-repo selection modes (`all`, `prefix`, `selected`).
* Added: admin REST endpoint `GET /remote/tree`.

= 0.2.0 =
* Chunked, async-by-default rebuild pipeline (`scan` → `pages` → `links` → `search` → `finalize`) driven by self-rescheduling WP-Cron ticks. Per-tick wall-clock + memory budgets prevent the historical "single 60 s+ PHP request crashes on a large repo" failure mode.
* Atomic staging-cache swap. A failed rebuild leaves the previous index intact instead of wiping the docs and leaving the SPA blank.
* Built-in vendor / dependency exclusion (`vendor/`, `node_modules/`, `bower_components/`, `.git/`, `.github/`, `dist/`, `build/`, `coverage/`, `tests/fixtures/`) applied during recursive scan to prune subdirectories before recursion. New `nvoos_docs_hub_force_include_globs` filter allow-lists specific vendored docs.
* Plugin-root `README.md` / `CHANGELOG.md` / `CONTRIBUTING.md` / `SECURITY.md` are now indexed unconditionally when the `root` source is enabled (no longer gated behind `WP_DEBUG`). `.context/*.md` remains gated behind `context_enabled` + `manage_options`.
* New source-priority (`root` > `base` > `addons` > `context` > `remote`) ensures the plugin-root README wins the canonical `readme` slug; addon READMEs receive suffixed slugs.
* New REST endpoints: `GET /rebuild/status`, `POST /rebuild/cancel`, `POST /rebuild/resume`. `POST /rebuild` returns HTTP 202 by default; pass `?sync=1` for the legacy inline behaviour.
* New WP-CLI command: `wp nvoos-docs rebuild [--async|--sync|--resume|--cancel]`.
* Admin UI: live progress panel polls `/rebuild/status` with start / resume / cancel buttons.
* New filters: `nvoos_docs_hub_force_include_globs`, `nvoos_docs_hub_pruned_dir_names`, `nvoos_docs_hub_source_priority`, `nvoos_docs_hub_rebuild_chunk_size`, `nvoos_docs_hub_rebuild_tick_budget`, `nvoos_docs_hub_max_files_total`. New action: `nvoos_docs_hub_rebuild_phase`.
* New setting: "Include per-addon README/CHANGELOG" (default on).
* Performance: `build_search_index()` now reuses cached page payloads instead of re-reading every file.

= 0.1.0 =
* Initial release.

== Upgrade Notice ==

= 0.4.3 =
WordPress.org submission hardening — no functional changes for existing sites. Recommended for all users.

= 0.4.2 =
Local-page links and TOC anchors now navigate inside the SPA, and broken-link fix suggestions resolve relative to the source page. Recommended for all users.

= 0.4.1 =
Cache invalidation after plugin updates is fixed; the index no longer goes stale. Recommended for all users.

= 0.2.0 =
Asynchronous chunked rebuilds + vendor exclusion. The "Rebuild Documentation Index" button is now non-blocking; long rebuilds run across WP-Cron ticks. Default exclusions for `vendor/` and `node_modules/` mean third-party READMEs no longer pollute your docs.
