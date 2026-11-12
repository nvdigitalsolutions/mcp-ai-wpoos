//! Number normalization (inverse text normalization, tiered).
//!
//! Implements Proposal 144 §4.1. The previous implementation converted every
//! cardinal unconditionally, which rewrote ordinary prose ("one or two things"
//! -> "1 or 2 things"). The published ITN practice is the opposite: high
//! precision first, and leave ambiguous forms in spoken form, because a wrong
//! digit substitution is silent and hard for the user to notice or undo.
//!
//! Tiers (all gated by [`NumberPolicy::enabled`]):
//!
//! - **T1** digit runs of 3+ single digits — `five two nine` -> `529`.
//!   Unambiguous, always on: phone numbers, IDs, codes.
//! - **T2** explicit unit present — `twenty dollars` -> `$20`,
//!   `fifty percent` -> `50%`, `three minutes` -> `3 minutes`.
//! - **T3** compound cardinals — `twenty three` -> `23`,
//!   `one hundred and five` -> `105`.
//! - **T4** bare cardinals — `one`, `twenty` with neither cue nor unit.
//!   Off by default; enabled by [`NumberPolicy::bare_small`].
//!
//! A `NUMBER_CUES` word immediately before the run ("page one", "version two")
//! promotes a bare cardinal to a conversion regardless of T4. An idiom list
//! (`IDIOMS`) protects phrases that must never be rewritten.

use crate::config::{AppCategory, NumberRuleConfig};

const ONES: &[(&str, u64)] = &[
    ("zero", 0),
    ("one", 1),
    ("two", 2),
    ("three", 3),
    ("four", 4),
    ("five", 5),
    ("six", 6),
    ("seven", 7),
    ("eight", 8),
    ("nine", 9),
    ("ten", 10),
    ("eleven", 11),
    ("twelve", 12),
    ("thirteen", 13),
    ("fourteen", 14),
    ("fifteen", 15),
    ("sixteen", 16),
    ("seventeen", 17),
    ("eighteen", 18),
    ("nineteen", 19),
];

const TENS: &[(&str, u64)] = &[
    ("twenty", 20),
    ("thirty", 30),
    ("forty", 40),
    ("fifty", 50),
    ("sixty", 60),
    ("seventy", 70),
    ("eighty", 80),
    ("ninety", 90),
];

/// Phrases that must never be normalised. Matched case-insensitively on word
/// boundaries; any number word overlapping a match is left untouched.
///
/// Deliberately short: only forms where a digit substitution changes meaning or
/// reads badly. Harmless conversions ("ten out of ten" -> "10 out of 10") are
/// intentionally absent so the list does not over-block.
const IDIOMS: &[&str] = &[
    "one or two",
    "one or the other",
    "one of",
    "one thing",
    "two things",
    "ten to one",
    "one another",
    "one and all",
    "no one",
    "a thousand times",
];

/// A number word immediately following one of these announces a quantity.
const NUMBER_CUES: &[&str] = &[
    "number",
    "numbers",
    "id",
    "ids",
    "page",
    "pages",
    "line",
    "lines",
    "version",
    "ver",
    "count",
    "step",
    "steps",
    "item",
    "items",
    "amount",
    "code",
    "pin",
    "extension",
    "ext",
    "phone",
    "room",
    "order",
    "invoice",
    "ticket",
    "ref",
    "reference",
    "chapter",
    "section",
    "part",
    "level",
    "port",
    "size",
    "total",
    "sum",
    "score",
    "rank",
    "position",
    "index",
];

