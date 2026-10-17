//! Portable push-to-talk state machine for the macOS event tap (P5 port).
//!
//! This module is platform-free on purpose: the event-tap shell
//! (`macos_event_tap.rs`) only translates CoreGraphics events into
//! `on_key()` calls and applies the returned actions, while everything
//! interesting — swallow pairing, combo passthrough, double-tap, Esc — is
//! unit-tested here on every OS.
//!
//! Semantics mirror the Windows `WH_KEYBOARD_LL` hook:
//! - A PTT key-down is swallowed optimistically (the focused app never sees
//!   it unless a combo follows).
//! - If any other key goes down while the PTT key is held, it is a real
//!   combo (Option+arrow etc.) — a synthetic PTT key-down is injected so
//!   the target app sees the modifier, and every subsequent event passes
//!   through untouched. Option-combos are common on macOS, so this rule is
//!   load-bearing.
//! - Auto-repeat PTT downs while armed stay swallowed and emit nothing.
//! - Esc while armed cancels the dictation (down + up swallowed).
//! - The PTT release emits `Released { shift }` (Shift held = raw
//!   passthrough request).
//! - A second press within `double_tap_ms` emits `DoubleTap` (hands-free
//!   toggle) instead of `Pressed`.

use std::time::{Duration, Instant};

/// macOS virtual key codes used by the tap (kVK_RightOption / kVK_RightControl / kVK_Escape).
pub const KVK_RIGHT_OPTION: i64 = 61;
pub const KVK_RIGHT_CONTROL: i64 = 62;
pub const KVK_ESCAPE: i64 = 53;

/// Map the settings PTT key name (`RAlt` / `RCtrl`) to a macOS key code.
pub fn ptt_keycode(ptt_key: &str) -> i64 {
    match ptt_key {
        "RCtrl" => KVK_RIGHT_CONTROL,
        _ => KVK_RIGHT_OPTION,
    }
}

/// What the event-tap shell must do with the current OS event.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum TapAction {
    /// Deliver the event to the focused app (return `Some(event)`).
    Pass,
    /// Swallow the event (return `None`).
    Swallow,
    /// Post a synthetic PTT key-down to the HID tap, then deliver the
    /// current event normally. Used when a held PTT key turns out to be a
    /// combo: the app must still see the modifier.
    InjectPttDown,
}

/// Events to forward to the dictation controller (paired with an action).
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum TapEvent {
    Pressed,
    Released { shift: bool },
    DoubleTap,
    Esc,
}

/// One input event's outcome: what to do with the OS event + what to emit.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct TapOutcome {
    pub action: TapAction,
    pub event: Option<TapEvent>,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum Armed {
    /// PTT down swallowed; its up will end the dictation.
    Swallowing,
    /// A combo was detected: the PTT down has been re-injected and
    /// everything now passes through until the PTT key comes up.
    Cancelled,
}

/// The platform-free PTT tap state machine.
#[derive(Debug)]
pub struct TapStateMachine {
    ptt: i64,
    esc: i64,
    esc_cancels: bool,
    double_tap_ms: u64,
    armed: Option<Armed>,
    /// Escape was swallowed while armed; swallow its key-up too.
    esc_down_swallowed: bool,
    last_up: Option<Instant>,
}

impl TapStateMachine {
    pub fn new(ptt_key: &str, esc_cancels: bool, double_tap_ms: u64) -> Self {
        Self {
            ptt: ptt_keycode(ptt_key),
            esc: KVK_ESCAPE,
            esc_cancels,
            double_tap_ms,
            armed: None,
            esc_down_swallowed: false,
            last_up: None,
        }
    }

    /// Whether a swallowed PTT key-down is still awaiting its key-up
    /// (diagnostics).
    pub fn swallow_pending(&self) -> bool {
        self.armed.is_some()
    }

