//! Paste backend trait.

use crate::error::AppResult;

/// Paste `text` into the currently focused application.
pub trait PasteBackend: Send + Sync {
    /// Human-readable backend id for diagnostics.
    fn id(&self) -> &'static str;

    /// Paste `text` at the focused caret.
    fn paste(&self, text: &str) -> AppResult<()>;
}
