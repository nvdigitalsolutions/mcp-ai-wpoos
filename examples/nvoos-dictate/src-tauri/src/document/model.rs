//! Local document-understanding tier (Proposal 111): a DeepSeek-OCR-class
//! model behind LM Studio / Ollama replaces the box-level OCR text for
//! pages the engines recognize poorly. Same blocking-worker shape as
//! `polish.rs` (own current-thread runtime); failures are always soft — the
//! deterministic result survives.

use image::imageops::FilterType;
use tauri::Emitter;

use crate::cleanup::providers::local_openai::{LocalKind, LocalOpenAiProvider};
use crate::config::{DocumentModelConfig, Settings};
use crate::error::{AppError, AppResult};
use crate::state::{SecretStore, KEY_LM_STUDIO_TOKEN, KEY_OLLAMA_TOKEN};

/// Long edge a page is downscaled to before the model round-trip. The
/// polish budget (1568) is too small for a document model; 3000 keeps
/// tables and small print legible while bounding the request size (the
/// model's internal optical compression handles the rest).
const MODEL_LONG_EDGE: u32 = 3000;

/// A blocking document-model worker (own current-thread tokio runtime).
pub struct DocumentModel {
    provider: LocalOpenAiProvider,
    rt: tokio::runtime::Runtime,
}

impl DocumentModel {
    pub fn new(provider: LocalOpenAiProvider) -> AppResult<Self> {
        let rt = tokio::runtime::Builder::new_current_thread()
            .enable_all()
            .build()?;
        Ok(Self { provider, rt })
    }

    /// One full-page pass (blocking). The reply is fence-stripped Markdown.
    #[cfg(feature = "document")]
    pub fn understand(&self, image_data_uri: &str, prior_text: &str) -> AppResult<String> {
        self.rt.block_on(
            self.provider
                .understand_document(image_data_uri, prior_text),
        )
    }

    /// Liveness check for the Settings "Test connection" button: a one-token
    /// completion through the configured endpoint (quality is the probe's
    /// job — this proves the server answers).
    pub fn test_connection(&self) -> AppResult<String> {
        self.rt.block_on(self.provider.complete(
            "You are a health check.",
            "Reply with the single word: ok",
            0.0,
        ))
    }
}

/// Should this page escalate to the model tier? Pure and testable.
///
/// Note (verified 2026-11-04): WinRT reports a constant 1.0 confidence
/// ("exposes no per-word confidence") and the ONNX tier reports `None` —
/// so with today's engines only `all_scans` fires. `min_confidence`
/// activates automatically when an engine starts reporting real scores.
pub fn should_escalate(
    cfg: &DocumentModelConfig,
    confidence: Option<f32>,
    pages_used: usize,
) -> bool {
    if !cfg.enabled || pages_used >= cfg.max_pages.max(1) {
        return false;
    }
    if cfg.all_scans {
        return true;
    }
    cfg.min_confidence
        .is_some_and(|t| confidence.is_some_and(|c| c < t))
}

/// Build one provider from its identity (`lm_studio` | `ollama`).
fn provider_for(
    kind: &str,
    settings: &Settings,
    secrets: &dyn SecretStore,
    model: &str,
) -> Option<LocalOpenAiProvider> {
    let (local_kind, base_url, load_ms, key) = match kind {
        "lm_studio" => (
            LocalKind {
                name: "LM Studio",
                id: "lm_studio",
            },
            settings.lm_studio.base_url.clone(),
            settings.lm_studio.load_timeout_ms,
            KEY_LM_STUDIO_TOKEN,
        ),
        _ => (
            LocalKind {
                name: "Ollama",
                id: "ollama",
            },
            settings.ollama.base_url.clone(),
            settings.ollama.load_timeout_ms,
            KEY_OLLAMA_TOKEN,
        ),
    };
    let token = secrets.get(key).ok().flatten();
    LocalOpenAiProvider::new(local_kind, &base_url, model, load_ms, token).ok()
}

