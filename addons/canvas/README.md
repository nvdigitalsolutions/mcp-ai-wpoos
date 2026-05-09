# NV oOS Canvas Addon

**Separate installable plugin** — provides platform-specific `canvas` native binaries for the NV oOS Pro addon's Tesseract PDF OCR feature.

## Overview

| Plugin | What it provides |
|--------|-----------------|
| `mcp-ai-wpoos` (Base) | AI assistant, 165 core tools, chat UI |
| `mcp-ai-wpoos-pro` (Pro) | 348 advanced tools, OCR service (image + PDF), document generation |
| **`nvoos-canvas` (this plugin)** | `canvas.node` native binary for PDF rendering via Tesseract |

The Pro addon ships without canvas native binaries because they are platform-specific (~50 MB compressed) and cannot be bundled into a cross-platform ZIP. This plugin bridges that gap by delivering the pre-compiled binary for your server platform as a separate installable addon.

## Distribution ZIPs

| Download | Platform | Size | Use case |
|----------|----------|------|---------|
| `nvoos-canvas-linux-x64.zip` | Linux x64 | ~50 MB | Ubuntu, Debian, CentOS, Cloudways, most cloud VPS |
| `nvoos-canvas-linux-arm64.zip` | Linux ARM64 | ~50 MB | AWS Graviton, Raspberry Pi, Ampere cloud |

**Windows / macOS:** Canvas is not needed on these platforms. For PDF OCR, use the AI Vision OCR method (GPT-4o, Gemini, Claude) built into the Pro addon — no extra installation required.

## Installation

1. Download the correct platform ZIP from [nvdigitalsolutions.com/wpoos#canvas-addon](https://nvdigitalsolutions.com/wpoos#canvas-addon)
2. Upload and activate via **Plugins → Add New → Upload Plugin**
3. After activation, visit **Settings → NV oOS → System Status** to verify canvas is detected

## Requirements

- NV oOS Base Plugin (mcp-ai-wpoos) active
- NV oOS Pro Addon (mcp-ai-wpoos-pro) active
- WordPress 6.0+, PHP 7.4+
- **Node.js ≥ 18.17.0** on the server (required by the Pro addon's OCR service)
- Linux x64 or ARM64 server (canvas native binaries are Linux-only)

## For Developers — Building Locally

```bash
# From addons/canvas/
npm install              # installs canvas@2 for current platform
npm run build            # copies binaries into assets/canvas/build/Release/
```

**Use canvas@2** — canvas v3+ requires Node.js ≥ 20.9.0. canvas@2 supports Node 18.x and Node 20.x.

## How It Works

On activation, this plugin exposes two PHP functions:

```php
// Returns path to canvas module directory, or '' if binary is missing
nvoos_canvas_get_dir();

// Returns true if canvas.node binary is present
nvoos_canvas_is_available();
```

The Pro addon's OCR PHP service (`WP_MCP_AI_OCR_Service`) calls `nvoos_canvas_get_dir()` and passes the result as `NVOOS_CANVAS_PATH` environment variable when spawning the Node.js OCR process. The Node.js OCR service then loads canvas from that path instead of trying `node_modules/canvas`.

## Canvas License

The bundled `canvas` npm package is licensed under the **MIT License**.  
Copyright (c) Automattic, Inc. — see `assets/canvas/package.json` for details.

## Plugin License

Proprietary — © 2025-2026 NV Digital Solutions. All rights reserved. The bundled `canvas` npm package retains its MIT license. See [`CREDITS.md`](../../CREDITS.md) at the repository root for full attribution.

## Credits

This addon redistributes the [`canvas`](https://github.com/Automattic/node-canvas) Node.js module — © Automattic and contributors, MIT-licensed — pre-compiled for Linux x64 and ARM64. The native bindings link against the [Cairo](https://www.cairographics.org/) graphics library (LGPL-2.1) and standard system image libraries.

For the full repo-wide attribution index, see [`CREDITS.md`](../../CREDITS.md) at the repository root.
