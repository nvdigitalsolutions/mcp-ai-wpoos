# NV oOS Algorave — AI

**Premium AI addon for NV oOS Algorave.**

Adds 9 AI tools to the NV oOS chat interface so users can describe music in natural language and get playable Strudel/Tone.js code, AI-generated tracks, MIDI exports, and live performance control — without leaving the chat.

## Requirements

- [NV oOS Algorave](https://github.com/nvdigitalsolutions/nvoos-algorave) (active and enabled)
- NV oOS base plugin (provides the tool registry and chat interface)
- WordPress 6.0+, PHP 7.4+

## Features

- **Natural-language pattern generation** — "a techno beat at 130bpm with a rolling bassline" becomes executable Strudel code
- **Pattern modification** — change tempo, key, and effects of existing patterns
- **AI music generation** — full audio tracks via Google Lyria or Replicate
- **Play control** — play, stop, pause, set BPM from the chat
- **MIDI export** — server-side `.mid` file generation from note data
- **MIDI output** — WebMIDI device routing and `.midi()` code generation
- **Sample management** — browse and search the WordPress media library
- **Visualizer control** — switch modes, colors, and fullscreen from chat
- **Strudel reference** — on-demand mini-notation, effects, transforms, sample banks, and synthesizers reference

## Installation

1. Install and activate **NV oOS Algorave**
2. Upload the `nvoos-algorave-ai` folder to `/wp-content/plugins/`
3. Activate the plugin through the WordPress admin
4. (Optional) Configure **Algorave Patterns → Settings → AI Music Generation** with a Lyria or Replicate API key

## Tools

| Tool | Description |
|------|-------------|
| `algorave_generate_pattern` | Generate Strudel/Tone.js code from natural language |
| `algorave_modify_pattern` | Modify tempo, key, effects of existing patterns |
| `algorave_play_control` | Play, stop, pause, record, set BPM |
| `algorave_export_midi` | Export pattern note data as a `.mid` file |
| `algorave_manage_samples` | Browse/search audio samples in the media library |
| `algorave_generate_music_ai` | Generate full tracks via Google Lyria or Replicate |
| `algorave_visualizer` | Control visualization mode, colors, fullscreen |
| `algorave_strudel_reference` | Mini-notation, effects, and transforms reference |
| `algorave_midi_output` | WebMIDI routing and `.midi()` code generation |

## Architecture

The addon is split along the same lines as the standalone plugin:

- **Tools** (`includes/tools/`) — the 9 tool classes, registered with the NV oOS tool registry via `wp_mcp_ai_register_tools` and the Pro-style lazy hook `wp_mcp_ai_load_pro_tools`. Tool files are loaded lazily so the addon never touches the base plugin's interfaces unless the base plugin is active.
- **Settings** (`class-nvoos-algorave-ai.php`) — renders the AI Music Generation section on the standalone plugin's settings page and extends its `nvoos_algorave/default_settings` / `nvoos_algorave/sanitize_settings` filters.

## License

**Proprietary.** Distributed as a premium companion to the AGPL-3.0 licensed NV oOS Algorave plugin. See the license terms at <https://nvdigitalsolutions.com/license>.

## Credits

- **[NV oOS Algorave](https://github.com/nvdigitalsolutions/nvoos-algorave)** — the standalone core this addon extends
- **[Google Lyria](https://deepmind.google/technologies/lyria/)** and **[Replicate](https://replicate.com/)** — optional AI music generation providers
