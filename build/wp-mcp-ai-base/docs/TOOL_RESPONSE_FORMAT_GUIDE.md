# Tool Response Format Guide for Chat Client Compatibility

## Overview

This guide ensures all WP oOS tools return responses that can be properly displayed in the chat widget/shortcode interface.

## Problem

The chat client's `extractGenericToolResponse()` function (assets/js/chat.js:3769) looks for specific properties in tool responses to extract displayable text. Without these properties, tool results won't appear in the chat interface.

## Solution

All tools must return at least ONE of these properties in their response array:

### Required Displayable Properties (in order of preference):

1. **`summary`** - Brief, user-friendly summary of the result (RECOMMENDED)
2. **`message`** - Primary message string
3. **`text`** - Alternative text content
4. **`title`** - Title/heading of the result
5. **`notices`** - Array of notice strings
6. **`messages`** - Array of message strings

## Best Practices

### ✅ Good Example (with summary):

```php
public function execute( array $arguments = array(), array $context = array() ) {
    // ... validation and processing ...
    
    $results = $this->fetch_data();
    
    return array(
        'summary' => sprintf( __( 'Found %d results', 'wp-mcp-ai' ), count( $results ) ),
        'results' => $results,
        'metadata' => array(
            'query' => $arguments,
            'total_count' => count( $results ),
        ),
    );
}
```

### ❌ Bad Example (no displayable property):

```php
public function execute( array $arguments = array(), array $context = array() ) {
    // ... validation and processing ...
    
    return array(
        'data' => $results,
        'count' => count( $results ),
        'query' => $arguments,
    );
    // Missing: summary, message, text, title, notices, or messages!
}
```

## Implementation Guide

### For Informational Tools (get_*, list_*, check_*)

```php
return array(
    'summary' => __( 'Operation completed successfully', 'wp-mcp-ai' ),
    // or with dynamic content:
    'summary' => sprintf( __( 'Found %d items', 'wp-mcp-ai' ), $item_count ),
    // ... rest of the data ...
);
```

### For Action Tools (create_*, update_*, delete_*, post_*)

```php
return array(
    'summary' => sprintf( __( 'Created %s successfully', 'wp-mcp-ai' ), $item_name ),
    // or:
    'message' => __( 'Post published to Facebook', 'wp-mcp-ai' ),
    // ... rest of the data ...
);
```

### For Tools Returning Links/URLs

```php
return array(
    'summary' => __( 'OpenAI Usage Dashboard', 'wp-mcp-ai' ),
    'label' => __( 'OpenAI Usage Dashboard', 'wp-mcp-ai' ),
    'url' => 'https://platform.openai.com/usage',
    'description' => __( 'Visit the dashboard...', 'wp-mcp-ai' ),
);
```

## Tools Fixed (as of this commit)

### ✅ Already Fixed:
1. check-site-security
2. check-wp-cli
3. count-tokens
4. generate-auth0-token
5. get-gdacs-events
6. get-import-duty
7. get-site-summary
8. get-system-logs
9. get-user-info
10. open-openai-logs
11. open-openai-usage
12. post-facebook-instagram

### 🔧 Need Fixing:

The following tools still need displayable properties added:

1. **get-facebook-instagram-insights** - Add summary with insights count
2. **get-google-analytics-report** - Add summary with report name
3. **get-google-business-insights** - Add summary with insights status
4. **get-jetformbuilder-forms** - Add summary with form count
5. **get-jetformbuilder-submissions** - Add summary with submission count
6. **get-linkedin-insights** - Add summary with insights data
7. **get-quickbooks-report** - Add summary with report name
8. **get-tiktok-insights** - Add summary with metrics summary
9. **list-jetengine-routes** - Add summary with route count
10. **post-tiktok-video** - Add summary "Video posted to TikTok successfully"
11. **query-mesh-intelligent** - Add summary with query result status

## Testing

After adding displayable properties to a tool:

1. Test via REST API:
   ```bash
   curl -X POST http://your-site/wp-json/mcp-ai/v1/tools \
     -H "X-WP-Nonce: YOUR_NONCE" \
     -H "Content-Type: application/json" \
     -d '{"assistant_id": 123, "tool": "your_tool_slug", "arguments": {}}'
   ```

2. Verify the response includes your `summary` or other displayable property

3. Test in chat widget:
   - Open a chat interface
   - Trigger the tool
   - Verify the summary appears in the chat message

## Chat Client Display Logic

The chat client processes tool results in this order:

```javascript
// From assets/js/chat.js:3769 extractGenericToolResponse()
if (result.message) {
    text = result.message.trim();
} else if (result.text) {
    text = result.text.trim();
} else if (result.summary) {
    text = result.summary.trim();
} else if (result.title) {
    text = result.title.trim();
} else if (result.notices && result.notices.length) {
    text = result.notices[0].trim();
} else if (result.messages && result.messages.length) {
    text = result.messages[0].trim();
}
```

## Additional Notes

- **Localization**: Always wrap display strings in translation functions (`__()`, `sprintf()`)
- **Dynamic Content**: Use `sprintf()` for dynamic values in summaries
- **Error Handling**: WP_Error responses are handled separately by the chat client
- **Nested Data**: The summary should be at the top level of the returned array
- **Backwards Compatibility**: Adding a summary doesn't break existing integrations

## Reference Implementation

See `includes/tools/class-wp-mcp-ai-tool-get-system-logs.php` for a complete example of proper tool response formatting.

## Related Files

- `assets/js/chat.js` - Chat client that displays tool results
- `includes/class-wp-mcp-ai-rest.php` - REST API handler for tool execution
- `includes/tools/class-wp-mcp-ai-tool-interface.php` - Tool interface definition
