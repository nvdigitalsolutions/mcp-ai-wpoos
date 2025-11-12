# Enhanced Chat Save Functions

## Overview

This document describes the enhanced chat save functions added to the WP oOS plugin to improve reliability, error handling, and user experience when saving data from the chat interface.

## New Functions

### 1. `saveChatPost(state, saveData, options)`

Dedicated function for saving posts via the `save_post` tool with enhanced error handling and retry logic.

**Parameters:**
- `state` (Object): Chat state object containing config and endpoints
- `saveData` (Object): Post data to save
  - `title` (string): Post title
  - `content` (string): Post content (required)
  - `post_type` (string): Post type (default: 'post')
  - `status` (string): Post status (default: 'draft')
  - `post_id` (number, optional): ID for updating existing post
  - `excerpt` (string, optional): Post excerpt
  - `slug` (string, optional): Post slug
- `options` (Object, optional): Configuration options
  - `maxRetries` (number): Maximum retry attempts (default: 1)
  - `retryDelay` (number): Delay between retries in ms (default: 1000)
  - `timeout` (number): Request timeout in ms (default: 30000)

**Returns:** Promise that resolves with save result

**Features:**
- Request timeout with AbortController
- Automatic retry on failure (up to 2 attempts by default)
- Enhanced JSON parsing with fallback to text on error
- Detailed error messages for debugging
- Proper error handling for network and server issues

**Example:**
```javascript
saveChatPost(state, {
    title: 'My New Post',
    content: 'Post content here',
    post_type: 'post',
    status: 'draft'
}, {
    maxRetries: 2,
    timeout: 30000
})
.then(result => {
    console.log('Post saved:', result.post_id);
})
.catch(error => {
    console.error('Save failed:', error.message);
});
```

### 2. `savePostFromChat(state, saveData)`

User-friendly wrapper for `saveChatPost()` that provides visual feedback in the chat interface.

**Parameters:**
- `state` (Object): Chat state object
- `saveData` (Object): Post data to save (same as saveChatPost)

**Returns:** Promise that resolves with formatted result

**Features:**
- Automatic status messages in chat UI
- Shows "Saving post..." indicator
- Displays success message with post ID and edit link
- Shows error messages in chat on failure
- Pre-configured retry settings (2 retries, 1s delay, 30s timeout)

**Example:**
```javascript
savePostFromChat(state, {
    title: 'Chat Post',
    content: 'Content from chat'
})
.then(result => {
    // Success - message already shown in chat
})
.catch(error => {
    // Error - message already shown in chat
});
```

### 3. Enhanced `saveConversationToCCT(state, options)`

Improved server-side conversation save with retry logic and timeout support.

**Parameters:**
- `state` (Object): Chat state object
- `options` (Object, optional): Configuration options
  - `maxRetries` (number): Maximum retry attempts (default: 2)
  - `retryDelay` (number): Base delay between retries in ms (default: 1000)
  - `timeout` (number): Request timeout in ms (default: 15000)
  - `silent` (boolean): Suppress console logging (default: true)

**Returns:** Promise that resolves with result object

**Features:**
- Timeout support (15s default)
- Retry logic for network and server errors (up to 2 retries)
- Exponential backoff for retry delays
- Silent mode for background saves
- Error categorization (timeout, network, server errors)
- Attempt counter in results

**Example:**
```javascript
saveConversationToCCT(state, {
    maxRetries: 3,
    retryDelay: 1000,
    timeout: 20000,
    silent: false
})
.then(result => {
    if (result.success) {
        console.log('Saved after', result.attempt, 'attempts');
    }
});
```

### 4. Enhanced `saveConversationToStorage(state, options)`

Improved localStorage save with automatic quota management.

**Parameters:**
- `state` (Object): Chat state object
- `options` (Object, optional): Configuration options
  - `immediate` (boolean): Bypass debouncing for instant save (default: false)

**Returns:** Object with success status

