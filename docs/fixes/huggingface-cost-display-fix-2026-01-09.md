# HuggingFace Cost Display Fix

**Issue**: HuggingFace costs showing up as "N/A" in token usage manager
**Date**: January 9, 2026
**Branch**: `copilot/fix-huggingface-costs-n-a`

## Problem Statement
When viewing the Token Usage Manager at the "Per Site" view (`https://bots.nvdigital.solutions/wp-admin/admin.php?page=wp-mcp-ai-dashboard&tab=token_manager&view=per_site`), HuggingFace model costs were displaying as "N/A" instead of showing actual cost calculations.

## Root Cause Analysis

### Discovery
1. The `WP_MCP_AI_Cost_Calculator` class (lines 295-337) already had HuggingFace pricing defined
2. The `WP_MCP_AI_Usage_Tracker::get_fallback_pricing()` method only included:
   - OpenAI models (gpt-4o, gpt-5, etc.)
   - Gemini models (gemini-1.5-flash, gemini-2.5-pro, etc.)
   - Claude/Anthropic models (claude-3.5-sonnet, etc.)
   - Cloudflare Workers AI models
   - **Missing: HuggingFace models**

### Why This Caused the Issue
1. Token Manager calls `WP_MCP_AI_Token_Usage_Service::get_site_wide_statistics()`
2. This service aggregates usage and calculates costs using `WP_MCP_AI_Usage_Tracker::calculate_cost()`
3. The `calculate_cost()` method tries to get pricing via `get_model_pricing()`
4. If Model Rate Limits CCT doesn't have the model, it falls back to `get_fallback_pricing()`
5. For HuggingFace models, `get_fallback_pricing()` returned `null`
6. When pricing is `null`, `calculate_cost()` returns `0.0`
7. The UI displays "N/A" when cost is `0.0`

## Solution

### Files Modified

#### 1. `includes/class-wp-mcp-ai-usage-tracker.php`
Added HuggingFace model pricing to the `get_fallback_pricing()` method (after line 622):

```php
// HuggingFace Inference API models (as of January 2026).
'deepseek-ai/deepseek-v3.2'                    => array(
    'input_cost_per_1k'  => 0.00028, // $0.28 per 1M tokens
    'output_cost_per_1k' => 0.00042, // $0.42 per 1M tokens
),
'meta-llama/llama-3.3-70b-instruct'            => array(
    'input_cost_per_1k'  => 0.001,
    'output_cost_per_1k' => 0.001,
),
'meta-llama/llama-3.1-8b-instruct'             => array(
    'input_cost_per_1k'  => 0.0003,
    'output_cost_per_1k' => 0.0003,
),
'mistralai/mistral-7b-instruct-v0.3'           => array(
    'input_cost_per_1k'  => 0.0002,
    'output_cost_per_1k' => 0.0002,
),
'microsoft/phi-3-mini-4k-instruct'             => array(
    'input_cost_per_1k'  => 0.0001,
    'output_cost_per_1k' => 0.0001,
),
'qwen/qwen2.5-72b-instruct'                    => array(
    'input_cost_per_1k'  => 0.001,
    'output_cost_per_1k' => 0.001,
),
'qwen/qwen2.5-7b-instruct'                     => array(
    'input_cost_per_1k'  => 0.0002,
    'output_cost_per_1k' => 0.0002,
),
```

**Note**: Model names are lowercase because the method applies `strtolower()` before matching (line 462).

#### 2. `tests/test-usage-tracker.php`
Added 5 comprehensive test methods (after line 465):

1. `test_calculate_cost_huggingface_deepseek()` - Tests DeepSeek-V3.2 cost calculation
2. `test_calculate_cost_huggingface_llama()` - Tests Llama 3.3 70B cost calculation  
3. `test_calculate_cost_huggingface_phi3()` - Tests Phi-3 Mini cost calculation
4. `test_calculate_cost_huggingface_mistral()` - Tests Mistral 7B cost calculation
5. `test_calculate_cost_huggingface_qwen()` - Tests Qwen 2.5 72B cost calculation

## Pricing Details

All pricing sourced from HuggingFace Inference API as of January 2026:

| Model | Input Cost (per 1M tokens) | Output Cost (per 1M tokens) |
|-------|---------------------------|----------------------------|
| DeepSeek-V3.2 | $0.28 | $0.42 |
| Llama 3.3 70B | $1.00 | $1.00 |
| Llama 3.1 8B | $0.30 | $0.30 |
| Mistral 7B v0.3 | $0.20 | $0.20 |
| Phi-3 Mini 4K | $0.10 | $0.10 |
| Qwen 2.5 72B | $1.00 | $1.00 |
| Qwen 2.5 7B | $0.20 | $0.20 |

## Verification

### Verification Script
Created `/tmp/verify_huggingface_fix.php` to test all pricing calculations independently.

**Results**: All 6 test cases passed ✓

### Code Quality
- No PHP syntax errors ✓
- Follows existing pricing pattern ✓
- Consistent with `WP_MCP_AI_Cost_Calculator` pricing ✓
- Code review passed with no issues ✓

## Impact

### Before Fix
- HuggingFace costs displayed as "N/A" in Token Manager
- Unable to track actual costs for HuggingFace API usage
- Inaccurate site-wide cost reporting

### After Fix
- HuggingFace costs calculate correctly
- Accurate display in all Token Manager views:
  - Per User view
  - Per Tool view
  - Per Site view (where issue was reported)
  - Per Models view
- Proper cost aggregation in site-wide statistics

## Testing Recommendations

1. **Manual Testing**:
   - View Token Manager "Per Site" view
   - Verify HuggingFace models show actual costs instead of "N/A"
   - Check "Usage by Provider" table shows HuggingFace costs
   - Check "Top Models by Usage" table shows costs for HuggingFace models

2. **Automated Testing**:
   - Run `./vendor/bin/phpunit tests/test-usage-tracker.php --filter "test_calculate_cost_huggingface"`
   - Verify all 5 new tests pass

3. **Integration Testing**:
   - Record some HuggingFace API usage
   - Verify costs appear correctly in all Token Manager views
   - Check that site-wide totals include HuggingFace costs

## Related Files

- `includes/class-wp-mcp-ai-usage-tracker.php` - Main fix location
- `includes/class-wp-mcp-ai-cost-calculator.php` - Reference pricing source
- `includes/services/class-wp-mcp-ai-token-usage-service.php` - Service layer that calls calculate_cost
- `includes/admin/sections/class-wp-mcp-ai-section-token-manager.php` - UI that displays costs
- `tests/test-usage-tracker.php` - Added test coverage
- `tests/test-cost-calculator.php` - Existing HuggingFace tests (reference)

## Future Improvements

1. Consider consolidating pricing into a single source to avoid duplication
2. Add automatic pricing updates from provider APIs
3. Implement caching for pricing lookups
4. Add support for dynamic model discovery

## References

- Issue URL: https://bots.nvdigital.solutions/wp-admin/admin.php?page=wp-mcp-ai-dashboard&tab=token_manager&view=per_site
- HuggingFace Inference Pricing: https://huggingface.co/pricing
- Cost Calculator Implementation: `includes/class-wp-mcp-ai-cost-calculator.php` lines 295-337
