# Flatsome / UX Builder — the outline-rebuild gap (2026-11-11)

First Flatsome site forged end-to-end (`monicarajiv.com`: a dark one-pager + 5
sub-pages, black page, `#dd3333` bands, Karma/Lato/Dancing Script). Proposal 137
shipped the *analyzer* adapter; the render half still assumed a builder whose
stylesheet ships and whose sections carry nav anchors. Flatsome breaks both.

## 1. What the markup looks like

```html
<section class="section" id="section_150552065">
  <div class="section-bg fill"></div>
  <div class="section-content relative">
    <div class="banner has-hover is-full-height has-parallax">
      <div class="banner-inner fill">
        <div class="banner-bg fill"><img class="bg" src="…"><div class="overlay"></div></div>
        <div class="banner-layers container">
          <div class="text-box banner-layer x50 md-x50 lg-x50 y100 md-y100 lg-y100">
            …h1 / a.button.white.is-outline…
          </div>
        </div>
      </div>
    </div>
    <!-- or: <div class="row row-collapse align-middle"><div class="col large-6 medium-6 small-12">… -->
  </div>
</section>
```

- The hero photo is a **layer `<img>`**, not a `background-image`; the vendor
  sets its geometry with a scoped `<style>` (`#banner-x { padding-top: 600px;
  background-color: #dd3333 }`, `#banner-x .banner-bg img { object-position:
  63% 90% }`) that sanitisation drops.
- The band ids are `section_<digits>`. Underscores make `isAnchorId` reject
  them, so an outline band had **no addressable handle at all**.

## 2. Analyzer: `domId` (shipped)

`BandCandidate.domId` captures the band node's own `id` when it is a valid CSS
identifier, is NOT a nav anchor (`anchorOf`/`anchorWithin` own those), and is
not a site landmark (`main|content|page|wrapper|primary|header|footer|top|
sidebar|site|container|app`). `promoteOutlineBands` maps
`anchor: band.anchor ?? band.domId`, so:

- `--geometry`, `--colors` and `bandColorRegions` address every band with no
  operator pin (measured 4/4 on the first run without pins);
- the renderer emits `id="section_<digits>"` on the wrap — the source's own id,
  so any `#section_…` link and the band's CSS scoping keep meaning.

Before this, each band needed three pins
(`sections.<bandId>.anchor|bgColor|measure`) and they only applied at merge
time — see §8.

### Band ids are STRUCTURAL

The id seed is `adapter:tag:classes:ordinal` — **never the text**. A live band
carries dynamic copy (the doomsday countdown) and two analyses of the same page
produced ids 4 bytes apart, orphaning every `bandFacts`/`geometry`/`colors` key
and every operator pin (`84a6c14` → `84a6c18` mid-session). Flatsome also
re-mints its OWN section ids across long intervals, so `domId` is a handle for
ONE run, not a durable key — the band id is the durable key.

**Per-band `--text` now works for outline sites:** `analyze --text` passes
`selectors` (band id → anchor/domId) into `textBaselineFromHtml`, and
`extractSectionTexts` matches the alias. The per-band text gate then runs
(3 bands, 1 skipped for the text-less hero). `band-audit` also parses each built
band with `textFromHtml` instead of a tag regex — a regex left `&amp;` encoded
and reported 80 % coverage on byte-identical text.

## 3. Render: the Flatsome shim

The vendor sheet never ships, so the template carries the arrangement rules
(`templates/blog/src/styles/global.css`, "Builder layout shim — Flatsome"):

- `.post-body.ds-section` is a flex column with `min-height: inherit`; the
  `section` and `.section-content` grow, so a banner can fill the measured band.
- `.banner` = the band's full-bleed stage **only as
  `.section-content > .banner`** (`width:100vw; margin-left:50%;
  transform:translateX(-50%)`). A banner **inside a column** is that column's
  media panel and gets `aspect-ratio: 1/1` (the dropped `padding-top: 100%`).
