//! Document input pipeline (Proposal 109): PDF + multi-page TIFF OCR.
//!
//! The hybrid two-pass standard (OCRmyPDF's skip-text strategy): per page,
//! a usable PDF text layer is extracted losslessly (reading order via the
//! shared `snippet::layout` geometry), while image-only pages are
//! rasterized at the OCR-standard 300 DPI and recognized with the existing
//! on-device engines (WinRT / PP-OCRv5 ONNX / Apple Vision). Every page
//! carries its provenance (`text` | `ocr` | `polished`).
//!
//! Feature wiring mirrors `snippet`/`vision`: the module is always compiled
//! so the types are stable for `AppState` and IPC, but the pdfium/tiff/OCR
//! bodies live behind the `document` cargo feature. Without it `available()`
//! is false and the IPC surface reports "not compiled".
//!
//! Zero egress: documents are opened, rendered, and recognized entirely
//! on-device; the optional VLM polish defaults to local servers only.

pub mod controller;
pub mod hybrid;

#[cfg(feature = "document")]
pub mod export;
#[cfg(feature = "document")]
pub mod ocr;
#[cfg(feature = "document")]
pub mod pdf;
#[cfg(feature = "document")]
pub mod polish;
#[cfg(feature = "document")]
pub mod preprocess;
#[cfg(feature = "document")]
pub mod tiff;
#[cfg(feature = "snippet-pdf")]
pub mod export_pdf;

use serde::{Deserialize, Serialize};

/// Per-page provenance: where the page's text came from.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "lowercase")]
pub enum PageProvenance {
    /// Extracted from the document's own text layer (lossless, no OCR).
    Text,
    /// Recognized from a rasterized page (image-only PDF page / TIFF page).
    Ocr,
    /// OCR page whose text was corrected by the optional local VLM polish.
    Polished,
}

impl PageProvenance {
    /// Serde-stable id for history rows.
    pub fn id(&self) -> &'static str {
        match self {
            Self::Text => "text",
            Self::Ocr => "ocr",
            Self::Polished => "polished",
        }
    }
}

/// One page of a recognized document.
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct DocumentPage {
    /// 0-based page index in document order.
    pub index: usize,
    /// Final text (post-layout, post-cleanup, post-polish).
    pub text: String,
    pub provenance: PageProvenance,
}

/// The full result handed to the review surface.
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct DocumentResult {
    /// Source file path (as picked by the user).
    pub path: String,
    pub pages: Vec<DocumentPage>,
    pub page_count: usize,
    /// Wall-clock ms for the whole pipeline.
    pub duration_ms: i64,
}

impl DocumentResult {
    /// All pages joined with double newlines (page separators).
    pub fn combined_text(&self) -> String {
        self.pages
            .iter()
            .map(|p| p.text.trim_end().to_string())
            .collect::<Vec<_>>()
            .join("\n\n")
    }

    /// Counts per provenance, for diagnostics/history.
    pub fn provenance_counts(&self) -> (usize, usize, usize) {
        let mut t = 0;
        let mut o = 0;
        let mut p = 0;
        for page in &self.pages {
            match page.provenance {
                PageProvenance::Text => t += 1,
                PageProvenance::Ocr => o += 1,
                PageProvenance::Polished => p += 1,
            }
        }
        (t, o, p)
    }
}

/// A PDF text-layer character with its bounding box, normalized to
/// top-left-origin page-point space (y grows down).
#[derive(Debug, Clone, PartialEq, Serialize)]
pub struct ExtractedChar {
    pub text: String,
    pub x: f32,
    pub y: f32,
    pub w: f32,
    pub h: f32,
    /// The glyph id has no unicode mapping (ToUnicode gap) — a fidelity
    /// signal for the hybrid router (D5).
    pub unmapped: bool,
}

/// Whether the document pipeline is compiled into this build.
pub fn available() -> bool {
    cfg!(feature = "document")
}

/// Whether searchable-PDF export is compiled in (feature `snippet-pdf`).
pub fn pdf_export_available() -> bool {
    cfg!(feature = "snippet-pdf")
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn provenance_ids_are_serde_stable() {
        assert_eq!(PageProvenance::Text.id(), "text");
        assert_eq!(PageProvenance::Ocr.id(), "ocr");
        assert_eq!(PageProvenance::Polished.id(), "polished");
    }

    #[test]
    fn combined_text_joins_pages_with_blank_line() {
        let result = DocumentResult {
            path: "a.pdf".into(),
            pages: vec![
                DocumentPage {
                    index: 0,
                    text: "one".into(),
                    provenance: PageProvenance::Text,
                },
                DocumentPage {
                    index: 1,
                    text: "two".into(),
                    provenance: PageProvenance::Ocr,
                },
            ],
            page_count: 2,
            duration_ms: 10,
        };
        assert_eq!(result.combined_text(), "one\n\ntwo");
        assert_eq!(result.provenance_counts(), (1, 1, 0));
    }
}
