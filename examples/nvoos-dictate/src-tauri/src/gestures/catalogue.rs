// SPDX-License-Identifier: LicenseRef-Proprietary
//! The human-readable vocabulary.
//!
//! One source of truth for the Settings legend, the onboarding screen, the
//! diagnostics table, and the accessibility audit (`keyboard_equivalent` is
//! never optional). Adding a gesture means adding it here *and* to
//! [`crate::gestures::GestureId`] — the exhaustive matches make that
//! mechanical.

use serde::Serialize;

use crate::gestures::{ActionId, GestureId, Safety};

/// One gesture as presented to a person.
#[derive(Debug, Clone, Serialize)]
pub struct GestureInfo {
    pub id: GestureId,
    pub label: &'static str,
    pub description: &'static str,
    /// The default action, if any. `None` means the pose is reserved for
    /// context (deictic pointing, confirmation) rather than a command.
    pub action: Option<ActionId>,
    pub motion: bool,
    pub default_enabled: bool,
}

/// Every gesture in the v1 vocabulary.
pub const GESTURES: &[GestureInfo] = &[
    GestureInfo {
        id: GestureId::OpenPalm,
        label: "Open palm",
        description: "Hold for 0.6 s to arm gestures. The only pose that works while disarmed.",
        action: Some(ActionId::Arm),
        motion: false,
        default_enabled: true,
    },
    GestureInfo {
        id: GestureId::ClosedFist,
        label: "Closed fist",
        description: "Dismisses an overlay, or disarms when nothing is open.",
        action: Some(ActionId::DismissOverlay),
        motion: false,
        default_enabled: true,
    },
    GestureInfo {
        id: GestureId::StopHand,
        label: "Stop (spread palm)",
        description: "Stops dictation. Always asks for confirmation.",
        action: Some(ActionId::StopDictation),
        motion: false,
        default_enabled: true,
    },
    GestureInfo {
        id: GestureId::PointingUp,
        label: "Point",
        description: "Marks the referent for \"ask your camera\" (deictic grounding).",
        action: None,
        motion: false,
        default_enabled: true,
    },
    GestureInfo {
        id: GestureId::Pinch,
        label: "Pinch",
        description: "Selects the pinned camera track (reserved).",
        action: None,
        motion: false,
        default_enabled: false,
    },
    GestureInfo {
        id: GestureId::Victory,
        label: "Victory",
        description:
            "Reserved for dictation punctuation. Off by default: it is an easy accidental pose.",
        action: None,
        motion: false,
        default_enabled: false,
    },
    GestureInfo {
        id: GestureId::ThumbUp,
        label: "Thumb up",
        description: "Confirmation only — never a standalone command.",
        action: None,
        motion: false,
        default_enabled: false,
    },
    GestureInfo {
        id: GestureId::ThumbDown,
        label: "Thumb down",
        description: "Rejection only — never a standalone command.",
        action: None,
        motion: false,
        default_enabled: false,
    },
    GestureInfo {
        id: GestureId::SwipeRight,
        label: "Swipe right",
        description: "Next item.",
        action: Some(ActionId::NextItem),
        motion: true,
        default_enabled: true,
    },
    GestureInfo {
        id: GestureId::SwipeLeft,
        label: "Swipe left",
        description: "Previous item.",
        action: Some(ActionId::PreviousItem),
        motion: true,
        default_enabled: true,
    },
    GestureInfo {
        id: GestureId::SwipeUp,
        label: "Swipe up",
        description: "Scroll up.",
        action: Some(ActionId::ScrollUp),
        motion: true,
        default_enabled: true,
    },
    GestureInfo {
        id: GestureId::SwipeDown,
        label: "Swipe down",
        description: "Scroll down.",
        action: Some(ActionId::ScrollDown),
        motion: true,
        default_enabled: true,
    },
    GestureInfo {
        id: GestureId::Wave,
        label: "Wave",
        description: "Reserved. Off by default: waving is the most common accidental motion.",
        action: None,
        motion: true,
        default_enabled: false,
    },
];

/// One action as presented to a person.
#[derive(Debug, Clone, Serialize)]
pub struct ActionInfo {
    pub id: ActionId,
    pub label: &'static str,
    pub safety: Safety,
    pub keyboard_equivalent: &'static str,
    pub default_enabled: bool,
}

