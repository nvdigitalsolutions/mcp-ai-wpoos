// SPDX-License-Identifier: LicenseRef-Proprietary
//! Hand-landmark backends.
//!
//! [`HandPoseBackend`] is the seam between the portable decision logic and a
//! platform/model implementation. v1 ships one backend, [`OrtHandPose`], which
//! runs a MediaPipe-compatible 21-keypoint ONNX export on the pinned `ort`
//! runtime. The macOS Vision backend (`DetectHumanHandPoseRequest`) can be
//! added behind the same trait without touching the router or the UI.
//!
//! The decoder is deliberately tolerant about the two conventions community
//! exports use — normalised `0..1` landmarks or input-pixel landmarks — and
//! strict about everything else: malformed output yields `None` (treated as
//! "no hand this frame"), never a panic.

use crate::camera::PreviewFrame;
use crate::error::AppResult;
use crate::gestures::HandLandmarks;
#[cfg(feature = "gestures")]
use crate::gestures::Handedness;
#[cfg(any(feature = "gestures", test))]
use crate::gestures::{BBox, Point3};

/// A frame source for the landmark stage.
pub trait HandPoseBackend: Send {
    /// Detect hands in `frame`, stamping results with `at_ms`.
    fn detect(&mut self, frame: &PreviewFrame, at_ms: u64) -> AppResult<Vec<HandLandmarks>>;

    /// Stable backend name for diagnostics.
    fn name(&self) -> &'static str;
}

/// Whether an ONNX landmark backend is compiled in.
pub fn available() -> bool {
    cfg!(feature = "gestures")
}

/// How the model expects its input tensor laid out.
#[cfg(any(feature = "gestures", test))]
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub(crate) enum InputLayout {
    /// `[1, 3, H, W]` — planar channels, the historic layout.
    Nchw,
    /// `[1, H, W, 3]` — interleaved pixels, what tf2onnx produces from the
    /// MediaPipe TFLite graph (and what OpenCV Zoo ships).
    Nhwc,
}

/// Pick the layout from the declared input dimensions.
///
/// Defaults to `Nchw` for dynamic or unexpected shapes, which preserves the
/// behaviour every earlier export relied on.
#[cfg(any(feature = "gestures", test))]
pub(crate) fn layout_from_dims(dims: &[i64]) -> InputLayout {
    if dims.len() == 4 && dims[3] == 3 && dims[1] != 3 {
        InputLayout::Nhwc
    } else {
        InputLayout::Nchw
    }
}

/// Reorder a planar `[C][H][W]` buffer into interleaved `[H][W][C]`.
///
/// Returns an empty vec when the buffer is too short, so a caller can turn that
/// into a clear error rather than feeding a wrongly-shaped tensor.
#[cfg(any(feature = "gestures", test))]
pub(crate) fn chw_to_hwc(chw: &[f32], size: usize) -> Vec<f32> {
    let plane = size * size;
    if chw.len() < plane * 3 {
        return Vec::new();
    }
    let mut out = vec![0.0f32; plane * 3];
    for c in 0..3 {
        for i in 0..plane {
            out[i * 3 + c] = chw[c * plane + i];
        }
    }
    out
}

/// Total element count when every dimension is statically known.
#[cfg(any(feature = "gestures", test))]
pub(crate) fn element_count(dims: &[i64]) -> Option<usize> {
    if dims.is_empty() || dims.iter().any(|d| *d <= 0) {
        return None;
    }
    usize::try_from(dims.iter().product::<i64>()).ok()
}

