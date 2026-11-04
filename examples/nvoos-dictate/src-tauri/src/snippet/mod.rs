//! Screen-to-text snippet OCR (Proposal 100).
//!
//! The "OCR push-to-talk": a global hotkey freezes the screen, the user
//! drags a region over a full-screen overlay (Snipping-Tool vocabulary:
//! drag = select, Shift+drag = move, Esc = cancel), and the text in that
//! region is recognized **entirely on-device**, cleaned, placed on the
//! clipboard, and optionally auto-pasted into the focused app.
//!
//! Engine strategy (per-platform native first, zero-download default):
//! - Windows: `Windows.Media.Ocr` (WinRT) — the engine behind PowerToys
//!   Text Extractor; language packs via Windows `Language.OCR*` capabilities.
//! - macOS: Apple Vision (`VNRecognizeTextRequest`) through the existing
//!   `mac-helper` ScreenCaptureKit sidecar (Screen Recording TCC already
//!   granted for meetings).
//! - Portable/multilingual: RapidOCR (PP-OCR det + rec) ONNX on the pinned
//!   `ort` rc.9 — hand-rolled like the vision detector, never `rapid-ocr-rs`
//!   (it pulls its own ort version and would drift the rc.9 pin).
//! - Opt-in VLM fallback for noisy/handwritten regions (M5).
//!
//! Feature wiring mirrors `vision`: the module is always compiled so
//! `AppState` can hold the controller and `Settings.snippet` is type-stable,
//! but the platform engines live behind `#[cfg]` + the `snippet` /
//! `snippet-onnx` cargo features. Without them `available()` is false and
//! the IPC surface reports "not compiled".
//!
//! Privacy bright line: the frozen frame lives **in memory only** — it is
//! dropped on selection commit/cancel and is never written to disk.

pub mod controller;
pub mod models;

/// Shared reading-order reconstruction (Proposal 102 D7, pulled forward by
/// Proposal 109): pure geometry over normalized boxes — always compiled.
pub mod layout;

#[cfg(feature = "snippet")]
pub mod capture;

#[cfg(all(windows, feature = "snippet"))]
pub mod ocr_winrt;

#[cfg(feature = "snippet-onnx")]
pub mod ocr_onnx;

#[cfg(all(target_os = "macos", feature = "snippet"))]
pub mod macos_sidecar;

use serde::{Deserialize, Serialize};

/// A region of the virtual screen, in physical pixels.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
pub struct RegionRect {
    /// Top-left x (virtual-screen coordinate; negative on left-of-primary
    /// monitors).
    pub x: i32,
    /// Top-left y.
    pub y: i32,
    /// Width in pixels.
    pub w: u32,
    /// Height in pixels.
    pub h: u32,
}

impl RegionRect {
    /// Clamp `self` into `bounds` (virtual screen) and reject degenerate
    /// regions below `min_side` pixels. Returns `None` when nothing usable
    /// remains — the caller surfaces "selection too small".
    pub fn clamped(&self, bounds: RegionRect, min_side: u32) -> Option<RegionRect> {
        let min_side = min_side.max(1);
        let x2 = self.x.saturating_add(self.w as i32);
        let y2 = self.y.saturating_add(self.h as i32);
        let bx2 = bounds.x.saturating_add(bounds.w as i32);
        let by2 = bounds.y.saturating_add(bounds.h as i32);
        let x = self.x.max(bounds.x).min(bx2);
        let y = self.y.max(bounds.y).min(by2);
        let ex = x2.max(bounds.x).min(bx2);
        let ey = y2.max(bounds.y).min(by2);
        let w = (ex - x).max(0) as u32;
        let h = (ey - y).max(0) as u32;
        if w < min_side || h < min_side {
            return None;
        }
        Some(RegionRect { x, y, w, h })
    }
}

/// A recognized word with its bounding box, in **region-pixel** coordinates
/// (relative to the captured region the engine was fed).
#[derive(Debug, Clone, PartialEq, Serialize)]
pub struct SnippetWord {
    pub text: String,
    pub x: u32,
    pub y: u32,
    pub w: u32,
    pub h: u32,
}

/// Engine-agnostic OCR output.
#[derive(Debug, Clone, Default, Serialize)]
pub struct SnippetText {
    /// Plain text in reading order; lines joined with '\n'.
    pub text: String,
    /// Word-level results (empty for engines without word geometry).
    pub words: Vec<SnippetWord>,
    /// Engine id that produced the result (`winrt` | `sidecar` | `onnx` | `vlm`).
    pub engine: String,
    /// Mean confidence 0..1 when the engine reports one.
    pub confidence: Option<f32>,
}

/// Whether the snippet surface is compiled into this build.
pub fn available() -> bool {
    cfg!(feature = "snippet")
}

/// Whether the portable ONNX (RapidOCR) backend is compiled in.
pub fn onnx_available() -> bool {
    cfg!(feature = "snippet-onnx")
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn region_clamps_into_bounds() {
        let bounds = RegionRect {
            x: -1920,
            y: 0,
            w: 3840,
            h: 1080,
        };
        // Selection straddling the right edge gets clamped.
        let sel = RegionRect {
            x: 1800,
            y: 100,
            w: 200,
            h: 50,
        };
        assert_eq!(
            sel.clamped(bounds, 4),
            Some(RegionRect {
                x: 1800,
                y: 100,
                w: 120,
                h: 50,
            })
        );
        // Selection entirely off-screen clamps to nothing usable.
        let off = RegionRect {
            x: 5000,
            y: 0,
            w: 100,
            h: 100,
        };
        assert_eq!(off.clamped(bounds, 4), None);
    }

    #[test]
    fn region_rejects_degenerate() {
        let bounds = RegionRect {
            x: 0,
            y: 0,
            w: 1920,
            h: 1080,
        };
        let tiny = RegionRect {
            x: 10,
            y: 10,
            w: 2,
            h: 40,
        };
        assert_eq!(tiny.clamped(bounds, 4), None);
    }

    #[test]
    fn region_keeps_negative_origin() {
        let bounds = RegionRect {
            x: -1920,
            y: -200,
            w: 3840,
            h: 1200,
        };
        let sel = RegionRect {
            x: -1920,
            y: -200,
            w: 640,
            h: 200,
        };
        assert_eq!(sel.clamped(bounds, 4), Some(sel));
    }
}
