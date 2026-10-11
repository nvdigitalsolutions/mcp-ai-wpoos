//! Moonshine v2 streaming engine (feature `moonshine`).
//!
//! Status: integration spike. Moonshine v2 (MIT) is the low-latency
//! streaming ASR option (Tiny 50 ms / Small 148 ms per the v2 paper) and
//! would power live partial previews during dictation. The `ort` crate
//! (ONNX Runtime) is the runtime; the streaming encoder/decoder loop needs
//! dedicated implementation work — see docs/decisions and the impl plan's
//! P0 note ("Moonshine v2 streaming preview" as an enhancement).

use std::path::Path;

use crate::error::{AppError, AppResult};
use crate::stt::engine::SttEngine;

pub struct MoonshineEngine {
    model: String,
}

impl MoonshineEngine {
    pub fn new(_models_dir: &Path, model: &str) -> AppResult<Self> {
        Ok(Self {
            model: model.to_string(),
        })
    }
}

impl SttEngine for MoonshineEngine {
    fn id(&self) -> &'static str {
        "moonshine"
    }

    fn model_id(&self) -> &str {
        &self.model
    }

    fn transcribe(
        &self,
        _audio: &[f32],
        _sample_rate: u32,
        _language: Option<&str>,
    ) -> AppResult<String> {
        Err(AppError::Unsupported(
            "the moonshine engine is a documented spike — the streaming ONNX loop is not \
             implemented yet; use the parakeet or whisper engine"
                .into(),
        ))
    }
}
