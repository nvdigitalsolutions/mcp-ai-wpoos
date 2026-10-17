# Proposal 071 — Video & Audio Input Extensions for the NV oOS Dictation Companion

**Status:** Draft for discussion
**Date:** 2026-10-13
**Related:** Proposal 067 (`067-tauri-dictation-companion-app-proposal.md`), `.agents/skills/mcp-ai-wpoos-dictation-app`, `examples/nvoos-dictate/src-tauri/src/util/decode.rs` (existing symphonia file-decode seam), `src/meetings/chunker.rs` (existing long-audio chunking)

## 1. Context

The dictation companion (`examples/nvoos-dictate`) is a local-first Tauri v2
app: push-to-talk dictation, meeting transcription, deterministic on-device
cleanup, and an optional AI polish backend (now including local LM Studio /
Ollama). Its **input pipeline** today is:

```
mic (16 kHz mono f32) ──▶ STT engine ──▶ Stage-1 cleanup ──▶ (Stage-2 polish) ──▶ paste / archive
file (symphonia decode → mono f32) ──▶ STT engine ──▶ …           ("Transcribe file…")
      ▲  audio-only: mp3 / m4a / wav / flac / ogg
```

The seam is already nearly video-capable: `util/decode.rs` uses **Symphonia**,
a pure-Rust demuxer/decoder that *already supports MKV, MP4/MOV, and WebM*
containers (in addition to the audio-only formats). The only reason the app
can't ingest video is that `decode_file` targets the *first audio track in an
audio container* and never demands demuxing out of a video container, and the
STT pipeline assumes ≤ ~5 minutes of audio rather than hour-long media.

This proposal describes, in the spirit of an **extended-cognition toolkit**,
the minimal set of extensions that turn the app from a *dictation* tool into a
*media-transcription* tool — video in, structured transcript + summary out —
without leaving the local-first, pure-Rust, no-FFmpeg architecture.

## 2. Goals

1. **Video ingestion** — accept MP4/MOV/MKV/WebM and transcribe the audio
   track locally, using the existing Symphonia demuxer (no FFmpeg sidecar).
2. **Long-media transcription** — handle hour-long inputs via the existing
   chunker, extended to carry cross-window context (whisper sliding-window
   semantics) and to bound memory.
3. **Timestamps + speaker-relevant structure** — emit a timecoded transcript
   (segment boundaries) so output is usable beyond raw text; diarization
   remains a non-goal (see §4).
4. **Unified media → artifact pipeline** — feed the transcript through the
   existing Stage-1/Stage-2 cleanup and (optionally) the summary/archive
   paths, so a video becomes a searchable, polishable artifact.
5. **Optional autonomous summarization** — an "Extended Cognition" mode that
   produces a structured summary (headline, key points, action items, timeline)
   using the configured polish backend (NV oOS or local LM Studio/Ollama),
   mirroring the existing meeting-summary pattern.

## 3. Non-goals (this proposal)

- **Speaker diarization** in v1 — no pyannote/NeMo; a follow-up could pair
  with a diarization model, but it adds a heavyweight runtime.
- **Video *frame* understanding** (OCR, scene detection, visual Q&A) — this
  proposal is audio-track extraction only; visual analysis is out of scope.
- **FFmpeg dependency** — the pure-Rust Symphonia path is retained; FFmpeg is
  explicitly rejected for the reasons in §5.
- **Real-time video** / screen-with-audio capture — meeting system-audio
  capture already exists; this is *file* ingestion.
- **Cloud STT for video** — local engines remain primary; the `embedded`
  remote-STT fallback stays opt-in and subject to its existing 10 MB / 30-min
  limits (which make it unsuitable for long video — called out in §6).

## 4. Industry research summary

### 4.1 Container/demux support (why Symphonia, not FFmpeg)

