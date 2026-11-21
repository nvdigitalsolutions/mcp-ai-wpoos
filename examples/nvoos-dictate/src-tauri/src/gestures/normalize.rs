//! Landmark normalisation.
//!
//! Raw landmarks mix four things we do not want the classifier to depend on:
//! hand position in frame, distance from the camera, in-plane rotation, and
//! (optionally) whether the buffer was mirrored. This module removes all four
//! so that a pose looks the same at 0.5 m and 3 m, centred or at the frame
//! edge, upright or tilted.
//!
//! The transform is similarity-only (translate + uniform scale + rotate); it
//! deliberately does **not** attempt a 3D pose solve. Depth (`z`) is carried
//! through untouched because it is only used for weak orientation hints.

use crate::gestures::Point3;

/// A hand in its own coordinate frame.
#[derive(Debug, Clone)]
pub struct NormalizedHand {
    /// Landmarks with the wrist at the origin, scaled so the wrist→middle-MCP
    /// distance is 1, and rotated so the index-MCP→pinky-MCP axis is +x.
    pub points: [Point3; 21],
    /// Index-MCP→pinky-MCP length in the normalised frame (≈1, kept for
    /// thresholds expressed in palm widths).
    pub palm_width: f32,
    /// Whether the source buffer was mirrored before normalisation.
    pub mirrored: bool,
}

/// Normalise a 21-landmark hand.
///
/// Returns `None` when the landmarks are degenerate (coincident wrist and
/// middle MCP, or a zero-length knuckle axis), which happens on malformed
/// model output. Callers treat `None` as "no hand this frame" rather than an
/// error.
pub fn normalize(points: &[Point3; 21], mirror: bool) -> Option<NormalizedHand> {
    // Mirroring is a coordinate flip, not a handedness relabel. Doing it here
    // keeps the classifier identical for both buffer conventions; the caller
    // flips `Handedness` separately.
    let mut p = *points;
    if mirror {
        for point in &mut p {
            point.x = -point.x;
        }
    }

    let wrist = p[0];
    let middle_mcp = p[9];
    let scale = wrist.distance_2d(middle_mcp);
    if !scale.is_finite() || scale < 1e-4 {
        return None;
    }

    let knuckle_axis = Point3::new(p[17].x - p[5].x, p[17].y - p[5].y, 0.0);
    let axis_len = knuckle_axis.distance_2d(Point3::default());
    if !axis_len.is_finite() || axis_len < 1e-4 {
        return None;
    }

    let (sin, cos) = (knuckle_axis.y / axis_len, knuckle_axis.x / axis_len);

    let mut out = [Point3::default(); 21];
    for (index, point) in p.iter().enumerate() {
        let dx = (point.x - wrist.x) / scale;
        let dy = (point.y - wrist.y) / scale;
        out[index] = Point3::new(dx * cos + dy * sin, -dx * sin + dy * cos, point.z);
    }

    Some(NormalizedHand {
        points: out,
        palm_width: axis_len / scale,
        mirrored: mirror,
    })
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::gestures::test_support;

    #[test]
    fn rejects_degenerate_landmarks() {
        let flat = [Point3::default(); 21];
        assert!(normalize(&flat, false).is_none());
    }

    #[test]
    fn translation_and_scale_do_not_change_the_pose() {
        let base = test_support::open_palm();
        let moved = test_support::transform(&base, 0.31, 0.72, 2.4, 0.0);

        let a = normalize(&base, false).expect("normalises");
        let b = normalize(&moved, false).expect("normalises");

        for i in 0..21 {
            assert!(
                (a.points[i].x - b.points[i].x).abs() < 1e-3,
                "x drift at {i}: {} vs {}",
                a.points[i].x,
                b.points[i].x
            );
            assert!(
                (a.points[i].y - b.points[i].y).abs() < 1e-3,
                "y drift at {i}: {} vs {}",
                a.points[i].y,
                b.points[i].y
            );
        }
    }

    #[test]
    fn rotation_is_removed() {
        let base = test_support::open_palm();
        let tilted = test_support::transform(&base, 0.5, 0.5, 1.0, 0.6);

        let a = normalize(&base, false).expect("normalises");
        let b = normalize(&tilted, false).expect("normalises");

        for i in 0..21 {
            assert!(
                (a.points[i].x - b.points[i].x).abs() < 2e-3,
                "x drift at {i} under rotation"
            );
            assert!(
                (a.points[i].y - b.points[i].y).abs() < 2e-3,
                "y drift at {i} under rotation"
            );
        }
    }

    #[test]
    fn mirroring_preserves_scale_and_sets_the_flag() {
        let base = test_support::open_palm();
        let normal = normalize(&base, false).expect("normalises");
        let mirrored = normalize(&base, true).expect("normalises");

        assert!(mirrored.mirrored);
        assert!(!normal.mirrored);
        // A mirror is a reflection, not a translation: the wrist stays at the
        // origin and the palm keeps its size.
        assert!(mirrored.points[0].distance_2d(Point3::default()) < 1e-3);
        assert!((normal.palm_width - mirrored.palm_width).abs() < 1e-3);
    }

    #[test]
    fn palm_width_is_scale_invariant() {
        let base = test_support::open_palm();
        let big = test_support::transform(&base, 0.0, 0.0, 5.0, 0.0);
        let a = normalize(&base, false).expect("normalises");
        let b = normalize(&big, false).expect("normalises");
        assert!((a.palm_width - b.palm_width).abs() < 1e-3);
    }
}
