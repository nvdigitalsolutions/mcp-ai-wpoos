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
 * The demo is local-first and honest: six Markdown files are written into
 * the plugin's default local content folder
 * (wp-content/uploads/nvoos-docs-hub/content/) and published by the
 * synchronous rebuild in the blueprint's second runPHP step. No remote
 * calls, no API keys, no configuration — exactly the plugin's
 * zero-setup workflow.
 *
 * @package NV_oOS_Docs_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The demo documentation set: filename => Markdown body.
 *
 * Front matter sets the nav title and sidebar order. The bodies showcase
 * GitHub Flavored Markdown (tables, task lists, fenced code) and the
 * :::note/:::tip/:::warning/:::danger callout blocks. External https://
 * links are safe from the broken-link detector (it only validates plain
 * relative paths); no relative links are used on purpose.
 *
 * @return array<string,string> Filename => Markdown body.
 */
function nvoos_dh_demo_docs(): array {
	return array(
		'getting-started.md'  => <<<'MD'
---
title: Getting Started
order: 10
---

# Welcome to Docs Hub

You are looking at **NV oOS Docs Hub** running inside WordPress Playground —
this entire site, WordPress, the plugin, and every page of this documentation
is executing in your browser. Nothing was installed and nothing is sent to a
server.

Docs Hub turns plain Markdown files into a GitBook-style documentation site
with sidebar navigation, a content area, and a per-page table of contents.

## What you can do here

| Feature | Try it |
|---|---|
| Sidebar navigation | Use the left sidebar to move between pages |
| Full-text search | Type in the search box (or press `/`) |
| Markdown rendering | Browse the Markdown Guide page |
| Callout blocks | Check the Callout Blocks page for note, tip, warning, and danger |
| Themes | Toggle light / dark from the toolbar |

:::note
This is what a `:::note` callout looks like. The four callout types are
demonstrated on the Callout Blocks page.
:::

## How this demo was built

Six Markdown files were dropped into
`wp-content/uploads/nvoos-docs-hub/content/` and one rebuild published them.
No API keys. No remote calls. That is the entire pipeline.
MD,
		'local-first.md'     => <<<'MD'
---
title: Local-First Publishing
order: 20
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
├── getting-started.md
├── local-first.md
├── markdown-guide.md
├── callouts.md
├── search-and-themes.md
└── deploy-on-your-site.md
```

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

## Publishing the docs

Create a page and drop in the shortcode:

```text
[nvoos_docs]
```

The docs browser renders right there — sidebar, content, and table of
contents — in any theme.

- [x] Write Markdown files
- [x] Drop them into the uploads content folder
- [x] Rebuild the index
- [ ] Embed `[nvoos_docs]` on any page
MD,
		'markdown-guide.md'  => <<<'MD'
---
title: Markdown Guide
order: 30
---

# Markdown Guide

Docs Hub renders GitHub Flavored Markdown — the same dialect used by GitHub,
GitLab, and most developer docs.

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
		'callouts.md'        => <<<'MD'
---
title: Callout Blocks
order: 40
---

# Callout Blocks

Docs Hub supports four callout types using a blockquote-style syntax. Each
renders with its own icon and accent color.

## Note

:::note
Useful context the reader should see but that does not interrupt the flow.
:::

## Tip

:::tip
A recommendation that saves time — like pressing `/` to focus the search
box.
:::

## Warning

:::warning
Something that can go wrong if ignored. Back up before large migrations.
:::

## Danger

:::danger
Destructive or irreversible. The `reset --hard` command below wipes the
docs cache, so use it deliberately.
:::

## Writing callouts

Start a line with the callout type and a blank line ends the block:

```markdown
:::warning
Rebuilds overwrite the published docs with the current source files.
:::
```

Callouts render in both light and dark themes, so they stay legible either
way.
MD,
		'search-and-themes.md' => <<<'MD'
---
title: Search & Themes
order: 50
---

# Search & Themes

## Full-text search

The search box in the sidebar searches every page of your documentation.

- Press `/` to focus search from anywhere.
- Matches are ranked and grouped by page.
- Search runs over a prebuilt index served by the REST API, with a
  client-side fallback so it keeps working even on unusual hosts.

Try it: search for **callout** and open the Callout Blocks page.

## Light and dark themes

The toolbar theme toggle switches the docs browser between light and dark.
The choice follows your preference and is remembered between visits.

:::tip
Themes apply to the docs browser, not your whole site — embed anywhere
without fighting the active theme's styles.
:::

## Where the theme comes from

The docs browser ships a built-in light theme and a built-in dark theme.
Both are plain CSS that can be overridden from your theme for full control.
MD,
		'deploy-on-your-site.md' => <<<'MD'
---
title: Deploy on Your Site
order: 60
---

# Deploy on Your Site

Docs Hub is a free plugin in the WordPress directory:
https://wordpress.org/plugins/nvoos-docs-hub/

## Install

1. Install **NV oOS Docs Hub** from the WordPress plugin directory.
2. Activate it.
3. Drop Markdown files into
   `wp-content/uploads/nvoos-docs-hub/content/`.
4. Rebuild, then embed `[nvoos_docs]` on any page.

## Optional: import from GitHub

Docs Hub can import Markdown from public GitHub repositories. The import is
**opt-in and off by default** — the local Markdown workflow above works with
zero remote calls. When enabled, fetches happen server-side, only from
`api.github.com` and `raw.githubusercontent.com`, and no GitHub account is
required (an optional token only raises rate limits).

:::warning
GitHub import is a deliberate opt-in. Leaving it off keeps Docs Hub 100%
local.
:::

## WP-CLI cheat sheet

```bash
wp nvoos-docs rebuild --sync   # synchronous rebuild
wp nvoos-docs status           # index + rebuild state
wp nvoos-docs clear            # clear the docs cache
```

## Want the full NV oOS toolkit?

Docs Hub pairs with NV oOS Content Graph — the same Markdown knowledge can
power a visual knowledge graph. Find it in the WordPress plugin directory.
MD,
	);
}

/**
 * Markup for the published demo page that embeds the docs browser.
 *
 * @return string Post content.
 */
function nvoos_dh_demo_page_content(): string {
	return '<p>This page embeds the <strong>NV oOS Docs Hub</strong> browser via the <code>[nvoos_docs]</code> shortcode. The six pages in the sidebar were seeded from Markdown files in <code>wp-content/uploads/nvoos-docs-hub/content/</code> and published by a single rebuild — no API keys, no remote calls.</p>' . "\n"
		. '<p>[nvoos_docs]</p>' . "\n"
		. '<p>Running in your browser via WordPress Playground. Want this on your own site? <a href="https://wordpress.org/plugins/nvoos-docs-hub/">Get NV oOS Docs Hub</a> from the WordPress plugin directory, or open <a href="/wp-admin/admin.php?page=nvoos-docs-hub">Settings → NV oOS Docs Hub</a> to inspect the index behind this demo.</p>';
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
	$dir = NV_oOS_Docs_Hub_Plugin::uploads_docs_dir();
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

	// 3. Publish the demo page embedding the docs browser.
	if ( ! get_page_by_path( 'docs', OBJECT, 'page' ) ) {
		wp_insert_post(
			array(
				'post_title'   => 'Docs Hub Demo',
				'post_name'    => 'docs',
				'post_content' => nvoos_dh_demo_page_content(),
				'post_excerpt' => 'A live documentation site rendered from Markdown by NV oOS Docs Hub.',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			),
			true
		);
	}

	update_option( 'nvoos_dh_demo_seeded', 1 );
}
