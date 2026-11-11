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
