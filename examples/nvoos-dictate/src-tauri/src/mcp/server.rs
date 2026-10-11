//! rmcp server wiring (feature `mcp`).
//!
//! API verified against the official rmcp README (3.5.x):
//! `#[tool_router(server_handler)]` + `#[tool]` + `Parameters<T>`, then
//! `.serve(stdio())` / `service.waiting()`.

use std::sync::Arc;

use rmcp::handler::server::wrapper::Parameters;
use rmcp::{tool, tool_router, transport::stdio, ServiceExt};

use crate::error::{AppError, AppResult};
use crate::mcp::tools::Toolbox;
use crate::meetings::recorder::MeetingRecorder;
use crate::storage::db::Db;

#[derive(Debug, serde::Deserialize, rmcp::schemars::JsonSchema)]
pub struct DictateParams {
    /// Optional hint shown to the user before dictation starts.
    #[serde(default)]
    pub hint: Option<String>,
}

#[derive(Debug, serde::Deserialize, rmcp::schemars::JsonSchema)]
pub struct HistoryParams {
    /// Free-text search over cleaned transcripts (empty = all).
    #[serde(default)]
    pub query: String,
    /// Maximum rows to return (1..=100).
    #[serde(default = "default_limit")]
    pub limit: u32,
}

fn default_limit() -> u32 {
    25
}

#[derive(Debug, serde::Deserialize, rmcp::schemars::JsonSchema)]
pub struct MeetingParams {
    /// Title stored with the transcript.
    pub title: String,
    /// Optional max recording duration in seconds (default 3600).
    #[serde(default)]
    pub duration_secs: Option<u64>,
}

/// MCP server service. Cloned per connection; shares state via Arc.
#[derive(Clone)]
pub struct DictateToolbox {
    pub toolbox: Toolbox,
    /// Engine used for meeting transcription (optional in headless mode).
    pub engine: Option<Arc<dyn crate::stt::SttEngine>>,
}

#[tool_router(server_handler)]
impl DictateToolbox {
    /// Trigger a push-to-talk dictation on this computer. In the desktop app
    /// this arms the overlay and waits for the user to speak; in headless
    /// stdio mode it returns a clear error.
    #[tool(
        description = "Trigger a push-to-talk dictation on this computer. Shows the dictation overlay, waits for the user to speak, then returns the cleaned transcript. Requires the desktop app to be running."
    )]
    async fn dictate(
        &self,
        Parameters(args): Parameters<DictateParams>,
    ) -> Result<String, rmcp::ErrorData> {
        self.toolbox
            .tool_dictate(args.hint)
            .map_err(|e| rmcp::ErrorData::internal_error(e.to_string(), None))
    }

    /// Search the local dictation history (text only; never audio).
    #[tool(
        description = "Search the local dictation history. Returns cleaned transcripts with target app, engine and latency metadata."
    )]
    async fn get_dictation_history(
        &self,
        Parameters(args): Parameters<HistoryParams>,
    ) -> Result<String, rmcp::ErrorData> {
        self.toolbox
            .tool_history(args.query, args.limit)
            .map_err(|e| rmcp::ErrorData::internal_error(e.to_string(), None))
    }

    /// Return the user's custom vocabulary (term -> replacement).
    #[tool(
        description = "Return the user's custom dictation vocabulary (term to replacement pairs)."
    )]
    async fn get_dictionary(&self) -> Result<String, rmcp::ErrorData> {
        self.toolbox
            .tool_dictionary()
            .map_err(|e| rmcp::ErrorData::internal_error(e.to_string(), None))
    }

    /// Record and transcribe a meeting (mic + system audio on Windows).
    #[tool(
        description = "Record a meeting for up to duration_secs (mic plus system audio on Windows), transcribe it locally, store the transcript, and return it."
    )]
    async fn transcribe_meeting(
        &self,
        Parameters(args): Parameters<MeetingParams>,
    ) -> Result<String, rmcp::ErrorData> {
        let engine = match self.engine.clone() {
            Some(e) => e,
            None => {
                return Err(rmcp::ErrorData::internal_error(
                    "no speech engine loaded in this mode",
                    None,
                ))
            }
        };
        self.toolbox
            .tool_transcribe_meeting(args.title, args.duration_secs, engine)
            .map_err(|e| rmcp::ErrorData::internal_error(e.to_string(), None))
    }
}

/// Serve the MCP tools over stdio (spawned by desktop AI clients).
pub async fn serve_stdio(
    db: Arc<Db>,
    recorder: Arc<MeetingRecorder>,
    engine: Option<Arc<dyn crate::stt::SttEngine>>,
    dictate: Arc<dyn Fn(Option<String>) -> AppResult<String> + Send + Sync>,
) -> AppResult<()> {
    let toolbox = Toolbox {
        db,
        recorder,
        dictate,
    };
    let service = DictateToolbox { toolbox, engine }
        .serve(stdio())
        .await
        .map_err(|e| AppError::Other(format!("mcp serve: {e}")))?;
    service
        .waiting()
        .await
        .map_err(|e| AppError::Other(format!("mcp waiting: {e}")))?;
    Ok(())
}
