//! Whitespace normalization: collapse runs, fix space-before-punctuation,
//! trim. Runs for both raw and cleaned output.

/// Normalize whitespace and punctuation spacing. Pure + idempotent.
pub fn normalize(text: &str) -> String {
    let mut out = text.split_whitespace().collect::<Vec<_>>().join(" ");

    // Fix spacing around punctuation produced by ASR: "hello ,world ." → "hello, world."
    let mut fixed = String::with_capacity(out.len());
    let chars: Vec<char> = out.chars().collect();
    let mut i = 0;
    while i < chars.len() {
        let c = chars[i];
        // Space before closing punctuation.
        if c == ' '
            && i + 1 < chars.len()
            && matches!(chars[i + 1], ',' | '.' | '!' | '?' | ';' | ':' | ')')
        {
            i += 1; // drop the space
            continue;
        }
        // Space after opening punctuation.
        if matches!(c, '(' | '[') && i + 1 < chars.len() && chars[i + 1] == ' ' {
            fixed.push(c);
            i += 2; // drop the space after
            continue;
        }
        fixed.push(c);
        // Missing space after punctuation: "hello,world" → "hello, world".
        // Deliberately excludes ':' and '.' ("Bar::new()", "3.14").
        if matches!(c, ',' | ';' | '!' | '?')
            && i + 1 < chars.len()
            && chars[i + 1].is_alphanumeric()
        {
            fixed.push(' ');
        }
        i += 1;
    }
    out = fixed.trim().to_string();
    out
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn collapses_runs_and_trims() {
        assert_eq!(normalize("  hello   world  "), "hello world");
        assert_eq!(normalize("a\n\tb  c"), "a b c");
    }

    #[test]
    fn fixes_punctuation_spacing() {
        assert_eq!(normalize("hello ,world ."), "hello, world.");
        assert_eq!(normalize("it ( mostly ) works"), "it (mostly) works");
    }
}
