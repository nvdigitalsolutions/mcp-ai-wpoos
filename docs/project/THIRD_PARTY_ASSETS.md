# Third-Party Asset Management

This document describes how third-party JavaScript libraries are managed in the Open Operator System plugin, including how to keep them updated.

## Overview

Per WordPress.org plugin guidelines, **all external resources must be included locally** in the plugin. We cannot load JavaScript libraries from CDNs at runtime.

## Current Third-Party Libraries

### Chart.js (Data Visualization)

**Version:** 4.4.1  
**Location:** `assets/js/vendor/chart.min.js`  
**Source:** https://github.com/chartjs/Chart.js  
**License:** MIT  
**Used By:**
- Chart creation tool (`includes/tools/class-wp-mcp-ai-tool-create-chart.php`)
- Weather forecast tool (`includes/tools/class-wp-mcp-ai-tool-get-open-meteo-forecast.php`)

**Update Process:**

```bash
# Via npm (recommended):
npm update chart.js
cp node_modules/chart.js/dist/chart.umd.js assets/js/vendor/chart.min.js

# Or manually:
# 1. Visit https://github.com/chartjs/Chart.js/releases
# 2. Download chart.umd.js from latest release
# 3. Copy to assets/js/vendor/chart.min.js
# 4. Update version number in README.md and this file
```

**Verification:**
```bash
# Check current version:
grep -o "Chart.js v[0-9.]*" assets/js/vendor/chart.min.js

# Verify file size (should be ~250KB):
ls -lh assets/js/vendor/chart.min.js
```

---

### DOMPurify (XSS Protection)

**Version:** 3.3.0  
**Location:** Bundled via esbuild  
**Source:** https://github.com/cure53/DOMPurify  
**License:** MPL-2.0 OR Apache-2.0  
**Used By:** Frontend chat interface

**Update Process:**
```bash
npm update dompurify
npm run build
```

---

### @microsoft/fetch-event-source (SSE Client)

**Version:** 2.0.1  
**Location:** Bundled via esbuild  
**Source:** https://github.com/Azure/fetch-event-source  
**License:** MIT  
**Used By:** Server-Sent Events for streaming responses

**Update Process:**
```bash
npm update @microsoft/fetch-event-source
npm run build
```

---

### @neplex/vectorizer (SVG Processing)

**Version:** 0.0.5  
**Location:** `assets/js/vendor/neplex-vectorizer/`  
**Source:** https://github.com/neplex/vectorizer  
**License:** MIT  
**Used By:** SVG image processing

**Update Process:**
```bash
npm update @neplex/vectorizer
# Files are automatically updated in node_modules
```

**Note:** Binary `.node` files are excluded from WordPress.org distribution via `.distignore`.

---

### Konva (2D Canvas Framework)

**Version:** 9.3.16
**Location:** `assets/js/vendor/konva/konva-9.3.16.min.js`
**Source:** https://github.com/konvajs/konva
**License:** MIT
**Used By:** Markup subsystem (interactive canvas overlays in chat UI), `addons/canvas/` rendering helpers.

**Update Process:**

```bash
# Pin to a specific Konva release on the unpkg CDN, then commit locally:
curl -L "https://unpkg.com/konva@<NEW_VERSION>/konva.min.js" \
  -o assets/js/vendor/konva/konva-<NEW_VERSION>.min.js
# Update the constant referencing the file (e.g. WP_MCP_AI_KONVA_VERSION) and remove the old version.
```

---

### @strudel/web (Live-Coding Music Engine — Algorave addon)

**Version:** 1.2.5
**Location:** `addons/algorave/assets/js/vendor/strudel/strudel-web-1.2.5.js` and the SharedWorker companion `addons/algorave/assets/js/vendor/strudel/assets/clockworker-ZDiUtESR.js`
**Source:** https://github.com/tidalcycles/strudel
**License:** AGPL-3.0
**Used By:** `addons/algorave/` pattern engine (Strudel REPL embedded in NV oOS).

**Update Process:**

```bash
# Inside addons/algorave/:
npm install @strudel/web@<NEW_VERSION>
cp node_modules/@strudel/web/dist/index.js \
   assets/js/vendor/strudel/strudel-web-<NEW_VERSION>.js
cp node_modules/@strudel/web/dist/assets/clockworker-*.js \
   assets/js/vendor/strudel/assets/
# Bump NVOOS_ALGORAVE_STRUDEL_VERSION in addons/algorave/nvoos-algorave.php
```

