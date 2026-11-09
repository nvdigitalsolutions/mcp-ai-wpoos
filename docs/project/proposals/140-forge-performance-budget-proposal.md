# Proposal 140 — Forge performance budget

**Status:** Implementing
**Depends on:** [139](./139-forge-media-localization-proposal.md) (the media localizer this extends)
**Surfaces:** `templates/blog/scripts/localize-media.mjs`, `templates/blog/scripts/media-localize-lib.mjs`, `scripts/forge.mjs`, `lib/scaffold.ts`, `lib/types.ts`, `sites/*.json`

## 1. Problem

A GTmetrix run of the first forged production site (`jayainc.com`, 2026-11-09) scored **95% structure / 98% performance** but left 327 KB — 38% of an 862 KB page — on the table:

| Audit | Finding |
|---|---|
| Serve images in next-gen formats | 3 JPEGs (305 KB, 157 KB, 157 KB) → 327 KB potential saving |
| Efficiently encode images | 194 KB potential saving |
| Use explicit width and height | 2 `<img>` (header/footer logo) — the only measured layout shift |
| Ensure text remains visible during webfont load | `auxin-front.woff` (129 KB) with no `font-display` |
| Avoid chaining critical requests | 3 chains, 1.2 s — HTML → `Base.css` → `auxin-icon.css` → woff |
| Eliminate render-blocking resources | `auxin-icon.css` and `fonts.googleapis.com` |
| Reduce impact of third-party code | 780 ms to Google Fonts — **the CSS request failed outright** |

The root cause is structural, not cosmetic. Proposal 139's localizer downloads with `copyFile`: verbatim bytes. Only images that flow through `SmartImage` → `astro:assets` are optimised, so site chrome, body-content images, collage photos and CSS backgrounds ship exactly as WordPress stored them.

The Google Fonts failure is the sharper lesson: a render-blocking third-party stylesheet can fail, and when it does the page renders with a fallback face — so the rebuild silently loses the source's type rhythm, which is the thing the forge exists to preserve.

## 2. Standards researched

- **Format/quality mapping.** Malte Ubl's measured JPEG→next-gen equivalence (`industrialempathy.com/posts/avif-webp-quality-settings`): JPEG 80 ≈ WebP 82 ≈ AVIF 64; JPEG 70 ≈ WebP 72 ≈ AVIF 56. AVIF is ~36% smaller than the equivalent JPEG, WebP ~15%.
- **AVIF encode cost.** sharp's default AVIF effort (4) is ~5x slower than effort 3 for ~4% bytes. For a build-time pass over a few dozen files that is affordable but not free; WebP is the better default, AVIF an opt-in.
- **CSS backgrounds.** `image-set()` with `type()` is the standard negotiation for background images (plain `url()` first as the fallback), because a CSS background cannot use `<picture>`.
- **Web fonts.** Self-host woff2 only, `font-display: swap`, keep Google's `unicode-range` subsets, preload at most the critical weight. Removes the cross-origin chain and the failure mode above. Licensing gates this: Rubik is SIL OFL 1.1, so self-hosting is permitted and the forge's own `adaptation.replace` rule ("licensed equivalents unless verifiably self-hostable") is satisfied.
- **CLS.** The browser reserves space from the `<img>` `width`/`height` **attributes**, before CSS applies; `aspect-ratio` in CSS does not help the first paint. Attributes lose to any CSS rule, so injecting them never fights the design.
- **Astro 7.** `<Image>` exposes `layout` (`constrained|fixed|full-width|none`), `widths`, `sizes`, `inferSize`, `fit`, `position`. `SmartImage` already uses `inferSize` + `widths`, so content images were never the problem.

## 3. Design

Five changes, all inside the post-build pass so they cannot affect render-time markup:

### 3.1 Re-encode raster media (§1)

After a download, if the extension is in `REENCODE_EXTENSIONS` (`jpg/jpeg/png/bmp/tif/tiff`), encode WebP at `NVOOS_FORGE_MEDIA_WEBP_QUALITY` (default **82**) and ship it **only when smaller**. A source that will not shrink is kept and reported under `unoptimized` — the source image is a legitimate outcome, never an error.

Excluded on purpose: `svg` (vector), `ico` (icon), `gif` (re-encoding drops animation), `webp`/`avif` (already next-gen), and anything `sharp` cannot read (fail-soft).

Consistency note: the template already ships WebP-only for content images (`<Image>` emits a single `<img>` in the optimised format, not a `<picture>` chain), so background and chrome images following the same rule adds no new compatibility risk.

### 3.2 Explicit dimensions (§2)

