//! Snippet-OCR risk spike (Proposal 100, M0).
//!
//! Two legs:
//!   (a) WinRT — prove `Windows.Media.Ocr` runs through the `windows` crate
//!       on this machine and returns lines + word boxes for a fixture image.
//!   (b) ONNX  — prove the hand-rolled RapidOCR det+rec pipeline (the same
//!       `ort` rc.9 the vision detector uses) decodes a real model pair.
//!
//! Usage (Windows):
//!   cargo run --features snippet --example snippet_ocr_probe -- \
//!       --engine winrt --image fixture.png [--language en-US]
//!   cargo run --features snippet-onnx --example snippet_ocr_probe -- \
//!       --engine onnx --image fixture.png --det det.onnx --rec rec.onnx --dict dict.txt
//!
//! Prints the recognized text (and per-word boxes) plus wall-clock time.

use std::path::PathBuf;
use std::time::Instant;

use nvoos_dictate_lib::error::AppResult;

fn main() -> AppResult<()> {
    let args: Vec<String> = std::env::args().skip(1).collect();
    let get = |name: &str| -> Option<String> {
        args.iter()
            .position(|a| a == name)
            .and_then(|i| args.get(i + 1).cloned())
    };
    let engine = get("--engine").unwrap_or_else(|| "winrt".into());
    let image_path = get("--image").map(PathBuf::from);
    let image = |_what: &str| {
        image_path.clone().unwrap_or_else(|| {
            eprintln!("missing --image");
            std::process::exit(2);
        })
    };

    match engine.as_str() {
        "winrt" => run_winrt(&image("winrt"), get("--language").as_deref()),
        "onnx" => run_onnx(
            &image("onnx"),
            &PathBuf::from(get("--det").expect("missing --det")),
            &PathBuf::from(get("--rec").expect("missing --rec")),
            &PathBuf::from(get("--dict").expect("missing --dict")),
        ),
        "onnx-raw" => run_onnx_raw(
            &image("onnx-raw"),
            &PathBuf::from(get("--rec").expect("missing --rec")),
        ),
        "download" => run_download(&PathBuf::from(get("--dir").expect("missing --dir"))),
        other => {
            eprintln!("unknown engine '{other}' (winrt | onnx | onnx-raw | download)");
            std::process::exit(2);
        }
    }
}

/// Exercise the production pack downloader (streaming + pinned SHA-256)
/// against the catalogue URLs.
#[cfg(feature = "snippet-onnx")]
fn run_download(dir: &std::path::Path) -> AppResult<()> {
    let pack = nvoos_dictate_lib::snippet::models::find("en")
        .ok_or_else(|| nvoos_dictate_lib::error::AppError::Other("no en pack".into()))?;
    let rt = tokio::runtime::Runtime::new().unwrap();
    rt.block_on(async {
        let path = nvoos_dictate_lib::snippet::models::download(pack, dir, false, |f| {
            if (f * 100.0).fract() < 0.01 {
                eprintln!("progress {:.0}%", f * 100.0);
            }
        })
        .await?;
        println!("installed into {}", path.display());
        println!(
            "installed: {}",
            nvoos_dictate_lib::snippet::models::is_installed(pack, dir)
        );
        Ok(())
    })
}

#[cfg(not(feature = "snippet-onnx"))]
fn run_download(_dir: &std::path::Path) -> AppResult<()> {
    eprintln!("download requires the `snippet-onnx` feature");
    std::process::exit(2);
}

