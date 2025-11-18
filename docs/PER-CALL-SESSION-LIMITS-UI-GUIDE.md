# Per-Call and Session Limits - Visual UI Guide

## New Settings Location

**Path**: WordPress Admin → Settings → WP oOS → Orchestration → Thresholds

## UI Sections Added

### Section: Per-Call and Per-Session Limits

```
┌─────────────────────────────────────────────────────────────────────┐
│ Per-Call and Per-Session Limits                                     │
│ ─────────────────────────────────────────────────────────────────── │
│ Set maximum token limits for individual tool calls and chat         │
│ sessions to prevent runaway costs and ensure fair resource          │
│ distribution.                                                        │
└─────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────┐
│ ☑ Enable Per-Call Token Limits                                      │
│ ─────────────────────────────────────────────────────────────────── │
│ Limit the maximum number of tokens a single tool call can consume.  │
│                                                                      │
│ Per-Call Token Limit                                                │
│ Maximum tokens per individual tool call (applies to all tools       │
│ unless overridden). Set to 0 for unlimited.                         │
│                                                                      │
│ 0 ────●─────────────────────────────────────────────────── 100,000  │
│      10,000                                               [step: 1k] │
└─────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────┐
│ ☑ Enable Per-Session Token Limits                                   │
│ ─────────────────────────────────────────────────────────────────── │
│ Limit the total number of tokens a single chat session can consume  │
│ across all tool calls.                                              │
│                                                                      │
│ Per-Session Token Limit                                             │
│ Maximum tokens per chat session (cumulative across all tool calls). │
│ Set to 0 for unlimited.                                             │
│                                                                      │
│ 0 ──────────●───────────────────────────────────────────── 500,000  │
│           50,000                                          [step: 5k] │
└─────────────────────────────────────────────────────────────────────┘
```

## Integration with Existing Settings

The new controls are added to the **Thresholds** tab, after the "Token Limits by Workload Tier" section:

```
Orchestration Layer Settings

┌─── Navigation Tabs ──────────────────────────────────────────────┐
│ [Overview] [Settings] [Thresholds*] [Per Model]                  │
└──────────────────────────────────────────────────────────────────┘

▼ Health Monitoring Thresholds
  ├─ Memory Warning Threshold: 70%
  ├─ Memory Critical Threshold: 85%
  ├─ Error Rate Warning Threshold: 5%
  └─ Error Rate Critical Threshold: 10%

▼ Adaptive Budget Allocation
  ├─ High Priority Budget: 100%
  ├─ Medium Priority Budget: 75%
  ├─ Low Priority Budget: 50%
  ├─ Critical Health Budget Reduction: 50%
  └─ Warning Health Budget Reduction: 75%

▼ Token Limits by Workload Tier
  ├─ Low Tier Max Tokens: 2,000
  ├─ Medium Tier Max Tokens: 8,000
  └─ High Tier Max Tokens: 32,000

▼ Per-Call and Per-Session Limits ← NEW!
  ├─ ☑ Enable Per-Call Token Limits
  ├─ Per-Call Token Limit: 10,000 tokens
  ├─ ☑ Enable Per-Session Token Limits
  └─ Per-Session Token Limit: 50,000 tokens

▼ Predictive Analytics
  ├─ Prediction Confidence Threshold: 40%
  └─ Prediction Safety Buffer: 15%
```

## Configuration Examples

### Example 1: Conservative Settings (Small Sites)
```
Per-Call Limit:    5,000 tokens  (catch large individual responses)
Per-Session Limit: 25,000 tokens (limit to ~5 tool calls average)
```

**Use Case**: Shared hosting, limited resources, cost-sensitive

### Example 2: Moderate Settings (Default)
```
Per-Call Limit:    10,000 tokens  (reasonable individual limit)
Per-Session Limit: 50,000 tokens  (allow ~10 tool calls average)
```

**Use Case**: Standard WordPress sites, balanced approach

### Example 3: Generous Settings (Enterprise)
```
Per-Call Limit:    50,000 tokens  (allow large responses)
Per-Session Limit: 200,000 tokens (support extensive conversations)
```

**Use Case**: Dedicated servers, power users, complex workflows

### Example 4: Per-Call Monitoring Only
```
☑ Enable Per-Call Limits
Per-Call Limit:    10,000 tokens

☐ Enable Per-Session Limits (disabled)
```

