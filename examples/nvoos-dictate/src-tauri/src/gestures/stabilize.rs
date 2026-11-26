// SPDX-License-Identifier: LicenseRef-Proprietary
//! Temporal stabilisation.
//!
//! Raw per-frame classification is unusable as input: a pose hovers at the
//! decision boundary and flickers between two labels, a hand entering the
//! frame produces one confident frame, and a held pose would otherwise fire
//! its action every frame. This module turns a stream of per-frame candidates
//! into *stable observations* using four independent mechanisms:
//!
//! 1. **enter/exit hysteresis** — a different pose must clear the enter bar to
//!    replace the current one, and the current pose survives until it falls
//!    below the exit bar;
//! 2. **N-of-M debounce** — the winner must repeat before it is reported;
//! 3. **track gating** — a hand must be present for a few frames before any
//!    gesture can fire;
//! 4. **held duration** — reported with every observation so the router can
//!    require a deliberate hold for arming.
//!
//! Deliberately *not* here: action cooldowns. Those belong to the router,
//! because they are per-action rather than per-gesture.

use std::collections::VecDeque;

use crate::gestures::{BBox, GestureId, GestureObservation, Handedness};

/// Stabiliser tunables (exposed in Settings).
#[derive(Debug, Clone, Copy)]
pub struct StabilizerConfig {
    /// Score required to take over as the current gesture.
    pub enter: f32,
    /// Score below which the current gesture is released.
    pub exit: f32,
    /// How many of the last `window` frames must agree.
    pub agree: usize,
    /// Sliding window size for the agreement check.
    pub window: usize,
    /// Frames a hand must be continuously present before gestures count.
    pub min_track_frames: u32,
}

impl Default for StabilizerConfig {
    fn default() -> Self {
        Self {
            enter: 0.75,
            exit: 0.55,
            agree: 3,
            window: 4,
            min_track_frames: 3,
        }
    }
}

/// One frame's worth of evidence: candidates sorted or unsorted, plus context.
#[derive(Debug, Clone, Copy)]
pub struct FrameEvidence<'a> {
    pub candidates: &'a [(GestureId, f32)],
    pub handedness: Handedness,
    pub bbox: BBox,
    pub hand_present: bool,
}

/// Stateful stabiliser. One instance per hand track.
#[derive(Debug)]
pub struct Stabilizer {
    cfg: StabilizerConfig,
    current: Option<GestureId>,
    current_since: u64,
    history: VecDeque<GestureId>,
    track_frames: u32,
}

impl Stabilizer {
    pub fn new(cfg: StabilizerConfig) -> Self {
        Self {
            cfg,
            current: None,
            current_since: 0,
            history: VecDeque::with_capacity(cfg.window.max(1)),
            track_frames: 0,
        }
    }

    pub fn config(&self) -> StabilizerConfig {
        self.cfg
    }

    /// The pose currently being tracked (even if not yet debounce-confirmed).
    pub fn current(&self) -> Option<GestureId> {
        self.current
    }

    /// Forget all history (hand lost, loop restarted, camera stopped).
    pub fn reset(&mut self) {
        self.current = None;
        self.current_since = 0;
        self.history.clear();
        self.track_frames = 0;
    }

    /// Feed one frame; returns an observation when the pose is stable.
    pub fn update(&mut self, at_ms: u64, frame: FrameEvidence<'_>) -> Option<GestureObservation> {
        if !frame.hand_present {
            self.reset();
            return None;
        }

        self.track_frames = self.track_frames.saturating_add(1);
        if self.track_frames < self.cfg.min_track_frames {
            return None;
        }

        let top = frame
            .candidates
            .iter()
            .copied()
            .max_by(|a, b| a.1.total_cmp(&b.1))?;

        match self.current {
            Some(current) => {
                let current_score = frame
                    .candidates
                    .iter()
                    .find(|(g, _)| *g == current)
                    .map(|(_, s)| *s)
                    .unwrap_or(0.0);

                if top.0 != current {
                    if top.1 >= self.cfg.enter {
                        self.switch_to(top.0, at_ms);
                    } else if current_score < self.cfg.exit {
                        self.release();
                        return None;
                    }
                } else if current_score < self.cfg.exit {
                    self.release();
                    return None;
                }
            }
            None => {
                if top.1 >= self.cfg.enter {
                    self.switch_to(top.0, at_ms);
                } else {
                    return None;
                }
            }
        }

        let current = self.current?;

        self.history.push_back(current);
        while self.history.len() > self.cfg.window.max(1) {
            self.history.pop_front();
        }
        let agree = self.cfg.agree.clamp(1, self.cfg.window);
        if self.history.len() < agree {
            return None;
        }
        let tail_agrees = self.history.iter().rev().take(agree).all(|g| *g == current);
        if !tail_agrees {
            return None;
        }

        let score = frame
            .candidates
            .iter()
            .find(|(g, _)| *g == current)
            .map(|(_, s)| *s)
            .unwrap_or(0.0);

        Some(GestureObservation {
            gesture: current,
            confidence: score,
            handedness: frame.handedness,
            bbox: frame.bbox,
            held_ms: at_ms.saturating_sub(self.current_since),
            at_ms,
        })
    }

    fn switch_to(&mut self, gesture: GestureId, at_ms: u64) {
        self.current = Some(gesture);
        self.current_since = at_ms;
        self.history.clear();
    }

