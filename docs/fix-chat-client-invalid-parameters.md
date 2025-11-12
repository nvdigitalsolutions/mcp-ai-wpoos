# Fix for "Invalid parameter(s): messages" in Chat-Client Agentic Workflow

## Problem

When using the chat-client agentic workflow, some tools were receiving "Invalid parameter(s): messages" errors. This occurred when AI providers (like OpenAI) called tools during the agentic loop and included extra parameters that weren't defined in the tool's schema.

## Root Cause

During the agentic workflow:
1. The AI model receives a list of available tools with their schemas
2. When the AI decides to call a tool, it sometimes includes extra context parameters
3. Some tools have strict schemas with `additionalProperties => false`, which means they should only accept defined parameters
4. However, the plugin was passing ALL parameters from the AI provider directly to the tool's `execute()` method
5. This caused errors or unexpected behavior for tools with strict schemas

## Solution

The fix implements parameter filtering at the REST API level before tool execution:

1. **New Method**: Added `filter_tool_arguments_by_schema()` to `WP_MCP_AI_REST` class
2. **Filtering Logic**:
   - Checks if the tool's schema has `additionalProperties => false`
   - If yes, filters out any parameters not defined in the schema's `properties`
   - If no (or `additionalProperties` is true/absent), passes all parameters through
3. **Integration Point**: The filtering happens in `execute_tool_call_internal()` before calling `$tool->execute()`
4. **Logging**: Logs filtered parameters for debugging purposes

## Files Changed

- `includes/class-wp-mcp-ai-rest.php`:
  - Added `filter_tool_arguments_by_schema()` method
  - Integrated filtering into `execute_tool_call_internal()`

## Tests Added

1. `tests/test-tool-argument-filtering.php`:
   - Unit tests for the filtering method
   - Tests with tools that have strict schemas (e.g., count_tokens)
   - Tests with tools that allow additional properties (e.g., get_user_info)
   - Tests edge cases (empty arguments, etc.)

2. `tests/test-chat-client-parameter-filtering.php`:
   - Integration tests simulating the full agentic workflow
   - Tests that count_tokens works with extra parameters
   - Tests that get_user_info continues to work normally
   - Tests the filtering in the complete REST API flow

## Affected Tools

Tools with `additionalProperties => false` that now benefit from parameter filtering:
- count_tokens
- check_site_security
- check_wp_cli
- crawl4ai_price_lookup
- create_cron_job
- create_google_calendar_event
- create_woo_product
- create_wpcode_snippet
- And many others (see `grep -l "additionalProperties.*false" includes/tools/*.php`)

Tools without strict schemas continue to work as before with no changes.

## Example

Before the fix:
```php
// AI provider calls count_tokens with extra parameters
$arguments = [
    'text' => 'Hello world',
    'method' => 'heuristic',
    'messages' => [...],  // Extra parameter causes error
];
$result = $tool->execute($arguments, $context);  // May fail
```

After the fix:
```php
// Extra parameters are filtered out before execution
$arguments = [
    'text' => 'Hello world',
    'method' => 'heuristic',
    'messages' => [...],  // Will be filtered out
];
$filtered = filter_tool_arguments_by_schema($tool, $arguments);
// $filtered = ['text' => 'Hello world', 'method' => 'heuristic']
$result = $tool->execute($filtered, $context);  // Works correctly
```

## Backward Compatibility

This fix is fully backward compatible:
- Tools without `additionalProperties => false` are not affected
- Tools with strict schemas now work better in the agentic workflow
- No changes needed to existing tool implementations
- No API changes for tool consumers

## Future Improvements

- Consider adding schema validation beyond just filtering extra properties
- Add metrics to track how often parameters are filtered
- Potentially add a filter hook to allow custom filtering logic
