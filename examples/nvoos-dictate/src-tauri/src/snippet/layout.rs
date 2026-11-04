//! Reading-order reconstruction for OCR + extracted-text boxes (Proposal 102
//! D7, pulled forward by Proposal 109 M1 as its first consumer).
//!
//! Pure geometry over normalized boxes. Order of operations matters and is
//! the whole point: **columns are detected on words first** (recursive
//! XY-cut over x-extents), then each column's words cluster into lines
//! (vertical-overlap grouping, x-sorted), so a two-column page never merges
//! same-baseline words from different columns. No models, no I/O.

/// A normalized text box in top-left-origin space (y grows down).
#[derive(Debug, Clone, PartialEq)]
pub struct LayoutBox {
    pub text: String,
    pub x: f32,
    pub y: f32,
    pub w: f32,
    pub h: f32,
}

/// A reconstructed line: words joined with spaces, plus its vertical span
/// and horizontal extent.
#[derive(Debug, Clone, PartialEq)]
pub struct LayoutLine {
    pub text: String,
    pub words: Vec<String>,
    pub y: f32,
    pub height: f32,
    pub x: f32,
    pub width: f32,
}

/// Recursive XY-cut over **word** boxes: find the widest horizontal gap
/// between x-sorted word intervals; when it exceeds `min_gap`, partition at
/// the gap's center and recurse. Returns columns left-to-right.
pub fn xy_cut_words(boxes: &[LayoutBox], page_w: f32, min_gap: f32) -> Vec<Vec<LayoutBox>> {
    if boxes.len() < 2 || min_gap <= 0.0 || page_w <= 0.0 {
        return vec![boxes.to_vec()];
    }
    let mut intervals: Vec<(f32, f32, usize)> = boxes
        .iter()
        .enumerate()
        .map(|(i, b)| (b.x.max(0.0), (b.x + b.w).min(page_w), i))
        .collect();
    intervals.sort_by(|a, b| a.0.partial_cmp(&b.0).unwrap_or(std::cmp::Ordering::Equal));

    let mut best_gap: f32 = 0.0;
    let mut best_idx = 0usize;
    for i in 1..intervals.len() {
        let gap = intervals[i].0 - intervals[i - 1].1;
        if gap > best_gap {
            best_gap = gap;
            best_idx = i;
        }
    }
    if best_gap < min_gap {
        return vec![boxes.to_vec()];
    }
    let gap_x = (intervals[best_idx - 1].1 + intervals[best_idx].0) / 2.0;
    let left: Vec<LayoutBox> = boxes
        .iter()
        .filter(|b| b.x + b.w / 2.0 < gap_x)
        .cloned()
        .collect();
    let right: Vec<LayoutBox> = boxes
        .iter()
        .filter(|b| b.x + b.w / 2.0 >= gap_x)
        .cloned()
        .collect();
    if left.is_empty() || right.is_empty() {
        return vec![boxes.to_vec()];
    }
    let mut cols = xy_cut_words(&left, page_w, min_gap);
    cols.extend(xy_cut_words(&right, page_w, min_gap));
    cols
}

/// Cluster one column's words into raw line groups (boxes kept, x-sorted
/// within each group). Shared by `cluster_lines` — this variant preserves
/// geometry for downstream word-level emitters (Proposal 109 follow-up 2).
pub fn line_groups(boxes: &[LayoutBox]) -> Vec<Vec<LayoutBox>> {
    let mut sorted: Vec<&LayoutBox> = boxes.iter().collect();
    sorted.sort_by(|a, b| {
        a.y.partial_cmp(&b.y)
            .unwrap_or(std::cmp::Ordering::Equal)
            .then(a.x.partial_cmp(&b.x).unwrap_or(std::cmp::Ordering::Equal))
    });

    let mut groups: Vec<Vec<&LayoutBox>> = Vec::new();
    for b in sorted {
        let center = b.y + b.h.max(1.0) / 2.0;
        let mut placed = false;
        for group in groups.iter_mut().rev() {
            let span_y = group[0].y;
            let span_h = group.iter().map(|w| w.h).fold(1.0f32, f32::max);
            if center >= span_y && center <= span_y + span_h {
                group.push(b);
                placed = true;
                break;
            }
        }
        if !placed {
            groups.push(vec![b]);
        }
    }

    groups
        .into_iter()
        .map(|group| {
            let mut g: Vec<LayoutBox> = group.into_iter().cloned().collect();
            g.sort_by(|a, b| a.x.partial_cmp(&b.x).unwrap_or(std::cmp::Ordering::Equal));
            g
        })
        .collect()
}

