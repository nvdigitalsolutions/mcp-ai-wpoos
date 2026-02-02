# Dependencies Bundling Guide

This document explains how NPM dependencies are managed and bundled in the Open Operator System plugin.

## Overview

The plugin uses a two-tier dependency management system:

1. **Base Plugin**: Manages its own dependencies via `package.json`
2. **Pro Addon**: Manages Pro-specific dependencies via `addons/pro/package.json`

## Base Plugin Dependencies

Located in root `package.json`:

```json
{
  "dependencies": {
    "@langchain/community": "^0.3.14",           // LangChain community integrations
    "@langchain/core": "^0.3.20",                // LangChain core functionality
    "@microsoft/fetch-event-source": "^2.0.1",   // SSE for streaming
    "@mlc-ai/web-llm": "^0.2.80",                // Browser-native LLM support
    "@neplex/vectorizer": "^0.0.5",              // Vector embeddings
    "chart.js": "^4.4.7",                        // Charts in admin
    "dompurify": "^3.3.0",                       // HTML sanitization
    "ky": "^1.14.0",                             // HTTP client
    "langchain": "^0.3.6",                       // LangChain framework
    "marked": "^9.1.6"                           // Markdown parsing
  }
}
```

### Base Plugin Bundling

- **Bundled into `chat-bundle.min.js`**: `@microsoft/fetch-event-source`, `dompurify`, `marked`, `ky`
- **Copied to `assets/js/vendor/`**: `chart.js`, `@neplex/vectorizer`

Build commands:
```bash
npm run build:js        # Bundles chat dependencies
npm run install:chartjs # Copies chart.js
npm run install:vectorizer # Copies vectorizer
npm run build:js:pro    # Bundles Pro addon orchestration & research packages
```

## Pro Addon Dependencies

Located in `addons/pro/package.json`:

```json
{
  "dependencies": {
    "@turf/turf": "^7.3.2",                       // Geospatial analysis
    "@types/pdfkit": "^0.17.4",                   // TypeScript types
    "@woocommerce/woocommerce-rest-api": "^1.0.1", // E-commerce API
    "axios": "^1.6.5",                            // HTTP client
    "chart.js": "^4.4.7",                         // Charts (also in base)
    "cheerio": "^1.0.0",                          // HTML parsing for research tools
    "csv-parse": "^5.6.0",                        // CSV parsing
    "csv-stringify": "^6.5.2",                    // CSV formatting
    "currency.js": "^2.0.4",                      // Currency formatting
    "d3": "^7.8.5",                               // Data visualization
    "docx": "^9.5.1",                             // Word document generation
    "email-validator": "^2.0.4",                  // Email validation
    "exceljs": "^4.4.0",                          // Excel generation
    "facebook-nodejs-business-sdk": "^24.0.1",    // Facebook API
    "fast-csv": "^5.0.0",                         // CSV processing
    "ffmpeg-static": "^5.2.0",                    // Video processing
    "ffprobe-static": "^3.1.0",                   // Video metadata
    "fluent-ffmpeg": "^2.1.3",                    // Video processing wrapper
    "franc": "^6.1.0",                            // Language detection
    "gif-encoder": "^0.7.2",                      // GIF creation
    "google-translate-api-x": "^10.7.0",          // Translation
    "i18next": "^23.7.0",                         // i18n framework
    "ical-generator": "^8.0.1",                   // Calendar generation
    "ics": "^3.8.1",                              // Calendar export
    "iso-639-1": "^3.1.0",                        // Language codes
    "katex": "^0.16.11",                          // Math rendering
    "libphonenumber-js": "^1.11.21",              // Phone number validation
    "linkedin-api-client": "^0.3.0",              // LinkedIn API
    "mailparser": "^3.7.1",                       // Email parsing
    "mathjs": "^12.3.0",                          // Math library
    "mjml": "^5.0.0-alpha.10",                    // Email templates
    "nodemailer": "^7.0.12",                      // Email sending
    "p-queue": "^8.0.1",                          // Promise queue for rate limiting
    "pdfkit": "^0.17.2",                          // PDF generation
    "prettier": "^3.4.2",                         // Code formatting
    "qrcode": "^1.5.4",                           // QR code generation
    "regression": "^2.0.1",                       // Statistical regression
    "sharp": "^0.33.5",                           // Image processing
    "stripe": "^14.0.0",                          // Payment processing
    "subtitle": "^3.0.0",                         // Subtitle files
    "turndown": "^7.2.0",                         // HTML to Markdown conversion
    "twitter-api-v2": "^1.15.2",                  // Twitter API
    "validator": "^13.12.0",                      // Data validation
    "video-stitch": "^1.7.1"                      // Video stitching
  }
}
```

### Pro Addon Bundling

Pro addon uses a **vendor directory pattern** for production distribution:

1. **Development**: Dependencies installed in `addons/pro/node_modules/`
2. **Production**: Dependencies copied to `addons/pro/assets/vendor/` during build
3. **Services/Tools**: Check vendor directory first, then node_modules (fallback for dev)

