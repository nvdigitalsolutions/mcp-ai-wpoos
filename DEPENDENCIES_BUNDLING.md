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
    "@microsoft/fetch-event-source": "^2.0.1",  // SSE for streaming
    "@neplex/vectorizer": "^0.0.5",              // Vector embeddings
    "chart.js": "^4.4.7",                        // Charts in admin
    "dompurify": "^3.3.0",                       // HTML sanitization
    "ky": "^1.14.0",                             // HTTP client
    "marked": "^17.0.0"                          // Markdown parsing
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
```

## Pro Addon Dependencies

Located in `addons/pro/package.json`:

```json
{
  "dependencies": {
    "@turf/turf": "^7.3.2",        // Geospatial analysis
    "@types/pdfkit": "^0.17.4",    // TypeScript types
    "chart.js": "^4.4.7",          // Charts (also in base)
    "docx": "^9.5.1",              // Word document generation
    "exceljs": "^4.4.0",           // Excel generation
    "fluent-ffmpeg": "^2.1.3",     // Video processing
    "ics": "^3.8.1",               // Calendar export
    "katex": "^0.16.11",           // Math rendering
    "mjml": "^4.18.0",             // Email templates
    "pdfkit": "^0.17.2",           // PDF generation
    "prettier": "^3.4.2",          // Code formatting
    "sharp": "^0.33.5"             // Image processing
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

| Package | Size | Files Copied |
|---------|------|-------------|
| @turf/turf | 53.2 KB | dist/ (cjs & esm) |
| katex | 2.8 MB | dist/ (fonts, CSS, JS) |
| ics | 6.0 KB | dist/index.js |
| sharp | 279.8 KB | lib/ |
| prettier | 99.3 KB | standalone.js, parsers |
| mjml | 1.8 KB | lib/ |
| fluent-ffmpeg | 111.4 KB | index.js, lib/ |

**Total**: 3.4 MB

#### Special Cases

**Document Generation (PDF, Word, Excel)**:
- Bundled into standalone scripts via esbuild
- Located in `addons/pro/bin/`
- `generate-pdf.bundle.js`, `generate-word.bundle.js`, `generate-excel.bundle.js`

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
    "symfony/process": "^6.4|^7.0"
  }
}
```

PHP dependencies are:
- Installed via `composer install`
- Located in `vendor/` (included in distribution)
- Used for validation, HTTP clients, caching, etc.

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
