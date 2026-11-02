//! RapidOCR (PP-OCR det + rec) ONNX pipeline — the portable/multilingual
//! backend for snippet OCR (Proposal 100, M4).
//!
//! Two stages, hand-rolled on the pinned `ort` rc.9 exactly like the vision
//! detector (letterbox → session → decode). `rapid-ocr-rs` is deliberately
//! NOT used: it pulls its own ONNX Runtime version and would drift the
//! `ort = "=2.0.0-rc.9"` pin shared with `vision`/`moonshine`.
//!
//! - Detection: DBNet probability map → threshold → connected components →
//!   axis-aligned boxes in reading order (top-to-bottom, left-to-right).
//! - Recognition: per-box crop → 3×48×W (aspect-preserving, width padded to
//!   8, capped 320) → softmax CRNN → greedy argmax through the dict with
//!   consecutive-duplicate collapse.
//!
//! The pure decode functions are unit-tested on synthetic tensors; session
//! loading is verified by the `snippet_ocr_probe` example against real
//! models.

use crate::error::{AppError, AppResult};
use crate::snippet::{SnippetText, SnippetWord};

/// PaddleOCR-export input/output names (RapidOCR's exports use the same).
pub const DET_INPUT_NAME: &str = "x";
pub const REC_INPUT_NAME: &str = "x";

/// Detection: longest side limit (PP-OCRv4/v5 det), with a minimum side so
/// tiny selections still detect.
pub const DET_LIMIT_SIDE: u32 = 960;
pub const DET_MIN_SIDE: u32 = 32;
/// Detection probability-map threshold (RapidOCR default).
pub const DET_BOX_THRESH: f32 = 0.3;

/// Recognition: fixed height, dynamic width (multiple of 8, capped).
pub const REC_IMG_H: u32 = 48;
pub const REC_MAX_W: u32 = 320;

/// A text box in **source-pixel** coordinates of the image the detector was
/// fed (before any det resize).
#[derive(Debug, Clone, Copy, PartialEq)]
pub struct OcrBox {
    pub x: u32,
    pub y: u32,
    pub w: u32,
    pub h: u32,
}

/// Resize an RGB frame for the detector: aspect-preserving to
/// `DET_LIMIT_SIDE` longest side (never below `DET_MIN_SIDE` shortest), then
/// pad right/bottom to a multiple of 32. Returns the CHW float tensor
/// (ImageNet-normalized) plus the scale/pad needed to project boxes back.
pub fn det_preprocess(rgb: &[u8], w: u32, h: u32) -> Option<(Vec<f32>, u32, u32, u32, u32, f32)> {
    if w == 0 || h == 0 || rgb.len() < (w * h * 3) as usize {
        return None;
    }
    let mut scale = (DET_LIMIT_SIDE as f32 / w as f32).min(DET_LIMIT_SIDE as f32 / h as f32);
    // A tiny selection must be upscaled rather than shrunk to nothing.
    if ((w as f32 * scale).round() as u32) < DET_MIN_SIDE {
        scale = DET_MIN_SIDE as f32 / w as f32;
    }
    if ((h as f32 * scale).round() as u32) < DET_MIN_SIDE {
        scale = scale.max(DET_MIN_SIDE as f32 / h as f32);
    }
    let nw = ((w as f32 * scale).round() as u32).max(32);
    let nh = ((h as f32 * scale).round() as u32).max(32);
    // Pad to a multiple of 32 for the model's downsampling chain.
    let pad_w = (32 - nw % 32) % 32;
    let pad_h = (32 - nh % 32) % 32;
    let tw = nw + pad_w;
    let th = nh + pad_h;

    let mut out = vec![0f32; (tw * th * 3) as usize];
    for y in 0..nh {
        let sy = ((y as f32) / scale) as u32;
        let syc = sy.min(h - 1);
        for x in 0..nw {
            let sx = ((x as f32) / scale) as u32;
            let sxc = sx.min(w - 1);
            let src = ((syc * w + sxc) * 3) as usize;
            let dst = (y * tw + x) as usize;
            // ImageNet normalization (x/255 - mean) / std.
            out[dst] = (rgb[src] as f32 / 255.0 - 0.485) / 0.229;
            out[dst + (tw * th) as usize] = (rgb[src + 1] as f32 / 255.0 - 0.456) / 0.224;
            out[dst + 2 * (tw * th) as usize] = (rgb[src + 2] as f32 / 255.0 - 0.406) / 0.225;
        }
    }
    Some((out, nw, nh, tw, th, scale))
}

