# 067 — NV oOS Dictation Companion (Tauri): Implementation Plan

**Status:** In progress — Phase 0 scaffold + P0/P1 code shipped under `examples/nvoos-dictate/` (mock-engine default build; native engines behind cargo features)
**Date:** 2026-10-11
**Scope:** New in-repo desktop app `examples/nvoos-dictate` (Tauri v2 + Rust core + React/TS webview), per Proposal 067
**Related:** `docs/project/proposals/067-tauri-dictation-companion-app-proposal.md`, `examples/nvoos-pro-spa-vite` (standalone-app precedent), `.agents/skills/mcp-ai-wpoos-content-graph-spa` (REST/auth contract), `.agents/skills/mcp-ai-wpoos-test-suite` (Docker WP test recipe)

## Motivation

Proposal 067 defines the product: a Windows-first dictation and meeting
transcription companion where local STT + deterministic cleanup run on-device
and the NV oOS plugin is the optional AI enrichment backend. This plan turns
that into file-level work items, ordered phases with exit criteria, a test
strategy, CI wiring, and a security checklist. Phases are deliberately
sequenced so the riskiest unknowns (Windows PTT hook reliability, STT
latency, plugin auth) are de-risked in P0/P1 before meetings, MCP, and the
Linux/macOS ports.

## Research findings that shape the implementation

