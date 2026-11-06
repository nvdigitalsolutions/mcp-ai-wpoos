//! Embedder implementations (Proposal 127).
//!
//! `MockEmbedder` (always compiled) backs the test suite with deterministic
//! hash-derived vectors so no test needs the network or the model weights.
//! `OrtEmbedder` (feature `memory`) runs the pinned granite ONNX encoder
//! through the app's `ort` rc.9 pin — session APIs only, positional inputs,
//! the same discipline as `vision/detect.rs`.

use crate::error::AppResult;
// The encoder bodies (cfg `memory`) name these directly; the default build
// has no use for them.
#[cfg(feature = "memory")]
use crate::error::AppError;

/// The embedding seam: text batch in, normalized vectors out.
/// All implementors normalize to unit length — the vector table is
/// queried with `distance_metric=cosine` and the blend treats
/// `1 - distance` as the semantic score.
pub trait Embedder: Send {
    /// Vector width this embedder produces (384 for the pinned granite).
    fn dim(&self) -> usize;

    /// Embed a batch. Order is preserved: `out[i]` corresponds to `texts[i]`.
    fn embed_batch(&mut self, texts: &[String]) -> AppResult<Vec<Vec<f32>>>;
}

/// Deterministic test embedder: an FNV-1a hash of the text seeds a simple
/// LCG that fills the vector, then L2-normalizes. Same text → same vector,
/// forever and on every platform; different text → (almost surely)
/// different direction. This gives the retrieval tests stable, meaningful
/// tie-breaking without any model files.
pub struct MockEmbedder {
    dim: usize,
}

impl MockEmbedder {
    pub fn new(dim: usize) -> Self {
        Self { dim }
    }
}

impl Embedder for MockEmbedder {
    fn dim(&self) -> usize {
        self.dim
    }

    fn embed_batch(&mut self, texts: &[String]) -> AppResult<Vec<Vec<f32>>> {
        Ok(texts.iter().map(|t| mock_vector(t, self.dim)).collect())
    }
}

/// Deterministic hash → unit vector. Pure; shared with the retrieval tests.
pub(crate) fn mock_vector(text: &str, dim: usize) -> Vec<f32> {
    let mut hash: u64 = 0xcbf2_9ce4_8422_2325; // FNV-1a offset basis.
    for b in text.as_bytes() {
        hash ^= u64::from(*b);
        hash = hash.wrapping_mul(0x0000_0100_0000_01b3); // FNV-1a prime.
    }
    // xorshift64* seeded by the hash → uniform-ish stream.
    let mut state = hash | 1;
    let mut out = Vec::with_capacity(dim);
    for _ in 0..dim {
        state ^= state >> 12;
        state ^= state << 25;
        state ^= state >> 27;
        let next = state.wrapping_mul(0x2545_f491_4f6c_dd1d);
        let bits = (next >> 40) as u32;
        out.push((bits as f32 / (u32::MAX as f32)) * 2.0 - 1.0);
    }
    normalize(&mut out);
    out
}

/// L2-normalize in place (zero vectors are left as-is).
pub(crate) fn normalize(v: &mut [f32]) {
    let norm: f32 = v.iter().map(|x| x * x).sum::<f32>().sqrt();
    if norm > 0.0 {
        for x in v.iter_mut() {
            *x /= norm;
        }
    }
}

/// The real encoder: granite ModernBERT ONNX + tokenizer.json, CLS pooling,
/// L2 normalization. Input/output tensor names are discovered from the
/// session (the sentence-transformers exporter names have changed across
/// versions — discovery beats hard-coding, and the probe logs what it saw).
#[cfg(feature = "memory")]
pub struct OrtEmbedder {
    tokenizer: tokenizers::Tokenizer,
    session: ort::session::Session,
    dim: usize,
    /// Name of the i64 ids input (usually `input_ids`).
    ids_input: String,
    /// Name of the i64 mask input (usually `attention_mask`).
    mask_input: String,
}

