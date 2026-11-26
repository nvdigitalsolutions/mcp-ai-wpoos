# 153 — Cognition Hand-Gesture Input: Implementation Plan

- **Date:** 2026-11-20 (status updated 2026-11-21)
- **Status:** 🚧 **M1–M5 shipped** (2026-11-21) — `src/gestures/` (14 files, including the new
  `deictic.rs` and `arbiter.rs`), the `gestures` feature, camera `frame_vision` ring,
  Settings/Diagnostics/IPC surfaces, the Home skeleton overlay, and the M5 "point + ask"
  deictic path are implemented and green on every gate below. **M0 is still open**
  (`models.rs` deliberately ships no URL/hash — MediaPipe distributes `.task`/TFLite, not
  ONNX, and no verified ONNX export with provenance was found; users supply a local
  `.onnx`). **M6's rulebook is shipped but not wired** — the stabiliser does not publish
  an ambiguous band yet.
- **Gates actually run (2026-11-21):** `cargo fmt --check`; `cargo clippy --all-targets
  -- -D warnings` on default, `vision`, `camera`, `gestures`, `gestures,vision`,
  `snippet`, `document`, `snippet-pdf`, `snippet-onnx`, `memory`, `mcp`, `agent`, and the
  software union (`camera,vision,gestures,agent,mcp,snippet,snippet-onnx,document,snippet-pdf,memory`);
  `cargo test` on default (**304**), `gestures` (**308**), `vision` (**316**),
  `gestures,vision` (**316**), `document` (**346**) and `snippet-pdf` (**346**) across all
  targets, 3 ignored each; `cargo check --features gestures --example gesture_probe`;
  `npm run build` (`tsc --noEmit && vite build`). No ESLint is configured in this package.
- **Fixed in passing (same bug class, found by the new gate):**
  `--features snippet-pdf` alone did not compile (`cannot find pdf in document`)
  because the feature declared `snippet` while `export_pdf.rs` re-rasterizes pages
  through the `document`-gated `document::pdf`; it now declares
  `["document", "dep:lopdf"]`. `--features silero-vad` alone did not compile either
  — `error.rs` gated its `From<ort::Error>` impl on that feature while the feature
  only enabled `dep:ort-sys` (the FFI build, not the safe wrapper our code uses);
  `silero-vad` is dropped from that cfg, since `audio/vad.rs` already maps the
  error by hand. CI never caught either because the complete-build union always
  lists every feature at once.
- **New CI gate:** the `feature-matrix` job in `build-nvoos-dictate.yml`
  (`windows-latest`) runs three things:
  1. `cargo hack clippy --each-feature --all-targets --keep-going
     --exclude-features whisper,parakeet,fastconformer -- -D warnings`;
  2. clippy over the feature pairs that share a dependency (`gestures,vision` ·
     `camera,vision` · `document,snippet-pdf` · `snippet,snippet-onnx`);
  3. `cargo hack test --each-feature --lib`, plus the `document` integration
     suite (pdfium is fetched, sha256-verified and cached).
  `--lib` is deliberate: without it, 15 × all-targets link steps exhausted
  link-time resources on the reference PC. Caches now use
  `Swatinem/rust-cache@v2` (dependency artifacts only, pruned before save,
  keyed per dependency profile) instead of caching the whole `target/` tree —
  the old `actions/cache` entries were 40+ GB each on one key per OS, so the
  `rust`, `boot-smoke` and `windows-bundle` jobs fought over them and blew the
  10 GB repo budget. Engines stay local gates (CMake/libclang for whisper, the
  sherpa-onnx prebuilt for parakeet/fastconformer); `windows-bundle` still
  links all three in the complete union.
- **`hotkey::win_hook` flake — diagnosed and fixed.** The intermittent
  three-at-once failure was `ptt_pressed_alone()` reading the *live*
  `GetKeyboardState`: a stray non-modifier key (left-Alt is deliberately not
  an allowed modifier) made a synthetic PTT press pass through, the assertion
  failed, and the panic poisoned `TEST_LOCK`, taking the next two tests with
  it. The decision is now the pure `alone_from_state(&[u8; 256], ptt)` with a
  `#[cfg(test)]` key-table override; 200 consecutive full-suite runs are
  clean, and the module now also covers shift/ctrl tolerance, the left-Alt
  rejection, and a real Alt-combo pass-through.
- **New advisory CI gate (`engine-matrix`) + one more bug found by it.**
  `whisper`, `parakeet` and `fastconformer` were the last per-feature gap:
  `windows-bundle` only ever builds them *together*, so a cfg block that only
  works when another engine is enabled is invisible there. The new
  `ubuntu-latest` job (with `cmake libclang-dev clang` installed) clippy-checks
  each engine alone, then all three together, with `continue-on-error: true` —
  advisory because it depends on native toolchain provisioning, with
  `windows-bundle` remaining authoritative. It immediately found that
  `--features fastconformer` did not compile: the sherpa installer is gated
  `any(parakeet, fastconformer)` while `replace_dir`/`replace_dir_backoff` were
  `parakeet`-only (`cannot find function replace_dir`). Fixed by widening both
  helpers and the three shared-installer tests to `any(parakeet,
  fastconformer)`. All four engine configurations (`whisper`, `parakeet`,
  `fastconformer`, and the three together) now pass `clippy -D warnings`.
