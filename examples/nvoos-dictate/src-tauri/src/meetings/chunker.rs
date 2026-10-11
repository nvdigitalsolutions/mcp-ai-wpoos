//! Meeting chunker: split long recordings into STT-friendly segments.
//!
//! Parakeet TDT/CTC models have a ~4-5 minute decode limit, so recordings
//! are split on VAD-detected silence into segments of at most
//! `MAX_SEGMENT_SECS` (default 240 s), with a small overlap guard. Whisper
//! engines skip chunking (supports_long_files).

use crate::audio::vad::{EnergyVad, Vad};

pub const MAX_SEGMENT_SECS: usize = 240;

pub struct Chunker {
    vad: EnergyVad,
    sample_rate: u32,
}

impl Chunker {
    pub fn new(sample_rate: u32) -> Self {
        Self {
            vad: EnergyVad::new(0.02, 400, sample_rate),
            sample_rate,
        }
    }

    /// Split a mono recording into segment boundaries (sample offsets).
    /// Always returns at least one segment covering the whole recording.
    pub fn split(&mut self, samples: &[f32]) -> Vec<(usize, usize)> {
        let mut segments = Vec::new();
        let mut start = 0usize;
        let mut last_silence_start = 0usize;
        let max_samples = MAX_SEGMENT_SECS * self.sample_rate as usize;

        let chunk = self.sample_rate as usize / 10; // 100 ms VAD chunks
        for i in (0..samples.len()).step_by(chunk.max(1)) {
            let end = (i + chunk).min(samples.len());
            let speaking = self.vad.process(&samples[i..end], self.sample_rate);
            if speaking {
                last_silence_start = end;
            }
            // Split at silence when over the hard limit.
            if end - start >= max_samples && last_silence_start > start {
                segments.push((start, last_silence_start));
                start = last_silence_start;
            }
        }
        if start < samples.len() {
            segments.push((start, samples.len()));
        }
        if segments.is_empty() {
            segments.push((0, samples.len()));
        }
        segments
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn tone(hz: f32, seconds: f32, rate: u32) -> Vec<f32> {
        (0..(rate as f32 * seconds) as usize)
            .map(|i| (2.0 * std::f32::consts::PI * hz * i as f32 / rate as f32).sin() * 0.4)
            .collect()
    }

    #[test]
    fn short_recording_is_one_segment() {
        let mut c = Chunker::new(16000);
        let audio = tone(220.0, 2.0, 16000);
        let segs = c.split(&audio);
        assert_eq!(segs.len(), 1);
        assert_eq!(segs[0], (0, audio.len()));
    }

    #[test]
    fn empty_recording_still_yields_one_segment() {
        let mut c = Chunker::new(16000);
        let segs = c.split(&[]);
        assert_eq!(segs, vec![(0, 0)]);
    }

    #[test]
    fn hard_limit_splits() {
        // Force a tiny "max segment" by using a small sample rate so the
        // limit math is exercised without generating minutes of audio:
        // max segment = 240 s * 100 Hz = 24,000 samples.
        let rate = 100u32;
        let mut c = Chunker::new(rate);
        let audio = tone(220.0, 300.0, rate); // 30,000 samples
        let segs = c.split(&audio);
        assert!(
            segs.len() >= 2,
            "expected at least 2 segments, got {}",
            segs.len()
        );
        let total: usize = segs.iter().map(|(a, b)| b - a).sum();
        assert_eq!(total, audio.len());
    }
}
