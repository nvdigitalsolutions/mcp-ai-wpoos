# NV oOS Dictation — macOS Port: Implementation Plan

**Status:** In progress — phases M0–M6 implemented on `feat/nvoos-dictate-macos-port` (code complete, unverified on macOS hardware); M7 publish wiring done (universal build M7.5 shipped — single arm64+x86_64 DMG); M8 matrix pending first signed builds
**Date:** 2026-10-12 (implementation started)
**Scope:** Port `examples/nvoos-dictate` (Tauri v2 + Rust + React/TS) from Windows/MSVC to macOS (Apple Silicon + Intel), reaching P0/P1/P2 feature parity with macOS-native permissions, system-audio capture, and distribution
**Related:** `docs/project/proposals/067-tauri-dictation-companion-app-implementation-plan.md` (Phase 6 is the 4-row sketch this plan replaces), `examples/nvoos-dictate/docs/decisions/0001–0003-*.md`, `.agents/skills/mcp-ai-wpoos-dictation-app`

## 1. Goal & success criteria

Ship the same product on macOS: push-to-talk dictation → local STT →
deterministic cleanup → paste into the focused app, plus meeting transcription
with system audio, the NV oOS plugin enrichment backend, and the MCP server —
behind a macOS permission model that is honest, minimal, and self-serviceable
from a first-run wizard.

**Hard success criteria (all must pass before "shipped"):**

- P0 parity: PTT (hold + double-tap toggle + Esc cancel), paste with clipboard
  save/restore, cleanup golden tests, history/settings UI, overlay — verified on
  Apple Silicon and Intel hardware
- `cargo test` + clippy `-D warnings` green on `macos-latest` CI for default and
  `mcp` feature sets; native engine features compile on macOS
- First-run permission wizard: Microphone, Accessibility, Input Monitoring,
  Screen Recording each detect/request/link-to-System-Settings correctly;
  every denied permission degrades gracefully with an in-app explanation
- Meetings: system audio captured on macOS 14.2+ via the ScreenCaptureKit
  sidecar; mic-only fallback everywhere else; > 5 min chunked transcription
- Release artifact: signed + notarized universal `.app` in a `.dmg` (arm64 +
  x86_64 — M7.5), updater feed wired; unsigned dev-build recipe documented
- No telemetry, no audio retention by default, secrets only in Keychain
  (unchanged product invariants)

## 2. Current state (what the code already does right)

The codebase is already structured for this port — the Windows-specific code is
isolated behind `#[cfg(windows)]` with non-Windows fallbacks in place:

| Area | Windows | Non-Windows today | macOS work needed |
|---|---|---|---|
| PTT hotkey | `hotkey/win_hook.rs` (WH_KEYBOARD_LL) + `fallback.rs` | `PluginToggleListener` (tauri-plugin-global-shortcut, **toggle** semantics, no hold) | Hold-semantics listener + permission flow (Phase M2) |
| Paste | `arboard` + `enigo` behind `PasteBackend` trait | same (enigo has a macOS backend) | Verify enigo macOS path; core-graphics fallback backend (Phase M3) |
| Mic capture | `audio/capture.rs` (cpal) | same — cpal is CoreAudio on macOS | TCC denial mapping + device picker verification (Phase M4) |
| VAD | energy + silero (ort rc.9 pinned) | same | verify ort rc.9 macOS binaries load (Phase M4) |
| STT engines | parakeet (sherpa-onnx), whisper (whisper-rs), embedded remote | same code paths | macOS builds, Metal acceleration, WER harness on Mac hardware (Phase M4) |
| Meetings loopback | `meetings/loopback.rs` (WASAPI) | `system_audio_available() = false` | Swift ScreenCaptureKit sidecar (Phase M5) |
| Per-app cleanup | `util/win_foreground` | falls back to `Default` category | NSWorkspace frontmost app (Phase M6) |
| Storage/models/config | `dirs::data_dir()` / `dirs::config_dir()` | already correct on macOS (`~/Library/Application Support/nvoos-dictate`) | verify only (Phase M0) |
| Secrets | keyring → Credential Manager | keyring → Keychain | ACL behavior on unsigned dev builds (Phase M0/M7) |
| Windows | `cfg(windows)` deps + `tauri.conf.json` NSIS/MSI | bundle targets not set | .dmg, Info.plist, entitlements, notarization (Phase M7) |