#### Bundling Process

**Automatic (via postinstall)**:
```bash
cd addons/pro
npm install  # Automatically runs copy-dependencies.js
```

**Manual**:
```bash
cd addons/pro
node scripts/copy-dependencies.js
```

#### What Gets Copied

All 46 Pro addon packages are automatically copied from `node_modules` to `assets/vendor` during the postinstall hook.

**Core Toolkits:**
| Package | Size | Files Copied |
|---------|------|-------------|
| @turf/turf | 53.2 KB | dist/ (cjs & esm) |
| katex | 2.8 MB | dist/ (fonts, CSS, JS) |
| ics | 6.0 KB | dist/index.js |
| sharp | 279.8 KB | lib/ |
| prettier | 99.3 KB | standalone.js, parsers |
| mjml | 1.8 KB | lib/ |
| fluent-ffmpeg | 111.4 KB | index.js, lib/ |
| ffmpeg-static | 2.4 KB | index.js |
| ffprobe-static | 1016 B | index.js |

**CRM & Email Marketing Toolkit:**
| Package | Size | Files Copied |
|---------|------|-------------|
| nodemailer | 454.7 KB | lib/ |
| validator | 466.0 KB | index.js, lib/, es/ |
| email-validator | 2.0 KB | index.js |
| libphonenumber-js | 523.9 KB | index.js, min/, mobile/, metadata |
| mailparser | 45.7 KB | lib/ |
| csv-parse | 78.1 KB | lib/ |
| csv-stringify | 35.6 KB | lib/ |
| ical-generator | 549.3 KB | dist/ |

**E-commerce Toolkit:**
| Package | Size | Files Copied |
|---------|------|-------------|
| @woocommerce/woocommerce-rest-api | 17.0 KB | index.js, index.mjs |
| stripe | 422.0 KB | cjs/, esm/ |
| currency.js | 5.4 KB | dist/currency.min.js |

**Social Media Toolkit:**
| Package | Size | Files Copied |
|---------|------|-------------|
| twitter-api-v2 | 979.6 KB | dist/ |
| axios | 1.8 MB | dist/, index.js |
| facebook-nodejs-business-sdk | 27.4 MB | dist/ |
| linkedin-api-client | 145.3 KB | dist/ |

**Analytics Toolkit:**
| Package | Size | Files Copied |
|---------|------|-------------|
| d3 | 848.6 KB | dist/ |
| mathjs | 8.9 MB | lib/ |
| regression | 8.3 KB | dist/regression.min.js |
| fast-csv | 4.7 KB | build/ |

**Multilingual Toolkit:**
| Package | Size | Files Copied |
|---------|------|-------------|
| i18next | 404.9 KB | dist/ |
| franc | 9.8 KB | index.js |
| google-translate-api-x | 23.3 KB | index.cjs, lib/ |
| iso-639-1 | 10.0 KB | build/index.js |

**Video Production Toolkit:**
| Package | Size | Files Copied |
|---------|------|-------------|
| gif-encoder | 42.3 KB | lib/ |
| video-stitch | 8.6 KB | index.js, lib/ |
| subtitle | 43.3 KB | dist/ |

**Document & Data Processing Toolkit:**
| Package | Size | Files Copied |
|---------|------|-------------|
| docx | ~700 KB | build/ |
| exceljs | ~1.2 MB | dist/, lib/ |
| pdfkit | ~250 KB | js/ |
| qrcode | ~45 KB | lib/ |

**Total**: ~49 MB (46 packages, including 3 browser-bundled packages: cheerio, p-queue, turndown)

#### Special Cases

**Document Generation (PDF, Word, Excel)**:
- Bundled into standalone scripts via esbuild
- Located in `addons/pro/bin/`
- `generate-pdf.bundle.js`, `generate-word.bundle.js`, `generate-excel.bundle.js`

**Orchestration & Research (Browser Bundles)**:
- **Orchestration Bundle** (`addons/pro/assets/js/orchestration-bundle.min.js`, 17KB):
  - `p-queue`: Promise queue with concurrency control for rate limiting (installed in Pro addon)
  - Custom browser-compatible circuit breaker (inspired by opossum pattern)
  - Used by: Autonomous orchestration tools, task execution management
- **Research Bundle** (`addons/pro/assets/js/research-bundle.min.js`, 360KB):
  - `cheerio`: Fast HTML parsing and data extraction (installed in Pro addon)
  - `turndown`: HTML to Markdown conversion (installed in Pro addon)
  - Used by: Research compiler, web scraping tools, content aggregation
- Built via: `esbuild.config.pro.js` using packages from Pro addon's `node_modules/`
- Build command: `npm run build:js:pro` (runs automatically on `npm install`)
- Note: esbuild resolves these packages from `addons/pro/node_modules/` using the `nodePaths` configuration

**Chart.js**:
- Duplicated in base and Pro for different contexts
- Base: Admin dashboard charts
- Pro: Health analytics charts

## PHP Dependencies (Composer)

