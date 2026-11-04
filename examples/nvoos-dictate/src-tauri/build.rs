fn main() {
    tauri_build::build();
    stage_pdfium_for_dev();
}

/// Best-effort: copy the gitignored `resources/pdfium.dll` beside the built
/// binary and beside integration-test binaries (`target/<profile>/deps/`)
/// so dev exes and the document test suite find the runtime library without
/// a manual copy. Silently skipped when the source is absent (CI, fresh
/// checkouts) — the dll is never fetched here; run
/// `scripts/fetch-pdfium.ps1` once per machine (Proposal 109 provisioning).
#[cfg(feature = "document")]
fn stage_pdfium_for_dev() {
    println!("cargo:rerun-if-changed=resources/pdfium.dll");
    let src = std::path::Path::new(env!("CARGO_MANIFEST_DIR"))
        .join("resources")
        .join("pdfium.dll");
    if !src.is_file() {
        return;
    }
    let out = std::path::PathBuf::from(std::env::var("OUT_DIR").expect("OUT_DIR"));
    // OUT_DIR = target/<profile>/build/<pkg>-<hash>/out -> profile root at [3].
    if let Some(profile_root) = out.ancestors().nth(3) {
        for dest in [
            profile_root.join("pdfium.dll"),
            profile_root.join("deps").join("pdfium.dll"),
        ] {
            let _ = std::fs::copy(&src, &dest);
        }
    }
}
