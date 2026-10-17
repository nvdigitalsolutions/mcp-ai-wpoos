//! `VisionController`: the resident detect→track loop that feeds the webview,
//! with an optional local-VLM label-polish layer (Proposal 076).
//!
//! Pulls downsampled RGB frames from the existing camera ring (`camera`), runs
//! the YOLO detector, updates the SORT tracker, and publishes the tracked
//! detections as a pollable snapshot. When vision-polish is enabled, the loop
//! hands low-confidence boxes to a dedicated `nvoos-vision-polish` thread
//! (owning a tokio runtime) that relabels each crop via a local VLM; the
//! polished labels are merged back without stalling the 15 fps detect loop.

use std::sync::atomic::{AtomicU64, Ordering};
#[cfg(feature = "vision")]
use std::sync::mpsc::channel;
use std::sync::mpsc::Sender;
use std::sync::{Arc, Mutex};
#[cfg(feature = "vision")]
use std::time::{Duration, Instant};

use crate::error::{AppError, AppResult};
#[cfg(feature = "vision")]
use crate::vision::detect::{Detector, DetectorConfig};
#[cfg(feature = "vision")]
use crate::vision::polish::{PolishRequest, PolishResult, VisionPolisher};
#[cfg(feature = "vision")]
use crate::vision::tracker::{SORTTracker, TrackerConfig};
use crate::vision::Detection;

/// Live health counters surfaced on the Diagnostics page (mirror `CameraStats`).
#[derive(Default)]
pub(crate) struct VisionStats {
    pub frames: AtomicU64,
    pub detections: AtomicU64,
    pub track_events: AtomicU64,
    pub errors: AtomicU64,
    pub last_error: Mutex<Option<String>>,
    pub last_infer_ms: AtomicU64,
    pub polish_calls: AtomicU64,
    pub polish_results: AtomicU64,
}

/// The shared, pollable result of the vision loop.
#[derive(Default)]
pub struct VisionSnapshot {
    /// The tracked detections for the most recent processed frame.
    pub detections: Vec<Detection>,
    /// Whether the detection loop is actively running.
    pub running: bool,
}

/// Resident vision controller (detector + tracker + worker thread).
pub struct VisionController {
    pub snapshot: Arc<Mutex<VisionSnapshot>>,
    pub(crate) stats: Arc<VisionStats>,
    pub(crate) stop_tx: Option<Sender<()>>,
    pin_tx: Sender<u64>,
}

#[cfg(feature = "vision")]
impl VisionController {
    /// Start the loop: load the detector ONNX model at `model_path`, pull
    /// frames from the camera `frame` ring, and begin emitting detections.
    /// `polisher` optionally enables VLM relabeling of low-confidence boxes.
    pub fn start(
        model_path: std::path::PathBuf,
        detector_cfg: DetectorConfig,
        tracker_cfg: TrackerConfig,
        frame: Arc<Mutex<Option<crate::camera::PreviewFrame>>>,
        polish: Option<PolishConfig>,
    ) -> AppResult<Self> {
        let (ready_tx, ready_rx) = channel::<AppResult<Arc<Mutex<VisionSnapshot>>>>();
        let (stop_tx, stop_rx) = channel::<()>();
        let (pin_tx, pin_rx) = channel::<u64>();
        let stats = Arc::new(VisionStats::default());
        let worker_stats = stats.clone();
        let snapshot = Arc::new(Mutex::new(VisionSnapshot::default()));

        let worker = std::thread::Builder::new()
            .name("nvoos-vision".into())
            .spawn(move || {
                let detector = match Detector::load(&model_path, detector_cfg) {
                    Ok(d) => d,
                    Err(e) => {
                        let _ = ready_tx.send(Err(e));
                        return;
                    }
                };
                let mut tracker = SORTTracker::new(tracker_cfg);
                {
                    let mut sn = snapshot.lock().unwrap();
                    sn.running = true;
                }
                let _ = ready_tx.send(Ok(snapshot.clone()));
                run_loop(
                    detector,
                    &mut tracker,
                    frame,
                    snapshot,
                    worker_stats,
                    stop_rx,
                    pin_rx,
                    polish,
                );
            })
            .map_err(|e| AppError::Other(e.to_string()))?;
        drop(worker);

        let snapshot = ready_rx
            .recv()
            .map_err(|e| AppError::Other(e.to_string()))??;
        Ok(Self {
            snapshot,
            stats,
            stop_tx: Some(stop_tx),
            pin_tx,
        })
    }
}

