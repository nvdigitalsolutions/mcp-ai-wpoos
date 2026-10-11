//! STT latency/WER benchmark harness (fala/Handy `transcribe_file` pattern).
//!
//! Usage:
//!   cargo run --release --example transcribe_file -- <audio-file> [--engine mock]
//!
//! Decodes any symphonia-supported file, runs the selected engine, prints
//! wall-clock decode + transcription timing, and — when a `.txt` reference
//! sits next to the audio file — a crude word-error rate.

use std::path::PathBuf;
use std::time::Instant;

use nvoos_dictate_lib::config::EngineConfig;
use nvoos_dictate_lib::error::AppResult;
use nvoos_dictate_lib::stt::create_engine;
use nvoos_dictate_lib::util::decode;

fn main() -> AppResult<()> {
    let args: Vec<String> = std::env::args().collect();
    if args.len() < 2 {
        eprintln!("usage: transcribe_file <audio-file> [--engine <engine>] [--model <model>]");
        std::process::exit(2);
    }
    let path = PathBuf::from(&args[1]);
    let mut engine_name = "mock".to_string();
    let mut model = "sherpa-onnx-nemo-parakeet-tdt-0.6b-v2-int8".to_string();
    let mut i = 2;
    while i < args.len() {
        match args[i].as_str() {
            "--engine" => {
                engine_name = args.get(i + 1).cloned().unwrap_or_default();
                i += 2;
            }
            "--model" => {
                model = args.get(i + 1).cloned().unwrap_or_default();
                i += 2;
            }
            _ => i += 1,
        }
    }

    let decode_start = Instant::now();
    let decoded = decode::decode_file(&path)?;
    let decode_ms = decode_start.elapsed().as_millis();

    let settings = EngineConfig {
        engine: engine_name.clone(),
        model: model.clone(),
        ..Default::default()
    };
    let models_dir = dirs::data_dir()
        .unwrap_or_else(|| PathBuf::from("."))
        .join("nvoos-dictate")
        .join("models");
    let engine = create_engine(
        &settings,
        &nvoos_dictate_lib::stt::EngineResources::local_only(&models_dir),
    )?;
    engine.warm()?;

    let stt_start = Instant::now();
    let text = engine.transcribe(&decoded.samples, decoded.sample_rate, None)?;
    let stt_ms = stt_start.elapsed().as_millis();

    let audio_secs = decoded.samples.len() as f64 / decoded.sample_rate as f64;
    println!("file:       {}", path.display());
    println!("engine:     {engine_name} ({model})");
    println!("audio:      {audio_secs:.2}s @ {} Hz", decoded.sample_rate);
    println!("decode:     {decode_ms} ms");
    println!(
        "transcribe: {stt_ms} ms (rtf {:.2})",
        stt_ms as f64 / (audio_secs * 1000.0)
    );
    println!("--- transcript ---");
    println!("{text}");

    // Crude WER against a sidecar reference file (<audio>.txt).
    let reference_path = path.with_extension("txt");
    if reference_path.exists() {
        let reference = std::fs::read_to_string(reference_path).unwrap_or_default();
        let (distance, ref_len) = wer(&text, &reference);
        let pct = if ref_len == 0 {
            0.0
        } else {
            100.0 * distance as f64 / ref_len as f64
        };
        println!("--- wer ---");
        println!("distance {distance} / reference words {ref_len} = {pct:.1}%");
    }
    Ok(())
}

/// Simple Levenshtein word-error rate.
fn wer(hypothesis: &str, reference: &str) -> (usize, usize) {
    let hyp: Vec<&str> = hypothesis.split_whitespace().collect();
    let reference: Vec<&str> = reference.split_whitespace().collect();
    let (n, m) = (hyp.len(), reference.len());
    let mut dp = vec![vec![0usize; m + 1]; n + 1];
    for (i, row) in dp.iter_mut().enumerate() {
        row[0] = i;
    }
    for (j, cell) in dp[0].iter_mut().enumerate() {
        *cell = j;
    }
    for i in 1..=n {
        for j in 1..=m {
            let cost = if hyp[i - 1].eq_ignore_ascii_case(reference[j - 1]) {
                0
            } else {
                1
            };
            dp[i][j] = (dp[i - 1][j] + 1)
                .min(dp[i][j - 1] + 1)
                .min(dp[i - 1][j - 1] + cost);
        }
    }
    (dp[n][m], m)
}
