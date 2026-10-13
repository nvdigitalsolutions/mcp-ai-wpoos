//! Stage-2 LLM polish providers.
//!
//! Each provider receives text only (plus the target-app category) — never
//! audio. Failures are returned as errors; the dictation controller always
//! falls back to Stage-1 text, so a provider can never block a paste.

mod disabled;
pub mod lm_studio;
pub(crate) mod local_openai;
mod nvoos;
pub mod ollama;
mod openai_compat;
pub(crate) mod openai_shape;

pub use lm_studio::LmStudioConnectionTest;
pub use ollama::OllamaConnectionTest;

use async_trait::async_trait;

use crate::config::{AppCategory, Settings};
use crate::error::AppResult;
use crate::state::SecretStore;

/// Metadata the polish round-trip returned (site v1.2.x): the resolved
/// assistant id + chat session key (needed by the transcript archive
/// route) and the model/provider that answered (observability for model
/// tuning). Empty for providers that don't report it.
///
/// `ttft_ms` / `total_ms` are measured client-side by the provider when it
/// supports timing (local `lm_studio` does; `nvoos` / `openai_compat` leave
/// them `None`). `total_ms` covers the full request from send to parsed
/// reply; `ttft_ms` is the time to the first response byte where measurable.
#[derive(Debug, Clone, Default, serde::Serialize)]
pub struct PolishMeta {
    pub assistant_id: Option<String>,
    pub session_key: Option<String>,
    pub model: Option<String>,
    pub provider: Option<String>,
    pub ttft_ms: Option<i64>,
    pub total_ms: Option<i64>,
}

/// A Stage-2 polish provider.
#[async_trait]
pub trait CleanupProvider: Send + Sync {
    /// Provider id: `openai_compat` | `nvoos`.
    fn id(&self) -> &'static str;

    /// Polish already-deterministically-cleaned text.
    async fn polish(&self, text: &str, category: AppCategory) -> AppResult<(String, PolishMeta)>;

    /// Produce a structured summary of a (long) transcript. Providers that
    /// support summarization (NV oOS, local LM Studio/Ollama) override this;
    /// the default reports it as unsupported so a call never silently no-ops.
    #[allow(unused_variables)]
    async fn summarize(&self, text: &str) -> AppResult<String> {
        Err(crate::error::AppError::NotConfigured(format!(
            "provider '{}' does not support summarization",
            self.id()
        )))
    }
}

/// Build the configured provider. `Ok(None)` = disabled.
pub fn create(
    settings: &Settings,
    secrets: &dyn SecretStore,
) -> AppResult<Option<Box<dyn CleanupProvider>>> {
    match settings.cleanup.provider.as_str() {
        "disabled" | "" => Ok(None),
        "openai_compat" => Ok(Some(Box::new(openai_compat::OpenAiCompatProvider::new(
            &settings.openai_compat,
            secrets,
        )?))),
        "lm_studio" => Ok(Some(Box::new(lm_studio::LmStudioProvider::new(
            &settings.lm_studio,
            secrets,
        )?))),
        "ollama" => Ok(Some(Box::new(ollama::OllamaProvider::new(
            &settings.ollama,
            secrets,
        )?))),
        "nvoos" => Ok(Some(Box::new(nvoos::NvoosProvider::new(
            &settings.nvoos,
            secrets,
        )?))),
        other => Err(crate::error::AppError::NotConfigured(format!(
            "unknown cleanup provider '{other}'"
        ))),
    }
}
