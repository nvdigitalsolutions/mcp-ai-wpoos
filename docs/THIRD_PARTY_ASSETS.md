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

- **2026-01**: Migrated Chart.js from CDN to local bundle
- **2026-01**: Added automated Dependabot monitoring
- **2025-12**: Initial document created
