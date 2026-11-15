//! Windows fallback PTT detection via `GetAsyncKeyState` polling.
//!
//! Rationale: Windows can silently stop delivering `WH_KEYBOARD_LL` events
//! while a Chromium window (Chrome, Edge, VSCode, WebView2) has focus — the
//! hook thread keeps ticking, but no key events arrive. The poller watches
//! the hook's **event-driven** liveness (last delivered key event) and only
//! activates when delivery stops, so the two sources never double-fire:
//! re-checked at emission time, a just-delivered hook event suppresses the
//! poller for that same press. The poller never swallows keys — that stays
//! in the hook — so in the degraded mode the focused app may also see the
//! PTT key; PTT still works, which is the point.

use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::mpsc::Sender;
use std::sync::Arc;
use std::time::Duration;

use windows::Win32::UI::Input::KeyboardAndMouse::{
    GetAsyncKeyState, VK_ESCAPE, VK_LSHIFT, VK_RSHIFT,
};

use super::win_hook::{last_event_age_ms, vk_for_name};
use super::{HotkeyEvent, HotkeyListener};
use crate::config::HotkeyConfig;
use crate::error::AppResult;

/// Hook delivery staleness threshold: fallback takes over after 1.5 s
/// without a DELIVERED key event (measured in the hook callback itself, not
/// the loop heartbeat — the loop keeps ticking even when LL delivery stops).
const HOOK_STALE_MS: u64 = 1500;
const POLL_INTERVAL_MS: u64 = 50;

fn key_down(vk: u16) -> bool {
    unsafe { (GetAsyncKeyState(vk as i32) as u16 & 0x8000u16) != 0 }
}

/// Polling fallback listener (Windows).
pub struct WinFallbackListener {
    stop: Arc<AtomicBool>,
    thread: Option<std::thread::JoinHandle<()>>,
    ptt_vk: u16,
}

impl Default for WinFallbackListener {
    fn default() -> Self {
        Self {
            stop: Arc::new(AtomicBool::new(false)),
            thread: None,
            ptt_vk: 0,
        }
    }
}

impl WinFallbackListener {
    pub fn new() -> Self {
        Self::default()
    }
}

impl HotkeyListener for WinFallbackListener {
    fn start(&mut self, config: &HotkeyConfig, tx: Sender<HotkeyEvent>) -> AppResult<()> {
        self.ptt_vk = vk_for_name(&config.ptt_key)
            .ok_or_else(|| crate::error::AppError::Hotkey("unsupported PTT key".into()))?;
        self.stop.store(false, Ordering::SeqCst);
        let ptt = self.ptt_vk;
        let stop = self.stop.clone();

        let handle = std::thread::Builder::new()
            .name("nvoos-fallback".into())
            .spawn(move || {
                let mut was_down = false;
                while !stop.load(Ordering::SeqCst) {
                    std::thread::sleep(Duration::from_millis(POLL_INTERVAL_MS));
                    let down = key_down(ptt);
                    if down && !was_down {
                        // Re-check delivery at emission time: if the hook just
                        // delivered this very press, its callback refreshed
                        // the event liveness and we must stay quiet (the hook
                        // also swallows the key, so it owns the whole pair).
                        if last_event_age_ms() < HOOK_STALE_MS {
                            continue;
                        }
                        was_down = true;
                        tracing::debug!("fallback poller: PTT pressed");
                        let _ = tx.send(HotkeyEvent::Pressed);
                    } else if !down && was_down {
                        was_down = false;
                        let shift = key_down(VK_LSHIFT.0) || key_down(VK_RSHIFT.0);
                        tracing::debug!("fallback poller: PTT released");
                        let _ = tx.send(HotkeyEvent::Released { shift });
                    }
                    if key_down(VK_ESCAPE.0) {
                        let _ = tx.send(HotkeyEvent::Esc);
                    }
                }
            })
            .map_err(|e| crate::error::AppError::Hotkey(e.to_string()))?;
        self.thread = Some(handle);
        Ok(())
    }

    fn stop(&mut self) {
        self.stop.store(true, Ordering::SeqCst);
        if let Some(handle) = self.thread.take() {
            let _ = handle.join();
        }
    }
}

// The fallback only polls `GetAsyncKeyState` — it never swallows keys. The
// symmetric down+up swallow lives entirely in the hook module, so there is
// nothing for this listener to mirror.
