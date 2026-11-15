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

use self::numbers::NumberPolicy;
use crate::config::{AppCategory, NumberRuleConfig};

/// Context for one cleanup run.
pub struct RuleContext<'a> {
    pub category: AppCategory,
    pub dictionary: &'a [(String, String)],
    /// Shift-held release: raw passthrough (spacing only).
    pub raw: bool,
}

/// Apply the full deterministic pipeline with the default number policy.
pub fn apply(
    text: &str,
    category: AppCategory,
    dictionary: &[(String, String)],
    raw_passthrough: bool,
) -> String {
    apply_with(
        text,
        category,
        dictionary,
        raw_passthrough,
        &NumberRuleConfig::default(),
    )
}

/// Apply the full deterministic pipeline with an explicit number policy
/// (Proposal 144 §4.1).
pub fn apply_with(
    text: &str,
    category: AppCategory,
    dictionary: &[(String, String)],
    raw_passthrough: bool,
    numbers_config: &NumberRuleConfig,
) -> String {
    let mut out = whitespace::normalize(text);
    if raw_passthrough {
        return out;
    }
    out = vocabulary::substitute(&out, dictionary);
    out = numbers::normalize(&out, NumberPolicy::resolve(category, numbers_config));
    out = fillers::remove(&out);
    out = capitalize::apply(&out, category);
    out = punctuate::apply(&out, category);
    out = per_app::adjust(&out, category);
    out
}
