//! Memory controller (Proposal 127, M2): the resident worker and the
//! ask/retrieve surface.
//!
//! One dedicated `nvoos-memory` worker owns the embedder (an ort session is
//! not cheap to build and embed-batches must never block the capture path).
//! The controller talks to it over a std-mpsc job channel:
//!
//! - `Swap(embedder)` — settings/model changes hand the worker a fresh (or
//!   no) encoder; the old session is dropped on the worker, off the hot path.
//! - `Embed { texts, reply }` — the ask path sends batches over and waits on
//!   the reply channel; the worker also polls for unembedded chunks every
//!   2 s when enabled.
//!
//! Failure-soft everywhere: no embedder (feature off, model missing, worker
//! error) → retrieval serves the keyword leg only; a provider error on ask
//! → chunks-only answer. Nothing here can panic a capture.

use std::path::Path;
use std::sync::atomic::{AtomicBool, AtomicU64, Ordering};
use std::sync::{mpsc, Arc, Mutex};

use crate::config::Settings;
use crate::error::{AppError, AppResult};
use crate::state::SecretStore;
use crate::storage::Db;

use super::embed::Embedder;
use super::index::{self, SURFACES};
use super::models;
use super::term;
use super::{AskResult, MemoryHit, MemoryStatus};

/// Batch size per worker poll — the encoder's throughput sweet spot.
const WORKER_BATCH: i64 = 32;

enum Job {
    Swap(Option<Box<dyn Embedder>>),
    Embed {
        texts: Vec<String>,
        reply: mpsc::Sender<Result<Vec<Vec<f32>>, String>>,
    },
}

pub struct MemoryController {
    job_tx: mpsc::Sender<Job>,
    enabled: Arc<AtomicBool>,
    /// The embedder spec the worker currently holds (None = none resident).
    resident: Mutex<Option<String>>,
    errors: Arc<AtomicU64>,
    last_batch_ms: Arc<AtomicU64>,
}

impl MemoryController {
    /// Build the controller and spawn the worker. The worker starts
    /// embedder-less; `apply_settings` hands it one when Memory is on and
    /// the model is installed.
    pub fn new(db: Arc<Db>) -> Self {
        let (job_tx, job_rx) = mpsc::channel::<Job>();
        let enabled = Arc::new(AtomicBool::new(false));
        let errors = Arc::new(AtomicU64::new(0));
        let last_batch_ms = Arc::new(AtomicU64::new(0));
        {
            let enabled = enabled.clone();
            let errors = errors.clone();
            let last_batch_ms = last_batch_ms.clone();
            std::thread::Builder::new()
                .name("nvoos-memory".into())
                .spawn(move || worker(job_rx, db, enabled, errors, last_batch_ms))
                .expect("spawn nvoos-memory worker");
        }
        Self {
            job_tx,
            enabled,
            resident: Mutex::new(None),
            errors,
            last_batch_ms,
        }
    }

    /// React to a settings save (and to model installs): build + swap the
    /// encoder when the configured spec changed or the worker holds none,
    /// drop it when Memory is off, and flip the poll gate.
    pub fn apply_settings(&self, settings: &Settings, models_dir: &Path) {
        let enabled = super::available() && settings.memory.enabled;
        let spec = settings.memory.embedder.clone();

        if enabled {
            let needs_build = {
                let resident = self.resident.lock().unwrap();
                resident.as_deref() != Some(spec.as_str())
            };
            if needs_build {
                match build_embedder(&spec, models_dir) {
                    Ok(Some(embedder)) => {
                        let _ = self.job_tx.send(Job::Swap(Some(embedder)));
                        *self.resident.lock().unwrap() = Some(spec);
                    }
                    Ok(None) => {
                        // Feature compiled out — nothing can ever embed.
                        *self.resident.lock().unwrap() = None;
                    }
                    Err(e) => {
                        // Model missing/unloadable: keyword-only retrieval
                        // until the user installs it (failure-soft).
                        self.errors.fetch_add(1, Ordering::Relaxed);
                        tracing::warn!("memory: embedder '{spec}' unavailable: {e}");
                        let _ = self.job_tx.send(Job::Swap(None));
                        *self.resident.lock().unwrap() = None;
                    }
                }
            }
        } else if (*self.resident.lock().unwrap()).is_some() {
            let _ = self.job_tx.send(Job::Swap(None));
            *self.resident.lock().unwrap() = None;
        }
        self.enabled.store(enabled, Ordering::Relaxed);
    }