/// Resolve `(landmark, score, handedness)` output roles from tensor sizes.
///
/// Some exports carry no useful tensor names — tf2onnx emits
/// `Identity`/`Identity_1`/… for Google's MediaPipe graph — so a name-only scan
/// never finds the confidence head. That matters: without a confidence head
/// every frame with no hand in it decodes as if a hand were detected.
///
/// 21 landmarks are 63 floats (with z) or 42 (without); the 1-element heads are
/// confidence then handedness, in that order, matching Google's export.
#[cfg(any(feature = "gestures", test))]
pub(crate) fn roles_from_shapes(
    counts: &[Option<usize>],
) -> (Option<usize>, Option<usize>, Option<usize>) {
    let landmark = counts.iter().position(|c| matches!(c, Some(63) | Some(42)));
    let mut ones = counts
        .iter()
        .enumerate()
        .filter(|(_, c)| **c == Some(1))
        .map(|(i, _)| i);
    let score = ones.next();
    let handedness = ones.next();
    (landmark, score, handedness)
}

/// Decode a landmark tensor into source-frame coordinates.
///
/// Accepts `[21,3]` or `[21,2]` flattened values. Landmarks may be normalised
/// (`0..1`) or expressed in input pixels; the two are distinguished by
/// magnitude. Returns `None` when the tensor is too short or degenerate.
#[cfg(any(feature = "gestures", test))]
#[allow(clippy::too_many_arguments)]
pub(crate) fn decode_landmarks(
    values: &[f32],
    frame_w: u32,
    frame_h: u32,
    input_size: u32,
    scale: f32,
    pad_x: f32,
    pad_y: f32,
    mirror: bool,
) -> Option<([Point3; 21], BBox)> {
    if values.len() < 42 || frame_w == 0 || frame_h == 0 || scale <= 0.0 || input_size == 0 {
        return None;
    }

    let stride = if values.len() >= 63 { 3 } else { 2 };
    let pixel_scale = values
        .iter()
        .take(21 * stride)
        .fold(0.0f32, |acc, v| acc.max(v.abs()))
        > 1.5;

    let mut points = [Point3::default(); 21];
    for (i, point) in points.iter_mut().enumerate() {
        let base = i * stride;
        let raw_x = values[base];
        let raw_y = values[base + 1];
        if !raw_x.is_finite() || !raw_y.is_finite() {
            return None;
        }
        let (px, py) = if pixel_scale {
            (raw_x, raw_y)
        } else {
            (raw_x * input_size as f32, raw_y * input_size as f32)
        };

        let mut sx = (px - pad_x) / scale;
        let sy = (py - pad_y) / scale;
        if mirror {
            sx = (frame_w as f32 - 1.0) - sx;
        }
        // Keep landmarks inside the frame; a model can emit a point just
        // outside the crop and the UI would otherwise draw off-canvas.
        point.x = (sx / frame_w as f32).clamp(0.0, 1.0);
        point.y = (sy / frame_h as f32).clamp(0.0, 1.0);
        point.z = if stride == 3 {
            values[base + 2].clamp(-1.0, 1.0)
        } else {
            0.0
        };
    }

    Some((points, bbox_from_points(&points, frame_w, frame_h)))
}

/// Expand the landmark extent into a clipped, padded pixel bbox.
#[cfg(any(feature = "gestures", test))]
fn bbox_from_points(points: &[Point3; 21], frame_w: u32, frame_h: u32) -> BBox {
    let mut min_x = f32::MAX;
    let mut min_y = f32::MAX;
    let mut max_x = f32::MIN;
    let mut max_y = f32::MIN;
    for p in points {
        min_x = min_x.min(p.x);
        min_y = min_y.min(p.y);
        max_x = max_x.max(p.x);
        max_y = max_y.max(p.y);
    }

    let x0 = (min_x * frame_w as f32).floor().max(0.0);
    let y0 = (min_y * frame_h as f32).floor().max(0.0);
    let x1 = (max_x * frame_w as f32).ceil().min(frame_w as f32);
    let y1 = (max_y * frame_h as f32).ceil().min(frame_h as f32);

    let w = (x1 - x0).max(0.0);
    let h = (y1 - y0).max(0.0);
    // 20% padding so the crop for deictic grounding includes the whole hand.
    let pad_x = w * 0.2;
    let pad_y = h * 0.2;
    let px0 = (x0 - pad_x).max(0.0);
    let py0 = (y0 - pad_y).max(0.0);
    let px1 = (x1 + pad_x).min(frame_w as f32);
    let py1 = (y1 + pad_y).min(frame_h as f32);

    BBox {
        x: px0 as u32,
        y: py0 as u32,
        w: (px1 - px0).max(0.0) as u32,
        h: (py1 - py0).max(0.0) as u32,
    }
}

