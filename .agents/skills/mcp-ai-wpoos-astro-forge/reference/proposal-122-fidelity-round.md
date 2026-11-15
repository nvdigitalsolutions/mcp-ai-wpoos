# Proposal 122 — fidelity round (2026-11-06)

Split out of `SKILL.md` on 2026-11-12 to keep the skill under Zed's load limit.
Ten jayainc drift reports fixed in one round (PR #7486): section widths, button
shape, the missing services image, the footer. All verified on-screen with
computed styles + pixel sampling before merge.

- **Measure is site, not prose (the width root cause):** the template's
  `--forge-measure: 72ch` (~470px at the rebuilt 13px body) squeezed EVERY
  band into a prose column — the rebuild looked like a blog, not the source.
  `measure` now defaults `75rem` in `DEFAULT_TOKENS` (scaffold.ts) and the
  template. NEVER reintroduce a ch-based measure; site bands are rem-bounded,
  not line-length bounded.
- **Full-bleed centering + motion composition:** `width: 100vw` + single-sided
  `margin-left` sits 7px left (100vw includes the scrollbar). Ship
  `margin-left: 50%; transform: translateX(-50%)` (self-referential, no calc)
  and move the reveal/parallax keyframes off `transform` onto the independent
  `translate`/`scale` properties — a keyframe `transform` REPLACES the centering
  transform (reveals jumped +40px, parallax bands shifted).
  `body { overflow-x: clip }` stays for the scrollbar sliver.
- **Section padding facts (band rhythm):** `sections.generated.ts` now emits
  the source's declared `padding` per band (jayainc: flush bands AND
  100px/125px bands), applied inline via `wrapStyle()`; template `.ds-section`
  default tightened to space-3/space-4. Same-origin CSS per section id is the
  fact source, as in 117.
