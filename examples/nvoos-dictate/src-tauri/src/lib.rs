//! NV oOS Dictation — library entry point (Tauri mobile-ready split).

pub mod agent;
pub mod audio;
pub mod camera;
pub mod cleanup;
pub mod config;
pub mod dictation;
pub mod error;
pub mod hotkey;
pub mod ipc;
#[cfg(feature = "mcp")]
pub mod mcp;
pub mod media;
pub mod meetings;
pub mod nvoos;
pub mod paste;
pub mod permissions;
pub mod snippet;
pub mod state;
pub mod storage;
pub mod stt;
pub mod util;
pub mod vision;

use crate::error::{AppError, AppResult};
use tauri::Manager;
use tauri_plugin_opener::OpenerExt;

/// Create the overlay window (hidden until dictation arms). The overlay is
/// focusless, transparent, always-on-top, and skipped by the taskbar; its
/// capability file grants core IPC only.
fn ensure_overlay(app: &tauri::AppHandle) {
    if app.get_webview_window("overlay").is_some() {
        return;
    }
    let _ = tauri::WebviewWindowBuilder::new(
        app,
        "overlay",
        tauri::WebviewUrl::App("index.html?window=overlay".into()),
    )
    .title("")
    .inner_size(480.0, 120.0)
    .resizable(false)
    .decorations(false)
    .transparent(true)
    .always_on_top(true)
    .skip_taskbar(true)
    .focused(false)
    .visible(false)
    .build();
}

/// Create the snippet selection window (Proposal 100): the inverse of the
/// dictation capsule — a pointer-owning, full-screen, transparent overlay
/// that renders the frozen frame and reports a selection. Created hidden;
/// the controller sizes it to the virtual screen on arm.
fn ensure_snippet_window(app: &tauri::AppHandle) {
    if app.get_webview_window("snippet").is_some() {
        return;
    }
    let _ = tauri::WebviewWindowBuilder::new(
        app,
        "snippet",
        tauri::WebviewUrl::App("index.html?window=snippet".into()),
    )
    .title("")
    .inner_size(640.0, 480.0)
    .resizable(false)
    .decorations(false)
    .transparent(true)
    .always_on_top(true)
    .skip_taskbar(true)
    .focused(false)
    .visible(false)
    .build();
}

/// Whether a navigation inside the console window is allowed: only the
/// configured console origin may navigate in-place. Foreign origins (docs
/// links, external redirects, `target=_blank`) are handed to the system
/// browser instead — the window never becomes a general-purpose browser
/// (the open-redirect abuse class the Tauri 1.3 advisory describes).
fn navigation_allowed(allowed_origin: &str, url: &tauri::Url) -> bool {
    url.origin().ascii_serialization() == allowed_origin
}

/// Create — or refocus — the NV oOS console window pointed at the configured
/// Complete Velocity URL.
///
/// The console gets a **top-level, first-party window** rather than an iframe
/// inside the main window: WebView2 blocks third-party cookies for cross-site
/// frames, and the console's WordPress cookie sign-in (`login.ts`) cannot
/// survive that (its test-cookie check fails and the login screen masks it as
/// "Invalid username or password"). A dedicated window is the industry
/// standard for cookie-authenticated third-party surfaces (same pattern as
/// Electron BrowserWindows hosting OAuth/SSO flows).
pub fn ensure_nvoos_console_window(app: &tauri::AppHandle, raw_url: &str) -> AppResult<()> {
    let url = tauri::Url::parse(raw_url)
        .map_err(|e| AppError::Other(format!("invalid console URL: {e}")))?;
    let origin = url.origin().ascii_serialization();

    if let Some(window) = app.get_webview_window("nvoos-console") {
        let same_target = window
            .url()
            .map(|current| current.origin().ascii_serialization() == origin)
            .unwrap_or(false);
        if same_target {
            let _ = window.show();
            let _ = window.unminimize();
            let _ = window.set_focus();
            return Ok(());
        }
        // The URL changed: the navigation guard is bound to the old origin,
        // so rebuild the window with a fresh guard instead of navigating.
        let _ = window.destroy();
    }

    let guard_origin = origin;
    let opener_nav = app.clone();
    let opener_popup = app.clone();
    let _ =
        tauri::WebviewWindowBuilder::new(app, "nvoos-console", tauri::WebviewUrl::External(url))
            .title("NV oOS Console")
            .inner_size(1200.0, 800.0)
            .min_inner_size(720.0, 520.0)
            .on_navigation(move |u| {
                if navigation_allowed(&guard_origin, u) {
                    return true;
                }
                let _ = opener_nav.opener().open_url(u.to_string(), None::<String>);
                false
            })
            .on_new_window(move |u, _features| {
                let _ = opener_popup
                    .opener()
                    .open_url(u.to_string(), None::<String>);
                tauri::webview::NewWindowResponse::Deny
            })
            .build();
    Ok(())
}

