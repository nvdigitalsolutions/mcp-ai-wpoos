//! Model download CLI — pulls a registry entry through the same streaming
//! SHA-256 + atomic-install path the GUI uses (never bundle weights).
//!
//! Usage:
//!   cargo run --features parakeet --example download_model -- \
//!       parakeet sherpa-onnx-nemo-parakeet-tdt-0.6b-v2-int8
//!   cargo run --example download_model -- whisper tiny.en

use std::path::PathBuf;

use nvoos_dictate_lib::error::{AppError, AppResult};
use nvoos_dictate_lib::stt::model_registry::{self};

fn main() -> AppResult<()> {
    let args: Vec<String> = std::env::args().collect();
    if args.len() < 3 {
        eprintln!("usage: download_model <engine> <model>");
        std::process::exit(2);
    }
    let spec = model_registry::find(&args[1], &args[2])
        .ok_or_else(|| AppError::Model(format!("unknown model '{}/{}'", args[1], args[2])))?;

    let models_dir = dirs::data_dir()
        .unwrap_or_else(|| PathBuf::from("."))
        .join("nvoos-dictate")
        .join("models");

    println!(
        "downloading {} (~{} MB) from {}",
        spec.id, spec.size_mb, spec.url
    );

    let rt = tokio::runtime::Runtime::new()?;
    let progress = |f: f64| {
        if f < 1.0 {
            eprint!("\rprogress: {:.1}%", f * 100.0);
        } else {
            eprintln!("\rdownload complete, verifying + installing");
        }
    };
    let installed = rt.block_on(model_registry::download(spec, &models_dir, progress))?;
    println!("installed: {}", installed.display());
    Ok(())
}
