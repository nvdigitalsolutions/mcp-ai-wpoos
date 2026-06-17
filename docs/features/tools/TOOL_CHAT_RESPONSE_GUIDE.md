# Tool Chat Response Standardization Guide

## Overview

All tools must return responses that are compatible with the chat client's message persistence system. This ensures proper conversation continuity, complete LLM context, and a good user experience.

## The Problem

Without standardized responses, several issues can occur:

1. **Empty Assistant Messages**: Tools return data without display text
2. **Broken Conversation Flow**: Missing messages break agentic workflows
3. **Poor User Experience**: Chat UI has nothing to display
4. **Incomplete LLM Context**: AI loses conversation history

## The Solution

Use the `WP_MCP_AI_Tool_Chat_Response` trait to ensure all tool responses include proper chat-displayable content.

## Quick Start

### 1. Add the Trait

```php
class WP_MCP_AI_Tool_My_Tool implements WP_MCP_AI_Tool_Interface {
	use WP_MCP_AI_Tool_Chat_Response;
	
	// ... rest of tool implementation
}
```

### 2. Format Your Response

```php
public function execute( array $arguments = array(), array $context = array() ) {
	$data = $this->do_something();
	
	// ✅ Good - includes message
	return $this->format_chat_response(
		$data,
		'Operation completed successfully.'
	);
}
```

## Response Formatting Methods

### `format_chat_response()` - Universal Formatter

Use this for any tool response:

```php
// Basic usage
return $this->format_chat_response( $data, 'Custom message' );

// With options
return $this->format_chat_response( $data, 'Message', array(
	'include_data'   => true,  // Include original data
	'data_key'       => 'data', // Key for data in response
	'auto_generate'  => true,  // Auto-generate message if empty
	'message_prefix' => 'Success:', // Add prefix
	'message_suffix' => '(complete)', // Add suffix
) );
```

### `format_success_response()` - For Successful Operations

```php
return $this->format_success_response(
	'Post created successfully.',
	array(
		'post_id' => $post_id,
		'url'     => get_permalink( $post_id ),
	)
);
```

### `format_empty_result_response()` - For No Results

```php
if ( empty( $results ) ) {
	return $this->format_empty_result_response(
		'No posts found matching your criteria.'
	);
}
```

### `format_collection_response()` - For Lists/Arrays

```php
return $this->format_collection_response(
	$posts,
	'Retrieved recent posts.',
	array(
		'page'     => 1,
		'per_page' => 20,
		'total'    => 100,
	)
);
```

### `ensure_response_message()` - Wrap External API Responses

```php
$api_response = $this->call_external_api();

// Ensure it has a message field
return $this->ensure_response_message(
	$api_response,
	'API request completed.'
);
```

## Response Structure Standards

### Required Fields

Every tool response **MUST** include at least ONE of these fields:

- `message` (preferred) - User-facing message
- `text` (acceptable) - Descriptive text
- `summary` (acceptable) - Summary of results

### Recommended Structure

```php
array(
	'message' => 'User-facing message here',  // REQUIRED
	'data'    => array( /* your data */ ),    // Optional
	'success' => true,                        // Optional
)
```

## Common Patterns

### Pattern 1: Data Retrieval Tool

```php
public function execute( array $arguments = array(), array $context = array() ) {
	$items = $this->get_items( $arguments );
	
	if ( empty( $items ) ) {
		return $this->format_empty_result_response();
	}
	
	return $this->format_collection_response( $items );
}
```

### Pattern 2: Action/Creation Tool

```php
public function execute( array $arguments = array(), array $context = array() ) {
	$result = $this->create_something( $arguments );
	
	if ( is_wp_error( $result ) ) {
		return $result; // WP_Error is always valid
	}
	
	return $this->format_success_response(
		sprintf( 'Created item #%d successfully.', $result['id'] ),
		$result
	);
}
```

### Pattern 3: Link/Navigation Tool

```php
public function execute( array $arguments = array(), array $context = array() ) {
	return array(
		'message'     => 'OpenAI Usage Dashboard',
		'url'         => 'https://platform.openai.com/usage',
		'description' => 'View your API usage and costs.',
	);
}
```

### Pattern 4: File/Media Generation Tool

```php
public function execute( array $arguments = array(), array $context = array() ) {
	$attachment = $this->generate_image( $arguments );
	
	return array(
		'message'       => sprintf( 'Generated image (ID: %d)', $attachment['attachment_id'] ),
		'attachment_id' => $attachment['attachment_id'],
		'url'           => $attachment['url'],
		'text'          => $attachment['revised_prompt'], // For LLM context
	);
}
```

## Anti-Patterns (Don't Do This)

### ❌ Returning Empty Array

```php
// BAD - No message for chat
return array();

// GOOD - Has message
return $this->format_empty_result_response();
```

### ❌ Data Without Message

```php
// BAD - No display text
return array(
	'results' => $data,
	'count'   => count( $data ),
);

// GOOD - Includes message
return $this->format_collection_response( $data );
```

### ❌ Boolean/Numeric Only

```php
// BAD - Chat can't display this
return true;
return 42;

// GOOD - Wrapped with message
return $this->format_success_response( 'Operation completed.' );
return $this->format_chat_response( 42, 'Token count: 42' );
```

### ❌ Raw External API Response

```php
// BAD - May lack 'message' field
return $external_api_result;

// GOOD - Ensures message exists
return $this->ensure_response_message( $external_api_result );
```

## Auto-Message Generation

If you don't provide a message, the trait will auto-generate one:

