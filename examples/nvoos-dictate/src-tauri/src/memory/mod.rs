//! Memory layer (Proposal 127): unified, searchable corpus over every
//! capture surface — dictation transcripts, meetings, snippets, and
//! document pages. Hybrid retrieval (FTS5 keyword leg + sqlite-vec vector
//! leg, blended) with a bundled hash-pinned multilingual embedder running
//! on the app's `ort` pin.
//!
//! Always compiled (type-stable for AppState/IPC — the document-module
//! discipline); the encoder (ort + tokenizers) lives behind the `memory`
//! feature. Without it, retrieval serves the keyword leg only — the
//! failure-soft rule, never a dead end.

pub mod chunker;
pub mod controller;
pub mod embed;
pub mod index;
pub mod models;
pub mod term;

/// Whether the encoder surface (ort + tokenizers) is compiled in.
pub fn available() -> bool {
    cfg!(feature = "memory")
}

/// One retrieved chunk, as serialized to the webview.
#[derive(Debug, Clone, serde::Serialize)]
pub struct MemoryHit {
    pub chunk_id: i64,
    /// `transcript` | `meeting` | `snippet`.
    pub surface: String,
    /// The id in the surface's own table (click-through target).
    pub surface_id: i64,
    /// Position within the source text.
    pub seq: i64,
    pub text: String,
    /// Blended score (keyword-strong at 0, semantic-strong at 1).
    pub score: f32,
    /// How many of the query's distinct terms this chunk contains.
    pub matched_terms: usize,
}

/// Snapshot of the memory subsystem for Settings/Diagnostics/Home.
#[derive(Debug, Clone, serde::Serialize)]
pub struct MemoryStatus {
    /// Whether the encoder is compiled in (`cfg!(feature = "memory")`).
    pub available: bool,
    /// Whether the layer is turned on (Settings → Memory).
    pub enabled: bool,
    /// Configured embedder catalogue id.
    pub embedder: String,
    /// Whether every file of the configured embedder is installed.
    pub model_installed: bool,
    /// Vector width (384).
    pub dim: usize,
    /// Configured blend weight.
    pub semantic_weight: f32,
    pub chunks_total: i64,
    pub chunks_indexed: i64,
    pub chunks_pending: i64,
    /// Latency of the last worker batch, ms.
    pub last_batch_ms: u64,
    /// Worker-side error count since launch.
    pub errors: u64,
    /// Most recent destructive operations (the NiceDB-style write log),
    /// newest first, at most the last five.
    pub recent_ops: Vec<MemoryOp>,
}

/// One destructive-operation audit row (Proposal 128).
#[derive(Debug, Clone, serde::Serialize)]
pub struct MemoryOp {
    /// `purge` | `reindex`.
    pub action: String,
    /// What the operation touched (row counts).
    pub detail: String,
    pub created_at: String,
}

/// The result of an ask: retrieved chunks always; a cited answer only when
/// a provider could be built and answered (failure-soft by contract).
#[derive(Debug, Clone, serde::Serialize)]
pub struct AskResult {
    pub hits: Vec<MemoryHit>,
    pub answer: Option<String>,
}