/// Every action the router can emit.
pub const ACTIONS: &[ActionInfo] = &[
    ActionInfo {
        id: ActionId::Arm,
        label: "Arm gestures",
        safety: Safety::Safe,
        keyboard_equivalent: ActionId::Arm.keyboard_equivalent(),
        default_enabled: true,
    },
    ActionInfo {
        id: ActionId::Disarm,
        label: "Disarm gestures",
        safety: Safety::Safe,
        keyboard_equivalent: ActionId::Disarm.keyboard_equivalent(),
        default_enabled: true,
    },
    ActionInfo {
        id: ActionId::DismissOverlay,
        label: "Dismiss overlay",
        safety: Safety::Safe,
        keyboard_equivalent: ActionId::DismissOverlay.keyboard_equivalent(),
        default_enabled: true,
    },
    ActionInfo {
        id: ActionId::ScrollUp,
        label: "Scroll up",
        safety: Safety::Safe,
        keyboard_equivalent: ActionId::ScrollUp.keyboard_equivalent(),
        default_enabled: true,
    },
    ActionInfo {
        id: ActionId::ScrollDown,
        label: "Scroll down",
        safety: Safety::Safe,
        keyboard_equivalent: ActionId::ScrollDown.keyboard_equivalent(),
        default_enabled: true,
    },
    ActionInfo {
        id: ActionId::NextItem,
        label: "Next item",
        safety: Safety::Safe,
        keyboard_equivalent: ActionId::NextItem.keyboard_equivalent(),
        default_enabled: true,
    },
    ActionInfo {
        id: ActionId::PreviousItem,
        label: "Previous item",
        safety: Safety::Safe,
        keyboard_equivalent: ActionId::PreviousItem.keyboard_equivalent(),
        default_enabled: true,
    },
    ActionInfo {
        id: ActionId::StopDictation,
        label: "Stop dictation",
        safety: Safety::Guarded,
        keyboard_equivalent: ActionId::StopDictation.keyboard_equivalent(),
        default_enabled: true,
    },
    ActionInfo {
        id: ActionId::ToggleDictation,
        label: "Start/stop dictation",
        safety: Safety::Guarded,
        keyboard_equivalent: ActionId::ToggleDictation.keyboard_equivalent(),
        default_enabled: false,
    },
    ActionInfo {
        id: ActionId::TogglePreview,
        label: "Toggle camera preview",
        safety: Safety::Safe,
        keyboard_equivalent: ActionId::TogglePreview.keyboard_equivalent(),
        default_enabled: false,
    },
];

pub fn gesture(id: GestureId) -> Option<&'static GestureInfo> {
    GESTURES.iter().find(|g| g.id == id)
}

pub fn action(id: ActionId) -> Option<&'static ActionInfo> {
    ACTIONS.iter().find(|a| a.id == id)
}

/// The default allowlist, derived from [`ACTIONS`].
pub fn default_allowed() -> Vec<ActionId> {
    ACTIONS
        .iter()
        .filter(|a| a.default_enabled)
        .map(|a| a.id)
        .collect()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn every_action_has_an_entry_and_a_keyboard_path() {
        let all = [
            ActionId::Arm,
            ActionId::Disarm,
            ActionId::DismissOverlay,
            ActionId::TogglePreview,
            ActionId::ScrollUp,
            ActionId::ScrollDown,
            ActionId::NextItem,
            ActionId::PreviousItem,
            ActionId::ToggleDictation,
            ActionId::StopDictation,
        ];
        for id in all {
            let info = action(id).unwrap_or_else(|| panic!("{id:?} missing from ACTIONS"));
            assert!(!info.keyboard_equivalent.trim().is_empty());
            assert_eq!(info.safety, id.safety());
        }
    }

    #[test]
    fn every_gesture_has_an_entry() {
        let all = [
            GestureId::OpenPalm,
            GestureId::ClosedFist,
            GestureId::PointingUp,
            GestureId::Pinch,
            GestureId::Victory,
            GestureId::ThumbUp,
            GestureId::ThumbDown,
            GestureId::StopHand,
            GestureId::SwipeLeft,
            GestureId::SwipeRight,
            GestureId::SwipeUp,
            GestureId::SwipeDown,
            GestureId::Wave,
        ];
        for id in all {
            assert!(gesture(id).is_some(), "{id:?} missing from GESTURES");
        }
    }

    #[test]
    fn the_default_allowlist_is_exactly_the_safe_core() {
        let allowed = default_allowed();
        assert!(allowed.contains(&ActionId::StopDictation));
        assert!(!allowed.contains(&ActionId::ToggleDictation));
        assert!(!allowed.contains(&ActionId::TogglePreview));
    }

    #[test]
    fn no_mapped_gesture_defaults_to_a_guarded_action_without_marking_it() {
        for g in GESTURES {
            if let Some(action) = g.action {
                let info = action_info(action);
                assert_eq!(info.safety, action.safety());
            }
        }
    }

    fn action_info(id: ActionId) -> &'static ActionInfo {
        action(id).expect("action listed")
    }
}