**Note:** Strudel is a JavaScript port of TidalCycles by Felix Roos and contributors. AGPL applies to the bundled JS file as redistributed.

---

### Cytoscape stack (Graph Rendering — Graphify addon)

| Library | Version | Location | License | Source |
|---------|---------|----------|---------|--------|
| Cytoscape | bundled minified build (© 2016–2023 The Cytoscape Consortium) | `addons/graphify/assets/vendor/cytoscape/cytoscape.min.js` | MIT | https://github.com/cytoscape/cytoscape.js |
| cytoscape-fcose | bundled (UMD) | `addons/graphify/assets/vendor/cytoscape-fcose/cytoscape-fcose.js` | MIT | https://github.com/iVis-at-Bilkent/cytoscape.js-fcose |
| cose-base | bundled (UMD) | `addons/graphify/assets/vendor/cose-base/cose-base.js` | MIT | https://github.com/iVis-at-Bilkent/cose-base |
| layout-base | bundled (UMD) | `addons/graphify/assets/vendor/layout-base/layout-base.js` | MIT | https://github.com/iVis-at-Bilkent/layout-base |

Each directory carries the upstream `LICENSE` file unmodified.

**Update Process:**

```bash
# Inside addons/graphify/:
npm install cytoscape cytoscape-fcose cose-base layout-base
cp node_modules/cytoscape/dist/cytoscape.min.js                  assets/vendor/cytoscape/
cp node_modules/cytoscape-fcose/cytoscape-fcose.js              assets/vendor/cytoscape-fcose/
cp node_modules/cose-base/cose-base.js                          assets/vendor/cose-base/
cp node_modules/layout-base/layout-base.js                      assets/vendor/layout-base/
# Re-copy each LICENSE file alongside its bundle.
```

---

### Cornerstone3D (Medical Imaging — `addons/cornerstone3d/`)

| Package | Version | Location | License | Source |
|---------|---------|----------|---------|--------|
| @cornerstonejs/core | 1.86.1 | `addons/cornerstone3d/assets/cornerstone/cornerstone-core.esm.js` | MIT | https://github.com/cornerstonejs/cornerstone3D |
| @cornerstonejs/tools | 1.86.1 | `addons/cornerstone3d/assets/cornerstone/cornerstone-tools.esm.js` | MIT | https://github.com/cornerstonejs/cornerstone3D |
| @cornerstonejs/dicom-image-loader | 1.86.0 | `addons/cornerstone3d/assets/cornerstone/cornerstone-dicom-loader.esm.js` | MIT | https://github.com/cornerstonejs/cornerstone3D |
| dicom-parser | 1.8.21 | `addons/cornerstone3d/assets/cornerstone/dicom-parser.esm.js` | MIT | https://github.com/cornerstonejs/dicomParser |
| xmlbuilder2 | 3.0.2 | `addons/cornerstone3d/assets/cornerstone/xmlbuilder2.esm.js` | MIT | https://github.com/oozcitak/xmlbuilder2 |

The bundles are pre-built ESM modules redistributed verbatim with the original
copyright headers intact. See `addons/cornerstone3d/README.md` for the
update procedure.

---

### SaaS Controller — Admin UI bundle (`addons/saas-controller/`)

The SaaS Controller addon ships a single compiled JS/CSS bundle, produced by
`@wordpress/scripts` (webpack + Babel + ESLint). Sources live under
`addons/saas-controller/assets/src/` and the build artifact is
`addons/saas-controller/assets/build/index.js` (+ `index.asset.php`,
`index.css`, `style-index.css`).

| Package | Version | Location | License | Source |
|---------|---------|----------|---------|--------|
| @tanstack/react-query | ^5.62.0 | embedded in `assets/build/index.js` | MIT | https://tanstack.com/query |
| zod | ^3.24.1 | embedded in `assets/build/index.js` | MIT | https://zod.dev/ |
| diff (jsdiff) | ^7.0.0 | embedded in `assets/build/index.js` | BSD-3-Clause | https://github.com/kpdecker/jsdiff |
| date-fns | ^4.1.0 | embedded in `assets/build/index.js` | MIT | https://date-fns.org/ |
| clsx | ^2.1.1 | embedded in `assets/build/index.js` | MIT | https://github.com/lukeed/clsx |

