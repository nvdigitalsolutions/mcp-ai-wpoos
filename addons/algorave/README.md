# NV oOS Algorave Addon

**Algorave live coding music extension for the Open Operator System (NV oOS).**

Transform natural language into live coded music through the oOS AI chat interface. This addon brings algorave-style live coding to WordPress with browser-based audio synthesis, AI-powered pattern generation, MIDI export, and real-time audio visualization.

## Features

- **AI Pattern Generation** — Describe the music you want ("a techno beat at 130bpm with a rolling bassline") and the AI generates executable Strudel/Tone.js code
- **Live Coder Interface** — Browser-based code editor with Ctrl+Enter execution, syntax highlighting-ready, and real-time audio playback
- **Dual Engine Support** — Strudel (TidalCycles mini-notation) via CDN and Tone.js for custom synthesis
- **Audio Visualizer** — Canvas-based waveform, spectrum, bars, circular, and particle visualizations
- **MIDI Export** — Server-side MIDI file generation from pattern note data
- **Sample Library** — Browse and manage audio samples via the WordPress media library
- **AI Music Generation** — Full track generation via Google Lyria or Replicate APIs
- **Pattern Library** — Save, browse, and share patterns as a Custom Post Type
- **Session Tracking** — Track live coding sessions for performance history

## Requirements

- WordPress 6.0+
- PHP 7.4+
- NV oOS base plugin (active)

## Installation

1. Upload the `nvoos-algorave` folder to `/wp-content/plugins/`
2. Activate the plugin through the WordPress admin
3. Navigate to **Algorave Patterns → Settings** to configure

## Shortcodes

### `[algorave_live_coder]`

Renders the live coding editor with audio playback and visualization.

| Attribute    | Default   | Description                |
|-------------|-----------|----------------------------|
| `bpm`       | `120`     | Initial BPM                |
| `scale`     | `C minor` | Initial musical scale      |
| `visualizer`| `true`    | Show audio visualizer      |

**Access control.** Authors (`edit_posts`) and above always see the live coder. Other users — including unauthenticated guests — see a small "log in to start playing" prompt unless an administrator enables **Algorave Patterns → Settings → Guest Access**. For safety the Tone.js raw-`eval` engine stays disabled for guests even when `WP_MCP_AI_ALLOW_TONEJS_EVAL` is defined; guests are limited to the sandboxed Strudel engine.

### `[algorave_pattern_library]`

Renders a browsable grid of saved patterns.

| Attribute  | Default | Description              |
|-----------|---------|--------------------------|
| `per_page`| `12`    | Patterns per page        |
| `genre`   | (all)   | Filter by genre slug     |

## AI Tools (Chat Interface)

These tools are registered with the oOS tool registry and callable from the chat:

| Tool | Description |
|------|-------------|
| `algorave_generate_pattern` | Generate Strudel/Tone.js code from natural language |
| `algorave_modify_pattern` | Modify tempo, key, effects of existing patterns |
| `algorave_play_control` | Play, stop, pause, record, set BPM |
| `algorave_export_midi` | Export pattern note data as a .mid file |
| `algorave_manage_samples` | Browse/search audio samples in the media library |
| `algorave_generate_music_ai` | Generate full tracks via Google Lyria or Replicate |
| `algorave_visualizer` | Control visualization mode, colors, fullscreen |

## REST API

All endpoints under `wp-json/nvoos-algorave/v1/`:

- `GET /patterns` — List patterns (paginated, filterable by genre)
- `POST /patterns` — Create a new pattern
- `GET /patterns/{id}` — Get a single pattern
- `GET /samples` — Browse audio samples

## Dependencies

### npm (browser-side)

| Package | Version | License | Purpose |
|---------|---------|---------|---------|
| `tone` | ^15.0.4 | MIT | Web Audio synthesis framework |
| `@tonejs/midi` | ^2.0.28 | MIT | MIDI file parsing/creation |
| `tonal` | ^6.3.0 | MIT | Music theory (scales, chords) |
| `webmidi` | ^3.1.11 | Apache-2.0 | WebMIDI API wrapper |

### CDN (loaded at runtime)

| Package | Version | License | Purpose |
|---------|---------|---------|---------|
| `@strudel/web` | 1.2.5 | AGPL-3.0 | TidalCycles live coding engine |

> **Note:** Strudel is bundled locally in `assets/js/vendor/strudel/` for reliability. The unmodified AGPL-3.0 source is included in the distribution.

## License

**AGPL-3.0-or-later.**

This addon is licensed as a whole under the **GNU Affero General Public License v3.0 or later** because it bundles `@strudel/web` 1.2.5 (AGPL-3.0) under `assets/js/vendor/strudel/`. Distribution of the combined work requires AGPL-3.0 compliance, including the network-use source-availability clause (§13).

The full GPL-3 grant is in the repository-root [LICENSE](../../LICENSE); AGPL-3 adds §13 on top — see <https://www.gnu.org/licenses/agpl-3.0.html>.

## Credits

- **[Tone.js](https://tonejs.github.io/)** — MIT licensed Web Audio framework
- **[Strudel](https://strudel.cc/)** — AGPL-3.0 licensed live coding environment
- **[TidalCycles](https://tidalcycles.org/)** — Inspiration for pattern-based live coding
