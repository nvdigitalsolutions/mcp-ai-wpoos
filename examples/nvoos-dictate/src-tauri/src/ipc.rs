//! Typed IPC commands exposed to the webview.
//!
//! Tauri trust-boundary rules: every command validates its inputs in Rust,
//! secrets never travel back to the webview (only presence booleans), and
//! all state changes go through `AppState` (single writer per resource).

use serde::Serialize;
use std::sync::Arc;
use tauri::{Emitter, State};
use tauri_plugin_opener::OpenerExt;

use cpal::traits::{DeviceTrait, HostTrait};

use crate::config::Settings;
use crate::error::{AppError, AppResult};
use crate::state::{
    AppState, SecretStore, KEY_LM_STUDIO_TOKEN, KEY_NVOOS_CREDENTIAL, KEY_OLLAMA_TOKEN,
    KEY_OPENAI_API_KEY, KEY_WP_APP_PASSWORD, KEY_WP_USERNAME,
};
use crate::storage::db::{MeetingRow, TranscriptRow};

#[derive(Debug, Clone, Serialize)]
pub struct StatusInfo {
    pub ptt_key: String,
    pub ptt_mode: String,
    pub ptt_combo: String,
    pub engine: Option<String>,
    pub model: Option<String>,
    pub engine_features: String,
    pub mic_available: bool,
    pub nvoos_configured: bool,
    pub nvoos_credential_stored: bool,
    pub openai_key_stored: bool,
    pub lm_studio_token_stored: bool,
    pub ollama_token_stored: bool,
    pub wp_app_password_stored: bool,
    pub meeting_recording: bool,
    pub system_audio_available: bool,
    pub vision_available: bool,
    pub vision_running: bool,
    pub vision_detector: Option<String>,
    pub vision_hand_model: Option<String>,
    pub agent_available: bool,
    pub agent_running: bool,
    pub version: String,
    /// Human-readable description of the active Stage-2 polish setup
    /// (provider + model), or `off (deterministic only)` when disabled.
    pub polish: String,
    /// Whether the Assistant tab has a hosted Pro SPA URL configured.
    pub spa_configured: bool,
    /// The configured Pro SPA URL (empty when unset).
    pub spa_url: String,
    /// Whether snippet OCR (Proposal 100) is compiled into this build.
    pub snippet_available: bool,
    /// The active snippet engine id (`winrt` | `sidecar` | `onnx` | `auto`).
    pub snippet_engine: String,
    /// The configured snippet hotkey (empty = disabled).
    pub snippet_hotkey: String,
    /// Whether the document pipeline (Proposal 109) is compiled in.
    pub document_available: bool,
    /// Whether the memory layer (Proposal 127) is compiled in.
    pub memory_available: bool,
    /// Whether the memory layer is turned on in Settings.
    pub memory_enabled: bool,
    /// Number of chunks with a stored vector.
    pub memory_indexed: i64,
    /// Total number of chunks in the corpus.
    pub memory_chunks: i64,
}

/// Reject commands invoked from any window other than the main one.
/// The overlay webview ships the same JS bundle, so this is defense in
/// depth: capabilities alone cannot gate custom commands.
fn main_window_only(window: &tauri::Window) -> AppResult<()> {
    if window.label() != "main" {
        return Err(AppError::Auth(
            "this command is only available from the main window".into(),
        ));
    }
    Ok(())
}

#[tauri::command]
pub fn get_settings(state: State<'_, AppState>) -> AppResult<Settings> {
    Ok(state.settings_snapshot())
}

#[tauri::command]
pub fn update_settings(state: State<'_, AppState>, settings: Settings) -> AppResult<Settings> {
    let previous = state.settings_snapshot();
    let updated = state.update_settings(settings)?;
    state.reapply_engine(&previous).map_err(|e| {
        // Settings are persisted; only the engine swap failed (typically a
        // valid combo whose model files are not downloaded yet). Make the
        // message say exactly that so the user is not misled.
        AppError::Other(format!(
            "settings saved, but the speech engine could not be started: {e}"
        ))
    })?;
    state.reapply_audio(&previous).map_err(|e| {
        // Settings are persisted; only the microphone swap failed — the
        // previous device keeps capturing.
        AppError::Other(format!(
            "settings saved, but the microphone could not be switched: {e}"
        ))
    })?;
    state.reapply_vision(&previous).map_err(|e| {
        AppError::Other(format!(
            "settings saved, but the vision loop could not be applied: {e}"
        ))
    })?;
    Ok(updated)
}

#[tauri::command]
pub fn get_status(state: State<'_, AppState>) -> AppResult<StatusInfo> {
    let settings = state.settings_snapshot();
    let engine = state.engine.read().unwrap().clone();
    let (memory_chunks, memory_indexed) = state
        .db
        .memory_counts()
        .map(|(total, indexed, _)| (total, indexed))
        .unwrap_or((0, 0));
    Ok(StatusInfo {
        ptt_key: settings.hotkey.ptt_key.clone(),
        ptt_mode: settings.hotkey.ptt_mode.clone(),
        ptt_combo: settings.hotkey.ptt_combo.clone(),
        engine: engine.as_ref().map(|e| e.id().to_string()),
        model: engine.as_ref().map(|e| e.model_id().to_string()),
        engine_features: crate::stt::available_features().to_string(),
        mic_available: state.mic.lock().unwrap().is_some(),
        nvoos_configured: !settings.nvoos.site_url.is_empty(),
        nvoos_credential_stored: state.has_nvoos_credential(),
        openai_key_stored: state.has_openai_key(),
        lm_studio_token_stored: state.has_lm_studio_token(),
        ollama_token_stored: state.has_ollama_token(),
        wp_app_password_stored: state.has_wp_app_password(),
        meeting_recording: state.recorder.is_recording(),
        system_audio_available: crate::meetings::system_audio_available(),
        vision_available: crate::vision::available(),
        vision_running: vision_running(&state),
        vision_detector: if settings.vision.enabled {
            Some(settings.vision.detector.clone())
        } else {
            None
        },
        vision_hand_model: if settings.vision.enabled && !settings.vision.hand_model.is_empty() {
            Some(settings.vision.hand_model.clone())
        } else {
            None
        },
        agent_available: crate::agent::available(),
        agent_running: state.agent.snapshot().running,
        version: env!("CARGO_PKG_VERSION").to_string(),
        polish: polish_summary(&settings),
        spa_configured: !settings.spa.url.trim().is_empty(),
        spa_url: settings.spa.url.clone(),
        snippet_available: crate::snippet::available(),
        snippet_engine: settings.snippet.engine.clone(),
        snippet_hotkey: settings.snippet.hotkey.clone(),
        document_available: crate::document::available(),
        memory_available: crate::memory::available(),
        memory_enabled: settings.memory.enabled,
        memory_indexed,
        memory_chunks,
    })
}

/// A compact description of the active Stage-2 polish provider + model for the
/// System status card. `off (deterministic only)` when polish is disabled; the
/// provider id + its configured model otherwise (empty model = the provider
/// serves its single loaded/default model).
fn polish_summary(settings: &Settings) -> String {
    if !settings.cleanup.polish_enabled {
        return "off (deterministic only)".to_string();
    }
    let (label, model): (&str, Option<&str>) = match settings.cleanup.provider.as_str() {
        "openai_compat" => ("OpenAI-compatible", Some(&settings.openai_compat.model)),
        "lm_studio" => ("LM Studio", Some(&settings.lm_studio.model)),
        "ollama" => ("Ollama", Some(&settings.ollama.model)),
        "nvoos" => ("NV oOS", settings.nvoos.polish_model.as_deref()),
        _ => return "off (deterministic only)".to_string(),
    };
    match model.filter(|m| !m.is_empty()) {
        Some(m) => format!("{label} · {m}"),
        None => label.to_string(),
    }
}

/// Result of the Assistant-tab reachability probe.
#[derive(Debug, Clone, Serialize)]
pub struct SpaCheck {
    pub ok: bool,
    /// HTTP status when the SPA origin answered; `None` when the request
    /// failed before a response (DNS, TLS, timeout, refused connection).
    pub status: Option<u16>,
    /// Human-readable result, e.g. `reachable (HTTP 200)` or the transport error.
    pub message: String,
}