#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    tracing_subscriber::fmt()
        .with_env_filter(
            tracing_subscriber::EnvFilter::try_from_default_env()
                .unwrap_or_else(|_| "nvoos_dictate_lib=info,warn".into()),
        )
        .init();

    let builder = tauri::Builder::default()
        .plugin(tauri_plugin_single_instance::init(|app, _args, _cwd| {
            // Second launch: focus the existing main window.
            if let Some(window) = app.get_webview_window("main") {
                let _ = window.show();
                let _ = window.set_focus();
            }
        }))
        .plugin(tauri_plugin_global_shortcut::Builder::new().build())
        .plugin(tauri_plugin_updater::Builder::new().build())
        .plugin(tauri_plugin_opener::init())
        .plugin(tauri_plugin_dialog::init())
        .on_window_event(|window, event| {
            // Exit when the main window closes: the hidden overlay window
            // would otherwise keep the process alive with no visible UI.
            if let tauri::WindowEvent::CloseRequested { .. } = event {
                if window.label() == "main" {
                    window.app_handle().exit(0);
                }
            }
        })
        .setup(|app| {
            let handle = app.handle().clone();
            state::setup(&handle)?;
            ensure_overlay(&handle);
            ensure_snippet_window(&handle);

            // Load persisted settings into state and start the core graph.
            let app_state = handle.state::<state::AppState>();
            let _ = app_state.reload_settings();
            dictation::DictationController::spawn(handle.clone())?;
            snippet::controller::SnippetController::start(&handle)?;
            Ok(())
        })
        .invoke_handler(tauri::generate_handler![
            ipc::get_settings,
            ipc::update_settings,
            ipc::get_status,
            ipc::check_spa,
            ipc::open_nvoos_console,
            ipc::get_diagnostics,
            ipc::list_input_devices,
            ipc::restart_microphone,
            ipc::list_camera_devices,
            ipc::camera_available,
            ipc::start_camera,
            ipc::stop_camera,
            ipc::get_preview_frame,
            ipc::vision_available,
            ipc::start_vision,
            ipc::stop_vision,
            ipc::get_detections,
            ipc::vision_select_track,
            ipc::get_vision_models,
            ipc::download_vision_model,
            ipc::test_vision_polish,
            ipc::agent_available,
            ipc::agent_run,
            ipc::agent_status,
            ipc::agent_pending,
            ipc::agent_respond,
            ipc::agent_stop,
            ipc::set_nvoos_credential,
            ipc::clear_nvoos_credential,
            ipc::test_nvoos_connection,
            ipc::set_openai_api_key,
            ipc::clear_openai_api_key,
            ipc::set_lm_studio_token,
            ipc::clear_lm_studio_token,
            ipc::test_lm_studio_connection,
            ipc::set_ollama_token,
            ipc::clear_ollama_token,
            ipc::test_ollama_connection,
            ipc::set_wp_app_password,
            ipc::clear_wp_app_password,
            ipc::list_history,
            ipc::delete_history,
            ipc::clear_history,
            ipc::export_history,
            ipc::get_dictionary,
            ipc::set_dictionary,
            ipc::start_meeting,
            ipc::stop_meeting,
            ipc::list_meetings,
            ipc::delete_meeting,
            ipc::get_models,
            ipc::get_compiled_engines,
            ipc::download_model,
            ipc::transcribe_file,
            ipc::transcribe_media,
            ipc::summarize_media,
            ipc::pick_media_file,
            ipc::cancel_dictation,
            ipc::snippet_available,
            ipc::snippet_cancel,
            ipc::snippet_region_selected,
            ipc::list_snippets,
            ipc::delete_snippet,
            ipc::get_snippet_models,
            ipc::download_snippet_model,
            ipc::list_ocr_languages,
            ipc::get_permissions,
            ipc::request_permission,
            ipc::open_system_settings,
        ]);

    builder
        .run(tauri::generate_context!())
        .expect("error while running NV oOS Dictation");
}

#[cfg(test)]
mod tests {
    use super::navigation_allowed;

    #[test]
    fn console_navigation_allows_only_the_configured_origin() {
        let origin = "https://console.nvoos.cloud";
        assert!(navigation_allowed(
            origin,
            &tauri::Url::parse("https://console.nvoos.cloud/wp-login.php").unwrap()
        ));
        assert!(navigation_allowed(
            origin,
            &tauri::Url::parse("https://console.nvoos.cloud/").unwrap()
        ));
        // Other hosts, ports, and schemes are denied (they open externally).
        assert!(!navigation_allowed(
            origin,
            &tauri::Url::parse("https://docs.nvoos.cloud/").unwrap()
        ));
        assert!(!navigation_allowed(
            origin,
            &tauri::Url::parse("https://console.nvoos.cloud:8443/").unwrap()
        ));
        assert!(!navigation_allowed(
            origin,
            &tauri::Url::parse("http://console.nvoos.cloud/").unwrap()
        ));
    }
}
