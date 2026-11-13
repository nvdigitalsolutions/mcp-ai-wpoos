# Proposal 147 — image lightbox for rebuilt galleries, carousels and image links

> Status: **Proposed 2026-11-13**. Not implemented.
> Extends 114 (design rebuild — the `Gallery` component), 137 (builder bands /
> raw `.post-body` markup), 139 (media localization), 140 (media budget),
> 142 (the no-vendor-JS interaction pattern).
> Origin: virtuallyjamaica.com — section `cd02263`, the 7-slide Brando/WPBakery
> image carousel.

---

## 1. What is actually there today (measured, not assumed)

Section `cd02263` is a **raw `.post-body` band** (not a semantic `gallery`
section): "supported hardware", 7 brand logos in a Brando/WPBakery owl
carousel. It is the page's **only** slider (`querySelectorAll('.owl-carousel,
.ds-carousel, .ds-gallery, .ds-marquee')` → one match).

```
<div id="image-carousel-1" class="owl-carousel … brando-image-carousel">
  <div class="titlelightboxgallery">
    <a href="https://virtuallyjamaica.com/wp-content/uploads/2017/09/9.png"
       class="lightboxgalleryitem" title="9">
      <div class="item padding-five-lr owl-bg-img">
        <img src="/media/4bc8f9356b91-9.webp" srcset="… 400w, … 300w, … 350w"
             sizes="auto, (max-width: 400px) 100vw, 400px" loading="lazy" alt="">
```

