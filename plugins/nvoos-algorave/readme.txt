=== NV oOS Algorave ===

Contributors: nvdigitalsolutions, vsamtani
Tags: algorave, live coding, music, strudel, tone.js, midi, audio visualizer, web audio
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: AGPL-3.0-or-later
License URI: https://www.gnu.org/licenses/agpl-3.0.html

Live coding music studio for WordPress. Strudel and Tone.js audio engines, a pattern library, MIDI export and a real-time audio visualizer. No API keys required.

== Description ==

NV oOS Algorave turns any WordPress page into an algorave-style live coding stage. Drop the `[algorave_live_coder]` shortcode on a page and visitors with author access can type TidalCycles mini-notation, press Ctrl+Enter, and hear it immediately — synthesized in the browser.

**No API keys. No external services.** Audio is synthesized entirely in the visitor's browser. Patterns are saved as WordPress posts and served from your own site.

### What You Get

= Browser-Based Live Coder =
A code editor with presets (Techno, House, Ambient, DnB, Minimal, Trap, Lo-Fi, Dub, Dubstep, Trance, Synthwave), sample bank selection, BPM control, and Ctrl+Enter playback.

= Dual Audio Engines =
* **Strudel** (bundled) — full TidalCycles mini-notation support: `* / ~ [] <> , ? (k,n) :n`, effects chains, Euclidean rhythms, and 80+ sample banks.
* **Tone.js** (optional) — raw JavaScript synthesis for advanced users. Disabled by default for safety; site operators opt in with the `NVOOS_ALGORAVE_ALLOW_TONEJS_EVAL` constant.

= Pattern Library =
Patterns are a custom post type (`algorave_pattern`) with a genre taxonomy, browsable via the `[algorave_pattern_library]` shortcode and the REST API. 12 industry-standard seed patterns across 10 electronic genres are installed on activation.

= Audio Visualizer =
Eight visualization modes — waveform, spectrum, bars, circular, particles, scope, spectrogram, and Lissajous — rendered on canvas.

= Session Tracking =
Live coding sessions are tracked as a custom post type (`algorave_session`) for performance history.

= Sample Library =
Browse audio samples from the WordPress media library with category filtering.

= REST API =
Pattern CRUD and sample browsing under `/wp-json/nvoos-algorave/v1/`. Read endpoints respect the Guest Access setting; write endpoints require `edit_posts`.

### Addon Ecosystem (optional)

- **nvoos-algorave-ai** (premium) — 9 AI tools for the NV oOS chat interface: natural-language pattern generation, AI music generation via Google Lyria or Replicate, MIDI export, sample management, visualizer control, and a Strudel reference.

== Installation ==

1. Upload the `nvoos-algorave` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu
3. Add the `[algorave_live_coder]` shortcode to any page
4. (Optional) Configure under **Algorave Patterns → Settings**

== Frequently Asked Questions ==

= Do I need an API key? =

No. All audio is synthesized in the browser. The optional premium AI addon uses API keys you configure yourself.

= Who can use the live coder? =

Authors (`edit_posts`) and above. Other visitors — including guests — see a login prompt unless an administrator enables Guest Access in **Algorave Patterns → Settings**.

= Is the Tone.js engine safe? =

The Tone.js engine evaluates user-typed JavaScript with `new Function`, so it is **disabled by default**. Site operators must explicitly define `NVOOS_ALGORAVE_ALLOW_TONEJS_EVAL` in `wp-config.php` to enable it, and even then it only runs for logged-in users with `edit_posts`. Guests are limited to the sandboxed Strudel engine.

= Does the plugin connect to external services? =

No. The bundled Strudel library runs locally in the browser. Sample maps and all audio processing are local.

= What happens if I deactivate? =

Patterns, sessions, and settings stay intact — reactivate at any time.

= What happens if I uninstall? =

Plugin options are removed. Your patterns and sessions (your content) are left in place unless you delete them manually.

== Privacy Notice ==

This plugin does not collect, store, or transmit any personal data. All audio synthesis and visualization happens locally in the visitor's browser. Pattern data is stored in your WordPress database.

== Third-Party Libraries ==

This plugin bundles the following open-source libraries:

* **Strudel** (@strudel/web) v1.2.5 — AGPL-3.0 — https://strudel.cc/ — served locally from `assets/js/vendor/strudel/`. Because Strudel is AGPL-3.0, the combined work is distributed under AGPL-3.0-or-later.
* **TidalCycles sample maps** — sample bank definitions from the TidalCycles ecosystem under `assets/samples/`.

Tone.js, tonal, @tonejs/midi, and webmidi are optional peer libraries: the bundled scripts use them only when your page already provides them.

== Changelog ==

= 1.0.0 — 2026-08-27 =
* Initial standalone release, split from the NV oOS Algorave addon
* Live coder + pattern library shortcodes with zero external dependencies
* Bundled Strudel engine, Tone.js opt-in eval engine
* Pattern and session custom post types with genre taxonomy
* REST API (`/wp-json/nvoos-algorave/v1/`)
* 8-mode audio visualizer
* Seed patterns across 10 electronic music genres
* Guest Access controls with defense-in-depth eval gating
* WordPress 6.0+, PHP 7.4+, AGPL-3.0-or-later
