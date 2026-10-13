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
}
