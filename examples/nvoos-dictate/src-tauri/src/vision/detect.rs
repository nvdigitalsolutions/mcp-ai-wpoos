//! YOLO ONNX detector: letterbox → `ort` inference → NMS → `Vec<Detection>`.
//!
//! The ONNX output shape is `[1, 4+nc, N]` (`[1, 84, 8400]` for COCO-80 with
//! N=8400 anchors): 4 box params (cx, cy, w, h) then `nc` class scores, per
//! candidate. Decode projects boxes from the 640×640 letterbox back into
//! source-pixel coordinates.
//!
//! The `ort`-dependent body is gated behind `#[cfg(feature = "vision")]`; the
//! non-feature path exists only so the module and `Detection` stay type-stable
//! across build configs (mirroring `camera`).

use crate::error::{AppError, AppResult};
use crate::vision::Detection;

/// COCO-80 class names (index-aligned) — the stock YOLO label set.
pub const COCO_LABELS: [&str; 80] = [
    "person",
    "bicycle",
    "car",
    "motorcycle",
    "airplane",
    "bus",
    "train",
    "truck",
    "boat",
    "traffic light",
    "fire hydrant",
    "stop sign",
    "parking meter",
    "bench",
    "bird",
    "cat",
    "dog",
    "horse",
    "sheep",
    "cow",
    "elephant",
    "bear",
    "zebra",
    "giraffe",
    "backpack",
    "umbrella",
    "handbag",
    "tie",
    "suitcase",
    "frisbee",
    "skis",
    "snowboard",
    "sports ball",
    "kite",
    "baseball bat",
    "baseball glove",
    "skateboard",
    "surfboard",
    "tennis racket",
    "bottle",
    "wine glass",
    "cup",
    "fork",
    "knife",
    "spoon",
    "bowl",
    "banana",
    "apple",
    "sandwich",
    "orange",
    "broccoli",
    "carrot",
    "hot dog",
    "pizza",
    "donut",
    "cake",
    "chair",
    "couch",
    "potted plant",
    "bed",
    "dining table",
    "toilet",
    "tv",
    "laptop",
    "mouse",
    "remote",
    "keyboard",
    "cell phone",
    "microwave",
    "oven",
    "toaster",
    "sink",
    "refrigerator",
    "book",
    "clock",
    "vase",
    "scissors",
    "teddy bear",
    "hair drier",
    "toothbrush",
];

/// Detector configuration (confidence + IoU thresholds). Defaults follow the
/// canonical YOLO val settings.
#[derive(Debug, Clone, Copy)]
pub struct DetectorConfig {
    pub confidence: f32,
    pub iou: f32,
}

impl Default for DetectorConfig {
    fn default() -> Self {
        Self {
            confidence: 0.25,
            iou: 0.45,
        }
    }
}

/// Input image size (square) the detector letterboxes to.
pub const INPUT_SIZE: u32 = 640;

/// Letterbox an RGB frame to `INPUT_SIZE×INPUT_SIZE`, returning the CHW float
/// tensor (normalized 0..1) plus the scale/pad needed to project boxes back.
/// `w`/`h` are the source dimensions; `rgb` is `w*h*3` bytes.
#[cfg(feature = "vision")]
pub(crate) fn letterbox(rgb: &[u8], w: u32, h: u32) -> Option<(Vec<f32>, f32, u32, u32)> {
    if w == 0 || h == 0 || rgb.len() < (w * h * 3) as usize {
        return None;
    }
    let scale = (INPUT_SIZE as f32 / w as f32).min(INPUT_SIZE as f32 / h as f32);
    let nw = (w as f32 * scale).round() as u32;
    let nh = (h as f32 * scale).round() as u32;
    let (pad_x, pad_y) = ((INPUT_SIZE - nw) / 2, (INPUT_SIZE - nh) / 2);

    // HWC RGB directly to CHW float (nearest-neighbour for speed).
    let mut out = vec![0f32; (INPUT_SIZE * INPUT_SIZE * 3) as usize];
    for y in 0..nh {
        let sy = ((y as f32) / scale) as u32;
        let syc = sy.min(h - 1);
        for x in 0..nw {
            let sx = ((x as f32) / scale) as u32;
            let sxc = sx.min(w - 1);
            let src = ((syc * w + sxc) * 3) as usize;
            let dst = ((y + pad_y) * INPUT_SIZE + x + pad_x) as usize;
            out[dst] = rgb[src] as f32 / 255.0;
            out[dst + (INPUT_SIZE * INPUT_SIZE) as usize] = rgb[src + 1] as f32 / 255.0;
            out[dst + 2 * (INPUT_SIZE * INPUT_SIZE) as usize] = rgb[src + 2] as f32 / 255.0;
        }
    }
    Some((out, scale, pad_x, pad_y))
}

/// A resident YOLO detector: owns the ONNX session and decodes frames.
#[cfg(feature = "vision")]
pub struct Detector {
    session: ort::session::Session,
    config: DetectorConfig,
}

#[cfg(feature = "vision")]
impl Detector {
    /// Load the ONNX model at `path` and create a session.
    pub fn load(path: &std::path::Path, config: DetectorConfig) -> AppResult<Self> {
        let session = ort::session::Session::builder()?
            .commit_from_file(path)
            .map_err(|e| AppError::Model(format!("failed to load detector model: {e}")))?;
        Ok(Self { session, config })
    }

