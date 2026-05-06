# NV oOS Cornerstone3D Addon

Pre-built Cornerstone3D ESM bundles for the NV oOS Pro Medical Imaging Viewer.

## What It Does

This addon bundles the Cornerstone3D medical imaging libraries as self-contained
ESM modules, eliminating the runtime CDN (esm.sh) dependency for the DICOM viewer.

When installed, the Medical Imaging Viewer loads all Cornerstone3D libraries from
local files instead of the CDN — providing:

- **Offline support** — works without internet access
- **Faster loading** — no CDN round-trip latency
- **Version pinning** — no risk of CDN-side changes breaking the viewer
- **Compliance** — meets air-gapped / restricted-network requirements

## Bundled Packages

| Package | Version | File |
|---------|---------|------|
| @cornerstonejs/core | 1.86.1 | `cornerstone-core.esm.js` |
| @cornerstonejs/tools | 1.86.1 | `cornerstone-tools.esm.js` |
| @cornerstonejs/dicom-image-loader | 1.86.0 | `cornerstone-dicom-loader.esm.js` |
| dicom-parser | 1.8.21 | `dicom-parser.esm.js` |
| xmlbuilder2 | 3.0.2 | `xmlbuilder2.esm.js` |

## Installation

1. Download the `nvoos-cornerstone3d-vX.Y.Z.zip` file
2. In WordPress admin → Plugins → Add New → Upload Plugin
3. Upload the zip file and activate

**Requirements:**
- NV oOS Pro addon must be installed and active
- WordPress 6.0+
- PHP 7.4+

## How It Works

The addon registers WordPress filters that the Pro imaging viewer checks:
- `wp_mcp_ai_cornerstone3d_addon_dir` — provides the local file path
- `wp_mcp_ai_cornerstone3d_addon_url` — provides the URL base

When the viewer detects these filters, it loads Cornerstone3D modules from the
addon's `assets/cornerstone/` directory instead of the esm.sh CDN.

## Building From Source

```bash
cd <repo-root>
node bin/vendor-cornerstone.js
```

This downloads the npm packages and bundles them with esbuild into ESM modules.

## License

Proprietary — © 2025-2026 NV Digital Solutions. All rights reserved. The bundled Cornerstone3D libraries retain their upstream MIT license.

## Credits

This addon redistributes pre-built ESM bundles of the [Cornerstone3D](https://github.com/cornerstonejs/cornerstone3D) medical-imaging stack — © OHIF and Cornerstone.js contributors, MIT-licensed:

- `@cornerstonejs/core` 1.86.1
- `@cornerstonejs/tools` 1.86.1
- `@cornerstonejs/dicom-image-loader` 1.86.0
- [`dicom-parser`](https://github.com/cornerstonejs/dicomParser) 1.8.21 (MIT)
- [`xmlbuilder2`](https://github.com/oozcitak/xmlbuilder2) 3.0.2 (MIT)

Each bundled file retains the upstream copyright header. For the full repo-wide attribution index, see [`CREDITS.md`](../../CREDITS.md) at the repository root.
