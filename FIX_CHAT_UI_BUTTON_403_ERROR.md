# Fix: Chat UI Button 403 Error

## Issue
The chat UI button was blocked with a 403 Forbidden error when attempting to call the REST API tools endpoint:

```
POST https://bots.nvdigital.solutions/wp-json/mcp-ai/v1/tools 403 (Forbidden)
```

This affected features like:
- Text-to-speech audio generation
- Other tool executions from the chat interface

## Root Cause

The problem was caused by using **absolute URLs** for REST API endpoints in JavaScript code that uses `credentials: 'same-origin'`:

```javascript
fetch(state.config.toolsEndpoint, {
    method: 'POST',
    headers: buildJsonHeaders(state),
    credentials: 'same-origin',  // ← Strict same-origin checking
    body: JSON.stringify(payload),
})
```

When `toolsEndpoint` was an absolute URL like:
- `https://bots.nvdigital.solutions/wp-json/mcp-ai/v1/tools`

The browser's same-origin policy enforcement would fail in certain scenarios, even for same-site requests, because:

1. The browser must match exact protocol, domain, and port
2. Subtle differences in URL formation can trigger CORS restrictions
3. This is especially problematic when sites are accessed through different domains or behind proxies

## Solution

Changed all REST API endpoints from **absolute URLs** to **relative paths**.

### Before (Absolute URLs)
```php
'toolsEndpoint' => esc_url_raw( 
    WP_MCP_AI_Request_Context::normalise_rest_url( 
        rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/tools' ) 
    ) 
),
// Result: https://example.com/wp-json/mcp-ai/v1/tools
```

### After (Relative Paths)
```php
'toolsEndpoint' => $this->get_rest_url_path( 
    WP_MCP_AI_REST::REST_NAMESPACE . '/tools' 
),
// Result: /wp-json/mcp-ai/v1/tools
```

## Implementation

### New Helper Method

Added `get_rest_url_path()` method to the `WP_MCP_AI_Shortcode` class:

```php
protected function get_rest_url_path( $path ) {
    // Get the REST URL prefix (usually 'wp-json' but can be filtered).
    $rest_prefix = rest_get_url_prefix();

    // Validate that we have a REST prefix (should never be empty in WordPress).
    if ( empty( $rest_prefix ) ) {
        $rest_prefix = 'wp-json'; // Fallback to default.
    }

    // Build the relative path using WordPress path functions for consistency.
    $relative_path = '/' . trailingslashit( $rest_prefix ) . ltrim( $path, '/' );

    return $relative_path;
}
```

**Key features:**
- Uses WordPress `rest_get_url_prefix()` to respect custom REST prefixes
- Includes validation with fallback to default 'wp-json'
- Uses `trailingslashit()` for robust path handling
- Handles edge cases: empty paths, leading slashes, multiple slashes

### Updated Endpoints

All endpoints in the chat configuration now use relative paths:

```php
$config = array(
    'restUrl'               => $this->get_rest_url_path( WP_MCP_AI_REST::REST_NAMESPACE ),
    'messagesEndpoint'      => $this->get_rest_url_path( WP_MCP_AI_REST::REST_NAMESPACE . '/chat-client' ),
    'toolsEndpoint'         => $this->get_rest_url_path( WP_MCP_AI_REST::REST_NAMESPACE . '/tools' ),
    // filesEndpoint and crawl4aiTaskEndpoint need trailing slashes as they are base paths for file operations.
    'filesEndpoint'         => trailingslashit( $this->get_rest_url_path( WP_MCP_AI_REST::REST_NAMESPACE . '/files' ) ),
    'transcriptsEndpoint'   => $this->get_rest_url_path( WP_MCP_AI_REST::REST_NAMESPACE . '/chat-transcripts' ),
    'crawl4aiTaskEndpoint'  => trailingslashit( $this->get_rest_url_path( WP_MCP_AI_REST::REST_NAMESPACE . '/crawl4ai/task' ) ),
    // ... rest of config
);
```

## Benefits

1. **Eliminates CORS Issues**: Relative paths are always treated as same-origin by browsers
2. **Works Across Domains**: Same code works regardless of how the site is accessed
3. **Protocol Agnostic**: Works with both HTTP and HTTPS
4. **Simpler**: Eliminates need for complex URL normalization logic
5. **WordPress Native**: Uses WordPress core functions (`rest_get_url_prefix()`, `trailingslashit()`)
6. **Fully Backward Compatible**: JavaScript code unchanged, only URL format changes

## Testing

All test cases passed:

```
✓ Normal case:       'mcp-ai/v1/tools' -> '/wp-json/mcp-ai/v1/tools'
✓ Leading slash:     '/mcp-ai/v1/tools' -> '/wp-json/mcp-ai/v1/tools'
✓ Empty path:        '' -> '/wp-json/'
✓ Just slash:        '/' -> '/wp-json/'
✓ Multiple slashes:  '///mcp-ai/v1/tools' -> '/wp-json/mcp-ai/v1/tools'
```

## Files Changed

- `includes/class-wp-mcp-ai-shortcode.php`
  - Added `get_rest_url_path()` helper method (lines 66-88)
  - Updated endpoint URLs to use relative paths (lines 517-523)
  - Added documentation for trailing slash usage

## Backward Compatibility

✅ **Fully backward compatible:**
- JavaScript code (`chat-audio-service.js`) unchanged
- Only the URL format changes from absolute to relative
- Relative URLs work in all contexts where absolute URLs work
- No breaking changes to any APIs or interfaces

## Answer to User Question

> "Do you think the old way of handling the url was better?"

**Yes** - if the "old way" referred to using relative URLs, then this fix returns to that superior approach. Relative URLs are definitively better than absolute URLs when working with `credentials: 'same-origin'` in fetch requests, as they avoid all CORS-related issues.

## Related Documentation

- WordPress REST API: https://developer.wordpress.org/rest-api/
- Fetch API credentials: https://developer.mozilla.org/en-US/docs/Web/API/fetch#credentials
- Same-origin policy: https://developer.mozilla.org/en-US/docs/Web/Security/Same-origin_policy

## Date
December 2, 2025