**The trait/`cfg` seams are already the right ones.** No refactor of the
dictation controller is required; every macOS implementation slots into an
existing seam (`HotkeyListener`, `PasteBackend`, `system_audio_available`,
`foreground_category`).

## 3. macOS platform realities that shape the plan

### 3.1 Permission model (TCC) — the defining constraint

macOS gates every capability this app needs behind a separate, user-grantable
permission. The plan treats permission UX as a first-class feature, not a
distribution footnote:

| Capability | TCC permission | How the user grants | API to detect/request | Consequence if denied |
|---|---|---|---|---|
| Microphone capture | Microphone | OS prompt on first stream open | `NSMicrophoneUsageDescription` (prompt) + stream-open error mapping | Dictation & meetings dead → wizard blocks with deep link |
| Paste keystroke injection (CGEventPost) | Accessibility | System Settings → Privacy & Security → Accessibility | `AXIsProcessTrustedWithOptions()` | PTT works, paste fails → fall back to "copy to clipboard only" mode |
| Global modifier PTT (CGEventTap, swallow) | Accessibility **and** Input Monitoring | Input Monitoring has **no one-click prompt** — user must enable manually after `CGRequestListenEventAccess()` opens the pane | `CGPreflightListenEventAccess()` / `CGRequestListenEventAccess()` | Modifier-hold PTT unavailable → permission-free combo PTT (plugin) remains default |
| System audio (meetings) | Screen Recording | macOS 14.2+: `SCContentSharingPicker`; 13.x: `CGRequestScreenCaptureAccess()` | `CGPreflightScreenCaptureAccess()` / picker result | Meetings continue mic-only |
| Keychain secrets | (implicit) | OS keychain prompt per ACL | — | Documented dev-build caveat (§3.4) |

**Consequence:** the macOS default PTT must be permission-free. A
`RegisterEventHotKey`-based combo (Carbon, what the plugin uses) works with
zero permissions, but **modifier-only hotkeys cannot be registered via Carbon**
— so hold-to-talk on a bare Right Option requires the CGEventTap path
(Accessibility + Input Monitoring). Plan: default = toggle combo
`Cmd+Option+Space` (plugin, zero permissions); Right-Option hold PTT = opt-in
"Pro PTT" mode gated by the event tap + permission wizard. This mirrors the
Windows belt-and-braces philosophy (a permission-free path must always exist).

### 3.2 Event taps (CGEventTap) — the Windows-hook analogue

The Windows discipline in this repo (dedicated thread, swallow both down+up,
standalone-only detection, synthetic key-up on exit) translates 1:1 to
`CGEventTapCreate(.cghidEventTap, .headInsertEventTap, .default, [keyDown,
keyUp, flagsChanged])`:

- **Standalone-only swallow:** Right Option (keyCode 61) that goes down and up
  with no interleaved keys is swallowed (both events — return `nil`); if any
  other key arrives while held, it's an Option-combo (Option+arrow, Option+char)
  → forward the whole sequence untouched. Same `ptt_pressed_alone` rule as
  `win_hook.rs`; Option-combos are common on macOS, so this is even more
  load-bearing here.
- **Tap timeout:** macOS disables a tap that stalls (`kCGEventTapTimeout`); the
  listener must re-enable via `CGEventTapEnable` and surface tap health in
  `hotkey_diagnostics` (parity with the Windows fallback heartbeat).
- **Exit discipline:** if the app dies mid-swallow, the modifier is not
  "stuck" at the OS level the way Windows synthesizes it (the tap dies with the
  process), but the pending-swallow bookkeeping still applies for the
  release-injection path; keep it symmetric with the Windows implementation.

### 3.3 ScreenCaptureKit (macOS 13+) — the only sanctioned loopback

- cpal cannot open loopback streams on macOS. The virtual-driver route
  (BlackHole etc.) cannot be bundled. **ScreenCaptureKit audio capture is the
  only distributable answer**, and it is a Swift API — hence the sidecar.
