# Parallax element pass (2026-11-12)

The 121 pass detected parallax at the **band** level. It over-fired: Phlox
writes `aux-parallax-section` on 24 columns of jayainc's home, but only **2**
elements actually move. The element pass fixes detection and rebuilds the
source's multi-plane motion.

## 1. What the source actually does

The theme's `AvertaParallaxBox` (`phlox-pro/js/plugins.min.js`) is the ground
truth, not the class name:

```js
// AuxinParallaxSectionInit
$scope.each(function () {
  var $box = $( this );
  if ( ! $box.find( '.aux-parallax-section' ).length ) {
    $box.AvertaParallaxBox( { targets: 'aux-parallax-piece' } );
  }
} );

// AvertaParallaxBox#_setPosition
var depth = $target.data( 'parallax-depth' ) || 0.5;
var absDepth = Math.abs( depth );
depth = depth < 0 ? 1 : -1;            // <- sign FLIP
var disablePoint = $target.data( 'parallax-off' );
if ( disablePoint >= window.innerWidth ) { /* clear transform */ }
switch ( origin ) {
  case 'top':    value = Math.min( 0, spaceFromTop * absDepth ); break;
  case 'bottom': value = Math.max( 0, spaceFromBot * absDepth ); break;
  case 'middle': value = spaceFromMid * absDepth
}
translateY( value * depth );
```

Consequences:

- `aux-parallax-section` is a **container marker**, not motion. With no
  `aux-parallax-piece` descendants the plugin's `init` returns immediately.
- A negative `data-parallax-depth` renders a **positive** translateY. Measured
  on the live jayainc about collage at scroll 1800: depth `-0.1` →
  `translateY(+81.44px)`, depth `+0.1` → `translateY(-81.44px)`.
- `data-parallax-origin` decides the shape of the travel: `bottom` 0 → +range,
  `top` 0 → -range, `middle` -range → +range (before the depth sign).
- `data-parallax-off` disables the effect at `innerWidth <= off` (so `1024`
  means the motion stops at 1024 and resumes at 1025).

Elementor Motion Effects (`data-settings` `motion_fx_*`) are the second source.
Its `direction: "negative"` **also** renders a positive translateY, so the
renderer treats `negative` as `+1` and `positive` as `-1`. `speed.size` is the
px speed, `affectedRange` narrows the viewport window and `motion_fx_devices`
scopes the breakpoints.

## 2. The fact model

`lib/types.ts` → `ParallaxPiece` on `ScrollBehaviorEntry.pieces[]`:

| Field | Source | Meaning |
| --- | --- | --- |
| `id` | `elementor-element-<id>` / `data-id` | source element (diagnostics) |
| `src` | the first `<img>` under the piece | media identity |
| `index` | 0-based ordinal among the band's `<img>`s | carrier mapping |
| `depth` | `data-parallax-depth` / `data-rellax-speed` / … | magnitude + sign |
| `origin` | `data-parallax-origin` | top / bottom / middle |
| `off` | `data-parallax-off` | disable at `innerWidth <= off` |
| `direction` | `motion_fx_*_direction` | negative / positive |
| `speed` | `motion_fx_*_speed.size` | px speed |
| `range` | `motion_fx_*_affectedRange.sizes` | viewport % window |
| `devices` | `motion_fx_devices` | desktop / tablet / mobile |

`analyzeScrollBehavior` rolls every fact up to the nearest
`elementor-top-section` — **inner sections are layout, not bands**. A piece in
an inner section used to attach to `elementor-element-<inner id>`, which the
renderer never wraps, so the fact was lost.

A band is parallax when it has **pieces**; a `background-attachment: fixed`
rule is the one band-level exception. `aux-parallax-section` alone is ignored.

## 3. The rebuild

`templates/blog/src/lib/motion.ts` (pure) turns a piece into a scoped rule:

```css
@media (min-width:1025px){
  [data-section-id="d5844b9"].ds-parallax .ds-media:nth-of-type(1){
    --ds-parallax-from:0px;
    --ds-parallax-to:48px;
  }
}
```

