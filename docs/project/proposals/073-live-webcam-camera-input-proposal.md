# Proposal 073 — Live Webcam & Camera Input for the NV oOS Dictation Companion

**Status:** Draft for discussion
**Date:** 2026-10-13
**Related:** Proposal 067 (dictation companion), Proposal 071 (video *file* input, audio-track demux), `.agents/skills/mcp-ai-wpoos-dictation-app`, `examples/nvoos-dictate`

## 1. Context

The dictation companion (`examples/nvoos-dictate`) today captures **live
microphone** and **meeting system audio**, and (as of Proposal 071) transcribes
**video files** by demuxing their *audio* track. It cannot yet ingest a **live
webcam/camera**, whether for its audio, its video frames, or both.

This proposal scopes adding live camera input, and — critically — *separates
two very different features* that are easily conflated under "webcam":

- **Camera audio capture** — treat the webcam as another audio source and
  stream its mic to the existing STT pipeline. A modest, well-understood
  extension.
- **Camera *vision*** — decode live video frames and run them through an AI
  model for scene/visual understanding. A large, separate capability with a
  different model + privacy + performance profile.

## 2. Industry research summary

### 2.1 Camera capture in a native Rust (Tauri) app

| Route | Library | Notes |
|---|---|---|
| **Native capture** | [`nokhwa`](https://github.com/l1npengtul/nokhwa) (MediaFoundation/AVFoundation/V4L2) | Cross-platform `Camera` struct; returns RGB frames; actively maintained | 
| | [`crabcamera`](https://crates.io/crates/crabcamera) | Lighter alternative; fewer backends | 
| **Webview capture** | `navigator.mediaDevices.getUserMedia({video, audio})` | Browser-standard; needs WebView2 permission handler + Tauri capability to grant camera/mic to the webview |

**Decision driver:** the app's architecture keeps all privileged hardware access
in the Rust core (webview has no fs/shell access — see `capabilities/main.json`).
Therefore the **native `nokhwa` backend** is the consistent choice: a `CameraCapture`
mirroring the existing `MicCapture` worker-thread pattern, exposing frames to the
core and never to the webview as raw access.

### 2.2 Camera *audio* vs *vision* are distinct problems

- **Audio-first live transcription** is the mature, well-trodden path: whisper.cpp +
  FFmpeg live captioning ([Brady Hurlburt](https://brady.fyi/post/local-live-captioning-with-whisper.cpp-and-ffmpeg/));
  FFmpeg even ships a `whisper` filter for live subtitles ([Neowin](https://www.neowin.net/news/a-powerful-new-whisper-audio-filter-brings-ai-transcription-to-ffmpeg/)).
  The webcam's audio track is just another microphone.
- **Vision** (frame understanding) is a different category — "AI meeting
  copilot / screen+webcam understanding" — requiring a vision-capable model
  (GPT-4o/Gemini vision, or a local vision model via Ollama/LM Studio), frame
  sampling/decimation, and a privacy/consent story. It is **not** a free
  addition to the STT pipeline.

### 2.3 Privacy & permission standards

- **Webcam is the highest-sensitivity capture surface** — above microphone.
  Industry practice: an explicit, user-initiated "start camera" action (never
  always-on), a visible in-use indicator, and immediate release on stop.
- On Windows/macOS the OS enforces camera permissions; a Tauri app must request
  camera access at the OS level and show its own consent UI (the app's existing
  `permissions.rs` TCC layer on macOS is the precedent).
- Analogous to the mic: retain a short rolling window, never write to disk by
  default (mirrors `privacy.retain_meeting_audio`).

## 3. Goals

1. **Live camera audio transcription** (primary): capture a selected webcam's
   audio channel and stream it into the existing STT → Stage-1 cleanup →
   (Stage-2 polish) pipeline, reusing the PTT gate and pre-roll ring.
2. **Camera as a first-class source** in Settings (device picker), Diagnostics
   (live frame/level health), and the transcription source selector.
3. **Structured camera sessions** — an explicit "record camera" mode distinct
   from PTT dictation, producing a transcript (+ optional archive) like meetings.
4. **Vision is explicitly deferred** to a follow-up (see §4) — v1 is audio-only
   camera capture with the webcam preview surfaced as a basic live view (no AI
   frame understanding).

## 4. Non-goals (this proposal)

- **Camera vision / frame understanding** — decoding frames and running them
  through a vision model is a separate proposal (call it 073b "camera vision").
- Always-on webcam / background capture / motion detection.
- Remote/network camera (IP camera/RTSP) — local USB/integrated cameras only.
- Changing the FFmpeg posture — the app remains pure-Rust; `nokhwa` (native) is
  the capture path, and the existing Symphonia audio path is unchanged for files.
- Speaker diarization (unchanged from prior proposals).

## 5. Design

### 5.1 New `camera` module (mirrors `audio`)

```
src-tauri/src/camera/
├── mod.rs          # CameraCapture handle + factory
├── capture.rs      # nokhwa Camera on a worker thread (like MicCapture)
└── (frames.rs)     # frame ring buffer for the live preview (v1: preview only)
```

- `CameraCapture::start(ring_capacity_secs, device_name)` mirrors `MicCapture`:
  a dedicated worker thread owns the (non-`Send`) `nokhwa::Camera`, holds it for
  its lifetime, and pushes audio (and, for preview, thumbnailed frames) into
  `Arc<Mutex<RingBuffer>>` handles. Drop sends a stop token (same pattern).
- Camera **audio** is downmixed to mono f32 @ 16 kHz (or the camera's native
  rate, resampled) and fed to the same STT engine and VAD gate as the mic.
- Camera **video** (v1) is reduced to a low-rate thumbnail used only for the
  live preview / in-use indicator — frames are **not** decoded for vision.

### 5.2 Source selection & config

Extend `Settings.audio` with a parallel `camera` block:

```rust
pub struct CameraConfig {
    pub enabled: bool,
    pub device: Option<String>,   // display name, like input_device
    pub preview: bool,            // show live thumbnail in the overlay
}
```

A new `list_camera_devices` IPC (mirrors `list_input_devices`) enumerates
`nokhwa` devices for the Settings picker.

### 5.3 Permissions

- Windows: `nokhwa` (MediaFoundation) triggers the OS camera consent at first
  open; the app surfaces the denial as an actionable error.
- macOS: extend `permissions.rs` with a `camera`/`screen-recording`-adjacent
  entry (NSCameraUsageDescription already needed by the Info.plist for the
  eventual vision path; audio-only needs AVFoundation mic entitlements).
- Add the camera to the Settings **Permissions** tab (existing surface).

### 5.4 Diagnostics

Extend `MicDiagnostics`/`get_diagnostics` with camera health: device name,
frame/audio callback counts, stall, and last error — same observability the mic
already gets (the "is the camera actually delivering?" question).

### 5.5 Privacy

- Explicit start/stop only; a visible in-use indicator; rolling window, no disk
  persistence by default (reuses the `privacy` defaults).

## 6. Dependencies

- **`nokhwa`** (feature-gated, `camera` cargo feature) — the sole new crate.
  Optional: `image` for thumbnail decode is **not** required in v1 (preview uses
  the raw frame downsample), so no image-decode dependency in v1.
- No FFmpeg, no new vision model, no `getUserMedia`/webview permission changes
  (native capture keeps the webview least-privileged).

## 7. Risks & mitigations

| Risk | Mitigation |
|---|---|
| `nokhwa` backend/API drift vs the pinned version | Isolate calls in `camera/capture.rs`; feature-gate; verify at first compile (same discipline as parakeet/whisper) |
| Camera mic rate ≠ 16 kHz | Resample (existing `audio` has a resample seam) or use native rate and pass `sample_rate` through |
| Camera busy/locked by another app | Surface a clear "camera in use" error (mirrors mic-unplugged handling) |
| Privacy / consent regression | Native OS permission + explicit start/stop + in-use indicator; never always-on |
| Scope creep into vision | §4 hard non-goal; vision is 073b |

## 8. Milestones

1. **M1 — `camera` module skeleton + device enumeration.** `list_camera_devices`
   IPC + Settings picker + `CameraConfig`. Verify `nokhwa` compiles/lists devices
   on the reference PC.
2. **M2 — audio capture via camera.** `CameraCapture` worker, mono downmix +
   resample, feed the existing STT + VAD path behind a source toggle (mic vs
   camera). Diagnostics health counters.
3. **M3 — "record camera" session.** Explicit camera transcription session
   (record → transcribe → archive), mirroring the meeting flow; preview
   thumbnail + in-use indicator.
4. **M4 — permissions + privacy.** Windows/macOS consent surfacing; Settings
   Permissions tab row; privacy defaults wiring.
5. **M5 — verification + docs.** Gate matrix extension, `camera` feature compile
   checks, skill + docs update.

## 9. Open questions

1. **Prioritize audio-only** (camera as another mic) ✓ recommended for v1, or
   should the live *preview* be a hard requirement before shipping?
2. Preferred capture backend: `nokhwa` (recommended, cross-platform) vs a
   webview `getUserMedia` flow (would break the least-privilege posture)?
3. Is a **camera vision** phase (frames → AI understanding, proposal 073b) in
   scope soon? If so, v1 should reserve the frame ring buffer + Info.plist
   camera entries now to avoid a rework.
4. Camera audio sample-rate handling: resample to 16 kHz vs native rate?

---

*Scoped deliberately: v1 = webcam as a first-class **audio** source (and a
passive live preview), reusing the proven STT/pipeline/observability seams.
Frame-level **vision** is a separate proposal so the privacy and model
implications are handled on their own merits.*
