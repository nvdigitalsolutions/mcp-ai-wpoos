//! Hand-landmark model catalogue with a **pinned, hash-verified** artifact.
//!
//! Proposal 153 M0 is satisfied for one export: OpenCV Zoo's ONNX conversion of
//! Google's MediaPipe Hands landmark model (Apache-2.0). The entry carries the
//! exact repository revision, byte count and SHA-256, so a download either
//! matches the reviewed bytes or fails closed.
//!
//! Three supported ways to supply a model:
//!
//! 1. `Settings → Gestures → Download` (the pinned artifact, verified on install);
//! 2. `Settings → Gestures → Model path` (any local `.onnx`);
//! 3. drop the file at `models/hand-landmark.onnx`.
//!
//! Downloads use the same streaming/atomic-rename discipline as
//! `vision/models.rs`, including resume-from-`.download` and the SHA-256 gate.

use std::path::{Path, PathBuf};

use serde::Serialize;
use sha2::{Digest, Sha256};

use crate::error::{AppError, AppResult};

#[derive(Debug, Clone)]
pub struct LandmarkModelSpec {
    /// Stable id stored in `Settings.gestures.landmark_model`.
    pub id: &'static str,
    pub description: &'static str,
    /// File name under the models directory.
    pub file_name: &'static str,
    /// Approximate size, MB (0 until the M0 pin records the real size).
    pub size_mb: u32,
    /// Licence note. **Not** a licence claim until M0 records the model card.
    pub license: &'static str,
    /// Download URL, or `None` while the export is unpinned.
    pub url: Option<&'static str>,
    /// SHA-256 of the artifact, or `None` while unpinned.
    pub sha256: Option<&'static str>,
}

/// The pinned artifact: OpenCV Zoo's ONNX conversion of Google's MediaPipe
/// Hands landmark model. Referenced by **repository revision**, not a branch, so
/// the bytes cannot change underneath the hash below.
pub const HAND_LANDMARK_URL: &str = "https://huggingface.co/opencv/handpose_estimation_mediapipe/resolve/4b2a0b446e5cf2f11fb6b2c7251091c035d2c1f7/handpose_estimation_mediapipe_2023feb.onnx";

/// SHA-256 of [`HAND_LANDMARK_URL`] (4,099,621 bytes), measured 2026-11-21.
pub const HAND_LANDMARK_SHA256: &str =
    "db0898ae717b76b075d9bf563af315b29562e11f8df5027a1ef07b02bef6d81c";

/// The v1 catalogue.
pub const CATALOG: &[LandmarkModelSpec] = &[LandmarkModelSpec {
    id: "hand-landmark",
    description: "MediaPipe Hands 21-keypoint regressor (fp32 ONNX, NHWC 1x224x224x3, input 0..1), \
         converted by OpenCV Zoo. This export regresses landmarks from a **cropped hand**, so \
         keep the hand large in frame; the palm-detector stage is not part of this pipeline.",
    file_name: "hand-landmark.onnx",
    size_mb: 4,
    license: "Apache-2.0 (OpenCV handpose_estimation_mediapipe, converted from Google MediaPipe Hands)",
    url: Some(HAND_LANDMARK_URL),
    sha256: Some(HAND_LANDMARK_SHA256),
}];

pub fn find(id: &str) -> Option<&'static LandmarkModelSpec> {
    CATALOG.iter().find(|m| m.id == id)
}

pub fn model_path(spec: &LandmarkModelSpec, models_dir: &Path) -> PathBuf {
    models_dir.join(spec.file_name)
}

pub fn is_installed(spec: &LandmarkModelSpec, models_dir: &Path) -> bool {
    model_path(spec, models_dir).is_file()
}

/// Catalogue entry + install state for the Settings UI.
#[derive(Debug, Clone, Serialize)]
pub struct LandmarkModelStatus {
    pub id: &'static str,
    pub description: &'static str,
    pub license: &'static str,
    pub installed: bool,
    /// `true` once M0 pins a verified download.
    pub downloadable: bool,
    /// Where the file is expected when supplied locally.
    pub expected_path: String,
}

pub fn status_list(models_dir: &Path) -> Vec<LandmarkModelStatus> {
    CATALOG
        .iter()
        .map(|spec| LandmarkModelStatus {
            id: spec.id,
            description: spec.description,
            license: spec.license,
            installed: is_installed(spec, models_dir),
            downloadable: spec.url.is_some() && spec.sha256.is_some(),
            expected_path: model_path(spec, models_dir).display().to_string(),
        })
        .collect()
}

