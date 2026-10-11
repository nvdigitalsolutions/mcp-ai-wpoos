//! WAV encoding for the remote STT fallback (`embedded` engine) and for
//! optional meeting-audio export.
//!
//! The Embedded addon's `/mcp-ai/v1/embedded/transcribe` route accepts
//! base64 WAV (16-bit PCM, mono). The capture pipeline produces mono f32,
//! so we encode a canonical 44-byte RIFF header + i16 samples.

/// Encode mono f32 samples as 16-bit PCM WAV (RIFF) and return the bytes.
///
/// Samples are clamped to [-1.0, 1.0]; the caller is expected to supply
/// 16 kHz mono audio (the capture default).
pub fn encode_f32_to_wav(samples: &[f32], sample_rate: u32) -> Vec<u8> {
    let data_len = (samples.len() * 2) as u32;
    let mut out = Vec::with_capacity(44 + samples.len() * 2);

    // RIFF header.
    out.extend_from_slice(b"RIFF");
    out.extend_from_slice(&(36 + data_len).to_le_bytes()); // chunk size
    out.extend_from_slice(b"WAVE");

    // fmt chunk: PCM, mono, 16-bit.
    out.extend_from_slice(b"fmt ");
    out.extend_from_slice(&16u32.to_le_bytes()); // fmt chunk size
    out.extend_from_slice(&1u16.to_le_bytes()); // PCM
    out.extend_from_slice(&1u16.to_le_bytes()); // channels
    out.extend_from_slice(&sample_rate.to_le_bytes());
    out.extend_from_slice(&(sample_rate * 2).to_le_bytes()); // byte rate
    out.extend_from_slice(&2u16.to_le_bytes()); // block align
    out.extend_from_slice(&16u16.to_le_bytes()); // bits per sample

    // data chunk.
    out.extend_from_slice(b"data");
    out.extend_from_slice(&data_len.to_le_bytes());
    for s in samples {
        let clamped = s.clamp(-1.0, 1.0);
        out.extend_from_slice(&((clamped * 32767.0).round() as i16).to_le_bytes());
    }
    out
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn header_is_canonical_16bit_mono_pcm() {
        let wav = encode_f32_to_wav(&[0.0f32; 1600], 16000);
        assert_eq!(&wav[0..4], b"RIFF");
        assert_eq!(&wav[8..12], b"WAVE");
        assert_eq!(&wav[12..16], b"fmt ");
        assert_eq!(u16::from_le_bytes([wav[20], wav[21]]), 1, "PCM");
        assert_eq!(u16::from_le_bytes([wav[22], wav[23]]), 1, "mono");
        assert_eq!(
            u32::from_le_bytes([wav[24], wav[25], wav[26], wav[27]]),
            16000,
            "sample rate"
        );
        assert_eq!(u16::from_le_bytes([wav[34], wav[35]]), 16, "bit depth");
        assert_eq!(&wav[36..40], b"data");
        assert_eq!(wav.len(), 44 + 1600 * 2, "16-bit samples follow");
    }

    #[test]
    fn samples_are_clamped_and_little_endian() {
        let wav = encode_f32_to_wav(&[0.5, -1.5, 1.0], 16000);
        let s0 = i16::from_le_bytes([wav[44], wav[45]]);
        let s1 = i16::from_le_bytes([wav[46], wav[47]]);
        let s2 = i16::from_le_bytes([wav[48], wav[49]]);
        assert_eq!(s0, 16384);
        assert_eq!(s1, i16::MIN + 1, "clamped to -1.0");
        assert_eq!(s2, i16::MAX, "clamped to +1.0");
    }

    #[test]
    fn empty_input_is_a_valid_empty_wav() {
        let wav = encode_f32_to_wav(&[], 16000);
        assert_eq!(wav.len(), 44);
        assert_eq!(u32::from_le_bytes([wav[4], wav[5], wav[6], wav[7]]), 36);
    }
}
