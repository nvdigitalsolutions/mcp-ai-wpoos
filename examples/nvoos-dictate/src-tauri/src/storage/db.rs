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

/// Wrapper around a `rusqlite::Connection`. `Connection` is `Send` but not
/// `Sync`, so all access goes through a `Mutex` — fine for this workload.
pub struct Db {
    conn: Mutex<Connection>,
}

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
        let mut conn = Connection::open_in_memory()?;
        migrations::migrations()
            .to_latest(&mut conn)
            .map_err(|e| AppError::Migration(e.to_string()))?;
        Ok(Self {
            conn: Mutex::new(conn),
        })
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
        let conn = self.conn.lock().unwrap();
        conn.execute(
            "INSERT INTO transcripts (text_raw, text_clean, engine, model, target_app, provider, e2e_ms)
             VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7)",
            params![text_raw, text_clean, engine, model, target_app, provider, e2e_ms],
        )?;
        Ok(conn.last_insert_rowid())
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
            "SELECT id, text_raw, text_clean, engine, model, target_app, provider, e2e_ms, created_at
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
                })
            })?
            .collect::<Result<Vec<_>, _>>()?;
        Ok(rows)
    }

    pub fn delete_transcript(&self, id: i64) -> AppResult<()> {
        let conn = self.conn.lock().unwrap();
        conn.execute("DELETE FROM transcripts WHERE id = ?1", params![id])?;
        Ok(())
    }

    pub fn clear_transcripts(&self) -> AppResult<()> {
        let conn = self.conn.lock().unwrap();
        conn.execute("DELETE FROM transcripts", [])?;
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
        let conn = self.conn.lock().unwrap();
        conn.execute(
            "INSERT INTO meetings (title, text, duration_ms) VALUES (?1, ?2, ?3)",
            params![title, text, duration_ms],
        )?;
        Ok(conn.last_insert_rowid())
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
}
