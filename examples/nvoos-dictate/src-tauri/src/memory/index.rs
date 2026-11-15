//! Hybrid retrieval: FTS5 keyword leg + vec0 vector leg, blended
//! (Proposal 127 — SurfSense ADRs 0006/0031/0033 adapted to Rust).
//!
//! `score = w · sim + (1 − w) · kw` over the union of both legs, where
//! `sim` is the candidate's own cosine similarity (floored at zero — ADR
//! 0033: measured here, never inherited from the vector leg's top-N) and
//! `kw` is the fraction of the query's distinct terms the chunk contains
//! (ADR 0031: a chunk matching one term of five is weak whatever BM25
//! says). The legs are asymmetric on purpose: a chunk the keyword leg
//! omits matched no terms (zero is a measurement); a chunk the vector leg
//! omits merely sat outside its nearest 20.

use std::collections::HashMap;

use crate::error::AppResult;
use crate::storage::Db;

use super::embed::{normalize, Embedder};
use super::term;
use super::MemoryHit;

/// The surfaces the corpus knows about (closed set — the scope filter is
/// rendered from these and only these).
pub const SURFACES: [&str; 3] = ["transcript", "meeting", "snippet"];

/// Full retrieval: tokenize → embed the query → blend. The keyword leg
/// still runs when `embedder` is `None` (embedder missing / feature off —
/// the failure-soft rule); a blank query returns nothing before anything
/// is embedded.
pub fn retrieve(
    db: &Db,
    embedder: Option<&mut dyn Embedder>,
    query: &str,
    semantic_weight: f32,
    top_k: usize,
    surfaces: &[&str],
) -> AppResult<Vec<MemoryHit>> {
    let terms = term::split_terms(query);
    if terms.is_empty() {
        return Ok(Vec::new());
    }
    let query_vector = match embedder {
        Some(e) => e
            .embed_batch(&[query.to_string()])?
            .pop()
            .filter(|v| v.iter().any(|x| *x != 0.0)),
        None => None,
    };
    retrieve_with_vector(
        db,
        &terms,
        query_vector.as_deref(),
        semantic_weight,
        top_k,
        surfaces,
    )
}

/// Retrieval against a precomputed query vector — the seam the tests use
/// to control semantics exactly (and the ask path uses after embedding
/// the question once).
pub fn retrieve_with_vector(
    db: &Db,
    terms: &[String],
    query_vector: Option<&[f32]>,
    semantic_weight: f32,
    top_k: usize,
    surfaces: &[&str],
) -> AppResult<Vec<MemoryHit>> {
    if terms.is_empty() {
        return Ok(Vec::new());
    }
    let w = semantic_weight.clamp(0.0, 1.0);

    // Per-candidate accumulators: (kw_score, sim_score).
    let mut candidates: HashMap<i64, (f32, f32)> = HashMap::new();

    // Keyword leg.
    if let Some(query) = term::fts_query(terms) {
        for (id, text) in db.fts_search(&query, surfaces, 20)? {
            let kw = term::term_fraction(terms, &text);
            let entry = candidates.entry(id).or_insert((0.0, 0.0));
            entry.0 = kw;
        }
    }

    // Vector leg.
    if let Some(vector) = query_vector {
        for (id, distance) in db.vector_search(vector, surfaces, 20)? {
            let sim = (1.0 - distance).max(0.0);
            let entry = candidates.entry(id).or_insert((0.0, 0.0));
            entry.1 = sim;
        }
    }

    if candidates.is_empty() {
        return Ok(Vec::new());
    }

    // Blend and rank. Tie-break on chunk id so results are deterministic.
    let mut scored: Vec<(i64, f32, f32, f32)> = candidates
        .into_iter()
        .map(|(id, (kw, sim))| {
            let score = w * sim + (1.0 - w) * kw;
            (id, score, kw, sim)
        })
        .collect();
    scored.sort_by(|a, b| {
        b.1.partial_cmp(&a.1)
            .unwrap_or(std::cmp::Ordering::Equal)
            .then(a.0.cmp(&b.0))
    });
    scored.truncate(top_k);

    // Hydrate the survivors into full hits (ids only — the blend already
    // decided the order).
    let ids: Vec<i64> = scored.iter().map(|(id, ..)| *id).collect();
    let rows = db.chunk_rows(&ids)?;
    let mut by_id: HashMap<i64, (String, i64, i64, String)> = rows
        .into_iter()
        .map(|(id, surface, surface_id, seq, text)| (id, (surface, surface_id, seq, text)))
        .collect();
    let total_terms = terms.len();
    Ok(scored
        .into_iter()
        .filter_map(|(id, score, kw, _sim)| {
            by_id
                .remove(&id)
                .map(|(surface, surface_id, seq, text)| MemoryHit {
                    chunk_id: id,
                    surface,
                    surface_id,
                    seq,
                    text,
                    score,
                    matched_terms: (kw * total_terms as f32).round() as usize,
                })
        })
        .collect())
}

