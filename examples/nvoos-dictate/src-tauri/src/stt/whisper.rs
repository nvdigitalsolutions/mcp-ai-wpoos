//! Whisper engine via `whisper-rs` (whisper.cpp bindings).
//!
//! API verified against whisper-rs 0.16 README (2026): the crate migrated
//! from GitHub to Codeberg (codeberg.org/tazz4843/whisper-rs) but remains
//! published on crates.io. Building requires CMake + a C++ toolchain;
//! `WHISPER_DONT_GENERATE_BINDINGS=1` works around bindgen issues.
//!
//! Models: `ggml-{tiny,base,small}.en.bin` etc. from
//! https://huggingface.co/ggerganov/whisper.cpp — download at first run via
//! `model_registry`, never bundled (see LICENSE of the weights: MIT).

use std::path::Path;

use whisper_rs::{FullParams, SamplingStrategy, WhisperContext, WhisperContextParameters};

use crate::error::{AppError, AppResult};
use crate::stt::engine::SttEngine;

pub struct WhisperEngine {
    ctx: WhisperContext,
    model: String,
}

impl WhisperEngine {
    pub fn new(models_dir: &Path, model: &str) -> AppResult<Self> {
        let path = models_dir.join(format!("{model}.bin"));
        if !path.exists() {
            return Err(AppError::Model(format!(
                "whisper model '{model}' not found at {} — download it from Settings > Engine",
                path.display()
            )));
        }
        let path_str = path
            .to_str()
            .ok_or_else(|| AppError::Model("non-UTF-8 model path".into()))?;
        let ctx = WhisperContext::new_with_params(path_str, WhisperContextParameters::default())
            .map_err(|e| AppError::Engine(format!("failed to load whisper model: {e:?}")))?;
        Ok(Self {
            ctx,
            model: model.to_string(),
        })
    }
}

impl SttEngine for WhisperEngine {
    fn id(&self) -> &'static str {
        "whisper"
    }

    fn model_id(&self) -> &str {
        &self.model
    }

    fn supports_long_files(&self) -> bool {
        true
    }

    fn transcribe(
        &self,
        audio: &[f32],
        sample_rate: u32,
        language: Option<&str>,
    ) -> AppResult<String> {
        // whisper.cpp expects 16 kHz mono f32.
        let audio = if sample_rate != 16000 {
            resample(audio, sample_rate, 16000)
        } else {
            audio.to_vec()
        };

        let mut params = FullParams::new(SamplingStrategy::Greedy { best_of: 1 });
        if let Some(lang) = language {
            params.set_language(Some(lang));
        }

        let mut state = self
            .ctx
            .create_state()
            .map_err(|e| AppError::Engine(format!("whisper state: {e:?}")))?;
        state
            .full(params, &audio)
            .map_err(|e| AppError::Engine(format!("whisper decode: {e:?}")))?;

        // whisper-rs 0.16: `full_n_segments()` is infallible and segments are
        // read via `get_segment(i)` + `WhisperSegment::to_str()`.
        let segments = state.full_n_segments();
        let mut out = String::new();
        for i in 0..segments {
            let seg = state
                .get_segment(i)
                .ok_or_else(|| AppError::Engine(format!("whisper segment {i} missing")))?;
            let text = seg
                .to_str()
                .map_err(|e| AppError::Engine(format!("whisper segment {i}: {e:?}")))?;
            out.push_str(text);
        }
        Ok(out.trim().to_string())
    }
}

/// Naive linear resampler (fine for speech at these rates).
fn resample(input: &[f32], from: u32, to: u32) -> Vec<f32> {
    if from == to {
        return input.to_vec();
    }
    let ratio = from as f64 / to as f64;
    let out_len = (input.len() as f64 / ratio).ceil() as usize;
    (0..out_len)
        .map(|i| {
            let src = (i as f64 * ratio) as usize;
            input[src.min(input.len() - 1)]
        })
        .collect()
}
