//! SQLite-backed local history, dictionary, and meeting transcripts.
pub mod db;
mod migrations;

pub use db::{Db, MeetingRow, TranscriptRow};
