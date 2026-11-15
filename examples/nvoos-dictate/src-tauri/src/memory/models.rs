//! Embedder catalogue + downloader (Proposal 127, M1).
//!
//! Mirrors `stt::model_registry` discipline: pinned specs, streaming
//! downloads with sha256 verification, `.download` partials in the models
//! root, atomic per-file install into `models_dir/memory-{id}/`. Weights are
//! never compiled in and never committed — they download at runtime.
//!
//! File facts verified against the Hugging Face API on 2026-11-07 (LFS
//! oids): see the proposal's implementation plan for the full table.

use std::path::{Path, PathBuf};

use crate::error::{AppError, AppResult};

/// One downloadable file of an embedder package.
pub struct ModelFile {
    /// Local file name under `models_dir/memory-{spec_id}/`.
    pub name: &'static str,
    /// Path relative to the HF repo root (appended to the resolve URL).
    pub repo_path: &'static str,
    /// SHA-256 of the file bytes (the LFS oid — what HF serves).
    pub sha256: &'static str,
    /// Exact byte size (guard against truncated/hostile responses).
    pub bytes: u64,
}

pub struct EmbedModelSpec {
    /// Catalogue id shown in Settings.
    pub id: &'static str,
    pub description: &'static str,
    /// Approximate total download size, MB.
    pub size_mb: u32,
    pub license: &'static str,
    /// Vector width the model emits (the vec0 table is fixed at 384).
    pub dim: usize,
    /// Measured blend weight for this model (the SurfSense plateau for
    /// granite-97m-r2; bge's is its own measured 0.65).
    pub semantic_weight: f32,
    /// HF repo the resolve URL points at.
    pub repo: &'static str,
    pub files: &'static [ModelFile],
}

/// The shipped catalogue. `granite-97m-r2-int8` is the default: the exact
/// multilingual model SurfSense selected after a measured cross-lingual
/// sweep (Apache-2.0, ungated, 384-dim, CLS pooling). The FP32 export is
/// the fallback for CPUs where the AVX2-tuned int8 export misbehaves —
/// the M0 probe is the arbiter. bge-small-en-v1.5 is the English-only
/// reference used by SurfSense + fastembed; its sha256 is recorded on the
/// first measured download (the house `Option` discipline), so it stays
/// unpinned until then.
pub const CATALOGUE: &[EmbedModelSpec] = &[
    EmbedModelSpec {
        id: "granite-97m-r2-int8",
        description: "Granite-Embedding 97M multilingual r2 (int8, ~55 languages) — default",
        size_mb: 118,
        license: "Apache-2.0 (IBM Granite)",
        dim: 384,
        semantic_weight: 0.85,
        repo: "ibm-granite/granite-embedding-97m-multilingual-r2",
        files: &[
            ModelFile {
                name: "model_quint8_avx2.onnx",
                repo_path: "onnx/model_quint8_avx2.onnx",
                sha256: "a6022dd8220ea6f6595562a1328ee216f4a94faa55362f2f4747c80f1e78772e",
                bytes: 98_247_878,
            },
            ModelFile {
                name: "tokenizer.json",
                repo_path: "tokenizer.json",
                sha256: "4f2842d568e2724370aec203652a42ac783c7937f8347a1a2cc7506d71f1582f",
                bytes: 25_301_672,
            },
        ],
    },
    EmbedModelSpec {
        id: "granite-97m-r2-fp32",
        description: "Granite-Embedding 97M multilingual r2 (FP32) — fallback for the int8 export",
        size_mb: 372,
        license: "Apache-2.0 (IBM Granite)",
        dim: 384,
        semantic_weight: 0.85,
        repo: "ibm-granite/granite-embedding-97m-multilingual-r2",
        files: &[
            ModelFile {
                name: "model.onnx",
                repo_path: "onnx/model.onnx",
                sha256: "68e592b160673d30250824c1116bc6ab33f70efb22b97c9e1d7ce1e69c1c9d70",
                bytes: 390_004_608,
            },
            ModelFile {
                name: "tokenizer.json",
                repo_path: "tokenizer.json",
                sha256: "4f2842d568e2724370aec203652a42ac783c7937f8347a1a2cc7506d71f1582f",
                bytes: 25_301_672,
            },
        ],
    },
    EmbedModelSpec {
        id: "bge-small-en-v1.5",
        description: "BGE small EN v1.5 (English-only reference embedder)",
        size_mb: 64,
        license: "MIT (BAAI)",
        dim: 384,
        semantic_weight: 0.65,
        repo: "Qdrant/bge-small-en-v1.5-onnx-Q",
        files: &[
            ModelFile {
                name: "model_optimized.onnx",
                repo_path: "model_optimized.onnx",
                // Unpinned until the first measured download (house rule).
                sha256: "",
                bytes: 66_465_124,
            },
            ModelFile {
                name: "tokenizer.json",
                repo_path: "tokenizer.json",
                sha256: "",
                bytes: 0,
            },
        ],
    },
];

