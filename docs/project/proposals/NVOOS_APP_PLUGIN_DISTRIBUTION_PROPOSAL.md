# NV oOS Canvas Addon & Distribution — Proposal

**Date:** March 2026  
**Version:** 2.0 (updated from v1.0 — architecture revised based on stakeholder feedback)  
> **Status:** ⏳ Not implemented (v1.1.29) — No multi-build distribution pipeline exists
**Author:** NV Digital Solutions  
**Related Docs:** [`docs/architecture/canvas-packaging-analysis.md`](../architecture/canvas-packaging-analysis.md), [`addons/pro/SIZE_BREAKDOWN.md`](../../addons/pro/SIZE_BREAKDOWN.md), [`addons/canvas/README.md`](../../addons/canvas/README.md)

> **Architecture Update (v2.0):** Based on stakeholder feedback, the "App plugin" umbrella concept (v1.0)  
> has been superseded. The adopted architecture is:  
> **Base + Pro = complete installation. Canvas = separate optional addon ZIP.**  
> The canvas addon (`nvoos-canvas`) has been implemented in `addons/canvas/`.

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Problem Statement](#2-problem-statement)
3. [Current State Analysis](#3-current-state-analysis)
4. [Industry Research & Best Practices](#4-industry-research--best-practices)
5. [Proposed Architecture](#5-proposed-architecture)
6. [Recommended Approach: Multi-Build Distribution](#6-recommended-approach-multi-build-distribution)
7. [App Plugin Structure](#7-app-plugin-structure)
8. [Build Matrix & CI/CD Strategy](#8-build-matrix--cicd-strategy)
9. [Canvas Native Binary Handling](#9-canvas-native-binary-handling)
10. [Implementation Roadmap](#10-implementation-roadmap)
11. [Technical Specifications](#11-technical-specifications)
12. [Size & Performance Estimates](#12-size--performance-estimates)
13. [Distribution Platform Strategy](#13-distribution-platform-strategy)
14. [Risks & Mitigations](#14-risks--mitigations)
15. [Success Metrics](#15-success-metrics)
16. [Decision Log](#16-decision-log)

---

## 1. Executive Summary

The NV oOS Pro plugin currently excludes the `canvas` npm package's native binary libraries (~50 MB compressed, 181 MB uncompressed) because they are platform-specific (Linux-only) and too large to include in a cross-platform distribution ZIP. Users who need Tesseract PDF OCR must manually install Node.js, system libraries (`libcairo2-dev`, `libpango1.0-dev`, etc.), and run `npm install canvas@2` — a significant barrier for non-technical users.

**This proposal recommends creating a new standalone "NV oOS App" plugin** (`nvoos-app`) that:

1. **Bundles the base plugin and pro addon into a single installable unit** — one ZIP, one installation, zero manual steps.
2. **Ships platform-specific distribution builds** so Linux users get a fully functional canvas-enabled OCR stack without any additional setup.
3. **Replaces the current 3-piece install process** (base plugin → pro addon → manual canvas) with a single package for supported platforms, while retaining the lightweight cross-platform option for hosts where canvas is not needed.
4. **Establishes a foundation for future "everything included" distributions** — packaging new heavy features (GPU binaries, WASM blobs, large model weights) without bloating the primary pro plugin.

**Expected Impact:**
- 🎯 **Zero-friction setup** for Linux-hosted WordPress sites with PDF OCR needs
- 📦 **~85 MB Linux build** vs current 3-step manual process
- ✅ **Maintained 33 MB cross-platform build** for the majority of users
- 🏗️ **Scalable architecture** for future large-dependency features

---

## 2. Problem Statement

### 2.1 The Canvas Problem

The `canvas` npm package provides HTML5 Canvas API for Node.js, used by the pro plugin's Tesseract PDF OCR pipeline. Its native binary libraries are:

| Library | Compressed Size | Platform |
|---------|----------------|----------|
| `librsvg-2.so.2` | ~26 MB | Linux only |
| `libharfbuzz.so.0` | ~7 MB | Linux only |
| `libgio-2.0.so.0` | ~3 MB | Linux only |
| Other 25+ libraries | ~14 MB | Linux only |
| **Total** | **~50 MB** | **Linux x64 only** |

**Canvas binaries cannot be cross-platform packaged because:**
- They are compiled C/C++ native addons tied to a specific OS, CPU architecture, and Node.js ABI version
- A Linux x64 binary will not run on Windows, macOS, ARM servers, or a different Node.js version
- Even with bundled binaries, users still need system libraries installed at the OS level

### 2.2 The Current 3-Step Install Process

For users needing Tesseract PDF OCR, the current process is:
```
Step 1: Install NV oOS Base Plugin (mcp-ai-wpoos.php)
Step 2: Install NV oOS Pro Addon (mcp-ai-wpoos-pro.php)
Step 3: SSH into server → install Node.js → apt-get install libcairo2-dev ... → npm install canvas@2
```

Step 3 is infeasible for:
- Managed WordPress hosts (WP Engine, Kinsta, Flywheel) — no SSH access
- Non-technical users
- Windows/macOS development environments (different binaries entirely)

### 2.3 The Distribution Gap

There is currently no single distribution artifact that delivers the full capability stack. The split between base + pro also means:
- Updates require two separate plugin uploads
- Licensing verification happens in two places
- Users can accidentally run the pro addon without the base plugin (or vice versa)

---

## 3. Current State Analysis

### 3.1 Plugin Architecture

```
Current distribution:
├── mcp-ai-wpoos.zip          (Base Plugin — ~5 MB)
│   ├── mcp-ai-wpoos.php      Main entry point
│   ├── includes/             165 base tools, REST API, settings
│   ├── assets/               JS/CSS (chat UI, admin)
│   └── packages/             9 npm utility packages
│
└── mcp-ai-wpoos-pro.zip      (Pro Addon — ~33 MB)
    ├── mcp-ai-wpoos-pro.php  Pro entry point
    ├── includes/             348 pro tools
    ├── assets/vendor/        Bundled NPM packages (35 MB uncompressed)
    │   ├── tesseract.js/
    │   ├── pdfjs-dist/
    │   ├── sharp/
    │   └── ... (29 more packages)
    └── vendor/               PHP Composer packages (56 MB uncompressed)
```

### 3.2 What's Missing

```
❌ canvas native binaries    (excluded — too large, platform-specific)
❌ Single-package install    (requires two separate plugin installs)
❌ Platform-aware delivery   (no mechanism to serve platform-optimized builds)
```

### 3.3 Size Budget (Current)

| Artifact | ZIP Size | Uncompressed | Notes |
|----------|----------|--------------|-------|
| Base plugin | ~5 MB | ~15 MB | Clean, lean |
| Pro addon | 33 MB | ~103 MB | Optimized in v1.1.2 |
| Canvas binaries | ~50 MB | ~181 MB | **Excluded** |
| **App (proposed)** | **~85 MB** | **~284 MB** | Linux x64 full build |
| **App (cross-platform)** | **~38 MB** | **~118 MB** | No canvas binaries |

---

## 4. Industry Research & Best Practices

### 4.1 WordPress Plugin Suite Patterns

Research into leading WordPress plugin suites reveals three dominant distribution models:

#### Model 1: Core + Separate Add-ons (Gravity Forms, WooCommerce)
- Lightweight core plugin on WordPress.org
- Each integration/feature as a separate add-on ZIP
- Users install only what they need
- **Lesson:** Works well when add-ons are optional; poor experience when most users need multiple add-ons

#### Model 2: Monolith with Feature Flags (Jetpack by Automattic)
- Single large plugin with all features bundled
- Features toggled on/off in settings
- Automatic platform detection for optional modules
- **Lesson:** Best user experience for all-in-one; requires careful size management

#### Model 3: Suite Umbrella Plugin (Agency/SaaS patterns)
- A top-level "app" plugin that loads nested sub-plugins
- Sub-plugins live in `/modules/` or `/bundled-plugins/` within the parent directory
- Each sub-plugin maintains its own hooks, filters, and class structure
- Parent plugin handles loading, dependency checks, and version compatibility
- **Lesson:** Best for products where all components are always needed together; closely matches NV oOS use case

**Reference:** [WordPress Plugin Best Practices — developer.wordpress.org](https://developer.wordpress.org/plugins/plugin-basics/best-practices/)

#### Recommended for NV oOS App
The **Umbrella Plugin** model is recommended for the app distribution, with the cross-platform and platform-specific ZIPs as separate build artifacts — not separate products. Users choose a single ZIP based on their platform, install once, and get everything.

### 4.2 npm Native Binary Distribution Strategies

Research into leading npm packages that ship native binaries reveals the following best practices (2024–2025):

#### Strategy A: Prebuildify (All Platforms in One Package)
Used by: `sharp`, `better-sqlite3`, `canvas`  
```
package/
└── prebuilds/
    ├── linux-x64/    canvas.node (compiled for Linux x64 Node 18)
    ├── linux-arm64/  canvas.node (compiled for Linux ARM64 Node 18)
    ├── darwin-x64/   canvas.node (macOS Intel)
    ├── darwin-arm64/ canvas.node (macOS Apple Silicon)
    └── win32-x64/    canvas.node (Windows x64)
```
- Runtime loader picks the correct binary from `prebuilds/`
- **Pro:** Works offline, no post-install scripts, reliable
- **Con:** Package size increases linearly with platforms supported
- **Reference:** [prebuildify GitHub](https://github.com/prebuild/prebuildify)

#### Strategy B: Platform Sub-packages (esbuild model)
Used by: `esbuild`, `Sentry CLI`, `@swc/core`  
```
"optionalDependencies": {
  "@canvas/linux-x64": "2.11.x",
  "@canvas/linux-arm64": "2.11.x",
  "@canvas/darwin-arm64": "2.11.x"
}
```
- npm/Yarn/pnpm installs only the matching sub-package
- **Pro:** Minimal download per platform, fine-grained control
- **Con:** More complex publish workflow; requires separate packages per platform
- **Reference:** [Sentry: How to publish binaries on npm](https://sentry.engineering/blog/publishing-binaries-on-npm)

#### Strategy C: Download-on-Install postinstall Script
Used by: older CLI tools, Playwright, Puppeteer  
- Tiny base package; postinstall downloads the binary from GitHub Releases
- **Pro:** Smallest initial download
- **Con:** Fails with `--ignore-scripts`, network errors, airgapped environments; increasingly discouraged for security reasons
- **Reference:** [MagicBell: Distributing Platform-Specific Binaries with npm](https://www.magicbell.com/blog/distributing-platform-specific-binaries-with-npm)

#### Recommended for NV oOS App
**Strategy A (Prebuildify)** is recommended for the Linux-specific app ZIP, and **Strategy B (Platform Sub-packages)** is the long-term target once the package is published to npm. For the initial WordPress plugin ZIP distribution, a **build-matrix approach** (separate ZIPs per platform) is the pragmatic first step.

### 4.3 Existing WordPress Plugin Size Limits

| Channel | Size Limit | Notes |
|---------|------------|-------|
| WordPress.org SVN | 10 MB per file | Applies to free plugin zip |
| WordPress.org directory | ~50 MB total | Per plugin guidance |
| WooCommerce Marketplace | 50 MB | Per extension guidelines |
| Self-hosted distribution | No limit | Customer-direct ZIP delivery |
| GitHub Releases | 2 GB per asset | Ideal for large platform builds |

**Implication:** The Linux App ZIP (~85 MB) cannot be submitted to WordPress.org but is perfectly suited for self-hosted distribution via the NV Digital Solutions website, GitHub Releases, or Freemius.

### 4.4 Freemius Distribution Patterns

[Freemius](https://freemius.com/wordpress/software-licensing/) is the industry standard for premium WordPress plugin licensing and distribution. Key patterns relevant to NV oOS App:

- **Per-Plan Download Variants:** Freemius supports multiple ZIPs per product version, enabling platform-specific downloads (Linux Build, Cross-Platform Build) under the same license
- **License Key Enforcement:** The app plugin validates license on activation — no separate license check needed per bundled component
- **Automatic Update Notifications:** Freemius SDK handles update pings, so the App plugin version can be bumped independently of the base/pro versions
- **Customer Portal:** Users self-serve their platform variant download without contacting support

---

## 5. Proposed Architecture

Three options are evaluated:

### Option A: Multi-Build Distribution (RECOMMENDED)

Create a new top-level plugin `nvoos-app` with a GitHub Actions build matrix that produces:

| Build Artifact | Platform | Size | Canvas | Use Case |
|----------------|----------|------|--------|----------|
| `nvoos-app-linux-x64.zip` | Linux x64 | ~85 MB | ✅ Included | VPS/cloud Linux servers |
| `nvoos-app-linux-arm64.zip` | Linux ARM64 | ~85 MB | ✅ Included | ARM cloud instances, Pi |
| `nvoos-app-cross-platform.zip` | All platforms | ~38 MB | ❌ Excluded | Managed WP hosts, Windows, macOS |

Each ZIP contains:
```
nvoos-app/
├── nvoos-app.php             (Umbrella plugin entry point)
├── bundled/
│   ├── mcp-ai-wpoos/         (Base plugin — full copy)
│   └── mcp-ai-wpoos-pro/     (Pro addon — full copy)
└── README.txt
```

**Verdict: ✅ Recommended — best balance of simplicity and capability**

### Option B: Self-Extracting Installer Plugin

A small (~1 MB) installer plugin that:
1. Activates and detects `process.platform` + server capabilities
2. Presents a UI: "We detected Linux x64. Download the full package? [Yes / Skip canvas]"
3. Downloads the appropriate platform ZIP from GitHub Releases / CDN
4. Extracts and self-configures

**Verdict: ⚠️ Complex to implement; fails on airgapped/restricted hosts; deferred to Phase 2**

### Option C: Docker-First Distribution

A Docker Compose setup with:
- WordPress container with base + pro plugins pre-installed
- Pre-built canvas/Node environment in sidecar

**Verdict: ❌ Not suitable for existing WordPress installations; niche use case**

---

## 6. Recommended Approach: Multi-Build Distribution

### 6.1 Why Multi-Build Wins

| Criterion | Option A (Multi-Build) | Option B (Installer) | Option C (Docker) |
|-----------|----------------------|---------------------|-------------------|
| Zero-friction setup | ✅ Single ZIP install | ⚠️ Requires download step | ❌ Separate infra |
| Works on managed hosts | ✅ Standard WP plugin | ❌ Needs outbound HTTP | ❌ Not applicable |
| Airgapped/offline | ✅ Self-contained ZIP | ❌ Requires internet | ✅ Pre-built image |
| WordPress.org compatible | ❌ Too large (85 MB) | ✅ Installer is small | ❌ Not a plugin |
| Implementation complexity | ✅ Low — CI/CD matrix | ⚠️ High — PHP installer | ❌ High — DevOps |
| Update mechanism | ✅ Standard WP update | ✅ Standard | Custom |
| User clarity | ✅ Clear platform choice | ⚠️ Wizard confusion | ❌ Different paradigm |

### 6.2 Build Decision Tree for End Users

```
User needs NV oOS App?
    │
    ├─ What server OS?
    │       ├─ Linux x64 (Ubuntu, Debian, CentOS, Cloudways, etc.)
    │       │       └─→ Download: nvoos-app-linux-x64.zip ✅ Canvas included
    │       │
    │       ├─ Linux ARM64 (AWS Graviton, Raspberry Pi, Ampere)
    │       │       └─→ Download: nvoos-app-linux-arm64.zip ✅ Canvas included
    │       │
    │       ├─ Windows Server (IIS/local dev)
    │       │       └─→ Download: nvoos-app-cross-platform.zip ⚠️ No canvas
    │       │
    │       └─ macOS (local dev, Valet, Herd)
    │               └─→ Download: nvoos-app-cross-platform.zip ⚠️ No canvas
    │
    └─ Don't need PDF OCR?
            └─→ Download: nvoos-app-cross-platform.zip ✅ Fastest, smallest
```

### 6.3 User Download Experience

The NV Digital Solutions download page will:
1. **Auto-detect** server platform via a lightweight PHP probe script (optional, user-triggered)
2. **Present download buttons** with clear platform labels and size badges
3. **Show canvas support indicator** next to each build
4. **Provide one-click download** with version number and SHA-256 checksum

---

## 7. App Plugin Structure

### 7.1 Directory Layout

```
nvoos-app/
├── nvoos-app.php                   # Main entry point (WP Plugin header)
├── README.txt                      # WordPress plugin readme
├── CHANGELOG.md
├── includes/
│   ├── class-nvoos-app-loader.php  # Loads bundled base + pro plugins
│   ├── class-nvoos-app-checker.php # Dependency & platform checks
│   └── class-nvoos-app-updater.php # Update mechanism (Freemius or self-hosted)
└── bundled/
    ├── mcp-ai-wpoos/               # Base plugin (symlinked or copied by build)
    │   └── mcp-ai-wpoos.php
    └── mcp-ai-wpoos-pro/           # Pro addon (symlinked or copied by build)
        └── mcp-ai-wpoos-pro.php
```

### 7.2 Main Plugin Entry Point

```php
<?php
/**
 * Plugin Name: NV oOS App
 * Plugin URI:  https://nvdigitalsolutions.com/wpoos-app
 * Description: Complete NV Open Operator System bundle — Base + Pro + canvas binaries (platform build).
 * Version:     1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: NV Digital Solutions
 * License: GPLv3 or later
 *
 * @package NV_oOS_App
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NVOOS_APP_FILE', __FILE__ );
define( 'NVOOS_APP_DIR', plugin_dir_path( __FILE__ ) );
define( 'NVOOS_APP_VERSION', '1.0.0' );

// Load the bundled base plugin.
$base_plugin = NVOOS_APP_DIR . 'bundled/mcp-ai-wpoos/mcp-ai-wpoos.php';
if ( file_exists( $base_plugin ) ) {
    require_once $base_plugin;
}

// Load the bundled pro addon.
$pro_plugin = NVOOS_APP_DIR . 'bundled/mcp-ai-wpoos-pro/mcp-ai-wpoos-pro.php';
if ( file_exists( $pro_plugin ) ) {
    require_once $pro_plugin;
}
```

### 7.3 Prevent Conflicts with Standalone Installations

The loader includes a guard to prevent double-loading if users accidentally have both the app plugin and the standalone base/pro plugins active:

```php
// In class-nvoos-app-loader.php
add_action( 'admin_notices', 'nvoos_app_check_duplicate_plugins' );
function nvoos_app_check_duplicate_plugins() {
    $conflicts = array();

    if ( is_plugin_active( 'mcp-ai-wpoos/mcp-ai-wpoos.php' ) ) {
        $conflicts[] = 'NV oOS Base';
    }
    if ( is_plugin_active( 'mcp-ai-wpoos-pro/mcp-ai-wpoos-pro.php' ) ) {
        $conflicts[] = 'NV oOS Pro';
    }

    if ( ! empty( $conflicts ) ) {
        echo '<div class="notice notice-error"><p>';
        printf(
            '<strong>NV oOS App:</strong> Please deactivate %s before using the App bundle to avoid conflicts.',
            esc_html( implode( ' and ', $conflicts ) )
        );
        echo '</p></div>';
    }
}
```

---

## 8. Build Matrix & CI/CD Strategy

### 8.1 GitHub Actions Build Workflow

A new workflow file `.github/workflows/build-app-plugin.yml` will produce all distribution artifacts:

```yaml
name: Build NV oOS App Plugin

on:
  workflow_dispatch:
    inputs:
      version:
        description: 'Release version (e.g. 1.0.0)'
        required: true

jobs:
  build:
    name: Build ${{ matrix.platform }}
    runs-on: ${{ matrix.runner }}
    strategy:
      matrix:
        include:
          - platform: linux-x64
            runner: ubuntu-22.04
            node-arch: x64
            artifact: nvoos-app-linux-x64
          - platform: linux-arm64
            runner: ubuntu-22.04
            node-arch: arm64
            artifact: nvoos-app-linux-arm64
          - platform: cross-platform
            runner: ubuntu-22.04
            node-arch: none
            artifact: nvoos-app-cross-platform

    steps:
      - uses: actions/checkout@v4

      - name: Set up Node.js
        if: matrix.node-arch != 'none'
        uses: actions/setup-node@v4
        with:
          node-version: '20'
          # Node 20.9.0+ is required: canvas@2 works on Node 18.x and Node 20.x,
          # but canvas@3+ requires Node >=20.9.0. Pinning to Node 20.x (latest LTS)
          # ensures canvas@2 builds succeed on the widest range of server environments.
          # End-user runtime note: the bundled canvas binaries are compiled against
          # Node 20 ABI. Servers running the canvas-enabled App build must have
          # Node.js >=18.17.0 (LTS) or >=20.9.0 installed for the OCR Node service.

      - name: Install canvas for ${{ matrix.platform }}
        if: matrix.node-arch != 'none'
        run: |
          cd addons/pro
          npm install canvas@2 --cpu=${{ matrix.node-arch }} --os=linux

      - name: Run copy-dependencies.js (with canvas)
        if: matrix.node-arch != 'none'
        run: |
          cd addons/pro
          INCLUDE_CANVAS=true node scripts/copy-dependencies.js

      - name: Run copy-dependencies.js (no canvas)
        if: matrix.node-arch == 'none'
        run: |
          cd addons/pro
          node scripts/copy-dependencies.js

      - name: Assemble App plugin bundle
        run: |
          mkdir -p dist/${{ matrix.artifact }}/nvoos-app/bundled
          cp -r . dist/${{ matrix.artifact }}/nvoos-app/bundled/mcp-ai-wpoos
          cp -r addons/pro dist/${{ matrix.artifact }}/nvoos-app/bundled/mcp-ai-wpoos-pro
          cp app-plugin/nvoos-app.php dist/${{ matrix.artifact }}/nvoos-app/
          cp app-plugin/README.txt dist/${{ matrix.artifact }}/nvoos-app/

      - name: Create ZIP
        run: |
          cd dist/${{ matrix.artifact }}
          zip -r ../../${{ matrix.artifact }}-v${{ inputs.version }}.zip nvoos-app/

      - name: Upload artifact
        uses: actions/upload-artifact@v4
        with:
          name: ${{ matrix.artifact }}-v${{ inputs.version }}
          path: ${{ matrix.artifact }}-v${{ inputs.version }}.zip
```

### 8.2 ARM64 Cross-Compilation

Building ARM64 canvas binaries requires cross-compilation or an ARM runner. Two approaches:

**Approach A: QEMU emulation (simple, slower builds)**
```yaml
- name: Set up QEMU
  uses: docker/setup-qemu-action@v3
  with:
    platforms: arm64

- name: Build canvas for ARM64 in Docker
  run: |
    docker run --rm --platform linux/arm64 \
      -v $PWD:/app -w /app/addons/pro \
      node:20-bookworm \
      npm install canvas@2
```

**Approach B: GitHub ARM64 runner (faster, requires paid plan)**
```yaml
runner: ubuntu-22.04-arm
```

**Recommendation:** Start with QEMU emulation (free), migrate to ARM64 runners when volume justifies cost.

### 8.3 Build Artifact Summary

| Artifact | Runner | Canvas | Node ABI | Approx. Build Time |
|----------|--------|--------|----------|-------------------|
| `nvoos-app-linux-x64` | ubuntu-22.04 | ✅ Native | Node 20 x64 | ~5 min |
| `nvoos-app-linux-arm64` | ubuntu-22.04 + QEMU | ✅ Emulated | Node 20 arm64 | ~20 min |
| `nvoos-app-cross-platform` | ubuntu-22.04 | ❌ None | N/A | ~3 min |

---

## 9. Canvas Native Binary Handling

### 9.1 Canvas Installation in the Build Pipeline

The `copy-dependencies.js` script (already used for bundling npm deps into `assets/vendor/`) will be extended with a conditional canvas copy:

```javascript
// In addons/pro/scripts/copy-dependencies.js

if ( process.env.INCLUDE_CANVAS === 'true' ) {
    console.log( '📦 Including canvas native binaries for platform build...' );
    const canvasSrc = path.join( nodeModulesPath, 'canvas' );
    const canvasDest = path.join( vendorPath, 'canvas' );

    // Copy full canvas package (lib + build/Release binaries)
    copyDir( canvasSrc, canvasDest );
    console.log( '✅ canvas → included with native binaries' );
} else {
    // Default: copy only JS wrapper, exclude build/
    const canvasLibSrc = path.join( nodeModulesPath, 'canvas', 'lib' );
    const canvasLibDest = path.join( vendorPath, 'canvas', 'lib' );
    copyDir( canvasLibSrc, canvasLibDest );
    console.log( '✅ canvas/lib → JS wrapper only (no native binaries)' );
}
```

### 9.2 Platform Detection at Runtime

The pro plugin's OCR service already handles the case where canvas is unavailable. For the App plugin, add a platform capability banner in the admin UI:

```
Settings → NV oOS → System Status

Platform Build: Linux x64 (canvas enabled)  ✅
Canvas Status:  Loaded — PDF OCR ready      ✅
Tesseract OCR:  Available                   ✅
```

vs. for cross-platform build:

```
Platform Build: Cross-Platform              ℹ️
Canvas Status:  Not installed               ⚠️
PDF OCR Method: AI Vision models (GPT-4o)   ✅
Optional setup: [ Install canvas manually ] →
```

### 9.3 Canvas Version Pinning

The App plugin build pins canvas to a specific version to ensure reproducible builds:

```json
// addons/pro/package.json
{
  "optionalDependencies": {
    "canvas": "2.11.2"
  }
}
```

> **Note:** Use `canvas@2` — canvas v3+ requires Node.js `>=20.9.0` and will fail on Node 18.x or Node 20.x < 20.9.0. This constraint is already documented in [`addons/pro/SIZE_BREAKDOWN.md`](../../addons/pro/SIZE_BREAKDOWN.md).

---

## 10. Implementation Roadmap

### Phase 1: Foundation (Week 1–2)

- [ ] Create `app-plugin/` directory with `nvoos-app.php`, loader, and checker classes
- [ ] Add conflict detection for duplicate base/pro plugin installs
- [ ] Write `README.txt` and plugin headers for the app plugin
- [ ] Extend `copy-dependencies.js` with `INCLUDE_CANVAS` environment flag
- [ ] Add basic tests for the app plugin loader class

### Phase 2: Build Matrix (Week 3–4)

- [ ] Create `.github/workflows/build-app-plugin.yml` with 3-way matrix
- [ ] Validate linux-x64 build produces functional canvas
- [ ] Validate linux-arm64 build via QEMU (or ARM runner)
- [ ] Validate cross-platform build excludes all native binaries
- [ ] Add SHA-256 checksum generation to build artifacts
- [ ] Manually test installation on fresh WordPress + Linux VPS

### Phase 3: Download Experience (Week 5–6)

- [ ] Create download page at `nvdigitalsolutions.com/wpoos-app`
- [ ] Implement server platform probe script (optional JS-based or PHP-based detection)
- [ ] Add platform-specific download buttons with size badges
- [ ] Add checksum verification instructions
- [ ] Freemius integration for license-gated downloads (if applicable)

### Phase 4: Admin UI Integration (Week 7–8)

- [ ] Add "Platform Build" status card to NV oOS System Status page
- [ ] Show canvas capability indicator in OCR settings
- [ ] Add migration guide for users moving from base+pro standalone to App plugin
- [ ] Documentation update: `docs/QUICK_REFERENCE.md`, `docs/deployment/`

### Phase 5: Long-Term (Ongoing)

- [ ] Explore `optionalDependencies` npm approach for future platform sub-packages
- [ ] Evaluate hosting large App ZIPs on GitHub Releases vs Freemius vs CDN
- [ ] Consider embedded LLM binary distribution using the same multi-build approach
  - The App plugin build matrix can be extended to include GGUF model download or llama.cpp binary per-platform (see `includes/class-wp-mcp-ai-embedded-client.php`)

---

## 11. Technical Specifications

### 11.1 Plugin Metadata

| Field | Value |
|-------|-------|
| Plugin slug | `nvoos-app` |
| Plugin Name | NV oOS App |
| Text Domain | `nvoos-app` |
| Minimum WordPress | 6.0 |
| Minimum PHP | 7.4 |
| License | GPLv3 or later |
| Author | NV Digital Solutions |

### 11.2 File Structure (Final)

```
app-plugin/                         ← New top-level directory in repo
├── nvoos-app.php
├── README.txt
├── CHANGELOG.md
└── includes/
    ├── class-nvoos-app-loader.php
    ├── class-nvoos-app-checker.php
    └── class-nvoos-app-updater.php
```

### 11.3 Composer / Autoload

The app plugin does not need its own Composer dependencies — it relies entirely on the bundled base and pro plugins' autoloaders. The `nvoos-app.php` entry point simply `require_once`s the bundled plugin files.

### 11.4 Update Mechanism

Three options for how the App plugin checks for updates:

| Option | Complexity | Recommended For |
|--------|------------|----------------|
| Freemius SDK | Low — drop-in | Commercial distribution |
| GitHub Releases + WP plugin updater | Medium | Open-source / self-hosted |
| Self-hosted update API | High | Full control, multisite |

**Initial recommendation:** GitHub Releases updater (using [`YahnisElsts/plugin-update-checker`](https://github.com/YahnisElsts/plugin-update-checker)) to keep the app plugin lean, with Freemius as the commercial path.

### 11.5 `.distignore` Rules

Add the following to `.distignore` to exclude the app-plugin source directory from the base/pro plugin builds (it is a separate distribution artifact):

```
app-plugin/
```

---

## 12. Size & Performance Estimates

### 12.1 Build Artifact Sizes

| Build | ZIP Size | Uncompressed | Download Time (50 Mbps) |
|-------|----------|--------------|------------------------|
| `nvoos-app-linux-x64.zip` | ~85 MB | ~290 MB | ~14 sec |
| `nvoos-app-linux-arm64.zip` | ~85 MB | ~290 MB | ~14 sec |
| `nvoos-app-cross-platform.zip` | ~38 MB | ~120 MB | ~6 sec |
| Current: pro-only | 33 MB | ~103 MB | ~5 sec |
| Current: base-only | ~5 MB | ~15 MB | <1 sec |

### 12.2 WordPress Installation Impact

```
wp-content/plugins/nvoos-app/       ~290 MB (Linux x64 uncompressed)
  └─ bundled/mcp-ai-wpoos/          ~15 MB
  └─ bundled/mcp-ai-wpoos-pro/      ~103 MB
  └─ (canvas native binaries)       ~170 MB
```

This is comparable to other large WordPress plugins (Elementor: ~30 MB, WPML: ~40 MB, Jetpack: ~50 MB), with the caveat that the Linux platform build is larger than typical due to the canvas native binary stack.

---

## 13. Distribution Platform Strategy

### 13.1 Primary Channels

| Channel | Build Variants | Access Control | Notes |
|---------|---------------|----------------|-------|
| NV Digital Solutions website | All 3 builds | License-gated | Primary channel |
| GitHub Releases | All 3 builds | Public | SHA-256 checksums |
| Freemius | All 3 builds | License-gated | Commercial path |
| WordPress.org | ❌ Too large | N/A | Not applicable for App plugin |

### 13.2 Version Tagging Strategy

App plugin versions are independent of base/pro versions:

```
nvoos-app v1.0.0  ← bundles mcp-ai-wpoos v1.1.5 + mcp-ai-wpoos-pro v1.1.2
nvoos-app v1.0.1  ← bundles mcp-ai-wpoos v1.1.6 + mcp-ai-wpoos-pro v1.1.2
nvoos-app v1.1.0  ← bundles mcp-ai-wpoos v1.1.6 + mcp-ai-wpoos-pro v1.2.0
```

The bundled versions are documented in `CHANGELOG.md` and the plugin `Description` header.

### 13.3 Download Page UX

```
┌─────────────────────────────────────────────────────────────────┐
│  NV oOS App v1.0.0 — Download                                    │
│                                                                  │
│  🐧 Linux (x64)           [Download — 85 MB]  ← Recommended for │
│     Full PDF OCR support                          Ubuntu/Debian  │
│                                                                  │
│  🐧 Linux (ARM64)         [Download — 85 MB]  ← AWS Graviton,   │
│     Full PDF OCR support                          Raspberry Pi   │
│                                                                  │
│  🌍 Cross-Platform        [Download — 38 MB]  ← Windows, macOS, │
│     AI Vision OCR (no canvas)                     managed hosts  │
│                                                                  │
│  ℹ️ Not sure? [Detect My Server →]                               │
│                                                                  │
│  SHA-256 checksums: [View →]                                     │
└─────────────────────────────────────────────────────────────────┘
```

---

## 14. Risks & Mitigations

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Canvas binaries break on different Node.js ABI versions | Medium | High | Pin Node.js version in build; document required ABI; add runtime ABI check before loading canvas |
| App plugin conflicts with standalone base/pro installs | High | Medium | Conflict detection in loader; clear admin notice; deactivation prompt |
| ARM64 build fails (QEMU instability) | Medium | Low | ARM64 build is non-blocking; users without ARM64 use Linux x64 in Docker |
| Size creep: App ZIP grows >100 MB | Low | Medium | `.distignore` rules; size budget check in CI; source-map exclusion enforced |
| Users upload wrong platform build | Medium | Low | Platform banner in admin; clear error message from canvas when binaries don't match |
| canvas@2 becomes unsupported | Low | Medium | Long-term: evaluate canvas@3 (Node 20.9+ only) and document upgrade path |
| License/GPLv3 compliance for bundled canvas | Low | High | canvas is MIT-licensed — compatible with GPLv3. Document in `docs/THIRD_PARTY_ASSETS.md` |

---

## 15. Success Metrics

| Metric | Baseline (Current) | Target (3 months post-launch) |
|--------|-------------------|-------------------------------|
| PDF OCR setup time for Linux users | 30–60 min (manual) | <5 min (App install) |
| Support tickets: canvas installation | Ongoing | -80% reduction |
| PDF OCR adoption rate | <5% of users | >15% of Linux users |
| App plugin install-to-activation success | N/A | >95% |
| Build pipeline reliability | N/A | >99% success rate |
| Linux x64 ZIP size | N/A | <90 MB |
| Cross-platform ZIP size | 38 MB (base+pro) | <40 MB |

---

## 16. Decision Log

| Decision | Rationale | Date |
|----------|-----------|------|
| Multi-build distribution over single monolith | Cross-platform monolith would bundle 250 MB of platform-specific binaries for all platforms simultaneously | March 2026 |
| Start with 3 builds (linux-x64, linux-arm64, cross-platform) | Covers 90%+ of production WordPress server environments; Windows/macOS are primarily local dev | March 2026 |
| canvas@2 (not canvas@3) | canvas@3 requires Node >=20.9.0; many hosts still run Node 18 or older Node 20 builds | March 2026 |
| Exclude App plugin from WordPress.org | 85 MB exceeds WordPress.org size limits; primary sales channel is NVDigitalSolutions.com | March 2026 |
| Freemius as commercial licensing path | Industry standard for premium WP plugins; supports multiple download variants per license | March 2026 |
| Keep standalone base+pro ZIPs | Majority of users don't need canvas; preserving the 33 MB pro ZIP is the correct choice for 95% of the user base | March 2026 |

---

## Appendix A: Comparison with Embedded LLM Binary Distribution

The App plugin distribution pattern described here is directly analogous to the existing embedded LLM binary management system (`WP_MCP_AI_Embedded_Client` in `includes/class-wp-mcp-ai-embedded-client.php`). The embedded client already:

- Downloads platform-specific llama.cpp binaries from GitHub Releases
- Detects server CPU architecture
- Manages binary versioning and updates

**Long-term opportunity:** The App plugin build matrix can be extended to pre-bundle llama.cpp binaries alongside canvas, creating a single "fully loaded" platform build that requires zero post-install configuration for both PDF OCR and embedded LLM inference.

---

## Appendix B: Related Documentation

- [`docs/architecture/canvas-packaging-analysis.md`](../architecture/canvas-packaging-analysis.md) — Detailed analysis of canvas size and why binaries were excluded from the pro plugin
- [`addons/pro/SIZE_BREAKDOWN.md`](../../addons/pro/SIZE_BREAKDOWN.md) — Pro plugin size budget and optimization history
- [`addons/pro/docs/BUILD_AND_DISTRIBUTION.md`](../../addons/pro/docs/BUILD_AND_DISTRIBUTION.md) — Current build and distribution process for the pro addon
- [`docs/architecture/pro-plugin-size-optimization.md`](../architecture/pro-plugin-size-optimization.md) — How the pro plugin was reduced from 87 MB to 33 MB
- [`docs/THIRD_PARTY_ASSETS.md`](../THIRD_PARTY_ASSETS.md) — Third-party asset licensing documentation
- [`docs/PRODUCTION_BUILD.md`](../PRODUCTION_BUILD.md) — Current production build process

---

*This proposal was informed by research into WordPress plugin suite patterns, npm native binary distribution strategies (prebuildify, esbuild optionalDependencies, Sentry CLI), Freemius licensing patterns, and the NV oOS codebase's existing canvas analysis and size optimization work.*
