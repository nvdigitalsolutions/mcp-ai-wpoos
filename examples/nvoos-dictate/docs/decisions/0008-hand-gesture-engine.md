# 0008 — Hand-gesture input: geometry-first classification, reuse `ort`, fail-closed catalogue

- **Date:** 2026-11-21
- **Status:** Accepted (Proposal 153, M1–M4 core shipped; M0 model pin open)
- **Related:** Proposal 153 (`docs/project/proposals/153-cognition-hand-gesture-input-*`), Proposal 073 (camera), Proposal 075 (vision), 0003 (local engines first)

## Context

The app already has an opt-in local webcam (`camera`, nokhwa) and an opt-in
local object detector (`vision`, YOLO ONNX on the pinned `ort` rc.9). This
ADR covers a third local camera consumer: recognising a small set of hand
poses and turning them into **app actions**.

The constraints that shape the decision:

- **Privacy is the product.** The pitch is "frames never leave this
  machine". A cloud gesture API is disqualified outright, and so is any
  design that uploads frames "just for recognition".
- **The latency budget is a keyboard budget.** Dictation targets
  < 1.5 s key-up → paste. A gesture that takes 800 ms to be believed feels
  broken, so recognition must run at frame rate on CPU with no round trip.
- **The blast radius must be small.** This is a *new input device* on a
  running dictation app. A false positive that starts a dictation is
  recoverable; one that deletes, sends, or buys is not. The safety model
  has to be structural, not a string check.
- **The app is proprietary** (`UNLICENSED`, mirrored with `LICENSE.txt`),
  so every runtime dependency has to be license-clean for closed source.
- **No new heavy dependency.** The build already carries `ort` rc.9 for
  vision; a second inference runtime would double the native build surface
  for one feature.

## Decision

1. **Geometry-first classifier (v1), pure Rust, no weights.** 21 landmarks
   are normalised (translate to the palm centre, scale by palm width,
   derotate), then finger curl / spread / thumb-direction ramps produce a
   `HandShape`, which maps to one of 8 static `GestureId`s. Motion gestures
   (5) come from a separate tracker over palm-centre history. A learned
   classifier is explicitly **v2**: it needs labelled data we do not have
   and it would make the vocabulary's failure modes opaque while the
   contract is still being designed.

2. **The only model in the loop is a landmark model, and it runs on the
   existing `ort` rc.9.** `gestures` implies `camera` and pulls
   `dep:ort`/`dep:ort-sys`/`dep:ndarray16`, exactly as `vision` does — so a
   build with both features links one runtime, not two. `ndarray16` (the
   0.16 alias) is required because `ort` rc.9 maps 0.16 while the app pins
   0.15 for `silero-vad`; rc.9 also has no crate-root `Session`/`Value`
   re-export.

3. **One decode, two rings.** The camera worker already downsamples for the
   preview. It now also fills `frame_vision` (≤ 640 px) beside `preview`
   (≤ 320 px). Recognition reads `frame_vision`; the UI reads `preview`.
   Adding a third ring or a second `nokhwa` decode is out of scope.

4. **Arming is explicit and nothing fires while disarmed.** `OpenPalm` held
   `arm_hold_ms` (600 default) arms; `ClosedFist` dismisses an overlay or
   disarms; the session also expires after `armed_timeout_ms` of no
   activity. This is the answer to "the webcam sees a hand at a desk all
   day": incidental hand poses cannot actuate anything.

5. **Forbidden actions are unrepresentable.** `ActionId` has ten variants
   and **no** variant for delete, purge, send, purchase, or any outbound
   tool call. The allowlist is checked in exactly one place (`router.rs`).
   A future contributor cannot "add one more action" without editing the
   enum — which is the review gate we want.

6. **Guarded actions need a second signal.** `ToggleDictation` and
   `StopDictation` are the only `Safety::Guarded` actions. A guarded intent
   executes only when the pose was held for `confirm_hold_ms` (1500
   default) — `hold_confirmed == true`. A short flash emits
   `gesture://pending-confirm` and runs nothing.

7. **The landmark model catalogue is fail-closed.** `models.rs` ships
   `url: None` and `sha256: None`; `download()` returns a typed error that
   names the open milestone. Users supply a local `.onnx` via
   `Settings.gestures.model_path`.

## Alternatives rejected

- **MediaPipe Tasks (`.task`, TFLite) + a TFLite runtime.** MediaPipe's own
  distribution is TFLite, not ONNX, so this would mean adding `tract-tflite`
  or `tflitec` purely for gestures — a second inference stack, a second
  licensing review, and a second set of platform build problems. Rejected
  on the "no new heavy dependency" constraint.
- **`rten` 0.28 (pure-Rust inference).** Attractive (no C++ runtime), but it
  requires rustc 1.94 + edition 2024; the workspace is 1.90/2021. Rejected
  as a toolchain migration smuggled in by a UI feature.
- **MediaPipe Hands via the Python sidecar.** The app has a Python sidecar
  (Apple Vision OCR on macOS), but a per-frame subprocess hop cannot hold
  the latency budget and would put a Python dependency on Windows for a
  camera feature.
- **A cloud gesture API.** Disqualified by the privacy pitch.
- **A large vocabulary (20+ poses).** Rejected: every class adds false
  positives, a cultural-meaning problem to justify, and a keyboard
  equivalent to maintain. Breadth is a liability, not a feature.
- **Face / identity signals.** Bright-line non-goal. Stated in the UI copy,
  not just the docs.

## Consequences

- `cargo test` **without** the feature still runs the entire decision layer
  (classification, stabilisation, routing, safety). That is deliberate: the
  safety contract is CI-covered on the default build, and only the three
  `ort`-touching bodies (`landmarks`, `models`, `controller`) are gated.
- Two feature sets now pull `ort`, so the gate must include the **combined**
  run (`--features gestures,vision`). Both start loops off the same ring;
  testing them only in isolation would miss a deadlock or a stolen frame.
- The vocabulary is fixed in `catalogue.rs` and surfaced to the UI through
  `get_gesture_catalogue`. Adding a pose means touching the enum, the
  catalogue, the classifier fixture, and the action mapping — four
  deliberate edits, not one.
- **M0 remains open.** No verified ONNX export with a provenance trail was
  found, so there is no default download. Until M0 pins one, gestures need a
  user-supplied model file. Shipping an unverified URL would fail on the
  user's machine instead of at review, which is strictly worse.
- A learned classifier (v2) can replace `classifier.rs` behind the same
  `GestureId` contract without touching the router, the stabiliser, or the
  safety model — that is why the decision layer is separated from the
  landmark backend.
- **M5 shipped as "point + ask", and that shape is itself a decision.** The
  gesture supplies the *referent*; the question supplies the *intent*. Keeping
  them separate means the gesture never has to be recognised precisely enough
  to imply a command, and the model output stays display-only. The spoken leg
  is deferred because exposing the last transcript would mean changing the
  dictation state machine (`src/dictation.rs`), which this work deliberately
  did not touch.
- **M6's rulebook is written before the wiring on purpose.** Arbitration can
  only narrow: it cannot add an action, cannot promote a guarded action to
  safe, and cannot resurrect a disallowed fallback. Writing and testing those
  rules as a standalone module means the safety contract is reviewable
  independently of the model integration, and the wiring becomes a small,
  auditable change.
