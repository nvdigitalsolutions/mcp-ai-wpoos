// SPDX-License-Identifier: LicenseRef-Proprietary
//! Local hand-gesture input (Proposal 153).
//!
//! The module is deliberately split so that the **decision logic is always
//! compiled** and fully testable without a camera or an ONNX model:
//!
//! - [`normalize`] / [`geometry`] / [`classifier`] — landmark → pose
//! - [`motion`] — short trajectory → swipe / wave
//! - [`stabilize`] — hysteresis, debounce, track gating
//! - [`router`] — allow-listed intent routing with a safety class
//! - [`catalogue`] — the human-readable vocabulary + keyboard equivalents
//!
//! Only the landmark backend ([`landmarks`]), the model catalogue
//! ([`models`]) and the resident loop ([`controller`]) are behind the
//! `gestures` cargo feature. Everything else compiles everywhere, mirroring
//! how `vision/tracker.rs` stays testable when `vision` is off.
//!
//! Bright lines (restated in UI copy): no facial recognition or identity
//! inference, no frame egress, and gestures are never the only way to reach
//! an action.

pub mod arbiter;
pub mod catalogue;
pub mod classifier;
pub mod controller;
pub mod deictic;
pub mod geometry;
pub mod landmarks;
pub mod models;
pub mod motion;
pub mod normalize;
pub mod router;
pub mod stabilize;

#[cfg(test)]
mod test_support;

use serde::{Deserialize, Serialize};

/// Which hand produced the observation.
///
/// MediaPipe documents that handedness describes the **subject's** hand, so a
/// horizontally mirrored input inverts the label. The caller must flip this
/// value when it feeds a mirrored buffer (see [`Handedness::mirrored`]).
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum Handedness {
    Left,
    Right,
    Unknown,
}

impl Handedness {
    /// The label after a horizontal mirror. `Unknown` stays unknown.
    pub fn mirrored(self) -> Self {
        match self {
            Self::Left => Self::Right,
            Self::Right => Self::Left,
            Self::Unknown => Self::Unknown,
        }
    }
}

/// A 3D landmark in the coordinate space named by its producer.
#[derive(Debug, Clone, Copy, Default, PartialEq, Serialize, Deserialize)]
pub struct Point3 {
    pub x: f32,
    pub y: f32,
    pub z: f32,
}

impl Point3 {
    pub const fn new(x: f32, y: f32, z: f32) -> Self {
        Self { x, y, z }
    }

    pub fn distance(self, other: Self) -> f32 {
        let dx = self.x - other.x;
        let dy = self.y - other.y;
        let dz = self.z - other.z;
        (dx * dx + dy * dy + dz * dz).sqrt()
    }

    /// Distance in the image plane; depth is noisy and is excluded on purpose.
    pub fn distance_2d(self, other: Self) -> f32 {
        let dx = self.x - other.x;
        let dy = self.y - other.y;
        (dx * dx + dy * dy).sqrt()
    }
}

/// A pixel-space rectangle around the detected hand (source-frame pixels).
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq, Serialize, Deserialize)]
pub struct BBox {
    pub x: u32,
    pub y: u32,
    pub w: u32,
    pub h: u32,
}

/// One detected hand.
///
/// `points` are normalised image coordinates in the producer's space (0..1 for
/// MediaPipe-style models). `bbox` is in **source pixels** because it is used
/// to crop the frame for deictic grounding; mixing the two spaces would crop
/// the wrong region.
#[derive(Debug, Clone, Serialize)]
pub struct HandLandmarks {
    pub track_id: Option<u64>,
    pub handedness: Handedness,
    pub score: f32,
    pub points: [Point3; 21],
    pub bbox: BBox,
    pub at_ms: u64,
}

/// The closed gesture vocabulary.
///
/// This is intentionally small. Breadth is a liability: every additional
/// class adds false positives, a cultural/meaning problem to justify, and a
/// keyboard equivalent to maintain.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum GestureId {
    // Static
    OpenPalm,
    ClosedFist,
    PointingUp,
    Pinch,
    Victory,
    ThumbUp,
    ThumbDown,
    StopHand,
    // Motion
    SwipeLeft,
    SwipeRight,
    SwipeUp,
    SwipeDown,
    Wave,
}

impl GestureId {
    pub fn as_str(self) -> &'static str {
        match self {
            Self::OpenPalm => "open_palm",
            Self::ClosedFist => "closed_fist",
            Self::PointingUp => "pointing_up",
            Self::Pinch => "pinch",
            Self::Victory => "victory",
            Self::ThumbUp => "thumb_up",
            Self::ThumbDown => "thumb_down",
            Self::StopHand => "stop_hand",
            Self::SwipeLeft => "swipe_left",
            Self::SwipeRight => "swipe_right",
            Self::SwipeUp => "swipe_up",
            Self::SwipeDown => "swipe_down",
            Self::Wave => "wave",
        }
    }

    pub fn is_motion(self) -> bool {
        matches!(
            self,
            Self::SwipeLeft | Self::SwipeRight | Self::SwipeUp | Self::SwipeDown | Self::Wave
        )
    }
}

