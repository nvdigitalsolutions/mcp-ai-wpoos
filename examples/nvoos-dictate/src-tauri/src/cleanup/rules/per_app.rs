// SPDX-License-Identifier: LicenseRef-Proprietary
//! Per-app profile adjustments — the last stage, applied after punctuation.
//!
//! Applied last on purpose: each category is a deliberate stylistic choice, and
//! nothing earlier should undo it.

use crate::config::AppCategory;

pub fn adjust(text: &str, category: AppCategory) -> String {
    match category {
        // Chat: strip trailing period (typing a bare sentence in a messenger
        // reads better without it — the murmur per-app convention).
        AppCategory::Chat => text.trim_end_matches('.').to_string(),
        // Terminal: no trailing punctuation either (shell commands).
        AppCategory::Terminal => text.trim_end_matches(['.', '!', '?']).to_string(),
        // Everything else: unchanged.
        _ => text.to_string(),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn chat_strips_period() {
        assert_eq!(adjust("Sounds good.", AppCategory::Chat), "Sounds good");
    }

    #[test]
    fn default_unchanged() {
        assert_eq!(adjust("Sounds good.", AppCategory::Default), "Sounds good.");
    }
}
