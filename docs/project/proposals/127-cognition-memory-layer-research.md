# 127 — Research: SurfSense Lessons + Industry Standards for a Local Memory Layer

Companion research to [`127-cognition-memory-layer-proposal.md`](127-cognition-memory-layer-proposal.md).
All facts below were verified against the cited sources on 2026-11-07.

## 1. What SurfSense is (verified)

- Repo: <https://github.com/MODSetter/SurfSense> — 16.8k stars, 1.6k forks,
  Apache-2.0. "Air-gapped, open-source NotebookLM alternative. AI agent that
  researches, transforms and edits your documents, on your machine."
- Stack: Electron desktop (`surfsense_local/`) + a Python backend (FastAPI,
  SQLite, onnxruntime, llama.cpp sidecar) + a Next.js marketing/update site
  (`www/`). One process tree per install; no account; local or remote models.
- Format matrix: PDF (incl. scanned, OCR reads Latin/Chinese/Japanese), DOCX
  (incl. tracked-changes edits), XLSX (reads formulas), PPTX, CSV, images,
  Markdown/HTML, EPUB/MOBI/AZW3, and recordings (WAV/MP3/FLAC/OGG
  transcribed on-machine). **Every edit/transform writes a new file; the
  original is never changed.**
- Plugins are MCP servers behind one tool gateway (ADR 0051), off by default;
  third-party registry signed; "any server by URL".
- Their docs discipline is the deliverable itself: `docs/architecture/` =
  what is true now + Known gaps; `docs/adr/` = why; proposals = not built
  yet; task state lives in GitHub issues.

## 2. SurfSense retrieval stack — the transferable core

Sources: `docs/architecture/search.md`, `embedding.md`, `docs/adr/0006`
(hybrid retrieval), `0007` (bundled embeddings), `0031` (blend ranks
absolute leg scores), `0032` (one tokenizer), `0033` (each candidate scores
its own cosine), `0036` (index records its embedder), `0058` (FP32 upcast
on x86-64), and `docs/proposals/retrieval.md` (multilingual embedder).

### 2.1 Blend

- Two legs: **FTS5 keyword** (terms tokenized by the index's own tokenizer,
  each quoted against FTS5 grammar, `OR`-joined, top-20 BM25, then scored
  by *fraction of distinct query terms matched*) and **vector** (sqlite-vec
  vec0 KNN, top-20, scope filter inside the vec0 CTE so other scopes can't
  crowd it out).
- Rank = `w × semantic + (1 − w) × keyword`; semantic strength is each
  candidate's **own** cosine (floored at zero), computed over the union.
  `w` = `semantic_weight`, measured per embedder (0.65 for bge-small).
- Asymmetry on purpose: a chunk the keyword leg omits matched no terms
  (zero is a measurement); a chunk the vector leg omits merely sat outside
  its nearest 20 (no information).
- Blank query returns nothing before embedding. No stopword list needed.
- Eval harness (`scripts/run_retrieval_eval.py`): 266 queries over 17
  authored docs + LIMIT-small; 98% top-5 blended. **Ranking is measured,
  not asserted.** Known blind spot: same-language slices are saturated at
  100% and cannot catch a regression — the cross-lingual slice is the gate.

### 2.2 Embedding model

- Bundled default: `Qdrant/bge-small-en-v1.5` ONNX (FP16 file, 66.5 MB),
  **pinned by sha256 — the pinned file defines the vector space**; a
  re-embed is the only path to change it. Chosen once at onboarding
  (skippable = bge-small), no in-app change.
- `embedding_indexes` row records the model spec that wrote the vectors;
  every document records its index. Without this, a same-width swap is
  undetectable.
- **FP32 upcast (the headline number):** onnxruntime's CPU provider runs
  FP16 MatMuls through a slow kernel on x86-64; an in-memory
  FLOAT16→FLOAT32 graph rewrite (protobuf, no onnx package) is **6.2×
  faster** on a Ryzen 5800X under Windows (11.73 vs 1.88 passages/s),
  cosine ≥ 0.99999958 between precisions, identical eval ranks on all 266
  queries. Costs ~52 MB more resident, +0.22 s first load. macOS stays FP16
  (not measured there). `SURFSENSE_LOCAL_EMBED_PRECISION=auto` on x86
  builds.
