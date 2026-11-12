//! `SnippetController`: the single-flight state machine for snippet OCR
//! (Proposal 100).
//!
//! Flow: hotkey → `arm()` (freeze screen, show the selection overlay with a
//! scaled preview) → webview reports a region or cancels → `commit()` crops
//! the full-res frame in memory, recognizes on a worker thread, cleans,
//! copies to the clipboard, optionally auto-pastes, and records history.
//!
//! The frozen frame is held only while Selecting and dropped immediately on
//! commit/cancel (privacy bright line: nothing touches disk).
//!
//! The controller is always compiled (type-stable for `AppState`, like the
//! agent controller); the capture/engine bodies live behind the `snippet` /
//! `snippet-onnx` features, and without them `available()` is false.

use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::{Arc, Mutex};

use serde::Serialize;
use tauri::{AppHandle, Emitter, Manager};

use crate::error::{AppError, AppResult};
use crate::snippet::RegionRect;
use crate::state::AppState;

/// UI/result event emitted on `snippet://state` (mirrors `dictate://state`).
#[derive(Debug, Clone, Serialize)]
#[serde(tag = "state", rename_all = "snake_case")]
pub enum SnippetEvent {
    /// The overlay should render the frozen-frame preview and begin
    /// selection. `image` is a PNG data-URI of the scaled preview; `frame_*`
    /// are the full-res virtual-screen geometry the region maps back to.
    Frame {
        image: String,
        frame_w: u32,
        frame_h: u32,
        origin_x: i32,
        origin_y: i32,
        preview_w: u32,
        preview_h: u32,
    },
    Done {
        text: String,
        engine: String,
        ocr_ms: u64,
        pasted: bool,
    },
    Error {
        message: String,
    },
    Cancelled,
    Idle,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum SnippetPhase {
    Idle,
    Selecting,
    Recognizing,
}

/// Live counters surfaced on the Diagnostics page (mirror `VisionStats`).
#[derive(Default)]
pub struct SnippetStats {
    pub captures: AtomicU64,
    pub recognitions: AtomicU64,
    pub errors: AtomicU64,
    pub last_ocr_ms: AtomicU64,
    pub last_error: Mutex<Option<String>>,
    pub last_region: Mutex<Option<RegionRect>>,
}

/// Resident snippet controller. Single-flight: a second arm while selecting
/// is ignored (matches every snippet tool — the overlay owns the pointer).
pub struct SnippetController {
    pub stats: Arc<SnippetStats>,
    phase: Mutex<SnippetPhase>,
    #[cfg(feature = "snippet")]
    frame: Mutex<Option<crate::snippet::capture::FrozenFrame>>,
}

impl Default for SnippetController {
    fn default() -> Self {
        Self::new()
    }
}

impl SnippetController {
    pub fn new() -> Self {
        Self {
            stats: Arc::new(SnippetStats::default()),
            phase: Mutex::new(SnippetPhase::Idle),
            #[cfg(feature = "snippet")]
            frame: Mutex::new(None),
        }
    }

    /// Whether the snippet surface is compiled into this build.
    pub fn available() -> bool {
        crate::snippet::available()
    }

    pub fn phase(&self) -> SnippetPhase {
        *self.phase.lock().unwrap()
    }

    pub fn captures(&self) -> u64 {
        self.stats.captures.load(Ordering::Relaxed)
    }

    pub fn recognitions(&self) -> u64 {
        self.stats.recognitions.load(Ordering::Relaxed)
    }

    pub fn last_ocr_ms(&self) -> u64 {
        self.stats.last_ocr_ms.load(Ordering::Relaxed)
    }

    pub fn error_count(&self) -> u64 {
        self.stats.errors.load(Ordering::Relaxed)
    }

    pub fn last_error(&self) -> Option<String> {
        self.stats.last_error.lock().unwrap().clone()
    }

    pub fn last_region(&self) -> Option<RegionRect> {
        *self.stats.last_region.lock().unwrap()
    }

