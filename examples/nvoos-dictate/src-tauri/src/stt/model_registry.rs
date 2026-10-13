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

/// Reject engine/model pairings the catalogue knows are wrong — e.g. a
/// whisper model id saved under the parakeet engine, the class of misconfig
/// that produces "parakeet model 'base.en' not found". Engines the catalogue
/// does not cover (mock, embedded — and their stored model value is ignored
/// at load) and unknown model ids are allowed: users may wire custom models
/// the catalogue does not list. Only ids that exist in the catalogue under a
/// DIFFERENT engine are rejected.
pub fn validate_pairing(engine: &str, model: &str) -> AppResult<()> {
    if !CATALOG.iter().any(|s| s.engine == engine) {
        return Ok(());
    }
    if let Some(spec) = CATALOG.iter().find(|s| s.id == model && s.engine != engine) {
        return Err(AppError::Model(format!(
            "model '{model}' belongs to the {} engine, not '{engine}' — pick it under {} in Settings",
            spec.engine, spec.engine
        )));
    }
    Ok(())
}

/// Whether a catalogue entry is present on disk, mirroring how each engine
/// resolves its files (parakeet dir with encoder/decoder/joiner/tokens;
/// whisper `{id}.bin`; vad `silero_vad.onnx`).
pub fn is_installed(spec: &ModelSpec, models_dir: &Path) -> bool {
    if spec.archive {
        let dir = models_dir.join(spec.id);
        dir.join("tokens.txt").is_file()
            && dir.join("encoder.int8.onnx").is_file()
            && dir.join("decoder.int8.onnx").is_file()
            && dir.join("joiner.int8.onnx").is_file()
    } else {
        let file = match spec.file_name {
            Some(name) => models_dir.join(name),
            None => models_dir.join(format!("{}.bin", spec.id)),
        };
        file.is_file()
    }
}

/// Catalogue entry + install state for the settings UI.
#[derive(Debug, Clone, Serialize)]
pub struct ModelStatus {
    pub engine: &'static str,
    pub id: &'static str,
    pub description: &'static str,
    pub size_mb: u32,
    pub license: &'static str,
    pub languages: &'static str,
    pub installed: bool,
}

/// The catalogue with each entry's on-disk state resolved.
pub fn status_list(models_dir: &Path) -> Vec<ModelStatus> {
    CATALOG
        .iter()
        .map(|s| ModelStatus {
            engine: s.engine,
            id: s.id,
            description: s.description,
            size_mb: s.size_mb,
            license: s.license,
            languages: s.languages,
            installed: is_installed(s, models_dir),
        })
        .collect()
}

/// Streaming download with progress callback (0.0..=1.0), SHA-256 check
/// (when the spec carries a hash), and atomic rename into place.
///
/// `force` restarts from zero instead of resuming a `.download` partial:
/// resume can stitch new bytes onto a corrupt file and can never repair it,
/// so the Settings UI offers a force re-download for installed models.
pub async fn download(
    spec: &'static ModelSpec,
    models_dir: &Path,
    force: bool,
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

    // The in-progress partial lives in `models_dir/` ROOT — a sibling of the
    // final install dir — NOT inside `models_dir/{id}/`. Putting it inside
    // the final dir means a re-download writes the `.download` file into the
    // very directory `install_archive` must `remove_dir_all` during the swap,
    // and a lingering Windows file-lock on the freshly written handle then
    // makes that removal (and the rename) fail, leaving an orphaned
    // `.download` + `.staging` with the model reported "not installed" even
    // though the bytes fully arrived.
    let tmp = models_dir.join(format!("{}.download", spec.id));
    // Resume a partial download when possible; HTTP Range keeps a flaky
    // connection from restarting a 600 MB pull from zero. A forced download
    // discards the partial — resuming from a corrupt prefix can never fix it.
    let mut existing = if force {
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
        // Server said the partial file is complete/out of range: restart.
        resp = client.get(spec.url).send().await?;
        existing = 0;
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
    // NOTE: every truncating create must carry a write access mode —
    // `OpenOptions` with create/truncate but no write/append access fails
    // client-side with InvalidInput "creating or truncating a file requires
    // write or append access" (a validation error, not an OS error). The
    // resumed path gets its write access from `append`.
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
            install_archive(&tmp, models_dir, spec.id)?
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
        // A re-download must be able to replace an existing target that is
        // read-only (or was left open by the resident engine — the IPC layer
        // drops the engine before calling download).
        make_writable(&dest);
        std::fs::rename(&tmp, &dest)?;
        dest
    };
    progress(1.0);
    Ok(final_path)
}

