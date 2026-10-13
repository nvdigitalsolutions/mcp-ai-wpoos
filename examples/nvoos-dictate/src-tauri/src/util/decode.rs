//! Decode audio files (m4a/mp3/wav/flac/ogg) and, for media containers,
//! demux the audio track out of video (mp4/mov/mkv/webm) to mono f32 PCM via
//! Symphonia (pure Rust — no FFmpeg sidecar). Used by "Transcribe file…" /
//! "Transcribe media…".
//!
//! Symphonia demuxes MKV, ISO/MP4 (incl. MOV), and WebM natively; this module
//! only needs the *audio* track, whose codecs (AAC/Opus/Vorbis/FLAC/PCM)
//! Symphonia already decodes — so video ingestion is a decode-seam change,
//! not a new dependency.

use std::path::Path;

use symphonia::core::audio::SampleBuffer;
use symphonia::core::codecs::DecoderOptions;
use symphonia::core::errors::Error as SymphoniaError;
use symphonia::core::formats::FormatOptions;
use symphonia::core::io::MediaSourceStream;
use symphonia::core::meta::MetadataOptions;
use symphonia::core::probe::Hint;

use crate::error::{AppError, AppResult};

/// Hard cap on retained samples (memory bound for long media). 12 h @ 16 kHz
/// ≈ 691 M samples; anything beyond that is rejected rather than OOM'd.
pub const MAX_MEDIA_SAMPLES: usize = 700_000_000;

/// The container a decoded file came from.
#[derive(Debug, Clone, Copy, PartialEq, Eq, serde::Serialize, serde::Deserialize)]
pub enum MediaContainer {
    /// Audio-only (mp3/m4a/wav/flac/ogg/aiff/caf).
    Audio,
    /// A video container whose audio track was demuxed.
    Video,
}

/// Decoded media: mono f32 PCM plus the metadata the transcription path needs.
#[derive(Debug, Clone, serde::Serialize)]
pub struct DecodedMedia {
    pub samples: Vec<f32>,
    pub sample_rate: u32,
    /// Total duration in ms (from container metadata, or derived from samples).
    pub duration_ms: i64,
    /// Whether the source was a video container (audio track demuxed out).
    pub container: MediaContainer,
}

/// The track + params we actually decode, captured as copyable primitives
/// during the scan loop (Sympy's `CodecParameters` carries a non-`Clone`
/// `CodecType`, so we cannot store or clone it directly).
#[derive(Debug, Clone, Copy)]
struct AudioSelection {
    track_id: u32,
    sample_rate: u32,
    channels: usize,
    duration_ms: i64,
}

/// Decode any supported audio/video file into mono f32 PCM + metadata.
///
/// Track selection: a video container lists its (video + audio) tracks. We
/// pick the **first track Symphonia can construct a decoder for** — an audio
/// codec — because the video codecs are not enabled/registered, so
/// `get_codecs().make()` fails for them. Any track that fails to make a
/// decoder is counted as "non-audio" (in practice, a video track), which is
/// what marks the container as video.
pub fn decode_file(path: &Path) -> AppResult<DecodedMedia> {
    let file = std::fs::File::open(path)?;
    let mss = MediaSourceStream::new(Box::new(file), Default::default());

    // Register the extension so MP4/MKV/WebM containers probe correctly
    // (a bare `Hint::new()` only does the generic probe).
    let mut hint = Hint::new();
    if let Some(ext) = path.extension().and_then(|e| e.to_str()) {
        hint.with_extension(ext);
    }

    let probed = symphonia::default::get_probe()
        .format(
            &hint,
            mss,
            &FormatOptions::default(),
            &MetadataOptions::default(),
        )
        .map_err(|e| AppError::Audio(format!("unsupported media format: {e}")))?;
    let mut format = probed.format;

    // Scan tracks: the first decodable (audio) track wins; tracks that cannot
    // be decoded are treated as non-audio (video) for classification.
    let mut selection: Option<AudioSelection> = None;
    let mut non_audio_tracks = 0usize;
    for track in format.tracks().iter() {
        match symphonia::default::get_codecs()
            .make(&track.codec_params, &DecoderOptions::default())
            .map(|_| track)
        {
            Ok(t) => {
                if selection.is_none() {
                    let sample_rate = t.codec_params.sample_rate.unwrap_or(16000);
                    let channels = t.codec_params.channels.map(|c| c.count()).unwrap_or(2);
                    let duration_ms = t
                        .codec_params
                        .n_frames
                        .and_then(|frames| t.codec_params.time_base.map(|tb| tb.calc_time(frames)))
                        .map(|time| (time.seconds as f64 + time.frac) * 1000.0)
                        .map(|ms| ms as i64)
                        .unwrap_or(0);
                    selection = Some(AudioSelection {
                        track_id: t.id,
                        sample_rate,
                        channels,
                        duration_ms,
                    });
                }
            }
            Err(_) => non_audio_tracks += 1,
        }
    }

    let sel = selection.ok_or_else(|| AppError::Audio("no audio track found".into()))?;

    // Re-open the decoder against the (single) audio track by re-scanning —
    // we already know one exists, so this cannot fail.
    let track = format
        .tracks()
        .iter()
        .find(|t| t.id == sel.track_id)
        .ok_or_else(|| AppError::Audio("no audio track found".into()))?;
    let mut decoder = symphonia::default::get_codecs()
        .make(&track.codec_params, &DecoderOptions::default())
        .map_err(|e| AppError::Audio(format!("unsupported codec: {e}")))?;

    let sample_rate = sel.sample_rate;
    let channels = sel.channels;
    let metric_duration_ms = sel.duration_ms;

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
        if packet.track_id() != sel.track_id {
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
                if out.len() > MAX_MEDIA_SAMPLES {
                    return Err(AppError::Audio(format!(
                        "media exceeds the {MAX_MEDIA_SAMPLES}-sample limit"
                    )));
                }
            }
            Err(SymphoniaError::DecodeError(_)) => continue,
            Err(e) => return Err(AppError::Audio(format!("decode error: {e}"))),
        }
    }

    // Derive duration from decoded samples when the container didn't report it.
    let duration_ms = if metric_duration_ms > 0 {
        metric_duration_ms
    } else if sample_rate > 0 {
        (out.len() as f64 / sample_rate as f64 * 1000.0) as i64
    } else {
        0
    };

    let container = if non_audio_tracks > 0 {
        MediaContainer::Video
    } else {
        MediaContainer::Audio
    };

    Ok(DecodedMedia {
        samples: out,
        sample_rate,
        duration_ms,
        container,
    })
}