/// Validate + normalize a hosted Pro SPA URL. Accepts only `https://` (plus
/// `http://localhost`/`127.0.0.1` for local development — never a plaintext
/// remote host). Returns the normalized origin (no trailing slash) on success.
fn validate_spa_url(raw: &str) -> AppResult<String> {
    let trimmed = raw.trim();
    if trimmed.is_empty() {
        return Err(AppError::NotConfigured(
            "No Complete Velocity URL is configured (Settings → NV oOS → Default Complete Velocity URL)"
                .into(),
        ));
    }
    let https_ok = trimmed.starts_with("https://");
    let localhost_ok =
        trimmed.starts_with("http://localhost") || trimmed.starts_with("http://127.0.0.1");
    if !https_ok && !localhost_ok {
        return Err(AppError::Other(
            "Complete Velocity URL must use https:// (http://localhost allowed for development)"
                .into(),
        ));
    }
    Ok(trimmed.trim_end_matches('/').to_string())
}

/// Probe whether the Assistant-tab URL is reachable. Bounded (8 s), no
/// redirects, no credentials: only the reachability of the configured origin
/// is tested, matching the dictate app's "no secrets to third parties" stance.
#[tauri::command]
pub async fn check_spa(url: String) -> AppResult<SpaCheck> {
    let url = validate_spa_url(&url)?;
    let client = reqwest::Client::builder()
        .user_agent(concat!("nvoos-dictate/", env!("CARGO_PKG_VERSION")))
        .redirect(reqwest::redirect::Policy::none())
        .timeout(std::time::Duration::from_secs(8))
        .build()?;
    match client.get(&url).send().await {
        Ok(resp) => {
            let status = resp.status().as_u16();
            // The SPA is a browser app behind whichever host serves it; any
            // 2xx–5xx means the origin answered (a 404/403 from a hardened
            // host still proves reachability).
            let ok = (200..500).contains(&status);
            Ok(SpaCheck {
                ok,
                status: Some(status),
                message: format!("reachable (HTTP {status})"),
            })
        }
        Err(e) => Ok(SpaCheck {
            ok: false,
            status: None,
            message: format!("unreachable: {e}"),
        }),
    }
}

/// Open (or refocus) the NV oOS Complete Velocity console in its own
/// first-party window. The console's cookie-based WordPress sign-in needs a
/// top-level context — WebView2 blocks third-party cookies for cross-site
/// iframes, so embedding the console in the main window breaks sign-in
/// (masked by the console as "Invalid username or password").
///
/// MUST be async: window creation dispatches to the main thread and waits;
/// a sync command runs ON the main thread and would deadlock the whole
/// IPC surface (Settings then hangs on "Loading settings…").
#[tauri::command]
pub async fn open_nvoos_console(
    app: tauri::AppHandle,
    state: State<'_, AppState>,
) -> AppResult<()> {
    let settings = state.settings_snapshot();
    let url = validate_spa_url(&settings.spa.url)?;
    crate::ensure_nvoos_console_window(&app, &url)
}

/// Whether the resident vision loop is currently running.
fn vision_running(state: &AppState) -> bool {
    state
        .vision
        .lock()
        .unwrap()
        .as_ref()
        .map(|v| v.running())
        .unwrap_or(false)
}

// ---- Diagnostics ---------------------------------------------------------

#[derive(Debug, Clone, Serialize)]
pub struct DiagnosticsInfo {
    pub version: String,
    pub os: String,
    pub config_path: String,
    pub models_dir: String,
    pub hotkey: HotkeyDiagnostics,
    pub mic: MicDiagnostics,
    pub engine: EngineDiagnostics,
    pub credentials: CredentialDiagnostics,
    pub vision: VisionDiagnostics,
    pub agent: AgentDiagnostics,
    pub snippet: SnippetDiagnostics,
    pub document: DocumentDiagnostics,
    pub memory: crate::memory::MemoryStatus,
    pub last_dictation: Option<crate::dictation::LastDictationStats>,
}

/// Document pipeline health (Proposal 109), mirroring the snippet block.
#[derive(Debug, Clone, Serialize)]
pub struct DocumentDiagnostics {
    pub available: bool,
    pub pdf_export_available: bool,
    pub busy: bool,
    pub documents: u64,
    pub pages: u64,
    pub text_pages: u64,
    pub ocr_pages: u64,
    pub last_ms: u64,
    pub errors: u64,
    pub last_error: Option<String>,
    pub dpi: u32,
    pub max_pages: usize,
    pub polish_enabled: bool,
    pub model_pages: u64,
    pub model_enabled: bool,
    pub model_provider: String,
    pub model_model: String,
}

/// Build the document pipeline diagnostics snapshot.
fn document_diagnostics(state: &AppState, settings: &Settings) -> DocumentDiagnostics {
    use std::sync::atomic::Ordering;
    let controller = &state.document;
    DocumentDiagnostics {
        available: crate::document::available(),
        pdf_export_available: crate::document::pdf_export_available(),
        busy: controller.is_busy(),
        documents: controller.stats.documents.load(Ordering::Relaxed),
        pages: controller.stats.pages.load(Ordering::Relaxed),
        text_pages: controller.stats.text_pages.load(Ordering::Relaxed),
        ocr_pages: controller.stats.ocr_pages.load(Ordering::Relaxed),
        last_ms: controller.stats.last_ms.load(Ordering::Relaxed),
        errors: controller.stats.errors.load(Ordering::Relaxed),
        last_error: controller.stats.last_error.lock().unwrap().clone(),
        dpi: settings.document.dpi,
        max_pages: settings.document.max_pages,
        polish_enabled: settings.document.polish_enabled,
        model_pages: controller.stats.model_pages.load(Ordering::Relaxed),
        model_enabled: settings.document.model.enabled,
        model_provider: settings.document.model.provider.clone(),
        model_model: settings.document.model.model.clone(),
    }
}

#[derive(Debug, Clone, Serialize)]
pub struct HotkeyDiagnostics {
    pub ptt_key: String,
    /// The configured PTT mode (`hold` | `toggle`).
    pub ptt_mode: String,
    /// Whether the low-level listener is alive and ticking (Windows
    /// WH_KEYBOARD_LL hook / macOS CGEventTap).
    pub hook_installed: bool,
    /// Milliseconds since the listener thread's last heartbeat tick.
    pub hook_heartbeat_age_ms: u64,
    /// Total listener callback invocations since start.
    pub hook_calls: u64,
    /// Whether a swallowed PTT key-down is still awaiting its key-up.
    pub swallow_pending: bool,
}

#[derive(Debug, Clone, Serialize)]
pub struct MicDiagnostics {
    pub available: bool,
    /// The OS default capture device's display name.
    pub device_name: Option<String>,
    pub sample_rate: u32,
    pub channels: u16,
    /// Samples retained in the pre-roll ring buffer right now.
    pub buffered_samples: usize,
    /// Live input level over the most recent ~250 ms (level meter).
    pub live_rms: f32,
    pub live_peak: f32,
    /// VAD implementation in use: `energy` or `silero`.
    pub vad_kind: &'static str,
    /// Capture stream health: callbacks delivered, stall age, errors.
    pub capture_callbacks: u64,
    pub capture_stall_ms: u64,
    pub capture_errors: u64,
    pub capture_last_error: Option<String>,
}

#[derive(Debug, Clone, Serialize)]
pub struct EngineDiagnostics {
    pub configured: String,
    pub model: String,
    pub loaded: bool,
    pub features: String,
    pub models: Vec<crate::stt::model_registry::ModelStatus>,
}

#[derive(Debug, Clone, Serialize)]
pub struct CredentialDiagnostics {
    pub nvoos_configured: bool,
    pub nvoos_credential_stored: bool,
    pub openai_key_stored: bool,
    pub lm_studio_token_stored: bool,
    pub ollama_token_stored: bool,
    pub wp_credentials_stored: bool,
}

/// Object-detection + tracking health, mirroring `MicDiagnostics`/camera.
#[derive(Debug, Clone, Serialize)]
pub struct VisionDiagnostics {
    pub available: bool,
    pub enabled: bool,
    pub running: bool,
    pub detector: String,
    pub hand_model: String,
    pub detector_installed: bool,
    pub confidence_threshold: f32,
    pub iou_threshold: f32,
    /// Detector loop health: frames processed, last inference latency, errors.
    pub frames: u64,
    pub total_detections: u64,
    pub track_events: u64,
    pub active_tracks: usize,
    pub last_infer_ms: u64,
    pub errors: u64,
    pub last_error: Option<String>,
    pub models: Vec<crate::vision::models::VisionModelStatus>,
    /// VLM label-polish (Proposal 076) state.
    pub polish_enabled: bool,
    pub polish_provider: String,
    pub polish_model: String,
    pub polish_calls: u64,
    pub polish_results: u64,
}

