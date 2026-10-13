---
type: Skill
name: mcp-ai-wpoos-dictation-app
description: Operational guide for the NV oOS Dictation desktop companion app (Tauri v2 + Rust, examples/nvoos-dictate) — build/run on Windows/MSVC, the local-first engine track (parakeet/whisper/moonshine/silero-vad), the embedded remote-STT fallback, the Stage-2 polish provider track (disabled/openai_compat/nvoos plus local LM Studio and Ollama), the file/media decode seam (Symphonia 0.5), the live camera + local object-detection/tracking track (Proposals 073 + 075) with an opt-in VLM label-polish layer (Proposal 076), model registry + verification harnesses, the plugins.updater boot-crash config rule, and the full test/clippy gate matrix. Use when building, extending, or debugging the dictation app, adding an engine/model, a polish provider, file/video transcription, camera/vision detection, or VLM relabeling.
license: Proprietary. See examples/nvoos-dictate/LICENSE.txt
metadata:
  app: nvoos-dictate
  app-version: "0.2.0"
  proposal: "067"
  pr: "7011"
  last-updated: "2026-10-13"
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
- **MSYS quoting traps:** `%APPDATA%`/`$_`/`$VAR` inside `cmd`/`powershell
  -Command` get mangled by the MSYS shell — write PowerShell to a `.ps1`
  file and run `powershell -NoProfile -ExecutionPolicy Bypass -File x.ps1`
  instead; paths like `C:\Users\…` stay literal. `cmdkey //list | findstr
  nvoos` (double slashes) shows what actually landed in Windows Credential
  Manager — the ground truth for "did the credential stick".

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
cargo clippy --all-targets --features camera -- -D warnings
cargo clippy --all-targets --features vision -- -D warnings
cargo test            # default (mock/embedded)
cargo test --features vision   # 94 lib (+9 vision/tracker/polish tests)
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
- **MSVC Release-config trap (the "portable exe takes forever" bug):** with
  the VS generator, whisper.cpp's `Release` CMake configuration maps to an
  unoptimized vcxproj (empty `<Optimization>` → /Od) — base.en decodes at
  RTF ~5 instead of ~0.3, so dictation "hangs" for tens of seconds while
  parakeet stays fast. The app therefore wires
  `whisper = ["dep:whisper-rs", "whisper-rs-sys/force-debug"]` — a direct
  optional `whisper-rs-sys` dep (unified with the `[patch.crates-io]`
  entry) forwards the vendor's `force-debug` feature, which compiles
  whisper.cpp with the RelWithDebInfo profile (verified fast on MSVC) even
  in release builds. Do not remove that wiring; verify speed with
  `cargo run --release --features whisper --example transcribe_file --
  <wav> --engine whisper --model base.en` (expect RTF < 1).
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

**Settings → NV oOS "Test connection" contract:** `GET
{site}/wp-json/mcp-ai/v1/assistants` with `Authorization: Bearer
<credential>` — 200/201 = "connected; credential accepted", 401/403 =
"site reached, credential rejected", anything else = transport/status
error surfaced verbatim. The credential must be issued on the site first
(`wp mcp-ai assistant create …` + `wp mcp-ai credential issue <id>
--porcelain`, or the admin UI) — a stored-but-invalid credential shows
401, a missing one errors before any network call (`nvoos_client()`
requires both site URL and keychain credential).

**Windows credential storage (the "I stored it but it did not stick" bug):**
the app does NOT use the `keyring` crate on Windows — keyring 3.6.3 hard-
codes `CRED_PERSIST_ENTERPRISE`, which from an unelevated app writes
"successfully" into a namespace the app cannot read back (nothing appears
in `cmdkey /list` either). `storage/win_cred.rs`
(`WindowsCredentialStore`) calls `CredReadW`/`CredWriteW`/`CredDeleteW`
directly with `CRED_PERSIST_LOCAL_MACHINE` (machine-local, survives
reboots, visible in Credential Manager — the persistence git credential
manager uses), keeping keyring's `{user}.{service}` target convention and
UTF-16LE blob format for compatibility. keyring remains the backend on
macOS/Linux. Every IPC secret write (`persist_secret_verified`) verifies
the read-back before reporting success. Diagnostics: `cargo run --example
keychain_probe` (set/read-back/delete); visibility check while held:
`cmdkey /list | findstr nvoos`.

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

