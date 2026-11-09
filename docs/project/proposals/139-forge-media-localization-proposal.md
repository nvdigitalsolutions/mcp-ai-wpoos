# Proposal 139 — Forge media localization (self-contained artifacts)

Status: **shipped 2026-11-09**. Surface: `examples/nvoos-astro-forge`.

## 1. The problem

A forged site is a static artifact: WordPress is the CMS, Velocity serves the
built `dist/`. Astro's `<Image>` already downloads and optimizes remote
images at build time — but only for **authorized hosts** and only for images
that pass through a component. Everything else stays a hotlink:

| Asset | Path today | Local in `dist/`? |
|---|---|---|
| Design-band media / featured images | `SmartImage` → `<Image>` | ✅ when the host is authorized |
| **Body-content `<img>`** (sanitized `set:html`) | plain `<img>` | ❌ |
| Site chrome (logo, favicon, footer logo) | plain `<img>` / `<link>` | ❌ |
| Collage photos (absolute offsets) | plain `<img>` | ❌ |
| Any non-authorized host (CDN, legacy domain) | plain `<img>` | ❌ |

That produced the 2026-11-09 incident: moving WordPress to
`cms.jayainc.com` while the public site took `jayainc.com` left every
`…/wp-content/uploads/…` reference pointing at the **old** host, which now
serves the Astro site. `SmartImage`'s same-host gate failed, the fallback
hotlink 404'd, and the images "broke". WordPress stores absolute media URLs
in the database (`guid`, post content, `_wp_attached_file` siblings), so this
is a normal consequence of any domain migration — not a forge bug in itself.

## 2. Research — what the ecosystem does

* **Astro images** — `<Image>` transforms **authorized remote** sources at
  build time for prerendered pages (on demand under SSR); `public/` is copied
  verbatim and never optimized; assets are cached in `node_modules/.astro`
  and revalidated by `Cache-Control`/`ETag`. `image.domains` /
  `image.remotePatterns` are the authorization list.
  <https://docs.astro.build/en/guides/images/>
* **CMS → static pipelines** download and localize assets as part of the
  build, not at request time: Gatsby's `createRemoteFileNode`,
  `gatsby-plugin-remote-images`, Next's image cache, WP2Static's URL rewrite
  and Netlify build plugins all follow the same compile-time pattern.
* **URL rewriting after rendering** is the long-standing static-migration
  technique (WP2Static; the `localize-html` family): rewrite the final HTML
  rather than trying to reconstruct the CMS's markup.
* **WordPress domain moves** are a `wp search-replace` problem first
  (serialized data must not be naive find/replaced), e.g.
  `wp search-replace 'https://old' 'https://new' --all-tables --precise`.
  The forge can only *tolerate* a stale database, not repair it permanently.

Conclusion: keep Astro's optimizer for the images it can see, and add a
**deterministic post-build localization pass** as the guarantee layer.

## 3. Design — three layers

### 3.1 Authorize (`astro.config.mjs`, `src/lib/media.ts`)

`image.domains` = CMS host ∪ `profile.media.hosts` ∪
`NVOOS_FORGE_MEDIA_HOSTS`. `SmartImage` uses the same rule (`isAuthorizedImageUrl`)
instead of a local single-host comparison, so a CDN or a second domain keeps
its optimization instead of degrading to a passthrough `<img>`.

### 3.2 Repair (`repairMediaUrl`, `resolveDownloadUrl`)

A `/wp-content/` or `/wp-includes/` path whose host is neither the CMS nor an
allowlisted media host is re-pointed at the CMS origin (path + query
preserved). That is the `wp search-replace` rule applied at build time: the
image is fetched from where it actually lives, not from the dead host.

Deliberately **not** a blanket host swap: an allowlisted media host (CDN) is
trusted, and a non-WordPress path is never rewritten.

### 3.3 Mirror / proxy (`scripts/localize-media.mjs`)

Runs after `astro build`, before `pagefind`. The strategy depends on the
deploy tier (`nvoos.json` now carries `runtime`):

* **Static (`runtime: static`, the default)** → **mirror**. A static artifact
  has no runtime origin, so the media must travel with it:

  1. scan the built `.html` + `.css` for image references —
     `img[src|srcset|data-src|data-srcset]`, `source[src|srcset]`,
     `video[poster]`, `link[rel~=icon][href]`, and every CSS `url(...)`;
  2. filter to candidates (CMS host, allowlisted host, WordPress asset path, or
     `NVOOS_FORGE_LOCALIZE_ALL=1`);
  3. download (bounded concurrency, per-file + build-wide caps, per-request
     timeout, `WP_FETCH_PROXY` honoured — bot-protected sources block
     datacenter egress);
  4. write `dist/media/<sha1(url)[0..12]>-<slug>.<ext>`;
  5. rewrite the references in place.

* **Managed (`runtime: managed`, the bridge/BFF is present) → proxy.** The
  runtime already ships `src/pages/media/[...path].ts`, which forwards
  `/media/<rest>` to `<CMS>/wp-content/uploads/<rest>` server-to-server and
  keeps the CMS host out of the HTML. Rewriting uploads to that path is
  cheaper and more correct than duplicating the library into every managed
  deploy, so `…/wp-content/uploads/<rest>` becomes `/media/<rest>` with **no
  download**. Non-upload references (theme assets, `wp-includes`) cannot be
  proxied and are reported and left alone.