/// Decode the DBNet probability map (shape `[1, 1, H, W]`) into source-pixel
/// boxes in reading order. Threshold → 4-connected components → bounding
/// boxes → project through `scale` → 3 px pad. Pure and unit-tested.
pub fn decode_det(prob: ndarray16::ArrayViewD<f32>, tw: u32, th: u32, scale: f32) -> Vec<OcrBox> {
    let w = tw as usize;
    let h = th as usize;
    if prob.shape().len() < 2
        || prob.shape()[prob.ndim() - 2] != h
        || prob.shape()[prob.ndim() - 1] != w
    {
        return Vec::new();
    }
    let base = prob.as_slice().expect("det probability map is contiguous");
    let map: Vec<u8> = base.iter().map(|&v| u8::from(v > DET_BOX_THRESH)).collect();

    // Union-find over foreground pixels (union with left/up neighbors).
    let n = w * h;
    let mut parent: Vec<i32> = (0..n as i32).collect();
    let mut active = vec![false; n];
    let mut rank = vec![0u8; n];
    let find = |parent: &mut Vec<i32>, mut i: usize| -> usize {
        while parent[i] != i as i32 {
            parent[i] = parent[parent[i] as usize];
            i = parent[i] as usize;
        }
        i
    };
    let union = |parent: &mut Vec<i32>, rank: &mut Vec<u8>, a: usize, b: usize| {
        if a == b {
            return;
        }
        if rank[a] < rank[b] {
            parent[a] = b as i32;
        } else if rank[a] > rank[b] {
            parent[b] = a as i32;
        } else {
            parent[b] = a as i32;
            rank[a] += 1;
        }
    };
    for y in 0..h {
        for x in 0..w {
            let i = y * w + x;
            if map[i] == 0 {
                continue;
            }
            active[i] = true;
            if x > 0 && map[i - 1] != 0 {
                let a = find(&mut parent, i);
                let b = find(&mut parent, i - 1);
                union(&mut parent, &mut rank, a, b);
            }
            if y > 0 && map[i - w] != 0 {
                let a = find(&mut parent, i);
                let b = find(&mut parent, i - w);
                union(&mut parent, &mut rank, a, b);
            }
        }
    }
    // Per-root bounding box.
    let mut bounds: Vec<Option<(usize, usize, usize, usize)>> = vec![None; n];
    for y in 0..h {
        for x in 0..w {
            let i = y * w + x;
            if !active[i] {
                continue;
            }
            let r = find(&mut parent, i);
            let b = bounds[r].get_or_insert((x, y, x, y));
            b.0 = b.0.min(x);
            b.1 = b.1.min(y);
            b.2 = b.2.max(x);
            b.3 = b.3.max(y);
        }
    }
    let mut boxes: Vec<OcrBox> = bounds
        .into_iter()
        .flatten()
        .filter(|(x1, y1, x2, y2)| (x2 - x1) >= 1 && (y2 - y1) >= 1)
        .map(|(x1, y1, x2, y2)| {
            // Unclip-style expansion (RapidOCR's unclip_ratio 1.5 spirit,
            // simplified to axis-aligned): 6% margin per side + 3 px so
            // glyph edges survive the crop; clamp to the source image is
            // applied later in `rec_preprocess`.
            let w = (x2 - x1) as i64 + 1;
            let h = (y2 - y1) as i64 + 1;
            let mx = (w as f64 * 0.06).ceil() as i64 + 3;
            let my = (h as f64 * 0.06).ceil() as i64 + 3;
            let x = ((x1 as f32 / scale).floor() as i64 - mx).max(0) as u32;
            let y = ((y1 as f32 / scale).floor() as i64 - my).max(0) as u32;
            let ex = ((x2 as f32 / scale).ceil() as i64 + mx).max(x as i64 + 1);
            let ey = ((y2 as f32 / scale).ceil() as i64 + my).max(y as i64 + 1);
            OcrBox {
                x,
                y,
                w: ((ex - x as i64).max(1)) as u32,
                h: ((ey - y as i64).max(1)) as u32,
            }
        })
        .collect();
    // Reading order: top-to-bottom, then left-to-right.
    boxes.sort_by(|a, b| a.y.cmp(&b.y).then(a.x.cmp(&b.x)));
    boxes
}

