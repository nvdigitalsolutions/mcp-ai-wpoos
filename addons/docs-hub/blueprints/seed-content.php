<?php
/**
 * Docs Hub Playground demo — seed content for the NV oOS Docs Hub
 * WordPress Playground blueprint.
 *
 * This file is EMBEDDED into the blueprint's runPHP step by
 * bin/generate-docs-hub-blueprint.php, which strips the opening <?php
 * tag. It must therefore stay a self-contained snippet:
 *
 *   - No namespace, no Composer dependencies, no closing ?> tag.
 *   - WordPress functions only (it runs right after wp-load).
 *   - Idempotent: guarded by the nvoos_dh_demo_seeded option.
 *
 * The demo is local-first and honest: ten Markdown files — a real plugin
 * wiki — are written into the plugin's default local content folder
 * (wp-content/uploads/nvoos-docs-hub/content/) and published by the
 * synchronous rebuild in the blueprint's second runPHP step. No remote
 * calls, no API keys, no configuration — exactly the plugin's
 * zero-setup workflow. The /docs/ page embeds ONLY the [nvoos_docs]
 * shortcode and gets demo CSS through WordPress core Additional CSS so
 * the docs browser fills the whole page like a standalone SPA.
 *
 * @package NV_oOS_Docs_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ensure the plugin is loaded and active before the demo code runs.
 *
 * The wp.org Live Preview installs the plugin WITHOUT activating it, so
 * the seed must activate it itself — otherwise the first reference to a
 * plugin class fatals with "Class not found". The standalone demo
 * installs with activate=true, making this a no-op there. When activation
 * is unavailable, the plugin file is loaded directly as a fallback.
 *
 * @return void
 */
function nvoos_dh_demo_ensure_plugin_active(): void {
	if ( class_exists( 'NV_oOS_Docs_Hub_Plugin' ) ) {
		return;
	}
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	if ( ! is_plugin_active( 'nvoos-docs-hub/nvoos-docs-hub.php' ) ) {
		$activated = activate_plugin( 'nvoos-docs-hub/nvoos-docs-hub.php' );
		if ( is_wp_error( $activated ) && ! class_exists( 'NV_oOS_Docs_Hub_Plugin' ) ) {
			$plugin_file = WP_PLUGIN_DIR . '/nvoos-docs-hub/nvoos-docs-hub.php';
			if ( file_exists( $plugin_file ) ) {
				include_once $plugin_file;
			}
		}
	}
}

/**
 * The demo documentation set: filename => Markdown body.
 *
 * A ten-page plugin wiki. Front matter sets the nav title and sidebar
 * order. README.md derives the `readme` slug — the plugin's default home
 * page — so the docs browser opens on "Getting Started" out of the box.
 * The bodies showcase GitHub Flavored Markdown (tables, task lists,
 * fenced code) and the :::note/:::tip/:::warning/:::danger callout
 * blocks. External https:// links are safe from the broken-link detector
 * (it only validates plain relative paths); no relative links are used
 * on purpose.
 *
 * @return array<string,string> Filename => Markdown body.
 */
