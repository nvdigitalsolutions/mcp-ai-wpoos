// SPDX-License-Identifier: LicenseRef-Proprietary
//! Landmark geometry: turn a normalised hand into interpretable features.
//!
//! Everything here is scale-free by construction (inputs are already
//! normalised to palm length), so a pose is described by *ratios* rather than
//! pixels. That is what makes the same thresholds work at 0.5 m and 3 m.

use crate::gestures::normalize::NormalizedHand;
use crate::gestures::Point3;

/// Geometric description of one normalised hand.
#[derive(Debug, Clone, Copy)]
pub struct HandShape {
    /// Index, middle, ring, pinky extension scores, 0..1.
    pub fingers: [f32; 4],
    /// Mean tip-to-tip gap across the four fingers, in palm widths.
    /// Distinguishes a flat palm from a spread "stop" hand.
    pub spread: f32,
    /// Thumb-tip to index-tip distance, in palm widths. Small = pinch.
    pub thumb_index_gap: f32,
    /// Thumb-tip y relative to the wrist, in palm widths. Negative = up
    /// (image coordinates grow downward).
    pub thumb_dir_y: f32,
    pub palm_center: Point3,
    pub palm_width: f32,
}

/// Compute the feature set from a normalised hand.
pub fn analyze(hand: &NormalizedHand) -> HandShape {
    let p = &hand.points;
    let width = hand.palm_width.max(1e-4);

    let fingers = [
        finger_score(p, 5, 6, 8),
        finger_score(p, 9, 10, 12),
        finger_score(p, 13, 14, 16),
        finger_score(p, 17, 18, 20),
    ];

    let spread = ((p[8].distance_2d(p[12]) + p[12].distance_2d(p[16]) + p[16].distance_2d(p[20]))
        / 3.0)
        / width;

    let thumb_index_gap = p[4].distance_2d(p[8]) / width;
    let thumb_dir_y = (p[4].y - p[0].y) / width;

    let mut center = Point3::default();
    for i in [0usize, 5, 9, 13, 17] {
        center.x += p[i].x;
        center.y += p[i].y;
        center.z += p[i].z;
    }
    center = Point3::new(center.x / 5.0, center.y / 5.0, center.z / 5.0);

    HandShape {
        fingers,
        spread,
        thumb_index_gap,
        thumb_dir_y,
        palm_center: center,
        palm_width: width,
    }
}

/// Extension score for a three-joint chain (`mcp → pip → tip`).
///
/// Two signals are combined: the tip must be farther from the wrist than the
/// PIP joint, and the chain must be roughly straight. A curled finger fails
/// both.
fn finger_score(p: &[Point3; 21], mcp: usize, pip: usize, tip: usize) -> f32 {
    let wrist = p[0];
    let pip_d = wrist.distance_2d(p[pip]).max(1e-4);
    let tip_d = wrist.distance_2d(p[tip]);
    let ratio = tip_d / pip_d;

    let straight = {
        let a = normalize2(sub(p[pip], p[mcp]));
        let b = normalize2(sub(p[tip], p[pip]));
        a.0 * b.0 + a.1 * b.1
    };

    (0.75 * ramp(ratio, 1.02, 1.35) + 0.25 * ramp(straight, -0.20, 0.80)).clamp(0.0, 1.0)
}

/// Clamped linear ramp from 0 at `lo` to 1 at `hi`.
pub fn ramp(x: f32, lo: f32, hi: f32) -> f32 {
    if hi <= lo {
        return if x >= hi { 1.0 } else { 0.0 };
    }
    ((x - lo) / (hi - lo)).clamp(0.0, 1.0)
}

fn sub(a: Point3, b: Point3) -> (f32, f32) {
    (a.x - b.x, a.y - b.y)
}

fn normalize2(v: (f32, f32)) -> (f32, f32) {
    let len = (v.0 * v.0 + v.1 * v.1).sqrt().max(1e-4);
    (v.0 / len, v.1 / len)
}

impl HandShape {
    /// Mean curl of the four fingers (1 = fully curled).
    pub fn min_curl(&self) -> f32 {
        self.fingers.iter().map(|f| 1.0 - f).fold(1.0, f32::min)
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::gestures::normalize::normalize;
    use crate::gestures::test_support;

    fn shape(points: &[Point3; 21]) -> HandShape {
        let hand = normalize(points, false).expect("fixture normalises");
        analyze(&hand)
    }

    #[test]
    fn open_palm_extends_all_five_and_is_not_spread() {
        let s = shape(&test_support::open_palm());
        for (i, f) in s.fingers.iter().enumerate() {
            assert!(*f > 0.8, "finger {i} = {f}");
        }
        assert!(s.spread < 0.45, "spread {}", s.spread);
    }

    #[test]
    fn fist_curls_all_four_fingers() {
        let s = shape(&test_support::closed_fist());
        for (i, f) in s.fingers.iter().enumerate() {
            assert!(*f < 0.2, "finger {i} = {f}");
        }
        assert!(s.min_curl() > 0.8);
    }

    #[test]
    fn stop_hand_is_spread_relative_to_open_palm() {
        let open = shape(&test_support::open_palm());
        let stop = shape(&test_support::stop_hand());
        assert!(
            stop.spread > open.spread + 0.08,
            "stop {} vs open {}",
            stop.spread,
            open.spread
        );
    }

    #[test]
    fn pinch_puts_the_thumb_on_the_index_tip() {
        let pinch = shape(&test_support::pinch());
        assert!(
            pinch.thumb_index_gap < 0.15,
            "gap {}",
            pinch.thumb_index_gap
        );
        let open = shape(&test_support::open_palm());
        assert!(open.thumb_index_gap > 0.7);
    }

    #[test]
    fn thumb_direction_splits_up_from_down() {
        assert!(shape(&test_support::thumb_up()).thumb_dir_y < -0.9);
        assert!(shape(&test_support::thumb_down()).thumb_dir_y > 0.35);
    }

    #[test]
    fn palm_centre_sits_inside_the_knuckle_ring() {
        let points = test_support::open_palm();
        let centre = test_support::palm_center(&points);
        // Between the wrist (y=0) and the middle knuckle (y≈−0.63).
        assert!(centre.y < 0.0 && centre.y > -0.6, "centre {centre:?}");
    }
}
