# Connecting NV oOS Pro assistants to the local dictation server (Remote Sites)

The desktop app can expose its tools (`dictate`, `get_dictation_history`,
`get_dictionary`, `transcribe_meeting`) as a local MCP server so NV oOS Pro
assistants can trigger dictation and read transcript history from inside a
chat. This is the "Resonant MCP registration" equivalent for the NV oOS
ecosystem.

## Status: pending the transport spike (decision record 0002)

rmcp (the official Rust MCP SDK) implements the modern **streamable HTTP**
transport and deliberately ships no legacy 2024-11-05 HTTP+SSE transport. The
NV oOS Pro plugin's MCP clients historically consumed SSE responses. Before
wiring this up end-to-end:

1. Inspect `addons/pro/includes/class-wp-mcp-ai-jetengine-mcp-client.php` and
   `addons/pro/includes/mcp-apps/` to determine which transport revision the
   Remote Sites "MCP Server" connection negotiates.
2. If it speaks streamable HTTP (2025-03-26+): mount rmcp's
   `StreamableHttpService` on a localhost tower/axum server in the app
   (`src-tauri/src/mcp/`), enable it in Settings, and follow the Remote Sites
   recipe in `.agents/skills/design-elementor-mcp-connection/SKILL.md`.
3. If it requires legacy HTTP+SSE: implement the small bridge module
   (`mcp/bridge_legacy_sse.rs`) per the oxml-mcp PR #57 pattern.

## Recipe (target state)

1. Install and start **NV oOS Dictation** (it must stay running).
2. In Settings → NV oOS, confirm the app is connected to the site.
3. In the plugin: **Pro → Remote Sites → Add** → type "MCP Server", URL
   `http://localhost:<port>/mcp` (streamable HTTP).
4. **Security Center → Host allowlist**: add `localhost` (the allowlist
   rejects non-allowlisted hosts with 403).
5. Test Connection / Discover Tools in the Remote Sites screen; the four
   tools above should appear.
6. In an assistant chat, ask it to "dictate a note" — the app shows the
   overlay, the assistant receives the cleaned transcript.

## Security posture

- The server binds `127.0.0.1` only; no LAN exposure.
- Tools return text only — never audio, never credentials.
- `dictate` requires a human at the microphone by design (PTT is physical).