#[cfg(feature = "memory")]
impl OrtEmbedder {
    /// Load tokenizer + session. `model_path` = `model_quint8_avx2.onnx`
    /// (or the FP32 fallback); `tokenizer_path` = `tokenizer.json`.
    pub fn load(model_path: &std::path::Path, tokenizer_path: &std::path::Path) -> AppResult<Self> {
        let mut tokenizer = tokenizers::Tokenizer::from_file(tokenizer_path)
            .map_err(|e| AppError::Model(format!("tokenizer load: {e}")))?;
        // Truncate at 512 tokens — the granite embedding context and the
        // SurfSense-verified sweet spot for chunk-sized passages.
        tokenizer
            .with_truncation(Some(tokenizers::TruncationParams {
                max_length: 512,
                strategy: tokenizers::TruncationStrategy::LongestFirst,
                stride: 0,
                direction: tokenizers::TruncationDirection::Right,
            }))
            .map_err(|e| AppError::Model(format!("tokenizer truncation: {e}")))?;

        let session = ort::session::Session::builder()?
            .commit_from_file(model_path)
            .map_err(|e| AppError::Model(format!("embedder session: {e}")))?;

        // Discover input roles positionally: the ONNX export's input order
        // is what the session runs on; we match each input by name pattern.
        let mut ids_input: Option<String> = None;
        let mut mask_input: Option<String> = None;
        for input in session.inputs.iter() {
            let name = input.name.clone();
            let lower = name.to_ascii_lowercase();
            if ids_input.is_none() && (lower.contains("input_ids") || lower.contains("inputid")) {
                ids_input = Some(name);
            } else if mask_input.is_none()
                && (lower.contains("attention_mask") || lower.contains("attentionmask"))
            {
                mask_input = Some(name);
            }
        }
        let ids_input = ids_input.ok_or_else(|| {
            AppError::Model("embedder export has no input_ids-style input".into())
        })?;
        let mask_input = mask_input.ok_or_else(|| {
            AppError::Model("embedder export has no attention_mask-style input".into())
        })?;

        Ok(Self {
            tokenizer,
            session,
            dim: 384,
            ids_input,
            mask_input,
        })
    }

    /// Pad token id (0 when the tokenizer declares none — BERT-family
    /// exports pad with 0 and mask it out anyway).
    fn pad_id(&self) -> i64 {
        self.tokenizer
            .get_padding()
            .map(|p| i64::from(p.pad_id))
            .unwrap_or(0)
    }
}

#[cfg(feature = "memory")]
impl Embedder for OrtEmbedder {
    fn dim(&self) -> usize {
        self.dim
    }