Symphonia's demuxers cover **MKV, ISO/MP4 (incl. MOV), and WebM** containers,
plus AAC/ALAC/FLAC/Vorbis/PCM codecs ([Symphonia repo](https://github.com/pdeljanov/Symphonia),
[docs.rs](https://docs.rs/symphonia/)). The app already depends on Symphonia
(0.5) for `decode_file`, so extending it to demux a video container's audio
track is an *incremental* change, not a new dependency. FFmpeg is the industry
fallback, but it is a ~100 MB native sidecar with its own licensing/security
surface; the app's stated design (ADR 0003, proposal 067) commits to pure-Rust
audio handling. The one real Symphonia gap vs FFmpeg is **AV1/HEVC video
codecs** — irrelevant here because we only need the *audio* track, whose codecs
(AAC/Opus/Vorbis) Symphonia already decodes.

### 4.2 Long-form transcription (whisper sliding windows)

Whisper splits long audio into **~30 s sliding windows** with previous-window
text/tokens carried as context, then stitches segments
([whisper discuss #1977](https://github.com/openai/whisper/discussions/1977),
[HF whisper-large-v3](https://huggingface.co/openai/whisper-large-v3)). Two
correctness gotchas the implementation must respect:

- **A window may yield 0 or N segments** — you cannot assume one transcription
  per window ([The Neural Base](https://theneuralbase.com/whisper/learn/advanced/chunk-based-streaming/)).
- **Context must be preserved across boundaries** or the transcript degrades at
  each cut ([Yoad Snapir long-form](https://medium.com/@yoad/whisper-long-form-transcription-1924c94a9b86)).

The app already chunks meetings for parakeet's ~4–5 min TDT limit
(`meetings/chunker.rs`); this proposal generalizes that into a media chunker
with context carry-over.

### 4.3 Video-to-summary UX standards

The emerging category (NotebookLM, Otter, Granola, Fireflies, and the macOS
Resonant CE the original proposal benchmarks against) converges on: (a) ingest
any media file, (b) produce a searchable timecoded transcript, (c) emit a
structured summary (headline / key points / action items / timeline), and (d)
keep transcription local by default with opt-in cloud enrichment. This maps
1:1 onto the app's existing meeting flow, so the proposal reuses that flow
rather than inventing a new one.

## 5. Design

### 5.1 Extend `decode_file` → `decode_media` (audio-track demux)

`util/decode.rs` currently: probe → select first audio track → decode to mono
f32. The extension is to treat video containers identically (they already
probe correctly) and additionally return metadata the current function drops:

```rust
pub struct DecodedMedia {
    pub samples: Vec<f32>,       // mono f32 PCM (already produced today)
    pub sample_rate: u32,
    pub duration_ms: i64,        // NEW: for chunking + progress
    pub container: MediaContainer, // NEW enum: Audio | Video(Mp4|Mkv|Webm)
    pub has_video_track: bool,   // NEW: distinguishes container from audio-only
}
```

- **No new dependency**: Symphonia's ISO/MP4, MKV, and WebM format readers are
  already enabled; the only change is selecting the *audio* track when a video
  track is also present (it already selects the first audio track).
- **Guardrails:** reject files with no audio track; cap sample count to a
  documented max (memory bound); stream-decode rather than slurp whole files
  where feasible (Symphonia's packet loop already streams).

### 5.2 Generalize `meetings/chunker.rs` → `media/chunker.rs`

Extract the existing meeting chunking into a reusable long-media chunker with:

- **Configurable window** (default 30 s to match whisper; parakeet keeps its
  ~4–5 min TDT limit as a larger window with sub-chunking).
- **Context carry-over**: feed the prior window's accepted text/tokens back as
  context for the next window (whisper semantics) — the key correctness fix.
- **0-or-N-segments tolerance**: accumulate returned segments, not one-per-window.

### 5.3 New input surface: `Transcribe video…`

Mirrors the existing `Transcribe file…` IPC/UI (same `transcribe_file` command
generalized, or a sibling `transcribe_media`), with:

- A **file picker** accepting video + audio extensions (mp4, mov, mkv, webm,
  plus the existing audio set).
- A **progress event** (`media://progress`) since long files no longer fit the
  pasted-text latency budget (unlike dictation, this is a batch operation).
- Output: timecoded transcript stored to local history + (optionally) archived
  to the NV oOS site / polished by the configured backend.

### 5.4 Extended-Cognition summary mode

Optional post-transcript step: when the user enables it, the full transcript is
summarized by the configured Stage-2 backend (NV oOS assistant **or** a local
LM Studio/Ollama model) into a structured artifact:

```json
{
  "headline": "…",
  "key_points": ["…"],
  "action_items": [{ "owner_note": "…", "text": "…" }],
  "timeline": [{ "ts_ms": 0, "note": "…" }]
}
```

This reuses the existing `CleanupProvider` seam (a `summarize` call added to
the provider trait or a dedicated summarization provider), so it inherits the
local/cloud choice, keychain auth, and non-blocking-error behavior already in
place. The summary is stored alongside the transcript.

### 5.5 Database / history

Reuse the existing `transcripts` / `meetings` tables; add a nullable `media`
column (container + duration + source path or `null` for mic/meeting). No new
schema migration beyond one column.

## 6. Risks & mitigations

| Risk | Mitigation |
|---|---|
| **Memory blow-up on long video** | Stream-decode + hard cap on retained samples; chunk and discard as each window transcribes. |
| **Whisper context degradation** | Carry prior-window text/tokens as context (§5.2); golden test asserting boundary continuity. |
| **Symphonia codec edge cases** (unusual video audio codecs) | Probe-and-fallback with a clear "unsupported codec" error; keep the `embedded` remote path explicitly off the default for video (its 10 MB / 30-min limits make it unsuitable — documented, not silently attempted). |
| **No audio track / corrupted container** | Explicit error surfaced; never a silent empty transcript. |
| **Scope creep into visual analysis** | §3 non-goal; frame/OCR/scene understanding deferred to a distinct proposal. |
| **Latency expectation mismatch** | Video is a *batch* path with progress UI, explicitly not the dictation latency budget. |

## 7. Dependencies

- **None new.** Symphonia 0.5 (already present) covers the demux/decode; the
  chunker, IPC, UI, DB, and summary paths are all existing modules.
- Optional: a summarization call on `CleanupProvider` (or a parallel
  `Summarizer` trait) — no new crates.

## 8. Milestones

1. **M1 — `decode_media`** — extend `decode_file` to return duration, container
   kind, and video-track flag; handle MP4/MOV/MKV/WebM; unit tests with small
   fixture files (or synthetic containers).
2. **M2 — chunker generalization** — extract `media/chunker.rs` with window +
   context carry-over; port meetings to it; golden tests for 0-or-N windows and
   boundary continuity.
3. **M3 — `Transcribe video…` IPC/UI** — generalize `transcribe_file` to accept
   video, add `media://progress`, wire the Settings/Home file picker and the
   local history row (`media` column).
4. **M4 — Extended-Cognition summary** — add `summarize` to the cleanup/
   summarizer seam (NV oOS + LM Studio + Ollama), produce the structured
   artifact, persist alongside the transcript, and surface it in the UI.
5. **M5 — verification** — extend the Rust gate matrix (new decode/chunker/
   summary tests stay green); document in the dictation-app skill.

## 9. Open questions

- Default chunk window: 30 s (whisper) vs per-engine override — confirm.
- Keep `transcribe_file` as one command (extension) or add `transcribe_media`
  (cleaner separation)? Leaning one command with a `media` flag to avoid a
  second capability surface.
- Summary schema: fixed JSON shape (§5.4) vs provider-driven free text?
- Whether `media` history rows should also be MCP-visible (the app already
  exposes a local MCP server — see `mcp/server.rs`).

---

*This proposal is intentionally scoped to audio-track extraction from video +
the long-media/summary path. Visual frame understanding, diarization, and
real-time video are separate future proposals.*
