# 153 — Research: Hand-Gesture Input for NV oOS Cognition

Companion research to [`153-cognition-hand-gesture-input-proposal.md`](153-cognition-hand-gesture-input-proposal.md)
and [`153-cognition-hand-gesture-input-implementation-plan.md`](153-cognition-hand-gesture-input-implementation-plan.md).

All facts below were verified against the cited source on **2026-11-20** unless
marked as an inference. Anything not verified is labelled explicitly — the
proposal must not depend on unverified claims.

---

## 1. Method and what "industry standard" means here

Hand-gesture input has four independent layers, and mature projects rarely
solve them the same way:

| Layer | What it decides | Industry default |
|---|---|---|
| **Capture** | Frame rate, resolution, colour order, mirroring | 15–30 fps, 320–640 px wide, BGR/RGB normalisation, mirrored preview |
| **Detection / landmarks** | Where the hand is, 21 keypoints, handedness | Per-frame palm detector + landmark regressor with frame-to-frame bbox reuse |
| **Recognition** | Which gesture is intended | Static classifier over landmarks; temporal model or trajectory heuristics for motion |
| **Interaction** | Whether the gesture becomes an action | Explicit engagement, hysteresis, dwell/confirm, cooldown, always a non-gesture alternative |

The failure modes that dominate field reports are never in layer 2. They are:

- **Midas touch** — every natural hand movement is interpreted as a command.
- **Arm fatigue ("gorilla arm")** — sustained mid-air pointing is tiring; users
  abandon interfaces that require it for more than a few seconds.
- **Recognition churn** — a gesture flickers between two labels at the
  confidence boundary, firing twice or firing the wrong action.
- **No escape hatch** — a gesture mis-fired and there is no keyboard/pointer
  path to undo it.

Every recommendation in this proposal is derived from those four risks, not
from benchmark chasing.

---

## 2. What the Cognition app already has (verified in-repo)

| Fact | Evidence |
|---|---|
| Camera capture exists and is feature-gated (`camera`, `nokhwa` 0.10) | `src-tauri/src/camera/capture.rs`, `Cargo.toml` |
| Capture worker is `nvoos-camera`; `nokhwa::Camera` is `!Sync` and stays on that thread | `camera/capture.rs` `CameraCapture::start` |
| **Preview frames are downsampled to ≤320 px wide** (`downsample(raw, w, h, 320)`) | `camera/capture.rs:162` |
| ONNX runtime is **pinned** `ort = "=2.0.0-rc.9"` + `ort-sys` = same pin | `Cargo.toml:131,134` |
| `ndarray` 0.15 (silero-vad) and `ndarray16` alias for `ort` | `Cargo.toml:140,144` |
| Object detection + SORT tracking already ships behind `vision = ["camera", "dep:ort", "dep:ort-sys", "dep:ndarray16"]` | `vision/mod.rs`, `vision/detect.rs`, `vision/tracker.rs`, `vision/controller.rs` |
| Vision loop runs at ~15 fps (`sleep(66ms)`) on its own `nvoos-vision` thread | `vision/controller.rs:340` |
| A dedicated VLM polish thread (`nvoos-vision-polish`) owns a tokio runtime so a ~500 ms round-trip never stalls the loop | `vision/controller.rs:235` |
| Shared local-VLM transport exists (`LocalOpenAiProvider`, LM Studio / Ollama, OpenAI chat shape) | `cleanup/local_openai.rs`, `cleanup/openai_shape.rs`, `vision/polish.rs` |
| `Settings.vision.hand_model` **already exists but is unused at runtime** — it is only echoed into `StatusInfo` and diagnostics | `config.rs:457`, `ipc.rs:147,626` |
| `cargo` feature union for a complete build is already large; a new feature must not silently drop surfaces | `.agents/skills/mcp-ai-wpoos-dictation-app/SKILL.md` |
| Crate is `edition = "2021"`, `rust-version = "1.90"` | `Cargo.toml` |

**Consequence 1.** A gesture pipeline does not need a new capture stack, a new
threading model, or a new ONNX runtime. It needs a *second frame consumer* and
a *larger frame*.

