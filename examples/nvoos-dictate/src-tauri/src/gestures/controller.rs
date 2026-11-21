//! The resident gesture loop.
//!
//! One worker (`nvoos-gesture`) owns the backend, the stabiliser, the motion
//! tracker and the router. It pulls the newest frame from the camera ring at
//! ~15 fps, publishes a pollable [`GestureSnapshot`] for the UI, and hands
//! routed intents to a sink (the Tauri layer), which is responsible for
//! executing them and for the guarded-action confirmation.
//!
//! Threading rules mirror `vision/controller.rs`: the backend lives on this
//! thread only and is dropped when the controller is dropped, which is what
//! lets Windows replace a model file that a previous session had open.

use std::sync::atomic::{AtomicU64, Ordering};
#[cfg(feature = "gestures")]
use std::sync::mpsc::channel;
use std::sync::mpsc::Sender;
use std::sync::{Arc, Mutex};
#[cfg(feature = "gestures")]
use std::time::Duration;
use std::time::Instant;

use serde::Serialize;

use crate::camera::PreviewFrame;
use crate::error::{AppError, AppResult};
#[cfg(feature = "gestures")]
use crate::gestures::classifier::classify;
#[cfg(feature = "gestures")]
use crate::gestures::geometry::analyze;
use crate::gestures::landmarks::HandPoseBackend;
#[cfg(not(feature = "gestures"))]
use crate::gestures::motion::MotionConfig;
#[cfg(feature = "gestures")]
use crate::gestures::motion::{MotionConfig, MotionTracker};
#[cfg(feature = "gestures")]
use crate::gestures::normalize::normalize;
#[cfg(feature = "gestures")]
use crate::gestures::router::GestureContext;
use crate::gestures::router::{Router, RouterConfig};
#[cfg(not(feature = "gestures"))]
use crate::gestures::stabilize::StabilizerConfig;
#[cfg(feature = "gestures")]
use crate::gestures::stabilize::{FrameEvidence, Stabilizer, StabilizerConfig};
#[cfg(feature = "gestures")]
use crate::gestures::GestureObservation;
use crate::gestures::{ActionId, GestureId, GestureIntent, Handedness, Point3};

/// Loop cadence: the same 15 fps the object detector uses, so a build with
/// both features enabled can co-schedule on one frame.
#[cfg(feature = "gestures")]
const TICK: Duration = Duration::from_millis(66);

/// Everything the loop needs, assembled from Settings.
#[derive(Debug, Clone)]
pub struct GestureRunConfig {
    pub stabilizer: StabilizerConfig,
    pub motion: MotionConfig,
    pub router: RouterConfig,
    /// Disarm after this long without a hand in frame.
    pub disarm_after_ms: u64,
}

impl Default for GestureRunConfig {
    fn default() -> Self {
        Self {
            stabilizer: StabilizerConfig::default(),
            motion: MotionConfig::default(),
            router: RouterConfig::default(),
            disarm_after_ms: 10_000,
        }
    }
}

/// Pollable state for the Home panel and Diagnostics.
#[derive(Debug, Clone, Serialize)]
pub struct GestureSnapshot {
    pub running: bool,
    pub armed: bool,
    pub gesture: Option<GestureId>,
    pub confidence: f32,
    pub held_ms: u64,
    pub handedness: Handedness,
    /// 21 normalised landmarks for the overlay (empty when no hand).
    pub landmarks: Vec<Point3>,
    pub at_ms: u64,
    /// The most recent routed action, for the "last action" chip.
    pub last_action: Option<ActionId>,
}

impl Default for GestureSnapshot {
    fn default() -> Self {
        Self {
            running: false,
            armed: false,
            gesture: None,
            confidence: 0.0,
            held_ms: 0,
            handedness: Handedness::Unknown,
            landmarks: Vec::new(),
            at_ms: 0,
            last_action: None,
        }
    }
}

#[derive(Default)]
pub(crate) struct GestureStats {
    pub frames: AtomicU64,
    pub detections: AtomicU64,
    pub observations: AtomicU64,
    pub intents: AtomicU64,
    pub errors: AtomicU64,
    pub last_infer_ms: AtomicU64,
    pub last_error: Mutex<Option<String>>,
}