#[cfg(not(feature = "vision"))]
impl VisionController {
    /// Stub start when the `vision` feature is not compiled in.
    pub fn start(
        _model_path: std::path::PathBuf,
        _detector_cfg: crate::vision::detect::DetectorConfig,
        _tracker_cfg: crate::vision::tracker::TrackerConfig,
        _frame: Arc<Mutex<Option<crate::camera::PreviewFrame>>>,
        _polish: Option<PolishConfig>,
    ) -> AppResult<Self> {
        Err(AppError::Unsupported(
            "vision detection is not compiled into this build".into(),
        ))
    }
}

/// Settings that enable and shape the VLM label-polish layer.
#[cfg(feature = "vision")]
#[derive(Clone)]
pub struct PolishConfig {
    /// The polisher transport (built by the caller from `Settings.vision`).
    pub polisher: Arc<VisionPolisher>,
    /// Only relabel boxes whose YOLO confidence is below this (0 = all).
    pub min_confidence: f32,
    /// Cap on VLM calls per second.
    pub max_per_second: usize,
}

/// Placeholder config when the `vision` feature is not compiled in (keeps the
/// `start` signature stable across feature sets).
#[cfg(not(feature = "vision"))]
#[derive(Clone)]
pub struct PolishConfig {
    _private: (),
}

impl VisionController {
    /// Pin a detection id (click-to-select). The id is applied by the worker
    /// on its next frame; returns false if the controller has stopped.
    pub fn select_track(&self, id: u64) -> bool {
        self.pin_tx.send(id).is_ok()
    }

    pub fn running(&self) -> bool {
        self.snapshot.lock().unwrap().running
    }

    pub fn frames(&self) -> u64 {
        self.stats.frames.load(Ordering::Relaxed)
    }

    pub fn total_detections(&self) -> u64 {
        self.stats.detections.load(Ordering::Relaxed)
    }

    pub fn track_events(&self) -> u64 {
        self.stats.track_events.load(Ordering::Relaxed)
    }

    pub fn last_infer_ms(&self) -> u64 {
        self.stats.last_infer_ms.load(Ordering::Relaxed)
    }

    pub fn error_count(&self) -> u64 {
        self.stats.errors.load(Ordering::Relaxed)
    }

    pub fn last_error(&self) -> Option<String> {
        self.stats.last_error.lock().unwrap().clone()
    }

    pub fn polish_calls(&self) -> u64 {
        self.stats.polish_calls.load(Ordering::Relaxed)
    }

    pub fn polish_results(&self) -> u64 {
        self.stats.polish_results.load(Ordering::Relaxed)
    }

    /// Number of live tracks in the latest snapshot.
    pub fn active_tracks(&self) -> usize {
        self.snapshot.lock().unwrap().detections.len()
    }
}

impl Drop for VisionController {
    fn drop(&mut self) {
        if let Some(tx) = self.stop_tx.take() {
            let _ = tx.send(());
        }
    }
}

