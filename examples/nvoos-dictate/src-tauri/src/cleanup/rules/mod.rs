//! Deterministic cleanup rules.
//!
//! Order matters: whitespace → vocabulary → numbers → fillers →
//! capitalization → punctuation → per-app adjustments. Each stage is a pure
//! function so the whole pipeline is golden-testable without an LLM.

mod capitalize;
mod fillers;
mod numbers;
mod per_app;
mod punctuate;
mod vocabulary;
mod whitespace;

use crate::config::AppCategory;

/// Context for one cleanup run.
pub struct RuleContext<'a> {
    pub category: AppCategory,
    pub dictionary: &'a [(String, String)],
    /// Shift-held release: raw passthrough (spacing only).
    pub raw: bool,
}

/// Apply the full deterministic pipeline.
pub fn apply(
    text: &str,
    category: AppCategory,
    dictionary: &[(String, String)],
    raw_passthrough: bool,
) -> String {
    let mut out = whitespace::normalize(text);
    if raw_passthrough {
        return out;
    }
    out = vocabulary::substitute(&out, dictionary);
    out = numbers::normalize(&out);
    out = fillers::remove(&out);
    out = capitalize::apply(&out, category);
    out = punctuate::apply(&out, category);
    out = per_app::adjust(&out, category);
    out
}
