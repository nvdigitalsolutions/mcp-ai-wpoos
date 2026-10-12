//! Dictation controller: the orchestration loop.
//!
//! Flow (Windows PTT):
//!   hotkey Pressed  -> arm recording (pre-roll preserved), show overlay
//!   hotkey Released -> snapshot pre-roll + utterance -> STT -> Stage-1
//!                      cleanup -> (optional Stage-2 polish) -> paste ->
//!                      history -> archive -> resolve pending MCP dictate
//!   Esc             -> cancel, discard audio
//!   double-tap      -> hands-free toggle (next press finalizes)
//!
//! Invariants:
//! - Stage-1 cleanup output is ALWAYS paste-ready; a provider failure or
//!   timeout can never block the paste (decision record 0001).
//! - Audio samples exist only in memory (the ring buffer); nothing is
//!   persisted unless a meeting recording is in progress.
//! - `e2e_ms` (key-up -> pasted) is recorded per transcript — the
//!   observability signal for latency regressions.

use std::sync::mpsc::{channel, Receiver, Sender};
use std::sync::{Arc, Mutex, OnceLock};
use std::time::Instant;

use tauri::{AppHandle, Emitter, Manager};
use tokio::sync::oneshot;

use crate::audio::capture::MicCapture;
use crate::cleanup;
use crate::config::AppCategory;
use crate::error::AppResult;
use crate::hotkey::{platform_listener, HotkeyEvent, HotkeyListener};
use crate::state::AppState;

/// Dictation phases.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum Phase {
    Idle,
    Recording { toggle: bool, press_pos: usize },
}

/// Trailing audio kept after key-up so the final phoneme is not clipped.
const RELEASE_TAIL_MS: usize = 300;

/// Floor for the energy-VAD gate: quiet speech must still count. The VAD
/// only trims silence edges for PTT — it is never the final gate on whether
/// the user spoke.
const VAD_MIN_THRESHOLD: f32 = 0.02;
/// Ceiling for the noise-adapted gate.
const VAD_MAX_THRESHOLD: f32 = 0.15;
/// The gate sits this many times above the window's quietest 10th-percentile
/// frame (WebRTC-style noise gating): a raised floor (fans, hum) gets trimmed
/// instead of transcribed, while speech above it always passes.
const VAD_NOISE_RATIO: f32 = 6.0;
/// A window peaking louder than this carries real input even when the VAD
/// found no segments — transcribe it anyway. False negatives must never drop
/// user audio; muted/unplugged mics stay errors because they peak at ~0.
const VAD_FALLBACK_PEAK: f32 = 0.004;

/// Pending MCP `dictate` request (hint + completion channel).
type PendingDictate = (Option<String>, oneshot::Sender<Result<String, String>>);
static PENDING: OnceLock<Mutex<Option<PendingDictate>>> = OnceLock::new();

/// Diagnostics snapshot of the most recent dictation attempt — the
/// "why did it say no speech" numbers, kept in memory only.
#[derive(Debug, Clone, serde::Serialize)]
pub struct LastDictationStats {
    /// Captured window length (pre-roll + utterance + tail), ms.
    pub window_ms: i64,
    /// Key-down to key-up duration, ms.
    pub utterance_ms: i64,
    pub sample_rate: u32,
    /// Window RMS and peak (0.0 when nothing was captured).
    pub rms: f32,
    pub peak: f32,
    /// The VAD gate in force for this window.
    pub vad_threshold: f32,
    pub engine: String,
    pub model: String,
    pub ok: bool,
    pub error: Option<String>,
    pub e2e_ms: Option<i64>,
}

static LAST_DICTATION: OnceLock<Mutex<Option<LastDictationStats>>> = OnceLock::new();

/// The most recent dictation attempt's stats (diagnostics page).
pub fn last_dictation_stats() -> Option<LastDictationStats> {
    LAST_DICTATION.get().and_then(|m| m.lock().unwrap().clone())
}

