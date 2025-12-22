# Fix: Array Tool Result Handling for search_attachments and list_jetengine_rest_routes

## Problem

### search_attachments Issue (Fixed in PR #2008)

The `search_attachments` tool was returning an array of attachment objects, but the JavaScript chat client couldn't properly handle array results. The `normaliseToolResultForDisplay` function only knew how to handle:
- Single object results (with properties like `url`, `message`, `text`)
- String results

When an array was returned, it would fall through to `extractGenericToolResponse`, which couldn't extract meaningful content from an array, resulting in `null` being returned.

### list_jetengine_rest_routes Issue (Similar Problem)

The `list_jetengine_rest_routes` tool returns an object with a nested `routes` array:
```javascript
{
  namespace: 'jet-engine/v2',
  routes: [
    { path: '...', methods: [...], description: '...', additional_requirements: [...] },
    // ... more route objects
  ]
}
```

This structured object with an embedded array was not being properly formatted for display and persistence. Without special handling, it would fall through to `extractGenericToolResponse`, which doesn't know how to format route information properly.

## Symptoms

### search_attachments
From the problem statement, we could see that attachment results WERE being displayed as HTML (showing all 10 banana images with their details), but they weren't being properly:
1. Normalized for display as attachments
2. Persisted in the conversation's display metadata

The debug log showed the innerHTML was being set, but the underlying data structure wasn't being captured for persistence.

### list_jetengine_rest_routes
Similar to search_attachments, the routes data would be displayed in some form but not properly formatted or persisted, making it difficult for users to understand the available API endpoints and for the AI to reference them in subsequent tool calls.

## Root Cause

### search_attachments
The `normaliseToolResultForDisplay` function in `assets/js/chat.js` had no handling for array results. It checked:
1. Is it a string? → Return {text, attachments: []}
2. Is it an object with async pending status? → Return null
3. Does it have chart HTML? → Call normaliseChartResult
4. Is it run_crawl4ai_job? → Call normaliseCrawl4aiResult
5. Does it have a URL or inline content? → Process as media attachment
6. Otherwise → Call extractGenericToolResponse

Arrays are objects in JavaScript, so they passed the `typeof result !== 'object'` check. They don't have URLs or inline content, so they fell through to `extractGenericToolResponse`. This function checked for `message`, `text`, `summary`, etc. properties on the array object itself, found nothing, and returned `null`.

### list_jetengine_rest_routes
The tool returns an object (not an array), but with a nested `routes` array property. Without special handling, this object falls through to `extractGenericToolResponse`, which doesn't have logic to:
1. Detect the nested `routes` array
2. Format route information (path, methods, description, requirements) in a user-friendly way
3. Preserve the structured data for conversation persistence

As a result, the routes data was either displayed poorly or not at all.

## Solution

Added three key components:

### 1. Array Detection in normaliseToolResultForDisplay (search_attachments fix)

Added array detection right after the async pending check:

```javascript
// Handle array results (e.g., from search_attachments tool)
// Arrays of attachment objects should be formatted as downloadable links
if (Array.isArray(result)) {
    return normaliseArrayToolResult(result, toolName);
}
```

### 2. New normaliseArrayToolResult Function (search_attachments fix)

Created a comprehensive function to handle array tool results:

```javascript
function normaliseArrayToolResult(resultArray, toolName) {
    // Validates array
    // Special handling for search_attachments
    // Generic handling for other array-returning tools
    // Returns {text, attachments} structure
}
```

For `search_attachments`, it:
- Extracts each attachment object's properties (title, download_url, permalink, etc.)
- Builds metadata strings (MIME type, filesize, upload date)
- Creates attachment objects with URL and label
- Adds both download URLs and permalinks (if different)
- Returns a formatted result with summary text and attachments array

For generic tools, it:
- Converts array items to strings
- Joins them with newlines
- Returns simple text output

### 3. New normaliseJetEngineRoutesResult Function (list_jetengine_rest_routes fix)

Created a specialized function to handle JetEngine route results:

```javascript
function normaliseJetEngineRoutesResult(result) {
    // Validates result object
    // Extracts routes array
    // Formats each route with path, methods, description, and requirements
    // Returns {text, attachments} structure
}
```

This function:
- Extracts the `routes` array from the result object
- Formats the namespace (e.g., "jet-engine/v2")
- Iterates through each route to build a formatted list
- Includes route number, HTTP methods, path, description, and requirements
- Returns a user-friendly text representation

Added special handling in `normaliseToolResultForDisplay`:

