//! Vocabulary substitution: user dictionary terms are replaced on word
//! boundaries, case-insensitively, longest-term-first so "new york city"
//! wins over "york".

use std::collections::HashMap;

/// Substitute dictionary terms into `text`.
pub fn substitute(text: &str, dictionary: &[(String, String)]) -> String {
    if dictionary.is_empty() || text.is_empty() {
        return text.to_string();
    }

    // Lowercased term -> (original term, replacement); longest first for
    // greedy multi-word matching.
    let mut terms: Vec<(&str, &str)> = dictionary
        .iter()
        .map(|(t, r)| (t.as_str(), r.as_str()))
        .collect();
    terms.sort_by_key(|t| std::cmp::Reverse(t.0.len()));

    // Map lowercased term -> replacement for lookup.
    let mut lookup: HashMap<String, &str> = HashMap::new();
    for (term, replacement) in &terms {
        lookup.entry(term.to_lowercase()).or_insert(replacement);
    }

    // Word-boundary scan: at each position, try the longest matching term.
    let lower = text.to_lowercase();
    let chars: Vec<char> = text.chars().collect();
    let lower_chars: Vec<char> = lower.chars().collect();
    let mut out = String::with_capacity(text.len());
    let mut i = 0;
    while i < chars.len() {
        let mut matched = false;
        for &(term, _) in &terms {
            let term_chars: Vec<char> = term.to_lowercase().chars().collect();
            if i + term_chars.len() > chars.len() {
                continue;
            }
            // Compare case-insensitively.
            let slice = &lower_chars[i..i + term_chars.len()];
            if slice != term_chars.as_slice() {
                continue;
            }
            // Word boundaries: previous and next chars must not be alphanumeric
            // (except when matching at edges).
            let prev_ok = i == 0 || !chars[i - 1].is_alphanumeric();
            let next_idx = i + term_chars.len();
            let next_ok = next_idx >= chars.len() || !chars[next_idx].is_alphanumeric();
            if prev_ok && next_ok {
                let replacement = lookup[&term.to_lowercase()];
                out.push_str(replacement);
                i += term_chars.len();
                matched = true;
                break;
            }
        }
        if !matched {
            out.push(chars[i]);
            i += 1;
        }
    }
    out
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn substitutes_word_boundaries() {
        let dict = vec![
            ("myco".to_string(), "MyCo".to_string()),
            ("aerlinn".to_string(), "Aerlinn".to_string()),
        ];
        assert_eq!(substitute("welcome to myco", &dict), "welcome to MyCo");
        // No match inside another word.
        assert_eq!(substitute("mycompany", &dict), "mycompany");
    }

    #[test]
    fn longest_term_wins() {
        let dict = vec![
            ("york".to_string(), "York".to_string()),
            ("new york city".to_string(), "NYC".to_string()),
        ];
        assert_eq!(substitute("fly to new york city", &dict), "fly to NYC");
    }
}
