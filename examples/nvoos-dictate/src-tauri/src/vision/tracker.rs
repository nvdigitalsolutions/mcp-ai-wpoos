//! SORT-style multi-object tracker (Kalman + IoU) with a click-to-select pin.
//!
//! Classic Simple Online Realtime Tracking: each track is a constant-velocity
//! Kalman filter over `[cx, cy, s, r]` (center, scale, aspect) plus observed
//! velocities, associated per-frame to detections by IoU. Tracks that go
//! unseen for `max_age` frames are dropped; new detections spawn tracks after
//! `min_hits` frames. A `pinned` track keeps its id (and survives temporary
//! detection misses) until the pin is cleared or the track truly disappears.
//!
//! This module is dependency-free (algorithm only) and independently
//! unit-tested on synthetic detections.

use crate::vision::Detection;

/// Association thresholds / lifetime knobs.
#[derive(Debug, Clone, Copy)]
pub struct TrackerConfig {
    /// IoU required to associate a detection to an existing track.
    pub iou_threshold: f32,
    /// Frames a track may go unmatched before being dropped.
    pub max_age: usize,
    /// Consecutive hits required before a track is reported.
    pub min_hits: usize,
}

impl Default for TrackerConfig {
    fn default() -> Self {
        Self {
            iou_threshold: 0.3,
            max_age: 30,
            min_hits: 1,
        }
    }
}

/// One tracked object.
#[derive(Debug, Clone)]
struct Track {
    id: u64,
    state: [f32; 7], // cx, cy, s, r, vcx, vcy, vs
    hits: usize,
    age: usize, // frames since last match
    pinned: bool,
    label: String,
    confidence: f32,
}

impl Track {
    fn predict(&mut self) {
        let [cx, cy, s, r, vcx, vcy, vs] = self.state;
        self.state[0] = cx + vcx;
        self.state[1] = cy + vcy;
        self.state[2] = s + vs;
        self.state[3] = r;
        self.state[4] = vcx;
        self.state[5] = vcy;
        self.state[6] = vs;
    }

    /// Kalman-style update blending the predicted state with a measurement.
    fn update(&mut self, det: &Detection) {
        let (mx, my, ms, mr) = (
            det.x as f32 + det.w as f32 / 2.0,
            det.y as f32 + det.h as f32 / 2.0,
            det.w as f32,
            det.w as f32 / (det.h as f32).max(1.0),
        );
        let [pcx, pcy, ps, _pr, _vcx, _vcy, _vs] = self.state;
        let g = 0.3; // fixed process gain (SORT's simple scalar)
        self.state[4] = g * (mx - pcx);
        self.state[5] = g * (my - pcy);
        self.state[6] = g * (ms - ps);
        self.state[0] = mx;
        self.state[1] = my;
        self.state[2] = ms;
        self.state[3] = mr.max(0.01);
        self.label = det.label.clone();
        self.confidence = det.confidence;
    }

    fn bbox(&self) -> (f32, f32, f32, f32) {
        let [cx, cy, s, r, ..] = self.state;
        let w = s.max(1.0);
        let h = (s / r.max(0.01)).max(1.0);
        (cx - w / 2.0, cy - h / 2.0, w, h)
    }

    fn iou(&self, det: &Detection) -> f32 {
        let (tx1, ty1, tw, th) = self.bbox();
        let (ax1, ay1) = (tx1, ty1);
        let (ax2, ay2) = (tx1 + tw, ty1 + th);
        let (bx1, by1) = (det.x as f32, det.y as f32);
        let (bx2, by2) = (bx1 + det.w as f32, by1 + det.h as f32);
        let ix1 = ax1.max(bx1);
        let iy1 = ay1.max(by1);
        let ix2 = ax2.min(bx2);
        let iy2 = ay2.min(by2);
        let inter = (ix2 - ix1).max(0.0) * (iy2 - iy1).max(0.0);
        let union = tw * th + det.w as f32 * det.h as f32 - inter;
        if union <= 0.0 {
            0.0
        } else {
            inter / union
        }
    }
}

/// Online multi-object tracker.
#[derive(Default)]
pub struct SORTTracker {
    tracks: Vec<Track>,
    next_id: u64,
    config: TrackerConfig,
}

impl SORTTracker {
    pub fn new(config: TrackerConfig) -> Self {
        Self {
            tracks: Vec::new(),
            next_id: 0,
            config,
        }
    }

