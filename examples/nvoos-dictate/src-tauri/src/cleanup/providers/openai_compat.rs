//! OpenAI-compatible Stage-2 provider (BYOK; also covers local Ollama
//! endpoints). The API key lives in the OS keychain — never in config.

use async_trait::async_trait;
use serde_json::json;

use crate::cleanup::providers::CleanupProvider;
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

    async fn polish(&self, text: &str, category: AppCategory) -> AppResult<String> {
        let system = system_prompt_for(category);
        let body = json!({
            "model": self.model,
            "messages": [
                { "role": "system", "content": system },
                { "role": "user", "content": text }
            ],
            "temperature": 0.2
        });
        let url = format!("{}/chat/completions", self.base_url);
        let resp = self.http.post(&url).json(&body).send().await?;
        if !resp.status().is_success() {
            return Err(AppError::Other(format!(
                "provider returned HTTP {}",
                resp.status()
            )));
        }
        let payload: serde_json::Value = resp.json().await?;
        let content = &payload["choices"][0]["message"]["content"];
        // content may be a string or an array of blocks — flatten defensively.
        match content {
            serde_json::Value::String(s) => Ok(s.trim().to_string()),
            serde_json::Value::Array(items) => {
                let mut out = String::new();
                for item in items {
                    match item {
                        serde_json::Value::String(s) => out.push_str(s),
                        serde_json::Value::Object(map) => {
                            if let Some(t) = map.get("text").and_then(|v| v.as_str()) {
                                out.push_str(t);
                            }
                        }
                        _ => {}
                    }
                }
                Ok(out.trim().to_string())
            }
            _ => Err(AppError::Other(
                "provider returned an empty response".into(),
            )),
        }
    }
}

fn system_prompt_for(category: AppCategory) -> &'static str {
    match category {
        AppCategory::Code | AppCategory::Terminal => {
            "You are a dictation editor. Return only the cleaned text. Preserve code \
             identifiers, casing, punctuation and line structure exactly. Remove filler \
             words only where clearly safe."
        }
        AppCategory::Chat => {
            "You are a dictation editor for a chat message. Return only the cleaned text: \
             natural, informal, correct spelling, no trailing period. Keep names and \
             numbers exactly as written."
        }
        _ => {
            "You are a dictation editor. Return only the cleaned text: remove filler words, \
             fix grammar and punctuation, keep names, numbers and code identifiers exactly \
             as written, and preserve the meaning."
        }
    }
}
