// Prevents an additional console window on Windows in release; DO NOT
// REMOVE (comment per Tauri template — required by some tooling lints).
#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

fn main() {
    // Headless MCP stdio server mode (feature `mcp`):
    //   nvoos-dictate --mcp
    // Spawned by desktop AI clients. History/dictionary/meeting tools work
    // headless; `dictate` asks the user to run the desktop app (which hosts
    // the same tools for the NV oOS Remote Sites surface).
    #[cfg(feature = "mcp")]
    if std::env::args().any(|a| a == "--mcp") {
        return run_mcp_stdio();
    }

    nvoos_dictate_lib::run()
}

#[cfg(feature = "mcp")]
fn run_mcp_stdio() {
    let db = match nvoos_dictate_lib::storage::Db::open_default() {
        Ok(db) => std::sync::Arc::new(db),
        Err(e) => {
            eprintln!("nvoos-dictate: failed to open database: {e}");
            std::process::exit(1);
        }
    };
    let recorder = std::sync::Arc::new(
        nvoos_dictate_lib::meetings::recorder::MeetingRecorder::new(16000),
    );

    // Headless mode: load the configured engine (mock by default) so the
    // meeting/history tools work; `dictate` still asks for the desktop app.
    let engine: Option<std::sync::Arc<dyn nvoos_dictate_lib::stt::SttEngine>> = {
        let settings = nvoos_dictate_lib::config::Settings::load().unwrap_or_default();
        let models_dir = dirs::data_dir()
            .unwrap_or_else(|| std::path::PathBuf::from("."))
            .join("nvoos-dictate")
            .join("models");
        match nvoos_dictate_lib::stt::create_engine(
            &settings.engine,
            &nvoos_dictate_lib::stt::EngineResources::local_only(&models_dir),
        ) {
            Ok(engine) => Some(std::sync::Arc::from(engine)),
            Err(e) => {
                eprintln!("nvoos-dictate: engine unavailable in headless mode: {e}");
                None
            }
        }
    };
    let dictate = std::sync::Arc::new(
        |_hint: Option<String>| -> nvoos_dictate_lib::error::AppResult<String> {
            Err(nvoos_dictate_lib::error::AppError::Unsupported(
                "dictation requires the NV oOS Dictation desktop app to be running (the headless \
             MCP server cannot capture the PTT hotkey). Start the app and connect via Remote \
             Sites — see docs/remote-sites-recipe.md."
                    .into(),
            ))
        },
    );

    let rt = tokio::runtime::Builder::new_multi_thread()
        .enable_all()
        .build()
        .expect("tokio runtime");
    rt.block_on(async move {
        if let Err(e) = nvoos_dictate_lib::mcp::serve_stdio(db, recorder, engine, dictate).await {
            eprintln!("nvoos-dictate mcp server stopped: {e}");
            std::process::exit(1);
        }
    });
}
