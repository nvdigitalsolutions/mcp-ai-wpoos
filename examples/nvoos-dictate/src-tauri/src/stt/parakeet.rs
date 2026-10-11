//! Parakeet TDT engine via the OFFICIAL k2-fsa `sherpa-onnx` Rust API.
//!
//! API verified against sherpa-onnx 1.13.8 (the crate's own doc example in
//! `offline_asr.rs`):
//!   - `OfflineRecognizerConfig::default()` + struct-typed `OfflineModelConfig`
//!     (all `Option<String>` paths, `model_type = "nemo_transducer"`).
//!   - `OfflineRecognizer::create(&config) -> Option<Self>`.
//!   - `stream.accept_waveform(sample_rate: i32, samples: &[f32])` (infallible,
//!     `&self`), `recognizer.decode(&stream)`, `stream.get_result() -> Option<…>`.
//!
//! Model: `sherpa-onnx-nemo-parakeet-tdt-0.6b-v2-int8` (English; ~600 MB, the
//! archive ships `encoder.int8.onnx`, `decoder.int8.onnx`, `joiner.int8.onnx`,
//! `tokens.txt`). CTC/TDT models have a ~4-5 minute decode limit — meetings
//! must be chunked (see meetings/chunker.rs).

use std::path::Path;

use sherpa_onnx::{OfflineRecognizer, OfflineRecognizerConfig, OfflineTransducerModelConfig};

use crate::error::{AppError, AppResult};
use crate::stt::engine::SttEngine;

pub struct ParakeetEngine {
    recognizer: OfflineRecognizer,
    model: String,
}

impl ParakeetEngine {
    pub fn new(models_dir: &Path, model: &str, num_threads: usize) -> AppResult<Self> {
        let dir = models_dir.join(model);
        if !dir.exists() {
            return Err(AppError::Model(format!(
                "parakeet model '{model}' not found at {} — download it from Settings > Engine",
                dir.display()
            )));
        }

        let mut config = OfflineRecognizerConfig::default();
        config.model_config.transducer = OfflineTransducerModelConfig {
            encoder: Some(dir.join("encoder.int8.onnx").to_string_lossy().into_owned()),
            decoder: Some(dir.join("decoder.int8.onnx").to_string_lossy().into_owned()),
            joiner: Some(dir.join("joiner.int8.onnx").to_string_lossy().into_owned()),
        };
        config.model_config.tokens = Some(dir.join("tokens.txt").to_string_lossy().into_owned());
        config.model_config.model_type = Some("nemo_transducer".to_string());
        config.model_config.num_threads = num_threads as i32;
        config.model_config.provider = Some("cpu".to_string());
        config.decoding_method = Some("greedy_search".to_string());

        let recognizer = OfflineRecognizer::create(&config)
            .ok_or_else(|| AppError::Engine(format!("parakeet init failed for model '{model}'")))?;
        Ok(Self {
            recognizer,
            model: model.to_string(),
        })
    }
}

impl SttEngine for ParakeetEngine {
    fn id(&self) -> &'static str {
        "parakeet"
    }

    fn model_id(&self) -> &str {
        &self.model
    }

    fn warm(&self) -> AppResult<()> {
        // Silent decode so the first real utterance is not slow.
        let stream = self.recognizer.create_stream();
        stream.accept_waveform(16000, &[0.0f32; 1600]);
        self.recognizer.decode(&stream);
        Ok(())
    }

    fn transcribe(
        &self,
        audio: &[f32],
        sample_rate: u32,
        _language: Option<&str>,
    ) -> AppResult<String> {
        if audio.is_empty() {
            return Ok(String::new());
        }
        let stream = self.recognizer.create_stream();
        stream.accept_waveform(sample_rate as i32, audio);
        self.recognizer.decode(&stream);
        stream
            .get_result()
            .map(|r| r.text)
            .ok_or_else(|| AppError::Engine("parakeet decode produced no result".into()))
    }
}
