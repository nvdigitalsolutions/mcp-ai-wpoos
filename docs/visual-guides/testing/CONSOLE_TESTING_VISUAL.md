# Browser Console Testing - Visual Guide

## 🎯 Quick Access

```javascript
wpMcpAiTestGetTranscript('session-key', userId, assistantId)
```

## 📸 Step-by-Step Visual Guide

### Step 1: Open Developer Console

**Windows/Linux:** Press `F12` or `Ctrl + Shift + I`  
**Mac:** Press `Cmd + Option + I`

Or right-click anywhere on the page → **Inspect** → **Console** tab

```
┌─────────────────────────────────────────────────────┐
│  Elements  Console  Sources  Network  Performance  │
├─────────────────────────────────────────────────────┤
│  >  _                                               │  ← Type here
│                                                     │
│                                                     │
└─────────────────────────────────────────────────────┘
```

### Step 2: Navigate to WP oOS Page

Make sure you're on a WordPress page with the WP oOS chat widget loaded.

```
┌─────────────────────────────────────────────────────┐
│  🏠 Home  📝 About  💬 Chat  ← Navigate here       │
├─────────────────────────────────────────────────────┤
│                                                     │
│  ┌─────────────────────────┐                       │
│  │  💬 Chat Widget         │                       │
│  │  Ask me anything...     │  ← WP oOS widget      │
│  └─────────────────────────┘                       │
└─────────────────────────────────────────────────────┘
```

### Step 3: Type the Function Call

```javascript
// Example 1: Basic usage (just session key)
wpMcpAiTestGetTranscript('1e05412c-c158-44c1-8f8d-584c9f29a1e9')
```

```
Console:
> wpMcpAiTestGetTranscript('1e05412c-c158-44c1-8f8d-584c9f29a1e9')
  ↵ Press Enter
```

### Step 4: View the Output

```
Console:
> wpMcpAiTestGetTranscript('1e05412c-c158-44c1-8f8d-584c9f29a1e9', 1, 14)

[wpMcpAiTestGetTranscript] Request: {
  url: "https://example.com/wp-json/mcp-ai/v1/chat-transcripts/1e05412c-c158-44c1-8f8d-584c9f29a1e9?user_id=1&assistant_id=14",
  sessionKey: "1e05412c-c158-44c1-8f8d-584c9f29a1e9",
  userId: 1,
  assistantId: 14,
  headers: {Accept: "application/json", X-WP-Nonce: "..."}
}

[wpMcpAiTestGetTranscript] Response status: 200 OK

[wpMcpAiTestGetTranscript] Success! Data: {
  session: {
    session_key: "1e05412c-c158-44c1-8f8d-584c9f29a1e9",
    messages: Array(5),
    created_at: "2025-11-23T10:30:00Z",
    updated_at: "2025-11-23T10:35:00Z"
  }
}

← Promise {<fulfilled>: {...}}
```

## 🎨 Visual Examples

### Example A: Success Response (200 OK)

```
┌────────────────────────────────────────────────┐
│ Console                                        │
├────────────────────────────────────────────────┤
│ > wpMcpAiTestGetTranscript('abc-123')          │
│                                                │
│ ✅ [wpMcpAiTestGetTranscript] Request: {...}  │
│ ✅ [wpMcpAiTestGetTranscript] Response: 200   │
│ ✅ [wpMcpAiTestGetTranscript] Success! {...}  │
│                                                │
│ ← Promise {<fulfilled>: {session: {...}}}     │
└────────────────────────────────────────────────┘
```

### Example B: Error Response (404 Not Found)

```
┌────────────────────────────────────────────────┐
│ Console                                        │
├────────────────────────────────────────────────┤
│ > wpMcpAiTestGetTranscript('wrong-key')        │
│                                                │
│ ⚠️  [wpMcpAiTestGetTranscript] Request: {...}  │
│ ❌ [wpMcpAiTestGetTranscript] Response: 404    │
│ ❌ [wpMcpAiTestGetTranscript] Error: {...}     │
│                                                │
│ ← Promise {<fulfilled>: {code: "...", ...}}   │
└────────────────────────────────────────────────┘
```

### Example C: Missing Parameter Error

