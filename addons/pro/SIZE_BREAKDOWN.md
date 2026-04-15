# Pro Plugin Size Breakdown

## Overview

**Source Directory Size**: ~176 MB (apparent size on disk)  
**Estimated Distributed ZIP**: ~39 MB  
**Total Number of Files**: ~9,184 files  
**PHP Files**: 3,738  
**JS Files (non-map)**: 2,624  
**JS Source Maps**: 63  
**Font Files**: 100  

> **Optimization History**:  
> - v1.1.0: 54 MB (source maps, Facebook SDK included)
> - v1.1.1: 87 MB (regression: canvas native binaries accidentally included)  
> - v1.1.2: 33 MB (fixed: excluded canvas binaries, old pdf.js versions, source maps)
> - **Current (~v1.2+): ~39 MB** (added sharp image processing, remotion video, pdfjs-dist, 31 toolkits, 605+ tools, 12 node microservices)
>
> **Growth since v1.1.2** is primarily due to:
> - `sharp` image processing library (17 MB — includes 16 MB Linux native binary `libvips-cpp.so.42`)
> - `@remotion`/`remotion` video generation (3.3 MB)
> - `pdfjs-dist` standalone Mozilla PDF.js package (7 MB)
> - Expanded `includes/` from 11 MB to 16 MB (new toolkits, MCP Apps, Vault, Healthcare, etc.)
> - Expanded `bin/` from 11 MB to 27 MB (remotion bundle + source maps)

---

## What's In The ZIP File

After `.distignore` exclusions, the ZIP contains approximately:

| Directory | Apparent Size | Files | % of Total | Description |
|-----------|--------------|-------|------------|-------------|
| `assets/vendor` | 66 MB | ~2,800 | 48% | Bundled NPM packages (JS libraries) |
| `vendor` | 65 MB | ~3,600 | 47% | PHP Composer dependencies (TCPDF, PHPOffice, dompdf, smalot/pdfparser, etc.) |
| `includes` | 16 MB | ~1,065 | 12% | PHP source code (605+ tools, 31 toolkit init files, admin UI, MCP Apps, Vault) |
| `bin` | ~10 MB | 7 | <1% | Webpack-bundled generation scripts (PDF/Word/Excel/Remotion), excl. maps |
| `node-services` | 68 KB | 13 | <1% | Node.js microservices (OCR, PDF extract, image preprocess, FFmpeg, etc.) |
| `docs` | 908 KB | ~50 | <1% | Documentation files |
| `scripts` | 60 KB | 5 | <1% | Build and maintenance scripts |
| `services` | 36 KB | ~6 | <1% | Service definitions (yfinance) |

**Excluded from ZIP** (~47 MB):
- `assets/vendor/facebook-nodejs-business-sdk/` — 14 MB (CDN available if needed)
- All `*.js.map` / `*.css.map` — 17.4 MB (development only)
- `build/` directory — 1.1 MB
- `vendor/*/tests/` directories — ~12 MB
- `vendor/*/docs/` and `vendor/*/examples/` — ~2 MB
- PDF sample files in vendor — ~1.7 MB

**Total: ~176 MB source → ~129 MB after exclusions → ~39 MB compressed ZIP**

### What's Excluded (Not in Distribution ZIP)

