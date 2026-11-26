// SPDX-License-Identifier: LicenseRef-Proprietary
//! SQLite-backed local history, dictionary, and meeting transcripts.
//!
//! `Db` is the only public handle; migrations and the credential store stay
//! behind it.
pub mod db;
mod migrations;
#[cfg(windows)]
pub mod win_cred;

pub use db::{Db, MeetingRow, TranscriptRow};
