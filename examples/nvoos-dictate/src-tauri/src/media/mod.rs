//! Media ingestion: decode → long-media chunking → transcription orchestration.
//!
//! This module ties together the Symphonia decode seam (`util/decode`) and the
//! windowed/VAD-split chunking so both "Transcribe file…" (audio) and
//! "Transcribe media…" (video) share one batch-transcription path.

pub mod chunker;

use crate::error::AppResult;
use crate::stt::SttEngine;

/// Transcribe a decoded media buffer (already mono f32 PCM), chunking long
/// media and carrying context across windows.
pub fn transcribe_media(
    engine: &dyn SttEngine,
    samples: &[f32],
    sample_rate: u32,
    language: Option<&str>,
) -> AppResult<String> {
    chunker::chunk_transcribe(engine, samples, sample_rate, language)
}