```
┌────────────────────────────────────────────────┐
│ Console                                        │
├────────────────────────────────────────────────┤
│ > wpMcpAiTestGetTranscript()                   │
│                                                │
│ ❌ Error: sessionKey parameter is required     │
│                                                │
│ ← Promise {<rejected>: Error: ...}            │
└────────────────────────────────────────────────┘
```

## 📋 Quick Copy-Paste Examples

### Test with Full Parameters
```javascript
wpMcpAiTestGetTranscript('1e05412c-c158-44c1-8f8d-584c9f29a1e9', 1, 14)
```

### Test with Session Key Only
```javascript
wpMcpAiTestGetTranscript('1e05412c-c158-44c1-8f8d-584c9f29a1e9')
```

### Test with Async/Await
```javascript
const data = await wpMcpAiTestGetTranscript('1e05412c-c158-44c1-8f8d-584c9f29a1e9');
console.log('Messages:', data.session.messages);
```

### Test with Error Handling
```javascript
wpMcpAiTestGetTranscript('test-session')
  .then(result => {
    console.log('✅ Got transcript:', result.session);
  })
  .catch(error => {
    console.error('❌ Failed:', error.message);
  });
```

## 🔍 Inspecting Results

Click on the returned object in the console to expand it:

```
▶ Promise {<fulfilled>: {…}}
  ▼ <fulfilled>: Object
    ▼ session: Object
        session_key: "1e05412c-c158-44c1-8f8d-584c9f29a1e9"
      ▼ messages: Array(5)
          ▶ 0: {role: "user", content: "Hello"}
          ▶ 1: {role: "assistant", content: "Hi!"}
          ▶ 2: {role: "user", content: "How are you?"}
          ▶ 3: {role: "assistant", content: "I'm doing well!"}
          ▶ 4: {role: "user", content: "Great!"}
        created_at: "2025-11-23T10:30:00Z"
        updated_at: "2025-11-23T10:35:00Z"
        user_id: 1
        assistant_id: 14
```

## 🎓 Pro Tips

### Tip 1: Copy Session Keys from URL
If you see a session key in the browser address bar, you can copy it directly:
```
https://example.com/chat?session=1e05412c-c158-44c1-8f8d-584c9f29a1e9
                                 ↑ Copy this part ↑
```

### Tip 2: Use Console Variables
Store frequently used session keys in variables:
```javascript
const mySession = '1e05412c-c158-44c1-8f8d-584c9f29a1e9';
wpMcpAiTestGetTranscript(mySession);
```

### Tip 3: Check Available Config
Before testing, verify the endpoint is configured:
```javascript
console.log(wpMcpAiChat.transcriptsEndpoint);
// Should output: "https://example.com/wp-json/mcp-ai/v1/chat-transcripts"
```

### Tip 4: Inspect Network Tab
Open the **Network** tab to see the actual HTTP request:
```
┌─────────────────────────────────────────────────────┐
│  Console  Network  ← Switch to Network tab          │
├─────────────────────────────────────────────────────┤
│  Name                         Status    Type        │
│  chat-transcripts/1e05...    200       xhr          │ ← Click to inspect
└─────────────────────────────────────────────────────┘
```

## 🆘 Troubleshooting Flowchart

```
Start
  │
  ▼
Does wpMcpAiTestGetTranscript exist?
  ├─ No → Navigate to page with WP oOS chat
  └─ Yes ↓
         │
         ▼
    Run the function
         │
         ▼
    Any errors?
         ├─ "sessionKey required" → Add session key parameter
         ├─ "transcriptsEndpoint not configured" → Check chat widget loaded
         ├─ 404 Not Found → Check session key is correct
         ├─ 401 Unauthorized → Log in to WordPress
         └─ Success! ✅
```

## 📚 Related Documentation

- [console-testing.md](console-testing.md) - Complete documentation
- [CONSOLE_TESTING_QUICK_REF.md](CONSOLE_TESTING_QUICK_REF.md) - Quick reference
- [examples/console-testing-example.html](examples/console-testing-example.html) - HTML demo
- [rest-api.md](rest-api.md) - REST API reference

---

**Remember:** Open the Developer Console (F12) on a page with WP oOS chat widget, then run:
```javascript
wpMcpAiTestGetTranscript('your-session-key-here')
```
