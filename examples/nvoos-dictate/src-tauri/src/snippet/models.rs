//! Snippet OCR (RapidOCR / PP-OCR) model catalogue: per-language packs of
//! detection + recognition ONNX models and the character dict, with SHA-256
//! verification, streaming download, and atomic install — mirroring
//! `vision/models.rs` and `stt/model_registry.rs`.
//!
//! Weights are downloaded at first use (never bundled). Each language pack
//! shares the detection model (`det.onnx`) and adds its own recognizer
//! (`rec.onnx`) + dictionary (`dict.txt`). Hashes are measured and pinned
//! after the first verified download (the vision policy: warn loudly while
//! a URL is unpinned, never ship a wrong hash).

use std::path::{Path, PathBuf};

use serde::Serialize;
use sha2::{Digest, Sha256};

use crate::error::{AppError, AppResult};

/// One downloadable file of a pack.
#[derive(Debug, Clone)]
pub struct PackFile {
    /// On-disk file name (e.g. `rec.onnx`, `dict.txt`).
    pub name: &'static str,
    /// Download URL (single file).
    pub url: &'static str,
    /// SHA-256 of the artifact, when published (verified on install).
    pub sha256: Option<&'static str>,
}

/// The shared detection model (DBNet), downloaded once for all packs.
pub const DET_FILE: PackFile = PackFile {
    name: "det.onnx",
    // Official RapidOCR ModelScope export (PP-OCRv5 mobile det, opset 14),
    // verified end-to-end on the reference PC via `snippet_ocr_probe`.
    url: "https://www.modelscope.cn/models/RapidAI/RapidOCR/resolve/master/onnx/PP-OCRv5/det/ch_PP-OCRv5_det_mobile.onnx",
    sha256: Some("4d97c44a20d30a81aad087d6a396b08f786c4635742afc391f6621f5c6ae78ae"),
};

/// A language pack: recognizer + dict for one language (det is shared).
#[derive(Debug, Clone)]
pub struct SnippetOcrPack {
    /// Pack id (BCP-47-ish, e.g. `en`, `de`).
    pub id: &'static str,
    /// Human label for the Settings UI.
    pub label: &'static str,
    /// Approximate total download size, MB (det + rec + dict).
    pub size_mb: u32,
    /// Model license (for the attribution note).
    pub license: &'static str,
    /// Recognizer model.
    pub rec: PackFile,
    /// Character dictionary (one char per line). 436 entries; decode maps
    /// class i ≥ 1 to dict[i-1] with class 0 = CTC blank (see
    /// `ocr_onnx::decode_rec`).
    pub dict: PackFile,
}

/// The pinned catalogue.
pub const CATALOG: &[SnippetOcrPack] = &[SnippetOcrPack {
    id: "en",
    label: "English (PP-OCRv5)",
    // det 4.8 MB + rec 7.8 MB + dict (negligible).
    size_mb: 13,
    license: "Apache-2.0 (PaddleOCR PP-OCR model weights)",
    rec: PackFile {
        name: "rec.onnx",
        // monkt/paddleocr-onnx (Hugging Face) mirror — this fixed-height
        // (48) export is used over the official ModelScope export because
        // the official export's dynamic height axis produces all-blank
        // output on the pinned ort rc.9 (verified on the reference PC).
        url: "https://huggingface.co/monkt/paddleocr-onnx/resolve/main/languages/english/rec.onnx",
        sha256: Some("4e16deb22c4da6468bdca539b2cd3c8687825538b67109177c47d359ab994cd7"),
    },
    dict: PackFile {
        name: "dict.txt",
        // Byte-identical to the official PaddleOCR ppocrv5_en_dict.txt
        // (verified by SHA-256).
        url: "https://huggingface.co/monkt/paddleocr-onnx/resolve/main/languages/english/dict.txt",
        sha256: Some("e025a66d31f327ba0c232e03f407ae8d105e1e709e7ccb3f408aa778c24e70d6"),
    },
}];

/// Look up a pack by id.
pub fn find(id: &str) -> Option<&'static SnippetOcrPack> {
    CATALOG.iter().find(|p| p.id == id)
}

/// Whether every file of the pack (plus the shared det) is present.
pub fn is_installed(pack: &SnippetOcrPack, models_dir: &Path) -> bool {
    models_dir.join("ocr").join(DET_FILE.name).is_file()
        && models_dir.join("ocr").join(pack.rec.name).is_file()
        && models_dir.join("ocr").join(pack.dict.name).is_file()
}

/// The on-disk directory for OCR packs.
pub fn pack_dir(models_dir: &Path) -> PathBuf {
    models_dir.join("ocr")
}

/// Resolve the on-disk path of one pack file.
pub fn file_path(_pack: &SnippetOcrPack, name: &str, models_dir: &Path) -> PathBuf {
    pack_dir(models_dir).join(name)
}

/// Catalogue entry + install state for the Settings UI.
#[derive(Debug, Clone, Serialize)]
pub struct SnippetModelStatus {
    pub id: &'static str,
    pub label: &'static str,
    pub size_mb: u32,
    pub license: &'static str,
    pub installed: bool,
}