fn record_last_dictation(stats: LastDictationStats) {
    *LAST_DICTATION
        .get_or_init(|| Mutex::new(None))
        .lock()
        .unwrap() = Some(stats);
}

/// Dictation state events emitted to the webview.
#[derive(Debug, Clone, serde::Serialize)]
#[serde(tag = "state")]
enum UiEvent {
    #[serde(rename = "recording")]
    Recording { hint: Option<String> },
    #[serde(rename = "processing")]
    Processing,
    #[serde(rename = "done")]
    Done {
        text: String,
        raw: String,
        provider: String,
        e2e_ms: i64,
    },
    #[serde(rename = "error")]
    Error { message: String },
    #[serde(rename = "cancelled")]
    Cancelled,
    #[serde(rename = "idle")]
    Idle,
}

/// Owns the hotkey listeners for the app's lifetime.
struct HotkeyGuard {
    primary: Box<dyn HotkeyListener>,
    #[cfg(windows)]
    fallback: crate::hotkey::fallback::WinFallbackListener,
}

impl Drop for HotkeyGuard {
    fn drop(&mut self) {
        self.primary.stop();
        #[cfg(windows)]
        self.fallback.stop();
    }
}

pub struct DictationController;

impl DictationController {
    /// Start the microphone, engine, hotkey listeners, and the controller
    /// thread. Called from Tauri setup.
    pub fn spawn(app: AppHandle) -> AppResult<()> {
        let state = app.state::<AppState>();

        // Resident microphone.
        let settings = state.settings_snapshot();
        match MicCapture::start(
            settings.audio.ring_capacity_secs,
            settings.audio.input_device.as_deref(),
        ) {
            Ok(mic) => {
                *state.mic.lock().unwrap() = Some(Arc::new(mic));
            }
            Err(e) => {
                tracing::warn!("no microphone available at startup: {e}");
            }
        }

        // Resident engine, warmed off-thread.
        let app_for_engine = app.clone();
        tauri::async_runtime::spawn_blocking(move || {
            if let Err(e) = app_for_engine.state::<AppState>().ensure_engine() {
                tracing::warn!("engine failed to load: {e}");
                let _ = app_for_engine.emit(
                    "dictate://state",
                    UiEvent::Error {
                        message: format!("speech engine failed to load: {e}"),
                    },
                );
            }
        });

        // Hotkey listeners (primary + Windows fallback).
        let (tx, rx): (Sender<HotkeyEvent>, Receiver<HotkeyEvent>) = channel();
        let mut primary = platform_listener();
        primary.start(&settings.hotkey, tx.clone())?;

        #[cfg(windows)]
        let mut fallback = crate::hotkey::fallback::WinFallbackListener::new();
        #[cfg(windows)]
        fallback.start(&settings.hotkey, tx.clone())?;

        app.manage(HotkeyGuard {
            primary,
            #[cfg(windows)]
            fallback,
        });

        // Non-Windows toggle registration via the global-shortcut plugin.
        #[cfg(not(windows))]
        register_plugin_shortcut(&app, tx.clone());

        std::thread::Builder::new()
            .name("nvoos-dictate-ctl".into())
            .spawn(move || Self::run(app, rx))
            .map_err(|e| crate::error::AppError::Other(e.to_string()))?;

        Ok(())
    }

