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
| `fashion-studio` | `mode="fashion-studio"` | ✅ shipped (v0.5.0) | AI fashion production suite: on-model, model-swap, face-swap, background, recolor, packshot, detail-repair, try-on transforms with cost-review gates, consent/acknowledgment flows, disclosure watermark on face outputs, Pro identities + batch/review queue, the marketplace output pipeline (Amazon/WooCommerce/social/web profiles), and IPTC 2025.1 XMP provenance + best-effort C2PA signing with compliance chips (see `docs/project/plans/media-studio-fashion-photography-enhancement-plan.md`) |

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
[nvoos_media_studio_app mode="fashion-studio"]
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

The AI bridge (v0.2.0+, used by `fashion-studio` and the editor's Save/Load
buttons):

| Route | Method | Permission | Purpose |
|-------|--------|-----------|---------|
| `/ai/capabilities` | GET | `edit_posts` | providers, transforms, disclosure settings, cost tripwires |
| `/ai/presets` | GET | `edit_posts` | fashion presets (base defaults; Pro seeds more) |
| `/ai/models` | GET | `edit_posts` | identity library (Pro-filled) |
| `/ai/generate` | POST | `upload_files` | run one transform on one attachment |
| `/ai/import` | POST | `upload_files` | register an attachment as editor source |
| `/ai/export` | POST | `upload_files` | persist a canvas/dataURL into the Media Library |
| `/ai/pipeline` | POST | `upload_files` | run an attachment through a marketplace output profile (amazon / woocommerce / social / web) — resize, square-crop, format conversion with JPEG fallback, alt text, white-background validation |
| `/ai/jobs` (Pro) | POST / GET | `upload_files` / `edit_posts` | create / list batch jobs |
| `/ai/jobs/<id>` (Pro) | GET | `edit_posts` | job detail + variants |
| `/ai/jobs/<id>/review` (Pro) | POST | `upload_files` | approve / reject / re-roll a variant |

The Pro addon registers the `/ai/jobs*` routes (plus the identity library via
`/ai/models` and fashion presets via `/ai/presets`) — see
`addons/pro/includes/fashion/`. Batch dispatch uses Action Scheduler
(group `nvoos_media_studio_batch`) with an inline fallback when AS is
unavailable; per-job cost gates follow the same review tripwires as single runs.

Cookie nonce auth (`X-WP-Nonce`); the SPA never holds provider credentials —
execution routes through the core tool registry (`edit_gemini_image` et al.)
or the `nvoos_media_studio_execute_tool` filter (sidecar seam). AI outputs carry
`_nvoos_ai_*` provenance meta; face outputs get a forced GD disclosure
watermark and a one-time acknowledgment gate (EU AI Act Art. 50 posture).

**Phase 4 provenance:** every AI output and pipeline-derived asset embeds the
IPTC Photo Metadata Standard 2025.1 AI fields
(`DigitalSourceType`, `AISystemUsed`, `AISystemVersionUsed`,
`AIPromptWriterName`) as XMP into JPEG (APP1), PNG (iTXt), and WebP (RIFF
`XMP ` chunk). `AIPromptInformation` is deliberately omitted — prompts are
stored only as hashes. Best-effort C2PA signing is available by setting
`c2pa_sign_url` (https) in `nvoos_media_studio_settings`; without a signing
service the assets carry the IPTC fields + the D-1 visible-disclosure path.
Note that XMP injection invalidates any existing C2PA signature, so signing
runs after the XMP write.

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