    /// Feed one keyboard event. `shift` is the modifier state at this event
    /// (only meaningful on the PTT release).
    pub fn on_key(&mut self, keycode: i64, down: bool, shift: bool) -> TapOutcome {
        // Esc: swallow the pair while armed (cancel); otherwise transparent.
        // When Esc-cancel is disabled it is just another key: pressing it
        // while armed triggers the combo passthrough below.
        if keycode == self.esc && self.esc_cancels {
            if down && self.esc_cancels && self.armed.is_some() {
                self.esc_down_swallowed = true;
                return TapOutcome {
                    action: TapAction::Swallow,
                    event: Some(TapEvent::Esc),
                };
            }
            if !down && self.esc_down_swallowed {
                self.esc_down_swallowed = false;
                return TapOutcome {
                    action: TapAction::Swallow,
                    event: None,
                };
            }
            return TapOutcome {
                action: TapAction::Pass,
                event: None,
            };
        }

        if keycode != self.ptt {
            // Non-PTT key while armed: real combo. Re-inject the swallowed
            // PTT down so the target app sees the modifier, then pass the
            // current event (and everything else) through.
            if self.armed == Some(Armed::Swallowing) && down {
                self.armed = Some(Armed::Cancelled);
                return TapOutcome {
                    action: TapAction::InjectPttDown,
                    event: None,
                };
            }
            return TapOutcome {
                action: TapAction::Pass,
                event: None,
            };
        }

        // PTT key.
        if down {
            match self.armed {
                Some(Armed::Swallowing) => {
                    // Auto-repeat while held: keep it swallowed, emit nothing.
                    TapOutcome {
                        action: TapAction::Swallow,
                        event: None,
                    }
                }
                Some(Armed::Cancelled) => {
                    // Our own injected PTT down coming back through the tap:
                    // pass it (everything passes in the cancelled state).
                    TapOutcome {
                        action: TapAction::Pass,
                        event: None,
                    }
                }
                None => {
                    // Fresh press: swallow it and arm. Decide press vs
                    // double-tap from the previous release.
                    self.armed = Some(Armed::Swallowing);
                    let is_double = self
                        .last_up
                        .map(|t| t.elapsed() <= Duration::from_millis(self.double_tap_ms))
                        .unwrap_or(false);
                    TapOutcome {
                        action: TapAction::Swallow,
                        event: Some(if is_double {
                            TapEvent::DoubleTap
                        } else {
                            TapEvent::Pressed
                        }),
                    }
                }
            }
        } else {
            // PTT key-up.
            self.last_up = Some(Instant::now());
            let was = self.armed.take();
            match was {
                Some(Armed::Swallowing) => TapOutcome {
                    action: TapAction::Swallow,
                    event: Some(TapEvent::Released { shift }),
                },
                Some(Armed::Cancelled) => TapOutcome {
                    // The app already saw the re-injected down; it must see
                    // the up too.
                    action: TapAction::Pass,
                    event: Some(TapEvent::Released { shift }),
                },
                // Stray key-up (tap started mid-hold): transparent.
                None => TapOutcome {
                    action: TapAction::Pass,
                    event: None,
                },
            }
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    const PTT: i64 = KVK_RIGHT_OPTION;
    const X: i64 = 7; // kVK_ANSI_X

    #[test]
    fn standalone_press_release_is_swallowed() {
        let mut m = TapStateMachine::new("RAlt", true, 400);
        let down = m.on_key(PTT, true, false);
        assert_eq!(down.action, TapAction::Swallow);
        assert_eq!(down.event, Some(TapEvent::Pressed));
        assert!(m.swallow_pending());

        let up = m.on_key(PTT, false, false);
        assert_eq!(up.action, TapAction::Swallow);
        assert_eq!(up.event, Some(TapEvent::Released { shift: false }));
        assert!(!m.swallow_pending());
    }

    #[test]
    fn release_carries_shift_for_raw_passthrough() {
        let mut m = TapStateMachine::new("RAlt", true, 400);
        let _ = m.on_key(PTT, true, false);
        let up = m.on_key(PTT, false, true);
        assert_eq!(up.event, Some(TapEvent::Released { shift: true }));
    }

    #[test]
    fn autorepeat_down_stays_swallowed_without_emitting() {
        let mut m = TapStateMachine::new("RAlt", true, 400);
        let _ = m.on_key(PTT, true, false);
        let repeat = m.on_key(PTT, true, false);
        assert_eq!(repeat.action, TapAction::Swallow);
        assert_eq!(repeat.event, None);
        // The release still ends the dictation normally.
        let up = m.on_key(PTT, false, false);
        assert_eq!(up.event, Some(TapEvent::Released { shift: false }));
    }

    #[test]
    fn interleaved_key_is_a_combo_passthrough() {
        let mut m = TapStateMachine::new("RAlt", true, 400);
        let _ = m.on_key(PTT, true, false);
        let combo = m.on_key(X, true, false);
        assert_eq!(combo.action, TapAction::InjectPttDown);
        assert_eq!(combo.event, None);
        // Subsequent events pass through untouched.
        assert_eq!(m.on_key(X, false, false).action, TapAction::Pass);
        assert_eq!(m.on_key(X, true, false).action, TapAction::Pass);
        // Our injected PTT down comes back through the tap: pass it.
        let injected = m.on_key(PTT, true, false);
        assert_eq!(injected.action, TapAction::Pass);
        assert_eq!(injected.event, None);
        // The real PTT up ends the dictation and reaches the app.
        let up = m.on_key(PTT, false, false);
        assert_eq!(up.action, TapAction::Pass);
        assert_eq!(up.event, Some(TapEvent::Released { shift: false }));
        assert!(!m.swallow_pending());
    }

    #[test]
    fn combo_detected_on_non_ptt_keyup_only_is_ignored() {
        // A stray key-up (e.g. tap created while a key was held) must not
        // cancel the swallow: only a fresh key-down proves a combo.
        let mut m = TapStateMachine::new("RAlt", true, 400);
        let _ = m.on_key(PTT, true, false);
        assert_eq!(m.on_key(X, false, false).action, TapAction::Pass);
        // Still armed: the PTT up is swallowed.
        assert!(m.swallow_pending());
        let up = m.on_key(PTT, false, false);
        assert_eq!(up.action, TapAction::Swallow);
    }

    #[test]
    fn esc_while_armed_cancels_and_swallows_pair() {
        let mut m = TapStateMachine::new("RAlt", true, 400);
        let _ = m.on_key(PTT, true, false);
        let esc_down = m.on_key(KVK_ESCAPE, true, false);
        assert_eq!(esc_down.action, TapAction::Swallow);
        assert_eq!(esc_down.event, Some(TapEvent::Esc));
        let esc_up = m.on_key(KVK_ESCAPE, false, false);
        assert_eq!(esc_up.action, TapAction::Swallow);
        assert_eq!(esc_up.event, None);
        // PTT release afterwards is swallowed + reported (controller is
        // Idle and ignores it).
        let up = m.on_key(PTT, false, false);
        assert_eq!(up.event, Some(TapEvent::Released { shift: false }));
    }

    #[test]
    fn esc_when_idle_passes_through() {
        let mut m = TapStateMachine::new("RAlt", true, 400);
        assert_eq!(m.on_key(KVK_ESCAPE, true, false).action, TapAction::Pass);
        assert_eq!(m.on_key(KVK_ESCAPE, false, false).action, TapAction::Pass);
    }

    #[test]
    fn esc_disabled_when_esc_cancels_off() {
        let mut m = TapStateMachine::new("RAlt", false, 400);
        let _ = m.on_key(PTT, true, false);
        let esc = m.on_key(KVK_ESCAPE, true, false);
        assert_eq!(esc.action, TapAction::InjectPttDown); // treated as a combo key
        assert_eq!(esc.event, None);
    }

    #[test]
    fn double_tap_emits_double_tap_event() {
        let mut m = TapStateMachine::new("RAlt", true, 400);
        let _ = m.on_key(PTT, true, false);
        let _ = m.on_key(PTT, false, false);
        let second = m.on_key(PTT, true, false);
        assert_eq!(second.action, TapAction::Swallow);
        assert_eq!(second.event, Some(TapEvent::DoubleTap));
    }

    #[test]
    fn slow_second_press_is_a_new_dictation() {
        let mut m = TapStateMachine::new("RAlt", true, 400);
        let _ = m.on_key(PTT, true, false);
        let _ = m.on_key(PTT, false, false);
        // Simulate the double-tap window expiring.
        m.last_up = Some(Instant::now() - Duration::from_millis(500));
        let second = m.on_key(PTT, true, false);
        assert_eq!(second.event, Some(TapEvent::Pressed));
    }

    #[test]
    fn rctrl_maps_to_right_control_keycode() {
        let mut m = TapStateMachine::new("RCtrl", true, 400);
        let down = m.on_key(KVK_RIGHT_CONTROL, true, false);
        assert_eq!(down.event, Some(TapEvent::Pressed));
        assert_eq!(down.action, TapAction::Swallow);
    }

    #[test]
    fn stray_ptt_keyup_when_unarmed_passes() {
        let mut m = TapStateMachine::new("RAlt", true, 400);
        let up = m.on_key(PTT, false, false);
        assert_eq!(up.action, TapAction::Pass);
        assert_eq!(up.event, None);
    }
}