    fn run(app: AppHandle, rx: Receiver<HotkeyEvent>) {
        let mut phase = Phase::Idle;
        while let Ok(event) = rx.recv() {
            tracing::debug!(?event, ?phase, "dictation hotkey event");
            match (phase, event) {
                (Phase::Idle, HotkeyEvent::Pressed) => {
                    let hint = pending_hint();
                    let press_pos = mic_position(&app);
                    emit(&app, UiEvent::Recording { hint });
                    phase = Phase::Recording {
                        toggle: false,
                        press_pos,
                    };
                }
                (Phase::Idle, HotkeyEvent::DoubleTap) => {
                    let hint = pending_hint();
                    let press_pos = mic_position(&app);
                    emit(&app, UiEvent::Recording { hint });
                    phase = Phase::Recording {
                        toggle: true,
                        press_pos,
                    };
                }
                (
                    Phase::Recording {
                        toggle: false,
                        press_pos,
                    },
                    HotkeyEvent::Released { shift },
                ) => {
                    phase = Phase::Idle;
                    finalize(&app, shift, press_pos, mic_position(&app));
                }
                // Hands-free toggle: the double-tap's own key release must
                // NOT end the recording — only the next PTT press (or Esc)
                // does. Without this arm the toggle recording ends the
                // instant the double-tap key is released.
                (Phase::Recording { toggle: true, .. }, HotkeyEvent::Released { .. }) => {}
                (
                    Phase::Recording {
                        toggle: true,
                        press_pos,
                    },
                    HotkeyEvent::Pressed,
                ) => {
                    phase = Phase::Idle;
                    finalize(&app, false, press_pos, mic_position(&app));
                }
                (Phase::Recording { .. }, HotkeyEvent::Esc) => {
                    phase = Phase::Idle;
                    resolve_pending(Err("dictation cancelled".into()));
                    emit(&app, UiEvent::Cancelled);
                    emit_idle_after_delay(&app);
                }
                (Phase::Idle, HotkeyEvent::Esc) | (Phase::Idle, HotkeyEvent::Released { .. }) => {
                    // Ignore stray release/cancel.
                }
                // Key auto-repeat / double-tap while already recording:
                // ignore (PTT_DOWN already dedupes repeated downs).
                (Phase::Recording { toggle: false, .. }, HotkeyEvent::Pressed)
                | (Phase::Recording { .. }, HotkeyEvent::DoubleTap) => {}
            }
        }
    }
}

fn pending_hint() -> Option<String> {
    PENDING.get().and_then(|m| {
        m.lock()
            .unwrap()
            .as_ref()
            .and_then(|(hint, _)| hint.clone())
    })
}

fn resolve_pending(result: Result<String, String>) {
    if let Some(pending) = PENDING.get().and_then(|m| {
        let mut guard = m.lock().unwrap();
        guard.take()
    }) {
        let _ = pending.1.send(result);
    }
}

/// Arm a pending MCP dictate request; resolves when the next dictation
/// finishes or after `timeout_secs`.
pub fn request_dictation(
    hint: Option<String>,
    timeout_secs: u64,
) -> tokio::sync::oneshot::Receiver<Result<String, String>> {
    let (tx, rx) = oneshot::channel();
    PENDING
        .get_or_init(|| Mutex::new(None))
        .lock()
        .unwrap()
        .replace((hint.clone(), tx));

    let app_hint = hint;
    tauri::async_runtime::spawn(async move {
        tokio::time::sleep(std::time::Duration::from_secs(timeout_secs)).await;
        if let Some(pending) = PENDING.get().and_then(|m| {
            let mut guard = m.lock().unwrap();
            guard.take()
        }) {
            let _ = pending.1.send(Err(format!(
                "dictation timed out{}",
                app_hint.map(|h| format!(" ({h})")).unwrap_or_default()
            )));
        }
    });
    rx
}

fn emit(app: &AppHandle, event: UiEvent) {
    let _ = app.emit("dictate://state", event);
}

/// The resident mic's absolute sample position, or 0 when absent.
fn mic_position(app: &AppHandle) -> usize {
    app.state::<AppState>()
        .mic
        .lock()
        .unwrap()
        .as_ref()
        .map(|m| m.position())
        .unwrap_or(0)
}

/// Return the UI to the idle state shortly after a terminal event.
fn emit_idle_after_delay(app: &AppHandle) {
    let app = app.clone();
    std::thread::spawn(move || {
        std::thread::sleep(std::time::Duration::from_millis(1600));
        emit(&app, UiEvent::Idle);
    });
}

