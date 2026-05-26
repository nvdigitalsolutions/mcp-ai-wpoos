# NV oOS Media Studio

React SPA addon for NV oOS, scaffolded from the
[Toolkit SPA Blueprint](../../docs/addons/toolkit-spa-blueprint.md). This is
the **Tier D** specialist surface serving the `image-production` and `media`
toolkits with three production-ready modes.

## Modes

| Mode | Shortcode attr | Status | Features |
|------|---------------|--------|----------|
| `image-editor` (default) | `mode="image-editor"` | ✅ shipped (v0.3.0) | react-konva canvas, zoom/pan, drawing tools (brush, eraser, shapes, text, undo/redo), undo/redo via history stack, filters (brightness, contrast, saturation, blur, hue, grayscale, sepia, invert), crop overlay, text annotations, keyboard shortcuts, responsive canvas, PNG/JPEG export, save to WP Media Library |
| `media-player` | `mode="media-player"` | ✅ shipped | react-player (YouTube, Vimeo, MP4, MP3, HLS…), playback speed, fullscreen, keyboard shortcuts |
| `audio-waveform` | `mode="audio-waveform"` | ✅ shipped | wavesurfer.js 7 waveform + zoom + playback speed |
| `drawing` | `mode="drawing"` | ✅ shipped (v0.3.0) | Integrated into image-editor mode — Konva canvas drawing tools with brush, eraser, shapes, text, undo/redo |

Unknown values fall back to `image-editor`.

## Quick start

```bash
cd addons/media-studio
npm ci
npm run build       # produces assets/dist/media-studio.{js,css}
```

Add the shortcode:

```
[nvoos_media_studio_app mode="image-editor" src="https://example.com/photo.jpg"]
[nvoos_media_studio_app mode="media-player" src="https://www.youtube.com/watch?v=dQw4w9WgXcQ"]
[nvoos_media_studio_app mode="audio-waveform" src="https://example.com/podcast.mp3" toolkit="media"]
```

Or use the matching Gutenberg block (`nvoos/media-studio`).

## Bundle size note

Tier D specialist addons have no hard gzip budget — they are separate addons
that are never loaded unless the matching shortcode/block is on the page.

| Mode bundle | Approx. gzip contribution |
|------------|--------------------------|
| wavesurfer.js | ~350 KB |
| react-player (all providers) | ~290 KB |
| react-konva + konva | ~120 KB |
| react-image-crop | ~15 KB |

Total: **~826 KB gzip**. If only specific modes are needed in production,
consider building a mode-scoped bundle via dynamic `import()` in a future PR.

## REST namespace

`/wp-json/nvoos-media-studio/v1/health` — health check gated by `manage_options`.
Media editing is performed client-side; no server-side CRUD routes are needed.

## Version bump rule

When the SPA bundle changes, bump **all three** in the same commit:

1. `Version:` header in `nvoos-media-studio.php`
2. `define( 'NVOOS_MEDIA_STUDIO_VERSION', '…' );`
3. `"version"` in `package.json`

This forces `?ver=` query strings to invalidate browser caches.

## Credits

This addon bundles:

- [React 19 + ReactDOM](https://github.com/facebook/react) (MIT)
- [Konva + react-konva](https://github.com/konvajs/konva) (MIT)
- [react-image-crop](https://github.com/DominicTobias/react-image-crop) (ISC)
- [react-player](https://github.com/cookpete/react-player) (MIT)
- [wavesurfer.js](https://github.com/wavesurfer-js/wavesurfer.js) (BSD-3-Clause)

When adding upstream packages, update:

- [`THIRD_PARTY_NOTICES.md`](THIRD_PARTY_NOTICES.md)
- The root [`CREDITS.md`](../../CREDITS.md)
- This Credits section

