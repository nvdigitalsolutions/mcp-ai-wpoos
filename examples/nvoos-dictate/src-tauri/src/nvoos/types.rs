//! Request/response types matching the plugin's REST contract.
//!
//! Message content is stored/validated as **segments**:
//! `[{ "type": "text", "text": "..." }]` (see the test-suite skill's
//! transcript notes). Parsing is defensive on the way back: the chat
//! response's `message.content` may arrive as a string, a list of strings,
//! or a list of segment objects.

use serde::{Deserialize, Serialize};

#[derive(Debug, Clone, Serialize)]
pub struct ContentSegment {
    #[serde(rename = "type")]
    pub kind: String,
    pub text: String,
}

impl ContentSegment {
    pub fn text(text: impl Into<String>) -> Self {
        Self {
            kind: "text".into(),
            text: text.into(),
        }
    }
}

#[derive(Debug, Clone, Serialize)]
pub struct ChatMessage {
    pub role: String,
    pub content: Vec<ContentSegment>,
}

#[derive(Debug, Clone, Serialize)]
pub struct ChatOptions {
    /// Per-request provider override (blank = the assistant's provider).
    #[serde(skip_serializing_if = "Option::is_none")]
    pub provider: Option<String>,
    /// Per-request model override (blank = the assistant's configured model).
    #[serde(skip_serializing_if = "Option::is_none")]
    pub model: Option<String>,
}

#[derive(Debug, Clone, Serialize)]
pub struct ChatRequest {
    #[serde(skip_serializing_if = "Option::is_none")]
    pub assistant_id: Option<String>,
    pub messages: Vec<ChatMessage>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub options: Option<ChatOptions>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub stream: Option<bool>,
}

/// Extract plain text from a chat response payload, handling every content
/// shape the plugin has produced across versions.
pub fn extract_reply_text(payload: &serde_json::Value) -> Option<String> {
    // v1.1.x: { message: { content } } or { reply: ... }
    if let Some(text) = payload
        .get("message")
        .and_then(|m| m.get("content"))
        .and_then(content_to_text)
    {
        return Some(text);
    }
    if let Some(text) = payload.get("reply").and_then(content_to_text) {
        return Some(text);
    }
    // v1.2.x: { data: { content } } and { data: { choices: [{ message: { content } }] } }
    if let Some(data) = payload.get("data") {
        if let Some(text) = data.get("content").and_then(content_to_text) {
            return Some(text);
        }
        if let Some(text) = data
            .get("choices")
            .and_then(|c| c.get(0))
            .and_then(|c| c.get("message"))
            .and_then(|m| m.get("content"))
            .and_then(content_to_text)
        {
            return Some(text);
        }
    }
    // Legacy: { data: { message: { content } } }
    payload
        .get("data")
        .and_then(|d| d.get("message"))
        .and_then(|m| m.get("content"))
        .and_then(content_to_text)
}

/// Coerce a content field (string, list of strings, or segment objects)
/// into plain text.
fn content_to_text(content: &serde_json::Value) -> Option<String> {
    match content {
        serde_json::Value::String(s) => Some(s.clone()),
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
            if out.is_empty() {
                None
            } else {
                Some(out)
            }
        }
        _ => None,
    }
}

/// Metadata the v1.2.x chat response carries: the resolved assistant id and
/// session key (needed by the transcript archive route) plus the model and
/// provider that answered (observability for model tuning).
pub fn extract_polish_meta(payload: &serde_json::Value) -> crate::cleanup::providers::PolishMeta {
    use crate::cleanup::providers::PolishMeta;
    let str_or_num = |key: &str| -> Option<String> {
        match payload.get(key) {
            Some(serde_json::Value::String(s)) => Some(s.clone()),
            Some(serde_json::Value::Number(n)) => Some(n.to_string()),
            _ => None,
        }
    };
    let data_str = |key: &str| -> Option<String> {
        payload
            .get("data")
            .and_then(|d| d.get(key))
            .and_then(|v| v.as_str())
            .map(|s| s.to_string())
    };
    PolishMeta {
        assistant_id: str_or_num("assistant_id"),
        session_key: str_or_num("sessionKey"),
        model: data_str("model"),
        provider: data_str("provider"),
    }
}

