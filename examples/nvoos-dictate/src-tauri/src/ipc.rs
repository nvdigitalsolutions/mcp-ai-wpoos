//! Typed IPC commands exposed to the webview.
//!
//! Tauri trust-boundary rules: every command validates its inputs in Rust,
//! secrets never travel back to the webview (only presence booleans), and
//! all state changes go through `AppState` (single writer per resource).

use serde::Serialize;
use tauri::{Emitter, State};

use crate::config::Settings;
use crate::error::{AppError, AppResult};
use crate::state::{
    AppState, KEY_NVOOS_CREDENTIAL, KEY_OPENAI_API_KEY, KEY_WP_APP_PASSWORD, KEY_WP_USERNAME,
};
use crate::storage::db::{MeetingRow, TranscriptRow};

#[derive(Debug, Clone, Serialize)]
pub struct StatusInfo {
    pub engine: Option<String>,
    pub model: Option<String>,
    pub engine_features: String,
    pub mic_available: bool,
    pub nvoos_configured: bool,
    pub nvoos_credential_stored: bool,
    pub openai_key_stored: bool,
    pub wp_app_password_stored: bool,
    pub meeting_recording: bool,
    pub system_audio_available: bool,
    pub version: String,
}

#[tauri::command]
pub fn get_settings(state: State<'_, AppState>) -> AppResult<Settings> {
    Ok(state.settings_snapshot())
}

#[tauri::command]
pub fn update_settings(state: State<'_, AppState>, settings: Settings) -> AppResult<Settings> {
    let previous = state.settings_snapshot();
    let updated = state.update_settings(settings)?;
    state.reapply_engine(&previous)?;
    Ok(updated)
}

#[tauri::command]
pub fn get_status(state: State<'_, AppState>) -> AppResult<StatusInfo> {
    let settings = state.settings_snapshot();
    let engine = state.engine.read().unwrap().clone();
    Ok(StatusInfo {
        engine: engine.as_ref().map(|e| e.id().to_string()),
        model: engine.as_ref().map(|e| e.model_id().to_string()),
        engine_features: crate::stt::available_features().to_string(),
        mic_available: state.mic.lock().unwrap().is_some(),
        nvoos_configured: !settings.nvoos.site_url.is_empty(),
        nvoos_credential_stored: state.has_nvoos_credential(),
        openai_key_stored: state.has_openai_key(),
        wp_app_password_stored: state.has_wp_app_password(),
        meeting_recording: state.recorder.is_recording(),
        system_audio_available: crate::meetings::system_audio_available(),
        version: env!("CARGO_PKG_VERSION").to_string(),
    })
}

// ---- NV oOS credential (keychain) ---------------------------------------

#[tauri::command]
pub fn set_nvoos_credential(
    state: State<'_, AppState>,
    site_url: String,
    credential: String,
) -> AppResult<()> {
    let site_url = site_url.trim().trim_end_matches('/').to_string();
    if site_url.is_empty() || credential.is_empty() {
        return Err(AppError::Auth(
            "site URL and credential are required".into(),
        ));
    }
    // Sanity-check the endpoint contract before storing anything.
    let client = crate::nvoos::NvoosClient::new(site_url, credential.clone())?;
    let _ = client;
    state.secrets.set(KEY_NVOOS_CREDENTIAL, &credential)?;
    Ok(())
}

#[tauri::command]
pub fn clear_nvoos_credential(state: State<'_, AppState>) -> AppResult<()> {
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
pub fn set_openai_api_key(state: State<'_, AppState>, api_key: String) -> AppResult<()> {
    if api_key.trim().is_empty() {
        return Err(AppError::Auth("API key must not be empty".into()));
    }
    state.secrets.set(KEY_OPENAI_API_KEY, api_key.trim())
}

#[tauri::command]
pub fn clear_openai_api_key(state: State<'_, AppState>) -> AppResult<()> {
    state.secrets.delete(KEY_OPENAI_API_KEY)
}

// ---- WP application password (embedded STT auth) --------------------------

#[tauri::command]
pub fn set_wp_app_password(
    state: State<'_, AppState>,
    username: String,
    app_password: String,
) -> AppResult<()> {
    if username.trim().is_empty() || app_password.trim().is_empty() {
        return Err(AppError::Auth(
            "WP username and application password are required".into(),
        ));
    }
    state.secrets.set(KEY_WP_USERNAME, username.trim())?;
    state
        .secrets
        .set(KEY_WP_APP_PASSWORD, app_password.trim())?;
    // The auth change may affect the resident `embedded` engine.
    *state.engine.write().unwrap() = None;
    Ok(())
}

#[tauri::command]
pub fn clear_wp_app_password(state: State<'_, AppState>) -> AppResult<()> {
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
pub fn delete_history(state: State<'_, AppState>, id: i64) -> AppResult<()> {
    state.db.delete_transcript(id)
}

#[tauri::command]
pub fn clear_history(state: State<'_, AppState>) -> AppResult<()> {
    state.db.clear_transcripts()
}

#[tauri::command]
pub fn export_history(state: State<'_, AppState>) -> AppResult<String> {
    let rows = state.db.list_transcripts("", 100_000, 0)?;
    serde_json::to_string_pretty(&rows).map_err(Into::into)
}

// ---- Dictionary ----------------------------------------------------------

#[tauri::command]
pub fn get_dictionary(state: State<'_, AppState>) -> AppResult<Vec<(String, String)>> {
    state.db.dictionary()
}

#[tauri::command]
pub fn set_dictionary(state: State<'_, AppState>, entries: Vec<(String, String)>) -> AppResult<()> {
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
pub fn delete_meeting(state: State<'_, AppState>, id: i64) -> AppResult<()> {
    state.db.delete_meeting(id)
}

// ---- Models & file transcription ----------------------------------------

#[tauri::command]
pub fn get_models() -> Vec<crate::stt::model_registry::ModelSpec> {
    crate::stt::model_registry::CATALOG.to_vec()
}

#[tauri::command]
pub async fn download_model(
    state: State<'_, AppState>,
    engine: String,
    model: String,
) -> AppResult<String> {
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
    let path = crate::stt::model_registry::download(spec, &dir, progress).await?;
    // Engine swap picks the new model up.
    let previous = state.settings_snapshot();
    *state.engine.write().unwrap() = None;
    let _ = state.reapply_engine(&previous);
    Ok(path.to_string_lossy().into_owned())
}

#[tauri::command]
pub async fn transcribe_file(state: State<'_, AppState>, path: String) -> AppResult<String> {
    let path = std::path::PathBuf::from(path);
    if !path.is_file() {
        return Err(AppError::Other("file not found".into()));
    }
    let decoded = crate::util::decode::decode_file(&path)?;
    let engine = state.ensure_engine()?;
    let settings = state.settings_snapshot();
    engine.transcribe(
        &decoded.samples,
        decoded.sample_rate,
        settings.engine.language.as_deref(),
    )
}

// ---- Dictation -----------------------------------------------------------

/// Cancel an in-flight dictation (the user can also press Esc).
#[tauri::command]
pub fn cancel_dictation() {
    // No-op placeholder: cancellation is driven by the Esc hotkey. Kept as a
    // command so the overlay UI has a stable button surface.
}
