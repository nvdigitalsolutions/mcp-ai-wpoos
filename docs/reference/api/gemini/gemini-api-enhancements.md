# Gemini API Enhancements

This document describes the enhanced Gemini API integration features available in Open Operator System (WP oOS).

## Overview

The Gemini client wrapper (`WP_MCP_AI_Gemini_Client`) provides four additional capabilities beyond basic chat completion:

1. **List Models** - Dynamically fetch available Gemini models
2. **Count Tokens** - Get token counts for budget management
3. **Create Embeddings** - Generate text embeddings for RAG/semantic search
4. **Streaming Support** - Real-time streaming responses via Server-Sent Events (SSE)

## List Models

Retrieve a list of available Gemini models dynamically from the API.

### Method Signature

```php
public function list_models( array $options = array() )
```

### Parameters

- `$options` (array) - Optional parameters:
  - `page_size` (int) - Number of models per page
  - `page_token` (string) - Token for pagination
  - `timeout` (int) - Request timeout in seconds

### Returns

- `array` - Array containing models data with pagination info
- `WP_Error` - On failure

### Example Usage

```php
$client = new WP_MCP_AI_Gemini_Client();
$result = $client->list_models(
	array(
		'page_size' => 50,
	)
);

if ( is_wp_error( $result ) ) {
	// Handle error.
	error_log( $result->get_error_message() );
} else {
	// Process models.
	foreach ( $result['models'] as $model ) {
		echo $model['displayName'] . "\n";
	}
}
```

### Response Structure

```json
{
  "models": [
    {
      "name": "models/gemini-1.5-flash",
      "displayName": "Gemini 1.5 Flash",
      "description": "Fast and efficient model",
      "supportedGenerationMethods": ["generateContent", "countTokens"]
    }
  ],
  "nextPageToken": "..."
}
```

## Count Tokens

Count tokens in a message payload to manage budgets and predict costs before making API calls.

### Method Signature

```php
public function count_tokens( array $messages, array $options = array() )
```

### Parameters

- `$messages` (array) - Message payload in OpenAI-compatible format
- `$options` (array) - Optional parameters:
  - `model` (string) - Model to use for token counting
  - `timeout` (int) - Request timeout in seconds

### Returns

- `array` - Token count data
- `WP_Error` - On failure

### Example Usage

```php
$client = new WP_MCP_AI_Gemini_Client();

$messages = array(
	array(
		'role'    => 'user',
		'content' => 'What is the capital of France?',
	),
);

$result = $client->count_tokens(
	$messages,
	array(
		'model' => 'gemini-1.5-flash',
	)
);

if ( ! is_wp_error( $result ) ) {
	echo 'Total tokens: ' . $result['totalTokens'];
}
```

### Response Structure

```json
{
  "totalTokens": 42
}
```

### Use Cases

- **Budget Management** - Check token counts before sending requests
- **Cost Estimation** - Calculate estimated costs for conversations
- **Context Window Management** - Ensure messages fit within model limits
- **Usage Analytics** - Track token consumption patterns

## Create Embeddings

Generate text embeddings for semantic search, RAG (Retrieval Augmented Generation), and similarity comparisons.

### Method Signature

```php
public function create_embedding( $text, array $options = array() )
```

### Parameters

- `$text` (string) - Text content to embed
- `$options` (array) - Optional parameters:
  - `model` (string) - Embedding model (default: `text-embedding-004`)
  - `task_type` (string) - Task optimization type
  - `title` (string) - Document title (for RETRIEVAL_DOCUMENT)
  - `timeout` (int) - Request timeout in seconds

### Task Types

- `RETRIEVAL_QUERY` - Optimize for search queries
- `RETRIEVAL_DOCUMENT` - Optimize for document indexing
- `SEMANTIC_SIMILARITY` - Optimize for similarity comparisons
- `CLASSIFICATION` - Optimize for text classification
- `CLUSTERING` - Optimize for document clustering

### Returns

- `array` - Embedding data with vector values
- `WP_Error` - On failure

### Example Usage

```php
$client = new WP_MCP_AI_Gemini_Client();

// Create embeddings for a document.
$result = $client->create_embedding(
	'The quick brown fox jumps over the lazy dog',
	array(
		'model'     => 'text-embedding-004',
		'task_type' => 'RETRIEVAL_DOCUMENT',
		'title'     => 'Sample Document',
	)
);

if ( ! is_wp_error( $result ) ) {
	$embedding_vector = $result['embedding']['values'];
	// Store or use the embedding vector.
	// Vector typically has 768 dimensions for text-embedding-004.
}
```

### Response Structure

```json
{
  "embedding": {
    "values": [0.1234, -0.5678, 0.9012, ...]
  }
}
```

### Use Cases

- **Semantic Search** - Find similar content based on meaning
- **RAG Systems** - Build retrieval-augmented generation systems
- **Content Recommendations** - Suggest related articles or products
- **Duplicate Detection** - Find similar or duplicate content
- **Knowledge Base** - Build searchable knowledge repositories

### Filter Hook

Modify the embedding payload before sending:

```php
add_filter( 'wp_mcp_ai_gemini_embedding_payload', function( $payload, $options, $text ) {
	// Customize payload.
	return $payload;
}, 10, 3 );
```

## Streaming Support

Get real-time streaming responses for improved user experience with long-form content generation.

### Method Signature

```php
public function stream_chat_completion( array $messages, array $options = array(), $callback = null )
```

### Parameters