/// Browser-agent health (dictated web control, §6).
#[derive(Debug, Clone, Serialize)]
pub struct AgentDiagnostics {
    pub available: bool,
    pub enabled: bool,
    pub running: bool,
    pub provider: String,
    pub model: String,
    pub max_steps: usize,
    pub confirmation_required: bool,
    pub last_error: Option<String>,
}

/// Snippet OCR health (Proposal 100), mirroring the vision block.
#[derive(Debug, Clone, Serialize)]
pub struct SnippetDiagnostics {
    pub available: bool,
    pub enabled: bool,
    pub hotkey: String,
    pub engine: String,
    pub language: String,
    pub phase: String,
    /// Installed Windows OCR languages (winrt) / empty elsewhere.
    pub ocr_languages: Vec<String>,
    pub captures: u64,
    pub recognitions: u64,
    pub last_ocr_ms: u64,
    pub errors: u64,
    pub last_error: Option<String>,
    pub last_region: Option<crate::snippet::RegionRect>,
    /// ONNX language packs + install state (empty without `snippet-onnx`).
    pub models: Vec<crate::snippet::models::SnippetModelStatus>,
    pub onnx_available: bool,
}

/// Full troubleshooting snapshot: hook health, capture device + live level,
/// engine/model install state, credential presence, and the most recent
/// dictation attempt's measured levels. No secrets ever leave the core.
#[tauri::command]
pub fn get_diagnostics(state: State<'_, AppState>) -> AppResult<DiagnosticsInfo> {
    let settings = state.settings_snapshot();
    let engine = state.engine.read().unwrap().clone();
    let mic_guard = state.mic.lock().unwrap();
    let mic = mic_guard
        .as_ref()
        .map(|m| {
            let (live_rms, live_peak) = m.live_level();
            MicDiagnostics {
                available: true,
                device_name: Some(m.device_name().to_string()),
                sample_rate: m.sample_rate(),
                channels: m.channels(),
                buffered_samples: m.buffered_samples(),
                live_rms,
                live_peak,
                vad_kind: crate::audio::vad::implementation(),
                capture_callbacks: m.callback_count(),
                capture_stall_ms: m.stall_ms(),
                capture_errors: m.stream_error_count(),
                capture_last_error: m.last_stream_error(),
            }
        })
        .unwrap_or(MicDiagnostics {
            available: false,
            device_name: None,
            sample_rate: 0,
            channels: 0,
            buffered_samples: 0,
            live_rms: 0.0,
            live_peak: 0.0,
            vad_kind: crate::audio::vad::implementation(),
            capture_callbacks: 0,
            capture_stall_ms: 0,
            capture_errors: 0,
            capture_last_error: None,
        });

    Ok(DiagnosticsInfo {
        version: env!("CARGO_PKG_VERSION").to_string(),
        os: format!("{} {}", std::env::consts::OS, std::env::consts::ARCH),
        config_path: crate::config::Settings::config_path()
            .to_string_lossy()
            .into_owned(),
        models_dir: state.models_dir().to_string_lossy().into_owned(),
        hotkey: hotkey_diagnostics(&settings),
        mic,
        engine: EngineDiagnostics {
            configured: settings.engine.engine.clone(),
            model: settings.engine.model.clone(),
            loaded: engine.is_some(),
            features: crate::stt::available_features().to_string(),
            models: crate::stt::model_registry::status_list(&state.models_dir()),
        },
        credentials: CredentialDiagnostics {
            nvoos_configured: !settings.nvoos.site_url.is_empty(),
            nvoos_credential_stored: state.has_nvoos_credential(),
            openai_key_stored: state.has_openai_key(),
            lm_studio_token_stored: state.has_lm_studio_token(),
            ollama_token_stored: state.has_ollama_token(),
            wp_credentials_stored: state.has_wp_app_password(),
        },
        vision: vision_diagnostics(&state, &settings),
        agent: agent_diagnostics(&state, &settings),
        snippet: snippet_diagnostics(&state, &settings),
        document: document_diagnostics(&state, &settings),
        memory: state
            .memory
            .status(&state.db, &settings, &state.models_dir())?,
        last_dictation: crate::dictation::last_dictation_stats(),
    })
}

/// Build the object-detection diagnostics snapshot.
fn vision_diagnostics(state: &AppState, settings: &Settings) -> VisionDiagnostics {
    let available = crate::vision::available();
    let models_dir = state.models_dir();
    let detector_spec = crate::vision::models::find(&settings.vision.detector);
    let detector_installed = detector_spec
        .map(|s| crate::vision::models::is_installed(s, &models_dir))
        .unwrap_or(false);
    let vision = state.vision.lock().unwrap();
    let running = vision.as_ref().map(|v| v.running()).unwrap_or(false);
    let (frames, total_detections, track_events, active_tracks, last_infer_ms, errors, last_error) =
        match vision.as_ref() {
            Some(v) => (
                v.frames(),
                v.total_detections(),
                v.track_events(),
                v.active_tracks(),
                v.last_infer_ms(),
                v.error_count(),
                v.last_error(),
            ),
            None => (0, 0, 0, 0, 0, 0, None),
        };
    let (polish_calls, polish_results) = match vision.as_ref() {
        Some(v) => (v.polish_calls(), v.polish_results()),
        None => (0, 0),
    };
    VisionDiagnostics {
        available,
        enabled: settings.vision.enabled,
        running,
        detector: settings.vision.detector.clone(),
        hand_model: settings.vision.hand_model.clone(),
        detector_installed,
        confidence_threshold: settings.vision.confidence,
        iou_threshold: settings.vision.iou_threshold,
        frames,
        total_detections,
        track_events,
        active_tracks,
        last_infer_ms,
        errors,
        last_error,
        models: crate::vision::models::status_list(&models_dir),
        polish_enabled: settings.vision.polish_enabled,
        polish_provider: settings.vision.polish_provider.clone(),
        polish_model: settings.vision.polish_model.clone(),
        polish_calls,
        polish_results,
    }
}

/// Build the browser-agent diagnostics snapshot.
fn agent_diagnostics(state: &AppState, settings: &Settings) -> AgentDiagnostics {
    let snap = state.agent.snapshot();
    AgentDiagnostics {
        available: crate::agent::available(),
        enabled: settings.agent.enabled,
        running: snap.running,
        provider: settings.agent.provider.clone(),
        model: settings.agent.model.clone(),
        max_steps: settings.agent.max_steps,
        confirmation_required: settings.agent.confirmation_required,
        last_error: snap.last_error,
    }
}

/// Build the snippet OCR diagnostics snapshot.
fn snippet_diagnostics(state: &AppState, settings: &Settings) -> SnippetDiagnostics {
    let controller = &state.snippet;
    let phase = format!("{:?}", controller.phase()).to_lowercase();
    #[cfg(all(windows, feature = "snippet"))]
    let ocr_languages = crate::snippet::ocr_winrt::list_languages();
    #[cfg(not(all(windows, feature = "snippet")))]
    let ocr_languages = Vec::new();
    SnippetDiagnostics {
        available: crate::snippet::available(),
        enabled: settings.snippet.enabled,
        hotkey: settings.snippet.hotkey.clone(),
        engine: settings.snippet.engine.clone(),
        language: settings.snippet.language.clone(),
        phase,
        ocr_languages,
        captures: controller.captures(),
        recognitions: controller.recognitions(),
        last_ocr_ms: controller.last_ocr_ms(),
        errors: controller.error_count(),
        last_error: controller.last_error(),
        last_region: controller.last_region(),
        models: crate::snippet::models::status_list(&state.models_dir()),
        onnx_available: crate::snippet::onnx_available(),
    }
}

