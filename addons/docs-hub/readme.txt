=== NV oOS Docs Hub ===
Contributors: nvdigitalsolutions
Tags: documentation, markdown, react, docs browser, spa
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

A React-based documentation browser SPA for the NV oOS plugin ecosystem.

== Description ==

NV oOS Docs Hub discovers, indexes, and renders Markdown documentation from the
NV oOS base plugin and every installed addon. It presents the docs in a
GitBook-style three-column interface (sidebar navigation, content area, right
table-of-contents) embedded anywhere on your WordPress site via a shortcode or
Gutenberg block.

**Key features:**

* Auto-discovers `docs/` folders in the base plugin and all addons
* Full-text search via the REST API with FlexSearch client-side fallback
* GitHub Flavored Markdown: tables, task lists, fenced code blocks
* Custom `:::note`, `:::tip`, `:::warning`, `:::danger` callout blocks
* Light and dark themes with CSS custom properties
* Two-layer cache (filesystem + WordPress transients)
* WP-CLI support: `wp nvoos-docs sync / clear / status`
* Nightly cron rebuild; also triggered on plugin activate/deactivate

== Installation ==

1. Ensure the NV oOS base plugin is active.
2. Upload the `nvoos-docs-hub` folder to `/wp-content/plugins/`.
3. Activate the plugin through the **Plugins** admin screen.
4. Go to **Settings → NV oOS Docs Hub** and click **Rebuild Index**.
5. Add `[nvoos_docs]` to any page to display the documentation browser.

== Shortcode ==

    [nvoos_docs section="base" theme="light" search="1" sidebar="1"]

Attributes:

* `section` – Filter to one source: `base`, `addons`, or an addon slug.
* `theme` – `light`, `dark`, or `auto` (follows OS preference).
* `search` – Set to `0` to disable the search box.
* `sidebar` – Set to `0` to hide the left sidebar.
* `home` – Slug of the default landing page.

== WP-CLI ==

    wp nvoos-docs sync      # Rebuild the full index
    wp nvoos-docs clear     # Clear all cached data
    wp nvoos-docs status    # Show index statistics

== Frequently Asked Questions ==

= Which file types are indexed? =

Only `.md` and `.txt` files up to 2 MB in size.

= How do I exclude specific files? =

Use the `nvoos_docs_hub_excluded_globs` filter to return an array of glob
patterns (relative to each source root) that should be excluded.

= Can I restrict public access to the docs? =

Yes. Use the `nvoos_docs_hub_can_read_section` filter. Return a `WP_Error` or
`false` to block unauthenticated REST access. You can also restrict at the
shortcode level with the `nvoos_docs_hub_can_render` filter.

= How is the cache invalidated? =

Automatically when any plugin is activated or deactivated, and nightly via
a scheduled cron job. You can also rebuild manually from the settings page,
via WP-CLI, or via the REST API (requires `manage_options`).

== Changelog ==

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

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 0.2.0 =
Asynchronous chunked rebuilds + vendor exclusion. The "Rebuild Documentation Index" button is now non-blocking; long rebuilds run across WP-Cron ticks. Default exclusions for `vendor/` and `node_modules/` mean third-party READMEs no longer pollute your docs.

= 1.0.0 =
Initial release.
