# Cloudflare JSON Tool Call Parsing Fix - Summary

**Date**: January 11, 2026  
**Issue**: Tool calls displayed as JSON text instead of being executed  
**Status**: ✅ **FIXED**

---

## Problem Description

After PR #2782, some Cloudflare Worker AI models (especially `@cf/meta/llama-4-scout-17b-16e-instruct`) started returning tool calls as plain JSON text in the response content instead of using the proper `tool_calls` array format.

### Observed Behavior

**Model Output**:
```json
{"type": "function", "name": "get_open_meteo_forecast", "parameters": {"latitude": "18", "longitude": "77"}}
```

This JSON was displayed directly to the user, and:
1. The agentic loop didn't recognize it as a tool call
2. Tools were never executed
3. No final response was generated
4. User saw raw JSON instead of tool execution results

---

## Root Cause

Cloudflare Worker AI models output tool calls in three formats:

1. **Standard Format** (OpenAI compatible) ✅ Already supported
   ```json
   {
     "tool_calls": [{
       "id": "call_123",
       "type": "function",
       "function": {"name": "tool_name", "arguments": "{}"}
     }]
   }
   ```

2. **XML Format** ✅ Already supported (PR #2770)
   ```xml
   <name>tool_name</name><arguments>{...}</arguments>
   ```

3. **JSON Text Format** ❌ **NOT SUPPORTED - This was the bug**
   ```json
   {"type": "function", "name": "tool_name", "parameters": {...}}
   ```

The backend had XML parsing but was missing JSON text parsing.

---

## Solution

### Implementation

Added JSON tool call detection and parsing to `WP_MCP_AI_Cloudflare_Client`:

#### 1. Detection Method
```php
protected function contains_json_tool_call( $content ) {
    // Validates JSON structure
    // Checks for required fields: type="function" and name
    // Returns boolean
}
```

#### 2. Parsing Method
```php
protected function parse_json_tool_calls( $content ) {
    // Extracts tool name
    // Extracts arguments/parameters
    // Converts to OpenAI format
    // Returns array of tool_calls
}
```

#### 3. Integration in normalize_response()
```php
// After XML detection...
if ( ! $tool_calls_found && ! empty( $content ) && $this->contains_json_tool_call( $content ) ) {
    $parsed_tool_calls = $this->parse_json_tool_calls( $content );
    if ( ! empty( $parsed_tool_calls ) ) {
        $result['tool_calls'] = $parsed_tool_calls;
        $tool_calls_found = true;
        $message['content'] = ''; // Remove JSON from display
    }
}
```

---

## Testing

### Test Coverage

Created `tests/test-cloudflare-xml-json-tool-parsing.php` with **17 comprehensive tests**:

**XML Parsing Tests** (verifying existing functionality):
- ✅ XML tool call detection
- ✅ XML tool call parsing
- ✅ Multiple XML tool calls
- ✅ XML with whitespace
- ✅ Empty arguments

**JSON Parsing Tests** (new functionality):
- ✅ JSON tool call detection
- ✅ JSON parsing with "parameters" field
- ✅ JSON parsing with "arguments" field
- ✅ JSON tool call ID format (`call_json_` prefix)
- ✅ Invalid JSON rejection
- ✅ Missing required fields rejection
- ✅ Empty parameters handling
- ✅ Non-function type rejection

### Run Tests

```bash
vendor/bin/phpunit tests/test-cloudflare-xml-json-tool-parsing.php
```

---

## Benefits

1. **✅ Backward Compatible**: Doesn't affect models that use proper tool_calls format or XML
2. **✅ Automatic Conversion**: Transparently handles JSON text format without user intervention
3. **✅ Full Agentic Loop Support**: Parsed tool calls are executed and responded to normally
4. **✅ Comprehensive Logging**: All parsing steps are logged for debugging
5. **✅ Flexible**: Supports both "parameters" and "arguments" field names
6. **✅ XML Support Verified**: Tests confirm XML parsing still works correctly

---

## Example Flow Comparison

### Before Fix (Broken ❌)

```
User: "Get weather for Montego Bay"
  ↓
Model Response: {"type": "function", "name": "get_open_meteo_forecast", ...}
  ↓
❌ Displayed as text to user
❌ No tool execution
❌ Chat flow breaks
```

### After Fix (Working ✅)

```
User: "Get weather for Montego Bay"
  ↓
Model Response: {"type": "function", "name": "get_open_meteo_forecast", ...}
  ↓
✅ Detected by contains_json_tool_call()
✅ Parsed by parse_json_tool_calls()
✅ Converted to OpenAI format
✅ Executed by server
✅ Results returned to user
✅ Chat continues normally
```

---

## Files Modified

1. **`includes/class-wp-mcp-ai-cloudflare-client.php`** (+160 lines)
   - Added JSON detection in normalize_response()
   - Added contains_json_tool_call() method
   - Added parse_json_tool_calls() method

2. **`tests/test-cloudflare-xml-json-tool-parsing.php`** (NEW, +260 lines)
   - 17 comprehensive tests
   - Covers both XML and JSON parsing
   - Tests edge cases and error handling

---

## Technical Details

### Format Comparison

| Format | Priority | Prefix | Example |
|--------|----------|--------|---------|
| Standard `tool_calls` array | 1 (checked first) | `call_` | Standard OpenAI format |
| XML in content | 2 (checked second) | `call_xml_` | `<name>...</name>` |
| JSON in content | 3 (checked third) | `call_json_` | `{"type": "function", ...}` |

### Logging Events

- `cloudflare_json_tool_calls_parsed` - Successful JSON parsing
- `cloudflare_json_tool_call_parse_error` - Parse errors

### Security

- ✅ Input sanitization with `sanitize_text_field()`
- ✅ JSON validation
- ✅ Type checking
- ✅ Error handling

---

## Related Issues

- Original bug report: Tool calls showing as JSON text
- PR #2782: Traditional function calling implementation
- PR #2770: XML tool call parsing (already implemented)

---

## Conclusion

The fix ensures that all three tool call formats from Cloudflare Worker AI models are properly detected, parsed, and executed. Users will no longer see raw JSON or XML in their chat responses, and tools will execute correctly regardless of which format the model chooses to use.

---

## Commit

**Commit**: fc0df49
**Branch**: copilot/fix-tools-execution-issue  
**Date**: January 11, 2026
