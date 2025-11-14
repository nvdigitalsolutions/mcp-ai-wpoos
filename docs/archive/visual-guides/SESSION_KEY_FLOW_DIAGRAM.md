# Session Key Flow - Visual Diagram

## Before Fix (BROKEN ❌)

```
┌─────────────────────────────────────────────────────────────────┐
│                        FIRST MESSAGE FLOW                        │
└─────────────────────────────────────────────────────────────────┘

CLIENT                          SERVER                      DATABASE
  │                               │                             │
  │  POST /chat                   │                             │
  │  messages: [...]              │                             │
  │  session_key: undefined       │                             │
  ├──────────────────────────────>│                             │
  │                               │                             │
  │                               │ Generate session_key        │
  │                               │ "wp-mcp-ai-abc123"          │
  │                               │                             │
  │                               │ Save transcript             │
  │                               ├────────────────────────────>│
  │                               │ session_key: "wp-mcp-ai-... │
  │                               │                             │
  │  Response:                    │                             │
  │  {                            │                             │
  │    data: {...},              │                             │
  │    ❌ NO sessionKey!         │                             │
  │  }                            │                             │
  │<──────────────────────────────┤                             │
  │                               │                             │
  │ Save to localStorage:         │                             │
  │ {                             │                             │
  │   conversation: [...],        │                             │
  │   sessionKey: "" ❌           │                             │
  │ }                             │                             │
  │                               │                             │

┌─────────────────────────────────────────────────────────────────┐
│                    LATER RETRIEVAL (FAILS ❌)                    │
└─────────────────────────────────────────────────────────────────┘

CLIENT                          SERVER                      DATABASE
  │                               │                             │
  │ GET /chat-transcripts?        │                             │
  │   session_key=""              │                             │
  ├──────────────────────────────>│                             │
  │                               │                             │
  │                               │ Query:                      │
  │                               │   WHERE session_key = ""    │
  │                               ├────────────────────────────>│
  │                               │                             │
  │                               │ No match! ❌                │
  │                               │<────────────────────────────┤
  │                               │                             │
  │  Empty results []             │                             │
  │<──────────────────────────────┤                             │
  │                               │                             │
  │ ❌ Chat history lost!         │                             │
  │                               │                             │
```

## After Fix (WORKING ✅)

```
┌─────────────────────────────────────────────────────────────────┐
│                        FIRST MESSAGE FLOW                        │
└─────────────────────────────────────────────────────────────────┘

CLIENT                          SERVER                      DATABASE
  │                               │                             │
  │  POST /chat                   │                             │
  │  messages: [...]              │                             │
  │  session_key: undefined       │                             │
  ├──────────────────────────────>│                             │
  │                               │                             │
  │                               │ Generate session_key        │
  │                               │ "wp-mcp-ai-abc123"          │
  │                               │                             │
  │                               │ Save transcript             │
  │                               ├────────────────────────────>│
  │                               │ session_key: "wp-mcp-ai-... │
  │                               │                             │
  │                               │ ← Return session_key        │
  │                               │                             │
  │  Response:                    │                             │
  │  {                            │                             │
  │    data: {...},              │                             │
  │    sessionKey: "wp-mcp-ai-..." ✅                           │
  │  }                            │                             │
  │<──────────────────────────────┤                             │
  │                               │                             │
  │ Extract & save session_key    │                             │
  │ state.config.sessionKey =     │                             │
  │   "wp-mcp-ai-abc123"          │                             │
  │                               │                             │
  │ Save to localStorage:         │                             │
  │ {                             │                             │
  │   conversation: [...],        │                             │
  │   sessionKey: "wp-mcp-ai-..." ✅                            │
  │ }                             │                             │
  │                               │                             │

┌─────────────────────────────────────────────────────────────────┐
│                   LATER RETRIEVAL (WORKS ✅)                     │
└─────────────────────────────────────────────────────────────────┘

CLIENT                          SERVER                      DATABASE
  │                               │                             │
  │ Load from localStorage:       │                             │
  │ sessionKey: "wp-mcp-ai-abc123"│                             │
  │                               │                             │
  │ GET /chat-transcripts?        │                             │
  │   session_key="wp-mcp-ai-..." │                             │
  ├──────────────────────────────>│                             │
  │                               │                             │
  │                               │ Query:                      │
  │                               │   WHERE session_key =       │
  │                               │     "wp-mcp-ai-abc123"      │
  │                               ├────────────────────────────>│
  │                               │                             │
  │                               │ Match found! ✅             │
  │                               │ Return transcript data      │
  │                               │<────────────────────────────┤
  │                               │                             │
  │  Transcript data              │                             │
  │  {messages: [...], ...}       │                             │
  │<──────────────────────────────┤                             │
  │                               │                             │
  │ ✅ Chat history restored!     │                             │
  │                               │                             │
```

## Key Changes

### 1. Transcript Recorder Returns Session Key
```php
// Before:
public static function record(...) {
    // ... save logic ...
    // Returns nothing ❌
}

// After:
public static function record(...) {
    $session_key = $record['session_key'];
    // ... save logic ...
    return $session_key; // ✅ Returns key
}
```

### 2. REST API Includes Session Key in Response
```php
// Before:
$payload = array(
    'assistant_id' => $assistant_id,
    'data'         => $response,
);
// ❌ Missing sessionKey

// After:
$recorded_session_key = WP_MCP_AI_Chat_Transcript_Recorder::record(...);
$payload = array(
    'assistant_id' => $assistant_id,
    'data'         => $response,
);
if ($recorded_session_key) {
    $payload['sessionKey'] = $recorded_session_key; // ✅ Added
}
```

### 3. Client Captures Session Key
```javascript
// Before:
function handleChatResponse(state, data) {
    const chatData = data && data.data ? data.data : null;
    // ❌ Missing session key extraction
    // ...
}

// After:
function handleChatResponse(state, data) {
    // ✅ Extract and save session key
    if (data && data.sessionKey && state.config) {
        state.config.sessionKey = data.sessionKey;
    }
    const chatData = data && data.data ? data.data : null;
    // ...
}
```

## Summary

| Aspect | Before Fix | After Fix |
|--------|-----------|-----------|
| Session Key Generated | ✅ Yes | ✅ Yes |
| Session Key Returned | ❌ No | ✅ Yes |
| Client Has Session Key | ❌ No (empty) | ✅ Yes (valid) |
| localStorage Has Session Key | ❌ No (empty) | ✅ Yes (valid) |
| Chat Retrieval Works | ❌ No | ✅ Yes |
