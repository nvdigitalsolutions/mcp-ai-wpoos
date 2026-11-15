//! Deterministic chunking for the memory corpus (Proposal 127).
//!
//! Splits captured text into searchable units: line/paragraph breaks first,
//! then sentence boundaries, then hard character windows. Targets the shape
//! embedding models are trained on (a few hundred chars per chunk for a
//! 512-token model). Pure and dependency-free so the behavior is fully
//! unit-testable and never varies by platform.

/// Hard cap per chunk, in characters. Sentences beyond this are split at
/// word boundaries; sentences below it merge until the cap.
const MAX_CHUNK_CHARS: usize = 600;

/// Split `text` into memory chunks. Deterministic; returns nothing for
/// whitespace-only input.
pub fn chunk_text(text: &str) -> Vec<String> {
    let mut out = Vec::new();
    let normalized = text.replace("\r\n", "\n").replace('\r', "\n");
    for line in normalized.split('\n') {
        let line = line.trim();
        if line.is_empty() {
            continue;
        }
        push_chunk(&mut out, line);
    }
    out
}

/// Append one logical block (a trimmed line/paragraph) to the chunk list,
/// splitting on sentence boundaries and merging until the cap.
fn push_chunk(out: &mut Vec<String>, block: &str) {
    let mut current = String::new();
    for sentence in split_sentences(block) {
        for piece in hard_split(&sentence) {
            if current.is_empty() {
                current = piece;
            } else if current.chars().count() + 1 + piece.chars().count() <= MAX_CHUNK_CHARS {
                current.push(' ');
                current.push_str(&piece);
            } else {
                flush(out, &mut current);
                current = piece;
            }
        }
    }
    flush(out, &mut current);
}

fn flush(out: &mut Vec<String>, current: &mut String) {
    if !current.is_empty() {
        out.push(current.trim().to_string());
        current.clear();
    }
}

/// Split on sentence-ending ASCII punctuation followed by whitespace/EOF.
/// Byte-wise scanning is safe here: ASCII bytes never appear inside a
/// multi-byte UTF-8 sequence, so every cut lands on a char boundary.
/// Abbreviation awareness is deliberately absent (v1): a split after
/// "e.g." degrades one chunk, never correctness.
fn split_sentences(text: &str) -> Vec<String> {
    let mut sentences = Vec::new();
    let bytes = text.as_bytes();
    let mut start = 0usize;
    let mut i = 0usize;
    while i < bytes.len() {
        let is_end = matches!(bytes[i], b'.' | b'!' | b'?' | b';')
            && bytes
                .get(i + 1)
                .is_none_or(|n| *n == b' ' || *n == b'\n' || *n == b'\t');
        if is_end {
            // Consume a trailing ellipsis run ("...") as one boundary so
            // the next sentence does not start with stray dots.
            while i + 1 < bytes.len() && bytes[i + 1] == b'.' {
                i += 1;
            }
            sentences.push(text[start..=i].trim().to_string());
            // Skip the whitespace run after the boundary so the next
            // sentence never starts (or merges) with a leading space.
            i += 1;
            while i < bytes.len() && (bytes[i] == b' ' || bytes[i] == b'\t') {
                i += 1;
            }
            start = i;
            continue;
        }
        i += 1;
    }
    if start < text.len() {
        sentences.push(text[start..].trim().to_string());
    }
    sentences
}

/// Split an over-long sentence into pieces of at most `MAX_CHUNK_CHARS`
/// chars, preferring word boundaries and falling back to character cuts
/// inside a single over-long word (no boundary would ever be found
/// otherwise).
fn hard_split(sentence: &str) -> Vec<String> {
    if sentence.chars().count() <= MAX_CHUNK_CHARS {
        return vec![sentence.to_string()];
    }
    let mut pieces = Vec::new();
    let mut current = String::new();
    for word in sentence.split_whitespace() {
        for piece in split_long_word(word) {
            if current.is_empty() {
                current = piece;
            } else if current.chars().count() + 1 + piece.chars().count() <= MAX_CHUNK_CHARS {
                current.push(' ');
                current.push_str(&piece);
            } else {
                pieces.push(std::mem::take(&mut current));
                current = piece;
            }
        }
    }
    if !current.is_empty() {
        pieces.push(current);
    }
    pieces
}

/// A single word longer than the cap: cut it at character intervals.
fn split_long_word(word: &str) -> Vec<String> {
    if word.chars().count() <= MAX_CHUNK_CHARS {
        return vec![word.to_string()];
    }
    let mut out = Vec::new();
    let mut piece = String::new();
    for c in word.chars() {
        piece.push(c);
        if piece.chars().count() >= MAX_CHUNK_CHARS {
            out.push(std::mem::take(&mut piece));
        }
    }
    if !piece.is_empty() {
        out.push(piece);
    }
    out
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn splits_sentences_and_merges_until_cap() {
        let chunks = chunk_text("First sentence here. Second sentence follows. Third one too.");
        assert_eq!(
            chunks,
            vec!["First sentence here. Second sentence follows. Third one too.".to_string()]
        );
    }

    #[test]
    fn splits_on_line_breaks() {
        let chunks = chunk_text("Alpha beta gamma.\nDelta epsilon.");
        assert_eq!(chunks.len(), 2);
        assert_eq!(chunks[0], "Alpha beta gamma.");
        assert_eq!(chunks[1], "Delta epsilon.");
    }

    #[test]
    fn cap_forces_new_chunks() {
        let long = "A".repeat(700);
        let mut text = long.clone();
        text.push_str(" then tail.");
        let chunks = chunk_text(&text);
        assert_eq!(chunks.len(), 2);
        assert!(chunks[0].chars().count() <= MAX_CHUNK_CHARS);
        assert!(chunks[0].starts_with('A'));
        assert!(chunks[1].contains("then tail"));
    }

    #[test]
    fn empty_and_whitespace_input_yields_nothing() {
        assert!(chunk_text("").is_empty());
        assert!(chunk_text("   \n\t  ").is_empty());
    }

    #[test]
    fn multi_byte_text_survives_intact() {
        // A Japanese sentence (no ASCII punctuation inside) must not be
        // mangled by the byte-wise boundary scan.
        let chunks = chunk_text("こんにちは世界。また明日。");
        assert_eq!(chunks.len(), 1);
        assert_eq!(chunks[0], "こんにちは世界。また明日。");
    }

    #[test]
    fn ellipsis_run_is_one_boundary() {
        // The ellipsis is one sentence boundary; the follow-up sentence
        // merges into the same chunk (single-space join — no double space
        // from the skipped whitespace run).
        let chunks = chunk_text("One thought... and another.");
        assert_eq!(chunks, vec!["One thought... and another.".to_string()]);
    }
}