/// Rebuild the resident capture stream on the configured device (recovery
/// for a dead/unplugged device without restarting the app or changing
/// settings).
#[tauri::command]
pub fn restart_microphone(state: State<'_, AppState>) -> AppResult<MicDiagnostics> {
    state.restart_mic()?;
    let mic_guard = state.mic.lock().unwrap();
    let m = mic_guard
        .as_ref()
        .ok_or_else(|| AppError::Audio("microphone unavailable".into()))?;
    let (live_rms, live_peak) = m.live_level();
    Ok(MicDiagnostics {
        available: true,
        device_name: Some(m.device_name().to_string()),
        sample_rate: m.sample_rate(),
        channels: m.channels(),
        buffered_samples: m.buffered_samples(),
        live_rms,
        live_peak,
        vad_kind: crate::audio::vad::implementation(),
        capture_callbacks: m.callback_count(),
        capture_stall_ms: m.stall_ms(),
        capture_errors: m.stream_error_count(),
        capture_last_error: m.last_stream_error(),
    })
}

#[cfg(windows)]
fn hotkey_diagnostics(settings: &Settings) -> HotkeyDiagnostics {
    use crate::hotkey::win_hook;
    HotkeyDiagnostics {
        ptt_key: settings.hotkey.ptt_key.clone(),
        ptt_mode: settings.hotkey.ptt_mode.clone(),
        // The heartbeat ticks every 50 ms while the hook thread runs; a
        // stale heartbeat means the hook install failed or the thread died
        // and the GetAsyncKeyState fallback is covering PTT.
        hook_installed: win_hook::hook_healthy(5000),
        hook_heartbeat_age_ms: win_hook::heartbeat_age_ms(),
        hook_calls: win_hook::hook_calls(),
        swallow_pending: win_hook::swallow_pending(),
    }
}

#[cfg(target_os = "macos")]
fn hotkey_diagnostics(settings: &Settings) -> HotkeyDiagnostics {
    use crate::hotkey::macos_event_tap;
    HotkeyDiagnostics {
        ptt_key: settings.hotkey.ptt_key.clone(),
        ptt_mode: settings.hotkey.ptt_mode.clone(),
        // A silent tap (installed but zero callbacks) is the Input
        // Monitoring signature: the tap exists but macOS delivers nothing.
        hook_installed: macos_event_tap::tap_healthy(5000),
        hook_heartbeat_age_ms: macos_event_tap::heartbeat_age_ms(),
        hook_calls: macos_event_tap::tap_calls(),
        swallow_pending: macos_event_tap::swallow_pending(),
    }
}

#[cfg(all(not(windows), not(target_os = "macos")))]
fn hotkey_diagnostics(settings: &Settings) -> HotkeyDiagnostics {
    HotkeyDiagnostics {
        ptt_key: settings.hotkey.ptt_key.clone(),
        ptt_mode: settings.hotkey.ptt_mode.clone(),
        hook_installed: false,
        hook_heartbeat_age_ms: 0,
        hook_calls: 0,
        swallow_pending: false,
    }
}

// ---- macOS TCC permissions (P5, phase M1) -------------------------------

// ---- Memory layer (Proposal 127) -----------------------------------------

/// Snapshot of the memory subsystem (Settings/Diagnostics/Home cards).
#[tauri::command]
pub fn memory_status(
    window: tauri::Window,
    state: State<'_, AppState>,
) -> AppResult<crate::memory::MemoryStatus> {
    main_window_only(&window)?;
    let settings = state.settings_snapshot();
    state
        .memory
        .status(&state.db, &settings, &state.models_dir())
}

/// Ask the memory corpus: retrieved chunks always, a cited answer when a
/// Stage-2 provider answered. Failure-soft by contract — a provider error
/// returns chunks-only, never an error to the user.
#[tauri::command]
pub async fn ask_memory(
    window: tauri::Window,
    state: State<'_, AppState>,
    query: String,
) -> AppResult<crate::memory::AskResult> {
    main_window_only(&window)?;
    let settings = state.settings_snapshot();
    state
        .memory
        .ask(&state.db, &query, &settings, state.secrets.as_ref())
        .await
}

/// Drop every vector row; the worker re-embeds the corpus on its next
/// polls (the re-embed path for embedder changes). The keyword index keeps
/// serving throughout. Gated by the typed confirmation (Proposal 128): the
/// backend is the authority — the UI input merely gathers the word.
#[tauri::command]
pub fn reindex_memory(
    window: tauri::Window,
    state: State<'_, AppState>,
    confirmation: String,
) -> AppResult<()> {
    main_window_only(&window)?;
    state.memory.reindex(&state.db, &confirmation)
}

/// Wipe the search corpus (chunks + vectors + FTS). Capture history is
/// untouched, but existing history stops being searchable until
/// re-captured — hence the stricter `PURGE` typed gate.
#[tauri::command]
pub fn purge_memory(
    window: tauri::Window,
    state: State<'_, AppState>,
    confirmation: String,
) -> AppResult<()> {
    main_window_only(&window)?;
    state.memory.purge(&state.db, &confirmation)
}

/// Download (or force re-download) the configured embedder's pinned files,
/// then rebuild the resident encoder.
#[tauri::command]
pub async fn download_memory_model(
    window: tauri::Window,
    state: State<'_, AppState>,
    force: bool,
) -> AppResult<()> {
    main_window_only(&window)?;
    let settings = state.settings_snapshot();
    let spec = crate::memory::models::spec_by_id(&settings.memory.embedder).ok_or_else(|| {
        AppError::Other(format!("unknown embedder '{}'", settings.memory.embedder))
    })?;
    // Drop the resident encoder BEFORE replacing files on disk: a loaded
    // session can hold the model open on Windows (the download-IPC rule).
    state.memory.drop_encoder();
    let models_dir = state.models_dir();
    crate::memory::models::download(spec, &models_dir, force, |_| {}).await?;
    // Rebuild on the fresh files.
    state.memory.apply_settings(&settings, &models_dir);
    Ok(())
}

/// Permission rows for the Settings wizard. The Microphone row derives its
/// state from the resident capture (a working stream = granted).
#[tauri::command]
pub fn get_permissions(state: State<'_, AppState>) -> Vec<crate::permissions::PermissionInfo> {
    let mic_available = state.mic.lock().unwrap().is_some();
    crate::permissions::permissions(mic_available)
}

/// Ask macOS for a permission (or open the pane for the manual toggles).
/// Returns the refreshed row.
#[tauri::command]
pub fn request_permission(
    window: tauri::Window,
    state: State<'_, AppState>,
    kind: String,
) -> AppResult<crate::permissions::PermissionInfo> {
    main_window_only(&window)?;
    let mic_available = state.mic.lock().unwrap().is_some();
    let row = crate::permissions::request(&kind, mic_available)?;
    // Requesting Microphone means rebuilding the capture stream — that is
    // the action that triggers the OS prompt.
    if kind == "microphone" {
        state.restart_mic()?;
    }
    Ok(row)
}

/// Open the matching System Settings privacy pane.
#[tauri::command]
pub fn open_system_settings(
    window: tauri::Window,
    app: tauri::AppHandle,
    kind: String,
) -> AppResult<()> {
    main_window_only(&window)?;
    let url = crate::permissions::system_settings_url(&kind)?;
    app.opener()
        .open_url(url, None::<String>)
        .map_err(|e| AppError::Other(format!("failed to open System Settings: {e}")))
}

// ---- NV oOS credential (keychain) ---------------------------------------

/// Persist a secret and verify the read-back. keyring's Windows backend
/// could report success while writing into a namespace the app cannot read
/// (CRED_PERSIST_ENTERPRISE) — "I stored it but it did not stick". Every
/// write must round-trip before it is reported as stored.
fn persist_secret_verified(
    secrets: &Arc<dyn SecretStore>,
    key: &str,
    value: &str,
) -> AppResult<()> {
    secrets.set(key, value)?;
    match secrets.get(key) {
        Ok(Some(stored)) if stored == value => Ok(()),
        Ok(_) => Err(AppError::Keyring(format!(
            "the {key} value could not be verified after writing — the OS credential store did not persist it"
        ))),
        Err(e) => Err(e),
    }
}

