//! Scan-profile preprocessing for document pages (Proposal 102 §3.3's
//! accuracy ladder, pulled forward by Proposal 109 M2).
//!
//! The `screen` profile (snippet captures) is untouched — this ladder is
//! applied only to rasterized document pages, which are scans/photos, not
//! crisp axis-aligned screen text:
//! 1. **Upscale small pages** (long edge < 1600 px → 2× Lanczos3): the
//!    single biggest win for phone-photo-of-text and low-res scans.
//! 2. **Otsu binarization** on grayscale: separates ink from background,
//!    which measurably helps every engine on typical scans.
//!
//! Deskew is deliberately deferred to Proposal 102 (its projection-profile
//! step needs the fuller ladder + fixtures).

use image::imageops::FilterType;

/// Long edge under which a page is upscaled 2×.
pub const UPSCALE_LONG_EDGE: u32 = 1600;

/// Run the scan profile over an RGB page. Returns the processed RGB buffer
/// and its (possibly new) dimensions.
pub fn scan_profile(rgb: &[u8], w: u32, h: u32) -> (Vec<u8>, u32, u32) {
    let mut img = image::RgbImage::from_raw(w, h, rgb.to_vec()).unwrap_or_default();
    let (mut w, mut h) = (w, h);
    if w.max(h) < UPSCALE_LONG_EDGE {
        img = image::imageops::resize(&img, w * 2, h * 2, FilterType::Lanczos3);
        w *= 2;
        h *= 2;
    }
    let gray: Vec<u8> = img
        .pixels()
        .map(|p| {
            // Rec. 601 luma.
            (0.299 * p.0[0] as f32 + 0.587 * p.0[1] as f32 + 0.114 * p.0[2] as f32) as u8
        })
        .collect();
    let t = otsu(&gray);
    let mut out = Vec::with_capacity(gray.len() * 3);
    for &g in &gray {
        let v = if g > t { 255 } else { 0 };
        out.extend_from_slice(&[v, v, v]);
    }
    (out, w, h)
}

/// Otsu's method: the threshold maximizing between-class variance. Returns
/// 127 (mid-gray) for a degenerate/empty input.
pub fn otsu(gray: &[u8]) -> u8 {
    let mut hist = [0u64; 256];
    let mut total = 0u64;
    for &g in gray {
        hist[g as usize] += 1;
        total += 1;
    }
    if total == 0 {
        return 127;
    }
    let mut sum = 0u64;
    for (i, &h) in hist.iter().enumerate() {
        sum += i as u64 * h;
    }
    let mut sum_b = 0u64;
    let mut w_b = 0u64;
    let mut best = -1.0f64;
    let mut best_t = 127u8;
    for (t, &h) in hist.iter().enumerate() {
        w_b += h;
        if w_b == 0 {
            continue;
        }
        let w_f = total - w_b;
        if w_f == 0 {
            break;
        }
        sum_b += t as u64 * h;
        let m_b = sum_b as f64 / w_b as f64;
        let m_f = (sum - sum_b) as f64 / w_f as f64;
        let between = w_b as f64 * w_f as f64 * (m_b - m_f) * (m_b - m_f);
        if between > best {
            best = between;
            best_t = t as u8;
        }
    }
    best_t
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn otsu_splits_bimodal_ink_and_paper() {
        let mut gray = vec![250u8; 2000]; // paper
        gray.extend_from_slice(&[10u8; 2000]); // ink
        let t = otsu(&gray);
        // Any separating threshold between the modes is correct (the flat
        // histogram has a plateau of equal maxima, so 10 or 249 both win).
        assert!(
            (10..=249).contains(&t),
            "threshold {t} must separate the two modes"
        );
    }

    #[test]
    fn otsu_handles_empty_input() {
        assert_eq!(otsu(&[]), 127);
    }

    #[test]
    fn scan_profile_upscales_small_pages() {
        // 100x100 all-white page -> upscaled to 200x200, binarized to white.
        let rgb = vec![255u8; 100 * 100 * 3];
        let (out, w, h) = scan_profile(&rgb, 100, 100);
        assert_eq!((w, h), (200, 200));
        assert_eq!(out.len(), 200 * 200 * 3);
        assert!(out.iter().all(|&v| v == 255));
    }

    #[test]
    fn scan_profile_leaves_large_pages_sized() {
        let rgb = vec![128u8; 2000 * 100 * 3];
        let (out, w, h) = scan_profile(&rgb, 2000, 100);
        assert_eq!((w, h), (2000, 100));
        assert_eq!(out.len(), rgb.len());
    }
}
