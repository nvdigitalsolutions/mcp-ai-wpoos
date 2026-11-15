# The colour gate — measured surfaces and measured ink

`analyze --colors` / `colors` records a baseline; `verify --colors` diffs the
built page against it with CIEDE2000 (warn ≥ 3, fail ≥ 6). Transparent surfaces
are skipped, never guessed. This file is the operating manual for what counts as
a "colour" and why.

## 1. Regions

`DEFAULT_COLOR_REGIONS` covers `body`, `header`, `footer`; `bandColorRegions()`
adds `band:<anchor>` for every renderable band anchor. Bands are measured with
`descendantFill: true` — a builder paints a band's surface on a child layer, so
the ancestor walk alone reads the page body for every band.

The header/footer ladders are ordered *specific-first, bare tag last*:

```ts
footer: [ 'role=contentinfo', '.site-footer', '#colophon', '.elementor-location-footer',
          '#sitefooter', '.aux-site-footer',
          'footer:not(.entry-meta):not(.post-meta)', '.footer', 'footer' ]
```

Why: WordPress themes emit `footer.entry-meta` **inside the loop**. With the bare
tag first, jayainc's `header`/`footer` regions measured that empty element — the
footer read `transparent` with no ink, so the gate silently skipped the chrome it
exists to protect (2026-11-12). Any new ladder follows the same rule.

## 2. Ink is a PALETTE, weighted by rendered glyph area

Each text-bearing element contributes

```
mass = visible characters × (computed font-size in px)²
```

to its computed `color`. Glyph area scales with the square of the size, so a
206-character 24px lede outweighs 426 characters of 13px list copy — which is
what a reader perceives.

`summarizeInk()` then:

1. drops `transparent` samples (invisible text is not ink);
2. merges colours within ΔE00 ≤ 2 onto the heavier one (so
   `rgba(255,255,255,0.7)` does not split the vote against `#ffffff`);
3. sorts by share, keeps the top 4, drops anything under 4%;
4. rounds shares to 2 decimals.

Recorded as `RegionColors.inks` (`[{ color, share }]`), dominant first.
`RegionColors.text` stays `inks[0].color` so pre-palette baselines still verify
and the console output keeps one colour per region.

### What gets gated

Every source ink with `share >= GATED_INK_SHARE (0.15)` is matched to the
**closest** build ink and compared on its own ΔE. So:

