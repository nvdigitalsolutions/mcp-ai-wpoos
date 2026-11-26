# CMS removal, cutover, and materialized content

2026-11-15, from a real handover: `virtuallyjamaica.com` was cut over to its
forged Astro app and the WordPress app was then deleted. The forge had no
supported path for either half of that.

## The failure signature

A build dies at content sync with:

```
Endpoint not found: wp/v2/pages
```

That is **not** a template or emit bug — it means `profile.url` no longer
serves WordPress. Confirm before touching any code:

| Probe | Still a CMS | CMS gone (real reading) |
|---|---|---|
| `GET /wp-json/` | 200 + JSON | 404 rendered by the *Astro* site (`<meta name="generator" content="NV oOS Astro Forge">`) |
| `GET /?rest_route=/wp/v2/pages` | JSON | 200 with the **homepage HTML** (the route falls through to the app) |
| `GET /wp-login.php`, `/wp-admin/` | 200 / 302 | 404 |
| `GET /wp-content/uploads/…jpg` | 200 from WP | 200 from **Cloudflare cache** — check `cf-cache-status: HIT` and a large `Age:` |
| `app_list` on the server | the WP app | the app is **absent** (an `app_get` 403 can also mean "outside this token's scope") |

A cutover typically leaves one last trap: `/wp-content/*` keeps answering from
the CDN cache for hours, so media looks alive while the API is gone.

## Why the site could not be rebuilt at all

Three separate holes, each independently fatal:

1. **Content lived only in the artifact.** The last good build baked the WP
   content into `dist/server/chunks/_astro_data-layer-content_*.mjs`. With the
   CMS deleted that bundle is the only surviving copy.
2. **Lock + no tree.** `profile.locked` makes CI skip the site and `scaffold`
   refuse it — correct when a developer owns the tree, fatal when no tree
   exists anywhere. See `handoff-lock-and-band-gates.md`.
3. **Local content rendered unthemed.** The hybrid-local merge (106 P2) kept
   MDX entries in the glob shape (`body` only); the template reads
   `rendered.html`, so a locally-sourced page fell through to raw markup and
   every band vanished (`bands — 7 of 7 band(s) failed`, all nav anchors dead).

## Recovering the content

`forge materialize <profile> --from <built project> [--out <dir>]`
(added 2026-11-15, `lib/materialize.ts`):

- finds `dist/server/chunks/_astro_data-layer-content_*.mjs`
- revives Astro's **flat serialization**: primitives inline, tagged arrays
  (`['Map', k, v, …]`, `['Set', …]`, `['Date', iso]`), and plain objects whose
  property values are **indexes** back into the array; `-1` means absent
- writes `<out>/<collection>/<slug>.mdx` (frontmatter only) **plus**
  `<slug>.html` (the sanitized body)

**Never `JSON.parse` the literal.** It is a JS module
(`export default JSON.parse("…")`) and can carry escapes JSON rejects
(`Bad escaped character in JSON at position 4540`). Import it instead.

### The body must not live in the MDX

MDX parses its body as JSX:

```
Expected a closing tag for `<ul>` (38:959) before the end of `paragraph`
```

Arbitrary WordPress HTML will hit this eventually. Ship the body as a sibling
`<slug>.html`; the loader reads it (and falls back to `body` for plain prose).

### Two documents may share a slug (2026-11-22)

WordPress page slugs are unique among SIBLINGS only, so a site can legitimately
publish `team` twice (`/about/team/`, `/services/team/`). The writer used to
name both `team.mdx` and silently overwrite the first — the report counted two
documents while one file survived. De-duplicating only the FILENAME was not
enough: the flat `/[slug]/` route reads the frontmatter `slug`, so the build
still claimed `/team/` twice.

`writeDocuments` now plans the published slugs before writing anything. One
document keeps the bare slug; the rest get `<slug>-N`, starting at `-2` (the
WordPress `wp_unique_post_slug` / Drupal Pathauto convention — the first
duplicate is the second document). A suffix skips every slug another document
really owns, so a genuine `team-2` is never shadowed. The keeper is
deterministic: the document whose own `link` is exactly `/<slug>/` wins,
otherwise the lowest numeric source id, otherwise input order — never API
order, so re-materializing is a stable diff.