/// The closed action vocabulary.
///
/// There is deliberately **no** variant for delete, purge, send, purchase or
/// any outbound tool call: a forbidden action is unrepresentable rather than
/// merely rejected by a string check.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum ActionId {
    Arm,
    Disarm,
    DismissOverlay,
    TogglePreview,
    ScrollUp,
    ScrollDown,
    NextItem,
    PreviousItem,
    ToggleDictation,
    StopDictation,
}

impl ActionId {
    /// Every action the router can emit. Used by Settings and by the
    /// allowlist parser.
    pub const ALL: [ActionId; 10] = [
        Self::Arm,
        Self::Disarm,
        Self::DismissOverlay,
        Self::TogglePreview,
        Self::ScrollUp,
        Self::ScrollDown,
        Self::NextItem,
        Self::PreviousItem,
        Self::ToggleDictation,
        Self::StopDictation,
    ];

    /// Parse the Settings string form. Unknown values are rejected, never
    /// coerced to a default.
    pub fn parse(value: &str) -> Option<Self> {
        Self::ALL
            .into_iter()
            .find(|action| action.as_str() == value)
    }

    pub fn as_str(self) -> &'static str {
        match self {
            Self::Arm => "arm",
            Self::Disarm => "disarm",
            Self::DismissOverlay => "dismiss_overlay",
            Self::TogglePreview => "toggle_preview",
            Self::ScrollUp => "scroll_up",
            Self::ScrollDown => "scroll_down",
            Self::NextItem => "next_item",
            Self::PreviousItem => "previous_item",
            Self::ToggleDictation => "toggle_dictation",
            Self::StopDictation => "stop_dictation",
        }
    }

    /// Safety class enforced by the single router gate.
    pub const fn safety(self) -> Safety {
        match self {
            // Dictation is the user's live capture: a mis-fire can start a
            // recording or end one mid-sentence, so both directions are
            // guarded even when the gesture was confident.
            Self::ToggleDictation | Self::StopDictation => Safety::Guarded,
            _ => Safety::Safe,
        }
    }

    /// The keyboard/pointer equivalent. WCAG 2.5.1 and the Apple HIG both
    /// require every gesture-driven function to have one; the catalogue and
    /// the Settings legend read this.
    pub const fn keyboard_equivalent(self) -> &'static str {
        match self {
            Self::Arm => "Camera panel → Arm button",
            Self::Disarm => "Camera panel → Disarm button",
            Self::DismissOverlay => "Esc / Close button",
            Self::TogglePreview => "Settings → Camera → Preview",
            Self::ScrollUp => "Page Up",
            Self::ScrollDown => "Page Down",
            Self::NextItem => "Down arrow",
            Self::PreviousItem => "Up arrow",
            Self::ToggleDictation => "Push-to-talk key",
            Self::StopDictation => "Esc (cancel) / PTT release",
        }
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize)]
#[serde(rename_all = "snake_case")]
pub enum Safety {
    Safe,
    Guarded,
}

/// A stabilised gesture, ready for routing.
#[derive(Debug, Clone, Serialize)]
pub struct GestureObservation {
    pub gesture: GestureId,
    pub confidence: f32,
    pub handedness: Handedness,
    pub bbox: BBox,
    /// How long the gesture has been continuously stable (ms).
    pub held_ms: u64,
    pub at_ms: u64,
}

/// Where an intent came from (diagnostics only; the router never trusts it).
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize)]
#[serde(rename_all = "snake_case")]
pub enum IntentSource {
    Geometry,
    Learned,
    Llm,
}

/// A routed action, before execution.
#[derive(Debug, Clone, Serialize)]
pub struct GestureIntent {
    pub action: ActionId,
    pub gesture: GestureId,
    pub confidence: f32,
    pub source: IntentSource,
    /// `true` for [`Safety::Guarded`] actions; the caller must obtain a second
    /// confirmation before executing.
    pub requires_confirm: bool,
    /// The second confirmation has already happened: the pose was held for the
    /// configured confirmation window. Guarded actions execute only when this
    /// is `true`.
    pub hold_confirmed: bool,
    /// How long the gesture had been held when the intent was produced.
    pub held_ms: u64,
    pub at_ms: u64,
}

/// Whether gesture recognition is compiled into this build.
pub fn available() -> bool {
    cfg!(feature = "gestures")
}

/// Whether recognition additionally needs a live camera (the frame source).
pub fn requires_camera() -> bool {
    cfg!(feature = "camera")
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn mirrored_handedness() {
        assert_eq!(Handedness::Left.mirrored(), Handedness::Right);
        assert_eq!(Handedness::Right.mirrored(), Handedness::Left);
        assert_eq!(Handedness::Unknown.mirrored(), Handedness::Unknown);
    }

    #[test]
    fn guarded_actions_are_exactly_the_dictation_pair() {
        let guarded: Vec<ActionId> = [
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
        ]
        .into_iter()
        .filter(|a| a.safety() == Safety::Guarded)
        .collect();
        assert_eq!(
            guarded,
            vec![ActionId::ToggleDictation, ActionId::StopDictation]
        );
    }

    #[test]
    fn every_action_has_a_keyboard_equivalent() {
        for action in [
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
        ] {
            assert!(
                !action.keyboard_equivalent().trim().is_empty(),
                "{} has no alternative input path",
                action.as_str()
            );
        }
    }
}