- **Companion docs:** [`153-cognition-hand-gesture-input-proposal.md`](153-cognition-hand-gesture-input-proposal.md)
  · [`153-cognition-hand-gesture-input-research.md`](153-cognition-hand-gesture-input-research.md)
- **Branch note:** touches `examples/nvoos-dictate/**` plus this proposal set and
  a new ADR. It must **not** touch `src/hotkey/` (PTT/hook isolation rule).

---

## Verified dependency facts (2026-11-20)

| Item | Verified fact |
|---|---|
| `ort` / `ort-sys` | pinned `=2.0.0-rc.9` in `src-tauri/Cargo.toml:131,134`. Use `ort::session::Session`, `ort::value::Value`, positional `ort::inputs![Value::from_array(..)?]` — rc.9 has no crate-root re-exports. |
| `ndarray` split | crate pins `ndarray` 0.15 (silero-vad) and aliases `ndarray16` for `ort`. Gesture tensors must use `ndarray16`. |
| Crate toolchain | `edition = "2021"`, `rust-version = "1.90"` → **`rten` 0.28 is unusable** (needs rustc 1.94 + edition 2024). `ort` is the only runtime. |
| Camera | `camera/capture.rs` holds `nokhwa::Camera` on `nvoos-camera`; only `Arc<Mutex<Option<PreviewFrame>>>` + atomics cross threads. |
| Preview resolution | `camera/capture.rs:162` writes `downsample(raw, w, h, 320)` only. A second ring is required for gesture-quality frames. |
| Vision loop | `vision/controller.rs` runs 66 ms ticks on `nvoos-vision`; polish runs on `nvoos-vision-polish` with its own tokio runtime. |
| VLM transport | `cleanup/local_openai.rs` + `cleanup/openai_shape.rs`; `vision/polish.rs` already builds `{image: data-uri, text}` requests for LM Studio/Ollama. |
| Settings | `Settings` serde-defaults every block; `VisionConfig.hand_model` exists and is unused. `Settings::save` is atomic (tmp + rename). |
| Model download | `vision/models.rs::download` is the pattern: streaming SHA-256, `.download` partial, force restart, atomic rename. |
| Feature gating | Modules stay **type-stable** with a stub `#[cfg(not(feature = ...))]` path, exactly like `camera/` and `vision/`. |
| Complete build union | `npm run tauri -- build --debug --no-bundle --features fastconformer,parakeet,whisper,silero-vad,camera,vision,agent,mcp,snippet,snippet-onnx,document,snippet-pdf,memory` — add `gestures`. |
| CI gates | `cargo fmt --check`, `cargo clippy --all-targets -- -D warnings` (default + feature), `cargo test` (default + feature), plus native build matrix in `build-nvoos-dictate.yml`. |

---

## Key design decisions (locked)

1. **`gestures` is a Cargo feature that implies `camera`.** It does not imply
   `vision`, but declares `ort`/`ort-sys`/`ndarray16` itself so a
   gestures-only build does not require the detector.
2. **The geometry classifier is always compiled** (pure Rust, no `ort`). Only
   the ONNX landmark stage is feature-gated. This keeps the maths, the
   stabiliser and the router testable on the default feature set — the same
   trick `vision/tracker.rs` uses.
3. **A second frame ring (`frame_vision`, ≤640 px) is additive.** Preview stays
   at 320 px. If camera CPU rises more than ~15% on the reference PC, collapse
   to one 480 px ring for both.
4. **One gate, one enum.** `ActionId` cannot represent a forbidden action, so
   the router's safety check is a `match` on a closed enum, not string parsing.
5. **No new dependency.** Everything is `ort` + existing crates.
6. **No changes to `src/hotkey/`, `paste/`, or `cleanup/`.** The only edits to
   existing modules are: a second frame ring in `camera/`, a `gestures` field in
   `config.rs`, a `gesture_diagnostics` block in `ipc.rs`, registration lines in
   `lib.rs`, and one guarded hook in `dictation.rs` (start/stop only).
7. **Weights are never bundled.** Model files download at runtime with a pinned
   SHA-256; the repo contains only the catalogue entry.

---

## M0 — Spike and decision gate (`examples/gesture_probe.rs`)

**Goal:** prove or disprove the ONNX landmark model on the pinned runtime
*before* any module structure exists.

Checklist:

- [ ] Choose two candidate pinned exports of a 21-landmark hand model
      (`hand-landmark-full`, `hand-landmark-lite`) and record:
      - upstream URL (exact), file size, SHA-256
      - model card URL + licence text
      - input names/shapes (NCHW vs NHWC, normalisation), output names/shapes
      - whether world landmarks are included
- [ ] `examples/gesture_probe.rs` (`required-features = ["gestures"]`):
      - `--download` → fetch both files into
        `%APPDATA%/nvoos-dictate/models/`, verify SHA-256
      - `--image <path>` → letterbox to the model's input, run inference, print
        the 21 landmarks, handedness, per-stage ms
      - `--video <path>` → run N frames, print landmark jitter (per-coordinate
        std-dev after EMA) and FPS
      - `--fixture` → run a generated synthetic hand image and assert the
        expected landmark ordering (thumb tip is landmark 4, pinky tip is 20)
