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
  "type": "bar | column | line | area | dot | donut | heatmap",
  "title": "optional, ≤120 chars",
  "caption": "optional, ≤120 chars",
  "labels": ["row / x labels"],
  "values": [12, 24, 18],
  "series": [ { "name": "A", "values": [1, 2, 3], "color": "#hex | rgb() | hsl()" } ]
}
```

Rules (enforced in code, identical across surfaces):

- `type` is required and must be one of the six values above; anything else
  renders as a normal code block (graceful fallback).
- `labels`: strings (non-strings are coerced), max 40 entries, each ≤60 chars.
- `values` / each series' `values`: finite numbers only, max 120 entries,
  non-finite entries dropped. `series` max 12 entries.
- `values` is shorthand for a single unnamed series.
- `color` must match a strict `#hex` / `rgb()` / `hsl()` pattern, else the
  internal palette is used.
- No user string is ever placed inside an inline `style`; only computed numbers
  and our own `var(--…)` references are.
- Donut uses `labels` + first series; negatives clamp to 0.

## 4. Rendering strategy (per type, all pure HTML/CSS)

| type    | construction |
|---------|--------------|
| bar     | rows of proportional slats on a **center baseline** (signed values extend left/right, rhp `<Tick>`-style); value labels outside the slats |
| column  | per-label groups; bars absolutely positioned above/below a center zero-line |
| line    | dots at computed `(x%, y%)`; connectors as thin rotated segments (angle corrected for the plot's fixed 16:10 aspect ratio) |
| area    | line + per-point fill slats at low opacity |
| dot     | line without connectors (scatter) |
| donut   | `conic-gradient` ring + center hole (total) + legend rows |
| heatmap | CSS grid (template columns computed from series count); per-cell background layer with `opacity: var(--v)`, sign class for negatives |

- Series colours come from an 8-step CSS-variable palette (`--…-chart-series-1…8`)
  so they adapt to theme; user colours are strictly validated.
- Max-absolute scaling per chart; zero lines via CSS pseudo-elements.
- All text via `textContent`; output serialized with `outerHTML` and spliced
  in **after** DOMPurify — the allowlist is never widened.

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

There is no central default-prompt template to update — prompts are authored
per assistant in the admin UI.