## Camera & live vision (Proposal 073 + 075)

Two opt-in local sources on the webcam, both feature-gated and off by default:

- **Camera preview (073)** — `camera/` module, `nokhwa` frames only, gated
  behind the `camera` feature. `nokhwa::Camera` is `!Sync` so it lives on a
  dedicated `nvoos-camera` worker thread; only `Send` handles (the
  `PreviewFrame` ring `Arc<Mutex<_>>`, atomics) cross into `AppState`.
  Camera *audio* is **not** nokhwa — a webcam mic is an ordinary `cpal` input
  device and transcribes through the existing mic path. `PreviewFrame` is
  downsampled to ≤320 px wide for the preview. IPC: `list_camera_devices`,
  `camera_available`, `start_camera`, `stop_camera`, `get_preview_frame`.
- **Object detection + tracking (075)** — `vision/` module, behind the
  `vision` feature (which implies `camera`: `vision = ["camera", "dep:ort",
  "dep:ort-sys", "dep:ndarray16"]`). Runs a YOLO ONNX detector (`yolov11n`
  default, `yolov8n` fallback) via the pinned `ort` rc.9, decodes + NMS-filters
  `[1, 84, 8400]` → source-pixel `Detection`s, then a dependency-free SORT
  tracker assigns stable ids + a click-to-select pin. **No facial
  recognition / identity** — bright-line non-goal, enforced in tool/UI copy.

`vision/` layout mirrors `camera` for feature stability:
`mod.rs` (always compiled: `Detection`, `available()`) + `detect.rs`
(letterbox + `ort` + NMS; **both** a `#[cfg(feature = "vision")]` real body
and a stub), `tracker.rs` (pure SORT, always compiled + unit-tested),
`models.rs` (vision ONNX catalogue: `yolov11n`/`yolov8n`, SHA-256 + streaming
+ atomic install, mirroring `stt::model_registry`), and `controller.rs`
(`VisionController` — detector+tracker on a worker, snapshot + pin channel
handoff). `ort` rc.9 has **no** crate-root `Session`/`Value` re-export — use
`ort::session::Session`, `ort::value::Value`, and positional `ort::inputs!`
(`inputs![Value::from_array(tensor)?]`, NOT the named form). `ort` rc.9 maps
`ndarray` **0.16** while the app pins `ndarray` 0.15 for `silero-vad` — use the
`ndarray16` alias (already in `Cargo.toml`).

`Settings.vision` gains `enabled`, `detector`, `confidence`, `iou_threshold`,
`track_objects`, `hand_model` (hand model + ByteTrack are deferred M5
follow-ups). `StatusInfo` adds `vision_available/running/detector/hand_model`;
`get_diagnostics` adds a `vision` block (availability, running, model install
state, fps-equivalent `frames`, `last_infer_ms`, `active_tracks`,
`track_events`, `errors`). IPC commands: `vision_available`, `start_vision`,
`stop_vision`, `get_detections`, `vision_select_track(id)`, `get_vision_models`,
`download_vision_model`. The Home "Camera preview" panel draws detections on a
`<canvas>` overlay with click-to-pin; the detector is started from the Home
panel and the model is downloaded from Diagnostics → Object detection (or
Settings → Vision).

The de-risk spike `examples/vision_probe.rs` (feature `vision`) proved
`yolov11n.onnx` (exported `opset=12`, `onnx` Python module required to
regenerate) runs on `ort` rc.9 with the confirmed `[1, 84, 8400]` output and
~49 ms CPU inference. Weights export: install `onnx` (`pip install onnx`),
download `yolo11n.pt` from
`https://github.com/ultralytics/assets/releases/download/v8.3.0/yolo11n.pt`
(note: the id is `yolo11n`, not `yolov11n`), then
`python -c "from ultralytics import YOLO; YOLO('yolo11n.pt').export(format='onnx', opset=12, imgsz=640)"`.
No `onnxslim` = the simplifier step logs a non-fatal warning.