- [ ] Compare against reference output from the MediaPipe Python task on the
      same fixture (one-off, recorded in the plan's verification table). Flag
      normalisation differences (0..1 vs 0..255, RGB vs BGR, `[-1,1]`).
- [ ] Measure on the reference PC: mean/p95 landmark latency at 224 and 320 px
      input, debug and release.
- [ ] Record whether the 320 px preview ring is good enough or the 640 px ring
      is required (measure recall on a hand held at ~1.5 m from the webcam).

**Gate:** the chosen export loads on rc.9, reproduces landmark ordering, and
hits ≤ 35 ms mean in release at 224 px. If not, try the second export. If
neither passes, the feature does **not** proceed to M1 on a model basis — the
geometry/stabiliser work (M1) can still land independently, but no UI ships.

**Trap:** do not "fix" normalisation by trial and error against a live camera.
Pin one fixture image, record its expected output once, and make the probe
assert it. Camera-based tuning belongs in M2, not M0.

---

## M1 — Pure-Rust core (no model, no camera)

**Goal:** the parts that decide whether the feature feels solid, testable with
synthetic landmarks only.

New files under `src-tauri/src/gestures/`:

| File | Contents |
|---|---|
| `mod.rs` | Always compiled. `HandLandmarks`, `GestureObservation`, `GestureId`, `ActionId`, `Handedness`, `available() = cfg!(feature = "gestures")`. |
| `normalize.rs` | Translate-to-wrist, scale-by-palm-width, rotate index-MCP→pinky-MCP; returns `[Point3; 21]`. Handles left/right mirroring. |
| `geometry.rs` | Finger-extension state, thumb abduction, thumb–index distance, palm normal. One `Thresholds` struct with `Default`. |
| `classifier.rs` | `StaticClassifier` trait + `GeometryClassifier` (7 poses). Returns a probability vector (softmax-free, normalised scores) so the stabiliser can see the runner-up. |
| `motion.rs` | Trajectory ring buffer (500 ms), palm-centre normalised displacement, direction reversals. Emits `D-*`. |
| `stabilize.rs` | EMA, hysteresis, N-of-M debounce, cooldown, track-presence gate. Emits `GestureObservation`. |
| `router.rs` | `GestureObservation + Context → Option<GestureIntent>`; `ActionId` allowlist; safety class; T0 table. |
| `catalogue.rs` | Gesture/action metadata for UI and docs: id, human name, safety class, keyboard equivalent, icon hint. |

Unit tests (no feature flag, no network):

- `normalize_*`: synthetic landmarks at different scales/rotations produce
  identical normalised results; a mirrored buffer flips handedness exactly once.
- `geometry_*`: each of the 7 poses synthesised from joint angles classifies
  correctly; a borderline pose yields two candidates (exercises the ambiguity
  band).
- `classifier_never_emits_denylisted`: `ILoveYou`, middle-finger, and unknown
  labels are unrepresentable / rejected.
- `stabilize_*`: single-frame flips do not fire; 3-of-4 does; hysteresis holds
  through the 0.55–0.75 band; cooldown suppresses a second fire; a hand
  appearing for < 3 frames never fires.
- `motion_*`: a synthetic swipe fires once with the right direction; a slow
  drift does not; a wave produces exactly one toggle.
- `router_*`: safe actions fire; guarded actions set `requires_confirm`;
  forbidden actions are not expressible (compile-time test via a `match` with
  no wildcard that would accept them).
- `router_cooldown_per_action`: cooldown is per-action, not global (so swipe →
  swipe is throttled but swipe → dismiss is not).

**Gate:** `cargo test` (default features) green; `cargo clippy --all-targets --
-D warnings` green. No new dependency.

---

## M2 — Landmark backend + controller + frame ring

New files:

| File | Contents |
|---|---|
| `gestures/landmarks.rs` | `trait HandPoseBackend`; `OrtHandPose` behind `#[cfg(feature = "gestures")]` + a stub that returns `Unsupported`. Letterbox, tensor build, decode, NMS-free (one hand by default, `num_hands ≤ 2`). |
| `gestures/models.rs` | Pinned catalogue (mirrors `vision/models.rs`): id, description, size, licence, URL, file name, SHA-256; `is_installed`, `download` (streaming, force, atomic). |
| `gestures/controller.rs` | `GestureController`: worker `nvoos-gesture`, snapshot (`Arc<Mutex<GestureSnapshot>>`), stats atomics, stop/pin channels, arming state. Mirrors `vision/controller.rs` including the stub `start()` for non-feature builds. |
| `gestures/diagnostics.rs` | `GestureDiagnostics` struct + counters (or fold into `ipc.rs` like `VisionDiagnostics`). |

Modified files:

| File | Change |
|---|---|
| `camera/mod.rs` | Add `pub frame_vision: Arc<Mutex<Option<PreviewFrame>>>` to `CameraCapture` (and to the stub struct). |
| `camera/capture.rs` | Write both rings: `downsample(..., 320)` for preview and `downsample(..., 640)` for vision; add `VISION_MAX_W: u32 = 640`. Keep the existing `frame` field name so no downstream call site changes. |
| `config.rs` | Add `GesturesConfig` + `pub gestures: GesturesConfig` to `Settings` with `#[serde(default)]`. |
| `state.rs` | `gestures: Mutex<Option<GestureController>>`, `start_gestures`, `stop_gestures`, `reapply_gestures(previous)` (drop-before-reload on model change). |
| `Cargo.toml` | `gestures = ["camera", "dep:ort", "dep:ort-sys", "dep:ndarray16"]`; `[[example]] gesture_probe`. |
| `lib.rs` | Register the new IPC commands; include `gestures` in the complete-build documentation comment. |

Behaviour:

- Start requires a running camera: `start_gestures()` returns a typed error
  (`"camera is not running"`) rather than silently starting capture.
- Model load failure is reported with the exact path and install state.
- On settings change, drop the controller (releases the ONNX file handle)
  *before* downloading/replacing a model, then restart — the documented
  memory-layer rule.
- When `settings.vision.enabled` is also true, the vision loop calls
  `GestureController::tick(frame)` after detection on the same frame; the
  gesture loop does not double-read the ring.

**Gate:** `cargo clippy --all-targets --features gestures -- -D warnings`;
`cargo test --features gestures` green (lib tests + controller start/stop);
`gesture_probe --fixture` asserts landmark ordering; live probe on the
reference PC prints sane landmarks and ≥ 15 fps end-to-end.

**Traps:**

- `ort` rc.9 positional inputs only. Do not copy `.task`-era examples.
- `nokhwa` frames may be BGR depending on backend; normalise once in
  `landmarks.rs` and assert channel order on a known fixture (the OCR stack
  already learned this the hard way).
- Do not hold the `frame_vision` lock across inference. Clone the `Arc`, copy
  the pixels, release.
- The stub `GestureController::start` must keep the **same signature** across
  feature sets or `state.rs` will not compile in default builds.

---

## M3 — Router, safety gate, and dictation integration

Modified files:

| File | Change |
|---|---|
| `dictation.rs` | One guarded hook: `gestures::router::apply(intent, &state)` where `ActionId::StartDictation` / `StopDictation` map to the *existing* start/stop paths. Nothing else. |
| `ipc.rs` | `get_gesture_state`, `get_gesture_events`, `gestures_available`, `start_gestures`, `stop_gestures`, `get_gesture_models`, `download_gesture_model`, `set_gesture_arming`; add a `gesture` block to `get_diagnostics`. |
| `lib.rs` | Register commands; emit `gesture://observation`, `gesture://fired`, `gesture://armed`. |
| `state.rs` | Arm/disarm state machine: `Disarmed → Armed → Firing → Cooldown → Armed`; auto-disarm after 10 s of no hand presence. |

Behaviour:

- Guarded actions: `requires_confirm` produces a `gesture://confirm` event; the
  frontend or the next accepted gesture completes it within 3 s, otherwise it
  expires. **No confirmation is ever auto-approved.**
- `StopDictation` and `StopMeeting` are `Guarded` **and** bypass cooldown
  (emergency stop must not be throttled).
- The event ring buffer is bounded (64) and holds no pixels — ids, scores,
  timestamps only.
- Every mapped action also registers a keyboard equivalent. The
  `catalogue.rs` entry is the single source for both the Settings legend and
  the audit test.

**Gate:** unit tests for the state machine and the confirm expiry; a manual
matrix on the reference PC (arm → point → dismiss → swipe → stop) recorded in
the plan's verification table; `cargo test --features gestures,vision` proves
co-scheduling does not deadlock.

---

## M4 — UI surfaces

Modified files:

| File | Change |
|---|---|
| `src/lib/ipc.ts` | Types + wrappers for the new commands and events. |
| `src/views/Home.tsx` | Skeleton overlay on the existing `<canvas>`; label + confidence bar; armed chip + countdown; last action; new "Ask your camera" card (hidden unless `deictic_enabled`). |
| `src/views/Settings.tsx` | "Gestures" tab: enable, arming mode, threshold sliders with a live preview, vocabulary checkboxes, legend, keyboard-equivalent table, deictic + arbiter blocks (provider/model/test/clear token). |
| `src/views/Diagnostics.tsx` | Gestures card: frames, landmarks/s, infer ms, stabiliser drops, intents by action, LLM calls/results/timeouts, last error, model install state. |
| `src/styles.css` | Skeleton stroke, confidence bar, armed chip — reuse existing tokens. |

Behaviour:

- The skeleton is drawn from the *observation* event at ≤ 10 Hz; do not
  re-render on every frame.
- Reduced-motion: static outline, no pulse animation.
- Empty states: "Camera off", "Gestures not compiled into this build",
  "Model not downloaded" each get a distinct message with the fix action.

**Gate:** `tsc --noEmit && vite build`; the WebView2 CDP check (documented in
the app skill) confirms the tab renders and Diagnostics reports the real
engine/model state.

---

## M5 — T1 deictic grounding (`ask_camera`)

> **As built (2026-11-21) — deltas from the plan below:**
>
> * No `gestures/deictic_prompt.rs`. The prompt lives in
>   `cleanup/providers/openai_shape::deictic_prompt` next to
>   `vision_polish_prompt`, because that module already owns the
>   no-face-identification copy and is where a reviewer looks for it.
> * No `nvoos-gesture-llm` thread and no separate tokio runtime. The command is
>   `async` and awaits the provider on Tauri's runtime — a one-shot user action
>   needs no resident loop, and `block_on` inside that runtime would panic.
> * Referent freshness is "a hand is visible **now**", not "snapshot ≤ 2 s
>   old". Latching the last pointing box is a follow-up; requiring the hand
>   keeps the crop honest and the failure message actionable.
> * Also no `test_deictic_connection` command (reuses
>   `test_vision_polish`'s endpoint/model) and no copy button (the panel shows
>   the answer; pasting anywhere would violate D9).
> * The crop helper **was** generalised — it moved to
>   `util::image::crop_to_data_uri`, shared with vision polish.
> * **The spoken leg is not wired.** There is no in-memory last transcript to
>   read (`dictation.rs` exposes stats, not text), so v1 asks in a text field.
>   Adding one means changing the dictation state machine deliberately.

New files:

| File | Contents |
|---|---|
| `gestures/deictic.rs` | Crop from `frame_vision` by bbox (clamped), downscale to ≤512 px long edge, PNG-encode, base64 data URI. Reuses `vision/polish.rs`'s crop helper if it is generalisable; otherwise a local function with a unit test. |
| `gestures/deictic_prompt.rs` | System prompt + request shape. Restates: no identification of people, no guessing, say "unclear" when the crop is ambiguous. |

Modified files:

| File | Change |
|---|---|
| `ipc.rs` | `ask_camera(question)` command; `test_deictic_connection`; credential set/clear reusing the LM Studio/Ollama keychain keys pattern. |
| `lib.rs` | Spawn `nvoos-gesture-llm` with its own tokio runtime (mirror `nvoos-vision-polish`). |
| `Home.tsx` | Question input + answer area + "copy" button. |

Behaviour:

- Requires `deictic_enabled` **and** a recent gesture snapshot (≤ 2 s old).
  Otherwise the command returns a typed error ("no recent pointing gesture").
- Request body is `{image: data-uri, text: question}` through
  `LocalOpenAiProvider`; 8 s timeout; errors are surfaced verbatim and never
  replaced with a fabricated answer.
- **The answer never enters another app's text field.** It is displayed and
  optionally copied — a deliberate decision (see proposal §3 D9).
- Diagnostics counts calls/results/timeouts; the crop is never written to disk.

**Gate:** unit tests for crop clamping (bbox partially off-frame, zero-size,
inverted), prompt invariant ("must not identify people"), and error mapping;
live check against a local VLM on the reference PC with a printed page and a
mug.

---

## M6 — T2 arbiter, hardening, docs

> **As built (2026-11-21):** only the `arbiter.rs` rulebook and the two docs
> shipped. The router does **not** call the arbiter — the stabiliser publishes
> no ambiguous band, so wiring it needs a new signal from `stabilize.rs` first.
> `ipc.ts`/`Settings.tsx` gained the M5 deictic controls, not an arbiter toggle.
> The hardening checklist below is still open except the gates.

New files:

| File | Contents |
|---|---|
| `gestures/arbiter.rs` | Ambiguity detection, request build (no pixels), strict response parse (`{"action": ...}`), allowlist validation, safety-class preservation, timeout/fallback. |
| `docs/decisions/0008-hand-gesture-engine.md` | ADR: geometry-first, ort reuse, arming model, why no TFLite runtime. |
| `docs/gesture-vocabulary.md` (app-local) | The legend, safety classes, keyboard equivalents — single source for UI copy review. |

Modified files:

| File | Change |
|---|---|
| `ipc.ts` / `Settings.tsx` | Arbiter toggle + model discovery via `GET /v1/models`. |
| `.agents/skills/mcp-ai-wpoos-dictation-app/SKILL.md` | New "Gestures" section: feature union, markers, probe, traps (mirroring, 320 px ring, hotkey isolation). |
| `docs/project/proposals/README.md` | Add 153 to the index (if the index is maintained for new entries). |

Hardening checklist:

- [ ] Fuzz-ish test: random landmark vectors never panic and never produce a
      non-allowlisted `ActionId` (property test over 10k cases).
- [ ] Timeout/error injection for both LLM tiers → feature stays usable.
- [ ] Camera stop while armed → auto-disarm, no stale `Armed` chip.
- [ ] Model file removed while running → snapshot reports "model missing",
      loop stops cleanly, no panic.
- [ ] Two hands in frame → deterministic hand selection (largest bbox), no
      double-fire.
- [ ] No-motion case (hand still for 60 s) → zero fired actions.
- [ ] String probe on the exe (`HandLandmarks`, `GestureController`) proves
      linkage; CDP check proves the UI renders.

**Gate:** full matrix — `cargo fmt --check`; `clippy -D warnings` on default,
`gestures`, `gestures,vision`, `gestures,memory`; `cargo test` on default and
`gestures`; `tsc --noEmit && vite build`; 10-minute armed-idle false-positive
run (< 1 event); verification table filled with reference-PC numbers.

---

## File-by-file change list (summary)

**New**

```
examples/nvoos-dictate/src-tauri/
├── examples/gesture_probe.rs
└── src/gestures/
    ├── mod.rs            (always compiled)
    ├── normalize.rs      (always compiled)
    ├── geometry.rs       (always compiled)
    ├── classifier.rs     (always compiled)
    ├── motion.rs         (always compiled)
    ├── stabilize.rs      (always compiled)
    ├── router.rs         (always compiled)
    ├── catalogue.rs      (always compiled)
    ├── landmarks.rs      (cfg gestures; OrtHandPose + stub)
    ├── models.rs         (catalogue + download)
    ├── controller.rs     (worker + snapshot + stub start)
    ├── deictic.rs        (cfg gestures)
    ├── deictic_prompt.rs (cfg gestures)
    └── arbiter.rs        (cfg gestures)
examples/nvoos-dictate/docs/decisions/0008-hand-gesture-engine.md
examples/nvoos-dictate/docs/gesture-vocabulary.md
```

**Modified**

```
examples/nvoos-dictate/src-tauri/Cargo.toml        (+ gestures feature, + example)
examples/nvoos-dictate/src-tauri/src/camera/mod.rs (+ frame_vision field + stub)
examples/nvoos-dictate/src-tauri/src/camera/capture.rs (+ second ring)
examples/nvoos-dictate/src-tauri/src/config.rs     (+ GesturesConfig)
examples/nvoos-dictate/src-tauri/src/state.rs      (+ controller lifecycle)
examples/nvoos-dictate/src-tauri/src/ipc.rs        (+ commands, + diagnostics)
examples/nvoos-dictate/src-tauri/src/lib.rs        (+ registration, + events)
examples/nvoos-dictate/src-tauri/src/dictation.rs  (+ guarded start/stop hook only)
examples/nvoos-dictate/src/lib/ipc.ts
examples/nvoos-dictate/src/views/Home.tsx
examples/nvoos-dictate/src/views/Settings.tsx
examples/nvoos-dictate/src/views/Diagnostics.tsx
examples/nvoos-dictate/src/styles.css
.agents/skills/mcp-ai-wpoos-dictation-app/SKILL.md
docs/project/proposals/README.md
```

**Explicitly untouched:** `src/hotkey/**`, `paste/**`, `cleanup/**`,
`stt/**`, `memory/**`, `snippet/**`, `document/**`. A diff that touches any of
these is a review red flag (the hotkey file is the app's most fragile module).

---

## Test matrix

| Layer | Default features | `gestures` | `gestures,vision` | Notes |
|---|---|---|---|---|
| `normalize` / `geometry` / `classifier` | ✅ | ✅ | ✅ | Pure Rust, synthetic landmarks |
| `stabilize` / `motion` / `router` | ✅ | ✅ | ✅ | Pure Rust, ring-buffer driven |
| `models::download` | — | ✅ | ✅ | Checksum + truncating-open regression (mirror of the STT regression) |
| `controller` start/stop | — | ✅ | ✅ | Model missing → typed error |
| Co-scheduling | — | — | ✅ | Vision loop drives gesture tick; no deadlock |
| Deictic crop | — | ✅ | ✅ | Clamping, zero-size, inverted bbox |
| Arbiter parse | — | ✅ | ✅ | Malformed/extra fields/unknown action → `none` |
| Property: no forbidden action | ✅ | ✅ | ✅ | 10k random observations |
| UI build | — | — | — | `tsc --noEmit && vite build` |
| Live (ignored in CI) | — | ✅ | ✅ | `live_gesture_fires_swipe`, `live_gesture_armed_idle_quiet` |

---

## Verification table (fill during M0–M6)

**Status 2026-11-21:** every row that needs a real landmark model is **blocked on
M0** — there is no verified ONNX export, so nothing below could be measured
honestly. Rows are marked ⛔ rather than left blank so a reader can tell
"not measured" from "measured as zero". The gate results that *were* run are
in the next table.

| Metric | Target | Measured | Date | Build |
|---|---|---|---|---|
| Landmark model load on rc.9 | loads | ⛔ M0 open — no verified export | — | — |
| Landmark latency p50 / p95 (224 px) | ≤ 25 / 35 ms | ⛔ M0 open | — | — |
| Landmark latency p50 / p95 (320 px) | ≤ 35 / 50 ms | ⛔ M0 open | — | — |
| Geometry classify | < 1 ms | ⛔ timing not measured (covered by unit tests) | — | — |
| End-to-end gesture→action p95 | ≤ 120 ms | ⛔ M0 open | — | — |
| Armed idle CPU | < 2% core | ⛔ M0 open | — | — |
| Armed idle false positives / 10 min | < 1 | ⛔ needs a live model + real desk time | — | — |
| Static accuracy, 8 poses | ≥ 95% | ⛔ M0 open; the geometry fixture pins separability, not dataset accuracy | — | — |
| Swipe accuracy, 4 directions | ≥ 90% | ⛔ M0 open | — | — |
| Arbiter p95 | ≤ 250 ms | ⛔ M6 wiring deferred (rulebook tested, provider not called) | — | — |
| Deictic round-trip p50 | ≤ 900 ms | ⛔ not measured (needs a live VLM endpoint) | — | — |
| Debug exe size delta | ≤ +3 MB (no weights) | ⛔ not measured | — | — |

## Gate results (2026-11-21, reference PC, MSYS + MSVC)

| Gate | Result |
|---|---|
| `cargo fmt --check` | clean |
| `cargo clippy --all-targets -- -D warnings` | clean |
| `cargo clippy --all-targets --features gestures -- -D warnings` | clean |
| `cargo clippy --all-targets --features gestures,vision -- -D warnings` | clean |
| `cargo test --lib` | 262 passed, 3 ignored |
| `cargo test --features gestures --lib` | 266 passed, 3 ignored |
| `cargo test` (all targets) | 304 passed, 3 ignored |
| `cargo test --features gestures` (all targets) | 308 passed, 3 ignored |
| `cargo test --features vision` (all targets) | 316 passed, 3 ignored |
| `cargo test --features gestures,vision` (all targets) | **316 passed**, 3 ignored |
| `cargo check --features gestures --example gesture_probe` | clean |
| `npm run build` (`tsc --noEmit && vite build`) | clean |
| `npm run lint:js` | n/a — no ESLint config in `examples/nvoos-dictate` |

---

## Rollback

1. Feature is default-off, so rollback is "do not enable".
2. If shipped on: flip `Settings.gestures.enabled = false`; the controller
   stops, the camera returns to preview-only behaviour, and the second frame
   ring is written but unread (negligible cost) — or gate the second ring on
   `gestures.enabled || vision.enabled` so a disabled build pays nothing.
3. If the model proves unusable, delete the catalogue entry and the M2 module
   body; M1 (pure Rust) remains testable and can ship later against a
   different backend.
4. If the macOS sidecar leg regresses, the backend trait returns `Unsupported`
   on `cfg(target_os = "macos")` and the feature reports "not available on this
   platform" — same degradation path as snippet OCR without Vision.

---

## Open questions to resolve before M2

1. Which exact ONNX export (provenance, hash, licence) passes M0? This is the
   only genuinely blocking unknown.
2. Does 640 px materially improve small-hand recall over 480 px on the
   reference webcam? Measure, do not assume.
3. Is the second ring's camera CPU cost acceptable on a mid-range laptop, or
   should the detector and landmarker share a single 480 px ring?
4. Does the learned classifier ever justify its licence complexity, given
   geometry covers 8 poses? Defer until after M4 usage data.

---

## Postmortem — startup wedge after the M4/M5 UI wiring (2026-11-21)

### Symptom

The debug build stopped responding on startup. Windows reported
`Responding: False` with CPU flat (~0.8 s total), yet the window painted
normally. Reported as "this only started after the recent addition of gestures".

### How it was actually diagnosed (worth reusing)

`Responding` is not a usable signal on its own, and `SendMessageTimeout`-based
probes can be fooled. Three measurements turned a guess into a fact:

1. **WebView2 CDP** (`WEBVIEW2_ADDITIONAL_BROWSER_ARGUMENTS="--remote-debugging-port=9222 --remote-allow-origins=*"`)
   proved the page **did** load: `document.readyState === 'complete'`, React
   mounted, `#root` populated, JS timers fired, `__TAURI_INTERNALS__` present.
2. **An IPC round-trip probe** — `invoke('get_settings')` raced against a
   timeout — proved the Rust side was wedged: the call returned in 27 ms at
   +4.0 s and then never returned again. Everything after that hung,
   *including* `Runtime.evaluate`, because Tauri's Windows IPC is synchronous:
   the renderer blocks waiting on the host, so one blocked host thread freezes
   both sides.
3. **A per-command enter log** in `ipc.rs` named the culprit in one run: the
   last `CMD enter:` line was `get_status`, with no matching return.

Timeline that pinned the window: `__TAURI_INTERNALS__` undefined at +3.7 s,
`get_settings` OK (27 ms) at +4.0 s, first timeout at +5.2 s.

### Root cause

`ipc::get_status` read the gesture controller **twice inside a single struct
literal**:

```rust
// BROKEN — self-deadlock
Ok(StatusInfo {
    gesture_armed: state.gestures.lock().unwrap().as_ref()
        .map(|g| g.armed()).unwrap_or(false),
    gesture_last: state.gestures.lock().unwrap().as_ref()
        .and_then(|g| g.snapshot().last_action)
        .map(|a| a.as_str().to_string()),
    // ...
})
```

Rust extends a temporary's lifetime to the end of the enclosing **statement**,
so the `MutexGuard` created for `gesture_armed` was still alive when
`gesture_last` locked the same mutex. `std::sync::Mutex` is **not reentrant**,
so the thread blocks on itself, permanently.

The consequence is amplified by two Tauri facts:

* **`#[tauri::command]` functions that are not `async` run on the main thread.**
  A blocking sync command therefore stalls the whole IPC dispatcher, not just
  one request.
* **Windows IPC is synchronous**, so the renderer blocks too — which is why the
  UI looked "painted but frozen" and why CDP `Runtime.evaluate` later hung.

This is why it looked gesture-related: the two offending lines were the two
gesture fields added for M4. It would have fired on any first `get_status`
call, whether or not a gesture controller existed (`None` still locks).

### Theories that were tested and eliminated

| Theory | Test | Result |
| --- | --- | --- |
| Auxiliary windows created too early | `NVOOS_SKIP_AUX_WINDOWS=1` | still froze — **not** the cause |
| Worker-thread window creation | same | still froze — **not** the cause |
| Orphaned WebView2 hosts | process count | 0 orphans |
| Model / memory / engine load | engine `mock`, all disabled | still froze |
| Missing or changed microphone | `input_device: null` | still froze |
| Console / feature union | CDP target inspection | main window only |

### Fix

Read every gesture field under **one** guard, in a named helper so the pattern
is testable and cannot be re-inlined per-field:

```rust
fn gesture_status_fields(
    gestures: &std::sync::Mutex<Option<std::sync::Arc<GestureController>>>,
) -> (bool, Option<String>) {
    let guard = gestures.lock().unwrap();
    match guard.as_ref() {
        Some(c) => {
            let s = c.snapshot();
            (c.armed(), s.last_action.map(|a| a.as_str().to_string()))
        }
        None => (false, None),
    }
}
```

`get_status` now calls `gesture_status_fields(&state.gestures)` once.

### Consequence for the earlier "wry#583" workaround — reverted

Because the wedge was misattributed to window creation, the aux windows had
been moved off the main thread onto a `nvoos-aux-windows` worker. Creating
Win32 windows off their owning message-pump thread is not a safe pattern, so
that change was reverted: `ensure_overlay` / `ensure_snippet_window` are called
directly from `setup` again. Verified: all three webviews (`main`, `overlay`,
`snippet`) are created and the app stays responsive — the "snippet builder
never returns" observation was the `get_status` deadlock blocking the main
thread that the builder's queued work needed.

### Regression guard

`ipc::tests::gesture_status_fields_reads_do_not_deadlock` runs the read on a
local `Mutex<Option<Arc<GestureController>>>` in a worker thread and waits on a
`recv_timeout(5s)`. A reintroduced double-lock makes the read never return, so
the timeout fails the test instead of hanging the suite.

### Verification after the fix

* IPC round-trip 3–9 ms, continuously, across 24 probes over ~6 s — with the
  user's real config (`fastconformer` + `sherpa-onnx-nemo-ctc-en-conformer-large`,
  memory and snippet enabled).
* `Responding: True`; CPU advances normally.
* All three webviews present: `main`, `overlay`, `snippet`.
* UI round-trip confirmed through the real button: Status → **Refresh** renders
  `fastconformer · sherpa-onnx-nemo-ctc-en-conformer-large`, microphone
  `available`, memory `1263/1263 chunks indexed`.
* Gates: `cargo test --lib` (union) **320 passed, 0 failed, 3 ignored**;
  `cargo clippy --all-targets -D warnings` clean on the full union;
  `cargo check --lib` clean with default features.

### Rule to carry forward

> **Never lock the same `std::sync::Mutex` (or take a `RwLock` write guard)
> twice inside one expression** — especially inside `struct { .. }` literals and
> argument lists, where temporaries live to the end of the statement. Take one
> named guard, compute the fields, drop it. Sync `#[tauri::command]` functions
> run on the main thread, so a lock mistake there freezes the entire app instead
> of returning an error.

---

## M0 gate closed — pinned hand-landmark artifact (2026-11-21)

The catalogue is no longer fail-closed: it carries one provenance-checked export,
and `Settings → Gestures` now shows a **Download** button ("like the rest") that
installs it through the same streaming / atomic-rename / SHA-256 path as the
vision models.

| Field | Value |
| --- | --- |
| Artifact | `handpose_estimation_mediapipe_2023feb.onnx` |
| Upstream | OpenCV Zoo `handpose_estimation_mediapipe` — ONNX conversion of Google MediaPipe Hands |
| URL | pinned to repository revision `4b2a0b446e5cf2f11fb6b2c7251091c035d2c1f7` (not a branch) |
| Size | 4,099,621 bytes |
| SHA-256 | `db0898ae717b76b075d9bf563af315b29562e11f8df5027a1ef07b02bef6d81c` |
| Licence | Apache-2.0 |

### Measured ONNX contract

Read from the artifact itself (`onnx.load`), not from documentation:

* input `input_1` `[1, 224, 224, 3]` → **NHWC**, float 0..1;
* outputs `Identity` `[1,63]` landmarks, `Identity_1` `[1,1]` confidence,
  `Identity_2` `[1,1]` handedness, `Identity_3` `[1,63]` world landmarks.

### Two backend changes this forced

1. **Input layout.** The export is NHWC; the backend hard-coded NCHW, so it
   would have failed at `session.run`. `InputLayout` is now derived from the
   model's declared input dimensions (falling back to NCHW when the shape is
   dynamic or unexpected, preserving behaviour for earlier exports), and
   `chw_to_hwc` reorders the letterboxed buffer.
