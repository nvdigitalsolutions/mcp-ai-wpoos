//! Paste backends: clipboard-set + keystroke injection with save/restore.
//!
//! `arboard` (clipboard) + `enigo` (keystrokes) is the fala-proven pair; both
//! sit behind the `PasteBackend` trait so platform-specific fallbacks can be
//! dropped in without touching the controller. macOS uses the CoreGraphics
//! backend (`macos_cgevent`) because enigo's macOS path has maintenance gaps
//! and CGEventPost is a direct, dependency-light call.

pub mod backend;
pub mod clipboard_keystroke;
#[cfg(target_os = "macos")]
pub mod macos_cgevent;

pub use backend::PasteBackend;
pub use clipboard_keystroke::ClipboardKeystrokeBackend;

use std::sync::Arc;

/// The platform-appropriate paste backend.
pub fn platform_backend() -> Arc<dyn PasteBackend> {
    #[cfg(target_os = "macos")]
    {
        Arc::new(macos_cgevent::MacCgEventBackend::default())
    }
    #[cfg(not(target_os = "macos"))]
    {
        Arc::new(ClipboardKeystrokeBackend::default())
    }
}
