//! Opt-in local VLM OCR polish (Proposal 109 §3.8).
//!
//! After the deterministic OCR cleanup, an optional Stage-2 correction pass
//! sends the page crop + OCR text to a **local** multimodal model through
//! the Proposal 076 transport (`LocalOpenAiProvider::correct_ocr`). Failure
//! always falls back to the deterministic text and can never block a result
//! (the dictation Stage-2 contract). Local providers only by default —
//! zero egress unless the user configures something else explicitly.

use image::imageops::FilterType;
use tauri::Emitter;

use crate::cleanup::providers::local_openai::{LocalKind, LocalOpenAiProvider};
use crate::config::Settings;
use crate::error::{AppError, AppResult};
use crate::state::{SecretStore, KEY_LM_STUDIO_TOKEN, KEY_OLLAMA_TOKEN};

/// Long edge a page crop is downscaled to before the VLM round-trip
/// (matches the vision-polish budget — enough for correction, cheap enough
/// for a local 7B model).
const POLISH_LONG_EDGE: u32 = 1568;

/// A blocking OCR-polish worker: owns a current-thread tokio runtime because
/// the document controller runs on a plain `std::thread`.
pub struct OcrPolisher {
    provider: LocalOpenAiProvider,
    rt: tokio::runtime::Runtime,
}

impl OcrPolisher {
    /// Build a polisher over a pre-configured provider.
    pub fn new(provider: LocalOpenAiProvider) -> AppResult<Self> {
        let rt = tokio::runtime::Builder::new_current_thread()
            .enable_all()
            .build()?;
        Ok(Self { provider, rt })
    }

    /// Correct one page's OCR text against its pixels (blocking).
    pub fn correct(&self, image_data_uri: &str, text: &str) -> AppResult<String> {
        let raw = self
            .rt
            .block_on(self.provider.correct_ocr(image_data_uri, text))?;
        Ok(strip_fences(&raw))
    }
}

/// Build the local polish provider from settings: LM Studio first, Ollama
/// fallback (both local-only servers). `None` when neither can be built —
/// the controller then skips polish without erroring.
pub fn build_local_provider(
    settings: &Settings,
    secrets: &dyn SecretStore,
) -> Option<LocalOpenAiProvider> {
    let model_override = settings.document.polish_model.trim();
    let lm_model = if model_override.is_empty() {
        settings.lm_studio.model.clone()
    } else {
        model_override.to_string()
    };
    let lm_token = secrets.get(KEY_LM_STUDIO_TOKEN).ok().flatten();
    if let Ok(p) = LocalOpenAiProvider::new(
        LocalKind {
            name: "LM Studio",
            id: "lm_studio",
        },
        &settings.lm_studio.base_url,
        &lm_model,
        settings.lm_studio.load_timeout_ms,
        lm_token,
    ) {
        return Some(p);
    }
    let ollama_model = if model_override.is_empty() {
        settings.ollama.model.clone()
    } else {
        model_override.to_string()
    };
    let ollama_token = secrets.get(KEY_OLLAMA_TOKEN).ok().flatten();
    LocalOpenAiProvider::new(
        LocalKind {
            name: "Ollama",
            id: "ollama",
        },
        &settings.ollama.base_url,
        &ollama_model,
        settings.ollama.load_timeout_ms,
        ollama_token,
    )
    .ok()
}

/// Encode a page as a downscaled PNG data-URI for the VLM (the same shape
/// `vision/polish.rs` uses for detection crops).
pub fn page_to_png_data_uri(rgb: &[u8], w: u32, h: u32) -> AppResult<String> {
    if w == 0 || h == 0 {
        return Err(AppError::Document("empty page for polish".into()));
    }
    let img = image::RgbImage::from_raw(w, h, rgb.to_vec())
        .ok_or_else(|| AppError::Document("page buffer size mismatch".into()))?;
    let long = w.max(h);
    let img = if long > POLISH_LONG_EDGE {
        let scale = POLISH_LONG_EDGE as f32 / long as f32;
        image::imageops::resize(
            &img,
            ((w as f32 * scale) as u32).max(1),
            ((h as f32 * scale) as u32).max(1),
            FilterType::Lanczos3,
        )
    } else {
        img
    };
    let mut png: Vec<u8> = Vec::new();
    {
        let mut cursor = std::io::Cursor::new(&mut png);
        image::DynamicImage::ImageRgb8(img)
            .write_to(&mut cursor, image::ImageFormat::Png)
            .map_err(|e| AppError::Document(format!("png encode failed: {e}")))?;
    }
    let b64 = {
        use base64::Engine as _;
        base64::engine::general_purpose::STANDARD.encode(&png)
    };
    Ok(format!("data:image/png;base64,{b64}"))
}

/// Strip markdown fences / prose that a verbose model may wrap the reply in:
/// the first fenced block's body wins; otherwise the raw text trimmed.
pub fn strip_fences(raw: &str) -> String {
    let trimmed = raw.trim();
    if trimmed.starts_with("```") {
        let mut lines = trimmed.lines();
        let _ = lines.next(); // opening fence
        let inner: Vec<&str> = lines
            .take_while(|l| !l.trim_start().starts_with("```"))
            .collect();
        return inner.join("\n").trim().to_string();
    }
    trimmed.to_string()
}

/// Correct one page's cleaned OCR text against its pixels. `None` when the
/// polisher is absent or the round-trip fails — the caller keeps the
/// deterministic text (never blocks, never regresses).
#[allow(clippy::too_many_arguments)]
pub fn maybe_polish(
    app: &tauri::AppHandle,
    polisher: &Option<OcrPolisher>,
    index: usize,
    page_count: usize,
    rgb: &[u8],
    w: u32,
    h: u32,
    text: &str,
) -> Option<String> {
    let polisher = polisher.as_ref()?;
    let uri = page_to_png_data_uri(rgb, w, h).ok()?;
    let _ = app.emit(
        "document://progress",
        crate::document::controller::DocumentProgress {
            page: index + 1,
            page_count,
            phase: "polish",
            fraction: (index as f32 + 0.7) / page_count.max(1) as f32,
        },
    );
    let corrected = polisher.correct(&uri, text).ok()?;
    let trimmed = corrected.trim();
    if trimmed.is_empty() || trimmed == text {
        return None;
    }
    Some(trimmed.to_string())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn strips_fenced_replies() {
        let raw = "```text\ncorrected line\nmore text\n```";
        assert_eq!(strip_fences(raw), "corrected line\nmore text");
        assert_eq!(strip_fences("  plain text  "), "plain text");
    }

    #[test]
    fn encodes_page_as_png_data_uri() {
        let w = 32u32;
        let h = 32u32;
        let rgb = vec![200u8; (w * h * 3) as usize];
        let uri = page_to_png_data_uri(&rgb, w, h).unwrap();
        assert!(uri.starts_with("data:image/png;base64,"));
    }
}