**Vision label polish (Proposal 076):** the stock `yolov11n` is a
closed-vocabulary 80-class COCO model, so it mislabels arbitrary objects
(a pen → "knife"). The opt-in VLM polish layer fixes this: when
`Settings.vision.polish_enabled` is on, the detect loop crops each
low-confidence box, PNG-encodes it as a base64 data-URI, and sends a
**multimodal** OpenAI-compatible request (image + text) through the *same*
`LocalOpenAiProvider` transport used by text polish (`local_openai.rs`). The
returned `{label, confidence}` replaces the COCO label (badged `VLM` in the
overlay). Implementation: `vision/polish.rs` (`VisionPolisher` + `prepare_request`
/`resolve`, crop→base64 PNG), `openai_shape::{build_vision_body, parse_label_response,
vision_polish_prompt}`, `LocalOpenAiProvider::describe_crop`, and a dedicated
`nvoos-vision-polish` thread (owns a tokio runtime) so the ~500 ms VLM
round-trip never stalls the 15 fps detect loop. Default model
`qwen/qwen2.5-vl-7b` (the reference VLM for structured-JSON grounding; verified
live on the reference PC — it returns **fenced** JSON, which
`parse_label_response` tolerates). The no-facial-recognition bright line is in
the system prompt. `VisionDiagnostics` gained `polish_enabled/provder/model/calls/results`.

## Stage-2 polish providers (local LLM cleanup)

Stage-2 is the optional LLM polish that runs **after** Stage-1 deterministic
rules, gated by `cleanup.wait_for_llm_ms` (ADR 0001). Provider dispatch lives
in `cleanup/providers/mod.rs::create()` — `disabled` | `openai_compat` |
`lm_studio` | `ollama` | `nvoos`. Each provider receives text + target-app
category only (never audio); failure always falls back to Stage-1 text and
can never block a paste.

**Provider architecture (refactor from this session):**

- `openai_shape.rs` owns the provider-agnostic OpenAI `/v1/chat/completions`
  shape: `system_prompt_for(category)`, `build_chat_body(model: Option<&str>,
  text, category, temperature)` (omits the `model` field entirely when `None`),
  and `parse_chat_response` (defensive string-or-segment-array content).
- `local_openai.rs` owns the shared **local**-server behaviour
  (`LocalOpenAiProvider` parameterized by a `LocalKind { name, id }`): the
  local-http-vs-remote-https invariant, optional bearer auth, `GET /v1/models`
  discovery, `ttft_ms`/`total_ms` timing, `load_timeout_ms` cold-load budget
  enforcement, and actionable error mapping. LM Studio and Ollama are thin
  `LocalKind` wrappers over it — they share ~95% of their logic.
- `lm_studio.rs` (`LmStudioProvider`) and `ollama.rs` (`OllamaProvider`) supply
  only their identity (`name`/`id`) and read their own keychain token
  (`KEY_LM_STUDIO_TOKEN`, `KEY_OLLAMA_TOKEN`). `id()` returns `lm_studio` /
  `ollama` so transcript/history labelling stays correct.

**Key facts that shaped the design:**

- **Model identifiers are discovered, not typed.** Both local servers expose
  `GET /v1/models` → `{ data: [{ id, … }] }`; the `id` is what goes back on
  `chat/completions.model` (an LM Studio "model identifier", an Ollama model
  name like `llama3.2` — NOT a GGUF filename). The Settings tabs surface a
  discovered-model dropdown + auto-select the single loaded model.
- **Auth defaults to none.** The local server needs no token (any non-empty
  `Authorization: Bearer …` is accepted as a placeholder). The optional token
  is really for a remote/headless server and lives in the OS keychain — never
  in `config.json` (the `Settings` struct holds no secrets).
- **Single-loaded-model behaviour:** if exactly one model is loaded, the server
  serves it regardless of the `model` field; omitting `model` when the config
  is empty is the correct default.
- **Two distinct timeouts:** `cleanup.wait_for_llm_ms` bounds the *whole*
  polish (dictation.rs); `load_timeout_ms` (per local provider) is a separate
  `tokio::time::timeout` budget for a Just-In-Time cold model load, plus a 60 s
  soft reqwest-client default so a hung server can't block forever.
