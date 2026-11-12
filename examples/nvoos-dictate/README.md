# NV oOS Cognition

Local-first push-to-talk dictation and meeting transcription for Windows —
the NV oOS ecosystem's desktop companion (Resonant-style UX, your own AI
backend). Hold a hotkey, speak, release: speech recognition and the
deterministic cleanup pipeline run on **your** machine, and the cleaned text
pastes into whatever app has focus.

<img width="75%" height="75%" alt="Screenshot 2026-11-09 030916" src="https://github.com/user-attachments/assets/4dab5054-4e06-4fec-97af-971c74ff5733" />

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
  number normalization ("five two nine" → 529; ambiguous prose such as
  "one or two" is left alone), custom vocabulary, per-app profiles (chat drops
  the trailing period; code preserves identifiers). Golden-tested, no LLM
  required.
- **Optional AI polish** — Stage-2 pass via your NV oOS WordPress assistant
  (transcript text only, assistant credential in the OS keychain) or any
  OpenAI-compatible endpoint. A provider failure never blocks the paste.
- **Live camera preview + object detection** (feature `camera`/`vision`) — a
  local webcam preview with on-device YOLO object detection and stable
  per-object tracking (SORT + click-to-pin). An opt-in **label-polish layer**
  relabels detection boxes with a local vision-language model (LM Studio /
  Ollama serving Qwen2.5-VL) for accurate names instead of generic COCO labels.
  Pixels never leave the machine; no facial recognition or identity.
- **Screen OCR** (feature `snippet`, Proposal 100) — press **Win+Shift+T**
  (macOS: ⌘⇧T) anywhere to freeze the screen, drag a region over it, and
  the text in that region lands on the clipboard (optionally auto-pasted),
  entirely on-device. Native engines need **no model download**:
  Windows OCR (WinRT, the PowerToys Text Extractor engine) on Windows,
  Apple Vision (Live Text) via the bundled sidecar on macOS. An opt-in
  RapidOCR (PP-OCR) ONNX backend (`snippet-onnx`) adds multilingual packs.
  The frozen frame lives in memory only — never written to disk.
- **Document OCR** (feature `document`, Proposals 109/111) — drop a PDF,
  multi-page TIFF or image on Home → Document OCR and get text back with
  page progress and cancel. A hybrid router uses the PDF text layer when
  it is good and falls back to on-device OCR for scans; exports go to
  `.txt`, `.md`, hOCR or ALTO (word boxes) and — with `snippet-pdf` — a
  searchable PDF. An optional local-VLM polish pass (Qwen2.5-VL via LM
  Studio / Ollama) and a DeepSeek-OCR model tier lift hard pages. PDFium
  (BSD-3) loads from a pinned binary beside the executable; documents
  never leave the machine.
- **Ask your words** (feature `memory`, Proposal 127) — opt-in local
  index over dictations, meetings, screen grabs and document pages
  (FTS5 + sqlite-vec hybrid retrieval, bundled Granite embedding model
  downloaded on first use). Ask a question on Home and matching passages
  come back cited; purge/reindex require typed confirmation and are
  audited.
- **Meeting transcription** — explicit record (mic + system audio on
  Windows via WASAPI loopback), local transcription, audio deleted after
  unless you opt into retention.
- **Local history** — searchable SQLite archive with per-utterance `e2e_ms`
  latency, JSON export.
- **Diagnostics page** — live microphone level meter (which device the app
  actually hears, at what level), low-level hook health, engine/model
  install state, credential presence, and the measured RMS/peak/gate of the
  most recent dictation attempt — plus a one-click JSON report for support.
- **Local MCP server** (feature `mcp`) — stdio for desktop AI clients;
  streamable HTTP planned for the plugin's Remote Sites (see
  `docs/remote-sites-recipe.md`).

## Build & run

Prerequisites: Rust ≥ 1.90, Node 20+, and for Windows builds the MSVC
toolchain (Visual Studio Build Tools).