/// Finalize a dictation: snapshot, transcribe, clean, paste, persist.
fn finalize(app: &AppHandle, shift_raw: bool, press_pos: usize, release_pos: usize) {
    tracing::debug!(shift_raw, press_pos, release_pos, "finalize started");
    emit(app, UiEvent::Processing);
    let app = app.clone();
    std::thread::spawn(move || {
        let started = Instant::now();
        let result = finalize_inner(&app, shift_raw, started, press_pos, release_pos);
        match result {
            Ok((text, raw, provider, e2e_ms)) => {
                emit(
                    &app,
                    UiEvent::Done {
                        text: text.clone(),
                        raw,
                        provider: provider.to_string(),
                        e2e_ms,
                    },
                );
                resolve_pending(Ok(text));
                emit_idle_after_delay(&app);
            }
            Err(e) => {
                resolve_pending(Err(e.to_string()));
                emit(
                    &app,
                    UiEvent::Error {
                        message: e.to_string(),
                    },
                );
                emit_idle_after_delay(&app);
            }
        }
    });
}

/// Keep only VAD-detected speech segments (with a small pad) so the engine
/// never decodes long silences — faster and hallucination-free. Returns an
/// empty vector when the window contains no speech.
fn trim_to_speech_threshold(window: &[f32], rate: usize) -> (Vec<f32>, f32) {
    let chunk = (rate / 10).max(512);
    let threshold = adaptive_threshold(window, chunk);
    let mut vad = crate::audio::vad::create(threshold, 400, rate as u32);
    let samples = trim_to_speech_with(window, rate, vad.as_mut());
    (samples, threshold)
}

/// Per-window gate for the energy VAD: the fixed floor, raised toward the
/// observed quiet-frame level so steady background noise gets gated without
/// ever pushing the floor above quiet speech. The silero (probability) VAD
/// keeps its own permissive default — the scales are not comparable.
fn adaptive_threshold(window: &[f32], chunk: usize) -> f32 {
    if crate::audio::vad::implementation() == "silero" {
        return VAD_MIN_THRESHOLD;
    }
    let mut frames: Vec<f32> = window
        .chunks(chunk.max(1))
        .map(rms)
        .filter(|r| r.is_finite())
        .collect();
    if frames.is_empty() {
        return VAD_MIN_THRESHOLD;
    }
    frames.sort_by(|a, b| a.partial_cmp(b).unwrap_or(std::cmp::Ordering::Equal));
    let quiet = frames[frames.len() / 10]; // 10th-percentile frame = noise floor
    VAD_MIN_THRESHOLD.max((quiet * VAD_NOISE_RATIO).min(VAD_MAX_THRESHOLD))
}

/// RMS of a mono buffer.
fn rms(samples: &[f32]) -> f32 {
    crate::audio::capture::rms_peak(samples).0
}

/// If the VAD found no segments but the window clearly carries signal,
/// transcribe the whole window instead of dropping the utterance (a VAD
/// false negative must never lose speech). Digital silence stays empty so
/// muted/unplugged mics still produce the actionable error.
fn vad_empty_fallback(window: &[f32], samples: Vec<f32>) -> Vec<f32> {
    if !samples.is_empty() {
        return samples;
    }
    if crate::audio::capture::rms_peak(window).1 > VAD_FALLBACK_PEAK {
        window.to_vec()
    } else {
        Vec::new()
    }
}

/// Testable core: VAD-injected variant of [`trim_to_speech_threshold`].
fn trim_to_speech_with(
    window: &[f32],
    rate: usize,
    vad: &mut dyn crate::audio::vad::Vad,
) -> Vec<f32> {
    if window.is_empty() {
        return Vec::new();
    }
    let chunk = (rate / 10).max(512);
    let mut segments: Vec<(usize, usize)> = Vec::new();
    let mut seg_start: Option<usize> = None;
    let mut idx = 0;
    for c in window.chunks(chunk) {
        let speech = vad.process(c, rate as u32);
        if speech && seg_start.is_none() {
            seg_start = Some(idx);
        } else if !speech && seg_start.is_some() {
            segments.push((seg_start.take().unwrap(), idx));
        }
        idx += c.len();
    }
    if let Some(s) = seg_start {
        segments.push((s, idx));
    }
    if segments.is_empty() {
        return Vec::new();
    }
    let pad = (rate / 10).max(512);
    let mut out = Vec::new();
    for (s, e) in segments {
        let s = s.saturating_sub(pad);
        let e = (e + pad).min(window.len());
        out.extend_from_slice(&window[s..e]);
    }
    out
}

