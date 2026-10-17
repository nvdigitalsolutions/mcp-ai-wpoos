//! Long-media transcription: windowed chunking with cross-window context.
//!
//! Two strategies:
//! - **Windowed** (whisper semantics): split the audio into fixed ~30 s
//!   windows with a small overlap, and carry the prior window's accepted text
//!   forward as context (whisper's sliding-window behaviour).
//! - **VAD-split** (parakeet semantics, via `meetings/chunker.rs`): split on
//!   VAD-detected silence up to a hard `MAX_SEGMENT_SECS` limit (parakeet's
//!   ~4–5 min TDT decode limit).
//!
//! The two are unified behind `chunk_transcribe`, which selects the strategy
//! from the engine's `supports_long_files()` and stitches the returned
//! segments (each window may yield 0..N segments — never assume one transcript
//! per window).

use crate::error::AppResult;
use crate::stt::SttEngine;

/// Default fixed window for whisper-style transcription (matches whisper's
/// ~30 s sliding window).
pub const WINDOW_SECS: usize = 30;

/// Overlap between windows, to avoid cutting a word at the boundary.
pub const WINDOW_OVERLAP_SECS: usize = 1;

/// Cap on context carried into the next window (chars), to avoid unbounded
/// growth across many windows.
const MAX_CONTEXT_CHARS: usize = 4096;

/// Fixed-size windows over a mono buffer, with overlap.
pub fn windows(samples: &[f32], sample_rate: u32) -> Vec<(usize, usize)> {
    let win = WINDOW_SECS * sample_rate as usize;
    let overlap = WINDOW_OVERLAP_SECS * sample_rate as usize;
    if win == 0 || samples.is_empty() {
        return vec![(0, samples.len())];
    }
    let mut out = Vec::new();
    let mut start = 0usize;
    while start < samples.len() {
        let end = (start + win).min(samples.len());
        out.push((start, end));
        if end >= samples.len() {
            break;
        }
        start = end.saturating_sub(overlap).max(start + 1);
    }
    out
}

/// Transcribe a long mono buffer, chunking as needed and carrying prior text
/// forward as context. Returns the stitched transcript.
///
/// - Engines that report `supports_long_files()` (e.g. whisper) receive the
///   buffer in fixed ~30 s windows with context carry-over.
/// - Engines that do not (parakeet) are split on VAD-detected silence at the
///   240 s limit (via `meetings::Chunker`) with no context (they do not accept
///   prior text).
pub fn chunk_transcribe(
    engine: &dyn SttEngine,
    samples: &[f32],
    sample_rate: u32,
    language: Option<&str>,
) -> AppResult<String> {
    if engine.supports_long_files() {
        // Whisper-style: fixed windows + context carry-over.
        let mut parts: Vec<String> = Vec::new();
        let mut context: Option<String> = None;
        for (start, end) in windows(samples, sample_rate) {
            if end <= start {
                continue;
            }
            let part = engine.transcribe_with_context(
                &samples[start..end],
                sample_rate,
                language,
                context.as_deref(),
            )?;
            let trimmed = part.trim().to_string();
            if !trimmed.is_empty() {
                context = Some(trimmed.chars().take(MAX_CONTEXT_CHARS).collect());
                parts.push(trimmed);
            }
        }
        Ok(parts.join("\n\n"))
    } else {
        // Parakeet-style: VAD-silence split at the hard limit, no context.
        let mut chunker = crate::meetings::Chunker::new(sample_rate);
        let mut parts = Vec::new();
        for (start, end) in chunker.split(samples) {
            if end <= start {
                continue;
            }
            let part = engine.transcribe(&samples[start..end], sample_rate, language)?;
            if !part.trim().is_empty() {
                parts.push(part.trim().to_string());
            }
        }
        Ok(parts.join("\n\n"))
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
    fn windows_split_long_audio() {
        let rate = 100u32; // tiny rate so "30 s" = 3,000 samples
        let audio = tone(220.0, 90.0, rate); // 9,000 samples = 3 windows
        let ws = windows(&audio, rate);
        assert!(ws.len() >= 3, "expected >= 3 windows, got {}", ws.len());
        // Total coverage must include the whole buffer (with overlap it's
        // >= len; each window's [start,end) is within bounds).
        for (s, e) in &ws {
            assert!(*e > *s);
            assert!(*e <= audio.len());
        }
        assert_eq!(ws.first().unwrap().0, 0);
        assert_eq!(ws.last().unwrap().1, audio.len());
    }

    #[test]
    fn windows_short_audio_is_one_window() {
        let rate = 100u32;
        let audio = tone(220.0, 1.0, rate); // 100 samples < 3,000
        let ws = windows(&audio, rate);
        assert_eq!(ws, vec![(0, audio.len())]);
    }

    #[test]
    fn windows_empty_is_single_empty() {
        assert_eq!(windows(&[], 16000), vec![(0, 0)]);
    }
}
