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

## A positioned pseudo under 50 % opacity is a VEIL, not a surface

`readBackground` reads a band's positioned `::before`/`::after` before the
element's own paint, because a builder often paints the band on an inner layer.
That is right for an OPAQUE overlay and wrong for a decorative one:
eca.directory's phased-delivery band paints `linear-gradient(#27104a 30%,
#ffffff 0%)` on the box and `url(bg-01.png)` on `::before` at **opacity 0.2**.
The photo won, the gradient was never emitted, the band rendered white, and the
white intro copy was then dropped as "invisible on white" — one wrong statistic
showed up as two unrelated-looking defects. Skip the pseudo below 0.5 opacity.

## A band can hold a card row AND a table — never drop either

When grouping a flex band into cards, the copy that is NOT in a card (the band
lede, a comparison table sharing the band) has to go somewhere: the band's text,
or a flat item. Dropping it makes the fidelity guard demote the whole band to raw
text, which reads like "the grouping did not fire" but is really "the grouping
lost words".

## The kicker comes FIRST

A builder's card often publishes a small label (`h3 "Phase 1"`) above its title
(`h4 "Pilot Setup"`, an `elementor-icon-box-title`). The title is the icon-box
heading; the label is a **kicker** rendered above it. Printing the label after
the title reorders the copy and the band's text shingles stop matching — the
coverage report says "phase 1 pilot" is missing even though both words render.

## A listing page's SLICE can live in its TITLE, not its slug

`eca.directory/eca-days/` lists the Monday activities and is titled
**"Monday- Junior School"**. Nothing in the slug says "Monday", so a preset that
claims listing pages by slug convention replays the builder's widgets and ships
the page's stale 14th row (lorem-ipsum demo copy the record set does not contain;
Monday has 13 records).

Record the CMS page **titles** with the inventory (`_fields=slug,link,title` — the
same request) and derive the claim from the title as well as the slug. A claim
must come from what the site SAYS; a slug is a convention, a title is data. A
chooser page (`select-list`: teaser lanes, no rows) still must not be claimed —
"names a slice" is not "renders the slice".

## The classifier sees SANITISED HTML — no SVGs, no inline styles

`sanitizeHtml` runs before section classification and strips every `<svg>`. A
"Compare User Roles" matrix whose cells are check/cross **icons** looks empty to
every markup detector: on eca.directory band `77f4271d` the raw REST HTML has 52
SVGs and `e-fas-check` classes, the sanitised HTML has 0, and the pipeline reports
`kind feature-grid items 3 cmp no` even though the values are plainly there in a
browser.

Do not write a detector that depends on markup the sanitizer drops, and do not
"fix" it by loosening the sanitizer. A structure carried by imagery needs an
**analyzer-measured fact** (captured in the browser, stored in `bandFacts`, emitted
by the renderer) — same contract as measured box geometry and interaction states.

## Count every slot you render in the fidelity guard

The guard demotes a band to raw text when its rebuilt slots carry < 50 % of the
band's words. `slotWordCount()` must therefore count **every** string the renderer
prints: `title`, `body`, `author`… and `kicker` and `bullets`. Adding a slot
without adding it to the count makes a correct band look lossy and silently
disappears from the page — the symptom reads as "the section type was not
detected", not "the guard miscounted".

## A card whose heading IS the band title is the band's sub-heading

A builder publishes an "updates" band as a small kicker heading plus an icon-box
whose title is the real heading and whose body is the band's lede — eca.directory
pairs a 14px `h2` "Our Updates" with a 33px icon-box "News & Insights". The
measured eyebrow/title pair correctly promotes the icon-box heading to the band
title, and the icon-box is ALSO rebuilt as a card, so the same string prints
twice above an empty-looking one-card grid.

The rule (`titleEchoItems` in `lib/band-copy.ts`) folds such a card into the
band's lede. It must NEVER fold a card that carries media, its own link, or a
measured lane surface — those are content the title does not speak for. Keep the
rule in one place: `DesignRenderer` needs the same answer to decide the band's
height (below).

## A measured `min-height` is a height WITH the content you dropped