- macOS 14.2+ has `SCContentSharingPicker` (system picker UI, best consent
  UX); macOS 13.x supports direct `SCStream` with audio-only config. Plan
  targets **14.2+ as the full-experience path and 13.x as a degraded path**
  (shareable-content enumeration, no picker). Minimum OS decision in §4.
- Audio-only capture still consumes the Screen Recording TCC slot.

### 3.4 Signing, hardening, notarization

- The app runs **hardened runtime, unsandboxed** (global hotkeys, event taps,
  child-process helper, and keychain ACLs are incompatible with App Sandbox).
  Distribution outside the Mac App Store therefore **requires notarization**,
  which requires the Apple Developer Program ($99/yr). This is a documented
  external prerequisite, not a blocker for development.
- Ad-hoc-signed dev builds (`codesign -s -`) work locally after
  `xattr -dr com.apple.quarantine <app>`; Keychain entries created by
  unsigned/ad-hoc builds can re-prompt because the ACL binds to the code
  signature — document, and verify behavior after the first signed build.
- Hardened runtime requires the `com.apple.security.device.audio-input`
  entitlement for microphone access. Tauri v2 merges `src-tauri/Info.plist`
  automatically; entitlements are wired via `bundle.macOS.entitlements`.

## 4. Architecture decisions (to be recorded as ADR 0004)

| # | Decision | Rationale |
|---|---|---|
| D1 | Minimum OS = macOS 13.0 (Ventura) | ScreenCaptureKit floor; drops the macOS 12 "no meetings" branch; Tauri v2 supports far lower, so this is a product choice, enforced via `minimumSystemVersion` |
| D2 | Default PTT = plugin combo toggle (zero permissions); Right-Option hold = opt-in event-tap mode | Permissions must never block first dictation (see §3.1) |
| D3 | System audio via a bundled Swift helper (`externalBin`), speaking newline-delimited JSON over stdout | ScreenCaptureKit is Swift-only; stdout framing reuses the app's existing JSON/base64 tooling; no network sockets, no daemon |
| D4 | Foreground-app detection via `objc2` + `objc2-app-kit` (NSWorkspace) in Rust | Avoids a second sidecar process; target-scoped deps keep the Windows tree untouched |
| D5 | Paste = enigo macOS backend if verified, core-graphics `CGEventPost` backend as the drop-in fallback | `PasteBackend` trait exists; do not fork the Windows path |
| D6 | Per-arch DMGs (arm64 + x86_64) first; universal binary as a fast-follow | Universal builds complicate the Swift helper + whisper Metal flags; defer until both arches verified (universal shipped — see M7.5) |
| D7 | App lifecycle parity with Windows for v1 (exit on window close); menu-bar tray deferred | Tray-only apps need LSUIElement + reopen handling; not required for P0 parity |

## 5. Phase M0 — Compile baseline & CI (1 day)

**Exit:** `cargo fmt --check`, clippy `-D warnings`, `cargo test` (default +
`mcp`) green on `macos-latest`; dev build boots and stays resident.

| # | File(s) | Change |
|---|---|---|
| M0.1 | `.github/workflows/build-nvoos-dictate.yml` | Add `macos-latest` job: fmt/clippy/test (default + `--features mcp`), frontend lint + build. Native engines stay local gates initially (§14) |
| M0.2 | `src-tauri/Cargo.toml` | Add `[target.'cfg(target_os = "macos")'.dependencies]`: `core-graphics` (event taps + CGEventPost), `macos-accessibility-client` (`applicationIsTrusted()` — the crate enigo itself uses for the AX check), `objc2` + `objc2-app-kit` (foreground app) — all target-scoped, zero impact on Windows builds |
| M0.3 | `src-tauri/src/{state,config,storage}/` | Verify `dirs::*` resolution on macOS (`~/Library/Application Support/nvoos-dictate/…`); fix any Windows-isms (none known — `%APPDATA%` is not hardcoded) |
| M0.4 | `src-tauri/src/ipc.rs` | `get_diagnostics` already reports `OS/ARCH` — confirm it renders `macos/aarch64` correctly; add macOS permission statuses later (M1/M2) |
| M0.5 | `README.md` | macOS prereqs: Xcode CLT (CMake, libclang, Swift), Node 20+, `rustup target add aarch64-apple-darwin x86_64-apple-darwin` |

