//! Word-box retention for the document pipeline (Proposal 109 follow-up 2).
//!
//! Pure functions: scale engine boxes into a page's canonical raster space,
//! group them into lines, and reconcile finalized (cleaned/polished) text
//! back onto raw recognition boxes. Golden-testable without engines.

use crate::document::{DocumentLine, DocumentWord};
use crate::snippet::layout::{self, LayoutBox};
use crate::snippet::SnippetWord;

/// Convert raw engine words (region-pixel, u32) into the page's canonical
/// raster space (f32), scaling by `sx`/`sy` (preprocessed → source pixels).
pub fn from_snippet(words: &[SnippetWord], sx: f32, sy: f32) -> Vec<DocumentWord> {
    words
        .iter()
        .map(|w| DocumentWord {
            text: w.text.clone(),
            x: w.x as f32 * sx,
            y: w.y as f32 * sy,
            w: w.w as f32 * sx,
            h: w.h as f32 * sy,
        })
        .collect()
}

/// Scale word boxes between raster spaces (e.g. PDF points → render pixels).
pub fn scale_words(words: Vec<DocumentWord>, s: f32) -> Vec<DocumentWord> {
    words
        .into_iter()
        .map(|mut w| {
            w.x *= s;
            w.y *= s;
            w.w *= s;
            w.h *= s;
            w
        })
        .collect()
}

fn to_layout(words: &[DocumentWord]) -> Vec<LayoutBox> {
    words
        .iter()
        .map(|w| LayoutBox {
            text: w.text.clone(),
            x: w.x,
            y: w.y,
            w: w.w,
            h: w.h,
        })
        .collect()
}

/// Build lines from clustered box groups (one group = one line).
fn lines_from_groups(groups: Vec<Vec<LayoutBox>>) -> Vec<DocumentLine> {
    groups
        .into_iter()
        .filter(|g| !g.is_empty())
        .map(|group| {
            let text = group
                .iter()
                .map(|w| w.text.as_str())
                .collect::<Vec<_>>()
                .join(" ");
            let y = group.iter().map(|w| w.y).fold(f32::MAX, f32::min);
            let h = group.iter().map(|w| w.h).fold(1.0f32, f32::max);
            let x = group.iter().map(|w| w.x).fold(f32::MAX, f32::min);
            let x2 = group.iter().map(|w| w.x + w.w).fold(0.0f32, f32::max);
            DocumentLine {
                text,
                words: group
                    .into_iter()
                    .map(|b| DocumentWord {
                        text: b.text,
                        x: b.x,
                        y: b.y,
                        w: b.w,
                        h: b.h,
                    })
                    .collect(),
                x,
                y,
                w: x2 - x,
                h,
            }
        })
        .collect()
}

/// Group already-in-reading-order words into lines (engine order trusted —
/// the OCR path; no XY-cut, which could reorder the engine's columns).
pub fn lines_from_words_linear(words: &[DocumentWord]) -> Vec<DocumentLine> {
    lines_from_groups(layout::line_groups(&to_layout(words)))
}

/// Group words into lines with column awareness (the PDF text-layer path:
/// XY-cut on words first, then per-column line clustering — the 102 D7
/// rule that prevents cross-column line merging).
pub fn lines_from_words_column_aware(
    words: &[DocumentWord],
    page_w: f32,
    min_col_gap: f32,
) -> Vec<DocumentLine> {
    let boxes = to_layout(words);
    let mut out = Vec::new();
    for col in layout::xy_cut_words(&boxes, page_w, min_col_gap) {
        out.extend(lines_from_groups(layout::line_groups(&col)));
    }
    out
}

/// Normalize a token for matching: lowercase + trim surrounding punctuation.
fn norm(tok: &str) -> String {
    tok.trim_matches(|c: char| !c.is_alphanumeric())
        .to_lowercase()
}