**Consequence 2.** The 320 px preview is the single biggest accuracy risk: a
hand at 0.5–4 m occupies very few pixels. MediaPipe's landmark and gesture
models take 192×192 / 224×224 inputs, so 320 px "works", but a dedicated
640-px ring materially improves small-hand recall. This is a concrete,
testable claim for the M0 spike, not an assumption.

---

## 3. Detection and landmark layer

### 3.1 MediaPipe Hand Landmarker (Google AI Edge) — the reference implementation

Verified from the official task documentation (2026-11-20):

- Outputs, per hand, index-parallel arrays: **handedness** (`Left`/`Right`),
  **21 landmarks** normalised to `[0,1]` with `z` relative to the **wrist
  (landmark 0)**, and **21 world landmarks in metres** with the origin at the
  hand's geometric centre.
- Landmark IDs 0–20: wrist, thumb CMC/MCP/IP/TIP, index MCP/PIP/DIP/TIP,
  middle MCP/PIP/DIP/TIP, ring MCP/PIP/DIP/TIP, pinky MCP/PIP/DIP/TIP.
- Model bundle = **palm detector + hand landmark regressor**, trained on
  ~30K real images plus rendered synthetic hands.
- In `VIDEO`/`LIVE_STREAM` modes the pipeline **reuses the previous frame's
  landmark bbox to localise the hand** and only re-runs palm detection when
  tracking fails or the hand count drops. This is the standard cheap-tracking
  trick and is why per-frame cost stays low.
- Config knobs: `numHands` (default **1**), `minHandDetectionConfidence`
  (0.5), `minHandPresenceConfidence` (0.5), `minTrackingConfidence` (0.5).
- **Published benchmark (Pixel 6):** HandLandmarker (full, FP16) **17.12 ms
  CPU / 12.27 ms GPU**.
- **Critical caveat:** "Left and Right handedness correspond to the subject's
  hands, not the viewer. **Passing a horizontally mirrored image will invert
  these outputs.**" The Cognition preview *is* mirrored (selfie view); the
  landmark stage must therefore either consume the unmirrored buffer or flip
  handedness before classification.

### 3.2 MediaPipe Gesture Recognizer — the reference recognition layer

Verified from the official task documentation (2026-11-20):

- Emits gesture categories, handedness, 21 image landmarks and 21 world
  landmarks — i.e. **detection and classification in one task**.
- **Canned vocabulary is 8 categories**, not 30+:
  `Unknown`/`None`, `Closed_Fist`, `Open_Palm`, `Pointing_Up`, `Thumb_Down`,
  `Thumb_Up`, `Victory`, `ILoveYou`.
- Architecture is a **two-stage bundle**: a *gesture embedding* model encodes
  image features, then a **lightweight classifier** consumes the embedding.
  The canned classifier is replaceable — "you can extend the model bundle to
  recognize more gestures by bundling your own custom gesture classifier".
  When both canned and custom classifiers recognise something, **the custom
  result wins**.
- Classifier options support `scoreThreshold`, `maxResults`,
  `categoryAllowlist` / `categoryDenylist` (mutually exclusive). An allowlist
  is the shipped mechanism for restricting the vocabulary — directly relevant
  to the safety design here.
- **Published benchmark (Pixel 6):** GestureRecognizer (FP16) **16.76 ms CPU /
  20.87 ms GPU**; model inputs 192×192 and 224×224.
- The classifier is **per-frame/static**. There is no swipe/wave/circle class.
  Dynamic gestures are explicitly out of scope for the stock task.

**Consequence 3.** A credible v1 does not need a 33-class model. Eight static
gestures plus deterministic motion primitives cover the interaction vocabulary
of a dictation app, and the 8th canned class (`ILoveYou`) should be *excluded*,
not mapped (see §7.3).

### 3.3 Apple Vision hand pose (macOS leg)

Verified from Apple's documentation index (2026-11-20):

- `DetectHumanHandPoseRequest` "detects a human hand pose" and produces
  `HumanHandPoseObservation` values.
- Configurable `maximumHandCount`, `supportedJointNames`,
  `supportedJointsGroupNames`, and a revisioned algorithm
  (`revision` / `supportedRevisions`).