/// Cluster one column's words into lines. A word joins a line when its
/// vertical center falls inside the line's span (handles mixed font sizes
/// on one baseline); words within a line are x-sorted so a taller glyph
/// slightly lower on the same baseline never reorders the line.
pub fn cluster_lines(boxes: &[LayoutBox]) -> Vec<LayoutLine> {
    line_groups(boxes)
        .into_iter()
        .map(|group| {
            let text = group
                .iter()
                .map(|w| w.text.as_str())
                .collect::<Vec<_>>()
                .join(" ");
            let y = group.iter().map(|w| w.y).fold(f32::MAX, f32::min);
            let height = group.iter().map(|w| w.h).fold(1.0f32, f32::max);
            let x = group.iter().map(|w| w.x).fold(f32::MAX, f32::min);
            let x2 = group.iter().map(|w| w.x + w.w).fold(0.0f32, f32::max);
            LayoutLine {
                text,
                words: group.iter().map(|w| w.text.clone()).collect(),
                y,
                height,
                x,
                width: x2 - x,
            }
        })
        .collect()
}

/// Split lines into paragraph blocks: a vertical gap larger than
/// `para_gap × line height` between consecutive lines starts a new paragraph.
pub fn paragraphs(lines: &[LayoutLine], para_gap: f32) -> Vec<Vec<LayoutLine>> {
    let mut blocks: Vec<Vec<LayoutLine>> = Vec::new();
    let mut current: Vec<LayoutLine> = Vec::new();
    let mut prev_bottom: Option<f32> = None;
    let mut prev_height: f32 = 1.0;
    for line in lines {
        if let Some(pb) = prev_bottom {
            let gap = line.y - pb;
            if gap > para_gap * prev_height && !current.is_empty() {
                blocks.push(std::mem::take(&mut current));
            }
        }
        prev_height = line.height.max(1.0);
        prev_bottom = Some(line.y + line.height);
        current.push(line.clone());
    }
    if !current.is_empty() {
        blocks.push(current);
    }
    blocks
}

/// The full pipeline: XY-cut columns on words → per-column line clustering
/// → column-major text. Columns are newline-joined.
pub fn reading_order(boxes: &[LayoutBox], page_w: f32, min_col_gap: f32) -> String {
    let cols = xy_cut_words(boxes, page_w, min_col_gap);
    let mut out = String::new();
    for (ci, col) in cols.iter().enumerate() {
        let lines = cluster_lines(col);
        for (li, line) in lines.iter().enumerate() {
            if li > 0 {
                out.push('\n');
            }
            out.push_str(&line.text);
        }
        if ci + 1 < cols.len() && !col.is_empty() {
            out.push('\n');
        }
    }
    out
}

#[cfg(test)]
mod tests {
    use super::*;

    fn box_at(text: &str, x: f32, y: f32, w: f32, h: f32) -> LayoutBox {
        LayoutBox {
            text: text.to_string(),
            x,
            y,
            w,
            h,
        }
    }

    #[test]
    fn clusters_words_into_lines_across_font_sizes() {
        let boxes = vec![
            box_at("The", 10.0, 100.0, 20.0, 12.0),
            box_at("quick", 35.0, 100.0, 30.0, 12.0),
            box_at("BROWN", 70.0, 102.0, 40.0, 20.0), // taller glyph, same baseline
            box_at("fox", 120.0, 100.0, 20.0, 12.0),
            box_at("next", 10.0, 140.0, 22.0, 12.0),
            box_at("line", 40.0, 140.0, 22.0, 12.0),
        ];
        let lines = cluster_lines(&boxes);
        assert_eq!(lines.len(), 2);
        assert_eq!(lines[0].text, "The quick BROWN fox");
        assert_eq!(lines[0].height, 20.0);
        assert_eq!(lines[1].text, "next line");
    }

    #[test]
    fn reading_order_is_column_major() {
        let mut boxes = Vec::new();
        for (i, t) in ["left one", "left two", "left three"].iter().enumerate() {
            boxes.push(box_at(t, 100.0, 100.0 + i as f32 * 30.0, 80.0, 12.0));
        }
        for (i, t) in ["right one", "right two", "right three"].iter().enumerate() {
            boxes.push(box_at(t, 500.0, 100.0 + i as f32 * 30.0, 90.0, 12.0));
        }
        let text = reading_order(&boxes, 800.0, 50.0);
        assert_eq!(
            text,
            "left one\nleft two\nleft three\nright one\nright two\nright three"
        );
    }

    #[test]
    fn xy_cut_does_not_split_single_column() {
        let boxes = vec![
            box_at("line one", 100.0, 100.0, 80.0, 12.0),
            box_at("line two", 110.0, 130.0, 70.0, 12.0),
        ];
        let cols = xy_cut_words(&boxes, 800.0, 50.0);
        assert_eq!(cols.len(), 1);
    }

    #[test]
    fn paragraphs_split_on_large_vertical_gaps() {
        let boxes = vec![
            box_at("a", 10.0, 100.0, 10.0, 12.0),
            box_at("b", 10.0, 116.0, 10.0, 12.0),
            box_at("c", 10.0, 200.0, 10.0, 12.0),
            box_at("d", 10.0, 216.0, 10.0, 12.0),
        ];
        let blocks = paragraphs(&cluster_lines(&boxes), 2.0);
        assert_eq!(blocks.len(), 2);
        assert_eq!(blocks[0][0].text, "a");
        assert_eq!(blocks[1][0].text, "c");
    }
}
