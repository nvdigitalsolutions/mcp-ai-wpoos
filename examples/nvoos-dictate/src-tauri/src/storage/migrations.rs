//! Embedded SQLite migrations (v1).

use rusqlite_migration::{Migrations, M};

pub fn migrations() -> Migrations<'static> {
    Migrations::new(vec![
        M::up(
            "CREATE TABLE transcripts (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                text_raw   TEXT NOT NULL,
                text_clean TEXT NOT NULL,
                engine     TEXT NOT NULL,
                model      TEXT NOT NULL DEFAULT '',
                target_app TEXT NOT NULL DEFAULT '',
                provider   TEXT NOT NULL DEFAULT '',
                e2e_ms     INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_transcripts_created ON transcripts(created_at);",
        ),
        M::up(
            "CREATE TABLE dictionary (
                term        TEXT PRIMARY KEY,
                replacement TEXT NOT NULL
            );",
        ),
        M::up(
            "CREATE TABLE meetings (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                title       TEXT NOT NULL DEFAULT '',
                text        TEXT NOT NULL,
                duration_ms INTEGER NOT NULL DEFAULT 0,
                created_at  TEXT NOT NULL DEFAULT (datetime('now'))
            );",
        ),
        M::up(
            "CREATE TABLE kv (
                key   TEXT PRIMARY KEY,
                value TEXT NOT NULL
            );",
        ),
    ])
}