/// Called once per routed action. Must not block the loop.
pub type IntentSink = Arc<dyn Fn(GestureIntent) + Send + Sync>;

/// Resident gesture controller.
pub struct GestureController {
    snapshot: Arc<Mutex<GestureSnapshot>>,
    stats: Arc<GestureStats>,
    router: Arc<Mutex<Router>>,
    started: Instant,
    stop_tx: Option<Sender<()>>,
}

impl GestureController {
    /// Start the loop against `frame` and emit intents to `sink`.
    ///
    /// With the `gestures` feature off this returns `Unsupported` in the same
    /// shape as every other optional surface, so `AppState` stays type-stable.
    pub fn start(
        backend: Box<dyn HandPoseBackend>,
        cfg: GestureRunConfig,
        frame: Arc<Mutex<Option<PreviewFrame>>>,
        sink: IntentSink,
    ) -> AppResult<Self> {
        #[cfg(not(feature = "gestures"))]
        {
            let _ = (backend, cfg, frame, sink);
            Err(AppError::Unsupported(
                "hand gestures are not compiled into this build".into(),
            ))
        }

        #[cfg(feature = "gestures")]
        {
            Self::start_inner(backend, cfg, frame, sink)
        }
    }

    #[cfg(feature = "gestures")]
    fn start_inner(
        backend: Box<dyn HandPoseBackend>,
        cfg: GestureRunConfig,
        frame: Arc<Mutex<Option<PreviewFrame>>>,
        sink: IntentSink,
    ) -> AppResult<Self> {
        let snapshot = Arc::new(Mutex::new(GestureSnapshot {
            running: true,
            ..Default::default()
        }));
        let stats = Arc::new(GestureStats::default());
        let router = Arc::new(Mutex::new(Router::new(cfg.router.clone())));
        let started = Instant::now();

        let (stop_tx, stop_rx) = channel::<()>();
        let worker_snapshot = snapshot.clone();
        let worker_stats = stats.clone();
        let worker_router = router.clone();
        let worker_started = started;

        std::thread::Builder::new()
            .name("nvoos-gesture".into())
            .spawn(move || {
                run_loop(
                    backend,
                    cfg,
                    frame,
                    sink,
                    worker_snapshot,
                    worker_stats,
                    worker_router,
                    worker_started,
                    stop_rx,
                );
            })
            .map_err(|e| AppError::Other(format!("failed to spawn gesture worker: {e}")))?;

        Ok(Self {
            snapshot,
            stats,
            router,
            started,
            stop_tx: Some(stop_tx),
        })
    }

    pub fn snapshot(&self) -> GestureSnapshot {
        self.snapshot.lock().unwrap().clone()
    }

    pub fn armed(&self) -> bool {
        self.router.lock().unwrap().armed()
    }

    /// Arm/disarm from the UI — the keyboard/pointer equivalent of the pose.
    pub fn set_armed(&self, armed: bool) {
        let now = self.started.elapsed().as_millis() as u64;
        let mut router = self.router.lock().unwrap();
        if armed {
            router.arm(now);
        } else {
            router.disarm();
        }
    }

    pub fn frames(&self) -> u64 {
        self.stats.frames.load(Ordering::Relaxed)
    }

    pub fn detections(&self) -> u64 {
        self.stats.detections.load(Ordering::Relaxed)
    }

    pub fn observations(&self) -> u64 {
        self.stats.observations.load(Ordering::Relaxed)
    }

    pub fn intents(&self) -> u64 {
        self.stats.intents.load(Ordering::Relaxed)
    }

    pub fn error_count(&self) -> u64 {
        self.stats.errors.load(Ordering::Relaxed)
    }

    pub fn last_error(&self) -> Option<String> {
        self.stats.last_error.lock().unwrap().clone()
    }

    pub fn last_infer_ms(&self) -> u64 {
        self.stats.last_infer_ms.load(Ordering::Relaxed)
    }
}