```php
// Auto-generates "Found 5 items."
return $this->format_collection_response( $items );

// Auto-generates "No results found."
return $this->format_collection_response( array() );

// Auto-generates based on data structure
return $this->format_chat_response( $data );
```

## Testing Your Tool Responses

### Manual Test

```php
// In your tool execute() method, add temporary logging:
$response = $this->format_chat_response( $data, $message );
error_log( 'Tool Response: ' . wp_json_encode( $response ) );
return $response;
```

### Check for Message Field

```php
// Validate your response has a message
$response = $tool->execute( $args, $context );
assert( isset( $response['message'] ) && ! empty( $response['message'] ) );
```

### Browser Console Test

After tool execution, check chat state:

```javascript
// In browser console
const chatInstance = window.wpMcpAiChatInstances['assistant-id'];
console.log(chatInstance.conversation);

// Check last message
const lastMsg = chatInstance.conversation[chatInstance.conversation.length - 1];
console.log('Role:', lastMsg.role);
console.log('Content:', lastMsg.content);
console.log('Display:', lastMsg.display);
```

## Migration Guide for Existing Tools

### Step 1: Add the Trait

```php
class WP_MCP_AI_Tool_Existing implements WP_MCP_AI_Tool_Interface {
	use WP_MCP_AI_Tool_Chat_Response; // Add this line
	
	// ...
}
```

### Step 2: Update execute() Method

#### Before:
```php
public function execute( array $arguments = array(), array $context = array() ) {
	$results = $this->get_data();
	return $results; // ❌ May lack message
}
```

#### After:
```php
public function execute( array $arguments = array(), array $context = array() ) {
	$results = $this->get_data();
	return $this->format_chat_response(
		$results,
		$this->generate_user_message( $results )
	); // ✅ Guaranteed message
}
```

### Step 3: Handle Empty Results

#### Before:
```php
if ( empty( $results ) ) {
	return array(); // ❌ Empty response
}
```

#### After:
```php
if ( empty( $results ) ) {
	return $this->format_empty_result_response(); // ✅ Has message
}
```

## Best Practices

### 1. Always Provide Context

```php
// Good - Explains what happened
return $this->format_success_response(
	sprintf( 'Created %d posts from template.', $count )
);

// Bad - Generic message
return $this->format_success_response( 'Done.' );
```

### 2. Include Relevant IDs

```php
// Good - Includes actionable data
return array(
	'message'   => 'Post published successfully.',
	'post_id'   => $post_id,
	'edit_link' => admin_url( "post.php?post={$post_id}&action=edit" ),
);
```

### 3. Use Translated Strings

```php
// Good - Translatable
__( 'Operation completed successfully.', 'mcp-ai-wpoos' )

// Bad - Hardcoded
'Operation completed successfully.'
```

### 4. Handle Both Success and Error Cases

```php
$result = $this->do_operation();

if ( is_wp_error( $result ) ) {
	return $result; // WP_Error is valid
}

if ( false === $result ) {
	return new WP_Error(
		'operation_failed',
		__( 'Operation could not be completed.', 'mcp-ai-wpoos' )
	);
}

return $this->format_success_response(
	__( 'Operation completed.', 'mcp-ai-wpoos' ),
	$result
);
```

### 5. Consistent Field Names

Use these standard field names:

- `message` - Main user-facing message
- `data` - Structured data
- `items` - Array of items
- `count` - Item count
- `url` - Links
- `attachment_id` - File IDs
- `success` - Boolean status
- `error` - Error details

## Validation Checklist

Before releasing a tool, verify:

- [ ] Response includes `message`, `text`, or `summary` field
- [ ] Message is user-friendly and descriptive
- [ ] Empty results return explanation message
- [ ] Success/error cases both have messages
- [ ] Response structure is consistent with similar tools
- [ ] All strings are translatable
- [ ] Tool has been tested in chat client
- [ ] Conversation persists after page reload

## Examples by Tool Type

### Search/Query Tools
```php
return $this->format_collection_response( $results, null, array(
	'page'   => $page,
	'total'  => $total_results,
) );
```

### CRUD Tools
```php
return $this->format_success_response(
	sprintf( 'Updated %s (ID: %d)', $title, $id ),
	array( 'id' => $id, 'url' => $url )
);
```

### External API Tools
```php
$api_response = $this->call_api( $args );
return $this->ensure_response_message(
	$api_response,
	'API request completed successfully.'
);
```

### Media Generation Tools
```php
return array(
	'message'       => sprintf( 'Generated %s successfully.', $type ),
	'attachment_id' => $id,
	'url'           => $url,
	'text'          => $description, // LLM context
);
```

## Troubleshooting

### Issue: Empty chat bubbles after tool execution

**Solution**: Add message field to response

```php
return $this->format_chat_response( $data, 'Descriptive message' );
```

### Issue: Conversation breaks after page reload

**Solution**: Ensure all responses have message field

```php
return $this->ensure_response_message( $response );
```

### Issue: LLM loses context after tool use

**Solution**: Include both `message` (UI) and `text` (LLM context)

```php
return array(
	'message' => 'Generated image successfully.', // For UI
	'text'    => 'Image prompt: ' . $prompt,      // For LLM
	'url'     => $url,
);
```

## Related Documentation

- [Message Persistence Validation](./MESSAGE_PERSISTENCE_VALIDATION.md)
- [Tool Development Guide](./tools/tool-development.md)
- [Chat Architecture](./architecture/chat-architecture.md)

---

**Last Updated**: January 18, 2026  
**Version**: 1.1.0  
**Maintainer**: NV Digital Solutions