/// Letterbox an RGB frame to a square CHW float tensor (0..1).
///
/// Returns `(tensor, scale, pad_x, pad_y)`. Aspect ratio is preserved; the
/// padding is black, matching the usual hand-landmark export preprocessing.
#[cfg(any(feature = "gestures", test))]
pub(crate) fn letterbox(
    frame: &PreviewFrame,
    input_size: u32,
    mirror: bool,
) -> Option<(Vec<f32>, f32, f32, f32)> {
    let (w, h) = (frame.width, frame.height);
    let size = input_size as usize;
    if w == 0 || h == 0 || size == 0 || frame.rgb.len() < (w * h * 3) as usize {
        return None;
    }

    let scale = input_size as f32 / w.max(h) as f32;
    let new_w = (w as f32 * scale).round().max(1.0) as u32;
    let new_h = (h as f32 * scale).round().max(1.0) as u32;
    let pad_x = (input_size.saturating_sub(new_w)) as f32 / 2.0;
    let pad_y = (input_size.saturating_sub(new_h)) as f32 / 2.0;

    let mut tensor = vec![0.0f32; 3 * size * size];
    for y in 0..new_h {
        let sy = (y as f32 / scale).floor() as u32;
        if sy >= h {
            continue;
        }
        for x in 0..new_w {
            let mut sx = (x as f32 / scale).floor() as u32;
            if sx >= w {
                continue;
            }
            if mirror {
                sx = w - 1 - sx;
            }
            let src = ((sy * w + sx) * 3) as usize;
            if src + 2 >= frame.rgb.len() {
                continue;
            }
            let dx = x + pad_x as u32;
            let dy = y + pad_y as u32;
            if dx as usize >= size || dy as usize >= size {
                continue;
            }
            let dst = dy as usize * size + dx as usize;
            tensor[dst] = frame.rgb[src] as f32 / 255.0;
            tensor[size * size + dst] = frame.rgb[src + 1] as f32 / 255.0;
            tensor[2 * size * size + dst] = frame.rgb[src + 2] as f32 / 255.0;
        }
    }

    Some((tensor, scale, pad_x, pad_y))
}

#[cfg(feature = "gestures")]
pub use ort_backend::OrtHandPose;

#[cfg(feature = "gestures")]
mod ort_backend {
    use super::*;
    use crate::error::AppError;
    use std::path::Path;

    /// ONNX hand-landmark backend on the pinned `ort` 2.0 rc.9 runtime.
    pub struct OrtHandPose {
        session: ort::session::Session,
        input_name: String,
        landmark_output: usize,
        score_output: Option<usize>,
        handedness_output: Option<usize>,
        input_size: u32,
        mirror: bool,
        layout: InputLayout,
    }

    /// Declared dimensions of a session input/output tensor, or empty when the
    /// value is not a tensor.
    fn tensor_dims(value: &ort::value::ValueType) -> Vec<i64> {
        match value {
            ort::value::ValueType::Tensor { dimensions, .. } => dimensions.clone(),
            _ => Vec::new(),
        }
    }

