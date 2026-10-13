# Proposal 075 — Local Object Detection & Tracking for the NV oOS Dictation Companion

**Status:** Draft for discussion (replaces the earlier embedding-focused 075 draft)
**Date:** 2026-10-13
**Related:** Proposal 073 (webcam preview, `nokhwa` frame ring), Proposal 074 (media-worker local providers), `.agents/skills/mcp-ai-wpoos-dictation-app`, `examples/nvoos-dictate`

## 1. Context

The `nvoos-dictate` companion captures a live webcam preview (Proposal 073)
into an in-memory RGB frame ring, runs local STT, and calls local text models
(LM Studio / Ollama) for transcript polish. It cannot yet **see** objects.

This proposal adds **live local object detection and tracking**: a user-enabled
"vision" mode where the app runs a lightweight ONNX object detector on the
camera feed, draws bounded boxes around detected things (people, hands, phones,
pens, …), and maintains per-object identity across frames so a selected object
can be followed as it moves.

This supersedes the earlier 075 draft (which framed the feature as *embedding
similarity for identity*). The active detector + tracker framing below is the
actual requirement: **real-time "what is in frame, and keep track of it."**

## 2. Industry research summary

### 2.1 Detection — YOLO ONNX in Rust (proven)

Real-time YOLO on a live webcam via **Rust + `ort` (ONNX Runtime)** is a
mature, well-documented pattern:

- Multiple Rust implementations run YOLOv8/v10/v11 ONNX: capture → 640×640
  preprocess → inference → NMS decode → bounding boxes + labels + FPS
  ([Alfred Weirich, Rust+ORT+YOLO parts 2–3](https://medium.com/@alfred.weirich/rust-ort-onnx-real-time-yolo-on-a-live-webcam-part-2-d74efc01bae0),
  [yolo-rust-ort](https://github.com/han-minhee/yolo-rust-ort),
  [yolov8_onnx_rust](https://github.com/AndreyGermanov/yolov8_onnx_rust)).
- `ort` is **already a dependency** of the app (pinned `=2.0.0-rc.9` for the
  `moonshine`/`silero-vad` features), so the vision runtime is in-tree.
- Standard model: `yolov8n` / `yolov8s` / `yolov11n` ONNX (COCO-80 classes),
  from Ultralytics export — tiny models run real-time on CPU/GPU.

### 2.2 Tracking — ByteTrack / SORT

The industry default for associating detections across frames is **ByteTrack**
(two-stage association: high-confidence detections → tracks, then low-confidence
recovery), with **SORT** (Kalman-predicted boxes + IoU matching) as the simpler
fallback ([ByteTrack arXiv](https://ar5iv.labs.arxiv.org/html/2110.06864),
[Roboflow trackers](https://trackers.roboflow.com/latest/trackers/bytetrack/)).

- A desktop app at 15–30 fps can implement a **SORT-class tracker** (Kalman +
  IoU) in ~200 lines with zero new deps; ByteTrack's low-confidence recovery is
  a later upgrade.
- Track identity stability is the point: "is the thing now the *same* box as
  before" → a stable track id.

### 2.3 The "pen / hand" nuance (important scope fork)

User-named targets reveal a real class-coverage gap:

| Target | Coverage | Consequence |
|---|---|---|
| person, phone, bottle, laptop, book, cup, … | COCO-80 (stock YOLO) | ✅ out of the box |
| **hand** | not in COCO; MediaPipe hand-landmark ONNX model exists | ✅ via a dedicated hand model / selective class |
| **pen** (and other *small, fine-grained* objects) | not in COCO; needs fine-grained detector or a VLM that returns boxes | ⚠️ hardest — see §4 |

Two honest options for "pen" and other arbitrary objects:

- **(A) Fixed-class detection** — ship one COCO YOLO model + an optional
  MediaPipe-hand model; "pen" is simply not a reliable class.
- **(B) Open-set / prompt-driven detection** — a grounded VLM or CLIP-based
  detector that returns a box for *"the pen"* described in text. More capable,
  heavier, less deterministic (researchy, slower).

This proposal ships **(A)** as v1 and treats **(B)** as a clearly-scoped
follow-up (§8), because open-set detection is a materially different model and
latency profile.

## 3. Goals

1. **Live local detection** — a user-enabled "vision" toggle runs an ONNX YOLO
   detector on the camera feed and draws labelled bounding boxes over the live
   preview.
2. **Local tracking** — a SORT/ByteTrack-class tracker assigns stable track ids
   across frames, so moving objects keep their identity.
3. **All on-device** — no cloud; detection/tracking/boxes run in-process via the
   existing `ort` + `image` crates. Consistency with the app's local-first stance.
4. **Minimal-footprint default** — vision is **off** by default and opt-in; a
   tiny model (`yolov8n`/`yolov11n`) is the default for CPU real-time.

## 4. Non-goals (this proposal)

- **Open-set / prompt-driven detection** ("box whatever matches this text") —
  deferred to a follow-up (§8).
- **"Pen" as a reliable class** in v1 — it is not in the stock detector; v1
  covers COCO classes + hand.
- **Biometric identity** / face-*recognition* databases — person detection yes,
  person *identification* no (unchanged from the earlier 075 privacy line).
- **Semantic segmentation / instance masks** — detection boxes only in v1.
- **Cloud detection** — local-only.

## 5. Design

### 5.1 `vision` module (new)

```
src/vision/
├── mod.rs            # VisionController: enabled toggle, per-frame pipeline
├── detect.rs         # YOLO ONNX: preprocess → ort inference → NMS → Vec<Detection>
├── tracker.rs        # SORT-class: Kalman + IoU association → stable track ids
└── hand.rs           # (optional) MediaPipe-hand ONNX for the "hand" label
```

- `Detection { class_id, label, confidence, bbox: [x,y,w,h] }` and
  `Track { id, class_id, label, bbox, // + age/frames_since_seen }`.
- Feature-gated behind `camera` (already present) or a new `vision` feature that
  adds `ort` (reuses the existing `ort` dep, no new crate).

### 5.2 Pipeline

```
camera frame (PreviewFrame, ~15 fps)
   → downscale to 640×640 (letterbox)       [detect.rs]
   → ort::Session::run (YOLO n)             [detect.rs]
   → decode + NMS (conf ≥ 0.25, IoU ≥ 0.45) [detect.rs]
   → SORT track association                  [tracker.rs]
   → render boxes + labels + track ids into the preview overlay
```

- The overlay is drawn client-side (webview) from a JSON array of
  `{id, label, confidence, bbox}` the Rust core emits each frame — the webview
  only draws, never touches the model.

### 5.3 Overlay / UI

- Home **"Vision"** toggle (Start/Stop detection) + a live canvas overlay on the
  camera preview showing boxes, labels, and track ids.

### 5.3b Status & Diagnostics surfaces (new)

The vision feature is surfaced in the app's two existing observability views,
mirroring the camera/mic health-counter pattern:

- **StatusInfo** (`get_status`) gains: `vision_available` (whether the `camera`
  feature is compiled), `vision_running` (toggle on), and
  `vision_detector`/`vision_hand_model` (the configured models).
- **`VisionDiagnostics`** (new struct, surfaced in `get_diagnostics` alongside
  `MicDiagnostics` / camera health):
  - `available`, `running`,
  - `detector_model`, `hand_model` (+ loaded/installed flags from the registry),
  - `detection_latency_ms` (last inference), `fps` (detections/sec),
  - `detections_per_frame` (last), `active_tracks`,
  - `track_events` (births/deaths/lost) and `last_error` (model-load or
    inference failure) — for "is vision actually seeing anything?" the way the
    mic stall/live-level meter answers "is capture delivering?".
- **Settings tab** row for vision (enable + model picker + thresholds) with the
  same debounced-save contract as the rest of Settings.

### 5.4 Config & models

- `Settings.vision` block: `enabled`, `detector_model` (`yolov8n`/`yolov11n`),
  `confidence_threshold`, `iou_threshold`, `track_objects` (bool), `hand_model`
  (optional).
- Model download via the existing model-registry (`stt/model_registry.rs`)
  pattern — pinned SHA-256, streaming, progress — extended to catalog the two
  ONNX vision models in `%APPDATA%/nvoos-dictate/models/`.

### 5.5 Dependencies

- **No new crates.** `ort` (in-tree, pinned rc.9), `image` (in-tree from
  `camera`), `ndarray` (in-tree). NMS/tracker are hand-rolled (~200–300 LOC)
  to avoid a heavyweight ML dependency.

## 6. Risks & mitigations

| Risk | Mitigation |
|---|---|
| `ort` ONNX drift (rc.9) vs detector export | Reuse the existing rc.9-pinned `ort`; validate the exported model against it at first compile (same discipline as moonshine/silero-vad) |
| CPU real-time throughput | Tiny `yolov8n`/`yolov11n` default; 15 fps preview cadence (not 30); opt-in |
| Class coverage (pen, arbitrary small objects) | §2.3/§4: v1 = COCO + hand; open-set deferred |
| Tracker identity drift under occlusion | SORT kalman + IoU; ByteTrack low-confidence recovery as M5 upgrade |
| Privacy overreach | Person *detection* only, no identification; all local; opt-in toggle |

## 7. Decisions (resolved 2026-10-13)

1. **Hand model: include in v1.** Ship the MediaPipe-hand ONNX alongside the
   COCO detector; a selective-class step maps hand detections to a `hand` label.
2. **Default detector: `yolov11n`.** YOLOv11 exports the same ONNX output shape
   as v8 and runs on the same pinned `ort`, so it is safe as the default; `yolov8n`
   retained as a catalog fallback.
3. **Open-set "track the pen": include a v1 affordance.** Ship a
   click-to-select on a box that pins the tracker to that instance (keeping a
   stable track id while the object moves), without full prompt-driven
   re-identification. Full open-set detection remains a follow-up.
4. **Overlay: webview canvas.** The Rust core emits a JSON array of
   `{id, label, confidence, bbox}`; the webview draws boxes over the preview. The
   model and boxes never cross the network boundary.

## 8. Milestones

1. **M1 — detector.** `vision/detect.rs`: 640×640 letterbox + `ort` YOLO inference
   + NMS decode; unit test against a sample ONNX (or a shape stub).
2. **M2 — tracker.** `vision/tracker.rs`: SORT Kalman+IoU association; unit tests
   (synthetic track states).
3. **M3 — wiring.** `VisionController` on the camera frame ring; IPC
   (`vision_start`/`vision_stop`/`vision_status`) + model-registry entries; off by
   default.
4. **M4 — overlay + Diagnostics.** Webview canvas overlay drawing boxes/labels/track
   ids; Diagnostics latency/FPS/track diagnostics.
5. **M5 — hand model + selection + ByteTrack upgrade.** Optional hand detector;
   click-to-select tracking pin; ByteTrack low-confidence recovery; docs + skill
   update.

---

*Replaced the embedding/identity framing with the actual requirement: an opt-in,
local, ONNX-YOLO detector + SORT/ByteTrack tracker drawing labelled boxes on the
camera preview — "track what's in frame" locally. Person detection only, no
biometric identification; hand detection and a click-to-select tracking pin are
in v1; full open-set "track the pen" is a scope-traced follow-up.*
