# 127 — Cognition Memory Layer: Implementation Plan

- **Date:** 2026-11-07
- **Status:** Shipped (M0–M4, 2026-11-07). P0+P1+P2 core implemented; P3/P4 deferred per [`127-cognition-memory-layer-proposal.md`](127-cognition-memory-layer-proposal.md). Live probe green: granite-97m-r2-int8 on ort rc.9 — ~7.4 ms/sentence (debug), cross-lingual cosine en↔de/fr/es 0.87–0.89 vs 0.65 unrelated.
- **Branch note:** works on top of `alpha-working` (or the current forge branch); touches only `examples/nvoos-dictate/**` + the proposal docs.

## Verified dependency facts (2026-11-07)

| Item | Verified fact |
|---|---|
| `sqlite-vec` crate | 0.1.9 (2026-09-21), MIT/Apache-2.0, pre-v1 → **pin `=0.1.9`**. Rust surface: `unsafe fn sqlite3_vec_init(...)`, loaded via rusqlite `load_extension("vec0", fn-ptr)` (rusqlite ≥0.29 overload; the app pins 0.32). Requires SQLite compiled with `SQLITE_ENABLE_LOAD_EXTENSION` → add rusqlite feature `loadable_extension`. vec0 supports `distance_metric=cosine`, aux columns (`+surface TEXT`), `MATCH ?1 AND k = ?2` KNN. |
| `tokenizers` crate | stable max 0.23.2 (Apache-2.0); a 1.0.0-rc series exists — **do not take rc**. BPE + deserialization are core; the `onig` feature is required for `\p{...}` regex pretokenizers (ModernBERT/GPT-2 ByteLevel). Use `default-features = false, features = ["onig"]`. |
| Granite embedder | `ibm-granite/granite-embedding-97m-multilingual-r2`, ungated, Apache-2.0, ModernBertModel, ~55 languages, **384-dim, CLS pooling** (`1_Pooling/config.json`). Files to pin: `onnx/model_quint8_avx2.onnx` 98,247,878 B sha256 `a6022dd8220ea6f6595562a1328ee216f4a94faa55362f2f4747c80f1e78772e`; `tokenizer.json` 25,301,672 B sha256 `4f2842d568e2724370aec203652a42ac783c7937f8347a1a2cc7506d71f1582f`. FP32 fallback `onnx/model.onnx` 390,004,608 B sha256 `68e592b160673d30250824c1116bc6ab33f70efb22b97c9e1d7ce1e69c1c9d70`. |
| HF download URL | `https://huggingface.co/ibm-granite/granite-embedding-97m-multilingual-r2/resolve/main/<path>` (LFS → signed CDN redirect; reqwest follows). |
| ort | already pinned `=2.0.0-rc.9`; session APIs only (`ort::session::Session::builder()?.commit_from_file`, `ort::inputs![Value::from_array(..)?]?` — the exact shapes `vision/detect.rs` uses). |
| Existing DB | `storage/db.rs`: `Mutex<Connection>`, `open()` runs migrations → pragmas. **Extension must load BEFORE migrations** (the vec0 CREATE is in the migration list). |
| Existing downloader | `stt/model_registry.rs::download` — streaming sha256, `.download` partial in models root, force restarts. Memory models reuse the mechanics with a two-file (non-archive) install. |

## Key design decisions (locked)

