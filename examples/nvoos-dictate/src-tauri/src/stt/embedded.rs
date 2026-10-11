//! Embedded (plugin) remote STT — the fallback engine for machines without
//! local models (ADR 0003).
//!
//! Sends 16-bit PCM WAV to the NV oOS Embedded addon's REST route
//! `POST /wp-json/mcp-ai/v1/embedded/transcribe`, which proxies to a Gemma 4
//! audio endpoint configured on the user's own WordPress host:
//!   - ≤ 10 MB audio, 30 req/min rate limit, 60 s timeout (server-side).
//!   - Auth: WP Application Password (Basic) preferred — the route admits
//!     logged-in users; the assistant credential (Bearer) works once the
//!     addon's `permission_callback` accepts it (small addon PR tracked in
//!     the implementation plan).
//!
//! This module ships in the default build (reqwest is a base dependency), so
//! the remote fallback is available even without the native engine features.

use base64::engine::general_purpose::STANDARD as BASE64;
use base64::Engine as _;
use reqwest::blocking::Client;
use serde::Deserialize;
use serde_json::json;

use crate::audio::wav::encode_f32_to_wav;
use crate::error::{AppError, AppResult};
use crate::stt::engine::SttEngine;

/// Server-side limit from `WP_MCP_AI_Embedded_Transcribe::MAX_AUDIO_SIZE`.
const MAX_AUDIO_BYTES: usize = 10 * 1024 * 1024;

/// Authentication for the Embedded addon route.
#[derive(Clone)]
pub enum EmbeddedAuth {
    /// WP Application Password (preferred — satisfies `is_user_logged_in()`).
    Basic { username: String, password: String },
    /// Assistant credential (`cred_x.SECRET`) — requires the addon
    /// permission-callback tweak to be accepted.
    Bearer(String),
}

impl EmbeddedAuth {
    /// Prefer Basic (application password); fall back to Bearer.
    pub fn from_parts(wp: Option<(&str, &str)>, credential: Option<&str>) -> AppResult<Self> {
        if let Some((username, password)) = wp {
            if !username.is_empty() && !password.is_empty() {
                return Ok(Self::Basic {
                    username: username.to_string(),
                    password: password.to_string(),
                });
            }
        }
        if let Some(credential) = credential {
            if !credential.is_empty() {
                return Ok(Self::Bearer(credential.to_string()));
            }
        }
        Err(AppError::NotConfigured(
            "embedded STT needs a WP application password (Settings > NV oOS) or an assistant \
             credential"
                .into(),
        ))
    }
}

/// Build the request URL for the transcribe route.
pub fn endpoint(base: &str) -> String {
    format!(
        "{}/wp-json/mcp-ai/v1/embedded/transcribe",
        base.trim_end_matches('/')
    )
}

/// Build the JSON request body. The `model` parameter is intentionally
/// omitted so the server default (`gemma4:e4b`) applies.
pub fn request_body(audio_base64: &str, language: Option<&str>) -> serde_json::Value {
    json!({
        "audio": audio_base64,
        "language": language.unwrap_or("en"),
    })
}

/// Server response: only `text` is required; the addon also returns
/// `language` and `unified_response` (unused here).
#[derive(Debug, Deserialize)]
struct TranscribeResponse {
    text: String,
}

/// Parse the JSON body into transcript text.
pub fn parse_response(body: &str) -> AppResult<String> {
    let parsed: TranscribeResponse = serde_json::from_str(body)?;
    Ok(parsed.text.trim().to_string())
}

pub struct EmbeddedEngine {
    http: Client,
    base: String,
    auth: EmbeddedAuth,
    model: String,
}

impl EmbeddedEngine {
    pub fn new(site_url: &str, auth: EmbeddedAuth) -> AppResult<Self> {
        if site_url.trim().is_empty() {
            return Err(AppError::NotConfigured(
                "embedded STT needs the NV oOS site URL (Settings > NV oOS)".into(),
            ));
        }
        let http = Client::builder()
            .user_agent(concat!("nvoos-dictate/", env!("CARGO_PKG_VERSION")))
            .build()?;
        Ok(Self {
            http,
            base: site_url.trim().trim_end_matches('/').to_string(),
            auth,
            // The server-side model id is not selectable per request by
            // default; keep the configured id for status reporting.
            model: "embedded (plugin Gemma 4 audio endpoint)".to_string(),
        })
    }

