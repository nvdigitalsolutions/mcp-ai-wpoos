//! Intent routing: the single safety gate.
//!
//! The router is the only place that turns a recognised gesture into an
//! action. It owns:
//!
//! - **arming** — nothing but the arm gesture fires while disarmed, which is
//!   what stops an always-on camera from becoming a Midas-touch machine;
//! - **the allowlist** — a user removing an action from Settings removes it
//!   here, not in the UI;
//! - **per-action cooldowns** — a held gesture cannot machine-gun an action;
//! - **safety classification** — guarded actions are marked
//!   `requires_confirm` so the caller must obtain a second signal.
//!
//! It deliberately has no access to the model, the camera, or the frame
//! buffer. That keeps the policy auditable and testable.

use std::collections::HashMap;

use crate::gestures::{
    ActionId, GestureId, GestureIntent, GestureObservation, IntentSource, Safety,
};

/// Routable context supplied by the app each frame.
#[derive(Debug, Clone, Copy, Default)]
pub struct GestureContext {
    /// A dismissible overlay (recording capsule, snippet palette) is visible.
    pub overlay_visible: bool,
    /// Dictation is currently recording.
    pub dictation_active: bool,
}

/// Tuning for the gate.
#[derive(Debug, Clone)]
pub struct RouterConfig {
    /// How long the arm pose must be held before arming.
    pub arm_hold_ms: u64,
    /// The arming pose.
    pub arm_gesture: GestureId,
    /// Per-action cooldown.
    pub cooldown_ms: u64,
    /// Disarm automatically after this long without a gesture.
    pub armed_timeout_ms: u64,
    /// How long a guarded gesture must be held to count as confirmed.
    pub confirm_hold_ms: u64,
    /// Actions the user has allowed.
    pub allowed: Vec<ActionId>,
}

impl Default for RouterConfig {
    fn default() -> Self {
        Self {
            arm_hold_ms: 600,
            arm_gesture: GestureId::OpenPalm,
            cooldown_ms: 500,
            armed_timeout_ms: 10_000,
            confirm_hold_ms: 1_500,
            // Conservative defaults: dictation toggling and preview toggling
            // are available but not enabled out of the box.
            allowed: vec![
                ActionId::Arm,
                ActionId::Disarm,
                ActionId::DismissOverlay,
                ActionId::ScrollUp,
                ActionId::ScrollDown,
                ActionId::NextItem,
                ActionId::PreviousItem,
                ActionId::StopDictation,
            ],
        }
    }
}

/// Resident routing state.
#[derive(Debug)]
pub struct Router {
    cfg: RouterConfig,
    armed: bool,
    armed_until: u64,
    last_fire: HashMap<ActionId, u64>,
}

impl Router {
    pub fn new(cfg: RouterConfig) -> Self {
        Self {
            cfg,
            armed: false,
            armed_until: 0,
            last_fire: HashMap::new(),
        }
    }

    pub fn config(&self) -> &RouterConfig {
        &self.cfg
    }

    pub fn armed(&self) -> bool {
        self.armed
    }

    /// Arm from the UI (the keyboard/pointer equivalent of the arm gesture).
    pub fn arm(&mut self, now: u64) {
        self.armed = true;
        self.armed_until = now.saturating_add(self.cfg.armed_timeout_ms);
    }

    /// Disarm from the UI or on camera stop.
    pub fn disarm(&mut self) {
        self.armed = false;
        self.armed_until = 0;
    }

    /// Replace the allowed-action set (Settings).
    pub fn set_allowed(&mut self, allowed: Vec<ActionId>) {
        self.cfg.allowed = allowed;
    }

