//! Webcam/camera capture (Proposal 073).
//!
//! Architecturally, a webcam enumerates as **two** separate devices: a camera
//! (video frames, via `nokhwa`) and a microphone (audio, via the existing
//! `cpal` `MicCapture`). This module owns the **video** side — the live
//! preview frame ring — while camera *audio* transcription simply reuses the
//! mic path (a webcam mic already appears in `list_input_devices`).
//!
//! The `CameraCapture` handle is always compiled (so `AppState` can hold it);
//! only the actual `nokhwa` capture is gated behind the `camera` cargo
//! feature. Without the feature, `start()` reports "not compiled" and
//! `list_devices()` is empty. `nokhwa::Camera` is `!Sync` and must live on a
//! dedicated worker thread; only Send handles (frame ring Arcs, atomics) cross
//! into `AppState`.

#[cfg(feature = "camera")]
mod capture;

/// A single downsampled preview frame (raw RGB, ≤ preview width).
#[derive(Clone, serde::Serialize)]
pub struct PreviewFrame {
    pub width: u32,
    pub height: u32,
    /// RGB bytes, `width * height * 3`.
    pub rgb: Vec<u8>,
}

#[cfg(feature = "camera")]
pub use capture::CameraCapture;

/// Enumerate camera device display names (empty when the `camera` feature is
/// not compiled in, or no camera exists).
pub fn list_devices() -> Vec<String> {
    #[cfg(feature = "camera")]
    {
        capture::list_devices()
    }
    #[cfg(not(feature = "camera"))]
    {
        Vec::new()
    }
}

/// Whether camera capture is compiled into this build.
pub fn available() -> bool {
    cfg!(feature = "camera")
}

/// Stub camera handle when the `camera` feature is off. Keeps the
/// `AppState` field and IPC surface type-stable across feature sets.
#[cfg(not(feature = "camera"))]
pub struct CameraCapture {
    pub frame: std::sync::Arc<std::sync::Mutex<Option<PreviewFrame>>>,
    pub device_name: String,
}

#[cfg(not(feature = "camera"))]
impl CameraCapture {
    pub fn start(_device_name: Option<&str>) -> crate::error::AppResult<Self> {
        Err(crate::error::AppError::Unsupported(
            "camera capture is not compiled into this build".into(),
        ))
    }

    pub fn device_name(&self) -> &str {
        &self.device_name
    }

    pub fn callback_count(&self) -> u64 {
        0
    }

    pub fn stall_ms(&self) -> u64 {
        u64::MAX
    }

    pub fn error_count(&self) -> u64 {
        0
    }

    pub fn last_error(&self) -> Option<String> {
        None
    }
}
