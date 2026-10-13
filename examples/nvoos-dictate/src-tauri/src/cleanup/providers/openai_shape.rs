//! Shared OpenAI-compatible transport shape for Stage-2 polish providers.
//!
//! Both `openai_compat` and `lm_studio` speak the same `/v1/chat/completions`
//! request/response dialect. This module owns the *provider-agnostic* bits —
//! the system prompt, the request body builder, and the defensive response
//! parser — so each provider only declares what genuinely differs from the
//! other (temperature, model omission, auth, error mapping).

use serde_json::{json, Value};

use crate::config::AppCategory;
use crate::error::{AppError, AppResult};

/// Dictation-editor system prompt for a target-app category. Provider-agnostic.
pub fn system_prompt_for(category: AppCategory) -> &'static str {
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

/// Build a `/v1/chat/completions` request body. `model` is omitted entirely
/// when `None` (LM Studio serves its single loaded model in that case).
pub fn build_chat_body(
    model: Option<&str>,
    text: &str,
    category: AppCategory,
    temperature: f64,
) -> Value {
    let system = system_prompt_for(category);
    let mut body = json!({
        "messages": [
            { "role": "system", "content": system },
            { "role": "user", "content": text }
        ],
        "temperature": temperature
    });
    if let Some(model) = model {
        body["model"] = json!(model);
    }
    body
}

/// System prompt for the Extended-Cognition summarization of a transcript.
pub fn summarize_prompt() -> &'static str {
    "You are a meeting/media summarizer. Given a transcript, produce a concise \
     structured summary as JSON with these keys only: \"headline\" (one sentence), \
     \"key_points\" (array of short strings), \"action_items\" (array of objects with \
     a \"text\" field), and \"timeline\" (array of objects with a \"note\" field). \
     Preserve names, numbers and technical terms exactly. Return only the JSON object, \
     no preamble."
}

/// Build a chat-completions body with an explicit system prompt (for
/// summarization, where the category-based editor prompt does not apply).
pub fn build_chat_body_with_system(
    model: Option<&str>,
    text: &str,
    system: &str,
    temperature: f64,
) -> Value {
    let mut body = json!({
        "messages": [
            { "role": "system", "content": system },
            { "role": "user", "content": text }
        ],
        "temperature": temperature
    });
    if let Some(model) = model {
        body["model"] = json!(model);
    }
    body
}

/// Extract the assistant message text from a `/v1/chat/completions` response,
/// defensively accepting a string or a list of content blocks.
pub fn parse_chat_response(payload: &Value) -> AppResult<String> {
    let content = &payload["choices"][0]["message"]["content"];
    match content {
        Value::String(s) => Ok(s.trim().to_string()),
        Value::Array(items) => {
            let mut out = String::new();
            for item in items {
                match item {
                    Value::String(s) => out.push_str(s),
                    Value::Object(map) => {
                        if let Some(t) = map.get("text").and_then(|v| v.as_str()) {
                            out.push_str(t);
                        }
                    }
                    _ => {}
                }
            }
            if out.trim().is_empty() {
                Err(AppError::Other(
                    "provider returned an empty response".into(),
                ))
            } else {
                Ok(out.trim().to_string())
            }
        }
        _ => Err(AppError::Other(
            "provider returned an empty response".into(),
        )),
    }
}

/// System prompt for the vision label-polish step (Proposal 076): name the
/// object in the supplied crop, returning a compact JSON object. The bright
/// line is explicit and non-negotiable: objects/assets only, never people.
#[cfg(feature = "vision")]
pub fn vision_polish_prompt() -> &'static str {
    "You are a precise object recognizer. The supplied image is a cropped region \
     of a live webcam frame. Identify the single most prominent object visible. \
     Return ONLY a JSON object with exactly two keys: \"label\" (a short, \
     common-name noun phrase, lowercase, e.g. \"coffee mug\", \"ballpoint pen\", \
     \"notebook\") and \"confidence\" (a number 0 to 1). Name objects and \
     physical assets only — never identify, describe, or classify people's faces, \
     bodies, or identity. If the crop is empty or unrecognizable, return \
     {\"label\":\"unknown\",\"confidence\":0}."
}

/// Build a multimodal chat-completions body: a text instruction plus a single
/// base64 data-URI image. `model` is omitted when `None` (LM Studio serves its
/// single loaded model in that case).
#[cfg(feature = "vision")]
pub fn build_vision_body(
    model: Option<&str>,
    prompt: &str,
    image_data_uri: &str,
    temperature: f64,
) -> Value {
    let mut body = json!({
        "messages": [
            { "role": "user", "content": [
                { "type": "text", "text": prompt },
                { "type": "image_url", "image_url": { "url": image_data_uri } }
            ]}
        ],
        "temperature": temperature
    });
    if let Some(model) = model {
        body["model"] = json!(model);
    }
    body
}