    fn post(&self, body: serde_json::Value) -> reqwest::blocking::RequestBuilder {
        let builder = self.http.post(endpoint(&self.base)).json(&body);
        match &self.auth {
            EmbeddedAuth::Basic { username, password } => {
                builder.basic_auth(username, Some(password))
            }
            EmbeddedAuth::Bearer(credential) => builder.bearer_auth(credential),
        }
    }
}

impl SttEngine for EmbeddedEngine {
    fn id(&self) -> &'static str {
        "embedded"
    }

    fn model_id(&self) -> &str {
        &self.model
    }

    fn transcribe(
        &self,
        audio: &[f32],
        sample_rate: u32,
        language: Option<&str>,
    ) -> AppResult<String> {
        if audio.is_empty() {
            return Ok(String::new());
        }
        let wav = encode_f32_to_wav(audio, sample_rate);
        if wav.len() > MAX_AUDIO_BYTES {
            return Err(AppError::Model(format!(
                "audio is {} MB — the Embedded addon accepts at most 10 MB per request",
                wav.len() / (1024 * 1024)
            )));
        }
        let body = request_body(&BASE64.encode(&wav), language);
        let resp = self.post(body).send()?;
        let status = resp.status().as_u16();
        let text = resp.text()?;
        match status {
            200 | 201 => parse_response(&text),
            401 | 403 => Err(AppError::Auth(
                "the site rejected the credential for embedded STT — use a WP application \
                 password, or enable assistant-credential support in the Embedded addon"
                    .into(),
            )),
            413 => Err(AppError::Model(
                "the site rejected the audio as too large (413)".into(),
            )),
            429 => Err(AppError::Engine(
                "the site rate-limited embedded STT (429) — wait a moment and retry".into(),
            )),
            other => Err(AppError::Engine(format!(
                "embedded STT failed: HTTP {other}: {}",
                text.chars().take(300).collect::<String>()
            ))),
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn endpoint_is_the_addon_route() {
        assert_eq!(
            endpoint("https://example.com/"),
            "https://example.com/wp-json/mcp-ai/v1/embedded/transcribe"
        );
    }

    #[test]
    fn body_carries_audio_and_language_not_model() {
        let body = request_body("aGVsbG8=", Some("en"));
        assert_eq!(body["audio"], "aGVsbG8=");
        assert_eq!(body["language"], "en");
        assert!(body.get("model").is_none(), "server default applies");
        assert_eq!(request_body("x", None)["language"], "en");
    }

    #[test]
    fn parses_valid_and_trimmed_text() {
        assert_eq!(
            parse_response(r#"{"text": "  hello world  "}"#).unwrap(),
            "hello world"
        );
        assert!(parse_response(r#"{"language":"en"}"#).is_err());
        assert!(parse_response("not json").is_err());
    }

    #[test]
    fn auth_prefers_basic_then_bearer_then_errors() {
        let basic = EmbeddedAuth::from_parts(Some(("admin", "pass")), Some("cred_x.SECRET"));
        assert!(matches!(basic, Ok(EmbeddedAuth::Basic { .. })));

        let bearer = EmbeddedAuth::from_parts(None, Some("cred_x.SECRET"));
        assert!(matches!(bearer, Ok(EmbeddedAuth::Bearer(c)) if c == "cred_x.SECRET"));

        assert!(EmbeddedAuth::from_parts(Some(("", "")), None).is_err());
        assert!(EmbeddedAuth::from_parts(None, Some("")).is_err());
        assert!(EmbeddedAuth::from_parts(None, None).is_err());
    }

    #[test]
    fn engine_requires_site_url() {
        assert!(EmbeddedEngine::new("  ", EmbeddedAuth::Bearer("c".into())).is_err());
        assert!(
            EmbeddedEngine::new("https://example.com", EmbeddedAuth::Bearer("c".into())).is_ok()
        );
    }
}
