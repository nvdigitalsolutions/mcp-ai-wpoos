//! `DocumentController`: the single-flight worker state for the document
//! pipeline (Proposal 109).
//!
//! Flow (per document): bind pdfium → open → per page, either extract the
//! text layer (hybrid `Text` class) or rasterize at the configured DPI,
//! preprocess with the scan profile, recognize with the existing OCR
//! engines, clean, and optionally polish with a local VLM. Multi-page
//! TIFFs take the OCR path for every page. Progress events stream to the
//! webview; a shared cancel flag aborts between pages.
//!
//! The heavy work runs on a `spawn_blocking` thread (the IPC command owns
//! the join); the controller itself only carries state — mirrors the
//! agent/snippet controllers (always compiled, feature-gated bodies).

use std::path::Path;
use std::sync::atomic::{AtomicBool, AtomicU64, Ordering};
use std::sync::{Arc, Mutex};

use serde::Serialize;
use tauri::AppHandle;
#[cfg(feature = "document")]
use tauri::Emitter;

use crate::config::Settings;
#[cfg(feature = "document")]
use crate::document::hybrid::{self, PageClass};
use crate::document::{DocumentPage, DocumentResult};
#[cfg(feature = "document")]
use crate::document::PageProvenance;
use crate::error::{AppError, AppResult};
use crate::state::SecretStore;

/// Per-page progress event, emitted on `document://progress`.
#[derive(Debug, Clone, Serialize)]
pub struct DocumentProgress {
    /// 1-based page index.
    pub page: usize,
    pub page_count: usize,
    /// `extract` (text-layer) | `ocr` (raster + recognize) | `polish`.
    pub phase: &'static str,
    /// 0..1 overall completion estimate.
    pub fraction: f32,
}

/// Live counters surfaced on the Diagnostics page.
#[derive(Default)]
pub struct DocumentStats {
    pub documents: AtomicU64,
    pub pages: AtomicU64,
    pub text_pages: AtomicU64,
    pub ocr_pages: AtomicU64,
    pub last_ms: AtomicU64,
    pub errors: AtomicU64,
    pub last_error: Mutex<Option<String>>,
}

/// Resident document-pipeline state. Single-flight: a second document while
/// one is processing is rejected (mirrors the snippet controller).
pub struct DocumentController {
    pub stats: Arc<DocumentStats>,
    busy: AtomicBool,
    cancel: AtomicBool,
    last_result: Mutex<Option<DocumentResult>>,
}

impl Default for DocumentController {
    fn default() -> Self {
        Self::new()
    }
}

impl DocumentController {
    pub fn new() -> Self {
        Self {
            stats: Arc::new(DocumentStats::default()),
            busy: AtomicBool::new(false),
            cancel: AtomicBool::new(false),
            last_result: Mutex::new(None),
        }
    }

    /// Whether the document pipeline is compiled into this build.
    pub fn available() -> bool {
        crate::document::available()
    }

    pub fn is_busy(&self) -> bool {
        self.busy.load(Ordering::SeqCst)
    }

    pub fn set_busy(&self, busy: bool) {
        if busy {
            // A fresh run starts un-cancelled.
            self.cancel.store(false, Ordering::SeqCst);
        }
        self.busy.store(busy, Ordering::SeqCst);
    }

    /// Ask the in-flight run to abort at the next page boundary.
    pub fn cancel(&self) {
        self.cancel.store(true, Ordering::SeqCst);
    }

    pub fn cancelled(&self) -> bool {
        self.cancel.load(Ordering::SeqCst)
    }

    pub fn last_result(&self) -> Option<DocumentResult> {
        self.last_result.lock().unwrap().clone()
    }

    pub fn remember(&self, result: DocumentResult) {
        *self.last_result.lock().unwrap() = Some(result);
    }
}

