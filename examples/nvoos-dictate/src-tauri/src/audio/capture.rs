//! Resident microphone capture feeding the pre-roll ring buffer.
//!
//! Architecture note: `cpal::Stream` is `!Send + !Sync` on WASAPI (cpal
//! marks it `NotSendSyncAcrossAllPlatforms`). The stream therefore lives and
//! dies on a dedicated worker thread; the shared handle is only the ring
//! buffer (`Arc<Mutex<RingBuffer>>`), which is what `AppState` keeps.
//!
//! PTT semantics mean we never *listen*, we only retain a short rolling
//! window (never written to disk). The OS shows its own microphone-in-use
//! indicator while the stream is open.

use std::sync::mpsc::channel;
use std::sync::{Arc, Mutex};

use cpal::traits::{DeviceTrait, HostTrait, StreamTrait};

use crate::audio::ring_buffer::RingBuffer;
use crate::error::{AppError, AppResult};

/// Shared, thread-safe microphone handle.
pub struct MicCapture {
    ring: Arc<Mutex<RingBuffer>>,
    sample_rate: u32,
}

impl MicCapture {
    /// Open the default input device on a worker thread and return the
    /// shared handle. The stream runs for the app's lifetime; the worker
    /// thread parks forever holding it.
    pub fn start(ring_capacity_secs: usize) -> AppResult<Self> {
        let (tx, rx) = channel::<AppResult<(u32, Arc<Mutex<RingBuffer>>)>>();
        let capacity = ring_capacity_secs.max(5);

        let worker = std::thread::Builder::new()
            .name("nvoos-mic".into())
            .spawn(move || {
                let result = build_stream(capacity);
                let _ = tx.send(result);
                // Hold the stream (and the device handles) on this thread
                // forever — dropping them stops capture.
                loop {
                    std::thread::park();
                }
            })
            .map_err(|e| AppError::Audio(e.to_string()))?;
        // Detach: the thread outlives this function by design.
        drop(worker);

        let (sample_rate, ring) = rx.recv().map_err(|e| AppError::Audio(e.to_string()))??;
        Ok(Self { ring, sample_rate })
    }

    pub fn sample_rate(&self) -> u32 {
        self.sample_rate
    }

    /// Snapshot the last `len_samples` samples (typically
    /// `pre_roll + utterance` at release time).
    pub fn snapshot(&self, len_samples: usize) -> Vec<f32> {
        self.ring.lock().unwrap().snapshot_last(len_samples)
    }

    /// Number of samples currently retained.
    pub fn buffered_samples(&self) -> usize {
        self.ring.lock().unwrap().len()
    }
}

/// Build + play the stream, returning the shared ring buffer + rate.
/// Runs entirely on the mic worker thread (cpal handles are not Send).
fn build_stream(ring_capacity_secs: usize) -> AppResult<(u32, Arc<Mutex<RingBuffer>>)> {
    let host = cpal::default_host();
    let device = host
        .default_input_device()
        .ok_or_else(|| AppError::Audio("no input device available".into()))?;
    let supported = device.default_input_config()?;
    let sample_rate = supported.sample_rate().0;
    let channels = supported.channels() as usize;
    let stream_config: cpal::StreamConfig = supported.config();

    let ring = Arc::new(Mutex::new(RingBuffer::new(
        sample_rate as usize * ring_capacity_secs,
    )));
    let writer = ring.clone();

    let stream = device.build_input_stream(
        &stream_config,
        move |data: &[f32], _info| {
            // Downmix interleaved multi-channel audio to mono.
            let mono: Vec<f32> = if channels == 1 {
                data.to_vec()
            } else {
                data.chunks(channels)
                    .map(|frame| frame.iter().sum::<f32>() / channels as f32)
                    .collect()
            };
            writer.lock().unwrap().push(&mono);
        },
        |err| tracing::warn!("mic stream error: {err}"),
        None,
    )?;
    stream.play()?;

    Ok((sample_rate, ring))
}
