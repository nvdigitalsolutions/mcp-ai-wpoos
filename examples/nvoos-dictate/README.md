# NV oOS Dictation

Local-first push-to-talk dictation and meeting transcription for Windows —
the NV oOS ecosystem's desktop companion (Resonant-style UX, your own AI
backend). Hold a hotkey, speak, release: speech recognition and the
deterministic cleanup pipeline run on **your** machine, and the cleaned text
pastes into whatever app has focus.

Windows first; Linux and macOS follow (see
`docs/project/proposals/067-tauri-dictation-companion-app-proposal.md` in the
repo root for the full design).

## Features

- **Push-to-talk dictation** — hold Right Alt (configurable), speak, release;
  pre-roll ring buffer keeps the first syllable; double-tap = hands-free
  toggle; Esc cancels; hold Shift on release = raw passthrough (code,
  spellings).
- **Local speech recognition** — Parakeet TDT (fastest English), Whisper
  (multilingual), Moonshine v2 (streaming, experimental). Models download at
  first use with explicit size notices; audio never leaves the machine for
  plain dictation. A remote fallback ("Embedded") transcribes on your own
  NV oOS site when no local engine is available.
- **Deterministic cleanup** — punctuation, capitalization, filler removal,
  number normalization ("five two nine" → 529), custom vocabulary, per-app
  profiles (chat drops the trailing period; code preserves identifiers).
  Golden-tested, no LLM required.
- **Optional AI polish** — Stage-2 pass via your NV oOS WordPress assistant
  (transcript text only, assistant credential in the OS keychain) or any
  OpenAI-compatible endpoint. A provider failure never blocks the paste.
- **Meeting transcription** — explicit record (mic + system audio on
  Windows via WASAPI loopback), local transcription, audio deleted after
  unless you opt into retention.
- **Local history** — searchable SQLite archive with per-utterance `e2e_ms`
  latency, JSON export.
- **Local MCP server** (feature `mcp`) — stdio for desktop AI clients;
  streamable HTTP planned for the plugin's Remote Sites (see
  `docs/remote-sites-recipe.md`).

## Build & run

Prerequisites: Rust ≥ 1.90, Node 20+, and for Windows builds the MSVC
toolchain (Visual Studio Build Tools).

```bash
npm install
node scripts/gen-icons.mjs      # regenerates placeholder icons
npm run tauri dev               # dev app (hot reload)

# Release build (NSIS + MSI)
npm run tauri build
```

### Engine features

Engines are **local-first** (ADR 0003): mock → parakeet → whisper →
moonshine, with the Embedded remote STT as the fallback.

The default build ships the mock engine (full pipeline, no models) plus the
`embedded` remote engine (no feature flag — it only needs the NV oOS site
URL and a WP application password). Native engines are cargo features —
build inside `src-tauri`:

```bash
cargo build --release --features parakeet          # Parakeet TDT via official sherpa-onnx
cargo build --release --features whisper           # whisper.cpp via whisper-rs (needs CMake + libclang)
cargo build --release --features moonshine          # ort runtime (documented spike)
cargo build --release --features silero-vad         # neural Silero VAD (model: catalogue entry "silero_vad")
cargo build --release --features mcp                # local MCP stdio server (nvoos-dictate --mcp)
```

- **parakeet** — API verified against sherpa-onnx 1.13.8 (the crate's own
  doc example). Models download from Settings → Engine
  (`sherpa-onnx-nemo-parakeet-tdt-0.6b-v2-int8`, ~600 MB).
- **whisper** — whisper-rs 0.16 API verified and **built on Windows with
  LLVM 23.1.3**: needs **CMake on PATH** (VS-bundled CMake works) and
  **libclang** (`LIBCLANG_PATH` → `C:\Program Files\LLVM\bin`;
  `winget install LLVM.LLVM`). `WHISPER_DONT_GENERATE_BINDINGS=1` only works
  on Linux (the checked-in bindings are glibc-specific).