#[cfg(feature = "document")]
fn emit_progress(
    app: &AppHandle,
    page: usize,
    page_count: usize,
    phase: &'static str,
    phase_fraction: f32,
) {
    let fraction = (page as f32 + phase_fraction) / page_count.max(1) as f32;
    let _ = app.emit(
        "document://progress",
        DocumentProgress {
            page: page + 1,
            page_count,
            phase,
            fraction,
        },
    );
}

/// Run the full pipeline (blocking — call from `spawn_blocking`).
///
/// Emits `document://progress` per page and updates the controller stats;
/// cancellation between pages surfaces as a clean `AppError`.
pub fn run_pipeline(
    app: &AppHandle,
    controller: &DocumentController,
    settings: &Settings,
    models_dir: &Path,
    secrets: &dyn SecretStore,
    path: &Path,
) -> AppResult<DocumentResult> {
    let started = std::time::Instant::now();
    controller.stats.documents.fetch_add(1, Ordering::Relaxed);

    let ext = path
        .extension()
        .and_then(|e| e.to_str())
        .map(|e| e.to_lowercase())
        .unwrap_or_default();
    let pages = match ext.as_str() {
        "pdf" => run_pdf_pages(app, controller, settings, models_dir, secrets, path),
        "tif" | "tiff" => run_tiff_pages(app, controller, settings, models_dir, secrets, path),
        other => Err(AppError::Document(format!(
            "unsupported document format '{other}' — use PDF or TIFF"
        ))),
    }?;

    let duration_ms = started.elapsed().as_millis() as i64;
    controller
        .stats
        .pages
        .fetch_add(pages.len() as u64, Ordering::Relaxed);
    controller
        .stats
        .last_ms
        .store(duration_ms as u64, Ordering::Relaxed);
    let result = DocumentResult {
        path: path.to_string_lossy().into_owned(),
        page_count: pages.len(),
        pages,
        duration_ms,
    };
    controller.remember(result.clone());
    Ok(result)
}

/// PDF flow: hybrid two-pass per page.
#[cfg(feature = "document")]
fn run_pdf_pages(
    app: &AppHandle,
    controller: &DocumentController,
    settings: &Settings,
    models_dir: &Path,
    secrets: &dyn SecretStore,
    path: &Path,
) -> AppResult<Vec<DocumentPage>> {
    let pdfium = crate::document::pdf::bind()?;
    let document = crate::document::pdf::open(&pdfium, path)?;
    let page_count = document.pages().len() as usize;
    let max_pages = settings.document.max_pages.max(1);
    let polisher = build_polisher(settings, secrets);

    let mut out: Vec<DocumentPage> = Vec::new();
    for (index, page) in document.pages().iter().enumerate() {
        if controller.cancelled() {
            return Err(AppError::Document("cancelled".into()));
        }
        if index >= max_pages {
            break;
        }
        let page_w = page.width().value;
        let chars = crate::document::pdf::extract_chars(&page)?;
        let class =
            hybrid::classify_with(&chars, settings.document.text_page_min_chars.max(1));

        let (text, provenance) = match class {
            PageClass::Text => {
                emit_progress(app, index, page_count, "extract", 1.0);
                controller.stats.text_pages.fetch_add(1, Ordering::Relaxed);
                (hybrid::chars_to_text(&chars, page_w), PageProvenance::Text)
            }
            PageClass::Ocr => {
                emit_progress(app, index, page_count, "ocr", 0.3);
                let raw = crate::document::pdf::render_page(&page, settings.document.dpi)?;
                let raw_rgb = raw.as_raw().to_vec();
                let (proc, pw, ph) = crate::document::preprocess::scan_profile(
                    raw.as_raw(),
                    raw.width(),
                    raw.height(),
                );
                let snippet =
                    crate::document::ocr::recognize_rgb(settings, models_dir, &proc, pw, ph)?;
                let cleaned = crate::cleanup::run_ocr_deterministic(&snippet.text);
                controller.stats.ocr_pages.fetch_add(1, Ordering::Relaxed);
                match crate::document::polish::maybe_polish(
                    app,
                    &polisher,
                    index,
                    page_count,
                    &raw_rgb,
                    raw.width(),
                    raw.height(),
                    &cleaned,
                ) {
                    Some(polished) => (polished, PageProvenance::Polished),
                    None => (cleaned, PageProvenance::Ocr),
                }
            }
        };
        out.push(DocumentPage {
            index,
            text,
            provenance,
        });
    }
    Ok(out)
}

