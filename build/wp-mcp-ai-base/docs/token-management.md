# Token Management and Parallelization Controls

This document describes the token payload reduction and parallelization features implemented in WP oOS.

## Overview

To optimize API usage, reduce costs, and prevent rate limiting, WP oOS now includes:

1. **Pre-flight token counting** - Estimate and validate token usage before API calls
2. **Input chunking** - Split large documents into manageable pieces
3. **Document summarization** - Intelligently condense oversized content
4. **Token budget enforcement** - Strict limits on input token usage per request
5. **Parallelization control** - Configurable limits on concurrent AI requests

## Features

### 1. Pre-flight Token Counting

Before sending requests to AI providers, WP oOS estimates the token count to prevent exceeding limits.

**OpenAI**: Uses a character-based heuristic (~4 characters per token)
```php
$client = new WP_MCP_AI_OpenAI_Client();
$token_count = $client->count_tokens( $messages );
```

**Gemini**: Uses native API token counting
```php
$client = new WP_MCP_AI_Gemini_Client();
$result = $client->count_tokens( $messages, $options );
```

### 2. Text Chunking

The `WP_MCP_AI_Text_Chunker` class provides intelligent text splitting that preserves semantic boundaries.

```php
// Chunk text with default 1200 char chunks and 200 char overlap
$chunks = WP_MCP_AI_Text_Chunker::chunk_text( $long_text );

// Custom chunk size and overlap
$chunks = WP_MCP_AI_Text_Chunker::chunk_text( $text, 2000, 300 );

// Estimate tokens
$tokens = WP_MCP_AI_Text_Chunker::estimate_tokens( $text );

// Trim text to fit token budget
$trimmed = WP_MCP_AI_Text_Chunker::trim_to_token_budget( $text, 1000 );
```

**Features**:
- Paragraph-aware splitting (prefers paragraph boundaries)
- Sentence-aware splitting (fallback to sentence boundaries)
- Configurable chunk sizes and overlaps
- Character-based chunking as last resort

### 3. Document Summarization

The `WP_MCP_AI_Document_Summarizer` class intelligently condenses large documents instead of simple truncation.

```php
// Auto-summarize if document exceeds 4000 chars
$summary = WP_MCP_AI_Document_Summarizer::summarize_if_needed( $content );

// Force summarization with custom target
$summary = WP_MCP_AI_Document_Summarizer::summarize_if_needed(
    $content,
    array(
        'force_summarize' => true,
        'target_chars'    => 1000,
    )
);

// Summarize multiple documents with total budget
$summarized_docs = WP_MCP_AI_Document_Summarizer::summarize_document_set(
    $documents,
    12000  // Total character budget
);
```

**Strategy**:
- Extracts beginning, middle, and end sections
- Preserves paragraph boundaries
- Adds metadata note about summarization
- Proportional budgeting for document sets

### 4. Token Budget Enforcement

The Resource Manager enforces strict token limits on incoming requests.

```php
$resource_mgr = WP_MCP_AI_Resource_Manager::instance();

// Get current max input token limit (default: 120,000)
$max_tokens = $resource_mgr->get_max_input_tokens();

// Set custom limit (1,000 - 500,000 range)
$resource_mgr->set_max_input_tokens( 100000 );

// Validate a token count
$result = $resource_mgr->validate_token_budget( $token_count );
if ( is_wp_error( $result ) ) {
    // Handle over-budget request
}
```

**REST API Integration**:
The `/chat` endpoint automatically:
1. Estimates token count of incoming messages
2. Compares against configured budget
3. Attempts to trim messages if over budget
4. Returns 413 error if still over budget after trimming
5. Logs all token budget events

### 5. Parallelization Control

Control the number of concurrent AI API requests to prevent overwhelming the service.

```php
$resource_mgr = WP_MCP_AI_Resource_Manager::instance();

// Get current max concurrent requests (default: 2)
$max_concurrent = $resource_mgr->get_max_concurrent_requests();

// Set limit (1-10 range)
$resource_mgr->set_max_concurrent_requests( 1 );
```

The Job Queue Manager automatically respects this setting:
```php
// Uses resource manager setting
$result = WP_MCP_AI_Job_Queue_Manager::process_queue();

// Or explicitly specify
$result = WP_MCP_AI_Job_Queue_Manager::process_queue( 2 );
```

## Configuration

### Via Code

```php
add_filter( 'wp_mcp_ai_max_input_tokens', function( $max_tokens ) {
    return 80000;  // Reduce to 80k tokens
}, 10, 1 );

add_filter( 'wp_mcp_ai_max_concurrent_requests', function( $max_concurrent ) {
    return 1;  // Only 1 concurrent request
}, 10, 1 );
```

### Via Settings

Settings are stored in WordPress options:
```php
$settings = get_option( 'wp_mcp_ai_settings', array() );
$settings['max_input_tokens'] = 100000;
$settings['max_concurrent_requests'] = 2;
update_option( 'wp_mcp_ai_settings', $settings );
```

## Memory Document Handling

Memory documents (uploaded files used as context) are automatically processed:

1. **Size Validation**: Files exceeding limits are rejected
2. **Text Extraction**: Content extracted based on MIME type
3. **Chunking**: Large files split into manageable chunks
4. **Summarization**: Files >2x the chunk limit are summarized instead of truncated
5. **Total Budget**: All memory documents together respect total character limit (12,000 default)

This happens transparently in the REST API when `memory_files` are included in chat requests.

## Monitoring

Token management events are logged for monitoring:

```php
// Check recent token-related logs
$events = get_option( 'wp_mcp_ai_recent_activity', array() );

foreach ( $events as $event ) {
    if ( in_array( $event['event'], array(
        'chat_request_token_budget_exceeded',
        'chat_request_trimmed_to_budget',
        'memory_document_summarization',
        'openai_token_count_estimated',
        'gemini_count_tokens',
    ) ) ) {
        // Process token management event
    }
}
```

## Performance Impact

**Token Counting**: Minimal - uses character counting heuristic
**Chunking**: Low - only for large documents
**Summarization**: Medium - only when documents exceed 2x budget threshold  
**Budget Enforcement**: Minimal - simple integer comparison

All operations are optimized to minimize processing overhead while maximizing token efficiency.

## Best Practices

1. **Set realistic budgets**: Consider your model's context window and typical usage
2. **Monitor logs**: Review token budget events to tune settings
3. **Use summarization wisely**: For very large documents, pre-process before upload
4. **Limit concurrent requests**: Start with 1-2 and increase only if needed
5. **Test with estimates**: Use token counting before expensive API calls

## Backwards Compatibility

All features are backwards compatible:
- Default settings maintain reasonable limits
- Existing API calls work unchanged
- No breaking changes to public APIs
- Filter hooks allow customization

## Future Enhancements

Planned improvements include:
- Admin UI for configuring token budgets
- Per-assistant token limits
- Token usage analytics dashboard
- More sophisticated summarization algorithms
- Integration with external tokenizers (tiktoken, etc.)
