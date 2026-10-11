//! Clipboard + Ctrl+V backend (Windows/macOS/Linux via arboard + enigo).
//!
//! Contract:
//! 1. Snapshot the existing clipboard (text) before touching it.
//! 2. Set the cleaned transcript.
//! 3. Inject Ctrl+V into the focused app.
//! 4. Restore the previous clipboard content after a short settle delay.
//!
//! The clipboard is restored even on keystroke failure so the user's
//! clipboard history survives a broken paste (dictto/murmur convention).

use std::time::Duration;

use enigo::{Direction, Enigo, Key, Keyboard, Settings};

use crate::error::{AppError, AppResult};
use crate::paste::backend::PasteBackend;

pub struct ClipboardKeystrokeBackend {
    /// Restore the previous clipboard after pasting (default: true).
    pub restore_clipboard: bool,
}

impl Default for ClipboardKeystrokeBackend {
    fn default() -> Self {
        Self {
            restore_clipboard: true,
        }
    }
}

impl PasteBackend for ClipboardKeystrokeBackend {
    fn id(&self) -> &'static str {
        "clipboard_keystroke"
    }

    fn paste(&self, text: &str) -> AppResult<()> {
        let mut clipboard = arboard::Clipboard::new()?;
        let previous = if self.restore_clipboard {
            clipboard.get_text().ok()
        } else {
            None
        };

        clipboard.set_text(text.to_string())?;

        let result = inject_ctrl_v();

        // Settle time so the target app's paste handler finishes reading the
        // clipboard before we restore it.
        std::thread::sleep(Duration::from_millis(180));
        if let Some(prev) = previous {
            let _ = clipboard.set_text(prev);
        }

        result
    }
}

fn inject_ctrl_v() -> AppResult<()> {
    let mut enigo = Enigo::new(&Settings::default())
        .map_err(|e| AppError::Paste(format!("input backend init failed: {e}")))?;
    enigo
        .key(Key::Control, Direction::Press)
        .map_err(|e| AppError::Paste(e.to_string()))?;
    enigo
        .key(Key::Unicode('v'), Direction::Click)
        .map_err(|e| AppError::Paste(e.to_string()))?;
    enigo
        .key(Key::Control, Direction::Release)
        .map_err(|e| AppError::Paste(e.to_string()))?;
    Ok(())
}
