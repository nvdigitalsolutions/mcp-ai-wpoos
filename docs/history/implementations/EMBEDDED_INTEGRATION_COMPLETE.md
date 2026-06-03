# Embedded LLM Integration: Orchestration, Logging & Transcripts

## Overview
This document explains how the embedded WebLLM chat client integrates with the plugin's orchestration layer, logging system, token manager, and transcript persistence.

## Problem Statement

### Before Integration
❌ **Embedded chat operated in isolation:**
- Usage data displayed in UI but not tracked server-side
- No cost estimation or budget monitoring
- No orchestration dashboard visibility
- Transcripts only in browser localStorage (24h expiry)
- No JetEngine CCT integration
- No historical usage analysis

### After Integration
✅ **Full system integration:**
- Usage tracked via REST API → `WP_MCP_AI_Usage_Tracker`
- Optional JetEngine CCT for detailed logs
- Orchestration dashboard shows embedded usage
- Transcripts persisted to server-side storage
- Cost estimation across all providers
- Historical analysis and reporting

---

## Architecture

### 1. Usage Tracking Flow

```
┌─────────────────┐
│ Embedded Chat   │
│  (WebLLM)       │
└────────┬────────┘
         │ usage data
         ↓
┌─────────────────────────────────────┐
│ POST /track-embedded-usage          │
│                                     │
│ Payload:                            │
│ {                                   │
│   assistant_id: 123,                │
│   model: "Llama-3.2-1B",           │
│   usage: {                          │
│     prompt_tokens: 45,              │
│     completion_tokens: 120,         │
│     total_tokens: 165               │
│   },                                │
│   finish_reason: "stop"             │
│ }                                   │
└────────┬────────────────────────────┘
         │
         ├────────────────────────────────┐
         │                                │
         ↓                                ↓
┌────────────────────┐     ┌──────────────────────────┐
│ WP_MCP_AI_Usage_   │     │ Action Hook:             │
│ Tracker            │     │ wp_mcp_ai_embedded_      │
│                    │     │ usage_tracked            │
│ • Aggregate totals │     │                          │
│ • user_meta        │     │ • Extensions can hook in │
│ • Cost estimation  │     │ • Custom logging         │
└────────┬───────────┘     └────────┬─────────────────┘
         │                          │
         │                          ↓
         │                 ┌──────────────────────────┐
         │                 │ JetEngine Usage Logs CCT │
         │                 │                          │
         │                 │ • Detailed history       │
         │                 │ • Queryable via REST     │
         │                 │ • Admin UI               │
         │                 │ • Export capabilities    │
         │                 └──────────────────────────┘
         ↓
┌────────────────────────┐
│ Orchestration          │
│ Dashboard              │
│                        │
│ • Total usage          │
│ • Cost breakdown       │
│ • Model statistics     │
│ • Budget monitoring    │
└────────────────────────┘
```

### 2. Transcript Persistence Flow

```
┌─────────────────┐
│ Embedded Chat   │
│  (WebLLM)       │
└────────┬────────┘
         │ conversation
         │
         ├──────────────────┬────────────────────┐
         │                  │                    │
         ↓                  ↓                    ↓
┌────────────────┐  ┌──────────────┐  ┌─────────────────────┐
│ localStorage   │  │ Usage        │  │ JetEngine Transcripts│
│                │  │ Tracking     │  │ CCT                  │
│ • Immediate    │  │              │  │                      │
│ • 24h expiry   │  │ • REST API   │  │ • POST /chat-        │
│ • Offline      │  │ • Tracked    │  │   transcripts        │
│   access       │  │   server-side│  │ • Persistent storage │
└────────────────┘  └──────────────┘  │ • Session management │
                                      └─────────────────────┘
```

### 3. Logging Layer Integration

