# Frontend Cost Display

**Phase 7 Week 5-6: Enhanced Token Tracking with Real-Time Cost Attribution**

## Overview

WP oOS now supports real-time display of token usage and estimated costs directly in the frontend chat interface. This feature helps users understand the API usage and associated costs for each AI interaction.

## Features

- **Token Count Display**: Shows total tokens used (prompt + completion)
- **Cost Display**: Shows estimated cost in USD
- **Provider/Model Info**: Hover tooltip shows which AI provider and model was used
- **Token Breakdown**: Hover tooltip shows input vs output token breakdown
- **Real-Time Updates**: Costs appear immediately after each assistant response
- **Opt-In Design**: Disabled by default, can be enabled in admin settings

## Enabling Cost Display

### Via Admin Settings

1. Navigate to **WP oOS → General Settings**
2. Scroll to **Show Usage Costs** checkbox
3. Check "Display token usage and estimated costs in chat interface"
4. Click **Save Changes**

### Via Code (Filter Hook)

```php
add_filter( 'wp_mcp_ai_show_usage_costs', function( $show_costs, $user_id ) {
    // Enable for specific users
    if ( user_can( $user_id, 'manage_options' ) ) {
        return true;
    }
    return $show_costs;
}, 10, 2 );
```

## What Users See

When enabled, small badge-style tabs appear below each assistant message:

```
Assistant: [Response text here]

[Tokens: 1,234] [Cost: $0.0025]
```

### Badge Details

**Tokens Badge:**
- Shows total tokens used
- Hover tooltip shows breakdown: "1,234 total (750 in / 484 out)"
- Blue color scheme

**Cost Badge:**
- Shows cost in USD to 4 decimal places
- Marked as "Est. Cost" if provider/model was inferred
- Marked as "Cost" if provider/model is confirmed
- Hover tooltip shows provider and model used
- Green color scheme

## Cost Calculation

Costs are calculated using the `WP_MCP_AI_Cost_Calculator` class with provider-specific pricing:

### Supported Providers

**OpenAI:**
- Pricing based on model (gpt-4o, gpt-4o-mini, etc.)
- Separate rates for input and output tokens
- Updated pricing tables included

**Google Gemini:**
- Pricing based on model (gemini-1.5-pro, gemini-1.5-flash, etc.)
- Character-based pricing converted to tokens
- Free tier considerations

**Anthropic Claude:**
- Pricing based on model (claude-3-opus, claude-3-sonnet, etc.)
- Separate rates for input and output tokens

**Ollama / LM Studio:**
- Local AI with no API costs
- Displays $0.00

### Cost Accuracy

**Actual Cost (`is_estimated: false`):**
- Provider and model confirmed from request
- Uses exact pricing for that model
- Labeled as "Cost"

**Estimated Cost (`is_estimated: true`):**
- Provider/model inferred from settings
- May not reflect actual model used
- Labeled as "Est. Cost" (italic style)

## Technical Implementation

### Backend

Cost data is calculated and included in the REST API response:

```php
// In includes/class-wp-mcp-ai-rest.php
$cost_data = array(
    'cost_usd'     => 0.0025,
    'provider'     => 'openai',
    'model'        => 'gpt-4o-mini',
    'is_estimated' => false,
);

$payload['cost'] = $cost_data;
```

### Frontend

The chat interface automatically displays badges when:
1. Setting is enabled (`showUsageCosts = true`)
2. Usage data is present in response
3. Cost data is present in response (optional)

```javascript
// In assets/js/chat.js
attachUsageBadges(messageElement, usage, cost);
```

### Styling

Badges use CSS custom properties for theming:

```css
.wp-mcp-ai-chat__usage-badge {
    background: var(--wp-mcp-ai-color-background-secondary);
    border: 1px solid var(--wp-mcp-ai-color-border);
    /* ... */
}
```

Dark mode is automatically supported via `prefers-color-scheme: dark`.

## Privacy & Data

- **No External Tracking**: Costs are calculated locally, not sent to external services
- **No Storage**: Costs are displayed but not stored in database (unless using Enhanced Token Tracking)
- **User Context**: Costs are only shown to users with the setting enabled
- **No PII**: Cost calculations use only token counts and model information

## Compatibility

### Requirements

- WP oOS 1.1.0 or higher
- Enhanced Token Tracking enabled (automatic)
- Cost Calculator class loaded (automatic)

### Browser Support

- Modern browsers (Chrome, Firefox, Safari, Edge)
- Mobile responsive design
- Graceful degradation for older browsers

### WordPress Compatibility

- WordPress 6.0+
- Works with all themes
- Compatible with Elementor, Gutenberg, Classic Editor

## Troubleshooting

### Costs Not Showing

**Check Settings:**
- Verify "Show Usage Costs" is enabled in General settings
- Clear browser cache and reload page

**Check Response:**
- View browser console for errors
- Verify API responses include `usage` data
- Check that Cost Calculator class exists

**Check Filters:**
- Verify no filter is disabling display
- Check `wp_mcp_ai_show_usage_costs` filter

### Incorrect Costs

**Verify Model:**
- Hover over cost badge to see provider/model
- Check if cost shows "Est. Cost" (estimated)
- Verify provider settings match actual usage

**Check Pricing:**
- Review `WP_MCP_AI_Cost_Calculator` pricing tables
- Verify provider pricing is up to date
- Compare with provider's official pricing page

**Update Pricing:**
```php
// Override pricing via filter
add_filter( 'wp_mcp_ai_cost_calculator_pricing', function( $pricing ) {
    $pricing['openai']['gpt-4o-mini']['input'] = 0.15 / 1000000; // per token
    $pricing['openai']['gpt-4o-mini']['output'] = 0.60 / 1000000;
    return $pricing;
} );
```

## Future Enhancements

Planned features for future releases:

- **Session Totals**: Cumulative cost for entire conversation
- **Budget Alerts**: Notify when approaching cost limits
- **Cost History**: Track costs over time
- **Cost Analytics**: Charts and reporting in admin
- **Per-Tool Costs**: Break down by which tools were used
- **Cost Optimization**: Suggest cheaper models/providers

## API Reference

### Filter Hooks

**`wp_mcp_ai_show_usage_costs`**
```php
apply_filters( 'wp_mcp_ai_show_usage_costs', bool $show_costs, int $user_id );
```
Control whether costs are shown for a specific user.

**`wp_mcp_ai_cost_calculator_pricing`**
```php
apply_filters( 'wp_mcp_ai_cost_calculator_pricing', array $pricing );
```
Modify provider pricing tables.

### JavaScript API

**Global Config:**
```javascript
window.wpMcpAiChat.showUsageCosts // boolean
```

**Function:**
```javascript
attachUsageBadges(messageElement, usage, costData)
```

**CSS Classes:**
- `.wp-mcp-ai-chat__usage-info` - Container
- `.wp-mcp-ai-chat__usage-badge` - Individual badge
- `.wp-mcp-ai-chat__usage-badge--tokens` - Tokens badge
- `.wp-mcp-ai-chat__usage-badge--cost` - Cost badge
- `.wp-mcp-ai-chat__usage-badge--estimated` - Estimated cost indicator

## Related Documentation

- [Token Management](./token-management.md)
- [Enhanced Token Tracking](./archive/token-manager/TOKEN-ENHANCEMENT-NEXT-STEP.md)
- [Cost Calculator](./cost-calculator.md)
- [Analytics Engine](./analytics-engine.md)

## Support

For issues or questions:
- GitHub Issues: https://github.com/nvdigitalsolutions/wp-mcp-ai/issues
- Documentation: See `/docs/` directory
