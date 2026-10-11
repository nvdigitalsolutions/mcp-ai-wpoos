# Chat Chart Recipes — Plan

Schema-driven, HTML/CSS chart rendering for NV oOS chat surfaces, inspired by
[rhp (Reactive HTML Plots)](https://rhp.vercel.app/start/introduction/) and its
[AI Recipes gallery](https://rhp.vercel.app/gallery/#ai-recipes).

**Status:** Implemented (base chat + Pro SPA v2 + chat-spa; standalone Vite SPA
inherits via source alias). See `## Implementation status` at the bottom.

---

## 1. Problem

Assistants want to answer "show me sales by month" with an actual chart. Today
the only chart path is *tool-generated* HTML rendered inside a sandboxed iframe
loading chart.js from a CDN (`MessageView` `P8`/`F8` in spa-v2; `__chart-block`
in base chat). That path:

- requires network access to jsDelivr (breaks offline/air-gapped sites),
- renders in an iframe, so it cannot inherit chat theming,
- is only reachable through tools, not through the assistant's own text.

rhp demonstrates that expressive charts (bars, lines, areas, dots, donuts,
heatmaps) can be assembled from plain HTML slats styled with CSS — no SVG, no
canvas, no libraries. We adopt that model for *assistant-authored* content.

## 2. Research synthesis (industry standards)

- **Generative UI** (Vercel AI SDK `streamUI`, Google A2UI, the
  `narrowin/awesome-generative-ui` catalogue): the LLM emits **structured data**,
  the client renders it. Models never emit trusted HTML; the client owns all
  markup. Our fence+JSON schema is this pattern, expressed in plain markdown so
  it works across OpenAI/Gemini/Ollama providers with no framework changes.
- **rhp**: charts are "stacks of slats" — `<Label>/<Bar>/<Dot>/<Tick>/<Cell>`
  blocks placed proportionally per row; theme via CSS custom properties.
- **XSS consensus**: model output is untrusted. The safe pattern is
  *sanitisation by construction* — build the chart DOM with `createElement`
  + `textContent` and fixed class names, and inject it **after** DOMPurify,
  rather than widening the DOMPurify allowlist. We keep every existing
  allowlist untouched.
- **Accessibility**: slats remain real DOM text (screen-reader friendly);
  the chart container carries `role="figure"` + `aria-label`.

## 3. The contract (schema)

A fenced code block with language `nvoos-chart` (backtick or tilde fences):

````markdown
```nvoos-chart
{ "type": "line", "title": "Sales", "labels": ["Jan","Feb","Mar"],
  "series": [ { "name": "2025", "values": [10, 14, 9] } ] }
```
````

```json
{
  "type": "bar | column | line | area | dot | donut | heatmap | histogram | box | strip | stem | violin | pie | waffle | unit | diverging | lollipop | dumbbell | slope | bullet | pyramid | waterfall | candlestick | gantt | sparkline | radial | dial | race | grid",
  "title": "optional, ≤120 chars",
  "caption": "optional, ≤120 chars",
  "labels": ["row / x labels"],
  "values": [12, 24, 18],
  "series": [ { "name": "A", "values": [1, 2, 3], "color": "#hex | rgb() | hsl()" } ],
  "data": { "items": [ { "label", "value" | "values" } ] },
  "mode": "grouped | stacked | 100",
  "direction": "horizontal | vertical",
  "sort": "asc | desc",
  "unit": "optional suffix",
  "animate": true | "race",
  "table": true | false,
  "target": "number (bullet charts)",
  "charts": [ "…specs…" ]
}
```

Rules (enforced in code, identical across surfaces):

- `type` is required and must be in the whitelist above (v1's six values still
  work unchanged); anything else renders as a normal code block (graceful
  fallback).
- `labels`: strings (non-strings are coerced), max 40 entries (20 for
  heatmaps), each ≤60 chars.
- `values` / each series' `values`: finite numbers only, max 120 entries,
  non-finite entries dropped. `series` max 12 entries.
- `values` is shorthand for a single unnamed series; `data.items` rows with a
  scalar `value` are coerced into one unnamed series + `labels`.
- `color` must match a strict `#hex` / `rgb()` / `hsl()` pattern, else the
  internal palette is used.
- No user string is ever placed inside an inline `style`; only computed numbers
  and our own `var(--…)` references are.
- Donut/pie use `labels` + first series; negatives clamp to 0.
- The full v2 schema (families, caps, per-type shapes) is specified in
  `docs/chat-chart-recipes-enhancement-plan.md` §3.

## 4. Rendering strategy (per type, all pure HTML/CSS)

| family | types | construction |
|--------|-------|--------------|
| basics (v1) | bar, column, line, area, dot, donut, heatmap | bar = rows of proportional slats (left-anchored for non-negative data, per-sign scaling around a center baseline for mixed signs); column = per-label groups with inter-series gaps, above/below a zero-line; line/area/dot = computed `(x%, y%)` dots with rotated connectors (angle corrected for the 16:10 aspect ratio); donut = `conic-gradient` ring + total; heatmap = CSS grid with `opacity: var(--v)` cells |
| distribution | histogram, box, strip, stem, violin | histogram = full-width column bars; box = whisker/min/median/max wireframe per group; strip/stem = points/stems per value; violin = mirrored Gaussian-KDE half-slices with a median dot |
| part-to-whole | pie, waffle, unit | pie = donut without the hole; waffle/unit = CSS-grid unit cells (≤400 / ≤60 cells) |
| ranking | diverging, lollipop, race | diverging = signed slats from a center axis; lollipop = stem + head dots; race = static final sorted frame (`animate: "race"` for replay surfaces) |
| change | dumbbell, slope, bullet, pyramid, waterfall, candlestick, gantt, sparkline | dumbbell = two series joined per row; slope = rotated before/after connectors; bullet = value bar vs `target` marker; pyramid = centered descending bars; waterfall = cumulative up/down bars with connectors; candlestick = OHLC wick/body per row; gantt = `[start, end]` spans; sparkline = word-space trend |
| special | radial, dial | single-value gauges (conic ring / clip + needle) |
| grid | grid | small multiples — ≤12 nested specs |

- Series colours come from an 8-step CSS-variable palette (`--…-chart-series-1…8`)
  so they adapt to theme; user colours are strictly validated.
- Max-absolute scaling per chart; zero lines via CSS pseudo-elements.
- All text via `textContent`; output serialized with `outerHTML` and spliced
  in **after** DOMPurify — the allowlist is never widened.
- Every chart auto-includes a collapsed `<details>` data table unless
  `table: false`; animations honour `prefers-reduced-motion`.
- Per-type construction details live in `docs/chat-chart-recipes-enhancement-plan.md` §3.

## 5. Surface map

| surface | file | action |
|---|---|---|
| Base plugin chat | `assets/js/chat-markdown-service.js` | segment-split `renderMarkdown`; import chart module |
| Base plugin chat (new) | `assets/js/chat-chart-recipes.js` | shared implementation (plain JS, esbuild-bundled) |
| Base styles | `assets/css/chat.css` | `wp-mcp-ai-chat__chart-recipe*` rules + series palette |
| Pro SPA v2 | `addons/pro/assets/spa-v2/src/components/shared/MarkdownContent.tsx` | same segment pipeline in `renderMarkdown` (every consumer — MessageView, OkfDrawer, Analytics, Workflows, JobCard — inherits it) |
| Pro SPA v2 (new) | `…/shared/chartRecipes.ts` | spec-identical TS port, prefix `nvoos-pro-spa-chart-recipe` |
| Pro SPA v2 styles | `…/src/styles/main.css` | chart rules + palette |
| chat-spa (legacy SPA) | `addons/chat-spa/src/api/markdown.ts` | same pipeline; prefix `nvoos-chat-spa-chart-recipe` |
| Standalone Vite SPA | `examples/nvoos-pro-spa-vite` | **no work** — it aliases spa-v2 sources via `@nvoos/pro-spa-v2` and inherits automatically |
| Tool-result chart iframes (`P8`/`F8`, `__chart-block`) | — | intentionally unchanged; they serve tool-generated HTML, not assistant text |

Known non-goal (documented): `assets/js/src/services/markdown.ts`
(`assets/js/dist/markdown.js`, docs-hub renderer) can adopt the same module
later; its callers don't need charts today.

## 6. Security model

1. Fences are extracted **before** marked, so chart JSON never flows through
   the markdown parser or DOMPurify.
2. Chart DOM is built exclusively with `createElement`/`textContent` and fixed
   class names; inline styles contain only computed numbers and our own
   `var()` references.
3. Invalid/unparseable fences fall back to the standard escaped code block.
4. Resource caps (40 labels, 12 series, 120 points, 240 heatmap cells) prevent
   DOM-blowup from hostile output.
5. Existing DOMPurify allowlists are untouched on every surface.

## 7. Verification

- Base: `tests/js/chat-chart-recipes.test.js` (jest + jsdom) — fence splitting
  (closed, unclosed, nested-in-code-fence), schema validation, XSS probes,
  fallbacks; `npm run lint:js`; `npm run build:js` (regenerates
  `chat-bundle.min.js`).
- spa-v2: `src/__tests__/chart-recipes.test.ts` (vitest + jsdom) — same matrix
  on the TS port; `npm run typecheck`; `npm test`; `npm run build`.
- Manual: render a `nvoos-chart` fence in base chat and the Pro SPA; check
  dark/light themes.

## 8. Implementation status

- [x] Plan (this document)
- [x] Base module + service integration + CSS + jest tests (21)
- [x] Pro SPA v2 module + MarkdownContent + CSS + vitest tests (21)
- [x] chat-spa mirror + tests (21)
- [x] Builds (base `build:js`, both SPA builds) + eslint (base, spa-v2, chat-spa)
- [x] Typechecks (spa-v2, chat-spa, standalone Vite app via alias)
- [x] Full suites: base jest (markdown subset 43), spa-v2 vitest (173), chat-spa vitest (43)
- [x] Manual render check (headless browser: bar proportions, negative styling,
      line segment geometry, donut stops, heatmap grid/opacity, plot ratio)

Standalone Vite SPA (`examples/nvoos-pro-spa-vite`) inherits automatically via
its `@nvoos/pro-spa-v2` source alias — typecheck verified, no code changes.

## 9. Adoption note

Assistant system prompts (per-assistant `_wp_mcp_ai_system_prompt` meta) should
mention the `nvoos-chart` fence so models know to emit it, e.g.:

> When showing data, render a chart with a fenced `nvoos-chart` code block
> containing JSON: `{ "type": "bar|column|line|area|dot|donut|heatmap",
> "title"?, "labels"?, "values"? | "series": [{ "name"?, "values", "color"? }] }`.

The v2 enhancement plan (`docs/chat-chart-recipes-enhancement-plan.md` §3)
adds 22 more types plus optional keys (`mode`, `sort`, `direction`, `unit`,
`animate`, `table`, `target`, `caption`, `data.items`). The `design-chart-recipes`
skill (`.agents/skills/design-chart-recipes/SKILL.md`) carries the full
copy-paste prompt snippet for prompt authors.

There is no central default-prompt template to update — prompts are authored
per assistant in the admin UI.

## 10. v2 enhancement — implementation status

Implemented per `docs/chat-chart-recipes-enhancement-plan.md` (see that file
for the schema, roadmap and verification gates):

- [x] All 22 new types (distribution, part-to-whole, ranking, change, special,
      grid/race) + v2 schema keys in all three renderer surfaces (base JS,
      chat-spa TS, spa-v2 TS canonical)
- [x] Bar/column gap fix (grouped bars no longer touch) + left-anchored
      positive bars + per-sign scaling for mixed-sign baselines
- [x] `data.items` row coercion (single series + labels) in all three renderers
- [x] Accessible `<details>` data table (unless `table: false`)
- [x] CSS entry animations with `prefers-reduced-motion` guards; race final
      frame is static
- [x] Full CSS for every type on all three surfaces (tokens, dark-theme aware)
- [x] `create_chart_fence` tool (base plugin, `includes/tools/`)
      — server-side validator returning a normalised fence + fallback table
- [x] `design-chart-recipes` skill (`.agents/skills/`)
- [x] Shared conformance fixtures: `addons/pro/assets/spa-v2/src/__tests__/chart-fixtures.json`
      (canonical) mirrored in `addons/chat-spa/src/__tests__/` and `tests/js/`,
      wired into all three suites
- [x] Tests: base jest 29, spa-v2 vitest 181, chat-spa vitest 51;
      PHP tool tests 9 (validation matrix); typechecks + eslint clean