function nvoos_dh_demo_docs(): array {
	return array(
		'README.md'           => <<<'MD'
---
title: Getting Started
order: 10
---

# Welcome to Docs Hub

You are looking at **NV oOS Docs Hub** running inside WordPress Playground —
this entire site, WordPress, the plugin, and every page of this wiki is
executing in your browser. Nothing was installed and nothing is sent to a
server.

Docs Hub turns plain Markdown files into a GitBook-style documentation
site with sidebar navigation, a content area, and a per-page table of
contents — embedded anywhere on your WordPress site.

## Take the tour

| Feature | Try it |
|---|---|
| Sidebar navigation | Use the left sidebar to move between pages |
| Full-text search | Click the search box (or press ⌘K / Ctrl+K) |
| Markdown rendering | Browse the Markdown Guide page |
| Callout blocks | Check the Callout Blocks page for note, tip, warning, and danger |
| Themes | Toggle light / dark from the toolbar |

:::note
This is what a `:::note` callout looks like. The four callout types are
demonstrated on the Callout Blocks page.
:::

## How this wiki was built

Ten Markdown files were dropped into
`wp-content/uploads/nvoos-docs-hub/content/` and one rebuild published
them. No API keys. No remote calls. That is the entire pipeline.
MD,
		'installation.md'     => <<<'MD'
---
title: Installation
order: 20
---

# Installation

Docs Hub is a free plugin in the WordPress directory.

## Install from the directory

1. In wp-admin, open **Plugins → Add New Plugin**.
2. Search for **NV oOS Docs Hub**.
3. Install and activate.

That is it. There is no setup wizard, no account, and no configuration
step — the plugin works the moment it is active.

:::tip
The plugin also ships on
https://wordpress.org/plugins/nvoos-docs-hub/ where you can download the
ZIP and upload it via **Plugins → Add New Plugin → Upload Plugin**.
:::

## What activation does

Activation creates the local content folder and schedules a nightly
rebuild so your documentation stays fresh without any manual step:

```text
wp-content/uploads/nvoos-docs-hub/content/
```

Drop Markdown files there, rebuild once, and you are publishing.
MD,
		'local-first.md'      => <<<'MD'
---
title: Local-First Publishing
order: 30
---

# Local-First Publishing

Docs Hub is local-first by default. There is no cloud, no account, and no
external service between your Markdown and your visitors.

## The workflow

1. Write documentation in Markdown (`.md`) or plain text (`.txt`).
2. Drop the files into your WordPress uploads folder.
3. Rebuild the index.
4. Embed the docs anywhere with a shortcode or block.

```text
wp-content/uploads/nvoos-docs-hub/content/
├── README.md
├── installation.md
├── local-first.md
├── shortcode-block.md
├── markdown-guide.md
├── callouts.md
├── search-and-themes.md
├── github-import.md
├── wp-cli.md
└── troubleshooting.md
```

Subfolders are supported — the sidebar mirrors the folder structure.

## Rebuilding

From WP-CLI:

```bash
wp nvoos-docs rebuild --sync
```

From the admin, open **Settings → NV oOS Docs Hub** and press
**Rebuild Documentation Index**. Large doc sets rebuild in chunks with a
live progress panel, so a rebuild never blocks the site.

:::tip
Rebuilds happen on your server. Your documentation content never leaves
your WordPress install unless you opt into an external source.
:::

- [x] Write Markdown files
- [x] Drop them into the uploads content folder
- [x] Rebuild the index
- [ ] Embed `[nvoos_docs]` on any page
MD,
		'shortcode-block.md'  => <<<'MD'
---
title: Shortcode & Block Embeds
order: 40
---

# Shortcode & Block Embeds

The docs browser embeds anywhere: a page, a post, a widget area — via a
shortcode or a Gutenberg block.

## The shortcode

```text
[nvoos_docs]
```

Every option has a default, so the bare shortcode is all most sites need:

| Attribute | Default | What it does |
|---|---|---|
| `section` | `all` | Which docs to show (`all`, or a source slug) |
| `theme` | `auto` | `auto` follows the visitor's preference; `light` / `dark` force one |
| `search` | `true` | Show the full-text search box |
| `sidebar` | `true` | Show the navigation sidebar |
| `home` | `readme` | Slug of the page shown by default |

Example:

```text
[nvoos_docs theme="dark" home="getting-started"]
```

## The block

In the block editor, add the **NV oOS Docs Hub** block and pick the same
options from the sidebar panel. The block and the shortcode render the
identical browser.

## Multiple embeds

You can place several docs browsers on the same site — each one is an
independent instance with its own state.
MD,
		'markdown-guide.md'   => <<<'MD'
---
title: Markdown Guide
order: 50
---

# Markdown Guide

Docs Hub renders GitHub Flavored Markdown — the same dialect used by
GitHub, GitLab, and most developer docs.

## Text formatting

You can use **bold**, *italic*, `inline code`, and ~~strikethrough~~.

> Blockquotes render with a left border, exactly as you would expect.

## Lists

1. Numbered lists
2. Work as you expect
   - with nested
   - unordered items

## Tables

| Release | Highlight |
|---|---|
| 0.5.1 | Uploads folder renamed to the plugin slug |
| 0.4.7 | Cron scheduled on init; readme-only "Tested up to" |
| 0.4.4 | Staging-isolated transients + symlink-safe cleanup |

## Task lists

- [x] Ship a Markdown renderer
- [x] Support GFM tables
- [x] Support task lists
- [ ] Finish the roadmap

## Code blocks

Fenced code blocks get syntax highlighting. A PHP example:

```php
function greet( string $name ): string {
	return sprintf( 'Hello, %s!', $name );
}

echo greet( 'Docs Hub' );
```

And a JSON example:

```json
{
  "plugin": "nvoos-docs-hub",
  "localFirst": true,
  "apiKeys": 0
}
```
MD,
		'callouts.md'         => <<<'MD'
---
title: Callout Blocks
order: 60
---

# Callout Blocks

Docs Hub supports four callout types using a blockquote-style syntax. Each
renders with its own icon and accent color.

## Note

:::note
Useful context the reader should see but that does not interrupt the
flow.
:::

## Tip

:::tip
A recommendation that saves time — like pressing ⌘K to focus the search
box.
:::

## Warning

:::warning
Something that can go wrong if ignored. Back up before large migrations.
:::

## Danger

:::danger
Destructive or irreversible. The `reset --hard` command wipes the docs
cache, so use it deliberately.
:::

## Writing callouts

Start a line with the callout type and a blank line ends the block:

```markdown
:::warning
Rebuilds overwrite the published docs with the current source files.
:::
```

Callouts render in both light and dark themes, so they stay legible
either way.
MD,
		'search-and-themes.md' => <<<'MD'
---
title: Search & Themes
order: 70
---

# Search & Themes

## Full-text search

The search box in the header searches every page of your documentation.

- Press ⌘K / Ctrl+K to focus search from anywhere.
- Matches are ranked and grouped by page.
- Search runs over a prebuilt index served by the REST API, with a
  client-side fallback so it keeps working even on unusual hosts.

Try it: search for **callout** and open the Callout Blocks page.

## Light, dark, and auto themes

The toolbar theme toggle switches the docs browser between light and dark,
or follows your visitor's preference in `auto` mode. The choice is
remembered between visits.

:::tip
Themes apply to the docs browser, not your whole site — embed anywhere
without fighting the active theme's styles.
:::

## Where the theme comes from

The docs browser ships a built-in light theme and a built-in dark theme.
Both are plain CSS that can be overridden from your theme for full
control.
MD,
		'github-import.md'    => <<<'MD'
---
title: GitHub Import (Opt-In)
order: 80
---

# GitHub Import (Opt-In)

Docs Hub can import Markdown from public GitHub repositories. The import
is **opt-in and off by default** — the local Markdown workflow works with
zero remote calls, and this demo never enables it.

## What it does

When you add a public repository under **Settings → NV oOS Docs Hub →
Remote repositories**, Docs Hub fetches the Markdown server-side and
indexes it alongside your local files.

- Fetches happen only from `api.github.com` and `raw.githubusercontent.com`.
- No GitHub account is required.
- An optional personal access token only raises the API rate limits.

:::warning
GitHub import is a deliberate opt-in. Leaving it off keeps Docs Hub 100%
local.
:::

## Local stays first

Remote sources are additive — your local uploads folder remains the
primary source, and anything you drop there is published on the next
rebuild with or without a connection.
MD,
		'wp-cli.md'           => <<<'MD'
---
title: WP-CLI Reference
order: 90
---

# WP-CLI Reference

Everything the admin UI can do is scriptable via the `nvoos-docs` command.

## Rebuild

```bash
wp nvoos-docs sync             # full synchronous rebuild
wp nvoos-docs sync --strict    # exit non-zero when broken links remain
wp nvoos-docs rebuild --sync   # same as sync
wp nvoos-docs rebuild --async  # chunked rebuild (default for rebuild)
wp nvoos-docs rebuild --resume # resume an interrupted chunked rebuild
```

## Reset & clear

```bash
wp nvoos-docs reset            # clear a stuck rebuild state
wp nvoos-docs reset --hard     # also wipe the live docs cache
wp nvoos-docs clear            # clear the docs cache
```

## Inspect

```bash
wp nvoos-docs status           # index + rebuild state
```

## Automate it

Pair `wp nvoos-docs rebuild --sync` with your deployment process (rsync,
git push hooks, CI) and the docs update with the same commit that changes
the Markdown.
MD,
		'troubleshooting.md'  => <<<'MD'
---
title: Troubleshooting & FAQ
order: 100
---

# Troubleshooting & FAQ

## My new Markdown file is not showing

Run a rebuild — the index only changes when a rebuild runs.

```bash
wp nvoos-docs rebuild --sync
```

A nightly rebuild also runs automatically; you may just be looking before
it fired.

## The rebuild seems stuck

A chunked rebuild leaves a state record behind when it is interrupted.

```bash
wp nvoos-docs reset
wp nvoos-docs rebuild --sync
```

## Links are broken

Docs Hub detects broken internal links and lists them with fix
suggestions. Correct the relative target in the Markdown and rebuild.

:::danger
`wp nvoos-docs reset --hard` wipes the live docs cache. The next rebuild
republishes it — but run it deliberately.
:::

## Does Docs Hub phone home?

No. Local publishing makes zero remote calls. Only the opt-in GitHub
import fetches, server-side, from `api.github.com` and
`raw.githubusercontent.com` — see the GitHub Import page.
MD,
	);
}

