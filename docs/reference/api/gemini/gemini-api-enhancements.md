# Gemini API Enhancements

This document describes the enhanced Gemini API integration features available in Open Operator System (WP oOS).

## Overview

The Gemini client wrapper (`WP_MCP_AI_Gemini_Client`) provides six additional capabilities beyond basic chat completion:

1. **List Models** - Dynamically fetch available Gemini models
2. **Count Tokens** - Get token counts for budget management
3. **Create Embeddings** - Generate text embeddings for RAG/semantic search
4. **Batch Embeddings** - ⭐ NEW - Process multiple embeddings in a single API call
5. **Streaming Support** - Real-time streaming responses via Server-Sent Events (SSE)
6. **Safety Settings** - ⭐ NEW - Configure content safety thresholds

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

## Batch Embeddings ⭐ NEW

Generate embeddings for multiple text inputs in a single API request. This is significantly more efficient than calling `create_embedding()` repeatedly.

### Method Signature

```php
public function batch_embed_content( array $texts, array $options = array() )
```

### Parameters

- `$texts` (array) - Array of text strings to embed
- `$options` (array) - Optional parameters:
  - `model` (string) - Embedding model (default: `text-embedding-004`)
  - `task_type` (string) - Task optimization type (see Task Types above)
  - `timeout` (int) - Request timeout in seconds

### Returns

- `array` - Batch embedding data with `embeddings` array
- `WP_Error` - On failure

### Example Usage

```php
$client = new WP_MCP_AI_Gemini_Client();

// Prepare multiple texts for embedding.
$posts = get_posts( array( 'posts_per_page' => 10 ) );
$texts = array_map( function( $post ) {
	return $post->post_title . ' ' . $post->post_content;
}, $posts );

// Generate embeddings in batch.
$result = $client->batch_embed_content(
	$texts,
	array(
		'model'     => 'text-embedding-004',
		'task_type' => 'RETRIEVAL_DOCUMENT',
	)
);

if ( ! is_wp_error( $result ) ) {
	foreach ( $result['embeddings'] as $index => $embedding ) {
		$vector = $embedding['values'];
		// Store embedding vector for the corresponding post.
		update_post_meta( $posts[ $index ]->ID, '_embedding_vector', $vector );
	}
}
```

### Response Structure

```json
{
  "embeddings": [
    {
      "values": [0.1234, -0.5678, 0.9012, ...]
    },
    {
      "values": [0.2345, -0.6789, 0.0123, ...]
    },
    ...
  ]
}
```

### Performance Benefits

- **Reduced API Calls** - Process N texts in 1 request instead of N requests
- **Lower Latency** - Single round-trip to API instead of multiple
- **Cost Efficiency** - Reduced overhead per embedding
- **Rate Limit Friendly** - Less likely to hit rate limits

### Filter Hook

Modify the batch embedding payload before sending:

```php
add_filter( 'wp_mcp_ai_gemini_batch_embedding_payload', function( $payload, $options, $texts ) {
	// Customize payload.
	// Example: Add custom metadata.
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

## Safety Settings ⭐ NEW

Configure content safety thresholds for different harm categories to control the type of content Gemini will generate or block.

### Overview

Gemini allows configuring safety settings for four harm categories:
- `HARM_CATEGORY_HARASSMENT`
- `HARM_CATEGORY_HATE_SPEECH`
- `HARM_CATEGORY_SEXUALLY_EXPLICIT`
- `HARM_CATEGORY_DANGEROUS_CONTENT`

Each category can be set to one of five threshold levels:
- `BLOCK_NONE` - Do not block any content
- `BLOCK_ONLY_HIGH` - Block only high-probability harmful content
- `BLOCK_MEDIUM_AND_ABOVE` - Block medium and high-probability harmful content (Default)
- `BLOCK_LOW_AND_ABOVE` - Block low, medium, and high-probability harmful content
- `HARM_BLOCK_THRESHOLD_UNSPECIFIED` - Use API default threshold

### Usage with Chat Completion

Safety settings can be passed as an option to both `create_chat_completion()` and `stream_chat_completion()` methods:

```php
$client = new WP_MCP_AI_Gemini_Client();

$messages = array(
	array(
		'role'    => 'user',
		'content' => 'Write a story about...',
	),
);

