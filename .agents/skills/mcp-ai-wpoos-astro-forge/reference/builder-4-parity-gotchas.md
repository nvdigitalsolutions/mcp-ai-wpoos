# Builder-4 parity gotchas (measured on eca.directory, 2026-11-24)

Five defects that a **gated** rebuild of an Elementor 4 site surfaced, each of
which had produced a *confident wrong answer* rather than an error. All five are
generic to builder sources; the ECA day pages are only where they landed.

The profile matters: the first `eca.directory` profile had been captured through
the theme's maintenance holding page, so it carried band outlines and no
geometry, colours or per-page baselines. Every gate below reported itself
skipped (or crashed) until the profile was re-analyzed with
`WP_GATE_USERNAME`/`WP_GATE_PASSWORD` set and `--design --geometry --text
--colors --inventory --vocabulary --pages` all on. 137 KB → 385 KB.

## 1. A CSS identifier cannot start with a digit

`document.querySelector('#6daaaef9')` throws a `SyntaxError`. Builders mint hash
ids constantly, so *any* selector built by string-concatenating `#` onto a
measured id is a latent crash — and one throw inside a `page.evaluate` region
loop aborted **every** colour comparison in the run (reported as
`Colour measurement failed — '#6daaaef9' is not a valid selector`).

Two helpers in `lib/region-colors.ts`:

| Helper | Returns |
|---|---|
| `idSelectors(id)` | `['#about', '[id="about"]']` for a legal identifier, `['[id="6daaaef9"]']` for a hash id, `[]` for empty |
| `cssIdSelector(id)` | the first of the above, or `''` |
| `builtBandSelectors(id)` | `['[data-section-id="6daaaef9"]', …idSelectors(id)]` — **the** way to address a rebuilt band |

Also: a rebuilt band carries **no `id` at all**. The scaffold emits
`<div data-section-id="…" class="ds-section-wrap">`; addressing it by `id`
measured nothing and reported all seven baselines as "dropped". A malformed
selector in the probe must be caught per region, never per run.

## 2. `--ds-measure` is the CONTENT column, not the band's padding box

Elementor 4 containers are `e-con` / `e-con-inner`; the container pool in
`measureBandFacts` looks for `.container`, `.container-fluid`, `.row`,
`.vc_row`, `.wpb_row`. Nothing matched, so `containerInner` was false and
`containerWidth` fell back to *the band's own width minus its padding* — 1380
where the hero's `e-con-inner` is capped at 1280.

Then `measuredContainer()`'s legacy rung added `BAND_GUTTER_PX` (64) on top:
`--ds-measure: 1444px`. The rebuilt copy column was 1380 against the source's
1280 (home) and 1392 against 1328 (day pages).

Fix: the middle rung. `analyze --geometry` records
`geometry[id][device].content.width` — the first descendant narrower than the
band, i.e. exactly the column the copy occupies. Use it when it is between 60 %
and 100 % of the band's inner width; below 60 % it is a *slab* (a partial
painted surface) and constraining the band to it would re-centre something the
source paints flush.

Exact values that worked, per device:

| Page type | desktop | tablet | mobile |
|---|---|---|---|
| home bands | 1280 | 774 | 330 |
| day band | 1328 | 744 | 330 |
| home form band | 1100 | 774 | 330 |

## 3. A state delta must outrank the resting fact

The per-element positional facts re-state the box measured from the source:

```css
[data-section-id="3589820"] .post-body > * > div:nth-child(1) > div:nth-child(1) > div:nth-child(2) > a:nth-child(1){background-color:rgb(135,100,160)}
```

Specificity (0,7,3). The generated `:hover` rule is (0,5,1) — so it existed, was
in the right order, and lost. The rebuilt button kept its resting background on
hover (`el.matches(':hover')` was `true`, the computed colour unchanged).

Rule: **a generated state declaration is `!important`; the resting pass is
not.** `forceState()` in `DesignRenderer.astro` appends it to every declaration
of `hover` / `focus` / `focus-visible` / `active` (control and child). The
resting `fact.base` stays overrideable by the operator.