    /// Route one stabilised observation.
    pub fn update(
        &mut self,
        obs: &GestureObservation,
        ctx: &GestureContext,
        now: u64,
    ) -> Option<GestureIntent> {
        if self.armed && now > self.armed_until {
            self.disarm();
        }

        // ---- arming -------------------------------------------------------
        if obs.gesture == self.cfg.arm_gesture {
            if !self.armed && obs.held_ms >= self.cfg.arm_hold_ms {
                self.arm(now);
                return self.intent(ActionId::Arm, obs, now);
            }
            // Already armed: holding the arm pose is not a repeatable action.
            if self.armed {
                self.armed_until = now.saturating_add(self.cfg.armed_timeout_ms);
            }
            return None;
        }

        if !self.armed {
            return None;
        }

        let action = map_gesture(obs.gesture, ctx)?;

        // Any routed activity keeps the session alive.
        self.armed_until = now.saturating_add(self.cfg.armed_timeout_ms);

        if matches!(action, ActionId::Disarm) {
            self.disarm();
        }

        self.intent(action, obs, now)
    }

    /// Apply the allowlist and cooldown, then build the intent.
    fn intent(
        &mut self,
        action: ActionId,
        obs: &GestureObservation,
        now: u64,
    ) -> Option<GestureIntent> {
        if !self.cfg.allowed.contains(&action) {
            return None;
        }
        if !self.ready(action, now) {
            return None;
        }
        Some(GestureIntent {
            action,
            gesture: obs.gesture,
            confidence: obs.confidence,
            source: IntentSource::Geometry,
            requires_confirm: action.safety() == Safety::Guarded,
            hold_confirmed: obs.held_ms >= self.cfg.confirm_hold_ms,
            held_ms: obs.held_ms,
            at_ms: now,
        })
    }

    fn ready(&mut self, action: ActionId, now: u64) -> bool {
        if let Some(last) = self.last_fire.get(&action).copied() {
            if now.saturating_sub(last) < self.cfg.cooldown_ms {
                return false;
            }
        }
        self.last_fire.insert(action, now);
        true
    }
}

