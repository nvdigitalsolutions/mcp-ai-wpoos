# Cost Tracking Implementation

## Overview

This document describes the implementation of actual cost tracking in the WP oOS Token Manager. The feature adds comprehensive cost calculation and reporting for all AI model usage.

## Features

### 1. Automatic Cost Calculation

Every time token usage is recorded, the system automatically calculates costs based on:
- **Input tokens** (prompt tokens)
- **Output tokens** (completion tokens)  
- **Cached tokens** (50% of input token cost)

Costs are tracked at two levels:
- **Model level**: Aggregated across all assistants
- **Assistant level**: Per-assistant breakdown

### 2. Flexible Pricing Configuration

The system supports multiple pricing sources in priority order:

1. **JetEngine CCT** (User-configurable)
   - `cost_per_1k_input_tokens`
   - `cost_per_1k_output_tokens`
   - Per-model, per-provider configuration

2. **Hardcoded Fallbacks** (Based on Nov 2024 public pricing)
   - OpenAI: GPT-4o, GPT-4o-mini, GPT-4-turbo, GPT-3.5-turbo, o1-preview, o1-mini
   - Anthropic: Claude 3.5 Sonnet, Claude 3 Opus, Claude 3 Haiku
   - Google: Gemini 1.5 Pro, Gemini 1.5 Flash, Gemini 2.0 Flash
   - Local models: Free (Ollama, LM Studio, etc.)

3. **Filter Hook** (`wp_mcp_ai_token_budget_default_cost`)
   - For custom pricing logic

### 3. Token Manager UI

Three views display cost data:

#### Per-User View
- Total cost column showing cumulative spending per user
- Click "Details" to see provider/model breakdown with costs

#### Per-Tool View  
- (Existing functionality unchanged)
- Tools don't directly track costs as they use various models

#### Per-Site View
- Provider-level cost aggregation
- Site-wide spending summary

### 4. REST API Endpoint

New endpoint for programmatic access:

```
GET /wp-json/mcp-ai/v1/users/{id}/model-usage
```

**Parameters:**
- `provider` (optional): Filter by provider
- `model` (optional): Filter by specific model

**Response:**
```json
{
  "user_id": 123,
  "usage": [
    {
      "provider": "openai",
      "model": "gpt-4o",
      "requests": 50,
      "total_tokens": 25000,
      "prompt_tokens": 15000,
      "completion_tokens": 10000,
      "cached_tokens": 500,
      "total_cost": 0.1250,
      "input_cost": 0.0375,
      "output_cost": 0.1000,
      "cached_cost": 0.0006,
      "last_used_gmt": "2024-11-12 10:30:00"
    }
  ],
  "totals": {
    "requests": 50,
    "total_tokens": 25000,
    "total_cost": 0.1250,
    ...
  }
}
```

## Provider Support

All providers used in WP oOS are fully tracked:

| Provider | Key | Cost Tracking | Notes |
|----------|-----|---------------|-------|
| OpenAI | `openai` | ✅ Full pricing | GPT-4o, GPT-3.5-turbo, o1-preview, etc. |
| Anthropic | `anthropic` | ✅ Full pricing | Claude 3.5 Sonnet, Opus, Haiku |
| Google | `google` | ✅ Full pricing | Gemini 1.5 Pro/Flash, 2.0 Flash |
| Gemini | `gemini` | ✅ Full pricing | Alias for Google Gemini |
| Azure OpenAI | `azure` | ⚠️ User-configured | Use CCT for custom pricing |
| Ollama | `ollama` | ✅ Free | Local models |
| LM Studio | `lm_studio` | ✅ Free | Local models |
| Crawl4AI | `crawl4ai` | ⚠️ User-configured | Use CCT for pricing |
| Local | `local` | ✅ Free | Generic local models |

## Technical Implementation

### Cost Calculation Flow

```
1. User makes AI request
2. Response includes usage data (prompt_tokens, completion_tokens, cached_tokens)
3. WP_MCP_AI_Usage_Tracker::record_chat_usage() called
4. increment_totals() calculates cost via WP_MCP_AI_Token_Budget_Manager::calculate_cost()
5. Costs stored alongside token counts in user meta
6. UI and API display costs from stored data
```

### Data Structure

User meta key: `_wp_mcp_ai_usage_totals`

```php
array(
  'openai' => array(
    'gpt-4o' => array(
      'requests' => 50,
      'prompt_tokens' => 15000,
      'completion_tokens' => 10000,
      'total_tokens' => 25000,
      'cached_tokens' => 500,
      'total_cost' => 0.1250,      // New
      'input_cost' => 0.0375,      // New
      'output_cost' => 0.1000,     // New
      'cached_cost' => 0.0006,     // New
      'last_used_gmt' => '2024-11-12 10:30:00',
      'assistants' => array(
        123 => array(
          // Same structure
        )
      )
    )
  )
)
```

### Key Functions

#### WP_MCP_AI_Token_Budget_Manager

- `get_model_cost_per_1k( $model, $provider, $type )` - Get cost per 1K tokens
- `calculate_cost( $model, $prompt_tokens, $completion_tokens, $cached_tokens, $provider )` - Calculate total cost
- `get_fallback_pricing()` - Hardcoded pricing data

#### WP_MCP_AI_Usage_Tracker  

- `get_initial_model_totals()` - Includes cost fields
- `increment_totals()` - Calculates and accumulates costs

## Configuration

### Using JetEngine CCT

1. Navigate to **JetEngine → AI Model Rate Limits**
2. Edit or create a model entry
3. Set `Cost Per 1K Input Tokens ($)` and `Cost Per 1K Output Tokens ($)`
4. Costs will automatically be used for that model

### Custom Pricing via Filter

```php
add_filter( 'wp_mcp_ai_token_budget_default_cost', function( $cost, $model, $provider, $type ) {
    if ( 'my-custom-model' === $model && 'input' === $type ) {
        return 0.005; // $0.005 per 1K input tokens
    }
    return $cost;
}, 10, 4 );
```

## Backward Compatibility

- Existing usage data without cost fields works seamlessly
- Cost fields are initialized to 0.0 on first access
- Old data can be retroactively priced using stored token counts (manual query needed)

## Testing

See `tests/test-token-cost-calculation.php` for comprehensive test coverage:

- Cost calculation for various models
- Cached token pricing (50% of input)
- Zero/unknown model handling
- Usage tracker integration
- Cumulative cost tracking

Run tests:
```bash
vendor/bin/phpunit tests/test-token-cost-calculation.php
```

## Future Enhancements

Potential improvements:
- Historical cost trends/graphs
- Budget alerts when costs exceed thresholds
- Cost estimation before request submission
- Batch cost recalculation for historical data
- Cost export/reporting features
- Integration with accounting systems

## Support

For issues or questions:
- GitHub Issues: https://github.com/nvdigitalsolutions/wp-mcp-ai/issues
- Documentation: `docs/` directory

## Changelog

### Version 1.0.0 (November 2024)
- Initial cost tracking implementation
- Support for OpenAI, Anthropic, Google Gemini
- Support for local models (Ollama, LM Studio)
- Support for all providers via CCT
- REST API endpoint for cost data
- Token Manager UI updates
- Comprehensive test coverage
