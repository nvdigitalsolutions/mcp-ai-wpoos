//! SQLite-backed local history, dictionary, and meeting transcripts.
//!
//! Layout (v1, embedded migrations):
//!   transcripts  — every dictation with raw + cleaned text, engine, target
//!                  app, provider, and e2e latency (the observability signal)
//!   dictionary   — user vocabulary: term -> replacement
//!   meetings     — meeting transcripts
//!   kv           — small key/value store for non-file settings
//!
//! The DB file lives at `{app_data_dir}/nvoos-dictate/dictate.db`. Audio is
//! never stored here; only text.

use std::path::PathBuf;
use std::sync::Mutex;

use rusqlite::{params, Connection, OptionalExtension};

use super::migrations;

use crate::error::{AppError, AppResult};

/// A dictation history row, as serialized to the webview.
#[derive(Debug, Clone, serde::Serialize, serde::Deserialize)]
pub struct TranscriptRow {
    pub id: i64,
    pub text_raw: String,
    pub text_clean: String,
    pub engine: String,
    pub model: String,
    pub target_app: String,
    pub provider: String,
    pub e2e_ms: i64,
    pub created_at: String,
    /// JSON metadata for media transcriptions (container + duration + source),
    /// or '' for ordinary dictation rows.
    pub media: String,
}

/// A snippet OCR history row, as serialized to the webview.
#[derive(Debug, Clone, serde::Serialize, serde::Deserialize)]
pub struct SnippetRow {
    pub id: i64,
    pub text_raw: String,
    pub text_clean: String,
    pub engine: String,
    pub language: String,
    pub region_w: i64,
    pub region_h: i64,
    pub ocr_ms: i64,
    /// `screen` (snippet capture, default) | `document` (Proposal 109).
    pub source: String,
    pub created_at: String,
}

/// A meeting transcript row.
#[derive(Debug, Clone, serde::Serialize, serde::Deserialize)]
pub struct MeetingRow {
    pub id: i64,
    pub title: String,
    pub text: String,
    pub duration_ms: i64,
    pub created_at: String,
}

/// Register the sqlite-vec `vec0` extension for every future connection
/// (Proposal 127). Process-global and idempotent — SQLite deduplicates auto
/// extensions by entry point — so a `Once` is the whole discipline. Must run
/// before any `Connection` opens: `Db::open` and `open_in_memory` call it
/// first thing, before the migration list (which creates `chunk_vectors`).
fn register_vec0() {
    use std::sync::Once;
    static REGISTER: Once = Once::new();
    REGISTER.call_once(|| unsafe {
        // The vendor's own pattern (bindings/rust/src/lib.rs): the C entry
        // point is declared without a signature and handed to
        // sqlite3_auto_extension through a pointer cast. The `load_extension`
        // rusqlite feature compiles SQLITE_ENABLE_LOAD_EXTENSION into the
        // bundled SQLite so the registration actually sticks. The transmute
        // is fully annotated here (clippy + reviewer clarity).
        let entry: unsafe extern "C" fn(
            *mut rusqlite::ffi::sqlite3,
            *mut *mut std::ffi::c_char,
            *const rusqlite::ffi::sqlite3_api_routines,
        ) -> std::ffi::c_int = std::mem::transmute(sqlite_vec::sqlite3_vec_init as *const ());
        rusqlite::ffi::sqlite3_auto_extension(Some(entry));
    });
}

/// Wrapper around a `rusqlite::Connection`. `Connection` is `Send` but not
/// `Sync`, so all access goes through a `Mutex` — fine for this workload.
pub struct Db {
    conn: Mutex<Connection>,
}

/// One memory chunk row: `(id, surface, surface_id, seq, text)`.
pub type ChunkRow = (i64, String, i64, i64, String);

impl Db {
    /// Open (or create) the on-disk database and run migrations.
    pub fn open_default() -> AppResult<Self> {
        let dir = dirs::data_dir()
            .unwrap_or_else(|| PathBuf::from("."))
            .join("nvoos-dictate");
        std::fs::create_dir_all(&dir)?;
        let path = dir.join("dictate.db");
        Self::open(&path)
    }

