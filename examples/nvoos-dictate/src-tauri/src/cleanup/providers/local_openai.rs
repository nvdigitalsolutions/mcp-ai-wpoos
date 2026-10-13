//! Shared implementation for **local** OpenAI-compatible Stage-2 providers.
//!
//! LM Studio and Ollama both expose an OpenAI-compatible `/v1` surface over
//! plain `http://localhost` (no auth by default, optional bearer token for a
//! remote/headless server), support `GET /v1/models` discovery, and can cold-
//! load/idle-evict a model. This module owns that common behaviour; each
//! provider (`lm_studio`, `ollama`) is a thin wrapper that supplies the
//! server-specific defaults, labels, and keychain key.

use serde::Serialize;

use crate::cleanup::providers::openai_shape;
use crate::cleanup::providers::PolishMeta;
use crate::config::AppCategory;
use crate::error::{AppError, AppResult};

/// Server-specific identity for a local OpenAI-compatible backend.
///
/// Values stay user-facing in error messages and connection-test results, so
/// they are chosen to read naturally ("LM Studio", "Ollama").
#[derive(Debug, Clone, Copy)]
pub struct LocalKind {
    /// Display name in messages ("LM Studio", "Ollama").
    pub name: &'static str,
    /// Provider id recorded in transcripts/history (`lm_studio`, `ollama`).
    pub id: &'static str,
}

/// Result of the Settings-wizard connectivity + model-discovery test.
#[derive(Debug, Clone, Serialize)]
pub struct LocalConnectionTest {
    pub ok: bool,
    pub message: String,
    /// Model identifiers reported by `GET /v1/models`.
    pub models: Vec<String>,
    /// The single loaded model, when exactly one is loaded (LM Studio serves
    /// it regardless of the request `model` field in that case).
    pub recommended_model: Option<String>,
}

/// A local OpenAI-compatible polish provider (LM Studio / Ollama).
pub struct LocalOpenAiProvider {
    pub(crate) kind: LocalKind,
    pub(crate) http: reqwest::Client,
    pub(crate) base_url: String,
    pub(crate) model: String,
    /// Cold-load budget (ms): a separate timeout for a Just-In-Time model
    /// load the user opted into via Settings. Distinct from
    /// `cleanup.wait_for_llm_ms` (which bounds the *whole* polish). 0 = no
    /// extra budget on top of the request client default.
    pub(crate) load_timeout_ms: u64,
}

impl LocalOpenAiProvider {
    /// Build the client, enforcing the local/plain-http vs remote/https
    /// invariant and attaching the optional bearer token.
    pub fn new(
        kind: LocalKind,
        base_url: &str,
        model: &str,
        load_timeout_ms: u64,
        token: Option<String>,
    ) -> AppResult<Self> {
        let base_url = base_url.trim().trim_end_matches('/').to_string();
        // Local servers may be plain http; remote/headless must be https.
        let https_ok = base_url.starts_with("https://");
        let localhost_ok =
            base_url.starts_with("http://localhost") || base_url.starts_with("http://127.0.0.1");
        if !https_ok && !localhost_ok {
            return Err(AppError::Auth(format!(
                "{} server must be http://localhost (local) or https:// (remote)",
                kind.name
            )));
        }

        let mut builder = reqwest::Client::builder();
        // Optional bearer token (remote/headless server only).
        if let Some(token) = token {
            let token = token.trim();
            if !token.is_empty() {
                let mut auth = reqwest::header::HeaderValue::from_str(&format!("Bearer {token}"))
                    .map_err(|_| AppError::Auth("invalid bearer token".into()))?;
                auth.set_sensitive(true);
                let mut headers = reqwest::header::HeaderMap::new();
                headers.insert(reqwest::header::AUTHORIZATION, auth);
                builder = builder.default_headers(headers);
            }
        }
        // A soft default so a hung local server can't block the polish path
        // forever even when the user left the budget at 0.
        builder = builder.timeout(std::time::Duration::from_secs(60));
        let http = builder.build()?;

        Ok(Self {
            kind,
            http,
            base_url,
            model: model.to_string(),
            load_timeout_ms,
        })
    }