    /// Drop the resident encoder — call BEFORE replacing model files on
    /// disk (a loaded session can hold them open on Windows, the
    /// download-model IPC rule).
    pub fn drop_encoder(&self) {
        let _ = self.job_tx.send(Job::Swap(None));
        *self.resident.lock().unwrap() = None;
    }

    /// Retrieve chunks for a question. Keyword leg always; vector leg when
    /// an encoder is resident and answers the embed call in time.
    pub fn retrieve(&self, db: &Db, query: &str, settings: &Settings) -> AppResult<Vec<MemoryHit>> {
        let terms = term::split_terms(query);
        if terms.is_empty() {
            return Ok(Vec::new());
        }
        let vector = self.embed_query(query);
        index::retrieve_with_vector(
            db,
            &terms,
            vector.as_deref(),
            settings.memory.semantic_weight,
            settings.memory.top_k.max(1),
            &SURFACES,
        )
    }

    /// Ask the memory: retrieve, then — when enabled and a provider exists —
    /// answer over the numbered passages. `complete()` (system + user) is
    /// the proper Q&A seam (local providers); providers without it fall
    /// back to `summarize()` on the self-contained prompt. Either failure
    /// returns chunks-only, never an error to the user.
    pub async fn ask(
        &self,
        db: &Db,
        query: &str,
        settings: &Settings,
        secrets: &dyn SecretStore,
    ) -> AppResult<AskResult> {
        let hits = self.retrieve(db, query, settings)?;
        let answer = if settings.memory.ask_provider_enabled {
            match crate::cleanup::create_provider(settings, secrets) {
                Ok(Some(provider)) => {
                    let prompt = build_answer_prompt(query, &hits);
                    let system = "You answer questions about the user's own captured text (dictations, meetings, screenshots, documents). Use ONLY the numbered passages below. Cite sources inline as [n] matching the passage numbers. If the passages do not answer the question, say so instead of guessing.";
                    match provider.complete(system, &prompt, 0.2).await {
                        Ok(answer) => Some(answer),
                        Err(_) => match provider.summarize(&prompt).await {
                            Ok(answer) => Some(answer),
                            Err(e) => {
                                tracing::warn!("memory: provider answer failed: {e}");
                                None
                            }
                        },
                    }
                }
                _ => None,
            }
        } else {
            None
        };
        Ok(AskResult { hits, answer })
    }

    /// Reindex: drop every vector row; the worker re-embeds the corpus on its
    /// next polls. Chunks + the keyword index keep serving throughout. Gated
    /// by the typed confirmation (NiceDB pattern, Proposal 128): the caller
    /// must type `REINDEX` — the check lives HERE, the single write gate, so
    /// no surface can skip it. On success the audit log gains a row.
    pub fn reindex(&self, db: &Db, confirmation: &str) -> AppResult<()> {
        require_confirmation(confirmation, REINDEX_CONFIRMATION, "reindex")?;
        let (total, _, _) = db.memory_counts()?;
        db.clear_vectors()?;
        log_op(
            db,
            "reindex",
            &format!("queued {total} chunks for re-embedding"),
        );
        Ok(())
    }

    /// Wipe the corpus (chunks + vectors + FTS). Capture history is not
    /// touched, but existing history will no longer be searchable until it is
    /// re-captured — hence the stricter `PURGE` gate. The audit log itself is
    /// never wiped.
    pub fn purge(&self, db: &Db, confirmation: &str) -> AppResult<()> {
        require_confirmation(confirmation, PURGE_CONFIRMATION, "memory wipe")?;
        let (total, _, _) = db.memory_counts()?;
        db.purge_memory()?;
        log_op(
            db,
            "purge",
            &format!("removed {total} chunks (history kept)"),
        );
        Ok(())
    }

    /// Snapshot for Settings/Diagnostics/Home.
    pub fn status(
        &self,
        db: &Db,
        settings: &Settings,
        models_dir: &Path,
    ) -> AppResult<MemoryStatus> {
        let spec = models::spec_by_id(&settings.memory.embedder);
        let installed = spec
            .map(|s| models::is_installed(models_dir, s))
            .unwrap_or(false);
        let (total, indexed, pending) = db.memory_counts()?;
        let recent_ops = db.recent_memory_ops(5)?;
        Ok(MemoryStatus {
            available: super::available(),
            enabled: self.enabled.load(Ordering::Relaxed),
            embedder: settings.memory.embedder.clone(),
            model_installed: installed,
            dim: spec.map(|s| s.dim).unwrap_or(384),
            semantic_weight: settings.memory.semantic_weight,
            chunks_total: total,
            chunks_indexed: indexed,
            chunks_pending: pending,
            last_batch_ms: self.last_batch_ms.load(Ordering::Relaxed),
            errors: self.errors.load(Ordering::Relaxed),
            recent_ops,
        })
    }

