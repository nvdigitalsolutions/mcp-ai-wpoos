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
pub struct ChatRequest {
    #[serde(skip_serializing_if = "Option::is_none")]
    pub assistant_id: Option<String>,
    pub messages: Vec<ChatMessage>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub stream: Option<bool>,
}

/// Extract plain text from a chat response payload, handling every content
/// shape the plugin has produced across versions.
pub fn extract_reply_text(payload: &serde_json::Value) -> Option<String> {
    let content = payload
        .get("message")
        .and_then(|m| m.get("content"))
        .or_else(|| payload.get("reply"))
        .or_else(|| payload.get("data")?.get("message")?.get("content"))?;

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

/// A transcript record sent to the plugin archive endpoint.
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct TranscriptRecord {
    #[serde(skip_serializing_if = "Option::is_none")]
    pub assistant_id: Option<String>,
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
}