**External stylesheets travel with their fonts.** A `<link rel="stylesheet">`
pointing at a candidate host (typically the theme's icon sheet) is downloaded,
its relative `url()` refs are resolved against the sheet's own URL, those
fonts/images are downloaded too, and the rewritten sheet is emitted to
`dist/media/<hash>-<name>.css` with every ref pointing at `/media/…`. Without
this the icon glyphs stay a cross-origin dependency — and a latent CSP failure
the moment `tracking.consent.enforceCsp` is turned on (`style-src`/`font-src`
never allowlist the CMS). Fails soft like everything else: an unfetchable sheet
keeps its original link and is reported.

Override with `NVOOS_FORGE_MEDIA_MODE=local|proxy|off`.

**Never** rewritten in either mode: `<a href>`, `<meta content>` (`og:image`
must stay absolute), JSON-LD `<script>` payloads. A local path is invalid
there, and a broken structured-data URL is worse than a hotlink.

The pass is **fail-soft**: a failed download keeps the original URL and lands
in `.forge-cache/media-localize.json`; the build never fails on media. It is
**idempotent**: after a pass every reference is relative, so a second run
finds nothing.

## 4. Configuration

| Source | Key | Default |
|---|---|---|
| Profile | `deploy.runtime` | `static` → mirror; `managed` → proxy |
| Profile | `media.hosts[]` | `[]` (CMS host only) |
| Profile | `media.localize` | `true` |
| Profile | `media.maxFileMb` | `25` |
| Env | `NVOOS_FORGE_MEDIA_MODE` | `local` (static) / `proxy` (managed) |
| Env | `NVOOS_FORGE_MEDIA_HOSTS` | additive to the profile |
| Env | `NVOOS_FORGE_LOCALIZE_MEDIA` | on (`0` disables) |
| Env | `NVOOS_FORGE_LOCALIZE_ALL` | off (mirror every host) |
| Env | `NVOOS_FORGE_MEDIA_MAX_MB` / `_MAX_TOTAL_MB` | 25 / 500 |
| Env | `NVOOS_FORGE_MEDIA_TIMEOUT_MS` | 20000 |

The profile values are baked into `nvoos.json` (`media`) and emitted in
`.env.example`; env always wins at build time.

## 5. Non-goals

* Repairing WordPress's database — that is `wp search-replace`, on the CMS.
* Localizing documents (`a[href]` PDFs, the flipbook catalogue) — a separate
  class of asset with different size/authorization rules.
* Rewriting `og:image` / JSON-LD to local paths — they must remain absolute.
* On-demand (SSR) pages — only prerendered HTML is localized; the managed
  runtime proxies `/media` server-to-server instead (that is the managed
  default, §3.3).

## 6. Verification

* `tests/media-localize.test.ts` — 27 unit cases: candidate rules, host
  repair, deterministic naming, `srcset` parsing, and the "never rewrite
  anchors/meta/JSON-LD" contract.
* `tests/forge-e2e.test.ts` — the hermetic fixture build asserts a
  body-content image lands in `dist/media/` and its absolute CMS URL is gone,
  a third-party image is untouched, and the report records the pass.
* Full gates: `npm test` (891), `npm run typecheck`, `npm run test:e2e` (33,
  including the minimal preset, SSR variant, offline build and wizard).

## 7. Gotchas (each cost a fix)

* **Nested quotes break an inline style.** Rewriting `url('…')` inside
  `style="…"` to `url("…")` truncates the attribute. Emit `url(/media/…)`
  unquoted — generated names never contain a character unquoted `url()`
  forbids.
* **Attribute regexes need `(?:^|\s)` / `(?<![\w-])`** so `src` does not match
  inside `data-src` and `srcset` does not match inside `data-srcset`.
* **`srcset` splitting is comma-based**; a URL containing a comma is left
  untouched (a missed rewrite, never corruption).
* **A bare `?` survives URL normalization** — `font.eot?#iefix` keeps an empty
  query (`…eot?`), splitting one font into two download keys. Strip the
  trailing `?` after clearing the hash.
* **A local stylesheet changes the meaning of relative `url()`s** — the sheet
  moves from `/wp-content/themes/x/css/` to `/media/`, so every ref must be
  rewritten (local when mirrored, absolute otherwise).
* **`<a>`, `<meta>`, JSON-LD must not be scanned** — `og:image` and schema.org
  `image` are invalid as relative paths, and the catalogue PDF must keep
  working.
* **SSR builds write to `dist/client/`** — pick the output dir the way
  `postbuild.mjs` does.
* **The scaffold staging-dir race** (`EPERM`/`ENOTEMPTY` on Windows) shows up
  as flaky timeouts under 61 parallel workers; bound the workers to confirm a
  green run (`--maxWorkers=4`) before chasing a code bug.

## 8. Rollout note (jayainc)

`sites/jayainc.json` now declares the two-tier topology: `url` =
`https://cms.jayainc.com` (the CMS), `deploy.publicOrigin` =
`https://jayainc.com` (the public site), `deploy.runtime` = `static`, and
`media.localize` = `true`. The old domain is **not** added to `media.hosts` —
a stale `/wp-content/` URL is repaired to the CMS origin, whereas allowlisting
the old host would make the build fetch from the Astro site and 404.

The real rebuild surfaced three references that are not images and so are not
mirrored — they are **repaired at render time** instead (`repairMediaUrl`):
`chrome.iconCss` (`<link rel=stylesheet>`), the catalogue CTA
(`design.sections[].catalog.source`) and the JSON-LD logo
(`seo-graph.ts` → `chrome.logo.src`). Result: 21 references localized
(1.7 MB — 12 images, the icon sheet and 8 font files), zero `jayainc.com`
media references in `dist/`, and the icon sheet now same-origin (no CSP
allowlist needed).
