# 127 — Cognition Memory Layer: Ask-Your-Capture Local Knowledge Retrieval (Proposal)

- **Date:** 2026-11-07
- **Status:** Implemented P0–P2 / M0–M4 (2026-11-07): schema + hybrid retrieval + controller + IPC + UI shipped behind the `memory` feature; encoder live-verified via `embed_probe` (7.4 ms/sentence, cross-lingual OK). P3 (reranker tier, admission-as-gate) and P4 (office pack, TTS read-back) remain deferred.
- **Related:** Proposal 067 (NV oOS Cognition app), 071 (media input), 100 (snippet OCR), 109 + 111 (document OCR + DeepSeek-OCR tier), 076 (local VLM transport); ADR 0003 (local-first engines), ADR 0005 (document engine). Research: [`127-cognition-memory-layer-research.md`](127-cognition-memory-layer-research.md), plan: [`127-cognition-memory-layer-implementation-plan.md`](127-cognition-memory-layer-implementation-plan.md).

## Context

[SurfSense](https://github.com/MODSetter/SurfSense) (16.8k stars, Apache-2.0)
is an air-gapped, local-first "NotebookLM alternative" desktop app: it ingests
documents and recordings, indexes them on disk, and answers questions with
citations through local or remote models — and can edit/transform documents
with the original never modified. Its engineering docs are unusually good:
every subsystem (`docs/architecture/*.md`) documents what is true now, the
ADRs say why, and its retrieval quality is **measured, not asserted**.

The NV oOS Cognition app is, in one respect, the mirror image of SurfSense:
it is an industry-grade *capture* machine (push-to-talk dictation, meeting
transcription, screen OCR, document OCR, media transcription — Proposals
067/071/100/109) with **no memory**. Every capture surface appends to its own
SQLite table (`transcripts`, `meetings`, `snippets`) with no shared index, no
search beyond the history list, and no retrieval. SurfSense is a *memory*
machine whose capture is comparatively shallow (recordings → STT, files →
parse). The two products converge on the same end state from opposite ends.

This proposal mines SurfSense's measured retrieval architecture and its
operational discipline (egress consent, runtime packs, admission tests) and
adapts them to the Cognition app's existing Rust/Tauri stack — ort rc.9,
rusqlite, local-first engines, probe-first verification.

## Problem

1. **Capture without retrieval.** Transcripts, meetings and snippets are
   append-only history rows. "What did I dictate about the WebSocket fix
   three weeks ago?" cannot be answered by the app; the text is there and
   decaying in value.
2. **No cross-surface view.** A topic discussed in a meeting, OCR'd from a
   slide, and dictated into an email is three silos with no unified search.
3. **Hybrid retrieval is a solved, measured problem** (SurfSense ADRs
   0006/0031–0033/0036, retrieval eval at 98% top-5 on 266 queries) and the
   Cognition app already ships every heavy dependency the pattern needs
   (ort rc.9 for ONNX, rusqlite for storage). The gap is architecture, not
   dependencies.

## Decision

**Add a local, opt-out "Memory" layer to the Cognition app: hybrid
FTS5 + vec0 retrieval over one indexed corpus built from all capture
surfaces, embedding with a bundled, hash-pinned multilingual ONNX
embedder running on the already-pinned ort rc.9 — with SurfSense's
blend, migration and admission-test discipline adopted wholesale.**

Explicitly rejected (see research notes for the full matrix):

- **Bundled llama.cpp / LLM server.** SurfSense ships a llama-server sidecar;
  the Cognition app's house rule stands: heavy LLM runtimes live in LM Studio
  / Ollama, reached through the Proposal 076 `LocalOpenAiProvider` transport.
  Embeddings are the exception — a ~60–100 MB encoder is in the same weight
  class as the STT engines (whisper ~142 MB) that the app already embeds, and
  embedding must run inline on the capture path, not behind a server the user
  may not run.
- **Remote embedding APIs.** Breaks local-first (ADR 0003) and adds a
  per-document egress decision for zero quality gain at this corpus size.
- **A separate vector database.** `sqlite-vec` loads into the app's existing
  rusqlite database; a standalone vector server (Qdrant etc.) buys nothing at
  personal-library scale and costs an install + lifecycle.
- **SurfSense's Office pack / Studio / agent tracks now.** Real, but
  out-of-scope candidates for later proposals (see P4 + "Lessons adopted
  regardless").

## Design (phases)

### P0 — Spike: embedding + vec0 + retrieval eval (probe-first)

- `examples/embed_probe.rs` (feature `memory`): load a candidate ONNX
  embedder through the pinned `ort` rc.9 (session APIs only — same discipline
  as `vision/detect.rs`), encode the fixture corpus, print dim, per-passage
  RTF, and cosine sanity (an English sentence vs its translation vs an
  unrelated sentence — the exact probe SurfSense used to expose bge's
  cross-lingual failure). Candidate set (all permissive licenses):
  - **`ibm-granite/granite-embedding-97m-multilingual-r2`** (Apache-2.0,
    384-dim, ~52 languages, int8 ONNX; the model SurfSense selected after a
    measured multilingual sweep — cross-lingual retrieval 88% at
    `semantic_weight` 0.85 vs bge-small's 25% at any weight),
  - **`BAAI/bge-m3`** (MIT, 1024-dim, 100+ languages; also emits sparse +
    ColBERT representations in one pass),
  - **`nomic-embed-text-v1.5`** (Apache-2.0, 768-dim) as the local-server
    parity check (Ollama/LM Studio serve it, so a server-backed embedder
    tier is a one-line fallback if the bundled path fails).
- Measure the **FP32 upcast** SurfSense documented: on x86-64, onnxruntime's
  CPU provider runs FP16 MatMuls through a slow kernel; their in-memory
  FLOAT16→FLOAT32 rewrite of the pinned graph is **6.2× faster** on a
  Ryzen 5800X (11.73 vs 1.88 passages/s) with cosine ≥ 0.99999958 between
  the two precisions. If the reference PC reproduces this, bake the upcast
  into the encoder (hash-gated, fall back to the file as shipped on refusal).
- `tests/memory/`: a miniature retrieval eval harness — a fixture corpus
  (existing golden transcripts + authored cross-lingual pairs), scoring
  "did the answering passage land in the top-5" — the *measured-not-asserted*
  contract from the start.

### P1 — Index: chunks, FTS5, vec0, embedder bookkeeping

- New `memory/` module (always compiled; feature-gated bodies per the
  `document` module discipline) + one appended migration (never insert
  mid-list — `storage/migrations.rs` rule):
  - `chunks(id, surface, surface_id, text, seq, created_at)` — dictation
    utterances, meeting segments (reuse `meetings/chunker.rs` boundaries),
    snippet text, document pages;
  - `chunks_fts` — FTS5 **external-content** table over `chunks` with a
    single shared tokenizer (SurfSense ADR 0032: one tokenizer for index and
    query, applied at both ends; CJK stays one token — accepted gap);
  - `chunk_vectors` — `sqlite-vec` vec0 table keyed by chunk id (the
    extension is pre-v1; pin the exact version, stage it like pdfium);
  - `embedding_indexes` — one row recording the model spec (id, sha256, dim,
    pooling, prefixes) that wrote the vectors (ADR 0036 pattern). **The
    index records its embedder** — a same-width swap is otherwise
    undetectable and silently searches a foreign vector space.
- Ingest is **incremental and resumable**: a `memory` worker thread embeds
  unindexed chunks in batches (like the `nvoos-vision-polish` / document
  worker pattern — own runtime, never on the capture hot path), FTS triggers
  keep the keyword side in step, provenance per chunk is preserved.
- Embedding is on by default (a Memory toggle in Settings turns it off and
  pauses the worker; history capture settings stay as they are).

### P2 — Ask surface: hybrid retrieve + cited answers

- `retrieve(query, top_k, surfaces)` — the SurfSense blend adapted to Rust:
  1. blank query short-circuits (nothing is embedded);
  2. keyword leg: tokenize with the index's tokenizer, quote against FTS5
     grammar, `OR`-joined for wide recall, top-20 by BM25, then score each
     candidate by the fraction of distinct query terms matched;
  3. vector leg: vec0 KNN over the surface scope (scope filter inside the
     vec0 CTE so other scopes can never crowd it out — the SurfSense
     gotcha);
  4. blend `w × semantic + (1 − w) × keyword` over the union, semantic =
     each candidate's own cosine floored at zero (ADR 0033), cut to `top_k`.
     The weight is **per-embedder, measured** (0.65 for bge-small / 0.85 for
     granite — retune in P3), never a free parameter.
- Home "Ask" panel → `ask_memory` IPC → retrieved chunks + an answer through
  the **existing** provider path (`CleanupProvider::summarize` family:
  `nvoos`, `lm_studio`, `ollama`, `openai_compat`) with citation markers;
  each cited chunk resolves to its source surface (transcript/meeting/
  snippet/document) and is click-to-paste. Failure-soft by contract: any
  provider error returns chunks-only, never a dead end (the Stage-2 rule).

### P3 — Quality tiers: weight retune, reranker, admission tests

- Retune the blend weight for the chosen embedder with the P0 eval (plateau
  sweep, SurfSense's six-point method — 0.85 beat 0.75 beat 0.65 for
  granite; 1.0 collapses keyword-only recall to 49%).
- Optional **reranker tier**: `bge-reranker-v2-m3` (MIT) or
  `jina-reranker-v2-base-multilingual` via ort, applied to the top-N blend
  candidates. SurfSense deliberately ships **no reranker** (deferred for
  lack of measurement) — the app adopts the same bar: the tier lands only
  with eval numbers behind it.
- **Admission tests** for any embedder or reranker added to the catalogue
  (SurfSense `verify.py`): (1) a probe embedding whose width is kept, and
  (2) a search check — ten questions over twenty passages, each answer
  beside a decoy, every answer must rank first. A failing model is rejected
  with a reason, not shipped.

### P4 — Deferred SurfSense candidates (future proposals, not this one)

- **Office-format ingest** (docx/pptx/xlsx/epub) — SurfSense's LibreOffice
  "runtime pack" pattern (opt-in, never in the installer, sha256-pinned,
  admin MSI unpack, hardened profile, neutralize-copy renders that strip
  external links/DDE/data connections, process-tree timeouts) is the
  security template the document pipeline (109) would need to extend past
  PDF/TIFF/images.
- **Read-back TTS** ("read my notes aloud" — the inverse of dictation):
  Kokoro-82M (Apache-2.0, 8 languages) is the industry-standard lightweight
  on-device voice; sherpa-onnx has a TTS pipeline if a Rust-native leg is
  wanted.
- **Edit-as-copy with tracked changes** (dictate revisions into a copy of a
  .docx): only after the Office pack; the never-modify-original contract
  below is the prerequisite.

## Lessons adopted regardless (cross-cutting standards from SurfSense)

These land as app-level discipline whether or not P0–P3 ship:

1. **Egress discipline.** Every non-loopback network call names a
   host-allowlisted destination, off by default, one consent question per
   user action, never a prompt on a background read; loopback (LM Studio,
   Ollama) is exempt by definition. The app's current posture (explicit
   per-provider config, no surprise fetches) is close; formalize it as a
   single `egress` guard checked in front of model downloads, provider
   calls, and updater traffic, with a Network settings list showing last
   call per host.
2. **Never modify originals.** Any future edit/transform feature writes a
   new file; the source is never opened for write. (SurfSense applies this
   to every edit and PDF operation.)
3. **Admission tests for every catalogued model** — embedders, rerankers,
   and (extending the existing engine-pairing guard) any new STT/OCR tier.
4. **Measured-not-asserted ranking.** Retrieval quality changes require the
   eval harness, and the harness's saturated slices are documented as blind
   spots (SurfSense: all four same-language slices sit at 100% and cannot
   catch a regression — the cross-lingual slice is the gate).
5. **Spellcheck-dictionary egress audit.** SurfSense measured that Chromium
   fetches Hunspell dictionaries from Google's CDN on session creation in
   Electron and neutered it. WebView2 may do the same — audit what the
   webview fetches at boot (proxy/DNS observation on the reference PC) and
   decide whether spellcheck stays worth the leak.
6. **Warm-what-you-will-need discipline.** Load the embedder lazily at
   first query (SurfSense: import is lazy, ~0.4 s first load), not at boot;
   never hold model bytes the session can rebuild from.
7. **Typed confirmation + append-only audit for destructive ops** (NiceDB's
   safety model, adopted post-ship in Proposal 128): `purge`/`reindex` are
   gated behind exact typed words (`PURGE` / `REINDEX`) enforced in the
   controller — the single gate point — and every completed destructive op
   writes an immutable `memory_ops` audit row shown on Diagnostics. See
   [`128-nicedb-safety-gates.md`](128-nicedb-safety-gates.md).

## Open questions / risks

- **Corpus size vs value.** A personal dictation corpus is thousands of
  short chunks — far below SurfSense's 100k-passage libraries. The hybrid
  blend may be overkill at this scale; the P0 eval must include a
  small-corpus slice and may justify keyword-first weights.
- **`sqlite-vec` is pre-v1.** Pin the exact release; the vec0 API has
  shifted between 0.1.x versions (SurfSense pins 0.1.9 behavior). Budget a
  version-pin discipline like the `ort` `=2.0.0-rc.9` pin.
- **Multilingual scope.** The chosen embedder covers ~12–52 languages, not
  all; the app's STT is English-first today, so a 12-language encoder may
  already outrun the capture side. Decide in P0 whether cross-lingual
  retrieval is a launch requirement or a P3 upgrade (the re-embed migration
  is the expensive half — choosing right first is cheap).
- **Privacy of an always-on index.** Embeddings are derived text on disk;
  a "purge memory" action must delete chunks + vectors atomically and be
  covered by a test.
- **Prompt-size budget.** Cited answers consume context; cap chunks per
  question (SurfSense default `top_k=5`) and surface the cap in Settings.

## Verification plan (reference PC)

1. P0 gates: `cargo fmt --check`, clippy `-D warnings` (+ `--features
   memory`), tests, `npm run build`; `embed_probe` against the fixture
   corpus with the upcast on/off (expect the 6× class of speedup), dim
   check, cross-lingual cosine sanity.
2. Retrieval eval: top-5 rate on the fixture corpus per candidate × weight
   — the numbers that decide the bundled model and `semantic_weight`.
3. Live ask-path: `ask_memory` against a seeded transcript corpus through
   (a) a local LM Studio model and (b) the `nvoos` provider; verify
   citations resolve to click-to-paste chunks.
4. Failure drills: embedder missing (clear Settings path), provider down
   (chunks-only fallback), corrupted vector row (skip + re-embed), foreign
   embedder width (admission refusal).

## Consequences

- One new feature flag (`memory`), one new crate (`sqlite-vec`, pinned), no
  change to the engine/cleanup/hotkey surfaces.
- New Settings block (Memory: enable, embedder, reranker, top_k) + a
  Diagnostics block (indexed chunks, vector table size, embedder spec, last
  embed ms) — same shapes as the document/vision diagnostics.
- The capture side gets a reason to exist beyond paste: every surface feeds
  one searchable, citeable corpus. SurfSense proves the retrieval half is
  ~60 MB of model and ~300 lines of measured ranking logic away; this
  proposal orders the rest of the work by risk, starting with the probe.
