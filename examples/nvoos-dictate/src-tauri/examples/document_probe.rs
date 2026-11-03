//! Document-input de-risk spike (Proposal 109, M0).
//!
//! Opens a PDF, reports the page count, extracts per-character text-layer
//! boxes on every page, and renders page 1 at the OCR-standard 300 DPI.
//! Proves the three load-bearing pdfium-render calls (open, text chars,
//! render) plus the D2 runtime-binary binding before any lib code lands.
//!
//! Run from `src-tauri/target/debug` so the probe binds the `pdfium.dll`
//! placed beside the exe: `./examples/document_probe.exe <file.pdf>`.

use std::path::Path;

use pdfium_render::prelude::*;

/// Long-edge clamp from the plan's D6 (bound per-page memory).
const MAX_RENDER_EDGE: i32 = 5000;
/// OCR rasterization standard (proposal §3.2).
const DPI: f32 = 300.0;
const POINTS_PER_INCH: f32 = 72.0;

fn main() -> Result<(), Box<dyn std::error::Error>> {
    let path = std::env::args()
        .nth(1)
        .expect("usage: document_probe <file.pdf>");

    // D2: dynamic binding — `Pdfium::default()` loads a library beside the
    // executable (or the current working directory), then the system
    // search. A missing library surfaces as a clean `PdfiumError`, never a
    // panic.
    let pdfium = Pdfium::default();

    // Encrypted docs: a `None` password on a protected file yields an
    // error here — the probe asserts that path is clean by not unwrapping.
    let document = pdfium.load_pdf_from_file(Path::new(&path), None)?;

    println!("file:     {path}");
    println!("pages:    {}", document.pages().len());

    for (index, page) in document.pages().iter().enumerate() {
        let width_pt = page.width().value;
        let height_pt = page.height().value;

        let segments = page.text().map(|t| t.all().len()).unwrap_or(0);
        // Owned snapshot: chars borrow from `PdfPageText`, so copy the
        // fields we need out inside the closure.
        let chars: Vec<(String, u32, f32, f32, f32, f32)> = page
            .text()
            .map(|t| {
                t.chars()
                    .iter()
                    .map(|c| {
                        let (x, y) = c
                            .origin()
                            .unwrap_or((PdfPoints { value: 0.0 }, PdfPoints { value: 0.0 }));
                        let bounds = c.loose_bounds().ok();
                        (
                            c.unicode_char()
                                .map(|ch| ch.to_string())
                                .unwrap_or_else(|| "?".to_string()),
                            c.unicode_value(),
                            x.value,
                            y.value,
                            bounds.map(|b| b.width().value).unwrap_or(0.0),
                            bounds.map(|b| b.height().value).unwrap_or(0.0),
                        )
                    })
                    .collect()
            })
            .unwrap_or_default();

        // D5's heuristic raw material: a glyph id that maps to no char is
        // the extraction-fidelity signal (ToUnicode gaps) — in 0.9.5 this
        // is `unicode_char() == None`; 0.9.6 adds `has_unicode_map_error()`.
        let unmapped = chars.iter().filter(|(g, ..)| g == "?").count();
        println!(
            "page {index}: {width_pt:.0}x{height_pt:.0}pt, segments={segments}, chars={}, unmapped={unmapped}",
            chars.len()
        );
        for (glyph, unicode, x, y, w, h) in chars.iter().take(6) {
            println!("  '{glyph}' u={unicode} x={x:.1} y={y:.1} w={w:.1} h={h:.1}");
        }

        // D6: 300-DPI render of the first page only (spike scope).
        if index == 0 {
            let target_w = ((width_pt * DPI / POINTS_PER_INCH) as i32).min(MAX_RENDER_EDGE);
            let target_h = ((height_pt * DPI / POINTS_PER_INCH) as i32).min(MAX_RENDER_EDGE);
            let config = PdfRenderConfig::new()
                .set_target_width(target_w)
                .set_maximum_height(target_h);
            let img = page.render_with_config(&config)?.as_image()?.into_rgb8();
            println!(
                "  rendered {}x{} (target {target_w}x{target_h} @ 300 DPI)",
                img.width(),
                img.height()
            );
        }
    }

    Ok(())
}