/// Units that disambiguate a quantity (T2).
const UNITS: &[&str] = &[
    // Currency
    "dollar",
    "dollars",
    "cent",
    "cents",
    "euro",
    "euros",
    "pound",
    "pounds",
    "yen",
    "franc",
    "francs",
    "rupee",
    "rupees",
    // Percent
    "percent",
    // Time
    "second",
    "seconds",
    "minute",
    "minutes",
    "hour",
    "hours",
    "day",
    "days",
    "week",
    "weeks",
    "month",
    "months",
    "year",
    "years",
    // Count nouns that read naturally as digits
    "people",
    "person",
    "item",
    "items",
    "file",
    "files",
    "line",
    "lines",
    "page",
    "pages",
    "word",
    "words",
    "character",
    "characters",
    "byte",
    "bytes",
    "row",
    "rows",
    "result",
    "results",
    "copy",
    "copies",
    "time",
    "times",
    // Measures
    "metre",
    "metres",
    "meter",
    "meters",
    "kilometre",
    "kilometres",
    "kilometer",
    "kilometers",
    "mile",
    "miles",
    "foot",
    "feet",
    "inch",
    "inches",
    "gram",
    "grams",
    "kilogram",
    "kilograms",
    "ounce",
    "ounces",
    "litre",
    "litres",
    "liter",
    "liters",
    "kilobyte",
    "kilobytes",
    "megabyte",
    "megabytes",
    "gigabyte",
    "gigabytes",
    "terabyte",
    "terabytes",
    "kb",
    "mb",
    "gb",
    "tb",
];

/// How number normalization behaves for one cleanup run.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct NumberPolicy {
    /// Master switch. When false the rule is a no-op.
    pub enabled: bool,
    /// T4: convert a bare cardinal with neither cue nor unit ("I have twenty").
    pub bare_small: bool,
    /// Group thousands (`$1,000`) in prose categories.
    pub thousands_separator: bool,
}

impl NumberPolicy {
    /// Prose defaults: T1–T3 on, T4 off, grouped thousands.
    pub fn prose() -> Self {
        Self {
            enabled: true,
            bare_small: false,
            thousands_separator: true,
        }
    }

    /// Per-category defaults (Proposal 144 §4.1). Code and shell commands keep
    /// their identifiers intact, so the rule is off for those two.
    pub fn for_category(category: AppCategory) -> Self {
        match category {
            AppCategory::Code | AppCategory::Terminal => Self {
                enabled: false,
                ..Self::prose()
            },
            _ => Self::prose(),
        }
    }

    /// Resolve the user configuration on top of the category defaults.
    pub fn resolve(category: AppCategory, config: &NumberRuleConfig) -> Self {
        let mut policy = Self::for_category(category);
        if !config.enabled {
            policy.enabled = false;
            return policy;
        }
        // Code and Terminal default to off, but an explicit `in_code` opt-in
        // wins. Setting `enabled` here (rather than only skipping the disable)
        // is what makes the opt-in actually take effect.
        policy.enabled =
            !matches!(category, AppCategory::Code | AppCategory::Terminal) || config.in_code;
        policy.bare_small = config.bare_small;
        policy.thousands_separator = config.thousands_separator;
        policy
    }
}

#[derive(Debug, Clone)]
struct Token {
    start: usize,
    text: String,
}

impl Token {
    fn is_word(&self) -> bool {
        self.text
            .chars()
            .next()
            .map(|c| c.is_ascii_alphabetic())
            .unwrap_or(false)
    }

    fn is_space(&self) -> bool {
        !self.text.is_empty() && self.text.chars().all(char::is_whitespace)
    }

    fn lower(&self) -> String {
        self.text.to_ascii_lowercase()
    }

    fn end(&self) -> usize {
        self.start + self.text.len()
    }
}

fn word_value(word: &str) -> Option<u64> {
    let w = word.to_ascii_lowercase();
    if let Some(&(_, v)) = ONES.iter().find(|(n, _)| *n == w) {
        return Some(v);
    }
    if let Some(&(_, v)) = TENS.iter().find(|(n, _)| *n == w) {
        return Some(v);
    }
    match w.as_str() {
        "hundred" => Some(100),
        "thousand" => Some(1_000),
        "million" => Some(1_000_000),
        _ => None,
    }
}

/// A single-digit word (`zero`..`nine`). Used to detect digit runs, which must
/// be concatenated rather than summed.
fn single_digit_value(word: &str) -> Option<u64> {
    let w = word.to_ascii_lowercase();
    ONES.iter().take(10).find(|(n, _)| *n == w).map(|(_, v)| *v)
}

fn is_number_word(word: &str) -> bool {
    word_value(word).is_some()
}

fn is_unit(word: &str) -> bool {
    UNITS.contains(&word)
}

fn is_cue(word: &str) -> bool {
    NUMBER_CUES.contains(&word)
}