```javascript
// Special handling for list_jetengine_rest_routes tool
// This tool returns an object with a 'routes' array that needs formatting
if (toolName === 'list_jetengine_rest_routes') {
    return normaliseJetEngineRoutesResult(result);
}
```

## Data Flow

### Before Fix

1. `search_attachments` tool executes → Returns array of attachment objects
2. Tool result content parsed from JSON string → Array
3. `normaliseToolResultForDisplay('search_attachments', arrayResult)` called
4. No array handling → Falls through to `extractGenericToolResponse(arrayResult)`
5. `extractGenericToolResponse` tries to find `message`/`text` properties on array → Returns `null`
6. No display metadata created → Attachments not persisted
7. Raw content might still render in HTML, but not properly structured

### After Fix

1. `search_attachments` tool executes → Returns array of attachment objects
2. Tool result content parsed from JSON string → Array
3. `normaliseToolResultForDisplay('search_attachments', arrayResult)` called
4. **Array detected** → `normaliseArrayToolResult(arrayResult, 'search_attachments')` called
5. Array processed → Returns `{text: "Here are the last N attachments...", attachments: [...]}`
6. `createToolDisplayMetadata(normalized)` creates display metadata
7. Display metadata attached to tool result: `toolResult.display = toolDisplay`
8. Tool result pushed to conversation with display metadata
9. `saveConversationToStorage(state)` persists the conversation
10. Attachments properly displayed AND persisted

## Persistence

The fix ensures attachments are persisted through the existing display metadata mechanism:

1. **Tool Result Display Metadata**: Created by `createToolDisplayMetadata(normalized)`
   - Contains `bubbleType: 'tool'`
   - Contains `text` from normalized result
   - Contains `attachments` array from normalized result

2. **Conversation Storage**: The tool result with display metadata is:
   - Added to `state.conversation` array
   - Saved to localStorage via `saveConversationToStorage(state)`
   - Can be saved to CCT (Custom Content Type) if JetEngine integration is active

3. **Restoration**: When the conversation is loaded:
   - Display metadata is read from storage
   - Attachments are extracted and rendered
   - The same attachment structure is used for display

## Testing

Added comprehensive test suite in `tests/js/array-tool-result-handling.test.js`:

- **22 tests total** (13 for search_attachments + 9 for list_jetengine_rest_routes)
- Tests for empty arrays, non-arrays
- Tests for search_attachments single and multiple results
- Tests for attachments without permalinks
- Tests for attachments with same URL and permalink
- Tests for generic array results
- Integration tests with normaliseToolResultForDisplay
- Real-world scenario test with banana images from problem statement
- **New JetEngine routes tests:**
  - Null/empty results handling
  - Single route formatting
  - Multiple routes formatting
  - Routes with multiple HTTP methods
  - Routes without descriptions
  - Default namespace handling
  - Integration with normaliseToolResultForDisplay
  - Complete routes formatting test

**All 415 tests pass** (22 in this test file + 393 existing tests)

## Files Modified

1. `assets/js/chat.js`:
   - Added `normaliseArrayToolResult()` function (lines 7615-7723)
   - Added `normaliseJetEngineRoutesResult()` function (lines 7555-7613)
   - Added array detection in `normaliseToolResultForDisplay()` (lines 7750-7752)
   - Added JetEngine routes handling in `normaliseToolResultForDisplay()` (lines 7767-7770)

2. `tests/js/array-tool-result-handling.test.js`:
   - Added `normaliseJetEngineRoutesResult` mock function
   - Added 9 new test cases for JetEngine routes handling
   - Updated test coverage from 13 to 22 tests

## Backward Compatibility

✅ No breaking changes
✅ All existing tests pass
✅ Non-array tool results work exactly as before
✅ Array results from other tools get generic handling (fallback to string list)

## Performance

- Minimal overhead: Array.isArray() is O(1)
- No additional API calls
- No duplicate data storage
- Efficient array iteration for formatting

## Future Considerations

The generic array handling provides a foundation for other tools that might return arrays:
- User lists
- Post collections
- Search results
- Any batch operation results

These will automatically get basic text formatting. If specialized formatting is needed, tool-specific handling can be added to `normaliseArrayToolResult`, similar to how `search_attachments` is handled.

For tools that return objects with nested arrays (like `list_jetengine_rest_routes`), add special handling in `normaliseToolResultForDisplay` with a dedicated formatter function, following the pattern established by `normaliseJetEngineRoutesResult`.

## Related Issues

- **PR #2008**: Fix array tool results not persisting from search_attachments
- **Current PR**: Fix array tool results not persisting from list_jetengine_rest_routes

Both issues stem from the same root cause: inadequate handling of array and array-containing results in the JavaScript chat client.
