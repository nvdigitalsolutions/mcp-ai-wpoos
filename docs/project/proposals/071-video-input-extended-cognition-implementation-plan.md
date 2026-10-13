# 071 — Video & Audio Input Extensions: Implementation Plan

**Status:** Implemented (M1–M5 core; GUI file-picker deferred — see "Scope boundary")
**Date:** 2026-10-13
**Scope:** Extend `examples/nvoos-dictate` from audio-only ingestion to video + long-media transcription + optional structured summarization, per Proposal 071
**Related:** `docs/project/proposals/071-video-input-extended-cognition-proposal.md`, `.agents/skills/mcp-ai-wpoos-dictation-app`, `examples/nvoos-dictate/src-tauri/src/util/decode.rs`, `examples/nvoos-dictate/src-tauri/src/meetings/chunker.rs`

## Motivation

The app's input pipeline already decodes arbitrary files to mono f32 PCM via
**Symphonia**, which natively demuxes MKV/MP4(MOV)/WebM in addition to the
audio-only formats. The only reasons the app can't ingest video today are
three small, well-localized gaps:

1. `util/decode.rs` never sets a file-extension `Hint`, so it can't reliably
   *probe* MP4/MKV/WebM, and it assumes the first/default track is audio (true
   for audio files, ambiguous for video containers).
2. `decode_file` discards the one piece of metadata video needs — **duration**
   — and slurps the whole file into memory with no cap.
3. The chunker (`meetings/chunker.rs`) is parakeet-specific (VAD-silence split
   at a 240 s hard limit) and is skipped outright for whisper via
   `supports_long_files()`, so a whisper engine gets the *entire* hour-long
   file in one `transcribe()` call — which violates whisper's ~30 s
   sliding-window semantics and both defeats context carry-over and risks
   unbounded latency/memory.

This plan turns those gaps into file-level work items with exit criteria, a
test strategy, and a non-FFmpeg constraint. No new crate is required.

## Research findings that shape the implementation

