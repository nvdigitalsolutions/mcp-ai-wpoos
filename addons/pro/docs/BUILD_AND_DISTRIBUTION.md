# Build and Distribution Process

**How to prepare the Pro addon for distribution with pre-packaged NPM dependencies**

---

## Overview

The Pro addon uses NPM packages but **end users don't need Node.js**. This is achieved by:

1. **Development**: Developers run `npm install` to get packages
2. **Build**: `copy-dependencies.js` copies packages to `assets/vendor/`
3. **Distribution**: `assets/vendor/` is included in the plugin zip
4. **End Users**: Just activate the plugin (Node.js not required)

---

## For Developers

### One-Time Setup

```bash
# Navigate to Pro addon directory
cd addons/pro

# Install Node.js 18+ if not already installed
# Check version
node --version  # Should be v18.x.x or higher

# Install NPM dependencies
npm install
```

This creates `node_modules/` directory with all 32 packages (~500MB).

### Build Process

```bash
# Copy packages from node_modules to assets/vendor
npm run build

# Or run manually
node scripts/copy-dependencies.js
```

**What happens:**
- Scans `node_modules/` for configured packages
- Copies essential files to `assets/vendor/{package-name}/`
- Excludes unnecessary files (tests, docs, source maps)
- Reports total size and packages copied

**Output:**
```
🚀 Copying Pro addon dependencies to vendor directory...

✅ @turf/turf → 1.2 MB
✅ katex → 3.4 MB
✅ sharp → 8.9 MB
✅ stripe → 2.1 MB
... (32 packages total)

✅ Copied 32 dependencies (45.2 MB) in 3.45s
📦 Vendor directory: addons/pro/assets/vendor
```

### What Gets Copied

Each package configuration in `copy-dependencies.js` specifies:

```javascript
{
	name: 'stripe',
	dirs: [
		{ src: 'lib', dest: 'stripe/lib' },  // Copy entire lib/ directory
	],
	files: [
		{ src: 'package.json', dest: 'stripe/package.json' },  // Copy single file
	],
}
```

**Only essential files are copied:**
- Production JavaScript/CSS
- Type definitions (if needed)
- package.json (for version tracking)
- Binary files (for native modules like sharp, ffmpeg-static)

**NOT copied:**
- Test files
- Documentation (README, etc.)
- Source maps
- Example files
- Development dependencies

**Special handling for Sharp:**
Sharp requires platform-specific native binaries (libvips) and JavaScript dependencies (detect-libc, color, semver).
The copy script automatically:
- Copies Sharp's JavaScript library
- Copies Sharp's dependencies to `sharp/node_modules/`
- Copies platform-specific binaries for common platforms
- For current platform only: Use standard `npm run build`
- For all platforms: Use `WP_MCP_AI_BUILD_OFFLINE=true npm run build`

---

## Git Workflow

### What's Tracked

`.gitignore` configuration:

```gitignore
# NOT tracked (excluded)
/addons/pro/node_modules/           # Development dependencies

# TRACKED (included)
!/addons/pro/assets/vendor/         # Pre-built packages for distribution
!/addons/pro/assets/vendor/sharp/node_modules/  # Sharp dependencies and platform binaries
```

### Workflow

```bash
# 1. Install dependencies (creates node_modules - not tracked)
cd addons/pro
npm install --include=optional    # Include Sharp platform binaries

# 2. Build vendor directory (creates assets/vendor - tracked)
npm run build
# This copies packages from node_modules to assets/vendor, including:
# - Sharp library, dependencies (detect-libc, color, semver), and platform binaries
# - All other NPM packages

# 3. Commit the vendor directory
cd ../..
git add addons/pro/assets/vendor/
git commit -m "Update vendor packages with Sharp dependencies"
```

**Note about Sharp:**
Sharp requires special handling because it needs:
- JavaScript library files
- Platform-specific native binaries (libvips)
- JavaScript dependencies (detect-libc, color, semver)

All these are automatically copied to `assets/vendor/sharp/` and committed to the repository, so users don't need to run `npm install` after cloning.

---

## Distribution

### Plugin Zip Creation

When creating a distribution zip:

```bash
# From repository root
bin/build-plugin-zip.sh
```

**What's included:**
- ✅ All PHP files
- ✅ `addons/pro/assets/vendor/` (pre-built packages)
- ✅ `readme.txt`, `LICENSE`, etc.

**What's excluded** (see `.distignore`):
- ❌ `node_modules/` (development only)
- ❌ `package.json`, `package-lock.json`
- ❌ `scripts/` directory
- ❌ Test files
- ❌ Documentation (except README.md)

### WordPress.org Deployment

For WordPress.org SVN:

```bash
# 10up deployment action uses .distignore
# Automatically excludes node_modules, includes assets/vendor
```

---

## Package Size Optimization

### Current Sizes

