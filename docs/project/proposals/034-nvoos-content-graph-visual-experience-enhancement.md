# NV oOS Content Graph — Visual Experience & Styling System Enhancement

**Date:** 2026-09-05
**Status:** ✅ Implemented — v1.0.4 (branch `content-graph-visual-experience-104`)
**Author:** Zed coding agent (per user request)
**Related docs:**
- `docs/project/proposals/graphify-core-implementation-spec.md` (product spec; visual centerpiece §5.19)
- `docs/project/proposals/nvoos-graphify-core-buildout-plan.md` (buildout tracker)
- `docs/project/proposals/nvoos-base-restructuring-roadmap.md` (ecosystem roadmap)
- `plugins/nvoos-content-graph/src/Admin/Sections/DisplaySection.php` (current display settings)
- `plugins/nvoos-content-graph/assets/js/content-graph-admin.js` / `content-graph-frontend.js` (current renderers)
- `plugins/nvoos-content-graph/assets/css/content-graph-admin.css` (current admin chrome)
- `plugins/nvoos-content-graph/src/Frontend/Shortcode.php` (frontend config delivery)

**Version:** 1.0

---

## 1. Executive Summary

The **Graph Explorer** is the flagship surface of the standalone `nvoos-content-graph`
plugin (the "visual knowledge graph" core of the NV oOS ecosystem). Today its visuals
are a fixed, hardcoded aesthetic: one dark-only admin theme, a 14-entry color map
duplicated in two JS files, no icons, no legend, no theme system, no per-type styling
controls, and no accessibility affordances. Users cannot make the graph match their
brand, their WordPress admin color scheme, or their audience's accessibility needs.

This proposal delivers a **Visual Experience System** for the graph explorer:

1. **A theme system** — Light / Dark / Auto / "WordPress Admin" themes built on design
   tokens (CSS custom properties + a JS theme engine), so canvas, nodes, edges, labels,
   and selection states are fully styleable.
2. **A type design system** — curated, colorblind-safe palettes, an inline-SVG icon set
   per node type (with monogram fallback for arbitrary CPTs/remote types), optional
   shape encoding, and a live **Appearance settings section** (color pickers, icon
   pickers, size mapping) replacing the hardcoded `TYPE_COLORS` maps.
3. **Encoding upgrades** — color-by modes (type / community / degree), community
   palettes, relationship-aware edge styling (color families, labels, arrowheads,
   curve styles), and an auto-generated interactive **legend**.
4. **Explorer chrome & UX** — minimap (overview), zoom controls, layout presets,
   label level-of-detail, keyboard navigation, reduced-motion support, and
   theme-aware PNG export.
5. **Frontend parity** — the `[nvoos_graph]` shortcode and Gutenberg block gain
   `theme`, `show_legend`, `color_by`, `show_edges`, and related attributes, driven by
   the same theme engine and defaults.

Everything is **opt-in and backwards compatible**: existing installs render identically
until a setting is changed. No new runtime dependencies are required (inline SVG +
existing Cytoscape.js 3.28 + fcose). The work is staged in five waves so each lands as
an independently shippable, testable increment.

---

## 2. Problem Statement & Current-State Audit

### 2.1 What exists today (verified against the codebase)

| Aspect | Current state | File |
|---|---|---|
| Rendering engine | Cytoscape.js `^3.28.1` + `cytoscape-fcose ^2.2.0`, vendored locally (no CDN) | `plugins/nvoos-content-graph/package.json`, `assets/vendor/` |
| Admin node colors | Hardcoded 14-entry `TYPE_COLORS` map, hex literals, fallback `#95a5a6` | `assets/js/content-graph-admin.js` L17–32 |
| Frontend node colors | Separate 7-entry map — **drifts from admin palette** (same type, different look) | `assets/js/content-graph-frontend.js` L14–22 |
| Admin theme | Dark-only: canvas `#0f0f1a`, labels `#e0e0ff`, borders `#2a2a4a`, edges `#444` | `content-graph-admin.js` L203–250; `content-graph-admin.css` L77–82 |
| Frontend theme | Light-only: labels `#333`, edges `#ccc` | `content-graph-frontend.js` L111–149 |
| Node size | Fixed degree mapping `mapData(degree, 0, 50, 12, 60)` (admin) / `0, 30, 14, 50` (frontend) — no user control | both renderers |
| Icons | None — nodes are colored circles/ellipses only | — |
| Shapes | None (default ellipse everywhere) | — |
| Communities | `community_id` is fetched and shown in the sidebar, but **never visualized** | `content-graph-admin.js` L157, L348 |
| Edge relations | `relation` string is fetched and shown in the sidebar list only; edges render as uniform gray lines with triangle arrows, opacity 0.5 | `content-graph-admin.js` L224–233, L342 |
| Legend | None — the type→color mapping is invisible to the user | — |
| Layout | fcose hardcoded (`quality: default`, 800 ms animation); relayout button re-runs identical params | `content-graph-admin.js` L251–257, L365–368 |
| Zoom / overview | Pan/zoom via mouse only; no buttons, no minimap | — |
| Label density | Labels always on at 10–11 px; no zoom-aware LOD | — |
| Keyboard | None (mouse/touch only) | — |
| Motion | 800 ms layout animation always on; `prefers-reduced-motion` ignored | — |
| Export | PNG only, hardcoded `bg: '#0f0f1a'` | `content-graph-admin.js` L371–380 |
| Settings surface | `cytoscape_height`, `max_display_nodes` only | `src/Admin/Sections/DisplaySection.php` |
| Config delivery | `wp_localize_script('nvoosContentGraphAdmin', { rest_url, nonce, ajax_url, ajax_nonce, height, max_nodes })` | `src/Admin/SettingsPage.php` L437–447 |
| Frontend config | Per-instance `window.nvoosContentGraphData_*` with `container/mode/community_id/post_id/max_nodes` | `src/Frontend/Shortcode.php` L89–97 |
| Shortcode atts | `[nvoos_graph]` with `mode`, `community_id`, `post_id`, `height`, `max_nodes` — no visual atts | `src/Frontend/Shortcode.php` |
| Block | Wrapper around shortcode; no visual controls in inspector | `src/Frontend/Block.php` |

