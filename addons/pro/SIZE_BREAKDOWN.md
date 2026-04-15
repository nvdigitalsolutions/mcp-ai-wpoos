# Pro Plugin Size Breakdown

## Overview

**Plugin Version**: 1.0.0 (`WP_MCP_AI_PRO_VERSION`)  
**Repository (Uncompressed)**: ~202 MB  
**Total Files in Repository**: 9,179  
**PHP Tool Files**: 610  
**Toolkits**: 31 specialized toolkits  

> **Note on Distribution ZIP**: The `.distignore` file excludes source maps, Facebook SDK, vendor test directories, sample PDFs, build artefacts, and dev config files from the distributed ZIP. The resulting ZIP is estimated at **~45–50 MB** compressed (see [Excluded from Distribution](#whats-excluded-from-distribution-zip) below for the full exclusion list).

---

## Repository Directory Breakdown

What is currently in the repository:

| Directory | Size | Files | Description |
|-----------|------|-------|-------------|
| `assets/` | 79 MB | 4,339 | JS/CSS source + pre-bundled NPM vendor packages |
| `vendor/` | 76 MB | 3,529 | PHP Composer dependencies |
| `bin/` | 27 MB | 24 | Webpack bundles + source maps (maps excluded from dist) |
| `includes/` | 18 MB | 1,115 | PHP source: 610 tool files + 31 toolkits + admin UI |
| `build/` | 1.2 MB | 18 | Build artefacts (excluded from dist) |
| `docs/` | 908 KB | 65 | Documentation |
| `tests/` | 784 KB | 55 | PHPUnit test suite (excluded from dist) |
| `node-services/` | 100 KB | 13 | Node.js microservices |
| `services/` | 64 KB | 6 | Service definitions |
| `scripts/` | 60 KB | 5 | Build/maintenance scripts |
| `languages/` | 4 KB | 1 | Translation file |

**Repository total**: ~202 MB uncompressed, 9,179 files

---

## What's Excluded from Distribution ZIP

The `.distignore` file removes the following before the ZIP is built:

| Excluded | Approx. Size | Reason |
|----------|-------------|--------|
| `*.js.map` / `*.css.map` | ~20 MB | Source maps — dev/debug only |
| `assets/vendor/facebook-nodejs-business-sdk/` | 14 MB | Unused; tools call Graph API directly |
| `vendor/*/tests/`, `vendor/*/Tests/` etc. | ~5 MB | Vendor unit tests |
| `vendor/*/docs/`, `vendor/*/examples/` etc. | ~2 MB | Vendor documentation |
| `vendor/*/README*`, `vendor/*/CHANGELOG*` etc. | ~1 MB | Vendor metadata files |
| `tests/` | 784 KB | Plugin PHPUnit tests |
| `build/` | 1.2 MB | Build artefacts |
| `*.md` (except `README.md`) | ~900 KB | Docs including SIZE_BREAKDOWN.md |
| `composer.json`, `composer.lock`, `package.json`, `package-lock.json` | ~520 KB | Dev tooling |
| Sample/test `.pdf` files in vendor | ~52 files | Not needed at runtime |

> **Sharp native binaries note**: The `assets/vendor/sharp/node_modules/@img/sharp-libvips-linux-x64/` directory contains `libvips-cpp.so.42` (~15.8 MB). This binary is Linux x64-specific and is **not** currently listed in `.distignore`. Consider adding `assets/vendor/sharp/node_modules/@img/` to `.distignore` to save ~16 MB in the distribution ZIP, requiring users who need image processing to run `npm install sharp` on their server.

---

## Assets/Vendor — NPM Packages (79 MB total in repository)

### Image & Video Processing (24 MB)
| Package | Size | Purpose |
|---------|------|---------|
| `sharp` | 17 MB | High-performance image processing (includes libvips native binary for Linux x64) |
| `@remotion/renderer` | 4.3 MB | Video frame rendering engine |
| `remotion` | 1.8 MB | Core Remotion video framework |
| `@remotion/bundler` | 260 KB | Remotion bundler |

### Document Generation (23 MB)
| Package | Size | Purpose |
|---------|------|---------|
| `exceljs` | 7.3 MB | Excel spreadsheet generation and parsing |
| `pdfjs-dist` | 7.0 MB | Mozilla PDF.js for PDF rendering to images |
| `puppeteer-core` | 5.1 MB | Headless Chrome for PDF/screenshot rendering |
| `pdfkit` | 3.9 MB | PDF creation library |
| `pdf-lib` | 3.9 MB | PDF manipulation and form filling |
| `pdf-parse` | 2.1 MB | PDF text extraction (includes pdf.js v2.0.550) |
| `@puppeteer` | 1.0 MB | Puppeteer support utilities |
| `tesseract.js` | 332 KB | Pure JavaScript OCR engine (WebAssembly-based) |

### Social Media & Communications (17 MB)
| Package | Size | Purpose |
|---------|------|---------|
| `facebook-nodejs-business-sdk` | 14 MB | Facebook/Instagram Marketing API (**excluded from dist ZIP**) |
| `twitter-api-v2` | 2.1 MB | Twitter/X API v2 client |
| `nodemailer` | 628 KB | Email sending |
| `linkedin-api-client` | 204 KB | LinkedIn integration |
| `mailparser` | 60 KB | Email parsing |

### Utilities & Integrations (8 MB)
| Package | Size | Purpose |
|---------|------|---------|
| `stripe` | 1.5 MB | Stripe payment processing |
| `validator` | 1.2 MB | String validation |
| `cheerio` | 812 KB | Server-side HTML parsing |
| `libphonenumber-js` | 780 KB | Phone number parsing and formatting |
| `i18next` | 436 KB | Internationalization framework |
| `ical-generator` | 212 KB | Calendar/ICS file generation |
| `qrcode` | 192 KB | QR code generation |
| `turndown` | 172 KB | HTML to Markdown conversion |
| `fluent-ffmpeg` | 164 KB | FFmpeg wrapper for video processing |
| `csv-parse` | 136 KB | CSV parsing |
| `csv-stringify` | 80 KB | CSV generation |
| `subtitle` | 72 KB | Video subtitle format handling |
| `canvas` | 72 KB | Canvas stub/headers (native build excluded from dist) |
| `turf` | 60 KB | Geospatial operations |

---

## PHP Vendor (Composer) — 76 MB in Repository

PHP libraries (tests/docs/READMEs excluded from dist via `.distignore`):

### PDF & Document Generation (60 MB)
| Package | Size | Purpose |
|---------|------|---------|
| `tecnickcom/tcpdf` | 29 MB | PDF generation (includes ~12 MB of international fonts) |
| `phpoffice/*` | 17 MB | Excel, Word, PowerPoint file handling |
| `dompdf/dompdf` | 14 MB | HTML to PDF conversion |

### Data & Utilities (14 MB)
| Package | Size | Purpose |
|---------|------|---------|
| `thecodingmachine/safe` | 8.5 MB | Type-safe PHP stdlib wrappers |
| `dvdoug/boxpacker` | 3.3 MB | 3D bin packing / shipping logistics |
| `sabberworm/php-css-parser` | 1.3 MB | CSS parsing (used by Dompdf) |
| `markbaker/matrix` + `complex` | 1.1 MB | Math libraries for PHPSpreadsheet |
| `masterminds/html5` | 1.0 MB | HTML5 parser |
| `maennchen/zipstream-php` | 504 KB | Streaming ZIP generation |
| `smalot/pdfparser` | 476 KB | PHP PDF text extraction fallback |
| `thiagoalessio/tesseract_ocr` | 380 KB | PHP wrapper for system Tesseract OCR binary |
| `symfony/polyfill-mbstring` | 152 KB | mbstring polyfill |
| `psr/*` | 112 KB | PSR standards interfaces |

---

## Bundled JavaScript — bin/ (27 MB in Repository)

Webpack-bundled Node.js scripts. Source maps are in the repo but **excluded from the distribution ZIP**.

| File | Repo Size | Notes |
|------|-----------|-------|
| `generate-pdf.bundle.js` | 4.8 MB | PDF generation bundle (included in dist) |
| `generate-pdf.bundle.js.map` | 7.2 MB | Source map — excluded from dist |
| `generate-word.bundle.js` | 3.3 MB | Word generation bundle (included in dist) |
| `generate-word.bundle.js.map` | 4.8 MB | Source map — excluded from dist |
| `generate-excel.bundle.js` | 2.1 MB | Excel generation bundle (included in dist) |
| `generate-excel.bundle.js.map` | 3.8 MB | Source map — excluded from dist |
| `remotion-render.bundle.js` | 2.5 KB | Remotion video render entry (included in dist) |
| `remotion-render.bundle.js.map` | 7.1 KB | Source map — excluded from dist |
| `sharp-process.js` | 7.5 KB | Sharp image processing service (included in dist) |

**Bundles only (no source maps)**: ~10.2 MB uncompressed → ~2.5 MB compressed in ZIP

---

## PHP Source Code — includes/ (18 MB, 1,115 files)

| Subdirectory / Feature | Description |
|------------------------|-------------|
| `tools/` | 610 PHP tool files covering all 31 toolkit domains |
| `admin/` | WordPress admin UI, settings pages |
| `mcp-apps/` | MCP Apps — per-assistant remote MCP server connections |
| `calendar-booking/` | Calendar and booking system |
| `data-stores/` | Toolkit data persistence layer |
| `elementor/` | Elementor widget integrations |
| `rest/` | Pro REST API endpoints |
| `services/` | Service classes |
| `helpers/` | Utility/helper classes |
| `bundled-skills/` | Pre-built skill bundles |
| `research-add/` | JetEngine CPT research tools |
| `src/` | Additional compiled source |
| `interfaces/` | PHP interfaces |
| `metaboxes/` | WordPress meta box definitions |
| `migrations/` | Database migration scripts |
| `vault/` | Password vault implementation |
| `cli/` | WP-CLI commands |
| `blocks/` | Gutenberg block integrations |

### 31 Toolkits

| Toolkit | Init File |
|---------|-----------|
| AI Tool Builder | `ai-tool-builder-toolkit-init.php` |
| Analytics | `analytics-toolkit-init.php` |
| Architect Agent | `architect-agent-toolkit-init.php` |
| Architectural Design | `architectural-design-toolkit-init.php` |
| Calendar & Booking | `calendar-booking-toolkit-init.php` |
| Chat Channels | `chat-channels-toolkit-init.php` |
| CRE Debt | `cre-debt-toolkit-init.php` |
| CRM | `crm-toolkit-init.php` |
| DJ Management | `dj-management-toolkit-init.php` |
| Document Generation | `document-generation-toolkit-init.php` |
| ECA Management | `eca-management-init.php` |
| E-Commerce | `ecommerce-toolkit-init.php` |
| Financial Planner | `financial-planner-toolkit-init.php` |
| Google Chat Webhook | `google-chat-webhook-init.php` |
| Health & Wellness | `health-wellness-management-init.php` |
| Healthcare Imaging | `healthcare-imaging-toolkit-init.php` |
| Image Production | `image-production-toolkit-init.php` |
| JetEngine CPT Research | `jetengine-cpt-research-init.php` |
| Law Firm | `law-firm-toolkit-init.php` |
| MCP Apps | `mcp-apps/mcp-apps-init.php` |
| Media | `media-toolkit-init.php` |
| Multilingual | `multilingual-toolkit-init.php` |
| Password Vault | `password-vault-init.php` |
| Places Management | `places-management-init.php` |
| Project Management | `project-management-init.php` |
| Quiz Management | `quiz-management-init.php` |
| Regulatory Registration | `regulatory-registration-toolkit-init.php` |
| Site Creator | `site-creator-toolkit-init.php` |
| Skills Manager | `skills-manager-init.php` |
| Social Media | `social-media-toolkit-init.php` |
| Video Production | `video-production-toolkit-init.php` |

---

## File Type Analysis (Full Repository)

| File Type | Count | Notes |
|-----------|-------|-------|
| PHP (`.php`) | 3,736 | Source code + vendor libraries |
| JavaScript (`.js`) | 2,620 | NPM packages + bundles |
| TypeScript (`.ts`) | 1,389 | TypeScript source files in vendor |
| Markdown (`.md`) | 256 | Documentation |
| Source Maps (`.map`) | 141 | Dev/debug only — excluded from dist |
| JSON (`.json`) | 127 | Package manifests + config |
| CSS (`.css`) | 106 | Stylesheets |
| Fonts (`.z`) | 84 | TCPDF compressed fonts |
| HTML (`.html`) | 59 | Templates |
| YAML (`.yml`) | 55 | CI/CD + config |
| PDF (`.pdf`) | 52 | Vendor test samples — excluded from dist |
| AFM (`.afm`) | 42 | Font metric files |
| Text (`.txt`) | 36 | Misc |
| SVG (`.svg`) | 32 | Icons |
| Word (`.docx`) | 25 | Vendor test documents — excluded from dist |

**Total**: 9,179 files

---

## Top Largest Files in Repository

| Size | File | Distribution Status |
|------|------|---------------------|
| 15.8 MB | `assets/vendor/sharp/node_modules/@img/sharp-libvips-linux-x64/lib/libvips-cpp.so.42` | ⚠️ Included — consider excluding (platform-specific binary) |
| 7.2 MB | `bin/generate-pdf.bundle.js.map` | ❌ Excluded (source map) |
| 4.8 MB | `bin/generate-pdf.bundle.js` | ✅ Included |
| 4.8 MB | `bin/generate-word.bundle.js.map` | ❌ Excluded (source map) |
| 3.8 MB | `bin/generate-excel.bundle.js.map` | ❌ Excluded (source map) |
| 3.3 MB | `bin/generate-word.bundle.js` | ✅ Included |
| 2.5 MB | `assets/vendor/pdfkit/js/pdfkit.standalone.js` | ✅ Included |
| 2.4 MB | `vendor/phpoffice/phpword/tests/.../docChinese.doc` | ❌ Excluded (vendor test) |
| 2.3 MB | `assets/vendor/pdfjs-dist/legacy/build/pdf.worker.mjs` | ✅ Included |
| 2.3 MB | `assets/vendor/facebook-nodejs-business-sdk/dist/umd.js` | ❌ Excluded (Facebook SDK) |
| 2.1 MB | `bin/generate-excel.bundle.js` | ✅ Included |
| 1.9 MB | `assets/vendor/exceljs/dist/exceljs.js` | ✅ Included |
| 1.8 MB | `vendor/dvdoug/boxpacker/tests/data/items.csv` | ❌ Excluded (vendor test) |
| 1.8 MB | `vendor/tecnickcom/tcpdf/fonts/freeserif.z` | ✅ Included |
| 1.5 MB | `vendor/tecnickcom/tcpdf/fonts/cid0*.php` (×4) | ✅ Included |
| 1.5 MB | `assets/vendor/pdf-parse/lib/pdf.js/v2.0.550/build/pdf.worker.js` | ✅ Included |
| 1.4 MB | `assets/vendor/pdfjs-dist/legacy/build/pdf.worker.min.mjs` | ✅ Included |
| 932 KB | `assets/vendor/exceljs/dist/exceljs.min.js` | ✅ Included |
| 912 KB | `assets/vendor/pdfjs-dist/legacy/build/pdf.sandbox.mjs` | ✅ Included |
| 892 KB | `vendor/tecnickcom/tcpdf/tcpdf.php` | ✅ Included |

---

## OCR & Image Processing Notes

### Tesseract OCR
The PHP wrapper (`thiagoalessio/tesseract_ocr`, 380 KB) requires the **system Tesseract binary** (`apt-get install tesseract-ocr`). The `tesseract.js` package (332 KB) provides a pure-JavaScript WebAssembly fallback with no system dependency.

### PDF OCR with Canvas
The `canvas` stub (72 KB) in `assets/vendor/canvas/` is headers only. Native canvas binaries are excluded via `.distignore` (`assets/vendor/canvas/build/`). For PDF OCR support:
1. Install Node.js on your server
2. Run `npm install canvas@2` in the plugin directory  
   (`canvas@3+` requires Node ≥ 20.9.0; use `canvas@2` for Node 18.x or Node 20.x < 20.9.0)
3. **On shared hosts (e.g. Cloudways)**: Run `mkdir node_modules && chmod 775 node_modules` first if you get `EACCES: permission denied`

### Sharp Image Processing
The `sharp` package provides high-performance image processing (resize, format conversion, watermarking, compositing) powered by `libvips`. The bundled Linux x64 binary (`libvips-cpp.so.42`, 15.8 MB) is platform-specific. The `.distignore` does not currently exclude it — consider adding `assets/vendor/sharp/node_modules/@img/` to save ~16 MB in the ZIP (users would need to run `npm install sharp` on their server).

---

## Optimization Opportunities

### ✅ Currently Implemented (via .distignore)

| Optimization | Approx. Savings (uncompressed) | Status |
|--------------|--------------------------------|--------|
| Source maps excluded (`*.js.map`, `*.css.map`) | ~20 MB | ✅ Done |
| Facebook SDK excluded | ~14 MB | ✅ Done |
| Canvas native binaries excluded (`canvas/build/`) | ~1 MB | ✅ Done |
| Vendor test directories excluded | ~5 MB | ✅ Done |
| Vendor docs/READMEs excluded | ~3 MB | ✅ Done |
| Sample PDFs excluded | ~1 MB | ✅ Done |
| Build artefacts excluded | ~1.2 MB | ✅ Done |

### 🔲 Potential Further Optimizations

| Optimization | Approx. Savings | Trade-off |
|--------------|----------------|-----------|
| Exclude sharp libvips binaries (`@img/`) | ~16 MB uncompressed | Users need `npm install sharp` on their server |
| Dynamic TCPDF font loading (ship a subset) | ~12 MB uncompressed | Requires internet access for additional fonts |
| Code splitting for document bundles | ~3 MB compressed | More complex load logic |
| Exclude Puppeteer Core | ~5 MB uncompressed | Removes headless Chrome PDF rendering |

---

## Why Current Size Is Justified

### Feature Density

With 610 tool files across 31 toolkits, the pro plugin provides:
- Complete document generation (PDF create/extract/merge, Word .docx, Excel .xlsx, HTML→PDF)
- Social media automation (Facebook, Twitter/X, LinkedIn)
- Video production (Remotion-based rendering)
- Healthcare imaging (DICOM support)
- CRM, project management, calendar & booking
- Law firm, financial planning, architectural design tools
- Password vault with encryption
- MCP Apps (per-assistant remote MCP server connections)
- E-commerce (Shopify, Stripe, WooCommerce)
- AI tool builder, skills manager, site creator
- And 21 more specialized toolkits

**Per-tool footprint (repo)**: ~202 MB ÷ 610 tools = **~330 KB per tool**

### Comparison to Other WordPress Plugins

| Plugin | ZIP Size | Features |
|--------|----------|----------|
| **NV oOS Pro** | ~45–50 MB (est.) | 610 AI tools, 31 toolkits, document/video/image generation |
| WooCommerce + Extensions | 30–100 MB | E-commerce only |
| Elementor Pro | 12–15 MB | Page builder only |
| WPBakery + Addons | 40–80 MB | Page builder + extensions |
| Jetpack | 5–15 MB | Limited features, requires external services |
| ACF Pro | 2 MB | Custom fields only |

### Key Differentiators
- ✅ **Complete offline functionality** — No mandatory external API dependencies for core features
- ✅ **Privacy-focused** — Processing stays local; no data sent to external services by default
- ✅ **Professional document generation** — PDF, Word, Excel with international font support
- ✅ **Video production** — Remotion-based rendering built in
- ✅ **High-performance image processing** — libvips/Sharp bundled
- ✅ **Battle-tested libraries** — Industry-standard packages throughout
