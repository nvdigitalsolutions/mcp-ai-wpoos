//! MCP tool handler tests (feature `mcp`) — the toolbox is tested directly
//! without a live rmcp transport, per the plan's test strategy.

#![cfg(feature = "mcp")]

use std::sync::Arc;

use nvoos_dictate_lib::error::AppResult;
use nvoos_dictate_lib::mcp::tools::Toolbox;
use nvoos_dictate_lib::meetings::recorder::MeetingRecorder;
use nvoos_dictate_lib::storage::Db;

fn toolbox() -> Toolbox {
    let db = Arc::new(Db::open_in_memory().unwrap());
    db.insert_transcript(
        "raw",
        "cleaned hello",
        "mock",
        "mock",
        "chrome",
        "disabled",
        42,
    )
    .unwrap();
    db.set_dictionary(&[("myco".to_string(), "MyCo".to_string())])
        .unwrap();
    let recorder = Arc::new(MeetingRecorder::new(16000));
    let dictate =
        Arc::new(|_hint: Option<String>| -> AppResult<String> { Ok("dictated text".to_string()) });
    Toolbox {
        db,
        recorder,
        dictate,
    }
}

#[test]
fn dictate_tool_returns_text() {
    let tb = toolbox();
    assert_eq!(
        tb.tool_dictate(Some("email".into())).unwrap(),
        "dictated text"
    );
}

#[test]
fn history_tool_returns_json_rows() {
    let tb = toolbox();
    let json = tb.tool_history(String::new(), 25).unwrap();
    let parsed: serde_json::Value = serde_json::from_str(&json).unwrap();
    let rows = parsed.as_array().unwrap();
    assert_eq!(rows.len(), 1);
    assert_eq!(rows[0]["text"], "cleaned hello");
    assert_eq!(rows[0]["target_app"], "chrome");
    assert_eq!(rows[0]["e2e_ms"], 42);
}

#[test]
fn history_tool_honors_search_and_limit() {
    let tb = toolbox();
    assert_eq!(tb.tool_history("missing".to_string(), 25).unwrap(), "[]");
    let json = tb.tool_history("hello".to_string(), 1).unwrap();
    let parsed: serde_json::Value = serde_json::from_str(&json).unwrap();
    assert_eq!(parsed.as_array().unwrap().len(), 1);
}

#[test]
fn dictionary_tool_returns_entries() {
    let tb = toolbox();
    let json = tb.tool_dictionary().unwrap();
    assert_eq!(json, "[\n  [\n    \"myco\",\n    \"MyCo\"\n  ]\n]");
}
