# Analytics

## Purpose

Analytics and usage analysis for the content-graph AI addon (Wave D3): linear-regression trends, statistical summaries, Z-score anomaly detection, usage-pattern detection, user/tool comparisons, and transcript-based usage rebuilds (`AnalyticsEngine`). The usage tracker and token-tracking stack land next (D3d/D3e).

## Tier

| | |
|---|---|
| **Distribution** | Addon plugin (`nvoos-content-graph-ai`) — proprietary |
| **PHP target** | 8.1+ |
| **License** | Proprietary (commercial license required) |
| **Loaded by** | Static utility — no hooks; consumers land with D3d/D3f |
| **Optional dependencies** | base plugin `WP_MCP_AI_Tool_Token_Limits` (monolith), `ToolTokenLimits` port (standalone, D3f) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraphAi\Analytics\AnalyticsEngine` | `AnalyticsEngine.php` | Static utility (analytics consumers land with D3d/D3f) |

## Inputs / Outputs / Neighbors

- **Reads from:** user tool-usage meta (`_wp_mcp_ai_tool_token_usage` — base store monolith, ported store standalone once D3f lands), transcript CCT (monolith rebuild path)
- **Writes to:** user meta (rebuild path only)
- **Upstream callers:** static analysis consumers (admin widgets, REST reporting — later waves)
- **Downstream collaborators:** `ToolTokenLimits` (D3f), base `WP_MCP_AI_Transcript_Repository` (monolith rebuild)
- **Events fired:** None
- **Events listened to:** None
