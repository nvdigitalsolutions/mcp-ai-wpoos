//! NeMo FastConformer-CTC engine via the OFFICIAL k2-fsa `sherpa-onnx` API.
//!
//! Runs the same `OfflineRecognizer` as parakeet but through the
//! `nemo_ctc` config slot (`OfflineNemoEncDecCtcModelConfig`), which is the
//! path sherpa-onnx uses for NeMo CTC models (`stt_en_conformer_ctc_*`).
//! Verified against sherpa-onnx 1.13.8:
//!   - `OfflineModelConfig::nemo_ctc: OfflineNemoEncDecCtcModelConfig { model }`
//!   - `model_type = "nemo_ctc"`, with `tokens`/`num_threads`/`provider`
//!     on the parent `OfflineModelConfig`.
//!   - `OfflineRecognizer::create(&config) -> Option<Self>`, then the same
//!     `create_stream` → `accept_waveform` → `decode` → `get_result` flow.
//!
//! Model archive: `sherpa-onnx-nemo-ctc-en-conformer-large.tar.bz2` unpacks a
//! top-level dir (`model.int8.onnx` + `tokens.txt` + `test_wavs/`); unpack
//! into `models_dir` (the parent), not `models_dir/{id}`, matching parakeet.

use std::path::Path;

use sherpa_onnx::{OfflineNemoEncDecCtcModelConfig, OfflineRecognizer, OfflineRecognizerConfig};

use crate::error::{AppError, AppResult};
use crate::stt::engine::SttEngine;

pub struct FastConformerEngine {
    recognizer: OfflineRecognizer,
    model: String,
}

impl FastConformerEngine {
    pub fn new(models_dir: &Path, model: &str, num_threads: usize) -> AppResult<Self> {
        let dir = models_dir.join(model);
        if !dir.exists() {
            return Err(AppError::Model(format!(
                "fastconformer model '{model}' not found at {} — download it from Settings > Engine",
                dir.display()
            )));
        }

        let mut config = OfflineRecognizerConfig::default();
        config.model_config.nemo_ctc = OfflineNemoEncDecCtcModelConfig {
            model: Some(dir.join("model.int8.onnx").to_string_lossy().into_owned()),
        };
        config.model_config.tokens = Some(dir.join("tokens.txt").to_string_lossy().into_owned());
        config.model_config.model_type = Some("nemo_ctc".to_string());
        config.model_config.num_threads = num_threads as i32;
        config.model_config.provider = Some("cpu".to_string());
        config.decoding_method = Some("greedy_search".to_string());

        let recognizer = OfflineRecognizer::create(&config).ok_or_else(|| {
            AppError::Engine(format!("fastconformer init failed for model '{model}'"))
        })?;
        Ok(Self {
            recognizer,
            model: model.to_string(),
        })
    }
}

impl SttEngine for FastConformerEngine {
    fn id(&self) -> &'static str {
        "fastconformer"
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
            .ok_or_else(|| AppError::Engine("fastconformer decode produced no result".into()))
    }
}
