# Build Matrix — NV oOS

**Single source of truth for all build scripts and config files.**

Every `npm run build:*` script is listed below with the config file it uses, what it
produces, and when you need to run it.

---

## Quick Reference

| Script | Config File | Output | Run When |
|---|---|---|---|
| `npm run build` | `esbuild.config.js` + `cleancss.config.js` + `esbuild.config.pro.js` | Chat/admin JS bundles + CSS | Any JS or CSS file under `assets/` changes |
| `npm run build:full` | All configs (see below) | All bundles (browser + Node + React apps) | Before a release |
| `npm run build:css` | `cleancss.config.js` | Minified CSS files | Any CSS source file changes |
| `npm run build:js` | `esbuild.config.js` | Chat/admin JS bundles (browser, ESM/IIFE) | Any browser JS source file changes |
| `npm run build:js:legacy` | UglifyJS (inline script) | Legacy minified JS (`*.min.js`) | Changes to `assets/js/*.js` that need IE/ES5 compat |
| `npm run build:js:pro` | `esbuild.config.pro.js` | Pro Node.js scripts (pdfkit, docx, exceljs) | Changes to Pro Node.js document-generation scripts |
| `npm run build:workflow` | `webpack.config.js` | Workflow builder React app → `addons/pro/build/workflow-builder/` | Changes to `src/workflow-builder/` |
| `npm run build:tma` | `webpack.config.tma.js` | All three TMA React SPAs (see below) | Changes to any `src/tma-*/` source |
| `npm run build:tma-builder` | `webpack.config.tma.js` (ENTRY=tma-template-builder) | TMA Template Builder → `addons/pro/build/tma-template-builder/` | Changes to `src/tma-template-builder/` only |
| `npm run build:tma-woo-shop` | `webpack.config.tma.js` (ENTRY=tma-woo-shop) | WooCommerce TMA → `addons/pro/build/tma-woo-shop/` | Changes to `src/tma-woo-shop/` only |
| `npm run build:tma-shopify-jewelry` | `webpack.config.tma.js` (ENTRY=tma-shopify-jewelry) | Shopify Jewelry TMA → `addons/pro/build/tma-shopify-jewelry/` | Changes to `src/tma-shopify-jewelry/` only |
| `npm run build:zip` | `bin/build-plugin-zip.sh` | Full combined plugin ZIP | Release packaging |
| `npm run build:zip:base` | `bin/build-plugin-zip.sh --base` | Base-only plugin ZIP | WP.org submission |
| `npm run build:zip:pro` | `bin/build-plugin-zip.sh --pro` | Pro-only plugin ZIP | Pro release |
| `npm run rebuild:all` | `bin/rebuild-all-zips.sh` | All ZIP variants | Full release cycle |

---

## Config File Details

### `esbuild.config.js` — Browser bundles (fast)

- **Tool:** esbuild (`platform: browser`)
- **Inputs:** `assets/js/chat.js`, admin JS, MCP client scripts
- **Outputs:** Minified/transpiled `assets/js/*.min.js` bundles
- **Features:** Fast transpile + minify, no React (React handled by webpack)
- **Used by:** `npm run build:js`

### `esbuild.config.pro.js` — Node.js scripts

- **Tool:** esbuild (`platform: node`, `format: cjs`)
- **Inputs:** Pro addon Node.js helper scripts (PDF generation, DOCX, XLSX)
- **Outputs:** `addons/pro/build/node/` — CJS bundles for WP-CLI execution
- **⚠️ Must NOT be changed to `platform: browser`** — these scripts run server-side
- **Used by:** `npm run build:js:pro`

### `webpack.config.js` — Workflow builder React app

- **Tool:** `@wordpress/scripts` webpack (extends defaultConfig)
- **Entry:** `src/workflow-builder/index.jsx`
- **Output:** `addons/pro/build/workflow-builder/`
- **Features:** React JSX, WordPress externals, asset.php manifest
- **Used by:** `npm run build:workflow`, `npm run start:workflow`

### `webpack.config.tma.js` — All TMA React SPAs (consolidated)

- **Tool:** `@wordpress/scripts` webpack (extends defaultConfig per entry)
- **Entries:** Three independent SPAs — each gets its own output directory
- **Outputs:**
  - `addons/pro/build/tma-template-builder/` — TMA Template Builder
  - `addons/pro/build/tma-woo-shop/` — WooCommerce TMA shop
  - `addons/pro/build/tma-shopify-jewelry/` — Shopify Jewelry TMA
- **Exports:** An array of three webpack configs (one per TMA)
- **Selective build:** Set `ENTRY=<name>` env var to build only one TMA
- **Used by:** `npm run build:tma`, `npm run build:tma-*`, `npm run start:tma-*`

### `cleancss.config.js` — CSS minification

- **Tool:** CleanCSS (custom Node script)
- **Inputs:** `assets/css/*.css` source files
- **Outputs:** Minified `assets/css/*.min.css`
- **Used by:** `npm run build:css`, `npm run minify:css`

### `babel.config.js` — Jest transform

- **Tool:** Babel (used only by Jest, not by any build script)
- **Purpose:** Transpiles JSX and modern JS for the Jest test runner
- **Used by:** `npm run test` via `jest.config.js`
- **⚠️ Not a build config** — does not produce any output files

### `jest.config.js` — JS unit tests

- **Tool:** Jest
- **Test files:** `src/**/*.test.js`, `packages/**/*.test.js`
- **Used by:** `npm run test`, `npm run test:watch`, `npm run test:coverage`

### `cosmos.webpack.config.js` — React Cosmos component preview (dev-only)

- **Tool:** React Cosmos (`cosmos` / `cosmos-export`)
- **Purpose:** Visual component explorer for TMA React components
- **Used by:** `npm run cosmos:tma`, `npm run cosmos:tma:export`
- **⚠️ Dev-only** — never used in production builds or CI

---

## Aggregate Scripts

| Script | What it runs | Use case |
|---|---|---|
| `npm run build` | `build:css` + `build:js` + `build:js:pro` | Day-to-day development (no React apps) |
| `npm run build:full` | All of the above + `build:workflow` + `build:tma` | Pre-release full rebuild |
| `npm run rebuild:all` | `bin/rebuild-all-zips.sh` (builds + zips all variants) | Full release cycle |

---

## Adding a New TMA

To add a new Telegram Mini App:

1. Create `src/tma-my-new-app/index.jsx` (and supporting files).
2. Add an entry to the `TMA_ENTRIES` array in `webpack.config.tma.js`:
   ```js
   {
       name:   'tma-my-new-app',
       entry:  'src/tma-my-new-app/index.jsx',
       output: 'addons/pro/build/tma-my-new-app',
   },
   ```
3. Add `build:tma-my-new-app` and `start:tma-my-new-app` scripts to `package.json`:
   ```json
   "build:tma-my-new-app": "ENTRY=tma-my-new-app wp-scripts build --config webpack.config.tma.js",
   "start:tma-my-new-app": "ENTRY=tma-my-new-app wp-scripts start --config webpack.config.tma.js"
   ```
4. Update this document.

---

## Build Tool Ownership Decision

When a new asset could be handled by either esbuild or webpack, use this rule:

- **esbuild** → browser scripts with no React/JSX, no WordPress block dependencies (fast, minimal)
- **webpack (`@wordpress/scripts`)** → React/JSX apps, Gutenberg blocks, anything needing `asset.php` manifests for WordPress dependency loading

This rule is established in [ADR_001_module_boundaries.md](ADR_001_module_boundaries.md).

---

_Last updated: March 2026. Update this file whenever a new build script or config file is added._
