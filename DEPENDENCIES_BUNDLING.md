# Production Dependencies Bundling - Implementation Summary

## Problem Statement
The following production npm dependencies were required at runtime but were not bundled into the plugin, causing the plugin to fail when `node_modules/` was not present:

**Not Bundled (❌):**
- `@microsoft/fetch-event-source@^2.0.1`
- `@types/pdfkit@^0.17.4`  
- `docx@^9.5.1`
- `exceljs@^4.4.0`
- `pdfkit@^0.17.2`

**Already Bundled (✅):**
- `@neplex/vectorizer@^0.0.5` (copied to vendor)
- `chart.js@^4.4.1` (copied to vendor)
- `dompurify@^3.3.0` (bundled in browser JS)
- `ky@^1.14.0` (bundled in browser JS)
- `marked@^17.0.0` (bundled in browser JS)

## Solution Overview

We followed the existing patterns in the codebase for bundling dependencies, implementing three distinct strategies based on use case:

### Strategy 1: Pre-Built UMD Copy (Chart.js pattern)
**Used for:** Pre-built browser libraries with no dependencies

**Example:** Chart.js
```bash
# package.json postinstall script
cp node_modules/chart.js/dist/chart.umd.min.js assets/js/vendor/chart.min.js
```

**Characteristics:**
- Pre-minified UMD bundle (~209KB)
- No build step required
- Directly enqueued in WordPress

### Strategy 2: Full Package Copy with Native Bindings (Vectorizer pattern)
**Used for:** Node.js packages with native binaries

**Example:** @neplex/vectorizer
```bash
# package.json postinstall script
rm -rf assets/js/vendor/neplex-vectorizer
mkdir -p assets/js/vendor/neplex-vectorizer
cp -r node_modules/@neplex/vectorizer* assets/js/vendor/neplex-vectorizer/
```

**Characteristics:**
- Entire package copied including `.node` native binaries
- Standalone script uses fallback: vendor path → node_modules
- Used by `bin/vectorize-image.js`

### Strategy 3: esbuild Bundling for Browser (New - Browser ES6 imports)
**Used for:** Browser JavaScript with ES6 imports

**Example:** @microsoft/fetch-event-source, marked, dompurify, ky
```javascript
// assets/js/sse-service.js
import { fetchEventSource } from '@microsoft/fetch-event-source';

// assets/js/chat-markdown-service.js
import { marked } from 'marked';
import DOMPurify from 'dompurify';

// assets/js/chat-http-client-service.js
import ky from 'ky';
```

**Build Process:**
```bash
# esbuild.config.js (bundled option)
{
  bundle: true,
  format: 'iife',
  platform: 'browser',
  entryPoints: ['assets/js/chat-bundle.js'],
  outfile: 'assets/js/chat-bundle.min.js'
}
```

**Result:** `chat-bundle.min.js` (350KB) - includes all dependencies

### Strategy 4: esbuild Bundling for Node.js (NEW - Document generation)
**Used for:** Node.js packages used server-side (pdfkit, docx, exceljs)

**Implementation:**

#### 1. Created Standalone Scripts
```
addons/pro/scripts/
├── generate-pdf.js      (uses pdfkit)
├── generate-word.js     (uses docx)
└── generate-excel.js    (uses exceljs)
```

#### 2. Created esbuild Configuration for Node.js
```javascript
// esbuild.config.pro.js
const nodeScriptOptions = {
  bundle: true,
  platform: 'node',  // Target Node.js, not browser
  target: 'node14',
  format: 'cjs',     // CommonJS for Node.js
  external: ['fs', 'path'], // Don't bundle Node.js built-ins
  minify: false,     // Keep readable for debugging
  sourcemap: true
};
```

#### 3. Build Output
```
addons/pro/bin/
├── generate-pdf.bundle.js      (2.5MB - includes pdfkit)
├── generate-word.bundle.js     (837KB - includes docx)
├── generate-excel.bundle.js    (2.3MB - includes exceljs)
└── data/                       (PDFKit font files)
    ├── Helvetica.afm
    ├── Times-Roman.afm
    └── ... (14 font files total)
```

#### 4. Updated PHP Tools
Changed from dynamic script creation to using pre-bundled scripts:

**Before:**
```php
protected function generate_pdf_document($data) {
    // Create Node.js script on the fly
    $script = $this->create_pdf_generation_script();
    $script_file = $temp_file . '.js';
    file_put_contents($script_file, $script);
    
    // Execute
    exec("node $script_file $json_file $output_file");
    
    // Cleanup
    unlink($script_file);
}
```

