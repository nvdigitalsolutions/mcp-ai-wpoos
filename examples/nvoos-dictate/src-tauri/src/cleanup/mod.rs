//! Two-stage text cleanup.
//!
//! **Stage 1 — deterministic rules (always, on-device, synchronous):**
//! pure functions with golden tests; never network, never an LLM. This is
//! the paste-ready fallback that can never block the user.
//!
//! **Stage 2 — optional LLM polish (provider trait):** `disabled` |
//! `openai_compat` | `nvoos`. Runs after Stage-1 text has been prepared;
//! the dictation controller decides whether to wait for it (setting
//! `wait_for_llm_ms`, decision record 0001) — provider failure never
//! blocks the paste.

pub mod providers;
pub mod rules;

use crate::config::{AppCategory, Settings};

/// Result of the full cleanup run.
#[derive(Debug, Clone)]
pub struct CleanupResult {
    /// Raw ASR output.
    pub raw: String,
    /// Stage-1 deterministic output (always available).
    pub stage1: String,
    /// Final text chosen for paste.
    pub final_text: String,
    /// Provider that produced `final_text` (`disabled` = stage1 passthrough).
    pub provider: &'static str,
}

impl CleanupResult {
    pub fn from_stage1(raw: String, stage1: String) -> Self {
        Self {
            raw,
            stage1: stage1.clone(),
            final_text: stage1,
            provider: "disabled",
        }
    }
}

/// Run Stage-1 deterministic cleanup.
pub fn run_deterministic(
    text: &str,
    category: AppCategory,
    dictionary: &[(String, String)],
    raw_passthrough: bool,
) -> String {
    rules::apply(text, category, dictionary, raw_passthrough)
}

/// Build the configured Stage-2 provider (None when disabled/unconfigured).
pub fn create_provider(
    settings: &Settings,
    secrets: &dyn crate::state::SecretStore,
) -> crate::error::AppResult<Option<Box<dyn providers::CleanupProvider>>> {
    providers::create(settings, secrets)
}