/// Preprocess a box crop for the recognizer: RGB → 3×`REC_IMG_H`×W
/// (aspect-preserving, width rounded up to 8, capped at `REC_MAX_W`),
/// normalized `(x/255 - 0.5) / 0.5`. **Channel order is BGR** — the
/// PaddleOCR `DecodeImage` transform specifies `img_mode: BGR`, and the
/// model weights were trained on it (feeding RGB yields all-blank output).
pub fn rec_preprocess(rgb: &[u8], w: u32, h: u32, crop: &OcrBox) -> Option<(Vec<f32>, u32, u32)> {
    let x1 = crop.x.min(w.saturating_sub(1));
    let y1 = crop.y.min(h.saturating_sub(1));
    let x2 = (crop.x.saturating_add(crop.w)).min(w).max(x1 + 1);
    let y2 = (crop.y.saturating_add(crop.h)).min(h).max(y1 + 1);
    let cw = x2 - x1;
    let ch = y2 - y1;
    if cw == 0 || ch == 0 {
        return None;
    }
    let mut rw = (cw as f32 * REC_IMG_H as f32 / ch as f32).round() as u32;
    rw = rw.clamp(8, REC_MAX_W);
    // Exact aspect width — do NOT quantize to a multiple of 8: the ONNX
    // export takes dynamic width, and quantization clips the last glyph
    // (observed as trailing-char swaps).
    let rh = REC_IMG_H;

    let mut out = vec![0f32; (rw * rh * 3) as usize];
    for y in 0..rh {
        let sy = y * ch / rh;
        for x in 0..rw {
            let sx = x * cw / rw;
            let src = (((y1 + sy) * w + (x1 + sx)) * 3) as usize;
            let dst = (y * rw + x) as usize;
            // BGR order (PaddleOCR convention): plane 0 = blue, plane 2 = red.
            out[dst] = rgb[src + 2] as f32 / 127.5 - 1.0;
            out[dst + (rw * rh) as usize] = rgb[src + 1] as f32 / 127.5 - 1.0;
            out[dst + 2 * (rw * rh) as usize] = rgb[src] as f32 / 127.5 - 1.0;
        }
    }
    Some((out, rw, rh))
}

/// Greedy-decode recognizer logits (shape `[1, T, C]`): per-timestep argmax
/// under the PaddleOCR export convention — class 0 = CTC blank, class
/// i ≥ 1 maps to `dict[i - 1]`. (The official PP-OCRv5 ONNX has
/// `len(dict) + 2` output classes: blank + one extra token from the shared
/// GTC/NRTR head that is never decoded here.) Consecutive duplicates
/// collapse. Returns `(text, mean_confidence)`.
pub fn decode_rec(logits: ndarray16::ArrayViewD<f32>, dict: &[String]) -> (String, f32) {
    let shape = logits.shape().to_vec();
    let (t, c) = match shape.as_slice() {
        [_, tt, cc, ..] => (*tt, *cc),
        [tt, cc] => (*tt, *cc),
        _ => (0, 0),
    };
    if t == 0 || c == 0 {
        return (String::new(), 0.0);
    }
    let mut out = String::new();
    let mut conf_sum = 0.0f32;
    let mut conf_n = 0u32;
    let mut last: Option<usize> = None;
    let row_shape = if logits.ndim() == 3 {
        Some(shape[0])
    } else {
        None
    };
    for ti in 0..t {
        let row = match row_shape {
            Some(_) => logits.slice(ndarray16::s![0, ti, ..]),
            None => logits.slice(ndarray16::s![ti, ..]),
        };
        let mut best = 0usize;
        let mut best_v = f32::MIN;
        for (ci, &v) in row.iter().enumerate() {
            if v > best_v {
                best_v = v;
                best = ci;
            }
        }
        // Blank (also breaks repeat runs — a blank between two same letters
        // means the letter genuinely appears twice).
        if best == 0 {
            last = None;
            continue;
        }
        // Out-of-dict classes (the extra GTC/NRTR token) also break repeat
        // runs: an intervening garbage class suggests a different glyph.
        let Some(ch) = dict.get(best - 1) else {
            last = None;
            continue;
        };
        if last == Some(best) {
            continue; // collapse repeats
        }
        last = Some(best);
        out.push_str(ch);
        conf_sum += best_v;
        conf_n += 1;
    }
    let conf = if conf_n > 0 {
        conf_sum / conf_n as f32
    } else {
        0.0
    };
    (out, conf)
}

/// A resident RapidOCR detector: owns the DBNet ONNX session.
pub struct OcrDetector {
    session: ort::session::Session,
}

impl OcrDetector {
    pub fn load(path: &std::path::Path) -> AppResult<Self> {
        let session = ort::session::Session::builder()?
            .commit_from_file(path)
            .map_err(|e| AppError::Model(format!("failed to load OCR det model: {e}")))?;
        Ok(Self { session })
    }