- **`temperature` is provider-specific:** `0.2` for `openai_compat` (cloud),
  `0.1` for local models (they drift more; cleanup is deterministic editing).
- **Polish latency:** `PolishMeta` carries `ttft_ms`/`total_ms` (local providers
  report them; `nvoos`/`openai_compat` leave `None`); `LastDictationStats`
  surfaces them as `polish_ttft_ms`/`polish_total_ms`, shown on the Diagnostics
  page under "Polish latency (TTFT / total)".

**Settings plumbing per local provider:** config block (`LmStudioConfig` /
`OllamaConfig` with `base_url`, `model`, `load_timeout_ms`) wired into
`Settings`; keychain key + `has_*_token()` + `*_provider()` on `AppState`;
three IPC commands each (`test_*_connection`, `set_*_token`, `clear_*_token`)
registered in `lib.rs::run`; a Settings tab + provider `<option>` in
`Settings.tsx`; a credential row in `Diagnostics.tsx`. Default base URLs:
LM Studio `http://localhost:1234/v1`, Ollama `http://localhost:11434/v1`.

**Pitfall (this session):** adding a field to `PolishMeta` (a struct with other
literal constructions) breaks `nvoos/types.rs::extract_polish_meta`, which
builds it field-by-field — use `..PolishMeta::default()` there. Also, a
`reqwest::Client` in a struct means it can't trivially `#[derive(Debug)]`, so
`unwrap_err()` on a `Result<Provider, _>` won't compile — match on it instead.

The design rationale is captured in
`examples/nvoos-dictate/docs/lm-studio-integration-plan.md`.

## File / media decode seam (Symphonia 0.5)

File transcription (`Transcribe file…`, `util/decode.rs::decode_file`) decodes
mp3/m4a/wav/flac/ogg to mono f32 PCM via Symphonia and feeds the resident STT
engine through `ipc::transcribe_file`. Key facts:

- **Symphonia 0.5 already demuxes MKV / MP4(MOV) / WebM** containers (not just
audio formats), so extending to video is a decode-seam change — an extension
`Hint` (`Hint::with_extension`) + explicit audio-track selection (iterate
`format.tracks()` for `codec_params.media_type == Audio`) — **not** a new
crate. There is deliberately **no FFmpeg sidecar** (the app is pure-Rust audio;
Symphonia only needs the *audio* track, whose codecs — AAC/Opus/Vorbis — it
already decodes).
- The current `decode_file` sets `Hint::new()` (no extension), assumes the
default track is audio, discards **duration**, and slurps the whole file into
memory with no cap. Those are the precise gaps video/long-media work must
close (`DecodedMedia { samples, sample_rate, duration_ms, container,
has_video_track }`, a memory cap, stream-and-chunk).
- **Long-media chunking:** `meetings/chunker.rs::Chunker` splits on VAD-detected
silence at a `MAX_SEGMENT_SECS = 240` hard limit (parakeet's ~4–5 min TDT
decode limit) and is **skipped for whisper** via `SttEngine::supports_long_files()`.
Consequence: a whisper engine currently receives the *entire* long file in one
`transcribe()` call, violating whisper's ~30 s sliding-window + context
carry-over semantics. Long media therefore needs (1) a fixed ~30 s whisper
window with overlap, (2) prior-window text/token context passed into the next
window, and (3) 0-or-N-segment tolerance per window (whisper may return empty
for a silent window).
- **`embedded` remote STT is disqualified for media** (10 MB / 30 req/min
limits) — video/long-media must use a local engine.

