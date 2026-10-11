//! Voice activity detection.
//!
//! Default: a pure-Rust **energy (RMS) VAD** — instant, zero dependencies,
//! license-clean. Used for meeting-transcription chunking (split long
//! recordings on silence so chunk sizes stay under the Parakeet ~4-5 minute
//! limit).
//!
//! Optional: `silero-vad` cargo feature enables the neural Silero model via
//! `silero-vad-rs` (the `voice_activity_detector` crate was rejected — its
//! license field is non-standard, which would fail the `cargo deny` gate).

/// Streaming VAD state machine shared by both implementations.
pub trait Vad: Send {
    /// Feed a mono chunk; returns true while speech is detected.
    fn process(&mut self, samples: &[f32], sample_rate: u32) -> bool;
}

/// RMS-energy VAD with hangover: speech stays "on" for `hangover_ms` after
/// energy drops below threshold, so short pauses inside a phrase don't split
/// it.
pub struct EnergyVad {
    threshold: f32,
    hangover_samples: usize,
    hangover_remaining: usize,
    speech_ms: u64,
    silence_ms: u64,
}

impl EnergyVad {
    pub fn new(threshold: f32, hangover_ms: u64, sample_rate: u32) -> Self {
        Self {
            threshold,
            hangover_samples: (hangover_ms as usize * sample_rate as usize) / 1000,
            hangover_remaining: 0,
            speech_ms: 0,
            silence_ms: 0,
        }
    }

    /// Total speech duration seen (useful for "did the user actually speak").
    pub fn speech_ms(&self) -> u64 {
        self.speech_ms
    }

    /// Total non-speech duration seen.
    pub fn silence_ms(&self) -> u64 {
        self.silence_ms
    }

    pub fn reset_timers(&mut self) {
        self.speech_ms = 0;
        self.silence_ms = 0;
    }
}

impl Vad for EnergyVad {
    fn process(&mut self, samples: &[f32], sample_rate: u32) -> bool {
        let rms = if samples.is_empty() {
            0.0
        } else {
            (samples.iter().map(|s| s * s).sum::<f32>() / samples.len() as f32).sqrt()
        };
        let chunk_ms = (samples.len() as u64 * 1000) / sample_rate.max(1) as u64;
        let speech = rms > self.threshold;
        if speech {
            self.speech_ms += chunk_ms;
            self.hangover_remaining = self.hangover_samples;
        } else if self.hangover_remaining > 0 {
            self.hangover_remaining = self.hangover_remaining.saturating_sub(samples.len());
            self.speech_ms += chunk_ms;
        } else {
            self.silence_ms += chunk_ms;
        }
        speech || self.hangover_remaining > 0
    }
}

/// Factory for the configured VAD implementation.
pub fn create(threshold: f32, hangover_ms: u64, sample_rate: u32) -> Box<dyn Vad> {
    #[cfg(feature = "silero-vad")]
    {
        if let Ok(vad) = SileroVadAdapter::new(threshold, hangover_ms, sample_rate) {
            return Box::new(vad);
        }
    }
    Box::new(EnergyVad::new(threshold, hangover_ms, sample_rate))
}

/// Neural VAD over `silero-vad-rs` (feature `silero-vad`). Same hangover
/// semantics as [`EnergyVad`] but gated on the Silero model's speech
/// probability instead of RMS energy. The ONNX model (`silero_vad.onnx`)
/// must be downloaded from Settings > Engine (catalogue engine `vad`).
#[cfg(feature = "silero-vad")]
struct SileroVadAdapter {
    model: silero_vad_rs::SileroVAD,
    threshold: f32,
    hangover_ms: u64,
    /// Buffers partial input until a full 512-sample chunk is available.
    pending: Vec<f32>,
    speech: bool,
    silence_ms: u64,
}

#[cfg(feature = "silero-vad")]
impl SileroVadAdapter {
    /// The model operates on exactly 512-sample chunks at 16 kHz.
    const CHUNK: usize = 512;

    fn new(threshold: f32, hangover_ms: u64, sample_rate: u32) -> crate::error::AppResult<Self> {
        if sample_rate != 16000 {
            return Err(crate::error::AppError::Engine(format!(
                "silero VAD requires 16 kHz audio (got {sample_rate} Hz)"
            )));
        }
        let model_path = dirs::data_dir()
            .unwrap_or_else(|| std::path::PathBuf::from("."))
            .join("nvoos-dictate")
            .join("models")
            .join("silero_vad.onnx");
        if !model_path.exists() {
            return Err(crate::error::AppError::Model(format!(
                "silero VAD model not found at {} — download the silero_vad entry from \
                 Settings > Engine",
                model_path.display()
            )));
        }
        let model = silero_vad_rs::SileroVAD::new(&model_path)
            .map_err(|e| crate::error::AppError::Engine(format!("silero VAD init: {e}")))?;
        Ok(Self {
            model,
            threshold,
            hangover_ms,
            pending: Vec::with_capacity(Self::CHUNK),
            speech: false,
            silence_ms: 0,
        })
    }

    /// One 512-sample chunk at 16 kHz = 32 ms.
    fn process_chunk(&mut self, chunk: &[f32]) {
        let arr = ndarray::Array1::from(chunk.to_vec());
        let probs = match self.model.process_chunk(&arr.view(), 16000) {
            Ok(p) => p,
            // Inference hiccup: keep the previous state.
            Err(_) => return,
        };
        let prob = probs.get(0).copied().unwrap_or(0.0);
        if prob >= self.threshold {
            self.speech = true;
            self.silence_ms = 0;
        } else if self.speech {
            self.silence_ms += 32;
            if self.silence_ms >= self.hangover_ms {
                self.speech = false;
            }
        }
    }
}

#[cfg(feature = "silero-vad")]
impl Vad for SileroVadAdapter {
    fn process(&mut self, samples: &[f32], sample_rate: u32) -> bool {
        if sample_rate != 16000 {
            // The model is 16 kHz-only; energy fallback for odd-rate input.
            let rms = if samples.is_empty() {
                0.0
            } else {
                (samples.iter().map(|s| s * s).sum::<f32>() / samples.len() as f32).sqrt()
            };
            return self.speech || rms > self.threshold;
        }
        self.pending.extend_from_slice(samples);
        while self.pending.len() >= Self::CHUNK {
            let chunk: Vec<f32> = self.pending.drain(..Self::CHUNK).collect();
            self.process_chunk(&chunk);
        }
        self.speech
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn tone(hz: f32, seconds: f32, rate: u32) -> Vec<f32> {
        (0..(rate as f32 * seconds) as usize)
            .map(|i| (2.0 * std::f32::consts::PI * hz * i as f32 / rate as f32).sin() * 0.5)
            .collect()
    }

    #[test]
    fn detects_speech_and_hangover() {
        let mut vad = EnergyVad::new(0.05, 120, 16000);
        assert!(!vad.process(&vec![0.0; 1600], 16000)); // 100ms silence
        assert!(vad.process(&tone(220.0, 0.5, 16000), 16000));
        // Hangover keeps it on during a short pause.
        assert!(vad.process(&vec![0.0; 1600], 16000));
        // Long silence ends the hangover.
        let mut ended = false;
        for _ in 0..10 {
            if !vad.process(&vec![0.0; 3200], 16000) {
                ended = true;
                break;
            }
        }
        assert!(ended);
        assert!(vad.speech_ms() > 400);
    }
}
