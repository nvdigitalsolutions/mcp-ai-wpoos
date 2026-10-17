# 076 — Vision Label Polish: VLM relabeling of YOLO detections

**Status:** Implemented (M1 core — VLM label polish)
**Date:** 2026-10-13
**Related:** Proposal 075 (local object detection/tracking), LM Studio/Ollama Stage-2 providers, `examples/nvoos-dictate`

## Problem

Proposal 075's detector (`yolov11n`) is a **closed-vocabulary** 80-class
COCO model. Whatever it cannot recognize it confidently shoehorns into the
nearest COCO class — a pen becomes "knife", a notebook "book", a mug "cup".
For a live webcam of an arbitrary scene, most labels are wrong.

## Industry-standard fix

YOLO-World (2024), and the subsequent VLM-for-grounding literature (Qwen2.5-VL,
Gemma 3), establish the same architecture: a **fast detector finds boxes**, a
**vision-language model (VLM) relabels/describes** each box in natural
language. The detector gives us cheap, local, ~15 fps boxes with stable track
ids; the VLM gives accurate open-vocabulary names.

## Design (opt-in vision-polish layer)

- YOLO keeps finding + tracking boxes (unchanged, zero-egress default).
- When **vision polish** is enabled, the controller crops each tracked box,
  base64-encodes it, and asks the configured local VLM (LM Studio / Ollama) to
  name it, via the **existing** OpenAI-compatible multimodal `image_url` shape.
- The returned `{ label, confidence }` replaces the COCO label on the overlay
  (badged `VLM` vs raw COCO).
- Reuses the `LocalOpenAiProvider` transport (auth, local-http vs remote-https
  invariant, cold-load budget, error mapping) — no new HTTP stack.

## Key decisions

1. **VLM default = `qwen2.5-vl-7b`-class** — the reference model for structured
   JSON visual grounding; runs in 8 GB VRAM (reference PC is RTX 3070 Laptop).
   Free-text base URL + model override in Settings (populated from `/v1/models`).
2. **Payload = base64 data-URI PNG** (most portable across LM Studio/Ollama).
3. **Throttled, non-blocking** — the VLM is hundreds of ms per crop; polishing
   runs at a small per-second budget off the 15 fps detect loop, never stalling
   it. Detections keep their YOLO label until the VLM answer lands.
4. **No facial recognition / identity** — the prompt names *objects/assets*
   only; the bright line from 075 §2 is preserved verbatim.
5. Provider tokens: LM Studio/Ollama keys stay in the OS keychain (unchanged
   secret discipline); the local default path needs no token.

## Non-goals (deferred)

- Embedding-mode corpus matching (OpenCLIP/local CLIP).
- Person identity / biometrics.
- Cloud vision providers (the layer is local-first, mirroring Stage-2 polish).

## Acceptance

- `vision/polish.rs` parses `{label, confidence}` (hardened JSON extraction).
- Settings → Vision gains polish enable/provider/model/throttle controls.
- Diagnostics → Object detection gains polish counters + a Test-connection
  button.
- Unit tests: multimodal request shape, label-parse, crop→base64.
- Gates green: `cargo fmt`, clippy (default + `mcp` + `camera` + `vision`),
  `cargo test`, `npm run build`; manual smoke against live Qwen2.5-VL.