/// Debug mode: run the recognizer over the WHOLE image and print the raw
/// per-timestep argmax indices (class-index mapping forensics).
#[cfg(feature = "snippet-onnx")]
fn run_onnx_raw(image_path: &std::path::Path, rec_path: &std::path::Path) -> AppResult<()> {
    let img = image::open(image_path)
        .map_err(|e| nvoos_dictate_lib::error::AppError::Other(format!("decode: {e}")))?;
    let rgb = img.to_rgb8();
    let (w, h) = rgb.dimensions();
    let crop = nvoos_dictate_lib::snippet::ocr_onnx::OcrBox { x: 0, y: 0, w, h };
    // Reuse the recognizer's preprocessing through a session directly (the
    // crate API only exposes the decoded text).
    let (input, rw, rh) =
        nvoos_dictate_lib::snippet::ocr_onnx::rec_preprocess(rgb.as_raw(), w, h, &crop).unwrap();
    let tensor = ndarray16::Array::from_shape_vec((1, 3, rh as usize, rw as usize), input)
        .map_err(|e| nvoos_dictate_lib::error::AppError::Other(format!("tensor: {e}")))?
        .into_dyn();
    let session = ort::session::Session::builder()?.commit_from_file(rec_path)?;
    let outputs = session.run(ort::inputs![ort::value::Value::from_array(tensor)?]?)?;
    let out = outputs[0].try_extract_tensor::<f32>()?;
    let shape = out.shape().to_vec();
    eprintln!("rec output shape: {:?}", shape);
    let (t, c) = match shape.as_slice() {
        [_, tt, cc, ..] => (*tt, *cc),
        [tt, cc] => (*tt, *cc),
        _ => (0, 0),
    };
    let mut seq = Vec::new();
    let view = out.view();
    for ti in 0..t {
        let row = view.slice(ndarray16::s![0, ti, ..]);
        let mut best = 0usize;
        let mut best_v = f32::MIN;
        for (ci, &v) in row.iter().enumerate() {
            if v > best_v {
                best_v = v;
                best = ci;
            }
        }
        seq.push((best, best_v));
    }
    println!("argmax sequence ({t} timesteps of {c} classes):");
    for (i, v) in &seq {
        println!("  idx={i} conf={v:.3}");
    }
    Ok(())
}

#[cfg(not(feature = "snippet-onnx"))]
fn run_onnx_raw(_image_path: &std::path::Path, _rec_path: &std::path::Path) -> AppResult<()> {
    eprintln!("onnx-raw requires the `snippet-onnx` feature");
    std::process::exit(2);
}

#[cfg(all(windows, feature = "snippet"))]
fn run_winrt(image_path: &std::path::Path, language: Option<&str>) -> AppResult<()> {
    use nvoos_dictate_lib::snippet::ocr_winrt;

    println!("installed OCR languages: {:?}", ocr_winrt::list_languages());
    let engine = ocr_winrt::create_engine(language.unwrap_or(""))?;
    let png = std::fs::read(image_path)?;
    let started = Instant::now();
    let result = ocr_winrt::recognize_png(&engine, &png)?;
    println!("recognized in {} ms", started.elapsed().as_millis());
    println!("--- text ---\n{}", result.text);
    for w in &result.words {
        println!("word {:?} @ ({},{}) {}x{}", w.text, w.x, w.y, w.w, w.h);
    }
    Ok(())
}

#[cfg(not(all(windows, feature = "snippet")))]
fn run_winrt(_image_path: &std::path::Path, _language: Option<&str>) -> AppResult<()> {
    eprintln!("winrt leg is Windows-only with the `snippet` feature");
    std::process::exit(2);
}

#[cfg(feature = "snippet-onnx")]
fn run_onnx(
    image_path: &std::path::Path,
    det_path: &std::path::Path,
    rec_path: &std::path::Path,
    dict_path: &std::path::Path,
) -> AppResult<()> {
    use nvoos_dictate_lib::snippet::ocr_onnx::{recognize_rgb, OcrDetector, OcrRecognizer};

    let detector = OcrDetector::load(det_path)?;
    let recognizer = OcrRecognizer::load(rec_path, dict_path)?;
    let img = image::open(image_path)
        .map_err(|e| nvoos_dictate_lib::error::AppError::Other(format!("decode: {e}")))?;
    let rgb = img.to_rgb8();
    let (w, h) = rgb.dimensions();

    let started = Instant::now();
    let result = recognize_rgb(&detector, &recognizer, rgb.as_raw(), w, h)?;
    println!(
        "recognized in {} ms (engine: {})",
        started.elapsed().as_millis(),
        result.engine
    );
    println!("--- text ---\n{}", result.text);
    for wd in &result.words {
        println!("word {:?} @ ({},{}) {}x{}", wd.text, wd.x, wd.y, wd.w, wd.h);
    }
    Ok(())
}

#[cfg(not(feature = "snippet-onnx"))]
fn run_onnx(
    _image_path: &std::path::Path,
    _det_path: &std::path::Path,
    _rec_path: &std::path::Path,
    _dict_path: &std::path::Path,
) -> AppResult<()> {
    eprintln!("onnx leg requires the `snippet-onnx` feature");
    std::process::exit(2);
}
