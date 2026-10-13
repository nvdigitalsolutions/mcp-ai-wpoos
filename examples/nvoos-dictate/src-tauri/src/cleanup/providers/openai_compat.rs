//! OpenAI-compatible Stage-2 provider (BYOK; also covers local Ollama
//! endpoints). The API key lives in the OS keychain — never in config.

use async_trait::async_trait;

use crate::cleanup::providers::openai_shape;
use crate::cleanup::providers::{CleanupProvider, PolishMeta};
use crate::config::{AppCategory, OpenAiCompatConfig};
use crate::error::{AppError, AppResult};
use crate::state::{SecretStore, KEY_OPENAI_API_KEY};

pub struct OpenAiCompatProvider {
    http: reqwest::Client,
    base_url: String,
    model: String,
}

impl OpenAiCompatProvider {
    pub fn new(config: &OpenAiCompatConfig, secrets: &dyn SecretStore) -> AppResult<Self> {
        let base_url = config.base_url.trim_end_matches('/').to_string();
        if !base_url.starts_with("https://") && !base_url.starts_with("http://localhost") {
            return Err(AppError::Auth(
                "OpenAI-compatible endpoint must be https:// (or localhost for dev)".into(),
            ));
        }
        let key = secrets.get(KEY_OPENAI_API_KEY)?.ok_or_else(|| {
            AppError::NotConfigured("OpenAI-compatible API key is not stored".into())
        })?;
        let mut headers = reqwest::header::HeaderMap::new();
        let mut auth = reqwest::header::HeaderValue::from_str(&format!("Bearer {key}"))
            .map_err(|_| AppError::Auth("invalid API key format".into()))?;
        auth.set_sensitive(true);
        headers.insert(reqwest::header::AUTHORIZATION, auth);
        let http = reqwest::Client::builder()
            .default_headers(headers)
            .build()?;
        Ok(Self {
            http,
            base_url,
            model: config.model.clone(),
        })
    }
}

#[async_trait]
impl CleanupProvider for OpenAiCompatProvider {
    fn id(&self) -> &'static str {
        "openai_compat"
    }

    async fn polish(&self, text: &str, category: AppCategory) -> AppResult<(String, PolishMeta)> {
        let body = openai_shape::build_chat_body(Some(&self.model), text, category, 0.2);
        let url = format!("{}/chat/completions", self.base_url);
        let resp = self.http.post(&url).json(&body).send().await?;
        if !resp.status().is_success() {
            return Err(AppError::Other(format!(
                "provider returned HTTP {}",
                resp.status()
            )));
        }
        let payload: serde_json::Value = resp.json().await?;
        let content = openai_shape::parse_chat_response(&payload)?;
        Ok((content, PolishMeta::default()))
    }
}
