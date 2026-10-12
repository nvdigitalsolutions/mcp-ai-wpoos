# Proposal 068 — NV oOS Dictation: macOS Port

> Supersedes phase **P5 — macOS** of
> [`067-tauri-dictation-companion-app-proposal.md`](067-tauri-dictation-companion-app-proposal.md).

## Context

The NV oOS Dictation companion (`examples/nvoos-dictate`) shipped Windows-first
(proposal 067): its push-to-talk hotkey is a `WH_KEYBOARD_LL` low-level hook
with `GetAsyncKeyState` fallback, meeting capture uses WASAPI loopback, and
the non-Windows hotkey path is a deliberate placeholder — a
`tauri-plugin-global-shortcut` toggle (first activation = press, second =
release) with no true hold semantics and no key-up events.

Since 067 shipped, the Windows build went through a hardening round that is
directly relevant here: the resident capture stream must be held by its
worker thread (dropping a playing `cpal::Stream` stops capture — the root
cause of a long "no speech detected" saga), adaptive VAD gating with a
never-drop-audible-audio fallback, a Diagnostics page with capture-health
counters and a device picker, and re-download hardening. All of that logic
lives in platform-agnostic modules and ports to macOS unchanged.

The macOS platform is where the remaining work concentrates:

- **PTT with hold semantics** needs a `CGEventTap` (or `IOHIDManager`) —
  the current plugin path cannot observe key-up, so macOS users only get
  toggle dictation.
- **Permissions are a first-class UX surface** on macOS: Microphone,
  Input Monitoring (global key observation), Accessibility (paste), and —
  for meetings — Screen Recording. Getting these wrong is how macOS
  dictation apps die in reviews (see research below).
- **System-audio capture for meetings** has no macOS equivalent of WASAPI
  loopback; the 2026 industry standard is ScreenCaptureKit.
- **Distribution** (signing, notarization, updates) is inseparable from
  permissions: TCC grants are bound to the app's code signature, so an
  unsigned or ad-hoc-signed build loses its permissions on every rebuild.

## Goals

1. **Parity dictation on macOS**: hold-to-talk with the same engine stack
   (whisper.cpp / sherpa-onnx parakeet / Moonshine / embedded), pre-roll
   ring buffer, deterministic cleanup, paste, history, model downloads,
   and the Diagnostics page.
2. **A permission model that users survive**: clear onboarding with
   one-click System Settings deep links, live permission status on the
   Diagnostics page, and graceful degradation (e.g. dictation works but
   paste requires Accessibility; global hotkey needs Input Monitoring).
3. **Apple-Silicon-fast STT**: whisper.cpp Metal (default) with an
   optional Core ML/ANE acceleration path — matching the category
   standard of sub-1 s end-to-end on M-series hardware.
4. **Meetings transcription parity** via ScreenCaptureKit system-audio
   capture (macOS 13+), with BlackHole documented as the legacy fallback.
5. **Distributable from day one**: Developer ID signing, notarization,
   DMG + Sparkle updates (or signed Tauri updater), so TCC grants survive
   updates and Gatekeeper is clean.

## Non-goals (for this proposal)

- **Mac App Store**: Apple's sandbox and review rules block global event
  taps and system-audio capture; category precedent (MacWhisper) ships its
  dictation feature only outside the store. Out of scope.
- **Apple's built-in Dictation integration** (the Fn/Globe double-press
  handoff), Siri, or Voice Control hooks.
- **Speaker diarization** and per-speaker labels (same deferral as 067).
- **iOS/iPadOS** — this is a macOS desktop port only.
- Rewriting the Windows implementation; this proposal only touches shared
  code where macOS needs a new seam (all seams must keep Windows green).

## Industry research summary

### macOS dictation UX standards

The category is crowded and convergent. Superwhisper, MacWhisper, Hold to
Talk, tacet, PushText, viskly and mac-voice-dictation all implement the same
core loop — menu-bar app, hold a key, speak, release, text pasted at the
cursor — and all document the same permission triad:

