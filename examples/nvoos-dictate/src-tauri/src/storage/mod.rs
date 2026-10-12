//! SQLite-backed local history, dictionary, and meeting transcripts.
pub mod db;
mod migrations;
#[cfg(windows)]
pub mod win_cred;

pub use db::{Db, MeetingRow, TranscriptRow};
