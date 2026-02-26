# Sharp Platform Binaries - Size Impact Analysis

**Date**: February 18, 2026  
**Analysis For**: Pro Addon Distribution

## Executive Summary

Adding Sharp platform binaries will enable the `optimize_image_sharp` tool to work immediately after plugin installation without requiring users to run `npm install`. However, this comes with a size tradeoff.

**Recommended Approach**: Include Linux x64 binaries only (default)
- Size increase: +16.5 MB (+9.5%)
- Covers ~90% of production WordPress installations
- Other platform users can easily install binaries via `npm install`

## Current Situation

- **Pro Addon Total Size**: 173 MB
- **Pro Addon Vendor**: 88 MB
- **Sharp Vendor (library only)**: 324 KB
- **Missing**: Dependencies and platform binaries

## What's Missing

### JavaScript Dependencies
| Package | Size | Purpose |
|---------|------|---------|
| detect-libc | 26 KB | Detect Linux C library (glibc vs musl) |
| color | 26 KB | Color manipulation utilities |
| semver | 96 KB | Semantic version parsing |
| **Total** | **148 KB** | Required for Sharp to load |

### Platform-Specific Binaries

| Platform | Package | Size |
|----------|---------|------|
| **Linux x64** | @img/sharp-linux-x64 | 413 KB |
| | @img/sharp-libvips-linux-x64 | 15.89 MB |
| | **Subtotal** | **16.30 MB** |
| **macOS ARM64** | @img/sharp-darwin-arm64 | 268 KB |
| | @img/sharp-libvips-darwin-arm64 | 15.34 MB |
| | **Subtotal** | **15.61 MB** |
| **macOS Intel** | @img/sharp-darwin-x64 | 252 KB |
| | @img/sharp-libvips-darwin-x64 | 17.17 MB |
| | **Subtotal** | **17.42 MB** |
| **Windows** | @img/sharp-win32-x64 | 18.97 MB |
| | **Subtotal** | **18.97 MB** |
| **Grand Total** | All 7 common platforms | **68.30 MB** |

## Build Options Comparison

| Option | Description | Size Increase | Final Size | Coverage |
|--------|-------------|---------------|------------|----------|
| **1. Linux x64 Only** *(Default)* | Most common production server | +16.5 MB | 189.5 MB | ~90% of WordPress sites |
| **2. All Common Platforms** | Linux, macOS, Windows | +68.5 MB | 241.5 MB | All common platforms |
| **3. Dependencies Only** | No platform binaries | +148 KB | 173.15 MB | Requires npm install for all |
| **4. Current (No Sharp)** | No changes | 0 MB | 173 MB | Tool doesn't work |

## Size Impact Visualization

```
Current Pro Addon:        [████████████████████] 173 MB (Baseline)
+ Linux x64 (Option 1):   [█████████████████████▓] 189.5 MB (+9.5%)
+ All Platforms (Option 2): [███████████████████████████] 241.5 MB (+39.6%)
```

## Platform Distribution Statistics

Based on WordPress hosting statistics:

| Platform | Market Share | Notes |
|----------|--------------|-------|
| Linux x64 | ~88% | Most common (AWS, DigitalOcean, etc.) |
| Linux ARM64 | ~2% | AWS Graviton, Raspberry Pi |
| Windows | ~5% | IIS servers |
| macOS | ~3% | Local development, Mac servers |
| Other | ~2% | Alpine Linux (musl), 32-bit systems |

## Recommendations by Use Case

### Use Case 1: SaaS / Commercial Distribution
**Recommended**: Option 1 (Linux x64 only)
- Target audience: Production WordPress sites
- Most run on Linux x64
- Reasonable size increase
- Users on other platforms can install binaries
- **Command**: `npm run build` (default)

### Use Case 2: Enterprise / Multi-Platform
**Recommended**: Option 2 (All platforms)
- Target audience: Enterprise customers
- Multiple server environments
- Works everywhere out of the box
- Size less critical for enterprise
- **Command**: `WP_MCP_AI_SHARP_ALL_PLATFORMS=true npm run build`

### Use Case 3: Development / Testing
**Recommended**: Option 3 (Dependencies only) or Option 4 (Manual install)
- Target audience: Developers
- npm install is normal workflow
- Minimal impact on repository size
- **Command**: Manual installation when needed

## Implementation

### Default Build (Linux x64)
```bash
cd addons/pro
npm install --include=optional
npm run build
```

