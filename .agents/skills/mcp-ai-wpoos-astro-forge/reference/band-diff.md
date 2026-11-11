# The band diff — where a height delta comes from (2026-11-11)

`verify --geometry` tells you a band is 15px taller than its source. That is the
**symptom**. The diagnosis is always the same manual step — walk both band trees,
compare the boxes, find the node that changed — and before this existed it was
re-done from scratch with a throwaway Playwright script every single time. On
virtuallyjamaica it was re-done four times in one session (features 1722 → 1621 →
1991 → 1540), each probe answering one question.

`forge verify --geometry` now runs it automatically for **every deviating band**
and always writes `verify-artifacts/band-diff.json`. A clean run pays nothing
(the pass is skipped when no band deviates).

## Why this shape — the industry precedent

The report deliberately copies the browser's own attribution model rather than
inventing one:

| decision | precedent |
|---|---|
| per-node `{ source, built }` rects | **Layout Instability API** — `LayoutShiftAttribution` reports `{ node, previousRect, currentRect }` per element |
| rank by impact, cap at **five** | the same API: "the five largest (as measured by impact on layout stability) sources are reported" |
| a 1–2px delta is rounding / a late font | [web.dev, *Debug layout shifts*](https://web.dev/articles/debug-layout-shifts) |
| a large downward delta usually means an inserted subtree | same article |
| the element **immediately preceding** a taller one is the likeliest cause | same article |
| element-scoped boxes, never a full-page pixel cloud | Percy/Chromatic compare "spatial positioning of elements by analysing bounding boxes"; Cypress recommends element-scoped visual diffs |
| text/JSON, not images | Playwright 1.60 added `boxes: true` to aria snapshots precisely so "an AI agent can reason about layout from text alone, without opening a single image" |
| deterministic capture | the Playwright visual-testing recipe: fixed device scale, animations/transitions killed, `document.fonts.ready` awaited, lazy images fetched |

Tree matching is by **structural key**, not tree-edit distance: Zhang–Shasha is
O(n²m²), and a raw-HTML band keeps its source class names, so a keyed walk
matches exactly where it can and reports `added`/`removed` where it cannot.

## Usage

```bash
# automatic: only the deviating bands
node scripts/forge.mjs verify sites/x.json --target ./x --geometry

# every band, every viewport (a full audit)
node scripts/forge.mjs verify sites/x.json --target ./x --geometry --diff

# how many per-band contributor nodes to print (default 5)
... --diff-limit 8

# where the report goes (default <target>/verify-artifacts/band-diff.json)
... --diff-json ./band-diff.json
```

## Reading the output

```
▸ Band diff 860a86e (#features) @ mobile — source 1525px · build 1540px (+15px)
  hot path  section.brando_row_1541163928-2-53 › div.container › div.row › .wpb_column › .vc-column-innner-wrapper
  +1360px  div.brando-inner-row-class                       build 1360 · source 0   · size change
  -168px   p.gray-text.padding-six-lr.text-medium            missing from build …    · subtree missing
  -85px    section.brando_row_1541163928-2-53                build 1440 · source 1525 · spacing (padding/margin)
  hint: The band WRAPPER adds 100px beyond its content (band padding and/or the measured `min-height` floor); the content inside moved -85px.
```

Four things to read, in order:

1. **The header line matches the geometry gate.** `sourceHeight`/`builtHeight` are
   the BAND ELEMENT's heights — the same numbers the gate compares.
2. **Wrapper vs content.** The rebuild wraps a raw-HTML band in
   `.ds-section-wrap > .ds-section`, so the band element and the source section
   are not the same box. `wrapperDelta` is what the wrapper adds (band padding
   and the measured `min-height` floor); `contentDelta` is what happened inside
   it. Above, the content is 85px too SHORT while the wrapper adds 100px — two
   independent bugs, not one.

   > A zero band delta can still hide a large content delta. On virtuallyjamaica
   > every non-deviating band reports `band 0` with `content −177…−508` because
   > the measured `min-height` (the scaffold's band-height floor) holds the total
   > while the rebuilt content is shorter. The gate cannot see that; the diff
   > does. Treat `contentDelta` as the fidelity number and `heightDelta` as the
   > gate number.
3. **`hot path`** is the root → leaf chain following the largest `|dH|` at each
   level — web.dev's "most likely root cause" line. Its last node is the deepest
   one whose own delta explains most of the band's.
4. **`hint:`** lines are the causal sentences: inserted subtree, spacing,
   width-driven reflow, sub-pixel rounding.

## The four rules that make the report trustworthy

- **Align to the source root first.** The two trees have different roots (the
  rebuild wraps the source `<section>`), so a raw keyed walk reports every node as
  added/removed. `alignToSourceRoot` finds the built node whose signature matches
  the source root and re-keys that subtree onto the source root's key. If it
  cannot, the report says so instead of pretending.
- **An ancestor is not a cause.** A wrapper whose height moved only because its
  content moved is a *carrier*: it is suppressed when a child's delta is within
  2px of its own. Without this the features band reported
  `vc-column-innner-wrapper +1389px`, which buried the real nodes.
- **A one-sided node's `dH` is its height.** There is nothing to subtract, so the
  height stands in as the impact proxy — a block that appears shifts everything
  below it by its own height. That is also how the Layout Instability API scores
  a shift (displacement, not size).
- **Hidden subtrees are skipped** (`display:none`), matching the API's caution
  about elements that are invisible but still laid out.

## Known limitations (by design)

- **Anonymous elements match by ordinal.** A `<p>` with no class has the
  signature `p`, so it is keyed `p[0]`, `p[1]`, … A paragraph removed mid-band
  shifts the ordinals and reads as one inserted + one removed. That is honest
  about what it can know; putting text in the key would make copy edits break
  matching instead.
- **A float-collapsed source is reported faithfully.** Brando's inner rows have
  uncleared floats on the live site, so a source wrapper can legitimately measure
  `51px` while its children are `369px`. The report is correct; the *source* is
  the odd one. Do not "fix" the rebuild to reproduce an uncleared float.
- **It is a diagnostic, never a gate.** A browser or source failure prints a
  warning and leaves the geometry verdict untouched (the hermetic e2e runs with
  no network at all).
