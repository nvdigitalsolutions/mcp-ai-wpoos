//! OCR engine dispatch for document pages (Proposal 109 M2).
//!
//! Mirrors the snippet controller's dispatch (platform-native first, ONNX
//! fallback) but takes an in-memory RGB page buffer instead of a captured
//! region. The dispatch is duplicated deliberately: refactoring Proposal
//! 100's private `recognize_region` for reuse risks a regression on the
//! shipped snippet path; this seam stays small and independently testable.

use std::path::Path;

use crate::config::Settings;
use crate::error::{AppError, AppResult};
use crate::snippet::SnippetText;

/// Recognize an RGB page with the configured snippet engine.
pub fn recognize_rgb(
    settings: &Settings,
    models_dir: &Path,
    rgb: &[u8],
    w: u32,
    h: u32,
) -> AppResult<SnippetText> {
    let engine = settings.snippet.engine.as_str();
    let want_auto = engine.is_empty() || engine == "auto";
    let mut last_err: Option<String> = None;
    #[cfg(not(feature = "snippet-onnx"))]
    let _ = models_dir;

    #[cfg(windows)]
    if engine == "winrt" || want_auto {
        let bgra = rgb_to_bgra(rgb, w, h);
        match crate::snippet::ocr_winrt::create_engine(&settings.snippet.language)
            .and_then(|e| crate::snippet::ocr_winrt::recognize_bgra(&e, &bgra, w, h))
        {
            Ok(r) => return Ok(r),
            Err(e) => {
                tracing::warn!("winrt OCR failed ({e}); trying fallback");
                last_err = Some(e.to_string());
            }
        }
    }

    #[cfg(all(target_os = "macos", feature = "snippet"))]
    if engine == "sidecar" || want_auto {
        let bgra = rgb_to_bgra(rgb, w, h);
        match crate::snippet::macos_sidecar::recognize_bgra(&bgra, w, h, &settings.snippet.language)
        {
            Ok(r) => return Ok(r),
            Err(e) => {
                tracing::warn!("macOS Vision OCR failed ({e}); trying fallback");
                last_err = Some(e.to_string());
            }
        }
    }

    #[cfg(feature = "snippet-onnx")]
    if engine == "onnx" || want_auto {
        match recognize_onnx(settings, models_dir, rgb, w, h) {
            Ok(r) => return Ok(r),
            Err(e) => {
                tracing::warn!("ONNX OCR failed ({e})");
                last_err = Some(e.to_string());
            }
        }
    }

    Err(AppError::NotConfigured(format!(
        "no OCR engine available{}",
        last_err.map(|e| format!(" — {e}")).unwrap_or_default()
    )))
}

/// Tightly-packed RGB -> BGRA (WinRT / sidecar engines consume BGRA).
pub fn rgb_to_bgra(rgb: &[u8], w: u32, h: u32) -> Vec<u8> {
    let n = (w * h) as usize;
    let mut bgra = vec![0u8; n * 4];
    for i in 0..n {
        bgra[i * 4] = rgb[i * 3 + 2];
        bgra[i * 4 + 1] = rgb[i * 3 + 1];
        bgra[i * 4 + 2] = rgb[i * 3];
        bgra[i * 4 + 3] = 255;
    }
    bgra
}

/// RapidOCR (PP-OCRv5) recognition — same load-per-shot discipline as the
/// snippet path (a resident session cache is deliberately not assumed).
#[cfg(feature = "snippet-onnx")]
fn recognize_onnx(
    settings: &Settings,
    models_dir: &Path,
    rgb: &[u8],
    w: u32,
    h: u32,
) -> AppResult<SnippetText> {
    use crate::snippet::ocr_onnx::{OcrDetector, OcrRecognizer};

    let pack_id = if settings.snippet.language.trim().is_empty() {
        "en"
    } else {
        settings.snippet.language.trim()
    };
    let pack = crate::snippet::models::find(pack_id)
        .ok_or_else(|| AppError::Model(format!("unknown OCR language pack '{pack_id}'")))?;
    if !crate::snippet::models::is_installed(pack, models_dir) {
        return Err(AppError::Model(format!(
            "OCR language pack '{pack_id}' is not downloaded — get it from Settings"
        )));
    }
    let dir = crate::snippet::models::pack_dir(models_dir);
    let det_path = dir.join(crate::snippet::models::DET_FILE.name);
    let rec_path = dir.join(pack.rec.name);
    let dict_path = dir.join(pack.dict.name);

    let detector = OcrDetector::load(&det_path)?;
    let recognizer = OcrRecognizer::load(&rec_path, &dict_path)?;
    crate::snippet::ocr_onnx::recognize_rgb(&detector, &recognizer, rgb, w, h)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn rgb_to_bgra_swaps_channels_and_fills_alpha() {
        // R=10, G=20, B=30, R=40, G=50, B=60
        let rgb = vec![10, 20, 30, 40, 50, 60];
        let bgra = rgb_to_bgra(&rgb, 2, 1);
        assert_eq!(bgra, vec![30, 20, 10, 255, 60, 50, 40, 255]);
    }
}
