# Analytics

## Purpose

Analytics and usage analysis for the content-graph AI addon (Wave D3): linear-regression trends, statistical summaries, Z-score anomaly detection, usage-pattern detection, user/tool comparisons, and transcript-based usage rebuilds (`AnalyticsEngine`). The usage tracker, token-tracking stack, and tool token limits (D3d/D3e/D3f) complete the tier- and budget-aware usage layer.

## Tier

| | |
|---|---|
| **Distribution** | Addon plugin (`nvoos-content-graph-ai`) — proprietary |
| **PHP target** | 8.1+ |
| **License** | Proprietary (commercial license required) |
| **Loaded by** | Static utilities + standalone-only hook registration in `Plugin::register()` |
| **Optional dependencies** | base plugin `WP_MCP_AI_Tool_Token_Limits` (monolith), `ToolTokenLimits` port (standalone, D3f) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraphAi\Analytics\AnalyticsEngine` | `AnalyticsEngine.php` | Static utility (analytics consumers land with D3d/D3f) |
| `NvoosContentGraphAi\Analytics\UsageTracker` | `UsageTracker.php` | `Plugin::register()` (standalone-only user-deletion hooks); chat flow records usage |
| `NvoosContentGraphAi\Analytics\TokenTrackingDatabase` | `TokenTrackingDatabase.php` | `Plugin::register()` (standalone-only schema hooks) |
| `NvoosContentGraphAi\Analytics\EnhancedTokenTracking` | `EnhancedTokenTracking.php` | `Plugin::register()` (standalone-only usage/tool hooks + cron) |
| `NvoosContentGraphAi\Analytics\TokenDbOptimizer` | `TokenDbOptimizer.php` | `Plugin::register()` (standalone-only usermeta index maintenance) |
| `NvoosContentGraphAi\Analytics\ToolTokenLimits` | `ToolTokenLimits.php` | `Plugin::register()` (standalone-only usage/tier/cron hooks) |

## Inputs / Outputs / Neighbors

- **Reads from:** user tool-usage meta (`_wp_mcp_ai_tool_token_usage` — base store monolith, ported store standalone via `ToolTokenLimits`), transcript CCT (monolith rebuild path)
- **Writes to:** user meta (rebuild path + usage/tier recording), transients (session accounting, alert throttling)
- **Upstream callers:** static analysis consumers (admin widgets, REST reporting — later waves)
- **Downstream collaborators:** base `WP_MCP_AI_Tool_Token_Limits` (monolith), base `WP_MCP_AI_Transcript_Repository` (monolith rebuild); workload-tier reads default to `medium` standalone until the resource manager ports (tracked in the ecosystem port tracker)
- **Events fired:** `wp_mcp_ai_tool_token_usage_recorded`, `wp_mcp_ai_tool_token_limit_exceeded`, `wp_mcp_ai_per_call_limit_exceeded`, `wp_mcp_ai_per_session_limit_exceeded`, `wp_mcp_ai_session_limit_approaching`, `wp_mcp_ai_usage_anomaly_detected`, `wp_mcp_ai_limit_alert_sent`, `wp_mcp_ai_user_tier_changed`
- **Events listened to:** `wp_mcp_ai_daily_cleanup`, `wp_mcp_ai_after_tool_execution`, `wp_mcp_ai_before_tool_execution`, `wp_mcp_ai_hourly_forecast_check`, `wp_mcp_ai_user_tier_changed` (standalone-only)