/// Reconcile finalized (cleaned/polished) text onto raw recognition boxes.
///
/// Greedy in-order matching, case- and punctuation-insensitive: stripped
/// fillers are skipped, capitalized/punctuated words keep their boxes, and
/// the FINAL token text is what the line carries. Requires a **perfect**
/// token match (every finalized token must find its raw box) — anything
/// weaker means a model rewrite, and the caller falls back to line-level
/// geometry so exported boxes never lie.
pub fn align(final_text: &str, raw_words: &[DocumentWord]) -> Option<Vec<DocumentLine>> {
    let tokens: Vec<&str> = final_text.split_whitespace().collect();
    if tokens.is_empty() || raw_words.is_empty() {
        return None;
    }
    let mut pairs: Vec<DocumentWord> = Vec::new();
    let mut wi = 0usize;
    for tok in &tokens {
        while wi < raw_words.len() && norm(raw_words[wi].text.trim()) != norm(tok) {
            wi += 1;
        }
        if wi >= raw_words.len() {
            return None;
        }
        let raw = &raw_words[wi];
        pairs.push(DocumentWord {
            text: (*tok).to_string(),
            x: raw.x,
            y: raw.y,
            w: raw.w,
            h: raw.h,
        });
        wi += 1;
    }
    Some(lines_from_words_linear(&pairs))
}

/// Line-level fallback for pages whose text no longer aligns to any boxes
/// (polished rewrites): one synthetic full-width line per text line, empty
/// words. Keeps hOCR/ALTO valid at line granularity.
pub fn fallback_lines(text: &str, page_w: f32, page_h: f32) -> Vec<DocumentLine> {
    let lines: Vec<&str> = text.lines().filter(|l| !l.trim().is_empty()).collect();
    let n = lines.len().max(1);
    let margin = page_w * 0.05;
    let width = (page_w - margin * 2.0).max(1.0);
    let h = (page_h - margin * 2.0) / n as f32;
    lines
        .into_iter()
        .enumerate()
        .map(|(i, l)| DocumentLine {
            text: l.trim_end().to_string(),
            words: Vec::new(),
            x: margin,
            y: margin + i as f32 * h,
            w: width,
            h: h * 0.8,
        })
        .collect()
}

#[cfg(test)]
mod tests {
    use super::*;

    fn word(text: &str, x: f32, y: f32, w: f32, h: f32) -> DocumentWord {
        DocumentWord {
            text: text.to_string(),
            x,
            y,
            w,
            h,
        }
    }

    #[test]
    fn from_snippet_scales_region_pixels() {
        let sw = [SnippetWord {
            text: "A".into(),
            x: 20,
            y: 40,
            w: 30,
            h: 10,
        }];
        let words = from_snippet(&sw, 0.5, 0.5);
        assert_eq!(words[0].x, 10.0);
        assert_eq!(words[0].y, 20.0);
        assert_eq!(words[0].w, 15.0);
    }

    #[test]
    fn align_keeps_boxes_when_cleanup_strips_fillers() {
        let raw = vec![
            word("So", 10.0, 100.0, 20.0, 12.0),
            word("we", 35.0, 100.0, 18.0, 12.0),
            word("need", 58.0, 100.0, 30.0, 12.0),
            word("boxes", 95.0, 100.0, 40.0, 12.0),
        ];
        let lines = align("We need boxes", &raw).expect("perfect alignment");
        assert_eq!(lines.len(), 1);
        assert_eq!(lines[0].text, "We need boxes");
        assert_eq!(lines[0].words[0].x, 35.0); // "So" box skipped
        assert_eq!(lines[0].words[0].text, "We");
    }

    #[test]
    fn align_bails_on_rewritten_text() {
        let raw = vec![
            word("hello", 10.0, 100.0, 20.0, 12.0),
            word("world", 35.0, 100.0, 25.0, 12.0),
        ];
        assert!(align("completely different sentence", &raw).is_none());
    }

    #[test]
    fn linear_lines_group_by_baseline() {
        let words = vec![
            word("a", 10.0, 100.0, 10.0, 12.0),
            word("b", 25.0, 102.0, 10.0, 16.0),
            word("c", 10.0, 140.0, 10.0, 12.0),
        ];
        let lines = lines_from_words_linear(&words);
        assert_eq!(lines.len(), 2);
        assert_eq!(lines[0].text, "a b");
        assert_eq!(lines[1].text, "c");
    }

    #[test]
    fn fallback_lines_synthesize_full_width() {
        let lines = fallback_lines("one\ntwo", 1000.0, 400.0);
        assert_eq!(lines.len(), 2);
        assert!(lines[0].words.is_empty());
        assert!(lines[0].w > 899.0);
        assert!(lines[1].y > lines[0].y);
    }
}