- **CTA shape harvest (`dominantCtaFillFromHtml`):** new `cta` palette role +
  `design.ctaStyle` (padding/radius). Walks actual `<a>`/`<button>` elements
  (button-ish class filter `button|btn|cta|aux-ac|aux-call|pricing`), resolves
  each property through a specificity-APPROXIMATED cascade — per rule,
  `matchCount * 1000000 + ruleIndex` (the rule matching the MOST of the
  element's classes wins; ties by source order). The old frequency vote let
  base rules (`padding: 12px 23px`, `radius: 5px`) out-vote the real pill rules.
  **Gradient fill ALWAYS beats flat color** — a flat hover/outline color
  (`#ebde68` on jayainc) otherwise wins over the gradient `background-image`
  that is the effective fill (this regressed once and was caught). Emits
  `--forge-cta`, `--forge-cta-ink`, `--forge-cta-pad`, `--forge-cta-radius`.
  jayainc pinned via `profile.tokens`: `cta-pad: "16px 50px"`, `cta-radius:
  "999px"` (the majority size — the source's CALL US is `14px 43px` at 1600px,
  the rest `16px 50px`); yellow gradient `#ffcf0e → #ffb70e`, dark ink
  `#111111` for WCAG (source uses white ink on the yellow).
- **Icon-list split media:** `IconList.astro` renders `slots.media` in a
  `.ds-icon-split` two-column layout — the "Why Choose Us" portrait photo
  (`jaya-2-fix-784x1236.jpg`) returned after being silently dropped.
- **Footer logo/bg + favicon:** `analyzeChrome` captures `footerLogo` (img
  inside the footer/aside scope — jayainc's white PNG), `footerBg` (the
  `.subfooter` terminal rule — jayainc `#f3f3f3`), and `favicon` (first
  `<link rel="icon">`, absolute — `cropped-jaya-inc-icon-32x32`); `Base.astro`
  wires all three.
- **Operator pins survive `analyze --write`:** the tokens merge preserves
  hand-tuned `cta-pad`/`cta-radius`/`measure` keys. Facts INSIDE the analyzed
  `design` block are replaced wholesale, so declare those at the profile's top
  level in `pins` — `{ "path": "sections.5e78df1.marginTop", "value": "170px" }`
  or `{ "path": "chrome.footerInk", "value": "…" }`; `mergeProfileDraft`
  re-applies them (`lib/pins.ts`) and `tests/pins.test.ts` fails when the
  committed profile stops satisfying one. `--fresh` still drops them (it warns).

## §1 — the jayainc round-2 + scroll-gap fixes (2026-11-12)

- **Theme resolves from the CONTENT side of a gradient.** `resolveSectionTheme`
  read the terminal stop, so jayainc's products band
  (`linear-gradient(180deg, #ffffff 6%, #04132b 100%)`) became `dark` and its
  h2 took white ink over a light top. A vertical gradient now resolves from the
  stop the content sits on (`contentSideColor`). A measured `--ds-title-color`
  outranks the band default (`.ds-section-wrap--dark :is(h1..h6)`).
- **The footer logo belongs to a COLUMN, not a strip.** `analyzeChrome` records
  `footerLogo.blockIndex` when the logo shares the first footer block's column
  (jayainc: logo y=5803, paragraph y=5900, same `.aux-widget-area`); `Base.astro`
  renders it inside that `.site-footer-block`.
- **The container width is the CONTENT width.** `bandFacts[id].containerWidth`
  and the per-device `container` fact are the source's inner CONTENT box, so the
  emitter adds the gutter back before using it as `measure`, and a measured
  container outranks the design's theme cap when it is wider. Operator pins win.
- **Icon-row rhythm is per device.** `iconRowStyle.padTop/padBottom/padX` accept
  `ResponsiveLength` maps (jayainc: 63px desktop, 36/50 tablet+mobile) and
  `rowGap` carries the widget margin. Plain strings go inline; maps go to
  `responsiveBandCss` (an inline value would outrank its own media queries).
- **The reveal animates the CONTENT, not the band wrapper.** `.ds-reveal >
  .ds-section` (never the wrap): translating/scaling the wrapper lifted the
  full-bleed background painter and exposed the body as white strips BETWEEN
  bands while scrolling. Desktop gap count is 0 at every scroll position.
- **`profile.pageBackground`** — measured body background (jayainc
  `rgb(229,229,229)`), emitted as `body{background:…}`. Without it the source's
  own band margins render as white strips on a white body.
- **Builder rows have `gap: 0`.** WPBakery/Brando put the gutter in the
  COLUMN's padding; a row gap shrank the columns and opened a seam the live
  site does not have (virtuallyjamaica #wifi: the photo column ends at x=719 and
  the red panel starts there).
- **`scopedDeclarationOf(html, property)`** lets the fact audit read a scoped
  declaration (tokens/padding/measure) instead of only the inline style; the
  `measure` check now reads both channels (it had 7 false "unrendered fact"
  failures).

## §2 — measured level, stage and split (2026-11-12)

Four facts that closed the last virtuallyjamaica/jayainc outline + layout drifts.
All four are browser measurements (`analyze --geometry`) merged into
`profile.bandFacts` and emitted into `sections.generated.ts`.

- **Heading LEVEL (`type.titleLevel`).** `collectBandTypeFacts` already picked
  the band's largest instance per tag; `tsBandType` now also records WHICH tag
  that was. `<SectionTitle>` (`templates/blog/src/components/design/`) renders
  it through `title-tag.ts`, and every title component (Hero, FeatureGrid,
  IconList, TextBlock, SectionHeading, Form, Cta, Gallery, LogoCloud,
  Testimonial) uses it. `h1` maps down to `h2` because the article owns the
  single h1 (the front page renders the source's own h1 inside its first raw
  band). virtuallyjamaica: the contact form's `<h3>` came back as `<h3>`, and
  the page's 18-heading sequence now matches the source 1:1.
- **Full-screen bands (`fullViewport`).** True when the measured band height
  equals THAT device's viewport height (within 2px) at desktop, tablet AND
  mobile. The emitter writes `min-height: 100vh` + `fullViewport: true`. The
  captured px must NOT be re-emitted: 900px is right on a 1440×900 laptop and
  overflows a 1440×800 window where the source still fills the screen.
- **Centring is measured, never assumed (`centered`).** `collectBandLayoutFacts`
  scans the band for the source's own centring DEVICE: a `table-cell` with
  `vertical-align: middle` (Brando's slider) or a flex/grid container ≥50% of
  the band height with `align-items/align-content/place-items: center`
  (Elementor's `e-con`). `true` → `ds-section-wrap--centered` (a `:has(> :only-child)`
  chain centres each single-file wrapper, so the copy track is centred as a
  unit and its own grid is untouched); `false` → `--ds-hero-justify: flex-start`
  on the wrap, which opts a top-aligned band out of the hero's historical
  default. virtuallyjamaica's contact band is top-aligned under 130px of
  padding; centring it moved the copy 151px down. Absent = unmeasured (older
  profiles) → the template default. The fact is recorded `false` explicitly for
  every resolved band, so absence is meaningful.
- **Hero split (`splitColumns`).** The structural track
  (`:is(div, section):has(> div > h1):has(> div > img)`) — the same signature the
  CSS rule uses — has its direct children's widths normalised to fractions
  (`[0.6, 0.4]` on virtuallyjamaica: 691/461 of a 1152 track). Emitted as
  `--ds-split` and consumed by `grid-template-columns`. Two gotchas: (1) the
  children are centred, so their TOPS differ — test side-by-side by the RIGHT
  edge, not the top; (2) a measured split also emits `--ds-split-gap: 0`,
  because the fractions are widths of a gapless track (the source's gutter is
  the image column's padding, already restated by the box facts); keeping the
  heuristic `gap` shrank the copy column to 665 against the source's 691 and
  re-wrapped the H1 to four lines.

**Centred titles and the hero cap.** `.ds-section-wrap--hero .ds-title` capped
all hero titles at 66% above 767px. On a CENTRED heading that box stayed flush
left, so the centred text sat ~200px left of the band centre (the contact
heading measured x=135 w=772 in a 1170 container). Two rules: the cap now reads
`var(--ds-title-max, var(--ds-hero-title-max, 66%))`, and the renderer emits
`--ds-title-max: none` when the measured `align` is `center` with no measured
`max-width`; the band type CSS also emits `margin-inline: auto` with
`text-align: center`, so a capped centred box centres in its parent.

## §3 — the colour gate, made sound (2026-11-12)

`verify --colors` reported 7 "drift" failures on virtuallyjamaica that were all
metric artefacts, and it was blind to two real defects. Both causes are fixed.
Full contract: [`colour-gate.md`](colour-gate.md).

- **Ink is a palette weighted by glyph area, not a colour count.** `readInk`
  counted ELEMENTS, so a lone white caption beat a 2,000-character grey body
  (`band:services` measured `#929292` on a source dominated by a white 24px
  lede). Now every text element contributes `chars × font-size²`, near-identical
  colours merge (ΔE00 ≤ 2), and the top 4 inks by share are recorded as
  `RegionColors.inks`. `text` stays `inks[0]` so older baselines still verify.
- **Every ink ≥ 15% of a region's text is gated.** A single-colour baseline
  scored virtuallyjamaica's four grey service bullets turning WHITE a pass
  (26% of the band's text). Each significant source ink is now matched to the
  build's closest ink and compared on its own ΔE; a lost minority ink fails and
  names its share (`source #929292 (26% of text), build #ffffff`).
- **Class ink is a measured box fact.** The vendor sheet never ships, so
  `ul.medium-gray-text{color:#929292}` was lost and the bullets inherited the
  panel's white. `collectBandBoxFacts` now records `MeasuredBox.color` when a
  class paints a colour it does not inherit (and every instance agrees), and
  `boxDeclarations` appends it — per band, per device, deduped by
  `mergeBoxFacts`. virtuallyjamaica's `#services` now measures white 74% +
  `#929292` 26% on BOTH sides, 201786/71994 mass.
- **Text a region does not contain is not its ink.** jayainc's site-title link
  renders 23px under its hero (a legacy descendant of `header`), so its cyan
  `#1bb0ce` was counted against a header that never shows it — a 29%-of-text
  "drift" no reader could see. `readInk` now requires the node's box to
  intersect the region element's own box. The header measures white 82% +
  `#242424` 18%, matching the screenshot.
- **The `footer` ladder put the bare tag first.** WordPress's
  `footer.entry-meta` (inside the loop, empty) won, so jayainc's
  `footer#sitefooter` was never measured: `transparent` with no ink, and the
  gate silently skipped the chrome it exists to protect. The ladder is now
  specific-first with `footer:not(.entry-meta):not(.post-meta)` before the bare
  tag; jayainc's footer measures `#081a43` / `#ffffff`.
- **`colors <profile> [--url <host>] [--write]`** re-records the baseline
  without re-harvesting the site. Needed when the metric changes (every old
  baseline becomes stale) or the source host moves — `jayainc.com` now serves
  the FORGE, `cms.jayainc.com` is the WordPress source.

Result: virtuallyjamaica 24 surface metrics, jayainc 16 — **0 drift, 0 missing**
on both, with geometry and interaction states unchanged.
