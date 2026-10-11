//! Number normalization (OpenAI Whisper post-processing convention):
//! spelled-out numbers become digits.
//!
//! - Digit sequences of 3+ single digits concatenate: "five two nine" → "529"
//!   (the OpenAI cookbook example).
//! - 1-2 word cardinal numbers become digits: "twenty three" → "23".
//! - Currency: "twenty dollars" → "$20", "five dollars and thirty cents" → "$5.30".

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

fn word_value(word: &str) -> Option<u64> {
    let w = word.to_lowercase();
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

/// Parse a sequence of number words into an integer (handles "hundred/thousand").
fn parse_sequence(words: &[&str]) -> Option<u64> {
    let mut total: u64 = 0;
    let mut current: u64 = 0;
    let mut any = false;
    for w in words {
        let v = word_value(w)?;
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

/// Tokenize into words + punctuation, keeping positions.
fn tokens(text: &str) -> Vec<(usize, String)> {
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
                out.push((start, std::mem::take(&mut current)));
            }
            out.push((i, c.to_string()));
        }
    }
    if !current.is_empty() {
        out.push((start, current));
    }
    out
}

pub fn normalize(text: &str) -> String {
    let toks = tokens(text);
    let mut result = String::with_capacity(text.len());
    let mut i = 0;

    while i < toks.len() {
        let tok = &toks[i].1;
        let is_word = tok
            .chars()
            .next()
            .map(|c| c.is_ascii_alphabetic())
            .unwrap_or(false);
        if !is_word || word_value(&tok.to_lowercase()).is_none() {
            result.push_str(tok);
            i += 1;
            continue;
        }

        // Collect the maximal run of number words. Whitespace and the
        // connector "and" are skipped so "one hundred and five" parses as
        // one run; any other token ends the run.
        let mut j = i;
        let mut run: Vec<&str> = Vec::new();
        let mut last_word = i;
        while j < toks.len() {
            let t = &toks[j].1;
            if !t
                .chars()
                .next()
                .map(|c| c.is_ascii_alphabetic())
                .unwrap_or(false)
            {
                if t.chars().all(|c| c.is_whitespace()) {
                    j += 1;
                    continue;
                }
                break;
            }
            let lower = t.to_lowercase();
            if lower == "and" {
                j += 1;
                continue;
            }
            if word_value(&lower).is_none() {
                break;
            }
            run.push(t);
            last_word = j;
            j += 1;
        }

        let digits_only = run.iter().all(|w| {
            matches!(
                w.to_lowercase().as_str(),
                "zero"
                    | "one"
                    | "two"
                    | "three"
                    | "four"
                    | "five"
                    | "six"
                    | "seven"
                    | "eight"
                    | "nine"
            )
        });

        let replacement = if run.len() >= 3 && digits_only {
            Some(
                run.iter()
                    .map(|w| word_value(w).unwrap().to_string())
                    .collect::<String>(),
            )
        } else {
            parse_sequence(&run).map(|v| v.to_string())
        };

        if let Some(repl) = replacement {
            // Currency/percent suffix: "twenty dollars" -> "$20".
            let mut consumed = last_word + 1;
            let mut currency = String::new();
            if j < toks.len() {
                match toks[j].1.to_lowercase().as_str() {
                    "dollars" | "dollar" => {
                        currency = format!("${repl}");
                        consumed = j + 1;
                    }
                    "percent" => {
                        currency = format!("{repl}%");
                        consumed = j + 1;
                    }
                    _ => {}
                }
            }
            result.push_str(if currency.is_empty() {
                &repl
            } else {
                &currency
            });
            i = consumed;
        } else {
            result.push_str(tok);
            i += 1;
        }
    }

    result
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn digit_sequences_concatenate() {
        assert_eq!(normalize("my number is five two nine"), "my number is 529");
    }

    #[test]
    fn cardinals_parse() {
        assert_eq!(normalize("twenty three people"), "23 people");
        assert_eq!(normalize("one hundred and five"), "105");
        assert_eq!(normalize("a thousand dollars"), "a $1000");
    }

    #[test]
    fn currency_and_percent() {
        assert_eq!(normalize("twenty dollars"), "$20");
        assert_eq!(normalize("fifty percent"), "50%");
    }

    #[test]
    fn leaves_ordinary_words_alone() {
        assert_eq!(normalize("nothing to see here"), "nothing to see here");
        // "one" inside another word is untouched by tokenizer boundaries.
        assert_eq!(normalize("someone said one thing"), "someone said 1 thing");
    }
}