#[allow(clippy::too_many_arguments)]
#[cfg(feature = "vision")]
fn run_loop(
    detector: Detector,
    tracker: &mut SORTTracker,
    frame: Arc<Mutex<Option<crate::camera::PreviewFrame>>>,
    snapshot: Arc<Mutex<VisionSnapshot>>,
    stats: Arc<VisionStats>,
    stop_rx: std::sync::mpsc::Receiver<()>,
    pin_rx: std::sync::mpsc::Receiver<u64>,
    polish: Option<PolishConfig>,
) {
    let polish_min_conf = polish.as_ref().map(|p| p.min_confidence).unwrap_or(0.0);
    let polish_max_per_sec = polish.as_ref().map(|p| p.max_per_second).unwrap_or(2);
    let (polish_tx, polish_rx): (
        Option<Sender<PolishRequest>>,
        Option<std::sync::mpsc::Receiver<PolishResult>>,
    ) = match polish {
        Some(cfg) => {
            let (req_tx, req_rx) = channel::<PolishRequest>();
            let (res_tx, res_rx) = channel::<PolishResult>();
            let polisher = cfg.polisher;
            let pstats = stats.clone();
            std::thread::Builder::new()
                .name("nvoos-vision-polish".into())
                .spawn(move || {
                    while let Ok(req) = req_rx.recv() {
                        pstats.polish_calls.fetch_add(1, Ordering::Relaxed);
                        match polisher.resolve(&req) {
                            Ok(result) => {
                                pstats.polish_results.fetch_add(1, Ordering::Relaxed);
                                if res_tx.send(result).is_err() {
                                    break; // controller dropped; stop
                                }
                            }
                            Err(e) => {
                                *pstats.last_error.lock().unwrap() = Some(e.to_string());
                            }
                        }
                    }
                })
                .ok();
            (Some(req_tx), Some(res_rx))
        }
        None => (None, None),
    };

    // Throttle: at most `max_per_second` polish requests, paced by a timer.
    let mut last_polish = Instant::now();

    loop {
        if stop_rx.try_recv().is_ok() {
            let mut sn = snapshot.lock().unwrap();
            sn.running = false;
            sn.detections.clear();
            return;
        }

        // Drain pending click-to-select pins.
        while let Ok(id) = pin_rx.try_recv() {
            if tracker.pin(id) {
                stats.track_events.fetch_add(1, Ordering::Relaxed);
            }
        }

        let frame_opt = frame.lock().unwrap().clone();
        if let Some(pf) = frame_opt {
            stats.frames.fetch_add(1, Ordering::Relaxed);
            let started = Instant::now();
            match detector.detect(&pf.rgb, pf.width, pf.height) {
                Ok(dets) => {
                    stats
                        .last_infer_ms
                        .store(started.elapsed().as_millis() as u64, Ordering::Relaxed);
                    let tracked = tracker.update(&dets);
                    stats
                        .detections
                        .fetch_add(tracked.len() as u64, Ordering::Relaxed);

                    // Enqueue low-confidence boxes for VLM polish (throttled).
                    if let Some(tx) = &polish_tx {
                        let elapsed = last_polish.elapsed();
                        let interval =
                            Duration::from_millis(1000 / polish_max_per_sec.max(1) as u64);
                        if elapsed >= interval {
                            for det in &tracked {
                                if det.confidence >= polish_min_conf {
                                    continue;
                                }
                                if let Ok(req) = crate::vision::polish::prepare_request(
                                    &pf.rgb, pf.width, pf.height, det.x, det.y, det.w, det.h,
                                    det.id,
                                ) {
                                    if tx.send(req).is_ok() {
                                        last_polish = Instant::now();
                                        break;
                                    }
                                }
                            }
                        }
                    }

                    let mut sn = snapshot.lock().unwrap();
                    sn.detections = tracked;
                }
                Err(e) => {
                    stats.errors.fetch_add(1, Ordering::Relaxed);
                    *stats.last_error.lock().unwrap() = Some(e.to_string());
                }
            }
        }

        // Apply any completed polish results to the snapshot (non-blocking).
        if let Some(rx) = &polish_rx {
            while let Ok(result) = rx.try_recv() {
                let mut sn = snapshot.lock().unwrap();
                if let Some(det) = sn
                    .detections
                    .iter_mut()
                    .find(|d| d.id == result.id && result.id.is_some())
                {
                    det.label = result.label;
                    det.confidence = result.confidence;
                    det.source = "vlm".to_string();
                }
            }
        }

        std::thread::sleep(Duration::from_millis(66));
    }
}
