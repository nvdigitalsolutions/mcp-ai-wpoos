# Proposal 142 — Forge animation inventory

**Status:** Phase 1 shipped 2026-11-12.
**Scope:** identify the source's animated effects, rebuild the finite CSS ones,
report the rest. Never vendor a JS animation runtime into the generated site.

## 1. Problem

The 121/121a motion passes cover what a builder *declares*: reveal classes,
`motion_fx` parallax, sticky headers, hover/focus states. They cannot see:

- CSS `@keyframes` a theme runs on its own (Phlox's `AuxFadeInUp`,
  `AuxScaleUp`, `AuxMaskFromLeft`, marquees, pulses);
- script-driven Web Animations (`element.animate()`), GSAP/Lottie/Rive/Swiper
  runtimes, autoplaying video, SMIL;
- target-level timing (duration, easing, iteration count, delay, range).

A rebuilt band could therefore be complete but *still*, with no record of what
moved. The operator had no inventory to review and no gate to catch a lost
effect.

## 2. The REA question

`github.com/morluto/rea` is **REA: Reverse Engineer Anything** — an MIT-licensed
MCP for inspecting native binaries, Electron/JavaScript apps, .NET assemblies and
websites (page structure, scripts, network observations, screenshots). It is not
an animation library and has no design-extraction contract.

Decision: **do not add it to the forge package or generated projects.**

- It is an open-world, agent-driven investigation tool with native analyzers
  (Hopper/Ghidra/IDA), browser capture and frequent releases. The forge's
  extraction is deterministic, SSRF-safe and build-time; a research MCP would
  break that boundary and add a large dependency to every customer build.
- Its website analysis could still be an **operator-side** investigation tool
  for a custom GSAP/canvas effect the static probe cannot explain. That is a
  runbook step, not a runtime dependency.
- The forge's own standards-based route is stronger for this job: the browser's
  `document.getAnimations()` already exposes CSSAnimation/CSSTransition and
  script-driven Web Animations, with computed keyframes and timing.

## 3. What ships

### 3.1 Static inventory (`lib/animations.ts`)

- `extractKeyframes(css)` — `@keyframes` / `@-webkit-keyframes` blocks via
  css-tree (name → offset + declarations, bounded).
- `extractAnimationDeclarations(css)` — the declared `animation` /
  `transition` rules (report fallback when no browser runs).
- `detectAnimationLibraries(html)` — script-URL, inline-script and markup
  fingerprints for gsap, lottie, anime, motion, rive, three, pixi, swiper,
  splide, aos, wow, locomotive-scroll, rellax, vivus, typed, countup,
  odometer, vanilla-tilt. Prose is never scanned.
- Video/`<animate>` counts.

### 3.2 Browser probe (`measureAnimations`)

`document.getAnimations()` at rest, mid-scroll and bottom (the scroll positions
trigger IntersectionObserver/AOS classes). Each fact carries:

`kind` (`css-keyframes` / `css-transition` / `waapi`), `name`, `bandId`,
`target` (`band`/`media`/`control`/`text`/`other`), `mediaIndex`, `selector`,
`duration`, `delay`, `easing`, `iterations`, `direction`, `fill`, `playState`,
`timeline` (`DocumentTimeline`/`ScrollTimeline`/`ViewTimeline`), `rangeStart`/
`rangeEnd`, `properties` and the computed `keyframes`.

Fail-soft: no Playwright / a WAF block returns `{ facts: [], error }`; the
draft continues.

### 3.3 Rebuild planner (`planAnimationRebuild`)

| Fact | Outcome | Why |
| --- | --- | --- |
| `css-transition` | `mapped` | the interaction-states pass owns hover/focus/press timing |
| `css-keyframes` on a band with a 121 reveal/parallax entry | `mapped` | the CSS-native `view()`/`scroll()` runtime already rebuilds it (Phlox runs reveals from a document timeline, so `timeline` alone is not the test; the name family is) |
| infinite (`iterations: infinite`) | `deferred` | WCAG 2.2.2 needs a pause/stop control; `planAnimationRebuild(..., { allowInfinite: true })` is used when the template ships the page-level **Pause motion** control |
| target `control` | `css` only for a button-like `<a>`/`<button>` (`button|btn|cta|aux-button|aux-call`) → `.ds-button`/`.call-button`; anything else defers |
| target `text` | `css` only for an `h1`–`h6` with paint-only properties (`opacity`/`color`/`background-color`) → `.ds-title`/`h1..h3`; a layout-animated heading defers |
| target `other` | `deferred` — the rebuilt node is not the source node |
| non-compositor property (`width`, `top`, `margin`…) | `deferred` | layout jank; the keyframes would not be faithful |
| finite + safe + band/media | `css` | scoped `@keyframes` rebuild |

Safe properties: opacity, transform/translate/scale/rotate, filter/backdrop,
background-position/color/image, color, border-color, box-shadow, text-shadow,
outline-color, stroke, fill.

### 3.4 Render

`animations.generated.ts` carries `animationCss` (deterministic; scaffold writes
it, the drift manifest hashes it). `DesignRenderer` appends it to the band
`<style>` block. The rule:

```css
@keyframes forge-anim-<hash>{…}
@media (prefers-reduced-motion: no-preference){
  [data-section-id="x"] .ds-media:nth-of-type(1){animation-name:…;animation-duration:…}
}
```

