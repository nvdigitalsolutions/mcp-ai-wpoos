# MCP Protocol Version Negotiation

**Version:** 1.1.52+
**Category:** Protocol — Core
**Implemented in:** `WP_MCP_AI_REST_MCP_Methods`, `WP_MCP_AI_Toolkit_MCP_REST_Controller`
**Files changed:** 2 files, +123 lines (PR #5829)

## Overview

The MCP (Model Context Protocol) server now performs **protocol version negotiation** with connecting clients instead of requiring a specific hardcoded version. This resolves compatibility issues where older clients (Zed editor, Claude Desktop, Cursor) rejected the `2026-07-28` protocol version that NV oOS previously required.

## Problem

Before v1.1.52, the MCP server hardcoded `2026-07-28` as its protocol version. This caused connection failures with:

- **Zed editor** — expects `2024-11-05`
- **Claude Desktop** — expects `2024-11-05`
- **Cursor** — expects `2024-11-05`
- **Other older MCP clients** — may not support `2026-07-28`

Clients receiving an unsupported version in the server's `initialize` response would abort the connection, making NV oOS unusable with these tools.

## Solution

The server now negotiates the highest protocol version supported by **both** the server and the client:

```
Client connects → sends client protocol version (or none)
                    │
Server responds →  highest_version ∩ client_versions
                    │
                    ├─ Client provides versions → negotiate
                    │   e.g., client: [2024-11-05, 2025-03-26]
                    │         server: [2026-07-28, 2025-03-26, 2024-11-05]
                    │         → negotiated: 2025-03-26
                    │
                    └─ Client provides no version → fallback to 2024-11-05
```

## Supported Versions

| Version | Status | Notes |
|---------|--------|-------|
| `2026-07-28` | Current (preferred) | Stateless core, `server/discover`, `_meta` headers, Streamable HTTP |
| `2025-03-26` | Supported | Intermediate spec with task-oriented features |
| `2024-11-05` | Fallback (default) | Original MCP spec, widest client compatibility |

## Client Compatibility Matrix

| Client | Default Version | Negotiation Result | Status |
|--------|----------------|-------------------|--------|
| Claude Desktop | `2024-11-05` | `2024-11-05` | ✅ Compatible |
| Zed editor | `2024-11-05` | `2024-11-05` | ✅ Compatible |
| Cursor | `2024-11-05` | `2024-11-05` | ✅ Compatible |
| NV oOS SPA | `2026-07-28` | `2026-07-28` | ✅ Full features |
| Unknown client (no version) | — | `2024-11-05` | ✅ Safe fallback |

## Implementation Details

### `WP_MCP_AI_REST_MCP_Methods` (77 lines changed)

- `negotiate_protocol_version()` — new method that accepts the client's declared versions and returns the highest mutually-supported version.
- `get_supported_versions()` — returns the ordered list of versions the server supports (newest first).
- Updated `initialize()` / `server_discover()` handlers to call negotiation before constructing responses.
- Legacy shim: when a client sends `initialize` (2024-11-05 style), the server responds in 2024-11-05 format.

### `WP_MCP_AI_Toolkit_MCP_REST_Controller` (49 lines changed)

- Added version negotiation to the per-toolkit MCP server endpoints.
- Each toolkit MCP server independently negotiates with its connecting client.
- Toolkit servers support the same version range as the main MCP server.

## Behavior by Client

### Modern Clients (2026-07-28)

Clients that declare `2026-07-28` support get:
- Stateless `server/discover` instead of `initialize`
- `_meta` per-request capability declarations
- `Mcp-Method` / `Mcp-Name` headers on Streamable HTTP
- TTL and cache-scope on `tools/list` responses

### Legacy Clients (2024-11-05)

Clients that declare only `2024-11-05` get:
- Traditional `initialize` handshake
- Session-based state management
- Standard `tools/list` without TTL annotations
- Full backward compatibility with the original MCP spec

### Unknown Clients (no version)

Clients that provide no protocol version information (e.g., simple HTTP clients) default to `2024-11-05` for maximum compatibility.

## Troubleshooting

| Symptom | Likely Cause | Solution |
|---------|-------------|---------|
| "Unsupported protocol version" | Client version too old or too new | Server will negotiate; check client logs for version sent |
| Missing features after connection | Negotiated older protocol version | Client may need to update to support `2026-07-28` |
| Per-toolkit MCP server connection fails | Toolkit server version mismatch | Same negotiation applies; check both client and toolkit server versions |

## Related

- [PR #5829: MCP protocol version negotiation](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/pull/5829)
- [MCP Specification 2026-07-28](https://spec.modelcontextprotocol.io/)
- [MCP Specification 2024-11-05](https://spec.modelcontextprotocol.io/specification/2024-11-05/)
