//! Model download registry: pinned model sources, SHA-256 verification,
//! progress reporting, atomic install.
//!
//! Weights are downloaded at first use (Resonant convention — never bundle
//! ~600 MB into the installer) and verified against the recorded hash where
//! upstream publishes one; entries without a hash log the measured value so
//! it can be pinned after review.

use std::path::{Path, PathBuf};

use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};

use crate::error::{AppError, AppResult};

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ModelSpec {
    /// Engine id: `parakeet` | `whisper` | `moonshine`.
    pub engine: &'static str,
    /// Model id shown in settings.
    pub id: &'static str,
    /// Human description.
    pub description: &'static str,
    /// Approximate download size, MB.
    pub size_mb: u32,
    /// Model license (for the attribution note in the UI).
    pub license: &'static str,
    /// Download URL (tar.bz2 archive or single file).
    pub url: &'static str,
    /// SHA-256 of the downloaded artifact, when published.
    pub sha256: Option<&'static str>,
    /// Archive vs single file.
    pub archive: bool,
    /// File name override for single-file downloads (default `{id}.bin`).
    pub file_name: Option<&'static str>,
    /// Supported languages.
    pub languages: &'static str,
}

/// The pinned catalogue.
pub const CATALOG: &[ModelSpec] = &[
    ModelSpec {
        engine: "parakeet",
        id: "sherpa-onnx-nemo-parakeet-tdt-0.6b-v2-int8",
        description: "NVIDIA Parakeet TDT 0.6B v2 (int8) — fastest English dictation on CPU (~380 ms per 5 s utterance)",
        size_mb: 600,
        license: "CC-BY-4.0 (base NVIDIA Parakeet model)",
        url: "https://github.com/k2-fsa/sherpa-onnx/releases/download/asr-models/sherpa-onnx-nemo-parakeet-tdt-0.6b-v2-int8.tar.bz2",
        sha256: None, // upstream does not publish per-release hashes; measured + pinned after review
        archive: true,
        file_name: None,
        languages: "English",
    },
    ModelSpec {
        engine: "whisper",
        id: "base.en",
        description: "Whisper base English (142 MB) — solid multilingual-family fallback",
        size_mb: 142,
        license: "MIT (OpenAI Whisper weights)",
        url: "https://huggingface.co/ggerganov/whisper.cpp/resolve/main/ggml-base.en.bin",
        sha256: None,
        archive: false,
        file_name: None,
        languages: "English",
    },
    ModelSpec {
        engine: "whisper",
        id: "tiny.en",
        description: "Whisper tiny English (75 MB) — fastest whisper option",
        size_mb: 75,
        license: "MIT (OpenAI Whisper weights)",
        url: "https://huggingface.co/ggerganov/whisper.cpp/resolve/main/ggml-tiny.en.bin",
        sha256: None,
        archive: false,
        file_name: None,
        languages: "English",
    },
    ModelSpec {
        engine: "whisper",
        id: "base",
        description: "Whisper base multilingual (142 MB)",
        size_mb: 142,
        license: "MIT (OpenAI Whisper weights)",
        url: "https://huggingface.co/ggerganov/whisper.cpp/resolve/main/ggml-base.bin",
        sha256: None,
        archive: false,
        file_name: None,
        languages: "Multilingual",
    },
    ModelSpec {
        engine: "vad",
        id: "silero_vad",
        description: "Silero VAD ONNX model (~2 MB) — neural voice-activity detection (optional silero-vad feature)",
        size_mb: 2,
        license: "MIT (Silero VAD)",
        url: "https://github.com/k2-fsa/sherpa-onnx/releases/download/asr-models/silero_vad.onnx",
        sha256: None,
        archive: false,
        file_name: Some("silero_vad.onnx"),
        languages: "All",
    },
];

pub fn find(engine: &str, id: &str) -> Option<&'static ModelSpec> {
    CATALOG.iter().find(|m| m.engine == engine && m.id == id)
}

