//! PDFium access layer for the document pipeline (Proposal 109, D1/D2).
//!
//! Runtime-dynamic binding: the `pdfium.dll` / `libpdfium.dylib` is loaded
//! from beside the executable first (bundle-time provisioning, D2), then the
//! system search. All functions are synchronous; the controller owns the
//! `Pdfium` instance on its worker thread (Pdfium is not thread-safe — the
//! crate's `thread_safe` feature serializes access internally).
//!
//! API notes verified against pdfium-render 0.9.5 source (the README
//! documents 0.9.6 — they differ): `PdfPageTextChars::iter()` yields owned
//! `PdfPageTextChar`s that borrow the `PdfPageText`, so owned fields are
//! extracted inside the closure; `origin()` returns a `Result`; `PdfRect`
//! exposes `width()`/`height()` accessors.

use std::path::Path;

use pdfium_render::prelude::*;

use crate::document::ExtractedChar;
use crate::error::{AppError, AppResult};

/// Long-edge render clamp (plan D6 — bounds per-page memory).
pub const MAX_RENDER_EDGE: i32 = 5000;
/// OCR rasterization standard (proposal §3.2).
pub const DEFAULT_DPI: u32 = 300;
const POINTS_PER_INCH: f32 = 72.0;

/// Bind the pdfium library: exe-adjacent first, then the system search.
/// A missing library is a clean `AppError` — never a panic.
pub fn bind() -> AppResult<Pdfium> {
    if let Ok(exe) = std::env::current_exe() {
        if let Some(dir) = exe.parent() {
            if let Ok(bindings) =
                Pdfium::bind_to_library(Pdfium::pdfium_platform_library_name_at_path(dir))
            {
                return Ok(Pdfium::new(bindings));
            }
        }
    }
    let bindings = Pdfium::bind_to_system_library()
        .map_err(|e| AppError::Document(format!("pdfium library not found: {e}")))?;
    Ok(Pdfium::new(bindings))
}

/// Open a document. A password-protected file opened without a password
/// maps to a dedicated clean error (no silent processing — proposal §3.6).
pub fn open<'a>(pdfium: &'a Pdfium, path: &Path) -> AppResult<PdfDocument<'a>> {
    pdfium.load_pdf_from_file(path, None).map_err(|e| {
        let dbg = format!("{e:?}").to_lowercase();
        if dbg.contains("password") || dbg.contains("encrypt") {
            AppError::Document("document is password-protected".into())
        } else {
            AppError::Document(format!("could not open document: {e}"))
        }
    })
}

/// Extract the text layer of one page as normalized top-left-origin
/// characters (y-flip from PDF's bottom-left user space — proposal §3.4).
pub fn extract_chars(page: &PdfPage<'_>) -> AppResult<Vec<ExtractedChar>> {
    let page_h = page.height().value;
    let text = page
        .text()
        .map_err(|e| AppError::Document(format!("text extraction failed: {e}")))?;
    let chars = text
        .chars()
        .iter()
        .map(|c| {
            let (x, y) = c
                .origin()
                .unwrap_or((PdfPoints { value: 0.0 }, PdfPoints { value: 0.0 }));
            let bounds = c.loose_bounds().ok();
            let w = bounds.as_ref().map(|b| b.width().value).unwrap_or(0.0);
            let h = bounds.as_ref().map(|b| b.height().value).unwrap_or(0.0);
            let unmapped = c.unicode_char().is_none();
            let glyph = c
                .unicode_char()
                .map(|ch| ch.to_string())
                .unwrap_or_default();
            ExtractedChar {
                text: glyph,
                x: x.value,
                y: page_h - y.value - h, // bottom-left -> top-left origin
                w,
                h,
                unmapped,
            }
        })
        .collect();
    Ok(chars)
}

/// Render a page at the given DPI to RGB8, clamped to `MAX_RENDER_EDGE`
/// (aspect preserved).
pub fn render_page(page: &PdfPage<'_>, dpi: u32) -> AppResult<image::RgbImage> {
    let scale = dpi as f32 / POINTS_PER_INCH;
    let w = (page.width().value * scale) as i32;
    let h = (page.height().value * scale) as i32;
    let (w, h) = clamp_long_edge(w, h, MAX_RENDER_EDGE);
    let config = PdfRenderConfig::new()
        .set_target_width(w)
        .set_maximum_height(h);
    let img = page
        .render_with_config(&config)
        .map_err(|e| AppError::Document(format!("page render failed: {e}")))?
        .as_image()
        .map_err(|e| AppError::Document(format!("page image conversion failed: {e}")))?
        .into_rgb8();
    Ok(img)
}

/// Clamp (w, h) so the long edge is at most `max_edge`, preserving aspect.
pub fn clamp_long_edge(w: i32, h: i32, max_edge: i32) -> (i32, i32) {
    let long = w.max(h);
    if long <= max_edge {
        return (w, h);
    }
    let scale = max_edge as f32 / long as f32;
    ((w as f32 * scale) as i32, (h as f32 * scale) as i32)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn clamp_preserves_aspect_and_caps_long_edge() {
        let (w, h) = clamp_long_edge(2550, 3300, 5000);
        assert_eq!((w, h), (2550, 3300));
        let (w, h) = clamp_long_edge(5100, 6600, 5000);
        assert_eq!((w, h), (3863, 5000)); // 5000 * (5100/6600)
    }
}
