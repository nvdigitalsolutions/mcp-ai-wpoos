# NV oOS Comic Reader

A modern comic book reader addon for the [NV oOS](https://nvdigitalsolutions.com/wpoos) platform. Supports **CBR, CBZ, CB7, and CBT** comic archive formats with a React-based reading interface, server-side metadata management, and AI creation tools.

## Features

- **📚 Comic Library** — searchable, sortable, paginated grid with series filters and collections
- **📖 Reading Modes** — paged (single/double page), vertical scroll, and webtoon (continuous strip)
- **🔍 Scale Types** — fit-to-screen, fit-to-width, fit-to-height, and free zoom (25%–400%)
- **↔ Reading Direction** — left-to-right and right-to-left, with automatic RTL detection from embedded ComicInfo.xml metadata
- **⚙ Reader Settings** — reading mode, scale, background color (white/gray/black), double-page spread, page transitions, and touch gestures — persisted per comic
- **⌨ Keyboard Navigation** — arrows, A/D for pages, +/− for zoom, F fullscreen, W/H fit, P double pages, R direction, G pages overview, ? help
- **📱 Touch Support** — swipe, pinch-to-zoom, and configurable tap zones
- **💾 Progress Sync** — per-user reading progress saved server-side (with localStorage fallback) and a "Continue reading" shelf
- **🖥 Fullscreen Mode** — immersive reading without distractions
- **📤 Drag & Drop Upload** — with archive magic-byte validation and a configurable size cap
- **🖼 Cover Extraction** — server-side CBZ cover thumbnails cached in the Media Library
- **🗂 Organization** — series and collections via taxonomy, ComicInfo.xml metadata import, and a metadata editor
- **🔌 WordPress Integration** — shortcode, Gutenberg block, REST API, and a settings page (Settings → Comic Reader)

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
| `direction` | settings | `ltr` (left-to-right) or `rtl` (right-to-left); falls back to the settings-page default |
| `height` | `""` | Minimum height for the reader container |

Reader preferences (zoom, fit mode, double-page, direction, reading mode…) are configurable from the reader's settings dialog and persist per comic. Site-wide defaults live in **Settings → Comic Reader**.

### Gutenberg Block

Add the "NV oOS Comic Reader" block from the widget category in the block editor.

### Settings Page

**Settings → Comic Reader** controls:

- **Reader defaults** — direction, reading mode, scale, background, double-page spread, page transition, touch gestures
- **Library & uploads** — maximum upload size, automatic cover extraction, server-side progress sync
- **Permissions** — capability overrides for read/upload/delete/edit (blank = default, filterable)

### Developer Hooks

| Hook | Type | Purpose |
|------|------|---------|
| `nvoos_comic_reader_can_render` | filter | Gate shortcode rendering (e.g. membership check) |
| `nvoos_comic_reader_manifest` | filter | Modify the REST manifest payload |
| `nvoos_comic_reader_creator_styles` | filter | Modify the AI creator's art-style list |
| `nvoos_comic_reader_generate_panels` | action | Fired when panel generation is requested |
| `nvoos_comic_reader_read_capability` | filter | Override the read capability (default `read`) |
| `nvoos_comic_reader_upload_capability` | filter | Override the upload capability (default `upload_files`) |
| `nvoos_comic_reader_delete_capability` | filter | Override the delete capability (default `delete_posts`) |
| `nvoos_comic_reader_edit_capability` | filter | Override the edit capability (default `edit_posts`) |
| `nvoos_comic_reader_max_upload_bytes` | filter | Override the maximum upload size in bytes |
| `nvoos_comic_reader_upload_handler` | filter | Replace the upload handler (off-site storage, custom sanitizers) |

## REST API

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/nvoos-comic-reader/v1/health` | GET | Health check |
| `/nvoos-comic-reader/v1/manifest` | GET | Addon metadata |
| `/nvoos-comic-reader/v1/comics` | GET | List library comics (`page`, `per_page`, `search`, `series`, `orderby`) |
| `/nvoos-comic-reader/v1/comics/{id}` | GET/PUT | Comic metadata; edit details |
| `/nvoos-comic-reader/v1/comics/{id}/file` | GET | Download raw comic file (Range supported) |
| `/nvoos-comic-reader/v1/comics/{id}/cover` | GET | Cover image status |
| `/nvoos-comic-reader/v1/comics/{id}/cover/generate` | POST | Generate the cached cover (CBZ) |
| `/nvoos-comic-reader/v1/comics/{id}/metadata` | GET/POST | Stored metadata; import ComicInfo.xml payload |
| `/nvoos-comic-reader/v1/comics/{id}/progress` | GET/POST | Per-user reading progress |
| `/nvoos-comic-reader/v1/comics/progress` | GET | The user's progress map |
| `/nvoos-comic-reader/v1/comics/{id}/delete` | DELETE | Delete a comic |
| `/nvoos-comic-reader/v1/upload` | POST | Upload a new comic |
| `/nvoos-comic-reader/v1/series` | GET | Series facets |
| `/nvoos-comic-reader/v1/collections` | GET/POST | List or create collections |
| `/nvoos-comic-reader/v1/collections/{id}/items` | POST | Add a comic to a collection |
| `/nvoos-comic-reader/v1/collections/{id}/items/{comic}` | DELETE | Remove a comic from a collection |

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

PHPUnit tests live in `addons/comic-reader/tests/` and run inside the repo's
Docker test environment.

## Architecture

### Client-Side Archive Extraction

The reader uses [libarchive.js](https://github.com/nika-begiashvili/libarchivejs) (compiled to WebAssembly) to extract comic archives entirely in the browser. Extraction runs in a **Web Worker** to keep the UI responsive; the worker also parses embedded `ComicInfo.xml` metadata for series grouping and RTL detection. No server-side processing is required to read — the WordPress server serves the raw archive with HTTP Range support.

### Tech Stack

- **React 18** — UI components
- **TypeScript** — Type-safe frontend code
- **esbuild** — Fast bundling (IIFE format for WordPress)
- **libarchive.js** — WASM-based archive extraction
- **WordPress REST API** — Data layer (comics, metadata, progress, collections)
- **WordPress Media Library** — Comic file storage and cover caching

## License

GPLv3 or later. See the repository `LICENSE` file.

## Third-Party Notices

- **libarchive.js** — MIT License (Copyright (c) 2018 Nika Begiashvili)
