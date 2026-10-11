//! Paste backends: clipboard-set + keystroke injection with save/restore.
//!
//! `arboard` (clipboard) + `enigo` (keystrokes) is the fala-proven pair; both
//! sit behind the `PasteBackend` trait so a Windows `SendInput` fallback can
//! be dropped in without touching the controller.

pub mod backend;
pub mod clipboard_keystroke;

pub use backend::PasteBackend;
pub use clipboard_keystroke::ClipboardKeystrokeBackend;