**Boot smoke test (macOS):** `./nvoos-dictate & sleep 10; pgrep -x nvoos-dictate`
(process alive = boot OK) — the macOS equivalent of the Windows tasklist check.

## 6. Phase M1 — Permissions & first-run wizard (1–2 days)

**Exit:** all four permissions detected/requestable from the wizard; each
denied state degrades gracefully; deep links open the correct System Settings
pane.

| # | File(s) | Change |
|---|---|---|
| M1.1 | `src-tauri/Info.plist` | `NSMicrophoneUsageDescription` ("NV oOS Dictation uses the microphone for push-to-talk dictation and meeting recording. Audio is processed on-device and never stored without your consent.") — Tauri merges this file automatically |
| M1.2 | `src-tauri/entitlements.plist` (new) | `com.apple.security.device.audio-input` (+ `com.apple.security.cs.allow-unsigned-executable-memory` if whisper/ort demand it — verify); wired via `bundle.macOS.entitlements` + `hardenedRuntime: true` |
| M1.3 | `src-tauri/src/permissions/` (new module) | `PermissionStatus { kind, granted, promptable }` for Microphone (stream-open attempt → map TCC denial to a readable error), Accessibility (`AXIsProcessTrusted`), Input Monitoring (`CGPreflightListenEventAccess`), Screen Recording (`CGPreflightScreenCaptureAccess`); `request_*` fns |
| M1.4 | `src/` wizard step | macOS-only step: four rows (state icon, request button, "Open System Settings" deep link — `x-apple.systempreferences:com.apple.preference.security?Privacy_<Microphone|Accessibility|ListenEvent|ScreenCapture>` via `tauri-plugin-opener`), re-check on window focus (permissions change while the app runs) |
| M1.5 | IPC | `get_permissions` / `request_permission(kind)` commands; Input Monitoring row must explain the no-one-click-prompt caveat and show *exactly* where to toggle |

## 7. Phase M2 — PTT hotkey (1–2 days — the riskiest piece)

**Exit:** hold PTT (Right Option) with double-tap toggle + Esc cancel on a real
Mac; standalone-only swallow verified against Option-combo apps (Safari,
VSCode, Terminal); tap-health surfaced in Diagnostics.

| # | File(s) | Change |
|---|---|---|
| M2.1 | `src-tauri/src/hotkey/macos_event_tap.rs` (new, `cfg(target_os = "macos")`) | `MacEventTapListener: HotkeyListener` — `CGEventTapCreate` on a dedicated thread + `CFRunLoop`; emits the existing `HotkeyEvent` enum (Pressed / Released{shift} / DoubleTap / Esc); standalone-only swallow (§3.2); tap-timeout re-enable; pending-swallow release on `stop()` (mirror `release_pending_swallow`) |
| M2.2 | `src-tauri/src/hotkey/mod.rs` | `platform_listener()` macOS arm → event-tap listener when settings say modifier PTT, else `PluginToggleListener`; config gains `ptt.mode = combo|modifier` (combo default per D2) |
| M2.3 | `src-tauri/src/ipc.rs` | `hotkey_diagnostics()` macOS arm: tap installed/disabled, timeout count, mode; permission statuses from M1 |
| M2.4 | Settings UI | macOS PTT section: mode picker, permission badges, "open Accessibility" / "open Input Monitoring" links |
| M2.5 | `src-tauri/tests/hotkey_macos.rs` | State-machine tests with injected synthetic key sequences (standalone swallow, combo passthrough, double-tap, Esc, exit-discipline) — serialize on a shared `TEST_LOCK` like the Windows hook tests |

## 8. Phase M3 — Paste injection (0.5–1 day)

**Exit:** paste works into Safari/Chrome/VSCode/Terminal/Notes with clipboard
restored; "copy to clipboard only" fallback active when Accessibility is
denied.

