# Model Rate Limits CCT Documentation

## Overview

The Model Rate Limits Custom Content Type (CCT) provides a centralized database for managing API rate limits and model capabilities across different AI providers. This allows the system to dynamically adapt to provider-specific rate limits and avoid API throttling errors.

## Purpose

The CCT was created to solve the problem of managing tokens-per-minute (TPM) rate limits across different AI models. As described in the original issue:

> Request too large for gpt-4o in organization org-EM69cNcOBn73NmSjDMYCvcSb on tokens per min (TPM): Limit 30000, Requested 108665. The input or output tokens must be reduced in order to run successfully.

By maintaining a database of model limits, the system can:
- Prevent rate limit errors by respecting TPM/RPM constraints
- Optimize request sizing based on model context windows
- Provide cost estimates based on token pricing
- Enable/disable features based on model capabilities

## Database Structure

### CCT Slug
`ai_model_rate_limits`

### Meta Fields

| Field Name | Type | Description |
|------------|------|-------------|
| `model_name` | Text | Model identifier (e.g., "gpt-4o", "claude-3.5-sonnet") |
| `provider` | Select | AI provider: openai, anthropic, google, azure, other |
| `tpm_limit` | Number | Tokens Per Minute rate limit |
| `rpm_limit` | Number | Requests Per Minute rate limit |
| `context_window` | Number | Maximum context window size in tokens |
| `max_output_tokens` | Number | Maximum output tokens per request |
| `tier` | Select | Account tier: free, tier-1, tier-2, tier-3, scale |
| `supports_streaming` | Boolean | Whether model supports streaming responses |
| `supports_function_calling` | Boolean | Whether model supports function/tool calling |
| `supports_vision` | Boolean | Whether model can process images |
| `cost_per_1k_input_tokens` | Number | Cost in USD per 1000 input tokens |
| `cost_per_1k_output_tokens` | Number | Cost in USD per 1000 output tokens |
| `notes` | Textarea | Additional notes about the model |

## Default Models

The CCT automatically populates with 37 models on first load:

### OpenAI Models (10 models)
- **gpt-4o**: 30,000 TPM (Tier 1), 128K context
- **gpt-4o-mini**: 200,000 TPM, 128K context
- **gpt-4.1**: 300,000 TPM, 1M context (future model)
- **gpt-4.1-mini**: 400,000 TPM, 1M context (future model)
- **gpt-4.1-nano**: 500,000 TPM, 1M context (future model)
- **gpt-4-turbo**: 80,000 TPM, 128K context
- **gpt-4**: 10,000 TPM, 8K context
- **gpt-3.5-turbo**: 60,000 TPM, 16K context
- **gpt-3.5-turbo-16k**: 60,000 TPM, 16K context
- **gpt-3.5-turbo-instruct**: 60,000 TPM, 4K context

### OpenAI Reasoning Models (2 models)
- **o1-preview**: 200,000 TPM, 128K context
- **o1-mini**: 200,000 TPM, 128K context

### Google Gemini Models (6 models)
- **gemini-1.5-pro**: 1M TPM, 2M context
- **gemini-1.5-pro-002**: 1M TPM, 2M context (latest)
- **gemini-1.5-flash**: 1M TPM, 1M context
- **gemini-1.5-flash-002**: 1M TPM, 1M context (latest)
- **gemini-2.0-flash**: 1M TPM, 1M context
- **gemini-pro**: 125K TPM, 32K context

### Anthropic Claude Models (6 models)
- **claude-3.5-sonnet**: 40,000 TPM (Build tier), 200K context
- **claude-3.5-sonnet-v2**: 40,000 TPM (Build tier), 200K context
- **claude-3-opus**: 20,000 TPM (Build tier), 200K context
- **claude-3-haiku**: 50,000 TPM (Build tier), 200K context
- **claude-2.1**: 20,000 TPM, 200K context
- **claude-instant-1.2**: 50,000 TPM, 100K context

### Azure OpenAI (1 model)
- **gpt-4o** (Azure): 450,000 TPM, 128K context

### GPT-5 Models (2 models)
- **gpt-5**: 500,000 TPM (Tier 1), 128K context - up to 40M TPM in Tier 5
- **gpt-5-mini**: 500,000 TPM (Tier 1), 128K context - up to 180M TPM in Tier 5

### Ollama / LM Studio Models (8 models - local deployment)
- **llama3**: 8K context, no rate limits (local)
- **llama3:70b**: 8K context, no rate limits (local)
- **mistral**: 8K context, no rate limits (local)
- **codellama**: 16K context, optimized for code
- **phi3**: 4K context, efficient small model
- **deepseek-coder**: 16K context, specialized for coding
- **qwen2**: 32K context, large context window
- **gemma2**: 8K context, Google's open model

## Usage

### Accessing Model Limits

```php
// Get model limits from CCT
$limits = WP_MCP_AI_Model_Rate_Limits_CCT::get_model_limits( 'gpt-4o' );

if ( $limits ) {
    echo "TPM Limit: " . $limits['tpm_limit'];
    echo "Context Window: " . $limits['context_window'];
}
```

### Token Budget Manager Integration

The Token Budget Manager automatically queries the CCT for model limits:

