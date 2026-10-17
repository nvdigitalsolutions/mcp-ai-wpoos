//! Mic probe: open the default input device (or the device whose name
//! contains an argument substring) for 3 seconds and report whether the
//! capture stream delivers buffers. Distinguishes an OS/device problem
//! (disabled endpoint, exclusive use, driver) from an app-side
//! capture-path bug.
//!
//! Usage: cargo run --example mic_probe ["name substring"]

use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::Arc;

use cpal::traits::{DeviceTrait, HostTrait, StreamTrait};

fn main() {
    let host = cpal::default_host();
    let want: Option<String> = std::env::args().nth(1).map(|s| s.to_lowercase());
    let device = match &want {
        Some(needle) => host
            .input_devices()
            .ok()
            .and_then(|devices| {
                devices
                    .filter_map(|d| match d.name() {
                        Ok(name) if name.to_lowercase().contains(needle) => Some(d),
                        _ => None,
                    })
                    .next()
            })
            .or_else(|| host.default_input_device()),
        None => host.default_input_device(),
    };
    let device = match device {
        Some(d) => d,
        None => {
            eprintln!("no matching/default input device");
            std::process::exit(1);
        }
    };
    println!(
        "device: {}",
        device.name().unwrap_or_else(|_| "?".to_string())
    );
    let config = match device.default_input_config() {
        Ok(c) => c,
        Err(e) => {
            eprintln!("default_input_config failed: {e}");
            std::process::exit(1);
        }
    };
    println!(
        "config: {} Hz, {} ch, {:?}",
        config.sample_rate().0,
        config.channels(),
        config.sample_format()
    );

    let calls = Arc::new(AtomicU64::new(0));
    let samples = Arc::new(AtomicU64::new(0));
    let errors = Arc::new(AtomicU64::new(0));
    let c1 = calls.clone();
    let s1 = samples.clone();
    let e1 = errors.clone();
    let stream = match device.build_input_stream(
        &config.into(),
        move |data: &[f32], _info| {
            c1.fetch_add(1, Ordering::Relaxed);
            s1.fetch_add(data.len() as u64, Ordering::Relaxed);
        },
        move |e| {
            e1.fetch_add(1, Ordering::Relaxed);
            eprintln!("stream error: {e}");
        },
        None,
    ) {
        Ok(s) => s,
        Err(e) => {
            eprintln!("build_input_stream failed: {e}");
            std::process::exit(1);
        }
    };
    if let Err(e) = stream.play() {
        eprintln!("play failed: {e}");
        std::process::exit(1);
    }
    std::thread::sleep(std::time::Duration::from_secs(3));
    drop(stream);

    let calls = calls.load(Ordering::Relaxed);
    let samples = samples.load(Ordering::Relaxed);
    let errors = errors.load(Ordering::Relaxed);
    println!("callbacks: {calls}, samples: {samples}, stream errors: {errors}");
    if calls == 0 {
        println!(
            "VERDICT: the device delivers no buffers — OS/device-level problem \
             (disabled endpoint, in exclusive use by another app, or driver)."
        );
    } else {
        println!("VERDICT: the device delivers audio — the app-side capture path is at fault.");
    }
}