### 2.2 Gaps this proposal closes

1. **No user-facing styling.** The only "styling" a site owner can do is edit the plugin's
   JS or write custom CSS selectors against Cytoscape's canvas DOM — fragile and
   undocumented.
2. **Inconsistent identity.** Admin and frontend use different palettes and themes for
   the same data. The graph does not adapt to the WP admin color scheme, dark mode, or
   the site's brand.
3. **Color is the only encoding.** Nodes are indistinguishable for ~8% of males with
   color-vision deficiency (CVD) — a known WCAG 2.2 failure mode for graphics
   (Success Criterion 1.4.11 requires non-text contrast ≥ 3:1; color must not be the
   only visual means of conveying information, SC 1.4.1).
4. **Unused data.** `community_id` and `relation` are computed and stored but not
   visualized — the two richest signals the graph engine produces.
5. **Missing discoverability affordances.** No legend, no overview, no zoom controls —
   all standard in reference products (Neo4j Bloom, yFiles, Cambridge Intelligence
   KeyLines/ReGraph toolkits).
6. **Hardcoded aesthetics block the marketing loop.** The plugin's wp.org listing and
   screenshot pipeline (`bin/capture-nvoos-content-graph-screenshots.js`) must be
   hand-tweaked whenever the look changes; a theme/token system makes screenshots
   reproducible and brandable per demo.

### 2.3 Non-goals (v1)

- No engine/library swap (stay on Cytoscape.js 3.28 + fcose; no WebGL, no Sigma/OGMA).
- No new JS build toolchain — the plugin ships hand-written ES5-style JS and will keep
  doing so.
- No 3D layouts, edge bundling algorithms, or layout server-side computation.
- No per-node *user-authored* CSS injection (security surface); everything goes
  through sanitized, allowlisted settings and theme tokens.
- No changes to graph **data** model (nodes/edges tables, extraction logic).

---

## 3. Research Synthesis — Industry Standards Applied

This plan was researched against published guidance on graph visualization UX,
accessibility standards, and the conventions of reference products. Key sources and
how each maps into this proposal:

### 3.1 Graph-specific UX guidance

