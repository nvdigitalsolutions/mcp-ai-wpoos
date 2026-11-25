# Measured chrome, the band list, and word fidelity (2026-11-23)

Three failures from the eca.directory rebuild (a container-only Elementor 3.16+
site, gated) that no earlier gate caught. Each is now a rule.

## 1. Chrome surfaces and ink are MEASURED, not harvested

`analyzeChrome` reads DECLARATIONS out of the stylesheets, and a declaration can
belong to a layer or a variant the page never paints. The browser disagreed with
the harvest on **every** chrome surface for eca.directory:

| fact | harvest | measured |
|---|---|---|
| `headerBg` | `#d30c5c` (a Kadence customizer colour nothing renders) | `#f7f7f7` |
| `footerBarBg` | `#d30c5c` | `#f7f7f7` |
| `footerInk` | dropped; `footerBarInk` = the link accent | `#747474` |
| `navInk` | `#000000` on a `--design`-only refresh | `#27104a` |
| header CTA pills | `#d9534f` (a foreign `--e-global-color-primary`) | `#8764a0` / `#27104a` |

- **`applyChromeSurfaces(chrome, measuredColours, { preserve })`** (`lib/region-colors.ts`)
  copies the measured header/footer surface + dominant ink onto the chrome
  facts, normalised to `#rrggbb` (the token/contrast passes parse hex only). A
  transparent header takes the BODY surface — the strip shows the page behind
  it. A region that could not be measured leaves the harvest alone. A pin
  survives through `preserve` and `applyDesignPins` on the merge.
- **`DEFAULT_COLOR_REGIONS`' header/footer now carry `descendantFill: true`.**
  Elementor's `header.elementor-location-header` is transparent and its inner
  `.e-con.e-parent` paints the strip; without the descendant scan the region read
  `transparent` and the colour gate SKIPPED the chrome it exists to protect.
- **The nav ink follows the header palette.** When the harvested `navInk` is
  absent from the header's measured ink palette (ΔE00 > 3), the palette's
  dominant ink wins.
- **The pills are a browser fact too** (`measureHeaderActions`, `lib/geometry.ts`):
  computed fill (an OPAQUE gradient counts, a translucent hover shade does not),
  ink, padding/radius/size/weight, and `hideMobile` — the source hides them at
  phone widths (`elementor-hidden-mobile`), and showing them overflowed the
  header row at 390px. Stored as `profile.chromeActions` (like `stickyHeader`) so
  `reconcileHeader` re-applies it on a later re-forge; the scaffold merges them
  with the icon actions into `headerActions`, and `Base.astro` renders a pill
  when `text` is present (`.header-cta`, `.header-cta--hide-mobile`).
- **`resolveElementRules` must ignore a rule scoped to a DIFFERENT Elementor
  element.** `selector.includes(token)` is per-token, so
  `.elementor-86 .elementor-element.elementor-element-424e0cff .elementor-button`
  "matched" the sibling pill through the shared header class and its colour
  leaked onto both CTAs. Any `elementor-element-<id>` in the selector that the
  element does not carry now skips the rule.
- **A translucent gradient is an OVERLAY, not a fill** (`gradientPaintsSurface`).
  Elementor paints every button `background-color: #8764a0` PLUS
  `background-image: linear-gradient(0deg, rgba(0,0,0,.35), transparent 75%)`;
  taking the gradient shipped a see-through pill. An opaque gradient still wins.
- **A spec the rebuild cannot resolve is dead, not a fact:** a residual `var(`
  in a fill/ink/padding/size is dropped instead of emitted.

## 2. The band list EXCLUDES the header/footer chrome

Elementor renders its `header.elementor-location-header` and
`footer.elementor-location-footer` templates INTO the homepage, but the REST
page body the build consumes excludes them. Counting them as content bands made
the profile carry **9 bands where the build renders 7**, so every POSITIONAL
audit (band flow, per-band text, geometry) compared band N with band N+2 and
failed wholesale — 9/9 bands at 0–2% text coverage, `verify --text` 43%.

Both band walks now skip a boundary inside a chrome container (`header` /
`footer` / `elementor-location-*`): `allSectionNodes` in `lib/design.ts` and
`collectBands` in `lib/bands.ts` (with a test in each). eca.directory: 9 → 7,
and `verify`'s band flow went from 2 failures to PASS.

## 3. Words may never be lost — the per-band fidelity guard

A CONFIDENT kind can still drop the copy it was never shaped for:

- “Explore Our Activities” (a heading + an icon-box + a button) classified as a
  `cta` — which renders title + links only — and lost three paragraphs;
- the team band took the first card's name for the band title and dropped the
  roster; the same for the hero band.

`lib/semantic.ts` now compares the words the RENDERED kind will paint
(`RENDERED_SLOTS`, mirroring the template's design components) against the
band's source words. Under half → the band keeps its RAW slice (`kind: 'text'`,
`slots: {}`, `html`), which the renderer emits inside `.post-body` with the
measured band facts still applied. `sectionHasContent` in `DesignRenderer.astro`
counts `section.html` — without it a demoted band skipped its measured
`min-height` and padding.

Result: page text parity 43% → 78%, and the two demoted bands went from 0–2% to
~100% per-band coverage.

**Known gap:** a demoted band keeps Elementor's `e-con`/`e-child` classes but
never ships the vendor sheet, so its lanes stack. The template carries only the
safe box rule (`.e-con img { max-width: 100% }`) — a guessed direction made the
band 100px TALLER. Closing it needs the measured per-container direction/width
(on eca.directory's team band: 1689 vs the source's 783).

## Card copy, lists and ink by role (v1.2.23, #8223–#8231)

The last fidelity pass moved four rules out of "guess from the palette" and into
"read the element":

- **Footer ink is painted by role, not by palette share (#8223).** A footer's
  link, label, legal and social inks are separate computed roles; picking the
  nth palette entry drifts the moment the source reorders its palette. Resolve
  each role's own colour and paint it.
- **Card copy takes its measured ink (#8224).** A card's headline, body and CTA
  do not inherit the band's ink — measure each element and paint that. A band
  whose copy is right but whose card copy is inherited still fails the visual
  diff.
- **A card's list stays inside the card (#8227).** A `ul`/`ol` inside a card
  must be contained by the card's measured box: a list that overflows changes
  the band's height and the column rhythm. Contain the list, do not clip the
  card.
- **A band reads its real surface and groups its cards (#8231).** The band's
  surface is the measured element (often a wrapper inside the section), and its
  cards belong to the group that lays them out — spacing and columns come from
  the group's measured geometry, not from a per-card guess.
