//! Multi-page TIFF decode (Proposal 109, D7).
//!
//! The `image` crate (and its `image-tiff` backend) decodes only the FIRST
//! page of a multi-page TIFF — an acknowledged ecosystem gap. The lower-
//! level `tiff` crate exposes page iteration (`more_images()`); each page is
//! converted to RGB8 for the shared OCR core. Page order follows the IFD
//! chain, which is the scan order users expect.

use std::path::Path;

use image::RgbImage;

use crate::error::{AppError, AppResult};

/// Decode up to `max_pages` pages of a multi-page TIFF into RGB8 frames.
pub fn pages(path: &Path, max_pages: usize) -> AppResult<Vec<RgbImage>> {
    let file = std::fs::File::open(path)?;
    let mut decoder = tiff::decoder::Decoder::new(std::io::BufReader::new(file))
        .map_err(|e| AppError::Document(format!("unsupported TIFF: {e}")))?;

    let mut out: Vec<RgbImage> = Vec::new();
    loop {
        let (w, h) = decoder
            .dimensions()
            .map_err(|e| AppError::Document(format!("TIFF page header: {e}")))?;
        let color_type = decoder
            .colortype()
            .map_err(|e| AppError::Document(format!("TIFF color type: {e}")))?;
        let image = decoder
            .read_image()
            .map_err(|e| AppError::Document(format!("TIFF page decode: {e}")))?;
        out.push(decoding_to_rgb(image, color_type, w, h)?);
        if out.len() >= max_pages {
            break;
        }
        if !decoder.more_images() {
            break;
        }
    }
    Ok(out)
}

/// Convert one decoded page to RGB8 (pragmatic color-type matrix; exotic
/// types degrade to a clean error, never a panic).
fn decoding_to_rgb(
    image: tiff::decoder::DecodingResult,
    color_type: tiff::ColorType,
    w: u32,
    h: u32,
) -> AppResult<RgbImage> {
    use tiff::decoder::DecodingResult as DR;
    use tiff::ColorType as CT;

    let dyn_img = match (color_type, image) {
        (CT::Gray(8), DR::U8(v)) => image::DynamicImage::ImageLuma8(
            image::GrayImage::from_raw(w, h, v)
                .ok_or_else(|| AppError::Document("TIFF gray buffer size mismatch".into()))?,
        ),
        (CT::GrayA(8), DR::U8(v)) => image::DynamicImage::ImageLumaA8(
            image::GrayAlphaImage::from_raw(w, h, v)
                .ok_or_else(|| AppError::Document("TIFF gray-alpha buffer size mismatch".into()))?,
        ),
        (CT::RGB(8), DR::U8(v)) => image::DynamicImage::ImageRgb8(
            image::RgbImage::from_raw(w, h, v)
                .ok_or_else(|| AppError::Document("TIFF RGB buffer size mismatch".into()))?,
        ),
        (CT::RGBA(8), DR::U8(v)) => image::DynamicImage::ImageRgba8(
            image::RgbaImage::from_raw(w, h, v)
                .ok_or_else(|| AppError::Document("TIFF RGBA buffer size mismatch".into()))?,
        ),
        (CT::Gray(16), DR::U16(v)) => image::DynamicImage::ImageLuma16(
            image::ImageBuffer::from_raw(w, h, v)
                .ok_or_else(|| AppError::Document("TIFF gray16 buffer size mismatch".into()))?,
        ),
        (CT::RGB(16), DR::U16(v)) => image::DynamicImage::ImageRgb16(
            image::ImageBuffer::from_raw(w, h, v)
                .ok_or_else(|| AppError::Document("TIFF rgb16 buffer size mismatch".into()))?,
        ),
        // CMYK TIFFs are deliberately unsupported (rare for scan/document
        // use; the conversion matrix above covers the real-world cases).
        _ => {
            return Err(AppError::Document(format!(
                "unsupported TIFF color layout {color_type:?}"
            )));
        }
    };
    Ok(dyn_img.to_rgb8())
}