/// Parse a vision-polish reply into `(label, confidence)`. Tolerates a
/// model that wraps the JSON in fences or prose: the first `{…}` block is
/// extracted and its `label`/`confidence` keys read defensively.
#[cfg(feature = "vision")]
pub fn parse_label_response(raw: &str) -> (String, f32) {
    let trimmed = raw.trim();
    // Try the whole reply first, then the first balanced {…} span (fenced
    // code blocks are the common verbose-model failure mode).
    if let Some(v) = try_parse_label(trimmed) {
        return v;
    }
    if let (Some(start), Some(end)) = (trimmed.find('{'), trimmed.rfind('}')) {
        if end > start {
            if let Some(v) = try_parse_label(&trimmed[start..=end]) {
                return v;
            }
        }
    }
    // No JSON at all: fall back to the raw text as the label.
    let label = trimmed
        .split_whitespace()
        .take(4)
        .collect::<Vec<_>>()
        .join(" ");
    (
        if label.is_empty() {
            "unknown".into()
        } else {
            label
        },
        0.5,
    )
}

#[cfg(feature = "vision")]
fn try_parse_label(json_fragment: &str) -> Option<(String, f32)> {
    let v: Value = serde_json::from_str(json_fragment).ok()?;
    let label = v
        .get("label")
        .and_then(|x| x.as_str())
        .map(str::trim)
        .filter(|s| !s.is_empty())?
        .to_string();
    let confidence = v
        .get("confidence")
        .and_then(|x| x.as_f64())
        .unwrap_or(0.5)
        .clamp(0.0, 1.0) as f32;
    Some((label, confidence))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn omits_model_when_none() {
        let body = build_chat_body(None, "hi", AppCategory::Default, 0.1);
        assert!(body.get("model").is_none());
        assert_eq!(body["temperature"], 0.1);
    }

    #[test]
    fn includes_model_when_some() {
        let body = build_chat_body(Some("qwen2.5-7b-instruct"), "hi", AppCategory::Default, 0.1);
        assert_eq!(body["model"], "qwen2.5-7b-instruct");
    }

    #[test]
    fn parses_string_content() {
        let payload = json!({ "choices": [{ "message": { "content": "Hello." } }] });
        assert_eq!(parse_chat_response(&payload).unwrap(), "Hello.");
    }

    #[test]
    fn parses_segment_content() {
        let payload = json!({
            "choices": [{ "message": { "content": [
                { "type": "text", "text": "Hello " },
                { "type": "text", "text": "world." }
            ] } }]
        });
        assert_eq!(parse_chat_response(&payload).unwrap(), "Hello world.");
    }

    #[test]
    fn empty_content_is_error() {
        let payload = json!({ "choices": [{ "message": { "content": null } }] });
        assert!(parse_chat_response(&payload).is_err());
    }

    #[cfg(feature = "vision")]
    mod vision {
        use super::*;

        #[test]
        fn vision_body_is_multimodal_image_url() {
            let body = build_vision_body(
                Some("qwen2.5-vl-7b-instruct"),
                "name it",
                "data:image/png;base64,AAA",
                0.0,
            );
            assert_eq!(body["model"], "qwen2.5-vl-7b-instruct");
            let content = &body["messages"][0]["content"];
            assert!(content.is_array());
            let items = content.as_array().unwrap();
            assert_eq!(items.len(), 2);
            assert_eq!(items[0]["type"], "text");
            assert_eq!(items[1]["type"], "image_url");
            assert_eq!(items[1]["image_url"]["url"], "data:image/png;base64,AAA");
        }

        #[test]
        fn vision_body_omits_model_when_none() {
            let body = build_vision_body(None, "name it", "data:image/png;base64,AAA", 0.0);
            assert!(body.get("model").is_none());
        }

        #[test]
        fn label_parser_reads_plain_json() {
            let (label, conf) = parse_label_response(r#"{"label":"coffee mug","confidence":0.91}"#);
            assert_eq!(label, "coffee mug");
            assert!((conf - 0.91).abs() < 1e-4);
        }

        #[test]
        fn label_parser_tolerates_fenced_json() {
            let raw = "Here is my answer:\n```json\n{\"label\":\"ballpoint pen\",\"confidence\":0.77}\n```";
            let (label, conf) = parse_label_response(raw);
            assert_eq!(label, "ballpoint pen");
            assert!((conf - 0.77).abs() < 1e-4);
        }

        #[test]
        fn label_parser_falls_back_to_raw_text() {
            let (label, conf) = parse_label_response("it is a stapler");
            assert!(label.contains("stapler"));
            assert!(conf > 0.0);
        }
    }
}
