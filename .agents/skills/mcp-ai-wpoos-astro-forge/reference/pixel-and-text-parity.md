# Content + pixel parity gates (2026-11-09)

Two gates were added after the first real rebuilds exposed a blind spot: the
design-language score, the token block, band flow and fact consumption can all
pass while **words go missing** and while **a render drifts**. `verify --text`
covers the first, `verify --pixel` the second.

Neither replaces the others. The gate matrix is now:

| Gate | Question | Deterministic | Needs a browser |
|---|---|---|---|
| language parity (`scoreParity`) | does the build speak the source's design language? | yes | no |
| token block | were the profile's tokens actually applied? | yes | no |
| band flow | does every source band render with its baked background? | yes | no |
| fact consumption | does every declared band fact reach the HTML? | yes | no |
| **text parity** | **did the source's words survive?** | yes | no |
| geometry (`--geometry`) | are the boxes the right size? | yes | yes |
| **colour system** | **does the browser render the source's colours (body/header/footer)?** | yes | yes |
| **pixel parity** | **is the render the same pixels as the baseline?** | yes* | yes |
| vision (`--visual`) | does it *look* like the source? | no | no (model) |

\* deterministic **only** when the capture settings below are honoured; the
baseline recorder refuses to write when two captures of the same build differ.

## The scorecard — `verify` (every gate runs by default)

Every applicable gate runs by default, so a plain `verify` prints one table at
the end. `--all` remains an explicit alias; `--minimal` restricts the run to the
deterministic, browser-free gates (what the CI blocking step uses). A gate whose
browser is missing records `skip` rather than aborting the run.

```
Parity scorecard
  ✓ design language — 91% (threshold 0.6)
  ⚠ nav anchors — dead: #contact
  ...
  8/10 applicable gates passed (80%) — 1 warn, 1 fail, 1 n/a
  → 100% needs every gate APPLIED (baselines recorded) and PASSING.
```

| Status | Meaning | Counts against 100%? |
|---|---|---|
| `pass` | the gate ran and held | — |
| `warn` | ran, found a non-blocking defect | yes |
| `fail` | ran, found a blocking defect | yes |
| `skip` | **applicable but not applied** (no baseline) | yes |
| `n/a` | structurally inapplicable (outline-only site has no band ids to measure; no tracking block) | no |

100% is reachable only when every applicable gate has its baseline recorded and
passes — an unrecorded baseline can never masquerade as success. `--visual`
stays out of the scorecard: non-deterministic and credential-bound, so advisory.

## Text parity — `analyze --text` / `verify --text`

Baseline discipline mirrors `geometry`: capture once from the source, diff the
build against the stored copy, never touch the network at verify time.

**Normalisation** (idempotent, applied to both sides):

1. `NFKC` — ligatures, full-width forms, compatibility equivalents.
2. Punctuation folding — smart quotes → `'`/`"`, en/em dashes → `-`,
   ellipsis → `...`, NBSP and the full Unicode space family → space,
   zero-width/ZWJ/BOM removed.
3. Whitespace collapse + trim.
4. Lowercase. Builders apply `text-transform` in CSS, so **rendered** casing
   differs while the DOM text does not — case-insensitivity is correct, not
   sloppy.

**Extraction** (`htmlparser2`): skips `script`, `style`, `noscript`,
`template`, `svg`, `iframe`, `object`, `head`, `canvas`, `video`, `audio`,
plus `hidden`, `aria-hidden="true"`, `display:none` and `visibility:hidden`
subtrees. Block tags emit boundaries so `Alpha</p><p>Beta` does not fuse into
one token. `<img alt>` is opt-in (`--text-alt`) because rebuilds regenerate it.

**Comparison**: 3-word w-shingles (Broder 1997; Manning/Schütze, *Introduction
to Information Retrieval* §19.6 — the standard near-duplicate measure). Documents
shorter than the width collapse to one shingle.

- `coverage = |source ∩ built| / |source|` — **the gate**.
- `precision = |source ∩ built| / |built|` — reported, never gated.
- `jaccard = |∩| / |source ∪ built|` — reported for context.

Direction is deliberate: a rebuild that keeps all source copy and adds a CTA
is **not** a content regression, so a union-based Jaccard would mis-fire.
Thresholds: pass ≥ 0.90, warn ≥ 0.75, fail < 0.75 (near-duplicate practice;
a 10% omission is a real content loss).