    /// Register the snippet hotkey (global-shortcut plugin — a one-shot
    /// chord, deliberately NOT the PTT low-level hook). No-op when disabled
    /// or not compiled.
    pub fn start(app: &AppHandle) -> AppResult<()> {
        if !Self::available() {
            return Ok(());
        }
        let state = app.state::<AppState>();
        let settings = state.settings_snapshot();
        if !settings.snippet.enabled || settings.snippet.hotkey.trim().is_empty() {
            return Ok(());
        }
        let combo = settings.snippet.hotkey.trim().to_string();
        if combo == settings.hotkey.ptt_combo || combo == settings.hotkey.ptt_key {
            tracing::warn!(
                combo,
                "snippet hotkey collides with the PTT hotkey; not registered"
            );
            return Ok(());
        }
        use tauri_plugin_global_shortcut::{GlobalShortcutExt, ShortcutState};
        let _ = app
            .global_shortcut()
            .on_shortcut(combo.as_str(), move |app, _s, event| {
                if event.state() != ShortcutState::Pressed {
                    return;
                }
                let state = app.state::<AppState>();
                let controller = state.snippet.clone();
                if let Err(e) = controller.arm(app) {
                    tracing::warn!("snippet arm failed: {e}");
                    let _ = app.emit(
                        "snippet://state",
                        SnippetEvent::Error {
                            message: e.to_string(),
                        },
                    );
                }
            });
        tracing::info!(combo, "snippet hotkey registered");
        Ok(())
    }

    /// Freeze the screen and show the selection overlay.
    pub fn arm(&self, app: &AppHandle) -> AppResult<()> {
        if !Self::available() {
            return Err(AppError::Unsupported(
                "screen OCR is not compiled into this build".into(),
            ));
        }
        {
            let mut phase = self.phase.lock().unwrap();
            if *phase != SnippetPhase::Idle {
                tracing::debug!("snippet arm ignored: already active");
                return Ok(());
            }
            *phase = SnippetPhase::Selecting;
        }

        #[cfg(feature = "snippet")]
        {
            let result: AppResult<()> = (|| {
                let frame = crate::snippet::capture::freeze()?;
                let (preview_rgba, pw, ph) = frame.preview_rgba(1920);
                let image = encode_png_data_uri(&preview_rgba, pw, ph)?;
                let origin = frame.origin;
                let (fw, fh) = (frame.width, frame.height);
                *self.frame.lock().unwrap() = Some(frame);
                self.stats.captures.fetch_add(1, Ordering::Relaxed);
                let _ = app.emit(
                    "snippet://state",
                    SnippetEvent::Frame {
                        image,
                        frame_w: fw,
                        frame_h: fh,
                        origin_x: origin.0,
                        origin_y: origin.1,
                        preview_w: pw,
                        preview_h: ph,
                    },
                );
                show_snippet_window(app, true, Some((origin.0, origin.1, fw, fh)))
            })();
            if result.is_err() {
                *self.phase.lock().unwrap() = SnippetPhase::Idle;
                *self.frame.lock().unwrap() = None;
            }
            result
        }
        #[cfg(not(feature = "snippet"))]
        {
            let _ = app;
            Err(AppError::Unsupported(
                "screen OCR is not compiled into this build".into(),
            ))
        }
    }