- `.banner-bg` is `position:absolute; inset:0`; its `img` is
  `width/height:100%; object-fit:cover`; `.overlay` is absolute.
- `.banner-inner` MUST be `position:absolute; inset:0` or it collapses to 0 and
  every `top:50%` layer resolves to 0 and renders **above** the band.
- `.banner-layer[class~=x50|y50|y100]` restore `left/top` + the translate pair
  (without them the copy stacked *under* the banner's 600px padding: an 835px
  band instead of 600).
- `.col[class~=small|medium|large-N]` = the 12-column spans (narrow→wide order,
  `[class~=…]` whole-token so `large-1` never matches `large-10`).
- `.row.row-collapse { gap: 0 }`, `.row.align-middle { align-items: center }`.
- A `.slider` is a **one-slide stage**: hide every child but the first and make
  the slide's banner layer static, or a testimonial carousel renders as a
  7,248px column.

Result: band heights matched the source exactly (900 / 600 / 1800 / 900).

## 4. Band facts are PER PAGE (`analyze --pages`)

`sections.generated.ts` is positional for outline sites, so the front page's
`minHeight`/`padding`/typography were applied to every sub-page — a doomsday
article rendered as a 7,497px band. Two fixes shipped:

1. `DesignRenderer.bandGeometry` (default false): a sub-page keeps the SURFACE
   (bg/bgImage/overlay/anchor) positionally and drops geometry. `index.astro`
   passes it.
2. **`analyze --pages [--pages-limit N]`** (implies `--geometry`) measures every
   page of the `pages` collection the way the homepage is measured — bands from
   the rendered HTML, boxes at 3 viewports, presentation facts, and the band's
   own surface — into `profile.pageBands[slug]`. The scaffold bakes
   `src/lib/pages.generated.ts` (one positional `SectionTheme[]` per slug) and
   `[slug].astro` passes `bandThemes={pageBands[slug]}`; the renderer applies
   that list positionally (id match first, then index).

Measured on this site: doomsday 900/684, airport-transfer 900/535/766/793/420,
sri-lanka 960/2106 — each band's own box, and the pink `#f1dfdc` band carried
through. `accommodations` keeps four content-taller bands (image galleries
render rows the source cropped away): per-page geometry failures are reported
ADVISORY (`--strict-pages` makes them fail) because a floor that content
exceeds is not a missing fact.

### Surface capture for page bands

