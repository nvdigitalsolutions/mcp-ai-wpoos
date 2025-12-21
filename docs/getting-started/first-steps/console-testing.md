# Console Testing Utility

## Overview

The WP oOS plugin exposes a global console function `wpMcpAiTestGetTranscript()` that allows you to test the GET `/chat-transcripts/{session_key}` endpoint directly from your browser's developer console.

## Usage

### Basic Syntax

```javascript
wpMcpAiTestGetTranscript(sessionKey, userId, assistantId)
```

### Parameters

- **sessionKey** (string, required): The session key/UUID of the chat transcript you want to retrieve
- **userId** (number, optional): The user ID who owns the transcript. If not provided, defaults to the current logged-in user
- **assistantId** (number, optional): The assistant ID to filter by

### Examples

#### Example 1: Retrieve a transcript with all parameters

```javascript
wpMcpAiTestGetTranscript('1e05412c-c158-44c1-8f8d-584c9f29a1e9', 1, 14)
```

This will make a GET request to:
```
/wp-json/mcp-ai/v1/chat-transcripts/1e05412c-c158-44c1-8f8d-584c9f29a1e9?user_id=1&assistant_id=14
```

#### Example 2: Retrieve a transcript with only session key

```javascript
wpMcpAiTestGetTranscript('1e05412c-c158-44c1-8f8d-584c9f29a1e9')
```

This will make a GET request to:
```
/wp-json/mcp-ai/v1/chat-transcripts/1e05412c-c158-44c1-8f8d-584c9f29a1e9
```

#### Example 3: Retrieve a transcript with session key and user ID

```javascript
wpMcpAiTestGetTranscript('abc-123-def-456', 5)
```

This will make a GET request to:
```
/wp-json/mcp-ai/v1/chat-transcripts/abc-123-def-456?user_id=5
```

### Return Value

The function returns a Promise that resolves with the transcript data or rejects with an error.

```javascript
// Using .then()
wpMcpAiTestGetTranscript('1e05412c-c158-44c1-8f8d-584c9f29a1e9', 1, 14)
  .then(data => console.log('Got transcript:', data))
  .catch(error => console.error('Failed:', error));

// Using async/await
const data = await wpMcpAiTestGetTranscript('1e05412c-c158-44c1-8f8d-584c9f29a1e9', 1, 14);
console.log('Got transcript:', data);
```

## Console Output

The function logs detailed information to the console:

### Request Information

```
[wpMcpAiTestGetTranscript] Request: {
  url: "https://example.com/wp-json/mcp-ai/v1/chat-transcripts/...",
  sessionKey: "1e05412c-c158-44c1-8f8d-584c9f29a1e9",
  userId: 1,
  assistantId: 14,
  headers: { Accept: "application/json", X-WP-Nonce: "..." }
}
```

### Response Information

#### Success Response

```
[wpMcpAiTestGetTranscript] Response status: 200 OK
[wpMcpAiTestGetTranscript] Success! Data: {
  session: {
    session_key: "...",
    messages: [...],
    ...
  }
}
```

#### Error Response

```
[wpMcpAiTestGetTranscript] Response status: 404 Not Found
[wpMcpAiTestGetTranscript] Error response: {
  code: "wp_mcp_ai_transcripts_not_found",
  message: "Chat transcript not found.",
  ...
}
```

## Requirements

- Must be on a page where the WP oOS chat JavaScript is loaded
- WordPress REST API nonce must be available in the global configuration
- User must have appropriate permissions to access the transcript

## Troubleshooting

### Error: "sessionKey parameter is required"

You forgot to provide the session key. Example:

```javascript
// ❌ Wrong
wpMcpAiTestGetTranscript()

// ✅ Correct
wpMcpAiTestGetTranscript('1e05412c-c158-44c1-8f8d-584c9f29a1e9')
```

### Error: "transcriptsEndpoint not configured"

The chat JavaScript hasn't been initialized yet, or you're on a page without the WP oOS chat widget. Try:

1. Navigate to a page with the chat widget
2. Wait for the page to fully load
3. Check that `wpMcpAiChat.transcriptsEndpoint` exists:
   ```javascript
   console.log(wpMcpAiChat.transcriptsEndpoint)
   ```

### 404 Not Found Response

The transcript doesn't exist, or:
- The user_id doesn't match the transcript owner
- The assistant_id doesn't match the transcript's assistant
- The session_key is incorrect
- JetEngine is not installed (transcripts are only stored when JetEngine is active)

### 401 Unauthorized Response

You're not logged in, or the WordPress nonce has expired. Try:
- Refreshing the page
- Logging in to WordPress
- Checking that the nonce is present: `console.log(wpMcpAiChat.nonce)`

## Related Functions

- `wpMcpAiLoadSession()` - Load a saved transcript into the chat UI
- See `docs/rest-api.md` for complete REST API documentation
- See `docs/tool-reference.md` for chat transcript storage details

## Technical Details

The function:
1. Validates the session key parameter
2. Retrieves the transcripts endpoint URL from global config
3. Constructs the full URL with session key and optional query parameters
4. Adds authentication headers (WordPress nonce)
5. Makes a fetch request with proper credentials
6. Logs all request/response details to console
7. Returns a Promise with the transcript data

The endpoint requires proper authentication via:
- WordPress REST nonce (for same-origin requests)
- Guest tokens (for public chat surfaces)
- Assistant credentials (for scoped access)

See `includes/rest/class-wp-mcp-ai-rest-chat-controller.php` for server-side implementation.