```
┌──────────────────────────────────────────────┐
│ Chat Completion (Embedded WebLLM)            │
│                                              │
│ • System prompt included                     │
│ • Tools available                            │
│ • Knowledge context maintained               │
└──────────────┬───────────────────────────────┘
               │
               ↓
┌──────────────────────────────────────────────┐
│ Response with OpenAI-compatible fields       │
│                                              │
│ {                                            │
│   role: "assistant",                         │
│   content: "...",                            │
│   finish_reason: "stop",                     │
│   usage: {                                   │
│     prompt_tokens: 45,                       │
│     completion_tokens: 120,                  │
│     total_tokens: 165                        │
│   }                                          │
│ }                                            │
└──────────────┬───────────────────────────────┘
               │
               ├─────────────┬─────────────┬───────────────┐
               │             │             │               │
               ↓             ↓             ↓               ↓
       ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────────┐
       │ UI       │  │ Storage  │  │ Usage    │  │ Transcript   │
       │ Display  │  │ Service  │  │ Tracking │  │ Persistence  │
       │          │  │          │  │          │  │              │
       │ • Badges │  │ • Local  │  │ • Server │  │ • Server     │
       │ • Stats  │  │ • 24h    │  │ • Aggreg │  │ • Permanent  │
       └──────────┘  └──────────┘  └──────────┘  └──────────────┘
```

---

## Implementation Details

### REST API Endpoint

**Endpoint:** `POST /wp-json/mcp-ai/v1/track-embedded-usage`

**Purpose:** Track embedded LLM usage for server-side monitoring

**Request:**
```json
{
  "assistant_id": 123,
  "model": "Llama-3.2-1B-Instruct-q4f16_1-MLC",
  "usage": {
    "prompt_tokens": 45,
    "completion_tokens": 120,
    "total_tokens": 165
  },
  "finish_reason": "stop"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Usage tracked successfully."
}
```

**Error Handling:**
- `401`: User not authenticated
- `400`: Invalid usage data
- Non-blocking: Failure doesn't affect chat functionality

### Usage Tracker Integration

**File:** `includes/class-wp-mcp-ai-usage-tracker.php`

**Method:** `WP_MCP_AI_Usage_Tracker::record_chat_usage()`

**Storage:** WordPress `user_meta` with key `_wp_mcp_ai_usage_totals`

**Data Structure:**
```php
array(
    'provider' => array(
        'model' => array(
            'prompt_tokens' => 12345,
            'completion_tokens' => 67890,
            'total_tokens' => 80235,
            'requests' => 142,
            'first_request' => 1234567890,
            'last_request' => 1234567899
        )
    )
)
```

**Provider Key:** `'embedded'` for WebLLM completions

### JetEngine Usage Logs CCT (Optional)

**File:** `includes/class-wp-mcp-ai-jetengine-usage-logs-cct.php`

**CCT Slug:** `ai_usage_logs`

**REST Endpoint:** `GET /wp-json/jet-cct/ai_usage_logs`

**Fields:**
- `timestamp` (datetime-local): When completion occurred
- `user_id` (number): WordPress user ID
- `assistant_id` (number): Assistant used
- `provider` (text): Provider key (e.g., 'embedded', 'openai')
- `model` (text): Model identifier
- `prompt_tokens` (number): Input tokens
- `completion_tokens` (number): Output tokens
- `total_tokens` (number): Total tokens
- `finish_reason` (text): Why generation stopped
- `source` (text): 'embedded' or 'server'

**Query Examples:**

Get all embedded LLM usage:
```
GET /wp-json/jet-cct/ai_usage_logs?provider=embedded
```

Get usage for specific user:
```
GET /wp-json/jet-cct/ai_usage_logs?user_id=5
```

Get usage by date range:
```
GET /wp-json/jet-cct/ai_usage_logs?timestamp_min=2024-01-01&timestamp_max=2024-01-31
```

**Admin UI:**
JetEngine provides built-in admin interface at:
`WordPress Admin → JetEngine → AI Usage Logs`

### Transcript Persistence

**Function:** `saveConversationToCCT(state, options)`

**Endpoint:** `POST /wp-json/mcp-ai/v1/chat-transcripts`

