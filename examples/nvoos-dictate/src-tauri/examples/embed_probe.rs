//! Memory-layer de-risk spike (Proposal 127, M0): the live-verification
//! gate for the encoder. Downloads the pinned granite embedder files
//! (streaming sha256 + atomic install), loads tokenizer + ort session, and
//! embeds fixture sentences — reporting tensor dims, RTF, and cross-lingual
//! cosine sanity (an English sentence must embed closer to its DE/FR/ES
//! translations than to unrelated text — the probe SurfSense used to expose
//! bge-small's English-only failure).
//!
//! ```bash
//! cargo run --features memory --example embed_probe -- --download
//! cargo run --features memory --example embed_probe -- --model granite-97m-r2-fp32 --force
//! ```

use std::path::PathBuf;
use std::time::Instant;

use nvoos_dictate_lib::error::AppResult;
use nvoos_dictate_lib::memory::embed::{Embedder, OrtEmbedder};
use nvoos_dictate_lib::memory::models;

fn main() -> AppResult<()> {
    let args: Vec<String> = std::env::args().collect();
    let download = args.iter().any(|a| a == "--download");
    let force = args.iter().any(|a| a == "--force");
    let spec_id = args
        .iter()
        .find_map(|a| a.strip_prefix("--model="))
        .unwrap_or("granite-97m-r2-int8");
    let spec =
        models::spec_by_id(spec_id).expect("unknown --model id (see memory::models::CATALOGUE)");

    let models_dir = dirs::data_dir()
        .unwrap_or_else(|| PathBuf::from("."))
        .join("nvoos-dictate")
        .join("models");

    if download || force || !models::is_installed(&models_dir, spec) {
        println!(
            "downloading '{}' (~{} MB, license: {})...",
            spec.id, spec.size_mb, spec.license
        );
        let rt = tokio::runtime::Runtime::new()?;
        rt.block_on(models::download(spec, &models_dir, force, |p| {
            eprintln!("  {:.0}%", p * 100.0);
        }))?;
        println!(
            "installed into {}",
            models::install_dir(&models_dir, spec).display()
        );
    }

    let (onnx, tokenizer) = models::model_paths(&models_dir, spec);
    println!("loading {}", onnx.display());
    let mut embedder = OrtEmbedder::load(&onnx, &tokenizer)?;
    println!("dim: {}", embedder.dim());

    let fixtures: Vec<String> = vec![
        "The quick brown fox jumps over the lazy dog.".into(),
        "Der schnelle braune Fuchs springt über den faulen Hund.".into(),
        "Le renard brun rapide saute par-dessus le chien paresseux.".into(),
        "El rápido zorro marrón salta sobre el perro perezoso.".into(),
        "A completely unrelated sentence about quantum computing.".into(),
    ];

    let started = Instant::now();
    let vectors = embedder.embed_batch(&fixtures)?;
    let elapsed = started.elapsed();
    println!(
        "embedded {} sentences in {:?} ({:.1} ms each)",
        fixtures.len(),
        elapsed,
        elapsed.as_millis() as f64 / fixtures.len() as f64
    );
    for (i, v) in vectors.iter().enumerate() {
        let norm: f32 = v.iter().map(|x| x * x).sum::<f32>().sqrt();
        println!("  [{}] dim={} norm={:.6}", i, v.len(), norm);
    }

    let cosine = |a: &[f32], b: &[f32]| -> f32 { a.iter().zip(b).map(|(x, y)| x * y).sum() };
    println!("cosine(en, de) = {:.4}", cosine(&vectors[0], &vectors[1]));
    println!("cosine(en, fr) = {:.4}", cosine(&vectors[0], &vectors[2]));
    println!("cosine(en, es) = {:.4}", cosine(&vectors[0], &vectors[3]));
    let unrelated = cosine(&vectors[0], &vectors[4]);
    println!("cosine(en, unrelated) = {unrelated:.4}");

    let cross_min = cosine(&vectors[0], &vectors[1])
        .min(cosine(&vectors[0], &vectors[2]))
        .min(cosine(&vectors[0], &vectors[3]));
    println!("cross-lingual min: {cross_min:.4} vs unrelated {unrelated:.4}");
    if cross_min <= unrelated {
        eprintln!(
            "WARN: cross-lingual probe FAILED — translations are not closer than unrelated text"
        );
    } else {
        println!("cross-lingual probe OK — the encoder is the real deal");
    }
    Ok(())
}
