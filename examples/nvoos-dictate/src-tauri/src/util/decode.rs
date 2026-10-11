//! Decode audio files (m4a/mp3/wav/flac/ogg) to mono f32 PCM via symphonia
//! (pure Rust — no FFmpeg sidecar). Used by "Transcribe file…".

use std::path::Path;

use symphonia::core::audio::SampleBuffer;
use symphonia::core::codecs::DecoderOptions;
use symphonia::core::errors::Error as SymphoniaError;
use symphonia::core::formats::FormatOptions;
use symphonia::core::io::MediaSourceStream;
use symphonia::core::meta::MetadataOptions;
use symphonia::core::probe::Hint;

use crate::error::{AppError, AppResult};

pub struct DecodedAudio {
    pub samples: Vec<f32>,
    pub sample_rate: u32,
}

/// Decode any supported audio file into mono f32 PCM.
pub fn decode_file(path: &Path) -> AppResult<DecodedAudio> {
    let file = std::fs::File::open(path)?;
    let mss = MediaSourceStream::new(Box::new(file), Default::default());
    let hint = Hint::new();
    let probed = symphonia::default::get_probe()
        .format(
            &hint,
            mss,
            &FormatOptions::default(),
            &MetadataOptions::default(),
        )
        .map_err(|e| AppError::Audio(format!("unsupported audio format: {e}")))?;
    let mut format = probed.format;

    let track = format
        .default_track()
        .ok_or_else(|| AppError::Audio("no audio track found".into()))?;
    let track_id = track.id;
    let mut decoder = symphonia::default::get_codecs()
        .make(&track.codec_params, &DecoderOptions::default())
        .map_err(|e| AppError::Audio(format!("unsupported codec: {e}")))?;

    let sample_rate = track
        .codec_params
        .sample_rate
        .ok_or_else(|| AppError::Audio("unknown sample rate".into()))?;
    let channels = track.codec_params.channels.map(|c| c.count()).unwrap_or(2);

    let mut out: Vec<f32> = Vec::new();
    // SampleBuffer is re-created whenever the stream spec changes (rare).
    let mut current_spec: Option<(u32, usize)> = None;
    let mut sbuf: Option<SampleBuffer<f32>> = None;
    let mut skip = 0usize;

    loop {
        let packet = match format.next_packet() {
            Ok(p) => p,
            Err(SymphoniaError::IoError(e)) if e.kind() == std::io::ErrorKind::UnexpectedEof => {
                break
            }
            Err(SymphoniaError::ResetRequired) => break,
            Err(e) => return Err(AppError::Audio(format!("decode error: {e}"))),
        };
        if packet.track_id() != track_id {
            continue;
        }
        // Skip the first couple of packets (metadata priming).
        if skip < 2 {
            skip += 1;
            continue;
        }
        match decoder.decode(&packet) {
            Ok(decoded) => {
                let spec = *decoded.spec();
                let spec_key = (spec.rate, spec.channels.count());
                if current_spec != Some(spec_key) {
                    sbuf = Some(SampleBuffer::new(decoded.capacity() as u64, spec));
                    current_spec = Some(spec_key);
                }
                let sbuf = sbuf.as_mut().unwrap();
                sbuf.copy_interleaved_ref(decoded);
                // Downmix to mono.
                let frames = sbuf.samples().len() / channels.max(1);
                for f in 0..frames {
                    let mut acc = 0.0f32;
                    for c in 0..channels {
                        acc += sbuf.samples()[f * channels + c];
                    }
                    out.push(acc / channels as f32);
                }
            }
            Err(SymphoniaError::DecodeError(_)) => continue,
            Err(e) => return Err(AppError::Audio(format!("decode error: {e}"))),
        }
    }

    Ok(DecodedAudio {
        samples: out,
        sample_rate,
    })
}