    /// The webview reported a selection: crop + recognize + deliver.
    pub fn commit(&self, app: &AppHandle, rect: RegionRect) -> AppResult<()> {
        #[cfg(feature = "snippet")]
        {
            let frame = self.frame.lock().unwrap().take();
            let Some(frame) = frame else {
                *self.phase.lock().unwrap() = SnippetPhase::Idle;
                return Err(AppError::Other("no frozen frame — arm first".into()));
            };
            let Some(clamped) = rect.clamped(frame.bounds(), 4) else {
                *self.phase.lock().unwrap() = SnippetPhase::Idle;
                let _ = app.emit(
                    "snippet://state",
                    SnippetEvent::Error {
                        message: "selection too small — drag a larger region".into(),
                    },
                );
                let _ = show_snippet_window(app, false, None);
                return Ok(());
            };
            *self.stats.last_region.lock().unwrap() = Some(clamped);
            let Some(region) = frame.crop(&clamped) else {
                *self.phase.lock().unwrap() = SnippetPhase::Idle;
                return Err(AppError::Other("crop failed".into()));
            };
            // The frame is dropped here — pixels exist only as the crop now.
            *self.phase.lock().unwrap() = SnippetPhase::Recognizing;

            let state = app.state::<AppState>();
            let settings = state.settings_snapshot();
            let models_dir = state.models_dir();
            let controller = state.snippet.clone();
            let stats = self.stats.clone();
            let app_for_worker = app.clone();
            let spawn = std::thread::Builder::new()
                .name("nvoos-snippet".into())
                .spawn(move || {
                    let outcome = recognize_and_deliver(
                        &app_for_worker,
                        &controller,
                        &settings,
                        &models_dir,
                        &region,
                    );
                    match outcome {
                        Ok((text, engine, pasted, ocr_ms)) => {
                            stats.last_ocr_ms.store(ocr_ms, Ordering::Relaxed);
                            stats.recognitions.fetch_add(1, Ordering::Relaxed);
                            let _ = app_for_worker.emit(
                                "snippet://state",
                                SnippetEvent::Done {
                                    text,
                                    engine,
                                    ocr_ms,
                                    pasted,
                                },
                            );
                            // Let the user read the result capsule, then
                            // dismiss the overlay.
                            let app = app_for_worker.clone();
                            std::thread::spawn(move || {
                                std::thread::sleep(std::time::Duration::from_millis(1400));
                                let _ = show_snippet_window(&app, false, None);
                                let _ = app.emit("snippet://state", SnippetEvent::Idle);
                            });
                        }
                        Err(e) => {
                            stats.last_ocr_ms.store(0, Ordering::Relaxed);
                            stats.errors.fetch_add(1, Ordering::Relaxed);
                            *stats.last_error.lock().unwrap() = Some(e.to_string());
                            let _ = app_for_worker.emit(
                                "snippet://state",
                                SnippetEvent::Error {
                                    message: e.to_string(),
                                },
                            );
                            let app = app_for_worker.clone();
                            std::thread::spawn(move || {
                                std::thread::sleep(std::time::Duration::from_millis(2600));
                                let _ = show_snippet_window(&app, false, None);
                                let _ = app.emit("snippet://state", SnippetEvent::Idle);
                            });
                        }
                    }
                    *controller.phase.lock().unwrap() = SnippetPhase::Idle;
                });
            if spawn.is_err() {
                *self.phase.lock().unwrap() = SnippetPhase::Idle;
                return Err(AppError::Other("failed to spawn OCR worker".into()));
            }
            Ok(())
        }
        #[cfg(not(feature = "snippet"))]
        {
            let _ = (app, rect);
            Err(AppError::Unsupported(
                "screen OCR is not compiled into this build".into(),
            ))
        }
    }

