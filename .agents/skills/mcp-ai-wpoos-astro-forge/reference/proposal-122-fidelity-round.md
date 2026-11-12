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
