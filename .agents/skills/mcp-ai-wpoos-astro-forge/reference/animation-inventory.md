# Animation inventory (Proposal 142, 2026-11-12)

The forge identifies the source's animated effects, rebuilds finite layout-safe
CSS keyframes, and reports everything else. It does **not** vendor a JS
animation runtime.

## 1. The decision on REA

`github.com/morluto/rea` is **REA: Reverse Engineer Anything** — an MIT MCP for
native binaries, Electron/JS apps, .NET and websites. It is not an animation
library. Do not add it to the forge package or generated projects:

- it is an open-world, agent-driven investigation tool with native analyzers
  and browser capture; the forge's extraction is deterministic, SSRF-safe and
  build-time;
- its website analysis can still be an **operator-side** investigation step for
  a custom GSAP/canvas effect the static probe cannot explain;
- `document.getAnimations()` is the standards-based runtime inventory the forge
  needs, with no dependency.

## 2. What is measured

`lib/animations.ts`:

- **Static:** `@keyframes` (css-tree), declared `animation`/`transition`
  rules, library signatures (gsap, lottie, anime, motion, rive, three, pixi,
  swiper, splide, aos, wow, locomotive-scroll, rellax, vivus, typed, countup,
  odometer, vanilla-tilt), `<video>`, SMIL.
- **Browser:** `document.getAnimations()` at rest, 50% scroll and bottom. A
  fact carries `kind` (`css-keyframes`/`css-transition`/`waapi`), `name`,
  `bandId`, `target` (`band`/`media`/`control`/`text`/`other`), `mediaIndex`,
  `selector`, timing, `timeline` (`DocumentTimeline`/`ScrollTimeline`/
  `ViewTimeline`), `rangeStart`/`rangeEnd`, `properties`, computed `keyframes`.
- **`--animations-pages N`** repeats the browser probe on up to N sub-pages
  (`profile.pageAnimations`), because a one-pager's hover states and a
  product page's carousel do not exist on the homepage.
- **Static fallbacks travel with the fact:** a Lottie asset/poster, a `<video>`
  `src`/`poster` and a SMIL count — a JS/canvas effect is reported with what a
  no-JS rebuild CAN show.

### Range offsets are dictionaries, not strings

`Animation.rangeStart` / `rangeEnd` are `TimelineRangeOffset` dictionaries
(`{ rangeName, offset: CSSUnitValue }`); `String()` yields `[object Object]`.
The collector serializes them the way CSS writes them (`cover 10%`), normalizes
`normal`/`auto` to absent, and the emitter drops any malformed value. A
scroll-driven source fact therefore round-trips through
`animationRebuildCss()` into a rule Chrome actually binds (pinned by the
hermetic browser test).

## 3. Rebuild policy

| Source | Decision |
| --- | --- |
| `css-transition` | `mapped` — interaction-states pass |
| reveal/parallax band (121) | `mapped` — CSS `view()`/`scroll()` runtime. Phlox runs `AuxFadeInUp` from a **DocumentTimeline** (an IO-toggled class), so map by the name family (`fade|scale|mask|slide|zoom|blur|rotate|reveal|appear|animate__`), not by timeline |
| infinite (`iterations: infinite`) | `deferred` by default — WCAG 2.2.2 needs a pause/stop control. With `allowInfinite` (the template's page-level **Pause motion** control) a finite-loop or infinite effect may rebuild |
| target `control` | a button-like `<a>`/`<button>` (`button|btn|cta|aux-button|aux-call`) rebuilds onto `.ds-button`/`.call-button`; any other control defers |
| target `text` | an `h1`–`h6` whose properties are paint-only (`opacity`/`color`/`background-color`) rebuilds onto `.ds-title`/`h1..h3`; a layout-animated heading defers |
| target `other` | `deferred` — the rebuilt node is not the source node |
| non-compositor prop (`width`, `top`, `margin`…) | `deferred` — layout jank |
| finite + safe + band/media | `css` — scoped `@keyframes` rebuild |

Safe: opacity, transform/translate/scale/rotate, filter/backdrop,
background-position/color/image, color, border-color, box-shadow, text-shadow,
outline-color, stroke, fill.

The generated rule is gated on `@media (prefers-reduced-motion: no-preference)`
and `animation-timeline` stays a **longhand** (the 121 minifier trap). The
`prefers-reduced-motion` gate is also the base-state contract: the rebuilt
element's UNANIMATED state is the finished state (no `from` frame is applied
outside the media query), so a reader who asks for less motion sees the page,
not a blank element. Pinned in the browser test (`reduce` → 0 animations,
opacity 1; `no-preference` → the rebuild runs).