| # | Standard / Finding | Source | Application in this plan |
|---|---|---|---|
| 1 | Labels: avoid permanently tiny labels; offer a zoom indicator and zoom-aware label density | Cambridge Intelligence, *Graph visualization UX* | §7.2 label LOD — labels fade below a zoom threshold; zoom % badge in toolbar |
| 2 | Color nodes by class/type; color edges by predicate family; show edge labels consistently; use a legend for long/abbreviated labels; use badges for qualifiers | yFiles, *Guide to Creating Knowledge Graph Visualizations* | §7.3 relation color families + hover edge labels; §7.1 auto-legend with type swatches |
| 3 | Link style choices "make or break" readability — offer a small curated set of edge styles rather than arbitrary freedom | Cambridge Intelligence, *5 Link Visualization Styles* | §7.3 edge style presets (plain / arrows / tapered / haystack density mode) |
| 4 | Legend panel + minimap are standard chrome in flagship graph UIs (Bloom's Legend + Map panels, scene/perspective concept) | Neo4j Bloom user guide | §7.1 legend; §7.4 minimap; "perspectives" → named theme presets |
| 5 | Styling by tag/folder/type with colors **and** icons is the most-requested customization pattern in consumer graph views | Obsidian graph view + Neo4j Graph View plugin (community feature requests) | §6 type design system (color + icon + optional shape) |
| 6 | Node-link guideline catalog: appearance decisions (layout, node/edge appearance, color) should be *decidable, not invented per-graph* — defaults derived from validated guidelines | GuidelineExplorer, arXiv:2406.05558 | §5 token/default system: every visual knob has a researched default; users override, never start from zero |

### 3.2 Accessibility & color standards

| # | Standard / Finding | Source | Application in this plan |
|---|---|---|---|
| 7 | WCAG 2.1/2.2: ≥ 3:1 contrast for non-text UI/graphical elements (node fills vs canvas, borders); ≥ 4.5:1 for text labels | WCAG SC 1.4.11 / 1.4.3; coloracci.ai color-theory guide | §5.4: default palettes validated to ≥ 3:1 on both themes; label colors ≥ 4.5:1; built-in contrast audit table in Appearance settings |
| 8 | Do not rely on color alone — add patterns, shapes, icons, or labels | UX Magazine, *Data Visualization Handbook* | §6: icons (and optional shapes) are **redundant encodings**, always shipped alongside color |
| 9 | Colorblind-safe categorical palettes: ColorBrewer qualitative sets, the Wong palette; limit categories to ~6–8; avoid pure red/green pairing | colorblind.io; Datylon; data.europa.eu (ColorBrewer) | §6.1: curated 12-color palette built from ColorBrewer-style hues validated against protanopia/deuteranopia/tritanopia simulations |
| 10 | 8-color categorical palettes hit the usability sweet spot for dashboards/graphs | color-analysis.app, 2026 palette study | §6.1: default palette capped at 8 core hues; overflow types fall to monogram + algorithmic hue with redundancy |
| 11 | Sequential data: use Viridis/Cividis or single-hue gradients with strong lightness variation | colorblind.io | §7.2 degree color mode uses a Cividis-like sequential ramp |
| 12 | Keyboard-first navigation is a quality signal for graph UIs | Cambridge Intelligence UX guide | §7.5 keyboard navigation (arrow keys move node focus, Enter opens detail) |
| 13 | `prefers-reduced-motion`: users with vestibular disorders should get instant (non-animated) layouts | WCAG 2.3.3 / CSS Media Queries Level 5 | §7.6: animation duration collapses to 0 when the OS asks for reduced motion |

### 3.3 Cytoscape.js capabilities used (verified against the v3.x style docs)

- `background-image` accepts data URIs → **inline SVG icons per node type** with
  theme-aware fill (single shared sprite string, no extra HTTP requests).
- `shape` supports `ellipse`, `round-rectangle`, `rectangle`, `diamond`, `hexagon`,
  `octagon`, `star`, `triangle`, `vee`, `tag` → optional shape encoding per category.
- `pie-i-background-size` (≤ 16 slices) → future "multi-type badge" nodes.
- Compound nodes (`parent`) → future cluster-by-community view (not in v1).
- `curve-style: haystack` → high-density edge mode (fastest render path).
- `textureOnViewport: true`, `hideEdgesOnViewport: true`, `pixelRatio: 1` → render-budget
  knobs for large graphs (§8).
- Style **functions** (`function( ele ){ ... }`) → zoom-aware label LOD and degree-based
  sizing without re-styling.
- No built-in SVG export (PNG/JPG only) → export plan in §7.7 stays PNG/JPG-based;
  SVG export is explicitly out of scope and documented as a known limitation.

### 3.4 Design-system conventions (internal repo skills)

The plan follows the repo's own standards:
- **Design tokens** (`wp-plugin-*` skills, `design-color-systems` skill): all visual
  values live in one token registry (PHP array + mirrored JS object), never inline.
- **Settings architecture** (`src/Admin/Section.php` pattern): the new Appearance
  section extends `Section` with `get_fields()`/`sanitize()`, exactly like
  `DisplaySection`; a new `color` field type is added to the base renderer
  (WP core ships `wp-color-picker`, no dependency).
- **Options storage** (`Schema::OPTION_SETTINGS` + `Settings::all()` with defaults):
  nested `visual` array, allowlisted keys, `sanitize_hex_color` for colors.
- **Escaping/sanitization discipline** (two-gate rule): every color/string entering
  the theme object is sanitized server-side; everything injected into HTML is escaped;
  JS injects only through `textContent`/jQuery `.text()` or cytoscape's style API
  (never raw HTML).
- **Filter hooks for addons**: `nvoos_content_graph/...` filters let the AI Platform
  addon register icons for `agent`/`wing`/`room`/`memory` types without forking core.

---

## 4. Design Goals & Principles

1. **Researched defaults, user overrides.** Every knob ships with a validated default
   (contrast-checked, CVD-safe). A user who never opens the Appearance tab gets a
   better graph than today's; a user who opens it can restyle everything.
2. **Redundant encoding by default.** Type is always encoded as *color + icon*
   (plus optional shape). Never color-only.
3. **One engine, two surfaces.** A single shared JS theme engine
   (`content-graph-theme.js`) consumes a visual config object and produces
   (a) the Cytoscape stylesheet and (b) chrome CSS variables, for both admin and
   frontend. Palette drift (§2.1) becomes structurally impossible.
4. **Theme-aware, always.** Every color is a token resolved against the active theme
   (dark/light/auto/admin). No more hardcoded `#0f0f1a`.
5. **Progressive disclosure.** Advanced controls (per-type overrides, edge styles,
   layout presets) live behind a collapsed "Advanced" area in Appearance settings;
   the main panel stays simple (theme, color-by, legend, icons on/off).
6. **Backwards compatible.** Default config reproduces today's dark admin look
   (slightly contrast-corrected) and today's light frontend look. No data migration.
7. **Performance budget preserved.** 300 nodes render < 500 ms; 1,000 nodes < 2 s;
   all new chrome (legend, minimap) is O(types) or O(1), never O(nodes²).

---

## 5. Architecture — The Visual Experience System

### 5.1 Token registry (single source of truth)

New file `src/Visual/Tokens.php` (PHP) — an associative registry:

```
visual_tokens => [
    'themes' => [
        'dark'   => [ canvas => '#0f0f1a', surface => '#1a1a2e', node_label => '#e0e0ff',
                      edge => '#444a5a', edge_strong => '#8b9bd4', border => '#2a2a4a',
                      selection => '#ffffff', halo => '#7c9ff2', grid_dot => '#232338' ],
        'light'  => [ canvas => '#f7f8fa', surface => '#ffffff', node_label => '#1e293b',
                      edge => '#c3c8d4', edge_strong => '#64748b', border => '#d7dbe4',
                      selection => '#2271b1', halo => '#2271b1', grid_dot => '#e5e8ee' ],
        // 'admin' is derived at runtime from WP admin color-scheme body class,
        // falling back to light/dark from prefers-color-scheme.
    ],
    'type_palette' => [ /* curated 12-color CVD-safe set, §6.1 */ ],
    'community_palette' => [ /* 12-color categorical set */ ],
    'degree_ramp' => [ /* cividis-like sequential stops */ ],
    'icons' => [ 'post' => 'doc', 'page' => 'page', 'term' => 'tag', ... ],
    'shapes' => [ /* optional category → cytoscape shape map */ ],
]
```

- Mirrored to JS as `window.nvoosContentGraphVisualDefaults` via
  `wp_add_inline_script` (keeps PHP the source of truth; JS only merges user overrides).
- **User overrides** come from the `visual` key inside `Schema::OPTION_SETTINGS`:
  `{ theme, color_by, show_icons, show_legend, node_shapes, type_colors: {post: '#..'},
  type_icons: {post: 'doc'}, edge_style, edge_labels, min_label_zoom, anim_enabled,
  color_community }` — all allowlisted and sanitized.
- Filters for addons:
  - `nvoos_content_graph/type_palette` (PHP, extend/override palette)
  - `nvoos_content_graph/type_icons` (PHP, map new types → icon slugs)
  - `nvoos_content_graph/visual_config` (PHP, mutate the shipped JS config)
  - JS hook equivalent: `window.nvoosContentGraphHooks.beforeStyle(config)` for
    site-specific tweaks via a small inline script.

### 5.2 Theme engine

New file `assets/js/content-graph-theme.js` — a ~150-line dependency-free module:

- `buildStylesheet(config, opts)` → Cytoscape `style:` array (replaces both hardcoded
  style arrays in admin/frontend).
- `applyThemeToChrome(config)` → sets `--nvoos-cg-*` CSS custom properties on the
  explorer wrapper so the toolbar/sidebar/legend chrome matches the canvas.
- Theme resolution: `auto` reads `matchMedia('(prefers-color-scheme: dark)')` and
  listens for changes; `admin` reads the `body[class*="admin-color-"]` class (WP ships
  8 schemes) plus core `--wp-admin-theme-color` where available; explicit
  `dark`/`light` win.
- Lightness correction for type colors: a type color chosen for one theme is
  automatically shifted in lightness (±ΔL in HSL) so it maintains ≥ 3:1 against the
  other theme's canvas — implemented as a small `ensureContrast(hex, canvasHex)`
  utility with a lookup table for the curated palette (no runtime contrast engine
  needed).

### 5.3 Config delivery

| Surface | Delivery | Change |
|---|---|---|
| Admin | `wp_localize_script('nvoosContentGraphAdmin', ...)` in `SettingsPage::enqueueAssets()` | Append `visual` (merged tokens + user overrides + resolved theme) |
| Frontend | `wp_add_inline_script` per-instance config in `Shortcode::render()` | Append `visual` from settings defaults, overridden by per-instance shortcode atts |

### 5.4 Contrast compliance targets (encoded in the plan, verified in tests)

- Every **default** type color: ≥ 3:1 vs both `dark.canvas` and `light.canvas`.
- Node label color: ≥ 4.5:1 vs its canvas.
- Selection border: ≥ 3:1 vs canvas.
- The Appearance tab ships a "Contrast report" table (computed in PHP from the token
  registry) that flags any *user-overridden* color below 3:1 with a warning — users
  may override but can't unknowingly ship an inaccessible default.
- Palettes validated against protanopia/deuteranopia/tritanopia simulation during
  development (documented in the section; see §11 testing).

---

## 6. Type Design System (colors, icons, shapes)

### 6.1 Curated palette (defaults)

A 12-color categorical palette derived from ColorBrewer qualitative + Wong principles,
ordered so the **8 most common types** in WordPress graphs get the most distinct hues:

| Type | Default color | Icon | Notes |
|---|---|---|---|
| `post` | `#3498db` (blue) | `doc` | keep recognizable current hue |
| `page` | `#2ecc71` (green) | `page` | |
| `term` | `#f39c12` (amber) | `tag` | |
| `topic` | `#9b59b6` (violet) | `bulb` | |
| `entity` | `#e74c3c` (red) | `cube` | paired with violet/blue, not green |
| `person` | `#e67e22` (orange) | `user` | |
| `place` | `#1abc9c` (teal) | `pin` | |
| `organization` | `#2980b9` (steel) | `building` | |
| `user` | `#c0392b` (brick) | `user-round` | differs from `person` via icon, not hue alone |
| `media` | `#7f8c8d` (slate) | `image` | |
| `memory` | `#f1c40f` (gold) | `brain` | AI Platform addon family |
| `agent` / `wing` / `room` | `#16a085` / `#8e44ad` / `#27ae60` | `bot` / `grid` / `door` | registered by the AI Platform addon via filter |
| *any other type* | algorithmic | `dot` + monogram | see §6.3 |

All hex values above are starting points only — **final values are set by the
contrast validation gate** (§11) against both canvas tokens.

### 6.2 Icon set — inline SVG sprite

- One SVG sprite string per theme (`assets/js/content-graph-icons.js`), ~24 glyphs
  (doc, page, tag, bulb, cube, user, user-round, pin, building, image, brain, bot,
  grid, door, cart, calendar, link, code, video, audio, file, star, dot, external).
- Rendered as Cytoscape `background-image: data:image/svg+xml;utf8,...` with
  `background-fit: cover` and a token-driven fill (`currentColor`-style substitution
  at config time — one string replace per theme, cached).
- Icons are **redundant encodings** (§3.2 #8): they ship alongside color, and a
  "high-contrast icon mode" (white glyph on full-saturation color, or outline glyph
  on light surface) is a one-click appearance preset.
- No image files, no HTTP requests, no uploads — nothing for wp.org reviewers to flag.

### 6.3 Algorithmic fallback for unknown types

Dynamic reality: custom post types, JetEngine CCTs, and remote-source types
(`concept`, CSV-mapped types, etc.) are unbounded. Rules:

1. If the type has a curated entry (palette + icon) → use it.
2. Else derive a hue: `hue = hash(type) % 360`, snapped to a 24-step wheel that
   maintains ≥ 30° separation from the 8 nearest curated hues; saturation/lightness
   fixed per theme for guaranteed contrast.
3. Icon: monogram = first letter of the humanized type label (uppercase, single
   glyph) centered in the node — still a non-color encoding.
4. The derived color is **stable across rebuilds** (pure function of the type slug),
   so users can then override it once in Appearance settings.

### 6.4 Optional shape encoding

"Shape mode" (off by default, Appearance toggle) maps top-level categories to
Cytoscape shapes: posts → `round-rectangle`, terms → `tag`, entities → `diamond`,
media → `rectangle`, people → `ellipse`. Disabled by default because it competes
with icons for attention; shipped as an accessibility-first alternative when icons
are turned off.

### 6.5 Node sizing

- Keep degree-mapped sizing as default but move the ramp into the token registry
  (`size_ramp: { domain: [0,50], range: [12,60], scale: 'sqrt' }` — sqrt reduces
  hub domination vs today's linear map).
- Appearance control: "Node size" slider (min 8–22, max 40–90).
- Communities color mode optionally *halos* the top-3 hubs per community (ring only,
  no size change) to make cluster centers pop without distorting the layout.

---

## 7. Feature Plan (by area)

### 7.1 Legend panel

- Auto-generated from the types actually present (`typesSeen` is already collected in
  the admin loader — reused).
- Rows: icon + color swatch + humanized type label + node count; click = toggle
  type filter (complements the existing dropdown); "All" chip resets.
- Positioned as a collapsible drawer attached to the explorer; hidden on mobile
  unless toggled; fully keyboard-operable (tab/Enter).
- Frontend: `show_legend="1"` shortcode/block attribute; server-rendered static
  legend fallback (visible before JS runs) for no-JS/SEO.

### 7.2 Color-by modes + label LOD

- `color_by` selector in the toolbar and Appearance settings:
  - `type` (default) — §6 palette.
  - `community` — categorical community palette (12 CVD-safe hues); a
    community→color lookup is computed client-side from `community_id` values in
    the loaded nodes (stable via the same hash algorithm as §6.3).
  - `degree` — Cividis-like sequential ramp (5 stops, lightness-dominant).
  - `monochrome` — single accent color (from the active theme's halo token).
- Label LOD: a style function reads `cy.zoom()`; labels fade out below
  `min_label_zoom` (default ~0.35, adjustable 0–1); a zoom % badge sits in the
  new zoom control cluster; hovering a faded-label node temporarily reveals its
  label (tooltip).
- Label typography: font size into tokens (`label_font_size`, min 9 / max 16 with a
  setting), `text-wrap: ellipsis`, `text-max-width` 90 px default.

### 7.3 Edge styling (relationships become first-class visuals)

- Edge color families from the `relation` data (verified present in REST responses):
  - **hierarchical** (`belongs_to`, `in_category`, `parent_of`, `has_term`) → steel/indigo
  - **similarity** (`related_to`, `similar`, `co_occur`) → teal/green
  - **reference** (`links_to`, `references`, `mentions`) → amber
  - **authorship** (`authored_by`, `created`) → violet
  - unknown → theme `edge` token
- Edge styles presets (toolbar dropdown):
  - `plain` — no arrows, soft lines (default for large graphs)
  - `arrows` — today's triangle arrowheads (current look, kept as an option)
  - `tapered` — width scaled by relationship strength (`strength` attr; falls back
    to edge count between node pairs)
  - `density` — `curve-style: haystack` + reduced opacity for graphs > 500 edges
    (fastest render path, §3.3)
- **Edge labels**: relation names shown on hover/selection only (avoid permanent
  clutter, per §3.1 #2); toggleable "always show edge labels" advanced option;
  labels use the `edge_label` token with 4.5:1 contrast.
- Auto edge-mode: if edges fetched > threshold (default 500), engine silently falls
  back to `density` to protect the frame budget (§8).

### 7.4 Explorer chrome — minimap, zoom cluster, layout presets, fullscreen

- **Minimap**: custom-built 160×120 px overview panel (upper-right). Rendered from a
  downscaled copy of node positions on a 2D canvas — no new dependency (avoids the
  unmaintained `cytoscape-navigator` extension). Click/drag to pan; viewport rectangle
  drawn from `cy.extent()`; updates throttled to 10 fps on `render` events; hides
  below 768 px viewport width.
- **Zoom cluster**: `−` / `+` / `fit` / zoom % badge; replaces the single Fit button
  (kept as a bigger primary action).
- **Layout presets** (toolbar dropdown):
  - `fcose — balanced` (current defaults; `quality: default`)
  - `fcose — compact` (higher nodeRepulsion factor, `nodeSeparation` 50)
  - `circle` (hubs on outer ring — for small/medium graphs)
  - `grid` (alphabetical — for list-like review)
  - `concentric` (degree-ranked rings)
  - `breadthfirst` (root = highest-degree node; good for hierarchy)
  - Advanced: `quality` (`draft`/`default`/`proof`) and iteration budget exposed in
    Appearance settings.
- **Fullscreen** toggle using the Fullscreen API with a resize listener re-running
  `cy.resize()` + `cy.fit()`.
- **View persistence**: zoom/pan/layout choice saved to `localStorage` per admin
  page (and per shortcode instance on the frontend), restored on next load — "resume
  where I left off" without touching the server.

### 7.5 Keyboard navigation

- Focus the explorer via Tab; arrow keys move focus between nearest nodes
  (closest-by-position, filtered by current type filter); Enter/Space opens the
  node detail sidebar; Escape clears selection; `+`/`−` zoom; `0` fits.
- `aria-label` on the explorer container, `role="application"` with instructions,
  and an `aria-live` region announcing the focused node label.
- Implements the Cambridge Intelligence "fully keyboard-navigable" quality bar
  (§3.1 #1, #12).

### 7.6 Motion & sensory preferences

- `anim_enabled` setting (default on) + `prefers-reduced-motion` media query:
  when set, `animate: false` and `animationDuration: 0` for all layouts.
- Transitions on hover/selection use opacity only (no position animation) — cheap
  and non-triggering.

### 7.7 Export

- **PNG** — theme-aware background (canvas token), plus two presets: *transparent*
  (for slide decks) and *white* (print). Scale options 1×/2×/3×. `full: true`
  already exports the whole graph (kept).
- **JPG** — white background export for email/print.
- **Legend capture**: optional side-by-side composition — export the canvas, then
  stamp the legend rows (rendered as a data-URI image via a hidden DOM node) under
  the graph on a 2D canvas. Single-file, no server work.
- Documented limitation: Cytoscape.js has no built-in SVG export; SVG is out of
  scope for v1 (§2.3).

---

## 8. Performance Budget & Safeguards

| Guard | Mechanism |
|---|---|
| Render budget | `pixelRatio: 1`, `textureOnViewport: true`, `hideEdgesOnViewport: true` by default |
| Edge budget | auto `density` (haystack) mode above 500 rendered edges; edge rendering cap (`max_render_edges`, default 2,000) with a "showing X of Y edges" note |
| Label budget | label LOD (§7.2) + `max_label_chars` (default 40, ellipsis) |
| Add/remove batching | neighbor expansion already per-node; future bulk loads use `cy.batch()` |
| Minimap cost | throttled 10 fps, single 2D canvas, `requestAnimationFrame`-gated |
| Icon cost | one cached sprite string per theme; `background-fit: cover`; no per-node HTTP |
| First paint | legend + minimap initialize after first `layoutstop`; never block initial render |
| Node cap | existing `max_display_nodes` (50–2000) remains the primary dial; new visual features must not lower the practical ceiling below 1,000 nodes on a mid-range laptop |

Acceptance: 300 nodes render < 500 ms, 1,000 < 2 s (measured on a 2020-class
laptop, Chrome + Firefox, cold cache) — encoded as manual e2e checks in §11.

---

## 9. Settings & Admin UX

### 9.1 New "Appearance" tab

New `src/Admin/Sections/AppearanceSection.php` extending `Section`
(`get_tab(): 'appearance'`). Registration follows the existing pattern in
`SettingsPage::registerSettings()`: one `SettingsRegistry::register_tab('appearance', ...)`
call plus `SettingsRegistry::register_section( new AppearanceSection() )` —
tabs and section rendering are otherwise handled generically by
`SettingsRegistry` / `SettingsPage`.

Fields (using the existing `get_fields()` declarative shape):

| Field | Type | Default | Notes |
|---|---|---|---|
| `visual[theme]` | select | `dark` (admin) / `light` (frontend default) | dark / light / auto / admin |
| `visual[color_by]` | select | `type` | type / community / degree / monochrome |
| `visual[show_icons]` | checkbox | on | redundant encoding |
| `visual[icon_mode]` | select | `filled` | filled / outline / high-contrast |
| `visual[node_shapes]` | checkbox | off | §6.4 |
| `visual[show_legend]` | checkbox | on (admin) / off (frontend) | |
| `visual[min_label_zoom]` | number | 0.35 | 0–1, step 0.05 |
| `visual[edge_style]` | select | `plain` | plain / arrows / tapered / density / auto |
| `visual[edge_labels]` | select | `hover` | off / hover / always |
| `visual[size_min]` / `visual[size_max]` | number | 12 / 60 | bounds only, ramp stays sqrt |
| `visual[anim_enabled]` | checkbox | on | respects reduced-motion regardless |
| `visual[type_colors]` | **color grid** | curated palette | one color picker per known type, rendered from the palette registry; unknown types appear once seen (persisted on save) |
| `visual[type_icons]` | **icon grid** | curated map | dropdown per type (24 glyphs + monogram) |
| `visual[presets]` | select | — | one-click named presets: *Default*, *High Contrast*, *Editorial (light)*, *Minimal* (icons off, monochrome) |

- The `Section` base class gains a `color` field type (renders
  `<input type="text" class="nvoos-cg-color">` + enqueues WP core's bundled
  `wp-color-picker`; sanitization via `sanitize_hex_color`) and an `icon` field type
  (a select fed by the icon registry). Both are additive — existing sections
  unaffected.
- **Contrast report** (§5.4): a small table under the color grid; PHP computes
  contrast for each override vs both canvases and flags < 3:1 rows.
- **Live preview**: the Appearance tab embeds a mini graph (same engine, 30 nodes)
  that restyles instantly on change before "Save Changes" — one page, no
  save-reload loop. (Admin-only; frontend blocks get a `Preview` toggle instead.)

### 9.2 Frontend parity — shortcode & block

Shortcode atts (all optional, defaults from settings):

```
[nvoos_graph theme="dark|light|auto" color_by="type|community|degree|monochrome"
 show_legend="0|1" show_icons="0|1" edge_style="plain|arrows|tapered|density|auto"
 show_edges="0|1" height="600px" max_nodes="300" min_label_zoom="0.35"]
```

- `show_edges` (new): frontend currently renders **nodes only** (edges are never
  fetched by the frontend — verified in `content-graph-frontend.js`); the frontend
  gains optional edge rendering via the existing `/nodes/{id}` neighbor data when
  `show_edges="1"` (bounded by `max_nodes` and the §8 edge budget).
- Block: `src/Frontend/Block.php` registers the same attributes in the
  server-side attribute schema (settable via code / block markup; `null`
  defaults inherit Appearance settings). The plugin ships no editor JS, so
  there are no inspector controls — attributes are the contract.
- The static legend fallback (§7.1) renders in the shortcode/block markup so the
  legend is present even with JS disabled.

---

## 10. Implementation Plan — Five Waves

Each wave is an independently shippable PR cluster (per the repo's cluster-by-cluster
workflow), with its own tests, and bumps `nvoos-content-graph` as noted.

### Wave V1 — Theme engine & Appearance settings foundation *(version 1.1.0)*

1. `src/Visual/Tokens.php` — token registry + palette + icon registry + contrast
   utility (PHP).
2. `assets/js/content-graph-theme.js` — theme engine (`buildStylesheet`,
   `applyThemeToChrome`, theme resolution incl. `auto`/`admin`).
3. Refactor `content-graph-admin.js` / `content-graph-frontend.js` to consume the
   engine (no visual change yet — default config reproduces current look, with the
   §6.5 sqrt size ramp and contrast-corrected values as the *only* visible deltas).
4. `src/Admin/Sections/AppearanceSection.php` + `color`/`icon` field types in
   `Section.php`; register the Appearance tab.
5. Delivery: extend `nvoosContentGraphAdmin` + per-instance frontend config with the
   merged `visual` object; add the four PHP filters (§5.1).
6. Tests: PHPUnit for section sanitization (hex validation, allowlists), token
   registry completeness, config delivery shape; JS smoke tests via the admin page.

### Wave V2 — Type design system (icons, legend, color-by) *(1.1.1)*

1. `assets/js/content-graph-icons.js` sprite set; icon + monogram rendering incl.
   algorithmic fallback (§6.3).
2. Legend panel (§7.1) with count chips, click-to-filter, keyboard support; frontend
   static fallback.
3. Color-by modes: community + degree + monochrome (§7.2) incl. stable hashing.
4. Optional shape mode (§6.4).
5. Tests: palette CVD/contrast validation gate (scripted, §11); legend a11y checks.

### Wave V3 — Edges & chrome *(1.1.2)*

1. Edge color families, styles presets, hover edge labels (§7.3).
2. Minimap + zoom cluster + fullscreen + view persistence (§7.4).
3. Layout presets dropdown (§7.4).
4. Frontend `show_edges` support with budget guards.
5. Tests: edge-budget fallback behavior; minimap frame-rate check on 1,000 nodes.

### Wave V4 — Accessibility, performance & export *(1.1.3)*

1. Keyboard navigation + aria announcements (§7.5).
2. Reduced-motion + `anim_enabled` (§7.6); label LOD + `min_label_zoom`.
3. Export presets (transparent/white/scale/legend capture) (§7.7).
4. Performance defaults + guards (§8) with a documented benchmark run.
5. Tests: axe-core pass on the admin explorer (target: zero serious/critical);
   manual keyboard script; benchmark log.

### Wave V5 — Frontend parity polish, docs & marketing *(1.1.4)*

1. Block attribute schema (`null`-inherit semantics) (§9.2).
2. Docs: `plugins/nvoos-content-graph/docs/visual-theming.md` (user-facing: how to
   theme, token list, filter hooks, icon registry, examples) + wp.org readme section
   update.
3. Screenshot refresh via `bin/capture-nvoos-content-graph-screenshots.js` for the
   `.wordpress-org` listing using the new presets.
4. CHANGELOG, QUICK_REFERENCE, credits (`CREDITS.md` — no new deps, so likely
   unchanged), pot regeneration for new strings (`composer run pot`).

---

## 11. Testing & Validation Plan

### 11.1 Automated (PHPUnit — plugin's existing `tests/` conventions)

- `AppearanceSection` sanitization: valid/invalid hex, icon slugs, numeric bounds,
  select enums, nested array allowlisting.
- Token registry: every curated type color ≥ 3:1 vs both canvases (asserted with a
  PHP contrast helper — no browser needed); every theme defines the full token set.
- Config delivery: `nvoosContentGraphAdmin.visual` and frontend per-instance config
  contain exactly the allowlisted keys; filter hooks mutate output.
- Shortcode: new atts sanitized (`theme` enum, booleans, `min_label_zoom` clamped);
  static legend markup escaped and present when `show_legend=1`.
- Regression: existing DisplaySection/settings tests keep passing.

### 11.2 Contrast & CVD gate (scripted, part of CI)

- Small Node script (repo already has `scripts/` tooling) that renders the token
  JSON and computes WCAG contrast for every default color pair, plus protanopia /
  deuteranopia / tritanopia simulation of the 12-color palette to assert pairwise
  perceptual separation (ΔE threshold). Runs in the PHPUnit workflow as a
  standalone step; failures block merge.

### 11.3 Manual / e2e matrix

| Scenario | Check |
|---|---|
| 300-node graph | < 500 ms first layout (Chrome/Firefox), legend populates, no console errors |
| 1,000-node graph | < 2 s; labels LOD works; edge budget fallback engages |
| Theme switch (dark→light→auto→admin) | canvas, chrome, sidebar, legend, minimap all restyle; persisted |
| WP admin scheme change (Profile → admin color) | `admin` theme follows within one reload |
| Keyboard-only run | full focus cycle: explorer → legend → nodes → sidebar; Escape behavior |
| `prefers-reduced-motion: reduce` | layouts instant, no zoom-pan smoothing |
| CVD simulation (browser devtools) | type distinction preserved via icons when color fails |
| Shortcode embed | `theme="light" show_legend="1" show_edges="1"` on a 2024+ theme; block inspector parity |
| Export | PNG transparent / white / 2× open correctly; legend capture legible |
| Mobile (390 px) | minimap hidden, toolbar wraps, touch pan/zoom intact |

### 11.4 Accessibility audit

- axe-core on the admin explorer (target: 0 serious/critical), keyboard script
  (§11.3), and a documented color-only-encoding check against SC 1.4.1/1.4.11.

---

## 12. Risks & Mitigations

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| fcose layout animation cost grows with new chrome | Med | Med | §8 budget; reduced-motion; LOD; benchmarks gating Wave V4 merge |
| Inline SVG data-URI icons break on some browsers | Low | Low | Standard `utf8` encoding is well-supported; monogram text fallback is automatic (icons are redundant, not critical) |
| User overrides create inaccessible colors | Med | Low | Contrast report + CVD gate; overrides are user-owned, warnings not blocks |
| wp.org plugin-review friction from new admin UI | Low | Med | All strings i18n'd, settings sanitized + escaped, no external requests, no uploads; follows existing section/sanitize patterns already accepted by the review process |
| Palette drift returns (admin vs frontend) | Low | Med | Single theme engine + single token registry; drift structurally impossible (§4.3) |
| Scope creep (3D, SVG export, edge bundling) | Med | Low | Non-goals §2.3; follow-up proposals only after v1 ships |
| Minimap perf on huge graphs | Low | Low | Throttled; hidden < 768 px; auto-disabled > 2,000 rendered nodes |

---

## 13. Effort Estimation

| Wave | Scope | Est. effort |
|---|---|---|
| V1 | Theme engine, tokens, Appearance section, delivery, filters | ~3 days |
| V2 | Icons, legend, color-by modes, shapes | ~2.5 days |
| V3 | Edges, minimap, zoom cluster, layouts, frontend edges | ~3 days |
| V4 | A11y, perf, export | ~2 days |
| V5 | Block controls, docs, screenshots, release hygiene | ~1.5 days |
| **Total** | | **~12 dev-days** (≈ 2.5 weeks single dev; parallelizable across V2/V3) |

No new dependencies, no schema migration, no data changes. Version bumps:
`nvoos-content-graph` 1.0.3 → 1.1.0 (V1) → 1.1.1 … 1.1.4 (V5); the AI Platform
addon is unaffected until it opts into registering its icons via the new filters
(a 1-line addon PR at V2, optional).

---

## 14. Success Metrics

1. **Adoption**: ≥ 20% of active installs change at least one visual setting within
   60 days of release (measured via the plugin's anonymous usage stats, if any —
   otherwise drop this metric and rely on wp.org reviews/screenshots).
2. **Accessibility**: axe-core 0 serious/critical; all default colors pass the §11.2
   gate; keyboard-only full-flow passes.
3. **Performance**: budgets in §8 met at 300 and 1,000 nodes.
4. **Consistency**: admin and frontend palettes verified identical for shared types
   (test-enforced).
5. **Perception**: refreshed wp.org screenshots ship with the release; support
   threads asking "how do I change node colors?" are answerable with the
   Appearance tab instead of custom CSS.

---

## 15. Decision Required

1. **Approve the five-wave plan** (or trim to V1–V3 first)?
2. **Frontend edge rendering** (`show_edges`): the frontend has never rendered
   edges; approve adding it behind an opt-in attribute?
3. **Version strategy**: 1.1.x series for the standalone plugin as proposed?
4. **Icons for AI-family types** (`agent`/`wing`/`room`/`memory`): ship in core
   registry, or leave registration to the AI Platform addon via the new filter?
5. **`admin` theme**: derive from the WP admin color scheme (8 built-in schemes) —
   approve the body-class + `--wp-admin-theme-color` derivation approach?

---

## Appendix A — Reference Links

- Cambridge Intelligence — *Graph visualization UX*:
  <https://cambridge-intelligence.com/graph-visualization-ux-how-to-avoid-wrecking-your-graph-visualization/>
- Cambridge Intelligence — *5 Link Visualization Styles*:
  <https://cambridge-intelligence.com/blog/link-visualization-styles/>
- yFiles — *Guide to Creating Knowledge Graph Visualizations*:
  <https://www.yfiles.com/resources/how-to/guide-to-visualizing-knowledge-graphs>
- GuidelineExplorer (arXiv:2406.05558) — node-link guideline catalog:
  <https://arxiv.org/html/2406.05558v1>
- WCAG 2.2 — SC 1.4.1 (Use of Color), SC 1.4.11 (Non-text Contrast):
  <https://www.w3.org/WAI/WCAG22/quickref/>
- colorblind.io — *Colorblind-Friendly Data Visualization*:
  <https://colorblind.io/guides/data-visualization>
- data.europa.eu — *Accessible colour palettes* (ColorBrewer):
  <https://data.europa.eu/apps/data-visualisation-guide/accessible-colour-palettes>
- UX Magazine — *The Ultimate Data Visualization Handbook for Designers*:
  <https://uxmag.com/articles/the-ultimate-data-visualization-handbook-for-designers>
- Neo4j Bloom — visual tour / legend & minimap panels:
  <https://neo4j.com/docs/bloom-user-guide/current/bloom-visual-tour/bloom-overview/>
- Cytoscape.js — style property reference (background-image, shapes, pie, haystack):
  <https://js.cytoscape.org/#style>

## Appendix B — Files Touched (map)

| File | Wave | Change |
|---|---|---|
| `plugins/nvoos-content-graph/src/Visual/Tokens.php` | V1 | **new** — token/icon/palette registry + contrast helper |
| `plugins/nvoos-content-graph/assets/js/content-graph-theme.js` | V1 | **new** — shared theme engine |
| `plugins/nvoos-content-graph/assets/js/content-graph-icons.js` | V2 | **new** — SVG sprite glyphs |
| `plugins/nvoos-content-graph/assets/js/content-graph-admin.js` | V1–V4 | consume engine; legend, minimap, zoom, presets, export, keyboard |
| `plugins/nvoos-content-graph/assets/js/content-graph-frontend.js` | V1–V3 | consume engine; legend, edges, color-by |
| `plugins/nvoos-content-graph/assets/css/content-graph-admin.css` | V1 | chrome tokens (`--nvoos-cg-*`), legend/minimap styles |
| `plugins/nvoos-content-graph/assets/css/content-graph-frontend.css` | V1 | embed chrome tokens |
| `plugins/nvoos-content-graph/src/Admin/Section.php` | V1 | `color` + `icon` field types |
| `plugins/nvoos-content-graph/src/Admin/Sections/AppearanceSection.php` | V1 | **new** — Appearance tab |
| `plugins/nvoos-content-graph/src/Admin/SettingsPage.php` | V1 | tab registration, config delivery, live-preview mount |
| `plugins/nvoos-content-graph/src/Frontend/Shortcode.php` | V1–V3 | new atts, config delivery, static legend |
| `plugins/nvoos-content-graph/src/Frontend/Block.php` | V5 | block attribute schema (null-inherit semantics) |
| `plugins/nvoos-content-graph/src/Schema.php` | V1 | `visual` defaults + sanitization schema |
| `plugins/nvoos-content-graph/tests/` | all | new test classes per §11.1 |
| `plugins/nvoos-content-graph/docs/visual-theming.md` | V5 | **new** — user theming guide |
| `plugins/nvoos-content-graph/CHANGELOG.md`, `readme.txt` | V5 | release hygiene |