```bash
npm install
node scripts/gen-icons.mjs      # bootstrap placeholders only — committed brand icons are authoritative
npm run tauri dev               # dev app (hot reload)

# Release build (NSIS + MSI)
npm run tauri build
```

### Engine features

All three native engines can be compiled into ONE binary:
`cargo build --features parakeet,silero-vad,whisper` (whisper needs CMake +
libclang on PATH; see the skill). The repo vendors a one-line-patched
`whisper-rs-sys` (vendor/whisper-rs-sys) that compiles whisper.cpp with the
static CRT (/MT) so it links alongside sherpa-onnx's /MT prebuilt libs.


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
cargo build --release --features camera             # live webcam preview (nokhwa)
cargo build --release --features vision             # object detection + tracking (implies camera)
cargo build --release --features snippet            # screen OCR (native engines, zero-download)
cargo build --release --features snippet-onnx       # + RapidOCR (PP-OCR) ONNX packs (implies snippet)
cargo build --release --features document           # + PDF / multi-page TIFF / image OCR (implies snippet)
cargo build --release --features snippet-pdf        # + searchable-PDF export (implies snippet)
cargo build --release --features memory             # + local hybrid memory index and cited answers
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
- **document** — PDF + multi-page TIFF + raster-image OCR. PDFium is
  runtime-loaded from `pdfium.dll` / `libpdfium` beside the executable;
  Windows builds fetch it with `scripts/fetch-pdfium.ps1` (pinned sha256)
  — the release workflow runs it before `tauri build`, and the DLL is a
  bundle resource (gitignored, never committed).
- **memory** — local hybrid retrieval (FTS5 + vec0) with the Granite
  encoder; enabling it in Settings → Memory downloads the model once
  (~98 MB) and indexes captures as they land.

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

Exposes `dictate`, `get_dictation_history`, `get_dictionary` and
`transcribe_meeting`; `dictate` asks for the desktop app (PTT needs a
session).

## Tests