    /// Discover models and test connectivity against `GET /v1/models`.
    pub async fn test_connection(&self) -> LocalConnectionTest {
        let url = format!("{}/models", self.base_url);
        let resp = match self.http.get(&url).send().await {
            Ok(r) => r,
            Err(e) => {
                let hint = if e.is_connect() || e.is_timeout() {
                    format!(
                        "{} is not reachable — is it running with the server started?",
                        self.kind.name
                    )
                } else {
                    "request failed".to_string()
                };
                return LocalConnectionTest {
                    ok: false,
                    message: format!("{hint} ({e})"),
                    models: Vec::new(),
                    recommended_model: None,
                };
            }
        };
        let status = resp.status().as_u16();
        if status == 404 {
            return LocalConnectionTest {
                ok: false,
                message: "got HTTP 404 — the base URL should include the /v1 path".into(),
                models: Vec::new(),
                recommended_model: None,
            };
        }
        if !resp.status().is_success() {
            return LocalConnectionTest {
                ok: false,
                message: format!("unexpected HTTP {status} from {url}"),
                models: Vec::new(),
                recommended_model: None,
            };
        }
        match resp.json::<serde_json::Value>().await {
            Ok(payload) => {
                let models = parse_models(&payload);
                if models.is_empty() {
                    LocalConnectionTest {
                        ok: false,
                        message: format!(
                            "server reached, but no models are loaded — load a model in {}\
                             first, or pull one",
                            self.kind.name
                        ),
                        models: Vec::new(),
                        recommended_model: None,
                    }
                } else {
                    let recommended = (models.len() == 1).then(|| models[0].clone());
                    LocalConnectionTest {
                        ok: true,
                        message: format!("connected; {} model(s) discovered", models.len()),
                        models,
                        recommended_model: recommended,
                    }
                }
            }
            Err(e) => LocalConnectionTest {
                ok: false,
                message: format!("could not parse the /v1/models response: {e}"),
                models: Vec::new(),
                recommended_model: None,
            },
        }
    }

    /// Run a polish round-trip against `/v1/chat/completions`.
    pub async fn polish(
        &self,
        text: &str,
        category: AppCategory,
    ) -> AppResult<(String, PolishMeta)> {
        // `model` is omitted when empty, so the server serves its single
        // loaded model. Temperature stays low: transcript cleanup is
        // deterministic editing.
        let model = if self.model.is_empty() {
            None
        } else {
            Some(self.model.as_str())
        };
        let body = openai_shape::build_chat_body(model, text, category, 0.1);
        let url = format!("{}/chat/completions", self.base_url);

        // Measure the round-trip: `total_ms` from just before send to parsed
        // reply; `ttft_ms` from send to the response headers (first byte).
        let total_start = std::time::Instant::now();
        let send_fut = self.http.post(&url).json(&body).send();
        let resp = if self.load_timeout_ms > 0 {
            match tokio::time::timeout(
                std::time::Duration::from_millis(self.load_timeout_ms),
                send_fut,
            )
            .await
            {
                Ok(Ok(r)) => r,
                Ok(Err(e)) => return Err(self.map_send_error(e)),
                Err(_) => {
                    return Err(AppError::Other(format!(
                        "{} did not respond within the {} ms cold-load budget — the model \
                         may still be loading; raise Load timeout in Settings",
                        self.kind.name, self.load_timeout_ms
                    )));
                }
            }
        } else {
            match send_fut.await {
                Ok(r) => r,
                Err(e) => return Err(self.map_send_error(e)),
            }
        };
        let ttft_ms = total_start.elapsed().as_millis() as i64;

        let status = resp.status().as_u16();
        if status == 400 {
            return Err(AppError::Other(format!(
                "{} rejected the request (HTTP 400) — the model identifier may be unknown; \
                 use one from Test connection, or load it first",
                self.kind.name
            )));
        }
        if status == 404 {
            return Err(AppError::Other(
                "got HTTP 404 — the base URL should include the /v1 path".into(),
            ));
        }
        if !resp.status().is_success() {
            return Err(AppError::Other(format!(
                "{} returned HTTP {status}",
                self.kind.name
            )));
        }
        let payload: serde_json::Value = resp.json().await?;
        let total_ms = total_start.elapsed().as_millis() as i64;
        let content = openai_shape::parse_chat_response(&payload)?;
        let meta = PolishMeta {
            ttft_ms: Some(ttft_ms),
            total_ms: Some(total_ms),
            ..PolishMeta::default()
        };
        Ok((content, meta))
    }

