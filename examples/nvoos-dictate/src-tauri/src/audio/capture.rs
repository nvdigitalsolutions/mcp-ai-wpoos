//! Resident microphone capture feeding the pre-roll ring buffer.
//!
//! Architecture note: `cpal::Stream` is `!Send + !Sync` on WASAPI (cpal
//! marks it `NotSendSyncAcrossAllPlatforms`). The stream therefore lives and
//! dies on a dedicated worker thread; the shared handle is only the ring
//! buffer (`Arc<Mutex<RingBuffer>>`) plus health counters, which is what
//! `AppState` keeps.
//!
//! **The stream must stay alive on the worker thread**: dropping a playing
//! `cpal::Stream` stops capture. A build that returned the ring but let the
//! stream drop at the end of `build_stream` captured nothing at all (the
//! ring stayed empty, dictation reported "no speech detected"). The worker
//! loop owns the stream for the handle's lifetime.
//!
//! PTT semantics mean we never *listen*, we only retain a short rolling
//! window (never written to disk). The OS shows its own microphone-in-use
//! indicator while the stream is open.

use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::mpsc::{channel, Sender};
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant};

use cpal::traits::{DeviceTrait, HostTrait, StreamTrait};

use crate::audio::ring_buffer::RingBuffer;
use crate::error::{AppError, AppResult};

/// Live health of the resident stream, updated by the audio callbacks and
/// surfaced on the Diagnostics page ("is capture actually delivering?").
#[derive(Default)]
struct StreamStats {
    callbacks: Arc<AtomicU64>,
    last_callback: Arc<Mutex<Option<Instant>>>,
    errors: Arc<AtomicU64>,
    last_error: Arc<Mutex<Option<String>>>,
}

/// Shared, thread-safe microphone handle.
pub struct MicCapture {
    ring: Arc<Mutex<RingBuffer>>,
    sample_rate: u32,
    channels: u16,
    device_name: String,
    stats: Arc<StreamStats>,
    /// Wakes the worker thread so it can drop the cpal stream (device swap,
    /// shutdown). Sending fails harmlessly if the thread already exited.
    stop_tx: Option<Sender<()>>,
}

impl MicCapture {
    /// Open the input device on a worker thread and return the shared
    /// handle. `device_name` selects a specific device by display name
    /// (Settings picker); `None` means the OS default. The stream runs
    /// until the handle is dropped; the worker thread owns it meanwhile.
    pub fn start(ring_capacity_secs: usize, device_name: Option<&str>) -> AppResult<Self> {
        let (tx, rx) = channel::<AppResult<(u32, u16, String, Arc<Mutex<RingBuffer>>)>>();
        let (stop_tx, stop_rx) = channel::<()>();
        let capacity = ring_capacity_secs.max(5);
        let requested = device_name.map(|s| s.to_string());
        let stats = Arc::new(StreamStats::default());
        let worker_stats = stats.clone();

        let worker = std::thread::Builder::new()
            .name("nvoos-mic".into())
            .spawn(move || {
                let built = build_stream(capacity, requested.as_deref(), &worker_stats);
                let (stream, sample_rate, channels, device_name, ring) = match built {
                    Ok((stream, sample_rate, channels, device_name, ring)) => {
                        let _ = tx.send(Ok((
                            sample_rate,
                            channels,
                            device_name.clone(),
                            ring.clone(),
                        )));
                        (stream, sample_rate, channels, device_name, ring)
                    }
                    Err(e) => {
                        let _ = tx.send(Err(e));
                        return;
                    }
                };
                // Hold the stream (and the device handles) on this thread
                // until the stop channel fires — dropping a playing stream
                // stops capture, so it must never drop before shutdown.
                let _stream_holder = (stream, device_name, ring, sample_rate, channels);
                loop {
                    if stop_rx.try_recv().is_ok() {
                        break;
                    }
                    std::thread::park_timeout(Duration::from_millis(250));
                }
            })
            .map_err(|e| AppError::Audio(e.to_string()))?;
        // Detach: the thread outlives this function by design; the stop
        // channel (via Drop) is the only thing that ends it.
        drop(worker);

        let (sample_rate, channels, device_name, ring) =
            rx.recv().map_err(|e| AppError::Audio(e.to_string()))??;
        Ok(Self {
            ring,
            sample_rate,
            channels,
            device_name,
            stats,
            stop_tx: Some(stop_tx),
        })
    }

    pub fn sample_rate(&self) -> u32 {
        self.sample_rate
    }

    /// Capture channel count of the device's stream.
    pub fn channels(&self) -> u16 {
        self.channels
    }

    /// The capture device's display name (diagnostics: "which mic am I
    /// actually recording?" — the default device is not always the one the
    /// user is speaking into).
    pub fn device_name(&self) -> &str {
        &self.device_name
    }

