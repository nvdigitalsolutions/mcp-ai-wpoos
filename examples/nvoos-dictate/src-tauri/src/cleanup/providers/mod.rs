//! Stage-2 LLM polish providers.
//!
//! Each provider receives text only (plus the target-app category) — never
//! audio. Failures are returned as errors; the dictation controller always
//! falls back to Stage-1 text, so a provider can never block a paste.

mod disabled;
mod nvoos;
mod openai_compat;

use async_trait::async_trait;

use crate::config::{AppCategory, Settings};
use crate::error::AppResult;
use crate::state::SecretStore;

/// A Stage-2 polish provider.
#[async_trait]
pub trait CleanupProvider: Send + Sync {
    /// Provider id: `openai_compat` | `nvoos`.
    fn id(&self) -> &'static str;

    /// Polish already-deterministically-cleaned text.
    async fn polish(&self, text: &str, category: AppCategory) -> AppResult<String>;
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
        "nvoos" => Ok(Some(Box::new(nvoos::NvoosProvider::new(
            &settings.nvoos,
            secrets,
        )?))),
        other => Err(crate::error::AppError::NotConfigured(format!(
            "unknown cleanup provider '{other}'"
        ))),
    }
}
