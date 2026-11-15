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
- Do not write this into a locked profile without a plan: `locked` is an
  ownership record, not a "site is finished" flag.
