//! macOS meeting system-audio via the ScreenCaptureKit sidecar helper
//! (P5 port, phase M5).
//!
//! cpal cannot open loopback streams on macOS and virtual audio drivers
//! cannot be bundled, so meetings capture the "other side of the call"
//! through a small Swift helper (`mac-helper/`) built on ScreenCaptureKit
//! (macOS 13+). The helper emits NDJSON frames on stdout
//! (`sidecar_protocol.rs`); this module spawns it, decodes chunks, and
//! pushes mono f32 samples into the recorder's buffer.
//!
//! Fallback discipline (mirrors the Windows loopback contract):
//! `open_sidecar` returns `Ok(None)` when the helper is missing or cannot
//! start — meetings continue microphone-only. Only a permission denial is
//! surfaced loudly, via the Diagnostics/`system_audio_available` path.

use std::io::BufRead;
use std::path::PathBuf;
use std::process::{Child, Command, Stdio};
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{Arc, Mutex};

use crate::error::{AppError, AppResult};
use crate::meetings::sidecar_protocol::{decode_pcm16, parse_line, SidecarFrame};

/// Resolve the helper binary. Order: explicit env override (dev/testing),
/// next to the running executable (bundled `externalBin` layout: the helper
/// lands in `Contents/MacOS`), then the local Swift build directory
/// (`npm run build:helper` during development).
pub fn helper_path() -> Option<PathBuf> {
    if let Ok(p) = std::env::var("NVOOS_AUDIO_HELPER") {
        let path = PathBuf::from(p);
        if path.is_file() {
            return Some(path);
        }
    }
    if let Ok(exe) = std::env::current_exe() {
        let sibling = exe.parent()?.join("nvoos-audio-helper");
        if sibling.is_file() {
            return Some(sibling);
        }
    }
    // Local Swift build (cargo dev / tauri dev layout).
    let dev = std::env::var("CARGO_MANIFEST_DIR")
        .ok()
        .map(PathBuf::from)
        .map(|dir| {
            dir.join("..")
                .join("..")
                .join("mac-helper")
                .join(".build")
                .join("debug")
                .join("nvoos-audio-helper")
        });
    if let Some(path) = dev {
        if path.is_file() {
            return Some(path);
        }
    }
    None
}

/// Whether meeting system-audio capture is possible on this machine
/// (helper present; Screen Recording permission is checked at start time).
pub fn helper_available() -> bool {
    helper_path().is_some()
}

/// A running sidecar: kills the child and joins the reader thread on drop
/// (the recorder drops it when the meeting stops).
pub struct SidecarHandle {
    child: Child,
    stop: Arc<AtomicBool>,
    reader: Option<std::thread::JoinHandle<()>>,
}

impl SidecarHandle {
    /// Spawn the helper feeding `sink`. `Ok(None)` = not available (meetings
    /// continue mic-only); errors are non-fatal by contract.
    pub fn start(sink: Arc<Mutex<Vec<f32>>>) -> AppResult<Option<SidecarHandle>> {
        let path = match helper_path() {
            Some(p) => p,
            None => return Ok(None),
        };
        let mut child = match Command::new(&path)
            .arg("capture")
            .stdin(Stdio::null())
            .stdout(Stdio::piped())
            .stderr(Stdio::null())
            .spawn()
        {
            Ok(c) => c,
            Err(e) => {
                tracing::warn!("system-audio helper failed to start: {e}");
                return Ok(None);
            }
        };
        let stdout = match child.stdout.take() {
            Some(s) => s,
            None => {
                tracing::warn!("system-audio helper has no stdout");
                let _ = child.kill();
                return Ok(None);
            }
        };

        let stop = Arc::new(AtomicBool::new(false));
        let stop_flag = stop.clone();
        let reader = std::thread::Builder::new()
            .name("nvoos-sidecar".into())
            .spawn(move || {
                let mut lines = std::io::BufReader::new(stdout).lines();
                while !stop_flag.load(Ordering::SeqCst) {
                    match lines.next() {
                        Some(Ok(line)) => match parse_line(&line) {
                            Some(SidecarFrame::Chunk {
                                channels, pcm16, ..
                            }) => {
                                if let Some(samples) = decode_pcm16(&pcm16, channels) {
                                    sink.lock().unwrap().extend_from_slice(&samples);
                                }
                            }
                            Some(SidecarFrame::Error { message }) => {
                                tracing::warn!("system-audio helper: {message}");
                            }
                            Some(_) => {}
                            None => tracing::debug!("sidecar: undecodable line"),
                        },
                        Some(Err(e)) => {
                            tracing::warn!("sidecar stdout read error: {e}");
                            break;
                        }
                        None => break, // EOF: helper exited
                    }
                }
                let _ = lines.into_inner();
            })
            .ok();

        Ok(Some(SidecarHandle {
            child,
            stop,
            reader,
        }))
    }

    /// Best-effort wait for a `ready` handshake so the caller can decide
    /// whether capture is actually live (permission denials surface as an
    /// immediate `error` frame and child exit instead).
    pub fn is_alive(&self) -> bool {
        match self.child.try_wait() {
            Ok(None) => true,
            _ => false,
        }
    }
}

impl Drop for SidecarHandle {
    fn drop(&mut self) {
        self.stop.store(true, Ordering::SeqCst);
        let _ = self.child.kill();
        let _ = self.child.wait();
        if let Some(reader) = self.reader.take() {
            let _ = reader.join();
        }
    }
}

/// Open the macOS system-audio sidecar feeding `sink` (recorder wiring).
pub fn open_sidecar(sink: Arc<Mutex<Vec<f32>>>) -> AppResult<Option<SidecarHandle>> {
    SidecarHandle::start(sink).map_err(|e| AppError::Audio(format!("system audio: {e}")))
}