```bash
cargo test                          # golden cleanup, storage, nvoos validation
cargo test --features mcp           # + MCP tool handler tests
cargo test --features snippet       # + snippet capture/OCR/selection tests
cargo test --features snippet-onnx  # + RapidOCR decode tests
cargo test --features document      # + document PDF/TIFF fixtures and exports
cargo test --features memory        # + memory schema/chunker/controller tests
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

### Microphone and privacy

The capture stream is **resident** by default: it opens when the app starts and
runs until the app quits, feeding a rolling in-memory buffer so push-to-talk
can keep the first syllable. The consequence is that the OS
microphone-in-use indicator stays lit for as long as the app is running. That
is real, and it is why `audio.capture_mode` exists.

**On-demand** pauses the stream when idle and resumes it on key-down (a
`cpal` pause/play on the same stream object). The indicator clears while
paused and the audio endpoint can idle. The costs are the pre-roll window (you
can lose the first syllable if you speak the instant you press) and a measured
resume latency; the dictation window only opens once the first callback has
actually arrived. Pre-pause audio is discarded on pause, so an old buffer can
never leak into a new utterance.

Set `audio.capture_mode` to `resident` (default) or `on_demand` in
`config.json`. Measured on the reference machine (25 s windows, 48 kHz stereo
device, `cargo run --example capture_probe -- 25 10`): resident capture
delivered ~100 callbacks/s and used 93.8 ms of process CPU over 25 s (0.38% of
one core); paused delivered **zero** callbacks and 0.0 ms; resume latency was
2 ms at both p50 and p95. That is CPU, not wall-plug power — battery figures
still need an external meter — but on this hardware on-demand is close to free
except for the lost pre-roll, so the choice is a trade-off rather than a cost.
Resident stays the default pending that decision.
`audio.linger_ms` keeps the stream running briefly after a dictation so a quick
follow-up does not pay the resume cost again. Full method and caveats:
[`docs/capture-residency-measurements.md`](docs/capture-residency-measurements.md).

### Clipboard behaviour

Paste uses the clipboard plus a real Ctrl+V (a synthesized unicode paste is
ignored by Chromium and WebView2). Before writing the transcript the app
snapshots **every format the clipboard holds** as raw bytes — text, file lists,
bitmaps, HTML, RTF and private application formats — and replays them after the
paste. The target app gets `paste.restore_delay_ms` (default 250 ms) to read
the clipboard before it is put back.

A few details worth knowing:

- The transcript write is marked with Microsoft's documented
  `ExcludeClipboardContentFromMonitorProcessing` and
  `CanIncludeInClipboardHistory = 0` formats, so dictation does not fill your
  `Win+V` history.
- The restore is skipped if something else wrote to the clipboard during the
  paste (`GetClipboardSequenceNumber`), so copying something mid-paste does not
  get overwritten.
- Formats whose payload is a GDI handle rather than a global memory block
  (`CF_BITMAP`, `CF_PALETTE`, metafiles) cannot be copied as bytes. They are
  counted and shown as "not copyable" in Diagnostics (`partial_formats`) —
  the rest of the clipboard is still restored.
- Snapshots are capped at 64 MiB total on Windows (1 MiB text / 32 MiB image on
  the other platforms). Above the cap the snapshot is reported as oversized
  and nothing is restored, rather than copying an unbounded amount into
  memory.
- Rich formats are preserved on Windows. macOS and Linux still restore text and
  images only (`arboard`), which is a documented limitation.

`paste.restore_clipboard = false` leaves the transcript on the clipboard
instead of restoring the previous contents.

### Text normalisation

Stage-1 cleanup converts spoken numbers to written ones in confidence tiers,
following the published inverse-text-normalization practice that precision
beats recall: a wrong digit substitution is silent, so ambiguous forms are
left in spoken form.

| Tier | Rule | Example | Default |
|---|---|---|---|
| T1 | Digit runs of 3+ single digits | `five two nine` → `529` | always on |
| T2 | Explicit unit present | `twenty dollars` → `$20` | always on |
| T3 | Compound cardinals | `twenty three` → `23` | on |
| T4 | Bare cardinals, no cue or unit | `one or two` → `1 or 2` | **off** |

A numeric cue (`page one`, `version two`) promotes a bare cardinal. Idioms
such as "one or two", "one of", "no one", "ten to one" and "one another" are
never rewritten. Sequences that are not English numerals are left in speech:
`five thirty` (a time or a score) does not become `35`. A digit run followed by
a scale word concatenates before scaling, so `five two nine thousand` is
`529,000`. `Code` and `Terminal` skip the rule entirely, and prose categories
group thousands (`$1,000`).

Override in `config.json` under `cleanup.numbers`: `enabled` (master switch),
`bare_small` (tier 4), `in_code` (run the rule in Code/Terminal), and
`thousands_separator`. History keeps the raw ASR output alongside the cleaned
result, so a rule change is always inspectable after the fact.

## Platform notes

- **Windows (primary):** WH_KEYBOARD_LL hook + GetAsyncKeyState fallback
  (Chromium-focus resilience — see `docs/ptt-matrix.md`); WASAPI loopback
  for meetings.
- **Linux:** toggle semantics via global-shortcut plugin (X11); Wayland
  global-shortcut restrictions documented; meeting system audio not yet
  supported.
- **macOS:** implemented (P5 port — see `docs/macos-port-plan.md` and
  ADR 0004). Supported on **Apple Silicon and Intel** Macs, macOS 13
  (Ventura) or newer — Sequoia 15.x included. One **universal** binary
  serves both CPUs (plan M7.5). Default PTT is the
  permission-free toggle combo
  `Command+Option+Space`; Right-Option hold is opt-in (CGEventTap, needs
  Accessibility + Input Monitoring). Meetings capture system audio through
  the bundled ScreenCaptureKit sidecar on macOS 14.2+ (mic-only fallback).

### macOS build & run

Prerequisites: Xcode Command Line Tools (`xcode-select --install`), Rust ≥
1.90, Node 20+. The app needs **no special permissions for dictation** in
toggle mode; hold-PTT and pasting need the grants in Settings → Permissions.

```bash
npm install
npm run build:helper            # builds the Swift sidecar (mac-helper)
npm run tauri dev               # dev app
npm run tauri build             # signed .app + .dmg (notarization needs
                                # Apple Developer credentials — see
                                # docs/macos-port-plan.md §12)
