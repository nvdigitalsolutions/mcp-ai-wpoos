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
//! Capture modes (Proposal 144 §4.3):
//!
//! - **Resident** — the stream runs continuously so the pre-roll ring always
//!   holds audio from before key-down. The OS microphone-in-use indicator
//!   stays lit while the app runs. This is the historical behaviour.
//! - **On-demand** — the stream is paused when idle and resumed on key-down.
//!   The indicator clears while paused and the audio endpoint can idle, at the
//!   cost of the pre-roll window and a resume latency (`resume()` waits for
//!   the first real callback before the caller starts an utterance).
//!
//! PTT semantics mean we never *listen*: we retain a short rolling window,
//! never written to disk.

use std::sync::atomic::{AtomicBool, AtomicU64, Ordering};
use std::sync::mpsc::{channel, RecvTimeoutError, Sender};
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant};

use cpal::traits::{DeviceTrait, HostTrait, StreamTrait};

use crate::audio::ring_buffer::RingBuffer;
use crate::error::{AppError, AppResult};

/// Worker-thread control messages.
enum Control {
    Pause,
    Resume,
    Stop,
}

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
    /// Controls the worker (pause/resume/shutdown). Sending fails harmlessly
    /// if the thread already exited.
    control_tx: Option<Sender<Control>>,
    /// Whether the stream is currently paused (worker-owned truth).
    paused: Arc<AtomicBool>,
    /// How long to wait for the first callback after `Resume`.
    resume_timeout_ms: u64,
    /// Measured pause -> first-callback latency of the last resume.
    last_resume_ms: Arc<AtomicU64>,
}

