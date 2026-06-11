# Implementation Summary: get_open_meteo_forecast Iframe Rendering Fix

## Problem
The `get_open_meteo_forecast` tool was not rendering chart iframes inline in the chat client, even though it was returning the correct `output_format: 'chart'` structure.

## Root Cause Analysis
The issue was identified through careful analysis of the data flow:

1. **Tool Response**: The tool was returning a valid chart structure with `output_format: 'chart'` and `html`
2. **Orchestration Layer Processing**: The response included a large `data` field with all weather payload data
3. **Token Limit Truncation**: The `WP_MCP_AI_Tool_Token_Limits` class detected the large `html` field and truncated it
4. **Further Truncation**: Even after HTML truncation, the response was still too large due to the `data` field
5. **String Conversion**: The orchestration layer converted the entire response to a JSON string (line 1695-1696 in `class-wp-mcp-ai-tool-token-limits.php`)
6. **JavaScript Detection Failure**: The JavaScript received a string instead of an object, so `result.output_format === 'chart'` failed
7. **Display as Text**: The response was displayed as truncated JSON text instead of being rendered as an iframe

## Solution
Removed the `data` field from the chart output response, matching the structure used by `create_chart`.

**Key Insight**: The weather data is already embedded in the HTML chart visualization, so returning it separately was:
- Redundant
- Causing response size issues
- Preventing proper iframe rendering

## Files Modified

### 1. Tool Implementation
**File**: `includes/tools/class-wp-mcp-ai-tool-get-open-meteo-forecast.php`
- **Line 455-467**: Removed `'data' => $payload` from return array
- **Added**: Explanatory comment about why the field is omitted

### 2. Test Update
**File**: `tests/test-open-meteo-forecast-tool.php`
- **Line 393-395**: Removed assertion for `data` field
- **Added**: Comment explaining the intentional omission

### 3. Documentation
**File**: `GET_OPEN_METEO_FIX.md`
- Comprehensive documentation of the issue, root cause, and solution
- Comparison with `create_chart` tool
- Testing instructions

## Result
The tool now returns a compact response structure identical to `create_chart`:

```php
array(
    'output_format' => 'chart',
    'chart_type'    => $chart_type,
    'chart_title'   => $chart_title,
    'html'          => $html,            // Complete Chart.js HTML
    'chart_config'  => $chart_config,    // Chart.js config
    'width'         => $chart_width,
    'height'        => $chart_height,
    // 'data' field removed
);
```

This allows the JavaScript normalization logic to:
1. Receive the response as an object (not a string)
2. Detect `output_format: 'chart'`
3. Call `normaliseChartResult()` 
4. Render the chart as an inline iframe

## Verification
- ✅ Code matches `create_chart` pattern
- ✅ PHP CodeSniffer passes (no linting errors)
- ✅ Test updated with explanatory comment
- ✅ Documentation added

## Next Steps for Testing
To manually verify the fix works:
1. Open the chat interface
2. Request: "Show me the weather forecast for Montego Bay, Jamaica with a chart"
3. Verify the assistant uses `get_open_meteo_forecast` with `output_format: 'chart'`
4. Confirm the response renders as an inline iframe with the chart visualization
5. Check that it's NOT displayed as truncated JSON text

## Impact
- **Minimal**: Only affects chart output format (non-breaking change)
- **Positive**: Fixes iframe rendering for weather forecast charts
- **Aligned**: Makes response structure consistent with `create_chart`
