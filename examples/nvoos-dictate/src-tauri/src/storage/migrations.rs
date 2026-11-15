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
        // Appended (never insert mid-list): `rusqlite_migration` keys applied
        // migrations by position, so reordering breaks existing databases.
        M::up("ALTER TABLE transcripts ADD COLUMN media TEXT NOT NULL DEFAULT '';"),
        // Snippet OCR history (Proposal 100). Off by default (`retain_history`);
        // the table is created unconditionally so enabling the setting never
        // needs a migration.
        M::up(
            "CREATE TABLE snippets (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                text_raw   TEXT NOT NULL,
                text_clean TEXT NOT NULL,
                engine     TEXT NOT NULL DEFAULT '',
                language   TEXT NOT NULL DEFAULT '',
                region_w   INTEGER NOT NULL DEFAULT 0,
                region_h   INTEGER NOT NULL DEFAULT 0,
                ocr_ms     INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_snippets_created ON snippets(created_at);",
        ),
        // Document history (Proposal 109): a `source` badge distinguishes
        // screen captures (`screen`, the default) from document results
        // (`document`); `language` carries the source path for documents.
        M::up("ALTER TABLE snippets ADD COLUMN source TEXT NOT NULL DEFAULT 'screen';"),
        // Memory layer (Proposal 127): unified retrieval corpus. Every capture
        // surface (transcripts, meetings, snippets/documents) fans out into
        // `chunks`; the keyword leg is an external-content FTS5 table kept in
        // step by triggers, and the vector leg is a vec0 table (sqlite-vec,
        // registered via sqlite3_auto_extension before ANY connection opens —
        // see storage::db::Db::open). "Embedded" == a row exists in
        // `chunk_vectors`; no marker column, so a failed batch is naturally
        // retried by the worker.
        M::up(
            "CREATE TABLE chunks (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                surface    TEXT NOT NULL,
                surface_id INTEGER NOT NULL,
                seq        INTEGER NOT NULL,
                text       TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_chunks_surface ON chunks(surface, surface_id, seq);",
        ),
        M::up(
            "CREATE VIRTUAL TABLE chunks_fts USING fts5(
                text,
                surface UNINDEXED,
                surface_id UNINDEXED,
                content='chunks',
                content_rowid='id'
            );
            CREATE TRIGGER chunks_ai AFTER INSERT ON chunks BEGIN
                INSERT INTO chunks_fts(rowid, text, surface, surface_id)
                VALUES (new.id, new.text, new.surface, new.surface_id);
            END;
            CREATE TRIGGER chunks_ad AFTER DELETE ON chunks BEGIN
                INSERT INTO chunks_fts(chunks_fts, rowid, text, surface, surface_id)
                VALUES ('delete', old.id, old.text, old.surface, old.surface_id);
            END;
            CREATE TRIGGER chunks_au AFTER UPDATE ON chunks BEGIN
                INSERT INTO chunks_fts(chunks_fts, rowid, text, surface, surface_id)
                VALUES ('delete', old.id, old.text, old.surface, old.surface_id);
                INSERT INTO chunks_fts(rowid, text, surface, surface_id)
                VALUES (new.id, new.text, new.surface, new.surface_id);
            END;",
        ),
        // Vector leg. Cosine distance, because the encoder normalizes every
        // vector and the blend treats `1 - distance` as the semantic score.
        // Dimension is fixed at 384 (granite-97m-r2); a different-width
        // embedder is a P3 re-embed migration into a second vector table.
        // `surface`/`surface_id` are PLAIN metadata columns — vec0 partition
        // keys (`+col`) are illegal in KNN WHERE clauses, metadata columns
        // filter inside the KNN query (before `k` is applied). The AD
        // trigger makes vector rows die with their chunk, so surface
        // deletes are a single `DELETE FROM chunks WHERE ...`.
        M::up(
            "CREATE VIRTUAL TABLE chunk_vectors USING vec0(
                embedding float[384] distance_metric=cosine,
                surface TEXT,
                surface_id INTEGER
            );
            CREATE TRIGGER chunk_vectors_ad AFTER DELETE ON chunks BEGIN
                DELETE FROM chunk_vectors WHERE rowid = old.id;
            END;",
        ),
        // Destructive-operation audit log (Proposal 128 — the NiceDB write
        // log): one append-only row per purge/reindex, recorded by the
        // single gate point in the memory controller. Never deleted by the
        // app; `purge_memory` wipes the corpus, not its own audit trail.
        M::up(
            "CREATE TABLE memory_ops (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                action     TEXT NOT NULL,
                detail     TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );",
        ),
    ])
}
