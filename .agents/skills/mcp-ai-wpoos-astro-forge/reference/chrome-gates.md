# Chrome gates — ids, resolution, and the opened mobile menu

Three gates added after a round where every failure was a **coverage** bug: the
gate measured something, just not the thing that was broken
(2026-11-12, virtuallyjamaica + jayainc).

## 1. `built ids` — duplicate ids in the built page

Runs whenever the built HTML exists; no flag, no browser.

The band wrapper emitted an anchor id the band's own markup already owned
(`id="wifi"` on Brando's half-column), so the page carried it twice.
`querySelector('#wifi')` resolved to the wrapper: in-page links landed on the
band instead of the red panel, and `verify --colors` compared the band's
`#ededed` against the source's `#dd3333` panel — a colour "drift" that did not
exist. The wrapper now yields (`templates/blog/src/lib/anchor.ts`); this gate
proves it on the output.

- Duplicates are invalid HTML everywhere; they **break** the document where
  something links to them. So: a duplicated id that is an in-page target
  (`href="#x"`, `/#x`, `…/#x`, `index.html#x`) **fails**; a stray duplicate
  nobody links to warns.
- `lib/html-ids.ts` reads start tags only, strips comments/`<script>`/`<style>`
  first, and ignores ids written in prose. It is not a validator.
- Homepage only: that is where the one-pager anchors live.

## 2. Colour-region resolution — "this gate has no opinion"

`measureRegionColorsDetailed` (the engine behind `analyze --colors`,
`colors`, `verify --colors`) now reports, per region, the selector that matched,
the element's box and how many text-bearing elements carried ink.

`unresolvedChromeRegions` turns that into findings:

| reason | meaning |
|---|---|
| `missing` | no selector in the ladder matched |
| `empty` | matched an element with no box (0 height/width) |
| `no-ink` | matched an element with no text |

jayainc's `footer` ladder matched WordPress's empty `footer.entry-meta` because
the bare tag came first: found, boxed, no text → every metric on it `skipped`,
which read as a pass. Now `analyze --colors` warns at record time and
`verify --colors` warns at gate time (`--strict-colors` fails). `no-ink` is a
warning only: an icon-only header has no text either, and an operator can tell
the difference.

## 3. `verify --chrome` — the OPENED mobile menu

One extra browser pass at 390×844; needs `analyze --geometry` (the source's
panel is `chrome.mobileNav`).

The colour gate measures 1440px at rest, so it cannot see the piece of chrome a
phone reader meets first. The rebuilt panel had no surface at all: white links
on the photo hero, unreadable.

`measureMobileNav` is url-only, so the gate measures the served build with the
same function that recorded the source and diffs the two (`compareMobileNav`):

| fact | grading |
|---|---|
| `background` absent in the build | **fail** — the links paint on the band behind the header |
| `background` ΔE00 | fail ≥ 6, warn ≥ 3; gradients compared as values |
| `ink` ΔE00 | fail ≥ 6, warn ≥ 3, only when both sides have a surface |
| `overlay` | warn (overlay ↔ in-flow changes what the menu covers) |
| `padX` / `itemPitch` | warn beyond 4px |
| no disclosure in the build | **fail** — the source opens one at this width |

Contrast alone would NOT have caught it: white on virtuallyjamaica's `#dd3333`
hero is **4.57:1**, which passes AA for normal text. The defect is "there is no
panel", not "the ink is low-contrast" — so the gate asserts the surface exists
and matches, then compares the ink.

Verified in both directions on the real build: clearing
`--forge-mobile-nav-bg` produces

```
✗ Mobile menu: mobileNav.background — source rgba(0, 0, 0, 0.95), build transparent
  — the opened menu has no surface — its links paint onto whatever band is behind the header
```

and restoring it passes with 4 compared metrics.

## 4. `restHeight` is recorded even when the header shrinks

`measureStickyHeader` used to omit the resting height whenever a pinned strip
existed or the header shrank (`headerShrank || stripInfo ? {} : { restHeight }`),
so `--forge-header-rest-h` was never emitted and the rebuild sized the header
from its content: **47px against the source's 75px**. The panel then started
28px too high and the hero copy showed through the 95%-black sheet.

It is now always recorded for a sticky header. Scope: the pass measures at
1440px, so the fact assumes the resting height does not change per device —
true for virtuallyjamaica (75/75/75), and non-sticky headers (jayainc: 100/160/100)
never reach this branch. A header that changes height per device needs the
per-device chrome-geometry pass (P4 in the roadmap), not this one.

## Roadmap (not built)

- **P4 — per-device chrome geometry**: header height/alignment, brand and
  toggle boxes at 1440/834/390, diffed like band boxes. This is the gate that
  makes "the header is centred / 28px short" a test instead of a conversation.
- **P5 — pseudo-element surfaces**: read `::before`/`::after` paint when the
  element's own surface is transparent (jayainc's footer gradient lives there).
- **P6 — inner-section anchors**: when an anchor lands on an inner section, fall
  through to the ancestor band's painted layer so its gradient is gated.