The renamed value goes into BOTH the filename and the frontmatter `slug`, and
the source's own slug is written as `slugRenamedFrom`. That field keeps the
original URL reachable: `redirects.json.ts` maps `data.link` (the WordPress
path) to the published slug, so `/services/team/` 301s to `/team-3/` while
`/about/team/` 301s to `/team/`. The keeper's own redirect is skipped when
source equals target, and the link-based tie-break is what stops a renamed
page's redirect from shadowing the winner. A HYBRID load (`content.snapshot`,
CMS still live) also merges on `slugRenamedFrom`, so the fallback snapshot
yields to WordPress's `team` instead of reappearing as `/team-3/`.

The materialized loader keys local entries by the source's numeric `id`
(`localEntryId`), so both documents survive Astro's glob load. The `route keys`
gate remains the second guard for content that was NOT captured — hand-written
hybrid MDX, a CMS that still publishes one slug twice, case variants — and
`build.strictRoutes: true` makes an exact duplicate a build failure. Do not
hand-edit a renamed snapshot slug back to the source slug: that reintroduces
the duplicate route. A renamed page also falls back to the default band
theming unless the profile's `pageBands` entry is re-keyed (those facts are
keyed by source slug).

## Rebuilding with no CMS

```sh
forge materialize sites/<site>.json --from ./<site>-frontend --out sites/<site>.content
forge sites/<site>.json --target ./<site>-rebuild
cd ./<site>-rebuild && npm install && ASTRO_SITE=https://<public> npm run build
```

`content.source: 'materialized'` is a **promise**, not a hint: the snapshot is
the only source, so the scaffold wires every collection to its snapshot glob
and sets `localOnly: true` — the generated loader never touches the network.
The dead origin may answer 404, 200 with whatever now serves the domain, or
hang; none of that can fail the build (run #102 died on exactly that 404 after
the content had already been recovered).

A materialized profile also makes every collection **optional** by default: a
collection the snapshot does not carry is an EMPTY collection, not a broken
source. You do not have to hand-edit `optional: true` into the profile.

Verified end-to-end: 3 materialized pages (28,354 B front-page body, 7
sections) → `bands 7 pass, 0 warn, 0 skip`, nav anchors 7, media 43 images,
8/9 gates (the 9th is the informational class-coverage warning). The 2026-11-15
CMS-less rebuild adds: no network at all, and `NVOOS_TARGET_SITE` left blank in
the managed env example (pointing it at the public origin would make `/api`
proxy to itself — the BFF answers 503 until a CMS returns).

## Keeping the CMS, with a snapshot as the net (2026-11-15)

A site that still has a CMS can keep the snapshot anyway — not as its source,
but as the thing it degrades to. Set `content.snapshot: true` and ship
`sites/<name>.content/` beside the profile:

```json
"content": { "snapshot": true }
```

What changes, and what does not:

- The snapshot is copied into `src/content/` exactly like `materialized`, so
  the local MDX glob reads it. The directory is derived from the profile path
  (`sites/<name>.json` → `sites/<name>.content`); `--content <dir>` still
  overrides it.
- The **WordPress loader still runs first** and wins on collision (hybrid
  local, 106 P2). A CMS outage yields the last snapshot instead of an empty
  site; a healthy CMS is always fresh.
- Only a **network-level** failure degrades: refused, DNS, timeout. An HTTP
  error (404/5xx) still fails the build on purpose — a reachable but
  misconfigured source is not something a snapshot should hide.
- The scaffold **refuses** `snapshot: true` when the directory is missing. A
  net that is silently absent is worse than none: run `forge materialize`
  first, or drop the flag.
- The fallback is announced in the build log, per collection:
  `[nvoos-wp] <collection>: WordPress unreachable (…) — serving local content only.`

Refresh the snapshot on a schedule (run `forge materialize` against the live
site), or it quietly becomes a stale copy. It is a rollback target, not a
cache — and it does not fix a misconfigured CMS, only an absent one.

## Cutting over while the CMS is still up (2026-11-21)