    fn release(&mut self) {
        self.current = None;
        self.current_since = 0;
        self.history.clear();
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    const BBOX: BBox = BBox {
        x: 10,
        y: 20,
        w: 100,
        h: 80,
    };

    fn frame(candidates: &[(GestureId, f32)], present: bool) -> FrameEvidence<'_> {
        FrameEvidence {
            candidates,
            handedness: Handedness::Right,
            bbox: BBOX,
            hand_present: present,
        }
    }

    fn feed(
        s: &mut Stabilizer,
        at_ms: u64,
        gesture: GestureId,
        score: f32,
    ) -> Option<GestureObservation> {
        let c = [(gesture, score)];
        s.update(at_ms, frame(&c, true))
    }

    #[test]
    fn a_hand_entering_the_frame_cannot_fire_immediately() {
        let mut s = Stabilizer::new(StabilizerConfig::default());
        assert!(feed(&mut s, 0, GestureId::OpenPalm, 0.99).is_none());
        assert!(feed(&mut s, 66, GestureId::OpenPalm, 0.99).is_none());
        // Third frame passes track gating and names the pose, but the debounce
        // still needs three agreeing frames.
        assert!(feed(&mut s, 132, GestureId::OpenPalm, 0.99).is_none());
        assert!(feed(&mut s, 198, GestureId::OpenPalm, 0.99).is_none());
        assert!(feed(&mut s, 264, GestureId::OpenPalm, 0.99).is_some());
    }

    #[test]
    fn a_single_frame_flip_does_not_change_the_observation() {
        let mut s = Stabilizer::new(StabilizerConfig::default());
        for i in 0..4 {
            feed(&mut s, i * 66, GestureId::OpenPalm, 0.95);
        }
        let held = feed(&mut s, 264, GestureId::OpenPalm, 0.95).expect("stable");

        // One frame of a *plausible but not convincing* alternative must not
        // disturb the held pose; the stable observation continues.
        let c = [(GestureId::OpenPalm, 0.90), (GestureId::ClosedFist, 0.60)];
        let mid = s.update(330, frame(&c, true)).expect("still stable");
        assert_eq!(mid.gesture, GestureId::OpenPalm);

        // Back to the original: still the same observation, with growing hold.
        let back = feed(&mut s, 396, GestureId::OpenPalm, 0.95).expect("stable again");
        assert_eq!(back.gesture, GestureId::OpenPalm);
        assert!(back.held_ms > held.held_ms);
    }

    #[test]
    fn hysteresis_keeps_the_current_pose_inside_the_band() {
        let mut s = Stabilizer::new(StabilizerConfig::default());
        for i in 0..5 {
            feed(&mut s, i * 66, GestureId::OpenPalm, 0.90);
        }
        // The alternative is above exit but below enter: it must not take over.
        let c = [(GestureId::OpenPalm, 0.62), (GestureId::StopHand, 0.70)];
        let obs = s.update(400, frame(&c, true)).expect("still stable");
        assert_eq!(obs.gesture, GestureId::OpenPalm);
    }

    #[test]
    fn a_confident_replacement_switches_after_debounce() {
        let mut s = Stabilizer::new(StabilizerConfig::default());
        for i in 0..5 {
            feed(&mut s, i * 66, GestureId::OpenPalm, 0.90);
        }
        let c = [(GestureId::ClosedFist, 0.92)];
        // Debounce resets on the switch: the first frames after it do not fire.
        assert!(s.update(400, frame(&c, true)).is_none());
        assert!(s.update(466, frame(&c, true)).is_none());
        let obs = s.update(532, frame(&c, true)).expect("committed");
        assert_eq!(obs.gesture, GestureId::ClosedFist);
    }

    #[test]
    fn losing_the_current_pose_releases_it() {
        let mut s = Stabilizer::new(StabilizerConfig::default());
        for i in 0..5 {
            feed(&mut s, i * 66, GestureId::OpenPalm, 0.90);
        }
        let c = [(GestureId::OpenPalm, 0.20)];
        assert!(s.update(400, frame(&c, true)).is_none());
        assert_eq!(s.current(), None);
    }

    #[test]
    fn losing_the_hand_resets_the_hold_timer() {
        let mut s = Stabilizer::new(StabilizerConfig::default());
        for i in 0..5 {
            feed(&mut s, i * 66, GestureId::OpenPalm, 0.90);
        }
        let before = feed(&mut s, 330, GestureId::OpenPalm, 0.90).expect("stable");
        assert!(before.held_ms >= 120);

        let empty: [(GestureId, f32); 0] = [];
        assert!(s.update(400, frame(&empty, false)).is_none());

        for i in 0..5 {
            feed(&mut s, 500 + i * 66, GestureId::OpenPalm, 0.90);
        }
        let after = feed(&mut s, 764, GestureId::OpenPalm, 0.90).expect("stable again");
        assert!(
            after.held_ms < before.held_ms,
            "hold should restart: {} vs {}",
            after.held_ms,
            before.held_ms
        );
    }

    #[test]
    fn empty_candidates_never_fire() {
        let mut s = Stabilizer::new(StabilizerConfig::default());
        let empty: [(GestureId, f32); 0] = [];
        for i in 0..10 {
            assert!(s.update(i * 66, frame(&empty, true)).is_none());
        }
    }
}
