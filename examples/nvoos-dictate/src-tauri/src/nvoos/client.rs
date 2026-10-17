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
use crate::nvoos::types::{ChatMessage, ChatOptions, ChatRequest, ContentSegment};

/// Result of the settings-wizard connection test.
#[derive(Debug, Clone, serde::Serialize)]
pub struct ConnectionTest {
    pub ok: bool,
    pub message: String,
    /// True when the credential was accepted (authorization confirmed).
    pub authenticated: bool,
}

/// A single hosted SPA surface reported by the site's discovery endpoint.
#[derive(Debug, Clone, serde::Serialize, serde::Deserialize)]
pub struct SpaSurface {
    pub id: String,
    pub title: String,
    pub url: String,
    #[serde(default)]
    pub description: String,
    /// Optional hash-route fragment for surfaces that live inside the hosted
    /// Pro SPA routes (e.g. `#/knowledge-graph`). The client prepends its own
    /// SPA base URL to resolve the real iframe target.
    #[serde(default)]
    pub route: String,
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
    /// Returns the polished text plus the metadata the site reported
    /// (resolved assistant id, chat session key, answering model/provider).
    pub async fn polish(
        &self,
        text: &str,
        category: AppCategory,
        cleanup_prompt: &str,
        assistant_id: Option<&str>,
        polish_provider: Option<&str>,
        polish_model: Option<&str>,
    ) -> AppResult<(String, crate::cleanup::providers::PolishMeta)> {
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

        let provider = polish_provider
            .map(str::trim)
            .filter(|p| !p.is_empty())
            .map(|p| p.to_string());
        let model = polish_model
            .map(str::trim)
            .filter(|m| !m.is_empty())
            .map(|m| m.to_string());
        // Omit the options object entirely when neither override is set.
        let options = if provider.is_none() && model.is_none() {
            None
        } else {
            Some(ChatOptions { provider, model })
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
            options,
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
        let text = crate::nvoos::types::extract_reply_text(&payload).ok_or_else(|| {
            AppError::Other("chat endpoint returned an unparseable response".into())
        })?;
        let meta = crate::nvoos::types::extract_polish_meta(&payload);
        Ok((text, meta))
    }

    /// Discover the SPA surfaces (hosted NV oOS addons) active on the site.
    ///
    /// Authenticated against `GET /wp-json/mcp-ai-pro/v1/spa-surfaces` with the
    /// assistant credential. The site is the source of truth for whether an
    /// addon is installed-and-activated; a surface is only returned when its
    /// addon is genuinely active server-side. A 4xx/5xx (including a site
    /// without the Pro endpoint) yields an empty list rather than an error so
    /// the NV oOS tab degrades gracefully to the legacy single-SPA view.
    pub async fn list_spa_surfaces(&self) -> Vec<SpaSurface> {
        let url = format!("{}/wp-json/mcp-ai-pro/v1/spa-surfaces", self.base);
        let resp = match self
            .http
            .get(&url)
            .bearer_auth(&self.credential)
            .send()
            .await
        {
            Ok(r) => r,
            Err(_) => return Vec::new(),
        };
        if !resp.status().is_success() {
            return Vec::new();
        }
        match resp.json::<serde_json::Value>().await {
            Ok(payload) => payload
                .get("surfaces")
                .and_then(serde_json::Value::as_array)
                .map(|rows| {
                    rows.iter()
                        .filter_map(|row| serde_json::from_value::<SpaSurface>(row.clone()).ok())
                        .collect()
                })
                .unwrap_or_default(),
            Err(_) => Vec::new(),
        }
    }

    /// One-way archive of a transcript into the plugin's transcript store.
    pub async fn archive_transcript(
        &self,
        record: &crate::nvoos::TranscriptRecord,
    ) -> AppResult<()> {
        let body = json!({
            "assistant_id": record.assistant_id,
            "session_key": record.session_key,
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

#[cfg(test)]
mod tests {
    use super::SpaSurface;

    #[test]
    fn spa_surface_deserializes_with_defaulted_description() {
        let v: SpaSurface = serde_json::from_value(serde_json::json!({
            "id": "assistant",
            "title": "Assistant",
            "url": "https://example.com/"
        }))
        .unwrap();
        assert_eq!(v.id, "assistant");
        assert_eq!(v.title, "Assistant");
        assert_eq!(v.description, "");
        assert_eq!(v.route, "");
    }

    #[test]
    fn spa_surface_reads_description_when_present() {
        let v: SpaSurface = serde_json::from_value(serde_json::json!({
            "id": "chat-spa",
            "title": "Chat",
            "url": "https://example.com/",
            "description": "a chat surface"
        }))
        .unwrap();
        assert_eq!(v.description, "a chat surface");
    }

    #[test]
    fn spa_surface_reads_route_fragment_when_present() {
        let v: SpaSurface = serde_json::from_value(serde_json::json!({
            "id": "content-graph",
            "title": "Content Graph",
            "url": "https://example.com/",
            "route": "#/knowledge-graph"
        }))
        .unwrap();
        assert_eq!(v.route, "#/knowledge-graph");
    }
}