impl Drop for GestureController {
    fn drop(&mut self) {
        if let Some(tx) = self.stop_tx.take() {
            let _ = tx.send(());
        }
    }
}

impl std::fmt::Debug for GestureController {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("GestureController")
            .field("armed", &self.armed())
            .field("frames", &self.frames())
            .field("running", &self.snapshot.lock().unwrap().running)
            .finish()
    }
}

#[cfg(feature = "gestures")]
#[allow(clippy::too_many_arguments)]
fn run_loop(
    mut backend: Box<dyn HandPoseBackend>,
    cfg: GestureRunConfig,
    frame: Arc<Mutex<Option<PreviewFrame>>>,
    sink: IntentSink,
    snapshot: Arc<Mutex<GestureSnapshot>>,
    stats: Arc<GestureStats>,
    router: Arc<Mutex<Router>>,
    started: Instant,
    stop_rx: std::sync::mpsc::Receiver<()>,
) {
    let mut stabilizer = Stabilizer::new(cfg.stabilizer);
    let mut motion = MotionTracker::new(cfg.motion);
    let mut last_hand_ms: Option<u64> = None;

    loop {
        if stop_rx.try_recv().is_ok() {
            let mut sn = snapshot.lock().unwrap();
            sn.running = false;
            sn.landmarks.clear();
            sn.gesture = None;
            return;
        }

        let now = started.elapsed().as_millis() as u64;
        let frame_opt = frame.lock().unwrap().clone();

        let Some(pf) = frame_opt else {
            // Camera not running: nothing to do, but keep the controller alive
            // so a camera restart resumes without a settings round-trip.
            std::thread::sleep(TICK);
            continue;
        };

        stats.frames.fetch_add(1, Ordering::Relaxed);
        let infer_started = Instant::now();
        let detected = backend.detect(&pf, now);
        stats.last_infer_ms.store(
            infer_started.elapsed().as_millis() as u64,
            Ordering::Relaxed,
        );

        match detected {
            Ok(hands) => {
                if let Some(hand) = hands.first() {
                    stats.detections.fetch_add(1, Ordering::Relaxed);
                    last_hand_ms = Some(now);

                    let obs = observe(&mut stabilizer, &mut motion, hand, now);

                    let mut sn = snapshot.lock().unwrap();
                    sn.landmarks = hand.points.to_vec();
                    sn.handedness = hand.handedness;
                    if let Some(observation) = &obs {
                        sn.gesture = Some(observation.gesture);
                        sn.confidence = observation.confidence;
                        sn.held_ms = observation.held_ms;
                    } else {
                        sn.gesture = None;
                        sn.confidence = 0.0;
                        sn.held_ms = 0;
                    }
                    drop(sn);

                    if let Some(observation) = obs {
                        stats.observations.fetch_add(1, Ordering::Relaxed);
                        let context = GestureContext::default();
                        let intent = router.lock().unwrap().update(&observation, &context, now);
                        if let Some(intent) = intent {
                            stats.intents.fetch_add(1, Ordering::Relaxed);
                            let mut sn = snapshot.lock().unwrap();
                            sn.last_action = Some(intent.action);
                            drop(sn);
                            // The sink must never stall the loop; the Tauri
                            // layer emits and returns.
                            sink(intent);
                        }
                    }
                } else {
                    // No hand: release the track so a re-entry cannot inherit
                    // a stale hold time, and expire the session.
                    stabilizer.reset();
                    motion.reset();
                    let mut sn = snapshot.lock().unwrap();
                    sn.landmarks.clear();
                    sn.gesture = None;
                    sn.confidence = 0.0;
                    sn.held_ms = 0;
                    drop(sn);
                }
            }
            Err(e) => {
                stats.errors.fetch_add(1, Ordering::Relaxed);
                *stats.last_error.lock().unwrap() = Some(e.to_string());
                stabilizer.reset();
                motion.reset();
            }
        }

        if let Some(last) = last_hand_ms {
            if now.saturating_sub(last) > cfg.disarm_after_ms {
                router.lock().unwrap().disarm();
                last_hand_ms = None;
            }
        }

        {
            let mut sn = snapshot.lock().unwrap();
            sn.running = true;
            sn.armed = router.lock().unwrap().armed();
            sn.at_ms = now;
        }

        std::thread::sleep(TICK);
    }
}