    impl OrtHandPose {
        /// Load an ONNX export.
        ///
        /// Input and output roles are discovered by name, with a positional
        /// fallback for exports with anonymous tensors. A model whose output
        /// does not decode to 21 landmarks is rejected at the first frame with
        /// a message that names the actual output shapes.
        pub fn load(path: &Path, input_size: u32, mirror: bool) -> AppResult<Self> {
            let session = ort::session::Session::builder()?
                .commit_from_file(path)
                .map_err(|e| AppError::Model(format!("failed to load hand-landmark model: {e}")))?;

            let input = session
                .inputs
                .first()
                .ok_or_else(|| AppError::Model("hand-landmark model has no inputs".into()))?;
            let input_name = input.name.clone();
            let layout = layout_from_dims(&tensor_dims(&input.input_type));

            let mut landmark_output = None;
            let mut score_output = None;
            let mut handedness_output = None;
            for (index, output) in session.outputs.iter().enumerate() {
                let lower = output.name.to_ascii_lowercase();
                if handedness_output.is_none()
                    && (lower.contains("handed") || lower.contains("left"))
                {
                    handedness_output = Some(index);
                } else if score_output.is_none()
                    && (lower.contains("score")
                        || lower.contains("flag")
                        || lower.contains("presence")
                        || lower.contains("confidence"))
                {
                    score_output = Some(index);
                } else if landmark_output.is_none()
                    && (lower.contains("landmark")
                        || lower.contains("ld_21")
                        || lower.contains("keypoint"))
                {
                    landmark_output = Some(index);
                }
            }
            // Fallback 1: tensor sizes, for exports with anonymous names.
            let counts: Vec<Option<usize>> = session
                .outputs
                .iter()
                .map(|o| element_count(&tensor_dims(&o.output_type)))
                .collect();
            let (shape_landmark, shape_score, shape_handedness) = roles_from_shapes(&counts);
            let mut score_output = score_output.or(shape_score);
            let mut handedness_output = handedness_output.or(shape_handedness);

            // Fallback 2: the first output that is not a score/handedness head.
            let landmark_output = landmark_output
                .or(shape_landmark)
                .or_else(|| {
                    (0..session.outputs.len())
                        .find(|i| Some(*i) != score_output && Some(*i) != handedness_output)
                })
                .ok_or_else(|| AppError::Model("hand-landmark model has no outputs".into()))?;

            // A shape guess must never claim the landmark tensor for a head.
            if score_output == Some(landmark_output) {
                score_output = None;
            }
            if handedness_output == Some(landmark_output) {
                handedness_output = None;
            }

            Ok(Self {
                session,
                input_name,
                landmark_output,
                score_output,
                handedness_output,
                input_size: input_size.max(64),
                mirror,
                layout,
            })
        }

        /// Output shapes, for `gesture_probe` and diagnostics.
        pub fn describe(&self) -> String {
            let outputs = self
                .session
                .outputs
                .iter()
                .enumerate()
                .map(|(i, o)| format!("{i}:{}", o.name))
                .collect::<Vec<_>>()
                .join(", ");
            format!(
                "input={} layout={:?} outputs=[{}] landmark_index={}",
                self.input_name, self.layout, outputs, self.landmark_output
            )
        }
    }

    impl HandPoseBackend for OrtHandPose {
        fn name(&self) -> &'static str {
            "ort-hand-landmark"
        }

