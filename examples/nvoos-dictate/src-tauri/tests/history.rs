//! Storage integration tests: history CRUD, dictionary, meetings, kv.

use nvoos_dictate_lib::storage::Db;

fn db() -> Db {
    Db::open_in_memory().expect("in-memory db")
}

#[test]
fn transcript_round_trip_and_search() {
    let db = db();
    let a = db
        .insert_transcript(
            "raw one",
            "hello world",
            "mock",
            "mock",
            "chrome",
            "disabled",
            123,
        )
        .unwrap();
    let b = db
        .insert_transcript(
            "raw two",
            "quarterly numbers",
            "mock",
            "mock",
            "code",
            "disabled",
            88,
        )
        .unwrap();
    assert_eq!(a + 1, b);

    let all = db.list_transcripts("", 10, 0).unwrap();
    assert_eq!(all.len(), 2);
    assert_eq!(all[0].id, b); // newest first

    let filtered = db.list_transcripts("quarterly", 10, 0).unwrap();
    assert_eq!(filtered.len(), 1);
    assert_eq!(filtered[0].id, b);

    let paged = db.list_transcripts("", 1, 0).unwrap();
    assert_eq!(paged.len(), 1);
    let paged2 = db.list_transcripts("", 1, 1).unwrap();
    assert_eq!(paged2[0].id, a);
}

#[test]
fn transcript_delete_and_clear() {
    let db = db();
    let id = db
        .insert_transcript("r", "c", "mock", "mock", "app", "disabled", 1)
        .unwrap();
    db.delete_transcript(id).unwrap();
    assert_eq!(db.list_transcripts("", 10, 0).unwrap().len(), 0);

    db.insert_transcript("r", "c", "mock", "mock", "app", "disabled", 1)
        .unwrap();
    db.insert_transcript("r2", "c2", "mock", "mock", "app", "disabled", 1)
        .unwrap();
    db.clear_transcripts().unwrap();
    assert_eq!(db.list_transcripts("", 10, 0).unwrap().len(), 0);
}

#[test]
fn dictionary_round_trip() {
    let db = db();
    let entries = vec![
        ("myco".to_string(), "MyCo".to_string()),
        ("aerlinn".to_string(), "Aerlinn".to_string()),
    ];
    db.set_dictionary(&entries).unwrap();
    assert_eq!(db.dictionary().unwrap(), entries);

    // Replacing clears the old set.
    db.set_dictionary(&[("x".to_string(), "y".to_string())])
        .unwrap();
    assert_eq!(
        db.dictionary().unwrap(),
        vec![("x".to_string(), "y".to_string())]
    );
}

#[test]
fn meetings_round_trip() {
    let db = db();
    let id = db
        .insert_meeting("sync", "the meeting text", 123_000)
        .unwrap();
    let list = db.list_meetings(10).unwrap();
    assert_eq!(list.len(), 1);
    assert_eq!(list[0].id, id);
    assert_eq!(list[0].title, "sync");
    assert_eq!(list[0].duration_ms, 123_000);
    db.delete_meeting(id).unwrap();
    assert_eq!(db.list_meetings(10).unwrap().len(), 0);
}

#[test]
fn kv_round_trip() {
    let db = db();
    assert_eq!(db.kv_get("missing").unwrap(), None);
    db.kv_set("k", "v1").unwrap();
    assert_eq!(db.kv_get("k").unwrap().as_deref(), Some("v1"));
    db.kv_set("k", "v2").unwrap();
    assert_eq!(db.kv_get("k").unwrap().as_deref(), Some("v2"));
}

#[test]
fn like_escaping_is_safe() {
    let db = db();
    db.insert_transcript("r", "100% sure", "mock", "mock", "app", "disabled", 1)
        .unwrap();
    // '_' is a single-char wildcard in LIKE; with ESCAPE '\' it must be
    // treated literally, so this query matches no rows.
    let rows = db.list_transcripts("_", 10, 0).unwrap();
    assert_eq!(rows.len(), 0);
    // A literal % query does match a row that actually contains '%'.
    let rows = db.list_transcripts("%", 10, 0).unwrap();
    assert_eq!(rows.len(), 1);
}
