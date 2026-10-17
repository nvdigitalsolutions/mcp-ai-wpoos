//! NDJSON framing protocol spoken by the macOS system-audio sidecar
//! (P5 port, phase M5).
//!
//! The Swift helper (`mac-helper/`, ScreenCaptureKit) emits one JSON object
//! per line on stdout; the Rust client parses lines and decodes PCM chunks.
//! This module is platform-free so the codec is unit-tested on every OS.
//!
//! Frame shapes:
//! ```json
//! {"type":"hello","protocol":1}
//! {"type":"ready","sample_rate":16000,"channels":1}
//! {"type":"chunk","seq":1,"sample_rate":16000,"channels":1,"pcm16":"<base64>"}
//! {"type":"stopped"}
//! {"type":"error","message":"…"}
//! ```

use serde::Deserialize;

pub const PROTOCOL_VERSION: u32 = 1;

/// One line parsed from the helper's stdout.
#[derive(Debug, Clone, PartialEq, Deserialize)]
#[serde(tag = "type", rename_all = "snake_case")]
pub enum SidecarFrame {
    Hello {
        protocol: u32,
    },
    Ready {
        sample_rate: u32,
        channels: u16,
    },
    Chunk {
        seq: u64,
        sample_rate: u32,
        channels: u16,
        pcm16: String,
    },
    Stopped,
    Error {
        message: String,
    },
}

/// Parse one NDJSON line. Returns `None` for blank/undecodable lines (the
/// client logs and continues — a corrupt frame must not kill capture).
pub fn parse_line(line: &str) -> Option<SidecarFrame> {
    let line = line.trim();
    if line.is_empty() {
        return None;
    }
    serde_json::from_str::<SidecarFrame>(line).ok()
}

/// Decode base64 little-endian PCM16 into mono f32 samples at 16 kHz
/// (channels > 1 are averaged — the helper sends mono today, but the codec
/// stays honest about the framing).
pub fn decode_pcm16(b64: &str, channels: u16) -> Option<Vec<f32>> {
    use base64::Engine as _;
    let bytes = base64::engine::general_purpose::STANDARD.decode(b64).ok()?;
    let channels = channels.max(1) as usize;
    // `usable` counts full frames; drop a trailing partial frame rather
    // than failing the chunk.
    let frames = (bytes.len() / 2) - ((bytes.len() / 2) % channels);
    if frames == 0 {
        return Some(Vec::new());
    }
    let mut out = Vec::with_capacity(frames);
    for frame in bytes[..frames * 2].chunks_exact(channels * 2) {
        let mut sum = 0.0f32;
        for ch in frame.as_chunks::<2>().0 {
            let sample = i16::from_le_bytes([ch[0], ch[1]]) as f32 / 32768.0;
            sum += sample;
        }
        out.push(sum / channels as f32);
    }
    Some(out)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn parses_all_frame_types() {
        assert_eq!(
            parse_line(r#"{"type":"hello","protocol":1}"#),
            Some(SidecarFrame::Hello { protocol: 1 })
        );
        assert_eq!(
            parse_line(r#"{"type":"ready","sample_rate":16000,"channels":1}"#),
            Some(SidecarFrame::Ready {
                sample_rate: 16000,
                channels: 1
            })
        );
        assert_eq!(
            parse_line(r#"{"type":"stopped"}"#),
            Some(SidecarFrame::Stopped)
        );
        assert_eq!(
            parse_line(r#"{"type":"error","message":"boom"}"#),
            Some(SidecarFrame::Error {
                message: "boom".into()
            })
        );
    }

    #[test]
    fn blank_and_garbage_lines_return_none() {
        assert_eq!(parse_line(""), None);
        assert_eq!(parse_line("   "), None);
        assert_eq!(parse_line("not json"), None);
        assert_eq!(parse_line(r#"{"type":"unknown"}"#), None);
    }

    #[test]
    fn decodes_mono_pcm16() {
        use base64::Engine as _;
        // One sample: 16384 = 0.5.
        let bytes = 16384i16.to_le_bytes();
        let b64 = base64::engine::general_purpose::STANDARD.encode(bytes);
        let samples = decode_pcm16(&b64, 1).unwrap();
        assert_eq!(samples.len(), 1);
        assert!((samples[0] - 0.5).abs() < 1e-6);
    }

    #[test]
    fn decodes_stereo_by_averaging() {
        use base64::Engine as _;
        // Frame: L=16384 (0.5), R=-16384 (-0.5) -> mono 0.0.
        let mut bytes = Vec::new();
        bytes.extend_from_slice(&16384i16.to_le_bytes());
        bytes.extend_from_slice(&(-16384i16).to_le_bytes());
        let b64 = base64::engine::general_purpose::STANDARD.encode(bytes);
        let samples = decode_pcm16(&b64, 2).unwrap();
        assert_eq!(samples.len(), 1);
        assert!(samples[0].abs() < 1e-6);
    }

    #[test]
    fn odd_trailing_byte_is_dropped_not_fatal() {
        use base64::Engine as _;
        // One full sample (100) plus a trailing junk byte that is not part
        // of any frame.
        let bytes = vec![100u8, 0, 0xAB];
        let b64 = base64::engine::general_purpose::STANDARD.encode(&bytes);
        let samples = decode_pcm16(&b64, 1).unwrap();
        assert_eq!(samples.len(), 1);
        assert!((samples[0] - (100.0f32 / 32768.0)).abs() < 1e-6);
    }

    #[test]
    fn invalid_base64_returns_none() {
        assert_eq!(decode_pcm16("!!!not-base64!!!", 1), None);
    }

    #[test]
    fn chunk_frame_round_trip() {
        assert!(matches!(
            parse_line(
                r#"{"type":"chunk","seq":7,"sample_rate":16000,"channels":1,"pcm16":"AA=="}"#
            ),
            Some(SidecarFrame::Chunk { seq: 7, .. })
        ));
    }
}