    /// Embed one query through the worker. `None` = no encoder resident
    /// (feature off, Memory off, model missing) or the worker failed the
    /// call — the keyword leg still serves.
    fn embed_query(&self, query: &str) -> Option<Vec<f32>> {
        if !super::available() || !self.enabled.load(Ordering::Relaxed) {
            return None;
        }
        let (reply_tx, reply_rx) = mpsc::channel();
        if self
            .job_tx
            .send(Job::Embed {
                texts: vec![query.to_string()],
                reply: reply_tx,
            })
            .is_err()
        {
            return None;
        }
        match reply_rx.recv_timeout(std::time::Duration::from_secs(30)) {
            Ok(Ok(mut batch)) => batch.pop().filter(|v| v.iter().any(|x| *x != 0.0)),
            Ok(Err(e)) => {
                self.errors.fetch_add(1, Ordering::Relaxed);
                tracing::warn!("memory: embed query failed: {e}");
                None
            }
            Err(_) => None,
        }
    }
}

/// The typed-confirmation words (NiceDB pattern, Proposal 128): type the
/// exact word to confirm a destructive operation. Case-sensitive after
/// trimming.
pub const REINDEX_CONFIRMATION: &str = "REINDEX";
pub const PURGE_CONFIRMATION: &str = "PURGE";

/// The single write gate: every destructive memory operation passes
/// through here, so no caller (IPC, future surfaces) can skip the typed
/// confirmation.
fn require_confirmation(provided: &str, required: &str, action: &str) -> AppResult<()> {
    if provided.trim() != required {
        return Err(AppError::Other(format!(
            "type '{required}' to confirm the {action}"
        )));
    }
    Ok(())
}

/// Best-effort audit write: the operation already happened, so a failing
/// log must not fail (or mislead) the caller — it warns instead.
fn log_op(db: &Db, action: &str, detail: &str) {
    if let Err(e) = db.log_memory_op(action, detail) {
        tracing::warn!("memory: audit log write failed: {e}");
    }
}

/// Build the resident encoder for a spec. `Ok(None)` = the feature is not
/// compiled in; `Err` = the model is missing or failed to load.
fn build_embedder(spec_id: &str, models_dir: &Path) -> AppResult<Option<Box<dyn Embedder>>> {
    #[cfg(feature = "memory")]
    {
        let spec = models::spec_by_id(spec_id)
            .ok_or_else(|| AppError::Model(format!("unknown embedder '{spec_id}'")))?;
        if !models::is_installed(models_dir, spec) {
            return Err(AppError::Model(format!(
                "embedder '{spec_id}' is not downloaded"
            )));
        }
        let (onnx, tokenizer) = models::model_paths(models_dir, spec);
        let embedder = super::embed::OrtEmbedder::load(&onnx, &tokenizer)?;
        Ok(Some(Box::new(embedder)))
    }
    #[cfg(not(feature = "memory"))]
    {
        let _ = (spec_id, models_dir);
        Ok(None)
    }
}

/// Render the ask prompt: the question plus numbered passages.
fn build_answer_prompt(query: &str, hits: &[MemoryHit]) -> String {
    let mut prompt = format!("Question: {query}\n\nPassages:\n");
    for (index, hit) in hits.iter().enumerate() {
        prompt.push_str(&format!("\n[{}] {}\n", index + 1, hit.text));
    }
    prompt.push_str("\nAnswer the question using only the passages above, citing [n].");
    prompt
}

