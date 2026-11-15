# Proposal 121 — Scroll-behavior pass (detect and rebuild scroll effects)

> Owner direction 2026-11-06: the rebuilt jayainc site is visually close
> (parity 87%, hero pixels ~equal, chrome + accents recovered) but the forge
> still rebuilds a **static** page — the source's motion (reveal-on-scroll,
> parallax, sticky header) is missing. The process should **loop through
> each element of the page** (one-pager) and classify its scroll behavior,
> the same way the semantic pass classifies sections and the chrome pass
> extracts the header/footer.

## 1. Research (2026-11-06)

- **What scroll effects a real source carries** (jayainc live DOM): the
  Phlox/Auxin theme uses `aux-appear-watch-animation` + effect classes
  (`aux-scale-up`, `aux-fade-in-up`, `aux-fade-in-right`, …) — **32 hits**
  on the homepage; `aux-parallax-section` on a hero column;
  `aux-logo-sticky`/`aux-logo-hidden` (sticky-header logo swap). Elementor
  Motion Effects: `data-settings` JSON with
  `motion_fx_motion_fx_scrolling: yes`, `motion_fx_translateY_effect: yes`,
  `motion_fx_translateY_speed: { size: 2 }`, `motion_fx_devices: [...]` —
  the scroll-tied (parallax) tier, plus entrance animations
  (`elementor-invisible` marks initially-hidden animated elements).
- **Elementor docs (Motion Effects / Vertical Scroll / Entrance Animations
  / Sticky)**: entrance animations play once; scrolling effects stay
  active (Vertical Scroll = the classic parallax, speed 1–4); sticky is a
  container/widget property. So the detection vocabulary needs three
  kinds: **sticky**, **reveal** (once), **parallax/scroll-tied** (continuous).
- **Rebuild technology (industry standards 2026)**: CSS **scroll-driven
  animations** (`animation-timeline: view()` for reveals, `scroll()` for
  parallax) have 90%+ support (Chrome 115+, MDN + css-tricks + multiple
  2026 round-ups) and need **zero JavaScript** — the mainstream replaces
  IntersectionObserver class-toggling for these patterns. The **golden
  rule** (buildmvpfast/css-tricks): author the finished state as the
  default and layer the animation only where supported — a browser that
  ignores `animation-timeline` renders correct, animation-free content.
  `prefers-reduced-motion` is the accessibility contract (ogBlocks:
  "accessibility fits into the contract, not into a cleanup task").
  IntersectionObserver + WAAPI (`awesome-reveal`, AOS, ScrollReveal) is
  the fallback tier; GSAP ScrollTrigger and motion (Framer) are the
  power tier — **none is a fit for the forge**: the generated project
  stays zero-dependency, so the runtime is a first-party CSS block with
  `@supports` gates (no JS island at all for the common cases).
- **Astro official skills/integrations**: none exists for scroll effects —
  Astro's only first-party primitive is `client:visible` (IO under the
  hood, a hydration gate, not an animation system). The well-maintained
  ecosystem options are the libraries above; for rebuild-not-replicate the
  first-party CSS runtime is both simpler and more robust.
- **Detection literature** (same corpus as 116): classify by *structure
  and declared facts* (classes, data-settings JSON, CSS rules), never by
  replaying vendor JS. The pass walks **every element** (the owner's loop
  idea) with the existing htmlparser2 walker, gathers per-element facts,
  and resolves them against the same-origin CSS — the same discipline as
  `analyzeSections`/`analyzeChrome`.

## 2. Design — the per-element behavior walk

### 2.1 The vocabulary (deterministic, name-mapped to canonical effects)