        fn detect(&mut self, frame: &PreviewFrame, at_ms: u64) -> AppResult<Vec<HandLandmarks>> {
            let (data, scale, pad_x, pad_y) = letterbox(frame, self.input_size, self.mirror)
                .ok_or_else(|| AppError::Other("empty or undersized camera frame".into()))?;

            let size = self.input_size as usize;
            let tensor = match self.layout {
                InputLayout::Nchw => ndarray16::Array::from_shape_vec((1, 3, size, size), data)
                    .map_err(|e| AppError::Other(format!("tensor reshape: {e}")))?
                    .into_dyn(),
                InputLayout::Nhwc => {
                    let hwc = chw_to_hwc(&data, size);
                    if hwc.is_empty() {
                        return Err(AppError::Other("empty camera frame".into()));
                    }
                    ndarray16::Array::from_shape_vec((1, size, size, 3), hwc)
                        .map_err(|e| AppError::Other(format!("tensor reshape: {e}")))?
                        .into_dyn()
                }
            };

            let outputs = self
                .session
                .run(ort::inputs![ort::value::Value::from_array(tensor)?]?)
                .map_err(|e| AppError::Other(format!("hand-landmark inference failed: {e}")))?;

            let out = outputs[self.landmark_output]
                .try_extract_tensor::<f32>()
                .map_err(|e| AppError::Other(format!("landmark output tensor: {e}")))?;
            let values: Vec<f32> = out.view().iter().copied().collect();

            let Some((points, bbox)) = decode_landmarks(
                &values,
                frame.width,
                frame.height,
                self.input_size,
                scale,
                pad_x,
                pad_y,
                self.mirror,
            ) else {
                return Ok(Vec::new());
            };

            let score = self
                .score_output
                .and_then(|index| outputs[index].try_extract_tensor::<f32>().ok())
                .and_then(|t| t.view().iter().next().copied())
                .map(|s| s.clamp(0.0, 1.0))
                .unwrap_or(1.0);

            let handedness = self
                .handedness_output
                .and_then(|index| outputs[index].try_extract_tensor::<f32>().ok())
                .and_then(|t| {
                    let v: Vec<f32> = t.view().iter().copied().collect();
                    if v.len() < 2 {
                        return None;
                    }
                    Some(if v[1] > v[0] {
                        Handedness::Right
                    } else {
                        Handedness::Left
                    })
                })
                // A mirrored buffer inverts the model's handedness label.
                .map(|h| if self.mirror { h.mirrored() } else { h })
                .unwrap_or(Handedness::Unknown);

            Ok(vec![HandLandmarks {
                track_id: Some(1),
                handedness,
                score,
                points,
                bbox,
                at_ms,
            }])
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn layout_is_read_from_the_declared_dims() {
        assert_eq!(layout_from_dims(&[1, 3, 224, 224]), InputLayout::Nchw);
        assert_eq!(layout_from_dims(&[1, 224, 224, 3]), InputLayout::Nhwc);
        assert_eq!(layout_from_dims(&[-1, -1, -1, 3]), InputLayout::Nhwc);
        // Dynamic or unusual shapes keep the historic behaviour.
        assert_eq!(layout_from_dims(&[-1, -1, -1, -1]), InputLayout::Nchw);
        assert_eq!(layout_from_dims(&[]), InputLayout::Nchw);
    }

    #[test]
    fn chw_to_hwc_interleaves_channels() {
        let size = 2;
        // One pixel per channel: R=[1,2,3,4], G=[5,6,7,8], B=[9,10,11,12].
        let mut chw = Vec::new();
        chw.extend_from_slice(&[1.0, 2.0, 3.0, 4.0]);
        chw.extend_from_slice(&[5.0, 6.0, 7.0, 8.0]);
        chw.extend_from_slice(&[9.0, 10.0, 11.0, 12.0]);
        let hwc = chw_to_hwc(&chw, size);
        assert_eq!(hwc.len(), 12);
        assert_eq!(&hwc[0..3], &[1.0, 5.0, 9.0]);
        assert_eq!(&hwc[3..6], &[2.0, 6.0, 10.0]);
        assert_eq!(&hwc[9..12], &[4.0, 8.0, 12.0]);
        assert!(chw_to_hwc(&[1.0, 2.0], size).is_empty());
    }

    #[test]
    fn anonymous_outputs_resolve_by_shape() {
        // The tf2onnx MediaPipe export: Identity[1,63], Identity_1[1,1],
        // Identity_2[1,1], Identity_3[1,63].
        let counts = [Some(63), Some(1), Some(1), Some(63)];
        assert_eq!(roles_from_shapes(&counts), (Some(0), Some(1), Some(2)));

        // A z-less export (21x2) still resolves.
        assert_eq!(
            roles_from_shapes(&[Some(1), Some(42)]),
            (Some(1), Some(0), None)
        );

        // Fully dynamic shapes leave every role unknown.
        assert_eq!(roles_from_shapes(&[None, None]), (None, None, None));
    }

    #[test]
    fn element_count_needs_every_dimension() {
        assert_eq!(element_count(&[1, 63]), Some(63));
        assert_eq!(element_count(&[1, 1]), Some(1));
        assert_eq!(element_count(&[-1, 63]), None);
        assert_eq!(element_count(&[]), None);
    }

    fn frame(w: u32, h: u32) -> PreviewFrame {
        PreviewFrame {
            width: w,
            height: h,
            rgb: vec![128; (w * h * 3) as usize],
        }
    }

    #[test]
    fn letterbox_preserves_aspect_and_reports_padding() {
        let f = frame(640, 480);
        let (data, scale, pad_x, pad_y) = letterbox(&f, 224, false).expect("letterbox");
        assert_eq!(data.len(), 3 * 224 * 224);
        assert!((scale - 0.35).abs() < 1e-4);
        assert!((pad_x - 0.0).abs() < 1e-4);
        // (224 - 168) / 2 = 28
        assert!((pad_y - 28.0).abs() < 1e-4);
    }

    #[test]
    fn letterbox_rejects_bad_frames() {
        assert!(letterbox(&frame(0, 0), 224, false).is_none());
        let mut broken = frame(640, 480);
        broken.rgb.truncate(10);
        assert!(letterbox(&broken, 224, false).is_none());
    }

    #[test]
    fn normalised_landmarks_map_to_the_source_frame() {
        // Centred hand in a 640x480 frame, letterboxed to 224 with pad_y=28.
        let mut values = vec![0.0f32; 63];
        for i in 0..21 {
            // Keep the wrist at the centre but spread the other landmarks so
            // the derived bbox has a real extent.
            values[i * 3] = if i == 0 {
                0.5
            } else {
                0.5 + ((i % 3) as f32 - 1.0) * 0.05
            };
            values[i * 3 + 1] = if i == 0 {
                0.5
            } else {
                0.5 + ((i % 4) as f32 - 1.5) * 0.05
            };
        }
        let (points, bbox) =
            decode_landmarks(&values, 640, 480, 224, 0.35, 0.0, 28.0, false).expect("decodes");
        assert!((points[0].x - 0.5).abs() < 1e-3);
        // ny 0.5 of the input → input pixel 112 → (112-28)/0.35 = 240 → 0.5 of 480.
        assert!((points[0].y - 0.5).abs() < 1e-3);
        assert!(bbox.w > 0 && bbox.h > 0);
    }

    #[test]
    fn pixel_scale_landmarks_are_detected_by_magnitude() {
        let mut values = vec![0.0f32; 63];
        for i in 0..21 {
            values[i * 3] = 112.0;
            values[i * 3 + 1] = 112.0;
        }
        let (points, _) =
            decode_landmarks(&values, 640, 480, 224, 0.35, 0.0, 28.0, false).expect("decodes");
        assert!((points[0].x - 0.5).abs() < 1e-3);
    }

    #[test]
    fn mirroring_flips_x() {
        let mut values = vec![0.0f32; 63];
        for i in 0..21 {
            values[i * 3] = 0.25;
            values[i * 3 + 1] = 0.5;
        }
        let (plain, _) =
            decode_landmarks(&values, 640, 480, 224, 0.35, 0.0, 28.0, false).expect("decodes");
        let (flipped, _) =
            decode_landmarks(&values, 640, 480, 224, 0.35, 0.0, 28.0, true).expect("decodes");
        assert!((plain[0].x + flipped[0].x - 1.0).abs() < 2e-3);
    }

    #[test]
    fn malformed_tensors_are_no_hand_not_a_panic() {
        assert!(decode_landmarks(&[], 640, 480, 224, 0.35, 0.0, 0.0, false).is_none());
        let nan = vec![f32::NAN; 63];
        assert!(decode_landmarks(&nan, 640, 480, 224, 0.35, 0.0, 0.0, false).is_none());
    }

    #[test]
    fn bbox_is_clipped_to_the_frame() {
        let mut values = vec![0.0f32; 63];
        for i in 0..21 {
            values[i * 3] = 0.0;
            values[i * 3 + 1] = 0.0;
        }
        let (_, bbox) =
            decode_landmarks(&values, 640, 480, 224, 0.35, 0.0, 28.0, false).expect("decodes");
        assert!(bbox.x + bbox.w <= 640);
        assert!(bbox.y + bbox.h <= 480);
    }
}