- **Admission tests for any embedder** (`verify.py`): (1) probe embed —
  width kept over config; (2) search check — 10 questions / 20 passages
  with decoys, every answer must rank first. Chat models used as embedders
  scored 5–8/10; bge-small and both granite models 10/10.
- HF repo ingestion: refuses gated repos, scan-flagged files, missing ONNX
  build/tokenizer, missing checksums. Prefers generic int8 builds; spec
  from sentence-transformers config (pooling, prefixes, max length, capped
  2048).

### 2.3 Multilingual gap + candidate (their proposal, status: proposed)

- Measured: bge-small is English-only — cross-language questions find their
  answer 1/8 by meaning, 2/8 blended; bge scores Hindi *backwards*
  (unrelated Hindi sentence cosines closer than its own translation).
- Candidate: `ibm-granite/granite-embedding-97m-multilingual-r2` —
  Apache-2.0, ungated, 52 languages, **384-dim (same width as bge-small → no
  schema change)**, int8 ONNX, CLS pooling, no query prefix.
- Weight sweep (per-embedder plateau, never inherited): granite cross-lingual
  top-5 = 38% at 0.65 → 88% at **0.85** → 49% at 1.0 (meaning-only 100% at
  1.0 but keyword collapse). bge best = 25% at any weight.
- Rejected in their eval: EmbeddingGemma-300M (gated weights = distribution
  problem; 768-dim native forces schema change), multilingual-e5-small
  (MIT, lower quality), Qwen3-Embedding-0.6B (Apache-2.0, ~4× slower).
- Migration = **re-embed, not re-ingest** (chunks/text kept): dual vector
  tables, background + resumable, search serves the old model until the new
  table is complete, one transaction switches; new uploads embed into both.
  ~8 min / 1,000 passages on a mid CPU.

## 3. SurfSense operational discipline — lessons, not code

### 3.1 Egress consent (`docs/architecture/egress.md`, ADRs 0017/0070)

- **A destination is a host, off by default.** `egress.require()` refuses
  with `403 egress_disabled` *before* any connection opens. Loopback is not
  egress (LM Studio/Ollama local presets need no consent; LAN servers do).
- One consent question per user action naming every host that action may
  reach (ADR 0060); never a prompt on a background read (refused GET =
  error). Settings › Network lists every destination + last call.
- Call sites enumerated per process — the check runs in whichever process
  makes the call (in-process downloads exist *because* a sidecar can't be
  gated).
- Audited leaks: Chromium **spellcheck fetches Hunspell dictionaries from
  Google's CDN** on session creation (Electron; they empty the spellchecker
  list — no spellcheck on Win/Linux, macOS uses the OS one); app updates
  and opt-in telemetry are gated separately.

### 3.2 Office pack (`docs/architecture/office-pack.md`)

- LibreOffice as an **opt-in runtime pack**: never in the installer,
  TDF's own build pinned by sha256, downloaded only after the user allows
  the host; or "use the LibreOffice I have" (fixed-path detection with
  supported-branch checks).
- Windows: MSI unpacked as an **administrative image** (`msiexec /a`,
  no elevation, no registration, 36 s, 1.2 GB).
- Runs on **neutralized copies**: every external relationship →
  `about:invalid`; INCLUDETEXT/INCLUDEPICTURE/LINK/DDE/DDEAUTO lose their
  instructions; data connections lose URLs. Profile hardened: macros
  disabled, update checks off three ways, proxy `127.0.0.1:9`.
- Process-tree kill with timeouts (90 s cap); success judged by output
  signature (`%PDF-` / zip header), never the exit code.

### 3.3 llama.cpp runtime (`docs/architecture/local-models/runtime.md`)

- Pinned build (b11050) + per-target sha256, never a floating tag (~10
  builds/day upstream). Router mode, `--models-max 1`, **no layer count —
  `-ngl 99` loads silently on CPU with exit 0** (the easiest silent
  failure); preset INI written atomically; models warmed at install /
  selection / startup; prompt-progress streamed from `return_progress`.
- "Assume nothing worked until something says it did" — llama.cpp's common
  failures exit 0 and look like success. Everything is re-verified by
  measurement, and each silent-CPU path is held by a test.
