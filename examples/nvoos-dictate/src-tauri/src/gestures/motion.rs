// SPDX-License-Identifier: LicenseRef-Proprietary
//! Motion primitives over a short palm-centre history.
//!
//! Static classification answers "what pose is this"; motion answers "what did
//! the hand just do". Keeping them separate is deliberate: MediaPipe's stock
//! classifier is per-frame and has no swipe class, and a temporal model is a
//! much larger commitment than four velocity thresholds.
//!
//! Displacement is measured in **palm widths**, so moving the hand closer to
//! the camera does not silently change the trigger distance.

use std::collections::VecDeque;

use crate::gestures::geometry::ramp;
use crate::gestures::Point3;

/// Tunables for the motion stage.
#[derive(Debug, Clone, Copy)]
pub struct MotionConfig {
    /// Window used to measure a swipe.
    pub window_ms: u64,
    /// Window used to count wave reversals.
    pub wave_window_ms: u64,
    /// Minimum displacement, in palm widths, for a swipe.
    pub min_displacement: f32,
    /// Maximum cross-axis drift as a fraction of the main axis.
    pub max_cross_ratio: f32,
    /// Minimum per-sample lateral movement that counts as a direction change.
    pub reversal_step: f32,
    /// Reversals required for a wave.
    pub wave_reversals: usize,
}

impl Default for MotionConfig {
    fn default() -> Self {
        Self {
            window_ms: 500,
            wave_window_ms: 1000,
            min_displacement: 1.8,
            max_cross_ratio: 0.7,
            reversal_step: 0.20,
            wave_reversals: 3,
        }
    }
}

#[derive(Debug, Clone, Copy)]
struct Sample {
    at_ms: u64,
    center: Point3,
    palm_width: f32,
}

/// Rolling trajectory buffer. Fed one sample per detected frame.
#[derive(Debug)]
pub struct MotionTracker {
    cfg: MotionConfig,
    samples: VecDeque<Sample>,
    reversals: VecDeque<u64>,
    last_sign: i32,
}

impl MotionTracker {
    pub fn new(cfg: MotionConfig) -> Self {
        Self {
            cfg,
            samples: VecDeque::with_capacity(32),
            reversals: VecDeque::with_capacity(8),
            last_sign: 0,
        }
    }

    /// Drop all history (hand left the frame, or the loop restarted).
    pub fn reset(&mut self) {
        self.samples.clear();
        self.reversals.clear();
        self.last_sign = 0;
    }

    /// Record one frame.
    pub fn push(&mut self, at_ms: u64, center: Point3, palm_width: f32) {
        let width = if palm_width.is_finite() && palm_width > 1e-4 {
            palm_width
        } else {
            1.0
        };

        if let Some(prev) = self.samples.back() {
            let dx = (center.x - prev.center.x) / width;
            if dx.abs() >= self.cfg.reversal_step {
                let sign = if dx > 0.0 { 1 } else { -1 };
                if self.last_sign != 0 && sign != self.last_sign {
                    self.reversals.push_back(at_ms);
                }
                self.last_sign = sign;
            }
        }

        self.samples.push_back(Sample {
            at_ms,
            center,
            palm_width: width,
        });

        let keep_ms = self.cfg.window_ms.max(self.cfg.wave_window_ms) + 100;
        while let Some(front) = self.samples.front() {
            if at_ms.saturating_sub(front.at_ms) > keep_ms {
                self.samples.pop_front();
            } else {
                break;
            }
        }
    }

