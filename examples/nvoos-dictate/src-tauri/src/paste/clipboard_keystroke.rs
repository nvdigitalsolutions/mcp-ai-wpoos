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

#[cfg(not(windows))]
use enigo::{Direction, Enigo, Key, Keyboard, Settings};

#[cfg(not(windows))]
use crate::error::AppError;
use crate::error::AppResult;
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

/// Inject the paste keystroke into the focused window.
///
/// Windows: a REAL Ctrl+V (VK_CONTROL + VK_V via keybd_event). WebView2 and
/// Chromium strictly match the native Ctrl+V accelerator, so a unicode-
/// character injection (`enigo::Key::Unicode('v')` with Ctrl held) is ignored
/// by Chromium-based surfaces — native edit controls (Notepad etc.) accept it,
/// which is why dictation worked everywhere except the NV oOS console window.
/// Other platforms keep the enigo path (macOS uses the CGEvent backend).
#[cfg(windows)]
fn inject_ctrl_v() -> AppResult<()> {
    use windows::Win32::UI::Input::KeyboardAndMouse::{keybd_event, KEYEVENTF_KEYUP, VK_CONTROL};

    // 'V' has no named VIRTUAL_KEY constant in the windows crate; 0x56 is VK_V.
    const VK_V: u8 = 0x56;

    unsafe {
        keybd_event(VK_CONTROL.0 as u8, 0, Default::default(), 0);
        keybd_event(VK_V, 0, Default::default(), 0);
        keybd_event(VK_V, 0, KEYEVENTF_KEYUP, 0);
        keybd_event(VK_CONTROL.0 as u8, 0, KEYEVENTF_KEYUP, 0);
    }
    Ok(())
}

#[cfg(not(windows))]
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
