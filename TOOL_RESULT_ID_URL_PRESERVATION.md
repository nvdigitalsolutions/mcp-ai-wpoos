# Tool Result ID/URL Field Preservation for Agentic Workflows

## Overview

This enhancement ensures that tool results include both `id` and `url` fields (where applicable) to maintain compatibility with different AI providers in agentic workflows.

## Problem

When tools execute asynchronously or generate resources (images, videos, etc.), they return results with provider-specific field names like `job_id`, `attachment_id`, or `url`. Different AI providers may expect different field names when processing tool results in an agentic loop:

- Some providers might look for a generic `id` field
- Some might expect specific fields like `job_id`
- All providers need access to resource URLs for reference

Without consistent field naming, AI agents might not be able to properly reference resources or check job status in subsequent tool calls.

## Solution

### 1. Async Job Responses

When the Tool Execution Orchestrator returns an async job response, it now includes both `job_id` (specific) and `id` (generic alias):

```php
return array(
    'async'   => true,
    'job_id'  => $job_id,
    'id'      => $job_id,  // Generic alias for provider compatibility
    'status'  => 'pending',
    'message' => 'Tool started in background...',
);
```

### 2. Job Status Responses

The `check_video_status` tool now includes `id` in all response types:

**Completed job with attachment:**
```php
return array(
    'success'       => true,
    'status'        => 'completed',
    'job_id'        => $job_id,
    'id'            => $job_id,  // Generic alias
    'attachment_id' => $attachment_id,
    'url'           => $url,
    'message'       => 'Video generation completed successfully.',
);
```

**Pending/polling status:**
```php
return array(
    'success'      => true,
    'job_id'       => $job_id,
    'id'           => $job_id,  // Generic alias
    'status'       => 'polling',
    'poll_attempt' => 1,
    'max_attempts' => 10,
    'message'      => 'Video is being generated...',
);
```

### 3. Existing Resource Tools

Image and video generation tools already return `url` and `attachment_id`:

```php
// generate_openai_image
return array(
    'attachment_id' => 123,
    'url'           => 'https://example.com/image.png',
    // ... other fields
);

// Sanitization preserves these fields and adds image_url structure
$sanitized = array(
    'attachment_id' => 123,
    'url'           => 'https://example.com/image.png',
    'image_url'     => array(
        'url' => 'https://example.com/image.png',
    ),
    // ... other essential metadata
);
```

## Benefits

### 1. Provider Compatibility

By including both specific (`job_id`, `attachment_id`) and generic (`id`) field names, tool results work across different AI providers:

- **OpenAI**: Can access both `job_id` and `id`
- **Gemini**: Decodes JSON content and accesses all fields
- **Anthropic**: Can reference resources using standard field names
- **Future providers**: Generic `id` field provides fallback

### 2. Agentic Workflow Continuity

AI agents can reliably:

1. **Start async jobs** and receive `job_id`/`id` to track them
2. **Check job status** using either field name
3. **Reference generated resources** via `url` or `attachment_id`
4. **Chain tool calls** that depend on previous results

### 3. Backward Compatibility

The changes are fully backward compatible:

- Existing code using `job_id` continues to work
- New code can use `id` for generic access
- All existing fields are preserved

## Example Agentic Workflow

### Scenario: Generate and Describe a Video

```
User: "Create a 5-second video of a sunset and describe what you see"

AI thinks: I need to generate a video and then analyze it

1. AI calls: generate_veo_video(prompt="sunset", duration=5)
   Tool returns: {
       async: true,
       job_id: "veo_abc123",
       id: "veo_abc123",      // Generic alias added
       status: "pending"
   }

2. AI waits, then calls: check_video_status(job_id="veo_abc123")
   Tool returns: {
       success: true,
       job_id: "veo_abc123",
       id: "veo_abc123",      // Generic alias added
       status: "completed",
       attachment_id: 456,
       url: "https://example.com/video.mp4"
   }

3. AI calls: analyze_video(attachment_id=456)
   Tool returns: {
       description: "A beautiful sunset over the ocean with orange and purple hues",
       // ... analysis results
   }

4. AI responds: "I've created a 5-second video of a sunset. The video shows a beautiful sunset over the ocean with vibrant orange and purple hues across the sky."
```

## Implementation Details

### Modified Files

1. **includes/services/class-wp-mcp-ai-tool-execution-orchestrator.php**
   - Line 242: Added `'id' => $job_id` to async response

2. **includes/tools/class-wp-mcp-ai-tool-check-video-status.php**
   - Line 97: Added `'id' => $job_id` to completed attachment response
   - Line 109: Added `'id' => $job_id` to completed result response
   - Line 119: Added `'id' => $job_id` to pending/polling response

### Test Coverage

New test file: `tests/test-tool-result-id-url-fields.php`

Tests verify:
- ✅ Async job responses include both `job_id` and `id`
- ✅ Video status responses include both fields
- ✅ Image generation preserves `url` and `attachment_id`
- ✅ JSON encoding preserves all fields
- ✅ Agentic loop can access both field names

## Migration

No migration needed. This is a non-breaking enhancement that:

- Adds new fields alongside existing ones
- Does not remove or modify existing fields
- Works immediately with all providers

## Future Considerations

### Potential Enhancements

1. **Standardize all tool responses** to include `id` when returning resources
2. **Create a base response interface** with required fields
3. **Add provider-specific response formatters** if needed

### Provider-Specific Handling

If specific providers need different formats, the chat service can transform responses:

```php
// Future enhancement: Provider-specific formatting
if ( 'gemini' === $provider ) {
    // Gemini-specific transformations
} elseif ( 'anthropic' === $provider ) {
    // Anthropic-specific transformations
}
```

## Related Documentation

- `docs/CURRENT-STATE-AGENTIC-WORKFLOW.md` - Agentic workflow architecture
- `docs/agentic-workflow-architecture.md` - Detailed workflow mechanics
- `docs/TOOL_RESPONSE_FORMAT_GUIDE.md` - Tool response formatting standards
- `docs/tool-llm-sanitization.md` - Result sanitization for LLM consumption

## Testing

To verify the changes work correctly:

```bash
# 1. Run the new test suite
vendor/bin/phpunit tests/test-tool-result-id-url-fields.php

# 2. Test async workflow via REST API
curl -X POST http://your-site/wp-json/mcp-ai/v1/chat \
  -H "X-WP-Nonce: YOUR_NONCE" \
  -H "Content-Type: application/json" \
  -d '{
    "assistant_id": 123,
    "messages": [{
      "role": "user",
      "content": "Generate a video of a sunset"
    }]
  }'

# 3. Verify response includes both job_id and id fields
```

## Summary

This enhancement ensures robust agentic workflow support across different AI providers by:

1. ✅ Including both specific and generic field names
2. ✅ Preserving all resource identifiers (URLs, IDs)
3. ✅ Maintaining backward compatibility
4. ✅ Enabling reliable multi-step tool execution chains

The AI can now reliably reference resources and track async jobs regardless of which provider is being used.
