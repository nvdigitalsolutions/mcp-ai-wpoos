//! Frozen-screen capture for snippet OCR (Proposal 100, M1).
//!
//! The frame lives **in memory only** (privacy bright line): `FrozenFrame`
//! owns a BGRA buffer of the full virtual screen and is dropped on
//! selection commit/cancel. Nothing is ever written to disk.
//!
//! - Windows: GDI `BitBlt` of the virtual screen into a top-down 32-bpp DIB
//!   section — the proven one-shot path (PowerToys-class utilities use the
//!   same). Multi-monitor-safe via the virtual-screen metrics (negative
//!   origins included). Tauri sets Per-Monitor-V2 DPI awareness on Windows,
//!   so physical pixels = true pixels.
//! - macOS: the bundled ScreenCaptureKit sidecar (`screenshot` subcommand,
//!   macOS 14+; `CGWindowListCreateImage` fallback inside the helper for
//!   macOS 13). The Screen Recording TCC grant already exists for meetings.
//! - Other platforms: `Unsupported` (the Linux port is P4; a Wayland/X11
//!   capture path is explicitly out of scope here).

use crate::error::{AppError, AppResult};
use crate::snippet::RegionRect;

/// An in-memory full-virtual-screen frame.
pub struct FrozenFrame {
    /// BGRA8 pixels, `width * height * 4` bytes, top-down rows.
    pub bgra: Vec<u8>,
    /// Frame width in physical pixels.
    pub width: u32,
    /// Frame height in physical pixels.
    pub height: u32,
    /// Virtual-screen origin (negative on monitors left of/above the
    /// primary) — screen coordinates map to frame pixels by subtracting it.
    pub origin: (i32, i32),
}

impl FrozenFrame {
    /// The virtual-screen bounds this frame covers.
    pub fn bounds(&self) -> RegionRect {
        RegionRect {
            x: self.origin.0,
            y: self.origin.1,
            w: self.width,
            h: self.height,
        }
    }

    /// Crop `rect` (virtual-screen coordinates) out of the frame. Small
    /// regions are nearest-neighbour upscaled (×2, ×3) so tiny text survives
    /// the recognizer — the industry-standard small-text fix. Returns the
    /// region's BGRA pixels and its (possibly upscaled) dimensions.
    pub fn crop(&self, rect: &RegionRect) -> Option<RegionImage> {
        let clamped = rect.clamped(self.bounds(), 4)?;
        let sx = (clamped.x - self.origin.0).max(0) as usize;
        let sy = (clamped.y - self.origin.1).max(0) as usize;
        let w = clamped.w as usize;
        let h = clamped.h as usize;
        let fw = self.width as usize;
        if sx + w > fw || sy + h > self.height as usize {
            return None;
        }
        let scale = if w.min(h) < 64 {
            3
        } else if w.min(h) < 200 {
            2
        } else {
            1
        };
        let ow = w * scale;
        let oh = h * scale;
        let mut out = vec![0u8; ow * oh * 4];
        for y in 0..oh {
            let src_y = sy + y / scale;
            for x in 0..ow {
                let src_x = sx + x / scale;
                let s = (src_y * fw + src_x) * 4;
                let d = (y * ow + x) * 4;
                out[d..d + 4].copy_from_slice(&self.bgra[s..s + 4]);
            }
        }
        Some(RegionImage {
            bgra: out,
            width: ow as u32,
            height: oh as u32,
        })
    }