/// The catalogue with each entry's on-disk state resolved.
pub fn status_list(models_dir: &Path) -> Vec<SnippetModelStatus> {
    CATALOG
        .iter()
        .map(|p| SnippetModelStatus {
            id: p.id,
            label: p.label,
            size_mb: p.size_mb,
            license: p.license,
            installed: is_installed(p, models_dir),
        })
        .collect()
}

/// Streaming download of one file with progress callback, SHA-256 check
/// (when pinned), and atomic rename into place. `force` restarts from zero.
async fn download_file(
    file: &PackFile,
    dest_dir: &Path,
    force: bool,
    progress: impl Fn(f64) + Send + Sync + 'static,
) -> AppResult<PathBuf> {
    let client = reqwest::Client::builder()
        .user_agent(concat!("nvoos-dictate/", env!("CARGO_PKG_VERSION")))
        .build()?;
    std::fs::create_dir_all(dest_dir)?;
    let tmp = dest_dir.join(format!("{}.download", file.name));
    let existing = if force {
        let _ = std::fs::remove_file(&tmp);
        0
    } else {
        tokio::fs::metadata(&tmp)
            .await
            .map(|m| m.len())
            .unwrap_or(0)
    };
    let mut request = client.get(file.url);
    if existing > 0 {
        request = request.header(reqwest::header::RANGE, format!("bytes={existing}-"));
    }
    let mut resp = request.send().await?;
    if resp.status() == reqwest::StatusCode::RANGE_NOT_SATISFIABLE {
        resp = client.get(file.url).send().await?;
    }
    if !resp.status().is_success() && resp.status() != reqwest::StatusCode::PARTIAL_CONTENT {
        return Err(AppError::Model(format!(
            "download failed for {}: HTTP {}",
            file.name,
            resp.status()
        )));
    }

    let resuming = resp.status() == reqwest::StatusCode::PARTIAL_CONTENT;
    let total = resp
        .headers()
        .get(reqwest::header::CONTENT_RANGE)
        .and_then(|v| v.to_str().ok())
        .and_then(|v| v.rsplit('/').next())
        .and_then(|v| v.parse::<u64>().ok())
        .or_else(|| {
            resp.content_length().map(|len| {
                if resuming {
                    existing.saturating_add(len)
                } else {
                    len
                }
            })
        })
        .unwrap_or(0);
    let mut received: u64 = if resuming { existing } else { 0 };
    let mut out = tokio::fs::OpenOptions::new()
        .create(true)
        .write(!resuming)
        .append(resuming)
        .truncate(!resuming)
        .open(&tmp)
        .await
        .map_err(|e| {
            AppError::Io(std::io::Error::new(
                e.kind(),
                format!("failed to open download target {}: {e}", tmp.display()),
            ))
        })?;
    use tokio::io::AsyncWriteExt;
    while let Some(chunk) = resp.chunk().await? {
        out.write_all(&chunk).await?;
        received += chunk.len() as u64;
        if total > 0 {
            progress(received as f64 / total as f64);
        }
    }
    out.sync_all().await?;
    drop(out);

    if let Some(expected) = file.sha256 {
        let actual = sha256_file(&tmp)?;
        if !actual.eq_ignore_ascii_case(expected) {
            let _ = std::fs::remove_file(&tmp);
            return Err(AppError::Model(format!(
                "checksum mismatch for {}: expected {expected}, got {actual}",
                file.name
            )));
        }
    } else {
        tracing::warn!(
            "OCR model {} downloaded without a pinned checksum (actual {}); pin it after review",
            file.name,
            sha256_file(&tmp)?
        );
    }

    let final_path = dest_dir.join(file.name);
    std::fs::rename(&tmp, &final_path)?;
    progress(1.0);
    Ok(final_path)
}

/// Download a full pack (det + rec + dict) with pack-level progress
/// (0.0..=1.0). Files already present are skipped unless `force` is set.
pub async fn download(
    pack: &'static SnippetOcrPack,
    models_dir: &Path,
    force: bool,
    progress: impl Fn(f64) + Send + Sync + 'static,
) -> AppResult<PathBuf> {
    let dest = pack_dir(models_dir);
    let files: [&PackFile; 3] = [&DET_FILE, &pack.rec, &pack.dict];
    let steps = files.len() as f64;
    // The per-file progress closure moves `progress`; share it through an
    // Arc so every file's callback can forward into it.
    let progress = std::sync::Arc::new(progress);
    for (i, file) in files.iter().enumerate() {
        let base = i as f64 / steps;
        let span = 1.0 / steps;
        let progress = progress.clone();
        let p = move |f: f64| progress(base + f * span);
        if !force && dest.join(file.name).is_file() {
            continue;
        }
        download_file(file, &dest, force, p).await?;
    }
    Ok(dest)
}

/// SHA-256 of a file (hex).
fn sha256_file(path: &Path) -> AppResult<String> {
    let bytes = std::fs::read(path)?;
    Ok(format!("{:x}", Sha256::digest(&bytes)))
}
