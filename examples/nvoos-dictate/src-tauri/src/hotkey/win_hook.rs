//! Windows `WH_KEYBOARD_LL` low-level keyboard hook.
//!
//! Runs on a dedicated OS thread with a message loop. This is the pattern
//! every global-hotkey app on Windows uses (parley#462, Handy, 2KSpeak);
//! without a message loop the hook silently never fires.
//!
//! Heartbeat discipline: the hook thread updates a timestamp every loop tick
//! (50 ms), and the fallback `GetAsyncKeyState` poller only takes over when
//! that heartbeat goes stale (Windows can silently stop delivering
//! `WH_KEYBOARD_LL` events while a Chromium window has focus).

use std::sync::atomic::{AtomicBool, AtomicU16, AtomicU64, Ordering};
use std::sync::mpsc::Sender;
use std::sync::{Mutex, OnceLock};
use std::time::{Duration, Instant};

use windows::Win32::Foundation::{HINSTANCE, LPARAM, LRESULT, WPARAM};
use windows::Win32::System::LibraryLoader::GetModuleHandleW;
use windows::Win32::UI::Input::KeyboardAndMouse::{
    GetAsyncKeyState, VK_ESCAPE, VK_LSHIFT, VK_RCONTROL, VK_RMENU, VK_RSHIFT,
};
use windows::Win32::UI::WindowsAndMessaging::{
    CallNextHookEx, DispatchMessageW, PeekMessageW, PostThreadMessageW, SetWindowsHookExW,
    TranslateMessage, UnhookWindowsHookEx, KBDLLHOOKSTRUCT, MSG, PM_REMOVE, WH_KEYBOARD_LL,
    WM_KEYDOWN, WM_KEYUP, WM_QUIT, WM_SYSKEYDOWN, WM_SYSKEYUP,
};

use super::{HotkeyEvent, HotkeyListener};
use crate::config::HotkeyConfig;
use crate::error::{AppError, AppResult};

/// Shared state between the hook callback (which runs on arbitrary threads)
/// and the hook thread. Low-level hooks are process-global and the callback
/// signature cannot carry user data, hence statics.
static EVENT_TX: OnceLock<Mutex<Option<Sender<HotkeyEvent>>>> = OnceLock::new();
static PTT_VK: AtomicU16 = AtomicU16::new(VK_RMENU.0);
static RECORDING: AtomicBool = AtomicBool::new(false);
static HEARTBEAT_MS: AtomicU64 = AtomicU64::new(0);
static PTT_DOWN: AtomicBool = AtomicBool::new(false);
static LAST_PRESS: Mutex<Option<Instant>> = Mutex::new(None);

/// Double-tap window (ms) for hands-free toggle detection.
const DOUBLE_TAP_MS: u64 = 300;

fn now_ms() -> u64 {
    std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|d| d.as_millis() as u64)
        .unwrap_or(0)
}

/// Map a configured key name to a virtual-key code.
pub fn vk_for_name(name: &str) -> Option<u16> {
    match name.to_ascii_lowercase().as_str() {
        "ralt" | "rightalt" | "altgr" => Some(VK_RMENU.0),
        "rctrl" | "rightctrl" | "rightcontrol" => Some(VK_RCONTROL.0),
        _ => None,
    }
}

/// Milliseconds since the hook thread's last heartbeat. `u64::MAX` before
/// the thread has ever ticked.
pub fn heartbeat_age_ms() -> u64 {
    let last = HEARTBEAT_MS.load(Ordering::Relaxed);
    if last == 0 {
        return u64::MAX;
    }
    now_ms().saturating_sub(last)
}

/// Whether the hook thread is alive, from the fallback's perspective.
pub fn hook_healthy(max_age_ms: u64) -> bool {
    heartbeat_age_ms() < max_age_ms
}

/// Mark the recording state so the hook swallows the PTT key's own events
/// only while dictating (AltGr layouts keep working the rest of the time).
pub fn set_recording(recording: bool) {
    RECORDING.store(recording, Ordering::SeqCst);
}