When the generated module reports `hasInfiniteMotion`, `Base.astro` renders a
page-level **Pause motion** checkbox (`nav.pauseMotion`); `global.css` pauses
every generated animation via `body:has(.motion-toggle input:checked)` — the
WCAG 2.2.2 control, without a JS runtime.

## 4. CLI + report

- `analyze <url> --animations [--write <profile>]` — measures and stores
  `profile.animations`. Without `--design` it reads the committed profile's
  `design.behavior` so the reveal mapping is correct.
- `verify <profile> --target <dir> --animations` — re-measures the built page
  and matches the deterministic `forge-anim-<hash>` names. Two refinements
  since the first cut:
  - the built HTML (the page's own `<style>` block) is the **presence fallback**:
    a finished animation falls out of `getAnimations()`, so a generated
    `@keyframes forge-anim-<hash>` in the served page counts as present;
  - a matched fact also diffs **timing** (`duration` ±5%/50ms, `iterations`,
    `easing`) and reports `warn`, never a failure — a re-rendered carrier may
    legitimately round a duration.
  Default **advisory** (`verify.gates["animated effects"]: "gate"` opts in); a
  JS runtime cannot be reproduced by a no-JS template, so mapped/deferred facts
  never fail. jayainc records `"gate"` (all 32 source facts are mapped; a
  future CSS rebuild that goes missing should fail the run).
- `MOTION.md` gains an **Animated effects** table with the rebuild/deferred
  reason and the detected runtimes, plus a **Per-page animated effects** table
  for `--animations-pages`.
- `animations.generated.ts` carries `animationCss`; `DesignRenderer` appends it
  to the band `<style>` block. The drift manifest hashes the file.

## 5. Tests

- `tests/animations.test.ts` — the pure half (extraction, signatures, planner,
  emitter guards, diff, generated report).
- `tests/animations-browser.test.ts` — a hermetic fixture page proves the
  browser half end-to-end: a CSS keyframe on a band image, a WAAPI animation
  (infinite → normalised), an IntersectionObserver reveal that only appears on
  the scroll pass, and a `scroll(root)` + `cover 10% cover 90%` animation whose
  emitted rebuild CSS is served back and re-measured (`ScrollTimeline`,
  `diff.missing` empty). A second block proves the reduced-motion base state.
  Skips (never silently passes) when Chromium is absent; CI installs it before
  `npm test`.
- `tests/forge-e2e.test.ts` — a fixture profile with `fixture-pulse` (finite)
  and `fixture-spin` (infinite) asserts the built page carries the scoped rule,
  the `motion-toggle` control, the `ds-media` carrier on real `<img>` elements
  (after the media mirror), and the `MOTION.md` rows.

## 6. Gotchas

- `getTiming().iterations` is `Infinity` for an infinite animation — JSON
  turns it into `null`; normalize to the string `'infinite'` and the `infinite`
  boolean.
- `getKeyframes()[i].computedOffset` is 0-1 — convert to `%` for CSS.
- **`Animation.rangeStart`/`rangeEnd` are dictionaries** (`{ rangeName, offset:
  CSSUnitValue }`) — `String()` gives `[object Object]`, which once reached the
  emitted CSS verbatim (`animation-range:[object Object] [object Object]`, an
  invalid declaration the browser silently ignores). Serialize the numeric
  value + unit, and drop `normal`/`auto`.
- The animation name is `animationName` on `CSSAnimation` and
  `transitionProperty` on `CSSTransition`; a script animation has only `id`.
- `effect.target` can be a `CSSPseudoElement` — use `.element` (or the host
  element) before `closest`.
- Measure after scrolling: a class toggled by IntersectionObserver is invisible
  at rest. The probe collects at 0%, 50% and 100% and dedupes.
- Never scan the whole HTML for library prose; only inline script text +
  `<script src>` URLs + explicit markup attributes/classes.
- An e2e/prod template without `src/lib/animations.generated.ts` skips the
  write (the builtin fixture); the production template ships the stub.
- The `animated effects` gate is in `FORGE_ADDRESSABLE_GATES`; keep the
  gate-policy test's list in sync when adding one.
- The rebuilt carriers must survive the media pass: `localize-media.mjs`
  rewrites `src`/`srcset` only and `SmartImage` puts the class on its `<img>`
  (never a `<picture>` wrapper), so `.ds-media:nth-of-type(n)` keeps resolving —
  the e2e asserts a real `<img class="ds-media ds-collage-media" src="/media/…">`
  rather than the selector text alone.
- The CSS ships in an inline `<style>` (Astro cannot extract dynamic CSS); a
  CSP that drops `style-src 'unsafe-inline'` blocks the whole design rebuild.
  See `reference/csp-client-navigation.md`.
