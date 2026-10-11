//! NV oOS Dictation — library entry point (Tauri mobile-ready split).

pub mod audio;
pub mod cleanup;
pub mod config;
pub mod dictation;
pub mod error;
pub mod hotkey;
pub mod ipc;
#[cfg(feature = "mcp")]
pub mod mcp;
pub mod meetings;
pub mod nvoos;
pub mod paste;
pub mod state;
pub mod storage;
pub mod stt;
pub mod util;

use tauri::Manager;

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
        .setup(|app| {
            let handle = app.handle().clone();
            state::setup(&handle)?;
            ensure_overlay(&handle);

            // Load persisted settings into state and start the core graph.
            let app_state = handle.state::<state::AppState>();
            let _ = app_state.reload_settings();
            dictation::DictationController::spawn(handle.clone())?;
            Ok(())
        })
        .invoke_handler(tauri::generate_handler![
            ipc::get_settings,
            ipc::update_settings,
            ipc::get_status,
            ipc::set_nvoos_credential,
            ipc::clear_nvoos_credential,
            ipc::test_nvoos_connection,
            ipc::set_openai_api_key,
            ipc::clear_openai_api_key,
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
            ipc::download_model,
            ipc::transcribe_file,
            ipc::cancel_dictation,
        ]);

    builder
        .run(tauri::generate_context!())
        .expect("error while running NV oOS Dictation");
}