fn finalize_inner(
    app: &AppHandle,
    shift_raw: bool,
    started: Instant,
    press_pos: usize,
    release_pos: usize,
) -> AppResult<(String, String, String, i64)> {
    let state = app.state::<AppState>();
    let settings = state.settings_snapshot();

    // 1. Audio snapshot: pre-roll + the held utterance (bounded window).
    let mic_guard = state.mic.lock().unwrap();
    let mic = mic_guard
        .as_ref()
        .ok_or_else(|| crate::error::AppError::Audio("microphone unavailable".into()))?;
    let rate = mic.sample_rate() as usize;
    let pre_roll = (settings.audio.pre_roll_ms as usize * rate) / 1000;
    let tail = (RELEASE_TAIL_MS * rate) / 1000;
    let window = mic.snapshot_range(
        press_pos.saturating_sub(pre_roll),
        release_pos.saturating_add(tail),
    );
    // Positions raced (no mic at press time, etc.): fall back to a short
    // window of the most recent audio instead of transcribing everything.
    let window = if window.is_empty() {
        let fallback = (pre_roll + 5 * rate).min(mic.buffered_samples().max(pre_roll));
        mic.snapshot(fallback)
    } else {
        window
    };
    // Speech gating: trim the silence edges, but never drop audible audio
    // (a VAD false negative must not nuke the utterance).
    let (trimmed, vad_threshold) = trim_to_speech_threshold(&window, rate);
    let samples = vad_empty_fallback(&window, trimmed);
    let (window_rms, window_peak) = crate::audio::capture::rms_peak(&window);
    let mut stats = LastDictationStats {
        window_ms: (window.len() as i64 * 1000) / rate as i64,
        utterance_ms: release_pos.saturating_sub(press_pos) as i64 * 1000 / rate as i64,
        sample_rate: mic.sample_rate(),
        rms: window_rms,
        peak: window_peak,
        vad_threshold,
        engine: settings.engine.engine.clone(),
        model: settings.engine.model.clone(),
        ok: false,
        error: None,
        e2e_ms: None,
    };
    if samples.is_empty() {
        stats.error = Some(format!(
            "no speech detected — {} ms captured at RMS {:.4} / peak {:.4} on '{}' ({} Hz)",
            stats.window_ms,
            window_rms,
            window_peak,
            mic.device_name(),
            rate
        ));
        record_last_dictation(stats);
        return Err(crate::error::AppError::Audio(
            "no speech detected — the microphone signal was flat. Check the input \
             device and mute state; the Diagnostics page shows the live input level."
                .into(),
        ));
    }

    // 2. STT, 3. cleanup, 4. polish, 5. paste, 6. history/archive. Every
    // terminal outcome is recorded into the diagnostics snapshot.
    let outcome: AppResult<(String, String, String, i64)> = (|| {
        let engine = state.ensure_engine()?;
        let raw = engine.transcribe(
            &samples,
            mic.sample_rate(),
            settings.engine.language.as_deref(),
        )?;

        let category = foreground_category();
        let dictionary = state.db.dictionary()?;
        let stage1 = if settings.cleanup.deterministic_cleanup {
            cleanup::run_deterministic(&raw, category, &dictionary, shift_raw)
        } else {
            // Whitespace-only passthrough when the user disabled the rules.
            cleanup::run_deterministic(&raw, category, &dictionary, true)
        };

        let provider = cleanup::create_provider(&settings, state.secrets.as_ref())?;
        let mut final_text = stage1.clone();
        let mut provider_id: String = "disabled".to_string();
        // Metadata from the last polish round-trip: the site resolves the
        // assistant and hands back a chat session key — the transcript
        // archive route rejects requests without one.
        let mut polish_meta = crate::cleanup::providers::PolishMeta::default();
        if let Some(provider) = provider {
            let base_id = provider.id();
            provider_id = base_id.to_string();
            let wait_ms = settings.cleanup.wait_for_llm_ms;
            if wait_ms > 0 {
                let fut = provider.polish(&stage1, category);
                let outcome = tauri::async_runtime::block_on(async {
                    tokio::time::timeout(std::time::Duration::from_millis(wait_ms), fut).await
                });
                if let Ok(Ok((polished, meta))) = outcome {
                    polish_meta = meta;
                    if !polished.trim().is_empty() {
                        final_text = polished.trim().to_string();
                        // Record which model answered, for tuning (e.g.
                        // "nvoos:deepseek-flash").
                        if let Some(model) = polish_meta.model.as_deref() {
                            provider_id = format!("{base_id}:{model}");
                        }
                    }
                }
            }
        }

        // Paste (Stage-1 or polished text — never blocked by provider failure).
        state.paste.paste(&final_text)?;

        let e2e_ms = started.elapsed().as_millis() as i64;

        let target_app = foreground_process_name().unwrap_or_default();
        let _ = state.db.insert_transcript(
            &raw,
            &final_text,
            engine.id(),
            engine.model_id(),
            &target_app,
            &provider_id,
            e2e_ms,
        )?;

        if settings.nvoos.archive_enabled && state.has_nvoos_credential() {
            // The archive route requires the chat session key from the polish
            // round-trip; with polish disabled there is no session, so skip
            // with a warning instead of a guaranteed 400.
            if polish_meta.session_key.is_none() {
                tracing::warn!(
                    "archive skipped: no chat session key (enable Stage-2 polish to archive)"
                );
            } else if let Ok(client) = state.nvoos_client() {
                let row = crate::nvoos::TranscriptRecord {
                    assistant_id: polish_meta
                        .assistant_id
                        .clone()
                        .or_else(|| settings.nvoos.assistant_id.clone()),
                    session_key: polish_meta.session_key.clone(),
                    title: format!("nvoos-dictate — {target_app}"),
                    raw: raw.clone(),
                    clean: final_text.clone(),
                    target_app,
                    engine: engine.id().to_string(),
                    e2e_ms,
                    created_at: chrono::Utc::now().to_rfc3339(),
                };
                let app_handle = app.clone();
                tauri::async_runtime::spawn(async move {
                    if let Err(e) = client.archive_transcript(&row).await {
                        tracing::warn!("archive failed: {e}");
                    }
                    let _ = app_handle;
                });
            }
        }

        Ok((final_text, raw, provider_id, e2e_ms))
    })();

    match &outcome {
        Ok((_, _, _, e2e_ms)) => {
            stats.ok = true;
            stats.e2e_ms = Some(*e2e_ms);
        }
        Err(e) => {
            stats.error = Some(e.to_string());
        }
    }
    record_last_dictation(stats);
    outcome
}