/**
 * Markup for the published demo page that embeds the docs browser.
 *
 * The app is the ONLY thing on the page — the seed also applies the
 * no-title page template (when the theme ships one) and demo CSS via
 * WordPress core Additional CSS so the browser fills the viewport like a
 * standalone SPA.
 *
 * @return string Post content.
 */
function nvoos_dh_demo_page_content(): string {
	return '[nvoos_docs]';
}

/**
 * Demo CSS that makes the docs browser the entire page.
 *
 * Written to WordPress core Additional CSS (wp_update_custom_css_post),
 * scoped to the demo page via its body.page-id-{ID} class. The
 * full-bleed technique mirrors the theme's own alignfull mechanism
 * (negating the global-padding custom properties), so it works with
 * block themes without fragile 100vw tricks.
 *
 * @param int $page_id The published demo page ID.
 * @return string CSS.
 */
function nvoos_dh_demo_page_css( int $page_id ): string {
	return '/* NV oOS Docs Hub — Playground demo: the docs browser is the whole page. */' . "\n"
		. 'body.page-id-' . $page_id . ' .wp-site-blocks > header,' . "\n"
		. 'body.page-id-' . $page_id . ' .wp-site-blocks > footer,' . "\n"
		. 'body.page-id-' . $page_id . ' h1.wp-block-post-title { display: none !important; }' . "\n"
		. 'body.page-id-' . $page_id . ' .wp-site-blocks { padding: 0 !important; }' . "\n"
		. 'body.page-id-' . $page_id . ' main#wp--skip-link--target { margin-top: 0 !important; }' . "\n"
		. 'body.page-id-' . $page_id . ' .entry-content.has-global-padding { padding-left: 0 !important; padding-right: 0 !important; }' . "\n"
		. 'body.page-id-' . $page_id . ' .wp-block-post-content.is-layout-constrained { max-width: none !important; }' . "\n"
		. 'body.page-id-' . $page_id . ' .nvoos-docs-hub-root {' . "\n"
		. "\t" . 'max-width: none !important;' . "\n"
		. "\t" . 'margin-left: calc(var(--wp--style--root--padding-left, 0px) * -1) !important;' . "\n"
		. "\t" . 'margin-right: calc(var(--wp--style--root--padding-right, 0px) * -1) !important;' . "\n"
		. "\t" . 'min-height: calc(100vh - 32px);' . "\n"
		. '}' . "\n"
		. 'body.page-id-' . $page_id . ':not(.admin-bar) .nvoos-docs-hub-root { min-height: 100vh; }' . "\n";
}

