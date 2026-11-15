# Proposal 131 — SEO head fidelity (Rank Math / Yoast)

> Owner question 2026-11-08: *"is the SEO section forged as well
> (rankmath/yoast)?"* — the answer was "the metadata is, the plugin's head is
> not". This proposal closes that gap: the forge already reads the SEO
> **title/description** and rebuilds JSON-LD; it drops the robots directive,
> canonical, Open Graph/Twitter tags, the site-level entity graph, and every
> product's SEO meta. It also actively **double-brands** real source titles.

**Status:** shipped 2026-11-08 (all five phases). Implementation record:
`131-forge-seo-head-fidelity-implementation-plan.md`.
**Related:** 096 (forge core), 106 (§1.2 SEO-meta passthrough — the parent
discipline), 107 (storefront content surface), 130 (ownership verification —
the other half of "Google Search").

---

## 0. Summary

| Surface | Before 131 | After 131 |
|---|---|---|
| Rank Math title/description | ✅ posts + pages | ✅ + **products** (sidecar) |
| Yoast title/description | ✅ posts + pages | ✅ + **products** (sidecar) |
| Document title composition | ❌ double-brands `${seo} — ${site}` | ✅ `composeDocumentTitle` |
| Robots directive | ❌ hardcoded `index, follow` | ✅ per page (noindex survives) |
| Canonical (source) | ❌ ignored | ✅ same-origin only, else ours (source canonical points at WP) |
| Open Graph | ⚠️ `og:image` only | ✅ title/description/type/url/site_name/image |
| Twitter card | ❌ | ✅ card/title/description/image |
| JSON-LD | ⚠️ one node per page | ✅ `@graph`: Organization + WebSite (+SearchAction) + BreadcrumbList + page |
| Sitemap | ⚠️ `<loc>` only | ✅ `lastmod`, noindex exclusion, image extension |
| **Verification gate** | ❌ | ✅ `verify --seo [--strict-seo]` |

---

## 1. Research (2026-11-08)

### 1.1 What the two plugins actually expose

**Rank Math** (flat post meta, exposed over REST when the site registers
them; the forge already requested `rank_math_title`/`rank_math_description`):

| Field | Meaning |
|---|---|
| `rank_math_title`, `rank_math_description` | final SEO title / description |
| `rank_math_canonical_url` | custom canonical |
| `rank_math_robots` | **array** of directives (`['noindex','follow']`) |
| `rank_math_facebook_title` / `_description` / `_image` | OG overrides |
| `rank_math_twitter_title` / `_description` / `_image` | Twitter overrides |
| `rank_math_primary_category`, `rank_math_focus_keyword` | editor-only |

Sources: Rank Math's documented meta keys (wpml-config lists the exact
field names), the community REST managers that register the same keys, and
the Gato GraphQL Rank Math guide.

**Yoast** — `yoast_head_json` is the structured head as an object:

| Key | Shape |
|---|---|
| `title`, `description` | strings (final, **usually include the site name**) |
| `canonical` | string |
| `robots` | **object**: `{ index: 'index', follow: 'follow', 'max-image-preview': 'max-image-preview:large', 'max-snippet': …, 'max-video-preview': … }` |
| `og_title`, `og_description`, `og_type`, `og_site_name` | strings |
| `og_image` | **array of objects** `{ url, width, height, type, … }` |
| `twitter_card` | `'summary'` \| `'summary_large_image'` |
| `article_published_time`, `article_modified_time` | strings |
| `schema` | the plugin's `@graph` (we deliberately do NOT copy it) |

Source: Yoast's REST API docs ("the properties provide the raw data … e.g.
`og_image` contains width, height, size, path, alt …"), plus the Yoast
headless guidance.

### 1.2 The double-brand bug (measured, not hypothetical)

Both plugins emit a **final** title, typically `"Page - Site"`. The template
appended its own suffix unconditionally:

```
Base.astro:  `${title} — ${siteTitle}`
```

so a real source title became `"Studio Notes - Fixture Wares — Fixture
Wares"`. The e2e **pinned** the append (`expect(...).toContain('… — Fixture
Wares')`), which is why it never surfaced. Fidelity requires a precedence
rule, not a blind append.

### 1.3 Why the source canonical cannot be copied

The source canonical is the **WordPress** URL. Rendering it on the forged
site would canonicalise every page to the old origin — a de-indexing bug.
The safe rule: render our own canonical; keep the source value as a fact for
the gate (a same-origin difference is reported, never silently applied).