```

For unsigned local builds, clear Gatekeeper quarantine before first launch:
xattr -dr com.apple.quarantine "NV oOS Cognition.app". Keychain entries
created by unsigned dev builds can re-prompt (the ACL binds to the code
signature).

Local builds compile for your Mac's own CPU (Intel or Apple Silicon) by
default. To produce ONE app that runs on both:
`rustup target add universal-apple-darwin`, then
`npm run tauri build -- --target universal-apple-darwin` — Rust lipo's the
binary and `build:helper` lipo's the Swift sidecar
(`NVOOS_HELPER_ARCH=universal`). An app built for the wrong CPU arch fails
to launch with "this application is not supported on this Mac".

### Testing the macOS build without a Mac

The GitHub Actions runner is the remote Mac:

- **Every push** (`.github/workflows/build-nvoos-dictate.yml`) runs a
  `boot-smoke` job that builds the debug **universal** `.app` (arm64 +
  x86_64 — one download runs on Apple Silicon AND Intel Macs), boots both
  slices on the runner (native + Intel slice under Rosetta 2, 30 s
  residency each), verifies the bundle with `lipo -info`, screenshots the
  desktop, and uploads the app zipped as run artifact
  `nvoos-dictate-macos-app` plus boot evidence. Release tags publish a
  single signed + notarized universal DMG.
- **Interactive sessions:** run the `NV oOS Cognition — macOS interactive
  session` workflow (manual `workflow_dispatch`) to build the app, run
  `scripts/macos-matrix-remote.sh` (the automatable subset of
  `docs/ptt-matrix-macos.md`), and hold the runner open for SSH (tmate).
- **TCC grants** (mic / Screen Recording / Accessibility) need a human at
  the GUI: use a Namespace macOS runner (VNC) or any physical/rented Mac.

Signed + notarized DMGs come from tagging `nvoos-dictate-v*` (publish
workflow + Apple Developer secrets — see `docs/macos-port-plan.md` §12).

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

## Releases

Tag `nvoos-dictate-v<version>` from `alpha-working` to cut a release
(`publish-nvoos-dictate.yml`): Windows NSIS + MSI, a universal macOS DMG
and the updater's `latest.json`, attached to a **draft** GitHub release.
Publish the draft, then dispatch **Mirror NV oOS Cognition Release** with
that tag to copy the installers into
`nvdigitalsolutions/nvoos-cognition`. The app keeps auto-updating from
the monorepo feed — the mirror copy is for standalone downloads. See
[`docs/project/releases/MIRROR_RELEASES.md`](../../docs/project/releases/MIRROR_RELEASES.md).

Bump the version in `package.json`, `package-lock.json`,
`src-tauri/tauri.conf.json` and `src-tauri/Cargo.toml` (plus this crate's
entry in `Cargo.lock`) before tagging.

## License

Proprietary — see [`LICENSE.txt`](./LICENSE.txt) and
[`NOTICE.md`](./NOTICE.md). The GPL-3.0 license at the repository root
does **not** apply to this directory. Model weights keep their own
licenses (Parakeet CC-BY-4.0, Whisper MIT, Moonshine MIT, Granite
embedding Apache-2.0, PDFium BSD-3) and are downloaded at first use —
never bundled — except PDFium, which is fetched as a pinned build
resource (see `NOTICE.md`).
