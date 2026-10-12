//! macOS CGEventTap listener: global modifier push-to-talk with hold
//! semantics (P5 port, phase M2).
//!
//! The tap runs on a dedicated OS thread with its own CFRunLoop. An event
//! tap fires only when its mach-port run-loop source is added to a running
//! loop — the same "dedicated thread + message pump" rule as the Windows
//! hook. All swallow/combo/double-tap decisions live in the platform-free
//! [`TapStateMachine`] (`tap_state.rs`); this shell translates CoreGraphics
//! events into state-machine calls and applies the returned actions.
//!
//! Swallow semantics note: core-graphics 0.23's callback returns
//! `Option<CGEvent>` where `None` re-posts the ORIGINAL event (it cannot
//! express "drop this event"). The established workaround is returning a
//! fresh null `CGEvent` — a type-0 event that posts as a no-op. That is
//! what `TapAction::Swallow` maps to.
//!
//! Permissions: listening for key events requires Input Monitoring
//! (CGPreflightListenEventAccess), swallowing/posting requires
//! Accessibility (AXIsProcessTrusted). Without them the tap is created but
//! stays silent — the Diagnostics page surfaces a zero-callback tap via
//! `tap_healthy()` and the Settings permission rows explain the fix.
//!
//! Exit discipline: CFRunLoopStop is documented thread-safe, so `stop()`
//! wakes the loop from the controller thread and joins the worker before
//! the process exits. A swallowed key needs no synthetic release on macOS
//! (the tap dies with the process), unlike the Windows hook — the swallow
//! bookkeeping stays symmetric for parity.

use std::sync::atomic::{AtomicBool, AtomicU64, Ordering};
use std::sync::mpsc::{channel, Receiver, Sender};
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant};

use core_foundation::runloop::{kCFRunLoopCommonModes, CFRunLoop};
use core_graphics::event::{
    CGEvent, CGEventFlags, CGEventTap, CGEventTapLocation, CGEventTapOptions, CGEventTapPlacement,
    CGEventTapProxy, CGEventType, EventField,
};
use core_graphics::event_source::{CGEventSource, CGEventSourceStateID};

use crate::config::HotkeyConfig;
use crate::error::{AppError, AppResult};
use crate::hotkey::tap_state::{TapAction, TapEvent, TapStateMachine};
use crate::hotkey::{HotkeyEvent, HotkeyListener};

/// Tap health statics (process-global; read by the Diagnostics IPC).
static TAP_INSTALLED: AtomicBool = AtomicBool::new(false);
static TAP_CALLS: AtomicU64 = AtomicU64::new(0);
static LAST_EVENT: Mutex<Option<Instant>> = Mutex::new(None);
static SWALLOW_PENDING: AtomicBool = AtomicBool::new(false);

pub fn tap_installed() -> bool {
    TAP_INSTALLED.load(Ordering::SeqCst)
}

pub fn tap_calls() -> u64 {
    TAP_CALLS.load(Ordering::SeqCst)
}

/// Milliseconds since the tap's last keyboard event (a silent tap = Input
/// Monitoring missing or the tap was never installed).
pub fn heartbeat_age_ms() -> u64 {
    match *LAST_EVENT.lock().unwrap() {
        Some(at) => at.elapsed().as_millis() as u64,
        None => u64::MAX,
    }
}

/// Installed and delivering events within `timeout_ms`.
pub fn tap_healthy(timeout_ms: u64) -> bool {
    TAP_INSTALLED.load(Ordering::SeqCst) && heartbeat_age_ms() <= timeout_ms
}

pub fn swallow_pending() -> bool {
    SWALLOW_PENDING.load(Ordering::SeqCst)
}

/// The macOS hold-PTT listener.
#[derive(Default)]
pub struct MacEventTapListener {
    /// The tap thread's run loop, stored so `stop()` can CFRunLoopStop it
    /// (CFRunLoop is Send + Sync in core-foundation).
    run_loop: Arc<Mutex<Option<CFRunLoop>>>,
    handle: Option<std::thread::JoinHandle<()>>,
}