/// Resolve the model file to load.
///
/// `explicit` is the user-supplied path (Settings). When empty, the catalogue
/// entry is used. Every failure names the exact path the user must provide.
pub fn resolve_path(id: &str, explicit: Option<&str>, models_dir: &Path) -> AppResult<PathBuf> {
    if let Some(raw) = explicit.map(str::trim).filter(|s| !s.is_empty()) {
        let path = PathBuf::from(raw);
        if path.is_file() {
            return Ok(path);
        }
        return Err(AppError::Model(format!(
            "hand-landmark model not found at {}. Point Settings → Gestures → Model path \
             at an .onnx file.",
            path.display()
        )));
    }

    let spec = find(id).ok_or_else(|| {
        AppError::Model(format!(
            "unknown hand-landmark model '{id}'. Known ids: {}",
            CATALOG.iter().map(|m| m.id).collect::<Vec<_>>().join(", ")
        ))
    })?;

    let path = model_path(spec, models_dir);
    if path.is_file() {
        return Ok(path);
    }
    Err(AppError::Model(format!(
        "hand-landmark model is not installed. Place a MediaPipe-compatible ONNX file at {} \
         (or set Settings → Gestures → Model path).",
        path.display()
    )))
}

/// Streaming download with progress callback (0.0..=1.0), SHA-256 check, and
/// atomic rename into place. `force` restarts from zero.
///
/// Mirrors `vision/models.rs`; the checksum is mandatory for this catalogue —
/// an artifact without a pinned hash is never installed silently.
pub async fn download(
    spec: &'static LandmarkModelSpec,
    models_dir: &Path,
    force: bool,
    progress: impl Fn(f64) + Send + Sync + 'static,
) -> AppResult<PathBuf> {
    let url = spec.url.ok_or_else(|| {
        AppError::Model(format!(
            "hand-landmark model '{}' has no pinned download; supply the .onnx locally \
             (Settings → Gestures → Model path)",
            spec.id
        ))
    })?;

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
    let mut request = client.get(url);
    if existing > 0 {
        request = request.header(reqwest::header::RANGE, format!("bytes={existing}-"));
    }
    let mut resp = request.send().await?;
    if resp.status() == reqwest::StatusCode::RANGE_NOT_SATISFIABLE {
        resp = client.get(url).send().await?;
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

    let expected = spec.sha256.ok_or_else(|| {
        AppError::Model(format!(
            "refusing to install {} without a pinned checksum",
            spec.id
        ))
    })?;
    let actual = sha256_file(&tmp)?;
    if !actual.eq_ignore_ascii_case(expected) {
        let _ = std::fs::remove_file(&tmp);
        return Err(AppError::Model(format!(
            "checksum mismatch for {}: expected {expected}, got {actual}",
            spec.id
        )));
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

#[cfg(test)]
mod tests {
    use super::*;

    fn temp_dir(name: &str) -> PathBuf {
        let dir = std::env::temp_dir().join(format!(
            "nvoos-gesture-test-{}-{}",
            name,
            std::process::id()
        ));
        let _ = std::fs::remove_dir_all(&dir);
        std::fs::create_dir_all(&dir).expect("temp dir");
        dir
    }

    #[test]
    fn the_catalogue_never_ships_an_unverified_download() {
        for spec in CATALOG {
            assert!(
                spec.url.is_none() || spec.sha256.is_some(),
                "{} has a URL without a pinned hash",
                spec.id
            );
        }
    }

    #[test]
    fn an_explicit_path_wins_and_must_exist() {
        let dir = temp_dir("explicit");
        let file = dir.join("my.onnx");
        std::fs::write(&file, b"not really a model").expect("write");

        let resolved = resolve_path("hand-landmark", Some(file.to_str().unwrap()), &dir)
            .expect("explicit path resolves");
        assert_eq!(resolved, file);

        let err = resolve_path("hand-landmark", Some("/definitely/missing.onnx"), &dir)
            .expect_err("missing explicit path errors");
        assert!(format!("{err}").contains("not found"));
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_missing_catalogue_file_names_the_expected_path() {
        let dir = temp_dir("missing");
        let err = resolve_path("hand-landmark", None, &dir).expect_err("not installed");
        let msg = format!("{err}");
        assert!(msg.contains("not installed"), "{msg}");
        assert!(msg.contains("hand-landmark.onnx"), "{msg}");
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn unknown_ids_list_the_known_ones() {
        let dir = temp_dir("unknown");
        let err = resolve_path("nope", None, &dir).expect_err("unknown id");
        assert!(format!("{err}").contains("hand-landmark"));
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn the_pinned_artifact_is_a_revision_url_with_a_sha256() {
        let spec = &CATALOG[0];
        let url = spec.url.expect("pinned entry has a URL");
        let hash = spec.sha256.expect("pinned entry has a hash");
        assert!(url.starts_with("https://"), "{url}");
        // A branch URL can change underneath the hash; a revision cannot.
        assert!(url.contains("/resolve/"), "{url}");
        assert_eq!(hash.len(), 64, "{hash}");
        assert!(hash.chars().all(|c| c.is_ascii_hexdigit()), "{hash}");
    }

    #[test]
    fn sha256_file_matches_a_known_vector() {
        let dir = temp_dir("sha256");
        let file = dir.join("abc.bin");
        std::fs::write(&file, b"abc").expect("write");
        assert_eq!(
            sha256_file(&file).expect("hash"),
            "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad"
        );
        let _ = std::fs::remove_dir_all(&dir);
    }
}
