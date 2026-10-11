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
use std::time::Duration;

use windows::Win32::Foundation::{HINSTANCE, LPARAM, LRESULT, WPARAM};
use windows::Win32::System::LibraryLoader::GetModuleHandleW;
use windows::Win32::UI::Input::KeyboardAndMouse::{
    GetAsyncKeyState, GetKeyboardState, VK_ESCAPE, VK_LCONTROL, VK_LSHIFT, VK_RCONTROL, VK_RMENU,
    VK_RSHIFT,
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
static LAST_PRESS_MS: AtomicU64 = AtomicU64::new(0);
static HOOK_CALLS: AtomicU64 = AtomicU64::new(0);

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
    HOOK_CALLS.fetch_add(1, Ordering::Relaxed);
    let vk = kb.vkCode as u16;
    let ptt = PTT_VK.load(Ordering::Relaxed);
    let message = wparam.0 as u32;

    let is_down = message == WM_KEYDOWN || message == WM_SYSKEYDOWN;
    let is_up = message == WM_KEYUP || message == WM_SYSKEYUP;

    if vk == ptt {
        if is_down {
            // Arm only when the PTT key is pressed ALONE (modifiers such as
            // Shift and Ctrl tolerated): RAlt combos like Alt+F4, Alt+Tab and
            // AltGr input must keep working while the app watches the key.
            if ptt_pressed_alone(ptt) && !PTT_DOWN.swap(true, Ordering::SeqCst) {
                // Atomically timestamp the press; the hook callback must not
                // block, so no mutexes here.
                let now_ms = now_ms();
                let double_tap =
                    now_ms.saturating_sub(LAST_PRESS_MS.load(Ordering::Relaxed)) < DOUBLE_TAP_MS;
                LAST_PRESS_MS.store(now_ms, Ordering::Relaxed);
                let event = if double_tap {
                    HotkeyEvent::DoubleTap
                } else {
                    HotkeyEvent::Pressed
                };
                send(event);
            }
        } else if is_up && PTT_DOWN.swap(false, Ordering::SeqCst) {
            let shift = unsafe {
                GetAsyncKeyState(VK_LSHIFT.0 as i32) < 0 || GetAsyncKeyState(VK_RSHIFT.0 as i32) < 0
            };
            send(HotkeyEvent::Released { shift });
        }
        // Swallow the PTT key's own events while recording so the focused app
        // never sees the held modifier (prevents menu-bar activation and
        // Ctrl/Win shortcut leakage — the 2KSpeak technique). This MUST come
        // AFTER the event emission above: swallowing the key-up first would
        // eat the Released event and dictation would hang in Recording.
        if RECORDING.load(Ordering::SeqCst) {
            return LRESULT(1);
        }
    } else if vk == VK_ESCAPE.0 && is_down {
        send(HotkeyEvent::Esc);
    }

    CallNextHookEx(None, code, wparam, lparam)
}

/// True when the PTT key is the only non-modifier key held. Reading the
/// 256-byte key-state table is cheap and safe inside the hook callback.
fn ptt_pressed_alone(ptt: u16) -> bool {
    unsafe {
        let mut keys = [0u8; 256];
        if GetKeyboardState(&mut keys).is_err() {
            // Cannot inspect the state table: stay permissive.
            return true;
        }
        for (vk, &state) in keys.iter().enumerate() {
            let down = state & 0x80 != 0;
            if !down || vk as u16 == ptt {
                continue;
            }
            let key = vk as u16;
            if key != VK_LSHIFT.0
                && key != VK_RSHIFT.0
                && key != VK_LCONTROL.0
                && key != VK_RCONTROL.0
            {
                return false;
            }
        }
        true
    }
}

fn send(event: HotkeyEvent) {
    // try_lock: the hook callback must never block (the sender slot is only
    // held during listener teardown at app exit).
    if let Some(tx) = EVENT_TX
        .get()
        .and_then(|m| m.try_lock().ok().and_then(|g| g.clone()))
    {
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
                    tracing::warn!("WH_KEYBOARD_LL install failed; fallback poller will take over");
                    return;
                };
                tracing::info!(
                    ptt_vk = PTT_VK.load(Ordering::Relaxed),
                    "WH_KEYBOARD_LL hook installed"
                );
                let mut msg: MSG = MSG::default();
                let mut last_calls: u64 = 0;

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
                    let calls = HOOK_CALLS.load(Ordering::Relaxed);
                    if calls != last_calls {
                        tracing::debug!(calls, "hook callback invocations");
                        last_calls = calls;
                    }
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

#[cfg(test)]
mod tests {
    use super::*;
    use windows::Win32::UI::WindowsAndMessaging::{WM_KEYDOWN, WM_KEYUP};

    /// Regression test for the stuck-recording bug: while dictation is
    /// recording the hook swallows the PTT key's events from the focused
    /// app, but the key-up must still emit `Released` — otherwise the
    /// controller hangs in `Recording` forever.
    #[test]
    fn ptt_release_fires_despite_recording_swallow() {
        let (tx, rx) = std::sync::mpsc::channel();
        EVENT_TX.get_or_init(|| Mutex::new(None));
        *EVENT_TX.get().unwrap().lock().unwrap() = Some(tx);
        PTT_VK.store(VK_RMENU.0, Ordering::Relaxed);
        RECORDING.store(false, Ordering::SeqCst);
        PTT_DOWN.store(false, Ordering::SeqCst);

        let mut kb: KBDLLHOOKSTRUCT = unsafe { std::mem::zeroed() };
        kb.vkCode = VK_RMENU.0 as u32;

        // Key down while idle -> Pressed.
        unsafe {
            hook_proc(
                0,
                WPARAM(WM_KEYDOWN as usize),
                LPARAM(&kb as *const _ as isize),
            );
        }
        assert_eq!(
            rx.recv_timeout(Duration::from_secs(1)).unwrap(),
            HotkeyEvent::Pressed
        );

        // Controller marks recording; the hook now swallows the key so the
        // focused app never sees the held modifier.
        RECORDING.store(true, Ordering::SeqCst);

        // Key up while recording -> Released MUST still fire.
        unsafe {
            hook_proc(
                0,
                WPARAM(WM_KEYUP as usize),
                LPARAM(&kb as *const _ as isize),
            );
        }
        assert_eq!(
            rx.recv_timeout(Duration::from_secs(1)).unwrap(),
            HotkeyEvent::Released { shift: false }
        );

        // A second press (toggle mode) also fires while recording. Wait past
        // the double-tap window so it registers as a plain press.
        std::thread::sleep(Duration::from_millis(350));
        unsafe {
            hook_proc(
                0,
                WPARAM(WM_KEYDOWN as usize),
                LPARAM(&kb as *const _ as isize),
            );
        }
        assert_eq!(
            rx.recv_timeout(Duration::from_secs(1)).unwrap(),
            HotkeyEvent::Pressed
        );

        RECORDING.store(false, Ordering::SeqCst);
        *EVENT_TX.get().unwrap().lock().unwrap() = None;
    }
}