fn foreground_category() -> AppCategory {
    #[cfg(windows)]
    {
        crate::util::win_foreground::current_category()
    }
    #[cfg(not(windows))]
    {
        AppCategory::Default
    }
}

fn foreground_process_name() -> Option<String> {
    #[cfg(windows)]
    {
        crate::util::win_foreground::foreground_process_name()
    }
    #[cfg(not(windows))]
    {
        None
    }
}

/// Non-Windows: toggle semantics via tauri-plugin-global-shortcut.
#[cfg(not(windows))]
fn register_plugin_shortcut(app: &AppHandle, tx: Sender<HotkeyEvent>) {
    use tauri_plugin_global_shortcut::{GlobalShortcutExt, ShortcutState};

    let tx = Arc::new(Mutex::new(tx));
    let recording = Arc::new(std::sync::atomic::AtomicBool::new(false));
    let r1 = recording.clone();
    let t1 = tx.clone();
    let _ =
        app.global_shortcut()
            .on_shortcut("Control+Shift+Space", move |_app, _shortcut, event| {
                if event.state() != ShortcutState::Pressed {
                    return;
                }
                let tx = t1.lock().unwrap().clone();
                // Toggle: first activation = press, second = release.
                let was_recording = r1.swap(true, std::sync::atomic::Ordering::SeqCst);
                if was_recording {
                    r1.store(false, std::sync::atomic::Ordering::SeqCst);
                    let _ = tx.send(HotkeyEvent::Released { shift: false });
                } else {
                    let _ = tx.send(HotkeyEvent::Pressed);
                }
            });
}