### 1.4 Why the entity graph is rebuilt

Yoast/Rank Math emit `Organization` + `WebSite` (+ `SearchAction`) +
`BreadcrumbList` + the page node, cross-linked by `@id`. Copying that graph
would carry the WP origin inside `@id`s. The forge rebuilds the same shape
from its own facts (manifest name, harvested logo, the search surface, the
route structure) — the 114 "facts, never markup" rule applied to structured
data.

### 1.5 Commerce

`wc/v3` has **no** SEO fields; WooCommerce products **are** the `product`
CPT, so the same ids resolve over `wp/v2/<rest_base>`. One paginated sidecar
request per build (not N+1) recovers the product's Rank Math/Yoast head.

---

## 2. Design

### 2.1 Title composition (`composeDocumentTitle`)

```ts
composeDocumentTitle('Contact Fixture Wares', 'Fixture Wares') // unchanged
composeDocumentTitle('About Us', 'Fixture Wares')              // → 'About Us — Fixture Wares'
composeDocumentTitle('', 'Fixture Wares')                      // → 'Fixture Wares'
```

Rules, in order: empty → site name; identical to the site name → unchanged;
ends with the site name (optionally behind ` — `, ` - `, ` | `, ` · `, `: `)
→ unchanged; otherwise append once. A brand mentioned mid-title still gets
the suffix (only the trailing position signals "already branded").

### 2.2 Loader extraction (`extractSeoMeta`)

Precedence Rank Math → Yoast → absent, normalising both robots shapes to one
`content` string. New `SeoMeta` fields: `title`, `description`, `canonical`,
`robots`, `ogTitle`, `ogDescription`, `ogImage`, `ogType`, `ogSiteName`,
`twitterCard`, `twitterTitle`, `twitterDescription`, `twitterImage` —
undefined keys are deleted so the entry data stays clean. Social image values
must be absolute http(s) (an attachment **ID** is rejected, never rendered as
a URL). `yoast_head_json` is tolerated as an object **or** a JSON string.

The schema gains one optional object (`seo`), keeping `seoTitle` /
`seoDescription` for backwards compatibility.

**Sidecar** (`WpCollection.seoFrom`): after the primary fetch, one bounded
`wp/v2/<rest_base>` request with only the SEO fields; the index is keyed by
`id` and merged into the primary document before extraction. A 404/403
(CPT not exposed) is a warning, never a failed build. `profileFromDiscovery`
sets it automatically for wc/v3 products. The SSR product route does the same
per request (`/wp/v2/<rest_base>/<id>`), fail-soft.

### 2.3 The graph (`jsonLdGraph`, `seo-graph.ts`)

```
@graph:
  Organization  @id #organization   name, url, logo, sameAs?
  WebSite       @id #website        url, name, publisher→#organization,
                                    potentialAction SearchAction (search surface on)
  BreadcrumbList (optional)         Home → [Posts|Products] → page
  <page node>                       isPartOf → #website, publisher → #organization
```

Pages call `buildGraph(Astro.site, jsonLdFor(...), crumbs)`; `seo-graph.ts`
supplies the identity from `nvoos.json` + `chrome.generated.ts` (the
harvested logo). Every page route emits it — home included.

### 2.4 Sitemap

`<loc>`, `<lastmod>` (from `modified`, `_gmt` normalised to `Z`), an
`<image:image>` block per entry with a featured image, and **noindex
exclusion** (a noindex URL in a sitemap is a Search Console error).

### 2.5 Base head

Accepts one optional `seo` prop and renders, in order: description, canonical,
`og:site_name`, `og:type`, `og:title`, `og:description`, `og:url`,
`og:image`, `twitter:card`, `twitter:title`, `twitter:description`,
`twitter:image`, `robots`. Defaults are social-complete even with no SEO
plugin: `og:type=website`, `og:site_name=<site>`, `og:title=<composed
title>`, `twitter:card=summary_large_image` when an image exists. The source
canonical is **not** rendered (see §1.3).

### 2.6 The gate (`verify --seo`)

`lib/seo-audit.ts`, pure over one built document:

| code | severity |
|---|---|
| `title-double-brand` | fail |
| `homepage-noindex` | fail |
| `jsonld-invalid` | fail |
| `title-missing` | fail |
| `canonical-missing` / `canonical-mismatch` | warn |
| `meta-description-missing`, `robots-missing` | warn |
| `og-incomplete`, `twitter-card-missing` | warn |
| `jsonld-graph-missing` | warn |
| `html-lang-missing` | warn |