    /// A scaled preview for the selection overlay (RGBA data-URI friendly):
    /// longest edge capped, returned as RGBA bytes + dims. The webview only
    /// ever sees this preview; the full-res frame stays in Rust.
    pub fn preview_rgba(&self, max_edge: u32) -> (Vec<u8>, u32, u32) {
        let max_edge = max_edge.max(320);
        let scale = (max_edge as f32 / self.width.max(1) as f32)
            .min(max_edge as f32 / self.height.max(1) as f32)
            .min(1.0);
        let w = ((self.width as f32 * scale).round() as u32).max(1);
        let h = ((self.height as f32 * scale).round() as u32).max(1);
        let fw = self.width as usize;
        let mut out = vec![0u8; (w * h * 4) as usize];
        for y in 0..h {
            let src_y = ((y as f32) / scale) as usize;
            let src_y = src_y.min(self.height as usize - 1);
            for x in 0..w {
                let src_x = ((x as f32) / scale) as usize;
                let src_x = src_x.min(fw - 1);
                let s = (src_y * fw + src_x) * 4;
                let d = ((y * w + x) * 4) as usize;
                // BGRA -> RGBA for the webview <img>.
                out[d] = self.bgra[s + 2];
                out[d + 1] = self.bgra[s + 1];
                out[d + 2] = self.bgra[s];
                out[d + 3] = 255;
            }
        }
        (out, w, h)
    }
}

/// A cropped region image (BGRA) with its dimensions.
pub struct RegionImage {
    pub bgra: Vec<u8>,
    pub width: u32,
    pub height: u32,
}

impl RegionImage {
    /// BGRA -> tightly-packed RGB (needed by the ONNX pipeline, which
    /// consumes RGB like the vision detector).
    pub fn to_rgb(&self) -> Vec<u8> {
        let n = (self.width * self.height) as usize;
        let mut rgb = vec![0u8; n * 3];
        for i in 0..n {
            rgb[i * 3] = self.bgra[i * 4 + 2];
            rgb[i * 3 + 1] = self.bgra[i * 4 + 1];
            rgb[i * 3 + 2] = self.bgra[i * 4];
        }
        rgb
    }
}

/// Freeze the full virtual screen. Platform-specific implementations;
/// everything else is `Unsupported`.
pub fn freeze() -> AppResult<FrozenFrame> {
    #[cfg(all(windows, feature = "snippet"))]
    {
        freeze_windows()
    }
    #[cfg(all(target_os = "macos", feature = "snippet"))]
    {
        crate::snippet::macos_sidecar::freeze()
    }
    #[cfg(all(not(windows), not(target_os = "macos")))]
    {
        Err(AppError::Unsupported(
            "screen capture is not supported on this platform yet".into(),
        ))
    }
}