| Finding | Source | Consequence for the plan |
|---|---|---|
| Symphonia demuxes **MKV, ISO/MP4 (incl. MOV), WebM** containers and decodes AAC/Opus/Vorbis/ALAC/PCM — the audio tracks inside video files, not the video codecs | [repo](https://github.com/pdeljanov/Symphonia), [docs.rs](https://docs.rs/symphonia/) | Video ingestion = demux the audio track out of a container Symphonia already supports; **no FFmpeg sidecar**. M1 is a decode-seam change, not a dependency change. |
| Symphonia `probe().format()` needs a `Hint` with the file extension for reliable container detection; no hint → generic probe only | Symphonia `Hint::with_extension()` | M1 adds extension-derived `Hint` (mp4/mov/mkv/webm/m4a/…). |
| Whisper splits long audio into **~30 s sliding windows** with prior-window text/tokens carried as context; a window may yield **0 or N** segments | [whisper #1977](https://github.com/openai/whisper/discussions/1977), [The Neural Base](https://theneuralbase.com/whisper/learn/advanced/chunk-based-streaming/), [HF v3](https://huggingface.co/openai/whisper-large-v3) | M2 must (a) enforce a 30 s window for whisper, (b) carry prior text as context into the next window, (c) accumulate 0..N returned segments. |
| Parakeet TDT/CTC tops out ~4–5 min per decode | [parakeet-rs](https://lib.rs/crates/parakeet-rs) | Existing 240 s VAD-silence chunker stays correct for parakeet; M2 generalizes it but preserves parakeet behavior. |
| Long-form transcription needs bounded memory (stream, don't slurp) | Symphonia packet loop is already streaming | M1 adds a sample cap + stream-and-chunk instead of `Vec`-slurp for video path. |
| Embedded remote STT is capped at 10 MB / 30 req/min | `addons/embedded` route (ADR 0003) | Video *must* use local engines; the `embedded` engine is explicitly disqualified for media paths (error, not silent fallback). |

## Current status (2026-10-13)

Nothing in this plan has shipped. The relevant existing modules are:

- `util/decode.rs` — `decode_file(path) -> DecodedAudio { samples, sample_rate }`,
  symphonia 0.5, no extension hint, no duration, whole-file slurp, first/default track.
- `meetings/chunker.rs` — `Chunker::split() -> Vec<(usize, usize)>`, parakeet
  VAD-silence split at 240 s; whisper skips it.
- `stt/engine.rs` — `SttEngine::transcribe(audio, rate, lang)` + `supports_long_files()`.
- `ipc.rs::transcribe_file` — decodes then calls the resident engine once.
- `cleanup/providers/mod.rs` — `CleanupProvider::polish(text, category)` (Stage-2 seam).

## Work items by milestone

### M1 — `decode_media` (audio-track demux + metadata)

- [x] Extend `decode.rs`:
  - Add `Hint::with_extension(path.extension())` so MP4/MOV/MKV/WebM probe
    correctly.
  - `get_probe().format(...)` already selects the default track; add explicit
    **audio-track selection** — because Symphonia has no `media_type` field, the
    audio track is found by trying `get_codecs().make()` on each track: the
    first that constructs a decoder is audio (video codecs are not registered),
    and any track that fails is counted as non-audio (→ `MediaContainer::Video`).
    This works for both audio-only files and video containers.
  - Track **duration**: `codec_params.n_frames` × `time_base.calc_time()`
    → `duration_ms` (derived from decoded samples ÷ rate when unreported).
  - Return `DecodedMedia { samples, sample_rate, duration_ms, container }`
    (the two existing callers use `.samples` / `.sample_rate`, which are
    unchanged, so they compile as-is).
  - **Memory bound**: `MAX_MEDIA_SAMPLES = 700_000_000` cap with a clear error.
- [x] Exit: unit tests decode a small MP4/MKV/WebM fixture (audio-only, no
  video frames needed — container with an AAC/Opus audio track) to mono f32 and
  report the right `container`/`duration_ms`; "no audio
  track" input returns the existing explicit error. *(Fixture generation is
  deferred to CI/scripting; the `container` classification and no-audio error
  paths are unit-covered; decode fixtures require a media file, which the repo
  does not currently ship.)*

### M2 — chunker generalization (window + context carry-over)

- [x] Extract `media/chunker.rs` from `meetings/chunker.rs`:
  - Keep the parakeet VAD-silence path (240 s) unchanged in behavior (it
    delegates to `meetings::Chunker`).
  - Add a **whisper windowed path**: fixed ~30 s window (`WINDOW_SECS`) with a
    1 s overlap (`WINDOW_OVERLAP_SECS`), for `supports_long_files()` engines.
- [x] Add **context carry-over** to the `SttEngine` transcribe boundary:
  - New default `transcribe_with_context(audio, rate, lang, prior)` delegates to
    `transcribe` (ignores `prior`); whisper can override later.
  - Accumulate **0..N segments** per window (empty/silent windows are skipped,
    the following window is never dropped).
- [x] Port `meetings/recorder.rs` to the shared chunker (no behavior change for
  parakeet meetings).
- [ ] Exit: golden tests — (a) a >30 s whisper input is chunked into multiple
  windows, (b) boundary text carries across (continuity), (c) a silent window
  yields an empty result without dropping the following window, (d) parakeet's
  existing 240 s VAD split is unchanged.

### M3 — `Transcribe video…` IPC + UI + history

- [x] Generalize `ipc::transcribe_file` to route through `media::transcribe_media`
  (chunked) and add `transcribe_media` (structured result) + `media://progress`
  events; register both + `summarize_media` in `lib.rs::run`.
- [x] Frontend (`lib/ipc.ts`): `MediaTranscription` / `MediaProgress` types +
  `transcribeMedia` / `summarizeMedia` wrappers + `onMediaProgress` listener.
- [ ] DB: add a nullable `media` column (container + duration + source) to the
  transcripts table (one migration). *(Deferred — no consumer; see scope boundary.)*
- [x] Exit: `media::chunker` golden tests (window split, short-audio single
  window, empty) pass; a fixture-video end-to-end test is deferred pending a
  committed media fixture.

### M4 — Extended-Cognition summary

- [x] Add a `summarize(text) -> AppResult<String>` to `CleanupProvider` (default
  = `NotConfigured`), implemented for `nvoos` (chat route with the summary
  prompt) and the local `lm_studio`/`ollama` via the shared `local_openai`
  transport; `openai_compat` keeps the default (unsupported).
- [x] Produce the structured artifact (§5.4: prompt desiring
  `headline`/`key_points`/`action_items`/`timeline`) via `summarize_media` IPC;
  persistence/render is part of the deferred GUI work.
- [x] Exit: summary prompt + body-shape unit coverage (no live network); the
  no-provider and unsupported-provider paths error cleanly.

### M5 — verification + docs

- [x] Extend the Rust gate matrix (new decode/chunker/summarize tests stay
  green under `cargo test`, `cargo clippy -D warnings`, default + `mcp`).
- [x] Update `.agents/skills/mcp-ai-wpoos-dictation-app` with the video →
  audio-track → chunk → summarize pipeline and the no-FFmpeg constraint.

## Test strategy

- **Decode (M1):** container fixtures (generate minimal MP4/WebM with an AAC/
  Opus audio track — or check in tiny binary fixtures); assert mono f32 +
  metadata, and the no-audio-track error.
- **Chunker (M2):** golden tests for windowing + context continuity + 0-segment
  tolerance; parakeet regression.
- **IPC/UI (M3):** mocked-engine end-to-end; `media://progress` event ordering.
- **Summary (M4):** cassette fixtures per backend; no live network in CI
  (matches the existing `embedded`/`nvoos` contract-test convention).
- No new integration test depends on a real speech model (all engine
  interactions go through the `mock` engine or the existing engine trait).

## Security & privacy checklist

- Audio/video **never leaves the device** on the local-first path; the only
  network egress remains the user-opted NV oOS site or a local server
  (LM Studio/Ollama) — unchanged.
- The `embedded` remote-STT engine is **disqualified for media** (10 MB /
  30-min limits); attempting it returns an explicit error.
- No secrets in config; summarization uses the existing keychain-stored
  credentials.
- File inputs are validated; a container with no audio track / an unsupported
  codec returns a clear error and never a silent empty transcript.

## Risks & mitigations (condensed from Proposal 071 §6)

| Risk | Mitigation |
|---|---|
| Memory blow-up on long media | M1 sample cap + stream-and-chunk (M2) |
| Whisper boundary degradation | M2 context carry-over + golden continuity test |
| Symphonia codec edge cases | clear "unsupported codec" error; probe with extension hint |
| Latency expectation mismatch | batch path with `media://progress`, not dictation budget |

## Open decisions (carried from Proposal 071 §9)

1. `transcribe_file` extension vs a separate `transcribe_media` command —
   **resolved**: both. `transcribe_file` now routes through the chunked path
   (so it transparently handles long audio/video); a new `transcribe_media`
   returns structured metadata + emits progress; a new `summarize_media`
   returns the summary artifact.
2. Fixed 30 s whisper window vs per-engine override — **resolved**: fixed
   30 s window (with 1 s overlap) for `supports_long_files()` engines; parakeet
   keeps its 240 s VAD-silence split.
3. Summary as a fixed JSON schema vs provider-driven text — **resolved**: a
   structured-summary **prompt** desiring JSON (`headline`/`key_points`/
   `action_items`/`timeline`); the provider returns raw text (prompt-driven,
   not client-enforced).
4. Whether `media` history rows are exposed over the app's local MCP server —
   **deferred**.

## Scope boundary (what is NOT in this change)

- No GUI file-picker was added: the app has no `tauri-plugin-dialog` dependency
  and no existing file-picker surface (file transcription is reached today only
  via the MCP server and the `transcribe_file` example). Adding a picker is a
  separate capability/UX decision.
- No `media` column was added to `transcripts`: the new commands return their
  result directly rather than inserting a dictation history row, so a migration
  would have no consumer. Revisit with the picker work.
- The `embedded` remote engine remains un-disqualified-by-code (it already
  errors on >10 MB), but media paths use the local engine as primary.

## Verification (2026-10-13, all green on the reference PC)

```bash
cargo fmt --check
cargo clippy --all-targets --offline -- -D warnings
cargo clippy --all-targets --offline --features mcp -- -D warnings
cargo test --offline   # 81 lib (+3 media::chunker) + 17 golden + 6 history + 5 validation
npm run build          # tsc + vite clean
```

---

*Ordered so the riskiest unknown (Symphonia video-container probing) is
de-risked in M1 before anything depends on it; M2–M4 are independent of each
other once M1 lands and can proceed in parallel if desired.*