    pub fn open(path: &std::path::Path) -> AppResult<Self> {
        register_vec0();
        let mut conn = Connection::open(path)?;
        migrations::migrations()
            .to_latest(&mut conn)
            .map_err(|e| AppError::Migration(e.to_string()))?;
        // WAL mode: readers never block the (rare) writer.
        conn.pragma_update(None, "journal_mode", "WAL")?;
        conn.pragma_update(None, "foreign_keys", "ON")?;
        Ok(Self {
            conn: Mutex::new(conn),
        })
    }

    /// In-memory database for tests.
    pub fn open_in_memory() -> AppResult<Self> {
        register_vec0();
        let mut conn = Connection::open_in_memory()?;
        migrations::migrations()
            .to_latest(&mut conn)
            .map_err(|e| AppError::Migration(e.to_string()))?;
        Ok(Self {
            conn: Mutex::new(conn),
        })
    }

    // ---- snippets -------------------------------------------------------

    #[allow(clippy::too_many_arguments)]
    pub fn insert_snippet(
        &self,
        text_raw: &str,
        text_clean: &str,
        engine: &str,
        language: &str,
        region_w: u32,
        region_h: u32,
        ocr_ms: u64,
    ) -> AppResult<i64> {
        let id = {
            let conn = self.conn.lock().unwrap();
            conn.execute(
                "INSERT INTO snippets (text_raw, text_clean, engine, language, region_w, region_h, ocr_ms)
                 VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7)",
                params![
                    text_raw,
                    text_clean,
                    engine,
                    language,
                    region_w as i64,
                    region_h as i64,
                    ocr_ms as i64
                ],
            )?;
            conn.last_insert_rowid()
        };
        self.fan_out_chunks("snippet", id, text_clean);
        Ok(id)
    }

    /// Insert a document-pipeline history row (Proposal 109): `source` =
    /// `document`; `language` carries the source path; `region_w` the page
    /// count; `ocr_ms` the pipeline duration.
    pub fn insert_document_snippet(
        &self,
        text_raw: &str,
        text_clean: &str,
        engine: &str,
        source_path: &str,
        page_count: u32,
        duration_ms: u64,
    ) -> AppResult<i64> {
        let id = {
            let conn = self.conn.lock().unwrap();
            conn.execute(
                "INSERT INTO snippets (text_raw, text_clean, engine, language, region_w, region_h, ocr_ms, source)
                 VALUES (?1, ?2, ?3, ?4, ?5, 0, ?6, 'document')",
                params![
                    text_raw,
                    text_clean,
                    engine,
                    source_path,
                    page_count as i64,
                    duration_ms as i64
                ],
            )?;
            conn.last_insert_rowid()
        };
        self.fan_out_chunks("snippet", id, text_clean);
        Ok(id)
    }

    pub fn list_snippets(&self, limit: i64, offset: i64) -> AppResult<Vec<SnippetRow>> {
        let conn = self.conn.lock().unwrap();
        let mut stmt = conn.prepare(
            "SELECT id, text_raw, text_clean, engine, language, region_w, region_h, ocr_ms, source, created_at
             FROM snippets
             ORDER BY id DESC
             LIMIT ?1 OFFSET ?2",
        )?;
        let rows = stmt
            .query_map(params![limit, offset], |r| {
                Ok(SnippetRow {
                    id: r.get(0)?,
                    text_raw: r.get(1)?,
                    text_clean: r.get(2)?,
                    engine: r.get(3)?,
                    language: r.get(4)?,
                    region_w: r.get(5)?,
                    region_h: r.get(6)?,
                    ocr_ms: r.get(7)?,
                    source: r.get(8)?,
                    created_at: r.get(9)?,
                })
            })?
            .collect::<Result<Vec<_>, _>>()?;
        Ok(rows)
    }

    pub fn delete_snippet(&self, id: i64) -> AppResult<()> {
        let conn = self.conn.lock().unwrap();
        conn.execute("DELETE FROM snippets WHERE id = ?1", params![id])?;
        conn.execute(
            "DELETE FROM chunks WHERE surface = 'snippet' AND surface_id = ?1",
            params![id],
        )?;
        Ok(())
    }

    // ---- transcripts -----------------------------------------------------

    #[allow(clippy::too_many_arguments)]
    pub fn insert_transcript(
        &self,
        text_raw: &str,
        text_clean: &str,
        engine: &str,
        model: &str,
        target_app: &str,
        provider: &str,
        e2e_ms: i64,
    ) -> AppResult<i64> {
        self.insert_transcript_with_media(
            text_raw, text_clean, engine, model, target_app, provider, e2e_ms, "",
        )
    }

    /// Insert a transcript row, optionally tagging media provenance (JSON).
    #[allow(clippy::too_many_arguments)]
    pub fn insert_transcript_with_media(
        &self,
        text_raw: &str,
        text_clean: &str,
        engine: &str,
        model: &str,
        target_app: &str,
        provider: &str,
        e2e_ms: i64,
        media: &str,
    ) -> AppResult<i64> {
        let id = {
            let conn = self.conn.lock().unwrap();
            conn.execute(
                "INSERT INTO transcripts (text_raw, text_clean, engine, model, target_app, provider, e2e_ms, media)
                 VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8)",
                params![text_raw, text_clean, engine, model, target_app, provider, e2e_ms, media],
            )?;
            conn.last_insert_rowid()
        };
        // Fan the polished text out into the memory corpus (Proposal 127).
        // Best-effort: a chunk failure must never fail the capture insert
        // itself — the dictation is saved either way.
        self.fan_out_chunks("transcript", id, text_clean);
        Ok(id)
    }

    pub fn list_transcripts(
        &self,
        query: &str,
        limit: i64,
        offset: i64,
    ) -> AppResult<Vec<TranscriptRow>> {
        let conn = self.conn.lock().unwrap();
        // Escape LIKE wildcards (and the escape char itself) so a user
        // query for "_" or "%" matches those characters literally.
        let like = format!(
            "%{}%",
            query
                .replace('\\', "\\\\")
                .replace('%', "\\%")
                .replace('_', "\\_")
        );
        let mut stmt = conn.prepare(
            "SELECT id, text_raw, text_clean, engine, model, target_app, provider, e2e_ms, created_at, media
             FROM transcripts
             WHERE (?1 = '' OR text_clean LIKE ?2 ESCAPE '\\')
             ORDER BY id DESC
             LIMIT ?3 OFFSET ?4",
        )?;
        let rows = stmt
            .query_map(params![query, like, limit, offset], |r| {
                Ok(TranscriptRow {
                    id: r.get(0)?,
                    text_raw: r.get(1)?,
                    text_clean: r.get(2)?,
                    engine: r.get(3)?,
                    model: r.get(4)?,
                    target_app: r.get(5)?,
                    provider: r.get(6)?,
                    e2e_ms: r.get(7)?,
                    created_at: r.get(8)?,
                    media: r.get(9)?,
                })
            })?
            .collect::<Result<Vec<_>, _>>()?;
        Ok(rows)
    }

    pub fn delete_transcript(&self, id: i64) -> AppResult<()> {
        let conn = self.conn.lock().unwrap();
        conn.execute("DELETE FROM transcripts WHERE id = ?1", params![id])?;
        conn.execute(
            "DELETE FROM chunks WHERE surface = 'transcript' AND surface_id = ?1",
            params![id],
        )?;
        Ok(())
    }

    pub fn clear_transcripts(&self) -> AppResult<()> {
        let conn = self.conn.lock().unwrap();
        conn.execute("DELETE FROM transcripts", [])?;
        conn.execute("DELETE FROM chunks WHERE surface = 'transcript'", [])?;
        Ok(())
    }

    // ---- dictionary ------------------------------------------------------

    pub fn dictionary(&self) -> AppResult<Vec<(String, String)>> {
        let conn = self.conn.lock().unwrap();
        let mut stmt = conn.prepare("SELECT term, replacement FROM dictionary ORDER BY rowid")?;
        let rows = stmt
            .query_map([], |r| Ok((r.get(0)?, r.get(1)?)))?
            .collect::<Result<Vec<_>, _>>()?;
        Ok(rows)
    }

    pub fn set_dictionary(&self, entries: &[(String, String)]) -> AppResult<()> {
        let mut conn = self.conn.lock().unwrap();
        let tx = conn.transaction()?;
        tx.execute("DELETE FROM dictionary", [])?;
        {
            let mut stmt =
                tx.prepare("INSERT INTO dictionary (term, replacement) VALUES (?1, ?2)")?;
            for (term, replacement) in entries {
                stmt.execute(params![term, replacement])?;
            }
        }
        tx.commit()?;
        Ok(())
    }

    // ---- meetings --------------------------------------------------------

    pub fn insert_meeting(&self, title: &str, text: &str, duration_ms: i64) -> AppResult<i64> {
        let id = {
            let conn = self.conn.lock().unwrap();
            conn.execute(
                "INSERT INTO meetings (title, text, duration_ms) VALUES (?1, ?2, ?3)",
                params![title, text, duration_ms],
            )?;
            conn.last_insert_rowid()
        };
        self.fan_out_chunks("meeting", id, text);
        Ok(id)
    }

    pub fn list_meetings(&self, limit: i64) -> AppResult<Vec<MeetingRow>> {
        let conn = self.conn.lock().unwrap();
        let mut stmt = conn.prepare(
            "SELECT id, title, text, duration_ms, created_at FROM meetings ORDER BY id DESC LIMIT ?1",
        )?;
        let rows = stmt
            .query_map(params![limit], |r| {
                Ok(MeetingRow {
                    id: r.get(0)?,
                    title: r.get(1)?,
                    text: r.get(2)?,
                    duration_ms: r.get(3)?,
                    created_at: r.get(4)?,
                })
            })?
            .collect::<Result<Vec<_>, _>>()?;
        Ok(rows)
    }

    pub fn delete_meeting(&self, id: i64) -> AppResult<()> {
        let conn = self.conn.lock().unwrap();
        conn.execute("DELETE FROM meetings WHERE id = ?1", params![id])?;
        conn.execute(
            "DELETE FROM chunks WHERE surface = 'meeting' AND surface_id = ?1",
            params![id],
        )?;
        Ok(())
    }

    // ---- kv --------------------------------------------------------------

    pub fn kv_get(&self, key: &str) -> AppResult<Option<String>> {
        let conn = self.conn.lock().unwrap();
        let value: Option<String> = conn
            .query_row("SELECT value FROM kv WHERE key = ?1", params![key], |r| {
                r.get(0)
            })
            .optional()?;
        Ok(value)
    }

    pub fn kv_set(&self, key: &str, value: &str) -> AppResult<()> {
        let conn = self.conn.lock().unwrap();
        conn.execute(
            "INSERT INTO kv (key, value) VALUES (?1, ?2)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value",
            params![key, value],
        )?;
        Ok(())
    }

    // ---- memory (Proposal 127) ------------------------------------------

    /// Chunk a captured text and fan it out into `chunks`. Best-effort by
    /// contract: the caller's capture row is already committed; a failure
    /// here is logged, never propagated (a dictation must not fail because
    /// the search index did).
    pub fn fan_out_chunks(&self, surface: &str, surface_id: i64, text: &str) {
        let chunks = crate::memory::chunker::chunk_text(text);
        if chunks.is_empty() {
            return;
        }
        if let Err(e) = self.insert_chunks_raw(surface, surface_id, &chunks) {
            tracing::warn!("memory: fan-out for {surface}/{surface_id} failed: {e}");
        }
    }

    /// Insert pre-chunked rows for one surface item. The FTS triggers keep
    /// the keyword index in step; vectors are the worker's job later.
    pub fn insert_chunks_raw(
        &self,
        surface: &str,
        surface_id: i64,
        chunks: &[String],
    ) -> AppResult<()> {
        let mut conn = self.conn.lock().unwrap();
        let tx = conn.transaction()?;
        {
            let mut stmt = tx.prepare(
                "INSERT INTO chunks (surface, surface_id, seq, text) VALUES (?1, ?2, ?3, ?4)",
            )?;
            for (seq, text) in chunks.iter().enumerate() {
                stmt.execute(params![surface, surface_id, seq as i64, text])?;
            }
        }
        tx.commit()?;
        Ok(())
    }

    /// Chunks that still need a vector ("embedded" == a `chunk_vectors` row
    /// exists; a failed batch is naturally retried on the next poll).
    pub fn pending_chunks(&self, limit: i64) -> AppResult<Vec<(i64, String)>> {
        let conn = self.conn.lock().unwrap();
        let mut stmt = conn.prepare(
            "SELECT c.id, c.text
             FROM chunks c
             LEFT JOIN chunk_vectors v ON v.rowid = c.id
             WHERE v.rowid IS NULL
             ORDER BY c.id ASC
             LIMIT ?1",
        )?;
        let rows = stmt
            .query_map(params![limit], |r| Ok((r.get(0)?, r.get(1)?)))?
            .collect::<Result<Vec<_>, _>>()?;
        Ok(rows)
    }

    /// Store normalized vectors for chunk ids. Aux columns copy the chunk's
    /// surface scope so the vec0 KNN filter (`surface IN (...)`) runs inside
    /// the vector query, before `k` is applied.
    pub fn store_vectors(&self, rows: &[(i64, Vec<f32>)]) -> AppResult<()> {
        if rows.is_empty() {
            return Ok(());
        }
        let mut conn = self.conn.lock().unwrap();
        let tx = conn.transaction()?;
        {
            let mut stmt = tx.prepare(
                "INSERT INTO chunk_vectors (rowid, embedding, surface, surface_id)
                 SELECT ?1, ?2, c.surface, c.surface_id FROM chunks c WHERE c.id = ?1",
            )?;
            for (id, vector) in rows {
                let json = serde_json::to_string(vector)
                    .map_err(|e| AppError::Other(format!("vector json: {e}")))?;
                stmt.execute(params![id, json])?;
            }
        }
        tx.commit()?;
        Ok(())
    }

    /// Drop every vector row (a reindex / embedder change): the worker
    /// re-embeds the whole corpus on its next polls. Chunks and the FTS
    /// index are untouched — the keyword leg keeps serving throughout.
    pub fn clear_vectors(&self) -> AppResult<()> {
        let conn = self.conn.lock().unwrap();
        conn.execute("DELETE FROM chunk_vectors", [])?;
        Ok(())
    }

    /// Wipe the whole memory corpus (chunks + vectors + FTS via triggers).
    /// The capture tables themselves are untouched.
    pub fn purge_memory(&self) -> AppResult<()> {
        let conn = self.conn.lock().unwrap();
        conn.execute("DELETE FROM chunks", [])?;
        Ok(())
    }

    /// Remove one surface item's chunks (vectors + FTS rows follow through
    /// the triggers). Public for the reindex/tests paths.
    pub fn delete_chunks_for(&self, surface: &str, surface_id: i64) -> AppResult<()> {
        let conn = self.conn.lock().unwrap();
        conn.execute(
            "DELETE FROM chunks WHERE surface = ?1 AND surface_id = ?2",
            params![surface, surface_id],
        )?;
        Ok(())
    }

    /// Append one row to the destructive-operation audit log (Proposal 128).
    /// Append-only: nothing in the app deletes from `memory_ops`.
    pub fn log_memory_op(&self, action: &str, detail: &str) -> AppResult<()> {
        let conn = self.conn.lock().unwrap();
        conn.execute(
            "INSERT INTO memory_ops (action, detail) VALUES (?1, ?2)",
            params![action, detail],
        )?;
        Ok(())
    }

    /// The most recent destructive memory operations, newest first.
    pub fn recent_memory_ops(&self, limit: i64) -> AppResult<Vec<crate::memory::MemoryOp>> {
        let conn = self.conn.lock().unwrap();
        let mut stmt = conn.prepare(
            "SELECT action, detail, created_at FROM memory_ops ORDER BY id DESC LIMIT ?1",
        )?;
        let rows = stmt
            .query_map(params![limit], |r| {
                Ok(crate::memory::MemoryOp {
                    action: r.get(0)?,
                    detail: r.get(1)?,
                    created_at: r.get(2)?,
                })
            })?
            .collect::<Result<Vec<_>, _>>()?;
        Ok(rows)
    }

    /// `(total chunks, embedded chunks, pending chunks)` for diagnostics.
    pub fn memory_counts(&self) -> AppResult<(i64, i64, i64)> {
        let conn = self.conn.lock().unwrap();
        let total: i64 = conn.query_row("SELECT COUNT(*) FROM chunks", [], |r| r.get(0))?;
        let embedded: i64 =
            conn.query_row("SELECT COUNT(*) FROM chunk_vectors", [], |r| r.get(0))?;
        Ok((total, embedded, total - embedded))
    }

    /// Keyword leg: FTS5 top-N by BM25 with the surface scope filter.
    /// Returns `(chunk_id, text)` in rank order.
    pub fn fts_search(
        &self,
        fts_query: &str,
        surfaces: &[&str],
        limit: i64,
    ) -> AppResult<Vec<(i64, String)>> {
        let filter = crate::memory::term::surface_filter(surfaces);
        let sql = format!(
            "SELECT rowid, text FROM chunks_fts
             WHERE chunks_fts MATCH ?1 AND {filter}
             ORDER BY rank LIMIT ?2"
        );
        let conn = self.conn.lock().unwrap();
        let mut stmt = conn.prepare(&sql)?;
        let rows = stmt
            .query_map(params![fts_query, limit], |r| Ok((r.get(0)?, r.get(1)?)))?
            .collect::<Result<Vec<_>, _>>()?;
        Ok(rows)
    }

    /// Vector leg: vec0 KNN (cosine distance) with the scope filter INSIDE
    /// the vector query, before `k` is applied — so a chunk outside the
    /// scope can never crowd a scope's results out (the SurfSense gotcha).
    /// Returns `(chunk_id, distance)` where distance = 1 - cosine.
    pub fn vector_search(
        &self,
        vector: &[f32],
        surfaces: &[&str],
        k: i64,
    ) -> AppResult<Vec<(i64, f32)>> {
        let filter = crate::memory::term::surface_filter(surfaces);
        let json = serde_json::to_string(vector)
            .map_err(|e| AppError::Other(format!("query vector json: {e}")))?;
        let sql = format!(
            "SELECT rowid, distance FROM chunk_vectors
             WHERE embedding MATCH ?1 AND k = ?2 AND {filter}"
        );
        let conn = self.conn.lock().unwrap();
        let mut stmt = conn.prepare(&sql)?;
        let rows = stmt
            .query_map(params![json, k], |r| Ok((r.get(0)?, r.get(1)?)))?
            .collect::<Result<Vec<_>, _>>()?;
        Ok(rows)
    }

    /// Fetch full chunk rows for hit rendering.
    pub fn chunk_rows(&self, ids: &[i64]) -> AppResult<Vec<ChunkRow>> {
        if ids.is_empty() {
            return Ok(Vec::new());
        }
        let conn = self.conn.lock().unwrap();
        let placeholders = ids.iter().map(|_| "?").collect::<Vec<_>>().join(",");
        let sql = format!(
            "SELECT id, surface, surface_id, seq, text FROM chunks WHERE id IN ({placeholders})"
        );
        let mut stmt = conn.prepare(&sql)?;
        let rows = stmt
            .query_map(rusqlite::params_from_iter(ids.iter()), |r| {
                Ok((r.get(0)?, r.get(1)?, r.get(2)?, r.get(3)?, r.get(4)?))
            })?
            .collect::<Result<Vec<_>, _>>()?;
        Ok(rows)
    }
}
