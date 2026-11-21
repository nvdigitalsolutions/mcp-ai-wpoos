# Hand-gesture vocabulary (Proposal 153)

The complete gesture surface as shipped in M1–M4. This document is
**generated from `src-tauri/src/gestures/catalogue.rs`** — if the two
disagree, the code is authoritative and this file is stale.

Every gesture has a non-gesture equivalent (`ActionId::keyboard_equivalent`).
That is a hard rule, not a nicety: gesture input is an accelerator for people
who want it, never the only way to reach a control.

## Safety classes

| Class | Meaning |
|---|---|
| `Safe` | Fires once the pose is stable. Reversible or non-destructive. |
| `Guarded` | Requires a **second** confirmation: the pose must be held for `confirm_hold_ms` (default 1500 ms). A short flash emits `gesture://pending-confirm` and executes nothing. |

**Nothing fires while disarmed.** `OpenPalm` is the only pose the router
considers when disarmed, and only as an arming hold.

## Arming

| Input | Effect |
|---|---|
| `OpenPalm` held ≥ `arm_hold_ms` (600 ms) | Arm the session |
| `ClosedFist` | Dismiss an open overlay; if nothing is open, disarm |
| Arm / Disarm button (Camera panel) | Arm or disarm directly |
| `armed_timeout_ms` (10 000 ms) with no hand | Session expires; back to disarmed |

## Static poses (8)

| Gesture | Id | Action | Default | Notes |
|---|---|---|---|---|
| Open palm | `open_palm` | Arm | on | The only pose that works while disarmed. Hold to arm. |
| Closed fist | `closed_fist` | Dismiss overlay | on | Disarms when no overlay is open. |
| Stop (spread palm) | `stop_hand` | Stop dictation | on | **Guarded.** Spread fingers distinguish it from an open palm. |
| Point | `pointing_up` | — | on | Reserved: marks the referent for deictic grounding (M5). No action, so it can never fire one. |
| Pinch | `pinch` | — | off | Reserved: selects the pinned camera track. |
| Victory | `victory` | — | off | Reserved for dictation punctuation. Off because it is an easy accidental pose. |
| Thumb up | `thumb_up` | — | off | Confirmation only — never a standalone command. |
| Thumb down | `thumb_down` | — | off | Rejection only — never a standalone command. |

## Motion gestures (5)

| Gesture | Id | Action | Default | Notes |
|---|---|---|---|---|
| Swipe right | `swipe_right` | Next item | on | Lateral palm-centre travel above the speed floor. |
| Swipe left | `swipe_left` | Previous item | on | Mirror of the above. |
| Swipe up | `swipe_up` | Scroll up | on | Vertical travel. |
| Swipe down | `swipe_down` | Scroll down | on | Vertical travel. |
| Wave | `wave` | — | off | Reserved. Off because waving is the most common accidental motion. |

A single lateral move reports **one** swipe, not a swipe plus a wave:
`motion::tests::a_swipe_does_not_also_report_a_wave` pins that.

## Actions (10)

`ActionId` is closed by design. There is **no** variant for delete, purge,
send, purchase, or any outbound tool call, so those actions cannot be
expressed even by a bug.

| Action | Id | Safety | Keyboard equivalent | Default |
|---|---|---|---|---|
| Arm gestures | `arm` | Safe | Camera panel → Arm button | on |
| Disarm gestures | `disarm` | Safe | Camera panel → Disarm button | on |
| Dismiss overlay | `dismiss_overlay` | Safe | Esc / Close button | on |
| Scroll up | `scroll_up` | Safe | Page Up | on |
| Scroll down | `scroll_down` | Safe | Page Down | on |
| Next item | `next_item` | Safe | Down arrow | on |
| Previous item | `previous_item` | Safe | Up arrow | on |
| Stop dictation | `stop_dictation` | **Guarded** | Esc (cancel) / PTT release | on |
| Start/stop dictation | `toggle_dictation` | **Guarded** | Push-to-talk key | off |
| Toggle camera preview | `toggle_preview` | Safe | Settings → Camera → Preview | off |

The allowlist is enforced in exactly one place (`router.rs`). The default
allowlist is the set of actions marked *on* above.

## Tuning (Settings → Gestures)

