//! Vision label polish (Proposal 076): relabel YOLO detection boxes with a
//! local VLM.
//!
//! YOLO gives fast, cheap, ~15 fps boxes but only an 80-class COCO label. A
//! local vision-language model (LM Studio / Ollama serving Qwen2.5-VL or
//! similar) names the actual object in each crop. This module packs a crop
//! into a base64 PNG data-URI, sends a multimodal OpenAI-compatible request
//! through the existing `LocalOpenAiProvider`, and parses `{label, confidence}`.
//!
//! The polished label is opt-in and non-blocking: it runs on a dedicated
//! `nvoos-vision-polish` thread (owning a tokio runtime), so the ~hundreds-of-ms
//! VLM round-trip never stalls the 15 fps detect loop. The no-facial-recognition
//! bright line is enforced by the system prompt in
//! `cleanup::providers::openai_shape::vision_polish_prompt`.

use crate::cleanup::providers::local_openai::LocalOpenAiProvider;
use crate::cleanup::providers::openai_shape;
use crate::error::{AppError, AppResult};

/// A single crop to relabel, sent from the detect loop to the polish worker.
#[derive(Debug, Clone)]
pub struct PolishRequest {
    /// Track id to attach the result to (stable across frames).
    pub id: Option<u64>,
    /// Source-frame crop, already PNG-encoded as a base64 data-URI.
    pub image_data_uri: String,
}

/// A resolved polish result for one box.
#[derive(Debug, Clone)]
pub struct PolishResult {
    pub id: Option<u64>,
    pub label: String,
    pub confidence: f32,
}

/// Relabels detection crops via a local VLM. Owns a tokio runtime because the
/// controller's detect loop runs on a plain `std::thread` (no async context).
pub struct VisionPolisher {
    provider: LocalOpenAiProvider,
    rt: tokio::runtime::Runtime,
}

impl VisionPolisher {
    /// Build a polisher. `provider` is pre-configured with the right base URL
    /// / model / token by the caller. A current-thread runtime drives the
    /// async HTTP calls; the polisher runs on its own thread.
    pub fn new(provider: LocalOpenAiProvider) -> AppResult<Self> {
        let rt = tokio::runtime::Builder::new_current_thread()
            .enable_all()
            .build()?;
        Ok(Self { provider, rt })
    }

    /// Run one relabel round-trip (blocking on this thread; call from the
    /// polish worker thread, not the detect loop).
    pub fn resolve(&self, request: &PolishRequest) -> AppResult<PolishResult> {
        let raw = self
            .rt
            .block_on(self.provider.describe_crop(&request.image_data_uri))?;
        let (label, confidence) = openai_shape::parse_label_response(&raw);
        Ok(PolishResult {
            id: request.id,
            label,
            confidence,
        })
    }
}

/// Crop a box from the source frame, PNG-encode it, and build a `PolishRequest`
/// ready to send to the VLM. Pure/CPU-bound — runs on the detect thread.
#[allow(clippy::too_many_arguments)]
pub fn prepare_request(
    rgb: &[u8],
    w: u32,
    h: u32,
    x: u32,
    y: u32,
    bw: u32,
    bh: u32,
    id: Option<u64>,
) -> AppResult<PolishRequest> {
    let image_data_uri = crop_to_png_data_uri(rgb, w, h, x, y, bw, bh)?;
    Ok(PolishRequest { id, image_data_uri })
}

/// Crop an RGB frame to a box (padded ~15% for context) and encode it as a
/// base64 PNG data-URI. Returns an error on an empty/degenerate box.
fn crop_to_png_data_uri(
    rgb: &[u8],
    w: u32,
    h: u32,
    x: u32,
    y: u32,
    bw: u32,
    bh: u32,
) -> AppResult<String> {
    if w == 0 || h == 0 || bw == 0 || bh == 0 {
        return Err(AppError::Other("empty crop box".into()));
    }
    let pad_x = bw / 6;
    let pad_y = bh / 6;
    let x0 = x.saturating_sub(pad_x);
    let y0 = y.saturating_sub(pad_y);
    let x1 = (x + bw + pad_x).min(w);
    let y1 = (y + bh + pad_y).min(h);
    let cw = x1.saturating_sub(x0);
    let ch = y1.saturating_sub(y0);
    if cw == 0 || ch == 0 {
        return Err(AppError::Other("degenerate crop box".into()));
    }

    let mut img = image::RgbImage::new(cw, ch);
    for cy in 0..ch {
        for cx in 0..cw {
            let sx = x0 + cx;
            let sy = y0 + cy;
            let idx = ((sy * w + sx) * 3) as usize;
            if idx + 2 < rgb.len() {
                img.put_pixel(cx, cy, image::Rgb([rgb[idx], rgb[idx + 1], rgb[idx + 2]]));
            }
        }
    }

    let mut png: Vec<u8> = Vec::new();
    {
        let mut cursor = std::io::Cursor::new(&mut png);
        image::DynamicImage::ImageRgb8(img)
            .write_to(&mut cursor, image::ImageFormat::Png)
            .map_err(|e| AppError::Other(format!("png encode failed: {e}")))?;
    }
    let b64 = {
        use base64::Engine as _;
        base64::engine::general_purpose::STANDARD.encode(&png)
    };
    Ok(format!("data:image/png;base64,{b64}"))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn crops_and_encodes_a_png_data_uri() {
        // 4x4 RGB frame, all grey.
        let w = 4u32;
        let h = 4u32;
        let rgb: Vec<u8> = vec![128u8; (w * h * 3) as usize];
        let uri = crop_to_png_data_uri(&rgb, w, h, 1, 1, 2, 2).unwrap();
        assert!(uri.starts_with("data:image/png;base64,"));
        assert!(uri.len() > 30);
    }

    #[test]
    fn rejects_empty_box() {
        let rgb = vec![0u8; 12];
        assert!(crop_to_png_data_uri(&rgb, 4, 4, 0, 0, 0, 0).is_err());
    }

    #[test]
    fn clamps_crop_to_frame_bounds() {
        // Box extends past the frame edge; must still produce a valid URI.
        let w = 8u32;
        let h = 8u32;
        let rgb = vec![64u8; (w * h * 3) as usize];
        let uri = crop_to_png_data_uri(&rgb, w, h, 6, 6, 8, 8).unwrap();
        assert!(uri.starts_with("data:image/png;base64,"));
    }
}
