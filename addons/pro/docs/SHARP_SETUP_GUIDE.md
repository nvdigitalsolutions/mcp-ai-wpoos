# Sharp Image Processing Setup Guide

## Overview

The `optimize_image_sharp` tool uses [Sharp](https://sharp.pixelplumbing.com/) - a high-performance Node.js image processing library built on libvips. Sharp provides:

- Fast image resizing and optimization
- Modern format conversion (WebP, AVIF)
- Advanced image operations (blur, sharpen, rotate)
- Lossless compression
- Batch processing capabilities

## Requirements

- **Node.js**: 18.17.0 or higher (18.17.0+, 20.x, 21.x, 22.x+)
- **Platform**: Pre-packaged for Linux x64 (glibc). Other platforms require manual installation.

### Important Version Notes

Sharp 0.33.5 requires **Node.js 18.17.0 or higher**. If you have Node.js 18.0.0-18.16.x, you must upgrade to at least 18.17.0 for Sharp to work correctly.

**Check Your Node.js Version:**
```bash
node --version
```

If the version is below v18.17.0, please upgrade:
- Visit [https://nodejs.org/](https://nodejs.org/) for the latest LTS version
- Or use a version manager like nvm: `nvm install 18.17.0`

## Why Sharp Requires Special Setup

Unlike pure JavaScript packages, Sharp requires:

1. **Node.js runtime** - To execute Sharp's JavaScript code
2. **Platform-specific native binaries** - Compiled C++ code with libvips
3. **JavaScript dependencies** - detect-libc, color, semver packages

The native binaries are platform-specific (Linux x64, macOS ARM64, Windows x64, etc.) and must be compiled or downloaded for your server's operating system and architecture.

## Installation Methods

### Method 1: For Cloned Repository (Development/Staging)

If you cloned the repository from GitHub:

```bash
# Navigate to Pro addon directory
cd wp-content/plugins/mcp-ai-wpoos/addons/pro

# Install Sharp with all platform binaries
npm install --include=optional

# Copy dependencies to vendor directory for distribution
npm run build
```

**What this does:**
- Installs Sharp package in `node_modules/sharp/`
- Downloads platform-specific binaries for your system
- Installs Sharp's dependencies (detect-libc, color, semver)
- Copies everything to `assets/vendor/sharp/` for distribution

### Method 2: For Production Servers (Minimal Install)

If you're deploying to a production server where you don't need all platforms:

```bash
cd wp-content/plugins/mcp-ai-wpoos/addons/pro

# Install only for current platform
npm install sharp --include=optional

# Copy to vendor directory
npm run build
```

This installs only the binaries for your current platform, reducing disk space usage.

### Method 3: Manual Binary Installation

If npm install fails or you need specific platform binaries:

1. **Download pre-built binaries** from Sharp's release page:
   - Visit: https://github.com/lovell/sharp/releases
   - Download the appropriate `@img/sharp-{platform}-{arch}` package
   - Download the matching `@img/sharp-libvips-{platform}-{arch}` package

2. **Extract to vendor directory:**
   ```bash
   mkdir -p assets/vendor/sharp/node_modules/@img
   unzip sharp-linux-x64.zip -d assets/vendor/sharp/node_modules/@img/sharp-linux-x64
   unzip sharp-libvips-linux-x64.zip -d assets/vendor/sharp/node_modules/@img/sharp-libvips-linux-x64
   ```

3. **Copy dependencies manually:**
   ```bash
   # You'll need detect-libc, color, and semver from npm
   npm install detect-libc color semver
   cp -r node_modules/detect-libc assets/vendor/sharp/node_modules/
   cp -r node_modules/color assets/vendor/sharp/node_modules/
   cp -r node_modules/semver assets/vendor/sharp/node_modules/
   ```

## Platform Requirements

### Linux Servers
- **Glibc-based** (Ubuntu, Debian, CentOS, RHEL): Use `sharp-linux-x64` and `sharp-libvips-linux-x64`
- **Musl-based** (Alpine Linux): Use `sharp-linuxmusl-x64` and `sharp-libvips-linuxmusl-x64`
- **Architecture**: Most servers are x64 (amd64), ARM servers need `sharp-linux-arm64`

**Check your Linux type:**
```bash
# Check architecture
uname -m  # Should show x86_64 for x64, aarch64 for ARM64

# Check C library (glibc vs musl)
ldd --version  # If it shows "musl", use linuxmusl packages
```

### macOS Servers
- **Apple Silicon (M1/M2/M3)**: Use `sharp-darwin-arm64`
- **Intel Macs**: Use `sharp-darwin-x64`

### Windows Servers
- **64-bit Windows**: Use `sharp-win32-x64`
- **32-bit Windows**: Use `sharp-win32-ia32` (rare)

## Verification

### 1. Check Files Exist

Verify Sharp and dependencies are in place:

```bash
cd wp-content/plugins/mcp-ai-wpoos/addons/pro

# Check Sharp library
ls -la assets/vendor/sharp/lib/index.js

# Check dependencies
ls -la assets/vendor/sharp/node_modules/detect-libc
ls -la assets/vendor/sharp/node_modules/color
ls -la assets/vendor/sharp/node_modules/semver

# Check platform binaries (example for Linux x64)
ls -la assets/vendor/sharp/node_modules/@img/sharp-linux-x64
ls -la assets/vendor/sharp/node_modules/@img/sharp-libvips-linux-x64
```

### 2. Test Sharp Loading

Test if Sharp can load in Node.js:

```bash
cd wp-content/plugins/mcp-ai-wpoos/addons/pro

# Test loading Sharp
node -e "const sharp = require('./assets/vendor/sharp/lib/index.js'); console.log('Sharp version:', sharp.versions); process.exit(0);"
```

**Expected output:**
```
Sharp version: {
  vips: '8.15.3',
  sharp: '0.33.5'
}
```

**If you see errors:**
- `Cannot find module 'detect-libc'` → Dependencies missing
- `Something went wrong installing the "sharp" module` → Platform binaries missing
- `MODULE_NOT_FOUND: @img/sharp-linux-x64` → Wrong platform binary

### 3. Test in WordPress

After installation, test the tool from WordPress admin:

1. Go to **Settings → NV oOS → Tools Manager**
2. Enable **Media Toolkit** if not already enabled
3. Try using the `optimize_image_sharp` tool on an image

## Troubleshooting

### Error: "Sharp is not fully installed"

This means the availability check failed. Check:

1. **Node.js installed?**
   ```bash
   node --version  # Should be v18.0.0 or higher
   ```

2. **Sharp library present?**
   ```bash
   ls wp-content/plugins/mcp-ai-wpoos/addons/pro/assets/vendor/sharp/lib/index.js
   ```

3. **Dependencies present?**
   ```bash
   ls -d wp-content/plugins/mcp-ai-wpoos/addons/pro/assets/vendor/sharp/node_modules/*
   ```

4. **Platform binaries present?**
   ```bash
   ls -d wp-content/plugins/mcp-ai-wpoos/addons/pro/assets/vendor/sharp/node_modules/@img/*
   ```

### Error: "Cannot find module 'detect-libc'"

Sharp's dependencies are missing. Run:

```bash
cd wp-content/plugins/mcp-ai-wpoos/addons/pro
npm install detect-libc color semver
npm run build
```

### Error: "Something went wrong installing Sharp"

Platform binaries are missing or incompatible. Try:

```bash
cd wp-content/plugins/mcp-ai-wpoos/addons/pro

# Force reinstall Sharp with platform binaries
rm -rf node_modules/sharp node_modules/@img
npm install sharp --include=optional
npm run build
```

### Error: "Unsupported architecture"

Your server architecture isn't supported by Sharp. Supported platforms:
- Linux x64 (most common)
- Linux ARM64 (Raspberry Pi, AWS Graviton)
- macOS ARM64 (Apple Silicon)
- macOS x64 (Intel Macs)
- Windows x64

If you're on an unsupported platform, Sharp won't work. Consider using alternative image processing tools.

### Performance Issues

Sharp is fast, but large images can still take time:

- **Increase PHP timeout** for large images:
  ```php
  // wp-config.php
  set_time_limit(300); // 5 minutes
  ```

- **Increase Node.js memory** if processing very large images:
  ```bash
  export NODE_OPTIONS="--max-old-space-size=4096"
  ```

## Advanced Configuration

### Using Sharp from node_modules

If you prefer to use Sharp directly from `node_modules/` instead of `assets/vendor/`:

1. Keep `node_modules/` directory with Sharp installed
2. The tool will automatically detect and use it
3. No need to run `npm run build`

This is useful for development environments where you frequently update Sharp.

### Custom Sharp Service Implementation

The `optimize_image_sharp` tool uses a filter-based approach. You can implement your own Sharp processing:

```php
add_filter( 'wp_mcp_ai_sharp_process_image', 'my_sharp_implementation', 10, 2 );

function my_sharp_implementation( $result, $params ) {
    if ( false !== $result ) {
        return $result; // Already handled
    }
    
    // Your custom Sharp processing implementation
    // Use exec() to call a Node.js script with Sharp
    // See addons/pro/docs/NPM_INTEGRATION_GUIDE.md for examples
    
    return $processed_result;
}
```

## Distribution / Packaging

When building the Pro addon for distribution:

### Default Build (Linux x64 only - Recommended)
```bash
cd addons/pro
npm run build  # Includes Linux x64 binaries (~16.5 MB)
```

**Size impact**: +16.5 MB (~9.5% increase)
- Sharp library: 324 KB
- Dependencies (detect-libc, color, semver): 148 KB
- Linux x64 binaries: ~16 MB

**Coverage**: Works immediately on most production servers (Linux x64)
**Other platforms**: Users run `npm install sharp --include=optional` to get their platform binaries

### Complete Build (All platforms - For Enterprise)
```bash
cd addons/pro
WP_MCP_AI_SHARP_ALL_PLATFORMS=true npm run build
# Or
WP_MCP_AI_BUILD_OFFLINE=true npm run build  # Includes Sharp + CDN packages
```

**Size impact**: +68.5 MB (~39.6% increase)
- Includes binaries for: Linux x64, macOS ARM64, macOS x64, Windows x64
- Platform binary sizes:
  - Linux x64: 16.3 MB (sharp + libvips)
  - macOS ARM64: 15.6 MB (sharp + libvips)
  - macOS x64: 17.4 MB (sharp + libvips)
  - Windows x64: 19.0 MB (sharp + libvips)

**Coverage**: Works immediately on all common platforms
**Best for**: Enterprise deployments, multiple server types

### Minimal Build (Dependencies only)
```bash
cd addons/pro
# Manually edit copy-dependencies.js to skip platform binaries
npm run build
```

**Size impact**: +148 KB (negligible)
**Coverage**: None - all users must run `npm install`
**Best for**: Development/testing only

### Build Size Comparison

| Build Type | Pro Addon Size | Size Increase | Platforms Covered |
|-----------|---------------|---------------|-------------------|
| Current (without Sharp deps) | 173 MB | Baseline | None |
| + Linux x64 only (default) | 189.5 MB | +16.5 MB (+9.5%) | Linux x64 |
| + All platforms | 241.5 MB | +68.5 MB (+39.6%) | Linux x64, macOS, Windows |

### Recommendation

**For most users**: Use default build (Linux x64 only)
- Most WordPress sites run on Linux x64 servers
- Reasonable size increase
- Users on other platforms can easily install binaries

**For enterprise customers**: Use complete build (all platforms)
- No additional setup required
- Works immediately everywhere
- Suitable for multi-platform environments

## Alternative Solutions

If Sharp installation is too complex for your environment:

1. **Use WordPress built-in image processing:**
   - The Pro addon has other image tools that use PHP's GD or ImageMagick
   - See `enhance_image_quality` and other image production tools

2. **Use external image processing services:**
   - Many CDNs offer image optimization (Cloudflare, Cloudinary, etc.)
   - Can be integrated via custom filters

3. **Use Sharp via external service:**
   - Set up Sharp on a separate server
   - Call it via HTTP API
   - Implement via `wp_mcp_ai_sharp_process_image` filter

## Support

For questions or issues:

1. Check WordPress error logs: `wp-content/debug.log`
2. Check Node.js error output when testing
3. Review Sharp's documentation: https://sharp.pixelplumbing.com/
4. Open an issue on GitHub: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues

---

**Last Updated:** February 18, 2026  
**Sharp Version:** 0.33.5  
**Libvips Version:** 8.15.3+