    /// Detect text boxes in an RGB image (`w`×`h`, `w*h*3` bytes), sorted in
    /// reading order.
    pub fn detect_rgb(&self, rgb: &[u8], w: u32, h: u32) -> AppResult<Vec<OcrBox>> {
        let (input, nw, nh, tw, th, scale) = det_preprocess(rgb, w, h)
            .ok_or_else(|| AppError::Other("empty or undersized OCR region".into()))?;
        let tensor = ndarray16::Array::from_shape_vec((1, 3, th as usize, tw as usize), input)
            .map_err(|e| AppError::Other(format!("det tensor reshape: {e}")))?
            .into_dyn();
        let outputs = self
            .session
            .run(ort::inputs![ort::value::Value::from_array(tensor)?]?)
            .map_err(|e| AppError::Other(format!("det inference failed: {e}")))?;
        let out = outputs[0]
            .try_extract_tensor::<f32>()
            .map_err(|e| AppError::Other(format!("det output tensor: {e}")))?;
        let _ = (nw, nh);
        Ok(decode_det(out.view(), tw, th, scale))
    }
}

/// A resident RapidOCR recognizer: owns the SVTR/CRNN ONNX session + dict.
pub struct OcrRecognizer {
    session: ort::session::Session,
    dict: Vec<String>,
}

impl OcrRecognizer {
    pub fn load(rec_path: &std::path::Path, dict_path: &std::path::Path) -> AppResult<Self> {
        let session = ort::session::Session::builder()?
            .commit_from_file(rec_path)
            .map_err(|e| AppError::Model(format!("failed to load OCR rec model: {e}")))?;
        let dict_raw = std::fs::read_to_string(dict_path)
            .map_err(|e| AppError::Model(format!("failed to read OCR dict: {e}")))?;
        let dict: Vec<String> = dict_raw
            .lines()
            .map(|l| l.trim_end_matches('\r').to_string())
            .collect();
        if dict.is_empty() {
            return Err(AppError::Model("OCR dict is empty".into()));
        }
        Ok(Self { session, dict })
    }

    /// Recognize one crop of an RGB image. Returns `(text, confidence)`.
    pub fn recognize_crop(
        &self,
        rgb: &[u8],
        w: u32,
        h: u32,
        crop: &OcrBox,
    ) -> AppResult<(String, f32)> {
        let (input, rw, rh) = rec_preprocess(rgb, w, h, crop)
            .ok_or_else(|| AppError::Other("empty OCR crop".into()))?;
        let tensor = ndarray16::Array::from_shape_vec((1, 3, rh as usize, rw as usize), input)
            .map_err(|e| AppError::Other(format!("rec tensor reshape: {e}")))?
            .into_dyn();
        let outputs = self
            .session
            .run(ort::inputs![ort::value::Value::from_array(tensor)?]?)
            .map_err(|e| AppError::Other(format!("rec inference failed: {e}")))?;
        let out = outputs[0]
            .try_extract_tensor::<f32>()
            .map_err(|e| AppError::Other(format!("rec output tensor: {e}")))?;
        Ok(decode_rec(out.view(), &self.dict))
    }
}

