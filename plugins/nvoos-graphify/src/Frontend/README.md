# Frontend

## Purpose

Delivers the public-facing surface of the knowledge graph — interactive Cytoscape.js viewer via `[nvoos_graph]` shortcode, Gutenberg block, Schema.org structured data injection, and related-content sidebar.

## Tier

| | |
|---|---|
| **Distribution** | Core plugin |
| **PHP target** | 8.1+ |
| **License** | GPL-3.0-or-later |
| **Loaded by** | `NvoosGraphify\Plugin::registerFrontend()` |
| **Optional dependencies** | None (block requires WordPress 6.5+ for Block API) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosGraphify\Frontend\Shortcode` | `Shortcode.php` | `Plugin::registerFrontend()` |
| `NvoosGraphify\Frontend\Block` | `Block.php` | `Plugin::registerFrontend()` |
| `NvoosGraphify\Frontend\SchemaOrg` | `SchemaOrg.php` | `Plugin::registerFrontend()` |
| `NvoosGraphify\Frontend\RelatedContent` | `RelatedContent.php` | `Plugin::registerFrontend()` |

## Inputs / Outputs / Neighbors

- **Reads from:** `nvoos_graphify_settings` option, custom graph DB tables (nodes, edges), WordPress post/content data
- **Writes to:** Rendered HTML (shortcode/block output), JSON-LD in `<head>` (SchemaOrg), post content filter (RelatedContent)
- **Upstream callers:** `NvoosGraphify\Plugin` (composition root), WordPress core (`do_shortcode`, block rendering, `wp_head`, `the_content`)
- **Downstream collaborators:** `src/Graph/Db` (graph queries), `src/Settings` (config)
- **Events fired:** None
- **Events listened to:** `init` (shortcode registration), `wp_enqueue_scripts`, `wp_head` (SchemaOrg), `the_content` (related content)

## Conventions

- The shortcode `[nvoos_graph]` supports `mode`, `community_id`, `post_id`, `height`, and `max_nodes` attributes.
- Cytoscape.js and layout extensions are vendored under `assets/vendor/`.
- Frontend JS is enqueued with `wp_add_inline_script` to pass REST URL and nonce.
- SchemaOrg output is valid JSON-LD and only injected when `schema_injection` setting is enabled.

## Tests

```bash
vendor/bin/phpunit --filter '/Frontend|Shortcode|Block|SchemaOrg|RelatedContent/'
```

## Also Load

- [`../../../.context/conventions.md`](../../../.context/conventions.md) — naming + style
- [`../../../.context/security-checklist.md`](../../../.context/security-checklist.md) — escaping

## See Also

- Parent: [`../`](../) — src root
- Collaborators: [`../Graph/Db.php`](../Graph/Db.php), [`../Rest/Controller.php`](../Rest/Controller.php)