- **embedded** (always available) — sends 16-bit PCM WAV to
  `POST /wp-json/mcp-ai/v1/embedded/transcribe` on your NV oOS site
  (Embedded addon → Gemma 4 audio endpoint). Auth: a **WP application
  password** (wp-admin → Users → Profile → Application Passwords) stored in
  the OS keychain; assistant credentials work once the addon's
  `permission_callback` accepts them (small addon PR). Audio is sent only to
  the configured site; ≤ 10 MB per request.

Model downloads (same registry path the GUI uses):

```bash
cargo run --features parakeet --example download_model -- parakeet sherpa-onnx-nemo-parakeet-tdt-0.6b-v2-int8
cargo run --example download_model -- whisper tiny.en
```

Bench/verify an engine against a speech file (WER + e2e_ms + RTF, with a
`.txt` reference sidecar):

```bash
cargo run --features parakeet --example transcribe_file -- speech.wav --engine parakeet --model sherpa-onnx-nemo-parakeet-tdt-0.6b-v2-int8
```

Reference-PC numbers (debug build, 4.27 s TTS sample): parakeet 1187 ms
(RTF 0.28), whisper tiny.en 1095 ms (WER 7.7%).

### Headless MCP stdio server

```bash
cargo run --release --features mcp -- --mcp
```

Exposes `get_dictation_history`, `get_dictionary`, `transcribe_meeting`;
`dictate` asks for the desktop app (PTT needs a session).

## Tests

```bash
cargo test                          # golden cleanup, storage, nvoos validation
cargo test --features mcp           # + MCP tool handler tests
cargo run --example transcribe_file -- path/to/audio.m4a --engine whisper
npm run build                       # webview typecheck + build
npm run test:ui                     # Playwright smoke (dev server on :1420)
```

## Privacy & security

- Push-to-talk / explicit record only — no wake words, no always-on
  listening. The OS microphone indicator is the only "recording" UI outside
  the app's own overlay.
- Dictation audio lives in a short in-memory ring window; nothing is written
  to disk. Meeting audio is deleted after transcription unless retention is
  enabled.
- Secrets (NV oOS assistant credential, OpenAI-compatible key) live in the
  OS keychain — never in `config.json` or logs.
- Webview capabilities are window-scoped (overlay gets core IPC only); a
  strict CSP is set; all network I/O happens in the Rust core against
  allowlisted origins (https only, plus localhost for dev).
- Model downloads are SHA-256-verified where upstream publishes hashes and
  logged for pinning where it doesn't.
- Telemetry: none, permanently.

## Platform notes

- **Windows (primary):** WH_KEYBOARD_LL hook + GetAsyncKeyState fallback
  (Chromium-focus resilience — see `docs/ptt-matrix.md`); WASAPI loopback
  for meetings.
- **Linux:** toggle semantics via global-shortcut plugin (X11); Wayland
  global-shortcut restrictions documented; meeting system audio not yet
  supported.
- **macOS:** planned (Swift system-audio sidecar, Keychain, signing).

## NV oOS plugin integration

`Settings → NV oOS`: enter your site URL and an assistant credential
(`cred_XXXXX.SECRET`, created in the plugin). The app then:

- polishes dictation via `POST {site}/wp-json/mcp-ai/v1/chat` (bearer auth;
  definitive 401 on bad credentials),
- optionally archives transcripts via `POST …/chat-transcripts`,
- and (phase P3) exposes its tools back to plugin assistants over MCP —
  see `docs/remote-sites-recipe.md`.

Auth contract mirrors the repo's content-graph SPA (see
`.agents/skills/mcp-ai-wpoos-content-graph-spa/SKILL.md`).

## License

MIT (app code). Model weights keep their own licenses (Parakeet CC-BY-4.0,
Whisper MIT, Moonshine MIT) and are downloaded at first use — never bundled.
