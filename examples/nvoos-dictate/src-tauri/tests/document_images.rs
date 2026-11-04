//! Raster-image decode integration tests (Proposal 109 follow-up 1).
//!
//! Decode + routing contract only: recognition needs the platform engines
//! or the ONNX model pack, which are exercised through the shared OCR core
//! elsewhere. Feature-gated on `document` — unlike the PDF tests, no
//! pdfium.dll is needed for these paths.

#![cfg(feature = "document")]

use nvoos_dictate_lib::document::images;

fn fixture(name: &str) -> std::path::PathBuf {
    std::path::Path::new(env!("CARGO_MANIFEST_DIR"))
        .join("tests")
        .join("fixtures")
        .join(name)
}

#[test]
fn png_fixture_decodes_to_rgb_frame() {
    let frame = images::page(&fixture("image-text.png")).expect("decode png");
    assert_eq!(frame.width(), 600);
    assert_eq!(frame.height(), 200);
    let dark = frame.pixels().filter(|p| p[0] < 200).count();
    assert!(
        dark > 500,
        "fixture should contain dark glyph pixels, got {dark}"
    );
}

#[test]
fn jpeg_fixture_decodes_to_rgb_frame() {
    let frame = images::page(&fixture("image-text.jpg")).expect("decode jpeg");
    assert_eq!(frame.width(), 600);
    assert_eq!(frame.height(), 200);
    let dark = frame.pixels().filter(|p| p[0] < 200).count();
    assert!(
        dark > 500,
        "fixture should contain dark glyph pixels, got {dark}"
    );
}

#[test]
fn garbage_image_errors_cleanly_no_panic() {
    let err = images::page(&fixture("broken.png")).unwrap_err();
    assert!(
        err.to_string().contains("unsupported image"),
        "expected a clean decode error, got: {err}"
    );
}
