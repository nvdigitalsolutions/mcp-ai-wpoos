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

## Rebuilding with no CMS

```sh
forge materialize sites/<site>.json --from ./<site>-frontend --out sites/<site>.content
forge sites/<site>.json --target ./<site>-rebuild --content sites/<site>.content
cd ./<site>-rebuild && npm install && ASTRO_SITE=https://<public> npm run build
```

The profile's collections must be **optional** (`"optional": true`) or the
build still hard-fails: a *network-level* failure degrades to local-only, but
an HTTP error (404) is deliberately fatal so a misconfigured source is never
silent.

Verified end-to-end: 3 materialized pages (28,354 B front-page body, 7
sections) → `bands 7 pass, 0 warn, 0 skip`, nav anchors 7, media 43 images,
8/9 gates (the 9th is the informational class-coverage warning).

## Checklist after a cutover

- Point `profile.url` at the CMS origin — or declare it gone. A stale value
  silently breaks every future build, and as `NVOOS_TARGET_SITE` it would make
  the managed BFF proxy to itself.
- Materialize the content **before** deleting the CMS (or keep a backup of
  `dist/server`).
- Mark collections optional (or drop them) once the CMS is gone.
- Decide the media story: the build localizes images, so a fresh rebuild while
  the CDN cache is cold will fetch 404s.
- Do not write this into a locked profile without a plan: `locked` is an
  ownership record, not a "site is finished" flag.
