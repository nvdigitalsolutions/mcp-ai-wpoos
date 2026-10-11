//! Capitalization: sentence starts + the standalone pronoun "i".
//!
//! Code category is exempt — identifiers must never be rewritten.

use crate::config::AppCategory;

pub fn apply(text: &str, category: AppCategory) -> String {
    if category == AppCategory::Code || text.is_empty() {
        return text.to_string();
    }

    let chars: Vec<char> = text.chars().collect();
    let mut out = String::with_capacity(text.len());
    let mut at_sentence_start = true;

    let mut i = 0;
    while i < chars.len() {
        let c = chars[i];
        if at_sentence_start && c.is_ascii_alphabetic() {
            out.push(c.to_ascii_uppercase());
            at_sentence_start = false;
            i += 1;
            continue;
        }
        // Standalone "i" -> "I" (pronoun), including contractions i'm / i've.
        if c == 'i'
            && (i == 0 || !chars[i - 1].is_alphanumeric())
            && (i + 1 >= chars.len()
                || !chars[i + 1].is_ascii_alphabetic()
                || matches!(chars[i + 1], '\''))
        {
            out.push('I');
            i += 1;
            continue;
        }
        if matches!(c, '.' | '!' | '?') {
            out.push(c);
            at_sentence_start = true;
            i += 1;
            continue;
        }
        out.push(c);
        if !c.is_whitespace() {
            at_sentence_start = false;
        }
        i += 1;
    }
    out
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn capitalizes_sentence_starts() {
        assert_eq!(
            apply("hello world. this is a test", AppCategory::Default),
            "Hello world. This is a test"
        );
    }

    #[test]
    fn capitalizes_pronoun_i() {
        assert_eq!(
            apply("i think i'm right", AppCategory::Default),
            "I think I'm right"
        );
    }

    #[test]
    fn code_category_untouched() {
        let input = "let foo = Bar::new(); i is a var";
        assert_eq!(apply(input, AppCategory::Code), input);
    }
}