/// Extract a model archive into a fresh staging dir and atomically swap it
/// into place. Two failure classes this protects against:
///
/// - A previous install's files can be **read-only** (archive metadata is
///   preserved on extraction) — re-extracting over them then fails with
///   "creating or truncating a file requires write or append access".
/// - A failed in-place extraction leaves a mixed old/new file set that can
///   never load; the staging swap is all-or-nothing.
#[cfg(feature = "parakeet")]
fn install_archive(tmp: &Path, models_dir: &Path, id: &str) -> AppResult<PathBuf> {
    let final_dir = models_dir.join(id);
    // The archive's top-level folder is named after the model id; extract
    // into a staging root first, then move that folder into place (never
    // extract over a live install).
    let staging_root = models_dir.join(format!(".{id}.staging"));
    let _ = std::fs::remove_dir_all(&staging_root);
    extract_tar_bz2(tmp, &staging_root)?;
    let staging = staging_root.join(id);
    if !staging.is_dir() {
        return Err(AppError::Model(format!(
            "archive for '{id}' did not extract a top-level '{id}/' directory"
        )));
    }
    // The fresh tree must be re-downloadable later: strip any read-only bits
    // the archive carried.
    make_writable_recursive(&staging)?;
    let _ = std::fs::remove_file(tmp);
    // The previous install may be read-only too (and could be held open by
    // the resident engine — the IPC layer drops it first); clear attributes
    // before attempting the replacement.
    make_writable_recursive(&final_dir).ok();
    // Windows: a deleted directory's files can take a moment to release, so
    // the remove+rename pair can transiently fail with a sharing violation
    // even after the handle is dropped. Retry a few times with a short
    // backoff instead of abandoning an otherwise-complete 600 MB install.
    replace_dir(&staging, &final_dir)?;
    let _ = std::fs::remove_dir_all(&staging_root);
    Ok(final_dir)
}

/// Remove `dest` (if present) and rename `staging` into place, retrying on
/// transient Windows sharing violations (the classic orphaned-`.download`
/// root cause). Bounded retries; the last I/O error is surfaced.
#[cfg(feature = "parakeet")]
fn replace_dir(staging: &Path, dest: &Path) -> AppResult<()> {
    let mut last: std::io::Error = std::io::Error::other("swap did not run");
    for attempt in 0..5 {
        match std::fs::remove_dir_all(dest).and_then(|_| std::fs::rename(staging, dest)) {
            Ok(()) => return Ok(()),
            Err(e) => {
                last = e;
                if attempt < 4 {
                    std::thread::sleep(replace_dir_backoff(attempt));
                }
            }
        }
    }
    Err(AppError::Io(std::io::Error::new(
        last.kind(),
        format!(
            "could not swap model into place at {}: {}",
            dest.display(),
            last
        ),
    )))
}

/// 50ms * 2^attempt backoff for the dir-swap retry.
#[cfg(feature = "parakeet")]
fn replace_dir_backoff(attempt: usize) -> std::time::Duration {
    std::time::Duration::from_millis(50 * (1u64 << attempt.min(4)))
}

