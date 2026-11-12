//! macOS paste backend: clipboard + CGEventPost Cmd+V (P5 port, phase M3).
//!
//! `enigo` 0.6 has a macOS path but the crate's maintenance record is
//! uneven; this backend posts the keystroke through CoreGraphics directly.
//! Requires the Accessibility permission (CGEventPost is gated by it) —
//! without it `paste()` returns a readable error and the History view remains
//! the copy source of truth.
//!
//! The snapshot/write/restore flow is shared with the Windows backend in
//! [`crate::paste::clipboard::run_paste`]; this module supplies only the
//! Cmd+V injection and the Accessibility gate.

use core_graphics::event::{CGEvent, CGEventFlags, CGEventTapLocation};
use core_graphics::event_source::{CGEventSource, CGEventSourceStateID};

use crate::config::PasteConfig;
use crate::error::{AppError, AppResult};
use crate::paste::backend::{PasteBackend, PasteOutcome};
use crate::paste::clipboard;

/// kVK_ANSI_V — the V key.
const KVK_ANSI_V: u16 = 9;

pub struct MacCgEventBackend {
    /// Restore the previous clipboard after pasting (default: true).
    pub restore_clipboard: bool,
    /// Settle time before restoring, so a slow target has read the text.
    pub restore_delay_ms: u64,
}

impl Default for MacCgEventBackend {
    fn default() -> Self {
        Self {
            restore_clipboard: true,
            restore_delay_ms: 250,
        }
    }
}

impl MacCgEventBackend {
    pub fn from_config(config: &PasteConfig) -> Self {
        Self {
            restore_clipboard: config.restore_clipboard,
            restore_delay_ms: config.restore_delay_ms,
        }
    }
}

impl PasteBackend for MacCgEventBackend {
    fn id(&self) -> &'static str {
        "macos_cgevent"
    }

    fn paste(&self, text: &str) -> AppResult<PasteOutcome> {
        if !macos_accessibility_client::accessibility::application_is_trusted() {
            return Err(AppError::Paste(
                "Accessibility permission is required to paste into other apps. Grant it in System Settings → Privacy & Security → Accessibility, or copy from History.".into(),
            ));
        }

        clipboard::run_paste(
            text,
            self.id(),
            self.restore_clipboard,
            self.restore_delay_ms,
            inject_cmd_v,
        )
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