| kind | signals (any) | canonical effect | rebuilt as |
|---|---|---|---|
| `sticky` | `elementor-sticky` class; `aux-top-sticky`/`aux-logo-sticky`; CSS `position: sticky|fixed` on the element's id rule | — | CSS `position: sticky` |
| `reveal` | `aux-appear-watch-animation` + `aux-{effect}` class; `elementor-invisible`; `data-aos`/`wow`/`animate__animated`; entrance `motion_fx` | fade-up/down/left/right, scale-up, blur, fade (unmapped → fade) | `@supports` view() timeline, finished state as default |
| `parallax` | `aux-parallax-section`; `motion_fx_translateY_effect: yes`; `background-attachment: fixed` on the id rule | — (+ speed from `motion_fx_translateY_speed.size`, default 1) | `@supports` scroll() timeline |

Header stickiness is a **top-level flag** (`headerSticky`), not a section
entry — the header has no elementor id; `aux-logo-sticky` (or an
`elementor-sticky` header) sets it.

### 2.2 The walk

`analyzeScrollBehavior(input)` walks EVERY element of the rendered
homepage (htmlparser2, `withStartIndices`), and per element:
1. collects classes, `data-settings` (parse the `motion_fx_*` JSON),
   `data-aos`, and the element's `elementor-element-<id>`;
2. classifies kind + effect + speed;
3. resolves CSS facts (sticky position / fixed attachment) from the
   same-origin sheets against the element's id (reuses `RULE_RE`);
4. emits one entry per element with an id, keyed by the SAME
   `elementor-element-<id>` the sections pass uses — the renderer looks
   behaviors up by section id, exactly like `sections.generated.ts`.

The effects map is seeded but name-mapped (like 116): an unmapped
`aux-*` effect class still yields `reveal` with the generic `fade` —
unknown vendor effects never drop.

### 2.3 Scaffold + template

- `profile.design.behavior` (`{ headerSticky, entries }`) refreshes via the
  analyze merge path; the scaffold bakes
  `src/lib/behaviors.generated.ts` (template ships an empty stub).
- `DesignRenderer` looks each section up by id and adds the behavior
  classes: `ds-reveal ds-reveal--{effect}` / `ds-parallax` / `ds-sticky`.
- `Base.astro`: `headerSticky` → `.site-header--sticky` on the header.
- `global.css` (all inside `@supports`, `prefers-reduced-motion:
  no-preference`, finished state as default):
  - reveals: `animation-timeline: view(); animation-range: entry 0% entry 60%`,
    one keyframe per canonical effect;
  - parallax: `animation-timeline: scroll(root)` translateY by speed;
  - sticky: `.site-header--sticky { position: sticky; top: 0; z-index: … }`.

### 2.4 Gates

- Unit: fixture covering aux-reveal (mapped + unmapped), parallax
  (+ speed from motion_fx JSON), sticky (class + CSS rule), headerSticky,
  name-free fallbacks.
- E2E: the generated template typechecks with the stub; the behavior
  classes render in the built HTML for a fixture profile.
- Live: re-analyze jayainc → `behaviors.generated.ts` populated →
  rebuilt home carries `ds-reveal`/`ds-parallax` classes and the sticky
  header; verify with the browser + the advisory vision pass.

## 3. Non-goals

- No JS islands for the common cases (CSS-native first); a tiny
  IntersectionObserver shim is the documented fallback path for browsers
  without `animation-timeline`, not part of this pass.
- No mouse-tilt/3D/cursor effects (Elementor Mouse Track) — out of scope.
- No scroll-spy nav active states yet (needs the nav + section id
  pairing; the anchors already exist — a follow-up pass).

## 4. Files

- `lib/design.ts` — `analyzeScrollBehavior`, `BehaviorEntry`,
  `ScrollBehaviorPass` (+ `ForgeDesign.behavior` in `lib/types.ts`).
- `lib/scaffold.ts` — `renderBehaviorsGenerated` + template wiring.
- `templates/blog/src/lib/behaviors.generated.ts` — stub.
- `templates/blog/src/components/design/DesignRenderer.astro` — behavior
  classes per section.