/// Build the model worker: the configured provider first, the other local
/// provider as fallback. `None` when the tier is disabled or nothing
/// builds. A blank model id means "the server's single loaded model".
pub fn build_document_model(
    settings: &Settings,
    secrets: &dyn SecretStore,
) -> Option<DocumentModel> {
    let cfg = &settings.document.model;
    if !cfg.enabled {
        return None;
    }
    let model = cfg.model.trim();
    let (first, second) = match cfg.provider.as_str() {
        "lm_studio" => ("lm_studio", "ollama"),
        _ => ("ollama", "lm_studio"),
    };
    for kind in [first, second] {
        if let Some(worker) =
            provider_for(kind, settings, secrets, model).and_then(|p| DocumentModel::new(p).ok())
        {
            return Some(worker);
        }
    }
    None
}

/// Encode the RAW page (never the binarized scan profile — the model needs
/// photos/tables intact) as a PNG data-URI, downscaled to the model budget.
pub fn page_to_png_data_uri(rgb: &[u8], w: u32, h: u32) -> AppResult<String> {
    if w == 0 || h == 0 {
        return Err(AppError::Document("empty page for the model tier".into()));
    }
    let img = image::RgbImage::from_raw(w, h, rgb.to_vec())
        .ok_or_else(|| AppError::Document("page buffer size mismatch".into()))?;
    let long = w.max(h);
    let img = if long > MODEL_LONG_EDGE {
        let scale = MODEL_LONG_EDGE as f32 / long as f32;
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

/// Run the model tier on one page. `None` when the worker is absent or the
/// round-trip fails — the caller keeps the box-level text (never blocks,
/// never regresses — the dictation Stage-2 rule).
#[allow(clippy::too_many_arguments)]
pub fn maybe_model(
    app: &tauri::AppHandle,
    worker: &Option<DocumentModel>,
    index: usize,
    page_count: usize,
    rgb: &[u8],
    w: u32,
    h: u32,
    prior_text: &str,
) -> Option<String> {
    let worker = worker.as_ref()?;
    let uri = page_to_png_data_uri(rgb, w, h).ok()?;
    let _ = app.emit(
        "document://progress",
        crate::document::controller::DocumentProgress {
            page: index + 1,
            page_count,
            phase: "model",
            fraction: (index as f32 + 0.8) / page_count.max(1) as f32,
        },
    );
    let out = worker.understand(&uri, prior_text).ok()?;
    let trimmed = out.trim();
    if trimmed.is_empty() || trimmed == prior_text.trim() {
        return None;
    }
    Some(trimmed.to_string())
}

#[cfg(test)]
mod tests {
    use super::*;

    fn cfg(
        enabled: bool,
        all_scans: bool,
        min_conf: Option<f32>,
        budget: usize,
    ) -> DocumentModelConfig {
        DocumentModelConfig {
            enabled,
            provider: "ollama".to_string(),
            model: "deepseek-ocr".to_string(),
            min_confidence: min_conf,
            all_scans,
            max_pages: budget,
            load_timeout_ms: 120_000,
        }
    }

    #[test]
    fn escalation_requires_enabled_and_budget() {
        assert!(!should_escalate(&cfg(false, true, None, 5), None, 0));
        assert!(!should_escalate(&cfg(true, true, None, 5), None, 5));
        assert!(!should_escalate(&cfg(true, true, None, 5), None, 6));
    }

    #[test]
    fn all_scans_fires_without_confidence() {
        assert!(should_escalate(&cfg(true, true, None, 5), None, 0));
        assert!(should_escalate(&cfg(true, true, None, 5), Some(1.0), 0));
    }

    #[test]
    fn confidence_gate_fires_below_threshold_only() {
        assert!(should_escalate(
            &cfg(true, false, Some(0.5), 5),
            Some(0.4),
            0
        ));
        assert!(!should_escalate(
            &cfg(true, false, Some(0.5), 5),
            Some(0.6),
            0
        ));
        // No reported confidence: never fires without all_scans.
        assert!(!should_escalate(&cfg(true, false, Some(0.5), 5), None, 0));
    }
}
