//! Memory layer integration tests (Proposal 127): schema, triggers, the
//! fan-out path from the capture tables into the corpus, and the cascade
//! discipline. Everything runs on the mock path — no network, no ort.

use nvoos_dictate_lib::memory::embed::Embedder;
use nvoos_dictate_lib::storage::Db;

fn db() -> Db {
    Db::open_in_memory().expect("in-memory db")
}

#[test]
fn migration_creates_memory_schema() {
    let db = db();
    // The vec0 extension is registered process-wide before any connection
    // opens; a query against the virtual table proves it loaded.
    let (total, embedded, pending) = db.memory_counts().unwrap();
    assert_eq!((total, embedded, pending), (0, 0, 0));
}

#[test]
fn transcript_fan_out_creates_chunks() {
    let db = db();
    db.insert_transcript(
        "raw",
        "Discussed the WebSocket fix. Then reviewed the budget numbers.",
        "mock",
        "mock",
        "chrome",
        "disabled",
        50,
    )
    .unwrap();
    let (total, embedded, pending) = db.memory_counts().unwrap();
    assert!(total >= 1, "fan-out must chunk the transcript");
    assert_eq!(embedded, 0, "vectors are the worker's job");
    assert_eq!(pending, total);
}

#[test]
fn snippet_and_meeting_fan_out() {
    let db = db();
    db.insert_snippet(
        "raw",
        "screenshot text about invoices",
        "winrt",
        "en",
        100,
        50,
        30,
    )
    .unwrap();
    db.insert_meeting(
        "standup",
        "Alice: shipped the fix. Bob: reviewed it.",
        60_000,
    )
    .unwrap();
    let fts = db.fts_search("\"invoices\"", &["snippet"], 10).unwrap();
    assert!(!fts.is_empty(), "snippet text must be keyword-searchable");
    let fts = db.fts_search("\"shipped\"", &["meeting"], 10).unwrap();
    assert!(!fts.is_empty(), "meeting text must be keyword-searchable");
}

#[test]
fn deleting_capture_rows_cascades_to_the_corpus() {
    let db = db();
    let id = db
        .insert_transcript(
            "raw",
            "unique aardvark phrase",
            "mock",
            "mock",
            "app",
            "disabled",
            1,
        )
        .unwrap();
    // Embed it so both legs hold data, then delete.
    let pending = db.pending_chunks(10).unwrap();
    let vectors: Vec<(i64, Vec<f32>)> = pending
        .into_iter()
        .map(|(chunk, text)| {
            let vector = nvoos_dictate_lib::memory::embed::MockEmbedder::new(384)
                .embed_batch(&[text])
                .unwrap()
                .remove(0);
            (chunk, vector)
        })
        .collect();
    db.store_vectors(&vectors).unwrap();
    db.delete_transcript(id).unwrap();

    let fts = db
        .fts_search("\"aardvark\"", &["transcript", "meeting", "snippet"], 10)
        .unwrap();
    assert!(fts.is_empty(), "FTS rows must die with the transcript");
    let (total, embedded, _) = db.memory_counts().unwrap();
    assert_eq!(
        (total, embedded),
        (0, 0),
        "vectors must die with their chunks"
    );
}

#[test]
fn clear_transcripts_cascades() {
    let db = db();
    db.insert_transcript("a", "first thought", "mock", "mock", "app", "disabled", 1)
        .unwrap();
    db.insert_transcript("b", "second thought", "mock", "mock", "app", "disabled", 1)
        .unwrap();
    db.clear_transcripts().unwrap();
    assert_eq!(db.memory_counts().unwrap(), (0, 0, 0));
}

#[test]
fn purge_leaves_capture_history_intact() {
    let db = db();
    db.insert_transcript(
        "raw",
        "keep me in history",
        "mock",
        "mock",
        "app",
        "disabled",
        1,
    )
    .unwrap();
    db.purge_memory().unwrap();
    assert_eq!(db.memory_counts().unwrap(), (0, 0, 0));
    let history = db.list_transcripts("", 10, 0).unwrap();
    assert_eq!(history.len(), 1, "purge must never touch capture history");
}

#[test]
fn clear_vectors_makes_everything_pending_again() {
    let db = db();
    db.insert_transcript("raw", "reindex me", "mock", "mock", "app", "disabled", 1)
        .unwrap();
    let pending = db.pending_chunks(10).unwrap();
    let vectors: Vec<(i64, Vec<f32>)> = pending
        .into_iter()
        .map(|(chunk, text)| {
            let vector = nvoos_dictate_lib::memory::embed::MockEmbedder::new(384)
                .embed_batch(&[text])
                .unwrap()
                .remove(0);
            (chunk, vector)
        })
        .collect();
    db.store_vectors(&vectors).unwrap();
    assert_eq!(db.memory_counts().unwrap().2, 0);

    db.clear_vectors().unwrap();
    let (total, embedded, pending) = db.memory_counts().unwrap();
    assert!(total > 0);
    assert_eq!(embedded, 0, "reindex empties the vector table");
    assert_eq!(pending, total, "every chunk becomes pending again");
    // The keyword leg survived.
    assert!(!db
        .fts_search("\"reindex\"", &["transcript", "meeting", "snippet"], 10)
        .unwrap()
        .is_empty());
}

#[test]
fn fts_scope_filter_respects_surfaces() {
    let db = db();
    db.insert_transcript(
        "raw",
        "secret transcript note",
        "mock",
        "mock",
        "app",
        "disabled",
        1,
    )
    .unwrap();
    db.insert_snippet("raw", "secret snippet note", "winrt", "en", 10, 10, 1)
        .unwrap();
    let fts = db.fts_search("\"secret\"", &["snippet"], 10).unwrap();
    assert_eq!(fts.len(), 1, "scope must filter inside the FTS query");
    let all = db
        .fts_search("\"secret\"", &["transcript", "meeting", "snippet"], 10)
        .unwrap();
    assert_eq!(all.len(), 2);
}

#[test]
fn vector_search_returns_cosine_distances() {
    let db = db();
    db.insert_transcript("raw", "vector me", "mock", "mock", "app", "disabled", 1)
        .unwrap();
    let pending = db.pending_chunks(10).unwrap();
    let text = pending[0].1.clone();
    let vector = nvoos_dictate_lib::memory::embed::MockEmbedder::new(384)
        .embed_batch(&[text])
        .unwrap()
        .remove(0);
    db.store_vectors(&[(pending[0].0, vector.clone())]).unwrap();

    let hits = db
        .vector_search(&vector, &["transcript", "meeting", "snippet"], 5)
        .unwrap();
    assert_eq!(hits.len(), 1);
    // distance = 1 - cosine; an exact self-match is ~0.
    assert!(
        hits[0].1.abs() < 1e-4,
        "self-match distance must be ~0, got {}",
        hits[0].1
    );
}

#[test]
fn memory_ops_log_is_newest_first_and_survives_purge() {
    let db = db();
    db.log_memory_op("reindex", "first op").unwrap();
    db.log_memory_op("purge", "second op").unwrap();
    let ops = db.recent_memory_ops(10).unwrap();
    assert_eq!(ops.len(), 2);
    assert_eq!(ops[0].action, "purge");
    assert_eq!(ops[1].action, "reindex");
    assert_eq!(ops[0].detail, "second op");
    // Wiping the corpus must never wipe its own audit trail.
    db.purge_memory().unwrap();
    assert_eq!(db.recent_memory_ops(10).unwrap().len(), 2);
}