**Features:**
- Automatic cleanup of expired conversations (>24h old)
- Quota exceeded error handling with auto-retry after cleanup
- Immediate save option to bypass debouncing
- Success/failure status reporting
- Debounced saves by default (300ms) to reduce writes

**Example:**
```javascript
const result = saveConversationToStorage(state, {
    immediate: true
});

if (result.success) {
    console.log('Saved to localStorage');
    if (result.cleaned) {
        console.log('Cleaned', result.cleaned, 'old entries');
    }
}
```

### 5. `cleanupOldStorageEntries()`

Utility function to clean up expired localStorage entries.

**Returns:** Number of entries cleaned up

**Features:**
- Removes conversations older than 24 hours
- Cleans up invalid JSON entries
- Safe error handling during cleanup
- Information logging about cleanup

**Example:**
```javascript
const cleaned = cleanupOldStorageEntries();
console.log('Removed', cleaned, 'old conversations');
```

### 6. `escapeHtml(text)`

Utility function to escape HTML and prevent XSS in dynamic content.

**Parameters:**
- `text` (string): Text to escape

**Returns:** Escaped HTML string

**Example:**
```javascript
const safe = escapeHtml('<script>alert("xss")</script>');
// Returns: &lt;script&gt;alert("xss")&lt;/script&gt;
```

## Error Handling

All enhanced save functions include comprehensive error handling:

1. **Network Errors**: Automatically retry with exponential backoff
2. **Server Errors** (5xx): Trigger retry logic
3. **Timeout Errors**: Aborted requests with clear error messages
4. **Parse Errors**: Fallback to text response for debugging
5. **Quota Errors** (localStorage): Automatic cleanup and retry

## Retry Logic

The retry system includes:
- Configurable maximum retry attempts
- Exponential backoff (delay increases with each retry)
- Smart retry decisions based on error type
- Attempt counter in results

## Timeout Handling

Requests include timeout protection using AbortController:
- Configurable timeout per function
- Clean request cancellation on timeout
- Clear error messages for debugging
- Retry support after timeout

## Backward Compatibility

All enhancements are backward compatible:
- Original function signatures preserved
- New parameters are optional
- Default behavior matches original implementation
- Silent fallback on errors

## Integration

These functions are automatically available in the chat interface:
1. Loaded with `assets/js/chat.js`
2. No additional configuration required
3. Work with existing WordPress REST API
4. Use existing nonce validation

## Testing

Manual testing can be performed using:
```
tests/manual/test-chat-save-functions.html
```

## Localized Strings

New localized string added to support the save functions:
- `savingPost`: "Saving post…" (used during post save operations)

## Best Practices

1. **Use savePostFromChat()** for user-initiated saves with UI feedback
2. **Use saveChatPost()** for programmatic saves without UI feedback
3. **Set immediate: true** for critical localStorage saves
4. **Use silent: false** for debugging server save issues
5. **Increase timeout** for slow network connections
6. **Adjust maxRetries** based on reliability requirements

## Browser Support

All functions use modern JavaScript features:
- Fetch API with AbortController
- Promises with async/await support
- localStorage API
- Supports all modern browsers (Chrome, Firefox, Safari, Edge)

## Security

Security measures included:
- HTML escaping for XSS prevention
- WordPress nonce validation
- Same-origin credentials
- Secure error messages (no sensitive data exposure)
- Proper JSON sanitization

## Performance

Performance optimizations:
- Debounced localStorage saves (300ms)
- Immediate save option when needed
- Automatic cleanup of old data
- Exponential backoff to prevent server hammering
- Request timeout to prevent hanging

## Future Enhancements

Potential improvements:
- Batch save operations
- Offline queue for failed saves
- Progress tracking for large saves
- Compression for large conversations
- IndexedDB fallback for large data

## Support

For issues or questions:
- GitHub Issues: https://github.com/nvdigitalsolutions/wp-mcp-ai/issues
- Documentation: docs/QUICK_REFERENCE.md
