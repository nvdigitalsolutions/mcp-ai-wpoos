# Chart Tool Display Fix - Testing Guide

## Changes Made

### 1. Fixed 3x3 Pixel Canvas Issue (✓ Complete)
**File**: `includes/tools/class-wp-mcp-ai-tool-create-chart.php` (line ~261-268)

**Problem**: Charts were displaying with canvas dimensions of 3x3 pixels instead of the intended dimensions (e.g., 800x400). This was caused by Chart.js's responsive mode resizing the canvas when the parent container has no explicit dimensions during iframe initialization.

**Fix**: Set Chart.js options `responsive: false` and `maintainAspectRatio: false` as defaults to preserve the explicit width/height attributes on the canvas element. Users can still override these by explicitly providing responsive options.

```php
// Ensure Chart.js respects explicit canvas dimensions and doesn't auto-resize.
// This prevents the canvas from being resized to 3x3 pixels during iframe initialization.
if ( ! isset( $options['responsive'] ) ) {
    $options['responsive'] = false;
}
if ( ! isset( $options['maintainAspectRatio'] ) ) {
    $options['maintainAspectRatio'] = false;
}
```

**Tests Added**: Three new tests in `tests/test-tool-create-chart.php`:
- `test_chart_responsive_options()` - Verifies responsive options are set to false by default
- `test_chart_custom_options_preserve_responsive_defaults()` - Ensures custom options don't override responsive defaults
- `test_chart_explicit_responsive_options_respected()` - Verifies user can override with explicit responsive options

### 2. Fixed Tool Message Restoration (✓ Complete)
**File**: `assets/js/chat.js` (line ~10420)

**Problem**: When restoring tool messages from conversation history (localStorage/CCT), the fallback path only extracted text fields and didn't normalize tool results to extract chart HTML.

**Fix**: Modified the fallback path to call `normaliseToolResultForDisplay()` when display metadata is missing, ensuring chartHtml is properly extracted from tool content.

```javascript
// Before:
toolPayload = { text: displayText };

// After:
const normalized = normaliseToolResultForDisplay(toolName, parsedContent);
if (normalized) {
    toolPayload = {
        text: normalized.text || '',
        attachments: normalized.attachments || []
    };
    if (normalized.chartHtml) {
        toolPayload.chartHtml = normalized.chartHtml;
        toolPayload.chartWidth = normalized.chartWidth || 800;
        toolPayload.chartHeight = normalized.chartHeight || 400;
    }
}
```

### 2. Added Debug Logging (✓ Complete)
**Files**: `assets/js/chat.js`

Added comprehensive debug logging to diagnose the "empty chart initially" issue:

**In `normaliseChartResult()` (line ~6902)**:
- Logs when result object is invalid or HTML is empty
- Logs normalized chart metadata (type, title, dimensions)
- Logs HTML length and preview (first 150 chars)

**In `createChartBlockElement()` (line ~14315)**:
- Logs when iframe is created with chart HTML
- Checks for Chart.js script presence in HTML
- Checks for chartConfig presence in HTML
- Logs HTML length and preview

## How to Test

### Test 1: Chart Creation (Real-time)
1. Open WordPress admin and navigate to the chat interface
2. Open browser Developer Console (F12)
3. Send message: "create a pie chart of the demographics of jamaica"
4. Watch console for debug logs:
   ```
   [NV oOS] Normalized chart result: { chartType: "pie", htmlLength: XXXX, ... }
   [NV oOS] Creating chart block element: { htmlLength: XXXX, hasChartJsScript: true, hasChartConfig: true, ... }
   ```
5. Verify:
   - Chart displays with data (not empty)
   - HTML length is > 1000 bytes (should be ~2000-3000 for typical chart)
   - `hasChartJsScript: true`
   - `hasChartConfig: true`

### Test 2: Chart Restoration (From Storage)
1. After Test 1, refresh the page
2. Open Developer Console (F12)
3. Chat should auto-load conversation from localStorage
4. Watch console for debug logs (same format as above)
5. Verify:
   - Chart displays correctly after page reload
   - Same debug logs appear showing complete HTML