| # | File(s) | Change |
|---|---|---|
| M3.1 | `src-tauri/src/paste/macos_cgevent.rs` (new) | `CGEventPost`-based `PasteBackend` (Cmd+V via `kVK_ANSI_V` + `.maskCommand`, posted at `.cghidEventTap`) with arboard clipboard save/restore — the drop-in fallback behind the existing trait |
| M3.2 | `src-tauri/src/paste/mod.rs` | Backend selection: try enigo macOS first (verify `enigo 0.6` macOS path at first compile — it has maintenance gaps), else `macos_cgevent`; Accessibility status gates the "copy-only" degraded mode (M1) |
| M3.3 | `docs/ptt-matrix-macos.md` (start) | Paste matrix rows per app |

## 9. Phase M4 — Audio capture, VAD, engines (1–2 days)

**Exit:** parakeet + whisper transcribe real speech on Mac hardware (WER
harness); model downloads via the existing registry; silero VAD loads.

| # | File(s) | Change |
|---|---|---|
| M4.1 | `src-tauri/src/audio/capture.rs` | Verify CoreAudio path (device picker, 16 kHz request); map TCC mic denial to `AppError::Audio("Microphone permission denied — grant it in System Settings")` |
| M4.2 | `src-tauri/src/stt/parakeet.rs` | macOS build verification (sherpa-onnx CMake build on arm64 + x86_64; prebuilt libs where available); model registry paths already correct |
| M4.3 | `src-tauri/src/stt/whisper.rs` + `Cargo.toml` | CMake + libclang come from Xcode CLT (no LLVM winget dance needed); **Metal**: enable `whisper-rs`'s `metal` feature for macOS via a target-scoped dependency (or feature unification) — verify against the vendored `vendor/whisper-rs-sys` patch tree; if it fights the patch, land CPU first and track Metal as a follow-up |
| M4.4 | `src-tauri/src/stt/moonshine.rs`, `audio/vad.rs` | ort rc.9 macOS binaries (aarch64/x86_64) load + silero_vad.onnx download — verify only (pins already correct) |
| M4.5 | `src-tauri/examples/` | Run `mic_probe` + `transcribe_file` (WER/RTF) on real Mac hardware — the same local-gate numbers recorded for Windows (target: < 1.5 s key-up→paste budget) |

## 10. Phase M5 — Meetings: system audio sidecar (2–3 days)

**Exit:** real-call loopback capture verified on macOS 14.2+; mic-only fallback
correct on denial; > 5 min chunked transcription.

| # | File(s) | Change |
|---|---|---|
| M5.1 | `examples/nvoos-dictate/mac-helper/` (new Swift package) | `nvoos-audio-helper` CLI using ScreenCaptureKit: 14.2+ `SCContentSharingPicker` flow; 13.x direct `SCStream` (audio-only config, `SCShareableContent` enumeration). stdout NDJSON framing: `hello`, `ready`, `chunk{seq,sample_rate,channels,b64_pcm16}`, `stopped`, `error{msg}` |
| M5.2 | `src-tauri/src/meetings/macos_system_audio.rs` (new, `cfg(target_os = "macos")`) | Spawn the helper (`current_exe().parent()` — Tauri `externalBin` places it in `Contents/MacOS`), parse NDJSON, downmix/resample to the recorder's f32 stream, kill on stop; `system_audio_available()` → true when helper present and Screen Recording granted |
| M5.3 | `src-tauri/tauri.conf.json` | `bundle.externalBin: ["binaries/nvoos-audio-helper"]`; helper built per-arch in CI before `tauri build` |
| M5.4 | `meetings/recorder.rs` | Wire the sidecar into `start_streams` behind the existing `system_audio` flag; consent UX unchanged (explicit record, indicator, no retention) |
| M5.5 | Tests | NDJSON codec unit tests (fixture frames, truncation, bad base64); Swift helper unit tests (local gate); chunker unchanged (parakeet 4–5 min limit applies identically) |

## 11. Phase M6 — Per-app cleanup profiles (0.5–1 day)

**Exit:** target-app category recorded for Safari/Chrome/VSCode/Terminal/
Notes/Slack; unknown apps → `Default`.