`animation-timeline` stays a separate longhand (Chrome drops a shorthand that
contains a timeline; lightningcss merges it back — the 121 minifier gate).

### 3.5 Report + gate

`MOTION.md` gains an **Animated effects** table (band, effect, target, timing,
rebuild/deferred reason) plus the detected runtimes.

`analyze --animations` writes `profile.animations`; `verify --animations`
re-measures the built page and compares the generated `forge-anim-<hash>`
names. The gate is **advisory by default** (`verify.gates["animated effects"]`
can opt into blocking) because a JS-driven source effect cannot be reproduced
by a no-JS template. Mapped and deferred facts never fail.

## 4. Industry standards

- `document.getAnimations()` / `Animation.effect.getKeyframes()`:
  <https://developer.mozilla.org/en-US/docs/Web/API/Document/getAnimations>,
  <https://developer.mozilla.org/en-US/docs/Web/API/KeyframeEffect/getKeyframes>
- Scroll-driven animations / `animation-timeline`:
  <https://developer.mozilla.org/en-US/docs/Web/CSS/CSS_scroll-driven_animations>
- `prefers-reduced-motion`:
  <https://developer.mozilla.org/en-US/docs/Web/CSS/@media/prefers-reduced-motion>
- WCAG 2.2.2 Pause, Stop, Hide:
  <https://www.w3.org/WAI/WCAG22/Understanding/pause-stop-hide.html>
- WCAG 2.3.3 Animation from Interactions:
  <https://www.w3.org/WAI/WCAG22/Understanding/animation-from-interactions.html>

## 5. Files

- `lib/animations.ts` — extraction, probe, planner, CSS emitter, diff.
- `lib/types.ts` — `ForgeAnimationFact`, `ForgeAnimationInventory`,
  `SiteProfile.animations`.
- `lib/scaffold.ts` — `renderAnimationsGenerated`, `MOTION.md` table, drift
  manifest.
- `templates/blog/src/lib/animations.generated.ts` + `DesignRenderer.astro`.
- `lib/gate-policy.ts` — `animated effects` (advisory default).
- `scripts/forge.mjs` — `analyze --animations [--animations-pages N]`, `verify --animations`.
- `tests/animations.test.ts` (pure) + `tests/animations-browser.test.ts` (hermetic
  Chromium: CSS keyframe, WAAPI, IO reveal, scroll-range round-trip,
  reduced-motion base); e2e fixture + asserts.

## 6. Deferred

- GSAP/Lottie/Rive/Swiper runtime replication (no vendor JS in the template).
- A canvas/WebGL effect reconstruction (operator investigation, e.g. with REA,
  not a deterministic forge pass).
- Per-frame easing fidelity for `matrix()` keyframes — the computed keyframes
  are emitted as measured; a viewport-specific matrix is not resampled.

## 7. Shipped state and verification (2026-11-12)

Everything in §3 ships, plus four items the implementation added:

1. **Control/text carriers.** A button-like control rebuilds onto `.ds-button`
   / `.call-button`; a paint-only heading animation rebuilds onto `.ds-title` /
   `h1..h3`. A generic `div` and a layout-animated heading still defer — the
   rebuilt node is not the source node.
2. **The WCAG 2.2.2 pause control.** `hasInfiniteMotion` makes `Base.astro`
   render a **Pause motion** checkbox; `global.css` pauses every generated
   animation with `body:has(.motion-toggle input:checked)`. Infinite motion can
   therefore be planned with `allowInfinite` instead of being deferred forever.
3. **Per-page inventory.** `analyze --animations-pages N` measures sub-pages
   into `profile.pageAnimations`; `MOTION.md` prints a per-page table.
4. **Static fallbacks.** A Lottie asset/poster, a video `src`/`poster` and SMIL
   survive on the fact, so a deferred runtime still tells the operator what the
   no-JS rebuild can show.

Verification (all run 2026-11-12):

- `npm test` — 1284 pass, including the new hermetic browser suite;
  `npm run typecheck` clean; `npm run test:e2e` 33 pass.
- **Hermetic browser fixture** (`tests/animations-browser.test.ts`): CSS
  keyframe on a band image, WAAPI (infinite → `'infinite'`), an
  IntersectionObserver reveal discovered only on the scroll pass, and a
  scroll-driven animation round-tripped through the emitter back into Chrome
  (`ScrollTimeline`, `cover 10% cover 90%`, diff clean). A second block proves
  the reduced-motion base state (0 animations, opacity 1) and that the rebuild
  runs under `no-preference`.
- **The fixture caught a real bug:** `Animation.rangeStart` is a
  `TimelineRangeOffset` dictionary, so the first collector wrote
  `[object Object]` into `animation-range` — invalid CSS, silently dropped by
  the browser. Fixed in the collector + guarded in the emitter.
- **jayainc fresh rebuild** (`verify --animations --geometry`): 9/9 bands pass
  the per-band audit, box geometry 0 fail across 9 bands × 3 viewports, fact
  consumption clean, 32 animation facts all mapped (0 CSS rebuilds). The
  profile now records `verify.gates["animated effects"] = "gate"`: with no
  expected rebuilds it passes today, and a future finite keyframe that goes
  missing will fail the run.