- a lost minority ink is a **failure** (virtuallyjamaica's four grey `li`s
  turned white — 26% of the band's text, invisible to a single-colour baseline);
- a lost 4% caption is not (typographic drift, not a colour regression);
- the dominant ink is always gated (a bounded palette makes it ≥ 25%).

A source baseline without `inks` falls back to the original single-colour
comparison.

### Text the region does not contain is not its ink

`readInk` requires the node's box to intersect the REGION element's own box.
Builders leave legacy descendants in the DOM that render outside the element and
are covered by the next band: jayainc's site-title link sits 23px under its hero,
so its cyan `#1bb0ce` was counted against a header that never shows it, and the
gate reported a 29%-of-text drift that no reader could see (2026-11-12). The
header then measured `#ffffff 82% / #242424 18%` — exactly the screenshot.

## 3. Class ink: the measured box sheet carries `color`

A builder declares text ink in its stylesheet:

```css
ul.medium-gray-text { color: #929292; }
```

The sanitizer keeps the class name and drops the vendor sheet, so the rebuilt
copy inherits the panel's colour — virtuallyjamaica's red WiFi panel has a white
lede and grey bullets; every bullet rebuilt white.

`collectBandBoxFacts` therefore records a `color` on a class **only when the
class paints its element a colour other than the one it inherits**, and only
when every instance of the class in that band agrees. An inherited colour is not
a fact about the class, so restating it can never fight the parent that
legitimately sets it. It rides the existing `box` facts (`MeasuredBox.color` →
`boxDeclarations`), so it is per band, per device, and deduped by
`mergeBoxFacts` like padding and margin.

Inert by construction: `box` rules are scoped to `[data-section-id="<band>"]`,
and a component-rebuilt band carries none of the source's classes.

## 4. Re-baselining — `colors <profile>`

`analyze --colors` re-harvests the whole profile, which is the wrong tool when
only the metric changed or the source host moved. Use the standalone command:

```sh
node scripts/forge.mjs colors sites/<site>.json --modes light            # dry run
node scripts/forge.mjs colors sites/<site>.json --modes light --write    # save
node scripts/forge.mjs colors sites/<site>.json --url https://cms.example.com --write
```

`--url` measures a different host without touching `profile.url` (the public
domain may already serve the FORGE — jayainc.com does, `cms.jayainc.com` is the
WordPress source). The command prints `bg … text <palette> (was …)` per region,
so a re-record can be reviewed before it is saved.

Re-record the baseline after ANY change to the ink metric — an old baseline
records values the current analyser would never produce, and the gate then
reports the metric's history as build drift.

## 5. Reading the output

```
✗ Colour [light]: band:services text — source #929292 (26% of text), build #ffffff (ΔE00 45.2)
```

- `(26% of text)` — the share the expected ink carries; a lost 26% is a
  different finding from a lost 4%.
- `build <colour>` is the CLOSEST ink in the build, not necessarily one the
  source shared: it names what replaced it.
- `colour: N surface(s) not comparable (transparent, or a gradient against a flat source)`
  is normal (photo bands, a transparent header over a hero). It NAMES the regions
  because a skip is the gate having no opinion — most often a `::before` overlay
  (jayainc's footer: the flat base is `rgba(8,26,67,0.9)`, the visible fill is an
  opaque `linear-gradient` on `.aux-subfooter:before` that a computed-style read
  cannot see; the design harvest records it as `chrome.footerBg`, so the build is
  correct and the surface simply stays ungated). Resolving pseudo-element
  surfaces is the next measurement step, not a reason to loosen a threshold.
- `⚠ Colour: footer resolved to an element with no text in the build` — the
  region is ungated, not fine. See §5a.

## 5a. A region that measures nothing is a FINDING

`measureRegionColorsDetailed` reports how each region resolved: the selector that
matched, the element's box, and how many text-bearing elements carried ink.
`unresolvedChromeRegions` classifies a chrome region as `missing` (no selector
matched), `empty` (no box) or `no-ink` (no text).

That is the shape of a **ladder that won**: jayainc's `footer` list had the bare
`footer` tag first, so it matched WordPress's empty `footer.entry-meta` — found,
boxed, transparent, no ink. Every metric on the region was skipped and the gate
reported nothing, which reads exactly like a pass. `analyze --colors` warns at
record time, `verify --colors` warns at gate time, and `--strict-colors` fails.
`no-ink` stays a warning: an icon-only header has no text either.

## 6. Measured results (2026-11-12, 1440×900)

| site | surfaces compared | drift | notes |
|---|---|---|---|
| virtuallyjamaica | 24 (light; source defines no dark) | 0 | `band:wifi` `#dd3333`, `band:services` white 74% + `#929292` 26% |
| jayainc | 16 | 0 | header `#04132b` white 82% + `#242424` 18%; footer `#081a43` |

Both sites' `band:` regions now match the source ink palette exactly, byte for
byte in mass where the markup is shared.

## 7. Known limits (measured, not guessed)

- **A `::before`/`::after` surface is invisible to this read.** `getComputedStyle(el).backgroundImage`
  does not include a pseudo-element's paint. jayainc's footer is a flat
  `rgba(8,26,67,0.9)` base plus an OPAQUE `linear-gradient` on
  `.aux-subfooter:before`; the harvest records the gradient as
  `chrome.footerBg` (correct build) while the colour read records the base, so
  the footer background is reported not-comparable rather than gated.
- **An anchor on an INNER section** (`#products` is an `elementor-inner-section`)
  leaves the surface on the OUTER band. The region then reads the inner
  section's own/descendant fill, and the outer band's gradient is not gated for
  that region. The band-flow + gradient gates cover the outer band separately.
- **Transparent is never estimated.** A transparent region over an unknown
  backdrop is skipped, not composited — guessing manufactures both false passes
  and false failures.