| # | File(s) | Change |
|---|---|---|
| M6.1 | `src-tauri/src/util/macos_foreground.rs` (new, `cfg(target_os = "macos")`) | NSWorkspace `frontmostApplication` bundle ID via `objc2-app-kit`; bundle-ID → `AppCategory` mapping table (browser/code/terminal/chat/notes/…); replaces the `Default` fallback in `dictation.rs` |
| M6.2 | `src-tauri/tests/foreground_macos.rs` | Mapping-table unit tests + unknown-ID fallback |

## 12. Phase M7 — Packaging, signing, notarization, updater (2–3 days + queue time)

**Exit:** signed + notarized `.dmg` per arch from CI; updater `latest.json`
per-arch; unsigned dev-build recipe in README.

| # | File(s) | Change |
|---|---|---|
| M7.1 | `src-tauri/tauri.conf.json` | `bundle.targets: ["dmg"]`, `bundle.macOS.minimumSystemVersion: "13.0"` (D1), `hardenedRuntime: true`, `entitlements`, `externalBin`; icon.icns already present |
| M7.2 | `.github/workflows/publish-nvoos-dictate.yml` | macOS matrix (aarch64-apple-darwin, x86_64-apple-darwin) via `tauri-action`; helper built with `swift build` per arch before bundling; signing/notarization via `APPLE_CERTIFICATE`/`APPLE_CERTIFICATE_PASSWORD`/`APPLE_SIGNING_IDENTITY`/`APPLE_ID`/`APPLE_PASSWORD`/`APPLE_TEAM_ID` secrets |
| M7.3 | `README.md` + `docs/distribution-macos.md` | Apple Developer Program prerequisite; ad-hoc dev-build recipe (`codesign -s - --deep`, `xattr -dr com.apple.quarantine`); Gatekeeper expectations |
| M7.4 | Updater | Per-arch `platforms` entries in `latest.json`; pubkey generation for minisign once signing lands; **unsigned macOS builds cannot self-update** — document |
| M7.5 | Done (2026-10) | Universal binary via `universal-apple-darwin` (Rust lipo) + lipo'd Swift sidecar (`NVOOS_HELPER_ARCH=universal`); boot-smoke boots both slices (native + Rosetta) and `lipo -info`-verifies the bundle; publish ships a single universal DMG |

## 13. Phase M8 — QA matrix, docs, release (1 day)

| # | Deliverable |
|---|---|
| M8.1 | `docs/ptt-matrix-macos.md` — PTT + paste + overlay rows: Safari, Chrome, VSCode, Terminal, iTerm2, Notes, Slack, Zoom (meetings), Stage Manager/Spaces, external displays |
| M8.2 | README macOS section: permissions, min OS, engines on Apple Silicon, model dirs, keychain caveats |
| M8.3 | Privacy note update: sidecar audio path, no-retention default (unchanged) |
| M8.4 | Skill update: `.agents/skills/mcp-ai-wpoos-dictation-app` gains the macOS build/permission/troubleshooting section |

## 14. Test plan

| Layer | Tooling | macOS-specific scope |
|---|---|---|
| Rust unit | `cargo test` (macos-latest CI) | event-tap state machine, NDJSON codec, foreground mapping, permission status enum, paste trait impls |
| Golden | `tests/cleanup_golden.rs` | unchanged — must stay green (platform-free by design) |
| Integration | existing suites | `nvoos` client, `mcp` (feature), history — run unchanged on macOS |
| Native engines | local gates on Mac hardware | `mic_probe`, `transcribe_file` WER/RTF, `live_capture_delivers_samples` (`--ignored`), model download e2e |
| Helper | Swift Testing | framing, permission-denied path (local gate) |
| UI | Playwright (`e2e/`) | wizard flow incl. macOS permission step |
| Manual | `docs/ptt-matrix-macos.md` | full matrix per release, both CPU arches |

**CI matrix after M0:** Windows (existing) + macOS (default + `mcp`). Native
engine compile checks (`parakeet`, `whisper`, `moonshine`, `silero-vad`)
graduate to a macOS CI job only after the local gates pass — same policy as
the Windows track.

## 15. Risk register