Debugging note: when a rule "should" win, enumerate the matching rules rather
than reading the sheet. Guard the walk — a `CSSStyleRule` has a (usually empty)
`cssRules` in modern Chromium, so `if (rule.cssRules) walk(...)` skips every
real style rule and returns `[]`.

## 4. The mobile gutter floor must subtract the band's own padding

```css
@media (max-width: 767px) { .ds-section { padding-left: max(var(--forge-space), var(--ds-pad-x, 0px)) } }
```

`--ds-pad-x` is the *container's* gutter; the band's own padding is inline on
the wrapper. A band with a measured 30px band padding and a 0px container
gutter therefore got 30 + 16 = 46px a side: copy 298px inside a 390px phone
against the source's 330.

```css
padding-left: max(calc(var(--forge-space) - var(--ds-band-pad-x, 0px)), var(--ds-pad-x, 0px));
```

`--ds-band-pad-x` is emitted inline by `wrapStyle` from the band's `padding`
shorthand (`bandSidePadding()` — px only; a `rem` value would parse as its bare
number). A band at zero still gets the phone floor.

## 5. A dynamic listing band is DATA, not design

`/tuesday/` (and `/saturday/`, `/junior-school-list/`, `/eca-days/`) are
Elementor pages whose band is a `jet-listing-grid` — 31 activity rows, each a
51px single line: title link, start/end time, venue, year group, a Book button,
a divider. 609 widgets per page.

Replaying those widgets as rebuilt markup produced 5603px against the source's
2312, and 19844 against 6435 on `/junior-school-list/`: the rows became stacked
cards, and the builder's own layout primitives (`e-flex`, `e-con-inner`) never
ship with the raw markup.

Replaying is *always* wrong for a query loop. The preset should classify the
page at analyze time and route it to a product template (see the ECA
implementation plan, "Outstanding — cluster A"). The same failure mode shows up
as cluster B in miniature: a single raw band (`3589820`, a 2-column icon-box
row) stacked its lanes 1180/1180 against the source's 286/954 and rendered 1724
tall against 521.

## Verify numbers, before → after

| Gate | before | after |
|---|---|---|
| colour system | crash | 24 surfaces, 7 drift, **0 missing** |
| page bands | 106 fail | 31 fail |
| box geometry | 15 fail | 6 fail |
| interaction states | 4 fail | **0 fail** |
| text parity | 68.6 % | 68.6 % (accordion band, see below) |

Known content gaps left open: band `77f4271d` (an Elementor toggle/accordion
band whose panel bodies the sanitizer drops, rebuilt as three empty cards — 8.6 %
text coverage) and the band-ink drift where the rebuild's `--ds-ink` outranks the
measured band ink (`#1a1e1d` against `#747474`).

## A footer paints TWO inks — the palette winner is the WORDS, not the titles

The colour gate compares a region ink by ink: every source ink with ≥15 % of the
region's glyph area must exist in the rebuild's palette. A footer routinely has
two, and the mass ranking picks the wrong one for the heading slot.

eca.directory, measured on the live footer:

| ink | share | carried by |
|---|---|---|
| `#747474` | 62 % | `span.elementor-icon-list-text` (the link lists) |
| `#27104a` | 38 % | `div.elementor-heading-title` (block titles) + the wordmark |

`chrome.footerInk` paints the rebuilt `<h3>`s; `chrome.footerBrand.text.ink`
paints the list copy. Writing the palette winner into both greyed every title and
left the rebuild carrying one of the source's two inks — so the gate failed
*both*.