#[tauri::command]
pub fn set_nvoos_credential(
    window: tauri::Window,
    state: State<'_, AppState>,
    site_url: String,
    credential: String,
) -> AppResult<()> {
    main_window_only(&window)?;
    let site_url = site_url.trim().trim_end_matches('/').to_string();
    if site_url.is_empty() || credential.is_empty() {
        return Err(AppError::Auth(
            "site URL and credential are required".into(),
        ));
    }
    // Sanity-check the endpoint contract before storing anything.
    let client = crate::nvoos::NvoosClient::new(site_url, credential.clone())?;
    let _ = client;
    persist_secret_verified(&state.secrets, KEY_NVOOS_CREDENTIAL, &credential)
}

#[tauri::command]
pub fn clear_nvoos_credential(window: tauri::Window, state: State<'_, AppState>) -> AppResult<()> {
    main_window_only(&window)?;
    state.secrets.delete(KEY_NVOOS_CREDENTIAL)
}

#[tauri::command]
pub async fn test_nvoos_connection(
    state: State<'_, AppState>,
) -> AppResult<crate::nvoos::ConnectionTest> {
    let client = state.nvoos_client()?;
    client.test_connection().await
}

#[tauri::command]
pub fn set_openai_api_key(
    window: tauri::Window,
    state: State<'_, AppState>,
    api_key: String,
) -> AppResult<()> {
    main_window_only(&window)?;
    if api_key.trim().is_empty() {
        return Err(AppError::Auth("API key must not be empty".into()));
    }
    persist_secret_verified(&state.secrets, KEY_OPENAI_API_KEY, api_key.trim())
}

#[tauri::command]
pub fn clear_openai_api_key(window: tauri::Window, state: State<'_, AppState>) -> AppResult<()> {
    main_window_only(&window)?;
    state.secrets.delete(KEY_OPENAI_API_KEY)
}

#[tauri::command]
pub fn set_lm_studio_token(
    window: tauri::Window,
    state: State<'_, AppState>,
    token: String,
) -> AppResult<()> {
    main_window_only(&window)?;
    if token.trim().is_empty() {
        return Err(AppError::Auth("token must not be empty".into()));
    }
    persist_secret_verified(&state.secrets, KEY_LM_STUDIO_TOKEN, token.trim())
}

#[tauri::command]
pub fn clear_lm_studio_token(window: tauri::Window, state: State<'_, AppState>) -> AppResult<()> {
    main_window_only(&window)?;
    state.secrets.delete(KEY_LM_STUDIO_TOKEN)
}

#[tauri::command]
pub async fn test_lm_studio_connection(
    state: State<'_, AppState>,
) -> AppResult<crate::cleanup::providers::lm_studio::LmStudioConnectionTest> {
    let provider = state.lm_studio_provider()?;
    Ok(provider.test_connection().await)
}

#[tauri::command]
pub fn set_ollama_token(
    window: tauri::Window,
    state: State<'_, AppState>,
    token: String,
) -> AppResult<()> {
    main_window_only(&window)?;
    if token.trim().is_empty() {
        return Err(AppError::Auth("token must not be empty".into()));
    }
    persist_secret_verified(&state.secrets, KEY_OLLAMA_TOKEN, token.trim())
}

#[tauri::command]
pub fn clear_ollama_token(window: tauri::Window, state: State<'_, AppState>) -> AppResult<()> {
    main_window_only(&window)?;
    state.secrets.delete(KEY_OLLAMA_TOKEN)
}

#[tauri::command]
pub async fn test_ollama_connection(
    state: State<'_, AppState>,
) -> AppResult<crate::cleanup::providers::ollama::OllamaConnectionTest> {
    let provider = state.ollama_provider()?;
    Ok(provider.test_connection().await)
}

// ---- WP application password (embedded STT auth) --------------------------

#[tauri::command]
pub fn set_wp_app_password(
    window: tauri::Window,
    state: State<'_, AppState>,
    username: String,
    app_password: String,
) -> AppResult<()> {
    main_window_only(&window)?;
    if username.trim().is_empty() || app_password.trim().is_empty() {
        return Err(AppError::Auth(
            "WP username and application password are required".into(),
        ));
    }
    state.secrets.set(KEY_WP_USERNAME, username.trim())?;
    persist_secret_verified(&state.secrets, KEY_WP_APP_PASSWORD, app_password.trim())?;
    // The auth change may affect the resident `embedded` engine.
    *state.engine.write().unwrap() = None;
    Ok(())
}

#[tauri::command]
pub fn clear_wp_app_password(window: tauri::Window, state: State<'_, AppState>) -> AppResult<()> {
    main_window_only(&window)?;
    state.secrets.delete(KEY_WP_USERNAME)?;
    state.secrets.delete(KEY_WP_APP_PASSWORD)
}

// ---- History -------------------------------------------------------------

#[tauri::command]
pub fn list_history(
    state: State<'_, AppState>,
    query: String,
    limit: u32,
    offset: u32,
) -> AppResult<Vec<TranscriptRow>> {
    state
        .db
        .list_transcripts(&query, limit.min(500) as i64, offset as i64)
}

#[tauri::command]
pub fn delete_history(window: tauri::Window, state: State<'_, AppState>, id: i64) -> AppResult<()> {
    main_window_only(&window)?;
    state.db.delete_transcript(id)
}

#[tauri::command]
pub fn clear_history(window: tauri::Window, state: State<'_, AppState>) -> AppResult<()> {
    main_window_only(&window)?;
    state.db.clear_transcripts()
}

#[tauri::command]
pub fn export_history(window: tauri::Window, state: State<'_, AppState>) -> AppResult<String> {
    main_window_only(&window)?;
    let rows = state.db.list_transcripts("", 100_000, 0)?;
    serde_json::to_string_pretty(&rows).map_err(Into::into)
}

// ---- Dictionary ----------------------------------------------------------

#[tauri::command]
pub fn get_dictionary(state: State<'_, AppState>) -> AppResult<Vec<(String, String)>> {
    state.db.dictionary()
}

#[tauri::command]
pub fn set_dictionary(
    window: tauri::Window,
    state: State<'_, AppState>,
    entries: Vec<(String, String)>,
) -> AppResult<()> {
    main_window_only(&window)?;
    if entries.len() > 2000 {
        return Err(AppError::Other(
            "dictionary is limited to 2000 entries".into(),
        ));
    }
    state.db.set_dictionary(&entries)
}

// ---- Meetings ------------------------------------------------------------

#[tauri::command]
pub fn start_meeting(state: State<'_, AppState>) -> AppResult<()> {
    state.recorder.start()
}

#[tauri::command]
pub fn stop_meeting(
    state: State<'_, AppState>,
    title: String,
) -> AppResult<crate::meetings::MeetingSummary> {
    let engine = state.ensure_engine()?;
    let mut summary = state.recorder.stop(engine, &title)?;
    let id = state
        .db
        .insert_meeting(&summary.title, &summary.text, summary.duration_ms)?;
    summary.id = id;
    Ok(summary)
}

#[tauri::command]
pub fn list_meetings(state: State<'_, AppState>) -> AppResult<Vec<MeetingRow>> {
    state.db.list_meetings(500)
}

#[tauri::command]
pub fn delete_meeting(window: tauri::Window, state: State<'_, AppState>, id: i64) -> AppResult<()> {
    main_window_only(&window)?;
    state.db.delete_meeting(id)
}

// ---- Microphone -----------------------------------------------------------

/// Display names of the host's input devices, for the Settings picker.
/// Sorted and deduplicated; "system default" is represented by the empty
/// selection, not by a name here.
#[tauri::command]
pub fn list_input_devices() -> AppResult<Vec<String>> {
    let host = cpal::default_host();
    let devices = host
        .input_devices()
        .map_err(|e| AppError::Audio(e.to_string()))?;
    let mut names: Vec<String> = devices.filter_map(|d| d.name().ok()).collect();
    names.sort();
    names.dedup();
    Ok(names)
}

// ---- Camera --------------------------------------------------------------

/// Display names of the host's cameras, for the Settings picker (empty when
/// the `camera` feature is not compiled in, or no camera exists).
#[tauri::command]
pub fn list_camera_devices() -> Vec<String> {
    let mut names = crate::camera::list_devices();
    names.sort();
    names.dedup();
    names
}

/// Whether camera capture is compiled into this build.
#[tauri::command]
pub fn camera_available() -> bool {
    crate::camera::available()
}

/// Start (or restart) the resident camera on the configured device.
#[tauri::command]
pub fn start_camera(window: tauri::Window, state: State<'_, AppState>) -> AppResult<()> {
    main_window_only(&window)?;
    state.restart_camera()
}