#[cfg(test)]
mod tests {
    use super::{
        adaptive_threshold, trim_to_speech_with, vad_empty_fallback, VAD_MAX_THRESHOLD,
        VAD_MIN_THRESHOLD,
    };
    use crate::audio::vad::EnergyVad;

    fn tone(hz: f32, seconds: f32, rate: usize, amplitude: f32) -> Vec<f32> {
        (0..(rate as f32 * seconds) as usize)
            .map(|i| (2.0 * std::f32::consts::PI * hz * i as f32 / rate as f32).sin() * amplitude)
            .collect()
    }

    #[test]
    fn trims_silence_and_keeps_speech() {
        let rate = 16_000;
        // 2 s silence, 1 s of a 440 Hz tone, 2 s silence.
        let mut audio = vec![0.0f32; 2 * rate];
        for i in 0..rate {
            audio.push((i as f32 * 440.0 * std::f32::consts::TAU / rate as f32).sin() * 0.5);
        }
        audio.extend_from_slice(&vec![0.0f32; 2 * rate]);

        let mut vad = EnergyVad::new(0.02, 400, rate as u32);
        let trimmed = trim_to_speech_with(&audio, rate, &mut vad);
        assert!(!trimmed.is_empty(), "speech must survive trimming");
        assert!(trimmed.len() < audio.len(), "silence must be trimmed");

        // Pure silence -> empty (never feed the engine hallucination fodder).
        let mut vad2 = EnergyVad::new(0.02, 400, rate as u32);
        assert!(trim_to_speech_with(&vec![0.0f32; 4 * rate], rate, &mut vad2).is_empty());
    }

    #[test]
    fn threshold_adapts_to_noise_floor() {
        let rate = 16_000;
        // Digital silence keeps the floor threshold.
        let silent = adaptive_threshold(&vec![0.0f32; rate], rate / 10);
        assert!(
            (silent - VAD_MIN_THRESHOLD).abs() < 1e-6,
            "silence threshold was {silent}"
        );
        // A raised noise floor (hum at RMS ~0.035) raises the gate so the
        // noise gets trimmed instead of transcribed.
        let noise = tone(120.0, 1.0, rate, 0.05);
        let adapted = adaptive_threshold(&noise, rate / 10);
        assert!(
            adapted > VAD_MIN_THRESHOLD && adapted <= VAD_MAX_THRESHOLD,
            "noise-adapted threshold was {adapted}"
        );
    }

    #[test]
    fn vad_miss_falls_back_to_full_window() {
        let rate = 16_000;
        // Quiet but real signal (peak above the fallback floor): a VAD false
        // negative must never drop the utterance.
        let quiet = tone(220.0, 0.5, rate, 0.012);
        assert!(!vad_empty_fallback(&quiet, Vec::new()).is_empty());
        // Detected segments pass through untouched.
        let segments = vec![0.1f32; 512];
        assert_eq!(vad_empty_fallback(&quiet, segments.clone()), segments);
        // Digital silence (muted/unplugged mic): still an error path.
        assert!(vad_empty_fallback(&vec![0.0f32; rate], Vec::new()).is_empty());
    }
}
