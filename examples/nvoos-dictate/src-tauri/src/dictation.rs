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
    Recording { toggle: bool },
}

/// Pending MCP `dictate` request (hint + completion channel).
type PendingDictate = (Option<String>, oneshot::Sender<Result<String, String>>);
static PENDING: OnceLock<Mutex<Option<PendingDictate>>> = OnceLock::new();

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
        match MicCapture::start(settings.audio.ring_capacity_secs) {
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
            match (phase, event) {
                (Phase::Idle, HotkeyEvent::Pressed) => {
                    let hint = pending_hint();
                    set_recording(true);
                    emit(&app, UiEvent::Recording { hint });
                    phase = Phase::Recording { toggle: false };
                }
                (Phase::Idle, HotkeyEvent::DoubleTap) => {
                    let hint = pending_hint();
                    set_recording(true);
                    emit(&app, UiEvent::Recording { hint });
                    phase = Phase::Recording { toggle: true };
                }
                (Phase::Recording { .. }, HotkeyEvent::Released { shift }) => {
                    phase = Phase::Idle;
                    set_recording(false);
                    finalize(&app, shift);
                }
                (Phase::Recording { toggle: true }, HotkeyEvent::Pressed) => {
                    phase = Phase::Idle;
                    set_recording(false);
                    finalize(&app, false);
                }
                (Phase::Recording { .. }, HotkeyEvent::Esc) => {
                    phase = Phase::Idle;
                    set_recording(false);
                    resolve_pending(Err("dictation cancelled".into()));
                    emit(&app, UiEvent::Cancelled);
                }
                (Phase::Idle, HotkeyEvent::Esc) | (Phase::Idle, HotkeyEvent::Released { .. }) => {
                    // Ignore stray release/cancel.
                }
                // Key auto-repeat / double-tap while already recording:
                // ignore (PTT_DOWN already dedupes repeated downs).
                (Phase::Recording { toggle: false }, HotkeyEvent::Pressed)
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

fn set_recording(recording: bool) {
    #[cfg(windows)]
    crate::hotkey::win_hook::set_recording(recording);
    let _ = recording;
}

fn emit(app: &AppHandle, event: UiEvent) {
    let _ = app.emit("dictate://state", event);
}

/// Finalize a dictation: snapshot, transcribe, clean, paste, persist.
fn finalize(app: &AppHandle, shift_raw: bool) {
    emit(app, UiEvent::Processing);
    let app = app.clone();
    std::thread::spawn(move || {
        let started = Instant::now();
        let result = finalize_inner(&app, shift_raw, started);
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
            }
            Err(e) => {
                resolve_pending(Err(e.to_string()));
                emit(
                    &app,
                    UiEvent::Error {
                        message: e.to_string(),
                    },
                );
            }
        }
    });
}

fn finalize_inner(
    app: &AppHandle,
    shift_raw: bool,
    started: Instant,
) -> AppResult<(String, String, &'static str, i64)> {
    let state = app.state::<AppState>();
    let settings = state.settings_snapshot();

    // 1. Audio snapshot: pre-roll + everything recorded while the key was held.
    let mic_guard = state.mic.lock().unwrap();
    let mic = mic_guard
        .as_ref()
        .ok_or_else(|| crate::error::AppError::Audio("microphone unavailable".into()))?;
    let pre_roll = (settings.audio.pre_roll_ms as usize * mic.sample_rate() as usize) / 1000;
    let capacity = settings.audio.ring_capacity_secs * mic.sample_rate() as usize;
    let total = (pre_roll + capacity).min(mic.buffered_samples().max(pre_roll));
    let samples = mic.snapshot(total);

    // 2. STT.
    let engine = state.ensure_engine()?;
    let raw = engine.transcribe(
        &samples,
        mic.sample_rate(),
        settings.engine.language.as_deref(),
    )?;

    // 3. Stage-1 deterministic cleanup (always paste-ready).
    let category = foreground_category();
    let dictionary = state.db.dictionary()?;
    let stage1 = if settings.cleanup.deterministic_cleanup {
        cleanup::run_deterministic(&raw, category, &dictionary, shift_raw)
    } else {
        // Whitespace-only passthrough when the user disabled the rules.
        cleanup::run_deterministic(&raw, category, &dictionary, true)
    };

    // 4. Optional Stage-2 provider.
    let provider = cleanup::create_provider(&settings, state.secrets.as_ref())?;
    let mut final_text = stage1.clone();
    let mut provider_id: &'static str = "disabled";
    if let Some(provider) = provider {
        provider_id = provider.id();
        let wait_ms = settings.cleanup.wait_for_llm_ms;
        if wait_ms > 0 {
            let fut = provider.polish(&stage1, category);
            let outcome = tauri::async_runtime::block_on(async {
                tokio::time::timeout(std::time::Duration::from_millis(wait_ms), fut).await
            });
            if let Ok(Ok(polished)) = outcome {
                if !polished.trim().is_empty() {
                    final_text = polished.trim().to_string();
                }
            }
        }
    }

    // 5. Paste (Stage-1 or polished text — never blocked by provider failure).
    state.paste.paste(&final_text)?;

    let e2e_ms = started.elapsed().as_millis() as i64;

    // 6. History + optional archive.
    let target_app = foreground_process_name().unwrap_or_default();
    let _ = state.db.insert_transcript(
        &raw,
        &final_text,
        engine.id(),
        engine.model_id(),
        &target_app,
        provider_id,
        e2e_ms,
    )?;

    if settings.nvoos.archive_enabled && state.has_nvoos_credential() {
        if let Ok(client) = state.nvoos_client() {
            let row = crate::nvoos::TranscriptRecord {
                assistant_id: settings.nvoos.assistant_id.clone(),
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
