# Tool LLM Sanitization

## Overview

When tools execute in the agentic workflow loop, their results are passed back to the LLM so it can continue processing. However, tool responses often contain large or verbose data that bloats the context window without providing value to the LLM's reasoning process.

This document describes the tool sanitization system that strips unnecessary payload before passing results to the LLM, while preserving the full response for frontend display.

## Architecture

### Two-Track Response Handling

The system maintains two versions of each tool result:

1. **Full Response** - Stored in `tool_results[]` array sent to frontend
   - Contains complete tool output including images, large content, verbose metadata
   - Used for chat UI display, attachments, downloads
   - Never sent back to LLM

2. **Sanitized Response** - Added to conversation `messages[]` for LLM
   - Strips large binary data, duplicate content, verbose metadata
   - Keeps essential information LLM needs (IDs, URLs, status, actual content when needed)
   - Reduces token consumption in agentic loops

### Sanitization Flow

```
Tool Execution
      ↓
  Full Result
      ↓
      ├──→ tool_results[] ──→ Frontend Display (FULL)
      |
      └──→ sanitize_tool_result_for_llm()
              ↓
          Check: Does tool implement
          WP_MCP_AI_Tool_LLM_Sanitizer_Interface?
              ↓
        YES ──→ tool->sanitize_for_llm() (CUSTOM RULES)
              ↓
         NO ──→ generic_sanitize_for_llm() (DEFAULT RULES)
              ↓
          Sanitized Result
              ↓
        messages[] ──→ LLM Context (STRIPPED)
```

## Per-Tool Custom Sanitization

### Why Per-Tool Rules?

Different tools return different data structures and have different needs:

- **Image generation**: LLM only needs metadata (ID, URL), not 100KB+ of base64 data
- **Data retrieval (crawl4ai)**: LLM DOES need content (markdown, text) to work with, but not duplicate raw responses
- **API calls**: May need some response data, but not verbose headers or timestamps

A centralized approach cannot handle these varied requirements maintainably.

### Implementing Custom Sanitization

Tools can implement the `WP_MCP_AI_Tool_LLM_Sanitizer_Interface` to define their own rules:

```php
<?php
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-llm-sanitizer-interface.php';

class My_Tool implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_LLM_Sanitizer_Interface {
    
    public function execute( array $arguments = array(), array $context = array() ) {
        // Tool execution...
        return array(
            'id'          => 123,
            'url'         => 'https://example.com/result',
            'large_data'  => str_repeat( 'X', 1000000 ), // 1MB of data
            'raw_api'     => array( /* huge API response */ ),
            'metadata'    => array(
                'headers'      => array( /* verbose headers */ ),
                'retrieved_at' => '2024-01-01',
            ),
        );
    }
    
    /**
     * Sanitize result for LLM consumption.
     *
     * Strip what LLM doesn't need, keep what it does.
     */
    public function sanitize_for_llm( $result ) {
        if ( ! is_array( $result ) ) {
            return $result;
        }
        
        $sanitized = $result;
        
        // Remove large data LLM doesn't need.
        unset( $sanitized['large_data'] );
        unset( $sanitized['raw_api'] );
        
        // Clean metadata.
        if ( isset( $sanitized['metadata'] ) ) {
            unset( $sanitized['metadata']['headers'] );
            unset( $sanitized['metadata']['retrieved_at'] );
        }
        
        // Keep: id, url, status, etc. - essential for LLM reasoning
        
        return $sanitized;
    }
}
```

### Guidelines for Custom Sanitization

**Strip:**
- Base64-encoded binary data (images, audio, video)
- Duplicate data (`raw` field that mirrors processed `results`)
- Verbose metadata (HTTP headers, user agents, timestamps)
- Large content fields when LLM doesn't need them
- Temporary/internal tracking fields

**Keep:**
- Identifiers (IDs, slugs, keys)
- URLs and links (for referencing resources)
- Status information (success/failure, state)
- Actual content when LLM needs it for reasoning
- Essential metadata (mime types, file sizes for context)
- Error messages and notices

## Generic Fallback Sanitization

Tools that don't implement custom sanitization fall back to generic rules:

```php
protected function generic_sanitize_for_llm( $result ) {
    // Strip duplicate raw responses
    unset( $result['raw'] );
    
    // Strip verbose metadata
    if ( isset( $result['metadata'] ) ) {
        unset( $result['metadata']['headers'] );
        unset( $result['metadata']['retrieved_at'] );
        unset( $result['metadata']['fetched_at'] );
        unset( $result['metadata']['user_agent'] );
    }
    
    // Strip base64 content
    if ( isset( $result['content']['data'] ) ) {
        unset( $result['content']['data'] );
        unset( $result['content']['data_url'] );
    }
    
    // Recursively sanitize nested arrays
    // ...
    
    return $result;
}
```

## Examples

### Example 1: Image Generation Tool

```php
// Tool returns
array(
    'attachment_id' => 123,
    'url'           => 'https://example.com/image.png',
    'file_name'     => 'generated.png',
    'mime_type'     => 'image/png',
    'bytes'         => 50000,
    'content'       => array(
        'encoding'  => 'base64',
        'data'      => str_repeat( 'A', 100000 ), // 100KB base64
        'data_url'  => 'data:image/png;base64,...',
    ),
)

// LLM receives (after sanitization)
array(
    'attachment_id' => 123,
    'url'           => 'https://example.com/image.png',
    'file_name'     => 'generated.png',
    'mime_type'     => 'image/png',
    'bytes'         => 50000,
)
```

**Why:** LLM doesn't need base64 data to reason about the image. It only needs to know the image was created and where it is.

### Example 2: Crawl4AI Tool

```php
// Tool returns
array(
    'status'   => 'completed',
    'task_id'  => 'task_123',
    'results'  => array(
        array(
            'url'      => 'https://example.com',
            'markdown' => '# Article Title\nContent...(truncated to 450K tokens)',
            'text'     => 'Article Title\nContent...(truncated)',
            'html'     => '<html>...(truncated)</html>',
            'metadata' => array(
                'headers'      => array( /* verbose headers */ ),
                'retrieved_at' => '2024-01-01 00:00:00',
            ),
        ),
    ),
    'raw'      => array(
        'results' => array(
            array(
                'markdown' => '# FULL UNTRUNCATED CONTENT (5MB)...',
                // Full duplicate data
            ),
        ),
    ),
    'metadata' => array(
        'user_agent' => 'WP oOS Crawler',
    ),
)

// LLM receives (after sanitization)
array(
    'status'  => 'completed',
    'task_id' => 'task_123',
    'results' => array(
        array(
            'url'      => 'https://example.com',
            'markdown' => '# Article Title\nContent...(truncated to 450K tokens)',
            'text'     => 'Article Title\nContent...(truncated)',
            'html'     => '<html>...(truncated)</html>',
        ),
    ),
)
```

**Why:** 
- `raw` field duplicates `results` without truncation - stripped
- `metadata.headers` and timestamps are verbose, low-value - stripped
- `markdown`, `text`, `html` are KEPT because LLM needs them to answer questions about the crawled content
- Already truncated by tool to fit token limits

## Benefits

1. **Reduced Token Usage**: Saves 90%+ tokens for data-heavy tools
2. **Prevents Context Overflow**: Avoids hitting model token limits
3. **Maintainable**: Each tool owns its sanitization logic
4. **Automatic**: Applies to all existing and future tools
5. **Transparent**: Frontend still gets full responses for display

## Testing

Test file: `tests/test-tool-llm-sanitization.php`

Covers:
- Custom sanitization is used when tool implements interface
- Generic fallback works for tools without custom rules
- Base64 data is stripped from image tools
- Duplicate `raw` data is removed
- Essential metadata is preserved
- Verbose fields are removed

## When to Implement Custom Sanitization

Implement `WP_MCP_AI_Tool_LLM_Sanitizer_Interface` when your tool:

1. Returns large binary/encoded data (images, audio, files)
2. Returns verbose API responses with duplicate data
3. Returns data structures where generic rules don't apply well
4. Returns content the LLM needs but in a specific format

## When Generic Sanitization is Sufficient

The generic fallback works for tools that:

1. Return simple structured data (IDs, titles, URLs, statuses)
2. Don't return large binary data
3. Don't have complex nested structures
4. Follow common patterns (standard metadata fields)

## Related Files

- Interface: `includes/tools/class-wp-mcp-ai-tool-llm-sanitizer-interface.php`
- REST Controller: `includes/class-wp-mcp-ai-rest.php` (sanitization methods)
- Example Tools:
  - `includes/tools/class-wp-mcp-ai-tool-run-crawl4ai-job.php`
  - `includes/tools/class-wp-mcp-ai-tool-generate-openai-image.php`
- Tests: `tests/test-tool-llm-sanitization.php`
