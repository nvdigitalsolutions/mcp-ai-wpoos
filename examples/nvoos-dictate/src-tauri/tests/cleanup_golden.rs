//! Golden tests for the deterministic cleanup pipeline (Stage-1).
//!
//! These are the regression backstop: any rule change must keep every case
//! green or be an intentional, reviewed behavior change. No LLM, no network.

use nvoos_dictate_lib::cleanup;
use nvoos_dictate_lib::config::AppCategory;

fn clean(text: &str, category: AppCategory) -> String {
    cleanup::run_deterministic(text, category, &[], false)
}

fn clean_raw(text: &str) -> String {
    cleanup::run_deterministic(text, AppCategory::Default, &[], true)
}

fn clean_with_dict(text: &str, dict: &[(String, String)]) -> String {
    cleanup::run_deterministic(text, AppCategory::Default, dict, false)
}

#[test]
fn golden_fillers_capitalize_punctuate() {
    assert_eq!(
        clean("um so like hello uh world", AppCategory::Default),
        "Hello world."
    );
}

#[test]
fn golden_digit_sequences() {
    assert_eq!(
        clean("my number is five two nine", AppCategory::Default),
        "My number is 529."
    );
}

#[test]
fn golden_pronoun_i() {
    assert_eq!(
        clean("i think i'm right", AppCategory::Default),
        "I think I'm right."
    );
}

#[test]
fn golden_chat_no_trailing_period() {
    assert_eq!(clean("see you there", AppCategory::Chat), "See you there");
}

#[test]
fn golden_code_untouched() {
    let input = "let foo = Bar::new()";
    assert_eq!(clean(input, AppCategory::Code), input);
}

#[test]
fn golden_cardinals() {
    assert_eq!(
        clean("twenty three people", AppCategory::Default),
        "23 people."
    );
}

#[test]
fn golden_currency() {
    assert_eq!(clean("twenty dollars", AppCategory::Default), "$20.");
}

#[test]
fn golden_repeated_words() {
    assert_eq!(
        clean("the the quick brown fox", AppCategory::Default),
        "The quick brown fox."
    );
}

#[test]
fn golden_raw_passthrough() {
    assert_eq!(clean_raw("  hello   world  "), "hello world");
}

#[test]
fn golden_dictionary_substitution() {
    let dict = vec![("myco".to_string(), "MyCo".to_string())];
    assert_eq!(
        clean_with_dict("welcome to myco", &dict),
        "Welcome to MyCo."
    );
}

#[test]
fn golden_mixed_currency() {
    assert_eq!(
        clean(
            "it costs five dollars and thirty cents",
            AppCategory::Default
        ),
        "It costs $5 and 30 cents."
    );
}

#[test]
fn golden_discourse_marker_comma() {
    assert_eq!(
        clean("however we shipped it", AppCategory::Default),
        "However, we shipped it."
    );
}

#[test]
fn golden_terminal_profile() {
    assert_eq!(
        clean("run the build", AppCategory::Terminal),
        "Run the build"
    );
}

#[test]
fn golden_phrase_filler() {
    assert_eq!(
        clean("I, you know, think so", AppCategory::Default),
        "I, think so."
    );
}

#[test]
fn golden_existing_punctuation_respected() {
    assert_eq!(clean("done!", AppCategory::Default), "Done!");
}

#[test]
fn golden_multiple_sentences() {
    assert_eq!(
        clean("hello world. this is a test", AppCategory::Default),
        "Hello world. This is a test."
    );
}

/// Fixture-based golden test (longer meeting-style dictation).
#[test]
fn golden_fixture_meeting1() {
    let raw = std::fs::read_to_string("tests/fixtures/cleanup/meeting1.raw.txt").unwrap();
    let expected = std::fs::read_to_string("tests/fixtures/cleanup/meeting1.expected.txt").unwrap();
    assert_eq!(clean(raw.trim(), AppCategory::Default), expected.trim());
}