### Test 3: Different Chart Types
Test with various chart types to ensure fix works universally:
- "create a bar chart showing monthly sales: Jan 100, Feb 150, Mar 200"
- "create a line chart of temperature over time"  
- "create a doughnut chart of browser market share"

### Test 4: Canvas Dimensions (New)
Test that canvas has correct dimensions (not 3x3):
1. Create any chart in the chat interface
2. Open browser DevTools (F12) and inspect the iframe
3. Look for the `<canvas>` element inside the iframe
4. Verify:
   - Canvas `width` attribute should be 800 (or custom width)
   - Canvas `height` attribute should be 400 (or custom height)
   - Canvas should NOT be `width="3" height="3"`
   - Inline style should show proper pixel dimensions: `width: 800px; height: 400px;` (or custom)

**Before Fix:**
```html
<canvas id="chart-XXX" width="3" height="3" style="display: block; box-sizing: border-box; height: 3px; width: 3px;"></canvas>
```

**After Fix:**
```html
<canvas id="chart-XXX" width="800" height="400" style="display: block; box-sizing: border-box; height: 400px; width: 800px;"></canvas>
```

## Expected Debug Output

### Normal (Working) Output
```
[NV oOS] Normalized chart result: {
  chartType: "pie",
  chartTitle: "Demographics of Jamaica (Ethnic Groups)",
  width: 600,
  height: 400,
  htmlLength: 2459,
  htmlPreview: "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n    <meta charset=\"UTF-8\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n    <title>Chart</title>..."
}

[NV oOS] Creating chart block element: {
  htmlLength: 2459,
  hasHtml: true,
  htmlPreview: "<!DOCTYPE html>...",
  width: 600,
  height: 400,
  hasChartJsScript: true,
  hasChartConfig: true
}
```

### Problem Indicators
- **Canvas is 3x3 pixels** → Chart.js responsive mode is resizing (FIXED in this PR)
- `htmlLength: 0` or very small → HTML not being passed
- `hasHtml: false` → HTML is empty/null
- `hasChartJsScript: false` → Chart.js CDN not in HTML
- `hasChartConfig: false` → Chart configuration missing
- Console warns: "normaliseChartResult: Empty HTML"

## What to Look For

1. **Canvas Dimensions (3x3 Issue)**: 
   - Inspect the canvas element in iframe DevTools
   - Should have `width="800" height="400"` (or custom dimensions)
   - Should NOT have `width="3" height="3"`
   - Chart.js config should have `responsive: false` and `maintainAspectRatio: false`

2. **Empty Chart Initially**: 
   - Check if `htmlLength` is 0 or very small initially
   - Check if HTML arrives incomplete and updates later

3. **Chart HTML Structure**:
   - Should contain `<!DOCTYPE html>` at start
   - Should contain Chart.js CDN: `<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.js"></script>`
   - Should contain chart data: `const chartConfig = { "type": "pie", "data": { ... }}`
   - Should contain `"responsive": false` and `"maintainAspectRatio": false` in chartConfig options

4. **Iframe Rendering**:
   - Inspect the iframe element in browser DevTools
   - Check if `srcdoc` attribute contains complete HTML
   - Check if iframe console shows Chart.js errors

## Rollback Instructions

If the fix causes issues, revert with:
```bash
git revert 747de96  # Remove debug logging
git revert a74d5a8  # Remove normalization fix
```

## Additional Notes

- Debug logging only runs if `window.console` exists
- Logs are prefixed with `[NV oOS]` for easy filtering
- HTML previews are truncated to avoid console spam
- Chart.js errors would appear in iframe's isolated console (not main window)

## Related Files

- `/includes/tools/class-wp-mcp-ai-tool-create-chart.php` - PHP tool that generates chart HTML
- `/assets/js/chat.js` - JavaScript handling chart display
- `/assets/css/` - CSS for chart bubble styling (not modified)
