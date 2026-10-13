//! Local object detection + tracking (Proposal 075).
//!
//! Runs a YOLO-family ONNX detector (`yolov11n` by default, `yolov8n` as
//! fallback) on the live webcam preview ring via the pinned `ort` runtime,
//! decodes + NMS-filters detections back into source-pixel boxes, and hands
//! them to a SORT tracker for stable ids with an optional click-to-select pin.
//!
//! The Rust core emits JSON (`Vec<Detection>`); the webview draws labelled
//! bounding boxes on a canvas overlay. Pixels never leave the device — the
//! only egress is the optional LM Studio/Ollama polish call (unrelated to
//! detection, which is fully local).
//!
//! Feature wiring mirrors `camera`: the module is always compiled so
//! `AppState` can hold the controller and `Settings.vision` is type-stable,
//! but the `ort`-dependent detector is gated behind the `vision` cargo
//! feature. Without it, `available()` is false and the IPC surface reports
//! "not compiled".

pub mod detect;
pub mod models;
pub mod tracker;

pub mod controller;

use serde::Serialize;

/// A single detected object, in **source-pixel** coordinates (relative to the
/// camera frame the detector was fed, before any preview scaling).
#[derive(Debug, Clone, Serialize)]
pub struct Detection {
    /// SORT track id (stable across frames while tracked). `None` before
    /// tracking is applied.
    pub id: Option<u64>,
    /// COCO-80 class label (e.g. `person`, `cell phone`).
    pub label: String,
    /// Detection confidence 0..1.
    pub confidence: f32,
    /// Top-left x, y and width/height in source pixels.
    pub x: u32,
    pub y: u32,
    pub w: u32,
    pub h: u32,
}

/// Whether local detection is compiled into this build.
pub fn available() -> bool {
    cfg!(feature = "vision")
}

/// Whether detection additionally requires a live camera (the frame source).
/// Detector-only builds still need the `camera` feature; `vision` always
/// implies `camera` in `Cargo.toml`.
pub fn requires_camera() -> bool {
    cfg!(feature = "camera")
}