    fn embed_batch(&mut self, texts: &[String]) -> AppResult<Vec<Vec<f32>>> {
        use ndarray16::Array2;

        if texts.is_empty() {
            return Ok(Vec::new());
        }
        let batch = texts.len();

        // Tokenize each text (special tokens on, truncated at 512).
        let encodings = texts
            .iter()
            .map(|t| {
                self.tokenizer
                    .encode(t.clone(), true)
                    .map_err(|e| AppError::Other(format!("tokenize: {e}")))
            })
            .collect::<AppResult<Vec<_>>>()?;

        // Pad to the batch max length.
        let max_len = encodings
            .iter()
            .map(|e| e.get_ids().len())
            .max()
            .unwrap_or(0);
        if max_len == 0 {
            return Err(AppError::Other(
                "embedder produced zero-length inputs".into(),
            ));
        }
        let pad_id = self.pad_id();
        let mut ids = vec![0i64; batch * max_len];
        let mut mask = vec![0i64; batch * max_len];
        for (row, enc) in encodings.iter().enumerate() {
            for (col, &id) in enc.get_ids().iter().enumerate() {
                ids[row * max_len + col] = i64::from(id);
            }
            for (col, &m) in enc.get_attention_mask().iter().enumerate() {
                mask[row * max_len + col] = i64::from(m);
            }
            for col in enc.get_ids().len()..max_len {
                ids[row * max_len + col] = pad_id;
            }
        }

        let ids_tensor = Array2::from_shape_vec((batch, max_len), ids)
            .map_err(|e| AppError::Other(format!("ids reshape: {e}")))?
            .into_dyn();
        let mask_tensor = Array2::from_shape_vec((batch, max_len), mask)
            .map_err(|e| AppError::Other(format!("mask reshape: {e}")))?
            .into_dyn();

        // Positional inputs in session order (the house pattern — the
        // named form has burned us before): pair each session input with
        // the tensor its discovered role demands, and reject anything we
        // did not name-check at load.
        if self.session.inputs.len() != 2 {
            return Err(AppError::Model(format!(
                "embedder export has {} inputs, expected 2 (ids + mask)",
                self.session.inputs.len()
            )));
        }
        let mut values = Vec::with_capacity(2);
        for input in self.session.inputs.iter() {
            if input.name == self.ids_input {
                values.push(ort::value::Value::from_array(ids_tensor.clone())?);
            } else if input.name == self.mask_input {
                values.push(ort::value::Value::from_array(mask_tensor.clone())?);
            } else {
                return Err(AppError::Model(format!(
                    "embedder export has unexpected input '{}'",
                    input.name
                )));
            }
        }
        let mut values = values.into_iter();
        let outputs = self
            .session
            .run(ort::inputs![
                values.next().unwrap(),
                values.next().unwrap()
            ]?)
            .map_err(|e| AppError::Other(format!("embedder inference: {e}")))?;

        // Output: newer sentence-transformers exports name it
        // `sentence_embedding` (pooled, [batch, dim]); older ones emit
        // `last_hidden_state` ([batch, seq, dim]). Try the pooled names
        // first, then fall back to the first positional output.
        let out = outputs
            .get("sentence_embedding")
            .or_else(|| outputs.get("last_hidden_state"))
            .unwrap_or(&outputs[0]);
        let tensor = out
            .try_extract_tensor::<f32>()
            .map_err(|e| AppError::Other(format!("embedder output tensor: {e}")))?;
        let shape = tensor.shape().to_vec();
        let (rows, cols, hidden) = match shape.as_slice() {
            [r, h] => (*r, 1usize, *h), // [batch, dim] pooled export
            [r, s, h] => (*r, *s, *h),  // [batch, seq, dim] — CLS-pool
            other => {
                return Err(AppError::Model(format!(
                    "unexpected embedder output shape {other:?}"
                )))
            }
        };
        if rows != batch || hidden != self.dim {
            return Err(AppError::Model(format!(
                "embedder output {rows}x{hidden} does not match batch {batch} dim {}",
                self.dim
            )));
        }
        // CLS pooling: first token of each sequence (granite's
        // sentence-transformers config is pooling_mode_cls_token). Flat
        // indexing keeps the [batch, dim] and [batch, seq, dim] shapes on
        // one code path (contiguity is guaranteed for these exports).
        let view = tensor.view();
        let slice = view
            .as_slice()
            .ok_or_else(|| AppError::Model("embedder output is not contiguous".into()))?;
        let mut out_vectors = Vec::with_capacity(rows);
        for r in 0..rows {
            let mut vector = Vec::with_capacity(hidden);
            for h in 0..hidden {
                let flat = if cols > 1 {
                    r * cols * hidden + h
                } else {
                    r * hidden + h
                };
                vector.push(slice[flat]);
            }
            normalize(&mut vector);
            out_vectors.push(vector);
        }
        Ok(out_vectors)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn mock_vectors_are_deterministic_and_normalized() {
        let a = mock_vector("the quick brown fox", 384);
        let b = mock_vector("the quick brown fox", 384);
        assert_eq!(a, b);
        let norm: f32 = a.iter().map(|x| x * x).sum::<f32>().sqrt();
        assert!((norm - 1.0).abs() < 1e-6);
        // Different text, different direction (overwhelmingly likely with a
        // seeded 64-bit stream; a hash collision here is a real bug).
        let c = mock_vector("unrelated text", 384);
        assert_ne!(a, c);
    }

    #[test]
    fn mock_embedder_batch_preserves_order() {
        let mut embedder = MockEmbedder::new(384);
        let out = embedder
            .embed_batch(&["first".to_string(), "second".to_string()])
            .unwrap();
        assert_eq!(out.len(), 2);
        assert_eq!(out[0], mock_vector("first", 384));
        assert_eq!(out[1], mock_vector("second", 384));
    }
}