/// One frame of evidence → optional stable observation.
#[cfg(feature = "gestures")]
fn observe(
    stabilizer: &mut Stabilizer,
    motion: &mut MotionTracker,
    hand: &crate::gestures::HandLandmarks,
    now: u64,
) -> Option<GestureObservation> {
    // The backend already applied any mirroring to the pixels, so the
    // landmarks are in canonical orientation and must not be flipped again.
    let normalized = normalize(&hand.points, false)?;
    let shape = analyze(&normalized);

    let mut candidates = classify(&shape);

    motion.push(now, shape.palm_center, shape.palm_width);
    candidates.extend(motion.detect(now));
    candidates.sort_by(|a, b| b.1.total_cmp(&a.1));

    stabilizer.update(
        now,
        FrameEvidence {
            candidates: &candidates,
            handedness: hand.handedness,
            bbox: hand.bbox,
            hand_present: true,
        },
    )
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::gestures::landmarks::HandPoseBackend;
    use crate::gestures::test_support;
    use crate::gestures::{BBox, HandLandmarks};

    /// A backend that replays the same synthetic hand every frame.
    struct FixedBackend {
        points: [Point3; 21],
        calls: Arc<AtomicU64>,
    }

    impl HandPoseBackend for FixedBackend {
        fn detect(&mut self, _frame: &PreviewFrame, at_ms: u64) -> AppResult<Vec<HandLandmarks>> {
            self.calls.fetch_add(1, Ordering::Relaxed);
            Ok(vec![HandLandmarks {
                track_id: Some(1),
                handedness: Handedness::Right,
                score: 0.99,
                points: self.points,
                bbox: BBox {
                    x: 10,
                    y: 10,
                    w: 100,
                    h: 100,
                },
                at_ms,
            }])
        }

        fn name(&self) -> &'static str {
            "fixed-test"
        }
    }

    fn running_frame() -> Arc<Mutex<Option<PreviewFrame>>> {
        Arc::new(Mutex::new(Some(PreviewFrame {
            width: 320,
            height: 240,
            rgb: vec![0; 320 * 240 * 3],
        })))
    }

    #[test]
    #[cfg(feature = "gestures")]
    fn the_loop_publishes_a_snapshot_and_stops_cleanly() {
        let calls = Arc::new(AtomicU64::new(0));
        let backend = Box::new(FixedBackend {
            points: test_support::open_palm(),
            calls: calls.clone(),
        });

        let intents = Arc::new(AtomicU64::new(0));
        let counter = intents.clone();
        let sink: IntentSink = Arc::new(move |_intent| {
            counter.fetch_add(1, Ordering::Relaxed);
        });

        let controller =
            GestureController::start(backend, GestureRunConfig::default(), running_frame(), sink)
                .expect("loop starts (feature on) or reports unsupported (feature off)");

        // Give the worker a few ticks, then check it did work.
        std::thread::sleep(Duration::from_millis(250));
        assert!(controller.frames() > 0, "worker never ticked");
        assert!(controller.detections() > 0, "backend never ran");

        let snap = controller.snapshot();
        assert!(snap.running);
        assert!(!snap.landmarks.is_empty());

        drop(controller);
        std::thread::sleep(Duration::from_millis(120));
        assert!(calls.load(Ordering::Relaxed) > 0);
    }

    #[test]
    #[cfg(not(feature = "gestures"))]
    fn start_reports_unsupported_without_the_feature() {
        let backend = Box::new(FixedBackend {
            points: test_support::open_palm(),
            calls: Arc::new(AtomicU64::new(0)),
        });
        let err = GestureController::start(
            backend,
            GestureRunConfig::default(),
            running_frame(),
            Arc::new(|_| {}),
        )
        .expect_err("must be unsupported");
        assert!(format!("{err}").contains("not compiled"));
    }
}
