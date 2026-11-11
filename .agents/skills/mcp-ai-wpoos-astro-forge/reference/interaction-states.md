# Interaction states — measuring hover, focus and press

A rebuilt band is the source's own sanitized markup with the vendor stylesheet
gone. The resting page can look right and still feel dead: the CTA that darkened
on hover, the button that pressed in, the field that lit up on focus and the link
that underlined were all expressed in CSS the rebuild never ships. This is the
pass that records them.

Written from the virtuallyjamaica rebuild (2026-11-11), where the only interactive
feedback left on the page was the accordion's `+` marker the template draws
itself.

## 1. Stylesheets are not a faithful source — force the state

The rules live behind builder classes, `:hover` groups, theme variables and
media queries. Reading them means re-implementing the cascade, so instead the
analyzer **forces each pseudo-class in a real browser** and diffs the computed
style — the same API the DevTools `:hov` panel drives.

`CSS.forcePseudoState` is CDP-only (no Playwright equivalent), so the pass gets a
CDP session from the page Playwright already owns:

```ts
const cdp = await context.newCDPSession( page );
await cdp.send( 'DOM.enable' );
await cdp.send( 'CSS.enable' );
const { root } = await cdp.send( 'DOM.getDocument', { depth: 0 } );
const { nodeId } = await cdp.send( 'DOM.querySelector', {
  nodeId: root.nodeId,
  selector: '[data-forge-interaction-probe="12"]',
} );
await cdp.send( 'CSS.forcePseudoState', { nodeId, forcedPseudoClasses: [ 'hover' ] } );
```

Getting a `nodeId` is the only awkward part. `Runtime.evaluate` returns an
`objectId`, but the element has to be reachable from an expression — so each
group's representative is tagged with `data-forge-interaction-probe="N"`,
addressed by attribute, and every tag is removed in a `finally`. `depth: 0` is
enough: `DOM.querySelector` searches the whole subtree of the document node.

## 2. The state set, and why each one is forced that way

| State | Forced classes | Why |
| --- | --- | --- |
| `hover` | `hover` | pointer over the control |
| `focus` | `focus` | element focus (mostly fields) |
| `focusVisible` | `focus` + `focus-visible` | keyboard focus; `:focus` is not a substitute (MDN: `:focus-visible` landed everywhere in 2022) |
| `active` | `hover` + `active` | a source writes `:hover:active`; forcing `:active` alone reads the resting value and records nothing |

`hover` and `active` are emitted inside `@media (hover: hover)`. A touch device
synthesises hover on tap and then KEEPS it, so an unguarded hover rule leaves a
card stuck in its raised state — the most common interaction bug on mobile.

`:visited` is not captured (unreadable from script by design). Pseudo-elements
are not captured either: the rebuild does not own those nodes, and a marker that
matters (an accordion `+`) is drawn by the template.

## 3. Rest paint — a hover delta alone is a bug

The first cut recorded only the DELTA. On virtuallyjamaica that produced white
text on a light band: the source's CTA rests on a red pill and hovers to
transparent + white ink, but the rebuild's button had lost the red pill (it lives
in `.btn-small-white-background`, a vendor class with no utility mapping), so the
hover half painted onto a different-looking shell.

So each fact also carries its **rest paint**, measured: `color`,
`background-color`, gradients (never `url()` — the media gate owns assets),
border sides as a shorthand when drawn, `border-radius`, decoration, weight,
tracking and transform. `font-size` and `padding` are deliberately excluded —
the band typography and the measured box facts already carry them, and freezing a
desktop size pins a control the source scales.

Emitted order matters: rest paint first, then `:hover`, `:focus`,
`:focus-visible`, `:active`, so a state rule (same specificity plus a
pseudo-class) wins over its own base.

## 4. Two filters that are accessibility, not taste

- **A state that REMOVES the focus ring is dropped.** `a:hover { outline: 0 }` is
  the classic WCAG F78 failure (2.4.7 Focus Visible). Re-stating it would let a
  hovered control lose the ring the baseline grants. The filter is
  "outline is not drawn in this state → skip every `outline-*` declaration in
  it".
- **A decoration COLOUR with no decoration LINE is dropped.** It paints nothing;
  it only bloats the sheet.

## 5. The baseline every forged site gets

A source usually has no usable focus indicator at all, so `global.css` ships one
for every site (WCAG 2.2 / 2.1):

```css
body :is(a, button, input, select, textarea, summary, [tabindex]):focus-visible {
  outline: max(2px, 0.12em) solid currentColor;
  outline-offset: 2px;
}
```

`currentColor` is the whole trick: the control's own ink already meets text
contrast against its own band, so the ring does too. A fixed accent ring measured
**2.79:1** on virtuallyjamaica's light surface and would have failed 1.4.11
Non-text Contrast. `max(2px, .12em)` keeps the indicator at the 2 CSS px floor
for small text (WCAG 2.4.13 Focus Appearance) and scales with larger controls.

The baseline is `:focus-visible`, never `:focus` — a mouse click should not paint
a ring, a Tab must — and nothing in the sheet ever sets `outline: none`.

Reduced motion: the source's own `transition` is emitted **only** inside
`@media (prefers-reduced-motion: no-preference)`, and rebuilt content gets a
`transition-duration: 0.01ms` override under `reduce`. The state still changes;
the animation does not.

## 6. Measured on virtuallyjamaica (2026-11-11)

13 states across 5 bands. What the browser reported:

- **`#about` CTA** — rest `#dd3333` pill / `#e2e2e2` ink / 2px solid border → hover
  `transparent` + `#fff` ink → active adds
  `box-shadow: inset 0 3px 5px rgba(0,0,0,.125)` (Brando's press). All three now
  reproduce byte-for-byte against the source.
- **`#features` accordion titles** (`a` ×8, no classes) — the source's only hover
  rule was `outline: 0`, which the filter drops. The honest result is "no hover
  effect", not "a broken focus ring".
- **`#contact` fields** — white field, `#dfdfdf` border, focus border `#989898`.
- **`#hardware` gallery** — hover `#272727` ink + matching border colour.

Timing: ~5 s for 7 bands at 1440px (rest read for all probes in one round-trip,
then four states per signature). The pass caps at 16 signatures per band so a
pathological band cannot turn `analyze` into a minute of CDP chatter.

## 7. Commands and where the facts live

```bash
node scripts/forge.mjs analyze https://virtuallyjamaica.com \
  --geometry --write sites/virtuallyjamaica.json
# ✓ Interaction states: 13 state(s) on 5 band(s) — hover, focus, focus-visible, active.

node scripts/forge.mjs forge sites/virtuallyjamaica.json --target <dir>
cd <dir> && npm install && npm run build
```

Profile: `bandFacts[id].interactions[]` (`tag`, `classes`, `type`, `count`,
`base`, `transition`, `hover`, `focus`, `focusVisible`, `active`). An operator
can pin `design.sections[].interactions` instead, which wins over the
measurement.

Generated: `sections.generated.ts` → `{ selector, count, base, transition,
hover, focus, focusVisible, active }` — the selector is baked at scaffold time
(`a[class~="btn"][class~="btn-small"]`) so escaping lives in one place, and the
renderer only scopes it with `[data-section-id="…"]`.

## 8. Verifying a rebuild — the `interaction states` gate

`verify --interactions` (on by default outside `--minimal`) measures the BUILT
page with the same forced-pseudo-class pass and compares state **presence** with
the profile. It is an addressable gate: `profile.verify.gates` may downgrade it
to `advisory`, which still measures and reports.

```bash
node scripts/forge.mjs verify sites/<site>.json --target <dir> --interactions
# ✓ Interaction states: 13 state(s) across 5 band(s) — every control keeps its
#   hover/focus/press and shows a focus ring.
```

Three findings, and why they are graded differently:

| Kind | Severity | Meaning |
| --- | --- | --- |
| `state-missing` | **fail** | the source declared the state and the emitted rule no longer takes effect (cascade, class, sanitizer) |
| `focus-indicator-missing` | **fail** | a rebuilt control shows no keyboard focus ring (WCAG 2.4.7) |
| `control-missing` | warn | the band lost a state KIND entirely — the signature is gone AND nothing else in the band exposes that state |

What is deliberately NOT compared:

- **declaration strings.** The rebuild legitimately differs: the template adds
a focus ring the source never had, a pinned band ink changes a computed colour.
Comparing strings would fail on improvements.
- **signatures that changed but kept the behaviour.** The contact band is rebuilt
as the template form (`.ds-input` / `.ds-button`); its focus treatment is still
there, on different classes. The comparison follows the STATE, so this is not a
finding. Only a state kind with no carrier left in the band warns.
- **controls the source generated in JavaScript** (owl dots, sliders) when they
declare no states — they are outside the contract.

Proven on virtuallyjamaica (2026-11-11): a clean build reports `0 fail, 0 warn`;
removing ONE emitted `:hover` rule from the built HTML produces exactly

```
✗ Interaction states: band 1d5804b: "hover" state lost on a[class~="alt-font"]…[class~="inner-link"]
  interaction states: 1 fail, 0 warn (12 of 13 expected state(s) present)
```

and exit 1; the same run with `"interaction states": "advisory"` exits 0 and
prints the provenance on the scorecard line.

"Present" counts the EXPECTED states, not the built ones: the build adds states
the source never declared (the focus baseline), so a raw built count read
`18 of 13` and said nothing.

## 9. Manual spot-check (when the gate is advisory)

`verify --interactions` is the gate; these are the checks to run when it is
downgraded to `advisory` on a site, or when the finding needs to be seen rather
than read. In a Playwright probe:

1. hover the control and read `color` / `backgroundColor` / `boxShadow`;
2. press and hold, then read again (the inset shadow);
3. press Tab to the control and read `outline`, `outline-offset` and confirm
   `matches(':focus-visible')`.

A focus ring observed mid-transition reads `outline-offset: 0px` — the source's
`transition: all .2s` animates the offset too. Wait ~250 ms before judging it.

## 10. Gotchas

- `DOM.querySelector` needs `DOM.enable`; `forcePseudoState` needs `CSS.enable`.
- Forced states are per-node and persist until cleared — always force `[]` in a
  `finally`, or the next probe inherits them.
- Node ids are invalidated by DOM mutation. Do not tag/untag between the lookup
  and the read; tag everything first, then walk.
- `getComputedStyle` during a transition returns the interpolated value, so the
  transition killer (`transition-duration: 0s !important`) is installed before
  the state passes — after the rest read, which needs the transition string.
- A signature is `tag + sorted classes + input type`. Hashed builder classes
  (`brando_button_1526883929-2-91`) are stable per element, so a group's count
  tells you whether a selector covers one control or a whole row.
