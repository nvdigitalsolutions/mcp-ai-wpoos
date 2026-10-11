//! Global push-to-talk hotkey.
//!
//! Design (per implementation-plan research):
//!
//! - **Windows:** a `WH_KEYBOARD_LL` low-level keyboard hook running on its
//!   own OS thread with a `GetMessageW` message loop (a hook without a
//!   message loop silently never fires), emitting Pressed/Released/Esc with
//!   hold semantics. While a dictation is recording, the PTT key's own
//!   down/up events are swallowed so the focused app never sees the modifier
//!   (prevents Windows menu-bar activation and Ctrl/Win shortcut leakage).
//!   Outside dictation the key passes through untouched, so AltGr layouts
//!   keep working.
//! - **Fallback (Windows):** Windows can silently stop delivering
//!   `WH_KEYBOARD_LL` events while a Chromium window (Chrome, VSCode,
//!   WebView2) has focus. A `GetAsyncKeyState` poller runs alongside the hook
//!   and takes over only when the hook thread's heartbeat goes stale. Both
//!   sources feed the same channel; the controller never cares which fired.
//! - **Other platforms (Linux/macOS):** `tauri-plugin-global-shortcut`
//!   toggle semantics — first activation = press, second = release. Esc
//!   cancels. Wayland restrictions documented in README.
//!
//! Precedents: parley#462 (modifier PTT via low-level hook), the dev.to
//! Chromium-focus hook-delivery report, 2KSpeak (held-modifier suppression).

use std::sync::mpsc::Sender;

use crate::config::HotkeyConfig;
use crate::error::AppResult;

#[cfg(windows)]
pub mod fallback;
#[cfg(windows)]
pub mod win_hook;

/// Unified hotkey events consumed by the dictation controller.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum HotkeyEvent {
    /// PTT key went down (or first toggle activation on non-Windows).
    Pressed,
    /// PTT key was released; `shift` = Shift was held on release
    /// (raw-passthrough request: skip cleanup).
    Released { shift: bool },
    /// Double-tap detected: hands-free toggle (Windows).
    DoubleTap,
    /// Escape pressed while recording: cancel this dictation.
    Esc,
}

/// Listener lifecycle. Implementations own their OS threads and forward
/// events to the provided channel until `stop()` is called.
pub trait HotkeyListener: Send + Sync {
    /// Start listening. May spawn threads; must return quickly.
    fn start(&mut self, config: &HotkeyConfig, tx: Sender<HotkeyEvent>) -> AppResult<()>;
    /// Stop listening and join threads.
    fn stop(&mut self);
}

/// Platform-specific listener factory.
pub fn platform_listener() -> Box<dyn HotkeyListener> {
    #[cfg(windows)]
    {
        Box::new(win_hook::WinHookListener::default())
    }
    #[cfg(not(windows))]
    {
        Box::new(plugin::PluginToggleListener::default())
    }
}

#[cfg(not(windows))]
mod plugin {
    use super::*;

    /// Non-Windows toggle listener wired to tauri-plugin-global-shortcut.
    /// Registration happens in `dictation::DictationController::start` (the
    /// plugin needs the Tauri app handle); this struct just bridges events.
    #[derive(Default)]
    pub struct PluginToggleListener;

    impl HotkeyListener for PluginToggleListener {
        fn start(&mut self, _config: &HotkeyConfig, _tx: Sender<HotkeyEvent>) -> AppResult<()> {
            // Registration is performed by the controller via the Tauri
            // handle; nothing to do at this layer.
            Ok(())
        }

        fn stop(&mut self) {}
    }
}
