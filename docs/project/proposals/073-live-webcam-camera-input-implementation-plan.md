# 073 — Live Webcam & Camera Input: Implementation Plan

**Status:** In progress
**Date:** 2026-10-13
**Related:** `073-live-webcam-camera-input-proposal.md`, `.agents/skills/mcp-ai-wpoos-dictation-app`, `examples/nvoos-dictate`

## Decisions (confirmed by owner)

1. **Live preview is a hard requirement** for v1.
2. **`nokhwa`** (native, cross-platform) is the capture backend.
3. **Reserve the frame ring buffer + macOS camera Info.plist entries now** (so a
   future vision phase doesn't rework the plumbing).
4. **Resample camera audio to 16 kHz.**

## Key architectural finding (shapes the whole design)

A USB/integrated webcam enumerates as **two separate devices**:

- a **camera** (video frames) — captured via `nokhwa`;
- a **microphone** (audio) — already captured via the existing `cpal`
  `MicCapture` (webcam mics already appear in `list_input_devices`).

So "camera audio" transcription is **already possible** by selecting the webcam's
mic in Settings; `nokhwa` adds the **video (live preview)** which has no audio to
demux. That means v1 is:

- **Preview**: `nokhwa` Camera on a worker thread → thumbnail frames into a ring.
- **Audio**: unchanged — the webcam's mic flows through the existing STT path
  (resample enforced by cpal's native rate handling; the app already passes
  `sample_rate` through, and dictation resamples where needed).

`nokhwa::Camera` is `!Sync` and `Send` **only** with `camera-sync-impl` — so, like
`cpal::Stream`/`MicCapture`, it must live on a dedicated worker thread and be
held there.

## Work items

### M1 — `camera` module + device enumeration + config

- [ ] Add optional dep `nokhwa = { version = "0.10", features = ["input-native", "camera-sync-impl"] }`
  behind a `camera` cargo feature.
- [ ] `src/camera/mod.rs` + `capture.rs`: `CameraCapture::start(...)` mirroring
  `MicCapture` (worker thread owns the `nokhwa::Camera`, holds it, stop-token Drop).
- [ ] `CameraConfig` in `config.rs` (`enabled`, `device`, `preview`) + `Settings.camera`.
- [ ] `list_camera_devices` IPC (via `nokhwa::query`) + Settings picker.

### M2 — live preview (hard requirement)

- [ ] Frame ring buffer (thumbnail, e.g. ≤ 320 px) + a `preview://frame` event
  or a shared buffer the webview polls; overlay/home renders the live view.
- [ ] `get_diagnostics` exposes camera health (device name, frame callback
  count, stall, last error) alongside mic.

### M3 — camera session (record camera → transcribe)

- [ ] Reuse the meeting/recorder flow for a "record camera" action (audio via
  webcam mic; preview shown); archive like a meeting.

### M4 — permissions + privacy

- [ ] Windows: surface `nokhwa` camera-denied / camera-in-use errors actionably.
- [ ] macOS (this branch is Windows; reserve only): add `camera` row to
  `permissions.rs` (`kind: "camera"`, label, explanation, deep link) and the
  `NSCameraUsageDescription` / `NSMicrophoneUsageDescription` strings in the
  bundle config for the eventual vision/AVFoundation path.
- [ ] Explicit start/stop + in-use indicator only; no always-on; rolling window.

### M5 — verification + docs

- [ ] `cargo check --features camera`, clippy with the feature, gate matrix.
- [ ] Update the dictation-app skill (`camera` feature, webcam-mic-as-audio,
  preview seam).

## Verification

`cargo fmt --check`, `cargo clippy --all-targets -- -D warnings` (default +
`mcp` + `camera`), `cargo test`, `npm run build`. Native camera compile gate is
local (no device in CI).
