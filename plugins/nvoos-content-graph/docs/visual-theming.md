# Visual Theming Guide

The graph explorer (admin settings page and the front-end `[nvoos_graph]`
shortcode / block) is powered by a **visual experience system** shipped in
NV oOS Content Graph 1.0.4. This guide covers the Appearance settings, the
theme engine, per-type styling, filters for addons, and shortcode/block
attributes.

---

## 1. Appearance settings

Open **NV Content Graph → Appearance** in wp-admin. The tab contains:

| Control | What it does |
|---|---|
| **Preset** | One-click styles: *Default*, *High Contrast*, *Editorial (light)*, *Minimal*. Choosing a preset fills the fields below — review, then Save Changes. |
| **Theme** | `Dark`, `Light`, `Auto` (follows the OS setting), or `WordPress Admin` (follows the active admin color scheme; falls back to the OS preference outside wp-admin). |
| **Color nodes by** | `Type` (default), `Community` (detected graph clusters), `Degree` (connection count, viridis ramp), or `Monochrome` (single accent color). |
| **Show icons** | Renders a glyph inside every node — a redundant, colorblind-friendly encoding. Unknown types get a monogram (first letter). |
| **Icon style** | `Filled node` (white glyph on the colored node), `Outline node` (colored glyph on a neutral node), `High contrast` (heavier white glyph). |
| **Shape mode** | Encodes top-level categories as node shapes (entities become diamonds, terms become tags, …). An alternative to icons. |
| **Show legend** | Auto-generated legend with swatches, icons, and click-to-filter rows. |
| **Edge style** | `Plain`, `Arrows`, `Tapered` (thickness by confidence), `Density` (fast haystack mode), or `Auto` (density above 500 edges). |
| **Edge labels** | `Off`, `On hover / selection`, or `Always`. |
| **Node size min/max** | Bounds for the degree-based sizing ramp (square-root scale — hubs dominate less than a linear ramp). |
| **Label font size** | 9–16 px. |
| **Label zoom threshold** | Labels hide below this zoom level (0 = always visible). |
| **Animate layouts** | Layout animation. Automatically disabled when the OS requests reduced motion. |
| **Type colors & icons** | Per-type overrides. Leave a color empty to use the curated default. |
| **Contrast report** | WCAG 2.2 SC 1.4.11 check: every color's ratio against both canvases, plus the automatically corrected value each theme will actually render. |

All settings apply to the admin explorer **and** the front-end embed, unless
an embed overrides them explicitly (see §4).

---

## 2. How colors stay accessible

- **Curated defaults.** The built-in palette is chosen so each type is
  distinguishable under protanopia/deuteranopia/tritanopia simulation, and
  every color is redundant with an icon (and optionally a shape) — color is
  never the only encoding.
- **Automatic per-theme correction.** Every type color is lightness-shifted
  (hue and saturation preserved) until it reaches **≥ 3:1** contrast against
  the active canvas (WCAG 2.2 SC 1.4.11). The same algorithm runs in PHP
  (`NvoosContentGraph\Visual\Tokens::ensure_contrast()`) and in JS
  (`nvoosContentGraphTheme.ensureContrast()`), so the contrast report shows
  exactly what renders.
- **Unknown types.** Custom post types, JetEngine CCTs, and remote-source
  types get a *stable* algorithmic color (hash of the type slug, snapped to a
  24-hue wheel and contrast-corrected) plus a monogram glyph — until you
  override them in the type grid.
- **Verification.** `scripts/verify-contrast.php` re-runs the contrast gate
  outside WordPress (CI-friendly). PHPUnit coverage lives in
  `tests/Unit/Visual/TokensTest.php`.

---

## 3. Explorer chrome

The admin explorer toolbar now includes:

- **Color-by and edge-style selectors** — live previews of the Appearance
  settings (changes are not persisted until you save them in the Appearance
  tab).
- **Layout selector** — fcose balanced/compact, circle, grid, concentric, and
  breadth-first layouts.
- **Zoom cluster** (in/out/fit + zoom badge), **minimap** (click or drag to
  pan; hidden on small screens and above 2,000 nodes), and **fullscreen**.
- **Keyboard navigation** — focus the explorer and use arrow keys to move
  between nodes, Enter/Space for details, Escape to clear, `+`/`−` to zoom,
  `0` to fit.
- **View persistence** — zoom, pan, and layout choice are remembered per
  browser (`localStorage`), no server round-trip.
- **Export PNG** — theme, transparent, or white background at 1×/2×/3× scale.

---

## 4. Front-end embed attributes

```
[nvoos_graph theme="light" color_by="community" show_legend="1"
 show_edges="1" edge_style="arrows" min_label_zoom="0.5" max_nodes="400"]
```

| Attribute | Values | Notes |
|---|---|---|
| `theme` | `dark`, `light`, `auto` | Empty = inherit Appearance setting. |
| `color_by` | `type`, `community`, `degree`, `monochrome` | Empty = inherit. |
| `show_legend` | `0` / `1` | Empty = inherit. |
| `show_icons` | `0` / `1` | Empty = inherit. |
| `show_edges` | `0` / `1` | **New in 1.0.4** — the front-end previously rendered nodes only; edges are fetched from the new `GET /edges` route and capped at 2,000. |
| `edge_style` | `plain`, `arrows`, `tapered`, `density`, `auto` | Empty = inherit. |
| `min_label_zoom` | 0–1 | Label density threshold. |
| `label_font_size` | 9–16 | px. |

The Gutenberg block accepts the same attributes (set them via code, block
markup, or a page builder that exposes block attributes); attributes left
unset (`null`) inherit the Appearance settings.

---

## 5. Filters for addons

Addons (such as the Content Graph AI Platform) can extend the visual system
without forking core:

```php
// Register a color for your own node type.
add_filter( 'nvoos_content_graph/type_palette', function ( array $palette ) {
	$palette['concept'] = '#c0392b';
	return $palette;
} );

// Register an icon for your own node type (slug must exist in the icon catalog).
add_filter( 'nvoos_content_graph/type_icons', function ( array $map ) {
	$map['concept'] = 'bulb';
	return $map;
} );

// Mutate the full config object delivered to the JS theme engine.
add_filter( 'nvoos_content_graph/visual_config', function ( array $visual, array $settings ) {
	$visual['brand_flag'] = true;
	return $visual;
}, 10, 2 );
```

The icon catalog slugs are: `doc`, `page`, `tag`, `bulb`, `cube`, `user`,
`user-round`, `pin`, `building`, `image`, `brain`, `bot`, `grid`, `door`,
`cart`, `calendar`, `link`, `code`, `video`, `audio`, `file`, `star`, `dot`,
`external`.

Site owners can also hook the JS config before styling:

```js
// In an inline script enqueued after nvoos-content-graph-theme:
if ( window.nvoosContentGraphTheme ) {
	// Theme engine helpers are available on window.nvoosContentGraphTheme.
}
```

---

## 6. Known limitations

- Cytoscape.js has no built-in SVG export; PNG export (theme / transparent /
  white backgrounds) is the supported vector-free path.
- Edge rendering on the front end is bounded by the 2,000-edge budget;
  denser graphs fall back to the haystack mode and cap the set.
- The minimap is hidden below 768 px viewport width and above 2,000 rendered
  nodes to protect the frame budget.