Full scope in Proposal 071
(`docs/project/proposals/071-video-input-extended-cognition-proposal.md` +
`…-implementation-plan.md`). **Implemented (M1–M5 core, 2026-10-13):**
`util/decode.rs` now returns `DecodedMedia { samples, sample_rate, duration_ms,
container }` — audio-track selection is by `get_codecs().make()` per track
(the first decodable track is audio; any non-decodable track marks the
container `Video`), with extension `Hint`, `n_frames`×`time_base` duration,
and a `MAX_MEDIA_SAMPLES` cap. New `media/chunker.rs` (`chunk_transcribe`)
windows `supports_long_files()` engines in fixed 30 s windows with a 1 s
overlap + context carry-over (default `transcribe_with_context`), and routes
parakeet through the 240 s VAD split. `SttEngine` gained
`transcribe_with_context(.., prior)` (default ignores `prior`). New IPC
commands `transcribe_media` (structured result + `media://progress` events) and
`summarize_media` (via a new `CleanupProvider::summarize`, implemented for
nvoos + local lm_studio/ollama). `transcribe_file` now routes through the
chunked path. **Deferred:** GUI file-picker (needs `tauri-plugin-dialog`), a
`transcripts.media` DB column (no consumer), and media fixtures for
decode e2e tests.