| Fact | Value |
| --- | --- |
| Slides | 7 brand logos (Ubiquiti, tp-link, Open-Mesh, LigoWave, Linkeys, …), **all `alt=""`** |
| Slide geometry | `bandFacts.cd02263.carousel`: perView 5 / 2 / 1 at desktop / tablet / mobile |
| Track | `display:flex`, `flex-wrap:nowrap`, `overflow-x:auto` — a swipe strip with scroll-snap |
| Dots | rebuilt by `BuilderCarousel.astro` (the vendor's owl JS is never shipped) |
| Slide markup | an `<a>` wrapping the image — the source's lightbox hook survived sanitisation |
| **`href` target** | **`https://virtuallyjamaica.com/wp-content/uploads/2017/09/9.png`** — the live CMS |
| Image actually loaded | `/media/b0ef57b47aa4-6-300x225.webp` (300×225), rendered at 202px |
| Largest available original | **400×300**, 9–16 KB per logo — the source has nothing bigger |
| Modal | none (`document.querySelectorAll('dialog').length === 0`) |

**Read the resolution row before scoping this feature.** On this band a
lightbox cannot reveal more detail: the slides are 202px, the mirrored 400w
candidate is the largest asset that exists, and the "full-size" original is the
same 400×300. The value here is *staying on the site* and *a real modal*, not
magnification. The resolution argument applies to the photo galleries the same
component serves on other forged sites.

So a click today **leaves the forged site for the source CMS**. Two problems,
only one of which is cosmetic:

1. **It is a dead end after the domain move.** Proposal 135 makes WordPress the
   CMS origin (`cms.client.com`), never the public origin. Every one of these
   anchors then 404s — and `lib/media-localize-lib.mjs` deliberately leaves
   `<a href>` alone ("an image anchor keeps pointing at the original").
2. **There is no full-size view.** The slide shows a 300px WebP in a 202px box;
   the anchor promises a 400×300 PNG from another origin.

The originals here are small (`9.png` = 9.2 KB, `6.png` = 15.8 KB), so this is
not a "we cannot afford the bytes" problem — it is a "the artifact is not
self-contained" problem.

### The source ships a lightbox — this is a fidelity gap, not a new feature

Measured on the live site (2026-11-13, clicking a slide): **Magnific Popup**
opens (`mfp-wrap mfp-gallery mfp-ready`) without leaving the page, and the
rebuild dropped it because a forged site ships no vendor JS — the same class of
gap `BuilderAccordion` and `BuilderCarousel` already close.

| Source behaviour | Measured value |
| --- | --- |
| Overlay | full-screen light/white (`mfp-bg`), page scroll locked (`html{overflow:hidden}`) |
| Image | the full-size anchor target at natural size (400×300 for these logos) |
| Gallery | prev/next arrows, arrow ink `rgb(98,98,98)` |
| Counter | `1 of 7` (`mfp-counter`) |
| Caption | the anchor's `title` — literally `"6"` / `"9"`; useless copy, but it is what the source shows |
| Close | solid `#000` square, `×` in `#fff`, top-right |

---

## 2. Decision

Add **one** progressive-enhancement component, `Lightbox.astro`, mounted once in
`Base.astro` next to `BuilderAccordion` / `BuilderCarousel`:

- **Native `<dialog>` + `showModal()`.** Free focus trap, `Escape`, `::backdrop`,
  background inertness and focus restoration to the invoker. This is the house
  pattern — `FaqAccordion` uses `<details name>`, `Tabs` uses radio inputs; a
  lightbox is `<dialog>`, not a library.
- **No dependency, no island, no third-party script.** CSP stays
  `script-src 'self'`; the component's `<script>` is hoisted like the other two.
- **The `<a>` stays the no-JS floor.** JS off → same-tab navigation to the
  image. JS on → `event.preventDefault()` and the dialog opens.
- **The displayed source is the largest mirrored candidate**, resolved from the
  trigger's own `srcset` (falling back to `currentSrc`, then `href`). Never a
  network fetch to the CMS at click time.
- **Enabled by default for image anchors**, because the source ships it on this
  exact band (measured above). Config is one small block:

```json
"lightbox": { "enabled": true, "exclude": [ "cd02263" ] }
```

`exclude` is per section id and exists for the judgement call, not the default:
the source lightboxes this logo strip, so **fidelity says leave it on**. A client
who finds a modal on brand marks noisy can name the band here.

### Scope — what gets a lightbox

| Surface | Lightbox | Why |
| --- | --- | --- |
| `a[href]` wrapping an `<img>`, href ends in an image extension | **yes** | the source's own lightbox hook — this is `cd02263` |
| `.ds-gallery` items (`Gallery.astro`) | **yes**, after wrapping each item in `<a href>` at render time | the component currently emits bare `<img>` — no baseline without JS |
| `.owl-carousel` slides with **no** anchor | **yes**, after the same render-time wrap | measured carousels are galleries |
| `Hero.astro` collage / background photo | **no** | the band is the composition; a modal on the hero is disorienting |
| `.ds-marquee` (logo/label strip) | **no** | decorative; the duplicate track is `aria-hidden` |
| plain `.post-body img` with no anchor | **no** | a content image that is inert today must not start hijacking clicks |
| any image with `data-no-lightbox` | **no** | explicit operator opt-out |
| a band listed in `profile.lightbox.exclude` | **no** | per-band opt-out; `cd02263` is the candidate — a logo strip rarely earns a modal |

---

## 3. The `href` repair (the part that touches 139)

Proposal 139's rule is "never rewrite `<a href>`" and the current
implementation says so in code:

```js
// Documents (Proposal 139 §documents) — only when the href is a DOCUMENT
// this build mirrored (an image anchor keeps pointing at the original)
```

That rule exists for good reasons (SEO, `og:image`, JSON-LD, the catalogue
PDF). An **image** anchor is a different case, and it is the one that breaks.

Two options, and the proposal takes the cheaper one first:

**P1 — client-side only (no build change).** The lightbox reads the largest
candidate out of the trigger's `srcset`. Works for every JS visitor, costs no
bytes. The anchor itself still points at the CMS → no-JS users and crawlers
still leave.

**P2 — repair the anchor too.** Two small edits, both narrow:

1. `rewriteHtml()` (`templates/blog/scripts/media-localize-lib.mjs`): an
   `<a href>` whose path has an **image** extension *and* is present in the
   rewrite map gets the local path — the same guard the document branch already
   uses (`map.get(path)`), so an unmirrored URL is never touched and documents
   keep their current behaviour.
2. The collector mirrors that URL **only when** it is already reachable through
   the page (the same attachment is on the page as an `<img>`/`srcset`
   candidate) **or** `profile.media.lightboxOriginals: true` is set — the
   opt-in for sites whose originals are genuinely larger, subject to the 140
   caps (`NVOOS_FORGE_MEDIA_MAX_FILE_MB`, the build-wide budget).

Default for virtuallyjamaica: its "original" is 400×300 and the largest mirrored
candidate is 400w — **the same image**. P2 therefore adds zero bytes here and
fixes the dead link.

---

## 4. Accessibility contract (pin it, it is the whole argument for `<dialog>`)

- One `<dialog>` for the page (not one per image): content swapped on open.
- Accessible name: `aria-labelledby` → the caption, or `aria-label="Image 3 of 7"`.
- Caption source: `title` on the anchor (the source carries `title="9"`) →
  `figcaption` if the band has one → `alt`. Note the slides ship `alt=""`
  (decorative), so the counter is the reliable label.
- `Escape` closes (native); focus returns to the invoking `<a>` (native).
- Arrow keys / `Home` / `End` move within the **group** (the band's gallery),
  and the dialog is `aria-live="off"` — the label carries position, never a
  live region.
- `::backdrop` + `html { overflow: hidden }` while open (some browsers still
  scroll the page under a modal dialog).
- `prefers-reduced-motion` drops the fade; no autoplay, no zoom, no pan.
- The axe e2e gate (Proposal 126 cluster B) runs with the dialog **open** — a
  closed-only pass proves nothing.

## 5. Interaction conflict: the carousel is a scroller

`BuilderCarousel` makes the track horizontally scrollable. A swipe ends in a
`click` on the slide's anchor, which would open the lightbox after every scroll:

- record `pointerdown` coordinates, cancel if the pointer moved **> 10 px**
  before `pointerup` (a drag, not a tap);
- never intercept modified clicks (`Meta` / `Ctrl` / `Shift` / `Alt`, middle
  button, `target="_blank"`) — the browser's own behaviour is the floor;
- keep `preventDefault()` **after** the drag guard, not before.

## 6. Phases

| Phase | Scope | Files | Exit gate |
| --- | --- | --- | --- |
| P1 | the component + Base mount | `templates/blog/src/components/Lightbox.astro`, `layouts/Base.astro`, `styles/global.css` (dialog + backdrop, first-party) | unit: markup/labels; browser e2e: open, Escape, arrows, focus return, drag-cancel; axe with the dialog open |
| P2 | the anchor repair | `scripts/media-localize-lib.mjs` (`rewriteHtml`), the collector gate | unit: image href rewritten when mapped, untouched when not, documents unchanged; e2e asserts the built `cd02263` anchors are same-origin |
| P3 | semantic galleries | `Gallery.astro` (+ `.owl-carousel` wrap where unlinked) | e2e: gallery item opens the dialog; no-JS floor is a working `<a>` |
| P4 | docs | `reference/` note + skill gotcha | — |

P1 is useful on its own and ships nothing risky: it cannot change layout, SEO or
the no-JS behaviour (a click still navigates).

## 7. Non-goals

- Zoom / pinch / pan, EXIF rotation, video, iframes.
- A third-party lightbox (PhotoSwipe, GLightbox, Fancybox) — one component with
  a native element beats 30 KB of vendor JS behind a CSP that then has to allow
  it.
- Preloading neighbours, thumbnail filmstrips, deep links (`#image-3`).
- Lightboxing the hero or the marquee.
- Replacing the measured carousel: `BuilderCarousel` keeps pagination; the
  lightbox only handles activation.

## 8. Risks

| Risk | Mitigation |
| --- | --- |
| A click that used to navigate now opens a modal | the anchor remains the floor; modified clicks are untouched; `data-no-lightbox` opt-out |
| Carousel swipe opens the lightbox | the 10 px drag guard, tested in the browser |
| `showModal()` on a band inside `overflow: clip` (the 120 full-bleed) still paints in the top layer | `dialog` is top-layer by spec — verify in the e2e, it is exactly the kind of thing CSS review misses |
| An unnamed `<dialog>` is announced as just "dialog", with no context | `aria-labelledby` on every open + a real `<button>` close control, not a click-anywhere backdrop |
| The anchor repair touches a surface 139 froze | it is gated on `map.get()` + an image extension; documents, `<meta>` and JSON-LD stay untouched and are covered by the existing media tests |