While mirroring, record each file's intrinsic size — `sharp` metadata for raster, `viewBox`/`width`/`height` for SVG — keyed by the emitted public path. After the URL rewrite, `addImageDimensions()` fills `width`/`height` on any `<img>` that carries neither. Runs in `applyRewrite`, so it sees the local path the tag now holds.

This covers exactly the elements Astro cannot: chrome logos, body-content images rendered via `set:html`, collage photos.

### 3.3 Self-host webfonts (§3)

`fonts.googleapis.com` / `fonts.gstatic.com` become localization candidates when `NVOOS_FORGE_LOCALIZE_FONTS` is not `0`. The existing stylesheet machinery already fetches external sheets and rewrites their `url()`s, and `rewriteHtml` already rewrites `<link rel="stylesheet" href>` — so the Google CSS is mirrored to `/media/<hash>-css`, its woff2 files to `/media/<hash>-woff2`, and the HTML link is rewritten. The downloader already sends a modern Chrome UA, which is what makes Google serve woff2 rather than TTF.

The hosts are a **separate opt-in list** (`webfontHosts`), not an addition to `media.hosts`: those hosts never serve site media, so accepting them cannot widen the image allowlist.

Fail-soft, as always: if the fetch fails the remote `<link>` survives, which is exactly today's behaviour.

### 3.4 `font-display` on every mirrored sheet (§4)

`addFontDisplaySwap()` injects `font-display: swap` into any `@font-face` block that does not declare it, at stylesheet-emit time. Applied to every mirrored sheet, so the theme icon font (which shipped without it and blocked 129 KB of text painting) is covered. A sheet that already declares a value keeps it — `optional` is a deliberate choice, not a gap. `@font-face` cannot nest, so a `[^}]*` block match is exact.

### 3.5 The media budget gate (§5)

`forge verify` gains a `media budget` gate that reads `.forge-cache/media-localize.json` — the report the pass already writes — and asserts the artifact ships no JPEG/PNG it could have re-encoded, reporting images shipped and bytes saved. `skip` when the report is absent (proxy mode, or localization off).

**Warn by default, fail under `--strict-media`.** A source image that does not shrink is a legitimate outcome, so this must not block a deploy; but it must be visible, or the win silently rots.

## 4. Profile surface

`media.mode?: 'local' | 'proxy' | 'off'` joins the profile schema. Precedence in `resolveMode`: `NVOOS_FORGE_MEDIA_MODE` env → `profile.media.mode` → runtime default (`managed` → proxy, else local).

This is what lets a **managed** site (bridge present, so the default is the same-origin `/media` proxy) opt into a fully self-contained artifact without a per-deploy env var:

```json
"deploy": { "runtime": "managed", "publicOrigin": "https://example.com" },
"media": { "localize": true, "mode": "local" }
```

Trade-off, stated plainly: `local` duplicates the media library into every deploy; `proxy` keeps one copy and depends on the bridge. Both are now a one-line choice.

## 5. Environment

| Variable | Default | Effect |
|---|---|---|
| `NVOOS_FORGE_MEDIA_WEBP_QUALITY` | `82` | WebP quality (`0` disables re-encoding) |
| `NVOOS_FORGE_LOCALIZE_FONTS` | on | Self-host the Google Fonts CSS + woff2 (`0` keeps the link) |
| `NVOOS_FORGE_MEDIA_MODE` | — | Overrides `profile.media.mode` |

`sharp` becomes an explicit dependency of the template (it was already transitive through Astro).

## 6. Gotchas

- **Do not resize.** Re-encoding keeps intrinsic dimensions, so `widths`/`sizes` and CSS backgrounds stay correct. Resizing a CSS background or a `100vw` hero without knowing its slot is how you get blurry full-bleed heroes.
- **Unquoted `url()`.** Inline `style="…"` attributes truncate on a nested quote — the rule Proposal 139 already learned.
- **Attribute injection after rewrite.** Dimension fill must key on the local path, so it runs *after* the URL rewrite, not before.
- **`@font-face` matching.** Only safe because `@font-face` cannot nest; do not reuse that regex for arbitrary at-rules.
- **Webfont hosts are not media hosts.** Keep them separate or `localizeAll` semantics drift.
- **The gate reads the report, not the filesystem.** `dist/` also contains Astro-optimised `_astro/*` output; counting files would double-count.

## 7. Validation

- `npm run typecheck` — clean.
- `npx vitest run --exclude tests/forge-e2e.test.ts` — **910 passing** (was 901; +9 for the new helpers).
- `tests/scaffold.test.ts` — 82 passing (`--testTimeout=60000`: the default 5 s elapses on a loaded Windows box, pre-existing).
- Expected effect on jayainc: the three JPEGs (619 KB) become WebP at roughly 290 KB — the 327 KB GTmetrix win — and the page stops chaining to Google Fonts.
