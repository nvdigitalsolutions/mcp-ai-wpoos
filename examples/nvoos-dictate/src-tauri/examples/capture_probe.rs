//! Phase 0 measurement harness for Proposal 144 §4.3 — capture residency.
//!
//! Measures, on this machine, in one run:
//!
//! 1. **Resident idle cost** — callbacks delivered and process CPU time over a
//!    window while the stream runs continuously.
//! 2. **Paused idle cost** — the same window with the stream paused (the
//!    on-demand steady state). Callbacks must stop; CPU should collapse.
//! 3. **Resume latency** — pause -> first-callback, p50/p95 over N runs.
//!
//! What this does **not** do: measure wall-plug power. Battery/energy numbers
//! need an external meter (or `powercfg /energy`) and a much longer window.
//! This harness makes the CPU half reproducible so the README can quote a
//! measurement instead of an assumption.
//!
//! Usage: `cargo run --example capture_probe -- [window_secs] [resumes]`

use std::time::{Duration, Instant};

use nvoos_dictate_lib::audio::capture::MicCapture;

fn main() {
    let mut args = std::env::args().skip(1);
    let window_secs: u64 = args.next().and_then(|a| a.parse().ok()).unwrap_or(20);
    let resumes: usize = args.next().and_then(|a| a.parse().ok()).unwrap_or(10);
    let window = Duration::from_secs(window_secs.max(5));

    println!("NV oOS Cognition — capture residency probe");
    println!(
        "window: {} s per phase · {} resume measurements\n",
        window.as_secs(),
        resumes
    );

    let mic = match MicCapture::start(5, None, 2000) {
        Ok(mic) => mic,
        Err(e) => {
            eprintln!("could not open the default microphone: {e}");
            std::process::exit(1);
        }
    };
    println!(
        "device: {} @ {} Hz, {} channel(s)\n",
        mic.device_name(),
        mic.sample_rate(),
        mic.channels()
    );

    // Device setup is attributed to neither phase.
    if !wait_for_callbacks(&mic, Duration::from_secs(5)) {
        eprintln!("no audio callbacks within 5 s — the device is not delivering");
        std::process::exit(2);
    }

    // ---- Phase 1: resident -------------------------------------------------
    let cb_start = mic.callback_count();
    let cpu_start = process_cpu_time();
    std::thread::sleep(window);
    let cb_resident = mic.callback_count() - cb_start;
    let cpu_resident = process_cpu_time()
        .zip(cpu_start)
        .map(|(end, start)| end.saturating_sub(start));

    // ---- Phase 2: paused ---------------------------------------------------
    if let Err(e) = mic.pause() {
        eprintln!("pause failed: {e}");
        std::process::exit(3);
    }
    // Let the worker apply the pause before the measurement window opens.
    std::thread::sleep(Duration::from_millis(400));
    if !mic.is_paused() {
        eprintln!("the worker did not report paused — measurement would be invalid");
        std::process::exit(4);
    }

    let cb_paused_start = mic.callback_count();
    let cpu_paused_start = process_cpu_time();
    std::thread::sleep(window);
    let cb_paused = mic.callback_count() - cb_paused_start;
    let cpu_paused = process_cpu_time()
        .zip(cpu_paused_start)
        .map(|(end, start)| end.saturating_sub(start));

    // ---- Phase 3: resume latency ------------------------------------------
    let mut latencies = Vec::with_capacity(resumes);
    for _ in 0..resumes {
        match mic.resume() {
            Ok(ms) => latencies.push(ms),
            Err(e) => {
                eprintln!("resume failed: {e}");
                std::process::exit(5);
            }
        }
        // Speak-length gap, then back to idle.
        std::thread::sleep(Duration::from_millis(500));
        if let Err(e) = mic.pause() {
            eprintln!("pause failed: {e}");
            std::process::exit(6);
        }
        std::thread::sleep(Duration::from_millis(300));
    }
    latencies.sort_unstable();
    let p50 = percentile(&latencies, 50);
    let p95 = percentile(&latencies, 95);

    // ---- Report ------------------------------------------------------------
    println!("{:<34}{:>16}{:>16}", "metric", "resident", "paused");
    println!("{:-<66}", "");
    println!(
        "{:<34}{:>16}{:>16}",
        "callbacks in window", cb_resident, cb_paused
    );
    let rate = cb_resident as f64 / window.as_secs_f64();
    println!("{:<34}{:>16.1}{:>16.1}", "callbacks per second", rate, 0.0);
    match (cpu_resident, cpu_paused) {
        (Some(r), Some(p)) => {
            println!(
                "{:<34}{:>13.1} ms{:>13.1} ms",
                "process CPU time",
                r.as_secs_f64() * 1000.0,
                p.as_secs_f64() * 1000.0
            );
            if r.as_secs_f64() > 0.0 {
                println!(
                    "{:<34}{:>16.2}{:>16.2}",
                    "CPU duty cycle (%)",
                    (r.as_secs_f64() / window.as_secs_f64()) * 100.0,
                    (p.as_secs_f64() / window.as_secs_f64()) * 100.0
                );
            }
        }
        _ => println!("{:<34}{:>16}{:>16}", "process CPU time", "n/a", "n/a"),
    }
    println!();
    println!("resume latency: p50 {p50} ms · p95 {p95} ms (n = {resumes})");
    println!();

    // A paused stream that still delivers callbacks invalidates the model, so
    // make that failure explicit instead of leaving it in the table.
    if cb_paused == 0 {
        println!("PASS: the stream delivered zero callbacks while paused.");
    } else {
        println!(
            "WARN: {cb_paused} callback(s) arrived while paused — pause is not fully stopping capture."
        );
    }
    if let Some(r) = cpu_resident {
        if let Some(p) = cpu_paused {
            println!(
                "Idle CPU reduced by {:.1}% when paused.",
                (1.0 - p.as_secs_f64() / r.as_secs_f64().max(f64::EPSILON)) * 100.0
            );
        }
    }
    println!();
    println!("Not measured here: wall-plug power. Use `powercfg /energy` or an");
    println!("external meter over a longer window for battery figures.");
}

