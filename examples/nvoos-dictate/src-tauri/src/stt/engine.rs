//! STT engine facade.
//!
//! One `SttEngine` implementation per backend; engines are created once,
//! loaded into memory, warmed with a silent decode, and kept resident for
//! the app's lifetime (per-utterance model reload is the classic latency
//! killer — murmur's architecture note). Heavy native backends are cargo
//! features so the default build (mock + embedded engines) needs no C++
//! toolchains.
//!
//! Engine preference (ADR 0003): **local-first** — mock (default, pipeline
//! testing) → parakeet → whisper → moonshine → embedded (remote fallback).

use std::path::Path;

use crate::config::EngineConfig;
use crate::error::{AppError, AppResult};
use crate::stt::embedded::{EmbeddedAuth, EmbeddedEngine};
use crate::stt::mock::MockEngine;

/// A resident speech-to-text engine.
pub trait SttEngine: Send + Sync {
    /// Engine identifier: `parakeet` | `whisper` | `moonshine` | `embedded` | `mock`.
    fn id(&self) -> &'static str;

    /// The selected model id (e.g. `sherpa-onnx-nemo-parakeet-tdt-0.6b-v2-int8`).
    fn model_id(&self) -> &str;

    /// Load/initialize and warm up (silent decode) so the first utterance is
    /// not slow. Called once on engine swap.
    fn warm(&self) -> AppResult<()> {
        Ok(())
    }

    /// Transcribe mono f32 PCM at `sample_rate` Hz (typically 16 kHz).
    /// `language` is a BCP-47 hint when the engine supports it.
    fn transcribe(
        &self,
        audio: &[f32],
        sample_rate: u32,
        language: Option<&str>,
    ) -> AppResult<String>;

    /// Whether the engine is suitable for long file/meeting transcription
    /// (Parakeet TDT/CTC models top out around 4-5 minutes per decode).
    fn supports_long_files(&self) -> bool {
        false
    }
}

/// Human-readable engine status for the settings UI.
#[derive(Debug, Clone, serde::Serialize)]
pub struct EngineStatus {
    pub engine: String,
    pub model: String,
    pub loaded: bool,
    pub message: String,
}

/// Runtime inputs the engine factory needs beyond `EngineConfig`.
pub struct EngineResources<'a> {
    /// Model files live under this directory (default
    /// `{app_data_dir}/nvoos-dictate/models`).
    pub models_dir: &'a Path,
    /// NV oOS site URL — required by the remote `embedded` engine.
    pub nvoos_site_url: Option<&'a str>,
    /// Assistant credential (Bearer) — the `embedded` engine fallback auth.
    pub nvoos_credential: Option<&'a str>,
    /// WP Application Password (Basic) — the `embedded` engine's preferred
    /// auth (the addon route admits logged-in users).
    pub wp_username: Option<&'a str>,
    pub wp_app_password: Option<&'a str>,
}

impl EngineResources<'_> {
    /// Minimal resources for headless/examples: models dir only, no remote auth.
    pub fn local_only(models_dir: &Path) -> EngineResources<'_> {
        EngineResources {
            models_dir,
            nvoos_site_url: None,
            nvoos_credential: None,
            wp_username: None,
            wp_app_password: None,
        }
    }
}

/// Factory: build the engine named in settings.
pub fn create_engine(
    settings: &EngineConfig,
    resources: &EngineResources<'_>,
) -> AppResult<Box<dyn SttEngine>> {
    match settings.engine.as_str() {
        "mock" => Ok(Box::new(MockEngine::new())),
        #[cfg(feature = "parakeet")]
        "parakeet" => Ok(Box::new(crate::stt::parakeet::ParakeetEngine::new(
            resources.models_dir,
            &settings.model,
            settings.num_threads,
        )?)),
        #[cfg(feature = "whisper")]
        "whisper" => Ok(Box::new(crate::stt::whisper::WhisperEngine::new(
            resources.models_dir,
            &settings.model,
        )?)),
        #[cfg(feature = "moonshine")]
        "moonshine" => Ok(Box::new(crate::stt::moonshine::MoonshineEngine::new(
            resources.models_dir,
            &settings.model,
        )?)),
        "embedded" => {
            let site_url = resources
                .nvoos_site_url
                .filter(|s| !s.is_empty())
                .ok_or_else(|| {
                    AppError::NotConfigured(
                        "the embedded engine needs the NV oOS site URL (Settings > NV oOS)".into(),
                    )
                })?;
            let auth = EmbeddedAuth::from_parts(
                match (resources.wp_username, resources.wp_app_password) {
                    (Some(u), Some(p)) => Some((u, p)),
                    _ => None,
                },
                resources.nvoos_credential,
            )?;
            Ok(Box::new(EmbeddedEngine::new(site_url, auth)?))
        }
        other => Err(AppError::NotConfigured(format!(
            "engine '{other}' is not compiled into this build (available: mock, embedded{}). \
             Rebuild with the matching cargo feature, e.g. --features parakeet",
            available_features(),
        ))),
    }
}

/// Feature banner used in error messages and the UI.
pub fn available_features() -> &'static str {
    let features = String::new();
    #[cfg(feature = "parakeet")]
    let features = features + ", parakeet";
    #[cfg(feature = "whisper")]
    let features = features + ", whisper";
    #[cfg(feature = "moonshine")]
    let features = features + ", moonshine";
    // Leak is fine: static strings, process lifetime.
    Box::leak(features.into_boxed_str())
}