- ✅ **JavaScript/CSS source maps** (63 files, 17.4 MB uncompressed): Development only
- ✅ **Facebook SDK** (`assets/vendor/facebook-nodejs-business-sdk/`, 14 MB): Tools use Graph API directly
- ✅ **build/** directory (1.1 MB): TMA templates, workflow builder build artifacts
- ✅ **Vendor tests/docs/examples** (~14 MB): PHPUnit test files, README, CHANGELOG files
- ✅ **PDF sample files** (~1.7 MB): Test PDFs in vendor packages

#### ⚠️ Sharp Native Binaries (Not Yet Excluded)

`assets/vendor/sharp/node_modules/@img/sharp-libvips-linux-x64/lib/libvips-cpp.so.42` is **15.5 MB** and is a Linux-specific native binary. It is **not currently excluded** by `.distignore`. This is similar to the canvas native binary issue fixed in v1.1.2.

**Recommendation**: Add `assets/vendor/sharp/node_modules/@img/sharp-libvips-linux-x64/` to `.distignore` to save ~16 MB. Sharp's JS wrapper will still load; users needing native acceleration can run `npm install sharp` in the plugin directory.

---

## Top 20 Largest Files in Distribution

Excluding `.map` files and `facebook-nodejs-business-sdk`:

| Size | File | Purpose |
|------|------|---------|
| 15.5 MB | assets/vendor/sharp/node_modules/@img/sharp-libvips-linux-x64/lib/libvips-cpp.so.42 | Sharp Linux native binary (⚠️ not yet excluded) |
| 4.9 MB | bin/generate-pdf.bundle.js | PDF generation bundle (includes PDFKit) |
| 3.2 MB | bin/generate-word.bundle.js | Word generation bundle (includes docx library) |
| 2.5 MB | assets/vendor/pdfkit/js/pdfkit.standalone.js | PDFKit standalone for PDF creation |
| 2.2 MB | assets/vendor/pdfjs-dist/legacy/build/pdf.worker.mjs | Mozilla PDF.js worker (standalone) |
| 2.1 MB | bin/generate-excel.bundle.js | Excel generation bundle (includes ExcelJS) |
| 1.8 MB | assets/vendor/exceljs/dist/exceljs.js | ExcelJS library for Excel files |
| 1.8 MB | vendor/tecnickcom/tcpdf/fonts/freeserif.z | TCPDF FreeSerif font (international characters) |
| 1.7 MB | assets/vendor/exceljs/dist/exceljs.bare.js | ExcelJS bare build |
| 1.5 MB | vendor/tecnickcom/tcpdf/fonts/cid0kr.php | TCPDF Korean CID font |
| 1.5 MB | vendor/tecnickcom/tcpdf/fonts/cid0jp.php | TCPDF Japanese CID font |
| 1.5 MB | vendor/tecnickcom/tcpdf/fonts/cid0ct.php | TCPDF Traditional Chinese CID font |
| 1.5 MB | vendor/tecnickcom/tcpdf/fonts/cid0cs.php | TCPDF Simplified Chinese CID font |
| 1.5 MB | assets/vendor/pdf-parse/lib/pdf.js/v2.0.550/build/pdf.worker.js | PDF.js worker v2.0.550 |
| 1.4 MB | assets/vendor/pdfjs-dist/legacy/build/pdf.worker.min.mjs | Mozilla PDF.js worker (minified) |
| 0.9 MB | assets/vendor/exceljs/dist/exceljs.min.js | ExcelJS minified |
| 0.9 MB | assets/vendor/pdfjs-dist/legacy/build/pdf.sandbox.mjs | PDF.js sandbox |
| 0.9 MB | vendor/tecnickcom/tcpdf/tcpdf.php | TCPDF main library |
| 0.8 MB | assets/vendor/pdfjs-dist/legacy/build/pdf.mjs | PDF.js core |
| 0.8 MB | vendor/tecnickcom/tcpdf/fonts/freesans.z | TCPDF FreeSans font |

**Note**: All source maps (`.js.map`) and the Facebook SDK are excluded from distribution.

---

## File Type Analysis

### Included in Distribution

| File Type | Count | Approximate Size | Notes |
|-----------|-------|-----------------|-------|
| JavaScript (.js) | ~2,624 | ~48 MB | NPM packages and bundles |
| PHP (.php) | ~3,738 | ~17 MB | Source code and vendor libraries |
| Fonts (.ttf, .woff, .woff2, .z) | ~100 | ~17 MB | TCPDF fonts for international PDFs |
| JSON/Config | ~1,200 | ~5 MB | Package manifests, locale files |
| Other | ~1,500 | ~42 MB | Native binaries (.so), archives, misc |

### Excluded from Distribution

| File Type | Count | Uncompressed | Reason |
|-----------|-------|-------------|--------|
| JS/CSS Source Maps (.js.map, .css.map) | 63 | 17.4 MB | Development only |
| Facebook SDK | N/A | 14 MB | Unused; tools use Graph API directly |
| Vendor test files | ~1,000 | ~12 MB | PHPUnit tests |
| Vendor docs/examples | ~200 | ~2 MB | Documentation |
| Build artifacts (build/) | ~25 | 1.1 MB | TMA templates, workflow builder |
| Sample PDFs | ~15 | ~1.7 MB | Test files in vendor packages |

---

## Assets/Vendor (NPM Packages) — 66 MB Apparent

JavaScript/Node.js libraries bundled for browser and Node.js usage:

### Image & Video Processing (21 MB)

| Package | Apparent Size | Purpose |
|---------|--------------|---------|
| `sharp` | 17 MB | High-performance image processing (includes Linux native binary) |
| `@remotion` + `remotion` | 3.3 MB | Programmatic video generation |

### Document Generation & OCR (27 MB)

| Package | Apparent Size | Purpose |
|---------|--------------|---------|
| `pdfjs-dist` | 7.0 MB | Mozilla PDF.js standalone (rendering to images for OCR) |
| `exceljs` | 7.3 MB | Excel spreadsheet generation and parsing |
| `pdfkit` | 3.9 MB | PDF generation library |
| `pdf-lib` | 3.9 MB | PDF manipulation and form filling |
| `puppeteer-core` + `@puppeteer` | 6.1 MB | Headless Chrome for PDF rendering |
| `pdf-parse` | 2.1 MB | PDF text extraction (v2.0.550 only) |
| `tesseract.js` | 332 KB | Pure JavaScript OCR engine (WebAssembly-based) |

### Social Media & Marketing (16 MB)

| Package | Apparent Size | Purpose |
|---------|--------------|---------|
| `facebook-nodejs-business-sdk` | 14 MB | Facebook/Instagram Marketing API (⛔ **excluded from ZIP**) |
| `twitter-api-v2` | 2.1 MB | Twitter API v2 client |
| `linkedin-api-client` | 204 KB | LinkedIn integration |

### E-commerce & Payments (1.5 MB)

| Package | Apparent Size | Purpose |
|---------|--------------|---------|
| `stripe` | 1.5 MB | Stripe payment processing |
| `woocommerce-rest-api` | 28 KB | WooCommerce integration |

### Communication & Utilities (3 MB)

| Package | Apparent Size | Purpose |
|---------|--------------|---------|
| `nodemailer` | 628 KB | Email sending |
| `i18next` | 436 KB | Internationalization framework |
| `cheerio` | 812 KB | HTML parsing |
| `validator` | 1.2 MB | String validation |
| `libphonenumber-js` | 780 KB | Phone number formatting |
| `ical-generator` | 212 KB | iCalendar event generation |
| `qrcode` | 172 KB | QR code generation |
| `google-translate-api-x` | 60 KB | Translation API client |
| `franc` | 20 KB | Language detection |
| `mjml` | 16 KB | Email template rendering |

### Media & File Processing (1 MB)

| Package | Apparent Size | Purpose |
|---------|--------------|---------|
| `fluent-ffmpeg` | 164 KB | FFmpeg wrapper for video/audio |
| `gif-encoder` | 60 KB | GIF generation |
| `video-stitch` | 28 KB | Video concatenation |
| `subtitle` | 72 KB | SRT/VTT subtitle parsing |

### Data Utilities (<1 MB each)

| Package | Approximate Size | Purpose |
|---------|-----------------|---------|
| `csv-parse` | 136 KB | CSV parsing |
| `csv-stringify` | 80 KB | CSV generation |
| `fast-csv` | 24 KB | Fast CSV processing |
| `turndown` | 172 KB | HTML to Markdown conversion |
| `regression` | 16 KB | Statistical regression |
| `currency.js` | 12 KB | Currency formatting |
| `iso-639-1` | 20 KB | ISO language codes |
| `turf` | 60 KB | Geospatial analysis |

---

## PHP Vendor (Composer) — 65 MB Apparent

PHP libraries managed by Composer:

### PDF & Document Generation (55 MB)

| Package | Apparent Size | Purpose |
|---------|--------------|---------|
| `tecnickcom/tcpdf` | 29 MB | PDF generation (16 MB of fonts for international characters) |
| `phpoffice/*` (phpspreadsheet, phpword, phppresentation) | 17 MB | Excel, Word, PowerPoint file handling |
| `dompdf/dompdf` | 13 MB | HTML to PDF conversion |

### Box Packing & Shipping (3 MB)

| Package | Apparent Size | Purpose |
|---------|--------------|---------|
| `dvdoug/boxpacker` | 3.0 MB | 3D bin-packing algorithm (shipping/logistics tools) |

### Utilities (8 MB)

| Package | Apparent Size | Purpose |
|---------|--------------|---------|
| `thecodingmachine/safe` | 6.6 MB | Type-safe PHP wrappers |
| `markbaker/matrix` + `markbaker/complex` | 1.1 MB | Math libraries (PHPSpreadsheet dependency) |
| `masterminds/html5` | 1.0 MB | HTML5 parser (dompdf dependency) |
| `sabberworm/php-css-parser` | 1.3 MB | CSS parsing (dompdf dependency) |
| `maennchen/zipstream-php` | 524 KB | Streaming ZIP creation |
| `smalot/pdfparser` | 476 KB | PHP PDF text extraction fallback |
| `symfony/*` | 152 KB | mbstring polyfills |
| `psr/*` | 112 KB | PHP standard interfaces |
| `thiagoalessio/tesseract_ocr` | 380 KB | PHP wrapper for Tesseract OCR binary |

**Note**: Vendor `tests/`, `docs/`, and `examples/` directories are excluded via `.distignore`, reducing from ~65 MB source to ~51 MB in distribution.

---

## Bundled JavaScript (bin/) — 27 MB Source, ~10 MB in Distribution

Webpack-bundled files for document and video generation:

| File | Source Size | In ZIP (excl. maps) | Purpose |
|------|------------|---------------------|---------|
| `generate-pdf.bundle.js` | 4.9 MB | 4.9 MB | PDF generation bundle (includes PDFKit) |
| `generate-word.bundle.js` | 3.3 MB | 3.3 MB | Word generation bundle (includes docx library) |
| `generate-excel.bundle.js` | 2.1 MB | 2.1 MB | Excel generation bundle (includes ExcelJS) |
| `remotion-render.bundle.js` | 4 KB | 4 KB | Remotion video render entry point |
| `sharp-process.js` | 8 KB | 8 KB | Sharp image processing helper |
| `bin/data/` | 656 KB | 656 KB | Generation data assets |
| Source maps (`.map`) | 15.7 MB | ❌ excluded | Development/debugging only |

---

## Node.js Microservices (node-services/) — 68 KB

Lightweight Node.js service files (not npm packages) called via PHP:

| Service | Purpose |
|---------|---------|
| `ocr-service.js` | Tesseract.js OCR orchestration |
| `pdf-extract-service.js` | PDF text extraction via pdf-parse |
| `canvas-service.js` | Canvas/image manipulation |
| `image-preprocess-service.js` | Image preprocessing for OCR |
| `ffmpeg-service.js` | FFmpeg video/audio processing |
| `lang-detect-service.js` | Language detection via franc |
| `mjml-service.js` | MJML email template rendering |
| `phone-format-service.js` | Phone number formatting |
| `prettier-service.js` | Code formatting |
| `qrcode-service.js` | QR code generation |
| `translate-service.js` | Translation via google-translate-api-x |
| `yfinance-client.js` | Yahoo Finance data client |

---

## PHP Source Code (includes/) — 16 MB

### 605+ Pro Tools across 19 Toolkit Subdirectories

The `includes/tools/` directory contains 608 PHP class files across:

| Subdirectory | Description |
|-------------|-------------|
| `ai-tool-builder/` | AI Tool Builder toolkit tools |
| `analytics/` | Analytics and reporting tools |
| `architect-agent/` | Architect agent orchestration tools |
| `architectural-design/` | Architectural design and drawing tools |
| `calendar-booking/` | Calendar and appointment booking tools |
| `cre-debt/` | Commercial real estate debt tools |
| `crm/` | CRM and contact management tools |
| `dj-management/` | DJ and event management tools |
| `document-generation/` | PDF, Word, Excel generation tools |
| `ecommerce/` | E-commerce and Shopify tools |
| `financial-planning/` | Financial planning and analysis tools |
| `image-production/` | Image generation and processing tools |
| `law-firm/` | Law firm and legal document tools |
| `multilingual/` | Translation and multilingual tools |
| `regulatory-registration/` | Regulatory compliance tools |
| `site-creator-toolkit/` | Site creation and templating tools |
| `social-media/` | Social media management tools |
| `vector-storage/` | Vector database tools |
| `video-production/` | Video production and editing tools |

Plus 193 root-level tool class files (orchestration, scheduling, templates, etc.)

### 31 Toolkit Init Files

| Toolkit | Purpose |
|---------|---------|
| `ai-tool-builder-toolkit` | AI-powered tool creation and management |
| `analytics-toolkit` | Analytics and data reporting |
| `architect-agent-toolkit` | Multi-agent orchestration |
| `architectural-design-toolkit` | Architectural drawings and specifications |
| `calendar-booking-toolkit` | Calendar, appointments, and scheduling |
| `chat-channels-toolkit` | Multi-channel messaging (SMS, email, Slack) |
| `cre-debt-toolkit` | Commercial real estate debt management |
| `crm-toolkit` | Customer relationship management |
| `dj-management-toolkit` | DJ sets, events, and jukebox management |
| `document-generation-toolkit` | PDF, Word, Excel document creation |
| `eca-management` | ECA (Extra-Curricular Activity) management |
| `ecommerce-toolkit` | WooCommerce and Shopify integration |
| `financial-planner-toolkit` | Financial planning and budgeting |
| `google-chat-webhook` | Google Chat integration |
| `health-wellness-management` | Health and wellness tracking |
| `healthcare-imaging-toolkit` | DICOM imaging and medical records |
| `image-production-toolkit` | Image generation and processing |
| `jetengine-cpt-research` | JetEngine CPT research integration |
| `law-firm-toolkit` | Legal document management |
| `mcp-apps` | Per-assistant remote MCP server connections (max 10/assistant) |
| `media-toolkit` | Media collections and templates |
| `multilingual-toolkit` | Multi-language translation and detection |
| `password-vault` | Encrypted password vault with Bitwarden sync |
| `places-management` | Location and geospatial management |
| `project-management` | Project, task, and milestone management |
| `quiz-management` | Quiz creation and management |
| `regulatory-registration-toolkit` | Regulatory compliance and registration |
| `site-creator-toolkit` | Website templates and scaffolding |
| `skills-manager` | AI skill library management |
| `social-media-toolkit` | Social media automation (Facebook, Twitter, LinkedIn) |
| `video-production-toolkit` | Video production and editing |

### Other Key Components

- **`includes/admin/`** — Pro admin UI panels
- **`includes/mcp-apps/`** — 5 classes for per-assistant remote MCP server support
- **`includes/vault/`** — 8 classes for encrypted password vault with Bitwarden sync
- **`includes/rest/`** — Pro REST API controllers
- **`includes/migrations/`** — Database migration scripts
- **`includes/data-stores/`** — Data store factory and implementations
- **`includes/bundled-skills/`** — Pre-built AI skill bundles
- **`includes/research-add/`** — Research addon integrations

---

## Build Directory (build/) — 1.2 MB

Five TMA (Template/App) build directories — **excluded from distribution ZIP**:

| Directory | Size | Purpose |
|-----------|------|---------|
| `build/workflow-builder/` | 256 KB | Workflow builder UI |
| `build/tma-template-builder/` | 236 KB | Template builder UI |
| `build/tma-woo-shop/` | 228 KB | WooCommerce shop template |
| `build/tma-shopify-jewelry/` | 224 KB | Shopify jewelry template |
| `build/tma-shopify-shop/` | 216 KB | Shopify shop template |

---

## Optimization Opportunities

### ✅ Implemented (since v1.1.2)

1. **✅ JS/CSS Source Maps Excluded** (−17.4 MB uncompressed)  
   63 `.js.map` / `.css.map` files excluded. Only needed for debugging.

2. **✅ Facebook SDK Excluded** (−14 MB uncompressed)  
   `assets/vendor/facebook-nodejs-business-sdk/` excluded. Tools use Graph API directly.

3. **✅ Sample PDF Files Excluded** (−1.7 MB uncompressed)  
   Test PDFs in vendor packages excluded.

4. **✅ Old pdf.js Versions Removed** from `pdf-parse`  
   Only `v2.0.550` retained (previous versions v1.9.426, v1.10.88, v1.10.100 removed).

5. **✅ Canvas Native Binaries Excluded** (was −181 MB in v1.1.1)  
   `assets/vendor/canvas/build/` excluded. Tiny JS stub (33 KB) retained.

### ❌ Not Yet Implemented

6. **⚠️ Sharp Native Binaries** (Save ~16 MB uncompressed, ~5 MB compressed)  
   `assets/vendor/sharp/node_modules/@img/sharp-libvips-linux-x64/` is a 16 MB Linux binary.  
   Add to `.distignore`: `assets/vendor/sharp/node_modules/@img/`  
   Sharp's JS wrapper still loads; users needing native acceleration run `npm install sharp`.  
   **Complexity**: Easy — one `.distignore` line.

7. **Dynamic TCPDF Font Loading** (Save ~4 MB compressed)  
   Ship minimal font set; download additional on demand.  
   **Complexity**: High — requires infrastructure.

8. **Code Splitting for Document Formats** (Save ~3 MB compressed)  
   Load PDF/Word/Excel bundle only on demand.  
   **Complexity**: Medium.

9. **Optional Puppeteer Core** (Save ~2 MB compressed)  
   Most users don't need headless Chrome. Document it as optional.  
   **Complexity**: Easy.

### Maximum Optimization Potential

| Optimization | ZIP Reduction | New Size | Status |
|--------------|---------------|----------|--------|
| **Current** | — | **~39 MB** | Baseline |
| Exclude sharp native binary | −5 MB | ~34 MB | ⚠️ Recommended |
| Dynamic TCPDF fonts | −4 MB | ~30 MB | Not done |
| Code splitting | −3 MB | ~27 MB | Not done |
| Optional Puppeteer | −2 MB | ~25 MB | Not done |
| **Maximum potential** | **−14 MB** | **~25 MB** | If all applied |

---

## Why Canvas / Sharp Binaries Are Excluded or Should Be

Native binary libraries are platform-specific and cannot be bundled cross-platform:

**Canvas** (`assets/vendor/canvas/build/` — excluded ✅):
- Was 181 MB of Linux shared libraries
- Requires system-level installation: `apt-get install libcairo2-dev libjpeg-dev libpango1.0-dev`
- For PDF OCR: `npm install canvas@2` in plugin directory

**Sharp** (`assets/vendor/sharp/node_modules/@img/sharp-libvips-linux-x64/` — ⚠️ not yet excluded):
- `libvips-cpp.so.42` alone is 15.5 MB
- Platform-specific; won't work on Windows/macOS
- For image processing: `npm install sharp` in plugin directory

---

## Feature Density

With ~39 MB, the pro plugin provides:

- **605+ AI-powered pro tools** (across 19 toolkit subdirectories + root tools)
- **31 specialized toolkits** (was ~15 in v1.1.x)
- Complete document generation (PDF, Word, Excel, HTML→PDF)
- Video generation (Remotion-based)
- Image processing (Sharp, canvas, image preprocessing)
- Social media automation (Facebook, Twitter/X, LinkedIn)
- E-commerce integrations (WooCommerce, Shopify, Stripe)
- CRM + project management
- Healthcare imaging (DICOM)
- Password vault (encrypted, Bitwarden sync)
- MCP Apps (per-assistant remote MCP server connections)
- 12 Node.js microservices
- Multi-language support (100+ languages)
- **~64 KB per tool average** (~39 MB ÷ 605 tools)

---

## Distribution Size Over Time

| Version | ZIP Size | Change | Notes |
|---------|----------|--------|-------|
| 1.0.0 | ~35 MB | — | Initial release |
| 1.1.0 | ~48 MB | +13 MB | Added Facebook SDK, video tools |
| 1.1.1 | ~54 MB | +6 MB | Added pdf-parse (30 MB uncompressed) |
| 1.1.2 | ~33 MB | −21 MB | Fixed: excluded canvas binaries, old pdf.js, source maps, Facebook SDK |
| **Current** | **~39 MB** | +6 MB | Added sharp, remotion, pdfjs-dist standalone, 31 toolkits (up from 15+), 605+ tools |

---

## Why These Dependencies?

### Document Generation Stack

- **pdf-parse** (v2.0.550 only): Mozilla's PDF.js for complex PDF text extraction
- **pdfjs-dist**: Standalone Mozilla PDF.js for rendering PDFs to images (OCR pipeline)
- **pdfkit**: Create PDFs from scratch with vector graphics
- **TCPDF**: PHP fallback with complete international font support
- **dompdf**: HTML → PDF conversion

### Image & Video Processing

- **sharp**: High-performance image resizing, format conversion, background removal integration
- **remotion/bin/remotion-render.bundle.js**: Programmatic video generation (React-based)
- **canvas**: HTML5 canvas in Node.js for image manipulation and OCR preprocessing
- **gif-encoder**: GIF generation

### International Support

- 100 font files (TCPDF) for 100+ languages
- `cid0*.php` fonts for CJK (Chinese/Japanese/Korean)
- `i18next` for JavaScript internationalization

### Why So Large?

1. **International Support**: CJK fonts and character maps are inherently large
2. **Browser Compatibility**: Pre-bundled dependencies — zero configuration after install
3. **PHP Fallbacks**: Pure PHP alternatives when Node.js unavailable
4. **Complete Solution**: No external API calls for core document generation features
5. **Feature Breadth**: 31 toolkits covering healthcare, legal, real estate, social media, e-commerce, video, imaging

---

## Conclusion

### The ~39 MB ZIP Contains (After Optimizations):

**Top Contributors (Uncompressed in ZIP):**
1. **JavaScript Libraries**: ~48 MB — NPM packages (sharp, pdfjs-dist, ExcelJS, pdfkit, remotion, etc.)
2. **PHP Vendor**: ~51 MB — TCPDF, PHPOffice, dompdf, thecodingmachine/safe, boxpacker
3. **PHP Source Code**: ~16 MB — 605+ tools, 31 toolkits, admin, MCP Apps, Vault
4. **Fonts**: ~17 MB — TCPDF fonts for international PDF support
5. **Document Bundles**: ~10 MB — PDF/Word/Excel/Remotion (without source maps)

**What's Excluded (Not in ZIP):**
- ❌ JS/CSS source maps (17.4 MB) — Development only
- ❌ Facebook SDK (14 MB) — Not used
- ❌ Vendor tests/docs (~14 MB) — Development files
- ❌ Build artifacts (1.1 MB) — TMA template builds

### Pending Quick Win:

Adding one line to `.distignore` to exclude the Sharp Linux native binary (`assets/vendor/sharp/node_modules/@img/sharp-libvips-linux-x64/`) would reduce the ZIP by approximately **5 MB** (16 MB uncompressed → ~5 MB compressed).

---

## Quick Reference

```
Pro Plugin Distribution Size: ~39 MB
Source Directory (apparent): 176 MB
├── assets/vendor/: 66 MB → ~20 MB compressed (JS libraries)
│   ├── sharp/: 17 MB (⚠️ includes 16 MB Linux native binary)
│   ├── pdfjs-dist/: 7.0 MB
│   ├── exceljs/: 7.3 MB
│   ├── puppeteer-core+@puppeteer: 6.1 MB
│   ├── pdfkit/: 3.9 MB
│   ├── pdf-lib/: 3.9 MB
│   ├── @remotion+remotion: 3.3 MB
│   └── 40+ smaller packages
├── vendor/: 65 MB → ~16 MB compressed (PHP composer)
│   ├── tecnickcom/tcpdf: 29 MB
│   ├── phpoffice/*: 17 MB
│   ├── dompdf/: 13 MB
│   └── 10+ smaller packages
├── includes/: 16 MB → ~4 MB compressed (PHP source)
│   └── 605+ tools, 31 toolkits
├── bin/: 27 MB source → 10 MB in ZIP (no source maps)
│   └── generate-pdf + generate-word + generate-excel + remotion
├── docs/: 908 KB
├── node-services/: 68 KB (12 microservices)
└── scripts/: 60 KB

Excluded from ZIP (~47 MB uncompressed):
├── JS/CSS source maps: 17.4 MB
├── Facebook SDK: 14 MB
├── Vendor tests/docs: ~14 MB
└── build/ dir: 1.1 MB

Tools: 605+ (across 31 toolkits)
Node microservices: 12
```

**Last Updated**: April 15, 2026  
**Plugin Version**: 1.0.0 (file) / ~v1.2 equivalent (feature set)  
**Analysis Date**: Based on live addons/pro directory with .distignore applied  
**Source Size**: ~176 MB apparent → ~39 MB estimated distribution ZIP