| # | Risk | L×I | Mitigation |
|---|---|---|---|
| R1 | Input Monitoring has no one-click prompt — worst permission UX on macOS | H×H | Combo PTT is the permission-free default (D2); wizard walks through the pane manually; event-tap mode is opt-in |
| R2 | Right-Option swallow breaks Option-combos in target apps (Option+arrows etc.) | H×M | Standalone-only swallow (M2.1) + matrix rows for combo-heavy apps + per-app opt-out |
| R3 | ScreenCaptureKit differences 13.x vs 14.2+ (picker, audio-only maturity) | M×M | 14.2+ primary path, 13.x degraded, mic-only fallback; helper is fully replaceable |
| R4 | enigo 0.6 macOS maintenance gaps | M×M | `PasteBackend` trait + core-graphics backend from day one (M3.1) |
| R5 | whisper Metal vs vendored `whisper-rs-sys` patch interplay | M×M | Land CPU-only first; Metal as tracked follow-up (M4.3) |
| R6 | Notarization blocked on Apple Developer account | L×H (schedule) | Documented prerequisite; ad-hoc dev builds unblock all development; CI publish job waits on secrets |
| R7 | Keychain ACL re-prompts on unsigned dev builds | L×M | Document in README; verify after first signed build |
| R8 | ort/sherpa-onnx build quirks on arm64 (network-downloaded binaries, cmake) | L×M | Pins already in place from the Windows track; verify at M4 compile gate |
| R9 | Overlay window focus/always-on-top behavior differs on macOS | L×M | Dedicated matrix rows (M8.1); Tauri window flags otherwise unchanged |

## 16. Acceptance criteria (summary)

- M0: macOS CI green (fmt/clippy/test + `mcp`); app boots and stays resident
- M1: wizard handles all four permissions incl. the Input Monitoring manual
  path; denials degrade, never crash
- M2: hold PTT + double-tap + Esc on real Mac; no stuck-modifier events; tap
  health in Diagnostics
- M3: paste verified across the matrix; copy-only fallback on Accessibility
  denial
- M4: parakeet + whisper live-verified on Mac hardware; < 1.5 s e2e budget held
- M5: 14.2+ loopback meeting captured via picker; > 5 min chunked; mic-only
  fallback correct
- M6: correct per-app category for the matrix apps
- M7: signed/notarized per-arch DMG + updater feed; unsigned recipe documented
- M8: macOS matrix doc + README + privacy notes + skill updated

## 17. Deliberately out of scope / deferred

- Menu-bar-only (tray) mode & LSUIElement — post-port backlog (D7)
- macOS 12 support (D1 floor is 13.0)
- Speaker diarization, wake-word, voice commands — already deferred repo-wide
- App Sandbox — incompatible with the product (documented in §3.4)
- Mac App Store distribution — notarized DMG only

## 18. Sources

- Tauri v2 macOS config / Info.plist merge / externalBin: https://v2.tauri.app/distribute/ · https://v2.tauri.app/reference/config/
- Tauri action + macOS signing/notarization env vars: https://github.com/tauri-apps/tauri-action · https://v2.tauri.app/distribute/pipelines/github/
- ScreenCaptureKit + SCContentSharingPicker: https://developer.apple.com/documentation/screencapturekit
- CGEventTap + Input Monitoring (CGPreflightListenEventAccess): https://developer.apple.com/documentation/coregraphics/cgeventtap · https://developer.apple.com/documentation/coregraphics/1455361-cgpreflightlisteneventaccess
- Accessibility trust prompt (AXIsProcessTrustedWithOptions): https://developer.apple.com/documentation/applicationservices/1460720-axisprocesstrustedwithoptions
- macOS sidecar precedent (Tauri + Rust system-audio capture): https://dev.to/baurzhan_zhetenov_442c4cd/how-i-capture-system-audio-and-transcribe-it-locally-with-no-server-in-the-loop-tauri-rust--4a0n
- Hardened runtime + audio-input entitlement: https://developer.apple.com/documentation/security/hardened_runtime
- whisper-rs metal feature: https://github.com/tazz4843/whisper-rs
- Right-Option PTT precedent (parley #462): https://github.com/pathorsAI/parley/issues/462
