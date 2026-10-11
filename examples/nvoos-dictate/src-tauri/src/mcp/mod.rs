//! Local MCP server (feature `mcp`).
//!
//! Exposes the dictation app to AI clients:
//! - **stdio transport** — the standard for local MCP servers spawned by
//!   desktop clients (Claude Desktop, Cursor, Zed…).
//! - **streamable HTTP** — for the NV oOS Pro plugin's Remote Sites "MCP
//!   Server" connections (localhost host allowlisted).
//!
//! The legacy 2024-11-05 HTTP+SSE transport is deliberately NOT provided by
//! rmcp; `bridge_legacy_sse` documents the fallback if the plugin's Pro MCP
//! client turns out to require it (transport spike, decision record 0002).

mod bridge_legacy_sse;
mod server;
pub mod tools;

pub use server::{serve_stdio, DictateToolbox};