**When Triggered:**
- After each embedded completion
- When `state.config.transcriptsEndpoint` is configured
- Non-blocking (failure doesn't affect chat)

**Payload:**
```json
{
  "assistant_id": 123,
  "session_key": "chat_123_1234567890_abc123",
  "messages": [
    {
      "role": "system",
      "content": "You are a helpful assistant..."
    },
    {
      "role": "user",
      "content": "Hello!"
    },
    {
      "role": "assistant",
      "content": "Hi there!"
    }
  ]
}
```

**Storage:**
- **localStorage**: Immediate (24h expiry)
- **JetEngine CCT**: Persistent (when configured)
- **Both**: Redundancy ensures reliability

### Action Hooks

**Hook:** `wp_mcp_ai_embedded_usage_tracked`

**Parameters:**
```php
do_action(
    'wp_mcp_ai_embedded_usage_tracked',
    $user_id,       // int
    $assistant_id,  // int
    $model,         // string
    $usage,         // array
    $finish_reason  // string
);
```

**Use Cases:**
- Custom logging systems
- Third-party analytics
- Billing integrations
- Monitoring alerts

**Example:**
```php
add_action( 'wp_mcp_ai_embedded_usage_tracked', function(
    $user_id,
    $assistant_id,
    $model,
    $usage,
    $finish_reason
) {
    // Send to external analytics
    wp_remote_post( 'https://analytics.example.com/usage', array(
        'body' => json_encode( array(
            'user'    => $user_id,
            'model'   => $model,
            'tokens'  => $usage['total_tokens'],
            'source'  => 'embedded_webllm'
        ) )
    ) );
}, 10, 5 );
```

---

## Token Manager Integration

### Cost Estimation

**Current Limitation:** Embedded LLM models (WebLLM) run **locally** in the browser, so there are **no API costs**.

**Future Enhancement:** Could track:
- Energy usage estimates
- Compute time
- Model download bandwidth
- Storage costs

### Budget Monitoring

**Aggregated Usage:**
```
Provider: embedded
├── Model: Llama-3.2-1B-Instruct-q4f16_1-MLC
│   ├── Total Tokens: 50,000
│   ├── Requests: 150
│   └── Cost: $0.00 (local execution)
└── Model: Phi-3-mini-4k-instruct-q4f16_1-MLC
    ├── Total Tokens: 30,000
    ├── Requests: 80
    └── Cost: $0.00 (local execution)
```

**Dashboard Display:**
- Total tokens used per model
- Request counts
- Average tokens per request
- Comparison with API-based models

---

## Orchestration Dashboard

### Usage Statistics

**Location:** `Settings → NV oOS → Orchestration Dashboard`

**Metrics Displayed:**
- **Total Usage:**
  - Server-side completions (OpenAI, Gemini, etc.)
  - Embedded completions (WebLLM)
  - Combined totals

- **By Provider:**
  - OpenAI: X tokens ($Y cost)
  - Gemini: X tokens ($Y cost)
  - Embedded: X tokens ($0.00 cost)

- **By Model:**
  - Model breakdown
  - Token usage
  - Request counts

- **By User:**
  - Per-user statistics
  - Budget tracking
  - Cost attribution

### Health Monitoring

**Status Indicators:**
- ✅ Embedded provider operational
- ✅ Usage tracking functional
- ✅ Transcript persistence working
- ⚠️ JetEngine CCT unavailable (optional)

---

## Benefits

### For Administrators

✅ **Unified Tracking:**
- All AI usage in one place
- Server + embedded combined view
- Consistent reporting

✅ **Cost Visibility:**
- API costs vs local execution
- Budget monitoring
- Cost optimization insights

✅ **Historical Analysis:**
- JetEngine CCT provides detailed logs
- Query by date, user, model, assistant
- Export for external analysis

✅ **Troubleshooting:**
- View usage patterns
- Identify heavy users
- Monitor model performance

### For Users

✅ **Persistent Transcripts:**
- Conversations saved server-side
- Access from any device
- Not lost on browser clear

✅ **Consistent Experience:**
- Embedded chat works like server-side
- Same features and capabilities
- Transparent integration

✅ **Offline Capability:**
- localStorage for immediate access
- Server sync when online
- Best of both worlds

---

## Configuration

### Enable Usage Tracking

**Automatic:** No configuration needed. Usage tracking happens automatically when embedded provider is used.

### Enable JetEngine Logging (Optional)

**Requirements:**
1. JetEngine plugin installed
2. Custom Content Types module enabled
3. CCT will auto-create on first use

**Verify:**
```php
// Check if usage logs CCT is available
if ( class_exists( 'WP_MCP_AI_JetEngine_Usage_Logs_CCT' ) ) {
    $slug = WP_MCP_AI_JetEngine_Usage_Logs_CCT::get_slug();
    echo "Usage logs available at: /wp-json/jet-cct/{$slug}";
}
```

### Enable Transcript Persistence

**Set `transcriptsEndpoint` in assistant config:**
```javascript
{
    "assistantId": 123,
    "model": "Llama-3.2-1B-Instruct-q4f16_1-MLC",
    "provider": "embedded",
    "transcriptsEndpoint": "/wp-json/mcp-ai/v1/chat-transcripts"
}
```

---

## Testing

### Test Usage Tracking

1. **Start embedded chat conversation**
2. **Send a message and get response**
3. **Check browser console:**
   ```
   [NV oOS] Tracking embedded LLM usage server-side
   [NV oOS] Embedded usage tracked successfully
   ```
4. **Verify in admin:**
   - Go to `Settings → NV oOS → Orchestration Dashboard`
   - Check for embedded provider usage statistics

### Test JetEngine Integration

1. **Ensure JetEngine installed**
2. **Go to:** `JetEngine → AI Usage Logs`
3. **Verify CCT exists** with fields:
   - timestamp, user_id, assistant_id
   - provider, model, tokens, finish_reason
4. **Test REST API:**
   ```bash
   curl -X GET "https://yoursite.com/wp-json/jet-cct/ai_usage_logs" \
        -H "Authorization: Bearer YOUR_TOKEN"
   ```

### Test Transcript Persistence

1. **Start embedded chat**
2. **Send multiple messages**
3. **Check console:**
   ```
   [NV oOS] Saving embedded chat transcript to server
   [NV oOS] Embedded chat transcript saved to server successfully
   ```
4. **Verify storage:**
   - localStorage: Check browser DevTools → Application → Local Storage
   - Server: Query `/wp-json/mcp-ai/v1/chat-transcripts`

---

## Troubleshooting

### Usage Not Tracked

**Symptoms:** No embedded usage in dashboard

**Checks:**
1. Browser console shows tracking call?
2. REST endpoint accessible?
3. User authenticated?
4. Usage data valid?

**Solution:**
```javascript
// Check if endpoint exists
fetch('/wp-json/mcp-ai/v1/track-embedded-usage', {
    method: 'HEAD'
}).then(r => console.log('Endpoint status:', r.status));
```

### JetEngine CCT Not Created

**Symptoms:** Usage logs CCT missing

**Checks:**
1. JetEngine installed?
2. CCT module enabled?
3. Class bootstrapped?

**Solution:**
```php
// Manually trigger bootstrap
if ( class_exists( 'WP_MCP_AI_JetEngine_Usage_Logs_CCT' ) ) {
    WP_MCP_AI_JetEngine_Usage_Logs_CCT::bootstrap();
}
```

### Transcripts Not Persisting

**Symptoms:** Transcripts only in localStorage

**Checks:**
1. `transcriptsEndpoint` configured?
2. User has permission?
3. Session key valid?

**Solution:**
Check assistant config has:
```json
{
    "transcriptsEndpoint": "/wp-json/mcp-ai/v1/chat-transcripts"
}
```

---

## Summary

This integration provides **complete visibility** into embedded LLM usage:

✅ **Usage Tracking** → Server-side aggregation  
✅ **Cost Estimation** → Token manager integration  
✅ **Logging** → JetEngine CCT (optional)  
✅ **Transcripts** → Persistent storage  
✅ **Dashboard** → Orchestration visibility  
✅ **REST API** → External integrations  

The embedded chat client now operates as a **first-class citizen** alongside server-side providers, with full integration into the plugin's monitoring, logging, and orchestration infrastructure.
