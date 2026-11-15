//! macOS snippet capture + OCR via the bundled `nvoos-audio-helper` Swift
//! sidecar (Proposal 100, M3).
//!
//! The helper (ScreenCaptureKit + Apple Vision) already ships for meetings
//! system audio and holds the Screen Recording TCC grant this feature
//! inherits. Snippet adds two **one-shot** subcommands speaking the existing
//! NDJSON stdout protocol — the long-lived `capture` stream is untouched:
//!
//! ```text
//! nvoos-audio-helper screenshot
//!   → {"type":"hello",...} {"type":"image","png":"<b64>","width":..,"height":..} {"type":"stopped"}
//! nvoos-audio-helper ocr --png <b64> --langs en,de
//!   → {"type":"hello",...} {"type":"ocr_result","text":"…","words":[…]} {"type":"stopped"}
//! ```
//!
//! v1 captures the main display (multi-display stitching is deferred; the
//! selection overlay maps coordinates against the returned frame, so the
//! interaction stays correct on the captured display).

use std::path::PathBuf;
use std::process::Command;

use crate::error::{AppError, AppResult};
use crate::meetings::sidecar_protocol::{parse_line, SidecarFrame};
use crate::snippet::capture::FrozenFrame;
use crate::snippet::{SnippetText, SnippetWord};

/// Resolve the helper (same lookup order as the meetings audio path).
fn helper_path() -> Option<PathBuf> {
    crate::meetings::macos_system_audio::helper_path()
}

/// Run a one-shot helper subcommand and return its stdout lines.
fn run_shot(args: &[&str]) -> AppResult<String> {
    let path =
        helper_path().ok_or_else(|| AppError::NotConfigured("mac-helper not found".into()))?;
    let output = Command::new(&path)
        .args(args)
        .stdin(std::process::Stdio::null())
        .stderr(std::process::Stdio::null())
        .output()
        .map_err(|e| AppError::Other(format!("snippet helper failed to start: {e}")))?;
    if !output.status.success() {
        return Err(AppError::Other(format!(
            "snippet helper exited with {}",
            output.status
        )));
    }
    Ok(String::from_utf8_lossy(&output.stdout).into_owned())
}

/// Freeze the main display via the sidecar and decode the returned PNG into
/// an in-memory `FrozenFrame` (origin (0,0) — main display).
pub fn freeze() -> AppResult<FrozenFrame> {
    use base64::Engine as _;
    let stdout = run_shot(&["screenshot"])?;
    for line in stdout.lines() {
        match parse_line(line) {
            Some(SidecarFrame::Image { png, width, height }) => {
                let bytes = base64::engine::general_purpose::STANDARD
                    .decode(&png)
                    .map_err(|e| AppError::Other(format!("screenshot decode failed: {e}")))?;
                let img = image::load_from_memory(&bytes)
                    .map_err(|e| AppError::Other(format!("screenshot image decode failed: {e}")))?;
                let rgba = img.to_rgba8();
                let (w, h) = rgba.dimensions();
                let mut bgra = Vec::with_capacity((w * h * 4) as usize);
                for px in rgba.pixels() {
                    let a = px[3] as f32 / 255.0;
                    bgra.push(((px[2] as f32 * a + 255.0 * (1.0 - a)).round()) as u8);
                    bgra.push(((px[1] as f32 * a + 255.0 * (1.0 - a)).round()) as u8);
                    bgra.push(((px[0] as f32 * a + 255.0 * (1.0 - a)).round()) as u8);
                    bgra.push(255);
                }
                return Ok(FrozenFrame {
                    bgra,
                    width: if width > 0 { width } else { w },
                    height: if height > 0 { height } else { h },
                    origin: (0, 0),
                });
            }
            Some(SidecarFrame::Error { message }) => {
                return Err(AppError::Other(format!("screenshot failed: {message}")))
            }
            _ => {}
        }
    }
    Err(AppError::Other(
        "screenshot helper returned no image (Screen Recording permission needed?)".into(),
    ))
}

/// Recognize BGRA pixels through Apple Vision (VNRecognizeTextRequest,
/// `.accurate`, language correction on). `langs` is a comma-separated list
/// of BCP-47 tags.
pub fn recognize_bgra(bgra: &[u8], w: u32, h: u32, langs: &str) -> AppResult<SnippetText> {
    use base64::Engine as _;
    use std::io::Cursor;

    let mut rgba = Vec::with_capacity((w * h * 4) as usize);
    for px in bgra.chunks_exact(4) {
        rgba.push(px[2]);
        rgba.push(px[1]);
        rgba.push(px[0]);
        rgba.push(255);
    }
    let img = image::RgbaImage::from_raw(w, h, rgba)
        .ok_or_else(|| AppError::Other("OCR image: bad dims".into()))?;
    let mut png = Vec::new();
    img.write_to(&mut Cursor::new(&mut png), image::ImageFormat::Png)
        .map_err(|e| AppError::Other(format!("OCR image encode failed: {e}")))?;
    let png_b64 = base64::engine::general_purpose::STANDARD.encode(png);

    let langs_arg = if langs.trim().is_empty() {
        "en".to_string()
    } else {
        langs.trim().to_string()
    };
    let stdout = run_shot(&["ocr", "--png", &png_b64, "--langs", &langs_arg])?;
    for line in stdout.lines() {
        match parse_line(line) {
            Some(SidecarFrame::OcrResult { text, words }) => {
                let words = words
                    .into_iter()
                    .map(|w| SnippetWord {
                        text: w.string,
                        x: w.x.max(0.0) as u32,
                        y: w.y.max(0.0) as u32,
                        w: w.w.max(0.0) as u32,
                        h: w.h.max(0.0) as u32,
                    })
                    .collect();
                return Ok(SnippetText {
                    text,
                    words,
                    engine: "sidecar".into(),
                    confidence: None,
                });
            }
            Some(SidecarFrame::Error { message }) => {
                return Err(AppError::Other(format!("OCR failed: {message}")))
            }
            _ => {}
        }
    }
    Err(AppError::Other("OCR helper returned no result".into()))
}
