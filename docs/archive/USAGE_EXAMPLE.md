# Console Testing Utility - Usage Example

## 🚀 Quick Start

### Step 1: Open Browser Console
Press **F12** (or **Cmd+Option+I** on Mac)

### Step 2: Navigate to WP oOS Chat Page
Make sure you're on a WordPress page with the WP oOS chat widget

### Step 3: Run the Test Function

```javascript
wpMcpAiTestGetTranscript('1e05412c-c158-44c1-8f8d-584c9f29a1e9', 1, 14)
```

## 📝 What You'll See

### Console Output
```
[wpMcpAiTestGetTranscript] Request: {
  url: "https://bots.nvdigital.solutions/wp-json/mcp-ai/v1/chat-transcripts/1e05412c-c158-44c1-8f8d-584c9f29a1e9?user_id=1&assistant_id=14",
  sessionKey: "1e05412c-c158-44c1-8f8d-584c9f29a1e9",
  userId: 1,
  assistantId: 14,
  headers: {Accept: "application/json", X-WP-Nonce: "abc123..."}
}

[wpMcpAiTestGetTranscript] Response status: 200 OK

[wpMcpAiTestGetTranscript] Success! Data: {
  session: {
    session_key: "1e05412c-c158-44c1-8f8d-584c9f29a1e9",
    messages: Array(5),
    created_at: "2025-11-23T10:30:00Z",
    updated_at: "2025-11-23T10:35:00Z",
    user_id: 1,
    assistant_id: 14
  }
}
```

## 🎯 Copy-Paste Examples

### Basic Test (just session key)
```javascript
wpMcpAiTestGetTranscript('1e05412c-c158-44c1-8f8d-584c9f29a1e9')
```

### Full Test (all parameters)
```javascript
wpMcpAiTestGetTranscript('1e05412c-c158-44c1-8f8d-584c9f29a1e9', 1, 14)
```

### With Async/Await
```javascript
const result = await wpMcpAiTestGetTranscript('1e05412c-c158-44c1-8f8d-584c9f29a1e9');
console.log('Messages:', result.session.messages);
```

### With Error Handling
```javascript
wpMcpAiTestGetTranscript('1e05412c-c158-44c1-8f8d-584c9f29a1e9')
  .then(data => console.log('✅ Success:', data))
  .catch(err => console.error('❌ Error:', err));
```

## 📚 More Information

- Full Documentation: [docs/console-testing.md](../getting-started/first-steps/console-testing.md)
- Quick Reference: [docs/CONSOLE_TESTING_QUICK_REF.md](../visual-guides/testing/CONSOLE_TESTING_QUICK_REF.md)
- Visual Guide: [docs/CONSOLE_TESTING_VISUAL.md](docs/CONSOLE_TESTING_VISUAL.md)
- HTML Demo: [docs/examples/console-testing-example.html](docs/examples/console-testing-example.html)