/// Stop the resident camera and release the device.
#[tauri::command]
pub fn stop_camera(window: tauri::Window, state: State<'_, AppState>) -> AppResult<()> {
    main_window_only(&window)?;
    state.stop_camera();
    Ok(())
}

/// Return the latest camera preview frame as a data URI (RGB PNG), or `None`
/// when no frame/stream is available.
#[tauri::command]
pub fn get_preview_frame(
    state: State<'_, AppState>,
) -> AppResult<Option<crate::camera::PreviewFrame>> {
    let cam = state.camera.lock().unwrap().clone();
    Ok(cam.and_then(|c| c.frame.lock().unwrap().clone()))
}

// ---- Vision (object detection + tracking) --------------------------------

/// Whether local vision detection is compiled into this build.
#[tauri::command]
pub fn vision_available() -> bool {
    crate::vision::available()
}

/// Start (or restart) the resident vision loop on the configured detector.
#[tauri::command]
pub fn start_vision(window: tauri::Window, state: State<'_, AppState>) -> AppResult<()> {
    main_window_only(&window)?;
    state.restart_vision()
}

/// Stop the resident vision loop and release the detector.
#[tauri::command]
pub fn stop_vision(window: tauri::Window, state: State<'_, AppState>) -> AppResult<()> {
    main_window_only(&window)?;
    state.stop_vision();
    Ok(())
}

/// Return the latest tracked detections (source-pixel boxes + stable ids), or
/// an empty list when the vision loop is not running.
#[tauri::command]
pub fn get_detections(state: State<'_, AppState>) -> Vec<crate::vision::Detection> {
    state
        .vision
        .lock()
        .unwrap()
        .as_ref()
        .map(|v| v.snapshot.lock().unwrap().detections.clone())
        .unwrap_or_default()
}

/// Pin a tracked object by id (click-to-select). Returns false if the loop is
/// not running or the id is unknown.
#[tauri::command]
pub fn vision_select_track(window: tauri::Window, state: State<'_, AppState>, id: u64) -> bool {
    if window.label() != "main" {
        return false;
    }
    state
        .vision
        .lock()
        .unwrap()
        .as_ref()
        .map(|v| v.select_track(id))
        .unwrap_or(false)
}

/// The vision model catalogue with install state.
#[tauri::command]
pub fn get_vision_models(
    state: State<'_, AppState>,
) -> Vec<crate::vision::models::VisionModelStatus> {
    crate::vision::models::status_list(&state.models_dir())
}

/// Download a vision model (streaming + SHA-256 verified) into the models dir.
#[tauri::command]
pub async fn download_vision_model(
    window: tauri::Window,
    state: State<'_, AppState>,
    model: String,
    force: bool,
) -> AppResult<String> {
    main_window_only(&window)?;
    let spec = crate::vision::models::find(&model)
        .ok_or_else(|| AppError::Model(format!("unknown vision model '{model}'")))?;
    let dir = state.models_dir();
    let app = state.app.clone();
    let progress = move |fraction: f64| {
        let _ = app.emit(
            "model://progress",
            serde_json::json!({ "engine": "vision", "model": model, "fraction": fraction }),
        );
    };
    let path = crate::vision::models::download(spec, &dir, force, progress).await?;
    Ok(path.to_string_lossy().into_owned())
}

/// Test the configured vision-polish VLM: build the provider from settings
/// and discover models via `GET /v1/models`. Returns the model list so the
/// Settings dropdown can populate (and the user can pick a vision-capable one).
#[tauri::command]
pub async fn test_vision_polish(
    window: tauri::Window,
    state: State<'_, AppState>,
) -> AppResult<crate::cleanup::providers::lm_studio::LmStudioConnectionTest> {
    main_window_only(&window)?;
    let settings = state.settings_snapshot();
    // Build the local provider the same way the polish layer will.
    let provider = crate::cleanup::providers::local_openai::LocalOpenAiProvider::new(
        crate::cleanup::providers::local_openai::LocalKind {
            name: "LM Studio",
            id: "lm_studio",
        },
        &settings.lm_studio.base_url,
        &settings.vision.polish_model,
        settings.lm_studio.load_timeout_ms,
        state.secrets.get(crate::state::KEY_LM_STUDIO_TOKEN)?,
    )?;
    Ok(provider.test_connection().await)
}

// ---- Browser agent (dictated web control) --------------------------------

/// Whether the browser-agent driver is compiled into this build.
#[tauri::command]
pub fn agent_available() -> bool {
    crate::agent::available()
}

/// Start a browser-agent run for a dictated instruction (main window only).
#[tauri::command]
pub fn agent_run(
    window: tauri::Window,
    state: State<'_, AppState>,
    instruction: String,
) -> AppResult<()> {
    main_window_only(&window)?;
    state.start_agent_run(instruction)
}

/// Return the current agent snapshot (running flag, last result, last error).
#[tauri::command]
pub fn agent_status(state: State<'_, AppState>) -> crate::agent::controller::AgentSnapshot {
    state.agent.snapshot()
}

/// The destructive action currently awaiting a human Approve/Deny, if any.
#[tauri::command]
pub fn agent_pending(
    state: State<'_, AppState>,
) -> Option<crate::agent::controller::PendingApproval> {
    state.agent.pending_approval()
}

/// Answer the pending approval (`approve` = true, deny = false). Returns false
/// if there was nothing to answer. Acceptable from the main window and the
/// overlay (the overlay hosts the Approve/Deny buttons).
#[tauri::command]
pub fn agent_respond(window: tauri::Window, state: State<'_, AppState>, approve: bool) -> bool {
    if window.label() != "main" && window.label() != "overlay" {
        return false;
    }
    state.agent.respond_approval(approve)
}

/// Set the stop flag: the running loop halts between steps.
#[tauri::command]
pub fn agent_stop(window: tauri::Window, state: State<'_, AppState>) {
    if window.label() != "main" {
        return;
    }
    state.stop_agent_run();
}

// ---- Models & file transcription ----------------------------------------

#[tauri::command]
pub fn get_models(state: State<'_, AppState>) -> Vec<crate::stt::model_registry::ModelStatus> {
    crate::stt::model_registry::status_list(&state.models_dir())
}

/// Engine ids compiled into this build (feature-gated backends).
#[tauri::command]
pub fn get_compiled_engines() -> Vec<String> {
    // `mut` is only used when a native engine feature is enabled; default
    // builds (mock + embedded) never push.
    #[allow(unused_mut)]
    let mut engines = vec!["mock".to_string(), "embedded".to_string()];
    #[cfg(feature = "parakeet")]
    engines.push("parakeet".to_string());
    #[cfg(feature = "whisper")]
    engines.push("whisper".to_string());
    #[cfg(feature = "moonshine")]
    engines.push("moonshine".to_string());
    #[cfg(feature = "fastconformer")]
    engines.push("fastconformer".to_string());
    engines
}

#[tauri::command]
pub async fn download_model(
    window: tauri::Window,
    state: State<'_, AppState>,
    engine: String,
    model: String,
    force: bool,
) -> AppResult<String> {
    main_window_only(&window)?;
    let spec = crate::stt::model_registry::find(&engine, &model)
        .ok_or_else(|| AppError::Model(format!("unknown model '{engine}/{model}'")))?;
    let dir = state.models_dir();
    let app = state.app.clone();
    let progress = move |fraction: f64| {
        let _ = app.emit(
            "model://progress",
            serde_json::json!({ "engine": engine, "model": model, "fraction": fraction }),
        );
    };
    // Release the resident engine BEFORE replacing files: a loaded engine can
    // keep model files open/mapped on Windows, and the overwrite then fails
    // with "creating or truncating a file requires write or append access".
    *state.engine.write().unwrap() = None;
    let path = crate::stt::model_registry::download(spec, &dir, force, progress).await?;
    // Reload the resident engine immediately: the download usually completes
    // the configured engine/model, so it becomes usable without an app
    // restart. A failed load here is non-fatal (surfaced via status).
    let _ = state.ensure_engine();
    Ok(path.to_string_lossy().into_owned())
}

