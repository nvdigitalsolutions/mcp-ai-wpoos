//! Meeting recorder: explicit record/stop, mic + optional system audio,
//! transcription on stop, transcript returned to the caller.
//!
//! Architecture note: `cpal::Stream` is `!Send + !Sync` on WASAPI, so all
//! streams live on a dedicated worker thread. `MeetingRecorder` itself is
//! `Send + Sync` (it only holds channels + atomics), which is what
//! `AppState` requires. Transcription also runs on the worker thread —
//! `SttEngine` is `Send + Sync`, so the engine handle crosses cleanly.
//!
//! Consent: recording is always user-initiated; the UI shows a persistent
//! "recording" indicator while active. Audio is discarded after
//! transcription unless `privacy.retain_meeting_audio` is enabled.

use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::mpsc::{channel, Receiver, Sender};
use std::sync::{Arc, Mutex};
use std::time::Instant;

use cpal::traits::{DeviceTrait, HostTrait, StreamTrait};

use crate::error::{AppError, AppResult};
use crate::meetings::chunker::Chunker;
use crate::stt::SttEngine;

/// Result of a finished meeting.
#[derive(Debug, Clone, serde::Serialize)]
pub struct MeetingSummary {
    pub id: i64,
    pub title: String,
    pub text: String,
    pub duration_ms: i64,
    pub system_audio: bool,
}

enum Cmd {
    Start {
        respond: Sender<AppResult<()>>,
    },
    Stop {
        engine: Arc<dyn SttEngine>,
        title: String,
        respond: Sender<AppResult<MeetingSummary>>,
    },
}

struct Active {
    started: Instant,
    mic: cpal::Stream,
    #[cfg(windows)]
    loopback: Option<cpal::Stream>,
    samples: Arc<Mutex<Vec<f32>>>,
    system_audio: bool,
}

impl Drop for Active {
    fn drop(&mut self) {
        // Explicit stop: dropping pauses both capture streams.
        let _ = self.mic.pause();
        #[cfg(windows)]
        if let Some(loopback) = &self.loopback {
            let _ = loopback.pause();
        }
    }
}

pub struct MeetingRecorder {
    tx: Mutex<Sender<Cmd>>,
    recording: Arc<AtomicBool>,
}

impl MeetingRecorder {
    pub fn new(sample_rate: u32) -> Self {
        let (tx, rx) = channel::<Cmd>();
        let recording = Arc::new(AtomicBool::new(false));
        let rec_flag = recording.clone();
        let worker = std::thread::Builder::new()
            .name("nvoos-meeting".into())
            .spawn(move || worker_loop(rx, rec_flag, sample_rate));
        // Detach — the worker lives for the app's lifetime.
        drop(worker);
        Self {
            tx: Mutex::new(tx),
            recording,
        }
    }

    pub fn is_recording(&self) -> bool {
        self.recording.load(Ordering::SeqCst)
    }

    /// Begin recording (mic always; system audio on Windows when available).
    pub fn start(&self) -> AppResult<()> {
        let (tx, rx) = channel();
        self.tx
            .lock()
            .unwrap()
            .send(Cmd::Start { respond: tx })
            .map_err(|e| AppError::Other(e.to_string()))?;
        rx.recv().map_err(|e| AppError::Other(e.to_string()))?
    }

    /// Stop recording and transcribe with the given engine.
    pub fn stop(&self, engine: Arc<dyn SttEngine>, title: &str) -> AppResult<MeetingSummary> {
        let (tx, rx) = channel();
        self.tx
            .lock()
            .unwrap()
            .send(Cmd::Stop {
                engine,
                title: title.to_string(),
                respond: tx,
            })
            .map_err(|e| AppError::Other(e.to_string()))?;
        rx.recv().map_err(|e| AppError::Other(e.to_string()))?
    }
}