- Rationale for dropping Ollama as *their* runtime: 1.4% of their model
  rows were installable via Ollama's library; GGUF covers 204k HF repos;
  11–31 MB sidecar vs 501 MB staged payload. (The Cognition app keeps the
  opposite rule for LLMs — LM Studio/Ollama transport, Proposal 076 — and
  only the rationale for *embeddings* transfers; see the proposal.)

### 3.4 Others worth stealing

- **Model capabilities gating** (`model-capabilities.md`): measured or
  assumed model classes; below-bar models run fixed workflows with
  structured output. Mirrors the app's engine-pairing guard philosophy.
- **Onboarding locks the embedder** (`selection.md`): one irreversible
  choice at setup, skippable to the bundled default.
- **Localization** (`localization.md`): 10 languages; backend text as codes
  so frontends word errors in their own language.
- **Usage stats** (ADR 0070): opt-in, id-less, range/flag values only.

## 4. Industry standards verified this session

### 4.1 Rust embedding/reranking

- **`fastembed-rs`** (`Anush008/fastembed-rs`, 1.1k stars, Apache-2.0,
  crate `fastembed` v7): Rust port of Qdrant's fastembed; ort (`@pykeio/ort`)
  + HF tokenizers; synchronous, no tokio. Model list includes
  `BAAI/bge-m3` (dense+sparse+ColBERT in one pass), bge-small/base/large,
  `intfloat/multilingual-e5-*`, `nomic-embed-text-v1.5`,
  `Alibaba-NLP/gte-*`, `snowflake-arctic-embed-*`, `jina-embeddings-v2-*`;
  **rerankers**: `BAAI/bge-reranker-base`, `bge-reranker-v2-m3`,
  `jina-reranker-v1-turbo-en`, `jina-reranker-v2-base-multilingual`.
  DirectML feature for Windows GPU; model cache with `FASTEMBED_CACHE_DIR`.
  *Caveat:* pulls its own ort (pykeio fork) — the Cognition app pins
  `ort = "=2.0.0-rc.9"`; prefer calling the pinned ort directly (session
  APIs only) over adopting a second ort tree, or vendor the model-config
  table only.
- **`sqlite-vec`** (`asg017/sqlite-vec`, 8.2k stars, MIT/Apache-2.0,
  pre-v1): pure-C `vec0` virtual tables, float/int8/binary vectors, runs
  anywhere SQLite runs; official Rust bindings (`cargo add sqlite-vec`).
  SurfSense pins 0.1.9 semantics (scope filter applied before `k`).
  Siblings `sqlite-lembed`/`sqlite-rembed` (local GGUF / remote API
  embedding inside SQL) are toy-grade — not production choices.
- Python-side reference: `fastembed` (Qdrant) adds SPLADE sparse,
  ColBERT late interaction, and **ColPali** (multimodal late-interaction,
  `Qdrant/colpali-v1.3-fp16`) — visual-document retrieval. ColPali is the
  current SOTA pattern for "find the page in a PDF that answers this" and
  is a candidate future tier on top of the 109/111 document pipeline
  (heavy; not in this proposal).

### 4.2 Embedding model landscape (licenses matter — proprietary app)

| Model | License | Dim | Languages | Notes |
|---|---|---|---|---|
| `granite-embedding-97m-multilingual-r2` | Apache-2.0 | 384 | ~52 | SurfSense-evaluated; int8 ONNX; CLS pooling |
| `granite-embedding-107m-multilingual` | Apache-2.0 | 384 | 12 | HF-verified (43.6k DL/month); trained without MS-MARCO (commercial-safe); 512-token context |
| `BAAI/bge-m3` | MIT | 1024 | 100+ | dense+sparse+ColBERT; heavier; 8k context |
| `nomic-embed-text-v1.5` | Apache-2.0 | 768 | 100+ | Ollama/LM Studio default; server-backed fallback tier |
| `Qwen3-Embedding-0.6B` | Apache-2.0 | 1024 | 100+ | ~4× slower per SurfSense; overkill for local first tier |
| `multilingual-e5-small` | MIT | 384 | 100+ | lowest quality of the small set |
| `EmbeddingGemma-300M` | Gemma Terms | 768 | 100+ | gated weights — distribution problem (rejected by SurfSense) |

### 4.3 On-device TTS (for the P4 read-back idea)