| Setting | Default | Effect |
|---|---|---|
| `enabled` | `false` | Master switch. Never on by default. |
| `landmark_model` | `hand-landmark` | Catalogue id for the landmark model. |
| `model_path` | *(empty)* | Explicit local `.onnx`. When set it wins over the catalogue — the way to use gestures before M0 pins a verified download. |
| `input_size` | `224` | Square model input (192 and 224 are the common exports). |
| `mirror_preview` | `true` | Mirrors the frame before inference and flips handedness once, so the preview reads as a selfie. |
| `arm_hold_ms` | `600` | Open-palm hold that arms. |
| `min_confidence` | `0.75` | Enter threshold. |
| `exit_confidence` | `0.55` | Exit threshold — the hysteresis band that stops label flicker. |
| `debounce_agree` | `3` | Agreeing frames required. Fires on the **3rd** agreeing frame. |
| `debounce_window` | `4` | Sliding window for the agreement check. |
| `min_track_frames` | `3` | Frames a hand must be present before any gesture counts. |
| `cooldown_ms` | `500` | Per-action floor. |
| `armed_timeout_ms` | `10 000` | Disarm after this long without a hand. |
| `confirm_hold_ms` | `1500` | Hold that counts as the second confirmation for guarded actions. |
| `allowed_actions` | the default set | Empty means **nothing** is allowed. |
| `deictic_enabled` | `false` | "Ask your camera" (M5). The only gesture path that sends a crop to a model. |
| `deictic_provider` | `lm_studio` | `lm_studio` or `ollama` — the local multimodal endpoint. |
| `deictic_model` | *(empty)* | Model id; empty lets the server serve its single loaded model. |

## Asking about what you point at (M5)

"Ask your camera" is the one gesture path that involves a model. It is
**off by default** and it is display-only.

1. Start the camera, then **Hand gestures**.
2. Show a hand to the camera (a `pointing_up` pose is best — the crop is then
   centred on the index-finger tip rather than the hand).
3. Type a question in **Ask your camera** and press Enter.

The crop is:

| Property | Value |
|---|---|
| Region | `1.7×` the hand (non-point pose) or `2.4×` centred on the fingertip (point pose) |
| Long edge | ≤ 512 px |
| Destination | the configured local endpoint only (`lm_studio` / `ollama`) |
| Persistence | none — the PNG lives in memory for one request |
| On success | shown in the panel; **never** pasted into another app |
| On failure | a typed error, never a fabricated answer |

The prompt restates the bright lines (no faces, no identity, no guessing) and
the feature is refused when there is no hand in view rather than cropping a
random part of the frame.

The **spoken** leg is not wired yet: the app has no in-memory "last
transcript" to read, and adding one means changing the dictation state
machine. Until then the question is typed. Composing it with the existing
push-to-talk output is the next step.

## Arbitration rulebook (M6, not wired)

`gestures/arbiter.rs` implements the closed-vocabulary rules that would let a
local model pick between two ambiguous candidate poses. It **cannot** widen
the vocabulary:

- an action outside the allowlist becomes `none`;
- a guarded action is never promoted to safe (the confirmation hold stands);
- the deterministic guess is used only when it is allowed **and** safe;
- everything else is dropped and counted, not surfaced as an error.

The request type carries candidates, scores and coarse context — a test
asserts it cannot serialise pixels. The router does not call it yet because
the stabiliser does not publish an ambiguous band.

## Privacy

- Frames are classified on this machine. No frame is uploaded, logged, or
  written to disk by the gesture path.
- There is no facial recognition and no identity signal of any kind. This is
  a bright-line non-goal, repeated in the in-app copy.
- The overlay skeleton is drawn in the webview from 21 normalised
  coordinates; it is not an image.

## Reserving a new gesture

Adding a pose is a four-part edit, intentionally:

1. Add the variant to `GestureId` and `GestureId::as_str`.
2. Add a `GestureInfo` entry to `GESTURES` (label, description,
   `default_enabled`).
3. Extend `classifier::tests::the_eight_static_poses_classify_as_themselves`
   (or the motion fixture) so the pose is proven to be distinguishable from
   its nearest neighbours.
4. Map it to an existing `ActionId` — or to `None` while it is reserved.

Do **not** add a new `ActionId` unless the action is reversible or guarded.
The bar for a new action is "what does an accidental fire cost?"
