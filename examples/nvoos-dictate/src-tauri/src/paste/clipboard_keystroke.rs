//! Clipboard + Ctrl+V backend (Windows/Linux via arboard + enigo; macOS has
//! its own CGEvent backend).
//!
//! The behaviour lives in [`crate::paste::clipboard::run_paste`] so every
//! platform shares one snapshot/write/inject/restore flow. This module only
//! supplies the Ctrl+V injection.
//!
//! The clipboard is restored even on keystroke failure so the user's clipboard
//! survives a broken paste (dictto/murmur convention).

#[cfg(not(windows))]
use enigo::{Direction, Enigo, Key, Keyboard, Settings};

use crate::config::PasteConfig;
#[cfg(not(windows))]
use crate::error::AppError;
use crate::error::AppResult;
use crate::paste::backend::{PasteBackend, PasteOutcome};
use crate::paste::clipboard;

pub struct ClipboardKeystrokeBackend {
    /// Restore the previous clipboard after pasting (default: true).
    pub restore_clipboard: bool,
    /// Settle time before restoring, so a slow target has read the text.
    pub restore_delay_ms: u64,
}

impl Default for ClipboardKeystrokeBackend {
    fn default() -> Self {
        Self {
            restore_clipboard: true,
            restore_delay_ms: 250,
        }
    }
}

impl ClipboardKeystrokeBackend {
    pub fn from_config(config: &PasteConfig) -> Self {
        Self {
            restore_clipboard: config.restore_clipboard,
            restore_delay_ms: config.restore_delay_ms,
        }
    }
}

impl PasteBackend for ClipboardKeystrokeBackend {
    fn id(&self) -> &'static str {
        "clipboard_keystroke"
    }

    fn paste(&self, text: &str) -> AppResult<PasteOutcome> {
        clipboard::run_paste(
            text,
            self.id(),
            self.restore_clipboard,
            self.restore_delay_ms,
            inject_ctrl_v,
        )
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

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn default_matches_the_documented_contract() {
        let backend = ClipboardKeystrokeBackend::default();
        assert!(backend.restore_clipboard);
        assert_eq!(backend.restore_delay_ms, 250);
        assert_eq!(backend.id(), "clipboard_keystroke");
    }

    #[test]
    fn config_overrides_are_honoured() {
        let config = PasteConfig {
            restore_clipboard: false,
            restore_delay_ms: 600,
        };
        let backend = ClipboardKeystrokeBackend::from_config(&config);
        assert!(!backend.restore_clipboard);
        assert_eq!(backend.restore_delay_ms, 600);
    }
}