- **Kokoro-82M** (`hexgrad/kokoro`, 9.9k stars, **Apache-2.0 weights**):
  82M-param TTS, 24 kHz, 8 languages (en×2, es, fr, hi, it, ja, pt-br, zh),
  espeak-ng G2P (Windows MSI available). The current quality/size/license
  standard for local voice; this is what "podcasts voiced on your machine"
  (SurfSense Studio) and most 2025–26 local assistants build on.
  Rust options: run via sherpa-onnx's TTS pipeline or a small ONNX session
  (Kokoro exports exist) — spike territory, not this proposal.
- **Piper** (`rhasspy/piper`, MIT) remains the fallback for tiny/embedded
  TTS (lower quality, more voices, very cheap).

### 4.4 Office-format parsing (for the P4 ingest idea)

- **Docling** (IBM, MIT): the reference docx/pptx/xlsx→Markdown/JSON
  parser; SurfSense bundles a parser pack and runs it with
  `HF_HUB_OFFLINE=1` + all outbound connections refused (tested). Python
  sidecar — an option only if the app accepts a Python pack, or as the
  spec to compare a Rust-native parser against.
- **LibreOffice runtime-pack** (see §3.2) is the conversion/render layer
  (PDF output, formula recalc); Docling-style parsing covers text
  extraction. The two compose.

## 5. Mapping SurfSense → NV oOS Cognition

| SurfSense subsystem | Transferable? | Landing spot |
|---|---|---|
| Hybrid FTS5+vec0 blend, per-model weight, own-cosine scoring | **Yes, wholesale** | `memory/` module, P1–P2 |
| Bundled hash-pinned embedder + index-records-embedder | **Yes** | `memory/models.rs` (mirror `stt/model_registry.rs` discipline) |
| FP32 upcast on x86 | **Yes (measure first)** | encoder; P0 probe decides |
| Multilingual embedder choice (granite 97m-r2) | **Yes** | default candidate; bge-m3 as alternative |
| Re-embed migration (dual table, resumable, transactional switch) | **Yes** | P3, when the model changes |
| Admission tests (probe + 10q/20p search check) | **Yes** | `tests/memory/` + install-time check |
| Retrieval eval harness | **Yes** | P0 |
| Reranker tier | **Later, measured** | P3 |
| ColPali visual retrieval | **Future** | on 109/111 document pages |
| Egress host-consent model | **Yes, app-wide** | "Lessons adopted regardless" #1 |
| Never-modify-originals | **Yes** | future edit features |
| LibreOffice runtime pack | **Later (P4)** | document pipeline extension |
| llama.cpp bundled runtime | **No** (house rule) | LLMs stay on LM Studio/Ollama (076) |
| Plugin registry / scrapers / license portal | **No** | out of scope |
| Docker self-host | **No** | out of scope (desktop app) |
| Kokoro TTS read-back | **Later (P4)** | sherpa-onnx TTS spike |
| Spellcheck-dictionary egress audit | **Yes, now** | webview boot-fetch audit |

## 6. Sources

- SurfSense README / AGENTS.md / ROADMAP: <https://github.com/MODSetter/SurfSense>
- Architecture docs (search, embedding, egress, office-pack, local-models/runtime, model-capabilities, localization, usage-statistics): `docs/architecture/` in-repo
- ADRs 0006/0007/0011/0012/0017/0031–0037/0051/0058/0060/0070: `docs/adr/` in-repo
- Retrieval proposal (multilingual embedder): `docs/proposals/retrieval.md` in-repo
- fastembed-rs: <https://github.com/Anush008/fastembed-rs>
- fastembed (Python, Qdrant): <https://github.com/qdrant/fastembed>
- sqlite-vec: <https://github.com/asg017/sqlite-vec>
- Granite-Embedding-107m-multilingual (HF, Apache-2.0, 384-dim, 12 langs, no MS-MARCO in training): <https://huggingface.co/ibm-granite/granite-embedding-107m-multilingual>
- Kokoro-82M (Apache-2.0 TTS): <https://github.com/hexgrad/kokoro>
- Piper TTS (MIT): <https://github.com/rhasspy/piper>
- Docling (MIT): <https://github.com/docling-project/docling>
- Cognition app ground truth: `examples/nvoos-dictate/src-tauri/src/storage/migrations.rs` (transcripts/meetings/snippets/kv/dictionary — no FTS, no vectors), `.agents/skills/mcp-ai-wpoos-dictation-app/SKILL.md`
