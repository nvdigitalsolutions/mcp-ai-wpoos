# NV oOS Canvas Toolkit

React SPA addon for NV oOS, scaffolded from the
[Toolkit SPA Blueprint](../../docs/addons/toolkit-spa-blueprint.md). This is
the **Tier B** companion to `addons/toolkit-shell/` — a separate addon for
canvas / whiteboard / node-graph / BPMN surfaces, lazy-loaded by mode.

## Modes

The shortcode accepts a `mode` attribute selecting which canvas surface to render:

| Mode | Status | Implementation |
|------|--------|----------------|
| `flow` (default) | ✅ shipped | `@xyflow/react` (MIT) — pannable, zoomable node-and-edge graph for the `ai-tool-builder` toolkit and any other workflow / DAG visualisation. |
| `whiteboard` | 🚧 stub | tldraw v3 (MIT) — ships in a follow-up PR. |
| `bpmn` | 🚧 stub | bpmn-js (MIT) — ships in a follow-up PR. |
| `mermaid` | 🚧 stub | Mermaid live preview — ships in a follow-up PR. |

Unknown values fall back to `flow`. Stub modes render a "Coming soon" panel
so the shortcode contract stays stable while individual modes ship
incrementally.

## Quick start

```bash
cd addons/canvas-toolkit
npm ci
npm run build       # produces assets/dist/canvas-toolkit.{js,css}
```

Add the shortcode to any post or page:

```
[nvoos_canvas_toolkit_app mode="flow" toolkit="ai-tool-builder"]
```

Or use the matching Gutenberg block (`nvoos/canvas-toolkit`).

## Version bump rule

When the SPA bundle changes, bump **all three** in the same commit:

1. `Version:` header in `nvoos-canvas-toolkit.php`
2. `define( 'NVOOS_CANVAS_TOOLKIT_VERSION', '…' );`
3. `"version"` in `package.json`

This forces `?ver=` query strings to invalidate browser caches.

## REST namespace

`/wp-json/nvoos-canvas-toolkit/v1/*` — see [`includes/rest/class-nvoos-canvas-toolkit-rest.php`](includes/rest/class-nvoos-canvas-toolkit-rest.php).
The addon's own namespace is for SPA-specific concerns (manifest, health,
rebuild). Domain data flows through the existing `mcp-ai-pro/v1/*` Pro REST
endpoints — this addon does **not** duplicate the data plane.

## Credits

This addon bundles:

- [React 19 + ReactDOM](https://github.com/facebook/react) (MIT)
- [`@xyflow/react`](https://github.com/xyflow/xyflow) (MIT) — node-graph engine for the `flow` mode

When adding upstream packages, update:

- [`THIRD_PARTY_NOTICES.md`](THIRD_PARTY_NOTICES.md)
- The root [`CREDITS.md`](../../CREDITS.md)
- This Credits section

