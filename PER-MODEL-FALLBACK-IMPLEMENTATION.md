# Per-Model High Token Fallback Implementation

## Summary

This implementation adds per-model high token fallback configuration to the WP oOS system, allowing different AI models to have different fallback strategies when their TPM (Tokens Per Minute) limits are exceeded.

## Problem Statement

**Question**: "Technically do I need a High Token Model Switch for OpenAI (per model)?"

**Answer**: **YES**, for the following reasons:

1. **Different TPM Limits by Tier**: OpenAI models have different TPM limits based on API tier:
   - `gpt-4o-mini`: 200,000 TPM (Tier 1)
   - `gpt-4o`: 30,000 TPM (Tier 1) → 800,000 TPM (Tier 5)
   - Users may have different tier access for different models

2. **One Size Doesn't Fit All**: A single global fallback model doesn't account for:
   - Model-specific characteristics and capabilities
   - Different tier levels across models
   - Provider-specific fallback strategies (OpenAI → Gemini vs Claude → GPT-4o)

3. **Optimization**: Per-model configuration allows:
   - Optimal fallback paths for each model
   - Fine-grained control over model switching behavior
   - Cost optimization by choosing appropriate fallbacks

## Solution Architecture

### Three-Tier Fallback Resolution

```
1. Per-Model Fallback (NEW)
   ↓ (if not configured)
2. Global Fallback Setting
   ↓ (if not configured)
3. Default Fallback (gemini-2.0-flash-exp)
```

### Implementation Details

#### 1. Model Rate Limits CCT Enhancement

**File**: `includes/class-wp-mcp-ai-model-rate-limits-cct.php`

- Added `fallback_model` field (text field, optional)
- Added `get_model_fallback($model)` method to retrieve per-model fallback
- Added admin column to display fallback model in CCT list
- Field description: "Model to use when this model's TPM limit is exceeded (e.g., gemini-2.0-flash-exp). Leave empty to use global fallback setting."

#### 2. Model Selector Enhancement

**File**: `includes/class-wp-mcp-ai-model-selector.php`

**Changes to `check_tpm_and_suggest_fallback()`**:
```php
// Check if auto-switching is enabled
if ( ! empty( $settings['enable_high_token_model_switch'] ) ) {
    // First, try per-model fallback from CCT
    $high_capacity_model = WP_MCP_AI_Model_Rate_Limits_CCT::get_model_fallback( $model );
    
    // Fall back to global setting if no per-model fallback
    if ( empty( $high_capacity_model ) && ! empty( $settings['high_token_fallback_model'] ) ) {
        $high_capacity_model = $settings['high_token_fallback_model'];
    }
    
    // Verify fallback can handle tokens and use it
}
```

**Changes to `get_high_capacity_fallback_model()`**:
- Now accepts optional `$original_model` parameter
- Checks for per-model fallback first
- Falls back to global setting
- Then to default if neither configured

**Enhanced Logging**:
```php
'fallback_source' => class_exists( 'WP_MCP_AI_Model_Rate_Limits_CCT' ) 
    && WP_MCP_AI_Model_Rate_Limits_CCT::get_model_fallback( $model ) 
    ? 'per_model' 
    : 'global'
```

### Backward Compatibility

✅ **Fully Backward Compatible**:
- Works without JetEngine (uses global settings only)
- Global fallback setting still works exactly as before
- No breaking changes to existing APIs or configurations
- Per-model settings are additive, not replacing global settings

### Usage Examples

#### Basic Usage (Global Fallback)

All users get this by default - no changes needed:

```
Settings → WP oOS → High Token Tool Handling
- Enable Auto-Switch: ✓
- High-Capacity Fallback Model: gemini-2.0-flash-exp
```

#### Advanced Usage (Per-Model Fallback)

For users with JetEngine and different tier levels:

```
WP oOS → Model Rate Limits
1. Edit "gpt-4o" model
   - TPM Limit: 800000 (Tier 5)
   - High-Capacity Fallback Model: gemini-1.5-pro
   
2. Edit "gpt-4o-mini" model
   - TPM Limit: 200000 (Tier 1)
   - High-Capacity Fallback Model: gpt-4o (upgrade to full model)
```

Result:
- `gpt-4o-mini` → falls back to `gpt-4o` if exceeds 200k TPM
- `gpt-4o` → falls back to `gemini-1.5-pro` if exceeds 800k TPM
- All other models → use global fallback `gemini-2.0-flash-exp`

## Testing

### Test Coverage

**File**: `tests/test-per-model-fallback.php`

Tests include:
1. Per-model fallback is used when configured
2. Global fallback is used when no per-model fallback exists
3. `get_high_capacity_fallback_model()` accepts model parameter
4. Fallback logging includes source information
5. Backward compatibility without JetEngine
6. Auto-switching can be disabled
7. `fallback_model` field exists in CCT

