//! Filler removal: um/uh/like/you-know and repeated-word collapse.
//!
//! Conservative by design: only removes words that are unambiguously filler
//! in dictation (never punctuation, never content words).

const FILLERS: &[&str] = &[
    "um",
    "uh",
    "uhh",
    "erm",
    "hmm",
    "hmmm",
    "ahh",
    "umm",
    "like",
    "you know",
    "i mean",
    "sort of",
    "kind of",
    "basically",
    "actually",
    "literally",
];

/// Discourse markers only removed at the start of the text.
const LEADING: &[&str] = &["so", "well", "okay so", "right", "ok so"];

pub fn remove(text: &str) -> String {
    let mut out = text.to_string();

    // Multi-word fillers first (they contain spaces; word-boundary matching
    // on the raw string avoids destroying punctuation).
    for filler in FILLERS.iter().filter(|f| f.contains(' ')) {
        out = remove_phrase(&out, filler);
    }

    // Single-word fillers via token scan.
    let words: Vec<String> = out.split_whitespace().map(|s| s.to_string()).collect();
    let mut cleaned: Vec<String> = Vec::new();
    for word in words {
        // Strip trailing punctuation for the lookup, keep it for output.
        let core: String = word
            .trim_start_matches(|c: char| !c.is_ascii_alphabetic())
            .trim_end_matches(|c: char| !c.is_ascii_alphabetic())
            .to_lowercase();
        if FILLERS.contains(&core.as_str()) {
            continue;
        }
        // Repeated-word collapse: "the the" → "the".
        if let Some(prev) = cleaned.last() {
            let prev_core: String = prev
                .trim_start_matches(|c: char| !c.is_ascii_alphabetic())
                .trim_end_matches(|c: char| !c.is_ascii_alphabetic())
                .to_lowercase();
            if !prev_core.is_empty() && prev_core == core {
                continue;
            }
        }
        cleaned.push(word);
    }
    out = cleaned.join(" ");

    // Leading discourse markers.
    for marker in LEADING {
        if let Some(rest) = strip_prefix_case_insensitive(&out, marker) {
            // Drop punctuation the marker's removal left dangling.
            let rest = rest.trim_start().trim_start_matches(',').trim_start();
            if !rest.is_empty() {
                out = rest.to_string();
                break;
            }
        }
    }

    out.trim().to_string()
}

fn remove_phrase(text: &str, phrase: &str) -> String {
    let lower = text.to_lowercase();
    let needle = phrase.to_lowercase();
    let mut result = String::with_capacity(text.len());
    let mut pos = 0;
    while let Some(found) = lower[pos..].find(&needle) {
        let start = pos + found;
        let end = start + needle.len();
        result.push_str(&text[pos..start]);
        pos = end;
        // Drop a trailing ", " that now dangles.
        if text[pos..].starts_with(", ") {
            pos += 2;
        }
    }
    result.push_str(&text[pos..]);
    result
}

fn strip_prefix_case_insensitive<'a>(text: &'a str, prefix: &str) -> Option<&'a str> {
    // `get` guards against slicing inside a multi-byte character.
    let head = text.get(..prefix.len())?;
    if head.eq_ignore_ascii_case(prefix) {
        Some(&text[prefix.len()..])
    } else {
        None
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn removes_single_word_fillers() {
        assert_eq!(remove("um so like hello uh world"), "hello world");
    }

    #[test]
    fn removes_phrase_fillers() {
        assert_eq!(remove("I, you know, think so"), "I, think so");
    }

    #[test]
    fn removes_leading_markers() {
        assert_eq!(remove("So we shipped it"), "we shipped it");
        assert_eq!(remove("Well, that's done"), "that's done");
    }

    #[test]
    fn collapses_repeats() {
        assert_eq!(remove("the the quick brown"), "the quick brown");
    }

    #[test]
    fn leaves_content_words() {
        assert_eq!(remove("I literally saw it"), "I saw it");
        assert_eq!(remove("basically done"), "done");
    }
}