impl MicCapture {
    /// Open the input device on a worker thread and return the shared
    /// handle. `device_name` selects a specific device by display name
    /// (Settings picker); `None` means the OS default. The stream runs
    /// until the handle is dropped; the worker thread owns it meanwhile.
    pub fn start(
        ring_capacity_secs: usize,
        device_name: Option<&str>,
        resume_timeout_ms: u64,
    ) -> AppResult<Self> {
        let (tx, rx) = channel::<AppResult<(u32, u16, String, Arc<Mutex<RingBuffer>>)>>();
        let (control_tx, control_rx) = channel::<Control>();
        let capacity = ring_capacity_secs.max(5);
        let requested = device_name.map(|s| s.to_string());
        let stats = Arc::new(StreamStats::default());
        let worker_stats = stats.clone();
        let paused = Arc::new(AtomicBool::new(false));
        let worker_paused = paused.clone();

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
                // Hold the stream (and the device identity) on this thread
                // until Stop fires — dropping a playing stream stops capture,
                // so it must never drop before shutdown. `ring` is kept here
                // so a Pause can discard pre-pause audio.
                let _device_identity = (device_name, sample_rate, channels);
                loop {
                    match control_rx.recv_timeout(Duration::from_millis(250)) {
                        Ok(Control::Pause) => {
                            if !worker_paused.swap(true, Ordering::SeqCst) {
                                // Pre-pause samples must not resurface through a
                                // pre-roll window on the next utterance.
                                ring.lock().unwrap().clear();
                                if let Err(e) = stream.pause() {
                                    tracing::warn!("microphone pause failed: {e}");
                                    worker_paused.store(false, Ordering::SeqCst);
                                }
                            }
                        }
                        Ok(Control::Resume) => {
                            if worker_paused.swap(false, Ordering::SeqCst) {
                                if let Err(e) = stream.play() {
                                    tracing::warn!("microphone resume failed: {e}");
                                    worker_paused.store(true, Ordering::SeqCst);
                                }
                            }
                        }
                        Ok(Control::Stop) => break,
                        Err(RecvTimeoutError::Timeout) => {}
                        Err(RecvTimeoutError::Disconnected) => break,
                    }
                }
            })
            .map_err(|e| AppError::Audio(e.to_string()))?;
        // Detach: the thread outlives this function by design; the control
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
            control_tx: Some(control_tx),
            paused,
            resume_timeout_ms: resume_timeout_ms.max(1),
            last_resume_ms: Arc::new(AtomicU64::new(0)),
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

    // ---- Capture modes (Proposal 144 §4.3) -------------------------------

    /// Whether the stream is currently paused (On-demand mode at idle).
    pub fn is_paused(&self) -> bool {
        self.paused.load(Ordering::Relaxed)
    }

    /// Pause -> first-callback latency of the last resume, in ms.
    pub fn last_resume_ms(&self) -> u64 {
        self.last_resume_ms.load(Ordering::Relaxed)
    }

    /// Resume capture and wait for the first real callback.
    ///
    /// Returning only once audio is flowing is what stops On-demand mode from
    /// clipping the first syllable: the caller opens its utterance window
    /// after this returns, not after the key-down. Returns the measured
    /// latency (0 when the stream was already running).
    pub fn resume(&self) -> AppResult<u64> {
        if !self.paused.load(Ordering::Relaxed) {
            return Ok(0);
        }
        let before = self.callback_count();
        self.send(Control::Resume)?;

        let started = Instant::now();
        let timeout = Duration::from_millis(self.resume_timeout_ms);
        while self.callback_count() == before {
            if started.elapsed() >= timeout {
                return Err(AppError::Audio(format!(
                    "microphone did not resume within {} ms",
                    self.resume_timeout_ms
                )));
            }
            std::thread::sleep(Duration::from_millis(2));
        }
        let elapsed = started.elapsed().as_millis() as u64;
        self.last_resume_ms.store(elapsed, Ordering::Relaxed);
        Ok(elapsed)
    }

    /// Pause capture. The stream object stays alive, so a later `resume()` is
    /// cheap and the OS indicator clears while paused.
    pub fn pause(&self) -> AppResult<()> {
        self.send(Control::Pause)
    }

    fn send(&self, control: Control) -> AppResult<()> {
        self.control_tx
            .as_ref()
            .ok_or_else(|| AppError::Audio("microphone worker is not running".into()))?
            .send(control)
            .map_err(|_| AppError::Audio("microphone worker stopped".into()))
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
        if let Some(tx) = self.control_tx.take() {
            let _ = tx.send(Control::Stop);
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

    // Reused across callbacks: allocating in the audio callback is a real
    // (if small) glitch/energy cost, and the buffer never needs to grow.
    let mut mono_buf: Vec<f32> = Vec::new();

    let stream = device.build_input_stream(
        &stream_config,
        move |data: &[f32], _info| {
            callbacks.fetch_add(1, Ordering::Relaxed);
            *last_callback.lock().unwrap() = Some(Instant::now());
            // Downmix interleaved multi-channel audio to mono.
            mono_buf.clear();
            if channels_usize == 1 {
                mono_buf.extend_from_slice(data);
            } else {
                mono_buf.extend(
                    data.chunks(channels_usize)
                        .map(|frame| frame.iter().sum::<f32>() / channels_usize as f32),
                );
            }
            writer.lock().unwrap().push(&mono_buf);
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

    #[test]
    fn rms_and_peak_of_silence_are_zero() {
        assert_eq!(rms_peak(&[]), (0.0, 0.0));
        assert_eq!(rms_peak(&[0.0, 0.0]), (0.0, 0.0));
    }

    /// The regression that shipped "no speech detected" on healthy mics:
    /// dropping the stream stopped capture. Requires a real audio device,
    /// so it is ignored in CI — run it manually on a dev machine:
    /// `cargo test -- --ignored live_capture_delivers_samples`
    #[test]
    #[ignore = "requires a real audio device"]
    fn live_capture_delivers_samples() {
        let mic = MicCapture::start(5, None, 250).expect("open default input device");
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

    /// On-demand round trip: pause stops callbacks, resume restarts them and
    /// reports a latency. Ignored in CI (no audio device).
    #[test]
    #[ignore = "requires a real audio device"]
    fn live_pause_resume_round_trip() {
        let mic = MicCapture::start(5, None, 2000).expect("open default input device");
        let deadline = Instant::now() + Duration::from_secs(5);
        while Instant::now() < deadline && mic.callback_count() == 0 {
            std::thread::sleep(Duration::from_millis(20));
        }
        assert!(mic.callback_count() > 0, "capture never started");

        mic.pause().expect("pause");
        std::thread::sleep(Duration::from_millis(300));
        assert!(mic.is_paused(), "pause was not applied");
        let paused_at = mic.callback_count();
        std::thread::sleep(Duration::from_millis(300));
        assert_eq!(
            mic.callback_count(),
            paused_at,
            "callbacks continued while paused"
        );

        let latency = mic.resume().expect("resume");
        assert!(!mic.is_paused(), "resume was not applied");
        assert!(
            mic.callback_count() > paused_at,
            "no callbacks after resume"
        );
        assert!(latency <= 2000, "resume latency exceeded the timeout");
        drop(mic);
    }
}
