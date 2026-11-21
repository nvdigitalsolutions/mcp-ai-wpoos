//! In-memory frame cropping shared by vision label polish (Proposal 076) and
//! deictic grounding (Proposal 153 M5).
//!
//! Pure CPU work on an RGB8 buffer. The result is a base64 PNG data-URI held in
//! memory for the duration of one multimodal request — it is never written to
//! disk by this module.

use crate::error::{AppError, AppResult};

/// Crop an RGB8 frame to a box, pad it by ~1/6 of its size for context,
/// optionally downscale so the long edge is at most `max_edge`, and encode it
/// as a base64 PNG data-URI.
///
/// `max_edge == None` keeps the crop at its native size (vision polish, which
/// sends one small box per detection). `Some(n)` caps the long edge (deictic
/// grounding, where the region can approach the whole frame).
#[allow(clippy::too_many_arguments)]
pub fn crop_to_data_uri(
    rgb: &[u8],
    w: u32,
    h: u32,
    x: u32,
    y: u32,
    bw: u32,
    bh: u32,
    max_edge: Option<u32>,
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
            let idx = (((y0 + cy) * w + (x0 + cx)) * 3) as usize;
            if idx + 2 < rgb.len() {
                img.put_pixel(cx, cy, image::Rgb([rgb[idx], rgb[idx + 1], rgb[idx + 2]]));
            }
        }
    }

    let img = match max_edge {
        Some(limit) if limit > 0 && cw.max(ch) > limit => {
            // Preserve the aspect ratio; `resize` fits inside the box.
            let scale = limit as f32 / cw.max(ch) as f32;
            let nw = ((cw as f32 * scale).round() as u32).max(1);
            let nh = ((ch as f32 * scale).round() as u32).max(1);
            image::DynamicImage::ImageRgb8(img).resize(
                nw,
                nh,
                image::imageops::FilterType::Triangle,
            )
        }
        _ => image::DynamicImage::ImageRgb8(img),
    };

    let mut png: Vec<u8> = Vec::new();
    {
        let mut cursor = std::io::Cursor::new(&mut png);
        img.write_to(&mut cursor, image::ImageFormat::Png)
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

    fn grey(w: u32, h: u32) -> Vec<u8> {
        vec![128u8; (w * h * 3) as usize]
    }

    #[test]
    fn crops_and_encodes_a_png_data_uri() {
        let uri = crop_to_data_uri(&grey(4, 4), 4, 4, 1, 1, 2, 2, None).unwrap();
        assert!(uri.starts_with("data:image/png;base64,"));
        assert!(uri.len() > 30);
    }

    #[test]
    fn rejects_empty_box() {
        assert!(crop_to_data_uri(&[0u8; 12], 4, 4, 0, 0, 0, 0, None).is_err());
    }

    #[test]
    fn clamps_crop_to_frame_bounds() {
        let uri = crop_to_data_uri(&grey(8, 8), 8, 8, 6, 6, 8, 8, None).unwrap();
        assert!(uri.starts_with("data:image/png;base64,"));
    }

    #[test]
    fn max_edge_shrinks_a_large_crop() {
        // A 200x100 crop capped at 40 must encode to a much smaller payload
        // than the uncapped version (a solid colour compresses well, so
        // compare the decoded pixel count instead of the string length).
        let big = grey(200, 100);
        let capped = crop_to_data_uri(&big, 200, 100, 0, 0, 200, 100, Some(40)).unwrap();
        let native = crop_to_data_uri(&big, 200, 100, 0, 0, 200, 100, None).unwrap();
        let decoded = |uri: &str| {
            use base64::Engine as _;
            let raw = uri.trim_start_matches("data:image/png;base64,");
            base64::engine::general_purpose::STANDARD
                .decode(raw)
                .unwrap()
        };
        let img = image::load_from_memory(&decoded(&capped)).unwrap();
        assert_eq!(img.width().max(img.height()), 40);
        let native_img = image::load_from_memory(&decoded(&native)).unwrap();
        assert_eq!(native_img.width().max(native_img.height()), 200);
    }
}
