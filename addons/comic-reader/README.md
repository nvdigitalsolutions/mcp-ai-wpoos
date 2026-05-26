# NV oOS Comic Reader

A modern comic book reader addon for the [NV oOS](https://nvdigitalsolutions.com/wpoos) platform. Supports **CBR, CBZ, CB7, and CBT** comic archive formats with a React-based reading interface.

## Features

- **📚 Comic Library** — Browse all uploaded comics in a responsive grid
- **📖 Dual Reading Modes** — Single page or double-page spread viewing
- **🔍 Zoom Controls** — Fit-to-width, fit-to-height, and free zoom (25%–400%)
- **↔ Reading Direction** — Left-to-right (Western) and right-to-left (Manga)
- **⌨ Keyboard Navigation** — Arrow keys, A/D for pages, +/- for zoom
- **📱 Touch Support** — Swipe and pinch-to-zoom on mobile/tablet
- **💾 Progress Persistence** — Automatically remembers your place in each comic
- **🖥 Fullscreen Mode** — Immersive reading without distractions
- **📤 Drag & Drop Upload** — Upload CBR/CBZ/CB7/CBT files directly
- **🔌 WordPress Integration** — Shortcode, Gutenberg block, and REST API

## Supported Formats

| Format | Extension | Archive Type |
|--------|-----------|-------------|
| Comic Book ZIP | `.cbz` | ZIP |
| Comic Book RAR | `.cbr` | RAR |
| Comic Book 7-Zip | `.cb7` | 7-Zip |
| Comic Book TAR | `.cbt` | TAR |

## Usage

### Shortcode

```
[nvoos_comic_reader]
```

With options:
```
[nvoos_comic_reader id="42" mode="reader" direction="rtl" height="800px"]
```

| Attribute | Default | Description |
|-----------|---------|-------------|
| `id` | `0` | Comic attachment ID to open directly |
| `mode` | `library` | `library` or `reader` |
| `direction` | `ltr` | `ltr` (left-to-right) or `rtl` (right-to-left) |
| `height` | `""` | Minimum height for the reader container |

### Gutenberg Block

Add the "NV oOS Comic Reader" block from the widget category in the block editor.

### REST API

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/nvoos-comic-reader/v1/health` | GET | Health check |
| `/nvoos-comic-reader/v1/manifest` | GET | Addon metadata |
| `/nvoos-comic-reader/v1/comics` | GET | List library comics |
| `/nvoos-comic-reader/v1/comics/{id}` | GET | Get comic metadata |
| `/nvoos-comic-reader/v1/comics/{id}/file` | GET | Download raw comic file |
| `/nvoos-comic-reader/v1/comics/{id}/cover` | GET | Get cover image |
| `/nvoos-comic-reader/v1/comics/{id}/delete` | DELETE | Delete a comic |
| `/nvoos-comic-reader/v1/upload` | POST | Upload a new comic |

## Development

```bash
# Install dependencies
cd addons/comic-reader
npm ci

# Development build with watch
npm run watch

# Production build
npm run build

# Run tests
npm test

# Type checking
npm run typecheck
```

## Architecture

### Client-Side Archive Extraction

The reader uses [libarchive.js](https://github.com/nika-begiashvili/libarchivejs) (compiled to WebAssembly) to extract comic archives entirely in the browser. Extraction runs in a **Web Worker** to keep the UI responsive. No server-side processing is required — the WordPress server simply serves the raw archive file.

### Tech Stack

- **React 18** — UI components
- **TypeScript** — Type-safe frontend code
- **esbuild** — Fast bundling (IIFE format for WordPress)
- **libarchive.js** — WASM-based archive extraction
- **WordPress REST API** — Data layer
- **WordPress Media Library** — Comic file storage

## License

GPLv3 or later. See the repository `LICENSE` file.

## Third-Party Notices

- **libarchive.js** — MIT License (Copyright (c) 2018 Nika Begiashvili)
