# SSE Event Data Parsing Fix - Visual Guide

## Problem Statement

The SSE (Server-Sent Events) parser was failing with the error:
```
Failed to parse SSE event data: {eventType: 'message', eventData: '{"assistant_id":331,"data":{"id":"chatcmpl-u91xbtn…ces":[{"index":0,"message":{"role":"assistant","c', error: 'finalText.substring is not a function'}
```

Additionally, users were seeing `[object Object]` instead of actual text in responses.

## Root Causes

### 1. Type Assumption Error
The code assumed `message.content` would always be a string, but some AI providers return it as:
- **Object**: `{ type: 'text', text: 'actual content' }`
- **Array**: `[{ type: 'text', text: 'part 1' }, { type: 'text', text: 'part 2' }]`

### 2. Missing Defensive Check
Line 8433 called `.substring()` without verifying `finalText` was actually a string:
```javascript
textSample: finalText.substring(0, 100)  // ❌ Crashes if finalText is an object
```

### 3. No Text Extraction from Nested Structures
When content was an object like `{text: 'Hello'}`, the code didn't extract the nested text.

## Solution

### Added `extractTextFromContent()` Helper Function

```javascript
/**
 * Helper function to extract text from various content formats
 * Handles: string, object with text property, array of content items
 */
function extractTextFromContent(content) {
    if (!content) {
        return '';
    }
    
    // If already a string, return it
    if (typeof content === 'string') {
        return content;
    }
    
    // Handle array of content items
    if (Array.isArray(content)) {
        let text = '';
        for (let i = 0; i < content.length; i++) {
            const item = content[i];
            if (typeof item === 'string') {
                text += item;
            } else if (item && typeof item === 'object') {
                if (typeof item.text === 'string') {
                    text += item.text;
                } else if (typeof item.content === 'string') {
                    text += item.content;
                }
            }
        }
        return text;
    }
    
    // Handle object with text property
    if (typeof content === 'object') {
        if (typeof content.text === 'string') {
            return content.text;
        }
        if (typeof content.content === 'string') {
            return content.content;
        }
    }
    
    return '';
}
```

### Updated Content Extraction Logic

**Before:**
```javascript
// ❌ Only handled string content
if (data.data.choices[0].message.content && typeof data.data.choices[0].message.content === 'string') {
    finalText = data.data.choices[0].message.content;
}
```

**After:**
```javascript
// ✅ Handles string, object, and array content
if (data.data.choices[0].message.content) {
    finalText = extractTextFromContent(data.data.choices[0].message.content);
}
```

### Added Defensive Type Check

**Before:**
```javascript
if (finalText) {
    fullContent = finalText;
    updateCallback(fullContent);
    
    console.log({
        textLength: finalText.length,
        textSample: finalText.substring(0, 100)  // ❌ Could crash
    });
}
```

**After:**
```javascript
// ✅ Ensures finalText is a string before using string methods
if (finalText && typeof finalText === 'string') {
    fullContent = finalText;
    updateCallback(fullContent);
    
    console.log({
        textLength: finalText.length,
        textSample: finalText.substring(0, 100)  // ✅ Safe
    });
}
```

## Provider Format Support

### OpenAI - String Content ✅
```json
{
  "choices": [{
    "message": {
      "content": "This is the response text"
    }
  }]
}
```

### OpenAI - Object Content ✅ (NEW)
```json
{
  "choices": [{
    "message": {
      "content": {
        "type": "text",
        "text": "This is the response text"
      }
    }
  }]
}
```

### OpenAI - Array Content ✅ (NEW)
```json
{
  "choices": [{
    "message": {
      "content": [
        { "type": "text", "text": "Part 1 " },
        { "type": "text", "text": "Part 2" }
      ]
    }
  }]
}
```

### Ollama ✅
```json
{
  "response": "This is the response text"
}
```

### Gemini ✅
```json
{
  "candidates": [{
    "content": {
      "parts": [
        { "text": "Part 1 " },
        { "text": "Part 2" }
      ]
    }
  }]
}
```

### Generic Provider ✅
```json
{
  "content": "This is the response text"
}
```

## Test Coverage

### New Test Suite: `sse-content-extraction.test.js`
- ✅ 24 comprehensive tests
- ✅ Tests string, object, and array content
- ✅ Tests all provider formats
- ✅ Tests edge cases (null, undefined, numbers, booleans)
- ✅ Verifies no `[object Object]` in output
- ✅ Verifies safe `.substring()` calls

### Test Results
```
✅ All 186 JavaScript tests pass
✅ New test suite: 24/24 tests pass
✅ Original SSE tests: 7/7 tests pass
```

## Impact

### Before Fix
- ❌ Error: `finalText.substring is not a function`
- ❌ Shows `[object Object]` in responses
- ❌ Only works with string content
- ❌ Fails for OpenAI object/array formats

### After Fix
- ✅ No errors - robust type handling
- ✅ Correctly extracts and displays text
- ✅ Works with all content formats (string, object, array)
- ✅ Supports all AI providers (OpenAI, Gemini, Ollama, others)

## Files Modified

1. **`assets/js/chat.js`**
   - Added `extractTextFromContent()` helper function
   - Updated content extraction to use helper
   - Added defensive type check before `.substring()`

2. **`tests/js/sse-content-extraction.test.js`** (NEW)
   - Comprehensive test coverage for all formats
   - 24 test cases covering all scenarios

## Security Considerations

The fix maintains all existing security properties:
- ✅ No XSS vulnerabilities (text extraction only)
- ✅ No code injection (JSON parsing uses existing safe methods)
- ✅ No infinite loops (simple iteration with bounds)
- ✅ No prototype pollution (uses standard object access)

## Performance Impact

- **Minimal**: Helper function adds negligible overhead
- **Benefits**: Prevents crashes and improves reliability
- **Trade-off**: Slightly more code for significantly better compatibility

## Backward Compatibility

- ✅ **100% backward compatible**
- ✅ All existing string content still works
- ✅ No breaking changes to API
- ✅ Adds support for new formats without affecting old ones