/// Spoken-number shape, used to reject sequences that are not English
/// numerals. "twenty three" is a number; "five thirty" is a time or a score
/// and must not become 35.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum Class {
    Ones,
    Teens,
    Tens,
    Scale,
}

fn class_of(value: u64) -> Class {
    match value {
        0..=9 => Class::Ones,
        10..=19 => Class::Teens,
        20..=99 => Class::Tens,
        _ => Class::Scale,
    }
}

/// Whether `next` may follow `prev` in a cardinal numeral.
fn composes(prev: Class, next: Class) -> bool {
    match (prev, next) {
        // hundred / thousand / million may follow anything and be followed by
        // anything: "one hundred and five", "two thousand twenty five".
        (_, Class::Scale) | (Class::Scale, _) => true,
        // "twenty three", "ninety nine".
        (Class::Tens, Class::Ones) => true,
        // Everything else stacks invalidly: "five thirty", "twenty thirty",
        // "ten five", "five three".
        _ => false,
    }
}

/// Parse a sequence of number words into an integer (handles "hundred/thousand").
///
/// Returns `None` for word sequences that are not valid numerals, so ambiguous
/// speech (`five thirty`) is left in spoken form instead of being summed.
fn parse_sequence(words: &[&str]) -> Option<u64> {
    let mut total: u64 = 0;
    let mut current: u64 = 0;
    let mut any = false;
    let mut prev_class: Option<Class> = None;

    for w in words {
        let v = word_value(w)?;
        let class = class_of(v);
        if let Some(prev) = prev_class {
            if !composes(prev, class) {
                return None;
            }
        }
        prev_class = Some(class);
        any = true;
        match v {
            100 => current = if current == 0 { 100 } else { current * 100 },
            1_000 | 1_000_000 => {
                current = if current == 0 { v } else { current * v };
                total += current;
                current = 0;
            }
            _ => current += v,
        }
    }
    if any {
        Some(total + current)
    } else {
        None
    }
}

fn tokens(text: &str) -> Vec<Token> {
    let mut out = Vec::new();
    let mut current = String::new();
    let mut start = 0usize;
    for (i, c) in text.char_indices() {
        if c.is_ascii_alphabetic() || c == '\'' {
            if current.is_empty() {
                start = i;
            }
            current.push(c);
        } else {
            if !current.is_empty() {
                out.push(Token {
                    start,
                    text: std::mem::take(&mut current),
                });
            }
            out.push(Token {
                start: i,
                text: c.to_string(),
            });
        }
    }
    if !current.is_empty() {
        out.push(Token {
            start,
            text: current,
        });
    }
    out
}

fn next_token(toks: &[Token], from: usize) -> Option<(usize, &Token)> {
    (from..toks.len()).find_map(|k| (!toks[k].is_space()).then_some((k, &toks[k])))
}

fn prev_token(toks: &[Token], from: usize) -> Option<(usize, &Token)> {
    (0..from)
        .rev()
        .find_map(|k| (!toks[k].is_space()).then_some((k, &toks[k])))
}

/// Byte ranges covered by an idiom. Offsets are safe to compare across the
/// lowercased copy because `to_ascii_lowercase` preserves byte length.
fn protected_ranges(text: &str) -> Vec<(usize, usize)> {
    let lower = text.to_ascii_lowercase();
    let mut ranges = Vec::new();
    for phrase in IDIOMS {
        let mut from = 0usize;
        while let Some(pos) = lower[from..].find(phrase) {
            let start = from + pos;
            let end = start + phrase.len();
            let before_ok = lower[..start]
                .chars()
                .next_back()
                .map(|c| !c.is_alphanumeric())
                .unwrap_or(true);
            let after_ok = lower[end..]
                .chars()
                .next()
                .map(|c| !c.is_alphanumeric())
                .unwrap_or(true);
            if before_ok && after_ok {
                ranges.push((start, end));
            }
            from = end.max(from + 1);
        }
    }
    ranges
}

fn is_protected(start: usize, end: usize, ranges: &[(usize, usize)]) -> bool {
    ranges.iter().any(|&(s, e)| start < e && end > s)
}

fn group_thousands(value: u64) -> String {
    let digits = value.to_string();
    let mut out = String::with_capacity(digits.len() + digits.len() / 3);
    for (i, c) in digits.chars().enumerate() {
        if i > 0 && (digits.len() - i).is_multiple_of(3) {
            out.push(',');
        }
        out.push(c);
    }
    out
}

