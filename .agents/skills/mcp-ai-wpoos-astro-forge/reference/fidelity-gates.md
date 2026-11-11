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

## Gate policy — per site (2026-11-11)

The forge runs against many sites, so one proposition splits the scorecard in
two: a gate's **measurement** is shared (that is what makes it a gate), its
**blocking-ness** is not. `lib/gate-policy.ts` owns the second half and is pure
— no I/O, no clock — so the policy a CI run applied is unit-testable.

The trigger was virtuallyjamaica: `verify` reported 11/14 (79%) and exited 1 on
a pixel diff that `--strict-pixel` could not soften, because the pixel exit was
unconditional. A multi-site forge cannot block every new site on a first-run
pixel diff, and it cannot let a site opt out of measuring either.

- **The addressable set is exactly three.** `profile.verify.gates` accepts a
  policy for `pixel parity`, `pixel regression` and `box geometry`. The content
  gates (`design language`, `token block`, `band flow`, `text parity`,
  `nav anchors`, `fact consumption`, `media budget`, `colour system`,
  `tracking`, `SEO head`, `bands`, `class coverage`) are NOT addressable: they
  prove the rebuild is complete and carries the source's words and colours.
  Downgrading those is not a policy decision, it is a broken site.
  `validateVerifyBlock` runs from `assertProfile`, so scaffold/verify/deploy all
  reject the key by name — a typo in JSON would otherwise keep the DEFAULT
  policy while the operator believes they changed it.
- **`gate | advisory | auto`, and deliberately no `off`.** `advisory` still
  runs, still prints and still appears on the scorecard; it just does not fail
  the run. `off` is absent because hiding a gate is what makes the pass rate
  dishonest, and the legitimate need ("no browser in this job") is already
  served by `--minimal` / `--no-bands` — flags, so the omission is visible in
  the pipeline definition rather than buried in a data file.
- **`pixel parity` defaults to `auto`.** With no source seam there is nothing
  to be equal to, so a brand-new forge would fail for having no reference:
  noise, not signal. Evidence is `verify-artifacts/source-*.png` or
  `--pixel-live`; present → `gate`, absent → `advisory`. Recording the seam is
  a deliberate human act (`--pixel-record-source`), so the gate tightening after
  it is expected, not a surprise. `box geometry` and `pixel regression` default
  to `gate`.
- **A build baseline is `pixel regression`, never parity.** Two captures of the
  same build differ by ~0, so a `baseline-*.png` comparison reports SSIM
  1.0000 and proves nothing about fidelity. It is recorded under its own gate
  name so it can never be read as parity. This is the discipline Playwright
  encodes with `updateSnapshots: 'none'` (a missing baseline must fail loudly,
  not write itself) and the reason a committed baseline needs review: an author
  committing one is self-approval.
- **A downgrade is printed, not silent.** The scorecard line carries its
  provenance — `ℹ pixel parity — 3 capture(s), 3 fail, 0 warn · advisory
  (profile · advisory)` — and the per-viewport lines say `FAIL · advisory, does
  not fail the run`. `gateNote()` returns empty for a BLOCKING gate on purpose:
  the provenance of the norm is noise, only a downgrade needs to be visible.
- **Scorecard arithmetic.** `applicable = total − na − advisory`. Advisory is
  excluded from the pass rate *because* it is printed on its own line, so the
  number and the policy can never disagree in silence. `skip` still counts
  against 100% (it means "applicable but never applied").
- **Precedence is flag → profile → default.** `--strict-pixel` now force-sets
  `pixel parity` to `gate` (it used to cover only the nothing-compared case) —
  the CLI override a reviewer can see in the pipeline file. A flag of `auto` is
  ignored: a flag may not delegate the decision back to the profile.
- **`verify.pixel` travels with the site.** `pixel.masks` (dynamic regions) and
  `pixel.baseline: 'source' | 'build'` are profile-level so they do not live in
  a CI flag nobody re-reads; `--pixel-mask` still wins when given, so a one-off
  investigation can widen the mask without editing the site. `baseline: 'build'`
  reverses the source-first preference deliberately, for a site whose source
  cannot be reached.

virtuallyjamaica's own state is unchanged by the policy: a source seam exists,
so parity resolves to `gate` and a default `verify` still exits 1. The residual
diff is real work (hero vertical offset, container gutter, image rendition), not
a site quirk to be waived — declaring `advisory` is the interim call, not a fix.