`materialize --from` recovers content from a built artifact, which needs
`dist/server/chunks/_astro_data-layer-content_*.mjs` — and a `static` runtime
never produces one. So the commonest cutover (freeze now, retire WordPress
later) had no path at all: by the time you *can* recover, there is nothing left
to recover from.

`forge materialize <profile> --from-wp [--out <dir>]`:

- drives the **production loader** (`lib/wp-loader.ts`) against a capture-only
  loader context, so the snapshot carries exactly what a build would bake —
  pagination, `_fields` pruning, the versioned sanitizer allowlist, the
  `wp/v2` SEO sidecar, featured-media resolution and `collections[].exclude`
- writes the SAME `<slug>.mdx` + `<slug>.html` shape through the same writer
  as the build-recovery path
- basic auth from `WP_APPLICATION_USER` / `WP_APPLICATION_PASSWORD`

Reusing the loader is the point. A second field-mapping is the defect class
this repo keeps paying for; the snapshot has to be indistinguishable from a
build's own store.

Then one profile edit:

```json
"content": { "source": "materialized" },
"media": { "snapshot": true }
```

and `forge media-snapshot <profile> --from <built project>` freezes the images.
It reads `.forge-cache/media-localize.json`, so point it at a BUILD, not a
deploy.

### Proving the detach is lossless

Three checks, cheap first — the first two catch almost everything:

1. **Route set**: `find dist -name '*.html' | sort` before and after must match.
2. **Visible text**: `textFromHtml()` every page in both builds and diff. A
   materialized rebuild must be word-identical; anything else means the
   snapshot lost a document (an `exclude` entry doing real work, or a
   collection the snapshot does not carry).
3. **Zero retired-CMS references** — the gate below.

monicarajiv: 10/10 routes, 10/10 pages word-identical, 68 media files, 0
`wp-content` references.

### Posts keep their WordPress id (2026-11-22)

The snapshot writes the source's numeric `id` in each document's frontmatter,
but a glob entry's store id is the FILENAME (`blog-details`), and every post
route is `/posts/<entry.id>/<slug>/` — so a detached post moved from
`/posts/2228/blog-details/` to `/posts/blog-details/blog-details/` and every
post URL 404'd (`monicarajiv` had no posts, which is why the route check missed
it). `adoptLocal` now adopts `data.id` as the store id in `localOnly` mode.
Hand-written local MDX carries no `id` and keeps the slug id — the hybrid-local
route shape the e2e pins (`/posts/hello-world/hello-world/`). virtuallysrilanka:
11/11 routes identical before and after the detach.

**Overrides (2026-11-22, issue #8092).** The merge keys on the ROUTE key — the
collection's slug, normalized — not the store id (a local id is the filename
slug, a WordPress id is numeric, so an id-keyed set never matched). WordPress
still wins by default, and the dropped local file is named in a warning. A
local document may replace a WordPress one with `override: true` in its
frontmatter; for the id-routed collections (`/<base>/<id>/<slug>/` — posts,
products, CPTs) the override MUST also carry `id: <wp-id>`, or the build fails
(overriding without it would move a live URL). Pages need no id — `/[slug]/` is
the page's own slug, and the WordPress slug wins for the URL.

Two layers guard duplicates besides the merge: the glob loader keys a local
entry by its frontmatter `slug`, so an EXACT duplicate in one collection is
dropped with Astro's `DuplicateContentEntrySlugError` warning (a build failure
under `build.strictRoutes: true`, which writes
`prerenderConflictBehavior: "error"`); and `verify`'s **route keys** gate reads
the post-sync store and fails on a URL claimed twice — the cases the glob check
does not compare (case variants, WordPress-side duplicates, two collections
mapping one path).

### Re-freeze a snapshot taken before the `sourceFile` field (2026-11-22)

A `media-snapshot` taken from a build whose `localize-media.mjs` predates the
`sourceFile` report field stores the REWRITTEN stylesheet, so the next cold
build resolves its `url(/media/…)` refs against the CMS host, 404s every font,
and rewrites the `@font-face` to `unavailable.svg` (virtuallysrilanka's eicons
face — every carousel glyph went blank). The fix is to re-freeze from a build
whose report carries `sourceFile: .forge-cache/source-stylesheets/…`:
temporarily drop `media.snapshot`, build against the live CMS, run
`media-snapshot`, restore the flag. The manifest then carries the SOURCE sheet
text and the second pass resolves its relative font URLs through the snapshot
(`localized 26 reference(s)`, 0 unavailable, 0 dropped faces).

