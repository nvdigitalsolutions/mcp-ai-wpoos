//! Static pose classification from geometry.
//!
//! Deliberately rule-based for v1: no weights, no download, no licence
//! question, and every decision is a readable expression over named features.
//! A learned classifier can replace [`classify`] later without touching the
//! stabiliser, the router, or the UI.
//!
//! Scores are independent confidences in 0..1, not probabilities. The
//! stabiliser compares them against enter/exit thresholds, and the runner-up
//! is what feeds the ambiguity band.

use crate::gestures::geometry::{ramp, HandShape};
use crate::gestures::GestureId;

/// Minimum score for a pose to be considered at all.
pub const MIN_SCORE: f32 = 0.45;

/// All plausible poses for a hand, highest score first.
pub fn classify(shape: &HandShape) -> Vec<(GestureId, f32)> {
    let fingers = shape.fingers;
    let min_finger = min4(fingers);
    // "All four curled" is the *minimum* curl across the fingers: a single
    // extended finger must veto the fist (otherwise a point scores as a fist).
    let min_curl = shape.min_curl();

    // Open vs stop is a *spread* decision, with a deliberate overlap band so
    // the stabiliser's hysteresis can own the boundary instead of a hard cut.
    let together = 1.0 - ramp(shape.spread, 0.42, 0.62);
    let spread = ramp(shape.spread, 0.42, 0.62);

    let mut out: Vec<(GestureId, f32)> = Vec::with_capacity(8);

    // Thumb direction is the discriminator for the two confirmation poses.
    // A folded thumb sits near the wrist and scores zero on both.
    let up = ramp(-shape.thumb_dir_y, 0.70, 1.00);
    let down = ramp(shape.thumb_dir_y, 0.25, 0.45);

    push(&mut out, GestureId::OpenPalm, min_finger * together);
    push(&mut out, GestureId::StopHand, min_finger * spread);
    push(
        &mut out,
        GestureId::ClosedFist,
        min_curl * (1.0 - up.max(down)),
    );

    // Index-only: the thumb must be *away* from the index tip, otherwise a
    // pinch also reads as a point.
    push(
        &mut out,
        GestureId::PointingUp,
        fingers[0]
            * (1.0 - fingers[1])
            * (1.0 - fingers[2])
            * (1.0 - fingers[3])
            * ramp(shape.thumb_index_gap, 0.30, 0.52),
    );

    push(
        &mut out,
        GestureId::Victory,
        fingers[0] * fingers[1] * (1.0 - fingers[2]) * (1.0 - fingers[3]),
    );

    push(
        &mut out,
        GestureId::Pinch,
        (1.0 - ramp(shape.thumb_index_gap, 0.22, 0.40)) * ramp(fingers[0], 0.15, 0.55),
    );

    // Thumbs up/down are confirmation gestures only (router-enforced); here we
    // only decide whether the *pose* looks like one.
    push(&mut out, GestureId::ThumbUp, min_curl * up);
    push(&mut out, GestureId::ThumbDown, min_curl * down);

    out.sort_by(|a, b| b.1.total_cmp(&a.1));
    out
}

fn push(out: &mut Vec<(GestureId, f32)>, gesture: GestureId, score: f32) {
    if score >= MIN_SCORE {
        out.push((gesture, score.clamp(0.0, 1.0)));
    }
}

fn min4(v: [f32; 4]) -> f32 {
    v.iter().copied().fold(1.0, f32::min)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::gestures::geometry::analyze;
    use crate::gestures::normalize::normalize;
    use crate::gestures::test_support;
    use crate::gestures::Point3;

    fn top(points: &[Point3; 21]) -> (GestureId, f32) {
        let hand = normalize(points, false).expect("fixture normalises");
        let scored = classify(&analyze(&hand));
        *scored.first().expect("a pose is recognised")
    }

    #[test]
    fn the_eight_static_poses_classify_as_themselves() {
        let cases = [
            (test_support::open_palm(), GestureId::OpenPalm),
            (test_support::stop_hand(), GestureId::StopHand),
            (test_support::closed_fist(), GestureId::ClosedFist),
            (test_support::pointing_up(), GestureId::PointingUp),
            (test_support::victory(), GestureId::Victory),
            (test_support::pinch(), GestureId::Pinch),
            (test_support::thumb_up(), GestureId::ThumbUp),
            (test_support::thumb_down(), GestureId::ThumbDown),
        ];

        for (points, expected) in cases {
            let (got, score) = top(&points);
            assert_eq!(got, expected, "expected {expected:?} got {got:?} ({score})");
            assert!(score > 0.7, "{expected:?} scored only {score}");
        }
    }

    #[test]
    fn classification_is_invariant_to_scale_position_and_rotation() {
        let base = test_support::victory();
        let moved = test_support::transform(&base, 0.4, 0.3, 2.2, 0.45);
        assert_eq!(top(&base).0, top(&moved).0);
    }

    #[test]
    fn the_mid_spread_pose_is_genuinely_ambiguous() {
        let hand = normalize(&test_support::half_spread(), false).expect("normalises");
        let scored = classify(&analyze(&hand));

        let open = scored
            .iter()
            .find(|(g, _)| *g == GestureId::OpenPalm)
            .map(|(_, s)| *s);
        let stop = scored
            .iter()
            .find(|(g, _)| *g == GestureId::StopHand)
            .map(|(_, s)| *s);

        assert!(
            open.is_some() && stop.is_some(),
            "both candidates should survive: {scored:?}"
        );
        assert!(
            (open.unwrap() - stop.unwrap()).abs() < 0.25,
            "candidates should be close: {scored:?}"
        );
    }

    #[test]
    fn a_pinch_is_not_also_a_point() {
        let hand = normalize(&test_support::pinch(), false).expect("normalises");
        let scored = classify(&analyze(&hand));
        assert_eq!(scored[0].0, GestureId::Pinch);
        assert!(
            !scored
                .iter()
                .any(|(g, s)| *g == GestureId::PointingUp && *s > 0.5),
            "pinch must not present as a confident point: {scored:?}"
        );
    }

    #[test]
    fn a_fist_does_not_read_as_a_thumbs_up() {
        let hand = normalize(&test_support::closed_fist(), false).expect("normalises");
        let scored = classify(&analyze(&hand));
        assert_eq!(scored[0].0, GestureId::ClosedFist);
        assert!(!scored.iter().any(|(g, _)| *g == GestureId::ThumbUp));
    }
}
