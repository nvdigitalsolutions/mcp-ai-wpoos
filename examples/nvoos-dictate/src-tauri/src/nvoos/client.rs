//! Typed REST client for the NV oOS plugin.
//!
//! Security invariants:
//! - Only `https://` origins (plus `http://localhost` for development) are
//!   accepted — credentials never leave the machine to a plaintext host.
//! - The credential is attached only to requests against the configured
//!   origin (never to model downloads or third-party hosts).
//! - A failing credential is a definitive 401: the client never retries
//!   without authentication.

use serde_json::json;

use crate::config::AppCategory;
use crate::error::{AppError, AppResult};
use crate::nvoos::types::{extract_reply_text, ChatMessage, ChatRequest, ContentSegment};

/// Result of the settings-wizard connection test.
#[derive(Debug, Clone, serde::Serialize)]
pub struct ConnectionTest {
    pub ok: bool,
    pub message: String,
    /// True when the credential was accepted (authorization confirmed).
    pub authenticated: bool,
}

pub struct NvoosClient {
    http: reqwest::Client,
    base: String,
    credential: String,
}

impl NvoosClient {
    pub fn new(site_url: String, credential: String) -> AppResult<Self> {
        let base = site_url.trim().trim_end_matches('/').to_string();
        let https_ok = base.starts_with("https://");
        let localhost_ok =
            base.starts_with("http://localhost") || base.starts_with("http://127.0.0.1");
        if !https_ok && !localhost_ok {
            return Err(AppError::Auth(
                "NV oOS site URL must use https:// (http://localhost allowed for development)"
                    .into(),
            ));
        }
        if credential.is_empty() {
            return Err(AppError::Auth("empty assistant credential".into()));
        }
        let http = reqwest::Client::builder()
            .user_agent(concat!("nvoos-dictate/", env!("CARGO_PKG_VERSION")))
            .build()?;
        Ok(Self {
            http,
            base,
            credential,
        })
    }

    pub fn base_url(&self) -> &str {
        &self.base
    }

    fn authenticated_post(&self, path: &str) -> reqwest::RequestBuilder {
        self.http
            .post(format!("{}{}", self.base, path))
            .bearer_auth(&self.credential)
    }

    /// Settings-wizard connectivity + credential check.
    pub async fn test_connection(&self) -> AppResult<ConnectionTest> {
        let url = format!("{}/wp-json/mcp-ai/v1/assistants", self.base);
        let resp = match self
            .http
            .get(&url)
            .bearer_auth(&self.credential)
            .send()
            .await
        {
            Ok(r) => r,
            Err(e) => {
                return Ok(ConnectionTest {
                    ok: false,
                    authenticated: false,
                    message: format!("could not reach {url}: {e}"),
                })
            }
        };
        match resp.status().as_u16() {
            200 | 201 => Ok(ConnectionTest {
                ok: true,
                authenticated: true,
                message: "connected; assistant credential accepted".into(),
            }),
            401 | 403 => Ok(ConnectionTest {
                ok: false,
                authenticated: false,
                message: "site reached, but the assistant credential was rejected (401/403)".into(),
            }),
            other => Ok(ConnectionTest {
                ok: false,
                authenticated: false,
                message: format!("unexpected HTTP {other} from {url}"),
            }),
        }
    }

    /// Stage-2 polish: send the transcript to the assistant for cleanup.
    pub async fn polish(
        &self,
        text: &str,
        category: AppCategory,
        cleanup_prompt: &str,
        assistant_id: Option<&str>,
    ) -> AppResult<String> {
        let system = match category {
            AppCategory::Code | AppCategory::Terminal => format!(
                "{cleanup_prompt} The user was dictating into a code editor or terminal: \
                 preserve identifiers and casing exactly."
            ),
            AppCategory::Chat => format!(
                "{cleanup_prompt} The user was dictating a chat message: keep it informal, \
                 no trailing period."
            ),
            _ => cleanup_prompt.to_string(),
        };

        let body = ChatRequest {
            assistant_id: assistant_id.map(|s| s.to_string()),
            messages: vec![
                ChatMessage {
                    role: "system".into(),
                    content: vec![ContentSegment::text(system)],
                },
                ChatMessage {
                    role: "user".into(),
                    content: vec![ContentSegment::text(text)],
                },
            ],
            stream: Some(false),
        };

        let resp = self
            .authenticated_post("/wp-json/mcp-ai/v1/chat")
            .json(&body)
            .send()
            .await?;

        let status = resp.status().as_u16();
        if status == 401 || status == 403 {
            return Err(AppError::Auth(
                "the assistant credential was rejected — re-enter it in Settings > NV oOS".into(),
            ));
        }
        if !resp.status().is_success() {
            return Err(AppError::Other(format!(
                "chat endpoint returned HTTP {status}"
            )));
        }
        let payload: serde_json::Value = resp.json().await?;
        extract_reply_text(&payload)
            .ok_or_else(|| AppError::Other("chat endpoint returned an unparseable response".into()))
    }

    /// One-way archive of a transcript into the plugin's transcript store.
    pub async fn archive_transcript(
        &self,
        record: &crate::nvoos::TranscriptRecord,
    ) -> AppResult<()> {
        let body = json!({
            "assistant_id": record.assistant_id,
            "title": record.title,
            "messages": [
                {
                    "role": "user",
                    "content": [ { "type": "text", "text": record.raw } ]
                },
                {
                    "role": "assistant",
                    "content": [ { "type": "text", "text": record.clean } ]
                }
            ],
            "meta": {
                "source": "nvoos-dictate",
                "target_app": record.target_app,
                "engine": record.engine,
                "e2e_ms": record.e2e_ms
            }
        });
        let resp = self
            .authenticated_post("/wp-json/mcp-ai/v1/chat-transcripts")
            .json(&body)
            .send()
            .await?;
        let status = resp.status().as_u16();
        if status == 401 || status == 403 {
            return Err(AppError::Auth(
                "assistant credential rejected during archive".into(),
            ));
        }
        if !resp.status().is_success() {
            return Err(AppError::Other(format!(
                "transcript endpoint returned HTTP {status}"
            )));
        }
        Ok(())
    }
}