    /// Cancel the active selection: drop the frame, hide the overlay.
    pub fn cancel(&self, app: &AppHandle) {
        #[cfg(feature = "snippet")]
        {
            *self.frame.lock().unwrap() = None;
        }
        *self.phase.lock().unwrap() = SnippetPhase::Idle;
        let _ = show_snippet_window(app, false, None);
        let _ = app.emit("snippet://state", SnippetEvent::Cancelled);
        let app = app.clone();
        std::thread::spawn(move || {
            std::thread::sleep(std::time::Duration::from_millis(400));
            let _ = app.emit("snippet://state", SnippetEvent::Idle);
        });
    }
}

/// Show/hide the full-screen selection window (created in `lib.rs` setup).
/// On show, the window is sized/positioned to cover the frozen frame's
/// virtual-screen geometry **before** it becomes visible, so the overlay
/// aligns pixel-perfectly with the frozen image underneath.
fn show_snippet_window(
    app: &AppHandle,
    visible: bool,
    geometry: Option<(i32, i32, u32, u32)>,
) -> AppResult<()> {
    let Some(window) = app.get_webview_window("snippet") else {
        return Err(AppError::Other("snippet window not created".into()));
    };
    if visible {
        if let Some((x, y, w, h)) = geometry {
            let _ = window.set_position(tauri::Position::Physical(tauri::PhysicalPosition::new(
                x, y,
            )));
            let _ = window.set_size(tauri::Size::Physical(tauri::PhysicalSize::new(w, h)));
        }
        window
            .show()
            .and_then(|_| window.set_focus())
            .map_err(|e| AppError::Other(format!("snippet window failed: {e}")))
    } else {
        window
            .hide()
            .map_err(|e| AppError::Other(format!("snippet window failed: {e}")))
    }
}

/// PNG-encode RGBA bytes into a data-URI (preview transport; the full-res
/// frame never leaves Rust memory).
#[cfg(feature = "snippet")]
fn encode_png_data_uri(rgba: &[u8], w: u32, h: u32) -> AppResult<String> {
    use std::io::Cursor;
    let img = image::RgbaImage::from_raw(w, h, rgba.to_vec())
        .ok_or_else(|| AppError::Other("preview encode: bad dims".into()))?;
    let mut out = Vec::new();
    img.write_to(&mut Cursor::new(&mut out), image::ImageFormat::Png)
        .map_err(|e| AppError::Other(format!("preview encode failed: {e}")))?;
    use base64::Engine as _;
    Ok(format!(
        "data:image/png;base64,{}",
        base64::engine::general_purpose::STANDARD.encode(out)
    ))
}

/// Run the configured engine over a region, clean, copy, paste, and record
/// history. Returns `(final_text, engine_id, pasted, ocr_ms)`.
#[cfg(feature = "snippet")]
fn recognize_and_deliver(
    app: &AppHandle,
    controller: &SnippetController,
    settings: &crate::config::Settings,
    models_dir: &std::path::Path,
    region: &crate::snippet::capture::RegionImage,
) -> AppResult<(String, String, bool, u64)> {
    use std::time::Instant;
    let ocr_started = Instant::now();
    let snippet = recognize_region(settings, models_dir, region)?;
    let ocr_ms = ocr_started.elapsed().as_millis() as u64;
    let state = app.state::<AppState>();

    // Stage-1 OCR cleanup (dedicated profile — speech rules would corrupt
    // OCR text).
    let raw = snippet.text.clone();
    let clean = match settings.snippet.cleanup_profile.as_str() {
        "off" => raw.clone(),
        "full_stage1" => {
            crate::cleanup::run_deterministic(&raw, crate::config::AppCategory::Default, &[], true)
        }
        _ => crate::cleanup::run_ocr_deterministic(&raw),
    };

    if clean.trim().is_empty() {
        return Err(AppError::Other(
            "no text found in the selected region — try a tighter selection".into(),
        ));
    }

    // Clipboard always; paste only when enabled.
    arboard::Clipboard::new()
        .map_err(|e| AppError::Paste(format!("clipboard unavailable: {e}")))?
        .set_text(clean.clone())
        .map_err(|e| AppError::Paste(e.to_string()))?;
    let pasted = if settings.snippet.auto_paste {
        match state.paste_backend().paste(&clean) {
            Ok(outcome) => {
                state.record_paste(outcome);
                true
            }
            Err(_) => false,
        }
    } else {
        false
    };

    if settings.snippet.retain_history {
        let _ = state.db.insert_snippet(
            &raw,
            &clean,
            &snippet.engine,
            &settings.snippet.language,
            region.width,
            region.height,
            ocr_ms,
        );
    }

    let engine = snippet.engine.clone();
    let _ = controller;
    Ok((clean, engine, pasted, ocr_ms))
}

/// Engine dispatch (platform-native first, ONNX fallback).
#[cfg(feature = "snippet")]
fn recognize_region(
    settings: &crate::config::Settings,
    models_dir: &std::path::Path,
    region: &crate::snippet::capture::RegionImage,
) -> AppResult<crate::snippet::SnippetText> {
    let engine = settings.snippet.engine.as_str();
    let want_auto = engine.is_empty() || engine == "auto";
    let mut last_err: Option<String> = None;
    #[cfg(not(feature = "snippet-onnx"))]
    let _ = models_dir;

    #[cfg(windows)]
    if engine == "winrt" || want_auto {
        match crate::snippet::ocr_winrt::create_engine(&settings.snippet.language).and_then(|e| {
            crate::snippet::ocr_winrt::recognize_bgra(&e, &region.bgra, region.width, region.height)
        }) {
            Ok(r) => return Ok(r),
            Err(e) => {
                tracing::warn!("winrt OCR failed ({e}); trying fallback");
                last_err = Some(e.to_string());
            }
        }
    }

    #[cfg(all(target_os = "macos", feature = "snippet"))]
    if engine == "sidecar" || want_auto {
        match crate::snippet::macos_sidecar::recognize_bgra(
            &region.bgra,
            region.width,
            region.height,
            &settings.snippet.language,
        ) {
            Ok(r) => return Ok(r),
            Err(e) => {
                tracing::warn!("macOS Vision OCR failed ({e}); trying fallback");
                last_err = Some(e.to_string());
            }
        }
    }

    #[cfg(feature = "snippet-onnx")]
    if engine == "onnx" || want_auto {
        match recognize_onnx(settings, models_dir, region) {
            Ok(r) => return Ok(r),
            Err(e) => {
                tracing::warn!("ONNX OCR failed ({e})");
                last_err = Some(e.to_string());
            }
        }
    }

    Err(AppError::NotConfigured(format!(
        "no OCR engine available{}",
        last_err.map(|e| format!(" — {e}")).unwrap_or_default()
    )))
}

/// ONNX (RapidOCR) recognition. Sessions are loaded per shot on the worker
/// thread (a resident session cache needs a Send+Sync guarantee from `ort`
/// sessions that is deliberately not assumed; load cost is ~100–300 ms and
/// the native engines are the latency-sensitive default path).
#[cfg(feature = "snippet-onnx")]
fn recognize_onnx(
    settings: &crate::config::Settings,
    models_dir: &std::path::Path,
    region: &crate::snippet::capture::RegionImage,
) -> AppResult<crate::snippet::SnippetText> {
    use crate::snippet::ocr_onnx::{OcrDetector, OcrRecognizer};

    let pack_id = if settings.snippet.language.trim().is_empty() {
        "en"
    } else {
        settings.snippet.language.trim()
    };
    let pack = crate::snippet::models::find(pack_id)
        .ok_or_else(|| AppError::Model(format!("unknown OCR language pack '{pack_id}'")))?;
    if !crate::snippet::models::is_installed(pack, models_dir) {
        return Err(AppError::Model(format!(
            "OCR language pack '{pack_id}' is not downloaded — get it from Settings"
        )));
    }
    let dir = crate::snippet::models::pack_dir(models_dir);
    let det_path = dir.join(crate::snippet::models::DET_FILE.name);
    let rec_path = dir.join(pack.rec.name);
    let dict_path = dir.join(pack.dict.name);

    let detector = OcrDetector::load(&det_path)?;
    let recognizer = OcrRecognizer::load(&rec_path, &dict_path)?;
    let rgb = region.to_rgb();
    crate::snippet::ocr_onnx::recognize_rgb(
        &detector,
        &recognizer,
        &rgb,
        region.width,
        region.height,
    )
}
