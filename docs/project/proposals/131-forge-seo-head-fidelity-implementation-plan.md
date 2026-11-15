# Proposal 131 — Implementation plan (SEO head fidelity)

Companion to `131-forge-seo-head-fidelity-proposal.md`. Shipped 2026-11-08.

---

## 1. Scope

Closes the Rank Math / Yoast gap end to end:

1. the double-branded `<title>` bug,
2. the rest of the plugin head (robots, canonical fact, OG, Twitter),
3. the site-level JSON-LD graph (Organization / WebSite / BreadcrumbList),
4. product SEO via a wp/v2 sidecar,
5. sitemap `lastmod` + noindex exclusion + images,
6. the `verify --seo` gate.

Out of scope (declared): hreflang/Polylang alternates and any attempt to
copy Yoast's raw `schema.@graph`. Redirection-plugin rules were out of scope
here and are delivered by **Proposal 132** (stored redirect capture).

---

## 2. File-by-file

### 2.1 `lib/types.ts`
- `WpCollection.seoFrom?: string` — the wp/v2 CPT the loader reads SEO meta
  from when the primary wire cannot carry it.

### 2.2 `lib/wp-loader.ts`
- `SeoMeta` (13 optional fields) + `SEO_SIDECAR_FIELDS`.
- `normalizeRobots(value)` — array (Rank Math) / object (Yoast) / string →
  one `content` string, deduped.
- `extractSeoMeta(entry)` rewritten: Rank Math flat fields → Yoast
  `yoast_head_json` → absent; undefined keys deleted. Social images must be
  absolute http(s) (an attachment ID is rejected). `yoast_head_json` may be
  an object **or** a JSON string.
- `fetchSeoIndex(client, collection, logger)` — ONE bounded paginated
  wp/v2 request, id-keyed; failure is a warning.
- `contentSchema.seo` (optional object) + the transform passthrough.
- The entry loop merges the sidecar document before `extractRaw`.
- `seoTitle`/`seoDescription` remain (backwards compatible), now derived from
  `seo.title` / `seo.description`.

### 2.3 `lib/discovery.ts`
- `SEO_FIELDS` (11 keys) appended to the post/page field lists.
- `profileFromDiscovery` sets `seoFrom` for wc/v3 products when the product
  CPT exists.

### 2.4 `lib/scaffold.ts`
- `collectionLiteral` emits `seoFrom`.
- `ForgeManifest.collections[].seoFrom` — always emitted (`''` when unused,
  matching the existing explicit-defaults rule) so the SSR route's inferred
  JSON type exposes the key. wc/v3 products default to `wp/v2/product`.

### 2.5 `lib/seo-audit.ts` (new)
Pure parse + 11 finding codes (3 fail, 8 warn). `scanSeoHtml` is exported so
the parse is unit-testable on its own.

### 2.6 `scripts/forge.mjs`
- `collectDistHtml(target, limit)` — homepage first, bounded, skips `404.html`,
  `_astro`, `pagefind`.
- `runSeoVerify(profile, options)` — counts failures, groups warning codes,
  exits 1 on a failure or on any warning under `--strict-seo`.
- `verify --seo [--strict-seo] [--seo-pages n]`, and the design-less profile
  can now run `--seo` / `--tracking` without the parity precondition.

### 2.7 Template
- `src/lib/seo.ts`: `composeDocumentTitle`, `SiteIdentity`, `JsonLdGraph`,
  `jsonLdGraph` (drops nested `@context`, wires `@id`s, optional SearchAction
  + BreadcrumbList).
- `src/lib/seo-graph.ts` (new): `buildGraph` (identity from `nvoos.json` +
  `chrome.generated.ts`) and `homeCrumb`.
- `src/layouts/Base.astro`: optional `seo` prop; `pageDescription` falls back
  to `seo.description`; full OG/Twitter head; `robots` from the source;
  the source canonical is deliberately **not** rendered.
- `index.astro`, `[slug].astro`, `posts/[...parts].astro`,
  `products/[...parts].astro`, `products/_ssr/[...slug].astro`: pass `seo`,
  build the graph, add breadcrumbs. The SSR product route fetches the
  wp/v2 sidecar document per request (fail-soft).
- `sitemap.xml.ts`: `lastmod` (gmt → `Z`), noindex exclusion, image
  extension, XML escaping.
- `content.config.ts`: the SEO field lists + product `seoFrom`.

### 2.8 Fixture
- `contact`: `rank_math_title` already carrying the site name +
  `rank_math_robots: ['noindex','follow']` + a description.
- `about` (front page): `rank_math_description`.
- `care-guide`: the full Yoast head (canonical, robots object, OG, Twitter,
  og_image array).
- `wpProducts`: id **101** (same id as the wc document — Woo products are the
  product CPT) carrying the Yoast head the sidecar must merge.

---

## 3. Decisions worth keeping

| Decision | Why |
|---|---|
| Title rule = "ends with the site name ⇒ don't append" | source titles are final; the append produced `X — Site — Site` |
| Source canonical never rendered | it points at the WordPress origin (de-indexing) |
| Schema `name`/`description` keep the content values | the SEO title is for `<title>`/social, not structured data |
| Robots rendered verbatim, noindex **homepage** fails the gate | fidelity, with one loud guard for the catastrophic case |
| Graph rebuilt, `@id`s derived from the site origin | copying Yoast's graph ships WP URLs inside `@id` |
| Sidecar = one collection request, id-keyed | no N+1; Woo products *are* the product CPT |
| Sitemap drops noindex | a noindex URL in a sitemap is an SC error |

---

## 4. Validation (2026-11-08)

- `npm run typecheck` — clean.
- `npm test` — **544/544** (28 new: `composeDocumentTitle` 4,
  `jsonLdGraph` 3, `normalizeRobots`+`extractSeoMeta` 10, `seo-audit` 10,
  discovery `seoFrom` 1).
- `npm run test:e2e` — **31/31** (338s), including the new built-page
  assertions: no double brand on `/contact/`, `noindex, follow` preserved,
  Yoast OG/Twitter on the post, `@graph` with Organization/WebSite/
  BreadcrumbList, the sidecar product title, sitemap `lastmod` + `/contact/`
  exclusion.
- Manual CLI proof on a real build against the fixture
  (`ASTRO_SITE` set, 8 pages): `verify --seo --strict-seo` → **exit 0**.
  Injecting a double-branded `<title>` → **exit 1** with
  `title-double-brand` naming the page.

---

## 5. Follow-ups (not started)

- `hreflang` from Polylang/WPML (`yoast_head_json` does not carry alternates;
  needs a plugin-specific source).
- Redirection-plugin rules (DB-only) — a bridge REST route would expose them.
- A profile-level `canonicalOverrides` map for deliberate cross-page
  canonicals (today the gate only reports a same-origin mismatch).
- Extend `verify --seo` to compare against the source head per page (needs a
  stored source snapshot; the loader currently keeps only what the build uses).