fn worker_loop(rx: Receiver<Cmd>, recording: Arc<AtomicBool>, sample_rate: u32) {
    let mut active: Option<Active> = None;
    while let Ok(cmd) = rx.recv() {
        match cmd {
            Cmd::Start { respond } => {
                let result = if active.is_some() {
                    Err(AppError::Other(
                        "a meeting is already being recorded".into(),
                    ))
                } else {
                    match start_streams() {
                        Ok(streams) => {
                            recording.store(true, Ordering::SeqCst);
                            active = Some(streams);
                            Ok(())
                        }
                        Err(e) => Err(e),
                    }
                };
                let _ = respond.send(result);
            }
            Cmd::Stop {
                engine,
                title,
                respond,
            } => {
                let result = match active.take() {
                    Some(streams) => {
                        recording.store(false, Ordering::SeqCst);
                        let duration_ms = streams.started.elapsed().as_millis() as i64;
                        // Snapshot samples, then drop the streams (dropping
                        // stops both captures and releases the Arc clones).
                        let samples = streams.samples.lock().unwrap().clone();
                        let system_audio = streams.system_audio;
                        drop(streams);
                        transcribe(
                            &samples,
                            sample_rate,
                            engine.as_ref(),
                            &title,
                            duration_ms,
                            system_audio,
                        )
                    }
                    None => Err(AppError::Other("no meeting is being recorded".into())),
                };
                let _ = respond.send(result);
            }
        }
    }
}

fn start_streams() -> AppResult<Active> {
    let host = cpal::default_host();
    let device = host
        .default_input_device()
        .ok_or_else(|| AppError::Audio("no microphone available".into()))?;
    let supported = device.default_input_config()?;
    let channels = supported.channels() as usize;
    let stream_config: cpal::StreamConfig = supported.config();

    let samples = Arc::new(Mutex::new(Vec::new()));
    let mic_samples = samples.clone();
    let mic = device.build_input_stream(
        &stream_config,
        move |data: &[f32], _| {
            let mono: Vec<f32> = if channels == 1 {
                data.to_vec()
            } else {
                data.chunks(channels)
                    .map(|f| f.iter().sum::<f32>() / channels as f32)
                    .collect()
            };
            mic_samples.lock().unwrap().extend_from_slice(&mono);
        },
        |err| tracing::warn!("meeting mic error: {err}"),
        None,
    )?;
    mic.play()?;

    #[cfg(windows)]
    let (loopback, system_audio) = match crate::meetings::loopback::open_loopback(samples.clone()) {
        Ok(Some(stream)) => {
            let _ = stream.play();
            (Some(stream), true)
        }
        Ok(None) => (None, false),
        Err(e) => {
            tracing::warn!("system audio unavailable: {e}");
            (None, false)
        }
    };

    #[cfg(not(windows))]
    let (loopback, system_audio) = (None, false);

    Ok(Active {
        started: Instant::now(),
        mic,
        #[cfg(windows)]
        loopback,
        samples,
        system_audio,
    })
}

/// Transcribe: chunk if the engine needs it, then stitch.
fn transcribe(
    samples: &[f32],
    sample_rate: u32,
    engine: &dyn SttEngine,
    title: &str,
    duration_ms: i64,
    system_audio: bool,
) -> AppResult<MeetingSummary> {
    let text = if engine.supports_long_files() {
        engine.transcribe(samples, sample_rate, None)?
    } else {
        let mut chunker = Chunker::new(sample_rate);
        let mut parts = Vec::new();
        for (start, end) in chunker.split(samples) {
            if end <= start {
                continue;
            }
            let part = engine.transcribe(&samples[start..end], sample_rate, None)?;
            if !part.trim().is_empty() {
                parts.push(part.trim().to_string());
            }
        }
        parts.join("\n\n")
    };

    Ok(MeetingSummary {
        id: 0, // assigned by the caller after DB insert
        title: title.to_string(),
        text,
        duration_ms,
        system_audio,
    })
}