    /// Live input level — RMS + peak over the most recent ~250 ms. Drives
    /// the diagnostics level meter; a flat ~0.0 reading means the app is not
    /// hearing the selected microphone (muted, unplugged, or wrong device).
    pub fn live_level(&self) -> (f32, f32) {
        let n = (self.sample_rate as usize / 4).max(256);
        rms_peak(&self.snapshot(n))
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

    /// Absolute position (total samples captured since start).
    pub fn position(&self) -> usize {
        self.ring.lock().unwrap().position()
    }

    /// Copy samples between two absolute positions.
    pub fn snapshot_range(&self, from: usize, to: usize) -> Vec<f32> {
        self.ring.lock().unwrap().snapshot_range(from, to)
    }

    // ---- Stream health (diagnostics) -------------------------------------

    /// Audio callbacks delivered since this handle started.
    pub fn callback_count(&self) -> u64 {
        self.stats.callbacks.load(Ordering::Relaxed)
    }

    /// Milliseconds since the last audio callback (`u64::MAX` if none yet).
    /// A stream that stalls after callbacks began = the device stopped
    /// delivering (unplugged, taken over by another app, driver).
    pub fn stall_ms(&self) -> u64 {
        match *self.stats.last_callback.lock().unwrap() {
            Some(at) => at.elapsed().as_millis() as u64,
            None => u64::MAX,
        }
    }

    /// Stream error callbacks seen so far.
    pub fn stream_error_count(&self) -> u64 {
        self.stats.errors.load(Ordering::Relaxed)
    }

    /// The most recent stream error, if any.
    pub fn last_stream_error(&self) -> Option<String> {
        self.stats.last_error.lock().unwrap().clone()
    }
}

impl Drop for MicCapture {
    fn drop(&mut self) {
        if let Some(tx) = self.stop_tx.take() {
            let _ = tx.send(());
        }
    }
}

/// Pick the requested device by display name; fall back to the system
/// default when the name is unset or no longer resolves (unplugged devices).
fn select_device(host: &cpal::Host, requested: Option<&str>) -> Option<cpal::Device> {
    if let Some(name) = requested {
        if let Ok(devices) = host.input_devices() {
            for device in devices {
                if device.name().map(|n| n == name).unwrap_or(false) {
                    return Some(device);
                }
            }
        }
        tracing::warn!(
            requested = name,
            "requested input device not found; using the system default"
        );
    }
    host.default_input_device()
}

/// RMS + peak of a mono buffer; `(0.0, 0.0)` for empty input. Shared by the
/// live level meter (capture) and the dictation VAD/fallback statistics.
pub(crate) fn rms_peak(samples: &[f32]) -> (f32, f32) {
    if samples.is_empty() {
        return (0.0, 0.0);
    }
    let mut peak = 0.0f32;
    let mut sum = 0.0f64;
    for &s in samples {
        peak = peak.max(s.abs());
        sum += (s as f64) * (s as f64);
    }
    ((sum / samples.len() as f64).sqrt() as f32, peak)
}

/// Build + play the stream, returning the live stream together with the
/// shared ring buffer, rate, and device identity. The caller must hold the
/// returned stream — dropping a playing stream stops capture.
/// Runs entirely on the mic worker thread (cpal handles are not Send).
#[allow(clippy::type_complexity)] // the tuple is consumed immediately by the worker
fn build_stream(
    ring_capacity_secs: usize,
    requested_device: Option<&str>,
    stats: &StreamStats,
) -> AppResult<(cpal::Stream, u32, u16, String, Arc<Mutex<RingBuffer>>)> {
    let host = cpal::default_host();
    let device = select_device(&host, requested_device)
        .ok_or_else(|| AppError::Audio("no input device available".into()))?;
    let device_name = device
        .name()
        .unwrap_or_else(|_| "unknown device".to_string());
    let supported = device.default_input_config()?;
    let sample_rate = supported.sample_rate().0;
    let channels = supported.channels();
    let stream_config: cpal::StreamConfig = supported.config();

    let ring = Arc::new(Mutex::new(RingBuffer::new(
        sample_rate as usize * ring_capacity_secs,
    )));
    let writer = ring.clone();
    let channels_usize = channels as usize;
    let callbacks = stats.callbacks.clone();
    let last_callback = stats.last_callback.clone();
    let errors = stats.errors.clone();
    let last_error = stats.last_error.clone();

    let stream = device.build_input_stream(
        &stream_config,
        move |data: &[f32], _info| {
            callbacks.fetch_add(1, Ordering::Relaxed);
            *last_callback.lock().unwrap() = Some(Instant::now());
            // Downmix interleaved multi-channel audio to mono.
            let mono: Vec<f32> = if channels_usize == 1 {
                data.to_vec()
            } else {
                data.chunks(channels_usize)
                    .map(|frame| frame.iter().sum::<f32>() / channels_usize as f32)
                    .collect()
            };
            writer.lock().unwrap().push(&mono);
        },
        move |err| {
            errors.fetch_add(1, Ordering::Relaxed);
            *last_error.lock().unwrap() = Some(err.to_string());
            tracing::warn!("mic stream error: {err}");
        },
        None,
    )?;
    stream.play()?;

    Ok((stream, sample_rate, channels, device_name, ring))
}

#[cfg(test)]
mod tests {
    use super::*;

    /// The regression that shipped "no speech detected" on healthy mics:
    /// dropping the stream stopped capture. Requires a real audio device,
    /// so it is ignored in CI — run it manually on a dev machine:
    /// `cargo test -- --ignored live_capture_delivers_samples`
    #[test]
    #[ignore = "requires a real audio device"]
    fn live_capture_delivers_samples() {
        let mic = MicCapture::start(5, None).expect("open default input device");
        let deadline = Instant::now() + Duration::from_secs(5);
        while Instant::now() < deadline && mic.callback_count() == 0 {
            std::thread::sleep(Duration::from_millis(50));
        }
        assert!(
            mic.callback_count() > 0,
            "capture delivered no callbacks in 5 s — the stream is being dropped early"
        );
        assert!(
            mic.buffered_samples() > 0,
            "callbacks fired but the ring stayed empty"
        );
        assert_eq!(mic.stream_error_count(), 0);
        drop(mic);
    }
}
