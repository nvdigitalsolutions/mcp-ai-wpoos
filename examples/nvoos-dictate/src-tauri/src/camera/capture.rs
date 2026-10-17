//! `nokhwa`-backed camera capture: device enumeration and a resident frame
//! ring feeding the live preview. Camera audio is handled by the existing mic
//! path (a webcam mic is an ordinary input device).
//!
//! This entire module is compiled only behind the `camera` cargo feature.

use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::mpsc::{channel, Sender};
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant};

use crate::camera::PreviewFrame;
use crate::error::{AppError, AppResult};

/// Live health counters, surfaced on the Diagnostics page (mirror `MicCapture`).
#[derive(Default)]
pub(crate) struct CameraStats {
    pub frames: AtomicU64,
    pub last_frame: Mutex<Option<Instant>>,
    pub errors: AtomicU64,
    pub last_error: Mutex<Option<String>>,
}

/// Shared, thread-safe camera handle (video preview only).
pub struct CameraCapture {
    pub frame: Arc<Mutex<Option<PreviewFrame>>>,
    pub device_name: String,
    pub(crate) stats: Arc<CameraStats>,
    pub(crate) stop_tx: Option<Sender<()>>,
}

/// Enumerate camera device display names.
pub fn list_devices() -> Vec<String> {
    match nokhwa::query(nokhwa::utils::ApiBackend::Auto) {
        Ok(devices) => devices.into_iter().map(|d| d.human_name()).collect(),
        Err(_) => Vec::new(),
    }
}

impl CameraCapture {
    /// Open the selected (or first) camera on a worker thread and return the
    /// shared handle. The `nokhwa::Camera` is held by the worker for its
    /// lifetime; dropping the handle signals shutdown.
    pub fn start(device_name: Option<&str>) -> AppResult<Self> {
        let (tx, rx) = channel::<AppResult<(String, Arc<Mutex<Option<PreviewFrame>>>)>>();
        let (stop_tx, stop_rx) = channel::<()>();
        let requested = device_name.map(|s| s.to_string());
        let stats = Arc::new(CameraStats::default());
        let worker_stats = stats.clone();

        let worker = std::thread::Builder::new()
            .name("nvoos-camera".into())
            .spawn(move || {
                let (camera, _name, frame) = match build_camera(requested.as_deref()) {
                    Ok(t) => {
                        let _ = tx.send(Ok((t.1.clone(), t.2.clone())));
                        t
                    }
                    Err(e) => {
                        let _ = tx.send(Err(e));
                        return;
                    }
                };
                run_loop(camera, frame, worker_stats, stop_rx);
            })
            .map_err(|e| AppError::Audio(e.to_string()))?;
        drop(worker);

        let (device_name, frame) = rx.recv().map_err(|e| AppError::Audio(e.to_string()))??;
        Ok(Self {
            frame,
            device_name,
            stats,
            stop_tx: Some(stop_tx),
        })
    }

    pub fn device_name(&self) -> &str {
        &self.device_name
    }

    pub fn callback_count(&self) -> u64 {
        self.stats.frames.load(Ordering::Relaxed)
    }

    pub fn stall_ms(&self) -> u64 {
        match *self.stats.last_frame.lock().unwrap() {
            Some(at) => at.elapsed().as_millis() as u64,
            None => u64::MAX,
        }
    }

    pub fn error_count(&self) -> u64 {
        self.stats.errors.load(Ordering::Relaxed)
    }

    pub fn last_error(&self) -> Option<String> {
        self.stats.last_error.lock().unwrap().clone()
    }
}

impl Drop for CameraCapture {
    fn drop(&mut self) {
        if let Some(tx) = self.stop_tx.take() {
            let _ = tx.send(());
        }
    }
}

type Held = (nokhwa::Camera, String, Arc<Mutex<Option<PreviewFrame>>>);

fn build_camera(requested: Option<&str>) -> AppResult<Held> {
    use nokhwa::pixel_format::RgbFormat;
    use nokhwa::utils::{CameraIndex, RequestedFormat, RequestedFormatType};

    let devices = nokhwa::query(nokhwa::utils::ApiBackend::Auto)
        .map_err(|e| AppError::Audio(format!("camera query failed: {e}")))?;
    if devices.is_empty() {
        return Err(AppError::Audio("no camera device found".into()));
    }

    let info = requested
        .and_then(|name| devices.iter().find(|d| d.human_name() == name).cloned())
        .or_else(|| devices.first().cloned())
        .ok_or_else(|| AppError::Audio("no camera device found".into()))?;
    let name = info.human_name();
    let index = CameraIndex::Index(
        info.index()
            .as_index()
            .map_err(|e| AppError::Audio(e.to_string()))?,
    );

    let format = RequestedFormat::new::<RgbFormat>(RequestedFormatType::AbsoluteHighestFrameRate);
    let mut camera = nokhwa::Camera::new(index, format)
        .map_err(|e| AppError::Audio(format!("camera open failed (in use?): {e}")))?;
    camera
        .open_stream()
        .map_err(|e| AppError::Audio(format!("camera stream failed: {e}")))?;

    Ok((camera, name, Arc::new(Mutex::new(None))))
}

fn run_loop(
    mut camera: nokhwa::Camera,
    frame: Arc<Mutex<Option<PreviewFrame>>>,
    stats: Arc<CameraStats>,
    stop_rx: std::sync::mpsc::Receiver<()>,
) {
    use nokhwa::pixel_format::RgbFormat;

    loop {
        if stop_rx.try_recv().is_ok() {
            break;
        }
        match camera.frame() {
            Ok(buffer) => {
                stats.frames.fetch_add(1, Ordering::Relaxed);
                *stats.last_frame.lock().unwrap() = Some(Instant::now());
                let (w, h) = (buffer.resolution().width(), buffer.resolution().height());
                if let Ok(img) = buffer.decode_image::<RgbFormat>() {
                    let raw = img.as_raw();
                    let downsampled = downsample(raw, w, h, 320);
                    *frame.lock().unwrap() = Some(downsampled);
                }
            }
            Err(e) => {
                stats.errors.fetch_add(1, Ordering::Relaxed);
                *stats.last_error.lock().unwrap() = Some(e.to_string());
            }
        }
        std::thread::sleep(Duration::from_millis(66)); // ~15 fps preview
    }
    let _ = camera.stop_stream();
}

/// Nearest-neighbour downsample of an RGB buffer to at most `max_w` wide.
fn downsample(rgb: &[u8], w: u32, h: u32, max_w: u32) -> PreviewFrame {
    let (tw, th) = if w == 0 || h == 0 {
        (0, 0)
    } else if w <= max_w {
        (w, h)
    } else {
        let ratio = max_w as f32 / w as f32;
        (max_w, (h as f32 * ratio) as u32)
    };
    let mut out = Vec::with_capacity((tw * th * 3) as usize);
    if tw == 0 || th == 0 {
        return PreviewFrame {
            width: tw,
            height: th,
            rgb: out,
        };
    }
    for y in 0..th {
        let sy = (y as f32 * h as f32 / th as f32) as u32;
        for x in 0..tw {
            let sx = (x as f32 * w as f32 / tw as f32) as u32;
            let idx = ((sy * w + sx) * 3) as usize;
            if idx + 2 < rgb.len() {
                out.push(rgb[idx]);
                out.push(rgb[idx + 1]);
                out.push(rgb[idx + 2]);
            } else {
                out.extend_from_slice(&[0, 0, 0]);
            }
        }
    }
    PreviewFrame {
        width: tw,
        height: th,
        rgb: out,
    }
}
