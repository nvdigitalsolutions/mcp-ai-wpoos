# Arbitrary CPT collections (extra routes)

2026-11-09. A profile may declare any number of collections; only
`posts`, `pages` and `products` have template-owned routes. Everything else is
an **extra collection** (a WordPress CPT such as `portfolio`) and is now routed
by the scaffold.

## Before the forge can read it: `show_in_rest`

A CPT registered with `show_in_rest => false` returns **404** from
`/wp-json/wp/v2/<cpt>` even though the front-end pages render. The content
layer can only read what REST exposes:

```php
add_filter( 'register_post_type_args', function ( $args, $post_type ) {
	if ( 'portfolio' === $post_type ) { $args['show_in_rest'] = true; }
	return $args;
}, 10, 2 );
```

Detect it from the page markup: `body_class` shows `single-<cpt>`
(`single-portfolio`, `postid-297`) while `/wp-json/wp/v2/portfolio` 404s.

## Profile shape

```json
{ "slug": "portfolio", "restBase": "wp/v2/portfolio", "kind": "post",
  "basePath": "/blog/portfolio", "fields": ["id","title","slug","content","excerpt"] }
```

`basePath` (default `/<slug>`) keeps the SOURCE's URLs. WordPress served these
items at `/blog/portfolio/<slug>/`; a rebuild that moved them to `/portfolio/…`
would drop the site's existing rankings. The route directory follows it, so
`src/pages/blog/portfolio/` is generated and the import depth adjusts with it.

## What is generated

Per extra collection, a thin pair that delegates to two generic components:

| Generated | Component |
| --- | --- |
| `src/pages/<base>/index.astro` | `components/CollectionIndex.astro` |
| `src/pages/<base>/[...parts].astro` | `components/EntryView.astro` |

`EntryView` mirrors `[slug].astro`: Base + SEO + JSON-LD + `DesignRenderer`,
with `<article data-collection="…">`.

Also wired: `applyPresetToProfile()` no longer drops extras (they are content,
not commerce — only `products` is preset-controlled), and both `sitemap.xml`
and `llms.txt` emit a section for each extra collection (llms via
`extraSections` on `composeLlmsTxt` — change the velocity copy in
`examples/nvoos-astro-velocity/src/lib/llms.ts` in the same commit or the parity
gate fails).

## Trap: `getStaticPaths` is hoisted

A module-level helper is **not in scope** when Astro calls `getStaticPaths`:

```astro
const load = getCollection as unknown as (n: string) => Promise<any[]>; // ❌ undefined at build
export async function getStaticPaths() { const entries = await load('portfolio'); }
```

Build it **inside** the function. The symptom is `loadCollection is not defined`
pointed at the route-cache, not at your file.

## Verifying without a real CPT

Point a throwaway collection at an endpoint the fixture already serves, and give
it a nested `basePath` so both the routing and the import depth are exercised:

```json
{ "slug": "work", "restBase": "wp/v2/posts", "basePath": "/blog/work" }
```

Then assert `dist/blog/work/<id>/<slug>/index.html` exists, and that
`sitemap.xml` / `llms.txt` carry `/blog/work/` URLs.