/// Streaming download with progress callback (0.0..=1.0), SHA-256 check
/// (when the spec carries a hash), and atomic rename into place.
pub async fn download(
    spec: &'static ModelSpec,
    models_dir: &Path,
    progress: impl Fn(f64) + Send + Sync + 'static,
) -> AppResult<PathBuf> {
    let client = reqwest::Client::builder()
        .user_agent(concat!("nvoos-dictate/", env!("CARGO_PKG_VERSION")))
        .build()?;

    let target_dir = if spec.archive {
        models_dir.join(spec.id)
    } else {
        models_dir.to_path_buf()
    };
    std::fs::create_dir_all(&target_dir)?;

    let tmp = target_dir.join(format!("{}.download", spec.id));
    let mut resp = client.get(spec.url).send().await?;
    if !resp.status().is_success() {
        return Err(AppError::Model(format!(
            "download failed for {}: HTTP {}",
            spec.id,
            resp.status()
        )));
    }
    let total = resp.content_length().unwrap_or(0);
    let mut received: u64 = 0;
    let mut file = tokio::fs::File::create(&tmp).await?;
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
        // Record the measured hash so the catalogue can pin it after review.
        tracing::warn!(
            "model {} downloaded without a pinned checksum (actual {}); pin it in model_registry.rs after review",
            spec.id,
            sha256_file(&tmp)?
        );
    }

    let final_path = if spec.archive {
        #[cfg(feature = "parakeet")]
        {
            // The sherpa-onnx archive extracts a top-level folder named after
            // the model id (e.g. `sherpa-onnx-nemo-parakeet-tdt-0.6b-v2-int8/`),
            // so unpack into the models dir itself.
            extract_tar_bz2(&tmp, models_dir)?;
            let _ = std::fs::remove_file(&tmp);
            models_dir.join(spec.id)
        }
        #[cfg(not(feature = "parakeet"))]
        {
            let _ = std::fs::remove_file(&tmp);
            return Err(AppError::Model(
                "archive models require the parakeet feature (tar/bzip2 support)".into(),
            ));
        }
    } else {
        let dest = match spec.file_name {
            Some(name) => target_dir.join(name),
            None => target_dir.join(format!("{}.bin", spec.id)),
        };
        std::fs::rename(&tmp, &dest)?;
        dest
    };
    progress(1.0);
    Ok(final_path)
}

/// Stream-hash a file (models can be hundreds of MB; never read into memory).
fn sha256_file(path: &Path) -> AppResult<String> {
    use std::io::Read;
    let file = std::fs::File::open(path)?;
    let mut reader = std::io::BufReader::new(file);
    let mut hasher = Sha256::new();
    let mut buf = [0u8; 64 * 1024];
    loop {
        let n = reader.read(&mut buf)?;
        if n == 0 {
            break;
        }
        hasher.update(&buf[..n]);
    }
    Ok(hex::encode(hasher.finalize()))
}

/// Minimal tar.bz2 extraction for the sherpa-onnx model archive format.
#[cfg(feature = "parakeet")]
fn extract_tar_bz2(archive: &Path, dest: &Path) -> AppResult<()> {
    let file = std::fs::File::open(archive)?;
    let decoder = bzip2::read::BzDecoder::new(std::io::BufReader::new(file));
    let mut archive = tar::Archive::new(decoder);
    archive
        .unpack(dest)
        .map_err(|e| AppError::Model(format!("extract failed: {e}")))?;
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn catalog_lookup_is_consistent() {
        for spec in CATALOG {
            assert!(!spec.id.is_empty() && !spec.url.is_empty() && !spec.engine.is_empty());
            assert_eq!(find(spec.engine, spec.id).map(|m| m.id), Some(spec.id));
        }
        assert!(find("parakeet", "no-such-model").is_none());
    }

    #[test]
    fn sha256_matches_known_vector() {
        let dir = std::env::temp_dir().join(format!("nvoos-dictate-hash-{}", std::process::id()));
        std::fs::create_dir_all(&dir).unwrap();
        let p = dir.join("abc.txt");
        std::fs::write(&p, b"abc").unwrap();
        assert_eq!(
            sha256_file(&p).unwrap(),
            "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad"
        );
        let _ = std::fs::remove_dir_all(&dir);
    }

    /// The sherpa-onnx archive layout: a top-level dir named after the model
    /// id containing `tokens.txt` etc. Extraction must preserve it so the
    /// engine finds files at `models_dir/{id}/…`.
    #[cfg(feature = "parakeet")]
    #[test]
    fn extract_tar_bz2_preserves_top_level_dir() {
        use std::io::Write;

        let dir =
            std::env::temp_dir().join(format!("nvoos-dictate-extract-{}", std::process::id()));
        std::fs::create_dir_all(&dir).unwrap();

        let mut header = tar::Header::new_gnu();
        header
            .set_path("sherpa-onnx-nemo-parakeet-tdt-0.6b-v2-int8/tokens.txt")
            .unwrap();
        header.set_size(4);
        header.set_mode(0o644);
        header.set_cksum();
        let mut builder = tar::Builder::new(Vec::new());
        builder.append(&header, &b"# 42"[..]).unwrap();
        let tar_bytes = builder.into_inner().unwrap();

        let archive_path = dir.join("m.tar.bz2");
        let encoder = bzip2::write::BzEncoder::new(
            std::fs::File::create(&archive_path).unwrap(),
            bzip2::Compression::default(),
        );
        let mut encoder = encoder;
        encoder.write_all(&tar_bytes).unwrap();
        encoder.finish().unwrap();

        extract_tar_bz2(&archive_path, &dir).unwrap();
        let tokens = std::fs::read_to_string(
            dir.join("sherpa-onnx-nemo-parakeet-tdt-0.6b-v2-int8/tokens.txt"),
        )
        .unwrap();
        assert_eq!(tokens, "# 42");
        let _ = std::fs::remove_dir_all(&dir);
    }
}
