//! Paste backend trait.

use crate::error::AppResult;

/// What one paste attempt actually did.
///
/// Surfaced on the Diagnostics page. It never carries clipboard contents —
/// only the snapshot *kind* and whether the restore ran.
#[derive(Debug, Clone, Default)]
pub struct PasteOutcome {
    /// Backend id (`clipboard_keystroke`, `macos_cgevent`).
    pub backend: &'static str,
    /// Snapshot kind taken before the transcript write (`empty`, `text`,
    /// `image`, `files`, `partial`).
    pub snapshot_kind: &'static str,
    /// Whether the previous clipboard content was put back.
    pub restored: bool,
    /// Why the restore did not run, when it did not.
    pub restore_skipped: Option<&'static str>,
    /// Formats that were present but could not be copied as bytes (GDI handles
    /// and similar). Non-zero means the restore is not fully faithful.
    pub partial_formats: usize,
    /// Total time spent in the paste path, including the settle delay.
    pub elapsed_ms: u64,
}

/// Paste `text` into the currently focused application.
pub trait PasteBackend: Send + Sync {
    /// Human-readable backend id for diagnostics.
    fn id(&self) -> &'static str;

    /// Paste `text` at the focused caret and report what happened.
    fn paste(&self, text: &str) -> AppResult<PasteOutcome>;
}
