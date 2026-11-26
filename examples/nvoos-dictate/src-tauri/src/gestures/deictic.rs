// SPDX-License-Identifier: LicenseRef-Proprietary
//! Deictic grounding — M5 of Proposal 153: "point at something and ask".
//!
//! The gesture supplies the **referent**, not a command: the user shows a hand,
//! asks a question, and the crop around that hand is sent to the local
//! vision-language model along with the question. Nothing about this path can
//! actuate the app — it produces text for the Home panel and nothing else.
//!
//! Everything in this module is pure geometry and is therefore **always
//! compiled and unit-tested**; the network leg lives in `AppState::ask_camera`
//! and reuses the existing `LocalOpenAiProvider` multimodal transport.

use crate::gestures::{GestureId, Point3};
use serde::Serialize;

/// A crop region in **source-frame pixels**.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize)]
pub struct Region {
    pub x: u32,
    pub y: u32,
    pub w: u32,
    pub h: u32,
}

/// How much bigger than the hand the crop is when the pose is not a deliberate
/// point. 1.7 keeps the hand and its immediate surroundings in frame without
/// swallowing the whole desk.
pub const HAND_CONTEXT: f32 = 1.7;

/// How much bigger than the hand the crop is when the pose *is* a point. The
/// box is centred on the index-finger tip (the thing being indicated) and sized
/// from the hand, so it has to be a little larger to still include the hand.
pub const POINT_CONTEXT: f32 = 2.4;

/// Long-edge cap for the crop sent to the model (proposal §7.2).
pub const MAX_EDGE: u32 = 512;

/// Smallest crop we will send, in pixels. Below this the model has nothing to
/// look at, so we report "no referent" instead of guessing.
pub const MIN_SIDE: u32 = 32;

/// MediaPipe landmark index of the index-finger tip.
const INDEX_TIP: usize = 8;

/// Normalised `(centre_x, centre_y, half_w, half_h)` of the region to crop,
/// derived from 21 normalised landmarks.
///
/// Returns `None` when there is no usable hand: wrong landmark count,
/// non-finite values, or a degenerate (sub-pixel) extent. Callers treat `None`
/// as "the user is not pointing at anything".
pub fn normalised_region(
    landmarks: &[Point3],
    gesture: Option<GestureId>,
) -> Option<(f32, f32, f32, f32)> {
    if landmarks.len() != 21 {
        return None;
    }
    let (mut min_x, mut max_x) = (f32::MAX, f32::MIN);
    let (mut min_y, mut max_y) = (f32::MAX, f32::MIN);
    for point in landmarks {
        if !point.x.is_finite() || !point.y.is_finite() {
            return None;
        }
        min_x = min_x.min(point.x);
        max_x = max_x.max(point.x);
        min_y = min_y.min(point.y);
        max_y = max_y.max(point.y);
    }
    let hand_w = max_x - min_x;
    let hand_h = max_y - min_y;
    // A collapsed landmark set (all points coincident) cannot define a region.
    if hand_w <= 1e-4 && hand_h <= 1e-4 {
        return None;
    }
    // Never let one degenerate axis collapse the box.
    let hand_w = hand_w.max(0.02);
    let hand_h = hand_h.max(0.02);

    let (cx, cy, scale) = match gesture {
        Some(GestureId::PointingUp) => {
            let tip = landmarks.get(INDEX_TIP)?;
            (tip.x, tip.y, POINT_CONTEXT)
        }
        _ => ((min_x + max_x) / 2.0, (min_y + max_y) / 2.0, HAND_CONTEXT),
    };
    Some((cx, cy, hand_w * scale / 2.0, hand_h * scale / 2.0))
}

/// Clip a normalised region to the frame and convert it to pixels.
///
/// Returns `None` for a zero-sized frame or a region smaller than
/// [`MIN_SIDE`] — the honest answer to "the user is not pointing at anything"
/// rather than a 3-pixel crop the model would hallucinate about.
pub fn pixel_region(region: (f32, f32, f32, f32), width: u32, height: u32) -> Option<Region> {
    let (cx, cy, half_w, half_h) = region;
    if width == 0 || height == 0 {
        return None;
    }
    if !cx.is_finite() || !cy.is_finite() || !half_w.is_finite() || !half_h.is_finite() {
        return None;
    }
    if half_w <= 0.0 || half_h <= 0.0 {
        return None;
    }
    let clamp_x = |v: f32| v.clamp(0.0, width as f32) as u32;
    let clamp_y = |v: f32| v.clamp(0.0, height as f32) as u32;
    let x0 = clamp_x((cx - half_w) * width as f32);
    let x1 = clamp_x((cx + half_w) * width as f32);
    let y0 = clamp_y((cy - half_h) * height as f32);
    let y1 = clamp_y((cy + half_h) * height as f32);
    let w = x1.saturating_sub(x0);
    let h = y1.saturating_sub(y0);
    if w < MIN_SIDE || h < MIN_SIDE {
        return None;
    }
    Some(Region { x: x0, y: y0, w, h })
}

