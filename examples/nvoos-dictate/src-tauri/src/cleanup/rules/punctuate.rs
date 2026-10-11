//! Punctuation: terminal punctuation, commas after discourse markers,
//! and category-aware behavior (code/terminal stay minimal).

use crate::config::AppCategory;

const DISCOURSE_MARKERS: &[&str] = &[
    "however",
    "therefore",
    "moreover",
    "furthermore",
    "nevertheless",
    "for example",
    "for instance",
    "in addition",
    "on the other hand",
    "meanwhile",
    "otherwise",
];

pub fn apply(text: &str, category: AppCategory) -> String {
    if text.is_empty() {
        return text.to_string();
    }

    let mut out = text.to_string();

    // Commas after leading discourse markers: "However we shipped" ->
    // "However, we shipped".
    let first_word = out
        .split_whitespace()
        .next()
        .unwrap_or("")
        .trim_end_matches(|c: char| !c.is_ascii_alphabetic())
        .to_lowercase();
    if DISCOURSE_MARKERS.contains(&first_word.as_str()) {
        if let Some(rest) = out.split_once(' ') {
            let word = rest.0.to_string();
            if !word.ends_with(',') {
                out = format!("{}, {}", word, rest.1);
            }
        }
    }

    // Terminal punctuation.
    let ends_terminated = out.ends_with(['.', '!', '?', ':', ';']);
    match category {
        // Chat, terminal, code: no forced terminal punctuation.
        AppCategory::Chat | AppCategory::Terminal | AppCategory::Code => {}
        _ => {
            if !ends_terminated {
                out.push('.');
            }
        }
    }

    out
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn adds_terminal_period() {
        assert_eq!(apply("hello world", AppCategory::Default), "hello world.");
        assert_eq!(apply("done!", AppCategory::Default), "done!");
    }

    #[test]
    fn chat_skips_terminal_period() {
        assert_eq!(apply("see you there", AppCategory::Chat), "see you there");
    }

    #[test]
    fn comma_after_marker() {
        assert_eq!(
            apply("however we shipped it", AppCategory::Default),
            "however, we shipped it."
        );
    }
}
