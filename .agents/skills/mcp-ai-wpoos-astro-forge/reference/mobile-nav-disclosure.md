# The mobile nav disclosure (`chrome.navToggle`)

The chrome pass captured nav **links** but nothing about the control that hides
them. A rebuild without it wrapped six links over three rows at 390px — neither
the source's layout nor a menu. virtuallyjamaica's source ships Bootstrap's
`navbar-toggle` (44×34, visible from 991px down).

## Two facts, two sources

| Fact | Source | Why |
|---|---|---|
| `label` | `analyzeChrome` (markup) | `aria-label` first, then visible text, then `Menu` |
| `visible.{desktop,tablet,mobile}` | `measureNavToggle` (browser) | where the control actually appears |

Detection accepts a `navbar-toggle` / `menu-toggle` / `nav-toggle` /
`mobile-toggle` / `hamburger` / `burger` / `aux-menu-toggle` / `toggle-menu`
class, a `[data-toggle]` / `[data-bs-toggle]` attribute, or any control with
`aria-expanded`.

**Never guess the breakpoint.** Brando collapses at 991px, Bootstrap's default
is 768px, and Elementor/Auxin pick their own. Guessing either strands links
behind a hidden menu on a wide screen or renders a wrapped stack where the
source shows a hamburger.

`analyze --geometry` runs `measureNavToggle` beside the sticky-header pass and
merges `visible` into `profile.design.chrome.navToggle`. It is fail-soft: a
source with no toggle stores nothing, and the rebuild keeps the nav inline —
which is what the source does.

## Template implementation — APG disclosure, not `<details>`

`templates/blog/src/layouts/Base.astro` renders, only when the fact exists:

```html
<div class="site-nav-shell" data-nav-shell data-collapse="tablet"
     data-collapsed="false" data-open="false">
  <button class="site-nav-toggle" type="button"
          aria-expanded="false" aria-controls="site-nav" data-nav-toggle>
    <span class="site-nav-bars" aria-hidden="true">…</span>
    <span class="visually-hidden">{label}</span>
  </button>
  <nav id="site-nav" aria-label="Primary">…</nav>
</div>
```

A `<details>` was rejected: a closed `<details>` cannot be opened by CSS, so a
desktop layout would need the element absent — which means duplicating the nav
or shipping no nav without JS.

Behaviour (WAI-ARIA APG disclosure navigation):

- Space/Enter toggle natively (it is a real `<button>`).
- **Escape** closes and returns focus to the button.
- **Focus leaving** the region closes it (WCAG 1.4.13, Content on Hover or Focus).
- A `matchMedia('change')` listener re-syncs on resize, so a viewport change
  cannot strand the menu open.
- A same-page anchor click closes it (the drawer would cover the target).

## Progressive enhancement is load-bearing

Every hiding rule is gated on `data-collapsed`, which **only JS sets**:

```css
.site-nav-shell { display: contents; }                     /* nav stays a header flex item */
.site-nav-shell[data-collapsed='true'] > nav { display: none; }
.site-nav-shell[data-collapsed='true'][data-open='true'] > nav { display: flex; flex-basis: 100%; }
```

With JS off nothing matches, so the nav is inline at every width.
`tests/mobile-nav.test.ts` asserts no media query hides it.

The shell is `display: contents` on purpose: the nav keeps participating in the
header's flex layout at the widths the source keeps it inline, so the desktop
header is byte-for-byte the layout it was before the feature.

## The three-edit trap (cost a full e2e cycle)

A chrome fact needs **three** edits or it silently vanishes:

1. `ForgeChrome` in `lib/types.ts`
2. the emitted `ForgeChrome` interface inside `renderChromeGenerated`
3. the emitted **object literal** in `renderChromeGenerated`

Missing (3) compiles fine, `astro check` passes, and the toggle simply never
renders — the fact is in the profile and in the type, and nowhere in the build.

The inverse is worse: a new emitted **value** without its **interface member**
fails `astro check`, which is the e2e exit gate's first step. That abort takes
the fixture scaffold down and reports as four unrelated failures (missing
Pagefind index, un-localized catalogue PDF, invisible consent banner, a
`false` where `true` was expected).

## The OPENED panel is a surface, not a naked list

Hiding/showing the links is only half of a disclosure. A builder paints the
panel too, and that paint is invisible to every stylesheet harvest (the rules
live behind vendor classes and a `<style>` block sanitisation drops).
virtuallyjamaica's Brando menu opens an absolutely positioned
`rgba(0,0,0,0.95)` sheet under a fixed TRANSPARENT header; a rebuild that only
toggled `display` painted white links straight onto the photo hero — the menu
was unreadable over every band that is not dark (2026-11-12).

`measureMobileNav` (`lib/geometry.ts`) opens the menu at 390×844 and records
`chrome.mobileNav`:

| fact | source | how |
|---|---|---|
| `background` | `rgba(0,0,0,0.95)` | first ancestor of the link container that PAINTS (α ≥ 0.9 or a gradient) |
| `ink` | `rgb(255,255,255)` | the biggest visible menu link's computed colour |
| `padX` / `padY` | `15px` / `0` | the panel's own padding |
| `itemPitch` | `30` | median gap between consecutive link tops |
| `overlay` | `true` | panel is absolute/fixed, or the document height did not grow |
| `fullWidth` | `true` | panel width ≈ viewport |

Finding the panel is the delicate part. Picking the largest painted box under
the header caught the page band (the hero is 8,302px tall), so the pass finds
the **deepest common ancestor of the visible menu links** and walks UP from
there for the first painted layer — stopping at the disclosure's own row,
because past that lies the page and the page is not the menu. That single rule
returns the black sheet on virtuallyjamaica and `no measured surface` when the
source's own panel is transparent.

Template contract (`global.css`):

```css
.site-nav-shell[data-collapsed='true'][data-open='true'] > nav {
  position: absolute; inset-inline: 0; top: 100%; z-index: 30;
  background: var(--forge-mobile-nav-bg, var(--forge-surface));
  color: var(--forge-mobile-nav-ink, var(--forge-ink));
  padding: var(--forge-mobile-nav-pad-y, .5rem) var(--forge-mobile-nav-pad-x, 1rem);
}
.site-nav-shell[data-collapsed='true'][data-open='true'] > nav a {
  min-height: var(--forge-mobile-nav-row-h, 2.75rem); color: inherit;
}
```

The fallback chain is the point: measured panel → the header's own surface when
it is a real colour (`Base.astro` decides; a `headerTransparent` header has
none) → the page surface. A panel is therefore never transparent, even on a
source whose own menu is, and never white-on-white when the header is light.

Two operational notes:

- A `--geometry` run measures this fact, and `mergeProfileDraft` now takes
  MEASURED chrome from a design-less run (it used to keep the existing chrome
  and drop every fresh measurement — the topbar facts only survived because a
  `--design` pass happened to run). `navToggle` merges field-wise so the
  harvested `label` survives.
- Opening the panel must not reflow the page: it is `position: absolute`
  against the header, and `:where(.site-header:has(> .site-nav-shell[data-collapsed='true']))`
  supplies `position: relative` at ZERO specificity so it can only fill in a
  missing containing block. A `:has()` rule at normal specificity would outrank
  `.site-header--sticky`/`--float` and flatten a pinned header to `relative`.
  The source overlays too — verified by an unchanged `scrollHeight`.