impl HotkeyListener for MacEventTapListener {
    fn start(&mut self, config: &HotkeyConfig, tx: Sender<HotkeyEvent>) -> AppResult<()> {
        let ptt_key = config.ptt_key.clone();
        let esc_cancels = config.esc_cancels;
        let double_tap_ms = config.double_tap_ms;
        let run_loop = self.run_loop.clone();
        let (ready_tx, ready_rx): (Sender<Result<(), String>>, Receiver<Result<(), String>>) =
            channel();

        let handle = std::thread::Builder::new()
            .name("nvoos-dictate-tap".into())
            .spawn(move || {
                // The tap must be created on the thread that runs its loop.
                let source = match CGEventSource::new(CGEventSourceStateID::HIDSystemState) {
                    Ok(s) => s,
                    Err(()) => {
                        let _ = ready_tx.send(Err("CGEventSource creation failed".into()));
                        return;
                    }
                };
                let cb_source = source.clone();
                let state = Arc::new(Mutex::new(TapStateMachine::new(
                    &ptt_key,
                    esc_cancels,
                    double_tap_ms,
                )));
                let cb_state = state.clone();
                let cb_tx = tx.clone();

                let tap = CGEventTap::new(
                    CGEventTapLocation::HID,
                    CGEventTapPlacement::HeadInsertEventTap,
                    CGEventTapOptions::Default,
                    vec![CGEventType::KeyDown, CGEventType::KeyUp],
                    move |_proxy: CGEventTapProxy, etype: CGEventType, event: &CGEvent| {
                        TAP_CALLS.fetch_add(1, Ordering::SeqCst);
                        *LAST_EVENT.lock().unwrap() = Some(Instant::now());
                        if etype != CGEventType::KeyDown && etype != CGEventType::KeyUp {
                            return Some(event.clone());
                        }
                        let keycode =
                            event.get_integer_value_field(EventField::KEYBOARD_EVENT_KEYCODE);
                        let down = etype == CGEventType::KeyDown;
                        let flags = event.get_flags();
                        let shift = flags.contains(CGEventFlags::CGEventFlagShift);

                        let mut state = cb_state.lock().unwrap();
                        let outcome = state.on_key(keycode, down, shift);
                        SWALLOW_PENDING.store(state.swallow_pending(), Ordering::SeqCst);

                        if let Some(ev) = outcome.event {
                            let mapped = match ev {
                                TapEvent::Pressed => HotkeyEvent::Pressed,
                                TapEvent::Released { shift } => HotkeyEvent::Released { shift },
                                TapEvent::DoubleTap => HotkeyEvent::DoubleTap,
                                TapEvent::Esc => HotkeyEvent::Esc,
                            };
                            let _ = cb_tx.send(mapped);
                        }

                        match outcome.action {
                            TapAction::Pass => Some(event.clone()),
                            // Swallow via the null-event workaround (None
                            // would re-post the original event).
                            TapAction::Swallow => CGEvent::new(cb_source.clone()).ok(),
                            TapAction::InjectPttDown => {
                                // The held PTT key turned out to be a combo:
                                // re-inject the modifier down so the target
                                // app sees it (the injected event comes back
                                // through the tap and passes via the
                                // Cancelled state).
                                if let Ok(synthetic) = CGEvent::new_keyboard_event(
                                    cb_source.clone(),
                                    keycode as u16,
                                    true,
                                ) {
                                    synthetic.set_flags(CGEventFlags::CGEventFlagAlternate);
                                    synthetic.post(CGEventTapLocation::HID);
                                }
                                Some(event.clone())
                            }
                        }
                    },
                );
                let tap = match tap {
                    Ok(t) => t,
                    Err(()) => {
                        let _ = ready_tx.send(Err(
                            "event tap install failed — Accessibility permission required".into(),
                        ));
                        return;
                    }
                };

                // Without the mach-port source on a run loop the tap never
                // fires (silently). Add it to the common modes and run.
                let loop_source = match tap.mach_port.create_runloop_source(0) {
                    Ok(s) => s,
                    Err(()) => {
                        let _ =
                            ready_tx.send(Err("event tap run-loop source creation failed".into()));
                        return;
                    }
                };
                let current = CFRunLoop::get_current();
                current.add_source(&loop_source, unsafe { kCFRunLoopCommonModes });

                tap.enable();
                TAP_INSTALLED.store(true, Ordering::SeqCst);
                *run_loop.lock().unwrap() = Some(current);
                let _ = ready_tx.send(Ok(()));
                // Blocks until stop() calls CFRunLoopStop from the other
                // side of `run_loop`.
                CFRunLoop::run_current();
                TAP_INSTALLED.store(false, Ordering::SeqCst);
                SWALLOW_PENDING.store(false, Ordering::SeqCst);
                *run_loop.lock().unwrap() = None;
            })
            .map_err(|e| AppError::Hotkey(format!("tap thread spawn failed: {e}")))?;

        // Wait for the tap to be installed (or fail with the reason).
        match ready_rx.recv_timeout(Duration::from_secs(2)) {
            Ok(Ok(())) => {
                self.handle = Some(handle);
                Ok(())
            }
            Ok(Err(e)) => {
                let _ = handle.join();
                Err(AppError::Hotkey(e))
            }
            Err(_) => {
                let _ = handle.join();
                Err(AppError::Hotkey(
                    "event tap did not initialize in time".into(),
                ))
            }
        }
    }

    fn stop(&mut self) {
        if let Some(rl) = self.run_loop.lock().unwrap().take() {
            rl.stop();
        }
        if let Some(handle) = self.handle.take() {
            let _ = handle.join();
        }
    }
}
