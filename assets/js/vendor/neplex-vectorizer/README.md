# @neplex/vectorizer Integration for Vectorize Image Tool

This directory contains the @neplex/vectorizer library and its platform-specific native binaries for the WP oOS `vectorize_image` tool.

## Files

### Main Library
- **vectorizer/** - Main @neplex/vectorizer package (v0.0.5)
  - `index.js` - Platform detection and binary loading logic
  - `index.d.ts` - TypeScript definitions
  - `package.json` - Package metadata
  - `LICENSE` - MIT License
  - `cli/index.mjs` - Command-line interface (not used by plugin)
  - `node_modules/commander/` - CLI dependency (not used by plugin)

### Platform-Specific Native Binaries
These are N-API native modules compiled for different operating systems and architectures. npm installs only the relevant binaries for the current platform.

- **vectorizer-linux-x64-gnu/** - Linux x64 with glibc (~2.3MB)
- **vectorizer-linux-x64-musl/** - Linux x64 with musl libc (~2.3MB)
- Additional binaries may be present depending on installation platform:
  - vectorizer-darwin-x64 - macOS Intel
  - vectorizer-darwin-arm64 - macOS Apple Silicon
  - vectorizer-win32-x64-msvc - Windows x64
  - vectorizer-win32-ia32-msvc - Windows 32-bit
  - vectorizer-win32-arm64-msvc - Windows ARM64
  - vectorizer-linux-arm64-gnu - Linux ARM64 (glibc)
  - vectorizer-linux-arm64-musl - Linux ARM64 (musl)
  - vectorizer-linux-arm-gnueabihf - Linux ARM (glibc)
  - vectorizer-android-arm64 - Android ARM64
  - vectorizer-android-arm-eabi - Android ARM
  - vectorizer-freebsd-x64 - FreeBSD x64

## Installation

The @neplex/vectorizer library is automatically installed and copied to this directory during `npm install` via the postinstall script.

### Automatic Installation (Recommended)
```bash
# Install all dependencies (including @neplex/vectorizer)
npm install

# Vectorizer will be automatically copied from node_modules to this directory
```

### Manual Installation
If needed, you can manually copy the files:
```bash
npm run install:vectorizer
```

This executes:
```bash
rm -rf assets/js/vendor/neplex-vectorizer && \
mkdir -p assets/js/vendor/neplex-vectorizer && \
cp -r node_modules/@neplex/vectorizer* assets/js/vendor/neplex-vectorizer/
```

## Usage

The vectorizer is automatically loaded by `bin/vectorize-image.js`:

1. **Production** (from vendor): Script loads from `assets/js/vendor/neplex-vectorizer/vectorizer`
2. **Development** (fallback): Script loads from `node_modules/@neplex/vectorizer`

### PHP Integration

The `vectorize_image` tool executes the vectorizer via Node.js subprocess:

```php
// From WP_MCP_AI_Tool_Vectorize_Image::execute()
$script_path = WP_MCP_AI_PATH . 'bin/vectorize-image.js';
$result = $this->execute_nodejs_script($script_path, $script_args);
```

### Node.js Script Usage

```bash
# Basic usage
node bin/vectorize-image.js input.png output.svg '{}'

# With options
node bin/vectorize-image.js input.png output.svg '{"colorMode":"binary","colorPrecision":6}'
```

## Why Vendor Directory?

Following the same pattern as Chart.js (`assets/js/vendor/chart.min.js`):

1. **Self-contained** - Plugin works immediately after installation without `npm install`
2. **Production ready** - No need for node_modules on production WordPress servers
3. **Version control** - Vendor files are tracked in git (node_modules is not)
4. **Build inclusion** - assets/ directory is automatically included in plugin ZIP files
5. **WordPress.org compatible** - No external dependencies required

## System Requirements

- **Node.js**: Version 14.0.0 or higher
- **Platform**: One of the supported OS/architecture combinations above
- **PHP**: 7.4 or higher (for WordPress plugin)

## Size Considerations

- Main package: ~292KB
- Each native binary: ~2.3MB
- Total (with 2 Linux binaries): ~4.8MB
- Only relevant platform binaries are used at runtime

## License

- **@neplex/vectorizer**: MIT License
- **WP oOS integration**: GPLv3 or later

## Troubleshooting

### Missing Dependency Error

**Error**: `Failed to load @neplex/vectorizer: Cannot find module`

**Solutions**:
1. Run `npm install` to install and copy the library
2. Manually run `npm run install:vectorizer`
3. Verify `assets/js/vendor/neplex-vectorizer/vectorizer/index.js` exists

### Unsupported Platform Error

**Error**: `Unsupported OS: [platform], architecture: [arch]`

**Cause**: Your system's OS/architecture combination is not supported by @neplex/vectorizer

**Solutions**:
1. Check [supported platforms](https://github.com/neplextech/vectorizer#supported-platforms)
2. Consider using alternative image processing methods

### Permission Errors

**Error**: Cannot write to vendor directory during `npm install`

**Solutions**:
```bash
# Fix directory permissions
chmod -R 755 assets/js/vendor/
npm install
```

## Resources

- [@neplex/vectorizer on npm](https://www.npmjs.com/package/@neplex/vectorizer)
- [@neplex/vectorizer on GitHub](https://github.com/neplextech/vectorizer)
- [Vectorize Image Tool Documentation](../../../../docs/reference/tools/vectorize-image.md)
- [Tool Implementation Summary](../../../../VECTORIZE_IMAGE_IMPLEMENTATION_SUMMARY.md)

## Version

- @neplex/vectorizer: v0.0.5
- Last Updated: 2026-01-01