- `--ds-parallax-range` stays the band-level `speed × 24px` contract; a piece's
  depth scales it (`|depth| / 0.1`), clamped to 8–96px.
- `global.css` keyframes read `--ds-parallax-from` / `--ds-parallax-to`, with
  the old symmetric even/odd pair as the fallback for a piece-less band.
- The scoped prefix repeats `.ds-parallax` so a piece rule outranks the
  fallback `:nth-child(even)` rule (equal 3-class specificity otherwise).
- `@media (min-width: off + 1)` honours `data-parallax-off`; a desktop-only
  `motion_fx_devices` list maps to 1025, desktop+tablet to 768, mobile-only to
  `max-width: 767px`.
- `renderer` mounts **all** behaviors for a band. The old single
  `behaviors.find(...)` returned whichever entry came first, so a band that was
  both reveal and parallax lost one class.

## 4. Industry standards applied

- **CSS scroll-driven animations, not scroll listeners.** `scroll(root)` +
  `animation-timeline` runs off the main thread; `view()` drives the reveals.
  MDN: <https://developer.mozilla.org/en-US/docs/Web/CSS/CSS_scroll-driven_animations>
  Chrome: <https://developer.chrome.com/docs/css-ui/scroll-driven-animations>
- **`animation-timeline` must stay a longhand.** Chrome drops the whole
  `animation` declaration when a timeline function appears inside the shorthand,
  and lightningcss merges it back. The template pins `cssMinify: 'esbuild'` and
  the e2e asserts the longhand survives.
- **`prefers-reduced-motion` is the contract.** The motion is additive; the
  at-rest page is complete. MDN:
  <https://developer.mozilla.org/en-US/docs/Web/CSS/@media/prefers-reduced-motion>
- **WCAG 2.2.2 / 2.3.3.** Non-essential motion is gated behind
  `prefers-reduced-motion: no-preference`; parallax never carries essential
  content. <https://www.w3.org/WAI/WCAG22/Understanding/pause-stop-hide.html>,
  <https://www.w3.org/WAI/WCAG22/Understanding/animation-from-interactions.html>
- **`translate` (the independent property), not `transform`.** The band
  full-bleed centering uses `transform: translateX(-50%)`; a keyframe
  `transform` would replace it. `translate` composes. MDN:
  <https://developer.mozilla.org/en-US/docs/Web/CSS/translate>
- **Honor the source's mobile gate.** Parallax on a touch device is both a
  performance and a legibility problem; the source's `data-parallax-off` and
  `motion_fx_devices` are the measured answer, not a guessed breakpoint.
- **No JS shim.** `@supports (animation-timeline: scroll())` leaves the static
  page in place on older browsers.

## 5. The lede width fix (the "weird left padding")

The template had `.ds-lede { max-width: 50%; margin-inline: auto }` globally.
The source hero's description is 673px inside a 1345px row, so the 50% rule was
right for a **centered** band and wrong everywhere else: jayainc's services
paragraph measured 640px inside a 640px column. The paragraph therefore started
a quarter of the column in on desktop.

Now only `.ds-body--center .ds-lede` and `.ds-section-wrap--hero .ds-lede` are
constrained; a left-aligned split band keeps the full column. Verified on the
built site: the services `.ds-lede` is `max-width: 100%`, 492.56px wide inside
a 492.56px content column (was 262px with 131px side margins).

## 6. Validation

- `tests/design.test.ts` — a bare `aux-parallax-section` is NOT parallax; inner
  pieces roll up to the top band; depth/origin/off/direction/speed/range/
  devices are captured per piece.
- `tests/motion.test.ts` — the fact → CSS mapping (sign, endpoints, gates,
  ranges, carriers) and the `global.css` contract.
- `tests/forge-e2e.test.ts` — the generated project runs `astro check` + build;
  the split hero carries `ds-parallax ds-reveal`, and the built HTML contains
  the per-piece rules with the `min-width:1025px` gate.
- Live jayainc rebuild — the About collage's two photos translate
  `+10.99px` / `-10.99px` at scroll 1200 (opposite directions), and the
  services paragraph is full-width.
