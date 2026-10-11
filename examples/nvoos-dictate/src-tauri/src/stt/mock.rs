//! Deterministic mock engine.
//!
//! Always compiled. Used by the default build (no native engine features),
//! by tests, and by the UI to exercise the full pipeline — hotkey, audio,
//! cleanup, paste, history — without model downloads. It deliberately
//! returns a recognizable sentence so it can never be mistaken for real
//! transcription.

use crate::error::AppResult;
use crate::stt::engine::SttEngine;

pub struct MockEngine;

impl MockEngine {
    pub fn new() -> Self {
        Self
    }
}

impl Default for MockEngine {
    fn default() -> Self {
        Self::new()
    }
}

impl SttEngine for MockEngine {
    fn id(&self) -> &'static str {
        "mock"
    }

    fn model_id(&self) -> &str {
        "mock"
    }

    fn transcribe(
        &self,
        _audio: &[f32],
        _sample_rate: u32,
        _language: Option<&str>,
    ) -> AppResult<String> {
        Ok(
            "[mock engine] no speech recognition model loaded. Enable a real engine under \
             Settings > Engine (rebuild with --features parakeet or --features whisper)."
                .to_string(),
        )
    }
}