The per-page pass reads each band's surface with `measureRegionColors`
(`descendantFill`) and applies it with `applyBandSurfaces` (also used by
`analyze --colors` for the homepage's own bands). Rules learned here:

- a builder paints the band BG on a CHILD (`.section-bg`, `.banner`), so the
  ancestor walk reports the page body for every band — the fill is the largest
  OPAQUE descendant box (≥60 % of the band width);
- **alpha is the FOURTH `rgb()` component, never the last number.** The original
  regex `/rgba?\([^)]*,\s*([0-9.]+)\s*\)/` captured the BLUE channel of
  `rgb(r, g, b)`, so `rgb(0,0,0)` (every black surface) and `rgb(255,255,0)`
  read as translucent and were skipped. Parse `rgba?(...)`, split on `,` and
  `/`, and take `parts[3]` only when it exists (`alphaOf`);
- a FILL is opaque: `rgba(0,0,0,0.5)` is the photo hero's overlay, not its
  surface. Taking it painted every hero black. Require alpha ≥ 0.95. Alpha 0 is
  "no colour" — fall through to the image check instead of skipping the node;
- **gradients are now captured** (`RegionColors.image`) and wired to
  `ForgeSection.bgGradient` by `applyBandSurfaces`. Only fully opaque gradients
  qualify (a fade to `rgba(0,0,0,0)` is a tint, like a translucent colour).
  `GRADIENT_SURFACE_RE` covers `linear`/`radial`/`conic` and the `repeating-`
  forms. Renderer/scaffold needed no change: `resolveSectionTheme` classifies
  from the last stop and `renderSectionThemeEntry` emits `bg`;
- a **photo** `url()` is CAPTURED but NOT wired. `bgImage` makes
  `resolveSectionTheme` return `hero`, which is wrong for a content band;
- **two half-width columns paint the band between them** with no single layer
  reaching 60 % (Flatsome's 2-col banner row). When the largest fill is narrower
  than 60 %, accept its colour if same-coloured siblings TILE ≥90 % of the band
  width. Without this the fallback read the page canvas and the colour gate
  failed a band the operator had pinned correctly;
- **the ancestor walk stops at the PAGE CANVAS**: `<html>` always, and any
  ancestor more than 2× the region's area. Flatsome paints `#main`/`#wrapper`
  `#000` so page transitions never flash; deriving the surface from them
  reported `#000000` for a transparent header over a hero and for every
  transparent band (2026-11-12). A wrapper of similar height (a `.wrap` 1.1× the
  band) still resolves;
- a band whose OWN background is opaque outranks its descendants. Doomsday's
  article band is `background: rgb(0,0,0)` with six `#dd3333` link cards inside:
  the band is black. Before the alpha fix the cards won and it read red;
- **`verify --colors` measures a built band with `descendantFill` too**, or the
  two sides are not comparable: the rebuild paints `background:` on the
  `.ds-section-wrap`, a child layer the non-descendant reading never inspects.
  The colour metric also gates the painted `image` (`metric: 'image'`): a source
  gradient with no built gradient FAILS, a differing gradient WARNS (serialisers
  legitimately vary), and a photo `url()` is never compared because the media
  localizer rewrites it.

`applyBandSurfaces` never overwrites an existing `bgColor`/`bgGradient`/`bgImage`,
so an operator pin survives re-analysis. A measured gradient beside a pinned
`bgColor` is intentional: `resolveSectionTheme` reads `bgGradient` first, the
same precedence `analyzeSections` already uses for Elementor.

### `min-height` and padding double-count

The band's inner `.ds-section` used `min-height: inherit`, so a 535px source
band with 31px wrap padding rendered 597. The wrap is now a flex column and the
inner GROWS (`flex: 1; min-height: 0`), so the total equals the measured box.
Do not remove the `display: flex; flex-direction: column` on the inner — without
it every `.section` collapses to 0 and the hero photo disappears.

## 5. Sticky headers that clone themselves

Flatsome's `sticky-jump` does **not** change the `<header>`: it injects a fixed
`.header-wrapper.stuck` clone (70px, `.header-bg-color` paints `#0a0a0a`) in a
different subtree, while the original stays `position:absolute` and loses the
`transparent` class. `measureStickyHeader` now:

- scans header-ish nodes after a scroll for a `fixed|sticky` element at the top
  that was NOT fixed at rest (`fixedAtRest` set) — that is the pinned strip;
- reads the strip's **painted child fill** for `pinnedBg` (both the header and
  its wrapper are transparent), the strip's height for `pinnedHeight`, and the
  most frequent text colour among its text-bearing nodes for ink (the first
  anchor is an empty `.banner-link` computing the accent red);
- keeps `restHeight` out when the strip is a clone (`restHeight` must describe
  the header the template renders, not the source wrapper that includes the
  topbar).

Rebuild: `.site-header--overlay.site-header--pinned` sits `position:absolute;
top:auto; left/right:0` (static position = under the topbar, out of flow, over
the hero) and switches to `position:fixed; top:0` with a **discrete scroll
timeline** (`ds-header-position`, composed with `ds-header-pin`). `top:auto` is
load-bearing — the sticky base rule's `top:0` otherwise pins the absolute header
over the topbar.

## 6. Region ink must be the RENDERED ink

Builder themes set `body { color: #000 }` and paint the visible words white
from a context class (`.dark`). `measureRegionColors` read the region element's
inherited `color`, so the source baseline said `#000` while the build (a
token-driven theme) said `#fff`: 7 ΔE 100 failures on a visually correct build.
It now counts the most frequent colour among text-bearing descendants (bounded
to 1200 nodes) and returns `transparent` for a region with **no text** (the
hero band is image + chevron) so the ΔE comparison skips it.

## 7. Photo crop (`object-position`) and pixel parity

The source's crop anchor lives in a `<style>` block sanitisation drops
(`#banner-x .banner-bg img { object-position: 63% 90% }`). `analyze --geometry`
now records it per band as `bandFacts.mediaPosition` (the most common
`objectPosition` among the band's `object-fit: cover` images, default 50 % 50 %
never recorded), the scaffold emits `--ds-media-pos`, and the Flatsome shim
applies `object-position: var(--ds-media-pos, 50% 50%)`. The built hero now
crops exactly like the source.

Pixel parity STILL fails (≈70 % differing pixels, SSIM 0.34): the media
localizer re-encodes to WebP, so every photo pixel differs by a few units — a
strict pixel gate is unreachable on a photo-heavy site even with the crop right.
Record it as policy, not a failure:

```json
"verify": { "gates": { "pixel parity": "advisory" },
            "pixel": { "masks": [".site-header", ".ux-timer"] } }
```

`verify --pixel-record-source` FIRST (writes `verify-artifacts/source-*.png`),
then `verify --pixel`. A build-vs-build comparison is `pixel regression`, never
parity. Capturing `object-position` as a band fact is the real fix — open.

## 8. Token block and pipeline traps

- **A cropped-image height must outrank the shim.** `[data-section-id] img.<cls>`
  (0,2,1) loses to `.ds-section-wrap .post-body .banner-bg > img { height:100% }`
  (0,3,1), so the renderer also emits the `.post-body img.<cls>` variant. A
  measured 420px crop then wins the tie via the inline page `<style>`.
- **Copying the template's `global.css` over a project wipes `--forge-*`.** Only
  the scaffold pipeline rewrites the `FORGE:tokens` span; the build then renders
  template defaults (white body, system font). New:
  `forge tokens <profile> --target <dir>` re-applies just that span
  (`--check` reports drift, exit 1). No `node_modules` reinstall.
- **`analyze --design --geometry` in ONE pass**: pins are now applied to the
  fresh design *before* the measurement passes (`applyWritePins`), so a pinned
  band anchor is addressable in the same run. Applying pins only at merge time
  measured 0 bands.
- **A stub design must never replace an analyzed one**: the nav-toggle pass
  writes `{ chrome: { navToggle } }` when `--design` is absent, and
  `mergeProfileDraft` used to treat that as fresh. It now requires a real block
  (`mode`, or sections, or outline).
- **In-place re-scaffold (no `--overwrite`) still replaces the directory** —
  `node_modules` is gone; reinstall. A detached `astro preview` holds the target
  (`EPERM`/`EBUSY` in `swapStagedProject`) — kill the PID on the port first.
- **Full-page screenshots don't load lazy images or trigger scroll animations.**
  Scroll the page in ~700px steps, wait, then return to top before capturing.

## 9. Measured result (this site)

Parity 80% · token block ✓ · band flow 4/4 · facts ✓ · media 41 images/3.5MB +
2 docs · text 81.2% (Cloudflare-obfuscated email caps it; the template's new
skip link recovered ~2 points) · colour 0 drift (9 compared surfaces × 2
schemes — band surfaces are gated now, not just chrome) · tracking ✓ · SEO
1 warn (placeholder page) · geometry 0 fail · interactions 13/13 (3 bands) ·
pixel advisory · 5 inert classes of 93. Scorecard: **10/14 applicable gates
pass, 3 warn, 1 skipped** (no pixel baseline).