/// Embed a query against an embedder, normalizing the result (the same
/// post-step the batch path applies). Convenience for the controller.
pub fn embed_query(embedder: &mut dyn Embedder, query: &str) -> AppResult<Option<Vec<f32>>> {
    let mut vector = embedder
        .embed_batch(&[query.to_string()])?
        .pop()
        .unwrap_or_default();
    if vector.iter().all(|x| *x == 0.0) {
        return Ok(None);
    }
    normalize(&mut vector);
    Ok(Some(vector))
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::memory::chunker;

    /// Seed a memory DB with chunks whose vectors the test controls.
    fn seed(db: &Db) {
        // Three chunks; chunk 1 and 2 share vocabulary, chunk 3 is unrelated.
        db.insert_chunks_raw("transcript", 1, &["the quick brown fox".to_string()])
            .unwrap();
        db.insert_chunks_raw("transcript", 2, &["a slow brown dog".to_string()])
            .unwrap();
        db.insert_chunks_raw("transcript", 3, &["zebra moon boots".to_string()])
            .unwrap();
        let fox = crate::memory::embed::mock_vector("the quick brown fox", 384);
        let dog = crate::memory::embed::mock_vector("a slow brown dog", 384);
        let zebra = crate::memory::embed::mock_vector("zebra moon boots", 384);
        db.store_vectors(&[(1, fox), (2, dog), (3, zebra)]).unwrap();
    }

    #[test]
    fn blank_query_returns_nothing() {
        let db = Db::open_in_memory().unwrap();
        seed(&db);
        let hits = retrieve_with_vector(&db, &[], None, 0.85, 5, &SURFACES).unwrap();
        assert!(hits.is_empty());
    }

    #[test]
    fn keyword_leg_finds_the_answering_chunk() {
        let db = Db::open_in_memory().unwrap();
        seed(&db);
        let terms = term::split_terms("brown fox");
        let hits = retrieve_with_vector(&db, &terms, None, 0.0, 5, &SURFACES).unwrap();
        assert!(!hits.is_empty());
        // Both fox and dog chunks contain "brown"; fox matches both terms.
        let fox = hits.iter().find(|h| h.chunk_id == 1).unwrap();
        let dog = hits.iter().find(|h| h.chunk_id == 2).unwrap();
        assert_eq!(fox.matched_terms, 2);
        assert_eq!(dog.matched_terms, 1);
        assert!(fox.score > dog.score);
        // The unrelated chunk never surfaces.
        assert!(hits.iter().all(|h| h.chunk_id != 3));
    }

    #[test]
    fn vector_leg_finds_by_meaning_without_shared_terms() {
        let db = Db::open_in_memory().unwrap();
        seed(&db);
        // Query vector = chunk 1's vector; the query shares NO words with
        // any chunk ("paraphrase" case — the vector leg is the only path).
        let terms = term::split_terms("paraphrase");
        let query = crate::memory::embed::mock_vector("the quick brown fox", 384);
        let hits = retrieve_with_vector(&db, &terms, Some(&query), 1.0, 5, &SURFACES).unwrap();
        assert_eq!(hits[0].chunk_id, 1);
    }

    #[test]
    fn blend_interpolates_between_legs() {
        let db = Db::open_in_memory().unwrap();
        seed(&db);
        let terms = term::split_terms("brown fox");
        let query = crate::memory::embed::mock_vector("zebra moon boots", 384);
        let hits = retrieve_with_vector(&db, &terms, Some(&query), 0.5, 5, &SURFACES).unwrap();
        // w=0.5: chunk 2 (kw=0.5, sim high via unrelated vector? no — its
        // sim is its own cosine to the query, small) — the point is that
        // the order is deterministic and both legs contributed.
        let fox = hits.iter().find(|h| h.chunk_id == 1).unwrap();
        assert!(fox.score > 0.0);
        assert_eq!(hits.len(), 3);
    }

    #[test]
    fn scope_filter_keeps_other_surfaces_out() {
        let db = Db::open_in_memory().unwrap();
        seed(&db);
        // A meeting chunk holding the only occurrence of a rare term.
        db.insert_chunks_raw("meeting", 9, &["the brown fox meeting note".to_string()])
            .unwrap();
        db.store_vectors(&[(9, crate::memory::embed::mock_vector("meeting note", 384))])
            .unwrap();
        let terms = term::split_terms("fox");
        let hits = retrieve_with_vector(&db, &terms, None, 0.0, 10, &["transcript"]).unwrap();
        assert!(hits.iter().all(|h| h.surface == "transcript"));
        assert!(hits.iter().any(|h| h.chunk_id == 1));
        assert!(hits.iter().all(|h| h.chunk_id != 9));
    }

    #[test]
    fn vector_leg_scope_filter_runs_before_k() {
        let db = Db::open_in_memory().unwrap();
        // Fill one surface with many vectors; keep one chunk in the
        // requested scope: only the scoped chunk may come back, whatever
        // the unscoped neighborhood looks like.
        let query = crate::memory::embed::mock_vector("needle", 384);
        for i in 0..25 {
            let text = format!("other surface chunk {i}");
            db.insert_chunks_raw("meeting", 100 + i, std::slice::from_ref(&text))
                .unwrap();
        }
        let pending = db.pending_chunks(100).unwrap();
        let vectors: Vec<(i64, Vec<f32>)> = pending
            .iter()
            .map(|(id, text)| (*id, crate::memory::embed::mock_vector(text, 384)))
            .collect();
        db.store_vectors(&vectors).unwrap();

        db.insert_chunks_raw("transcript", 1, &["needle in a haystack".to_string()])
            .unwrap();
        let pending = db.pending_chunks(10).unwrap();
        assert_eq!(pending.len(), 1);
        let needle_id = pending[0].0;
        db.store_vectors(&[(needle_id, query.clone())]).unwrap();

        let terms = term::split_terms("needle");
        let hits =
            retrieve_with_vector(&db, &terms, Some(&query), 1.0, 5, &["transcript"]).unwrap();
        assert_eq!(
            hits.len(),
            1,
            "the scope filter must apply inside the KNN query"
        );
        assert_eq!(hits[0].chunk_id, needle_id);
        assert_eq!(hits[0].text, "needle in a haystack");
    }

    #[test]
    fn deleting_a_chunk_removes_its_vector_and_fts_row() {
        let db = Db::open_in_memory().unwrap();
        seed(&db);
        db.delete_chunks_for("transcript", 1).unwrap();
        // Directly inspect the legs: neither index may hold chunk 1.
        let fts = db.fts_search("\"fox\"", &SURFACES, 10).unwrap();
        assert!(fts.iter().all(|(id, _)| *id != 1));
        let (total, embedded, _) = db.memory_counts().unwrap();
        assert_eq!(total, 2);
        assert_eq!(embedded, 2);
    }

    #[test]
    fn pending_and_store_roundtrip() {
        let db = Db::open_in_memory().unwrap();
        db.insert_chunks_raw("snippet", 7, &["fresh text".to_string()])
            .unwrap();
        let pending = db.pending_chunks(10).unwrap();
        assert_eq!(pending.len(), 1);
        assert_eq!(pending[0].1, "fresh text");
        let vector = crate::memory::embed::mock_vector("fresh text", 384);
        db.store_vectors(&[(pending[0].0, vector)]).unwrap();
        assert!(db.pending_chunks(10).unwrap().is_empty());
        let (total, embedded, pend) = db.memory_counts().unwrap();
        assert_eq!((total, embedded, pend), (1, 1, 0));
    }

    #[test]
    fn chunker_text_roundtrips_through_retrieval() {
        // End-to-end (mock): the chunker's output is what gets indexed and
        // searched — a sentence inside a longer dictation must be findable.
        let db = Db::open_in_memory().unwrap();
        let chunks = chunker::chunk_text(
            "Discussed the WebSocket fix today. Also approved the marketing budget.",
        );
        db.insert_chunks_raw("transcript", 5, &chunks).unwrap();
        let terms = term::split_terms("marketing budget");
        let hits = retrieve_with_vector(&db, &terms, None, 0.0, 5, &SURFACES).unwrap();
        assert!(hits.iter().any(|h| h.text.contains("marketing budget")));
    }
}