unsafe extern "system" fn hook_proc(code: i32, wparam: WPARAM, lparam: LPARAM) -> LRESULT {
    if code < 0 {
        return CallNextHookEx(None, code, wparam, lparam);
    }
    let kb = unsafe { &*(lparam.0 as *const KBDLLHOOKSTRUCT) };
    let vk = kb.vkCode as u16;
    let ptt = PTT_VK.load(Ordering::Relaxed);
    let message = wparam.0 as u32;

    let is_down = message == WM_KEYDOWN || message == WM_SYSKEYDOWN;
    let is_up = message == WM_KEYUP || message == WM_SYSKEYUP;

    // Swallow the PTT key's own events while recording so the focused app
    // never sees the held modifier (prevents menu-bar activation and Ctrl/Win
    // shortcut leakage — the 2KSpeak technique).
    if vk == ptt && RECORDING.load(Ordering::SeqCst) {
        return LRESULT(1);
    }

    if vk == ptt && is_down && !PTT_DOWN.swap(true, Ordering::SeqCst) {
        let mut last = LAST_PRESS.lock().unwrap();
        let now = Instant::now();
        let double_tap = last
            .map(|t| now.duration_since(t) < Duration::from_millis(DOUBLE_TAP_MS))
            .unwrap_or(false);
        *last = Some(now);
        let event = if double_tap {
            HotkeyEvent::DoubleTap
        } else {
            HotkeyEvent::Pressed
        };
        send(event);
    } else if vk == ptt && is_up && PTT_DOWN.swap(false, Ordering::SeqCst) {
        let shift = unsafe {
            GetAsyncKeyState(VK_LSHIFT.0 as i32) < 0 || GetAsyncKeyState(VK_RSHIFT.0 as i32) < 0
        };
        send(HotkeyEvent::Released { shift });
    } else if vk == VK_ESCAPE.0 && is_down {
        send(HotkeyEvent::Esc);
    }

    CallNextHookEx(None, code, wparam, lparam)
}

fn send(event: HotkeyEvent) {
    if let Some(tx) = EVENT_TX.get().and_then(|m| m.lock().unwrap().clone()) {
        let _ = tx.send(event);
    }
}

/// Windows hook listener.
#[derive(Default)]
pub struct WinHookListener {
    stop_tx: Option<std::sync::mpsc::Sender<()>>,
    thread: Option<std::thread::JoinHandle<()>>,
}

impl HotkeyListener for WinHookListener {
    fn start(&mut self, config: &HotkeyConfig, tx: Sender<HotkeyEvent>) -> AppResult<()> {
        let vk = vk_for_name(&config.ptt_key).ok_or_else(|| {
            AppError::Hotkey(format!(
                "unsupported PTT key '{}' (use RAlt or RCtrl)",
                config.ptt_key
            ))
        })?;
        PTT_VK.store(vk, Ordering::Relaxed);
        EVENT_TX
            .set(Mutex::new(Some(tx)))
            .map_err(|_| AppError::Hotkey("hotkey listener already started".into()))?;

        let (stop_tx, stop_rx) = std::sync::mpsc::channel::<()>();
        self.stop_tx = Some(stop_tx);

        let handle = std::thread::Builder::new()
            .name("nvoos-hook".into())
            .spawn(move || {
                // Low-level keyboard hooks require a thread with a message
                // loop. The handle lives until UnhookWindowsHookEx at exit.
                // WH_KEYBOARD_LL allows a NULL hmod (our hook is in-process).
                let hinst = unsafe { GetModuleHandleW(None) }
                    .map(|hmod| HINSTANCE(hmod.0))
                    .unwrap_or_else(|_| HINSTANCE(std::ptr::null_mut()));
                let hook = unsafe { SetWindowsHookExW(WH_KEYBOARD_LL, Some(hook_proc), hinst, 0) };
                let Ok(hook) = hook else {
                    // Can't install the hook: leave heartbeat at 0 so the
                    // fallback poller takes over immediately.
                    return;
                };
                let mut msg: MSG = MSG::default();

                loop {
                    if stop_rx.try_recv().is_ok() {
                        break;
                    }
                    // Drain queued messages without blocking, then tick the
                    // heartbeat, then sleep. PeekMessageW keeps the stop
                    // channel responsive and the heartbeat fresh even when no
                    // keyboard traffic arrives.
                    while unsafe { PeekMessageW(&mut msg, None, 0, 0, PM_REMOVE) }.as_bool() {
                        if msg.message == WM_QUIT {
                            break;
                        }
                        unsafe {
                            let _ = TranslateMessage(&msg);
                            DispatchMessageW(&msg);
                        }
                    }
                    if msg.message == WM_QUIT {
                        break;
                    }
                    HEARTBEAT_MS.store(now_ms(), Ordering::Relaxed);
                    std::thread::sleep(Duration::from_millis(50));
                }

                unsafe {
                    let _ = UnhookWindowsHookEx(hook);
                }
            })
            .map_err(|e| AppError::Hotkey(e.to_string()))?;

        self.thread = Some(handle);
        Ok(())
    }

    fn stop(&mut self) {
        if let Some(tx) = self.stop_tx.take() {
            let _ = tx.send(());
        }
        if let Some(handle) = self.thread.take() {
            let _ = handle.join();
        }
        if let Some(slot) = EVENT_TX.get() {
            *slot.lock().unwrap() = None;
        }
    }
}

/// Windows-specific helper to post WM_QUIT to the hook thread (unused while
/// the stop channel is the shutdown path; kept for the documented pattern).
#[allow(dead_code)]
fn _post_quit(thread_id: u32) {
    unsafe {
        let _ = PostThreadMessageW(thread_id, WM_QUIT, None, None);
    }
}
