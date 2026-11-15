//! Hybrid per-page router (Proposal 109, D5): decide whether a page's text
//! comes from the document's own text layer (`Text`) or from OCR of a
//! rasterized page (`Ocr`). The OCRmyPDF skip-text strategy: never OCR a
//! page that has usable text — extraction is lossless, OCR is not.
//!
//! Pure functions over extracted metrics — golden-testable with no pdfium
//! or engine involvement.

#[cfg(feature = "document")]
use crate::document::words;
use crate::document::ExtractedChar;
#[cfg(feature = "document")]
use crate::document::{DocumentLine, DocumentWord};
use crate::snippet::layout::{self, LayoutBox};

/// A page with fewer extracted chars than this is treated as image-only.
pub const TEXT_PAGE_MIN_CHARS: usize = 50;
/// Pages whose unmapped-glyph ratio (ToUnicode gaps) exceeds this are
/// demoted to OCR — extraction would return garbage (proposal §3.4).
pub const MAX_UNMAPPED_RATIO: f32 = 0.05;
/// Max intra-word gap as a fraction of glyph height (char → word clustering).
const WORD_GAP_RATIO: f32 = 0.35;

/// Where a page's text should come from.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum PageClass {
    /// The text layer is usable — extract, don't OCR.
    Text,
    /// Image-only / unreliable layer — rasterize + OCR.
    Ocr,
}

/// Classify a page from its extracted chars (D5 thresholds).
pub fn classify(chars: &[ExtractedChar]) -> PageClass {
    classify_with(chars, TEXT_PAGE_MIN_CHARS)
}

/// Classify with a settings-provided minimum char count.
pub fn classify_with(chars: &[ExtractedChar], min_chars: usize) -> PageClass {
    if chars.len() < min_chars {
        return PageClass::Ocr;
    }
    let unmapped = chars.iter().filter(|c| c.unmapped).count();
    if (unmapped as f32 / chars.len() as f32) > MAX_UNMAPPED_RATIO {
        return PageClass::Ocr;
    }
    PageClass::Text
}

/// Reconstruct words from character boxes (PDFs store glyphs, not words):
/// consecutive chars on one line with a gap under ~0.35 glyph-heights join,
/// and whitespace glyphs act as explicit word separators (dropping them
/// would merge "The quick" into "Thequick").
pub fn chars_to_words(chars: &[ExtractedChar]) -> Vec<LayoutBox> {
    let mut sorted: Vec<&ExtractedChar> = chars.iter().collect();
    sorted.sort_by(|a, b| {
        a.y.partial_cmp(&b.y)
            .unwrap_or(std::cmp::Ordering::Equal)
            .then(a.x.partial_cmp(&b.x).unwrap_or(std::cmp::Ordering::Equal))
    });

    let mut words: Vec<LayoutBox> = Vec::new();
    let mut after_separator = true;
    for c in sorted {
        if c.text.trim().is_empty() {
            after_separator = true;
            continue;
        }
        if after_separator {
            words.push(LayoutBox {
                text: c.text.clone(),
                x: c.x,
                y: c.y,
                w: c.w.max(1.0),
                h: c.h.max(1.0),
            });
            after_separator = false;
            continue;
        }
        if let Some(last) = words.last_mut() {
            let center = c.y + c.h / 2.0;
            let same_line = center >= last.y && center <= last.y + last.h;
            let gap = c.x - (last.x + last.w);
            if same_line && gap < c.h.max(1.0) * WORD_GAP_RATIO {
                last.text.push_str(&c.text);
                last.w = (c.x + c.w) - last.x;
                last.h = last.h.max(c.h);
                continue;
            }
        }
        words.push(LayoutBox {
            text: c.text.clone(),
            x: c.x,
            y: c.y,
            w: c.w.max(1.0),
            h: c.h.max(1.0),
        });
    }
    words
}

/// Extract a page's text layer into reading order (word reconstruction →
/// shared layout pipeline → XY-cut columns).
pub fn chars_to_text(chars: &[ExtractedChar], page_w: f32) -> String {
    let words = chars_to_words(chars);
    if words.is_empty() {
        return String::new();
    }
    // Column-gap threshold: ~2.5x the median word width (102's D7 spec).
    let mut widths: Vec<f32> = words.iter().map(|w| w.w).collect();
    widths.sort_by(|a, b| a.partial_cmp(b).unwrap_or(std::cmp::Ordering::Equal));
    let median = widths[widths.len() / 2].max(1.0);
    layout::reading_order(&words, page_w, median * 2.5)
}