The Cloudflare Worker artifact (`addons/saas-controller/worker/dist/index.js`)
is bundled separately by esbuild and contains no third-party runtime
dependencies (Cloudflare runtime + WHATWG Fetch only). All build-time
tooling (`wrangler`, `esbuild`, `@cloudflare/workers-types`, `miniflare`,
`typescript`, `@wordpress/scripts`) is **not** distributed — it lives only
in `devDependencies` and is dropped from the addon ZIP. See
`addons/saas-controller/THIRD_PARTY_NOTICES.md` for the full per-package
license + copyright table.

**Update Process:**
```bash
cd addons/saas-controller
npm ci
npm run build         # rebuild assets/build/ and worker/dist/
npm audit --audit-level=high
# Update versions in addons/saas-controller/THIRD_PARTY_NOTICES.md and CREDITS.md
```

---

### ExcelJS (Pro addon)

**Version:** 4.4.0
**Location:** `addons/pro/assets/vendor/exceljs/`
**Source:** https://github.com/exceljs/exceljs
**License:** MIT (© 2014–2019 Guyon Roche)
**Used By:** Pro spreadsheet generation tools.

**Update Process:**

```bash
# Inside addons/pro/:
npm update exceljs
# Run the Pro vendor-bundling script that mirrors node_modules/exceljs/ into assets/vendor/exceljs/.
```

---

### Sharp (Pro addon)

**Version:** 0.33.5
**Location:** `addons/pro/assets/vendor/sharp/` (with platform-specific binaries under `node_modules/@img/sharp-*`)
**Source:** https://github.com/lovell/sharp
**License:** Apache-2.0 (© Lovell Fuller and contributors)
**Used By:** High-performance image processing (resize, convert, optimize) for the Pro media toolkit.

**Update Process:**

```bash
# Inside addons/pro/:
npm install sharp@<NEW_VERSION> --legacy-peer-deps
# Re-bundle assets/vendor/sharp/ via the Pro packaging script. Sharp ships
# platform-specific native binaries — Linux x64 is pre-packaged; other
# platforms install via `npm install` on the host.
```

---

### Pro addon vendored JavaScript (full list)

`addons/pro/assets/vendor/` contains hand-vendored copies of every npm package
declared in `addons/pro/package.json`. Each subdirectory carries its own
`LICENSE` (or `package.json` with a `license` field). The complete index —
upstream URL, version, copyright holder — is maintained in the project-wide
[`CREDITS.md`](../CREDITS.md) at the repository root.

When adding or updating any Pro vendor library:

1. Update `addons/pro/package.json` and run `npm install --legacy-peer-deps`.
2. Re-bundle into `addons/pro/assets/vendor/<name>/` (the existing build
   step copies `node_modules/<name>/` minus tests and the upstream node
   binaries that are excluded from distribution).
3. Append / update the matching row in `CREDITS.md`.
4. Append / update the matching row in the **Pro Packages** admin settings
   page (`addons/pro/includes/admin/class-wp-mcp-ai-pro-packages-settings-page.php`)
   if the package is user-relevant — that page is the in-product credits
   surface for Pro npm packages.

---

## Maintenance Schedule

**Quarterly Review (Every 3 Months):**
1. Check for security updates: `npm audit`
2. Review changelogs for breaking changes
3. Test updates in development environment
4. Update production if stable

**Security Updates:**
- Apply immediately when npm audit reports vulnerabilities
- Test critical paths before deployment
- Document any API changes in CHANGELOG.md

**Major Version Updates:**
- Review migration guide from library authors
- Update integration code if API changes
- Add unit tests for new features
- Document breaking changes

---

## Adding New Third-Party Libraries

Before adding a new external library:

1. **Check WordPress.org Compatibility:**
   - Library must be GPL-compatible
   - No CDN loading at runtime
   - No tracking/telemetry
   - Source code available

2. **Add via npm/Composer:**
   ```bash
   # For JavaScript libraries:
   npm install --save library-name
   
   # For PHP libraries:
   composer require vendor/library-name
   ```

3. **Bundle or Copy:**
   - For JS: Include in esbuild bundle OR copy to assets/js/vendor/
   - For PHP: Composer autoload handles this