    fn map_send_error(&self, e: reqwest::Error) -> AppError {
        let hint = if e.is_connect() || e.is_timeout() {
            format!(
                "{} is not reachable — is it running with the server started?",
                self.kind.name
            )
        } else {
            format!("{} request failed", self.kind.name)
        };
        AppError::Other(format!("{hint}: {e}"))
    }

    /// Produce a structured summary of a (long) transcript. Mirrors `polish`
    /// but uses the summarization prompt and does not carry timing metadata.
    pub async fn summarize(&self, text: &str) -> AppResult<String> {
        let model = if self.model.is_empty() {
            None
        } else {
            Some(self.model.as_str())
        };
        let body = openai_shape::build_chat_body_with_system(
            model,
            text,
            openai_shape::summarize_prompt(),
            0.0,
        );
        let url = format!("{}/chat/completions", self.base_url);
        let resp = match self.http.post(&url).json(&body).send().await {
            Ok(r) => r,
            Err(e) => return Err(self.map_send_error(e)),
        };
        let status = resp.status().as_u16();
        if status == 400 {
            return Err(AppError::Other(format!(
                "{} rejected the summary request (HTTP 400)",
                self.kind.name
            )));
        }
        if status == 404 {
            return Err(AppError::Other(
                "got HTTP 404 — the base URL should include the /v1 path".into(),
            ));
        }
        if !resp.status().is_success() {
            return Err(AppError::Other(format!(
                "{} returned HTTP {status}",
                self.kind.name
            )));
        }
        let payload: serde_json::Value = resp.json().await?;
        openai_shape::parse_chat_response(&payload)
    }
}

/// Extract model identifiers from an OpenAI-compatible `{ data: [{ id, … }] }`.
fn parse_models(payload: &serde_json::Value) -> Vec<String> {
    payload
        .get("data")
        .and_then(|d| d.as_array())
        .map(|items| {
            items
                .iter()
                .filter_map(|m| m.get("id").and_then(|v| v.as_str()).map(str::to_string))
                .collect()
        })
        .unwrap_or_default()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn parses_openai_compat_model_list() {
        let payload = serde_json::json!({
            "object": "list",
            "data": [
                { "id": "qwen2.5-7b-instruct", "object": "model" },
                { "id": "llama3.2", "object": "model" }
            ]
        });
        let models = parse_models(&payload);
        assert_eq!(models.len(), 2);
        assert_eq!(models[0], "qwen2.5-7b-instruct");
    }

    #[test]
    fn empty_model_list_is_empty() {
        assert!(parse_models(&serde_json::json!({ "data": [] })).is_empty());
        assert!(parse_models(&serde_json::json!({})).is_empty());
    }

    #[test]
    fn rejects_non_local_http_url() {
        let kind = LocalKind {
            name: "Ollama",
            id: "ollama",
        };
        match LocalOpenAiProvider::new(kind, "http://192.168.1.10:11434/v1", "", 0, None) {
            Ok(_) => panic!("expected non-local http URL to be rejected"),
            Err(err) => assert!(err.to_string().contains("http://localhost")),
        }
    }
}
