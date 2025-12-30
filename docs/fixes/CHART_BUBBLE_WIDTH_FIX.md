# Chart Bubble Width Fix

## Issue
Chart bubbles (with `data-bubble-type="chart"`) were not displaying at full width, making charts appear cramped and difficult to read.

## Solution
Updated CSS rules to ensure chart bubbles are displayed at full width with a minimum of 600px (or 100% on smaller screens).

## Changes Made

### 1. Base Chart Bubble Styles (`assets/css/chat.css` line 2105-2113)
```css
.wp-mcp-ai-chat__bubble--chart {
    max-width: 100%;
    min-width: min(600px, 100%);  /* NEW: Ensures at least 600px or full container width */
    width: 100%;                  /* NEW: Forces full width */
    padding: 0.75rem;
    background: var(--wp-mcp-ai-color-chart-bubble-background, #f8faff);
    border: 1px solid var(--wp-mcp-ai-color-chart-bubble-border, rgba(59, 130, 246, 0.2));
}
```

### 2. Mobile Responsive Styles (`assets/css/chat.css` line 2137-2148)
```css
@media (max-width: 600px) {
    .wp-mcp-ai-chat__bubble--chart {
        padding: 0.5rem;
        min-width: 100%;  /* NEW: Full width on mobile devices */
    }
}
```

### 3. Compact Template Override (`assets/css/chat.css` line 2332-2337)
```css
/* Chart bubbles should be full width even in compact template */
.wp-mcp-ai-chat--template-compact .wp-mcp-ai-chat__bubble--chart {
    max-width: 100%;
    min-width: min(600px, 100%);
    width: 100%;
}
```

## How It Works

The `min(600px, 100%)` CSS function ensures:
- On screens wider than 600px: Chart bubbles will be at least 600px wide
- On screens narrower than 600px: Chart bubbles will be 100% of the container width
- Combined with `width: 100%`, this forces the bubble to fill its container

## Affected Elements

Chart bubbles are identified by:
- CSS class: `.wp-mcp-ai-chat__bubble--chart`
- Data attribute: `data-bubble-type="chart"`
- JavaScript: Applied when `displayPayload.chartHtml` is present

Example HTML structure:
```html
<div class="wp-mcp-ai-chat__message wp-mcp-ai-chat__bubble wp-mcp-ai-chat__bubble--tool wp-mcp-ai-chat__bubble--chart wp-mcp-ai-delete-enabled" 
     data-bubble-type="chart">
    ✓ Pie Chart
    <div class="wp-mcp-ai-chat__chart-block">
        <!-- Chart iframe content -->
    </div>
</div>
```

## Testing

To test the changes:

1. **Create a chart using a tool that generates Chart.js visualizations**
   - Use tools like `create_pie_chart`, `create_bar_chart`, etc.

2. **Verify on desktop (>600px width)**
   - Chart bubble should be full width of the chat container
   - Minimum width should be 600px

3. **Verify on mobile (<600px width)**
   - Chart bubble should be full width (100%)
   - Should adapt to screen size gracefully

4. **Verify in different templates**
   - Default template: Full width behavior
   - Compact template: Full width behavior (overriding the 85% max-width)
   - Sidebar template: Full width behavior

## Notes

- Other bubble types (user, assistant, tool) remain at their original max-width (80% for default, 85% for compact)
- Chart blocks within the bubble maintain their responsive aspect ratio
- Dark mode styles remain unchanged and compatible
- No changes to JavaScript - this is a CSS-only fix

## Browser Compatibility

The `min()` CSS function is supported in:
- Chrome 79+
- Firefox 75+
- Safari 11.1+
- Edge 79+

This covers all modern browsers and provides graceful degradation for older browsers (they will use the `width: 100%` rule).
