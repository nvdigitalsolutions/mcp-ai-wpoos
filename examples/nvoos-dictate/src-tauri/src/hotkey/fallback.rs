//! Windows fallback PTT detection via `GetAsyncKeyState` polling.
//!
//! Rationale: Windows can silently stop delivering `WH_KEYBOARD_LL` events
//! while a Chromium window (Chrome, Edge, VSCode, WebView2) has focus. The
//! hook thread maintains a heartbeat; this poller watches it and only
//! activates when the hook goes stale, so the two sources never double-fire.

use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::mpsc::Sender;
use std::sync::Arc;
use std::time::Duration;

use windows::Win32::UI::Input::KeyboardAndMouse::{
    GetAsyncKeyState, VK_ESCAPE, VK_LSHIFT, VK_RSHIFT,
};

use super::win_hook::{heartbeat_age_ms, set_recording, vk_for_name};
use super::{HotkeyEvent, HotkeyListener};
use crate::config::HotkeyConfig;
use crate::error::AppResult;

/// Hook staleness threshold: fallback takes over after 1.5 s without a
/// heartbeat tick (the hook ticks every 50 ms when healthy).
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
                    let hook_alive = heartbeat_age_ms() < HOOK_STALE_MS;
                    if hook_alive {
                        continue; // primary hook is healthy; stay quiet
                    }
                    let down = key_down(ptt);
                    if down && !was_down {
                        was_down = true;
                        let _ = tx.send(HotkeyEvent::Pressed);
                    } else if !down && was_down {
                        was_down = false;
                        let shift = key_down(VK_LSHIFT.0) || key_down(VK_RSHIFT.0);
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

// The fallback never swallows keys (it only polls), so the shared recording
// flag is unused here but kept in the hook module. Reference it to document
// the asymmetry.
#[allow(dead_code)]
fn _fallback_does_not_swallow() {
    set_recording(false);
}
