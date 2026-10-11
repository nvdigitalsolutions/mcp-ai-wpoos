//! Archive sync: build a `TranscriptRecord` from a history row and push it
//! one-way to the configured NV oOS site. Local history stays the source of
//! truth — the plugin is a mirror, never the other way around.

use crate::error::AppResult;
use crate::nvoos::{NvoosClient, TranscriptRecord};
use crate::storage::db::TranscriptRow;

/// Push a single transcript to the plugin (idempotent at the plugin layer by
/// caller-chosen title; the app does not re-push already-archived rows).
pub async fn archive_transcript(
    client: &NvoosClient,
    row: &TranscriptRow,
    assistant_id: Option<&str>,
) -> AppResult<()> {
    let record = TranscriptRecord {
        assistant_id: assistant_id.map(|s| s.to_string()),
        title: format!("nvoos-dictate #{} — {}", row.id, row.target_app),
        raw: row.text_raw.clone(),
        clean: row.text_clean.clone(),
        target_app: row.target_app.clone(),
        engine: row.engine.clone(),
        e2e_ms: row.e2e_ms,
        created_at: row.created_at.clone(),
    };
    client.archive_transcript(&record).await
}