**Use Case**: Want monitoring/logging but not session enforcement

### Example 5: Session Budget Only
```
☐ Enable Per-Call Limits (disabled)

☑ Enable Per-Session Limits
Per-Session Limit: 75,000 tokens
```

**Use Case**: Focus on total session cost, allow flexible individual calls

## User-Facing Error Messages

### When Per-Session Limit is Exceeded

```
┌─────────────────────────────────────────────────────────────┐
│ ⚠ Session Token Limit Exceeded                              │
│                                                              │
│ This session has used 52,345 tokens of the 50,000 token     │
│ limit. Please start a new session to continue.              │
│                                                              │
│ [Start New Session]                                          │
└─────────────────────────────────────────────────────────────┘
```

### When Per-Call Limit is Exceeded (Logged Only)

No user-facing error (non-blocking), but logged to WordPress debug:

```
[2025-11-18 02:50:37] per_call_token_limit_exceeded:
  user_id: 1
  tool_slug: run_crawl4ai_job
  tokens: 15,234
  limit: 10,000
  ratio: 1.52
```

## Admin Dashboard Indicators (Future Enhancement)

Planned for future versions:

```
┌─────────────────────────────────────────────────────────────┐
│ Session Budget Monitor                                       │
│ ─────────────────────────────────────────────────────────── │
│ Current Session: chat-abc123-xyz789                         │
│ Total Tokens Used: 35,420 / 50,000                          │
│                                                              │
│ ████████████████████████▒▒▒▒▒▒▒▒▒▒▒ 71%                    │
│                                                              │
│ Tool Breakdown:                                              │
│ • search_content:    12,000 tokens (3 calls)                │
│ • run_crawl4ai_job:  23,420 tokens (2 calls)                │
│                                                              │
│ [View Details] [Reset Session]                               │
└─────────────────────────────────────────────────────────────┘
```

## Developer Access

### Get Session Usage (JavaScript)

```javascript
// Via REST API (future implementation)
fetch('/wp-json/mcp-ai/v1/session-usage', {
    headers: {
        'X-WP-Nonce': wpApiSettings.nonce,
        'X-Session-ID': currentSessionId
    }
})
.then(res => res.json())
.then(data => {
    console.log('Tokens used:', data.total_tokens);
    console.log('Limit:', data.limit);
    console.log('Remaining:', data.limit - data.total_tokens);
});
```

### Get Session Usage (PHP)

```php
// Get session data
$session_id = 'chat-abc123-xyz789';
$user_id    = get_current_user_id();
$data       = WP_MCP_AI_Tool_Token_Limits::get_session_data( $user_id, $session_id );

// Display usage
echo "Session tokens: " . $data['total_tokens'];
echo "Tool calls: " . count( $data['tool_calls'] );

// Show breakdown
foreach ( $data['tool_calls'] as $tool => $stats ) {
    echo "{$tool}: {$stats['count']} calls, {$stats['tokens']} tokens\n";
}
```

## Settings Storage

### WordPress Options

```php
// Per-call settings
wp_mcp_ai_enable_per_call_limits    = true/false
wp_mcp_ai_per_call_token_limit      = integer (0-100000)

// Per-session settings
wp_mcp_ai_enable_per_session_limits = true/false
wp_mcp_ai_per_session_token_limit   = integer (0-500000)
```

### Session Data (Transients)

```php
// Transient key format
_transient_wp_mcp_ai_session_{user_id}_{session_id}

// Example
_transient_wp_mcp_ai_session_1_chat-abc123-xyz789

// Expiration: 24 hours (86400 seconds)
```

## Migration Path

Existing installations:
1. Settings default to **disabled** (no breaking changes)
2. Admins opt-in by enabling checkboxes
3. Suggested starting values populated
4. No impact on existing workflows until enabled

New installations:
1. Settings default to **disabled**
2. Admins can enable during initial setup
3. Documentation guides optimal configuration

## Benefits Summary

✅ **Per-Call Limits**
- Monitor individual tool performance
- Identify problematic queries
- Alert on anomalous usage
- Non-disruptive logging

✅ **Per-Session Limits**
- Control total conversation costs
- Prevent runaway agentic loops
- Fair resource distribution
- Clear user feedback

✅ **Combined**
- Multi-layered protection
- Granular budget control
- Flexible enforcement options
- Comprehensive monitoring