`media-snapshot` now WARNS about both traps instead of leaving them to a cold
build: a sheet entry with no `sourceFile` (or whose source text still contains
`url(/media/…)`) and any `/wp-content/`/`/wp-includes/` URL in the built
HTML/CSS that the report never localized (an image Astro's `<Image>` consumed).
Both are advisory by default — the snapshot is still written. `--strict` turns
them into exit 1 (the write still completes first, so a red CI run leaves a
coherent snapshot to inspect and a re-run merges over it).

### A materialized build must not authorize the dead CMS (2026-11-22)

`astro.config.mjs` derives `image.domains` from `WP_SITE_URL ?? manifest.url`
and `SmartImage` hands an authorized URL to Astro's `<Image>`, which DOWNLOADS
it at build time. A materialized profile kept authorizing its retired CMS, so
the first genuinely offline rebuild died:

```
[ERROR] [build] Caught error rendering /: FailedToFetchRemoteImageDimensions:
  Failed to get the dimensions for http://…/wp-content/uploads/…png
```

`cmsOrigin()` in `src/lib/media.ts` now returns `''` when the manifest's
`contentSource` is `materialized`, and `astro.config.mjs` skips the CMS host
for the same profile. SmartImage falls back to a plain `<img>`, the post-build
localizer mirrors the committed snapshot, and the build makes no request at
all (verified with a preload that throws on any request to the retired host:
exit 0, 29/29 references localized). Two consequences: an allowlisted
`media.hosts` CDN still optimizes, and the images Astro used to consume have no
snapshot entry — re-freeze AFTER the switch (virtuallysrilanka went 26 → 29
URLs; the two PNGs left uncompressed are the localizer's"not smaller as WebP"
decision, now an advisory on the media-budget gate).

### The gate that proves it stays detached

`verify` runs **`retired cms`** whenever `content.source` is `materialized`: it
fails the run when any built HTML or CSS still addresses `<host>/wp-content/`
or `/wp-includes/`.

Only ASSET paths, deliberately — every page legitimately points its canonical
and `og:url` at the public origin, which is usually the same hostname the CMS
answered on. The gate exists because JSON-LD `Organization.logo` shipped the
retired `wp-content` URL on all five content pages and nothing looked: the
localizer left JSON-LD absolute on purpose (schema.org needs an absolute URL),
which is right while the CMS lives and fatal once it does not.

## Checklist after a cutover

- Point `profile.url` at the CMS origin — or declare it gone (`content.source:
  'materialized'`). A stale value silently breaks every future build, and a
  materialized profile must never inherit the old CMS as its BFF upstream.
- Materialize the content **before** deleting the CMS (or keep a backup of
  `dist/server`).
- Commit `sites/<name>.content/` beside the profile — the scaffold derives the
  directory from the profile path, so CI needs no extra flag. A materialized
  profile with no snapshot scaffolds with a loud warning and builds EMPTY.
- Decide the media story: the build localizes images, so a fresh rebuild while
  the CDN cache is cold will fetch 404s. `media.snapshot: true` freezes them.
- Snapshot **while the CMS is live** (`materialize --from-wp`) if you can. The
  build-recovery path only works for a `dist/server` artifact, and a static
  runtime never has one — waiting until the CMS is gone is waiting too long.
- Re-check the chrome TYPE, not just the boxes. The nav link's size/weight/
  transform/tracking come from the browser measurement
  (`chrome.geometry.navStyle`, majority over VISIBLE links) and its face from
  `design.typography.ui`; a profile written before 2026-11-21 carries the static
  resolver's guess (a relative `font-size` read against the 16px root) and needs
  `analyze --geometry` or a re-forge to correct.
- Do not write this into a locked profile without a plan: `locked` is an
  ownership record, not a "site is finished" flag.
