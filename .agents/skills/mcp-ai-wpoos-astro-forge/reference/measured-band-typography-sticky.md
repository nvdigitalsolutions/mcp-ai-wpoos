# Measured band typography + the sticky header (2026-11-10)

Extracted from the skill body on 2026-11-26 to stay under Zed load limit.


The two passes that closed "the sections still don't match": the raw-HTML band
path had NO heading scale (a 40px source `<h3>` rendered at 15.21px = `1.17em`
of the 13px body), and a sticky header's SCROLLED state was never captured. Both
are browser facts on `analyze --geometry`, stored as `profile.bandFacts` and
`profile.stickyHeader`. Full contract:
[`reference/measured-band-facts-and-sticky-header.md`](reference/measured-band-facts-and-sticky-header.md).

- `bandFacts[id]` = per-heading computed facts (size/weight/line-height/
  letter-spacing/transform/**text-align**), the body paragraph, and the inner
  `containerWidth`. Scaffold emits `[data-section-id] h3{…}` + `--ds-title-*`,
  and derives the band `measure` from the container (fluid bands stop rendering
  at the site measure).
- **A measured device size REPLACES the headline `clamp()`, it is not an anchor
  for it** (2026-11-21). The template shrinks a headline to a hard-coded floor
  (`1.5rem`/`1.75rem`), which is a guess about the source — so a band title that
  is 22.2px on desktop and 16px on a phone rendered at the desktop size on
  phones (39% large). `title.tablet.size` / `title.mobile.size` now emit
  `--ds-title-size-md` / `--ds-title-size-sm`, and `global.css` swaps the whole
  clamp for them under `1024px` / `767px`. The vars are absent where the source
  does not change size at that device, so the clamp still governs.
  **Known gap:** on a raw-HTML band the class box fact still wins (see the
  Gotchas bullet on the specificity floor), so a desktop class type reaches the
  phone; the device title vars fix the COMPONENT path only.
- **A `rem` body size must resolve against the html root rule.** `chromeSizeFrom`
  reads `body { font-size }` and its `em` pattern is anchored, so `1.125rem`
  declined every branch and fell through to the polluted element resolution.
  The chrome size is now also a MEASURED fact (`geometry.chromeSize`, from the
  copyright node's computed style, overlaid by `applyMeasuredChromeSize`) — a
  declaration is not a computed value: `1.125rem` resolves to 18px where the
  source paints 15.984px.
- `stickyHeader` = rest vs pinned background/ink/height. `Base.astro` adds
  `site-header--pinned`; global.css rebuilds it as a `scroll(root)` timeline
  (rest state is the BASE — a header's finished state is the scrolled one, and a
  solid strip over the hero at scrollY 0 is the louder error).
- `bandFacts[id].hidden` = the selectors the SOURCE renders hidden at rest
  (accordion panels, tab panes, mobile drawers), hidden at BOTH breakpoints and
  carrying `:not(.in)`-style exclusions so the open panel stays visible. This
  replaced the hand-written `.panel-collapse.collapse:not(.in)` rule. Reopening
  is behaviour, not data: `templates/blog/src/components/BuilderAccordion.astro`
  toggles those classes back — see
  [`reference/measured-band-facts-and-sticky-header.md`](reference/measured-band-facts-and-sticky-header.md) §3.
- `verify` runs a **class-coverage audit** (report-only; `--strict-classes`
  makes it a gate): every class name in the rebuilt bands that matches no rule,
  written to `verify-artifacts/class-coverage.json`. That list is the inventory
  behind every hand fix in this arc — read it before adding CSS.
- **Vendor utilities are DATA, not template CSS** (`BUILDER_ADAPTERS[].utilities`):
  `text-center`, `text-medium`, `padding-four-all`, `font-weight-600` … are
  emitted per band, trimmed to the classes the page measured (`bandFacts[].classes`).
  `global.css` carries none of them, so a Divi site gets Divi's conventions and
  nothing else. Values are measured. The VALUE-NAMING responsive variants
  (`xs-padding-40px-tb`, `sm-padding-70px-tb`, `md/sm/xs-text-center`) are mapped;
  the numeric spacing scale is not — it arrives as a measured box fact instead
  (see the addendum below).
- Gotchas: read the pinned snapshot after a real wait (the live page's header
  colours land a beat after the scroll class); the fact audit cannot see a
  scoped rule that sits before the band, so its marker needs a `present`
  predicate over the whole page; and the audit must include the page's INLINE
  `<style>` blocks and treat `[class~=…]`/`[class*=…]` as coverage, or it
  reports correctly-styled bands as missing.

### Addendum (2026-11-11) — box facts, header LAYOUT, two sanitizer bugs

Typography and scrolled colours were not sufficient: four more measured facts
(box facts, header LAYOUT, `stickyHeader.position`, container-per-device) plus
two sanitizer bugs. The bullets — box diff, `media`, `themeToggle`, the pixel
gate's source seam, gate policy, the per-device container, the `<p>`-class gap,
`box`, `stickyHeader.position`, chrome-vs-nav ink and the fluid-band rule — moved
to
[`reference/measured-band-facts-and-sticky-header.md`](reference/measured-band-facts-and-sticky-header.md)
§11 rather than restating the reference here. Read it before touching
`bandFacts`, `chrome.headerBg` or the gate policy.

Two bugs fixed in that pass: `class` was stripped from `h1`–`h6` (headings lost
their measured box), and `--dark` set light ink on the WRAP instead of its
children. virtuallyjamaica: **0 fail** box geometry, **0 drift** colour.