Once a dynamic list folds away or its only card was a placeholder, the band's
measured `min-height` floors nothing — eca.directory's updates band held 478px
around three lines of copy. Skip the measured height for such a band **in both
emission paths** (`wrapStyle`'s inline value AND the per-device scoped CSS), or
one of them reinstates the void.

This legitimately costs band-geometry parity (the source's height included the
card). Record the delta rather than "fixing" it by shipping dead space, and note
the band id so the fail is attributable.

## A scaffolded `src/` is a COPY — re-sync it after changing `lib/` or `templates/`

`src/forge/*.ts` and the template components in a scaffolded project do not track
the forge repo. Editing `lib/eca.ts` and rebuilding the existing project re-ran a
bug that had already been fixed upstream, because `src/forge/eca.ts` was stale.
Either copy each changed file into the project or re-scaffold; then run the
project's own `npm run check` before building.

## A fact recorded inside a pass whose output can be DISCARDED is invisible

`analyzeSections()` keeps only bands that state a fact, and the design pass then
chooses the band MODEL: when the section list is shorter than the vendor-agnostic
outline, the outline wins (`bandSource: outline`). A detector written inside
`analyzeSections()` therefore produces nothing on such a site — eca.directory's
comparison matrix was measured correctly and thrown away.

Record per-section facts **keyed by section id** (`comparisonFactsById`) and merge
them onto whichever band list wins, so the fact survives the model decision.

## A design refresh can flip the band model and drop every band surface

`analyze --design --write` replaces `profile.design` wholesale. On eca.directory it
flipped `elementor` → `outline` bands and silently dropped every
`bgColor`/`bgGradient`, including a gradient an earlier pass existed to capture.
`diffDesignFacts` reports dropped CHROME facts and says nothing about this.

After any design refresh on an outline-builder site, diff `bandSource` and the
section background facts against the previous profile before building. If they
regressed, apply the one fact you came for to the OLD profile instead of shipping
the refresh.

## An unseeded Elementor widget is a `content` blob

`collectWidgets` resolves an unknown `elementor-widget-<name>` to
`{ kind: 'content' }`, which flattens the widget's own structure **and** forces
the band to `low` confidence (the `effectiveConfidence` rule). `posts` was
unseeded for a long time: a query loop was indistinguishable from an authored
grid. Seed the widget dictionary before concluding a band "has no structure" —
and give loops the `list` role, which names them without downgrading confidence
or skipping their real cards.

## A design refresh can flip the band model

`analyze --design --write` may switch `bandSource` between `sections` and
`outline`. Outline bands carry no `bgColor`/`bgGradient`, so the flip repaints
every band transparent. `diffDesignFacts` now reports the flip and each dropped
surface, and `mergeProfileDraft` carries surfaces forward on surviving ids — but
check `bandSource` after any refresh on an outline-builder site.

## Re-scaffolding for a profile change is a wrecking ball

`forge regen <profile> --target <dir> --only sections` rewrites the generated
modules in place (byte-identical to the scaffold's output) and skips modules the
project does not ship. On Windows a full re-scaffold fails with `EPERM` whenever
an editor or watcher holds the target; `regen` never swaps the directory, so it
does not. Use it for profile-fact changes; re-scaffold only for template changes.

## A band you deliberately render shorter is not drift

Emit `data-section-collapsed` on the wrapper (the renderer does this when a grid
folds away) and `diffGeometry` skips the band. Without it, a correct decision
scores as geometry drift: eca.directory's updates band read 478 → 239 and
counted three fails.

## Add a structural widget as a DETECTOR, not a pipeline

`ForgeSection.structure` (emitted as `SectionTheme.structure`) is the shared
channel for anything the build-time classifier cannot see. To add one: write a
detector on the RAW html, push `{ type, data }` onto the section's `structure`,
and add a branch in the renderer. A measured structure renders **in addition to**
the band's classified kind — the band that carries a matrix also carries cards.

`comparison` is the first entry and the worked example. Its typed
`ForgeSection.comparison` field stays for one release; the scaffold synthesises
the `structure` entry from it, so an old profile still renders.

## A vision verdict may only FILL a gap

`lib/vision.ts` has `STRUCTURE_PROMPT`, `parseStructureVerdict` and
`mergeStructureVerdicts`. The vocabulary is closed (an invented label becomes
`unknown`), a deterministic detector always wins, and a stored verdict carries
`source: 'vision'` plus the model id. Never let vision overrule a measured fact,
and never let it emit markup or copy.

`forge structure <profile> --target <dir> [--write]` is the wiring
(`lib/structure-pass.ts`): serve `dist/`, screenshot each `[data-section-id]` at
1440 and 390, and merge. It is opt-in and fail-soft (no endpoint → a printed
skip, exit 0), it skips a band that already carries a measured structure
*without a model call*, and it stores nothing without `--write`. The cache is
keyed `section id + sha256(screenshot)`, so an unchanged band costs nothing and
a changed one is re-asked. Never wire it into the build.

A band whose matrix lives in the typed `comparison` field (a profile recorded
before the `structure` channel) counts as measured: the renderer falls back to
`comparison` only while `structure` is empty, so a label there would suppress
the table.

Two traps found by actually running the pass on eca.directory:

- `builtStaticRoot` takes the **dist** path (`join(target, 'dist')`). Passing the
  project root serves the project directory, answers 404, and captures nothing —
  an empty capture must report `skipped`, never a tidy row of zeros.
- **Precision comes from the page, not the model.** A local 7B VLM answers with a
  flat `0.9` for everything, so three rules decide what is storable: a band whose
  render contains a `<form>` is measured (it called the enquiry band `faq` at 0.9
  on *both* ends); the two device reads must AGREE (an abstention is not a
  contradiction); and the band's measured heading is appended to the prompt
  (without it a hero reads as `text`; with it, *Meet the Project Team* → `team`).
  Calibrate the prompt as well — reserve 0.9 for an unmistakable cue. Most bands
  then abstain, which is the right answer for a small model: run the pass dry,
  read the notes, and only then decide about `--write`.

## A responsive class changes a container's SHAPE

`containerShape` (and therefore `containerRows`) groups siblings by their class
tokens, and `elementor-hidden-mobile` is one of them. So a card row where one
lane carries a per-device class is grouped as *two+one*, and the hidden lane is
split out of its own row — exactly the lane a lane-fact pass is about. If you
need the true lane set, re-group the parent with the responsive vocabulary
(`elementor-hidden-*`, `elementor-reverse-*`, `elementor-col-*`, `elementor-md-*`,
`elementor-sm-*`, `elementor-widget-*`) normalised away, locally. Changing
`containerShape` itself relayouts every band already verified byte-identical.

## A measured lane list silently drops per-device facts

`measuredLaneColumns` in `lib/scaffold.ts` prefers the geometry pass's real
widths over the declared split — correctly. But it builds each lane from
scratch, so `hiddenMobile` / `hiddenTablet` / `widthTablet` / `reverse*` were
discarded, and the emitted `columns` lost them even when the profile had them.
A visibility class is not measurable from the desktop render (it is still in the
DOM) and a width override lives in a media block, so the DECLARATION is the only
source. When you add a field to `ForgeSectionColumn`, carry it through
`measuredLaneColumns` **and** the measured emission, or it never reaches the
renderer.

## A sub-page has no design facts

The homepage runs two passes — the browser one (`measurePageBands`: geometry,
band facts, region colours) and the design one (`analyzeSections`: surfaces,
panels, layout, media). Sub-pages used to get only the first, so
`pageBands[slug].sections` held an id and a column list and the rebuild lost the
band's backgrounds, media and split. `forge page-design` closes that: same
extractor, merged **by id**, with `id`/`vendorId`/`columns` owned by the browser
pass. It also only names bands that STATE a fact — two of about-us's four bands
say nothing at section level, so their surfaces live on inner elements.

## A background image is not a fact without its BOX

`bgImage` alone paints at native scale: storing `bg-01.png` on eca.directory's
hero and stats bands covered both with a giant checkerboard and made white text
unreadable. The source declares
`background-size:cover; background-repeat:no-repeat; background-position:top center`
beside the URL, and `bgSize` / `bgRepeat` / `bgPosition` now ride the same fact
(extracted from the band-scoped rule, emitted on `SectionTheme`, applied by
`wrapStyle` with `cover` / `no-repeat` / `center` as the fallback).

**The box is necessary but not sufficient.** With the box applied the build
still showed the halftone at full strength, because Elementor renders that
image on a `::before` LAYER whose `opacity` and `--background-overlay` fade it.
We capture the image and its box but not the layer's opacity, so the band is
100% pattern where the source is a faint texture. Do not apply a page band's
background image until the layer (opacity + overlay) is on the same channel —
at full strength it is worse than no image at all.

## A gate must check EFFECT, not presence

`fact consumption` passed while every band background was being dropped: the
module emitted `bg`, so the fact was "rendered" — but `background:background-color:rgb(…)`
is invalid CSS and the browser discarded it. Likewise the colour gate reported
the missing surfaces as **not comparable (transparent)** rather than failed.
When a gate asks "was the fact present?", a wrong rendering is invisible. Ask
"did the page paint it?".

## Lane visibility is `nth-child`, never a reorder

A lane the source hides per device is emitted as
`[data-section-id="…"] .ds-grid > :nth-child(i){display:none}` under the band's
media queries. The rebuilt cards follow the source's document order, so lane *i*
is the grid's *i*-th child — that is the whole contract. Do not compact the grid
or reorder children: the hidden lane keeps its slot in the source's reading
order (and in the DOM, for crawlers).

A lane's per-device **width** rides the same block, but as a track COUNT
(`lib/lane-tracks.ts`), never a per-lane width: Elementor's width is a flex basis
and a row wraps past 100%, so the faithful grid fact is the first row's count.
Only when a lane states that device's width, and only with hidden lanes excluded
— an implicit basis is the desktop split and must not be applied at 390px.