- `$messages` (array) - Message payload in OpenAI-compatible format
- `$options` (array) - Optional parameters (same as `create_chat_completion`)
- `$callback` (callable|null) - Callback function to process streaming chunks

### Callback Signature

```php
function callback( $chunk, $type ) {
	// $chunk: The data chunk (string for text, array for function_call)
	// $type: 'text' or 'function_call'
}
```

### Returns

- `array` - Final accumulated response (OpenAI-compatible format)
- `WP_Error` - On failure

### Example Usage

```php
$client = new WP_MCP_AI_Gemini_Client();

$messages = array(
	array(
		'role'    => 'user',
		'content' => 'Write a short story about a robot.',
	),
);

// Process chunks in real-time.
$result = $client->stream_chat_completion(
	$messages,
	array(
		'model'       => 'gemini-1.5-flash',
		'temperature' => 0.7,
	),
	function ( $chunk, $type ) {
		if ( 'text' === $type ) {
			// Output each chunk as it arrives.
			echo $chunk;
			flush();
		} elseif ( 'function_call' === $type ) {
			// Handle tool calls.
			error_log( 'Function call: ' . wp_json_encode( $chunk ) );
		}
	}
);

if ( ! is_wp_error( $result ) ) {
	// Final response with accumulated content and usage data.
	$full_response = $result['choices'][0]['message']['content'][0]['text'];
	$usage         = $result['usage'];
}
```

### Response Structure

The final response matches the OpenAI-compatible format:

```json
{
  "choices": [
    {
      "index": 0,
      "message": {
        "role": "assistant",
        "content": [
          {
            "type": "text",
            "text": "Complete accumulated response text..."
          }
        ]
      }
    }
  ],
  "provider": "gemini",
  "model": "gemini-1.5-flash",
  "usage": {
    "prompt_tokens": 15,
    "completion_tokens": 125
  }
}
```

### SSE Format

The underlying API returns Server-Sent Events (SSE):

```
data: {"candidates":[{"content":{"parts":[{"text":"Hello, "}]}}]}
data: {"candidates":[{"content":{"parts":[{"text":"how can I help you?"}]}}],"usageMetadata":{"promptTokenCount":10,"candidatesTokenCount":20}}
data: [DONE]
```

### Use Cases

- **Interactive Chat** - Display responses as they're generated
- **Long-Form Content** - Show progress during article generation
- **Real-Time Transcription** - Stream audio transcription results
- **Progress Indicators** - Provide user feedback during processing

## Error Handling

All methods return `WP_Error` objects on failure. Check for errors using:

```php
$result = $client->list_models();

if ( is_wp_error( $result ) ) {
	$error_code    = $result->get_error_code();
	$error_message = $result->get_error_message();
	$error_data    = $result->get_error_data();
	
	// Handle specific errors.
	switch ( $error_code ) {
		case 'wp_mcp_ai_missing_gemini_api_key':
			// Guide user to configure API key.
			break;
		case 'wp_mcp_ai_api_error':
			// API returned an error.
			$http_status = $error_data['status'];
			break;
		case 'wp_mcp_ai_http_error':
			// Network/transport error.
			break;
	}
}
```

## Common Error Codes

- `wp_mcp_ai_missing_gemini_api_key` - API key not configured
- `wp_mcp_ai_missing_gemini_model` - Model not specified
- `wp_mcp_ai_missing_text` - Text content required (embeddings)
- `wp_mcp_ai_api_error` - API returned an error response
- `wp_mcp_ai_http_error` - Network/transport failure
- `wp_mcp_ai_invalid_response` - Malformed JSON response

## Logging

All methods log events and errors through `WP_MCP_AI_Logger`:

```php
// Enable logging in settings or via constant.
define( 'WP_MCP_AI_DEBUG', true );

// Check recent logs.
$errors   = get_option( 'wp_mcp_ai_recent_errors', array() );
$activity = get_option( 'wp_mcp_ai_recent_activity', array() );
```

## Security Considerations

1. **API Key Storage** - Keys are stored in WordPress options, not in code
2. **Input Sanitization** - All user inputs are sanitized before API calls
3. **Capability Checks** - Use WordPress capabilities to restrict access
4. **Rate Limiting** - Consider implementing rate limits for public endpoints
5. **HTTPS Only** - All API requests use HTTPS

## Performance Tips

1. **Token Counting** - Use `count_tokens()` before large requests to validate
2. **Caching** - Cache embedding vectors to avoid redundant API calls
3. **Timeouts** - Set appropriate timeouts for different operations
4. **Batch Processing** - Group multiple operations when possible
5. **Streaming** - Use streaming for long-running operations to improve UX

## Related Documentation

- [ENABLE-SSE-STREAMING.md](ENABLE-SSE-STREAMING.md) - SSE streaming implementation
- [RESOURCE-MANAGEMENT.md](RESOURCE-MANAGEMENT.md) - Token and budget management
- [rest-api.md](rest-api.md) - REST API endpoints

## Testing

Comprehensive test coverage is available in `tests/test-gemini-client.php`:

```bash
# Run Gemini client tests.
vendor/bin/phpunit tests/test-gemini-client.php
```

## API References

- [Google Gemini API Documentation](https://ai.google.dev/docs)
- [Gemini Models List](https://ai.google.dev/models/gemini)
- [Gemini Embeddings](https://ai.google.dev/docs/embeddings)
- [Gemini Streaming](https://ai.google.dev/docs/streaming)
