//! Single raster-image decode (Proposal 109, follow-up 1): PNG / JPEG /
//! WebP — anything the `image` crate's default format set decodes — into
//! the shared RGB8 frame shape the TIFF path produces. One file = one page;
//! the OCR core, progress events, and polish are identical to a TIFF page.

use std::path::Path;

use image::RgbImage;

use crate::error::{AppError, AppResult};

/// Hard cap on decoded pixel count. A3 at 300 DPI is ~17.4 MP; the 2x
/// scan-profile upscale doubles the working set, so keep headroom below
/// absurd scans that would OOM the machine.
const MAX_IMAGE_PIXELS: u64 = 40_000_000;

/// Decode a raster image into the single RGB8 "page" the pipeline expects.
///
/// Format is sniffed from content (`image::open`), so a renamed file with
/// a valid image payload still decodes — and garbage maps to a clean
/// `unsupported image` error, never a panic.
pub fn page(path: &Path) -> AppResult<RgbImage> {
    let img =
        image::open(path).map_err(|e| AppError::Document(format!("unsupported image: {e}")))?;
    let (w, h) = (img.width() as u64, img.height() as u64);
    if w * h > MAX_IMAGE_PIXELS {
        return Err(AppError::Document(format!(
            "image too large ({w}x{h} — {MAX_IMAGE_PIXELS} pixel cap)"
        )));
    }
    Ok(img.to_rgb8())
}