/// One-shot deictic result for the Home panel.
#[derive(Debug, Clone, Serialize)]
pub struct DeicticAnswer {
    pub answer: String,
    /// The pose that supplied the referent, when one was recognised.
    pub gesture: Option<GestureId>,
    /// The crop that was sent, so the UI can show what the model saw.
    pub region: Region,
    /// Round-trip wall time, ms.
    pub elapsed_ms: u64,
}

#[cfg(test)]
mod tests {
    use super::*;

    /// 21 landmarks forming a hand ~0.10 wide / 0.16 tall around (0.5, 0.5).
    fn centred_hand() -> Vec<Point3> {
        let mut points = Vec::with_capacity(21);
        for i in 0..21 {
            let fx = (i % 3) as f32 / 2.0; // 0.0 / 0.5 / 1.0
            let fy = (i % 5) as f32 / 4.0; // 0..1
            points.push(Point3::new(0.45 + fx * 0.10, 0.42 + fy * 0.16, 0.0));
        }
        points
    }

    #[test]
    fn a_hand_centred_in_frame_maps_inside_the_frame() {
        let region = normalised_region(&centred_hand(), None).expect("has a region");
        let px = pixel_region(region, 640, 480).expect("has pixels");
        assert!(px.x + px.w <= 640);
        assert!(px.y + px.h <= 480);
        // Centred on the hand, so the middle of the frame is inside the box.
        assert!(px.x <= 320 && 320 <= px.x + px.w);
        assert!(px.y <= 240 && 240 <= px.y + px.h);
    }

    #[test]
    fn a_pointing_pose_centres_on_the_fingertip_not_the_palm() {
        let mut hand = centred_hand();
        // Fingertip well above the hand body.
        hand[INDEX_TIP] = Point3::new(0.50, 0.20, 0.0);
        let palm = normalised_region(&hand, None).expect("region");
        let point = normalised_region(&hand, Some(GestureId::PointingUp)).expect("region");
        assert!(
            point.1 < palm.1,
            "the point region sits above the palm region"
        );
        assert!((point.0 - 0.50).abs() < 1e-5, "centred on the fingertip x");
        assert!((point.1 - 0.20).abs() < 1e-5, "centred on the fingertip y");
    }

    #[test]
    fn the_point_region_is_larger_than_the_hand() {
        let hand = centred_hand();
        let (_, _, hw, hh) = normalised_region(&hand, Some(GestureId::PointingUp)).expect("region");
        let hand_w = 0.10;
        assert!(hw * 2.0 > hand_w, "context box must exceed the hand");
        assert!(hh > 0.0);
    }

    #[test]
    fn a_region_off_the_edge_is_clipped_not_rejected() {
        // Hand hugging the right edge.
        let mut hand = centred_hand();
        for (i, point) in hand.iter_mut().enumerate() {
            let fx = (i % 3) as f32 / 2.0;
            let fy = (i % 5) as f32 / 4.0;
            point.x = 0.92 + fx * 0.08;
            point.y = 0.10 + fy * 0.10;
        }
        let region = normalised_region(&hand, None).expect("region");
        let px = pixel_region(region, 640, 480).expect("clipped but valid");
        assert_eq!(px.x + px.w, 640);
        assert!(px.y + px.h <= 480);
    }

    #[test]
    fn no_landmarks_is_no_referent() {
        assert!(normalised_region(&[], None).is_none());
        assert!(normalised_region(&centred_hand()[..20], None).is_none());
    }

    #[test]
    fn non_finite_landmarks_are_rejected() {
        let mut hand = centred_hand();
        hand[4] = Point3::new(f32::NAN, 0.5, 0.0);
        assert!(normalised_region(&hand, None).is_none());
    }

    #[test]
    fn a_collapsed_hand_is_rejected() {
        let hand = vec![Point3::new(0.5, 0.5, 0.0); 21];
        assert!(normalised_region(&hand, None).is_none());
    }

    #[test]
    fn a_tiny_region_is_rejected_rather_than_hallucinated() {
        // 0.001 of a 640px frame is well under MIN_SIDE.
        let tiny = (0.5f32, 0.5f32, 0.0005f32, 0.0005f32);
        assert!(pixel_region(tiny, 640, 480).is_none());
    }

    #[test]
    fn a_zero_sized_frame_is_rejected() {
        assert!(pixel_region((0.5, 0.5, 0.1, 0.1), 0, 480).is_none());
        assert!(pixel_region((0.5, 0.5, 0.1, 0.1), 640, 0).is_none());
    }

    #[test]
    fn the_whole_frame_region_is_clamped_to_the_frame() {
        let px = pixel_region((0.5, 0.5, 1.0, 1.0), 640, 480).expect("valid");
        assert_eq!(
            px,
            Region {
                x: 0,
                y: 0,
                w: 640,
                h: 480
            }
        );
    }
}