4. **Document:**
   - Add entry to this file
   - Update README.md
   - Add to docs/EXTERNAL_SERVICES.md if it makes network requests
   - Update LICENSE file if needed

5. **Exclude from SVN:**
   - Add to `.distignore` if it's dev-only
   - Keep in distribution if needed at runtime

---

## Automated Update Checks

We use GitHub Dependabot to monitor for updates:

**Configuration:** `.github/dependabot.yml`

Dependabot will:
- Check for updates weekly
- Create PRs for security fixes
- Group non-security updates monthly

**Review Process:**
1. Dependabot creates PR
2. Automated tests run
3. Review changelog
4. Merge if tests pass and no breaking changes

---

## Security Policies

### npm Audit

Run before each release:
```bash
npm audit
npm audit fix  # For automated fixes
```

### Subresource Integrity (SRI)

For any externally loaded resources (not recommended):
- Generate SRI hash: `openssl dgst -sha384 -binary file.js | openssl base64 -A`
- Add to script tag: `integrity="sha384-HASH" crossorigin="anonymous"`

**Note:** We avoid external loading per WordPress.org guidelines.

---

## License Compliance

All bundled libraries must be:
- GPL-compatible (MIT, Apache 2.0, BSD, etc.)
- Properly attributed in LICENSE file
- Source code available

**Current Licenses:**
- Chart.js: MIT ✅
- DOMPurify: MPL-2.0 OR Apache-2.0 ✅
- @microsoft/fetch-event-source: MIT ✅
- @neplex/vectorizer: MIT ✅
- Konva: MIT ✅
- @strudel/web (algorave addon): AGPL-3.0 ✅ — bundled into the algorave addon ZIP only; AGPL applies to that bundled file
- Cytoscape + cytoscape-fcose / cose-base / layout-base (graphify addon): MIT ✅
- Cornerstone3D + dicom-parser + xmlbuilder2 (cornerstone3d addon): MIT ✅
- ExcelJS (Pro addon): MIT ✅
- Sharp (Pro addon): Apache-2.0 ✅

For the canonical, repo-wide cross-reference of every third-party resource (PHP, JS, fonts, bundled skills, methodology), see [`CREDITS.md`](../CREDITS.md) at the repository root.

---

## Build Process Integration

### Development:
```bash
npm install          # Install all dependencies
npm run dev          # Build for development
npm run watch        # Watch mode for active development
```

### Production:
```bash
npm install --production  # Install runtime dependencies only
npm run build            # Build optimized bundles
```

### WordPress.org Deployment:
```bash
# Using WP-CLI dist-archive:
wp dist-archive . --plugin-dirname=mcp-ai-wpoos

# Or using GitHub Actions:
# .github/workflows/deploy.yml handles this automatically
```

The `.distignore` file ensures:
- `node_modules/` excluded (dev dependencies)
- Bundled assets included (production builds)
- Binary files excluded (*.node, *.dll, etc.)

---

## Troubleshooting

### Chart.js not loading
```bash
# Verify file exists:
ls -la assets/js/vendor/chart.min.js

# Check permissions:
chmod 644 assets/js/vendor/chart.min.js

# Verify WordPress URL:
echo WP_CONTENT_URL . '/plugins/mcp-ai-wpoos/assets/js/vendor/chart.min.js'
```

### npm install fails
```bash
# Clear cache:
npm cache clean --force

# Remove lock file and retry:
rm package-lock.json
npm install

# Check Node version (requires 14+):
node --version
```

### Bundle size too large
```bash
# Analyze bundle:
npm run build -- --analyze

# Check for duplicate dependencies:
npm dedupe

# Consider code splitting for large libraries
```

---

## References

- [WordPress.org Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)
- [npm Documentation](https://docs.npmjs.com/)
- [Dependabot Documentation](https://docs.github.com/en/code-security/dependabot)
- [SRI Hash Generator](https://www.srihash.org/)

---

## Changelog

- **2026-05**: Expanded coverage to include Konva, @strudel/web (algorave), Cytoscape stack (graphify), Cornerstone3D (cornerstone3d), ExcelJS, Sharp; added pointer to root-level `CREDITS.md` as the canonical cross-reference for **all** third-party resources (PHP + JS + fonts + bundled skills).
- **2026-01**: Migrated Chart.js from CDN to local bundle
- **2026-01**: Added automated Dependabot monitoring
- **2025-12**: Initial document created