#[tauri::command]
pub async fn transcribe_file(
    window: tauri::Window,
    state: State<'_, AppState>,
    path: String,
) -> AppResult<String> {
    main_window_only(&window)?;
    let path = std::path::PathBuf::from(path);
    if !path.is_file() {
        return Err(AppError::Other("file not found".into()));
    }
    let decoded = crate::util::decode::decode_file(&path)?;
    let engine = state.ensure_engine()?;
    let settings = state.settings_snapshot();
    // Long audio (and any video) is chunked with cross-window context rather
    // than handed to the engine in one call.
    crate::media::transcribe_media(
        engine.as_ref(),
        &decoded.samples,
        decoded.sample_rate,
        settings.engine.language.as_deref(),
    )
}

/// Structured result of a media (audio or video) transcription.
#[derive(Debug, Clone, serde::Serialize)]
pub struct MediaTranscription {
    pub text: String,
    pub duration_ms: i64,
    pub container: String,
}

/// Transcribe an audio or video file, chunking long media with context
/// carry-over and emitting `media://progress` events (fraction + container).
/// Returns the transcript plus container/duration metadata.
#[tauri::command]
pub async fn transcribe_media(
    window: tauri::Window,
    state: State<'_, AppState>,
    path: String,
) -> AppResult<MediaTranscription> {
    main_window_only(&window)?;
    let path = std::path::PathBuf::from(path);
    if !path.is_file() {
        return Err(AppError::Other("file not found".into()));
    }
    let app = state.app.clone();
    let _ = app.emit(
        "media://progress",
        serde_json::json!({ "phase": "decode", "fraction": 0.0 }),
    );
    let decoded = crate::util::decode::decode_file(&path)?;
    let engine = state.ensure_engine()?;
    let settings = state.settings_snapshot();
    let _ = app.emit(
        "media://progress",
        serde_json::json!({ "phase": "transcribe", "fraction": 0.5 }),
    );
    let text = crate::media::transcribe_media(
        engine.as_ref(),
        &decoded.samples,
        decoded.sample_rate,
        settings.engine.language.as_deref(),
    )?;
    let container = match decoded.container {
        crate::util::decode::MediaContainer::Video => "video",
        crate::util::decode::MediaContainer::Audio => "audio",
    };
    // Persist the result to local history, tagging media provenance so the
    // history view can distinguish media transcriptions from dictation.
    let media_json = serde_json::json!({
        "container": container,
        "duration_ms": decoded.duration_ms,
        "source": path.to_string_lossy()
    });
    let _ = state.db.insert_transcript_with_media(
        &text,
        &text,
        engine.id(),
        engine.model_id(),
        "media",
        engine.id(),
        decoded.duration_ms,
        &media_json.to_string(),
    );
    let _ = app.emit(
        "media://progress",
        serde_json::json!({ "phase": "done", "fraction": 1.0 }),
    );
    Ok(MediaTranscription {
        text,
        duration_ms: decoded.duration_ms,
        container: container.to_string(),
    })
}

/// Summarize a transcript via the configured Stage-2 provider (NV oOS or a
/// local LM Studio/Ollama model). Returns the summarization artifact (JSON).
#[tauri::command]
pub async fn summarize_media(
    window: tauri::Window,
    state: State<'_, AppState>,
    text: String,
) -> AppResult<String> {
    main_window_only(&window)?;
    let settings = state.settings_snapshot();
    let provider = crate::cleanup::create_provider(&settings, state.secrets.as_ref())?
        .ok_or_else(|| AppError::NotConfigured("no Stage-2 provider configured".into()))?;
    provider.summarize(&text).await
}

/// Open a native file picker filtered to supported audio/video media and
/// return the chosen path (or `None` if the user cancels). The actual file
/// access stays in the Rust core — the webview never touches the filesystem.
#[tauri::command]
pub async fn pick_media_file(window: tauri::Window) -> AppResult<Option<String>> {
    main_window_only(&window)?;
    use tauri_plugin_dialog::DialogExt;
    let picked = window
        .dialog()
        .file()
        .add_filter(
            "All media (audio + video)",
            &[
                "mp4", "mov", "mkv", "webm", "m4a", "mp3", "wav", "flac", "ogg",
            ],
        )
        .add_filter("Video", &["mp4", "mov", "mkv", "webm"])
        .add_filter("Audio", &["m4a", "mp3", "wav", "flac", "ogg"])
        .blocking_pick_file();
    Ok(picked
        .and_then(|p| p.into_path().ok())
        .map(|path| path.to_string_lossy().into_owned()))
}

// ---- Dictation -----------------------------------------------------------

/// Cancel an in-flight dictation (the user can also press Esc).
#[tauri::command]
pub fn cancel_dictation() {
    // No-op placeholder: cancellation is driven by the Esc hotkey. Kept as a
    // command so the overlay UI has a stable button surface.
}

// ---- Snippet OCR (Proposal 100) ------------------------------------------

/// Whether snippet OCR is compiled into this build.
#[tauri::command]
pub fn snippet_available() -> bool {
    crate::snippet::available()
}

/// The selection overlay reports its region (virtual-screen pixels). Called
/// **from the snippet window** — deliberately NOT main-window-gated, but
/// validated: only accepted while a frozen frame is active, and the rect is
/// clamped inside the controller.
#[tauri::command]
pub fn snippet_region_selected(
    state: State<'_, AppState>,
    x: i32,
    y: i32,
    w: u32,
    h: u32,
) -> AppResult<()> {
    let rect = crate::snippet::RegionRect { x, y, w, h };
    if rect.w == 0 || rect.h == 0 {
        return Err(AppError::Other("empty selection".into()));
    }
    state.snippet.commit(&state.app, rect)
}

/// The selection overlay cancels (Esc / right-click). Called from the
/// snippet window.
#[tauri::command]
pub fn snippet_cancel(state: State<'_, AppState>) -> AppResult<()> {
    state.snippet.cancel(&state.app);
    Ok(())
}

/// Snippet OCR history rows (only populated when `retain_history` is on).
#[tauri::command]
pub fn list_snippets(
    window: tauri::Window,
    state: State<'_, AppState>,
    limit: Option<i64>,
    offset: Option<i64>,
) -> AppResult<Vec<crate::storage::db::SnippetRow>> {
    main_window_only(&window)?;
    state.db.list_snippets(
        limit.unwrap_or(100).clamp(1, 500),
        offset.unwrap_or(0).max(0),
    )
}

/// Delete one snippet history row.
#[tauri::command]
pub fn delete_snippet(window: tauri::Window, state: State<'_, AppState>, id: i64) -> AppResult<()> {
    main_window_only(&window)?;
    state.db.delete_snippet(id)
}

/// The RapidOCR language-pack catalogue with install state.
#[tauri::command]
pub fn get_snippet_models(
    state: State<'_, AppState>,
) -> Vec<crate::snippet::models::SnippetModelStatus> {
    crate::snippet::models::status_list(&state.models_dir())
}

/// Download a RapidOCR language pack (streaming + SHA-256 verified).
#[tauri::command]
pub async fn download_snippet_model(
    window: tauri::Window,
    state: State<'_, AppState>,
    pack: String,
    force: bool,
) -> AppResult<String> {
    main_window_only(&window)?;
    let spec = crate::snippet::models::find(&pack)
        .ok_or_else(|| AppError::Model(format!("unknown OCR language pack '{pack}'")))?;
    let dir = state.models_dir();
    let app = state.app.clone();
    let pack_id = pack.clone();
    let progress = move |fraction: f64| {
        let _ = app.emit(
            "model://progress",
            serde_json::json!({ "engine": "snippet_ocr", "model": pack_id, "fraction": fraction }),
        );
    };
    let path = crate::snippet::models::download(spec, &dir, force, progress).await?;
    Ok(path.to_string_lossy().into_owned())
}

/// Installed Windows OCR recognizer languages (BCP-47 tags) for the
/// Settings dropdown. Empty on non-Windows.
#[tauri::command]
pub fn list_ocr_languages() -> Vec<String> {
    #[cfg(all(windows, feature = "snippet"))]
    {
        crate::snippet::ocr_winrt::list_languages()
    }
    #[cfg(not(all(windows, feature = "snippet")))]
    {
        Vec::new()
    }
}

// ---- Document OCR (Proposal 109) -----------------------------------------

/// Whether the document pipeline is compiled into this build.
#[tauri::command]
pub fn document_available() -> bool {
    crate::document::available()
}

/// Whether the document-model tier is compiled in (Proposal 111).
#[tauri::command]
pub fn document_model_available() -> bool {
    crate::document::available()
}

