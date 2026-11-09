# Fidelity gates and measured facts

Extracted from SKILL.md on 2026-11-09 — SKILL.md sits at the ~100 KB load
limit and Zed silently drops larger skills. Content is verbatim.


The jayainc band-by-band review produced three gates and four fact kinds.
Each line below cost a wrong build — do not re-derive them.

**Gates** (all documented in the Proposal 114 verify section):
`analyze --geometry` + `verify --geometry` (box metrics at 1440/834/390),
fact consumption in `verify` (`--strict-facts`), and the `rulesInMedia`
responsive read. **`npm run typecheck` is part of the gate** — vitest does
not typecheck, so a green suite can still fail CI on fixture types. The most
common miss is an extensionless relative import in a new test: `tsc` under
NodeNext raises TS2835, the module then resolves to `any`, and the report
cascades into dozens of TS7006 "implicitly has an 'any' type" errors on the
callbacks. Fix the specifier (`'../lib/foo.ts'`), not the parameter types.

- **Device-scoped margins and padding.** `topLevelRules` deliberately keeps
  a media body as inert nested text, so facts inside `@media` were
  invisible: jayainc's band pull is 0px desktop / 170px tablet / 100px
  mobile and came back as "none". `rulesInMedia` recurses into media
  bodies; `responsiveLength` emits `{ desktop, tablet, mobile }` only when
  the values differ. **An inline `margin-top`/`padding` OUTRANKS media
  queries** — a device map must be emitted as CSS (base rule + two media
  rules), never inline. Proof it works: raw extraction on jayainc now
  returns exactly the map. `ForgeSection.padding` takes the same map
  (`string | ResponsiveLength`): jayainc `059421c` is 125px desktop /
  100px tablet+mobile, and a single shorthand over-padded both by 25px.
  `DesignRenderer`'s `responsiveDeviceCss` emits it under
  `[data-section-id="…"]`; only a plain string goes inline.
- **A band fact has TWO render paths — teach both.** `verifyBandFlow`
  (`builtBandsOf`) and fact consumption (`auditFacts`, `lib/fact-audit.ts`)
  are independent checkers. Making one read a new render form leaves the
  other emitting a phantom failure — after padding moved to scoped CSS,
  `builtBandsOf` was fixed and `verify` still said "band 059421c declares
  padding but the build has no padding:". Both now read
  `lib/scoped-css.ts` (`stripAtMedia` + `scopedPaddingOf`) so they cannot
  drift. Keep `auditFacts` strict per form: a string must be inline, a
  device map must have its `[data-section-id]` rule — an unrelated inline
  `padding:` elsewhere in the band must not mask a missing rule.
- **`sectionHtml()` slices band-tag → next band-tag.** Anything rendered in
  a `<style>` block AHEAD of the band (scoped margins/padding) falls
  outside that slice, so a fact whose marker lives there needs the
  `FACT_MARKERS[].present` hook — a new marker string cannot see it.
- **The collage geometry rides custom properties, not inline
  `width`/`height`.** Same trap as margins, one level deeper: an inline
  `width`/`height` on `.ds-collage-shape` cannot be overridden by the reflow
  media query, and a fixed 412px child sizes the *implicit* grid column, so
  `max-width: 100%` has no definite containing block to resolve against.
  jayainc measured 45px of horizontal scroll at 390px and 72px at 768px
  before the fix. The reflow needs BOTH a definite track
  (`grid-template-columns: minmax(0, 1fr)`) and the geometry in
  `--ds-shape-w` / `--ds-shape-h` / `--ds-shape-ratio`, so the shape can go
  full width at its own ratio (`[data-fluid]` + `aspect-ratio`).
- **`bandSpacing: 'template' | 'source'`** (profile-level, default
  `template`). `source` renders fact-less bands flush. Two traps: the wrap's
  padding comes from a RULE (`--forge-space-4`), not `--ds-pad-y` (which
  only collapses the inner `.ds-section`), so source mode must zero
  `padding-top/bottom` explicitly; and an EMPTY band is a spacer (jayainc's
  `dff3f1a` is 70px with no padding fact) — zeroing it rendered 0px and
  shifted every band below. Empty bands keep the wrap rhythm and drop only
  `--ds-pad-y`.
- **`cardStyle: { gap, padding, textPadding }`** (per band). Elementor card
  metrics live on theme widgets (`.elementor-widget-wrap`, `.aux-text-inner`),
  never on the band, so no band-scoped CSS scan can find them — they are
  MEASURED facts, applied as `--ds-card-gap` / `--ds-card-pad` /
  `--ds-card-text-pad` with the template value as fallback. Probe the card's
  own tree before guessing: jayainc's missing 107px was a 40px text-block
  padding, not the image.
- **`.ds-card img` needs `height: auto`, and must not force 1/1.**
  SmartImage's `width`/`height` attributes are presentational hints that beat
  `aspect-ratio`; without `height: auto` a 687×768 source rendered 224×768
  and every card stood 903px tall. Forcing `1 / 1` then cost another 139px —
  the source keeps the intrinsic ratio. A fixed `height: 300px` is wrong at
  every other viewport and over-crops under `object-fit: cover`.
- **`measure` is not necessarily the site default.** Extraction reads the
  1140px container default; a band's own container can be Elementor's 1600px
  (jayainc's products band — its 25% columns are 345px, not 258px). Correct
  the fact and PIN it.
- **A short band can be MISSING CONTENT, not wrong layout.** jayainc's
  products band still measured 885px against 1216px after its cards matched,
  because the source has a third block — an "ALL SERVICES" CTA — that never
  entered the section slots. Grep the built HTML for the source's text, not
  just its boxes; geometry cannot see content that was never emitted.
- **Pin every measured/authored fact.** `profile.pins` re-applies through
  `mergeProfileDraft`; `tests/pins.test.ts` fails naming the exact path when
  a regenerate drops one. Known jayainc pins: `5e78df1` marginTop, measure,
  cardStyle; `059421c` padding (device map); `fd63522` padding;
  `chrome.footerInk`.
- **A geometry fail COUNT can stay flat while the build improves.** The
  gate warns at tolerance and fails at 2×, so a genuine fix smaller than
  the fail threshold leaves the count unchanged — cite per-band px
  deviations, not the count. Structural fails not worth chasing:
  `dff3f1a`'s paddingTop/Bottom 0→30px (its padding IS how it reaches
  60px — the empty-band trap above) and `fd63522` (a `shortcode` band, a 3D
  flipbook a static forge cannot execute; its 100px bottom padding is a
  deliberate operator pin).
