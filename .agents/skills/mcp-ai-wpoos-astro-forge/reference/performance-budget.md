# Forge performance budget (Proposal 140)

The media localizer (Proposal 139) makes a forged site self-contained. This
layer makes it *fast*: what GTmetrix flagged on the first production build
(`jayainc.com`: 862 KB, 706 KB of it images, 327 KB of it three un-optimised
WordPress JPEGs, plus a Google Fonts stylesheet that **failed outright** in the
audit and dropped the source's type rhythm).

## What the pass now does

| Step | Rule |
|---|---|
| Re-encode | Raster mirrors (`jpg/jpeg/png/bmp/tif/tiff`) → WebP at **q82**, kept **only when smaller**. A source that will not shrink ships as-is and is reported, never failed. |
| Dimensions | Every `<img>` the pass touched gains intrinsic `width`/`height` (`sharp` metadata; `viewBox` for SVG). Attributes — not CSS `aspect-ratio` — reserve the first paint. |
| Webfonts | Google's CSS + woff2 mirrored; `<link>` rewritten to `/media/<hash>.css`. |
| `font-display` | `swap` injected into any mirrored `@font-face` that lacks one. |
| Preconnects | Hints to a host we now serve are stripped (`stripPreconnects`). |
| Gate | `forge verify` prints a `media budget` gate: images shipped, bytes saved, documents by weight. Warn by default; `--strict-media` fails. |

Quality reference: Malte Ubl's measured equivalence
(`industrialempathy.com/posts/avif-webp-quality-settings`) — JPEG 80 ≈ WebP 82
≈ AVIF 64. WebP is the default because it is ~97% supported and ~15% smaller;
AVIF is ~36% smaller but ~5× the encode time at sharp's default effort.

## Modes

`profile.media.mode` sits between the env override and the runtime default:

```
NVOOS_FORGE_MEDIA_MODE  >  profile.media.mode  >  runtime default
                                                 (managed → proxy, else local)
```

- `local` — mirror every candidate into `dist/media`. Self-contained.
- `proxy` — managed tier: uploads are rewritten to the runtime's same-origin
  `/media/<rest>` (no download). **Stylesheets and the fonts they reference are
  still mirrored**, because the proxy only matches uploads; leaving the font
  chain remote kept a render-blocking cross-origin hop in a "self-contained"
  artifact (and the old early return paid for a download it then discarded).
- `off` — nothing.

A managed site that wants a fully self-contained artifact:

```json
"media": { "localize": true, "mode": "local" }
```

Trade-off: `local` duplicates the library into every deploy; `proxy` keeps one
copy and depends on the bridge.

## Environment

| Variable | Default | Effect |
|---|---|---|
| `NVOOS_FORGE_MEDIA_MODE` | — | Overrides `profile.media.mode` |
| `NVOOS_FORGE_LOCALIZE_MEDIA` | on | `0` disables the pass |
| `NVOOS_FORGE_MEDIA_WEBP_QUALITY` | `82` | WebP quality |
| `NVOOS_FORGE_LOCALIZE_FONTS` | on | `0` keeps the remote Google `<link>` |
| `NVOOS_FORGE_MEDIA_HOSTS` | — | Extra mirrorable hosts |
| `NVOOS_FORGE_MEDIA_MAX_MB` / `_MAX_TOTAL_MB` | 25 / 500 | Caps |
| `NVOOS_FORGE_MEDIA_MAX_DOC_MB` | 50 | Per-document cap |
| `NVOOS_FORGE_MEDIA_TIMEOUT_MS` | 20000 | Per-request timeout |

## Gotchas

- **Never resize.** Re-encoding preserves intrinsic dimensions, so `widths`/`sizes`
  and CSS backgrounds stay correct. Resizing an unknown slot blurs full-bleed heroes.
- **Order matters.** Document-extension detection (139) decides the extension
  first; the WebP step layers after it. Dimension fill runs *after* the URL
  rewrite, keyed on the local path.
- **Never hand a non-image to `sharp`.** PDFs, fonts and stylesheets have no
  `<img>`; `intrinsicSize` returns early unless the extension is in
  `IMAGE_EXTENSIONS`.
- **`@font-face` cannot nest**, which is the only reason a `[^}]*` block match
  is exact. Do not reuse that regex for other at-rules.
- **Webfont hosts are not media hosts** — a separate `webfontHosts` list, so the
  image allowlist cannot widen.
- **`url()` stays unquoted** in inline `style="…"` (a nested quote truncates).
- **The gate reads the report**, not `dist/` — `_astro/*` would double-count.
- **Documents are counted, not judged**: a 50 MB catalogue is a deploy-size
  fact, not a budget failure.