/// Test the document-model endpoint: builds the worker from the current
/// settings and sends a one-token ping. Quality is the probe's job — this
/// proves the local server answers.
#[cfg(feature = "document")]
#[tauri::command]
pub async fn test_document_model_connection(state: State<'_, AppState>) -> AppResult<String> {
    let settings = state.settings_snapshot();
    let worker = crate::document::model::build_document_model(&settings, state.secrets.as_ref())
        .ok_or_else(|| {
            AppError::NotConfigured(
                "document model tier is disabled or no local provider is configured".into(),
            )
        })?;
    let outcome = tauri::async_runtime::spawn_blocking(move || worker.test_connection())
        .await
        .map_err(|e| AppError::Other(format!("connection test failed: {e}")))?;
    outcome
}

/// Stub for builds without the `document` feature (the command surface is
/// stable; the body fails cleanly).
#[cfg(not(feature = "document"))]
#[tauri::command]
pub async fn test_document_model_connection(_state: State<'_, AppState>) -> AppResult<String> {
    Err(AppError::NotConfigured(
        "document pipeline not compiled into this build".into(),
    ))
}

/// Pick a document file (PDF, TIFF, or raster image) via the native dialog.
#[tauri::command]
pub fn pick_document(window: tauri::Window) -> AppResult<Option<String>> {
    main_window_only(&window)?;
    use tauri_plugin_dialog::DialogExt;
    let picked = window
        .dialog()
        .file()
        .add_filter("Documents", &["pdf", "tif", "tiff"])
        .add_filter("Images", &["png", "jpg", "jpeg", "webp"])
        .blocking_pick_file();
    Ok(picked
        .and_then(|p| p.into_path().ok())
        .map(|path| path.to_string_lossy().into_owned()))
}

/// Run the document pipeline on a picked file. The heavy work runs on a
/// blocking thread; per-page progress streams on `document://progress` and
/// the final result is returned to the caller.
#[tauri::command]
pub async fn transcribe_document(
    window: tauri::Window,
    state: State<'_, AppState>,
    path: String,
) -> AppResult<crate::document::DocumentResult> {
    main_window_only(&window)?;
    let path = std::path::PathBuf::from(path);
    if !path.is_file() {
        return Err(AppError::Other("file not found".into()));
    }
    let controller = state.document.clone();
    if controller.is_busy() {
        return Err(AppError::Other(
            "a document is already being processed".into(),
        ));
    }
    controller.set_busy(true);

    let settings = state.settings_snapshot();
    let models_dir = state.models_dir();
    let secrets = state.secrets.clone();
    let app = state.app.clone();
    let path_for_task = path.clone();
    let controller_task = controller.clone();
    let settings_task = settings.clone();
    let outcome = tauri::async_runtime::spawn_blocking(move || {
        crate::document::controller::run_pipeline(
            &app,
            &controller_task,
            &settings_task,
            &models_dir,
            secrets.as_ref(),
            &path_for_task,
        )
    })
    .await
    .map_err(|e| AppError::Other(format!("document worker failed: {e}")))?;
    controller.set_busy(false);

    match outcome {
        Ok(result) => {
            if settings.snippet.retain_history {
                let combined = result.combined_text();
                let (text_pages, _ocr_pages, _polished, _model_pages) = result.provenance_counts();
                let engine = if text_pages == 0 {
                    "document-ocr"
                } else {
                    "document"
                };
                let _ = state.db.insert_document_snippet(
                    &combined,
                    &combined,
                    engine,
                    &result.path,
                    result.page_count as u32,
                    result.duration_ms as u64,
                );
            }
            Ok(result)
        }
        Err(e) => {
            use std::sync::atomic::Ordering;
            controller.stats.errors.fetch_add(1, Ordering::Relaxed);
            *controller.stats.last_error.lock().unwrap() = Some(e.to_string());
            Err(e)
        }
    }
}

/// Abort the in-flight document run at the next page boundary.
#[tauri::command]
pub fn cancel_document(state: State<'_, AppState>) {
    state.document.cancel();
}

/// Export the last document result. Text formats (`txt` | `md` | `hocr` |
/// `alto`) are string emitters; `pdf` rebuilds the source as a searchable
/// PDF (feature `snippet-pdf`). A save dialog asks for the destination —
/// exports only ever happen on an explicit user action.
#[tauri::command]
pub fn export_document(
    window: tauri::Window,
    state: State<'_, AppState>,
    format: String,
) -> AppResult<Option<String>> {
    main_window_only(&window)?;
    #[cfg(not(feature = "document"))]
    {
        let _ = (&window, &format, &state);
        Err(AppError::NotConfigured(
            "document pipeline not compiled into this build".into(),
        ))
    }
    #[cfg(feature = "document")]
    {
        let result = state
            .document
            .last_result()
            .ok_or_else(|| AppError::Document("no document result to export".into()))?;
        use tauri_plugin_dialog::DialogExt;

        if format == "pdf" {
            if !crate::document::pdf_export_available() {
                return Err(AppError::NotConfigured(
                    "searchable-PDF export is not compiled into this build (feature snippet-pdf)"
                        .into(),
                ));
            }
            #[cfg(feature = "snippet-pdf")]
            {
                let settings = state.settings_snapshot();
                let picked = window
                    .dialog()
                    .file()
                    .set_file_name(default_export_name(&result, "pdf"))
                    .add_filter("PDF", &["pdf"])
                    .blocking_save_file();
                let Some(dest) = picked.and_then(|p| p.into_path().ok()) else {
                    return Ok(None);
                };
                crate::document::export_pdf::export_searchable_pdf(
                    std::path::Path::new(&result.path),
                    &result,
                    &dest,
                    settings.document.dpi,
                )?;
                return Ok(Some(dest.to_string_lossy().into_owned()));
            }
            #[cfg(not(feature = "snippet-pdf"))]
            {
                return Err(AppError::NotConfigured(
                    "searchable-PDF export is not compiled into this build (feature snippet-pdf)"
                        .into(),
                ));
            }
        }

        let content = crate::document::export::emit(&format, &result)?;
        let ext = if format == "md" {
            "md"
        } else {
            format.as_str()
        };
        let picked = window
            .dialog()
            .file()
            .set_file_name(default_export_name(&result, ext))
            .add_filter("Export", &[ext])
            .blocking_save_file();
        let Some(dest) = picked.and_then(|p| p.into_path().ok()) else {
            return Ok(None);
        };
        std::fs::write(&dest, content)?;
        Ok(Some(dest.to_string_lossy().into_owned()))
    }
}

/// Default export filename: source stem + suffix.
#[cfg(feature = "document")]
fn default_export_name(result: &crate::document::DocumentResult, ext: &str) -> String {
    let stem = std::path::Path::new(&result.path)
        .file_stem()
        .and_then(|s| s.to_str())
        .unwrap_or("document");
    match ext {
        "pdf" => format!("{stem}-searchable.pdf"),
        "md" => format!("{stem}.md"),
        "hocr" => format!("{stem}.hocr.html"),
        "alto" => format!("{stem}.alto.xml"),
        _ => format!("{stem}.{ext}"),
    }
}

#[cfg(test)]
mod tests {
    use super::validate_spa_url;

    #[test]
    fn accepts_https_and_trims_trailing_slash() {
        assert_eq!(
            validate_spa_url("https://chat.nvoos.cloud/").unwrap(),
            "https://chat.nvoos.cloud"
        );
        assert_eq!(
            validate_spa_url("  https://example.com  ").unwrap(),
            "https://example.com"
        );
    }

    #[test]
    fn accepts_localhost_development() {
        assert_eq!(
            validate_spa_url("http://localhost:5199").unwrap(),
            "http://localhost:5199"
        );
        assert_eq!(
            validate_spa_url("http://127.0.0.1:5199").unwrap(),
            "http://127.0.0.1:5199"
        );
    }

    #[test]
    fn rejects_plaintext_remote_host() {
        assert!(validate_spa_url("http://example.com").is_err());
        assert!(validate_spa_url("ftp://example.com").is_err());
        assert!(validate_spa_url("example.com").is_err());
    }

    #[test]
    fn rejects_empty() {
        assert!(validate_spa_url("").is_err());
        assert!(validate_spa_url("   ").is_err());
    }
}