fn format_value(value: u64, policy: NumberPolicy) -> String {
    if policy.thousands_separator && value >= 1_000 {
        group_thousands(value)
    } else {
        value.to_string()
    }
}

/// Drop a `a`/`an` article that immediately precedes a currency conversion:
/// "a thousand dollars" reads as "$1,000", not "a $1,000".
fn drop_article_before_currency(out: &mut String) {
    let trimmed_len = out.trim_end().len();
    let lowered = out[..trimmed_len].to_ascii_lowercase();
    if lowered == "a" || lowered == "an" {
        out.clear();
        return;
    }
    let cut = if lowered.ends_with(" an") {
        3
    } else if lowered.ends_with(" a") {
        2
    } else {
        return;
    };
    if trimmed_len < cut {
        return;
    }
    // Keep the separator that sat before the article: "i paid a " must become
    // "i paid " so the currency symbol does not collide with the last word.
    out.truncate(trimmed_len - cut + 1);
}

fn concat_digits(run: &[&str]) -> String {
    run.iter()
        .map(|w| single_digit_value(w).unwrap_or(0).to_string())
        .collect()
}

/// A run of single-digit words followed only by scale words is a concatenated
/// number, not arithmetic: "five two nine thousand" is 529 × 1000 = 529,000,
/// never 5+2+9 = 16 scaled. Returns `None` when the run is not that shape, so
/// the normal parser (or no conversion) still applies.
fn concat_digit_run_with_scale(run: &[&str]) -> Option<u64> {
    let split = run.iter().position(|w| single_digit_value(w).is_none())?;
    if split == 0 || !run[..split].iter().all(|w| single_digit_value(w).is_some()) {
        return None;
    }

    let mut scale = 1u64;
    for word in &run[split..] {
        match word_value(word)? {
            100 => scale = scale.saturating_mul(100),
            1_000 => scale = scale.saturating_mul(1_000),
            1_000_000 => scale = scale.saturating_mul(1_000_000),
            _ => return None,
        }
    }

    let digits = concat_digits(&run[..split]);
    digits.parse::<u64>().ok().map(|n| n * scale)
}

/// Normalize `text` under `policy`.
pub fn normalize(text: &str, policy: NumberPolicy) -> String {
    if !policy.enabled || text.is_empty() {
        return text.to_string();
    }

    let toks = tokens(text);
    let protected = protected_ranges(text);
    let mut out = String::with_capacity(text.len());
    let mut i = 0usize;

    while i < toks.len() {
        if !is_number_word(&toks[i].text) {
            out.push_str(&toks[i].text);
            i += 1;
            continue;
        }

        // Collect the maximal run of number words. Whitespace and the
        // connector "and" are skipped so "one hundred and five" parses as one
        // run; any other token ends the run.
        let mut j = i;
        let mut run: Vec<&str> = Vec::new();
        let mut last_word = i;
        while j < toks.len() {
            let t = &toks[j];
            if t.is_space() {
                j += 1;
                continue;
            }
            if t.is_word() {
                let lower = t.lower();
                if lower == "and" {
                    j += 1;
                    continue;
                }
                if is_number_word(&t.text) {
                    run.push(&t.text);
                    last_word = j;
                    j += 1;
                    continue;
                }
            }
            break;
        }

        let start = toks[i].start;
        let end = toks[last_word].end();
        if run.is_empty() || is_protected(start, end, &protected) {
            out.push_str(&toks[i].text);
            i += 1;
            continue;
        }

        let digits_only = run.iter().all(|w| single_digit_value(w).is_some());
        let next = next_token(&toks, j);
        let prev = prev_token(&toks, i);
        let unit_present = next.map(|(_, t)| is_unit(&t.lower())).unwrap_or(false);
        let cue_present = prev.map(|(_, t)| is_cue(&t.lower())).unwrap_or(false);

        // T1 and the cued short digit run are decided by position, not by
        // arithmetic: summing "five two nine" would give 16. They run before
        // `parse_sequence`, which rejects non-numeral sequences outright.
        let replacement: Option<String> =
            if digits_only && (run.len() >= 3 || (run.len() == 2 && cue_present)) {
                Some(concat_digits(&run))
            } else if let Some(v) = concat_digit_run_with_scale(&run) {
                // "five two nine thousand" -> 529,000 (concatenate, then scale).
                Some(format_value(v, policy))
            } else if let Some(v) = parse_sequence(&run) {
                if unit_present {
                    // T2 — an explicit unit disambiguates the quantity.
                    Some(format_value(v, policy))
                } else if digits_only {
                    // A lone digit word needs a cue or the opt-in.
                    if cue_present || policy.bare_small {
                        Some(format_value(v, policy))
                    } else {
                        None
                    }
                } else if run.len() >= 2 {
                    // T3 — compound cardinals (validated by `parse_sequence`).
                    Some(format_value(v, policy))
                } else if policy.bare_small || cue_present {
                    // T4 — bare cardinals, opt-in.
                    Some(format_value(v, policy))
                } else {
                    None
                }
            } else {
                // Not an English numeral ("five thirty"): leave it in speech.
                None
            };

        let Some(repl) = replacement else {
            out.push_str(&toks[i].text);
            i += 1;
            continue;
        };

        // Currency and percent suffixes consume the unit token.
        let mut consumed = last_word + 1;
        let mut rendered = repl;
        if let Some((idx, tok)) = next {
            match tok.lower().as_str() {
                "dollars" | "dollar" => {
                    drop_article_before_currency(&mut out);
                    rendered = format!("${rendered}");
                    consumed = idx + 1;
                }
                "percent" => {
                    rendered = format!("{rendered}%");
                    consumed = idx + 1;
                }
                _ => {}
            }
        }

        out.push_str(&rendered);
        i = consumed;
    }

    out
}