Result: Includes Linux x64 binaries (~16.5 MB added)

### Enterprise Build (All Platforms)
```bash
cd addons/pro
npm install --include=optional
WP_MCP_AI_SHARP_ALL_PLATFORMS=true npm run build
```

Result: Includes all common platform binaries (~68.5 MB added)

### Combined Zip Build (From root)
```bash
# Builds Pro addon with npm dependencies automatically
./bin/build-plugin-zip.sh --pro
```

## Size Comparison with Other Plugins

For context, here are sizes of popular WordPress plugins:

| Plugin | Type | Size | Notes |
|--------|------|------|-------|
| Jetpack | Free | 45 MB | Popular all-in-one plugin |
| WooCommerce | Free | 28 MB | E-commerce platform |
| Yoast SEO Premium | Premium | 15 MB | SEO optimization |
| Elementor Pro | Premium | 30 MB | Page builder |
| Gravity Forms | Premium | 18 MB | Form builder |
| WP Rocket | Premium | 3 MB | Caching (pure PHP) |
| **NV oOS Pro (current)** | Premium | 173 MB | AI automation with 24 toolkits |
| **NV oOS Pro (with Sharp)** | Premium | 189.5 MB | +Linux x64 binaries |

Note: NV oOS Pro is significantly larger because it includes:
- 24 specialized toolkits
- 40+ NPM packages (pre-bundled)
- PHP libraries (Composer vendor)
- AI/ML capabilities

## Git Repository Impact

### What Gets Committed

```
addons/pro/assets/vendor/sharp/
├── lib/                           # 324 KB (JavaScript library)
│   ├── index.js
│   ├── sharp.js
│   └── ...
├── node_modules/                  # Tracked in git!
│   ├── detect-libc/              # 26 KB
│   ├── color/                    # 26 KB
│   ├── semver/                   # 96 KB
│   └── @img/
│       ├── sharp-linux-x64/      # 413 KB (default)
│       └── sharp-libvips-linux-x64/  # 15.89 MB (default)
└── package.json                   # 8 KB
```

**Default**: ~16.8 MB committed to git (Linux x64)
**With all platforms**: ~68.6 MB committed to git

### .gitignore Configuration

```gitignore
# Don't track Pro dev dependencies
/addons/pro/node_modules/

# DO track vendor directory (for distribution)
!/addons/pro/assets/vendor/

# DO track Sharp's dependencies and binaries
!/addons/pro/assets/vendor/sharp/node_modules/
```

## User Experience

### With Binaries (Recommended)

1. User clones repository or downloads plugin
2. Activates plugin in WordPress
3. Enables Media Toolkit
4. Uses `optimize_image_sharp` tool immediately ✅

### Without Binaries (Current)

1. User clones repository or downloads plugin
2. Activates plugin in WordPress
3. Enables Media Toolkit
4. Tries to use `optimize_image_sharp` tool
5. Gets error: "Sharp is not fully installed" ❌
6. Reads documentation
7. Runs `cd addons/pro && npm install --include=optional`
8. Runs `npm run build`
9. Can now use tool ✅

## Conclusion

**Recommended**: **Option 1 - Linux x64 binaries (default build)**

### Pros
- Works immediately for 90% of users
- Reasonable size increase (+9.5%)
- Balances functionality with download size
- Simple fallback for other platforms (npm install)

### Cons
- macOS/Windows users need extra step
- Still a 16.5 MB increase

### Why Not Option 2 (All Platforms)?
- +68.5 MB is significant (+39.6%)
- Most users won't need macOS/Windows binaries
- Commercial plugin users expect larger downloads
- BUT: Can still offer as alternate download

### Alternative: Multiple Downloads
Consider offering two download options:
- `mcp-ai-wpoos-pro-standard.zip` (Linux x64 only - 189.5 MB)
- `mcp-ai-wpoos-pro-complete.zip` (All platforms - 241.5 MB)

Users choose based on their needs.

---

**Implementation Status**: ✅ Complete
- copy-dependencies.js updated with platform options
- build-plugin-zip.sh updated to build Pro dependencies
- Documentation created (SHARP_SETUP_GUIDE.md)
- .gitignore updated to track Sharp node_modules

**Next Steps**:
1. Run `cd addons/pro && npm install --include=optional && npm run build`
2. Commit the vendor directory with Sharp dependencies
3. Test Sharp tool functionality
4. Build Pro zip and verify size
5. Update release documentation with Sharp setup info