The CLI audits up to `--seo-pages` (default 8) built pages, homepage first,
prints failures per page and warning **codes** once with a count (they are
usually site-wide), and exits 1 on a failure — or on any warning under
`--strict-seo`.

---

## 3. File map

| File | Change |
|---|---|
| `lib/types.ts` | `WpCollection.seoFrom` |
| `lib/wp-loader.ts` | `SeoMeta`, `SEO_SIDECAR_FIELDS`, `normalizeRobots`, richer `extractSeoMeta`, `fetchSeoIndex`, schema `seo` |
| `lib/discovery.ts` | Rank Math extra fields; `seoFrom` for wc/v3 products |
| `lib/scaffold.ts` | `seoFrom` in the generated collection literal |
| `lib/seo-audit.ts` | the gate (new) |
| `scripts/forge.mjs` | `verify --seo [--strict-seo] [--seo-pages]`, `collectDistHtml` |
| `templates/blog/src/lib/seo.ts` | `composeDocumentTitle`, `jsonLdGraph`, `SiteIdentity` |
| `templates/blog/src/lib/seo-graph.ts` | `buildGraph`, `homeCrumb` (new) |
| `templates/blog/src/layouts/Base.astro` | `seo` prop, title composition, social head, robots |
| `templates/blog/src/pages/{index,[slug],posts/…,products/…,_ssr/…}` | graph + seo props |
| `templates/blog/src/pages/sitemap.xml.ts` | lastmod, noindex, images |
| `templates/blog/src/content.config.ts` | SEO field lists + product `seoFrom` |
| `scripts/wp-fixture-server.mjs` | Rank Math/Yoast facts (noindex page, rich Yoast head, product CPT sidecar id) |

---

## 4. Tests

- `tests/seo.test.ts` — `composeDocumentTitle` (4), `jsonLdGraph` (3).
- `tests/seo-meta.test.ts` — `normalizeRobots` (3), Rank Math mapping (2),
  Yoast fallback (3), precedence/absence (2).
- `tests/seo-audit.test.ts` — `scanSeoHtml` + every finding code.
- `tests/discovery.test.ts` — `seoFrom` derivation.
- `tests/forge-e2e.test.ts` — built-page assertions: no double brand, robots
  survives, OG/Twitter from Yoast, `@graph` with Organization/WebSite/
  BreadcrumbList, product sidecar title, sitemap `lastmod` + `/contact/`
  exclusion.

---

## 5. Risks and deliberate limits

- **Robots fidelity is a two-edged sword**: a source left on `noindex` from
  staging propagates. The gate fails only on a noindex **homepage** (loud);
  inner pages are honoured. Operators flip it in the profile/source.
- **Custom (cross-page) canonicals are dropped** — the safe default. The gate
  reports a same-origin mismatch when an expected URL is supplied.
- **Schema `name` stays the product/post title**, not the SEO title; the SEO
  title is for `<title>`/social only.
- **Yoast's raw `schema.@graph` is never copied** — only rebuilt entities.
- **hreflang / Polylang alternates** remain out of scope.
- **Redirection-plugin rules** still cannot be read over REST (131 only
  extends what the REST documents expose).

---

## 6. References

- Yoast — REST API (`yoast_head_json` raw properties, `og_image` object
  shape): <https://developer.yoast.com/customization/apis/rest-api/>
- Yoast — Metadata API (the presenter model behind the head):
  <https://developer.yoast.com/customization/apis/metadata-api/>
- Rank Math meta keys (canonical/robots/facebook/twitter):
  <https://github.com/rankmath/seo-by-rank-math/blob/master/wpml-config.xml>
- Rank Math meta-data filters (robots/canonical semantics):
  <https://rankmath.com/docs/filters-and-hooks/frontend/meta-data/>
- Rank Math REST field names in the wild:
  <https://nextgrowth.ai/n8n-rank-math-rest-api-integration/>,
  <https://gatographql.com/guides/plugins/rankmath>
- schema.org `Organization` / `WebSite` / `SearchAction` / `BreadcrumbList`:
  <https://schema.org/>
- Google structured-data guidance (graph + `@id` entity linking):
  <https://developers.google.com/search/docs/appearance/structured-data>
