//! NV oOS WordPress plugin REST integration.
//!
//! The app is a headless client of a user-configured NV oOS site, using the
//! same bearer contract the repo's content-graph SPA documents:
//! `Authorization: Bearer cred_XXXXX.SECRET`, definitive 401 on bad
//! credentials, and message content in segment form
//! `[{ "type": "text", "text": "..." }]`.

mod archive;
mod client;
pub mod types;

pub use archive::archive_transcript;
pub use client::{ConnectionTest, NvoosClient, SpaSurface};
pub use types::{extract_polish_meta, extract_reply_text, TranscriptRecord};