Located in root `composer.json`:

```json
{
  "require": {
    "rahul900day/tiktoken-php": "^1.0",
    "symfony/http-client": "^6.1|^7.0",
    "nyholm/psr7": "^1.8",
    "symfony/validator": "^6.4|^7.0",
    "symfony/cache": "^6.4|^7.0",
    "symfony/filesystem": "^6.4|^7.0",
    "symfony/process": "^6.4|^7.0",
    "league/oauth2-client": "^2.7"
  }
}
```

### Installation and Distribution

PHP dependencies are:
- **Installed via**: `composer install --no-dev --prefer-dist --classmap-authoritative`
- **Located in**: `vendor/` directory at plugin root
- **Included in distribution**: YES - The `vendor/` directory is automatically included when building plugin ZIPs
- **Used for**: Validation (Symfony Validator), HTTP clients, caching, file operations, etc.

### Critical: Symfony Validator Dependency

**Symfony Validator is REQUIRED for validated tools to function.** The plugin includes defensive checks to gracefully handle missing dependencies:

1. If `vendor/autoload.php` is missing → Plugin still loads, but validated tools are skipped
2. If Symfony Validator class is not available → Validated tools return a helpful error message
3. Non-validated versions of tools continue to work normally

**For Production Deployments:**
- The `vendor/` directory MUST be included in your deployment
- Build the plugin using `./bin/build-plugin-zip.sh` which automatically includes vendor
- Do NOT manually copy files - use the build script to ensure all dependencies are included
- The `.distignore` file does NOT exclude `vendor/` - only `vendor-dev/` is excluded

**Checking Vendor Availability:**
```bash
# Verify vendor directory exists in your deployment
ls -la /path/to/wordpress/wp-content/plugins/mcp-ai-wpoos/vendor/

# Verify Symfony Validator is present
ls -la /path/to/wordpress/wp-content/plugins/mcp-ai-wpoos/vendor/symfony/validator/
```

## Git Strategy

### Base Plugin
- `node_modules/` excluded
- `assets/js/vendor/` included

### Pro Addon
- `addons/pro/node_modules/` excluded
- `addons/pro/assets/vendor/` included

## Build Process

### Development Setup
```bash
# Install all dependencies
npm install
cd addons/pro && npm install

# Build all assets
npm run build
```

### Production Build
```bash
# Run plugin build script
./bin/build-plugin-zip.sh --combined

# This creates a distribution with:
# - Base plugin with its dependencies
# - Pro addon with vendor directory
# - No node_modules directories
```

## Service/Tool Pattern

All Pro services and tools follow this pattern for package availability checks:

```php
public function is_available() {
    // Check vendor directory first (production)
    $vendor_path = WP_MCP_AI_PRO_PATH . 'assets/vendor/package/file.js';
    
    // Fallback to node_modules (development)
    $node_modules_path = WP_MCP_AI_PRO_PATH . 'node_modules/package/file.js';
    
    if ( ! file_exists( $vendor_path ) && ! file_exists( $node_modules_path ) ) {
        return false;
    }
    
    return true;
}
```

## Troubleshooting

### Fatal Error: Symfony\Component\Validator\Validation Not Found

**Symptoms:**
```
Fatal error: Class "Symfony\Component\Validator\Validation" not found
```

**Cause:** The `vendor/` directory is missing from your plugin installation.

**Solution:**
1. **If deploying from source:** Use the build script to create a proper distribution:
   ```bash
   ./bin/build-plugin-zip.sh --combined
   # Upload build/mcp-ai-wpoos-X.Y.Z.zip to your site
   ```

2. **If already deployed:** Manually install production dependencies on the server:
   ```bash
   cd /path/to/wordpress/wp-content/plugins/mcp-ai-wpoos
   composer install --no-dev --prefer-dist --classmap-authoritative
   ```

3. **If using Git deployment:** Make sure `vendor/` is NOT in `.gitignore` for production branches.

**Prevention:** 
- Always use `./bin/build-plugin-zip.sh` to create distributions
- The build script automatically runs `composer install --no-dev` and includes vendor
- Do NOT use `.distignore` patterns that would exclude the `vendor/` directory

### Package Not Found in Production

1. Check if package is in `addons/pro/package.json`
2. Run `cd addons/pro && npm install` to trigger copy
3. Verify file exists in `addons/pro/assets/vendor/`
4. Check service/tool uses correct vendor path

### Development vs Production Mismatch

Services/tools check both paths automatically:
- Production: `assets/vendor/`
- Development: `node_modules/`

### Build Fails

```bash
# Clean install
rm -rf node_modules addons/pro/node_modules
npm install
cd addons/pro && npm install

# Rebuild
npm run build
```

## Reference

- **Copy Script**: `addons/pro/scripts/copy-dependencies.js`
- **Pro Settings**: `includes/admin/class-wp-mcp-ai-pro-settings.php`
- **Build Config**: `esbuild.config.pro.js`
- **Package Files**: `package.json`, `addons/pro/package.json`