    /// Detect a swipe or wave from the current buffer.
    ///
    /// A detected swipe consumes the window (only the newest sample is kept)
    /// so the same motion cannot fire twice while it is still in the buffer.
    pub fn detect(&mut self, at_ms: u64) -> Vec<(crate::gestures::GestureId, f32)> {
        use crate::gestures::GestureId;

        let mut out = Vec::with_capacity(2);

        // ---- swipe (short window) ----
        let window_start = at_ms.saturating_sub(self.cfg.window_ms);
        let window: Vec<&Sample> = self
            .samples
            .iter()
            .filter(|s| s.at_ms >= window_start)
            .collect();

        let mut fired_swipe = false;
        if window.len() >= 3 {
            let first = window.first().unwrap();
            let last = window.last().unwrap();
            let width = mean_width(&window);
            let dx = (last.center.x - first.center.x) / width;
            let dy = (last.center.y - first.center.y) / width;

            let min = self.cfg.min_displacement;

            if dx.abs() >= min && dy.abs() <= self.cfg.max_cross_ratio * dx.abs() {
                let score = ramp(dx.abs(), min, min + 1.2);
                out.push((
                    if dx > 0.0 {
                        GestureId::SwipeRight
                    } else {
                        GestureId::SwipeLeft
                    },
                    score,
                ));
                fired_swipe = true;
            } else if dy.abs() >= min && dx.abs() <= self.cfg.max_cross_ratio * dy.abs() {
                let score = ramp(dy.abs(), min, min + 1.2);
                out.push((
                    // Image y grows downward.
                    if dy > 0.0 {
                        GestureId::SwipeDown
                    } else {
                        GestureId::SwipeUp
                    },
                    score,
                ));
                fired_swipe = true;
            }
        }

        if fired_swipe {
            if let Some(latest) = self.samples.back().copied() {
                self.samples.clear();
                self.samples.push_back(latest);
            }
            self.reversals.clear();
            self.last_sign = 0;
        } else {
            // ---- wave (longer window) ----
            let wave_start = at_ms.saturating_sub(self.cfg.wave_window_ms);
            while let Some(front) = self.reversals.front() {
                if *front < wave_start {
                    self.reversals.pop_front();
                } else {
                    break;
                }
            }
            if self.reversals.len() >= self.cfg.wave_reversals {
                let score = ramp(
                    self.reversals.len() as f32,
                    self.cfg.wave_reversals as f32,
                    self.cfg.wave_reversals as f32 + 2.0,
                );
                out.push((GestureId::Wave, score));
                // One wave per burst: require the buffer to refill.
                self.reversals.clear();
                self.last_sign = 0;
            }
        }

        out
    }
}

fn mean_width(window: &[&Sample]) -> f32 {
    let sum: f32 = window.iter().map(|s| s.palm_width).sum();
    (sum / window.len() as f32).max(1e-4)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::gestures::GestureId;

    fn at(x: f32, y: f32) -> Point3 {
        Point3::new(x, y, 0.0)
    }

    fn ids(found: &[(GestureId, f32)]) -> Vec<GestureId> {
        found.iter().map(|(g, _)| *g).collect()
    }

    #[test]
    fn a_fast_horizontal_move_is_one_swipe() {
        let mut t = MotionTracker::new(MotionConfig::default());
        for i in 0..7 {
            t.push(i * 50, at(i as f32 * 0.4, 0.0), 1.0);
        }
        let found = t.detect(300);
        assert_eq!(ids(&found), vec![GestureId::SwipeRight]);
        assert!(found[0].1 > 0.45);

        // The same buffer must not fire again.
        assert!(t.detect(320).is_empty());
    }

    #[test]
    fn a_slow_drift_is_not_a_swipe() {
        let mut t = MotionTracker::new(MotionConfig::default());
        for i in 0..7 {
            t.push(i * 80, at(i as f32 * 0.12, 0.0), 1.0);
        }
        assert!(t.detect(480).is_empty());
    }

    #[test]
    fn vertical_motion_maps_to_scroll_directions() {
        let mut down = MotionTracker::new(MotionConfig::default());
        for i in 0..7 {
            down.push(i * 50, at(0.0, i as f32 * 0.4), 1.0);
        }
        assert_eq!(ids(&down.detect(300)), vec![GestureId::SwipeDown]);

        let mut up = MotionTracker::new(MotionConfig::default());
        for i in 0..7 {
            up.push(i * 50, at(0.0, -(i as f32) * 0.4), 1.0);
        }
        assert_eq!(ids(&up.detect(300)), vec![GestureId::SwipeUp]);
    }

    #[test]
    fn a_swipe_does_not_also_report_a_wave() {
        let mut t = MotionTracker::new(MotionConfig::default());
        for i in 0..7 {
            t.push(i * 50, at(i as f32 * 0.4, 0.0), 1.0);
        }
        let found = t.detect(300);
        assert!(!ids(&found).contains(&GestureId::Wave));
    }

    #[test]
    fn lateral_reversals_produce_one_wave() {
        let mut t = MotionTracker::new(MotionConfig::default());
        // +0.5, −0.5, +0.5, −0.5 → three direction changes, small net motion.
        let xs = [0.0, 0.5, 0.0, 0.5, 0.0];
        for (i, x) in xs.iter().enumerate() {
            t.push(i as u64 * 100, at(*x, 0.0), 1.0);
        }
        let found = t.detect(400);
        assert_eq!(ids(&found), vec![GestureId::Wave]);

        // Cleared after firing.
        assert!(t.detect(420).is_empty());
    }

    #[test]
    fn reset_clears_everything() {
        let mut t = MotionTracker::new(MotionConfig::default());
        for i in 0..7 {
            t.push(i * 50, at(i as f32 * 0.4, 0.0), 1.0);
        }
        t.reset();
        assert!(t.detect(400).is_empty());
    }
}
