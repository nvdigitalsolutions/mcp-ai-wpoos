// SPDX-License-Identifier: LicenseRef-Proprietary
//! Hand-landmark de-risk probe (Proposal 153, M0).
//!
//! Loads a MediaPipe-compatible 21-keypoint ONNX export, runs it on one image,
//! and prints the discovered tensors, the decoded landmarks, and per-stage
//! latency. This is the gate that decides whether a model is usable on the
//! pinned `ort` 2.0 rc.9 runtime — nothing else in the feature depends on a
//! particular export.
//!
//! Usage:
//!   cargo run --features gestures --example gesture_probe -- \
//!       --model path/to/hand_landmark.onnx --image path/to/hand.jpg
//!
//! Optional: `--size 224` (model input), `--mirror` (simulate a selfie view),
//! `--repeat 20` (latency sampling).

use std::path::PathBuf;
use std::time::Instant;

use nvoos_dictate_lib::camera::PreviewFrame;
use nvoos_dictate_lib::gestures::landmarks::{HandPoseBackend, OrtHandPose};

fn main() {
    if let Err(error) = run() {
        eprintln!("gesture_probe: {error}");
        std::process::exit(1);
    }
}

fn run() -> Result<(), String> {
    let mut model: Option<PathBuf> = None;
    let mut image: Option<PathBuf> = None;
    let mut size: u32 = 224;
    let mut mirror = false;
    let mut repeat: u32 = 1;

    let mut args = std::env::args().skip(1);
    while let Some(arg) = args.next() {
        match arg.as_str() {
            "--model" => model = args.next().map(PathBuf::from),
            "--image" => image = args.next().map(PathBuf::from),
            "--size" => {
                size = args
                    .next()
                    .and_then(|v| v.parse().ok())
                    .ok_or_else(|| "--size needs a number".to_string())?
            }
            "--mirror" => mirror = true,
            "--repeat" => {
                repeat = args
                    .next()
                    .and_then(|v| v.parse().ok())
                    .filter(|v| *v > 0)
                    .ok_or_else(|| "--repeat needs a positive number".to_string())?
            }
            "--help" | "-h" => {
                println!(
                    "usage: gesture_probe --model <onnx> [--image <jpg|png>] [--size 224] \
                     [--mirror] [--repeat N]"
                );
                return Ok(());
            }
            other => return Err(format!("unknown argument '{other}'")),
        }
    }

    let model = model.ok_or_else(|| "missing --model <onnx>".to_string())?;
    if !model.is_file() {
        return Err(format!("model not found: {}", model.display()));
    }

    let frame = match image {
        Some(path) => load_frame(&path)?,
        None => {
            println!("no --image supplied: using a synthetic 640x480 grey frame");
            PreviewFrame {
                width: 640,
                height: 480,
                rgb: vec![96; 640 * 480 * 3],
            }
        }
    };

    let load_started = Instant::now();
    let mut backend =
        OrtHandPose::load(&model, size, mirror).map_err(|e| format!("load failed: {e}"))?;
    println!("loaded in {} ms", load_started.elapsed().as_millis());
    println!("backend: {}", backend.name());
    println!("tensors: {}", backend.describe());
    println!("frame: {}x{} mirror={mirror}", frame.width, frame.height);

    let mut best = f64::MAX;
    let mut total = 0.0f64;
    let mut hands = Vec::new();
    for i in 0..repeat {
        let started = Instant::now();
        hands = backend
            .detect(&frame, i as u64 * 66)
            .map_err(|e| format!("detect failed: {e}"))?;
        let elapsed = started.elapsed().as_secs_f64() * 1000.0;
        best = best.min(elapsed);
        total += elapsed;
    }

    println!(
        "inference: mean {:.1} ms, best {:.1} ms over {repeat} run(s)",
        total / repeat as f64,
        best
    );

    match hands.first() {
        Some(hand) => {
            println!(
                "hand: score={:.3} handedness={:?} bbox=({},{} {}x{})",
                hand.score, hand.handedness, hand.bbox.x, hand.bbox.y, hand.bbox.w, hand.bbox.h
            );
            println!("landmarks (normalised x, y, z):");
            let names = [
                "wrist",
                "thumb_cmc",
                "thumb_mcp",
                "thumb_ip",
                "thumb_tip",
                "index_mcp",
                "index_pip",
                "index_dip",
                "index_tip",
                "middle_mcp",
                "middle_pip",
                "middle_dip",
                "middle_tip",
                "ring_mcp",
                "ring_pip",
                "ring_dip",
                "ring_tip",
                "pinky_mcp",
                "pinky_pip",
                "pinky_dip",
                "pinky_tip",
            ];
            for (i, p) in hand.points.iter().enumerate() {
                println!(
                    "  {:2} {:>11}  {:.4}  {:.4}  {:+.4}",
                    i, names[i], p.x, p.y, p.z
                );
            }

            // A coarse sanity check: a real hand has a non-trivial extent.
            let spread =
                hand.points
                    .iter()
                    .fold((f32::MAX, f32::MAX, f32::MIN, f32::MIN), |acc, p| {
                        (
                            acc.0.min(p.x),
                            acc.1.min(p.y),
                            acc.2.max(p.x),
                            acc.3.max(p.y),
                        )
                    });
            let (w, h) = (spread.2 - spread.0, spread.3 - spread.1);
            if w < 0.02 || h < 0.02 {
                println!(
                    "WARNING: landmarks are nearly coincident (extent {:.3}x{:.3}); \
                     check input normalisation (RGB/BGR, 0..1 vs 0..255) and output layout",
                    w, h
                );
            }
        }
        None => println!("no hand decoded — check the export's output layout"),
    }

    Ok(())
}

fn load_frame(path: &std::path::Path) -> Result<PreviewFrame, String> {
    let image = image::open(path)
        .map_err(|e| format!("failed to open {}: {e}", path.display()))?
        .to_rgb8();

    // Match the camera ring's vision resolution (never upscale).
    let (w, h) = image.dimensions();
    let max_w = 640u32;
    let (tw, th) = if w <= max_w {
        (w, h)
    } else {
        let ratio = max_w as f32 / w as f32;
        (max_w, (h as f32 * ratio).max(1.0) as u32)
    };
    let resized = if (tw, th) == (w, h) {
        image
    } else {
        image::imageops::resize(&image, tw, th, image::imageops::FilterType::Triangle)
    };

    Ok(PreviewFrame {
        width: tw,
        height: th,
        rgb: resized.into_raw(),
    })
}