/// The worker loop: owns the encoder, serves embed jobs, polls for
/// unembedded chunks when enabled.
fn worker(
    job_rx: mpsc::Receiver<Job>,
    db: Arc<Db>,
    enabled: Arc<AtomicBool>,
    errors: Arc<AtomicU64>,
    last_batch_ms: Arc<AtomicU64>,
) {
    let mut embedder: Option<Box<dyn Embedder>> = None;
    loop {
        match job_rx.recv_timeout(std::time::Duration::from_secs(2)) {
            Ok(Job::Swap(new)) => {
                embedder = new;
                continue;
            }
            Ok(Job::Embed { texts, reply }) => {
                let result = match embedder.as_mut() {
                    Some(e) => e.embed_batch(&texts).map_err(|e| e.to_string()),
                    None => Err("no encoder resident".into()),
                };
                let _ = reply.send(result);
                continue;
            }
            Err(mpsc::RecvTimeoutError::Timeout) => {}
            Err(mpsc::RecvTimeoutError::Disconnected) => break,
        }

        if !enabled.load(Ordering::Relaxed) || embedder.is_none() {
            continue;
        }
        match db.pending_chunks(WORKER_BATCH) {
            Ok(pending) if !pending.is_empty() => {
                let started = std::time::Instant::now();
                let texts: Vec<String> = pending.iter().map(|(_, t)| t.clone()).collect();
                match embedder
                    .as_mut()
                    .expect("checked above")
                    .embed_batch(&texts)
                {
                    Ok(vectors) if vectors.len() == pending.len() => {
                        let rows: Vec<(i64, Vec<f32>)> = pending
                            .into_iter()
                            .zip(vectors)
                            .map(|((id, _), v)| (id, v))
                            .collect();
                        match db.store_vectors(&rows) {
                            Ok(()) => {
                                last_batch_ms
                                    .store(started.elapsed().as_millis() as u64, Ordering::Relaxed);
                            }
                            Err(e) => {
                                errors.fetch_add(1, Ordering::Relaxed);
                                tracing::warn!("memory worker: store vectors failed: {e}");
                            }
                        }
                    }
                    Ok(_) => {
                        errors.fetch_add(1, Ordering::Relaxed);
                        tracing::warn!("memory worker: embedder returned a mismatched batch");
                    }
                    Err(e) => {
                        errors.fetch_add(1, Ordering::Relaxed);
                        tracing::warn!("memory worker: embed failed: {e}");
                    }
                }
            }
            Ok(_) => {}
            Err(e) => {
                errors.fetch_add(1, Ordering::Relaxed);
                tracing::warn!("memory worker: pending poll failed: {e}");
            }
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn confirmation_gate_is_exact_and_case_sensitive() {
        assert!(require_confirmation("PURGE", PURGE_CONFIRMATION, "wipe").is_ok());
        assert!(require_confirmation("  PURGE ", PURGE_CONFIRMATION, "wipe").is_ok());
        assert!(require_confirmation("purge", PURGE_CONFIRMATION, "wipe").is_err());
        assert!(require_confirmation("DELETE", PURGE_CONFIRMATION, "wipe").is_err());
        assert!(require_confirmation("", PURGE_CONFIRMATION, "wipe").is_err());
        assert!(require_confirmation("REINDEX", REINDEX_CONFIRMATION, "reindex").is_ok());
        assert!(require_confirmation("PURGE", REINDEX_CONFIRMATION, "reindex").is_err());
    }

    #[test]
    fn refused_purge_touches_nothing_and_gated_purge_logs() {
        let db = crate::storage::Db::open_in_memory().unwrap();
        let db = std::sync::Arc::new(db);
        let controller = MemoryController::new(db.clone());
        db.insert_transcript("raw", "keep me", "mock", "mock", "app", "disabled", 1)
            .unwrap();

        // A refused confirmation must touch neither corpus nor audit log.
        assert!(controller.purge(&db, "nope").is_err());
        assert_eq!(db.memory_counts().unwrap().0, 1);
        assert!(db.recent_memory_ops(5).unwrap().is_empty());

        // The gated op wipes the corpus, keeps history, and logs one row.
        controller.purge(&db, "PURGE").unwrap();
        assert_eq!(db.memory_counts().unwrap(), (0, 0, 0));
        assert_eq!(db.list_transcripts("", 5, 0).unwrap().len(), 1);
        let ops = db.recent_memory_ops(5).unwrap();
        assert_eq!(ops.len(), 1);
        assert_eq!(ops[0].action, "purge");
        assert!(ops[0].detail.contains("removed 1 chunks"));
    }

    #[test]
    fn gated_reindex_logs_and_keeps_chunks() {
        let db = crate::storage::Db::open_in_memory().unwrap();
        let db = std::sync::Arc::new(db);
        let controller = MemoryController::new(db.clone());
        db.insert_transcript("raw", "reindex me", "mock", "mock", "app", "disabled", 1)
            .unwrap();

        assert!(controller.reindex(&db, "wrong").is_err());
        controller.reindex(&db, "REINDEX").unwrap();
        let (total, embedded, pending) = db.memory_counts().unwrap();
        assert!(total > 0);
        assert_eq!(embedded, 0, "reindex empties the vector table");
        assert_eq!(pending, total, "every chunk becomes pending again");
        let ops = db.recent_memory_ops(5).unwrap();
        assert_eq!(ops[0].action, "reindex");
        assert!(ops[0].detail.contains("re-embedding"));
    }
}