/// Look up a catalogue entry by id.
pub fn spec_by_id(id: &str) -> Option<&'static EmbedModelSpec> {
    CATALOGUE.iter().find(|s| s.id == id)
}

/// Install directory for a spec: `models_dir/memory-{id}/`.
pub fn install_dir(models_dir: &Path, spec: &EmbedModelSpec) -> PathBuf {
    models_dir.join(format!("memory-{}", spec.id))
}

/// Every file present (the `sha256`-empty bge entry counts as installed by
/// name — its pin lands with the first measured download).
pub fn is_installed(models_dir: &Path, spec: &EmbedModelSpec) -> bool {
    let dir = install_dir(models_dir, spec);
    spec.files.iter().all(|f| dir.join(f.name).is_file())
}

/// Paths the encoder loads, when installed.
pub fn model_paths(models_dir: &Path, spec: &EmbedModelSpec) -> (PathBuf, PathBuf) {
    let dir = install_dir(models_dir, spec);
    let onnx = spec
        .files
        .iter()
        .find(|f| f.name.ends_with(".onnx"))
        .map(|f| dir.join(f.name))
        .unwrap_or_else(|| dir.join("model.onnx"));
    let tokenizer = spec
        .files
        .iter()
        .find(|f| f.name == "tokenizer.json")
        .map(|f| dir.join(f.name))
        .unwrap_or_else(|| dir.join("tokenizer.json"));
    (onnx, tokenizer)
}

/// Download every file of a spec. Resumes partials with HTTP Range; a
/// `force` download restarts from zero (a corrupt prefix can never be
/// repaired by resuming). Each file is verified against its pinned sha256
/// and exact size before the atomic rename into place — a failed file
/// deletes its `.download` partial and aborts.
pub async fn download(
    spec: &'static EmbedModelSpec,
    models_dir: &Path,
    force: bool,
    progress: impl Fn(f64) + Send + Sync + 'static,
) -> AppResult<PathBuf> {
    let dir = install_dir(models_dir, spec);
    std::fs::create_dir_all(&dir)?;
    let client = reqwest::Client::builder()
        .user_agent(concat!("nvoos-dictate/", env!("CARGO_PKG_VERSION")))
        .build()?;

    let total_files = spec.files.len() as f64;
    for (index, file) in spec.files.iter().enumerate() {
        let url = format!(
            "https://huggingface.co/{}/resolve/main/{}",
            spec.repo, file.repo_path
        );
        download_file(&client, &url, &dir.join(file.name), file, force).await?;
        progress((index as f64 + 1.0) / total_files);
    }
    Ok(dir)
}

async fn download_file(
    client: &reqwest::Client,
    url: &str,
    dest: &Path,
    file: &ModelFile,
    force: bool,
) -> AppResult<()> {
    // The partial lives beside the final path, never overwriting it.
    let partial = dest.with_extension("download");
    if force {
        let _ = std::fs::remove_file(&partial);
    }
    let mut existing = std::fs::metadata(&partial).map(|m| m.len()).unwrap_or(0);
    if existing == file.bytes && verify_file(&partial, file) {
        // A complete, verified partial: promote it.
        std::fs::rename(&partial, dest)?;
        return Ok(());
    }
    if existing >= file.bytes {
        // Oversized or a complete-but-corrupt partial: resume can never
        // repair it, restart from zero.
        let _ = std::fs::remove_file(&partial);
        existing = 0;
    }

    let mut request = client.get(url);
    if existing > 0 {
        request = request.header(reqwest::header::RANGE, format!("bytes={existing}-"));
    }
    let mut response = request
        .send()
        .await
        .map_err(|e| AppError::Model(format!("download failed for {}: {e}", file.name)))?;
    let status = response.status();
    if !(status.is_success() || status == reqwest::StatusCode::PARTIAL_CONTENT) {
        return Err(AppError::Model(format!(
            "download for {} answered HTTP {status}",
            file.name
        )));
    }
    // A server that ignored the Range answers 200: truncate rather than
    // append (the STT downloader's rule). `.append(true)` implies write;
    // the explicit `.truncate` covers the fresh-path stale-tail case.
    let resuming = status == reqwest::StatusCode::PARTIAL_CONTENT;
    let mut out = std::fs::OpenOptions::new()
        .create(true)
        .append(resuming)
        .write(!resuming)
        .truncate(!resuming)
        .open(&partial)
        .map_err(|e| AppError::Model(format!("open partial for {}: {e}", file.name)))?;
    use std::io::Write;
    // `Response::chunk()` is inherent — no futures-util dependency needed
    // for the streaming loop.
    while let Some(chunk) = response
        .chunk()
        .await
        .map_err(|e| AppError::Model(format!("download stream for {}: {e}", file.name)))?
    {
        out.write_all(&chunk)
            .map_err(|e| AppError::Model(format!("write partial: {e}")))?;
    }
    out.flush().ok();
    drop(out);

    // Size gate first (cheap), then the sha256 (the real pin).
    let got = std::fs::metadata(&partial).map(|m| m.len()).unwrap_or(0);
    if got != file.bytes {
        let _ = std::fs::remove_file(&partial);
        return Err(AppError::Model(format!(
            "{} arrived with {got} bytes, expected {}",
            file.name, file.bytes
        )));
    }
    if !verify_file(&partial, file) {
        let _ = std::fs::remove_file(&partial);
        return Err(AppError::Model(format!(
            "{} failed its sha256 check — deleted",
            file.name
        )));
    }
    std::fs::rename(&partial, dest)
        .map_err(|e| AppError::Model(format!("install {}: {e}", file.name)))?;
    Ok(())
}

