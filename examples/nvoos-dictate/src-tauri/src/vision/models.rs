//! Vision model catalogue: pinned ONNX detector / hand models with SHA-256
//! verification, streaming download, and atomic install — mirroring
//! `stt::model_registry` but scoped to single-file ONNX vision models.
//!
//! Weights are downloaded at first use (never bundled); each entry resolves a
//! single `.onnx` file under the models dir, verified against its hash where
//! upstream publishes one.

use std::path::{Path, PathBuf};

use serde::Serialize;
use sha2::{Digest, Sha256};

use crate::error::{AppError, AppResult};

/// A pinned vision model.
#[derive(Debug, Clone)]
pub struct VisionModelSpec {
    /// Model id (e.g. `yolov11n`, `yolov8n`, `mediapipe-hand`).
    pub id: &'static str,
    /// Human description.
    pub description: &'static str,
    /// Approximate download size, MB.
    pub size_mb: u32,
    /// Model license (for the attribution note).
    pub license: &'static str,
    /// Download URL (single `.onnx` file).
    pub url: &'static str,
    /// On-disk file name (default `{id}.onnx`).
    pub file_name: &'static str,
    /// SHA-256 of the artifact, when published (verified on install).
    pub sha256: Option<&'static str>,
}

/// The pinned vision catalogue.
pub const CATALOG: &[VisionModelSpec] = &[
    VisionModelSpec {
        id: "yolov11n",
        description: "YOLOv11 nano (COCO-80) — default local object detector (~5 MB)",
        size_mb: 11,
        license: "AGPL-3.0 (Ultralytics YOLOv11 weights)",
        url: "https://github.com/ultralytics/assets/releases/download/v8.3.0/yolo11n.onnx",
        file_name: "yolov11n.onnx",
        sha256: None, // measured + pinned after review
    },
    VisionModelSpec {
        id: "yolov8n",
        description: "YOLOv8 nano (COCO-80) — fallback detector on the same ONNX shape",
        size_mb: 12,
        license: "AGPL-3.0 (Ultralytics YOLOv8 weights)",
        url: "https://github.com/ultralytics/assets/releases/download/v8.3.0/yolov8n.onnx",
        file_name: "yolov8n.onnx",
        sha256: None,
    },
];

/// Look up a vision model by id.
pub fn find(id: &str) -> Option<&'static VisionModelSpec> {
    CATALOG.iter().find(|m| m.id == id)
}

/// Whether a vision model file is present on disk.
pub fn is_installed(spec: &VisionModelSpec, models_dir: &Path) -> bool {
    models_dir.join(spec.file_name).is_file()
}

/// Catalogue entry + install state for the Settings UI.
#[derive(Debug, Clone, Serialize)]
pub struct VisionModelStatus {
    pub id: &'static str,
    pub description: &'static str,
    pub size_mb: u32,
    pub license: &'static str,
    pub installed: bool,
}

/// The catalogue with each entry's on-disk state resolved.
pub fn status_list(models_dir: &Path) -> Vec<VisionModelStatus> {
    CATALOG
        .iter()
        .map(|s| VisionModelStatus {
            id: s.id,
            description: s.description,
            size_mb: s.size_mb,
            license: s.license,
            installed: is_installed(s, models_dir),
        })
        .collect()
}

/// Resolve the on-disk path of a model file (whether installed or not).
pub fn model_path(spec: &VisionModelSpec, models_dir: &Path) -> PathBuf {
    models_dir.join(spec.file_name)
}

/// Streaming download with progress callback (0.0..=1.0), SHA-256 check, and
/// atomic rename into place. `force` restarts from zero.
pub async fn download(
    spec: &'static VisionModelSpec,
    models_dir: &Path,
    force: bool,
    progress: impl Fn(f64) + Send + Sync + 'static,
) -> AppResult<PathBuf> {
    let client = reqwest::Client::builder()
        .user_agent(concat!("nvoos-dictate/", env!("CARGO_PKG_VERSION")))
        .build()?;

    std::fs::create_dir_all(models_dir)?;
    let tmp = models_dir.join(format!("{}.download", spec.file_name));
    let existing = if force {
        let _ = std::fs::remove_file(&tmp);
        0
    } else {
        tokio::fs::metadata(&tmp)
            .await
            .map(|m| m.len())
            .unwrap_or(0)
    };
    let mut request = client.get(spec.url);
    if existing > 0 {
        request = request.header(reqwest::header::RANGE, format!("bytes={existing}-"));
    }
    let mut resp = request.send().await?;
    if resp.status() == reqwest::StatusCode::RANGE_NOT_SATISFIABLE {
        resp = client.get(spec.url).send().await?;
    }
    if !resp.status().is_success() && resp.status() != reqwest::StatusCode::PARTIAL_CONTENT {
        return Err(AppError::Model(format!(
            "download failed for {}: HTTP {}",
            spec.id,
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
    let mut file = tokio::fs::OpenOptions::new()
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
        file.write_all(&chunk).await?;
        received += chunk.len() as u64;
        if total > 0 {
            progress(received as f64 / total as f64);
        }
    }
    file.sync_all().await?;
    drop(file);

    if let Some(expected) = spec.sha256 {
        let actual = sha256_file(&tmp)?;
        if !actual.eq_ignore_ascii_case(expected) {
            let _ = std::fs::remove_file(&tmp);
            return Err(AppError::Model(format!(
                "checksum mismatch for {}: expected {expected}, got {actual}",
                spec.id
            )));
        }
    } else {
        tracing::warn!(
            "vision model {} downloaded without a pinned checksum (actual {}); pin it after review",
            spec.id,
            sha256_file(&tmp)?
        );
    }

    let final_path = model_path(spec, models_dir);
    std::fs::rename(&tmp, &final_path)?;
    progress(1.0);
    Ok(final_path)
}

/// SHA-256 of a file (hex).
fn sha256_file(path: &Path) -> AppResult<String> {
    let bytes = std::fs::read(path)?;
    Ok(format!("{:x}", Sha256::digest(&bytes)))
}