1. **`sqlite-vec` is a NON-optional dependency.** The vec0 virtual table is created unconditionally in the migration list, because `rusqlite_migration` keys migrations by position — a feature-conditional migration would break opening a memory-enabled DB from a feature-off build (document-module lesson). The `memory` Cargo feature gates only the encoder (ort + tokenizers) and the ask path.
2. **Triggers own consistency.** `chunks_fts` (FTS5 external-content) sync via AI/AD/AU triggers; vector rows die with their chunk via an AD trigger. "Embedded" = a row exists in `chunk_vectors` (no marker column; `pending = chunks LEFT JOIN chunk_vectors ... WHERE chunk_vectors.rowid IS NULL`).
3. **One tokenizer for index and query** (SurfSense ADR 0032): FTS5 `unicode61` on the index side; a matching Rust splitter (split on non-alphanumeric, ASCII-lowercase) on the query side. Non-Latin scripts ride the vector leg — the documented CJK gap.
4. **Blend** (ADRs 0031/0033): `score = w·sim + (1−w)·kw` over the union; `sim = max(0, 1 − vec0_cosine_distance)` (each candidate's own cosine), `kw` = fraction of distinct query terms present in the chunk; `w` = `settings.memory.semantic_weight`, default **0.85** (the SurfSense-measured granite plateau).
5. **Model downloads at runtime** (house rule — weights are never compiled in): `%APPDATA%/nvoos-dictate/models/memory-granite-97m-r2/{model_quint8_avx2.onnx, tokenizer.json}`, pinned sha256, atomic per-file install, Re-download button.
6. **Tests never need the network or ort**: a deterministic `MockEmbedder` (seeded hash → normalized 384-dim vector) backs the retrieval/blend/cascade tests; `embed_probe` is the live-verification gate.
7. **Failure-soft everywhere**: embedder missing → chunks-only retrieval (FTS leg still works); provider error → chunks-only answer; worker errors → diagnostics counter, never a panic.

## Phases

### M0 — Spike: `embed_probe` + dependency wiring

- `Cargo.toml`: `sqlite-vec = "=0.1.9"` (non-optional); `tokenizers = { version = "0.23", default-features = false, features = ["onig"], optional = true }`; feature `memory = ["dep:ort", "dep:ort-sys", "dep:tokenizers"]`; rusqlite gains `loadable_extension`; `[[example]] embed_probe (required-features = ["memory"])`.
- `examples/embed_probe.rs`: `--download` (fetch both pinned files, verify sha256), load tokenizer + ort session, embed fixture sentences (EN pair, EN↔DE/ES/FR translation probes), print input/output tensor names, dim, RTF, cross-lingual cosine sanity. **This is the gate that decides whether the quint8 export runs on ort rc.9; if not, the FP32 entry is the fallback catalogue id.**
- Gate: `cargo check --features memory` + probe runs green on the reference PC (model ~123 MB download).

### M1 — Storage: schema, chunking, catalogue, retrieval core

- `storage/migrations.rs` (append-only; the vec0 CREATE sits **last**):
  - `chunks(id, surface, surface_id, seq, text, created_at)` + index `(surface, surface_id, seq)`;
  - `chunks_fts` FTS5 `(text, surface UNINDEXED, surface_id UNINDEXED, content='chunks', content_rowid='id')` + AI/AD/AU triggers;
  - `chunk_vectors` vec0 `embedding float[384] distance_metric=cosine, +surface TEXT, +surface_id INTEGER` + AD trigger deleting vectors with their chunk.
- `storage/db.rs`: load sqlite-vec before migrations (`#[cfg]`-free — always compiled); new methods: `insert_chunks(surface, surface_id, &[&str])`, `pending_chunks(limit) -> Vec<(i64, String)>`, `store_vectors(&[(i64, Vec<f32>)])`, `delete_chunks_for(surface, surface_id)`, `purge_memory()`, `memory_counts()`; delete paths (`delete_transcript`, `delete_snippet`, `delete_meeting`, `clear_transcripts`) cascade via `delete_chunks_for` (triggers take the FTS/vector rows).
- `memory/` module (always compiled; encoder bodies cfg-gated):
  - `mod.rs` — `MemoryHit { chunk_id, surface, surface_id, seq, text, score, matched_terms }`, `MemoryStatus`, `available()` = `cfg!(feature = "memory")`;
  - `chunker.rs` — deterministic splitter (paragraphs → sentences → 100–600 char windows), no deps, unit-tested;
  - `term.rs` — `split_terms()` + `fts_query(terms)` (quote + OR-join + `"`-doubling), unit-tested;
  - `embed.rs` — `trait Embedder { fn dim() ; fn embed_batch(&mut self, &[String]) -> AppResult<Vec<Vec<f32>>> }`; `MockEmbedder` (always); `OrtEmbedder` (cfg `memory`: tokenizers + ort session, dynamic input/output name discovery, CLS pooling, L2 normalization);
  - `models.rs` — pinned catalogue (granite int8 default, granite fp32 fallback, bge-small-en-v1.5 as the third entry for the eval harness), `is_installed()`, `download()` (two-file, streaming sha256, atomic rename, force) + tests (truncating-open regression mirror);
  - `index.rs` — `retrieve(db, query, w, top_k, surfaces) -> Vec<MemoryHit>` (blank short-circuit; FTS leg: top-20 by BM25 → term-fraction score; vec leg: `MATCH ?1 AND k=20` with the surface filter inside the vec0 CTE; union blend; sort/cut) — tested against `MockEmbedder`-seeded corpora.
- Gates: `cargo test` (default — mock path) green; `cargo test --features memory` green.

### M2 — Controller, settings, diagnostics, IPC

- `memory/controller.rs` — `MemoryController` (always compiled, like `DocumentController`): worker thread `nvoos-memory` (own runtime) polling `pending_chunks` every 2 s when enabled; `ensure_encoder()` (drop+reload on model/settings change); `retrieve()`, `ask()` (retrieve → numbered-passages prompt → `cleanup::create_provider(..)?.summarize(..)`, chunks-only fallback); `reindex()` (DELETE vectors → worker refills), `purge()`, `status()`, diagnostics counters (chunks, indexed, pending, last_batch_ms, errors).
- `config.rs` — `MemoryConfig { enabled: bool, embedder: String, semantic_weight: f32, top_k: usize, ask_provider_enabled: bool }` + serde defaults (0.85 / 5 / true); `Settings.memory`.
- `state.rs` — `AppState.memory: Arc<MemoryController>`; worker start/stop reacts to `settings.memory.enabled` in `update_settings`.
- `ipc.rs` — `StatusInfo` += `memory_available`, `memory_enabled`, `memory_indexed`, `memory_chunks`; commands `memory_status`, `ask_memory(query)`, `reindex_memory`, `purge_memory`, `download_memory_model(force)`; `get_diagnostics` += a `memory` block.
- `lib.rs` — register the five commands.
- Gates: fmt/clippy `-D warnings` (default + `--features memory`), `cargo test` both, `npm run build`.

### M3 — Frontend

- `src/lib/ipc.ts` — `Settings.memory` shape + `MemoryStatus`/`MemoryHit`/`AskResult` types + five invoke wrappers.
- `src/views/Home.tsx` — "Ask your words" panel (query box, results list with surface badges + click-to-paste, answer block, provider-off hint), gated on `memory_available`.
- `src/views/Settings.tsx` — Memory section: enable toggle, embedder select (catalogue ids), semantic weight slider (0–1), top_k, Re-download / Reindex / Purge buttons.
- `src/views/Diagnostics.tsx` — Memory card (available, enabled, model + installed, dim, chunks/indexed/pending, last_batch_ms, errors).
- Gate: `npm run build` (tsc + vite).

### M4 — Docs, attribution, complete-build union

- `NOTICE.md` — granite-embedding-97m-multilingual-r2 (Apache-2.0, IBM), tokenizer.json (same repo), sqlite-vec (MIT/Apache-2.0, Alex Garcia), tokenizers (Apache-2.0, HF).
- `scripts/verify_complete_build.ps1` — add `memory` to the feature union + a marker string (`chunk_vectors`).
- `.agents/skills/mcp-ai-wpoos-dictation-app/SKILL.md` — new "Memory layer (Proposal 127)" section (architecture, gate matrix row, model pins, probe recipe).
- `docs/project/proposals/127-…` statuses updated to reflect what shipped.

## Deferred (per proposal)

P3 (weight retune + reranker tier + admission tests as install gates), P4 (office pack, TTS read-back, edit-as-copy), egress consent dashboard, spellcheck-dictionary audit, re-embed migration machinery (the purge+reindex pair covers model changes manually until then).

## Gate matrix (target)

```bash
cd src-tauri
cargo fmt --check
cargo clippy --all-targets --offline -- -D warnings            # default
cargo clippy --all-targets --offline --features memory -- -D warnings
cargo test --offline                                           # default (mock embedder)
cargo test --offline --features memory
npm run build
# live (reference PC, network):
cargo run --features memory --example embed_probe -- --download
```