#[cfg(test)]
mod tests {
    use super::*;

    fn prose(text: &str) -> String {
        normalize(text, NumberPolicy::for_category(AppCategory::Default))
    }

    fn chat(text: &str) -> String {
        normalize(text, NumberPolicy::for_category(AppCategory::Chat))
    }

    // ---- T1: digit runs -------------------------------------------------

    #[test]
    fn digit_runs_concatenate() {
        assert_eq!(prose("my number is five two nine"), "my number is 529");
    }

    #[test]
    fn two_digit_run_needs_a_cue() {
        // Summing this would produce "14"; concatenating it without a cue
        // would rewrite ordinary speech. Leave it alone.
        assert_eq!(prose("five nine"), "five nine");
        assert_eq!(prose("extension five nine"), "extension 59");
    }

    // ---- T2: units ------------------------------------------------------

    #[test]
    fn currency_and_percent() {
        assert_eq!(prose("twenty dollars"), "$20");
        assert_eq!(prose("fifty percent"), "50%");
        assert_eq!(prose("five dollars"), "$5");
    }

    #[test]
    fn currency_drops_the_article() {
        assert_eq!(prose("a thousand dollars"), "$1,000");
        assert_eq!(prose("i paid a thousand dollars"), "i paid $1,000");
        assert_eq!(prose("an hour"), "an hour");
    }

    #[test]
    fn time_and_measure_units() {
        assert_eq!(prose("three minutes"), "3 minutes");
        assert_eq!(prose("five gigabytes"), "5 gigabytes");
        assert_eq!(prose("two people"), "2 people");
    }

    // ---- T3: compound cardinals ----------------------------------------

    #[test]
    fn compound_cardinals() {
        assert_eq!(prose("twenty three people"), "23 people");
        assert_eq!(prose("one hundred and five"), "105");
        assert_eq!(prose("two thousand twenty five"), "2,025");
    }

    // ---- T4: bare cardinals --------------------------------------------

    #[test]
    fn bare_small_cardinals_are_off_by_default() {
        assert_eq!(prose("i have one"), "i have one");
        assert_eq!(prose("give me twenty"), "give me twenty");
    }

    #[test]
    fn bare_small_cardinals_opt_in() {
        let policy = NumberPolicy {
            bare_small: true,
            ..NumberPolicy::prose()
        };
        assert_eq!(normalize("i have one", policy), "i have 1");
    }

    #[test]
    fn a_cue_promotes_a_bare_cardinal() {
        assert_eq!(prose("page one"), "page 1");
        assert_eq!(prose("version two"), "version 2");
    }

