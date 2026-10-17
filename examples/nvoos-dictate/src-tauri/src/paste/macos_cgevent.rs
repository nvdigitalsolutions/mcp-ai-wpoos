//! macOS paste backend: arboard clipboard + CGEventPost Cmd+V (P5 port,
//! phase M3).
//!
//! `enigo` 0.6 has a macOS path but the crate's maintenance record is
//! uneven; this backend is the drop-in `PasteBackend` fallback that posts
//! keystrokes through CoreGraphics directly. Requires the Accessibility
//! permission (CGEventPost is gated by it) — without it `paste()` returns
//! a readable error and the History view remains the copy source of truth.
//!
//! Contract (identical to `ClipboardKeystrokeBackend`):
//! 1. Snapshot the clipboard text. 2. Set the transcript. 3. Post Cmd+V.
//! 4. Restore after a settle delay — even on keystroke failure.

use std::time::Duration;

use core_graphics::event::{CGEvent, CGEventFlags, CGEventTapLocation};
use core_graphics::event_source::{CGEventSource, CGEventSourceStateID};

use crate::error::{AppError, AppResult};
use crate::paste::backend::PasteBackend;

/// kVK_ANSI_V — the V key.
const KVK_ANSI_V: u16 = 9;

pub struct MacCgEventBackend {
    /// Restore the previous clipboard after pasting (default: true).
    pub restore_clipboard: bool,
}

impl Default for MacCgEventBackend {
    fn default() -> Self {
        Self {
            restore_clipboard: true,
        }
    }
}

impl PasteBackend for MacCgEventBackend {
    fn id(&self) -> &'static str {
        "macos_cgevent"
    }

    fn paste(&self, text: &str) -> AppResult<()> {
        if !macos_accessibility_client::accessibility::application_is_trusted() {
            return Err(AppError::Paste(
                "Accessibility permission is required to paste into other apps. Grant it in System Settings → Privacy & Security → Accessibility, or copy from History.".into(),
            ));
        }

        let mut clipboard = arboard::Clipboard::new()?;
        let previous = if self.restore_clipboard {
            clipboard.get_text().ok()
        } else {
            None
        };

        clipboard.set_text(text.to_string())?;
        let result = inject_cmd_v();

        // Settle time so the target app's paste handler finishes reading the
        // clipboard before we restore it (same timing as the Windows path).
        std::thread::sleep(Duration::from_millis(180));
        if let Some(prev) = previous {
            let _ = clipboard.set_text(prev);
        }

        result
    }
}

fn inject_cmd_v() -> AppResult<()> {
    let source = CGEventSource::new(CGEventSourceStateID::HIDSystemState)
        .map_err(|()| AppError::Paste("CGEventSource creation failed".into()))?;

    let key_down = CGEvent::new_keyboard_event(source.clone(), KVK_ANSI_V, true)
        .map_err(|()| AppError::Paste("key-down event creation failed".into()))?;
    key_down.set_flags(CGEventFlags::CGEventFlagCommand);
    key_down.post(CGEventTapLocation::HID);

    let key_up = CGEvent::new_keyboard_event(source, KVK_ANSI_V, false)
        .map_err(|()| AppError::Paste("key-up event creation failed".into()))?;
    key_up.set_flags(CGEventFlags::CGEventFlagCommand);
    key_up.post(CGEventTapLocation::HID);

    Ok(())
}