- **Microphone** — recording ([mac-voice-dictation](https://github.com/gapsong/mac-voice-dictation),
  [VoiceInput permissions help](https://voiceinput.app/en/help/permissions/)).
- **Accessibility** — "lets it watch global key events (for the hotkey)
  and send Cmd+V to other apps (for pasting)" — the exact quote from
  [mac-voice-dictation](https://github.com/gapsong/mac-voice-dictation);
  quoth notes Accessibility is what allows the synthetic Cmd+V and the
  AX-insertion path ([quoth #3](https://github.com/ryan-stoffel/quoth/issues/3)).
- **Input Monitoring** — the modern TCC service (kTCCServiceListenEvent)
  that a listen-only `CGEventTap` needs to see keys in other apps
  ([quoth #3](https://github.com/ryan-stoffel/quoth/issues/3),
  [zeroclaw #6482](https://github.com/zeroclaw-labs/zeroclaw/issues/6482),
  [HackTricks TCC taxonomy](https://hacktricks.wiki/en/macos-hardening/macos-security-and-privilege-escalation/macos-security-protections/macos-input-monitoring-screen-capture-accessibility.html)).

Conventions worth copying:

- **Menu-bar accessory app** with a monochrome `trayTemplate` icon, plus a
  focusless overlay capsule while recording
  ([ciao #4](https://github.com/iliyasone/ciao/issues/4)).
- **Default trigger keys**: Right Option or Fn/Globe are the usual
  dictation-app choices ([ciao #4](https://github.com/iliyasone/ciao/issues/4));
  talk-keys uses Right Option for "speak selected text" and Right Command
  for hold-to-dictate ([talk-keys](https://github.com/stevederico/talk-keys)).
- **Hold-to-talk as the privacy default** — "Only listens while you're
  holding the key" is a headline marketing claim
  ([Hold to Talk](https://holdtotalk.com/)); macOS shows the limited
  `PostEvent` grant under Accessibility for such apps.
- **Self-diagnosable permission state** — talk-keys exposes
  `AX=true ListenEvent=true` status and documents that toggles don't
  attach to a running process until restart
  ([talk-keys](https://github.com/stevederico/talk-keys)). Our existing
  Diagnostics page is the natural home for this on macOS.

### Global hotkey / push-to-talk infrastructure

The Windows hook discipline (swallow both down and up symmetrically,
release pending swallows on teardown) does **not** map directly. macOS
options, in industry order of preference:

1. **Listen-only `CGEventTap` (session tap)** — the standard. Observes
   every key down/up system-wide and lets the event continue to the
   focused app; the tap callback decides pass vs swallow per event
   ([Gaitatzis](https://gaitatzis.medium.com/capture-key-bindings-in-swift-3050b0ccbf42)).
   quoth's design is the canonical safe pattern: **listen-only, on
   `flagsChanged` and `keyDown`** — "an active tap can hang system input
   if the permission is revoked, and a push-to-talk app never needs to
   swallow events" ([quoth #4](https://github.com/ryan-stoffel/quoth/issues/4)).
   A swallowed-event tap is a tollbooth on every keystroke the machine
   produces; watching `flagsChanged` for modifier PTT avoids most of that
   cost ([C.W.K. Firekeeper Quest](https://www.creativeworksofknowledge.com/en/cwk-quests/firekeeper-quest/the-latency-war/flagschanged-not-tap/)).
2. **Swallowing tap (opt-in)** — possible (return `nil` from the
   callback) and used by apps that must suppress the held modifier, but
   it comes with the revocation-hang risk and requires careful symmetric
   release handling — our Windows exit-safety lesson applies verbatim.
3. **`NSEvent.addGlobalMonitorForEvents`** — observe-only, cannot
   swallow, coarser; acceptable fallback for key-up observation.
4. **`IOHIDManager`** — device-level key down/up without the event
   stream, cannot swallow, extra driver-surface complexity; used for
   media-key interception
   ([Reddit r/swift](https://www.reddit.com/r/swift/comments/1bhck2o/capture_and_stop_propagation_on_media_keyboard/)).
5. **`tauri-plugin-global-shortcut`** — Carbon `RegisterEventHotKey`
   underneath: fires on key-down only, no key-up, no swallow. Fine for
   launch-style shortcuts, unusable for hold-to-talk. This is why 067
   shipped toggle-only on non-Windows.

Key-choice reality on macOS: Option is the AltGr equivalent (Option+letter
produces special characters), Command is every menu shortcut, Fn is
Apple's built-in Dictation. A listen-only tap on **Right Option** must
tolerate Option+key combos passing through (the Windows `ptt_pressed_alone`
"pressed alone" discipline ports directly), and **F19/F20** is the
zero-conflict power-user alternative.

### Microphone capture & permissions

- `cpal` works on macOS (CoreAudio AUHAL) — the existing `MicCapture`,
  ring buffer, device picker, and the stream-hold fix port unchanged.
- `NSMicrophoneUsageDescription` must be present in `Info.plist` or the
  app crashes on first capture ([ciao #4](https://github.com/iliyasone/ciao/issues/4)).
- Default-device changes are observable properly on macOS via CoreAudio
  property listeners (`kAudioHardwarePropertyDefaultInputDevice`) — nicer
  than polling; a dock/headset swap should trigger the same
  restart-capture recovery the Diagnostics page already offers manually.
- The VAD/fallback layer (adaptive energy gate, never-drop-audible-audio)
  is platform-neutral and already fixed.

### System-audio capture for meetings

The 2026 consensus is unambiguous: **ScreenCaptureKit** on macOS 13+ is the
standard, replacing the BlackHole + Multi-Output Device dance that "works,
and it breaks constantly"
([MeetingRecorder build log](https://meetingsrecorder.com/blog/system-audio-macos-build-log/)).
Points that shape the design:

- SCK captures system audio natively under the **Screen Recording**
  permission — no virtual driver, no output switching
  ([Screenify](https://www.screenify.studio/blog/2026-04-15-best-screen-recorder-mac-audio),
  [Screen Charm](https://screencharm.com/blog/capture-system-audio-mac),
  [OBS practice via ScreenBuddy](https://screenbuddy.xyz/blog/screen-record-with-audio-mac)).
- The hard engineering problem is **clock reconciliation**: the SCK audio
  stream and the mic stream are independent clocks; MeetingRecorder's
  636-meeting build log documents reconciling them without dropping a
  sample on the real-time thread — budget for this explicitly.
- Legacy fallback remains **BlackHole** (+ Audio MIDI Setup multi-output)
  for macOS 12 and for users who refuse the Screen Recording prompt;
  ffmpeg-era recipes are well documented
  ([mkarim](https://www.mkarim.us/blog/2026-09-01-record-internal-audio-mac.html)).
- Meetings stay **explicit-record** (never always-on), matching the app's
  privacy posture and the Screen Recording permission's sensitivity.

### Local STT on Apple Silicon

- **whisper.cpp is the Apple-Silicon baseline**: Metal GPU by default; the
  optional Core ML path runs the encoder on the Apple Neural Engine with
  "more than x3 faster compared with CPU-only execution"
  ([whisper.cpp README](https://github.com/ggml-org/whisper.cpp)). Real-world
  RTF benchmarks on M1→M4 show base/small models far below realtime
  ([JustVoice](https://justvoice.ai/blog/whisper-benchmark-apple-silicon-m3-m4),
  [SpeakUp](https://getspeakup.app/blog/whisper-cpp-benchmark-mac/)).
- The Swift ecosystem has its own race (WhisperKit, FluidAudio CoreML,
  Parakeet MLX — [mac-whisper-speedtest](https://github.com/anvanvan/mac-whisper-speedtest)),
  but our Rust stack keeps **whisper-rs/sherpa-onnx**, which run on macOS
  already; a Core ML conversion pipeline is an optional acceleration
  tier, not a rewrite.
- Parakeet TDT via sherpa-onnx on CPU is the Windows default and works on
  macOS; Metal/CoreML acceleration for it is secondary (whisper is the
  macOS default for multilingual reach).

### Paste automation

- **NSPasteboard + synthetic Cmd+V** via `CGEvent.post` is the category
  standard and requires Accessibility
  ([mac-voice-dictation](https://github.com/gapsong/mac-voice-dictation)).
- **AXUIElement insertion** is the upgrade path (no clipboard
  save/restore race) at the cost of more Accessibility surface
  ([quoth #3](https://github.com/ryan-stoffel/quoth/issues/3)).
- `arboard` already covers clipboard read/write cross-platform; the app's
  `PasteBackend` trait gets a macOS implementation — Windows behavior
  (save clipboard, paste, restore) is preserved.

### Distribution, signing & updates

- **Sign from the very first internal build.** TCC permission grants are
  bound to the code signature's designated requirement: with ad-hoc
  signing, the Accessibility/Input Monitoring grants **reset on every new
  build**, which also makes auto-updates painful
  ([mosaic #1](https://github.com/gsiener/mosaic/issues/1)).
- Tauri v2 supports DMG bundling, hardened runtime, `APPLE_SIGNING_IDENTITY`
  / `APPLE_CERTIFICATE` env-driven signing, notarization and stapling
  ([Tauri macOS signing docs](https://v2.tauri.app/distribute/sign/macos/),
  [dev.to full flow](https://dev.to/hiyoyok/code-signing-a-tauri-app-for-macos-the-complete-flow-54jk)).
- **Sparkle** is the macOS-native auto-update standard (appcast + EdDSA);
  Tauri's updater is cross-platform but signing-key plumbing on macOS
  commonly lands on Sparkle anyway
  ([Steinberger](https://steipete.me/posts/2025/code-signing-and-notarization-sparkle-and-tears)).
  Ship updates from day one
  ([0xmassi](https://dev.to/0xmassi/shipping-a-production-macos-app-with-tauri-20-code-signing-notarization-and-homebrew-mc3)).
- Target a **universal binary** (aarch64 + x86_64) so Gatekeeper-clean
  DMGs install on every still-supported Mac
  ([stuffbucket signing skill](https://stuffbucket.github.io/skills/catalog/tauri-bundling-macos-signing/)).
- Homebrew cask is the community distribution channel
  ([0xmassi](https://dev.to/0xmassi/shipping-a-production-macos-app-with-tauri-20-code-signing-notarization-and-homebrew-mc3)).
- Cost: Apple Developer Program ($99/yr) for Developer ID certificates.

## Proposed architecture

### Permissions model (new, first-class)

| Capability | TCC service | API | Failure mode when missing |
|---|---|---|---|
| Record mic | Microphone | cpal/CoreAudio | capture opens but delivers nothing / crash without plist key |
| Observe global keys | Input Monitoring (`kTCCServiceListenEvent`) | listen-only `CGEventTap` | tap receives no events |
| Paste | Accessibility | `CGEvent.post` (Cmd+V) / `AXUIElement` | paste silently fails |
| Meetings system audio | Screen Recording | ScreenCaptureKit | no system audio; meetings degrade to mic-only |

Implementation: a `macos/permissions.rs` module with
`CGPreflightListenEventAccess`/`CGRequestListenEventAccess`,
`AXIsProcessTrusted`/`AXIsProcessTrustedWithOptions`, and
`AVCaptureDevice.authorizationStatus` — plus System Settings deep links
(`x-apple.systempreferences:com.apple.preference.security?Privacy_ListenEvent`
etc.). An onboarding card (first run) and a **permissions row on the
existing Diagnostics page** show live status; the app degrades
feature-by-feature instead of hard-failing.

`Info.plist` gains `NSMicrophoneUsageDescription` (required),
`NSScreenCaptureUsageDescription`-style messaging for SCK, and the app
runs **unsandboxed with the hardened runtime** (event taps and global
paste are incompatible with the App Sandbox; hardened-runtime
entitlements: `com.apple.security.device.audio-input`,
`com.apple.security.automation.apple-events` as needed).

### PTT hotkey (replaces the plugin toggle on macOS)

New `hotkey/mac_tap.rs` implementing the existing `HotkeyListener` trait
with true hold semantics so the dictation controller is untouched:

- **Default: listen-only session tap** watching `keyDown`/`keyUp` (+
  `flagsChanged` for modifier keys) — emits the existing
  `Pressed`/`Released { shift }`/`DoubleTap`/`Esc` events. No swallowing:
  quoth's revocation-hang argument plus the fact that macOS PTT keys are
  chosen to be inert when held alone.
- **Opt-in "suppress held key" mode** for modifier PTT: symmetric swallow
  (down + up) with the Windows exit-safety rule — on tap teardown,
  inject a synthetic key-up for any key the tap consumed. Marked
  experimental; listen-only remains the default.
- Default key: **Right Option**, `pressed_alone` discipline ported from
  Windows; alternates: Right Command, F19/F20. The Settings hotkey
  section gains the macOS key list.
- The Windows hook and fallback poller stay `#[cfg(windows)]`; Linux
  keeps the plugin toggle until its own port.

### Meetings system audio (P2)

`meetings/macos_system_audio.rs` behind a macOS seam:

- ScreenCaptureKit `SCStream` (audio-only capture where supported),
  clock-reconciled with the mic ring per the MeetingRecorder technique.
- Permission = Screen Recording; degrade to mic-only with a visible
  notice when denied.
- Binding choice: a thin Swift sidecar (compiled into the bundle,
  communicated with over a local channel) vs `objc2`-based Rust bindings
  to SCK. Decision point below; the meeting-recorder contract is
  unchanged (PCM stream in, chunker in, STT in).

### Shared-module carryover (no changes)

`cleanup`, `stt` (whisper-rs Metal enabled on macOS), `storage`,
`model_registry` (+ re-download hardening), `mcp` stdio mode, `embedded`
fallback, the Diagnostics page, the device picker, and the VAD/fallback
logic — all platform-agnostic already. `keyring` uses the macOS Keychain.

### CI

Add a `macos-latest` job to `build-nvoos-dictate.yml` running
fmt/clippy/test (default + `mcp`) — pure-Rust gates are portable. Native
engine feature checks and hardware capture tests stay local-gate; the
ignored `live_capture_delivers_samples` test doubles as a macOS smoke
test on real hardware.

## Phasing

| Phase | Scope | Exit criteria |
|---|---|---|
| **P0 — macOS dictation parity** | Listen-only CGEventTap PTT (hold semantics), permissions module + onboarding + Diagnostics row, `NSMicrophoneUsageDescription`, macOS paste backend, Right Option/F19 hotkeys, Metal-enabled whisper default | End-to-end dictation on real hardware; permission-denied paths degrade gracefully; Windows CI stays green |
| **P1 — Distribution** | Developer ID signing from first build, notarization + stapling, DMG, Sparkle (or signed Tauri updater), universal binary, Homebrew cask recipe | Gatekeeper-clean DMG install; TCC grants survive an in-place update |
| **P2 — Meetings** | ScreenCaptureKit system audio + clock reconciliation, mic+system mix, BlackHole fallback docs | Real-call meeting transcription verified; SCK-denied degrades to mic-only |
| **P3 — Acceleration & polish** | Core ML/ANE whisper path (opt-in), swallow-mode PTT hardening, default-device-change listener, AX-insert paste upgrade | Core ML path ≥2× CPU on M-series; no regression in RTF harness |

## Testing strategy

- **Permissions matrix** — unit-test the degradation logic with injected
  permission states; manual matrix on a real Mac (granted/denied/revoked
  mid-run, per quoth/talk-keys notes that revocation kills active taps).
- **Hotkey semantics** — the mac tap's pure decision logic (pass/swallow,
  pressed-alone, double-tap, symmetric-release accounting) lives in
  testable pure functions mirroring the Windows `win_hook` tests; only
  the CGEvent plumbing stays untested-by-unit.
- **Latency regression** — reuse the `transcribe_file` WER/e2e_ms harness
  with the same corpus; add Metal vs CPU comparison numbers to CI logs
  (macOS runner).
- **Capture** — `live_capture_delivers_samples` (currently ignored) runs
  on macOS hardware; extend `mic_probe` to report the CoreAudio device.
- **Update integrity** — notarization/stapling and Sparkle appcast
  verified in the release pipeline, not CI.

## Licensing

Unchanged from 067: app code MIT, Parakeet weights CC-BY-4.0, Whisper MIT,
sherpa-onnx Apache-2.0, whisper.cpp MIT, Silero MIT. ScreenCaptureKit is
an OS framework (no license concern); Sparkle is MIT. No AGPL.

## Risks & mitigations

| Risk | Mitigation |
|---|---|
| TCC grants reset when the signature changes | Sign with a stable Developer ID identity from the first internal build; never ship ad-hoc builds to testers |
| Permission revocation hangs an active (swallowing) tap | Listen-only default; swallow mode experimental + teardown release; document revoke → re-grant → relaunch (talk-keys behavior) |
| Right Option conflicts with Option+letter input | `pressed_alone` arm discipline; F19/F20 alternative; never swallow by default |
| ScreenCaptureKit clock drift vs mic | Dedicated reconciliation step (MeetingRecorder pattern); meetings tolerate ±50 ms alignment |
| App Store exclusion limits reach | Outside-store distribution is the category norm (MacWhisper precedent); Homebrew cask + GitHub releases |
| x86_64 Macs (Rosetta era) | Universal binary; whisper Metal degrades to CPU on Intel — verify RTF there before claiming parity |
| Apple Silicon GPU contention with whisper Metal | CPU fallback already built; RTF budget has headroom (sub-1 s at base.en) |
| Sidecar complexity for SCK | Swift sidecar is isolated to one module; meetings still function mic-only without it |

## Effort estimate

| Phase | Estimate |
|---|---|
| P0 macOS parity | 4–6 days (tap/permissions = 2–3, paste = 1, plist/UX = 1, hardware verify = 1) |
| P1 Distribution | 2–3 days (+ $99/yr Apple Developer Program) |
| P2 Meetings | 3–5 days (SCK sidecar + clock reconciliation is the hard part) |
| P3 Acceleration & polish | 2–4 days |

## Open decision points

1. **Default PTT key** — Right Option (category convention, possible
   Option+key friction) vs F19/F20 (zero conflict, unknown to most
   users). Proposal: Right Option default, F19 offered in Settings.
2. **Listen-only vs swallow** — proposal: listen-only default, opt-in
   swallow mode with the Windows-style symmetric release + teardown
   injection.
3. **SCK binding** — Swift sidecar vs `objc2` bindings. Proposal: objc2
   first (stays in the Rust toolchain); Swift sidecar as fallback if the
   SCK surface proves unwieldy.
4. **Updater** — Sparkle (macOS standard) vs Tauri updater (one config
   for both platforms). Proposal: Sparkle for macOS; keep the existing
   updater plugin Windows-only.
5. **Universal binary timing** — from P1 (recommended) vs aarch64-first.
6. **Core ML conversion pipeline** — worth maintaining per-model
   conversions in the model registry, or Metal-only until proven needed?
7. **Meetings consent UX** — reuse the Screen Recording prompt as-is or
   show an explicit pre-recording explainer card first (privacy posture
   says explainer first).

## Sources

- tacet — https://github.com/DRYCodeWorks/tacet
- mac-voice-dictation — https://github.com/gapsong/mac-voice-dictation
- quoth issues #3/#4 — https://github.com/ryan-stoffel/quoth/issues/3 ,
  https://github.com/ryan-stoffel/quoth/issues/4
- talk-keys — https://github.com/stevederico/talk-keys
- ciao macOS support — https://github.com/iliyasone/ciao/issues/4
- Hold to Talk — https://holdtotalk.com/
- Superwhisper — https://superwhisper.com/voice-to-text-mac
- MacWhisper dictation docs — https://docs.macwhisper.com/article/14-how-to-use-the-dictation-feature
- C.W.K. Firekeeper Quest (flagsChanged vs tap) — https://www.creativeworksofknowledge.com/en/cwk-quests/firekeeper-quest/the-latency-war/flagschanged-not-tap/
- Gaitatzis, CGEventTap in Swift — https://gaitatzis.medium.com/capture-key-bindings-in-swift-3050b0ccbf42
- Itsuki, global key events two ways — https://levelup.gitconnected.com/swiftui-macos-detect-listen-to-global-key-events-two-ways-df19e565793d
- r/swift, media-key IOHIDManager — https://www.reddit.com/r/swift/comments/1bhck2o/capture_and_stop_propagation_on_media_keyboard/
- HackTricks, macOS Input Monitoring / Accessibility TCC — https://hacktricks.wiki/en/macos-hardening/macos-security-and-privilege-escalation/macos-security-protections/macos-input-monitoring-screen-capture-accessibility.html
- VoiceInput permissions help — https://voiceinput.app/en/help/permissions/
- zeroclaw Input Monitoring feature request — https://github.com/zeroclaw-labs/zeroclaw/issues/6482
- MeetingRecorder system-audio build log — https://meetingsrecorder.com/blog/system-audio-macos-build-log/ (dev.to mirror: https://dev.to/alexander_mishin_1c72158e/how-i-record-system-audio-on-macos-without-a-virtual-driver-54e1)
- Screenify Studio, SCK audio — https://www.screenify.studio/blog/2026-04-15-best-screen-recorder-mac-audio
- ScreenBuddy, OBS SCK audio — https://screenbuddy.xyz/blog/screen-record-with-audio-mac
- Screen Charm, internal audio — https://screencharm.com/blog/capture-system-audio-mac
- mkarim, BlackHole/ffmpeg fallback — https://www.mkarim.us/blog/2026-09-01-record-internal-audio-mac.html
- whisper.cpp (Metal/CoreML/ANE) — https://github.com/ggml-org/whisper.cpp
- JustVoice Apple Silicon benchmarks — https://justvoice.ai/blog/whisper-benchmark-apple-silicon-m3-m4
- SpeakUp whisper.cpp benchmark — https://getspeakup.app/blog/whisper-cpp-benchmark-mac/
- mac-whisper-speedtest — https://github.com/anvanvan/mac-whisper-speedtest
- Tauri macOS signing docs — https://v2.tauri.app/distribute/sign/macos/
- dev.to, Tauri notarization flow — https://dev.to/hiyoyok/code-signing-a-tauri-app-for-macos-the-complete-flow-54jk
- dev.to, production macOS app + Homebrew — https://dev.to/0xmassi/shipping-a-production-macos-app-with-tauri-20-code-signing-notarization-and-homebrew-mc3
- Steinberger, code signing & Sparkle — https://steipete.me/posts/2025/code-signing-and-notarization-sparkle-and-tears
- mosaic issue #1 (signature ↔ TCC grants) — https://github.com/gsiener/mosaic/issues/1
- stuffbucket, tauri-bundling-macos-signing — https://stuffbucket.github.io/skills/catalog/tauri-bundling-macos-signing/