$result = $client->create_chat_completion(
	$messages,
	array(
		'model'           => 'gemini-1.5-flash',
		'safety_settings' => array(
			'HARM_CATEGORY_HARASSMENT'        => 'BLOCK_MEDIUM_AND_ABOVE',
			'HARM_CATEGORY_HATE_SPEECH'       => 'BLOCK_MEDIUM_AND_ABOVE',
			'HARM_CATEGORY_SEXUALLY_EXPLICIT' => 'BLOCK_LOW_AND_ABOVE',
			'HARM_CATEGORY_DANGEROUS_CONTENT' => 'BLOCK_ONLY_HIGH',
		),
	)
);
```

### Array Format

Safety settings can also be provided in array format for more explicit configuration:

```php
$result = $client->create_chat_completion(
	$messages,
	array(
		'safety_settings' => array(
			array(
				'category'  => 'HARM_CATEGORY_HARASSMENT',
				'threshold' => 'BLOCK_MEDIUM_AND_ABOVE',
			),
			array(
				'category'  => 'HARM_CATEGORY_HATE_SPEECH',
				'threshold' => 'BLOCK_ONLY_HIGH',
			),
		),
	)
);
```

### Use Cases

- **Strict Moderation** - Use `BLOCK_LOW_AND_ABOVE` for public-facing applications
- **Balanced Approach** - Use `BLOCK_MEDIUM_AND_ABOVE` (default) for general use
- **Permissive Content** - Use `BLOCK_ONLY_HIGH` or `BLOCK_NONE` for creative writing tools
- **Custom Per-Assistant** - Configure different settings per assistant type

### Example: Strict Safety Preset

```php
/**
 * Get strict safety settings for public chat.
 */
function wp_mcp_ai_get_strict_safety_settings() {
	return array(
		'HARM_CATEGORY_HARASSMENT'        => 'BLOCK_LOW_AND_ABOVE',
		'HARM_CATEGORY_HATE_SPEECH'       => 'BLOCK_LOW_AND_ABOVE',
		'HARM_CATEGORY_SEXUALLY_EXPLICIT' => 'BLOCK_LOW_AND_ABOVE',
		'HARM_CATEGORY_DANGEROUS_CONTENT' => 'BLOCK_MEDIUM_AND_ABOVE',
	);
}

$result = $client->create_chat_completion(
	$messages,
	array(
		'safety_settings' => wp_mcp_ai_get_strict_safety_settings(),
	)
);
```

### Example: Permissive Safety Preset

```php
/**
 * Get permissive safety settings for creative writing.
 */
function wp_mcp_ai_get_permissive_safety_settings() {
	return array(
		'HARM_CATEGORY_HARASSMENT'        => 'BLOCK_ONLY_HIGH',
		'HARM_CATEGORY_HATE_SPEECH'       => 'BLOCK_ONLY_HIGH',
		'HARM_CATEGORY_SEXUALLY_EXPLICIT' => 'BLOCK_ONLY_HIGH',
		'HARM_CATEGORY_DANGEROUS_CONTENT' => 'BLOCK_ONLY_HIGH',
	);
}

$result = $client->create_chat_completion(
	$messages,
	array(
		'safety_settings' => wp_mcp_ai_get_permissive_safety_settings(),
	)
);
```

### Handling Safety Blocks

When content is blocked by safety settings, Gemini returns a response with safety ratings:

```php
$result = $client->create_chat_completion( $messages, $options );

if ( ! is_wp_error( $result ) ) {
	// Check if response was blocked for safety reasons.
	if ( isset( $result['blocked'] ) && $result['blocked'] ) {
		$safety_ratings = isset( $result['safety_ratings'] ) ? $result['safety_ratings'] : array();
		// Handle safety block - show user-friendly message.
		$message = __( 'The response was blocked due to content safety policies.', 'wp-mcp-ai' );
	} else {
		// Process normal response.
		$text = $result['choices'][0]['message']['content'][0]['text'];
	}
}
```

### Validation

The implementation automatically validates:
- Invalid categories are filtered out
- Invalid thresholds are filtered out
- Only valid combinations are sent to the API

This prevents API errors from malformed safety settings.

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
- `wp_mcp_ai_missing_texts` - ⭐ NEW - Text array required (batch embeddings)
- `wp_mcp_ai_empty_batch` - ⭐ NEW - No valid texts after sanitization (batch embeddings)
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
4. **Batch Processing** - Group multiple operations when possible (use `batch_embed_content()` for embeddings)
5. **Streaming** - Use streaming for long-running operations to improve UX

## Related Documentation

- [ENABLE-SSE-STREAMING.md](../../../features/streaming/ENABLE-SSE-STREAMING.md) - SSE streaming implementation
- [RESOURCE-MANAGEMENT.md](../../../features/performance/RESOURCE-MANAGEMENT.md) - Token and budget management
- [rest-api.md](../rest-api.md) - REST API endpoints
- [GEMINI_INTEGRATION_GAP_ANALYSIS.md](../../../features/ai-providers/gemini/GEMINI_INTEGRATION_GAP_ANALYSIS.md) - Future enhancements

## Testing

Comprehensive test coverage is available:

```bash
# Run all Gemini client tests.
vendor/bin/phpunit tests/test-gemini-client.php

# Run batch embedding tests.
vendor/bin/phpunit tests/test-gemini-batch-embed.php

# Run safety settings tests.
vendor/bin/phpunit tests/test-gemini-safety-settings.php
```

## API References

- [Google Gemini API Documentation](https://ai.google.dev/docs)
- [Gemini Models List](https://ai.google.dev/models/gemini)
- [Gemini Embeddings](https://ai.google.dev/docs/embeddings)
- [Gemini Streaming](https://ai.google.dev/docs/streaming)
