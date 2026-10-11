# nvoos-chart Enhancement Plan (v2)

Schema-driven, HTML/CSS chart rendering for NV oOS chat surfaces — expansion of
the implemented baseline documented in [`chat-chart-recipes-plan.md`](chat-chart-recipes-plan.md).

**Status:** Plan (proposal). Baseline v1 is implemented on 3 surfaces (base chat,
Pro SPA v2, chat-spa) with 7 chart types.

---

## 1. Baseline recap

Implemented today (v1):

- Fence ` ```nvoos-chart` with JSON `{ type, title?, caption?, labels?, values? | series[{name?, values, color?}] }`.
- 7 types: `bar`, `column`, `line`, `area`, `dot`, `donut`, `heatmap`.
- Pure HTML/CSS slats (rhp-style); sanitisation by construction; caps
  (40 labels / 12 series / 120 points); 3 surface implementations + tests.

Tool paths that exist alongside (and stay separate):

| path | purpose |
|---|---|
| `create_chart` / `create_chart_validated` | Chart.js HTML/JS (iframe), bar/line/pie/doughnut/radar/polarArea/scatter/bubble |
| `generate_chart` | AI-generated chart *images* |
| `generate_mermaid` | Mermaid diagrams (SVG) |

## 2. Research synthesis (industry standards, 2025–2026)

- **GitHub Copilot chart artifacts** (2025): the industry reference for
  LLM-emitted charts in chat. The model emits a small declarative JSON schema
  (Vega-Lite-inspired); the client renders interactive SVG. Design principles
  worth adopting: (a) one small, stable JSON grammar per artifact,
  (b) client-owned rendering, (c) built-in interactivity (hover values),
  (d) graceful degradation to text.
- **Vega-Lite grammar**: composition via *encoding channels* (x/y/color/size)
  rather than one type per permutation. Our v2 schema should grow a small
  number of orthogonal properties (`mode: grouped|stacked|100`, `sort`,
  `direction`) instead of a type per variant — this is what keeps the schema
  small enough for LLMs to emit reliably.
- **rhp gallery** (the project's north star): 23 plots + 34 AI recipes.
  Gap list vs v1: grouped/stacked/100% bars, diverging bars, waterfall,
  bullet, histogram, box & whisker, violin, strip, stem, dumbbell, Gantt,
  candlestick, radial bars, pie, waffle, lollipop, slope, sparklines,
  population pyramid, unit bars, log-line, dial, silhouettes, small multiples,
  and three *capability* recipes: animated dots, values-on-hover, and the
  animated bar race + live updating.
- **Motion**: WCAG 2.2 SC 2.2.2 (Pause/Stop/Hide) + `prefers-reduced-motion`.
  All animation must be CSS keyframes (transform/opacity only), honouring the
  media query — no JS animation loops except the `race` type (which still
  requires a pause control and reduced-motion fallback to a static final
  frame). Lottie-class JSON animation is *out of scope*: it needs a runtime
  and violates the no-dependency rule.
- **Accessibility** (WAI Complex Images tutorial, WCAG 2.2):
  - chart container keeps `role="figure"` + `aria-label` (already done),
  - every chart must offer a machine-readable data table alternative — we add
    an optional auto-generated `<details>` data table (SC 1.1.1),
  - colour alone never encodes meaning: divergent signs keep `+/-` glyphs and
    stacked modes keep legend swatches with labels (SC 1.4.1),
  - non-text contrast 3:1 (SC 1.4.11) — check series palette against both
    themes.
- **Generative UI** (Vercel AI SDK `streamUI`, Google A2UI): the fence is the
  transport; keep the JSON grammar as the only contract the model must learn.
  A *skill* + *system-prompt snippet* + *server-side validator tool* together
  raise model compliance without framework changes.

## 3. Schema evolution (backward compatible)

Keep v1 keys unchanged. Add orthogonal properties and new types:

```json
{
  "type": "… (v1 set ∪ new types below)",
  "title"?, "caption"?, "labels"?, "values"?,
  "series": [ { "name"?, "values", "color"? } ],
  "mode": "grouped | stacked | 100",        // bar/column only; default grouped
  "direction": "horizontal | vertical",      // bar/column
  "sort": "asc | desc | none",               // default none (respect input order)
  "unit": "string",                          // unit bars, ≤20 chars
  "animate": true | "race",                  // defaults false
  "table": true | false,                     // a11y data table, default true
  "data": { "items": [ {…} ] }?              // alternative input for named rows
}
```

New `type` values, grouped by family (rhp naming):

| family | types | notes |
|---|---|---|
| distribution | `histogram`, `box`, `strip`, `stem`, `violin` | violin = CSS-only approximation (stepped stem silhouette) |
| part-to-whole | `pie`, `waffle`, `unit` | `unit` needs `unit` key; `waffle` caps at 400 cells |
| ranking / comparison | `diverging`, `lollipop`, `dumbbell`, `slope`, `bullet`, `pyramid` | `dumbbell`/`slope` need paired series; `bullet` needs `target` |
| change / time | `waterfall`, `candlestick`, `gantt` | `gantt` uses `series[].items[{label,from,to}]` rows |
| special | `sparkline`, `radial`, `dial` | `radial` = radial bars; `dial` = single value vs max |
| animation | `race` | bar race; `animate: true` on bar/column/line for entry reveal |
| composition | `grid` | small multiples: `"charts": [ {…spec}, … ]`, max 12, shares `labels` |

Hard rules preserved from v1 (enforced identically everywhere):
type whitelist, string coercion + caps, numeric coercion + caps, strict colour
regex, computed-number-only inline styles, invalid input → escaped code block.

## 4. Cross-cutting capabilities

1. **Hover values** (`title`-free, CSS/ARIA-based): slats already carry real
   text; add `:hover`/`:focus` highlight + value reveal via sibling selectors
   (rhp "values on hover" recipe, recipe 04). No JS required on base surface.
2. **Animations**: CSS `@keyframes` entry reveal for bar/column/line when
   `animate: true`; `race` = JS-driven re-sort loop with pause/play button and
   `prefers-reduced-motion` fallback to final frame. Cap race steps (≤30
   states, ≤10 series).
3. **Data table alternative**: render a collapsed `<details><summary>` table
   under every chart unless `table: false`. Adds no security surface (built
   with `createElement`/`textContent`).
4. **Theming**: extend the `--chart-*` token set (axis, tooltip, table,
   positive/negative) so new types theme correctly; audit palette for 3:1
   contrast in both themes.
5. **Export**: "Copy data (CSV)" button on Pro SPA surface only (client-side
   clipboard from the parsed spec — no server call). PNG export via
   `html-to-image` is deferred (dependency + CSP cost) — revisit if requested.

## 5. Architecture changes

- **Canonical renderer registry** (both JS and TS ports): replace the current
  `switch(type)` dispatch with a `renderers: Record<type, (spec, prefix) => HTMLElement>`
  map; new types are additive without touching the splitter or the validation
  entry point.
- **Shared conformance fixtures**: `assets/js/chart-fixtures.json`
  (spec JSON → expected HTML assertions) consumed by all three test suites
  (jest base, vitest spa-v2, vitest chat-spa) to kill the x3 duplication drift
  that already exists between `assets/js/chat-chart-recipes.js`,
  `addons/chat-spa/src/api/chartRecipes.ts`, and
  `addons/pro/assets/spa-v2/src/components/shared/chartRecipes.ts`.
  Long term (out of scope here): one shared TS source compiled for all three.
- **Caps**: raise only where safe; new per-type caps documented in §3
  (waffle 400 cells, race 30 steps, grid 12 charts). Total DOM budget per
  message stays bounded (~≤500 chart elements).
- **Security model**: unchanged (fences extracted pre-marked; chart DOM built
  with `createElement`/`textContent`; fixed class names; computed-number styles;
  DOMPurify allowlists untouched). New types introduce no new string-into-style
  paths. `data.items` input follows the same coercion rules as `series`.

## 6. New tool: `create_chart_fence` (base plugin, `includes/tools/`)

Server-side counterpart that *validates and returns* a `nvoos-chart` fence —
for assistants that should produce charts programmatically, and for
tool-result charts that must degrade gracefully (markdown fence renders
everywhere the fence is supported; no iframe/CDN needed).

- Inputs: `type`, `title?`, `labels?`, `values?` or `series`, plus v2 keys
  (`mode`, `sort`, `unit`, `animate`, `table`, `data`).
- Behaviour: validates against the same rules as the client schema (PHP
  mirror, single source of truth documented in this file), returns the
  JSON-normalised fenced block string + a plain-text fallback table.
- Envelope: canonical success array / `WP_Error` (P0–P6 rules; two-gate
  sanitisation; PHPCS sniffs apply).
- Capability: `edit_posts` default; no network; deterministic.
- Tests: `tests/test-tool-create-chart-fence.php` (validation matrix mirroring
  the JS suites + oracle against shared fixtures where practical).
- Optional follow-up: `render_chart_preview` REST route (same validator,
  returns rendered HTML string for block/Elementor use) — phase 3.

## 7. New skill: `design-chart-recipes` (`.agents/skills/`)

Coding-time/assistant-time guidance that raises schema compliance and quality:

- when to use the fence vs `create_chart` (Chart.js) vs `generate_mermaid`
  vs `generate_chart` (image);
- chart-type selection guide (which type answers which question);
- data-shape rules (labels/series alignment, negatives, missing values);
- style rules (colours, ≤6 series, ≤7 labels for readability, sort guidance);
- accessibility checklist (title, table, contrast, reduced-motion);
- worked examples per family (copy-paste JSON templates).

Registered in the Zed skill library; the same text doubles as a system-prompt
snippet (update the adoption note in `docs/chat-chart-recipes-plan.md` §9 and
the skill covers the per-assistant prompt authors).

## 8. Diagrams & "infographics" scope decision

- **Diagrams**: `generate_mermaid` already covers diagrams via tool path.
  A client-side `nvoos-diagram` fence is *deferred*: mermaid v11 bundles are
  ~1.7 MB minified — acceptable only as a lazy-loaded, Pro-SPA-only, opt-in
  module. Track as phase 4, gated on a bundle-budget check.
- **Infographics**: not a new fence. Composed from (a) markdown headers/callout
  text + (b) the new `grid` (small multiples) type + (c) `waterfall`/`bullet`
  for narrative numbers. This keeps one schema, one renderer, one security
  model.
- **Animations**: covered by §4.2 (CSS reveal + race). No new dependencies.

## 9. Phased roadmap

| phase | scope | estimate |
|---|---|---|
| 0 | Conformance fixtures + renderer registry refactor (3 surfaces), shared fixture harness | S |
| 1 | v2 schema props: `mode`, `sort`, `direction`, `unit` + types `diverging`, `lollipop`, `pie`, `waffle`, `unit`, `sparkline`, `histogram`, `stem`, `waterfall` | M |
| 2 | `dumbbell`, `slope`, `bullet`, `pyramid`, `box`, `strip`, `radial`, `dial`, `gantt`, `candlestick` | M |
| 3 | `grid` (small multiples), hover-values pass, a11y data tables, `animate: true` entry reveals, `create_chart_fence` tool + REST preview, `design-chart-recipes` skill | M |
| 4 | `race` + pause/reduced-motion, `violin`, CSV copy (Pro SPA), mermaid fence spike (bundle-budget gated) | L |

Each phase ships: JS + TS renderers, CSS tokens, fixture tests (shared),
surface tests, docs table update in `docs/chat-chart-recipes-plan.md` §4.

## 10. Verification

- **Unit**: shared fixtures run on all 3 suites (schema validation, per-type
  geometry assertions, XSS probes, caps, reduced-motion classes).
- **Visual**: headless render check per new type (dark + light), like v1's
  manual check (bar proportions, donut stops, etc.), extended to new types.
- **Accessibility**: axe-core scan on a chat page containing every chart type
  (table alternative present, role/aria intact, contrast).
- **Build**: base `npm run build:js`, both SPA builds + typechecks;
  `composer run lint`, `lint:compat`, PHPCS for the new tool.
- **Performance**: bundle-size delta per phase reported; DOM node budget per
  chart asserted in tests.

## 11. Risks & mitigations

- **Schema drift across 3 implementations** → shared fixtures (phase 0) are
  the gate; no fixture-only changes.
- **Model compliance with a larger schema** → orthogonal props over new types
  (§2 Vega-Lite reasoning), strict whitelist with graceful fallback, skill +
  prompt snippet + server validator as the three compliance levers.
- **Bundle/a11y regressions** → per-phase size gates, axe gate in CI,
  `prefers-reduced-motion` required for any animated type.
- **Scope creep into infographic/animation tooling** → §8 keeps the contract
  at one fence, one schema, no new runtime dependencies.

## 12. Implementation status (completed 2026-10-11)

| Item | Status |
|---|---|
| v2 renderer (all 22 new types + schema keys) — spa-v2 canonical, base JS, chat-spa | ✅ all three byte-equivalent (class-token diff identical) |
| Bar/column visual fix (grouped gap, left-anchor, per-sign scaling) | ✅ + regression tests |
| `data.items` row coercion | ✅ fixed in all three renderers + PHP mirror |
| Heatmap NaN guard, violin KDE rewrite, dumbbell/gantt label positioning | ✅ |
| CSS for all types on 3 surfaces (tokens, dark theme, reduced-motion) | ✅ ~630 lines per surface |
| `create_chart_fence` tool (base plugin) | ✅ `includes/tools/class-wp-mcp-ai-tool-create-chart-fence.php`, registered, 9 PHPUnit tests |
| `design-chart-recipes` skill | ✅ `.agents/skills/design-chart-recipes/SKILL.md` |
| Shared conformance fixtures | ✅ `addons/pro/assets/spa-v2/src/__tests__/chart-fixtures.json` (canonical) + 2 mirrors, wired into all 3 suites |
| Test counts | ✅ base jest 29 · spa-v2 vitest 181 · chat-spa vitest 51 · PHP 9/9 |
| Renderer-registry refactor (§5) | ⏸ deferred — the ports are byte-equivalent and the fixture gate supersedes the registry churn |
| CSV copy (Pro SPA) · REST preview route · mermaid fence spike (§9 ph. 4) | ⏸ deferred — follow the phase-4 gates in §9 |