/// GDI virtual-screen freeze: `BitBlt` into a top-down 32-bpp DIB section.
#[cfg(all(windows, feature = "snippet"))]
fn freeze_windows() -> AppResult<FrozenFrame> {
    use windows::Win32::Foundation::HWND;
    use windows::Win32::Graphics::Gdi::{
        BitBlt, CreateCompatibleDC, CreateDIBSection, DeleteDC, DeleteObject, GetDC, ReleaseDC,
        SelectObject, BITMAPINFO, BITMAPINFOHEADER, BI_RGB, DIB_RGB_COLORS, RGBQUAD, SRCCOPY,
    };
    use windows::Win32::UI::WindowsAndMessaging::{
        GetSystemMetrics, SM_CXVIRTUALSCREEN, SM_CYVIRTUALSCREEN, SM_XVIRTUALSCREEN,
        SM_YVIRTUALSCREEN,
    };

    unsafe {
        let x = GetSystemMetrics(SM_XVIRTUALSCREEN);
        let y = GetSystemMetrics(SM_YVIRTUALSCREEN);
        let w = GetSystemMetrics(SM_CXVIRTUALSCREEN);
        let h = GetSystemMetrics(SM_CYVIRTUALSCREEN);
        if w <= 0 || h <= 0 {
            return Err(AppError::Other("virtual screen has no size".into()));
        }

        let screen_dc = GetDC(HWND::default());
        if screen_dc.is_invalid() {
            return Err(AppError::Other("GetDC failed".into()));
        }
        let mem_dc = CreateCompatibleDC(screen_dc);
        if mem_dc.is_invalid() {
            ReleaseDC(HWND::default(), screen_dc);
            return Err(AppError::Other("CreateCompatibleDC failed".into()));
        }

        // Top-down DIB (negative height) so rows are memory-ordered.
        let bmi = BITMAPINFO {
            bmiHeader: BITMAPINFOHEADER {
                biSize: std::mem::size_of::<BITMAPINFOHEADER>() as u32,
                biWidth: w,
                biHeight: -h,
                biPlanes: 1,
                biBitCount: 32,
                biCompression: BI_RGB.0,
                biSizeImage: 0,
                biXPelsPerMeter: 0,
                biYPelsPerMeter: 0,
                biClrUsed: 0,
                biClrImportant: 0,
            },
            bmiColors: [RGBQUAD::default(); 1],
        };
        let mut bits: *mut std::ffi::c_void = std::ptr::null_mut();
        // windows 0.58: CreateDIBSection returns Result<HBITMAP>.
        let dib =
            CreateDIBSection(screen_dc, &bmi, DIB_RGB_COLORS, &mut bits, None, 0).map_err(|e| {
                let _ = DeleteDC(mem_dc);
                let _ = ReleaseDC(HWND::default(), screen_dc);
                AppError::Other(format!("CreateDIBSection failed: {e}"))
            })?;
        let old = SelectObject(mem_dc, dib);
        if old.is_invalid() {
            let _ = DeleteObject(dib);
            let _ = DeleteDC(mem_dc);
            let _ = ReleaseDC(HWND::default(), screen_dc);
            return Err(AppError::Other("SelectObject failed".into()));
        }

        // windows 0.58: BitBlt returns Result<()>.
        let blt = BitBlt(mem_dc, 0, 0, w, h, screen_dc, x, y, SRCCOPY);
        let _ = SelectObject(mem_dc, old);
        if blt.is_err() {
            let _ = DeleteObject(dib);
            let _ = DeleteDC(mem_dc);
            let _ = ReleaseDC(HWND::default(), screen_dc);
            return Err(AppError::Other("BitBlt failed".into()));
        }

        let len = (w * h * 4) as usize;
        // Copy out of the DIB section before DeleteObject frees it (the
        // bitmap owns the memory — taking it via from_raw_parts would
        // double-free).
        let bgra = std::slice::from_raw_parts(bits as *const u8, len).to_vec();
        let _ = DeleteObject(dib);
        let _ = DeleteDC(mem_dc);
        let _ = ReleaseDC(HWND::default(), screen_dc);
        Ok(FrozenFrame {
            bgra,
            width: w as u32,
            height: h as u32,
            origin: (x, y),
        })
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn crop_maps_virtual_coords_with_negative_origin() {
        let frame = FrozenFrame {
            bgra: vec![200u8; 8 * 8 * 4],
            width: 8,
            height: 8,
            origin: (-8, 0),
        };
        // Select the right half of the frame via virtual coords.
        let rect = RegionRect {
            x: -4,
            y: 2,
            w: 4,
            h: 4,
        };
        let img = frame.crop(&rect).unwrap();
        // min side 4 -> scale 3 (below the 64 px threshold).
        assert_eq!(img.width, 12);
        assert_eq!(img.height, 12);
        assert!(img.bgra.iter().all(|&b| b == 200));
    }

    #[test]
    fn crop_rejects_out_of_bounds() {
        let frame = FrozenFrame {
            bgra: vec![0u8; 8 * 8 * 4],
            width: 8,
            height: 8,
            origin: (0, 0),
        };
        let rect = RegionRect {
            x: 100,
            y: 100,
            w: 10,
            h: 10,
        };
        assert!(frame.crop(&rect).is_none());
    }

    #[test]
    fn preview_is_bgra_to_rgba_and_capped() {
        let mut bgra = vec![0u8; 4 * 4 * 4];
        // First pixel BGRA = (10, 20, 30, 255).
        bgra[0] = 30;
        bgra[1] = 20;
        bgra[2] = 10;
        bgra[3] = 255;
        let frame = FrozenFrame {
            bgra,
            width: 4,
            height: 4,
            origin: (0, 0),
        };
        let (rgba, w, h) = frame.preview_rgba(2);
        // The preview caps max_edge at 320 minimum, so a 4x4 frame is not
        // downscaled (real screens are far larger than the floor).
        assert_eq!((w, h), (4, 4));
        // First preview pixel: RGBA = (10, 20, 30, 255).
        assert_eq!(&rgba[0..4], &[10, 20, 30, 255]);
    }
}