```php
// Get context window (falls back to CCT)
$context_window = WP_MCP_AI_Token_Budget_Manager::get_model_limit( 'gpt-4o' );

// Get TPM limit
$tpm_limit = WP_MCP_AI_Token_Budget_Manager::get_model_tpm_limit( 'gpt-4o' );

// Get RPM limit
$rpm_limit = WP_MCP_AI_Token_Budget_Manager::get_model_rpm_limit( 'gpt-4o' );
```

### Adding New Models

You can add new models via:

1. **WordPress Admin**: Navigate to the Model Rate Limits section in JetEngine
2. **REST API**: POST to `/wp-json/jet-cct/ai_model_rate_limits` (requires `manage_options` capability)
3. **Programmatically**:

```php
$handler = WP_MCP_AI_Model_Rate_Limits_CCT::get_item_handler();
$handler->update_item( array(
    'model_name'                => 'new-model-name',
    'provider'                  => 'openai',
    'tpm_limit'                 => 50000,
    'context_window'            => 128000,
    'supports_streaming'        => true,
    'supports_function_calling' => true,
    'supports_vision'           => false,
) );
```

### Updating Existing Models

Rate limits change over time as providers adjust their tiers. Update models via:

1. **Admin Interface**: Edit the model record in WordPress admin
2. **REST API**: PUT to `/wp-json/jet-cct/ai_model_rate_limits/{id}`
3. **Bulk Update**: Export, modify, and re-import via JetEngine

## Rate Limit Tiers

Different account tiers have different rate limits:

| Tier | Description | Typical TPM Range |
|------|-------------|-------------------|
| free | Free tier | 10K - 50K |
| tier-1 | Paid accounts | 20K - 200K |
| tier-2 | Higher usage | 100K - 1M |
| tier-3 | Enterprise | 500K - 10M |
| scale | Scale tier | 1M+ |

## Model Capabilities

The CCT tracks three key capability flags:

### Streaming Support
Models that support streaming responses for real-time output:
- All GPT models: ✅
- All Gemini models: ✅
- Most Claude models: ✅
- o1 models: ❌

### Function Calling
Models that support function/tool calling:
- GPT-4o, GPT-4-turbo: ✅
- Gemini 1.5+: ✅
- Claude 3+: ✅
- o1 models: ❌

### Vision Support
Models that can process images:
- GPT-4o, GPT-4-turbo: ✅
- Gemini 1.5+: ✅
- Claude 3+: ✅
- GPT-3.5, o1: ❌

## Cost Tracking

The CCT includes pricing data to help estimate costs:

```php
$limits = WP_MCP_AI_Model_Rate_Limits_CCT::get_model_limits( 'gpt-4o' );

$input_tokens = 1000;
$output_tokens = 500;

$input_cost = ( $input_tokens / 1000 ) * $limits['cost_per_1k_input_tokens'];
$output_cost = ( $output_tokens / 1000 ) * $limits['cost_per_1k_output_tokens'];
$total_cost = $input_cost + $output_cost;

echo "Estimated cost: $" . number_format( $total_cost, 4 );
```

## Best Practices

1. **Regular Updates**: Rate limits change frequently. Review and update limits quarterly.
2. **Monitor Usage**: Track actual TPM usage to ensure it stays within limits.
3. **Tier Selection**: Choose the appropriate tier for your account's actual limits.
4. **Fallback Handling**: The system falls back to hardcoded values if CCT is unavailable.
5. **Provider-Specific**: Remember that Azure OpenAI and direct OpenAI have different limits.

## Troubleshooting

### Model Not Found
If `get_model_limits()` returns null:
1. Check that JetEngine and Custom Content Types module are active
2. Verify the model name matches exactly (case-sensitive)
3. Ensure the CCT has been populated (runs automatically on `init` hook)

### Incorrect Limits
If limits seem wrong:
1. Verify your account tier with the provider
2. Check provider documentation for current limits
3. Update the CCT record with correct values
4. Note that limits may vary by region (especially Azure)

### CCT Not Loading
If the CCT doesn't appear in admin:
1. Ensure JetEngine plugin is installed and active
2. Check that Custom Content Types module is enabled
3. Verify Data Stores module is enabled
4. Look for errors in WordPress debug log

## Future Enhancements

Potential improvements for future versions:
- Automatic rate limit detection via API headers
- Historical tracking of limit changes
- Per-user/per-organization custom limits
- Real-time TPM usage monitoring
- Automatic request throttling based on limits
- Integration with provider usage dashboards

## References

- [OpenAI Rate Limits](https://platform.openai.com/docs/guides/rate-limits)
- [Google Gemini API Rate Limits](https://ai.google.dev/gemini-api/docs/rate-limits)
- [Anthropic Claude Rate Limits](https://docs.anthropic.com/claude/reference/rate-limits)
- [Azure OpenAI Quotas](https://learn.microsoft.com/en-us/azure/ai-services/openai/quotas-limits)

## Support

For questions or issues with the Model Rate Limits CCT:
1. Check this documentation
2. Review the test file: `tests/test-model-rate-limits-cct.php`
3. Examine the source: `includes/class-wp-mcp-ai-model-rate-limits-cct.php`
4. Open an issue on GitHub