/// Extract a page's text layer into boxed lines in the page's canonical
/// raster space (300-DPI render pixels — PDF points are scaled by
/// `dpi / 72`). Reading order matches `chars_to_text` (the same XY-cut +
/// line-clustering geometry), now retaining word boxes.
#[cfg(feature = "document")]
pub fn chars_to_lines(chars: &[ExtractedChar], page_w: f32, dpi: f32) -> Vec<DocumentLine> {
    let words = chars_to_words(chars);
    if words.is_empty() {
        return Vec::new();
    }
    let scale = (dpi / 72.0).max(1.0);
    let mut widths: Vec<f32> = words.iter().map(|w| w.w).collect();
    widths.sort_by(|a, b| a.partial_cmp(b).unwrap_or(std::cmp::Ordering::Equal));
    let median = widths[widths.len() / 2].max(1.0);
    let words = words::scale_words(
        words
            .into_iter()
            .map(|b| DocumentWord {
                text: b.text,
                x: b.x,
                y: b.y,
                w: b.w,
                h: b.h,
            })
            .collect(),
        scale,
    );
    words::lines_from_words_column_aware(&words, page_w * scale, median * 2.5 * scale)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn ch(text: &str, x: f32, y: f32, w: f32, h: f32) -> ExtractedChar {
        ExtractedChar {
            text: text.to_string(),
            x,
            y,
            w,
            h,
            unmapped: false,
        }
    }

    #[test]
    fn classify_routes_small_and_unmapped_pages_to_ocr() {
        // Too few chars.
        let few: Vec<ExtractedChar> = (0..10)
            .map(|i| ch("a", i as f32 * 10.0, 100.0, 8.0, 12.0))
            .collect();
        assert_eq!(classify(&few), PageClass::Ocr);

        // Plenty of chars but a broken ToUnicode map.
        let mut bad: Vec<ExtractedChar> = (0..100)
            .map(|i| {
                ch(
                    "a",
                    (i % 20) as f32 * 10.0,
                    100.0 + (i / 20) as f32 * 15.0,
                    8.0,
                    12.0,
                )
            })
            .collect();
        for c in bad.iter_mut().take(20) {
            c.unmapped = true;
        }
        assert_eq!(classify(&bad), PageClass::Ocr);
    }

    #[test]
    fn classify_routes_healthy_pages_to_text() {
        let good: Vec<ExtractedChar> = (0..80)
            .map(|i| {
                ch(
                    "a",
                    (i % 20) as f32 * 10.0,
                    100.0 + (i / 20) as f32 * 15.0,
                    8.0,
                    12.0,
                )
            })
            .collect();
        assert_eq!(classify(&good), PageClass::Text);
    }

    #[test]
    fn chars_reconstruct_into_words() {
        // "The" + space + "quick" on one line; "fox" below.
        let chars = vec![
            ch("T", 10.0, 100.0, 8.0, 12.0),
            ch("h", 18.0, 100.0, 8.0, 12.0),
            ch("e", 26.0, 100.0, 8.0, 12.0),
            ch(" ", 34.0, 100.0, 6.0, 12.0),
            ch("q", 50.0, 100.0, 8.0, 12.0),
            ch("u", 58.0, 100.0, 8.0, 12.0),
            ch("f", 10.0, 130.0, 8.0, 12.0),
        ];
        let words = chars_to_words(&chars);
        assert_eq!(words.len(), 3);
        assert_eq!(words[0].text, "The");
        assert_eq!(words[1].text, "qu"); // space ends the previous word
        assert_eq!(words[2].text, "f");
    }

    #[test]
    #[cfg(feature = "document")]
    fn chars_to_lines_scales_points_to_render_pixels() {
        let chars = vec![
            ch("a", 72.0, 100.0, 8.0, 12.0),
            ch("b", 80.0, 100.0, 8.0, 12.0),
        ];
        let lines = chars_to_lines(&chars, 612.0, 300.0);
        assert_eq!(lines.len(), 1);
        // 72pt at 300 DPI = 300px.
        assert!((lines[0].words[0].x - 300.0).abs() < 0.5);
    }

    #[test]
    fn text_extraction_reads_line_order() {
        let mut chars = Vec::new();
        for (li, word) in ["hello", "world"].iter().enumerate() {
            for (ci, g) in word.chars().enumerate() {
                chars.push(ch(
                    &g.to_string(),
                    100.0 + ci as f32 * 10.0,
                    200.0 + li as f32 * 15.0,
                    8.0,
                    12.0,
                ));
            }
        }
        assert_eq!(chars_to_text(&chars, 800.0), "hello\nworld");
    }
}