## Status & follow-ups (2026-10-13)

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
`live_capture_delivers_samples` ignored test verify real hardware;
the **Stage-2 polish/archive contract fix** (verified live against
console.nvoos.cloud v1.2.3): `extract_reply_text` now parses the v1.2.x
versions (`data.content`, `data.choices[0].message.content`) and the
`nvoos_polish_probe` example exercises the real path — the archive route
`POST /chat-transcripts` REQUIRES both `assistant_id` and `session_key`
(both returned by the chat response: `assistant_id` + camelCase
`sessionKey`; the app now threads them via `PolishMeta`), and the **per-
request polish model override**: `nvoos.polish_model` (Settings > NV oOS)
is sent as `options.model` on the polish chat request — verified live:
the site answers with the requested model (`deepseek-v4-pro` honored;
~10.5 s vs ~9 s for `deepseek-flash` at ~4× cost, so flash stays the
sensible default for transcript cleanup). `nvoos.polish_provider` is
sent as `options.provider` the same way — the site's `sanitize_options`
allowlist accepts openai/gemini/anthropic/deepseek/etc., and a provider
without an API key configured on the site errors, so the overrides only
work for providers the site has keys for (the public `/status` endpoint
reports "N providers operational" but never names them). The
`nvoos_polish_probe` example takes `--provider`/`--model`/`--archive`/
`--raw` flags for live A/B runs. Also: the app's Windows credential
(CRED target `nvoos/assistant-credential.com.nvdigitalsolutions.
nvoos-dictate`) can vanish when the user exercises the Clear-credential
UI — probe runs should check `credential: stored` and ask the user to
re-store it (the site only ever shows `cred_XXXXX.SECRET` at creation;
a lost one needs regeneration in the console).
New this session (2026-10-13): **first-class local Stage-2 polish providers**
— `lm_studio` and `ollama` — plus the shared `local_openai`/`openai_shape`
transport refactor, `load_timeout_ms` cold-load enforcement, and polish
`ttft`/`total` latency on the Diagnostics page (see "Stage-2 polish
providers" section above). All gates green on the reference PC:
`cargo fmt --check`, `clippy --all-targets --offline -- -D warnings`
(default + `--features mcp`), `cargo test --offline` (78 lib + 17 golden +
6 history + 5 validation), and `npm run build` (tsc + vite). A fresh debug
exe was built with `npm run tauri -- build --debug --no-bundle --features
whisper,parakeet,silero-vad` → `src-tauri/target/debug/nvoos-dictate.exe`
(the cleanup providers need no feature flag — they're always compiled;
the local-engine features are what require a flagged build).
New this session (2026-10-13, implemented): **video + long-media input**
(Proposal 071). `util/decode.rs` → `DecodedMedia` (extension `Hint`,
audio-track-by-`get_codecs().make()`, duration via `n_frames`×`time_base`,
`MAX_MEDIA_SAMPLES` cap); `media/chunker.rs` (`chunk_transcribe`) windows
whisper-class engines at 30 s + context carry-over and parakeet at the 240 s
VAD split; `SttEngine::transcribe_with_context`; `transcribe_media` +
`summarize_media` IPC (+ `media://progress`); `CleanupProvider::summarize`
(nvoos + local). Gates green: 81 lib + 17 golden + 6 history + 5 validation,
clippy default + `mcp`, frontend build. Deferred: GUI file-picker, DB column,
media fixtures.
New this session (2026-10-13, implemented): **local object detection +
  tracking** (Proposal 075) — the `vision/` module (see "Camera & live vision"
  above): `detect.rs` (letterbox + `ort` YOLO + NMS), `tracker.rs` (SORT +
  click-to-select pin, dependency-free + unit-tested), `models.rs` (vision ONNX
  catalogue), `controller.rs` (`VisionController` worker loop). `Settings.vision`,
  `vision_available/start_vision/stop_vision/get_detections/
  vision_select_track/get_vision_models/download_vision_model` IPC, a `vision`
  block in `get_diagnostics`, `StatusInfo` vision fields, and a Home preview
  `<canvas>` overlay + Diagnostics/Settings rows. The `vision_probe` spike
  proved `yolov11n.onnx` runs on `ort` rc.9 (confirmed `[1, 84, 8400]`, ~49 ms
  CPU). Gates green: 85 lib + 17 golden + 6 history + 5 validation, clippy
  default + `mcp` + `camera` + `vision`, `cargo fmt --check`, `npm run build`.
  Deferred M5: MediaPipe-hand model + ByteTrack low-confidence recovery.

Follow-up (2026-10-13): **VLM label polish (Proposal 076)** for the COCO-mislabel
  problem — see "Vision label polish" above. Default model is `qwen/qwen2.5-vl-7b`
  (note the `qwen/` prefix), verified live on the reference PC: LM Studio returns
  the label as **fenced** JSON (``` ```json {…} ``` ```), which
  `parse_label_response` tolerates. Also fixed the **duplicate-track bug**: a
  pinned track kept drifting via `predict()` while unmatched, so a re-appearing
  object spawned a second track; the spawn step now re-associates any detection
  still overlapping a live/pinned track (IoU > 0.1) instead of spawning, and the
  pinned grace period is `max_age*2`. The Home "System" card, `README.md`
  (camera/vision features + feature flags), and `NOTICE.md` (model-weight
  licenses: YOLO AGPL-3.0, et al.) were surfaced. Gates green this session:
  94 lib + 17 golden + 6 history + 5 validation, clippy default + `mcp` +
  `camera` + `vision`.
Remaining: PTT manual matrix + loopback meeting verification (P2
real-call), embedded live test (needs a Gemma 4 audio endpoint), Embedded
addon permission tweak for `cred_x.SECRET`, P4 (Linux) port, the macOS
port, and release signing/updater feed. See the implementation plan
(`docs/project/proposals/067-…-plan.md`) for the full item list. The macOS
port (067-P5) is implemented on `feat/nvoos-dictate-macos-port`
(2026-10-12, unverified on macOS hardware): `hotkey/macos_event_tap.rs` +
portable `tap_state.rs` state machine, `paste/macos_cgevent.rs`,
`permissions.rs` TCC layer + Settings Permissions tab,
`meetings/macos_system_audio.rs` + `sidecar_protocol.rs` + `mac-helper/`
Swift package (externalBin stubs in `src-tauri/binaries/`),
`util/macos_foreground.rs`, Info.plist + entitlements, macOS CI/publish
matrix. macOS-side compile gates run in CI (`macos-latest` job) — not
verifiable from the Windows reference PC. See the comprehensive plan
`examples/nvoos-dictate/docs/macos-port-plan.md` (phases M0–M8: TCC
permission model + first-run wizard, permission-free combo PTT default +
opt-in Right-Option CGEventTap hold mode, ScreenCaptureKit Swift sidecar
for meeting system audio, NSWorkspace per-app cleanup, signed/notarized
DMG). An alternative macOS design is scoped in proposal 068
(`docs/project/proposals/068-macos-dictation-port-proposal.md`: listen-only
CGEventTap PTT, Metal/CoreML STT, Sparkle distribution) — reconcile the
two before merging the port.

## Related skills

- `mcp-ai-wpoos-plugin` — plugin-side setup (assistant credentials, Docker)
- `mcp-ai-wpoos-spa-ui` — React/Vite conventions shared with the SPA addons
- `mcp-ai-wpoos-test-suite` — PHPUnit side; this skill covers the Rust gates
