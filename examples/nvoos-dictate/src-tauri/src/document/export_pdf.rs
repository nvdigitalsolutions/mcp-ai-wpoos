//! Searchable-PDF export (Proposal 109 M4, D10): rebuild the source PDF
//! with each page as a raster image plus an invisible ISO-32000 text layer
//! (`3 Tr` render mode), so Ctrl+F/selection work. This is the honest v1:
//! pages are re-rasterized (the vector-preserving in-place relayer is
//! deferred), and text positions are sequential line slots (per-line boxes
//! are not retained across the pipeline yet). Feature `snippet-pdf`.

use std::path::Path;

use image::codecs::jpeg::JpegEncoder;

use crate::document::DocumentResult;
use crate::error::{AppError, AppResult};

/// Default text-layer line spacing (points) for placed invisible lines.
const LINE_HEIGHT_PT: f32 = 12.0;
/// JPEG quality for embedded page images.
const JPEG_QUALITY: u8 = 85;

/// Rebuild `source` as a searchable PDF at `dest`, using the text from
/// `result` (pages in document order).
pub fn export_searchable_pdf(
    source: &Path,
    result: &DocumentResult,
    dest: &Path,
    dpi: u32,
) -> AppResult<()> {
    if result.pages.len() != result.page_count {
        return Err(AppError::Document(format!(
            "page count mismatch ({} of {})",
            result.pages.len(),
            result.page_count
        )));
    }

    let pdfium = crate::document::pdf::bind()?;
    let document = crate::document::pdf::open(&pdfium, source)?;

    let mut doc = lopdf::Document::with_version("1.4");
    let pages_id = doc.new_object_id();
    let catalog_id = doc.new_object_id();
    let font_id = doc.new_object_id();

    let mut page_ids: Vec<lopdf::ObjectId> = Vec::new();
    for (index, page) in document.pages().iter().enumerate() {
        if index >= result.pages.len() {
            break;
        }
        let w_pt = page.width().value;
        let h_pt = page.height().value;

        // Render + JPEG-encode the page image.
        let rgb = crate::document::pdf::render_page(&page, dpi)?;
        let mut jpeg: Vec<u8> = Vec::new();
        {
            let mut cursor = std::io::Cursor::new(&mut jpeg);
            let mut encoder = JpegEncoder::new_with_quality(&mut cursor, JPEG_QUALITY);
            encoder
                .encode_image(&rgb)
                .map_err(|e| AppError::Document(format!("JPEG encode failed: {e}")))?;
        }
        // Image XObject built by hand (lopdf 0.36 dropped `add_image`; the
        // dict + DCTDecode stream is version-proof).
        let img_id = doc.new_object_id();
        let mut img_dict = lopdf::Dictionary::new();
        img_dict.set("Type", "XObject");
        img_dict.set("Subtype", "Image");
        img_dict.set("Width", rgb.width() as i64);
        img_dict.set("Height", rgb.height() as i64);
        img_dict.set("ColorSpace", "DeviceRGB");
        img_dict.set("BitsPerComponent", 8i64);
        img_dict.set("Filter", "DCTDecode");
        img_dict.set("Length", jpeg.len() as i64);
        doc.set_object(
            img_id,
            lopdf::Object::Stream(lopdf::Stream::new(img_dict, jpeg)),
        );

        // Content: page image + invisible text layer (every page carries
        // its text — extracted for `text` pages, recognized for the rest).
        let page_text = &result.pages[index].text;
        let mut content = format!("q\n{w_pt} 0 0 {h_pt} 0 0 cm\n/Im{index} Do\nQ\n");
        if !page_text.trim().is_empty() {
            content.push_str("BT\n/F1 12 Tf\n3 Tr\n");
            let mut y = h_pt - LINE_HEIGHT_PT;
            for line in page_text.lines() {
                let line = line.trim_end();
                if line.is_empty() {
                    y -= LINE_HEIGHT_PT;
                    continue;
                }
                content.push_str(&format!("1 0 0 1 36 {y} Tm\n({}) Tj\n", pdf_escape(line)));
                y -= LINE_HEIGHT_PT;
            }
            content.push_str("ET\n");
        }
        let content_id = doc.new_object_id();
        let mut content_dict = lopdf::Dictionary::new();
        content_dict.set("Length", content.len() as i64);
        doc.set_object(
            content_id,
            lopdf::Object::Stream(lopdf::Stream::new(content_dict, content.into_bytes())),
        );

        // Page object.
        let mut page_dict = lopdf::Dictionary::new();
        page_dict.set("Type", "Page");
        page_dict.set("Parent", pages_id);
        page_dict.set(
            "MediaBox",
            lopdf::Object::Array(vec![0.into(), 0.into(), w_pt.into(), h_pt.into()]),
        );
        let mut resources = lopdf::Dictionary::new();
        let mut fonts = lopdf::Dictionary::new();
        fonts.set("F1", font_id);
        resources.set("Font", fonts);
        let mut xobjects = lopdf::Dictionary::new();
        xobjects.set(format!("Im{index}"), img_id);
        resources.set("XObject", xobjects);
        page_dict.set("Resources", resources);
        page_dict.set("Contents", content_id);
        let page_id = doc.new_object_id();
        doc.set_object(page_id, lopdf::Object::Dictionary(page_dict));
        page_ids.push(page_id);
    }

    // Pages tree.
    let mut pages_dict = lopdf::Dictionary::new();
    pages_dict.set("Type", "Pages");
    pages_dict.set(
        "Kids",
        lopdf::Object::Array(
            page_ids
                .iter()
                .map(|id| lopdf::Object::Reference(*id))
                .collect(),
        ),
    );
    pages_dict.set("Count", page_ids.len() as i64);
    doc.set_object(pages_id, lopdf::Object::Dictionary(pages_dict));

    // Catalog.
    let mut catalog = lopdf::Dictionary::new();
    catalog.set("Type", "Catalog");
    catalog.set("Pages", pages_id);
    doc.set_object(catalog_id, lopdf::Object::Dictionary(catalog));
    doc.trailer.set("Root", catalog_id);

    // Font (Helvetica — the standard base-14 face search layers use).
    let mut font = lopdf::Dictionary::new();
    font.set("Type", "Font");
    font.set("Subtype", "Type1");
    font.set("BaseFont", "Helvetica");
    doc.set_object(font_id, lopdf::Object::Dictionary(font));

    doc.save(dest)
        .map_err(|e| AppError::Document(format!("PDF save failed: {e}")))?;
    Ok(())
}

/// Escape a line for a PDF string literal.
fn pdf_escape(s: &str) -> String {
    s.replace('\\', "\\\\")
        .replace('(', "\\(")
        .replace(')', "\\)")
}
