# Vector Tool Display Fix Summary

## Issue
After recent vector tool changes, tool results were not displaying properly in the chat client. Messages appeared to be "scrolling by" but not rendering in the UI. This affected:
- `vectorize_image` tool - SVG images not showing
- `get_system_info` - Truncated messages not displaying
- Any tool implementing `WP_MCP_AI_Tool_LLM_Sanitizer_Interface`

## Root Cause
The `sanitize_tool_result_for_display()` method in `includes/rest/class-wp-mcp-ai-rest-validator.php` was incorrectly calling `$tool_instance->sanitize_for_llm($result)`.

### The Problem
```php
// BEFORE (INCORRECT)
public function sanitize_tool_result_for_display( $result, $tool_name, $tool_instance = null ) {
    // This was WRONG - it stripped data needed for display
    if ( $tool_instance && $tool_instance instanceof WP_MCP_AI_Tool_LLM_Sanitizer_Interface ) {
        $result = $tool_instance->sanitize_for_llm( $result );
    }
    
    // Apply filters...
    return $result;
}
```

This caused the chat client to receive sanitized results that were missing:
- `image_url` structures needed for image display
- Full text content
- Metadata fields used by the UI
- Other display-specific data

### Why This Happened
There are TWO separate sanitization paths in the codebase:

1. **Display Sanitization** (`sanitize_tool_result_for_display`)
   - Purpose: Prepare results for the chat client UI
   - Should: Preserve ALL data needed for rendering (images, metadata, text, etc.)
   - Used in: REST API responses sent to the browser

2. **LLM Sanitization** (`sanitize_tool_result_for_llm`)
   - Purpose: Reduce token usage when sending to AI models
   - Should: Strip large/unnecessary data (base64 images, verbose logs, etc.)
   - Used in: Agentic loop when sending tool results back to the LLM

The bug was that display sanitization was calling LLM sanitization, which stripped out data the UI needed.

## The Fix

### Code Change
**File: `includes/rest/class-wp-mcp-ai-rest-validator.php`**

```php
// AFTER (CORRECT)
public function sanitize_tool_result_for_display( $result, $tool_name, $tool_instance = null ) {
    // Do NOT call sanitize_for_llm() here - that method strips data needed for display.
    // The LLM sanitization is only for reducing token usage when sending to AI models,
    // not for preparing data for the chat client UI.
    
    // Allow filtering of tool results before display.
    $result = apply_filters( 'wp_mcp_ai_sanitize_tool_result_display', $result, $tool_name );
    $result = apply_filters( "wp_mcp_ai_sanitize_tool_result_display_{$tool_name}", $result );
    
    return $result;
}
```

### Key Changes
1. **Removed** the `sanitize_for_llm()` call from display sanitization
2. **Added** clear comments explaining the distinction
3. **Updated** PHPDoc to clarify the method's purpose
4. **Preserved** filter hooks for custom sanitization if needed

### Tests Added
**File: `tests/test-rest-validator.php`**

Added two tests:
1. `test_sanitize_tool_result_for_display_preserves_all_fields()` - Verifies all fields are preserved
2. `test_sanitize_tool_result_for_display_applies_filters()` - Verifies filters still work

## How Data Flows

### Before the Fix (BROKEN)
```
Tool Execute → Full Result → sanitize_for_display (calls sanitize_for_llm) → Stripped Result → Chat Client ❌
                                                                                                        ↑
                                                                                         Missing image_url, metadata, etc.
```

### After the Fix (CORRECT)
```
Tool Execute → Full Result → sanitize_for_display (preserves everything) → Full Result → Chat Client ✅
                                                                                                   ↑
                                                                                    Has image_url, metadata, etc.

Tool Execute → Full Result → sanitize_for_llm (strips for LLM) → Minimal Result → LLM (Agentic Loop) ✅
                                                                                            ↑
                                                                         Reduced tokens, just what LLM needs
```

## Example: vectorize_image Tool

### Tool's sanitize_for_llm Method
```php
public function sanitize_for_llm( $result ) {
    // Keep only essential metadata for LLM reasoning
    $keep_fields = array(
        'attachment_id',
        'url',
        'file_name',
        'mime_type',
        'bytes',
        'title',
        'source_format',
        'source_size',
        'svg_size',
        'size_ratio',
        'text',
    );
    
    // Strips: duration_ms, options, and other fields
    // Adds: image_url for agentic workflow
}
```

### Impact on Chat Client

**Before Fix:**
- Full tool result has `image_url`, `duration_ms`, `options`, etc.
- Display sanitizer calls `sanitize_for_llm()` → strips most fields
- Chat client receives minimal result → Can't render image properly

**After Fix:**
- Full tool result has all fields
- Display sanitizer preserves everything → No stripping
- Chat client receives full result → Can render image with all metadata

## Affected Tools
Any tool implementing `WP_MCP_AI_Tool_LLM_Sanitizer_Interface`:
- `vectorize_image`
- `generate_veo_video`
- `generate_gemini_image`
- `edit_gemini_image`
- `generate_openai_image`
- `run_crawl4ai_job`
- `web_search`

## Verification

### Manual Testing Checklist
- [ ] Test `vectorize_image` tool - verify SVG displays in chat
- [ ] Test `get_system_info` - verify truncated messages display
- [ ] Test `generate_openai_image` - verify image displays
- [ ] Test `generate_veo_video` - verify video displays
- [ ] Test other image tools with metadata display

### Automated Testing
- ✅ PHP syntax validation passed
- ✅ Unit tests added and pass
- ✅ Code review passed
- ✅ Security checks passed

## Related Files
- `includes/rest/class-wp-mcp-ai-rest-validator.php` - Fixed sanitization method
- `includes/tools/class-wp-mcp-ai-tool-vectorize-image.php` - Example tool with LLM sanitizer
- `includes/interfaces/interface-wp-mcp-ai-tool-llm-sanitizer.php` - Interface definition
- `includes/services/class-wp-mcp-ai-chat-service.php` - Uses both sanitization paths
- `includes/class-wp-mcp-ai-rest.php` - Calls display sanitization
- `assets/js/chat.js` - Receives and renders tool results
- `tests/test-rest-validator.php` - Test coverage

## References
- Working branch: `copilot/add-documentation-section` (had correct implementation)
- Issue branch: `copilot/fix-vector-tool-message-display` (this fix)
- Previous PR: #2510 (added vectorize_image image_url support)

## Lessons Learned
1. **Separation of Concerns**: Display and LLM sanitization serve different purposes
2. **Method Naming**: Names should clearly indicate their purpose
3. **Documentation**: Comments should explain WHY, not just WHAT
4. **Testing**: Both paths need separate test coverage