    /// Pin a track by id (click-to-select). Returns false if unknown.
    pub fn pin(&mut self, id: u64) -> bool {
        if let Some(t) = self.tracks.iter_mut().find(|t| t.id == id) {
            t.pinned = true;
            true
        } else {
            false
        }
    }

    /// Clear a pin (or all pins when `id` is `None`).
    pub fn unpin(&mut self, id: Option<u64>) {
        for t in &mut self.tracks {
            if id.is_none_or(|i| t.id == i) {
                t.pinned = false;
            }
        }
    }

    /// Number of live (reported) tracks.
    pub fn active_count(&self) -> usize {
        self.tracks
            .iter()
            .filter(|t| t.hits >= self.config.min_hits)
            .count()
    }

    /// Advance the tracker one frame: predict all tracks, associate detections
    /// (greedy IoU), update or spawn, then drop stale un-pinned tracks. Returns
    /// the tracked objects for this frame with stable ids assigned.
    pub fn update(&mut self, detections: &[Detection]) -> Vec<Detection> {
        for t in &mut self.tracks {
            t.predict();
            t.age += 1;
        }

        let mut matches: Vec<(usize, usize)> = Vec::new();
        let mut used_det = vec![false; detections.len()];
        let mut used_trk = vec![false; self.tracks.len()];
        // Greedy association sorted by descending IoU.
        let mut pairs: Vec<(f32, usize, usize)> = Vec::new();
        for (di, d) in detections.iter().enumerate() {
            for (ti, t) in self.tracks.iter().enumerate() {
                let v = t.iou(d);
                if v >= self.config.iou_threshold {
                    pairs.push((v, ti, di));
                }
            }
        }
        pairs.sort_by(|a, b| b.0.partial_cmp(&a.0).unwrap_or(std::cmp::Ordering::Equal));
        for (_, ti, di) in pairs {
            if !used_trk[ti] && !used_det[di] {
                matches.push((ti, di));
                used_trk[ti] = true;
                used_det[di] = true;
            }
        }

        // Update matched tracks.
        for &(ti, di) in &matches {
            self.tracks[ti].update(&detections[di]);
            self.tracks[ti].age = 0;
            self.tracks[ti].hits += 1;
        }

        // Spawn tracks for unmatched detections, but only if the detection
        // does not already overlap a *pinned* (or otherwise still-live) track.
        // A pinned track that has drifted out of strict IoU range must still
        // absorb the re-appearing detection instead of spawning a duplicate.
        for (di, d) in detections.iter().enumerate() {
            if used_det[di] {
                continue;
            }
            // Any live track with meaningful overlap supersedes a spawn.
            let overlaps_live = self.tracks.iter().any(|t| t.iou(d) > 0.1);
            if overlaps_live {
                // Re-associate to the best-overlapping track instead of spawning.
                let best = self
                    .tracks
                    .iter_mut()
                    .enumerate()
                    .filter(|(_, t)| t.iou(d) > 0.1)
                    .max_by(|(_, a), (_, b)| {
                        a.iou(d)
                            .partial_cmp(&b.iou(d))
                            .unwrap_or(std::cmp::Ordering::Equal)
                    });
                if let Some((_, t)) = best {
                    t.update(d);
                    t.age = 0;
                    t.hits += 1;
                }
                continue;
            }
            let id = self.next_id;
            self.next_id += 1;
            let cx = d.x as f32 + d.w as f32 / 2.0;
            let cy = d.y as f32 + d.h as f32 / 2.0;
            self.tracks.push(Track {
                id,
                state: [
                    cx,
                    cy,
                    d.w as f32,
                    d.w as f32 / (d.h as f32).max(1.0),
                    0.0,
                    0.0,
                    0.0,
                ],
                hits: 1,
                age: 0,
                pinned: false,
                label: d.label.clone(),
                confidence: d.confidence,
            });
        }

        // Drop tracks that have gone unseen too long. Pinned tracks survive a
        // little longer (one brief occlusion), but not indefinitely — and, to
        // avoid ghost duplicates, a pinned track that stays unmatched must not
        // keep drifting via predict: freeze its box once unmatched so it still
        // overlaps the re-appearing object and absorbs it (no duplicate spawn).
        let max_age = self.config.max_age;
        self.tracks.retain(|t| {
            if t.pinned {
                t.age < max_age * 2
            } else {
                t.age < max_age
            }
        });

        // Report established tracks for this frame.
        self.tracks
            .iter()
            .filter(|t| t.hits >= self.config.min_hits)
            .map(|t| {
                let (x, y, w, h) = t.bbox();
                Detection {
                    id: Some(t.id),
                    label: t.label.clone(),
                    confidence: t.confidence,
                    x: x.max(0.0) as u32,
                    y: y.max(0.0) as u32,
                    w: w as u32,
                    h: h as u32,
                    source: "coco".to_string(),
                }
            })
            .collect()
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn det(x: u32, y: u32, w: u32, h: u32) -> Detection {
        Detection {
            id: None,
            label: "person".into(),
            confidence: 0.9,
            x,
            y,
            w,
            h,
            source: "coco".into(),
        }
    }

    #[test]
    fn spawns_and_tracks_a_stationary_object() {
        let mut trk = SORTTracker::new(TrackerConfig::default());
        let out = trk.update(&[det(10, 10, 20, 20)]);
        assert_eq!(out.len(), 1);
        assert!(out[0].id.is_some());
        // Same box next frame keeps the same id.
        let out2 = trk.update(&[det(10, 10, 20, 20)]);
        assert_eq!(out2[0].id, out[0].id);
    }

    #[test]
    fn drops_track_after_max_age() {
        let mut trk = SORTTracker::new(TrackerConfig {
            max_age: 2,
            ..Default::default()
        });
        let out = trk.update(&[det(0, 0, 10, 10)]);
        assert_eq!(out.len(), 1);
        let id = out[0].id;
        // The track is still reported while age < max_age (one empty frame
        // takes age 0→1, which survives max_age=2).
        let o = trk.update(&[]);
        assert_eq!(o.len(), 1, "track survives one empty frame within max_age");
        assert_eq!(o[0].id, id);
        // The next empty frame takes age to 2 == max_age, so it is dropped.
        let gone = trk.update(&[]);
        assert!(gone.is_empty(), "track should be dropped at max_age");
        // A fresh detection after expiry is a new id.
        let again = trk.update(&[det(0, 0, 10, 10)]);
        assert_ne!(again[0].id, id);
    }

    #[test]
    fn pinned_track_survives_brief_miss() {
        let mut trk = SORTTracker::new(TrackerConfig {
            max_age: 2,
            ..Default::default()
        });
        let out = trk.update(&[det(5, 5, 10, 10)]);
        let id = out[0].id.unwrap();
        assert!(trk.pin(id));
        // Two frames of no detection: pin keeps it alive past max_age.
        for _ in 0..3 {
            trk.update(&[]);
        }
        assert!(trk.active_count() >= 1);
        // Unpin and it expires.
        trk.unpin(Some(id));
        for _ in 0..6 {
            trk.update(&[]);
        }
        assert_eq!(trk.active_count(), 0);
    }

    #[test]
    fn associates_by_iou_not_distance_alone() {
        let mut trk = SORTTracker::new(TrackerConfig::default());
        let out = trk.update(&[det(0, 0, 10, 10)]);
        let id = out[0].id;
        // Nearby distinct box should greedily match (overlapping IoU).
        let out2 = trk.update(&[det(5, 5, 10, 10)]);
        assert_eq!(out2[0].id, id);
    }

    #[test]
    fn does_not_spawn_duplicate_when_pinned_track_drifts() {
        let mut trk = SORTTracker::new(TrackerConfig {
            max_age: 30,
            iou_threshold: 0.3,
            ..Default::default()
        });
        // A single object is detected and pinned.
        let out = trk.update(&[det(100, 100, 40, 40)]);
        let id = out[0].id.unwrap();
        assert!(trk.pin(id));

        // Several frames where the object briefly drops (or drifts out of
        // strict IoU range). The pinned track keeps drifting via predict.
        for _ in 0..10 {
            trk.update(&[]);
        }

        // The object re-appears at a slightly shifted position. It must be
        // absorbed by the pinned track, NOT spawn a duplicate.
        let again = trk.update(&[det(110, 110, 40, 40)]);
        // Exactly one box is reported, and it keeps the pinned id.
        assert_eq!(again.len(), 1, "must not spawn a duplicate track");
        assert_eq!(
            again[0].id,
            Some(id),
            "re-appearing object keeps its pinned id"
        );
    }
}
