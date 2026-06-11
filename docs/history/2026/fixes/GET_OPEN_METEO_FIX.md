# Get Open Meteo Forecast Iframe Rendering Fix

## Issue
The `get_open_meteo_forecast` tool was returning chart output with `output_format: 'chart'` and `html` fields, but the charts were not rendering as inline iframes in the chat UI. Instead, the response was being displayed as truncated JSON text.

## Root Cause
The tool was including a `data` field in the chart output response that contained the full weather data payload. This caused two problems:

1. The HTML field was being truncated by the orchestration layer's token limit management (see `class-wp-mcp-ai-tool-token-limits.php` line 1681-1683)
2. After truncation, the response was still too large due to the `data` field, causing the orchestration layer to convert the entire response to a JSON string (line 1695-1696)
3. When the JavaScript received a string instead of an object, the check for `result.output_format === 'chart'` failed (in `assets/js/chat.js` line 8104)
4. The string response was displayed as text rather than being processed by `normaliseChartResult()`

## Solution
Removed the `data` field from the chart output response in the `generate_chart_output()` method. The weather data is already embedded in the HTML chart visualization, so returning it separately was:
- Redundant
- Causing response size issues
- Preventing proper iframe rendering

## Changes Made

### 1. Tool Response Structure (`includes/tools/class-wp-mcp-ai-tool-get-open-meteo-forecast.php`)
**Before:**
```php
return array(
    'output_format' => 'chart',
    'chart_type'    => $chart_type,
    'chart_title'   => $chart_title,
    'html'          => $html,
    'chart_config'  => $chart_config,
    'width'         => $chart_width,
    'height'        => $chart_height,
    'data'          => $payload,  // ❌ Removed - causing truncation
);
```

**After:**
```php
return array(
    'output_format' => 'chart',
    'chart_type'    => $chart_type,
    'chart_title'   => $chart_title,
    'html'          => $html,
    'chart_config'  => $chart_config,
    'width'         => $chart_width,
    'height'        => $chart_height,
);
```

This matches the response structure of the `create_chart` tool, which works correctly.

### 2. Test Update (`tests/test-open-meteo-forecast-tool.php`)
Removed the assertion expecting the `data` field in the chart output test and added an explanatory comment about why the field is omitted.

## How It Works Now

1. User requests weather forecast with `output_format: 'chart'`
2. Tool generates Chart.js HTML with embedded weather data
3. Tool returns compact response (without redundant `data` field)
4. Response size stays below truncation threshold
5. JavaScript receives response as object (not string)
6. Check `result.output_format === 'chart'` passes
7. `normaliseChartResult()` processes the response
8. Chart iframe renders inline in chat UI

## Testing

The fix has been validated through:
- Code review against `create_chart` tool implementation
- PHP CodeSniffer linting (passes with no errors)
- Test file updated to reflect new response structure

To manually test:
1. Open chat interface
2. Ask for weather forecast with chart output: "Show me the weather forecast for Montego Bay, Jamaica with a chart"
3. The assistant should use: `get_open_meteo_forecast` with `output_format: 'chart'`
4. Verify the response renders as an inline iframe with the chart, not truncated JSON

## Related Files
- Tool: `includes/tools/class-wp-mcp-ai-tool-get-open-meteo-forecast.php`
- Test: `tests/test-open-meteo-forecast-tool.php`
- Chat UI: `assets/js/chat.js` (lines 8102-8106, 6902-6963)
- Token Limits: `includes/class-wp-mcp-ai-tool-token-limits.php` (lines 1662-1709)

## Comparison with create_chart
Both tools now return identical response structures for chart output:
- ✅ `output_format: 'chart'`
- ✅ `chart_type`
- ✅ `chart_title`
- ✅ `html` (complete Chart.js HTML)
- ✅ `chart_config` (Chart.js configuration object)
- ✅ `width` and `height`
- ❌ No `data` field (prevents truncation)

## Security Considerations
The HTML content is rendered in a sandboxed iframe with `sandbox="allow-scripts"` attribute (see `assets/js/chat.js` line 14354), ensuring security even though user-provided data is embedded in the Chart.js visualization.
