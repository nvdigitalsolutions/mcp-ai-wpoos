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

/// Deterministic cleanup for **OCR** output (Proposal 100, snippet path).
///
/// A dedicated, conservative profile — the speech rules (vocabulary
/// substitution, number-words, filler removal, capitalization, punctuation)
/// would corrupt OCR text, which is already written text. This only:
/// - normalizes line endings and trims per-line whitespace;
/// - collapses internal whitespace runs (OCR word boxes produce stray
///   gaps; indentation is not reliably reconstructible anyway);
/// - drops empty lines and pure table-border/decoration lines.
pub fn run_ocr_deterministic(text: &str) -> String {
    let mut lines: Vec<String> = Vec::new();
    for line in text.replace("\r\n", "\n").replace('\r', "\n").split('\n') {
        let collapsed: String = line.split_whitespace().collect::<Vec<_>>().join(" ");
        if collapsed.is_empty() {
            continue;
        }
        let stripped = collapsed.replace(
            [
                '|', '-', '=', '_', '·', '+', '—', '–', '─', '│', '└', '├', '┌',
            ],
            "",
        );
        if stripped.trim().is_empty() {
            continue; // pure decoration line
        }
        lines.push(collapsed);
    }
    lines.join("\n")
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn ocr_profile_collapses_whitespace_and_drops_decorations() {
        let raw = "First   line\r\n\r\n────────\nSecond   line  with    gaps\n\n|----|\nThird";
        assert_eq!(
            run_ocr_deterministic(raw),
            "First line\nSecond line with gaps\nThird"
        );
    }

    #[test]
    fn ocr_profile_never_touches_words() {
        // The speech rules would "fix" these; the OCR profile must not.
        let raw = "OK 1234 well, done. AI, ML, and C++";
        assert_eq!(run_ocr_deterministic(raw), raw);
    }

    #[test]
    fn ocr_profile_preserves_empty_output() {
        assert_eq!(run_ocr_deterministic("\r\n  \n"), "");
    }
}