/**
 * Seed the Docs Hub Playground demo. Idempotent.
 *
 * @return void
 */
function nvoos_dh_demo_seed(): void {
	if ( get_option( 'nvoos_dh_demo_seeded' ) ) {
		return;
	}

	nvoos_dh_demo_ensure_plugin_active();

	// 1. Pretty permalinks so the /docs/ landing page resolves.
	global $wp_rewrite;
	if ( '/%postname%/' !== get_option( 'permalink_structure' ) ) {
		if ( $wp_rewrite instanceof WP_Rewrite ) {
			$wp_rewrite->set_permalink_structure( '/%postname%/' );
		} else {
			update_option( 'permalink_structure', '/%postname%/' );
		}
		flush_rewrite_rules( false );
	}

	// 2. Write the demo docs into the plugin's default local content folder.
	if ( class_exists( 'NV_oOS_Docs_Hub_Plugin' ) ) {
		$dir = NV_oOS_Docs_Hub_Plugin::uploads_docs_dir();
	} else {
		$info = wp_upload_dir();
		$dir  = ( isset( $info['basedir'] ) ? (string) $info['basedir'] : '' ) . '/nvoos-docs-hub/content';
	}
	if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
		return;
	}
	foreach ( nvoos_dh_demo_docs() as $file => $markdown ) {
		$path = $dir . '/' . $file;
		if ( ! file_exists( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- seed-only write inside wp-content/uploads.
			file_put_contents( $path, $markdown );
		}
	}

	// 3. Publish (or converge) the demo page embedding the docs browser.
	$page = get_page_by_path( 'docs', OBJECT, 'page' );
	if ( $page instanceof WP_Post ) {
		$page_id = (int) $page->ID;
		wp_update_post(
			array(
				'ID'           => $page_id,
				'post_title'   => 'Docs Hub Demo',
				'post_content' => nvoos_dh_demo_page_content(),
				'post_status'  => 'publish',
			),
			true
		);
	} else {
		$page_id = (int) wp_insert_post(
			array(
				'post_title'   => 'Docs Hub Demo',
				'post_name'    => 'docs',
				'post_content' => nvoos_dh_demo_page_content(),
				'post_excerpt' => 'A live documentation wiki rendered from Markdown by NV oOS Docs Hub.',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			),
			true
		);
	}
	if ( $page_id < 1 ) {
		return;
	}

	// 4. No-title template when the active theme ships one (Twenty
	//    Twenty-Five+: page-no-title.html) — the CSS below also hides the
	//    title, so this is a progressive enhancement.
	$templates = glob( get_stylesheet_directory() . '/templates/*.html' );
	$template  = '';
	if ( $templates && in_array( 'page-no-title.html', array_map( 'basename', $templates ), true ) ) {
		$template = 'page-no-title.html';
	}
	update_post_meta( $page_id, '_wp_page_template', $template );

	// 5. Full-page demo CSS via WordPress core Additional CSS.
	$css     = nvoos_dh_demo_page_css( $page_id );
	$current = function_exists( 'wp_get_custom_css' ) ? (string) wp_get_custom_css() : '';
	if ( false === strpos( $current, 'Playground demo' ) ) {
		if ( function_exists( 'wp_update_custom_css_post' ) ) {
			wp_update_custom_css_post( $css );
		}
	}

	update_option( 'nvoos_dh_demo_seeded', 1 );
}