/// Gesture → action, before the allowlist and safety checks.
///
/// Only deliberate, low-risk mappings live here. Confirmation gestures
/// (thumbs) and deictic poses are intentionally unmapped: they are inputs to
/// other features, not standalone commands.
pub fn map_gesture(gesture: GestureId, ctx: &GestureContext) -> Option<ActionId> {
    match gesture {
        GestureId::ClosedFist => Some(if ctx.overlay_visible {
            ActionId::DismissOverlay
        } else {
            ActionId::Disarm
        }),
        GestureId::StopHand => Some(ActionId::StopDictation),
        GestureId::SwipeRight => Some(ActionId::NextItem),
        GestureId::SwipeLeft => Some(ActionId::PreviousItem),
        GestureId::SwipeUp => Some(ActionId::ScrollUp),
        GestureId::SwipeDown => Some(ActionId::ScrollDown),
        // Reserved / context gestures.
        GestureId::OpenPalm
        | GestureId::PointingUp
        | GestureId::Pinch
        | GestureId::Victory
        | GestureId::ThumbUp
        | GestureId::ThumbDown
        | GestureId::Wave => None,
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::gestures::{BBox, Handedness};

    fn obs(gesture: GestureId, held_ms: u64, at_ms: u64) -> GestureObservation {
        GestureObservation {
            gesture,
            confidence: 0.92,
            handedness: Handedness::Right,
            bbox: BBox::default(),
            held_ms,
            at_ms,
        }
    }

    #[test]
    fn arming_requires_a_hold() {
        let mut r = Router::new(RouterConfig::default());
        assert!(r
            .update(
                &obs(GestureId::OpenPalm, 300, 300),
                &GestureContext::default(),
                300
            )
            .is_none());
        let intent = r
            .update(
                &obs(GestureId::OpenPalm, 650, 650),
                &GestureContext::default(),
                650,
            )
            .expect("arms");
        assert_eq!(intent.action, ActionId::Arm);
        assert!(!intent.requires_confirm);
        assert!(r.armed());
    }

    #[test]
    fn nothing_else_fires_while_disarmed() {
        let mut r = Router::new(RouterConfig::default());
        for g in [
            GestureId::SwipeRight,
            GestureId::ClosedFist,
            GestureId::StopHand,
        ] {
            assert!(r
                .update(&obs(g, 500, 500), &GestureContext::default(), 500)
                .is_none());
        }
    }

    #[test]
    fn a_swipe_fires_once_per_cooldown() {
        let mut r = Router::new(RouterConfig::default());
        r.arm(0);

        let first = r
            .update(
                &obs(GestureId::SwipeRight, 0, 1000),
                &GestureContext::default(),
                1000,
            )
            .expect("fires");
        assert_eq!(first.action, ActionId::NextItem);

        assert!(r
            .update(
                &obs(GestureId::SwipeRight, 0, 1200),
                &GestureContext::default(),
                1200
            )
            .is_none());

        let again = r
            .update(
                &obs(GestureId::SwipeRight, 0, 1600),
                &GestureContext::default(),
                1600,
            )
            .expect("fires after cooldown");
        assert_eq!(again.action, ActionId::NextItem);
    }

    #[test]
    fn the_allowlist_is_enforced_in_one_place() {
        let mut cfg = RouterConfig::default();
        cfg.allowed.retain(|a| *a != ActionId::NextItem);
        let mut r = Router::new(cfg);
        r.arm(0);
        assert!(r
            .update(
                &obs(GestureId::SwipeRight, 0, 1000),
                &GestureContext::default(),
                1000
            )
            .is_none());
    }

    #[test]
    fn fist_dismisses_an_overlay_or_disarms() {
        let mut r = Router::new(RouterConfig::default());

        r.arm(0);
        let dismiss = r
            .update(
                &obs(GestureId::ClosedFist, 0, 1000),
                &GestureContext {
                    overlay_visible: true,
                    dictation_active: false,
                },
                1000,
            )
            .expect("dismisses");
        assert_eq!(dismiss.action, ActionId::DismissOverlay);
        assert!(r.armed(), "dismissing an overlay keeps the session armed");

        let disarm = r
            .update(
                &obs(GestureId::ClosedFist, 0, 2000),
                &GestureContext::default(),
                2000,
            )
            .expect("disarms");
        assert_eq!(disarm.action, ActionId::Disarm);
        assert!(!r.armed());
    }

    #[test]
    fn stop_is_guarded() {
        let mut r = Router::new(RouterConfig::default());
        r.arm(0);
        let intent = r
            .update(
                &obs(GestureId::StopHand, 0, 1000),
                &GestureContext::default(),
                1000,
            )
            .expect("fires");
        assert_eq!(intent.action, ActionId::StopDictation);
        assert!(intent.requires_confirm, "dictation must never be silent");
        assert!(
            !intent.hold_confirmed,
            "a quick flash must not stop a recording"
        );
    }

    #[test]
    fn a_deliberate_hold_confirms_a_guarded_action() {
        let mut r = Router::new(RouterConfig::default());
        r.arm(0);
        let first = r
            .update(
                &obs(GestureId::StopHand, 400, 1000),
                &GestureContext::default(),
                1000,
            )
            .expect("fires");
        assert!(!first.hold_confirmed);

        let held = r
            .update(
                &obs(GestureId::StopHand, 1_600, 1_600),
                &GestureContext::default(),
                1_600,
            )
            .expect("fires after cooldown");
        assert!(held.requires_confirm);
        assert!(held.hold_confirmed);
    }

    #[test]
    fn the_session_expires_without_activity() {
        let mut r = Router::new(RouterConfig {
            armed_timeout_ms: 1_000,
            ..Default::default()
        });
        r.arm(0);
        assert!(r.armed());
        assert!(r
            .update(
                &obs(GestureId::SwipeRight, 0, 5_000),
                &GestureContext::default(),
                5_000
            )
            .is_none());
        assert!(!r.armed());
    }

    #[test]
    fn confirmation_gestures_are_not_standalone_actions() {
        let mut r = Router::new(RouterConfig::default());
        r.arm(0);
        for g in [
            GestureId::ThumbUp,
            GestureId::ThumbDown,
            GestureId::PointingUp,
            GestureId::Pinch,
            GestureId::Victory,
            GestureId::Wave,
        ] {
            assert!(
                r.update(&obs(g, 0, 1000), &GestureContext::default(), 1000)
                    .is_none(),
                "{g:?} must not map to an action"
            );
        }
    }
}