### Manual Testing Scenarios

1. **Scenario**: User with Tier 5 gpt-4o, Tier 1 gpt-4o-mini
   - Configure per-model fallbacks as shown above
   - Test large request with gpt-4o-mini → should fallback to gpt-4o
   - Test very large request with gpt-4o → should fallback to gemini-1.5-pro

2. **Scenario**: User without JetEngine
   - System uses global fallback for all models
   - No errors or warnings

3. **Scenario**: Mixed provider usage
   - Configure Claude → GPT-4o fallback
   - Configure OpenAI → Gemini fallback
   - Test with different models to verify correct fallbacks

## Documentation

### Updated Files

**`docs/high-token-tool-handling.md`**:
- Added "Per-Model Fallback Configuration (Advanced)" section
- Updated "High-Capacity Fallback Model" to clarify it's the global setting
- Added "Fallback Resolution Order" explanation
- Enhanced logging documentation with `fallback_source` field
- Added FAQs about when to use per-model vs global settings
- Added changelog for version 1.1.0

## Benefits

1. **Flexibility**: Different models can have different fallback strategies
2. **Optimization**: Choose optimal fallback for each model's characteristics
3. **Tier Support**: Properly handle different API tier levels
4. **Cost Control**: Use cost-effective fallbacks where appropriate
5. **Provider Mixing**: Intelligent fallback across different AI providers
6. **Backward Compatible**: Existing configurations continue to work

## Use Cases

### Use Case 1: Multi-Tier OpenAI Account

**Setup**:
- Tier 5 access for `gpt-4o` (800k TPM)
- Tier 1 access for `gpt-4o-mini` (200k TPM)

**Configuration**:
- `gpt-4o` → fallback to `gemini-1.5-pro` (for > 800k)
- `gpt-4o-mini` → fallback to `gpt-4o` (for > 200k)
- Global → `gemini-2.0-flash-exp`

**Result**: Optimal model selection based on actual tier limits.

### Use Case 2: Multi-Provider Strategy

**Setup**:
- Primary: OpenAI models
- Secondary: Gemini models
- Tertiary: Claude models (via integration)

**Configuration**:
- OpenAI models → fallback to `gemini-2.0-flash-exp`
- Claude models → fallback to `gpt-4o`
- Gemini models → no fallback needed (1M+ TPM)

**Result**: Intelligent cross-provider fallback strategy.

### Use Case 3: Cost Optimization

**Setup**:
- Budget-conscious user wants to minimize costs

**Configuration**:
- `gpt-4o-mini` → fallback to `gemini-1.5-flash` (cheaper than gpt-4o)
- `gpt-4o` → fallback to `gemini-1.5-pro` (cheaper for large contexts)
- Global → `gemini-2.0-flash-exp`

**Result**: Lowest cost for high-token scenarios.

## Technical Notes

### Database Schema

The `fallback_model` field is stored in the Model Rate Limits CCT with these properties:
- Type: `text`
- Required: No
- Default: Empty (uses global fallback)
- Validation: None (allows any model identifier)
- REST API: Enabled

### Performance Impact

- **Negligible**: Single database query per model (cached by JetEngine)
- **No added latency**: Fallback check happens only when TPM limit is exceeded
- **Efficient**: Uses existing CCT infrastructure

### Security Considerations

- Field is only editable by users with `manage_options` capability
- Model identifier is sanitized with `sanitize_text_field()`
- No direct user input from frontend

## Migration Path

### For Existing Users

**No action required**:
- Existing global fallback settings continue to work
- Per-model settings are optional
- System automatically uses global fallback if per-model not configured

### For New Users

**Recommended Setup**:
1. Set global fallback to `gemini-2.0-flash-exp` (default)
2. If using JetEngine and have different tier levels:
   - Configure TPM limits for each model based on your tier
   - Optionally set per-model fallbacks for specific models
3. Enable logging to monitor fallback behavior

## Future Enhancements

Potential future improvements:
1. Auto-detection of OpenAI tier level
2. UI in main settings page for per-model configuration
3. Fallback chain (model → fallback1 → fallback2 → default)
4. Cost-aware fallback selection
5. A/B testing of different fallback strategies

## Conclusion

This implementation answers the question "Do I need per-model high token switch for OpenAI?" with a definitive **YES**, and provides a flexible, backward-compatible solution that:

- Allows per-model customization when needed
- Falls back to global settings when not configured
- Works without JetEngine for basic users
- Provides optimal fallback strategies for advanced users
- Maintains full backward compatibility

The system now supports both simple global configuration (for most users) and advanced per-model configuration (for users with complex needs), making it suitable for everyone from beginners to enterprise users.
