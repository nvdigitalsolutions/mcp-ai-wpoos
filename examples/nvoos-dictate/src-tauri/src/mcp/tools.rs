//! MCP tool handlers — implemented as plain methods on a `Clone` toolbox so
//! they are unit-testable without a live transport or a Tauri app handle,
//! then wired to rmcp's `#[tool_router]` in `server.rs`.

use std::sync::Arc;

use serde_json::json;

use crate::config::AppCategory;
use crate::error::AppResult;
use crate::meetings::recorder::MeetingRecorder;
use crate::storage::db::Db;

/// Shared toolbox handed to every MCP tool invocation.
#[derive(Clone)]
pub struct Toolbox {
    pub db: Arc<Db>,
    pub recorder: Arc<MeetingRecorder>,
    /// Trigger a dictation and receive the cleaned text (or error). In the
    /// desktop app this arms the PTT overlay; in headless stdio mode it
    /// returns a clear "run the desktop app" error.
    pub dictate: Arc<dyn Fn(Option<String>) -> AppResult<String> + Send + Sync>,
}

impl Toolbox {
    /// `dictate` tool body.
    pub fn tool_dictate(&self, hint: Option<String>) -> AppResult<String> {
        (self.dictate)(hint)
    }

    /// `get_dictation_history` tool body.
    pub fn tool_history(&self, query: String, limit: u32) -> AppResult<String> {
        let limit = limit.clamp(1, 100) as i64;
        let rows = self.db.list_transcripts(&query, limit, 0)?;
        let payload: Vec<serde_json::Value> = rows
            .iter()
            .map(|r| {
                json!({
                    "id": r.id,
                    "text": r.text_clean,
                    "target_app": r.target_app,
                    "engine": r.engine,
                    "e2e_ms": r.e2e_ms,
                    "created_at": r.created_at,
                })
            })
            .collect();
        Ok(serde_json::to_string_pretty(&payload)?)
    }

    /// `get_dictionary` tool body.
    pub fn tool_dictionary(&self) -> AppResult<String> {
        let entries = self.db.dictionary()?;
        Ok(serde_json::to_string_pretty(&entries)?)
    }

    /// `transcribe_meeting` tool body: start a meeting recording, wait for
    /// `duration_secs` (or until stopped), transcribe, persist, return text.
    pub fn tool_transcribe_meeting(
        &self,
        title: String,
        duration_secs: Option<u64>,
        engine: Arc<dyn crate::stt::SttEngine>,
    ) -> AppResult<String> {
        self.recorder.start()?;
        let limit = duration_secs.unwrap_or(3600).min(4 * 3600);
        let deadline = std::time::Instant::now() + std::time::Duration::from_secs(limit);
        while self.recorder.is_recording() && std::time::Instant::now() < deadline {
            std::thread::sleep(std::time::Duration::from_millis(250));
        }
        let summary = self.recorder.stop(engine, &title)?;
        let id = self
            .db
            .insert_meeting(&summary.title, &summary.text, summary.duration_ms)?;
        Ok(json!({
            "id": id,
            "title": summary.title,
            "text": summary.text,
            "duration_ms": summary.duration_ms,
        })
        .to_string())
    }
}

/// Category helper for prompt building (shared with providers).
pub fn category_of(app: &str) -> AppCategory {
    AppCategory::from_process_name(app)
}
