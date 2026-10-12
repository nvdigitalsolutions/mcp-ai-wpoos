---
type: Skill
name: mcp-ai-wpoos-dictation-app
description: Operational guide for the NV oOS Dictation desktop companion app (Tauri v2 + Rust, examples/nvoos-dictate) — build/run on Windows/MSVC, the local-first engine track (parakeet/whisper/moonshine/silero-vad, all API-verified against pinned crate versions), the embedded remote-STT fallback against the Embedded addon's transcribe route, model registry + verification harnesses, the plugins.updater boot-crash config rule, and the full test/clippy gate matrix. Use when building, extending, or debugging the dictation app, adding an engine or model, touching the cleanup pipeline golden tests, or continuing the P2/P4/P5/distribution follow-up work.
license: Proprietary. See LICENSE.txt
metadata:
  app: nvoos-dictate
  app-version: "0.1.0"
  proposal: "067"
  pr: "7011"
  last-updated: "2026-10-11"
---
# NV oOS Dictation App — Build, Engines, and Verification Guide

Operational guide for `examples/nvoos-dictate` (Proposal 067, PR #7011):
a local-first push-to-talk dictation + meeting transcription desktop app
with the NV oOS plugin as the optional AI-enrichment backend.

## When to use this skill

- Building/running the app on Windows, or debugging a boot failure
- Adding or swapping an STT engine, a model catalog entry, or VAD behavior
- Changing the deterministic cleanup pipeline (golden fixtures are the backstop)
- Wiring the Embedded addon remote-STT fallback or its auth
- Running the benchmark harness (`transcribe_file`) or the model downloader
- Continuing P2 (loopback + PTT matrix), P4/P5 ports, or distribution

## Layout

```
examples/nvoos-dictate/
├── src/                  # React webview (Settings/History/Meetings/Overlay)
├── src-tauri/            # the Rust crate (manifest lives HERE, not at app root)
│   ├── src/{audio,stt,cleanup,nvoos,hotkey,paste,meetings,mcp,storage}
│   ├── tests/            # integration tests (must stay under src-tauri/tests!)
│   ├── examples/         # transcribe_file.rs (WER/e2e_ms) + download_model.rs
│   └── capabilities/ + icons/
├── docs/decisions/       # ADRs 0001 (paste strategy), 0002 (MCP transport), 0003 (engines)
└── e2e/                  # Playwright (webview UI)
```

## Build & run on Windows/MSVC

- Prerequisites: Rust + MSVC (VS 2022), Node 20+, WebView2 runtime
  (Win11 ships it), tauri-cli via npm (`npm run tauri`) — no cargo install needed.
- **MSYS shells**: every cargo command needs
  `export PATH="/c/Users/rasta/.cargo/bin:$PATH"` first; tauri-cli also needs
  cargo on PATH (`npm run tauri` fails with "cargo metadata: program not found"
  otherwise).
- Dev: `npm run tauri dev` (vite :1420). Debug exe:
  `npm run tauri -- build --debug --no-bundle` → `src-tauri/target/debug/nvoos-dictate.exe`.
  **The debug exe must include the feature for the engine the settings
  configure** — the default build is mock+embedded only, and a whisper
  config on a default build boots to "engine 'whisper' is not compiled
  into this build". Build with the features you need:
  `npm run tauri -- build --debug --no-bundle --features whisper,parakeet,silero-vad`
  (whisper needs CMake + libclang on PATH — see below; whisper+parakeet
  co-link because the vendored whisper-rs-sys compiles whisper.cpp with
  the static CRT /MT, matching sherpa-onnx's prebuilt libs).
  Quick engine-load check without the GUI: build with `mcp` too and run
  `target/debug/nvoos-dictate.exe --mcp < /dev/null` — headless mode loads
  the configured engine and prints whisper.cpp's model-load log on
  success, or "engine unavailable in headless mode: …" on failure.
  **Trap:** a plain `cargo build`/`cargo run` (debug profile) compiles with
  `devUrl` (`http://localhost:1420`) baked in, so the webview loads the
  Vite dev server at runtime and shows `ERR_CONNECTION_REFUSED` in the app
  body when nothing is serving there — and it silently OVERWRITES
  `target/debug/nvoos-dictate.exe`. Only `tauri build --debug --no-bundle`
  (or `npm run tauri dev` with vite running) produces a usable GUI exe;
  after any plain-cargo headless check, rebuild via tauri before handing
  the exe back.
- `generate_context!` embeds the frontend `dist` at compile time — run
  `npm run build` before any cargo step in CI.
- Boot smoke test: `cmd //c start "" <exe>` → `tasklist //FI "IMAGENAME eq
  nvoos-dictate.exe"` after ~10 s (process alive = boot OK); kill with
  `taskkill //IM nvoos-dictate.exe //F`. A running app locks the exe — kill
  it before `cargo build`/`test` or you get "failed to remove file" errors.

## Critical config rule: `plugins.updater` block

`tauri.conf.json` MUST contain a `plugins.updater` block (endpoints +
pubkey). Without it the app panics at boot:

```
error while running NV oOS Dictation: PluginInitialization("updater",
"Error deserializing 'plugins.updater' within your Tauri configuration:
invalid type: null, expected struct Config")
```

The endpoint uses the runtime placeholder `{{current_version}}` matching the
`nvoos-dictate-v*` tag convention; `pubkey: ""` = unsigned dev builds.

## Gate matrix (all verified green on the reference PC)

```bash
cd src-tauri
cargo fmt --check
cargo clippy --all-targets -- -D warnings                          # default
cargo clippy --all-targets --features mcp -- -D warnings
cargo test            # 73 tests (45 lib + 17 golden + 6 history + 5 validation)
cargo test --features mcp       # +4 toolbox tests
cargo test --features parakeet  # 46 lib (+1 tar.bz2 extraction test)
```

Native feature compile checks: `parakeet`, `whisper`, `moonshine`,
`silero-vad`, and the combined `--features parakeet,silero-vad,moonshine`.
**whisper needs extra env** (below). CI (`build-nvoos-dictate.yml`) runs
fmt/clippy/test default + `mcp` only; the native engines are local gates.

## Engine track (ADR 0003: local-first, embedded as fallback)

Ordering: mock → parakeet → whisper → moonshine → embedded. The factory is
`stt::create_engine(&EngineConfig, &EngineResources)` — `EngineResources`
carries `models_dir` plus nvoos secrets (`nvoos_site_url`,
`nvoos_credential`, `wp_username`, `wp_app_password`) so the `embedded`
engine can build auth without touching the keychain itself. Call sites:
`state.rs ensure_engine` (fetches secrets), `main.rs` headless +
`examples/*` use `EngineResources::local_only`.

### parakeet (sherpa-onnx, feature `parakeet`) — API VERIFIED 1.13.8

Use the crate's own doc example shape (the old stub had drift everywhere):

```rust
let mut config = OfflineRecognizerConfig::default();
config.model_config.transducer = OfflineTransducerModelConfig {
    encoder: Some(".../encoder.int8.onnx".into()),
    decoder: Some(".../decoder.int8.onnx".into()),
    joiner:  Some(".../joiner.int8.onnx".into()),
};
config.model_config.tokens     = Some(".../tokens.txt".into());
config.model_config.model_type = Some("nemo_transducer".into());
config.model_config.num_threads = n as i32;
config.model_config.provider   = Some("cpu".into());
config.decoding_method         = Some("greedy_search".into());
let recognizer = OfflineRecognizer::create(&config).ok_or(...)?;  // -> Option
let stream = recognizer.create_stream();               // infallible
stream.accept_waveform(sample_rate as i32, samples);   // &self, infallible
recognizer.decode(&stream);                            // infallible
stream.get_result().map(|r| r.text)                    // -> Option
```

Model archive: `sherpa-onnx-nemo-parakeet-tdt-0.6b-v2-int8.tar.bz2` extracts
a top-level dir named after the model id — **unpack into `models_dir` (the
parent)**, not `models_dir/{id}`. Files: `encoder.int8.onnx`,
`decoder.int8.onnx`, `joiner.int8.onnx`, `tokens.txt` (v2 int8 = feature_dim
80 default; v3 uses 128). TDT limit ≈ 4–5 min → meetings must be chunked
(`meetings/chunker.rs`).

### whisper (whisper-rs 0.16, feature `whisper`) — API VERIFIED

- `full_n_segments() -> c_int` (NOT a Result); segments via
  `state.get_segment(i) -> Option<WhisperSegment>` +
  `seg.to_str() -> Result<&str>` (the old `full_get_segment_text` is gone).
- Windows build prerequisites: **CMake on PATH** (VS-bundled CMake at
  `C:\Program Files\Microsoft Visual Studio\2022\Community\Common7\IDE\CommonExtensions\Microsoft\CMake\CMake\bin`
  works) + **libclang** (`winget install LLVM.LLVM` → set
  `LIBCLANG_PATH=/c/Program Files/LLVM/bin`).
- `WHISPER_DONT_GENERATE_BINDINGS=1` is **Linux-only** — the checked-in
  bindings are glibc-specific and fail to compile on MSVC (`_IO_FILE` size
  errors). Always use real bindgen on Windows.
- The vendored `vendor/whisper-rs-sys/whisper.cpp/` tree is **trimmed** (no
  `bindings/`); its `CMakeLists.txt` has the upstream
  `configure_file(bindings/javascript/package-tmpl.json …)` line REMOVED.
  Never restore that line without restoring `bindings/javascript/` too — a
  fresh build dir (new feature-set hash, CI clean checkout, or after
  `cargo clean`) re-copies the trimmed tree into OUT_DIR and CMake configure
  fails. A stale pre-trim OUT_DIR masks the bug locally, which is why this
  only surfaces on fresh builds. If whisper-rs-sys CMake errors appear,
  `rm -rf target/{debug,release}/build/whisper-rs-sys-*` and rebuild.

### ort / moonshine / silero-vad (features `moonshine`, `silero-vad`)

- **ort pin**: `ort = "=2.0.0-rc.9"` + `ort-sys = "=2.0.0-rc.9"` (direct).
  Without the ort-sys pin, the caret range drifts to rc.13 whose bindings
  break the rc.9 high-level API (233 errors), and rc.13's `download-binaries`
  build script demands a TLS feature rc.9 doesn't expose. Add `dep:ort-sys`
  to both `moonshine` and `silero-vad` features.
- Moonshine engine is a documented spike (`Unsupported` error) — the
  streaming ONNX loop is intentionally unimplemented.
- **silero-vad-rs 0.1.2 real API**: `SileroVAD::new(path)` +
  `model.process_chunk(&arr.view(), 16000) -> Result<Array1<f32>>` with
  exactly **512-sample chunks @ 16 kHz** (32 ms). The adapter in
  `audio/vad.rs` buffers partial input and mirrors the EnergyVad hangover
  semantics. Model: `silero_vad.onnx` from sherpa-onnx releases, catalogued
  as engine `vad` (UI shows `vad` entries alongside the selected engine).

### embedded (remote fallback, always compiled — no feature flag)

Contract: `POST {site}/wp-json/mcp-ai/v1/embedded/transcribe` with
`{"audio": "<base64 16-bit PCM WAV>", "language": "en"}`; server limit
10 MB, 30 req/min, 60 s timeout (Embedded addon → Gemma 4 audio endpoint).
Auth: **WP application password → Basic** (satisfies the route's
`is_user_logged_in()`; stored in keychain via `set_wp_app_password` IPC);
assistant credential → Bearer requires the addon `permission_callback`
tweak (follow-up PR). WAV encoder: `audio/wav.rs::encode_f32_to_wav`.
Contract tests in `stt/embedded.rs` (endpoint/body/parse/auth) — no network.

## Model registry conventions

`stt/model_registry.rs`: pinned `ModelSpec` catalogue (`engine`, `id`, url,
optional sha256, `archive`, `file_name` override for non-`.bin` files).
Rules: streaming SHA-256 (never read models into memory), atomic rename,
measured-hash logging when unpinned, tar.bz2 extraction behind the
`parakeet` feature. Downloads live in
`%APPDATA%/nvoos-dictate/models/` (GUI: Settings → Engine & Models).

Two guardrails added after the "parakeet model 'base.en' not found" wedge:

- **`validate_pairing(engine, model)`** rejects catalogue-known cross-engine
  pairings (a whisper id under parakeet) BEFORE settings are persisted —
  called from `state::update_settings` and `create_engine`. Engines without
  catalogue entries (mock/embedded) and unknown ids always pass: the shipped
  default settings store the parakeet id under the `mock` engine and MUST
  keep saving. The Settings UI model field is a dropdown filtered by engine,
  so a mismatch cannot be typed anymore; custom ids still work via
  config.json.
- **`download(spec, dir, force, progress)`** — `force` restarts from zero
  instead of resuming the `.download` partial (resume can never repair a
  corrupt file). The Settings UI shows a **Re-download** button on installed
  models. `download_model` IPC re-runs `ensure_engine` after install so the
  fresh model is usable without an app restart.

Re-download hardening: archive installs extract into a `.staging` dir and
atomically swap into place via `install_archive` — never extract over a live
install. Both the fresh tree and the previous install get a
`make_writable_recursive` pass (Windows `FILE_ATTRIBUTE_READONLY` clear,
Unix owner-write) because tar extraction preserves archive metadata and can
leave read-only model files that break later truncating overwrites.
Non-archive renames get `make_writable(dest)` first. The `download_model`
IPC **drops the resident engine before** replacing files (a loaded engine
can hold model files open/mapped on Windows) and re-creates it after.
**The actual root cause of the persistent "Download failed: I/O error:
creating or truncating a file requires write or append access" was NOT an
OS error — it is `OpenOptions` client-side validation** (`ErrorKind::
InvalidInput`): the tmp-file open had `.create(true).truncate(!resuming)`
with `.append(resuming)` but NO write access mode, so every fresh/forced
(non-resuming) download failed before touching disk, while resumed
downloads worked (append implies write). Fix: `.write(!resuming)` added;
the error now also carries the failing path. Regression tests:
`reinstall_over_readonly_previous_install_succeeds` and
`truncating_open_requires_write_access` (both feature `parakeet` for the
first; the second runs everywhere).

## Verification harnesses

```bash
# Registry-driven download (same path the GUI uses)
cargo run --features parakeet --example download_model -- parakeet sherpa-onnx-nemo-parakeet-tdt-0.6b-v2-int8
cargo run --example download_model -- whisper tiny.en

# Live-capture probe: does a fresh stream on a device actually deliver?
# (distinguishes OS/device problems from app-side capture bugs)
cargo run --example mic_probe              # default device
cargo run --example mic_probe -- "ROG EYE S"  # device whose name contains ...

# Live-capture integration test on real hardware (ignored in CI)
cargo test -- --ignored live_capture_delivers_samples

# WER + e2e_ms + RTF against a speech file (sidecar .txt = reference)
cargo run --features parakeet --example transcribe_file -- ../speech.wav --engine parakeet --model sherpa-onnx-nemo-parakeet-tdt-0.6b-v2-int8
```

- Speech samples without a mic: PowerShell SAPI TTS —
  `Add-Type -AssemblyName System.Speech; $s.Speak(...)` →
  `SetOutputToWaveFile(...)`.
- Reference-PC numbers (debug build, 4.27 s sample): parakeet 1187 ms
  (RTF 0.28), whisper tiny.en 1095 ms (WER 7.7%). Both beat the 1.5 s
  key-up→paste budget; release is faster.

## Known Rust pitfalls (this codebase)

- `cpal::Stream` is `!Send + !Sync` on WASAPI — streams must live on
  detached worker threads; only Send handles (ring buffer Arcs, channels,
  atomics) may enter `AppState`. Do not regress this.
  **And the stream must be HELD by the worker** — `build_stream` returns the
  live `cpal::Stream` alongside the ring, and the worker binds it for its
  lifetime. The original code dropped the stream at the end of
  `build_stream` (the "hold it forever" comment was aspirational): dropping
  a playing stream stops capture, so the ring NEVER filled — every dictation
  reported "no speech detected" on perfectly healthy mics. The
  `MicCapture` health counters (`callback_count()`, `stall_ms()`, stream
  errors) surface this on the Diagnostics page, `restart_microphone` IPC
  rebuilds capture without an app restart, and the ignored integration test
  `live_capture_delivers_samples` (`cargo test -- --ignored`) reproduces
  the failure on real hardware (ignored in CI — no audio device).
- **Bare expression paths do not resolve sibling modules declared in a
  parent module** (proven with a minimal repro): inside
  `stt/engine.rs`, `parakeet::X` fails while `crate::stt::parakeet::X`
  works — always use crate-qualified paths for cfg-gated siblings.
- **Hotkey swallow discipline (Windows hook)**: an armed PTT press must
  consume BOTH the key-down and the matching key-up (`SWALLOWED_DOWN`
  tracks the pair; auto-repeat downs stay swallowed). Swallowing only the
  key-up while the down passes through leaves Windows believing RAlt is
  permanently held — the "keyboard breaks, Ctrl+Alt+Del fixes it" stuck-Alt
  failure. Non-armed events (AltGr combos) always pass through via
  `ptt_pressed_alone`. **Exit discipline**: if the app shuts down with a
  swallow pending (Alt+F4 close while RAlt is held is the classic repro),
  the hook thread injects a synthetic key-up via `SendInput` AFTER
  `UnhookWindowsHookEx` (`release_pending_swallow` / `take_pending_swallow`
  bookkeeping) so the modifier is never left stuck for the session. The
  hook tests serialize on a shared `TEST_LOCK` because the hook state lives
  in process-global statics.
- **VAD gating (dictation)**: the energy gate is adaptive per window —
  `max(0.02, min(10th-percentile frame RMS × 6, 0.15))` (WebRTC-style noise
  gating); silero keeps its probability default. The VAD only trims silence
  edges — it is never the final gate: `vad_empty_fallback` transcribes the
  whole window whenever the peak exceeds 0.004 (false negatives must never
  drop user audio; muted/unplugged mics peak at ~0 and still error). Every
  attempt records `LastDictationStats` (window/utterance ms, RMS/peak, gate,
  outcome) surfaced by `get_diagnostics`.
- **Settings saves that fail validation must not persist**: `update_settings`
  runs `validate_pairing` BEFORE `settings.save()` so a broken combo can
  never wedge the next boot. If the engine still fails to load after a valid
  save (missing model files), the IPC error says exactly that — settings
  ARE saved in that case; only the engine swap failed.

## Cleanup pipeline (golden backstop)

Order matters: whitespace → vocabulary → numbers → fillers → capitalize →
punctuate → per_app. Any rule change must keep `src-tauri/tests/cleanup_golden.rs`
(17 cases + `meeting1` fixture) green or be an intentional, reviewed change.
The fixture previously said "So we need…" but the leading-marker rule strips
`so` after filler removal — "We need…" is the correct expectation.

## Status & follow-ups (2026-10-12)

Done: P0 core, P1 (nvoos client/archive), P3 (MCP stdio), engine track live-
verified, embedded fallback implemented, PR #7011 → `alpha-working`;
post-merge hardening: exit-on-close + stuck-PTT release + model download UX
(#7017), VAD windowing/whisper CRT fix/hotkey guard (#7020), and this round:
symmetric hook swallow (stuck-Alt fix), hands-free toggle release fix
(double-tap recording no longer ends on its own key release), engine/model
pairing validation + model dropdown, force re-download, vendored
whisper.cpp CMakeLists bindings fix; hook exit-safety (synthetic key-up via
SendInput after unhook — fixes the keyboard stuck until Ctrl+Alt+Del when
closing with Alt+F4/while the PTT key is held), adaptive VAD gate +
no-speech fallback (audible audio is never dropped), `LastDictationStats`
+ Diagnostics tab (`get_diagnostics` IPC: mic device name + live level
meter, hook health, model install state, credentials, copy-report JSON),
mic input-device picker in Settings (`audio.input_device` +
`list_input_devices` IPC + `state::reapply_audio` — the resident capture
restarts on save; MicCapture's worker thread now stops via a Drop stop
channel), the model re-download hardening (staging-swap install,
read-only attribute clearing, engine released before file replacement,
and the real re-download root cause: the tmp-file `OpenOptions` missing
`.write(!resuming)` — a client-side InvalidInput, not an OS error), and
the **live-capture fix**: the resident stream was being dropped right
after `play()` so the pre-roll ring never filled (zero callbacks on
healthy mics) — the worker now holds the stream, capture health
(callbacks/stall/errors) shows on Diagnostics with a Restart-capture
button, `restart_microphone` IPC, and the `mic_probe` example +
`live_capture_delivers_samples` ignored test verify real hardware.
Remaining: PTT manual matrix + loopback meeting verification (P2
real-call), embedded live test (needs a Gemma 4 audio endpoint), Embedded
addon permission tweak for `cred_x.SECRET`, P4 port, macOS on-hardware
verification + release signing/updater feed. See the implementation plan
(`docs/project/proposals/067-…-plan.md`) for the full item list. The macOS
port (P5) has its own comprehensive plan:
`examples/nvoos-dictate/docs/macos-port-plan.md` (phases M0–M8: TCC
permission model + first-run wizard, permission-free combo PTT default +
opt-in Right-Option CGEventTap hold mode, ScreenCaptureKit Swift sidecar
for meeting system audio, NSWorkspace per-app cleanup, signed/notarized
DMG). Implementation landed on `feat/nvoos-dictate-macos-port` (2026-10-12,
unverified on macOS hardware): `hotkey/macos_event_tap.rs` + portable
`tap_state.rs` state machine, `paste/macos_cgevent.rs`, `permissions.rs`
TCC layer + Settings Permissions tab, `meetings/macos_system_audio.rs` +
`sidecar_protocol.rs` + `mac-helper/` Swift package (externalBin stubs in
`src-tauri/binaries/`), `util/macos_foreground.rs`, Info.plist +
entitlements, macOS CI/publish matrix. macOS-side compile gates run in CI
(`macos-latest` job) — not verifiable from the Windows reference PC.

## Related skills

- `mcp-ai-wpoos-plugin` — plugin-side setup (assistant credentials, Docker)
- `mcp-ai-wpoos-spa-ui` — React/Vite conventions shared with the SPA addons
- `mcp-ai-wpoos-test-suite` — PHPUnit side; this skill covers the Rust gates