| Finding | Source | Consequence for the plan |
|---|---|---|
| `WH_KEYBOARD_LL` must run on a **dedicated thread with a message loop** or it silently never fires | [SO](https://stackoverflow.com/questions/51033906/how-to-use-setwindowshookex-in-rust), parley [#462](https://github.com/pathorsAI/parley/issues/462) | PTT hook is its own OS thread + `GetMessageW` loop, never the Tokio/UI thread |
| Windows can **silently stop delivering `WH_KEYBOARD_LL` events when a Chromium window has focus** (incl. WebView2/Chrome/VSCode) | [dev.to report](https://dev.to/wudaming00/windows-silently-stops-delivering-whkeyboardll-hook-events-when-a-chromium-window-has-focus-and-bi8) | P0 must implement **belt-and-braces key detection**: `WH_KEYBOARD_LL` + `GetAsyncKeyState` polling fallback + `RegisterHotKey` (non-modifier) backup; acceptance test runs PTT with Chrome focused |
| Modifier-key PTT (right Ctrl/right Alt) is the proven default; left variants are too easy to hit | parley [#462](https://github.com/pathorsAI/parley/issues/462), murmur | Default = Right Alt (mirrors murmur); configurable in settings |
| **`transcribe-rs`** already provides a multi-engine Rust STT trait (Parakeet, Canary, Cohere, Moonshine, SenseVoice, Whisper, OpenAI) | [crates.io](https://crates.io/crates/transcribe-rs), [Dictata](https://github.com/AntoineChatry/Dictata) | Evaluate/adopt instead of hand-rolling all three engine impls; keep our own thin `SttEngine` facade for swap/bench |
| Parakeet TDT/CTC models have a **~4–5 minute audio limit** | [parakeet-rs](https://lib.rs/crates/parakeet-rs) | Dictation fine; **meetings (P2) must chunk + stitch** or switch engine per length (whisper.cpp for long files) |
| sherpa-onnx publishes an official Rust API + pre-converted Parakeet int8 models (`sherpa-onnx-nemo-parakeet-tdt-0.6b-v2-int8`); v3 exists as CC-BY-4.0 base + community int8 conversions | [sherpa-onnx](https://github.com/k2-fsa/sherpa-onnx), [HF card](https://huggingface.co/nvidia/parakeet-tdt-0.6b-v3) | Model registry pins exact HF repo + SHA-256 manifest; download-at-first-run with progress |
| `whisper-rs` (0.16.x, whisper.cpp bindings) builds whisper.cpp via CMake (C++ toolchain in CI); `whisper-cpp-plus-rs` adds streaming+VAD | [whisper-rs](https://github.com/tazz4843/whisper-rs) | Multilingual fallback engine; CI needs `cmake` + MSVC build tools; lazy-init only when selected |
| `ort` (pykeio) is the maintained ONNX Runtime wrapper; `moonshine-ort` runs Moonshine natively | [ort](https://github.com/pykeio/ort), [moonshine-ort](https://docs.rs/moonshine-ort) | Moonshine v2 streaming preview reuses the same ONNX runtime as Parakeet |
| Silero VAD: `silero-vad-rs` (ort-based) and `voice_activity_detector` are maintained Rust impls | [docs.rs](https://docs.rs/silero-vad-rs) | `voice_activity_detector` rejected (non-standard license); energy VAD is the default with `silero-vad-rs` as an optional feature (`silero-vad`) |
| Clipboard+keystroke injection: `arboard` + `enigo` (now under `enigo-rs` org) is the fala-proven pair; `enigo` history had maintenance gaps — pin and vendor-check | [fala PR #57](https://github.com/augusto-dmh/fala/pull/57), [enigo-rs](https://github.com/enigo-rs/enigo) | Paste module isolates both behind a trait so Windows `SendInput` (via `windows` crate) is a drop-in fallback |
| Deterministic post-processing: OpenAI cookbook normalizes numbers ("five two nine" → "529") and product terminology; HF speechbox uses Whisper forced-decoding for punctuation; OpenWhisper is pursuing a zero-LLM ONNX punctuation pass | [OpenAI cookbook](https://github.com/openai/openai-cookbook/blob/main/examples/Whisper_processing_guide.ipynb), [speechbox](https://github.com/huggingface/speechbox), [OpenWhisper #40](https://github.com/Knuckles92/OpenWhisper/issues/40) | Stage-1 cleanup = pure-Rust rule engine (golden tests); optional ONNX punctuation model as a later enhancement; LLM pass stays Stage-2 |
| `rmcp` (official Rust MCP SDK): streamable HTTP behind feature flags; **the SDK dropped the legacy 2024-11-05 HTTP+SSE transport** — consumers needing old SSE bridge it manually | [rust-sdk](https://github.com/modelcontextprotocol/rust-sdk), [oxml-mcp PR #57](https://github.com/sebastienrousseau/oxml-mcp/pull/57) | P3 includes a **transport spike**: inspect the plugin's Pro MCP client (JetEngine/MCP-App per 066 plan — it parses SSE responses) and, if it speaks legacy SSE, add the small bridge module (oxml-mcp pattern) |
| MCP 2026-07-28 protocol standardizes Streamable HTTP routing headers (`Mcp-Method`, `Mcp-Name`, `Mcp-Param-*`); rmcp negotiates per-connection | [rust-sdk](https://github.com/modelcontextprotocol/rust-sdk) | Pin rmcp ≥ version supporting 2026-07-28; do not hand-write JSON-RPC framing |
| Secrets: OS keychain via `keyring` (Windows Credential Manager / Keychain / Secret Service); `tauri-plugin-secure-keystore` alternative | [keyring](https://docs.rs/keyring/latest/keyring/), [secure-keystore](https://crates.io/crates/tauri-plugin-secure-keystore) | NV oOS credential + any provider keys live in the OS keychain; config files hold **no secrets** (explicit anti-pattern: murmur's plaintext config) |
| `tauri-apps/tauri-action` builds all three OSes in CI and generates the updater static-JSON feed; updater uses minisign Ed25519; NSIS needs WebView2 bootstrapper awareness | [tauri-action](https://github.com/tauri-apps/tauri-action), [Tauri pipelines](https://v2.tauri.app/distribute/pipelines/github/), [updater](https://v2.tauri.app/plugin/updater/) | One `publish` workflow; `TAURI_SIGNING_PRIVATE_KEY` + minisign pubkey in repo config; unsigned dev artifacts documented with SHA-256 checksums |
| `tauri-plugin-global-shortcut` requires Rust ≥ 1.90 | [plugin docs](https://v2.tauri.app/plugin/global-shortcut/) | Pin toolchain in `rust-toolchain.toml` |
| NV oOS Embedded addon (`addons/embedded/`) ships server-side STT: `POST /wp-json/mcp-ai/v1/embedded/transcribe` proxies base64 WAV (≤ 10 MB, 30/min rate limit, 60 s timeout) to a configured Gemma 4 audio endpoint (Ollama/vLLM/NIM); permission = logged-in user or `nvoos_embedded_allow_guest_transcribe` filter | `addons/embedded/includes/class-wp-mcp-ai-embedded-transcribe.php`, `class-nvoos-embedded.php` | Fallback real-dictation path for machines without local engines: an `embedded` engine variant reuses the app's existing site URL + credential (or a WP application password) — no local model downloads (ADR 0003) |

## Current status & revised remaining plan (2026-10-11)

The reference implementation in `examples/nvoos-dictate` has moved past the
phase boundaries below. Status:

**Complete and validated on the reference PC (Windows/MSVC):**
- Phase 0 scaffold, capabilities, CSP, icons, `build-nvoos-dictate.yml` + `publish-nvoos-dictate.yml` workflows.
- P0 hotkey (hook + fallback), paste, audio capture (16 kHz mono f32; cpal streams pinned to worker threads), energy VAD (default), deterministic cleanup rules + golden fixtures, storage (SQLite + migrations), React settings/history UI, overlay.
- P1 nvoos client (credential auth, chat/archive), keyring credential storage, connection test.
- P2 meetings core (recorder, chunker) — real-engine transcription pending the STT track below.
- P3 MCP server (`rmcp` stdio, `mcp` feature) — compiled and tested.
- **Engine track (ADR 0003):** parakeet compiled against sherpa-onnx 1.13.8 + model registry with streaming SHA-256 and tar.bz2 extraction tests; embedded remote fallback engine with WAV encoder + contract tests; WP application-password keychain/IPC/UI wiring; whisper-rs 0.16 + moonshine/silero-vad adapters API-verified and feature-compiling (ort pinned rc.9 + ort-sys rc.9). **Live-verified on the reference PC** (LLVM 23.1.3 installed for whisper's bindgen): parakeet 0.6b-v2-int8 → 1187 ms for 4.27 s audio (RTF 0.28, transcript correct); whisper tiny.en → 1095 ms (WER 7.7%). Model downloads exercised end-to-end via the registry CLI (`examples/download_model.rs`).
- Validation: `cargo fmt --check`, `cargo clippy -D warnings` (default + `mcp`), `cargo test` (59 tests) and `cargo test --features mcp` (63 tests) all green; `nvoos-dictate.exe` builds, boots, and stays resident (required adding the `plugins.updater` block to `tauri.conf.json`).

**Remaining, re-ranked:**

1. **Real dictation — local engines first (primary path, local-first).** ✅ Done and live-verified: parakeet (sherpa-onnx 1.13.8) + whisper (whisper-rs 0.16, built with LLVM 23.1.3) transcribe real speech on the reference PC with the WER/e2e_ms harness; models download via the SHA-256-verified registry. Moonshine stays the documented streaming spike; silero VAD adapter wired (model catalogue entry).
2. **Embedded remote STT (fallback).** ✅ Implemented (`stt/embedded.rs`, WAV encoder, contract tests): `POST /wp-json/mcp-ai/v1/embedded/transcribe` with WP application password (Basic) or assistant credential (Bearer); UI options + keychain fields wired. End-to-end live test still needs a Gemma 4 audio endpoint on the target site.
3. **Loopback meeting capture verification** (P2 real-call) + the PTT manual matrix (`docs/ptt-matrix.md`).
4. **P4/P5 platform ports** (Linux, macOS) — untouched.
5. **Distribution** — NSIS/MSI release build, signing, updater `latest.json` publishing.

## Repo placement & scaffold

```
examples/nvoos-dictate/
├── package.json                # scripts: dev, build, test:ui; deps mirror spa-vite conventions (React+TS)
├── tsconfig.json / vite.config.ts
├── src/                        # React/TS webview: settings, history, meetings, first-run wizard
├── src-tauri/
│   ├── Cargo.toml              # workspace member crate (workspace = false, standalone-lockable)
│   ├── tauri.conf.json         # bundle NSIS+MSI, updater endpoints, strict CSP
│   ├── capabilities/
│   │   ├── overlay.json        # overlay window: global-shortcut, core:default (no shell/http)
│   │   └── main.json           # main window: settings/fs(scope-limited)/dialog permissions
│   ├── icons/                  # generated (tauri icon)
│   └── src/
│       ├── lib.rs / main.rs
│       ├── error.rs            # thiserror app error enum
│       ├── config.rs           # settings DTOs, no secrets (secrets in keyring)
│       ├── audio/              # capture.rs (cpal), ring_buffer.rs (pre-roll), vad.rs
│       ├── stt/                # engine.rs (facade trait), parakeet.rs, whisper.rs, moonshine.rs, model_registry.rs
│       ├── cleanup/            # mod.rs (pipeline), rules/ (deterministic, pure), providers/ (nvoos.rs, openai_compat.rs, disabled.rs)
│       ├── nvoos/              # client.rs (reqwest, credential auth), archive.rs, types.rs
│       ├── hotkey/             # win_hook.rs (WH_KEYBOARD_LL thread), fallback.rs (GetAsyncKeyState/RegisterHotKey), mod.rs
│       ├── paste/              # mod.rs (trait), arboard_enigo.rs, sendinput.rs
│       ├── meetings/           # recorder.rs, loopback.rs (cfg(windows)), chunker.rs
│       ├── mcp/                # server.rs (rmcp), tools.rs, bridge_legacy_sse.rs
│       ├── storage/            # db.rs (rusqlite), migrations/
│       ├── updater.rs
│       ├── examples/
│       │   └── transcribe_file.rs  # WER + e2e_ms harness (fala/Handy pattern)
│       └── tests/              # Rust integration tests (cargo test)
│           ├── cleanup_golden.rs   # golden fixture runner
│           ├── history.rs          # storage CRUD round-trips
│           ├── mcp_tools.rs        # toolbox handlers (feature mcp)
│           ├── nvoos_validation.rs # client construction rules
│           └── fixtures/cleanup/*.txt
├── bench/                      # reserved for comparative benchmarks
├── e2e/                        # Playwright specs for the webview UI
└── README.md                   # build, run, model download, privacy, plugin setup
```

CI additions: `.github/workflows/build-nvoos-dictate.yml` (test + lint) and
`.github/workflows/publish-nvoos-dictate.yml` (tauri-action release), both
mirroring the repo's existing `build-nvoos-content-graph.yml` shape.

## Toolchain & dependencies

| Concern | Choice | Notes |
|---|---|---|
| Rust | pinned via `rust-toolchain.toml` (≥ 1.90 per global-shortcut plugin) | stable channel |
| Tauri | `tauri` 2.x + `@tauri-apps/api`; plugins: `global-shortcut`, `updater`, `dialog`, `opener`, `single-instance` | no `shell`, no `http`-from-webview |
| Frontend | React 18 + TypeScript + Vite (repo SPA conventions per `mcp-ai-wpoos-spa-ui`) | thin UI; logic in Rust |
| Audio | `cpal` (mic; loopback on Windows via recent cpal), `voice_activity_detector` (Silero VAD) | pre-roll ring buffer hand-rolled (~300 ms @ 16 kHz) |
| STT | `transcribe-rs` (evaluate → adopt engines) or direct: sherpa-onnx Rust API (Parakeet), `whisper-rs` (whisper.cpp), `ort`+`moonshine-ort` | facade trait keeps swap cheap |
| Hotkey | `tauri-plugin-global-shortcut` + Windows `windows`-crate `SetWindowsHookExW(WH_KEYBOARD_LL)` on a dedicated message-loop thread + `GetAsyncKeyState` fallback | see Chromium-focus finding |
| Paste | `arboard` + `enigo` behind `PasteBackend` trait; `windows` SendInput fallback | clipboard save/restore mandatory |
| MCP | `rmcp` (server; streamable-HTTP + stdio features) | legacy-SSE bridge only if the plugin client needs it |
| HTTP | `reqwest` (Rust core only) | allowlisted to configured NV oOS origin |
| Storage | `rusqlite` + `rusqlite_migration` | WAL mode; schema v1 shipped as embedded migrations |
| Secrets | `keyring` | Windows Credential Manager target `com.nvdigitalsolutions.nvoos-dictate` |
| Async | `tokio` (rt-multi-thread) | audio + STT on dedicated blocking threads |
| Quality | `cargo clippy -D warnings`, `cargo fmt --check`, `cargo deny` (licenses/bans), eslint+prettier per repo config | CI gates |

## Phase 0 — Scaffold (0.5–1 day)

**Work items:**

| # | File(s) | Change |
|---|---|---|
| 0.1 | `examples/nvoos-dictate/` (all scaffold files) | `pnpm create tauri-app` equivalent layout hand-placed to match repo conventions; Cargo workspace member; `rust-toolchain.toml` |
| 0.2 | `src-tauri/capabilities/{overlay,main}.json` | Minimal permission sets from day one — empty capability = no IPC (never start with `core:default` everywhere) |
| 0.3 | `src-tauri/tauri.conf.json` | Strict CSP (`default-src 'self'`), windows: main (settings/history) + overlay (always-on-top, focusless) |
| 0.4 | `.github/workflows/build-nvoos-dictate.yml` | `cargo fmt --check`, `clippy -D warnings`, `cargo test`, frontend `lint` + `build`, MSVC/CMake setup for whisper-rs builds |
| 0.5 | `examples/nvoos-dictate/README.md` | Build/run/model-download/privacy; placeholder sections filled per phase |

**Exit:** `cargo build` + `pnpm build` green in CI; `cargo tauri dev` launches the empty two-window app.

## Phase 1 — P0: Windows dictation MVP (3–5 days)

Ordered by risk, not by module:

**1.1 Hotkey + paste loop (day 1 — the riskiest piece).**

| # | File(s) | Change |
|---|---|---|
| 1.1a | `src-tauri/src/hotkey/win_hook.rs` | `WH_KEYBOARD_LL` hook on a dedicated OS thread with `GetMessageW` loop; emits Press/Release/Hold state machine (right Alt default; double-tap → toggle mode; Esc → cancel) |
| 1.1b | `src-tauri/src/hotkey/fallback.rs` | 50 ms `GetAsyncKeyState` poll fallback + `RegisterHotKey`-based non-modifier alternate; unified `HotkeyEvent` stream the rest of the app consumes |
| 1.1c | `src-tauri/src/hotkey/mod.rs` | Merge hook+fallback (dedupe), expose `HotkeySource`; log which source fired (diagnostic for the Chromium-focus bug) |
| 1.1d | `src-tauri/src/paste/{mod,arboard_enigo}.rs` | Clipboard snapshot → set → `Ctrl+V` (or `Shift+Insert` fallback for terminals) → restore; `PasteBackend` trait |
| 1.1e | `tests/` + manual matrix | Manual acceptance matrix: Chrome, VSCode, Notepad, Word, Windows Terminal, WebView2 — recorded in README `docs/ptt-matrix.md` |

**1.2 Audio pipeline (day 2).**

| # | File(s) | Change |
|---|---|---|
| 1.2a | `src-tauri/src/audio/capture.rs` | `cpal` input stream, 16 kHz mono f32; opened once, resident |
| 1.2b | `src-tauri/src/audio/ring_buffer.rs` | ~300 ms pre-roll ring buffer consumed at press |
| 1.2c | `src-tauri/src/audio/vad.rs` | Energy VAD (default) + optional `silero-vad` feature; phrase-commit: after ≥2 s speech + pause, hand finished phrase to STT while key still held (murmur trick) |
| 1.2d | `src-tauri/src/stt/engine.rs` | `SttEngine` facade trait: `transcribe_phrase()`, `transcribe_file()`, `warm()`, engine enum + selection |

**1.3 STT engines.**

| # | File(s) | Change |
|---|---|---|
| 1.3a | `src-tauri/src/stt/parakeet.rs` (+ `model_registry.rs`) | ✅ **Done** — compiled against sherpa-onnx 1.13.8 (crate doc-example API); registry downloads + SHA-256 + tar.bz2 extraction tested |
| 1.3b | `src-tauri/src/stt/whisper.rs` | ✅ **Done** — API-verified against whisper-rs 0.16; built on Windows with LLVM 23.1.3 (`LIBCLANG_PATH` + CMake on PATH; `WHISPER_DONT_GENERATE_BINDINGS=1` is Linux-only since the checked-in bindings are glibc-specific); live-tested (tiny.en, WER 7.7%) |
| 1.3c | `src-tauri/src/stt/moonshine.rs` | ✅ Feature compiles (ort rc.9 + ort-sys rc.9 pinned); the streaming ONNX loop remains the documented spike |
| 1.3d | `src-tauri/src/stt/embedded.rs` (+ `engine.rs` arm) | ✅ **Done** — remote fallback: WAV → base64 → `POST /wp-json/mcp-ai/v1/embedded/transcribe`; Basic (app password) preferred, Bearer fallback; contract tests |
| 1.3e | `bench/transcribe_file.rs` | ✅ Harness exists as `src-tauri/examples/transcribe_file.rs` (WER + e2e_ms + RTF) — live-verified with both engines; bench corpus + CI thresholds pending |

**1.4 Deterministic cleanup (day 4 — pure Rust, the highest-test-value module).**

| # | File(s) | Change |
|---|---|---|
| 1.4a | `src-tauri/src/cleanup/rules/` | `punctuation.rs` (sentence boundaries), `case.rs` (capitalization), `fillers.rs` (um/uh/like lists, locale-aware), `numbers.rs` ("five two nine" → 529 per OpenAI cookbook), `vocabulary.rs` (dictionary substitution), `per_app.rs` (profiles keyed by foreground-app category) |
| 1.4b | `src-tauri/src/cleanup/mod.rs` | Two-stage pipeline: Stage-1 rules (always, sync, fast) → Stage-2 provider (optional, async, never blocks paste) |
| 1.4c | `src-tauri/src/cleanup/providers/{disabled,openai_compat}.rs` | Provider trait + first two impls (openai_compat covers Ollama/OpenAI-compatible endpoints) |
| 1.4d | `tests/cleanup_golden.rs` + `tests/fixtures/cleanup/*.txt` | Golden fixtures: raw → expected per rule + per-app profile; ≥ 20 cases at P0 exit |

**1.5 UI, storage, observability (day 5).**

| # | File(s) | Change |
|---|---|---|
| 1.5a | `src-tauri/src/storage/{db,migrations}.rs` | SQLite (WAL): `transcripts(id, text_raw, text_clean, engine, model, target_app, e2e_ms, created_at)`, `settings`, `dictionary`; migrations embedded |
| 1.5b | `src/` React UI | Settings (hotkey, engine/model, per-app profiles, dictionary), History (search, copy, delete, export), overlay widget (non-focus-stealing, DPI-aware, drag-to-position persisted per monitor) |
| 1.5c | `src-tauri/src/config.rs` + IPC commands | Typed commands (`set_hotkey`, `get_settings`, …) — every IPC input validated in Rust (Tauri trust-boundary rule) |
| 1.5d | e2e/ | Playwright: settings round-trip, history search, overlay states |

**P0 exit criteria (all must pass):**

- PTT matrix (1.1e) green including **Chrome-focused** and **WebView2-focused** cases
- `tests/cleanup_golden.rs` ≥ 20 cases green; cleanup never requires network
- Bench: median e2e (key-up → pasted) **< 1.5 s** on reference machine for 5 s utterances
- `cargo test`, clippy `-D warnings`, fmt, frontend lint green; NSIS artifact builds in CI
- Zero telemetry; audio not persisted by default

## Phase 2 — P1: NV oOS plugin integration (2–3 days)

| # | File(s) | Change |
|---|---|---|
| 2.1 | `src-tauri/src/nvoos/client.rs` | reqwest client bound to the configured origin; `Authorization: Bearer cred_XXXXX.SECRET`; `POST /wp-json/mcp-ai/v1/chat` with a configurable cleanup system prompt + transcript + target-app category; streaming (SSE) optional — non-streaming first |
| 2.2 | `src-tauri/src/nvoos/types.rs` | Response DTOs mirroring the chat contract (message content as segments — see test-suite skill: content is `array{type,text}`); defensive parsing for both string and array shapes |
| 2.3 | `src-tauri/src/cleanup/providers/nvoos.rs` | `nvoos` provider: on success swap clipboard (only if paste hasn't landed), on failure fall through to Stage-1 output + logged non-blocking error; per-app enable toggle (chat/code/terminal/etc.) |
| 2.4 | Settings UI + keyring wiring | Site URL + credential setup wizard: test-connection call (a cheap authenticated endpoint) → store credential via `keyring`; never echo the secret back |
| 2.5 | `src-tauri/src/nvoos/archive.rs` | One-way sync: finished transcripts → `/mcp-ai/v1/chat-transcripts` (or Pro Paper Store `paper_store_write` when the site reports it available); local history remains source of truth |
| 2.6 | `src-tauri/src/stt/embedded.rs` + settings | Remote STT via the Embedded addon's REST route (`POST /mcp-ai/v1/embedded/transcribe`, base64 WAV) for machines without local models — labeled "Embedded (plugin)" in the UI; auth: WP application password (Basic) with assistant-credential support as the addon-side follow-up PR |
| 2.7 | `tests/nvoos_client.rs` | Integration against Docker WP (repo `docker-compose.yml`, seeded assistant credential); cassette mode (recorded HTTP fixtures) for offline CI; asserts: 401 on bad credential is definitive, no retry-without-auth fallback |

**P1 exit:** integration tests green against Docker WP; credential never appears in logs/files; cleanup latency unchanged when provider disabled; archive sync idempotent (duplicate transcripts deduped); embedded-STT contract tests green from cassettes (401 definitive, 413/429 mapped).

## Phase 3 — P2: Meetings (2–3 days)

| # | File(s) | Change |
|---|---|---|
| 3.1 | `src-tauri/src/meetings/loopback.rs` (`cfg(windows)`) | WASAPI loopback in-process (`cpal` recent; process-loopback `AUDIOCLIENT_ACTIVATION_TYPE_PROCESS_LOOPBACK` where available with fallback to endpoint loopback — steno PR #175 pattern) |
| 3.2 | `src-tauri/src/meetings/recorder.rs` | Explicit "Record" action only; mic + system mix; visible recording indicator + consent notice; stop → save to temp (no retention by default) |
| 3.3 | `src-tauri/src/meetings/chunker.rs` | Parakeet's ~4–5 min limit: VAD-based chunking + stitch; long files route to whisper.cpp engine automatically |
| 3.4 | Meetings UI | Record/stop, live status, transcript view, "send to NV oOS" action (reuses 2.1/2.5), export .md |
| 3.5 | `tests/` | Chunker unit tests (segment boundaries, stitching); loopback smoke test marked `#[ignore]` (needs audio hardware) + manual matrix |

**P2 exit:** real-call loopback capture verified; >5 min meeting transcribed correctly chunked; consent UX reviewed.

## Phase 4 — P3: Local MCP server (1–2 days + spike)

| # | File(s) | Change |
|---|---|---|
| 4.0 | **Spike:** inspect `addons/pro/includes/class-wp-mcp-ai-jetengine-mcp-client.php` + `mcp-apps/` transport negotiation | Determines whether Remote Sites speaks legacy HTTP+SSE (2024-11-05) or streamable HTTP (2025-03-26+). Output: decision record in `docs/` |
| 4.1 | `src-tauri/src/mcp/server.rs` | `rmcp` server: tools `dictate`, `transcribe_meeting`, `get_dictation_history`, `get_dictionary`; stdio transport for desktop AI clients |
| 4.2 | `src-tauri/src/mcp/bridge_legacy_sse.rs` | Only if 4.0 requires: legacy HTTP+SSE bridge (oxml-mcp PR #57 pattern) on `127.0.0.1` |
| 4.3 | `src-tauri/src/mcp/tools.rs` | Tool handlers reusing the existing engine facade — no duplicated audio logic; JSON Schemas typed via rmcp derives |
| 4.4 | Docs | `examples/nvoos-dictate/docs/remote-sites-recipe.md` — host allowlist (`localhost`), Remote Sites "MCP Server" connection setup per the elementor-MCP-connection skill's allowlist notes |
| 4.5 | `tests/mcp_server.rs` | rmcp in-memory transport test: tool list, `dictate` round-trip with injected fake engine |

**P3 exit:** plugin assistant calls `dictate` end-to-end via Remote Sites (or the documented blocker with the decision record).

## Phase 5 — P4: Linux (2–4 days)

| # | File(s) | Change |
|---|---|---|
| 5.1 | Hotkey | `tauri-plugin-global-shortcut` (X11) as primary; document Wayland limits (portal / Shortcuts inhibit) in README; overlay-focus behavior re-verified |
| 5.2 | Audio | cpal (ALSA/PulseAudio/PipeWire); no loopback for meetings on Linux v1 — meetings marked unsupported until P4.1 |
| 5.3 | Secrets | `keyring` → Secret Service; DBus session requirement documented |
| 5.4 | Packaging | AppImage + deb via tauri-action; CI matrix extends |

**P4 exit:** P0 feature parity on X11; Wayland gaps documented.

## Phase 6 — P5: macOS (3–5 days)

| # | File(s) | Change |
|---|---|---|
| 6.1 | System audio sidecar | Thin Swift helper process feeding PCM to the Rust core (dev.to pattern); signed/notarized later |
| 6.2 | Hotkey | global-shortcut plugin (Accessibility permission flow); overlay permissions checklist in README |
| 6.3 | Secrets | `keyring` → Keychain |
| 6.4 | Packaging | .app + updater; notarization requires the Apple Developer Program — document as distribution prerequisite |

## Test plan summary

| Layer | Tooling | Scope |
|---|---|---|
| Unit (Rust) | `cargo test` | rules engine, chunker, config, providers, hotkey state machine (injectable key events) |
| Golden | `tests/cleanup_golden.rs` fixtures | raw→expected per rule/profile; the regression backstop for cleanup |
| Bench | `bench/transcribe_file.rs` | WER + e2e_ms per engine/model on fixed corpus; threshold asserted in CI with tolerance |
| Integration | Docker WP (`docker-compose.yml` + seeded credential) with cassette fallback | nvoos client auth (401 definitive), archive idempotency, chat response parsing |
| MCP | rmcp in-memory transport + fake engine | tool schema, dictate round-trip |
| UI | Playwright (`e2e/`) | settings, history, overlay states, first-run wizard |
| Manual matrix | `docs/ptt-matrix.md` | Chrome/VSCode/Word/terminal/WebView2 focus cases per release |

## CI/CD wiring

- `build-nvoos-dictate.yml` — on PRs: fmt/clippy/test/frontend-lint/build; matrix target `windows-latest` (P0–P3), + `ubuntu-latest` from P4.
- `publish-nvoos-dictate.yml` — on tags: `tauri-action` (NSIS+MSI, updater JSON feed), minisign via `TAURI_SIGNING_PRIVATE_KEY` secret, SHA-256 checksums attached to the release; SignPath signing as a documented follow-up (SmartScreen note per dictto).
- `cargo deny` job (licenses+bans) — enforces the no-AGPL rule (faster-whisper/pyannote never enter the tree).

## Security checklist (enforced in CI where possible)

| # | Requirement |
|---|---|
| S1 | Capabilities scoped per window label; no `shell`; overlay window has no network/storage permissions |
| S2 | Strict CSP; `connect-src` only the configured NV oOS origin; no remote content |
| S3 | Secrets only via OS keychain; CI job greps built artifacts for `cred_` patterns (fails on match) |
| S4 | All IPC command inputs validated in Rust; no raw filesystem paths from the webview |
| S5 | Model downloads SHA-256-verified before use |
| S6 | Audio: push-to-talk/explicit-record only; no audio persisted unless user opts in; meeting recording indicator |
| S7 | No telemetry; updater artifacts signature-verified (minisign) |
| S8 | `cargo deny` blocks AGPL/GPL-incompatible deps; `cargo audit` in CI |

## Acceptance criteria (summary)

- P0: PTT matrix green (incl. Chromium focus), golden cleanup ≥ 20, e2e < 1.5 s median, NSIS in CI, no telemetry/audio retention
- P1: Docker WP integration green, credential keychain-only, 401 definitive, archive idempotent, embedded remote-STT contract green (ADR 0003)
- P2: loopback meeting capture verified, >5 min chunked transcription, consent UX
- P3: assistant↔app MCP round-trip via Remote Sites (or documented decision record)
- P4/P5: feature parity per phase with platform gaps documented in README

## Deliberately out of scope / deferred

- Speaker diarization (AGPL pyannote excluded; revisit with MIT alternatives)
- Wake-word/always-on listening
- Cloud-hosted NV oOS infrastructure (user's site is the backend)
- Mobile clients (repo SPA/embedded surfaces cover)
- Voice commands / read-aloud (murmur-style extras) — post-P5 backlog

## Sources

- Tauri security/CSP/capabilities: https://v2.tauri.app/security/ · https://v2.tauri.app/security/csp/ · https://v2.tauri.app/security/capabilities/
- Global shortcut plugin (Rust ≥ 1.90): https://v2.tauri.app/plugin/global-shortcut/
- WH_KEYBOARD_LL message-loop requirement: https://stackoverflow.com/questions/51033906/how-to-use-setwindowshookex-in-rust
- Chromium-focus hook delivery bug: https://dev.to/wudaming00/windows-silently-stops-delivering-whkeyboardll-hook-events-when-a-chromium-window-has-focus-and-bi8
- Modifier PTT precedent: https://github.com/pathorsAI/parley/issues/462
- Paste injection precedent: https://github.com/augusto-dmh/fala/pull/57 · https://github.com/enigo-rs/enigo
- STT crates: https://crates.io/crates/transcribe-rs · https://github.com/tazz4843/whisper-rs · https://github.com/pykeio/ort · https://docs.rs/moonshine-ort · https://lib.rs/crates/parakeet-rs
- Parakeet model + license: https://huggingface.co/nvidia/parakeet-tdt-0.6b-v3 · https://github.com/k2-fsa/sherpa-onnx
- Embedded addon STT (remote path, ADR 0003): `addons/embedded/includes/class-wp-mcp-ai-embedded-transcribe.php` · `addons/embedded/includes/class-nvoos-embedded.php` · `addons/embedded/README.md`
- Silero VAD Rust: https://docs.rs/silero-vad-rs · https://crates.io/crates/voice_activity_detector
- Post-processing standards: https://github.com/openai/openai-cookbook/blob/main/examples/Whisper_processing_guide.ipynb · https://github.com/huggingface/speechbox · https://github.com/Knuckles92/OpenWhisper/issues/40
- MCP Rust SDK + transports: https://github.com/modelcontextprotocol/rust-sdk · https://github.com/sebastienrousseau/oxml-mcp/pull/57
- Keyring: https://docs.rs/keyring/latest/keyring/ · https://crates.io/crates/tauri-plugin-secure-keystore
- CI/updater: https://github.com/tauri-apps/tauri-action · https://v2.tauri.app/distribute/pipelines/github/ · https://v2.tauri.app/plugin/updater/
- WASAPI loopback precedents: https://github.com/NicolaiSchmid/steno/pull/175 · https://github.com/pathorsAI/parley/pull/453
- macOS sidecar pattern: https://dev.to/baurzhan_zhetenov_442c4cd/how-i-capture-system-audio-and-transcribe-it-locally-with-no-server-in-the-loop-tauri-rust--4a0n