**Per-band text** needs *addressable* band ids. The extractor tries
`[data-section-id]`, `#id`, `.elementor-element-<id>` and class-name matches.
Outline-only sites (WPBakery/Brando → `design.sections: []`,
`design.outline: 6`) have no addressable ids, so the baseline is page-level
and `verify` says so rather than pretending.

## Colour system — `analyze --colors` / `verify --colors`

The token block proves the CSS **carries** the colours; band flow proves each
band paints its baked background; the contrast audit proves the token pairs
meet WCAG. None of them prove the browser **renders** the source's colours on
the chrome. That gap is a real bug class: a themed header that ships with the
template default passes every other gate.

- Reads the computed `background-color` and `color` of `body`, `header`
  (`header`, `[role=banner]`, `.site-header`, `#masthead`,
  `.elementor-location-header`, `nav.navbar`, `.navbar`, `.header`) and
  `footer` (`footer`, `[role=contentinfo]`, `.site-footer`, `#colophon`,
  `.elementor-location-footer`, `.footer`) — the first ladder match wins.
- Measures **each colour scheme separately** (`--colors-modes light,dark`, the
  default): every mode gets its own browser context, so `prefers-color-scheme`
  is a real media query. This matters in both directions:
  - a **dark header in light mode** is a surface difference the mode-agnostic
    check would have missed;
  - a build that **invents a dark mode** the source never had is reported
    (virtuallyjamaica: source dark == light `#ffffff`/`#626262`, build dark
    `#171717`/`#f5f5f4`). If the source has no dark mode, measure light only
    (`--colors-modes light`) so the gate judges what the source actually has.
- Compares with **CIEDE2000** (Sharma, Wu & Dalal 2005; verified against their
  published test data): ΔE00 3 warns, 6 fails. ΔE00 ≈ 1 is just-noticeable,
  5+ is clearly visible.
- `transparent` surfaces are **skipped**, not compared — the gate cannot see
  the backdrop, and guessing manufactures both false passes and false fails.
- **A scheme the source never implemented is `n/a`, not a failure.** If the
  source's dark measurement equals its light measurement, it has no
  `prefers-color-scheme` support; a build that adds one is an enhancement.
  `implementsScheme()` decides this per profile, so 100% stays reachable for
  sources without dark mode while a source that *does* define dark is still
  gated.
- **Rendered body ink outranks the declared role** (`mergeTokens`): body copy is
  measured, extraction reads CSS. Precedence is defaults ← design block ←
  measured body ink ← explicit `profile.tokens` pins. An explicit pin always
  wins — check it first when the gate reports a body-colour miss
  (virtuallyjamaica pins `ink: #2a2a2a` while its body renders `#626262`).
- A region the source had but the build dropped is reported; `--strict-colors`
  makes it a failure. Absence is never a silent pass.

Computed styles are machine-stable where pixels are not, so the baseline is
plain data in the profile (`colors`), captured once by `analyze --colors` —
the same discipline as `geometry`.

## Pixel parity — `verify --pixel`

Two engines, because they fail differently:

1. **Per-pixel delta** — pixelmatch's YIQ metric with the anti-aliasing filter
   (Mapbox, ISC). `threshold` 0.1 default; the AA probe ignores pixels that
   are flat-neighbourhood edges in *both* images, which is what makes text
   rendering portable across machines.
2. **SSIM** — structural similarity over non-overlapping 8×8 luma windows,
   `C1=(0.01·255)²`, `C2=(0.03·255)²` (Wang et al., *IEEE TIP* 2004). Catches
   "same palette, flattened layout", which a per-pixel ratio can under-report.

Verdicts are two-sided: fail above `--pixel-max-diff` (default 0.001 = 0.1%)
**or** below `--pixel-min-ssim` (default 0.98); warn at 0.01% / 0.99.
Size mismatch is a **failure**, not a warning — mismatched viewports make every
downstream number a lie.

