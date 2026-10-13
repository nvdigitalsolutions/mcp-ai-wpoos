# 075 — Local Object Detection & Tracking: Implementation Plan

**Status:** Implemented (M1–M4; M5 deferred)
**Date:** 2026-10-13
**Related:** `075-local-image-tracking-identity-proposal.md`, Proposal 073 (camera), `.agents/skills/mcp-ai-wpoos-dictation-app`, `examples/nvoos-dictate`

## Decisions (resolved)

1. Hand model **in v1** (MediaPipe-hand ONNX + selective class → `hand` label).
2. Default detector **`yolov11n`** (YOLOv11 exports the same ONNX shape as v8 and
   runs on the pinned `ort`; `yolov8n` kept as fallback).
3. **Click-to-select tracking pin in v1** (track the selected instance by id,
   without prompt-driven re-identification).
4. Overlay **webview canvas** (Rust emits JSON boxes; webview draws).

## Why this is "no new crates"

| Need | Existing |
|---|---|
| ONNX inference | `ort = "=2.0.0-rc.9"` (already for `moonshine`/`silero-vad`) |
| Image decode / resize | `image = "0.25"` (already added for `camera`) |
| Arrays | `ndarray` (already for `silero-vad`) |
| Model download | `stt/model_registry.rs` (SHA-256 + streaming + progress) |
| Camera frames | `camera/capture.rs` `PreviewFrame` ring (Proposal 073) |

The only net-new code is the detector decode (letterbox + NMS), the SORT
tracker, the controller, and the overlay drawing — no new ML dependency.

## Feature wiring

- Reuse the `camera` cargo feature as the vision gate (vision *requires* the
  camera frame source). `vision` becomes an additional always-compiled module
  whose `ort`-dependent `detect.rs` is behind `#[cfg(feature = "camera")]`
  (mirroring how `camera` gates `nokhwa`).

## Work items

### M1 — `vision/detect.rs` (YOLO ONNX → `Vec<Detection>`)

- [x] 640×640 letterbox (pad + scale) from `PreviewFrame` RGB; store
  `scale`/`pad` for box de-projecting back to source coords.
- [x] `ort::Session::run` of `yolov11n.onnx`; parse the `[1, 84, 8400]` (or
  `[1, 4+nc, N]`) output — 4 box params + class scores.
- [x] NMS (conf ≥ 0.25, IoU ≥ 0.45); produce `Vec<Detection { class_id, label,
  confidence, x, y, w, h }>` in **source-pixel** coords.
- [x] `COCO_LABELS` (80) constant + optional hand-label override.
- [x] Exit: unit test the decode + NMS on a synthetic output tensor (no model
  needed); a `#[ignore]` real-model smoke test for local runs.

### M2 — `vision/tracker.rs` (SORT)

- [x] Kalman filter per track (constant-velocity, 6-state) + predict step.
- [x] IoU association (Hungarian/greedy) with a `max_age` (drop tracks unseen
  for N frames).
- [x] Click-to-select pin: mark a track `pinned`; keep its id stable across
  temporary detections misses.
- [x] Exit: synthetic-state unit tests (association, birth/death, pin survival).

### M3 — wiring (`VisionController`, IPC, registry)

- [x] `VisionController` pulling frames from the existing camera ring at ~15 fps,
  running detect → track → emit `Detections` JSON.
- [x] IPC: `vision_start`, `vision_stop`, `vision_status`,
  `vision_select_track(id)`; `get_detections` (or an event) feeding the webview.
- [x] Model registry: catalog `yolov11n` + `yolov8n` + `mediapipe-hand` (pinned
  SHA-256, streaming download, progress) under the existing registry pattern.
- [x] `Settings.vision` (`enabled`, `detector_model`, `confidence_threshold`,
  `iou_threshold`, `track_objects`, `hand_model`).
- [x] Exit: off-by-default; `vision_available()` reflects the `camera` feature.

### M4 — overlay + Diagnostics + Status

- [x] Webview: canvas overlay on the camera `PreviewFrame` drawing
  `{id, label, confidence, bbox}` boxes + track ids; click a box → `vision_select_track`.
- [x] **Diagnostics**: new `VisionDiagnostics` struct in `get_diagnostics`
  (availability, running, detector/hand model + installed flags, last
  detection latency, fps, detections/frame, active tracks, track events,
  last error) — mirroring `MicDiagnostics`/camera health.
- [x] **Status**: `StatusInfo` gains `vision_available`, `vision_running`,
  `vision_detector`, `vision_hand_model`; `ipc.ts` types updated accordingly.
- [x] Home "Vision" toggle wiring + Settings vision row (enable/model/thresholds).
- [x] Exit: `npm run build` clean; manual run shows boxes + live Diagnostics
  counters update on a webcam.

### M5 — hand model, pin hardening, docs

- [ ] MediaPipe-hand ONNX: run the hand detector when `hand_model` is on; merge
  its detections (label `hand`) into the same track stream.
- [ ] ByteTrack low-confidence recovery (two-stage association) as an opt-in
  upgrade behind `track_objects`.
- [x] Update the dictation-app skill (`vision` module, model catalogue, toggle,
  and the Status/Diagnostics fields).

## Verification

- `cargo fmt --check`, `clippy --all-targets -- -D warnings` (default + `mcp` +
  `camera`), `cargo test` (new detect/tracker unit tests), `npm run build`.
- Real-model smoke tests are `#[ignore]` local gates (no camera/model in CI),
  matching the `live_capture_delivers_samples` convention.

## Risks (from proposal §6)

- `ort` rc.9 vs exported ONNX op-set — validate `yolov11n` export against rc.9 at
  first compile; fall back to `yolov8n` if an op is unsupported.
- CPU throughput — ~15 fps, tiny `n` model, opt-in only.
- Identity drift — SORT-now, ByteTrack-later.
- Privacy — person detection only, no identification, all local.