fn wait_for_callbacks(mic: &MicCapture, timeout: Duration) -> bool {
    let deadline = Instant::now() + timeout;
    let start = mic.callback_count();
    while Instant::now() < deadline {
        if mic.callback_count() > start {
            return true;
        }
        std::thread::sleep(Duration::from_millis(20));
    }
    false
}

fn percentile(sorted: &[u64], pct: usize) -> u64 {
    if sorted.is_empty() {
        return 0;
    }
    let idx = (sorted.len() * pct / 100).min(sorted.len() - 1);
    sorted[idx]
}

/// Total process CPU time (user + kernel). `None` where the platform has no
/// cheap equivalent, which the report renders as `n/a`.
#[cfg(windows)]
fn process_cpu_time() -> Option<Duration> {
    use windows::Win32::Foundation::FILETIME;
    use windows::Win32::System::Threading::{GetCurrentProcess, GetProcessTimes};

    unsafe {
        let mut creation = FILETIME::default();
        let mut exit = FILETIME::default();
        let mut kernel = FILETIME::default();
        let mut user = FILETIME::default();
        GetProcessTimes(
            GetCurrentProcess(),
            &mut creation,
            &mut exit,
            &mut kernel,
            &mut user,
        )
        .ok()?;

        let ticks = |ft: FILETIME| ((ft.dwHighDateTime as u64) << 32) | ft.dwLowDateTime as u64;
        // FILETIME is 100-ns units.
        Some(Duration::from_nanos((ticks(kernel) + ticks(user)) * 100))
    }
}

#[cfg(not(windows))]
fn process_cpu_time() -> Option<Duration> {
    None
}