Fix pattern (implemented 2026-11-24): measure `RegionColors.roles = { heading,
body }` by splitting the same `chars × fontSize²` mass on heading-like elements,
then fill each slot from its role. Heading-like is `h1`–`h6` **or** a
`heading`/`title` class at weight ≥ 600 — builders publish headings as `div`s
(Elementor's `elementor-heading-title`), so the tag alone is not enough. Only
write the second slot when the roles differ (ΔE00 > 3), or every single-ink
footer in the fleet gains a redundant fact.

Two traps found on the way:

- **`--force-device-scale-factor=1` is not enough to keep a builder stable.**
  Elementor re-mints `elementor-dcss-…` class hashes per render. A re-analyze
  re-keys `bandFacts.box`/`.classes` with identical values; diff the *values*
  before treating the profile churn as drift.
- **`--colors` alone does not reapply chrome.** `applyChromeSurfaces` is guarded
  by `if ( profile.design )`, and a `--colors`-only run builds a fresh draft with
  no design block. Run `--design --geometry --text --colors …` together, or the
  measured chrome is recorded and never written.

## A band's ink and its CARD's copy are two different facts

A band-level body ink is measured over the whole band. A card inside the band
paints its own surface, so the band ink is only valid for the card when the two
surfaces are the same paint.

The template carries a blanket rule for this:

```css
.ds-section .ds-card p { color: var(--forge-muted); }
```

It is (0,2,1) and therefore outranks the band's own `[data-section-id] p`
(0,1,1). It exists because a band ink harvested from a **dark panel** repainted a
**white card** (virtuallysrilanka). The cost is the mirror case: where the card
IS the band surface, the source's measured ink loses to the `muted` token —
eca.directory's cards were `#747474` in the source and `#69727d` (Elementor's
global text colour) in the rebuild, ΔE00 6.4 on two bands.

Do not fix this with contrast. `#747474` on the card is 4.34:1, and a source's
deliberate low contrast is parity (see the invisible-ink rule above). Fix it with
a **surface** test:

- `contrast(bandSurface, cardSurface) < 1.15` → the ink was measured on the card
  → emit `SectionTheme.cardInk` and scope it as
  `[data-section-id="…"] .ds-card:not(.ds-card--surface) p{color:…}`;
- otherwise → leave the template's `muted`.

Three things to keep out of scope while doing it: a global `--ds-card-ink`, a
token change to `muted`, and deleting the blanket rule. Each one moves every site
in the fleet to fix one band. `:not(.ds-card--surface)` is required — a card that
paints its own measured slab must keep the ink derived from that slab.

## A flex-container band's cards are CONTAINERS, not columns

Elementor 4.x publishes a card grid as nested flex containers, not
`.elementor-column`s:

```
e-con-inner                 (the band)
├─ e-con-boxed.e-child      (intro row)
└─ e-con-full.e-child       (card row)
    └─ e-con-full.e-child   (CARD: heading + icon-box + text-editor
                             + icon-list + button)
```

`columnItems()` looks for `.elementor-container` + `.elementor-column`, so it
finds nothing on these bands, and the flat widget merge sees only widgets: every
h3 caption becomes a bare item and every icon-list `<li>` becomes an item of its
own. eca.directory's phased-delivery band: **20 items for 6 cards**, then
`FeatureGrid` resolved `items.find(columnIndex === lane)` against 3 measured lanes
and drew **three empty cards**.

Two traps found while trying to fix it:

- **Top-down bucket collection takes the ROWS first.** A row of cards is itself a
  repeated child of the band, so grouping "repeated sibling containers" yields
  2 rows where there are 5 cards (and 8 where there are 6). Group bottom-up from
  each widget to its nearest card container instead, so no widget is left
  unassigned — a lost widget means the fidelity guard demotes the band to raw
  text and the whole classification disappears.
- **Rendering the flat items instead of empty lanes is not a fix.** It lifted
  text coverage from 68.6 % to 80.9 %, but the band's copy is white on a
  background PHOTO the rebuild does not paint, so white became dark-on-white:
  colour drift went 1 → 2 and mobile height 2070 → 3102 px. Content appearing in
  the wrong ink is still a parity regression — fix the photo first.

## A measured band photo is captured but not wired

`RegionColors.image` records `url(…)` for a band that paints a photo. Nothing
emits it: `ForgeSection.bgImage` makes `resolveSectionTheme` return `hero`, which
is wrong for a content band. The consequence is worse than a missing picture —
`readableBandType` tests the measured ink against `bandSurface()`, which falls
back to the PAGE background when the surface is a photo, so the source's white
copy is dropped as "invisible on white" and the rebuild paints dark text on a
bare panel. Wiring the photo and its ink must land together.