2. **Output roles.** tf2onnx names every output `Identity*`, so the name scan
   found neither the confidence nor the handedness head. An undiscovered
   confidence head is not cosmetic — every frame without a hand would have
   decoded as a detected hand. Roles now fall back to tensor sizes: 63/42 floats
   are the landmark tensor, and the 1-element heads are confidence then
   handedness, matching Google's export order.

Both are covered by unit tests (`layout_is_read_from_the_declared_dims`,
`chw_to_hwc_interleaves_channels`, `anonymous_outputs_resolve_by_shape`,
`element_count_needs_every_dimension`), plus a catalogue test asserting the
pinned entry is a revision URL with a 64-hex hash.

### Verification

* `gesture_probe` on a real hand photograph → **score 0.829**, bbox over the
  hand, 25 ms inference (224 px input).
* `gesture_probe` on the *Open Hand Monument* (a sculpture) → **score 0.001** —
  the confidence head correctly rejects a non-hand, which is the property that
  matters for the stabiliser.
* In-app: Download button → installed in ~3 s at
  `models/hand-landmark.onnx`, SHA-256 re-verified on disk, button disappears,
  catalogue flips to `installed: true`.
* `start_camera` → `start_gestures` → `gestures_running: true` → clean stop.

### Known limitation (recorded in the catalogue description)

This export regresses landmarks from a **cropped hand**; the palm-detector stage
is not part of this pipeline. Accuracy therefore depends on the hand occupying a
good share of the frame. Adding the palm detector as a first stage is the
follow-up that would make it work at arm's length.