    // ---- Idioms ---------------------------------------------------------

    #[test]
    fn idioms_are_never_rewritten() {
        assert_eq!(prose("one or two things"), "one or two things");
        assert_eq!(prose("i have one thing to say"), "i have one thing to say");
        assert_eq!(prose("no one knows"), "no one knows");
        assert_eq!(prose("ten to one it fails"), "ten to one it fails");
        assert_eq!(prose("one of them"), "one of them");
        assert_eq!(prose("they helped one another"), "they helped one another");
    }

    #[test]
    fn idiom_protection_survives_the_opt_in() {
        let policy = NumberPolicy {
            bare_small: true,
            ..NumberPolicy::prose()
        };
        assert_eq!(normalize("no one knows", policy), "no one knows");
        assert_eq!(normalize("one of them", policy), "one of them");
    }

    #[test]
    fn chat_mail_docs_share_the_prose_policy() {
        assert_eq!(chat("one or two things"), "one or two things");
        assert_eq!(
            normalize(
                "one or two things",
                NumberPolicy::for_category(AppCategory::Docs)
            ),
            "one or two things"
        );
    }

    // ---- Category switches ---------------------------------------------

    #[test]
    fn code_and_terminal_disable_the_rule() {
        for category in [AppCategory::Code, AppCategory::Terminal] {
            let policy = NumberPolicy::for_category(category);
            assert!(!policy.enabled, "{category:?} must not normalise numbers");
            assert_eq!(normalize("twenty three", policy), "twenty three");
        }
    }

    #[test]
    fn config_can_force_the_rule_in_code() {
        let config = NumberRuleConfig {
            in_code: true,
            ..NumberRuleConfig::default()
        };
        let policy = NumberPolicy::resolve(AppCategory::Code, &config);
        assert!(policy.enabled);
        assert_eq!(normalize("twenty three", policy), "23");
    }

    #[test]
    fn config_master_switch_wins() {
        let config = NumberRuleConfig {
            enabled: false,
            ..NumberRuleConfig::default()
        };
        let policy = NumberPolicy::resolve(AppCategory::Default, &config);
        assert!(!policy.enabled);
        assert_eq!(normalize("twenty three", policy), "twenty three");
    }

    #[test]
    fn digit_runs_compose_with_scale_words() {
        // Concatenate first, then scale: 529 × 1000, not (5+2+9) × 1000.
        assert_eq!(prose("five two nine thousand dollars"), "$529,000");
        assert_eq!(prose("five two nine hundred"), "52,900");
        assert_eq!(prose("five thousand dollars"), "$5,000");
    }

    #[test]
    fn invalid_compositions_stay_spoken() {
        // "five thirty" is a time or a score, not 35. Summing it was a defect.
        assert_eq!(prose("five thirty"), "five thirty");
        assert_eq!(prose("twenty thirty"), "twenty thirty");
        assert_eq!(prose("ten five"), "ten five");
    }

    #[test]
    fn valid_compositions_still_parse() {
        assert_eq!(prose("twenty three"), "23");
        assert_eq!(prose("five hundred thirty"), "530");
        assert_eq!(prose("two thousand twenty five"), "2,025");
        assert_eq!(prose("one hundred and five"), "105");
    }

    #[test]
    fn approximations_without_number_words_are_untouched() {
        // "a couple of" and "a dozen" contain no number word at all, so the
        // rule never sees them. Pinned so a future unit addition cannot
        // change that silently.
        assert_eq!(prose("a couple of things"), "a couple of things");
        assert_eq!(prose("a dozen eggs"), "a dozen eggs");
    }

    // ---- Invariants -----------------------------------------------------

    #[test]
    fn normalization_is_idempotent() {
        let cases = [
            "my number is five two nine",
            "twenty three people",
            "a thousand dollars",
            "one or two things",
            "page one",
            "extension five nine",
            "five thirty",
            "fifty percent",
            "two thousand twenty five",
            "no one knows",
        ];
        for case in cases {
            let once = prose(case);
            let twice = prose(&once);
            assert_eq!(once, twice, "not idempotent for {case:?}");
        }
    }

    #[test]
    fn empty_and_punctuation_only_are_safe() {
        assert_eq!(prose(""), "");
        assert_eq!(prose("... , !"), "... , !");
    }
}