- `templates/blog/src/layouts/Base.astro` — sticky header flag.
- `templates/blog/src/styles/global.css` — the `@supports` runtime.
- `tests/design.test.ts` — the behavior fixture suite.
- Skill: `mcp-ai-wpoos-astro-forge` gets the 121 section + gotchas.

## Addendum (2026-11-12) — element-level parallax

The shipped pass classified parallax from the `aux-parallax-section` class.
That class is a **container marker**: Phlox's `AvertaParallaxBox` returns when
the container has no `.aux-parallax-piece` targets. jayainc's home has 24
markers and 2 real pieces, so the rebuilt page drifted five bands that never
moved in the source.

The element pass (full contract:
`../.agents/skills/mcp-ai-wpoos-astro-forge/reference/parallax-element-pass.md`)
reads the source's own facts:

- **Pieces**: `aux-parallax-piece` + `data-parallax-depth/origin/off` (Phlox)
  and Elementor `motion_fx_*` scrolling JSON (`direction`, `speed`,
  `affectedRange`, `devices`). `ParallaxPiece` rides on
  `ScrollBehaviorEntry.pieces[]`; `src` + `index` bind it to a rebuilt media
  slot.
- **Sign**: Phlox flips the depth sign (`depth = depth < 0 ? 1 : -1`), so
  negative depth renders a POSITIVE translateY. Measured on the live collage:
  depth -0.1 → `translateY(+81.44px)`, depth +0.1 → `translateY(-81.44px)`.
  Elementor's `direction: negative` does the same.
- **Roll-up**: pieces inside `elementor-inner-section` elements attach to the
  nearest `elementor-top-section`; the renderer only wraps top-level bands.
- **Rebuild**: `templates/blog/src/lib/motion.ts` emits a scoped rule per piece
  (`--ds-parallax-from/to`, `animation-range` when affectedRange is non-default,
  `@media (min-width: off+1)` / device gate). `global.css` keyframes consume the
  endpoints; the symmetric even/odd pair remains the fallback.
- **All behaviors per band**: the old single `behaviors.find` returned the first
  entry only, so a band that was both parallax and reveal lost one class. The
  renderer now mounts every behavior and picks the first reveal.

One template fix rode along: `.ds-lede` was globally `max-width: 50%` with auto
margins. The source hero's description is 673px inside a 1345px row (centered),
but a left-aligned split band is full-width (jayainc services: 640px inside
640px), so every left-aligned intro paragraph was inset by a quarter of its
column. The constraint now applies only to `.ds-body--center` and
`.ds-section-wrap--hero`.

Standards basis (researched 2026-11-12):

- CSS scroll-driven animations for the timeline (main-thread-free):
  <https://developer.mozilla.org/en-US/docs/Web/CSS/CSS_scroll-driven_animations>,
  <https://developer.chrome.com/docs/css-ui/scroll-driven-animations>.
  `animation-timeline` stays a longhand (Chrome drops a shorthand carrying a
  timeline; lightningcss merges it back — the esbuild pin and the e2e assert).
- `prefers-reduced-motion` (WCAG 2.2.2 / 2.3.3): motion is additive, the
  at-rest page is complete. <https://developer.mozilla.org/en-US/docs/Web/CSS/@media/prefers-reduced-motion>
- `translate` as the independent property so the band's
  `transform: translateX(-50%)` centering survives. <https://developer.mozilla.org/en-US/docs/Web/CSS/translate>
- The source's own mobile gate (`data-parallax-off` / `motion_fx_devices`) is
  the measured breakpoint; no guessed device split.

Validation: `tests/design.test.ts` (container ≠ motion, inner roll-up, piece
facts), `tests/motion.test.ts` (fact → CSS), `tests/forge-e2e.test.ts` (astro
check + build + built rules), and the live jayainc rebuild (About collage
`+10.99px` / `-10.99px` at scroll 1200; services paragraph full-width).
