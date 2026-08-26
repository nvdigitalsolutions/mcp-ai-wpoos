# NV oOS Algorave

**Live coding music studio for WordPress. No API keys required.**

Turn any WordPress page into an algorave-style live coding stage. Drop the `[algorave_live_coder]` shortcode on a page, type TidalCycles mini-notation, press Ctrl+Enter, and hear it immediately — synthesized in the visitor's browser.

## Features

- **Live Coder Interface** — Code editor with Ctrl+Enter playback, 11 genre presets, sample bank selection, and BPM control
- **Dual Engine Support** — Strudel (bundled, TidalCycles mini-notation) and Tone.js (optional, opt-in raw synthesis)
- **Pattern Library** — `algorave_pattern` custom post type with genre taxonomy and `[algorave_pattern_library]` shortcode
- **Audio Visualizer** — 8 canvas modes: waveform, spectrum, bars, circular, particles, scope, spectrogram, Lissajous
- **Session Tracking** — `algorave_session` custom post type for performance history
- **Sample Library** — Browse audio samples from the WordPress media library
- **REST API** — Pattern CRUD and sample browsing under `/wp-json/nvoos-algorave/v1/`
- **Seed Patterns** — 12 industry-standard patterns across 10 electronic genres installed on activation

## Requirements

- WordPress 6.0+
- PHP 7.4+

## Installation

1. Upload the `nvoos-algorave` folder to `/wp-content/plugins/`
2. Activate the plugin through the WordPress admin
3. Add `[algorave_live_coder]` to any page
4. Configure under **Algorave Patterns → Settings**

## Shortcodes

### `[algorave_live_coder]`

Renders the live coding editor with audio playback and visualization.

| Attribute    | Default   | Description                |
|--------------|-----------|----------------------------|
| `bpm`        | `120`     | Initial BPM                |
| `scale`      | `C minor` | Initial musical scale      |
| `visualizer` | `true`    | Show audio visualizer      |

**Access control.** Authors (`edit_posts`) and above always see the live coder. Other users — including unauthenticated guests — see a "log in to start playing" prompt unless an administrator enables **Algorave Patterns → Settings → Guest Access**. The Tone.js raw-`eval` engine stays disabled for everyone until the site operator defines `NVOOS_ALGORAVE_ALLOW_TONEJS_EVAL` in `wp-config.php`; even then it only runs for logged-in users with `edit_posts`. Guests are limited to the sandboxed Strudel engine.

### `[algorave_pattern_library]`

Renders a browsable grid of saved patterns.

| Attribute  | Default | Description              |
|------------|---------|--------------------------|
| `per_page` | `12`    | Patterns per page        |
| `genre`    | (all)   | Filter by genre slug     |

## REST API

All endpoints under `wp-json/nvoos-algorave/v1/`:

- `GET /patterns` — List patterns (paginated, filterable by genre)
- `POST /patterns` — Create a new pattern (`edit_posts`)
- `GET /patterns/{id}` — Get a single pattern
- `GET /samples` — Browse audio samples

## Extending the plugin

Consumer addons can hook into:

- `nvoos_algorave/default_settings` — extend settings defaults
- `nvoos_algorave/sanitize_settings` — persist additional settings keys
- `nvoos_algorave_is_enabled()` / `nvoos_algorave_get_settings()` — public API

The premium companion **nvoos-algorave-ai** uses these hooks and registers 9 AI tools with the NV oOS chat interface.

## Dependencies

### Bundled

| Package | Version | License | Purpose |
|---------|---------|---------|---------|
| `@strudel/web` | 1.2.5 | AGPL-3.0 | TidalCycles live coding engine |

### Optional (used only when already present on the page)

| Package | Version | License | Purpose |
|---------|---------|---------|---------|
| `tone` | ^15.0.4 | MIT | Web Audio synthesis framework |
| `@tonejs/midi` | ^2.0.28 | MIT | MIDI file parsing/creation |
| `tonal` | ^6.3.0 | MIT | Music theory (scales, chords) |
| `webmidi` | ^3.1.11 | Apache-2.0 | WebMIDI API wrapper |

## License

**AGPL-3.0-or-later.**

This plugin is licensed as a whole under the **GNU Affero General Public License v3.0 or later** because it bundles `@strudel/web` 1.2.5 (AGPL-3.0) under `assets/js/vendor/strudel/`. Distribution of the combined work requires AGPL-3.0 compliance, including the network-use source-availability clause (§13). See <https://www.gnu.org/licenses/agpl-3.0.html>.

## Credits

- **[Strudel](https://strudel.cc/)** — AGPL-3.0 licensed live coding environment
- **[Tone.js](https://tonejs.github.io/)** — MIT licensed Web Audio framework
- **[TidalCycles](https://tidalcycles.org/)** — Inspiration for pattern-based live coding
