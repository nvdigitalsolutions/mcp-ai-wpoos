# Console Testing Quick Reference

## Function Signature

```javascript
wpMcpAiTestGetTranscript(sessionKey, userId, assistantId)
```

## Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `sessionKey` | string | ✅ Yes | Session key/UUID of the transcript |
| `userId` | number | ❌ No | User ID (defaults to current user) |
| `assistantId` | number | ❌ No | Assistant ID to filter by |

## Quick Examples

### Basic Usage
```javascript
wpMcpAiTestGetTranscript('1e05412c-c158-44c1-8f8d-584c9f29a1e9')
```

### With All Parameters
```javascript
wpMcpAiTestGetTranscript('1e05412c-c158-44c1-8f8d-584c9f29a1e9', 1, 14)
```

### Using Async/Await
```javascript
const data = await wpMcpAiTestGetTranscript('session-key-here');
console.log(data);
```

### Using Promises
```javascript
wpMcpAiTestGetTranscript('session-key-here')
  .then(data => console.log('Success:', data))
  .catch(err => console.error('Error:', err));
```

## Console Output

### Request Log
```
[wpMcpAiTestGetTranscript] Request: {
  url: "...",
  sessionKey: "...",
  userId: 1,
  assistantId: 14,
  headers: {...}
}
```

### Success Response
```
[wpMcpAiTestGetTranscript] Response status: 200 OK
[wpMcpAiTestGetTranscript] Success! Data: {...}
```

### Error Response
```
[wpMcpAiTestGetTranscript] Response status: 404 Not Found
[wpMcpAiTestGetTranscript] Error response: {...}
```

## Common Errors

| Error | Cause | Solution |
|-------|-------|----------|
| "sessionKey parameter is required" | Missing session key | Provide session key as first parameter |
| "transcriptsEndpoint not configured" | Chat not loaded | Navigate to page with WP oOS chat widget |
| 404 Not Found | Transcript doesn't exist | Check session key, user ID, assistant ID |
| 401 Unauthorized | Not logged in | Log in to WordPress |

## Where to Use

1. Open Developer Console (F12)
2. Navigate to page with WP oOS chat widget
3. Run the function

## Full Documentation

- [docs/console-testing.md](getting-started/first-steps/console-testing.md) - Complete guide
- [docs/examples/console-testing-example.html](examples/console-testing-example.html) - HTML example
- [docs/rest-api.md](reference/api/rest-api.md) - REST API reference

---

**Tip:** Type `wpMcpAiTestGetTranscript` in console to see function definition
