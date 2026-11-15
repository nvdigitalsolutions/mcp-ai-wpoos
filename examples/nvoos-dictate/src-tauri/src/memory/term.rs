//! Query-side tokenizer + FTS5 query builder (Proposal 127).
//!
//! One tokenizer for index and question (SurfSense ADR 0032): the index side
//! is FTS5's `unicode61` (splits on non-alphanumerics, case-folds); this side
//! mirrors it closely enough for the keyword leg. Known v1 gaps, accepted:
//! - unicode61 strips diacritics by default on recent SQLite; this splitter
//!   keeps them, so a diacritic-bearing term misses on the keyword leg (the
//!   vector leg still finds the chunk).
//! - CJK scripts are one unicode61 token on the index side and one term here,
//!   so the keyword leg finds nothing in them — the documented CJK gap.

/// Split a query into distinct terms, lowercased, in first-seen order.
pub fn split_terms(query: &str) -> Vec<String> {
    let mut terms = Vec::new();
    let mut current = String::new();
    for c in query.chars() {
        if c.is_alphanumeric() {
            current.extend(c.to_lowercase());
        } else if !current.is_empty() {
            terms.push(std::mem::take(&mut current));
        }
    }
    if !current.is_empty() {
        terms.push(current);
    }
    let mut seen = std::collections::HashSet::new();
    terms.retain(|t| seen.insert(t.clone()));
    terms
}

/// Build the FTS5 MATCH expression: each term quoted (embedded `"` doubled)
/// and OR-joined to keep recall wide. `None` for an empty term list.
pub fn fts_query(terms: &[String]) -> Option<String> {
    if terms.is_empty() {
        return None;
    }
    let quoted = terms
        .iter()
        .map(|t| format!("\"{}\"", t.replace('"', "\"\"")))
        .collect::<Vec<_>>();
    Some(quoted.join(" OR "))
}

/// Fraction of the query's distinct terms present in `text` (0.0–1.0) —
/// the keyword-leg score that keeps a weak BM25 match honest (SurfSense
/// ADR 0031: a chunk matching one term of five contributes little whatever
/// BM25 says).
pub fn term_fraction(terms: &[String], text: &str) -> f32 {
    if terms.is_empty() {
        return 0.0;
    }
    let haystack = split_terms(text);
    let matched = terms.iter().filter(|t| haystack.contains(t)).count();
    matched as f32 / terms.len() as f32
}

/// Render a surface-scope filter for SQL. Surfaces come from a fixed,
/// closed set (`transcript` | `meeting` | `snippet`) — the quoting is
/// defense in depth, not the primary safety boundary.
pub fn surface_filter(surfaces: &[&str]) -> String {
    if surfaces.is_empty() {
        return "1".to_string();
    }
    let quoted = surfaces
        .iter()
        .map(|s| format!("'{}'", s.replace('\'', "''")))
        .collect::<Vec<_>>()
        .join(",");
    format!("surface IN ({quoted})")
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn splits_lowercases_and_dedupes() {
        assert_eq!(
            split_terms("The QUICK quick, brown!"),
            vec!["the".to_string(), "quick".to_string(), "brown".to_string()]
        );
    }

    #[test]
    fn empty_query_has_no_terms() {
        assert!(split_terms("   …!? ").is_empty());
    }

    #[test]
    fn fts_query_quotes_and_ors() {
        let q = fts_query(&["brown".into(), "fox".into()]).unwrap();
        assert_eq!(q, "\"brown\" OR \"fox\"");
    }

    #[test]
    fn fts_query_doubles_embedded_quotes() {
        let q = fts_query(&["he\"llo".into()]).unwrap();
        assert_eq!(q, "\"he\"\"llo\"");
    }

    #[test]
    fn fts_query_empty_is_none() {
        assert!(fts_query(&[]).is_none());
    }

    #[test]
    fn term_fraction_scores_matches() {
        let terms = split_terms("brown fox");
        assert!((term_fraction(&terms, "the quick brown fox") - 1.0).abs() < f32::EPSILON);
        assert!((term_fraction(&terms, "the quick brown dog") - 0.5).abs() < f32::EPSILON);
        assert_eq!(term_fraction(&terms, "nothing here"), 0.0);
        assert_eq!(term_fraction(&[], "anything"), 0.0);
    }

    #[test]
    fn surface_filter_quotes_and_scopes() {
        assert_eq!(surface_filter(&["transcript"]), "surface IN ('transcript')");
        assert_eq!(
            surface_filter(&["transcript", "meeting"]),
            "surface IN ('transcript','meeting')"
        );
        assert_eq!(surface_filter(&[]), "1");
    }
}
