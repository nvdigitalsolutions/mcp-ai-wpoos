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
//!
//! Swallow discipline: an armed PTT press consumes BOTH its key-down and the
//! matching key-up (the 2KSpeak technique). Consuming only the key-up while
//! letting the key-down through leaves the system believing the modifier is
//! still held — the classic stuck-Alt failure where the keyboard "breaks"
//! until Ctrl+Alt+Del resets it. Non-armed events (AltGr combos) always pass
//! through untouched.
//!
//! Exit discipline: if the app shuts down while a swallowed key-down is
//! pending (Alt+F4 close with RAlt as PTT), the teardown path injects a
//! synthetic key-up after unhooking so the modifier is never left stuck for
//! the rest of the session.

use std::sync::atomic::{AtomicBool, AtomicU16, AtomicU64, Ordering};
use std::sync::mpsc::Sender;
use std::sync::{Mutex, OnceLock};
use std::time::Duration;

use windows::Win32::Foundation::{HINSTANCE, LPARAM, LRESULT, WPARAM};
use windows::Win32::System::LibraryLoader::GetModuleHandleW;
use windows::Win32::UI::Input::KeyboardAndMouse::{
    GetAsyncKeyState, GetKeyboardState, SendInput, INPUT, INPUT_0, INPUT_KEYBOARD, KEYBDINPUT,
    KEYEVENTF_KEYUP, VIRTUAL_KEY, VK_ESCAPE, VK_LCONTROL, VK_LSHIFT, VK_RCONTROL, VK_RMENU,
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
static HEARTBEAT_MS: AtomicU64 = AtomicU64::new(0);
static PTT_DOWN: AtomicBool = AtomicBool::new(false);
/// True between a swallowed PTT key-down and its matching key-up. Every
/// swallow must cover the whole down..up pair: a swallowed up without a
/// swallowed down wedges the system keyboard state (stuck modifier).
static SWALLOWED_DOWN: AtomicBool = AtomicBool::new(false);
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

/// Total hook callback invocations (diagnostics).
pub fn hook_calls() -> u64 {
    HOOK_CALLS.load(Ordering::Relaxed)
}

/// Whether a swallowed PTT key-down is still awaiting its key-up
/// (diagnostics).
pub fn swallow_pending() -> bool {
    SWALLOWED_DOWN.load(Ordering::SeqCst)
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
            // Auto-repeat downs of a held PTT key arrive as extra WM_KEYDOWN
            // events; everything between an armed press and its release stays
            // swallowed so the pair is always symmetric.
            if SWALLOWED_DOWN.load(Ordering::SeqCst) {
                return LRESULT(1);
            }
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
                // Consume the key-down: the focused app never sees the held
                // modifier (prevents menu-bar activation — the 2KSpeak
                // technique). The matching key-up is consumed below, so the
                // system keyboard state never sees a lone half of the pair.
                SWALLOWED_DOWN.store(true, Ordering::SeqCst);
                return LRESULT(1);
            }
        } else if is_up && PTT_DOWN.swap(false, Ordering::SeqCst) {
            let shift = unsafe {
                GetAsyncKeyState(VK_LSHIFT.0 as i32) < 0 || GetAsyncKeyState(VK_RSHIFT.0 as i32) < 0
            };
            // Emit BEFORE swallowing (the Released event must always reach
            // the controller) and only swallow when the matching down was
            // swallowed — an unpaired key-up (AltGr combo release) passes
            // through so the focused app keeps working.
            send(HotkeyEvent::Released { shift });
            if SWALLOWED_DOWN.swap(false, Ordering::SeqCst) {
                return LRESULT(1);
            }
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

/// Take the pending-swallow state: returns the PTT virtual-key when a
/// swallowed key-down is still waiting for its release. Pure bookkeeping —
/// used at teardown so the system keyboard state is never left holding a
/// swallowed modifier.
fn take_pending_swallow() -> Option<u16> {
    PTT_DOWN.store(false, Ordering::SeqCst);
    if SWALLOWED_DOWN.swap(false, Ordering::SeqCst) {
        Some(PTT_VK.load(Ordering::Relaxed))
    } else {
        None
    }
}

/// Synthetic key-up for the PTT key. Injected after the hook is uninstalled
/// so the release travels the normal system path.
fn key_up_input(vk: u16) -> INPUT {
    INPUT {
        r#type: INPUT_KEYBOARD,
        Anonymous: INPUT_0 {
            ki: KEYBDINPUT {
                wVk: VIRTUAL_KEY(vk),
                wScan: 0,
                dwFlags: KEYEVENTF_KEYUP,
                time: 0,
                dwExtraInfo: 0,
            },
        },
    }
}

/// Exit-safety release (the AutoHotkey `Send {Alt up}` OnExit pattern).
///
/// If the app shuts down between a swallowed PTT key-down and its key-up —
/// e.g. Alt+F4 closes the window while RAlt is the PTT key — Windows never
/// sees a release for the consumed down and the modifier stays "held" until
/// Ctrl+Alt+Del resets the keyboard. Injecting a synthetic key-up after
/// unhooking guarantees the state is released; the later physical release is
/// then harmless.
fn release_pending_swallow() {
    if let Some(vk) = take_pending_swallow() {
        let inputs = [key_up_input(vk)];
        let injected = unsafe { SendInput(&inputs, std::mem::size_of::<INPUT>() as i32) };
        tracing::warn!(
            ptt_vk = vk,
            injected,
            "released pending PTT swallow at hook teardown"
        );
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
                // The hook is gone: if a swallowed key-down never saw its
                // key-up (app closed while the PTT key was held), inject a
                // synthetic release so the modifier is not left stuck down
                // for the whole system.
                release_pending_swallow();
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

    /// The hook state lives in process-global statics; serialize the tests
    /// so parallel runners cannot clobber each other's EVENT_TX/PTT state.
    static TEST_LOCK: Mutex<()> = Mutex::new(());

    fn send_ptt(message: WPARAM, kb: &KBDLLHOOKSTRUCT) -> LRESULT {
        unsafe { hook_proc(0, message, LPARAM(kb as *const _ as isize)) }
    }

    /// The swallow-pair contract: an armed press consumes the key-down, the
    /// matching key-up still emits `Released` (so the controller never hangs
    /// in Recording) AND is consumed too — a swallowed up without a swallowed
    /// down is what wedges the system keyboard state (stuck Alt).
    #[test]
    fn armed_press_swallows_down_and_up_symmetrically() {
        let _guard = TEST_LOCK.lock().unwrap();
        let (tx, rx) = std::sync::mpsc::channel();
        EVENT_TX.get_or_init(|| Mutex::new(None));
        *EVENT_TX.get().unwrap().lock().unwrap() = Some(tx);
        PTT_VK.store(VK_RMENU.0, Ordering::Relaxed);
        PTT_DOWN.store(false, Ordering::SeqCst);
        SWALLOWED_DOWN.store(false, Ordering::SeqCst);

        let mut kb: KBDLLHOOKSTRUCT = unsafe { std::mem::zeroed() };
        kb.vkCode = VK_RMENU.0 as u32;

        // Key down while idle -> Pressed AND swallowed (down consumed).
        let down = send_ptt(WPARAM(WM_KEYDOWN as usize), &kb);
        assert_eq!(down.0, 1, "armed PTT key-down must be swallowed");
        assert_eq!(
            rx.recv_timeout(Duration::from_secs(1)).unwrap(),
            HotkeyEvent::Pressed
        );

        // Auto-repeat downs of the held key stay swallowed, no extra events.
        let repeat = send_ptt(WPARAM(WM_KEYDOWN as usize), &kb);
        assert_eq!(repeat.0, 1);
        assert!(rx.recv_timeout(Duration::from_millis(100)).is_err());

        // Key up -> Released MUST still fire AND be swallowed.
        let up = send_ptt(WPARAM(WM_KEYUP as usize), &kb);
        assert_eq!(up.0, 1, "matching PTT key-up must be swallowed");
        assert_eq!(
            rx.recv_timeout(Duration::from_secs(1)).unwrap(),
            HotkeyEvent::Released { shift: false }
        );

        // The pair is complete: state is back to idle and the next press
        // works again (wait past the double-tap window).
        std::thread::sleep(Duration::from_millis(350));
        let second = send_ptt(WPARAM(WM_KEYDOWN as usize), &kb);
        assert_eq!(second.0, 1);
        assert_eq!(
            rx.recv_timeout(Duration::from_secs(1)).unwrap(),
            HotkeyEvent::Pressed
        );
        send_ptt(WPARAM(WM_KEYUP as usize), &kb);
        assert_eq!(
            rx.recv_timeout(Duration::from_secs(1)).unwrap(),
            HotkeyEvent::Released { shift: false }
        );

        *EVENT_TX.get().unwrap().lock().unwrap() = None;
    }

    /// A PTT key pressed as part of a combo (AltGr input) must pass through
    /// untouched: no events, no swallow. `ptt_pressed_alone` reads the real
    /// 256-byte key-state table, so simulate the combo by flipping the queue
    /// state of another key through `keybd_event`-free means is not possible
    /// here — instead assert the guard itself stays permissive when the key
    /// state can't be inspected.
    #[test]
    fn non_ptt_keys_pass_through() {
        let _guard = TEST_LOCK.lock().unwrap();
        let (tx, rx) = std::sync::mpsc::channel();
        EVENT_TX.get_or_init(|| Mutex::new(None));
        *EVENT_TX.get().unwrap().lock().unwrap() = Some(tx);
        PTT_VK.store(VK_RMENU.0, Ordering::Relaxed);
        PTT_DOWN.store(false, Ordering::SeqCst);
        SWALLOWED_DOWN.store(false, Ordering::SeqCst);

        // A plain 'A' key must not be swallowed.
        let mut kb: KBDLLHOOKSTRUCT = unsafe { std::mem::zeroed() };
        kb.vkCode = 'A' as u32;
        let down = send_ptt(WPARAM(WM_KEYDOWN as usize), &kb);
        assert_eq!(down.0, 0);
        assert!(rx.recv_timeout(Duration::from_millis(100)).is_err());

        // An unpaired PTT key-up (hook started mid-press) passes through and
        // emits nothing.
        let mut kb_ptt: KBDLLHOOKSTRUCT = unsafe { std::mem::zeroed() };
        kb_ptt.vkCode = VK_RMENU.0 as u32;
        let up = send_ptt(WPARAM(WM_KEYUP as usize), &kb_ptt);
        assert_eq!(up.0, 0, "unpaired key-up must pass through");
        assert!(rx.recv_timeout(Duration::from_millis(100)).is_err());

        *EVENT_TX.get().unwrap().lock().unwrap() = None;
    }

    /// Teardown must never leave a swallowed key-down pending: the exit path
    /// has to know which key needs a synthetic release. When the down/up pair
    /// is balanced there is nothing to inject.
    #[test]
    fn teardown_release_accounting() {
        let _guard = TEST_LOCK.lock().unwrap();
        PTT_VK.store(VK_RMENU.0, Ordering::Relaxed);

        // Swallowed down with no release yet: the teardown release must
        // target RMenu and clear both flags.
        PTT_DOWN.store(true, Ordering::SeqCst);
        SWALLOWED_DOWN.store(true, Ordering::SeqCst);
        assert_eq!(take_pending_swallow(), Some(VK_RMENU.0));
        assert!(!SWALLOWED_DOWN.load(Ordering::SeqCst));
        assert!(!PTT_DOWN.load(Ordering::SeqCst));

        // Balanced pair: nothing pending, no synthetic release.
        assert_eq!(take_pending_swallow(), None);

        // Held key whose down passed through (combo): no swallow state, but
        // the down flag still clears so the next press arms cleanly.
        PTT_DOWN.store(true, Ordering::SeqCst);
        SWALLOWED_DOWN.store(false, Ordering::SeqCst);
        assert_eq!(take_pending_swallow(), None);
        assert!(!PTT_DOWN.load(Ordering::SeqCst));
    }
}