**After:**
```php
protected function generate_pdf_document($data) {
    // Use pre-bundled script
    $script_file = $this->get_pdf_generation_script_path();
    // Returns: WP_MCP_AI_PRO_PATH . 'bin/generate-pdf.bundle.js'
    
    if (is_wp_error($script_file)) {
        return $script_file; // Script not found error
    }
    
    // Execute (no temp file creation needed)
    exec("node $script_file $json_file $output_file");
}
```

## Build Process

### Development Workflow
```bash
# 1. Install dependencies
npm install

# Automatically runs postinstall:
# - Copies chart.js to assets/js/vendor/
# - Copies vectorizer to assets/js/vendor/neplex-vectorizer/
# - Bundles Pro addon scripts: npm run build:js:pro

# 2. Build all assets
npm run build
# Runs: build:css && build:js && build:js:pro

# Or build individually:
npm run build:js      # Browser JS (chat-bundle.min.js)
npm run build:js:pro  # Pro addon Node.js scripts
```

### Distribution Build
```bash
# Build plugin ZIP
./bin/build-plugin-zip.sh --pro

# Creates: build/mcp-ai-wpoos-pro-X.Y.Z.zip
# Includes:
# - addons/pro/bin/*.bundle.js (bundled scripts)
# - addons/pro/bin/data/* (PDFKit fonts)
# - assets/js/vendor/chart.min.js
# - assets/js/vendor/neplex-vectorizer/
# - assets/js/chat-bundle.min.js (with embedded deps)
#
# Excludes:
# - node_modules/ (via .distignore)
# - *.map files
# - tests/
```

## Files Modified

### New Files
- `addons/pro/scripts/generate-pdf.js` - Standalone PDF generator
- `addons/pro/scripts/generate-word.js` - Standalone Word generator
- `addons/pro/scripts/generate-excel.js` - Standalone Excel generator
- `esbuild.config.pro.js` - Node.js bundling configuration
- `addons/pro/bin/*.bundle.js` - Bundled scripts (generated)
- `addons/pro/bin/data/*` - PDFKit font files (copied)

### Modified Files
- `package.json` - Added `build:js:pro` script, updated postinstall
- `addons/pro/includes/tools/document-generation/class-wp-mcp-ai-tool-pro-pdf.php`
- `addons/pro/includes/tools/document-generation/class-wp-mcp-ai-tool-pro-word.php`
- `addons/pro/includes/tools/document-generation/class-wp-mcp-ai-tool-pro-excel.php`

## Testing

All document generation tools tested successfully without `node_modules/`:

### PDF Generation
```bash
echo '{"title":"Test","content":"Test PDF"}' > test.json
node addons/pro/bin/generate-pdf.bundle.js test.json output.pdf
# ✅ Output: PDF generated successfully
```

### Word Generation
```bash
echo '{"title":"Test","sections":[{"heading":"Intro","content":"Test"}]}' > test.json
node addons/pro/bin/generate-word.bundle.js test.json output.docx
# ✅ Output: Word document generated successfully  
```

### Excel Generation
```bash
echo '{"data":[["Name","Age"],["John",30]]}' > test.json
node addons/pro/bin/generate-excel.bundle.js test.json output.xlsx
# ✅ Output: Excel document generated successfully
```

## Bundle Sizes

| Component | Method | Size | Notes |
|-----------|--------|------|-------|
| chart.js | Pre-built copy | 209 KB | UMD bundle |
| vectorizer | Package copy | ~2.5 MB | Includes .node binaries |
| chat-bundle | esbuild (browser) | 350 KB | 4 deps bundled |
| generate-pdf.bundle | esbuild (node) | 2.5 MB | Includes pdfkit + fonts |
| generate-word.bundle | esbuild (node) | 837 KB | Includes docx |
| generate-excel.bundle | esbuild (node) | 2.3 MB | Includes exceljs |
| **Total Pro addon** | | **~6 MB** | All Node.js bundles |

## Summary

✅ **All production dependencies are now bundled into the plugin**
✅ **No `node_modules/` required in production**
✅ **Follows established patterns in codebase**
✅ **Tested and working**
✅ **Build process is automated**

The solution uses four different strategies optimized for each use case:
1. Pre-built UMD copy (Chart.js)
2. Full package copy with native binaries (Vectorizer)
3. esbuild browser bundling (SSE, marked, dompurify, ky)
4. esbuild Node.js bundling (pdfkit, docx, exceljs) ← NEW

All dependencies are self-contained within the plugin distribution.