    /// Run detection on an RGB frame (`w`×`h`, `w*h*3` bytes), returning
    /// source-pixel detections (sorted by confidence, NMS-deduplicated).
    pub fn detect(&self, rgb: &[u8], w: u32, h: u32) -> AppResult<Vec<Detection>> {
        let (input, scale, pad_x, pad_y) = letterbox(rgb, w, h)
            .ok_or_else(|| AppError::Other("empty or undersized camera frame".into()))?;
        let tensor = ndarray16::Array::from_shape_vec(
            (1, 3, INPUT_SIZE as usize, INPUT_SIZE as usize),
            input,
        )
        .map_err(|e| AppError::Other(format!("tensor reshape: {e}")))?
        .into_dyn();
        let outputs = self
            .session
            .run(ort::inputs![ort::value::Value::from_array(tensor)?]?)
            .map_err(|e| AppError::Other(format!("inference failed: {e}")))?;
        let out = outputs[0]
            .try_extract_tensor::<f32>()
            .map_err(|e| AppError::Other(format!("output tensor: {e}")))?;
        let shape = out.shape().to_vec();
        Ok(decode(
            out.view(),
            shape.as_slice(),
            self.config,
            scale,
            pad_x,
            pad_y,
        ))
    }
}

/// Decode the YOLO output tensor + NMS into source-pixel detections. Pure and
/// unit-tested on synthetic tensors (no model required).
#[cfg(feature = "vision")]
pub(crate) fn decode(
    out: ndarray16::ArrayViewD<f32>,
    shape: &[usize],
    config: DetectorConfig,
    scale: f32,
    pad_x: u32,
    pad_y: u32,
) -> Vec<Detection> {
    let (num_pred, nc) = match shape {
        [1, cols, n, ..] if *cols >= 5 => (*n, cols - 4),
        [cols, n] if *cols >= 5 => (*n, cols - 4),
        _ => {
            tracing::warn!(
                ?shape,
                "unexpected detector output shape; assuming 80 classes, 8400"
            );
            (8400, 80)
        }
    };

    let mut raw: Vec<Detection> = Vec::new();
    for p in 0..num_pred {
        let mut best_class = 0usize;
        let mut best_score = -1.0f32;
        for c in 0..nc {
            let s = out[[0, 4 + c, p]];
            if s > best_score {
                best_score = s;
                best_class = c;
            }
        }
        if best_score < config.confidence {
            continue;
        }
        let (cx, cy, bw, bh) = (
            out[[0, 0, p]],
            out[[0, 1, p]],
            out[[0, 2, p]],
            out[[0, 3, p]],
        );
        let x = ((cx - bw / 2.0 - pad_x as f32) / scale).max(0.0) as u32;
        let y = ((cy - bh / 2.0 - pad_y as f32) / scale).max(0.0) as u32;
        let w = (bw / scale) as u32;
        let h = (bh / scale) as u32;
        raw.push(Detection {
            id: None,
            label: COCO_LABELS
                .get(best_class)
                .copied()
                .unwrap_or("?")
                .to_string(),
            confidence: best_score,
            x,
            y,
            w,
            h,
            source: "coco".to_string(),
        });
    }

    raw.sort_by(|a, b| {
        b.confidence
            .partial_cmp(&a.confidence)
            .unwrap_or(std::cmp::Ordering::Equal)
    });
    let mut keep: Vec<Detection> = Vec::new();
    for d in raw {
        if !keep.iter().any(|k| iou(k, &d) > config.iou) {
            keep.push(d);
        }
    }
    keep
}

/// Intersection-over-union of two source-pixel boxes.
#[cfg(feature = "vision")]
pub(crate) fn iou(a: &Detection, b: &Detection) -> f32 {
    let (ax1, ay1) = (a.x as f32, a.y as f32);
    let (ax2, ay2) = (ax1 + a.w as f32, ay1 + a.h as f32);
    let (bx1, by1) = (b.x as f32, b.y as f32);
    let (bx2, by2) = (bx1 + b.w as f32, by1 + b.h as f32);
    let ix1 = ax1.max(bx1);
    let iy1 = ay1.max(by1);
    let ix2 = ax2.min(bx2);
    let iy2 = ay2.min(by2);
    let inter = (ix2 - ix1).max(0.0) * (iy2 - iy1).max(0.0);
    let union = (ax2 - ax1) * (ay2 - ay1) + (bx2 - bx1) * (by2 - by1) - inter;
    if union <= 0.0 {
        0.0
    } else {
        inter / union
    }
}

// ---- non-feature stub (keeps the module type-stable) ---------------------

#[cfg(not(feature = "vision"))]
pub struct Detector;

#[cfg(not(feature = "vision"))]
impl Detector {
    pub fn load(_path: &std::path::Path, _config: DetectorConfig) -> AppResult<Self> {
        Err(AppError::Unsupported(
            "vision detection is not compiled into this build".into(),
        ))
    }

    pub fn detect(&self, _rgb: &[u8], _w: u32, _h: u32) -> AppResult<Vec<Detection>> {
        Err(AppError::Unsupported(
            "vision detection is not compiled into this build".into(),
        ))
    }
}
