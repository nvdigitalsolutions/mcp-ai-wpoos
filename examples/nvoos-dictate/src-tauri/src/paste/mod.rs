//! Paste backends: clipboard-set + keystroke injection with save/restore.
//!
//! `arboard` (clipboard) + `enigo` (keystrokes) is the fala-proven pair; both
//! sit behind the `PasteBackend` trait so platform-specific fallbacks can be
//! dropped in without touching the controller. macOS uses the CoreGraphics
//! backend (`macos_cgevent`) because enigo's macOS path has maintenance gaps
//! and CGEventPost is a direct, dependency-light call.
//!
//! Clipboard fidelity (Proposal 144 §4.2) lives in [`clipboard`]: it snapshots
//! every restorable format, excludes the transient transcript from clipboard
//! history on Windows, and refuses to restore over a concurrent user copy.

pub mod backend;
pub mod clipboard;
pub mod clipboard_keystroke;
#[cfg(target_os = "macos")]
pub mod macos_cgevent;

pub use backend::{PasteBackend, PasteOutcome};
pub use clipboard::ClipSnapshot;
pub use clipboard_keystroke::ClipboardKeystrokeBackend;

use std::sync::Arc;

use crate::config::PasteConfig;

/// The platform-appropriate paste backend for `config`.
pub fn platform_backend(config: &PasteConfig) -> Arc<dyn PasteBackend> {
    #[cfg(target_os = "macos")]
    {
        Arc::new(macos_cgevent::MacCgEventBackend::from_config(config))
    }
    #[cfg(not(target_os = "macos"))]
    {
        Arc::new(ClipboardKeystrokeBackend::from_config(config))
    }
}