/// TIFF flow: every page is an image — scan profile → OCR → optional polish.
#[cfg(feature = "document")]
fn run_tiff_pages(
    app: &AppHandle,
    controller: &DocumentController,
    settings: &Settings,
    models_dir: &Path,
    secrets: &dyn SecretStore,
    path: &Path,
) -> AppResult<Vec<DocumentPage>> {
    let frames = crate::document::tiff::pages(path, settings.document.max_pages.max(1))?;
    let page_count = frames.len();
    let polisher = build_polisher(settings, secrets);

    let mut out: Vec<DocumentPage> = Vec::new();
    for (index, frame) in frames.iter().enumerate() {
        if controller.cancelled() {
            return Err(AppError::Document("cancelled".into()));
        }
        emit_progress(app, index, page_count, "ocr", 0.3);
        let (proc, pw, ph) = crate::document::preprocess::scan_profile(
            frame.as_raw(),
            frame.width(),
            frame.height(),
        );
        let snippet =
            crate::document::ocr::recognize_rgb(settings, models_dir, &proc, pw, ph)?;
        let cleaned = crate::cleanup::run_ocr_deterministic(&snippet.text);
        controller.stats.ocr_pages.fetch_add(1, Ordering::Relaxed);
        let (text, provenance) = match crate::document::polish::maybe_polish(
            app,
            &polisher,
            index,
            page_count,
            frame.as_raw(),
            frame.width(),
            frame.height(),
            &cleaned,
        ) {
            Some(polished) => (polished, PageProvenance::Polished),
            None => (cleaned, PageProvenance::Ocr),
        };
        out.push(DocumentPage {
            index,
            text,
            provenance,
        });
    }
    Ok(out)
}

/// Build the polish worker when enabled (settings + a local provider).
/// Any failure is non-blocking: polish is optional by contract.
#[cfg(feature = "document")]
fn build_polisher(
    settings: &Settings,
    secrets: &dyn SecretStore,
) -> Option<crate::document::polish::OcrPolisher> {
    if !settings.document.polish_enabled {
        return None;
    }
    let provider = crate::document::polish::build_local_provider(settings, secrets)?;
    match crate::document::polish::OcrPolisher::new(provider) {
        Ok(p) => Some(p),
        Err(e) => {
            tracing::warn!("OCR polish unavailable: {e}");
            None
        }
    }
}

// Stub variants for builds without the `document` feature: the controller
// is always compiled (type-stable for `AppState`), the pipeline bodies are
// not — calls fail cleanly instead of linking.

#[cfg(not(feature = "document"))]
#[allow(clippy::too_many_arguments)]
fn run_pdf_pages(
    _app: &AppHandle,
    _controller: &DocumentController,
    _settings: &Settings,
    _models_dir: &Path,
    _secrets: &dyn SecretStore,
    _path: &Path,
) -> AppResult<Vec<DocumentPage>> {
    Err(AppError::NotConfigured(
        "document pipeline not compiled into this build".into(),
    ))
}

#[cfg(not(feature = "document"))]
#[allow(clippy::too_many_arguments)]
fn run_tiff_pages(
    _app: &AppHandle,
    _controller: &DocumentController,
    _settings: &Settings,
    _models_dir: &Path,
    _secrets: &dyn SecretStore,
    _path: &Path,
) -> AppResult<Vec<DocumentPage>> {
    Err(AppError::NotConfigured(
        "document pipeline not compiled into this build".into(),
    ))
}
