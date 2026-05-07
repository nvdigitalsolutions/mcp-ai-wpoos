=== NV oOS Docs Hub ===
Contributors: nvdigitalsolutions
Tags: documentation, markdown, react, docs browser, spa
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.2
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

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