| Package Category | Uncompressed | Compressed (zip) | Notes |
|-----------------|--------------|------------------|-------|
| Existing (12) | ~25 MB | ~8 MB | Already optimized |
| E-commerce (3) | ~3 MB | ~1 MB | Stripe is largest |
| Social Media (4) | ~4 MB | ~1.5 MB | Axios is small |
| Analytics (4) | ~8 MB | ~2.5 MB | d3 is large |
| Multilingual (4) | ~2 MB | ~0.5 MB | Lightweight |
| Video (5) | ~15 MB | ~5 MB | FFmpeg binaries |
| **TOTAL (32)** | **~57 MB** | **~18 MB** | Acceptable for Pro |

### Optimization Strategies

Already implemented in `copy-dependencies.js`:

1. **Selective copying** - Only production files
2. **No test files** - Exclude test/, __tests__/, etc.
3. **No docs** - Exclude README, CHANGELOG, etc.
4. **Minified versions** - Use .min.js when available
5. **Binary packages** - Only platform-specific binaries

### Future Optimizations (if needed)

If package size becomes an issue:

1. **Lazy loading** - Load packages only when toolkit is enabled
2. **On-demand download** - Download large packages on first use
3. **CDN hosting** - Host large packages on CDN
4. **Platform-specific builds** - Different zips for different platforms

---

## CI/CD Integration

### GitHub Actions

```yaml
name: Build Pro Addon

on:
  push:
    paths:
      - 'addons/pro/**'

jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      
      - name: Setup Node.js
        uses: actions/setup-node@v3
        with:
          node-version: '20'
          
      - name: Install dependencies
        run: |
          cd addons/pro
          npm ci
          
      - name: Build vendor packages
        run: |
          cd addons/pro
          npm run build
          
      - name: Check vendor directory
        run: |
          ls -lah addons/pro/assets/vendor/
          du -sh addons/pro/assets/vendor/
          
      - name: Commit if changed
        run: |
          git config --local user.email "action@github.com"
          git config --local user.name "GitHub Action"
          git add addons/pro/assets/vendor/
          git diff --quiet && git diff --staged --quiet || git commit -m "Auto-build: Update vendor packages"
```

---

## Troubleshooting

### Problem: npm install fails

**Solution:**
```bash
# Clear npm cache
npm cache clean --force

# Remove node_modules and lockfile
rm -rf node_modules package-lock.json

# Reinstall
npm install
```

### Problem: Sharp installation fails

**Solution:**
```bash
# Sharp requires node-gyp, ensure build tools are installed
# On Ubuntu/Debian:
sudo apt-get install build-essential

# On macOS:
xcode-select --install

# Then reinstall
npm install sharp --force
```

### Problem: FFmpeg-static download fails

**Solution:**
```bash
# FFmpeg-static downloads platform-specific binaries
# Check network/proxy settings

# Manual workaround:
npm install ffmpeg-static --no-optional
```

### Problem: Vendor directory too large

**Solution:**
```bash
# Check what's taking space
du -sh addons/pro/assets/vendor/*/ | sort -h

# Update copy-dependencies.js to exclude more files
# Or implement lazy loading for large packages
```

---

## Version Management

### Updating Packages

```bash
# Check for outdated packages
npm outdated

# Update specific package
npm update stripe

# Update all packages (careful!)
npm update

# After updating, rebuild vendor
npm run build

# Test thoroughly before committing
```

### Package Version Pinning

In `package.json`, we use caret ranges (`^`):

```json
"stripe": "^14.0.0"  // Allows 14.x.x updates
```

**Why?**
- Security patches auto-install
- No breaking changes in minor versions
- Flexibility for dependency resolution

**For production stability**, consider exact versions:

```json
"stripe": "14.0.0"  // Locked to exact version
```

---

## Verification

### Before Committing

```bash
# 1. Check vendor directory exists
ls addons/pro/assets/vendor/

# 2. Verify key packages are present
ls addons/pro/assets/vendor/stripe/
ls addons/pro/assets/vendor/sharp/
ls addons/pro/assets/vendor/d3/

# 3. Check total size
du -sh addons/pro/assets/vendor/

# 4. Test in WordPress
# - Activate plugin
# - Enable a toolkit
# - Test a tool that uses NPM package
```

### Testing Distribution Zip

```bash
# Build zip
bin/build-plugin-zip.sh

# Unzip to temp directory
unzip mcp-ai-wpoos-pro.zip -d /tmp/test/

# Verify vendor included
ls /tmp/test/mcp-ai-wpoos-pro/addons/pro/assets/vendor/

# Verify node_modules excluded
ls /tmp/test/mcp-ai-wpoos-pro/addons/pro/node_modules/ 2>&1 | grep "No such file"
```

---

## Summary

✅ **Development**: Run `npm install` and `npm run build`  
✅ **Git**: Track `assets/vendor/`, ignore `node_modules/`  
✅ **Distribution**: Include `assets/vendor/`, exclude `node_modules/`  
✅ **End Users**: No Node.js required, everything pre-packaged  

This approach gives developers flexibility while providing end users a seamless experience.