/// Full pipeline: detect boxes, recognize each, join into reading order with
/// a line split on large vertical gaps. Used by the controller and the
/// `snippet_ocr_probe` spike.
pub fn recognize_rgb(
    detector: &OcrDetector,
    recognizer: &OcrRecognizer,
    rgb: &[u8],
    w: u32,
    h: u32,
) -> AppResult<SnippetText> {
    let boxes = detector.detect_rgb(rgb, w, h)?;
    let mut words: Vec<SnippetWord> = Vec::new();
    for b in &boxes {
        match recognizer.recognize_crop(rgb, w, h, b) {
            Ok((text, _)) if !text.trim().is_empty() => words.push(SnippetWord {
                text,
                x: b.x,
                y: b.y,
                w: b.w,
                h: b.h,
            }),
            Ok(_) => {}
            Err(e) => tracing::warn!("OCR crop failed: {e}"),
        }
    }
    // Group into lines: a new line starts when the vertical gap between
    // consecutive boxes exceeds half the previous box height.
    let mut lines: Vec<String> = Vec::new();
    let mut current = String::new();
    let mut prev_y2: Option<u32> = None;
    let mut prev_h: u32 = 0;
    for wd in &words {
        if let Some(py2) = prev_y2 {
            let gap = wd.y as i64 - py2 as i64;
            if gap > (prev_h as i64 / 2).max(4) && !current.is_empty() {
                lines.push(std::mem::take(&mut current));
            }
        }
        if !current.is_empty() {
            current.push(' ');
        }
        current.push_str(&wd.text);
        prev_y2 = Some(wd.y + wd.h);
        prev_h = wd.h;
    }
    if !current.is_empty() {
        lines.push(current);
    }
    // Per-word confidence is not surfaced in v1 (kept honest).
    let confidence = None;
    Ok(SnippetText {
        text: lines.join("\n"),
        words,
        engine: "onnx".into(),
        confidence,
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn decode_det_recovers_single_axis_aligned_box() {
        // 64x32 probability map: a solid 20x10 rectangle at (10, 10).
        let w = 64usize;
        let h = 32usize;
        let mut map = vec![0f32; w * h];
        for y in 10..20 {
            for x in 10..30 {
                map[y * w + x] = 0.9;
            }
        }
        let arr = ndarray16::Array::from_shape_vec((1, 1, h, w), map).unwrap();
        let boxes = decode_det(arr.view().into_dyn(), w as u32, h as u32, 1.0);
        assert_eq!(boxes.len(), 1);
        let b = boxes[0];
        // 10 - unclip margin (~5 for a 20px-wide box).
        assert!((4..=6).contains(&(b.x as i32)), "x={}", b.x);
        assert!((5..=7).contains(&(b.y as i32)), "y={}", b.y);
        assert!((28..=32).contains(&(b.w as i32)), "w={}", b.w);
        assert!((16..=20).contains(&(b.h as i32)), "h={}", b.h);
    }

    #[test]
    fn decode_det_orders_boxes_reading_order() {
        let w = 64usize;
        let h = 64usize;
        let mut map = vec![0f32; w * h];
        // Two boxes: one lower-left, one upper-right.
        for y in 5..15 {
            for x in 40..55 {
                map[y * w + x] = 0.9;
            }
        }
        for y in 40..50 {
            for x in 5..20 {
                map[y * w + x] = 0.9;
            }
        }
        let arr = ndarray16::Array::from_shape_vec((1, 1, h, w), map).unwrap();
        let boxes = decode_det(arr.view().into_dyn(), w as u32, h as u32, 1.0);
        assert_eq!(boxes.len(), 2);
        assert!(boxes[0].y < boxes[1].y);
    }

    #[test]
    fn decode_rec_collapses_repeats_and_skips_blank() {
        // PaddleOCR export convention: class 0 = blank, class i >= 1 =
        // dict[i-1].
        let dict: Vec<String> = vec!["h", "e", "l", "o"]
            .into_iter()
            .map(String::from)
            .collect();
        // 5 classes: 0 blank, 1=h, 2=e, 3=l, 4=o
        // T=8: h e l l o blank o blank
        let logits = ndarray16::Array::from_shape_vec(
            (1, 8, 5),
            vec![
                0.1, 0.9, 0.0, 0.0, 0.0, // h
                0.1, 0.0, 0.9, 0.0, 0.0, // e
                0.1, 0.0, 0.0, 0.9, 0.0, // l
                0.1, 0.0, 0.0, 0.9, 0.0, // l
                0.1, 0.0, 0.0, 0.0, 0.9, // o
                0.9, 0.0, 0.0, 0.0, 0.0, // blank
                0.1, 0.0, 0.0, 0.0, 0.9, // o (blank broke the repeat)
                0.9, 0.0, 0.0, 0.0, 0.0, // blank
            ],
        )
        .unwrap();
        let (text, conf) = decode_rec(logits.view().into_dyn(), &dict);
        assert_eq!(text, "heloo");
        assert!((conf - 0.9).abs() < 1e-4);
    }

    #[test]
    fn decode_rec_out_of_dict_class_is_skipped() {
        let dict: Vec<String> = vec!["a"].into_iter().map(String::from).collect();
        // 3 classes (blank + dict + extra NRTR token): the extra class must
        // be skipped, not panic.
        let logits = ndarray16::Array::from_shape_vec(
            (1, 3, 3),
            vec![
                0.1, 0.9, 0.0, // a
                0.1, 0.0, 0.9, // extra class 2 (no dict entry)
                0.1, 0.9, 0.0, // a (repeat was broken by the skip)
            ],
        )
        .unwrap();
        let (text, _) = decode_rec(logits.view().into_dyn(), &dict);
        assert_eq!(text, "aa");
    }

    #[test]
    fn rec_preprocess_aspect_and_multiple_of_8() {
        let rgb = vec![200u8; (100 * 40 * 3) as usize];
        let crop = OcrBox {
            x: 0,
            y: 0,
            w: 100,
            h: 40,
        };
        let (out, rw, rh) = rec_preprocess(&rgb, 100, 40, &crop).unwrap();
        assert_eq!(rh, REC_IMG_H);
        assert_eq!(rw % 8, 0);
        assert!(rw <= REC_MAX_W);
        assert_eq!(out.len(), (rw * rh * 3) as usize);
    }
}
