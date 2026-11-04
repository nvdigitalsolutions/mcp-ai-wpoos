//! Export emitters for document results (Proposal 109 M4): plain text,
//! Markdown, hOCR and ALTO — pure string emitters over the page/line
//! structure, no dependencies. The searchable-PDF emitter lives in
//! `export_pdf.rs` behind the `snippet-pdf` feature.

use crate::document::DocumentResult;
use crate::error::{AppError, AppResult};

/// Plain text: pages joined with a blank line.
pub fn to_txt(result: &DocumentResult) -> String {
    format!("{}\n", result.combined_text())
}

/// Markdown: a `## Page N` heading per page.
pub fn to_markdown(result: &DocumentResult) -> String {
    let mut out = String::new();
    for (i, page) in result.pages.iter().enumerate() {
        if i > 0 {
            out.push('\n');
        }
        out.push_str(&format!("## Page {}\n\n{}\n", i + 1, page.text.trim_end()));
    }
    out
}

/// Escape XML text content (hOCR/ALTO are XML).
fn xml_escape(s: &str) -> String {
    s.replace('&', "&amp;")
        .replace('<', "&lt;")
        .replace('>', "&gt;")
        .replace('"', "&quot;")
        .replace('\'', "&apos;")
}

/// hOCR: XHTML with per-page `ocr_page` elements and per-line `ocr_line`
/// children. v1 emits line text without word bounding boxes (box retention
/// across the pipeline is a later upgrade) — still a valid hOCR document.
pub fn to_hocr(result: &DocumentResult) -> String {
    let mut out = String::from(
        "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n\
         <!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.0 Transitional//EN\" \
         \"http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd\">\n\
         <html xmlns=\"http://www.w3.org/1999/xhtml\">\n<head>\n\
         <meta content=\"nvoos-dictate document OCR\" name=\"ocr-system\"/>\n\
         </head>\n<body>\n",
    );
    for (i, page) in result.pages.iter().enumerate() {
        out.push_str(&format!(
            "<div class=\"ocr_page\" id=\"page_{}\" title=\"page {}\">\n",
            i + 1,
            i + 1
        ));
        for (li, line) in page.text.lines().enumerate() {
            if line.trim().is_empty() {
                continue;
            }
            out.push_str(&format!(
                "<span class=\"ocr_line\" id=\"page_{}_line_{}\">{}</span>\n",
                i + 1,
                li + 1,
                xml_escape(line)
            ));
        }
        out.push_str("</div>\n");
    }
    out.push_str("</body>\n</html>\n");
    out
}

/// ALTO XML: the library-standard archival layout description, same v1
/// line-only granularity as hOCR.
pub fn to_alto(result: &DocumentResult) -> String {
    let mut out = String::from(
        "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n\
         <alto xmlns=\"http://www.loc.gov/standards/alto/ns-v4#\">\n\
         <Description><MeasurementUnit>pixel</MeasurementUnit></Description>\n\
         <Layout>\n",
    );
    for (i, page) in result.pages.iter().enumerate() {
        out.push_str(&format!(
            "<Page ID=\"page{}\" PHYSICAL_IMG_NR=\"{}\">\n<PrintSpace>\n",
            i + 1,
            i + 1
        ));
        for (li, line) in page.text.lines().enumerate() {
            if line.trim().is_empty() {
                continue;
            }
            out.push_str(&format!(
                "<TextLine ID=\"page{}_line{}\"><String CONTENT=\"{}\"/></TextLine>\n",
                i + 1,
                li + 1,
                xml_escape(line)
            ));
        }
        out.push_str("</PrintSpace>\n</Page>\n");
    }
    out.push_str("</Layout>\n</alto>\n");
    out
}

/// Dispatch by format id: `txt` | `md` | `hocr` | `alto`.
pub fn emit(format: &str, result: &DocumentResult) -> AppResult<String> {
    match format {
        "txt" => Ok(to_txt(result)),
        "md" => Ok(to_markdown(result)),
        "hocr" => Ok(to_hocr(result)),
        "alto" => Ok(to_alto(result)),
        other => Err(AppError::Document(format!(
            "unknown export format '{other}'"
        ))),
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::document::{DocumentPage, PageProvenance};

    fn sample() -> DocumentResult {
        DocumentResult {
            path: "a.pdf".into(),
            pages: vec![
                DocumentPage {
                    index: 0,
                    text: "hello <world> & friends".into(),
                    provenance: PageProvenance::Text,
                },
                DocumentPage {
                    index: 1,
                    text: "second page".into(),
                    provenance: PageProvenance::Ocr,
                },
            ],
            page_count: 2,
            duration_ms: 1,
        }
    }

    #[test]
    fn txt_joins_pages() {
        assert_eq!(
            to_txt(&sample()),
            "hello <world> & friends\n\nsecond page\n"
        );
    }

    #[test]
    fn markdown_numbers_pages() {
        let md = to_markdown(&sample());
        assert!(md.starts_with("## Page 1\n\nhello <world> & friends\n"));
        assert!(md.contains("## Page 2\n\nsecond page"));
    }

    #[test]
    fn hocr_and_alto_escape_text() {
        let hocr = to_hocr(&sample());
        assert!(hocr.contains("hello &lt;world&gt; &amp; friends"));
        assert!(hocr.contains("class=\"ocr_page\""));
        let alto = to_alto(&sample());
        assert!(alto.contains("hello &lt;world&gt; &amp; friends"));
        assert!(alto.contains("<Page ID=\"page2\""));
    }

    #[test]
    fn unknown_format_errors() {
        assert!(emit("pdf", &sample()).is_err());
        assert!(emit("txt", &sample()).is_ok());
    }
}