**Determinism recipe** (`lib/screenshot.ts`): `deviceScaleFactor: 1`,
`scale: 'css'`, `animations: 'disabled'` + a CSS kill switch,
`caret: 'hide'`, `reducedMotion: 'reduce'`, fixed `colorScheme`, `locale`,
`timezoneId`, `document.fonts.ready`, lazy-image scroll-through, image settle.
Verified: two independent captures of the same build = **0.000% / SSIM
1.0000** on all three viewports (virtuallyjamaica, 2026-11-09).

Deliberate omission: the clock is **not** faked. Playwright's clock API
replaces timers and can freeze hydration — a screenshot of a page that never
finished rendering. Use `--pixel-mask` for genuinely dynamic regions.

**Baseline workflow** — this is a *regression* gate:

```sh
# record a known-good build (refuses to write if the capture is noisy)
node scripts/forge.mjs verify site.json --target ./app --pixel --pixel-baseline
# every later run diffs against verify-artifacts/baseline-<viewport>.png
node scripts/forge.mjs verify site.json --target ./app --pixel
# or compare against the live SOURCE (near-clone work only)
node scripts/forge.mjs verify site.json --target ./app --pixel --pixel-live
```

Diff artifacts land in `<target>/verify-artifacts/pixel-diff-<viewport>.png`
(red = differing, faded = unchanged).

**Colour schemes:** `--pixel-mode light|dark|both` (default `light`). `both`
captures every viewport twice and names the artifacts `baseline-<viewport>-<mode>.png`
(unsuffixed names remain the light-mode legacy fallback). Baseline-recording
with `both` also runs the determinism check per scheme, and a failed comparison
is **re-captured once** — a lazy image or late font swap can land after settle;
a real regression fails twice.

**Mask, don't loosen.** When a region is noisy, mask it
(`--pixel-mask .carousel`); raising the global threshold hides real regressions
everywhere else (Percy/Chromatic/Visual Sentinel guidance).

## Measured on virtuallyjamaica.com (2026-11-09)

| Gate | Result |
|---|---|
| language parity | 91% PASS |
| band flow / facts / tokens | PASS |
| **text parity (page)** | **92.9% coverage PASS** — 666/717 shingles, precision 98.5%, jaccard 91.6% |
| text parity (bands) | page-level only (outline-only site) |
| **colour system** | **FAIL** — body/header text `#2a2a2a` vs source `#626262` (ΔE00 18.79); footer `#595959` vs `#626262` (ΔE00 3.27, warn); 2 transparent surfaces skipped |
| pixel determinism (build vs build) | 0.000% / SSIM 1.0000 — PASS |
| pixel vs LIVE source | 94.6% differ / SSIM 0.63 — FAIL by design |

The last two rows matter: a language-level rebuild is **not** a pixel clone
(source-vs-build pixel diffing is for near-clone work; the regression gate is
build-vs-baseline), and the colour gate caught a genuine defect the design
language could not: the extracted `text` role (`#2a2a2a`) is not the body
colour the source actually renders (`#626262`).

## Gotchas

- **PNG scope is enforced.** 8-bit, non-interlaced, colour types 0/2/4/6
  only. Palette and 16-bit PNGs throw rather than compare garbage.
- **Playwright is optional.** Language and text parity need no browser; pixel
  and geometry report a clear install hint when `playwright` is absent.
- **`--pixel-live` can be bot-blocked.** Bot-protected hosts may refuse the
  headless capture — drop `verify-artifacts/source-<viewport>.png` instead.
- **The baseline is an artifact, not profile data.** PNGs live in
  `verify-artifacts/`; keep them out of the profile JSON.

## Industry basis

- pixelmatch (Mapbox) — YIQ delta + anti-alias filter, 0.1 default threshold.
- SSIM — Wang, Bovik, Sheikh, Simoncelli, *IEEE TIP* 13(4), 2004.
- Playwright `toHaveScreenshot`: `animations: 'disabled'`, `caret: 'hide'`,
  `mask`, `scale`; defaults documented on PageAssertions.
- Percy / Chromatic anti-flake guidance: stabilise dynamic content, mask
  rather than loosen, control time-based elements.
- Broder (1997) shingling; Manning & Schütze §19.6 — w-shingles + Jaccard for
  near-duplicate detection; the same method duplicate-content QA tools use.
- Unicode NFKC + whitespace/punctuation normalisation before any text equality
  check (standard NLP pre-processing; NFKC alone does not touch whitespace).