/// A transcript record sent to the plugin archive endpoint.
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct TranscriptRecord {
    #[serde(skip_serializing_if = "Option::is_none")]
    pub assistant_id: Option<String>,
    /// Chat session key from the last polish call — the transcript archive
    /// route rejects requests without it.
    #[serde(skip_serializing_if = "Option::is_none")]
    pub session_key: Option<String>,
    pub title: String,
    pub raw: String,
    pub clean: String,
    pub target_app: String,
    pub engine: String,
    pub e2e_ms: i64,
    pub created_at: String,
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn extracts_string_content() {
        let payload = serde_json::json!({ "success": true, "message": { "role": "assistant", "content": "Hello." } });
        assert_eq!(extract_reply_text(&payload).as_deref(), Some("Hello."));
    }

    #[test]
    fn extracts_segment_content() {
        let payload = serde_json::json!({
            "success": true,
            "message": { "role": "assistant", "content": [ { "type": "text", "text": "Hello " }, { "type": "text", "text": "world." } ] }
        });
        assert_eq!(
            extract_reply_text(&payload).as_deref(),
            Some("Hello world.")
        );
    }

    #[test]
    fn extracts_string_array_content() {
        let payload = serde_json::json!({ "message": { "content": ["a", "b"] } });
        assert_eq!(extract_reply_text(&payload).as_deref(), Some("ab"));
    }

    #[test]
    fn missing_content_is_none() {
        assert_eq!(extract_reply_text(&serde_json::json!({})), None);
    }

    #[test]
    fn chat_request_serializes_model_override() {
        let request = ChatRequest {
            assistant_id: Some("12534".into()),
            messages: vec![],
            options: Some(ChatOptions {
                provider: Some("gemini".into()),
                model: Some("deepseek-v4-pro".into()),
            }),
            stream: Some(false),
        };
        let value = serde_json::to_value(&request).unwrap();
        assert_eq!(value["options"]["model"], "deepseek-v4-pro");
        assert_eq!(value["options"]["provider"], "gemini");

        let no_override = ChatRequest {
            assistant_id: None,
            messages: vec![],
            options: Some(ChatOptions {
                provider: None,
                model: None,
            }),
            stream: None,
        };
        let value = serde_json::to_value(&no_override).unwrap();
        assert_eq!(value["options"], serde_json::json!({}));
        assert!(value.get("assistant_id").is_none());
    }

    #[test]
    fn extracts_v12_data_and_choices_shapes() {
        // v1.2.x: { data: { content, choices: [{ message: { content } }] }, sessionKey }.
        let payload = serde_json::json!({
            "assistant_id": 12534,
            "data": {
                "choices": [{ "message": { "content": "from choices" } }],
                "content": "from data.content",
                "model": "deepseek-flash",
                "provider": "deepseek"
            },
            "sessionKey": "wp-mcp-ai-0f6a3458"
        });
        // data.content wins (checked before choices).
        assert_eq!(
            extract_reply_text(&payload).as_deref(),
            Some("from data.content")
        );
        let meta = extract_polish_meta(&payload);
        assert_eq!(meta.assistant_id.as_deref(), Some("12534"));
        assert_eq!(meta.session_key.as_deref(), Some("wp-mcp-ai-0f6a3458"));
        assert_eq!(meta.model.as_deref(), Some("deepseek-flash"));
        assert_eq!(meta.provider.as_deref(), Some("deepseek"));

        // choices-only shape, content as segments.
        let payload2 = serde_json::json!({
            "data": {
                "choices": [{ "message": { "content": [ { "type": "text", "text": "seg" } ] } }]
            }
        });
        assert_eq!(extract_reply_text(&payload2).as_deref(), Some("seg"));

        // Unknown shapes stay unparseable.
        assert!(extract_reply_text(&serde_json::json!({ "data": {} })).is_none());
    }
}