- Runs through the standard `ImageProcessingRequest` surface — `perform(on:)`
  for `CVPixelBuffer`, `CMSampleBuffer`, `CGImage`, `Data`, `URL`.

This is the natural macOS counterpart, and it fits the existing architecture:
the app already ships a Swift sidecar (`Snipper.swift`) with a one-shot
`ocr`/`screenshot` protocol for the snippet feature. A `handpose` subcommand
would reuse that transport, keeping the Rust core unchanged. It is
**CI-verified only** on this project because Swift cannot be built on the
Windows reference machine — the same constraint the OCR feature already lives
with.

Dynamic gestures on macOS have a first-party option too: CreateML's
**Hand Action Classifier** (trained on labelled hand-pose sequences). That is a
distribution-time asset decision, not a v1 requirement.

### 3.4 Windows

There is no first-party hand-pose WinRT API comparable to Vision's
`DetectHumanHandPoseRequest`. Windows ML / DirectML are inference *runtimes*,
not domain APIs. The app's existing answer — ONNX via `ort` — is therefore the
correct and only portable Windows path. No new dependency is implied.

---

## 4. Recognition layer: static, dynamic, and where models come from

### 4.1 Geometry-first classification (recommended v1)

The best-maintained community reference is
[`Kazuhito00/hand-gesture-recognition-using-mediapipe`](https://github.com/Kazuhito00/hand-gesture-recognition-using-mediapipe)
(verified 2026-11-20: **774 stars, 690 forks, 55 commits, Apache-2.0**).

Its architecture is exactly the two-tier split this proposal needs:

1. **Hand-sign classifier** — a *simple MLP over the 21 keypoints* (preprocessed
   landmark vector), shipped as a tiny TFLite model plus the training notebook
   and CSV of keypoints.
2. **Finger-gesture classifier** — a *point-history* classifier over the index
   fingertip's coordinate history with an optional **LSTM** head, for
   circular/moving gestures. Classes shipped: stationary, clockwise,
   counter-clockwise, moving.

Two transferable lessons:

- **63 floats are enough.** A classifier over normalised landmarks is tiny
  (<2 ms) and can be trained from a few hundred samples per class.
- **Motion is a separate model over a short history**, not a label emitted by
  the static classifier. Static and dynamic must be separate stages.

For v1 the even cheaper option is **pure geometry** — finger-extension state
from joint angles plus thumb–index distance, all after a similarity
normalisation (translate to wrist, scale by palm width, rotate so
index-MCP→pinky-MCP is horizontal). This is deterministic, needs no weights,
and is unit-testable against synthetic landmark fixtures. It is the direct
analogue of the app's Stage-1 deterministic cleanup; the learned classifier
becomes the optional Stage-2.

### 4.2 HaGRID / HaGRIDv2 — the vocabulary and evaluation corpus

Verified from the dataset repository (2026-11-20):

| Property | HaGRID (2022) | HaGRIDv2 (2024-09-24) |
|---|---|---|
| Images | 552,992 | **1,086,158** |
| Classes | 18 + `no_gesture` | **33 + `no_gesture`** |
| Size | 716 GB | **1.5 TB** (512 px subset 119.4 GB) |
| Subjects | 34,730 | **65,977** |
| Distance | 0.5–4 m | 0.5–4 m |
| Landmarks | — | auto-annotated MediaPipe landmarks per hand |

Notable extras:

- Two-handed classes were added in v2 (`hand_heart`, `hand_heart2`,
  `thumb_index2`, `timeout`, `holy`, `take_picture`, `xsign`).
- **2025-02-27:** a dynamic-gesture algorithm was released that "enables the
  recognition of dynamic gestures while being trained **exclusively on static
  ones**", with pretrained models. This is strong evidence that dynamic
  recognition can be layered on a static dataset without a second labelling
  campaign.
- Reported baselines: YOLOv10x gesture detector **89.4 mAP**, YOLOv10n 88.2;
  hand detector YOLOv10x 88.8; full-frame classifiers ResNet152 **98.6 F1**,
  MobileNetV3_large 93.4.
- Annotation format includes normalised bboxes, per-hand landmark arrays and a
  `no_gesture` class with 200,390 boxes — i.e. a ready-made **false-positive
  corpus**.
- **Licence: "a variant of Creative Commons Attribution-ShareAlike 4.0
  International"** (dataset); model weights inherit dataset obligations. This
  is *not* a permissive licence and must be an explicit decision, not a
  default. See §8.

**Consequence 4.** HaGRID is the right *benchmark*, but shipping HaGRID-trained
weights in a proprietary app requires a licence review. v1 should therefore
ship geometry (no weights) and treat any HaGRID-derived classifier as an
opt-in, separately-licensed download with attribution.

### 4.3 Canned-classifier realities that shape the vocabulary

The 7 usable MediaPipe classes are a poor fit for a dictation app on their own:

- `Thumb_Up` / `Thumb_Down` are culturally loaded; they must be *confirmation*
  gestures only, never silent destructive triggers.
- `Victory` is a common accidental pose (two fingers while typing/gesturing).
- `Open_Palm` is the single most useful class — it is a natural "stop", "wait",
  and "attention" gesture, and it reads well from a webcam across distances.
- `ILoveYou` is an ASL sign with meaning. Mapping it to an app action is both
  culturally inappropriate and a mis-trigger risk.

---

## 5. Accessibility and interaction standards

### 5.1 WCAG 2.2 — gesture input is never the only path

Verified from W3C WAI "Understanding SC 2.5.1 Pointer Gestures" (Level A,
updated 2026-08-10):

> "All functionality that uses multipoint or path-based gestures for operation
> can be operated with a single pointer **without a path-based gesture**,
> unless a multipoint or path-based gesture is essential."

Supporting facts from the same document:

- A **path-based gesture** is one "where the movement of the pointer along a
  specific path matters" — swipes/flicks and shape drawing are the canonical
  examples.
- Multipoint = two or more simultaneous pointers (pinch, split tap,
  two-finger swipe).
- The criterion does **not** apply to gestures defined by the OS/user agent,
  only to author-provided content — but for a desktop app the spirit applies:
  app-defined gestures need app-defined alternatives.
- New in 2.2: the single-pointer alternative "can't exclusively rely on a
  dragging movement" (that is SC 2.5.7).
- Failures are codified as **F105** ("providing functionality via a path-based
  gesture without simple pointer alternative").
- The stated benefit includes users with cognitive/learning disabilities who
  "may not understand the custom gesture interaction" — an argument for visible
  legends and simple gestures, not for clever ones.

Related criteria that bind this design:

- **2.1.1 Keyboard** — every gesture action needs a keyboard equivalent.
- **2.5.4 Motion Actuation** — device/user-motion input needs an alternative
  and a way to disable it; here, gestures are *opted into*, never required.
- **2.5.7 Dragging Movements** — do not encode an action only as a drag.

**Design rule adopted:** every gesture in the vocabulary maps to an existing
keyboard shortcut or button. The gesture is an *accelerator*, never the sole
path. Swipes are the highest-risk class (path-based) and therefore get the
most conservative mappings (navigation/scroll, never commit).

### 5.2 Apple Human Interface Guidelines — "Gestures"

Verified from the HIG (gestures page, markdown render, 2026-11-20). Verbatim
principles that apply directly:

- **"Give people more than one way to interact with your app."** "Don't assume
  that people can use a specific gesture to perform a given task."
- **"In general, respond to gestures in ways that are consistent with people's
  expectations."** Avoid using a familiar gesture for a novel action, and avoid
  inventing a unique gesture for a standard action.
- **"Handle gestures as responsively as possible."** Provide feedback that
  "helps people predict its results".
- **"Indicate when a gesture isn't available."** Otherwise people "might think
  your app has frozen or they aren't performing the gesture correctly".
- **"Add custom gestures only when necessary."** Custom gestures must be
  **discoverable, straightforward to perform, distinct from other gestures, and
  not the only way to perform an important action.**
- **"Avoid requiring specific body movements or positions for input."** Reasons
  given include disability, spatial constraints and environmental factors.
- visionOS-specific but universally true: direct/close gestures "work best when
  they are within reach. Because people may find it tiring to keep their arms
  raised for extended periods, direct gestures are best for infrequent use."
  And: "A custom interaction that requires people to keep their arms raised for
  even a little while can be physically tiring."
- Also: **avoid gestures that require a specific hand**, and be careful with
  two-handed or multi-finger gestures because "people may not always have both
  hands available".

**Design rule adopted:** no sustained mid-air pointing. Every gesture is a
short, bounded pose or motion (<1.5 s). The app never requires a *held* hand
position to keep working; the only "hold" is an opt-in arming pose with a
visible timer and an immediate escape.

### 5.3 The Midas-touch and gorilla-arm constraints

These are standard HCI constraints rather than a single citable spec, but they
are the reason gesture products fail:

- **Midas touch** (after the myth): with an always-on camera, every scratch,
  wave at a colleague, or reach for coffee becomes a command. Mitigation:
  explicit engagement (arming) + a narrow vocabulary + a "no gesture" class
  with a high bar.
- **Gorilla arm**: sustained unsupported arm elevation is fatiguing within
  tens of seconds. Mitigation: short gestures, no dwell-to-select, no
  "hover to move a cursor".

### 5.4 Patterns that survive contact with users

| Pattern | Why | Implementation note |
|---|---|---|
| **Engagement gesture / arming** | kills Midas touch | `Open_Palm` held 600 ms, or the existing PTT-style hotkey |
| **Hysteresis** | kills label churn | enter at 0.75, exit at 0.55 |
| **Debounce** | kills single-frame noise | require N of M consecutive frames (3 of 4) |
| **Cooldown** | kills double-fire | 500 ms per action |
| **Two-stage commit** | kills accidental destructive actions | guarded actions require a second gesture/click |
| **Visible legend** | discoverability | overlay shows the 4–6 armed gestures, not 30 |
| **Immediate feedback** | trust | skeleton + label + confidence bar in the preview panel |
| **Undo** | recovery | every gesture action that mutates text is undoable |
| **Never sole path** | WCAG/Apple | keyboard equivalents shipped in the same milestone |

---

## 6. LLM integration: where a model actually helps

Gesture recognition is a *perception* problem; LLMs add value in exactly three
places, and harm in the rest. The app's existing discipline — deterministic
Stage-1, optional LLM Stage-2, LLM failure never blocks the core path — maps
cleanly.

### 6.1 Tier 0 — deterministic intent mapping (no model)

`gesture + context → action` via a static table. This must handle >90% of
events. It is instant, auditable, testable, and cannot hallucinate.

### 6.2 Tier 1 — deictic grounding ("point and ask")

The strongest product idea in this space: gesture supplies the *referent*,
speech supplies the *question*. Research and shipping products both call this
pointing/deictic grounding.

In this app it composes with existing pieces:

1. User arms `Pointing_Up` or `Open_Palm` (hold) → the gesture loop records the
   hand bbox at that instant.
2. User presses PTT and speaks ("what is this", "add this to the invoice").
3. After transcription, if `gestures.deictic_enabled`, crop the recorded bbox
   from the larger frame, PNG-encode it, and send **crop + transcript** through
   the Proposal 076 `LocalOpenAiProvider` multimodal transport.
4. Return an answer to the Home panel / clipboard. **The dictation text itself
   is not rewritten by default** — rewriting another app's text box with an
   LLM inference is exactly the kind of silent edit Proposal 144 was written to
   stop.

Latency budget: the gesture+speech path is interactive but not
key-up-critical; a ~500–900 ms VLM round-trip is acceptable *because* it runs
on a dedicated thread and never delays the paste.

### 6.3 Tier 2 — ambiguity resolution with a closed vocabulary

When geometry confidence sits in an ambiguous band (e.g. 0.55–0.75) or two
candidates tie, a local text-only model can arbitrate using *context only*:
candidate labels + scores + foreground app category + whether a confirm dialog
is open + the previous action. The request must be schema-constrained to:

```json
{ "action": "one of the allow-listed action ids, or none", "reason": "short" }
```

Hard gates (non-negotiable):

- The router validates the returned `action` against the **allowlist compiled
  into the binary**. Unknown ids → `none`.
- T2 can never *raise* an action's safety class. A guarded action stays
  guarded.
- Failure/timeout → fall through to T0's best guess only if it is `Safe`;
  otherwise drop the event.
- T2 never sees pixels.

### 6.4 What LLMs must not do here

- Never classify raw gestures from pixels (worse, slower, and non-deterministic
  than the landmark model).
- Never invent new actions.
- Never decide *whether* a destructive action happens.
- Never receive continuous frames or landmark streams (biometric-adjacent).

---

## 7. Safety, privacy, and vocabulary hygiene

### 7.1 Privacy posture (consistent with Proposals 073/075/076)

- Frames and landmarks are **processed in-process only**. No frame egress.
- The only egress is Tier-1 crops, and only when the user has enabled deictic
  grounding *and* explicitly invoked it.
- **No facial recognition, no face detection, no identity.** This is an
  existing bright line for the vision module and must be repeated in tool/UI
  copy.
- Landmarks are treatable as biometric-adjacent data: not persisted by default,
  not written to `config.json`, not included in diagnostics beyond aggregate
  counters. A debug landmark dump exists only behind an env var in debug
  builds.
- The camera indicator + "camera in use" OS light is the user's ground truth;
  the app must not start the camera implicitly because gestures are enabled
  (gestures start only when the camera is running).

### 7.2 Destructive-action gate

Consistent with Proposal 128's typed-confirmation pattern and the desktop
destructive-op gate:

- Gestures may never trigger `purge_memory`, transcript deletion, model
  deletion, purchase, send, or any outbound tool call.
- Guarded actions (start/stop dictation, paste, accept) require either a
  physical confirmation (click/hotkey) or a two-stage gesture with a visible
  prompt.
- Every action has a `safety` field and the router enforces it in one place —
  a single gate, like the memory purge gate.

### 7.3 Vocabulary hygiene

- **Exclude `ILoveYou`** (ASL sign, culturally inappropriate to repurpose).
- **Exclude the middle-finger class** (`middle_finger` in HaGRID) via an
  explicit denylist even if a learned classifier can emit it.
- Avoid gestures whose meaning flips by culture (thumbs up/down are used as
  *confirmation only*, never as a standalone command).
- Avoid two-handed and hand-specific requirements (Apple HIG).

---

## 8. Licensing and dependency matrix

| Asset | Licence | Verified | Decision |
|---|---|---|---|
| `ort` / `ort-sys` rc.9 | MIT OR Apache-2.0 | yes (already a dependency) | reuse, **no new dep** |
| `nokhwa` 0.10 | MIT (already a dependency) | yes | reuse |
| MediaPipe code / task docs | Apache-2.0 (code samples), CC-BY-4.0 (docs) | yes | reference only |
| MediaPipe model bundles (`.task`) | model-card terms (typically Apache-2.0) | **not verified per-model** | must be verified at pin time before shipping converted weights |
| HaGRID / HaGRIDv2 dataset | "variant of CC BY-SA 4.0" | yes | **copyleft-ish**; benchmark only, or opt-in download with attribution and a licence review — never bundled |
| `Kazuhito00` models/notebooks | Apache-2.0 | yes | reference architecture; do not vendor TFLite (no TFLite runtime in this app) |
| `rten` 0.28 | MIT OR Apache-2.0 | yes | **rejected**: requires `rustc 1.94` + `edition 2024`; repo is `rust-version 1.90` / `edition 2021` |
| `tract` | MIT OR Apache-2.0 | not re-verified this session | unnecessary — `ort` already ships |

**Packaging reality (important, and often glossed over):** MediaPipe ships
`.task` bundles (zipped TFLite), not ONNX. The app's runtime is ONNX-only. A
hand-landmark model therefore needs either (a) a pinned ONNX export whose
provenance and hash we control, or (b) a TFLite→ONNX conversion step kept in a
generator script, with the resulting file hash-pinned in
`vision/models.rs`-style catalogue code. Either way this is a **milestone with
a gate**, not a copy-paste.

---

## 9. Published performance numbers vs. our budget

| Stage | Published / reference | Cognition target (CPU, reference PC) |
|---|---|---|
| Hand landmarker | 17.12 ms CPU (Pixel 6, FP16) | ≤ 35 ms at 15 fps |
| Gesture classifier (embedding+head) | 16.76 ms CPU (Pixel 6) | ≤ 20 ms (learned) / < 1 ms (geometry) |
| YOLO detector (existing) | ~49 ms CPU (documented spike) | unchanged |
| Gesture→action latency | — | ≤ 120 ms p95 end-to-end |
| Idle CPU when armed | — | < 2% of one core |
| Idle CPU when disabled | — | 0 (no worker, no camera start) |

Budget rationale: the dictation path's key-up→paste budget is 1.5 s; a gesture
does not share that deadline, but *perceived* responsiveness for a discrete
input is roughly 100 ms. Hence the action, not the model, is the budget.

---

## 10. Rejected alternatives

| Alternative | Why rejected |
|---|---|
| **Always-on gesture control** | Midas touch; battery/CPU; privacy optics. Arming is mandatory. |
| **MediaPipe Tasks runtime (TFLite) in-process** | New C++ runtime, new licence surface, new cross-platform build. `ort` already ships. |
| **`rten` pure-Rust runtime** | Toolchain bump (1.94 / edition 2024) for no capability gain over the pinned `ort`. |
| **Learned classifier as v1** | Needs a licensed dataset or a labelling campaign before a single action works. Geometry ships first. |
| **33-class HaGRID vocabulary** | The majority of the 33 classes are two-handed (7 in v2), culturally loaded, or sign-language signs. Vocabulary breadth is a liability, not a feature. |
| **Cursor control from the hand** | Gorilla arm, precision, and WCAG path-based risk. Not a v1 goal. |
| **Cloud gesture API** | Contradicts local-first. Non-starter. |
| **VLM-as-gesture-classifier** | Slower, non-deterministic, cannot hit 100 ms, and reframes a perception problem as a generation problem. |
| **Rewriting dictated text from a VLM inference** | Silent edits to the user's words — the exact defect class fixed in Proposal 144. |

---

## 11. Source list

| # | Source | Used for |
|---|---|---|
| 1 | <https://ai.google.dev/edge/mediapipe/solutions/vision/hand_landmarker> | 21 landmarks, world landmarks, handedness caveat, tracking reuse, 17.12 ms/12.27 ms, config thresholds |
| 2 | <https://ai.google.dev/edge/mediapipe/solutions/vision/gesture_recognizer> | 8 canned categories, embedding+classifier bundle, custom classifier precedence, allow/deny lists, 16.76 ms/20.87 ms |
| 3 | <https://developer.apple.com/documentation/vision/detecthumanhandposerequest> | macOS hand-pose API shape, `maximumHandCount`, joint groups, revisioning |
| 4 | <https://github.com/hukenovs/hagrid> | HaGRIDv2 size/classes/subjects, dynamic-gesture release, baselines, CC BY-SA-variant licence |
| 5 | <https://github.com/Kazuhito00/hand-gesture-recognition-using-mediapipe> | MLP-over-keypoints + point-history/LSTM two-tier design, Apache-2.0, 774 stars |
| 6 | <https://www.w3.org/WAI/WCAG22/Understanding/pointer-gestures.html> | SC 2.5.1 text, path-based/multipoint definitions, F105, 2.5.7 cross-reference |
| 7 | <https://developer.apple.com/design/human-interface-guidelines/gestures> | Multi-path requirement, custom-gesture criteria, body-movement and fatigue guidance |
| 8 | <https://crates.io/api/v1/crates/rten> | `rten` 0.28.0 rustc 1.94 / edition 2024 requirement |
| 9 | In-repo: `examples/nvoos-dictate/src-tauri/**` | Existing camera/vision/ort/settings/IPC surfaces |

Items deliberately **not** cited because they could not be verified in this
session: MediaPipe per-model licence text for the hand/gesture bundles, the
exact byte sizes and SHA-256 of any ONNX export, and any claim that a specific
community ONNX conversion is numerically faithful. Those become **M0 gate
outputs**, not assumptions.