fn verify_file(path: &Path, file: &ModelFile) -> bool {
    if file.sha256.is_empty() {
        // Unpinned entry (bge, first download): measure and log, then accept
        // — the pin lands in a follow-up commit after the measured hash.
        if let Ok(bytes) = std::fs::read(path) {
            use sha2::Digest;
            let mut hasher = sha2::Sha256::new();
            hasher.update(&bytes);
            tracing::warn!(
                "memory: unpinned model file {} measured sha256 {} ({} bytes)",
                file.name,
                hex::encode(hasher.finalize()),
                bytes.len()
            );
        }
        return true;
    }
    use sha2::Digest;
    let mut hasher = sha2::Sha256::new();
    // Stream the hash — model files are ~100 MB, never read into memory.
    let mut reader = match std::fs::File::open(path) {
        Ok(f) => f,
        Err(_) => return false,
    };
    use std::io::Read;
    let mut buf = [0u8; 64 * 1024];
    loop {
        match reader.read(&mut buf) {
            Ok(0) => break,
            Ok(n) => hasher.update(&buf[..n]),
            Err(_) => return false,
        }
    }
    hex::encode(hasher.finalize()) == file.sha256
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn catalogue_is_internally_consistent() {
        for spec in CATALOGUE {
            assert!(!spec.files.is_empty(), "{} has no files", spec.id);
            assert!(
                spec.files.iter().any(|f| f.name.ends_with(".onnx")),
                "{} has no .onnx file",
                spec.id
            );
            assert!(
                spec.files.iter().any(|f| f.name == "tokenizer.json"),
                "{} has no tokenizer.json",
                spec.id
            );
            assert_eq!(spec.dim, 384, "{} must match the vec0 table width", spec.id);
        }
    }

    #[test]
    fn granite_int8_pins_are_present() {
        let spec = spec_by_id("granite-97m-r2-int8").unwrap();
        for f in spec.files {
            assert_eq!(f.sha256.len(), 64, "{} needs a 64-char sha256", f.name);
            assert!(f.bytes > 0, "{} needs an exact byte size", f.name);
        }
    }

    #[test]
    fn spec_lookup_and_paths() {
        assert!(spec_by_id("nope").is_none());
        let spec = spec_by_id("granite-97m-r2-int8").unwrap();
        let (onnx, tok) = model_paths(std::path::Path::new("/tmp"), spec);
        assert!(onnx.ends_with("model_quint8_avx2.onnx"));
        assert!(tok.ends_with("tokenizer.json"));
    }

    #[test]
    fn verify_file_checks_streamed_sha256() {
        let dir = std::env::temp_dir().join("nvoos-memory-test");
        std::fs::create_dir_all(&dir).unwrap();
        let path = dir.join("f.bin");
        std::fs::write(&path, b"hello world").unwrap();
        use sha2::Digest;
        let mut hasher = sha2::Sha256::new();
        hasher.update(b"hello world");
        let file = ModelFile {
            name: "f.bin",
            repo_path: "f.bin",
            sha256: "",
            bytes: 11,
        };
        // Unpinned: accepted (and the measured hash is logged).
        assert!(verify_file(&path, &file));
        // Wrong pin: rejected.
        let bad = ModelFile {
            sha256: "deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef",
            ..file
        };
        assert!(!verify_file(&path, &bad));
        // Right pin: accepted.
        let pin: &'static str = Box::leak(hex::encode(hasher.finalize()).into_boxed_str());
        let good = ModelFile {
            sha256: pin,
            ..file
        };
        assert!(verify_file(&path, &good));
        let _ = std::fs::remove_dir_all(&dir);
    }
}