/// Clear read-only attributes across a tree. Windows archive metadata can
/// leave model files read-only, and every truncating re-download (or
/// re-extraction) then fails with "creating or truncating a file requires
/// write or append access". Attribute changes can be denied on some
/// volumes/filesystems — callers decide whether that is fatal.
#[cfg(feature = "parakeet")]
fn make_writable_recursive(path: &Path) -> AppResult<()> {
    let meta = match std::fs::symlink_metadata(path) {
        Ok(meta) => meta,
        Err(_) => return Ok(()), // not present yet
    };
    if meta.is_dir() {
        for entry in std::fs::read_dir(path)? {
            make_writable_recursive(&entry?.path())?;
        }
    }
    make_writable(path);
    Ok(())
}

/// Clear the read-only flag on a single path, if set. Cross-platform:
/// Windows maps this to `FILE_ATTRIBUTE_READONLY`; Unix adds owner-write
/// only (never world-writable).
fn make_writable(path: &Path) {
    let meta = match std::fs::metadata(path) {
        Ok(meta) => meta,
        Err(_) => return, // not present yet
    };
    #[cfg(windows)]
    {
        let mut perms = meta.permissions();
        if perms.readonly() {
            // `readonly` is exactly FILE_ATTRIBUTE_READONLY here — the
            // Unix-mode side effect the clippy lint warns about does not
            // apply on Windows.
            #[allow(clippy::permissions_set_readonly_false)]
            perms.set_readonly(false);
            let _ = std::fs::set_permissions(path, perms);
        }
    }
    #[cfg(unix)]
    {
        use std::os::unix::fs::PermissionsExt;
        let mut perms = meta.permissions();
        let mode = perms.mode();
        if mode & 0o200 == 0 {
            // Add owner-write only.
            perms.set_mode(mode | 0o200);
            let _ = std::fs::set_permissions(path, perms);
        }
    }
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
    fn pairing_validation_rejects_cross_engine_model_ids() {
        // The exact failure that wedged the app: whisper model under parakeet.
        assert!(validate_pairing("parakeet", "base.en").is_err());
        assert!(validate_pairing("whisper", "sherpa-onnx-nemo-parakeet-tdt-0.6b-v2-int8").is_err());
        // Valid pairings and custom (unknown) ids pass.
        assert!(validate_pairing("whisper", "base.en").is_ok());
        assert!(validate_pairing("parakeet", "sherpa-onnx-nemo-parakeet-tdt-0.6b-v2-int8").is_ok());
        assert!(
            validate_pairing("whisper", "small.en").is_ok(),
            "custom model ids are allowed"
        );
        // Engines without catalogue entries ignore the model value entirely;
        // the shipped default (mock + parakeet id) must keep saving.
        assert!(validate_pairing("mock", "").is_ok());
        assert!(validate_pairing("mock", "sherpa-onnx-nemo-parakeet-tdt-0.6b-v2-int8").is_ok());
        assert!(validate_pairing("embedded", "base.en").is_ok());
    }

    /// The downloader's tmp-file open contract: create+truncate REQUIRES an
    /// explicit write access mode — without it `OpenOptions` fails
    /// client-side with InvalidInput ("creating or truncating a file
    /// requires write or append access"), which is exactly the error a
    /// force re-download used to surface. The resumed path (append) gets
    /// write access from `append` itself.
    #[test]
    fn truncating_open_requires_write_access() {
        let dir =
            std::env::temp_dir().join(format!("nvoos-dictate-openopts-{}", std::process::id()));
        std::fs::create_dir_all(&dir).unwrap();
        let tmp = dir.join("m.download");

        // create+truncate with no access mode: client-side validation error.
        let err = std::fs::OpenOptions::new()
            .create(true)
            .append(false)
            .truncate(true)
            .open(&tmp)
            .unwrap_err();
        assert_eq!(err.kind(), std::io::ErrorKind::InvalidInput);

        // The fixed shape: write(true) for the fresh path...
        let file = std::fs::OpenOptions::new()
            .create(true)
            .write(true)
            .append(false)
            .truncate(true)
            .open(&tmp)
            .unwrap();
        drop(file);

        // ...and append(true) for the resumed path (append implies write).
        let file = std::fs::OpenOptions::new()
            .create(true)
            .append(true)
            .truncate(false)
            .open(&tmp)
            .unwrap();
        drop(file);

        let _ = std::fs::remove_dir_all(&dir);
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

    /// Regression: re-downloading over a previous install whose files are
    /// read-only (archive metadata) used to fail with "creating or
    /// truncating a file requires write or append access". The staging swap
    /// + attribute cleanup must replace the tree regardless.
    #[cfg(feature = "parakeet")]
    #[test]
    fn reinstall_over_readonly_previous_install_succeeds() {
        use std::io::Write;

        const ID: &str = "sherpa-onnx-nemo-parakeet-tdt-0.6b-v2-int8";
        let dir =
            std::env::temp_dir().join(format!("nvoos-dictate-reinstall-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&dir);
        std::fs::create_dir_all(dir.join(ID)).unwrap();

        // A previous install whose files are read-only (as archive metadata
        // extraction leaves them): confirm the failure mode exists, then
        // that the install path overcomes it.
        let old = dir.join(ID).join("tokens.txt");
        std::fs::write(&old, b"old").unwrap();
        let mut perms = std::fs::metadata(&old).unwrap().permissions();
        perms.set_readonly(true);
        std::fs::set_permissions(&old, perms).unwrap();
        assert!(
            std::fs::OpenOptions::new()
                .write(true)
                .truncate(true)
                .open(&old)
                .is_err(),
            "a read-only file must reject truncating opens (the reported failure mode)"
        );

        // Build a fresh archive for the same model id.
        let mut header = tar::Header::new_gnu();
        header.set_path(format!("{ID}/tokens.txt")).unwrap();
        header.set_size(3);
        header.set_mode(0o644);
        header.set_cksum();
        let mut builder = tar::Builder::new(Vec::new());
        builder.append(&header, &b"new"[..]).unwrap();
        let tar_bytes = builder.into_inner().unwrap();
        let archive_path = dir.join("m.tar.bz2");
        let mut encoder = bzip2::write::BzEncoder::new(
            std::fs::File::create(&archive_path).unwrap(),
            bzip2::Compression::default(),
        );
        encoder.write_all(&tar_bytes).unwrap();
        encoder.finish().unwrap();

        install_archive(&archive_path, &dir, ID).unwrap();

        let fresh = dir.join(ID).join("tokens.txt");
        assert_eq!(std::fs::read_to_string(&fresh).unwrap(), "new");
        // The fresh install is writable, so the NEXT re-download also works.
        std::fs::OpenOptions::new()
            .write(true)
            .truncate(true)
            .open(&fresh)
            .unwrap();
        let _ = std::fs::remove_dir_all(&dir);
    }

    /// The orphaned-`.download` regression: `replace_dir` must swap a staging
    /// tree over an already-present destination dir (the state a completed
    /// download leaves when the resident engine or a stale handle held the
    /// old install), and must leave the destination fully replaced.
    #[cfg(feature = "parakeet")]
    #[test]
    fn replace_dir_overwrites_existing_destination() {
        let dir = std::env::temp_dir().join(format!("nvoos-dictate-swap-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&dir);
        std::fs::create_dir_all(&dir).unwrap();

        let dest = dir.join("model");
        let staging = dir.join("model.stage");
        std::fs::create_dir_all(&dest).unwrap();
        std::fs::write(dest.join("tokens.txt"), b"old").unwrap();
        std::fs::create_dir_all(&staging).unwrap();
        std::fs::write(staging.join("tokens.txt"), b"new").unwrap();

        replace_dir(&staging, &dest).unwrap();

        assert_eq!(
            std::fs::read_to_string(dest.join("tokens.txt")).unwrap(),
            "new"
        );
        assert!(!staging.exists(), "staging tree is consumed by the swap");
        let _ = std::fs::remove_dir_all(&dir);
    }
}
