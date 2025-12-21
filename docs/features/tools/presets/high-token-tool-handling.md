# High Token Tool Handling

## Overview

The WP oOS system includes intelligent handling for tools that return large amounts of data (such as web crawling, document parsing, or large database queries). When these tools return results that exceed the current AI model's token limits, the system can automatically switch to a higher-capacity model or gracefully handle the overflow through message truncation.

## The Problem

Some tools, particularly `run_crawl4ai_job`, can return very large responses containing:
- HTML content
- Markdown conversions
- Text extractions
- Metadata

When used in an agentic loop (where the AI automatically calls tools), these large responses get added to the conversation history. Each subsequent API call includes all previous messages, causing token counts to grow rapidly.

### Example Scenario

```
Initial request: User asks to crawl a website
↓
First API call: AI decides to use run_crawl4ai_job tool (tokens: ~1,000)
↓
Tool executes: Returns 100,000 tokens of website content
↓
Second API call: Needs to send all previous messages + tool result (tokens: ~101,000)
↓
If using gpt-4o-mini (200k TPM limit): ✓ Works
↓
AI calls another tool: Adds more tokens to conversation
↓
Third API call: Now at 200k+ tokens
↓
ERROR: "Request too large for gpt-4o-mini: Limit 200,000, Requested 463,045"
```

## The Solution

WP oOS implements a three-tier strategy to handle this scenario:

### 1. Token Limit Detection

Before each API call in the agentic loop, the system:
- Estimates total tokens in the conversation
- Checks if it exceeds the model's TPM (Tokens Per Minute) limit
- Compares against configured model limits

### 2. Automatic Model Switching (Tier 1)

When token limits are exceeded:
- System checks if auto-switching is enabled (default: ON)
- Attempts to use the configured fallback model (default: Gemini 2.0 Flash)
- Gemini models have much higher limits (1-2 million tokens)
- If fallback model can handle the tokens, switches seamlessly

**Benefits:**
- Preserves full context
- No data loss
- Transparent to the user
- Better model for handling large content

### 3. Message Truncation (Tier 2)

If even the fallback model can't handle the tokens:
- Truncates older messages from the conversation
- Always preserves:
  - System prompts
  - Recent messages (most relevant context)
- Removes oldest user/assistant exchanges first
- Logs what was truncated for debugging

### 4. Clear Error Handling (Tier 3)

If truncation still doesn't work:
- Returns detailed error message
- Includes context about what was attempted
- Suggests solutions (use smaller inputs, different model, etc.)

## Configuration

### Admin Settings

Navigate to **Settings → WP oOS → High Token Tool Handling**

#### Enable Auto-Switch to High-Capacity Model
- **Default**: Enabled ✓
- **Description**: Automatically switches to the fallback model when token limits are exceeded
- **Recommendation**: Keep enabled for best user experience

#### High-Capacity Fallback Model (Global)
- **Default**: `gemini-2.0-flash-exp`
- **Options**:
  - **Gemini 2.0 Flash (Experimental)** - 1M tokens - Best balance of speed and capacity
  - **Gemini 1.5 Flash** - 1M tokens - Stable production model
  - **Gemini 1.5 Pro** - 2M tokens - Maximum capacity
  - **GPT-4o** - 128k tokens - OpenAI's highest capacity
  - **GPT-4 Turbo** - 128k tokens - Alternative OpenAI option

**Note**: This is the global fallback setting. You can also configure per-model fallback settings (see below).

#### Per-Model Fallback Configuration

You can configure different fallback models for each AI model directly in the settings page:

1. Navigate to **Settings → WP oOS → High Token Tool Handling**
2. Scroll to the **Per-Model Fallback Configuration** section
3. For each model (e.g., `gpt-4o-mini`, `gpt-4o`), select a specific fallback from the dropdown
4. Leave blank to use the global fallback setting
5. Save settings

**Available Models for Configuration:**
- **GPT-4o Mini** - Tier 1: 200k TPM
- **GPT-4o** - Tier 1: 30k TPM / Tier 5: 800k TPM
- **GPT-4 Turbo** - Tier 1: 80k TPM
- **O1 Preview** - Tier 1: 200k TPM
- **O1 Mini** - Tier 1: 200k TPM

**When to use per-model fallbacks:**
- You have different OpenAI API tier levels for different models
- Your `gpt-4o` has Tier 5 access (800,000 TPM) but `gpt-4o-mini` only has Tier 1 (200,000 TPM)
- You want to use different fallback strategies for different providers (e.g., OpenAI → Gemini, Claude → GPT-4o)
- You need fine-grained control over model switching behavior

**Example Configuration:**
```
Global Fallback: gemini-2.0-flash-exp
gpt-4o-mini → gpt-4o (upgrade to full model when exceeds 200k TPM)
gpt-4o → gemini-1.5-pro (switch to high-capacity when exceeds tier limit)
gpt-4-turbo → (blank - uses global fallback)
```

#### Advanced: JetEngine CCT Configuration

For advanced users with JetEngine installed, you can also configure fallback models for additional AI models in the Model Rate Limits CCT:

1. Navigate to **WP oOS → Model Rate Limits** in the WordPress admin
2. Edit the model you want to configure (e.g., `claude-3-opus`)
3. Set the **High-Capacity Fallback Model** field to your preferred fallback
4. Save the model configuration

**Fallback Resolution Order:**
1. Check if the specific model has a configured fallback in Settings page
2. If not, check if the specific model has a configured fallback in Model Rate Limits CCT (JetEngine)
3. If not, use the global "High-Capacity Fallback Model" setting
4. If neither is configured, default to `gemini-2.0-flash-exp`

#### Choosing a Fallback Model

**For Most Users**: Use `gemini-2.0-flash-exp` (default)
- 1M token capacity handles most scenarios
- Fast response times
- Cost-effective
- Experimental but stable

**For Maximum Capacity**: Use `gemini-1.5-pro`
- 2M token capacity
- Can handle extremely large documents
- Slightly higher cost
- Use when crawling multiple large pages

**For OpenAI-Only Setups**: Use `gpt-4o`
- 128k tokens (less than Gemini)
- May still hit limits with very large tools
- Use only if you don't have Gemini API access

### Programmatic Configuration

You can also configure this programmatically:

```php
// Disable auto-switching
add_filter( 'wp_mcp_ai_settings', function( $settings ) {
    $settings['enable_high_token_model_switch'] = false;
    return $settings;
} );

// Change fallback model
add_filter( 'wp_mcp_ai_settings', function( $settings ) {
    $settings['high_token_fallback_model'] = 'gemini-1.5-pro';
    return $settings;
} );

// Add custom fallback model option
add_filter( 'wp_mcp_ai_high_token_fallback_models', function( $models ) {
    $models['claude-3-opus'] = 'Claude 3 Opus - 200k tokens';
    return $models;
} );
```

**Note**: Per-model fallback configuration is managed through the Model Rate Limits CCT in the WordPress admin when JetEngine is installed. The system automatically checks for per-model fallback settings before using the global fallback.

## Tool-Specific Settings

### Crawl4AI Token Limit

The `run_crawl4ai_job` tool has its own token limit to prevent returning excessively large results:

**Default**: 100,000 tokens (reduced from 450,000)

This can be adjusted via filter:

```php
add_filter( 'wp_mcp_ai_crawl4ai_result_token_limit', function( $limit, $response ) {
    // Increase for larger sites
    return 200000; // 200k tokens
}, 10, 2 );
```

**Recommendations**:
- **100k tokens**: Good for most websites (default)
- **200k tokens**: For content-heavy sites with many images/links
- **50k tokens**: For faster processing and lower costs
- **Never exceed 450k**: Even Gemini may struggle with extremely large responses

## Logging and Debugging

### Enable Logging

Enable logging in **Settings → WP oOS → Enable Logging** to see:
- When model switches occur
- What triggered the switch
- Token counts before/after
- Message truncation events

### Log Events

The system logs these events:

#### Model Switch Event
```
Event: model_switched_to_high_capacity
Message: "Automatically switched to configured high-capacity fallback model."
Context:
  - original_model: "gpt-4o-mini"
  - fallback_model: "gemini-2.0-flash-exp"
  - required_tokens: 250000
  - fallback_tpm_limit: 1000000
  - fallback_source: "settings_per_model", "cct_per_model", or "global"
```

**New in 1.2.0**: The log now includes `fallback_source` which indicates where the fallback came from:
- `settings_per_model`: Model-specific fallback configured in Settings → WP oOS
- `cct_per_model`: Model-specific fallback configured in Model Rate Limits CCT (JetEngine)
- `global`: Global fallback setting from Settings → WP oOS

#### Agentic Model Switch Event
```
Event: agentic_model_switched
Message: "Switched to higher-capacity model due to token limits"
Context:
  - iteration: 0
  - original_model: "gpt-4o-mini"
  - new_model: "gemini-2.0-flash-exp"
  - assistant_id: 14
```

#### Message Truncation Event
```
Event: agentic_messages_truncated
Message: "Messages truncated to fit within TPM limits during agentic loop"
Context:
  - iteration: 0
  - model: "gpt-4o-mini"
  - target_tokens: 160000
  - assistant_id: 14
```

#### Error Event
```
Error: wp_mcp_ai_tpm_limit_exceeded
Message: "Agentic tool execution loop failed: Messages exceed TPM limit even after truncation"
Context:
  - assistant_id: 14
  - user_id: 1
  - iteration: 0
  - error_code: "wp_mcp_ai_tpm_limit_exceeded"
  - model: "gpt-4o-mini"
```

## Best Practices

### For Plugin Developers

1. **Test with Large Data**: When creating tools, test with responses >100k tokens
2. **Implement `sanitize_for_llm()`**: Implement the `WP_MCP_AI_Tool_LLM_Sanitizer_Interface` to strip unnecessary data
3. **Set Reasonable Limits**: Don't return more data than necessary
4. **Use Pagination**: For large datasets, return results in chunks

Example sanitization:
```php
class My_Tool implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_LLM_Sanitizer_Interface {
    
    public function sanitize_for_llm( $result ) {
        if ( ! is_array( $result ) ) {
            return $result;
        }
        
        // Remove verbose metadata
        unset( $result['raw_data'] );
        unset( $result['debug_info'] );
        
        // Keep only essential fields
        return array(
            'summary' => $result['summary'],
            'key_findings' => $result['key_findings'],
            'url' => $result['url'],
        );
    }
}
```

### For Site Administrators

1. **Monitor Logs**: Keep an eye on model switch events
2. **Adjust Limits**: If switches happen frequently, consider:
   - Using a higher-capacity default model
   - Adjusting tool-specific token limits
   - Reducing input sizes
3. **Check API Costs**: Gemini is cost-effective, but monitor usage
4. **Test Workflows**: Test common user scenarios with realistic data

### For End Users

The system handles everything automatically! Users don't need to worry about token limits. However:

- **Be Patient**: Large web crawls may take longer when model switching occurs
- **Refine Queries**: If consistently hitting limits, try breaking tasks into smaller pieces
- **Report Issues**: If you see token limit errors despite auto-switching, report to admin

## Troubleshooting

### "Still Too Large After Truncation" Errors

**Symptoms**: Getting errors even with auto-switching enabled

**Solutions**:
1. Verify Gemini API key is configured
2. Check fallback model is set to a high-capacity model (Gemini)
3. Reduce crawl4ai token limit
4. Split large tasks into multiple smaller requests

### Model Not Switching

**Symptoms**: Logs show no model switch events but hitting limits

**Solutions**:
1. Check "Enable Auto-Switch" is enabled
2. Verify fallback model is different from default model
3. Ensure you have API credentials for the fallback provider
4. Check logs for authentication errors

### Unexpected Costs

**Symptoms**: API costs higher than expected

**Solutions**:
1. Review log for frequent model switches
2. Consider increasing default model capacity
3. Adjust crawl4ai limits to be more aggressive
4. Use caching for repeated crawls

## Technical Details

### Token Estimation

The system uses a simple heuristic for token estimation:
- **4 characters ≈ 1 token** (average across models)
- Actual token counts may vary by model
- Conservative estimates prevent edge cases

### TPM Limits by Model

```php
'gpt-4o-mini'       => 200,000 TPM
'gpt-4o'            => 30,000 TPM (Tier 1), 800,000 TPM (Tier 5)
'gpt-4-turbo'       => 150,000 TPM
'gemini-1.5-flash'  => 1,000,000 TPM
'gemini-1.5-pro'    => 1,000,000 TPM
'gemini-2.0-flash'  => 1,000,000 TPM
```

### Performance Impact

- **Token counting**: Negligible (~1ms for typical conversations)
- **Model switching**: Transparent, no delays
- **Message truncation**: Fast, array operations only

## Frequently Asked Questions

**Q: Does this cost more money?**
A: Gemini models are generally more cost-effective than GPT-4 for large contexts, so costs may actually decrease.

**Q: Will responses be slower?**
A: Gemini Flash models are very fast, often faster than GPT-4. You shouldn't notice a difference.

**Q: Can I force a specific model?**
A: Yes, disable auto-switching and the assistant will use its configured model regardless of token count.

**Q: What happens to my conversation history?**
A: With auto-switching, all history is preserved. With truncation, only old messages are removed, recent context stays.

**Q: Can I use Claude models as fallback?**
A: Not currently built-in, but you can add Claude support via filters if you have Claude API integration.

**Q: Does this work with local models (Ollama)?**
A: Local models don't have TPM limits in the cloud sense, but they have context window limits. The system respects these limits.

**Q: When should I use per-model fallback configuration instead of the global setting?**
A: Use per-model configuration when:
- You have different OpenAI API tier levels (e.g., Tier 5 for `gpt-4o` but Tier 1 for `gpt-4o-mini`)
- Different models need different fallback strategies based on their capabilities
- You want fine-grained control over each model's behavior
- You're mixing multiple AI providers and want custom fallback paths

**Q: How do I set up per-model fallback?**
A: In the main settings page:
1. Go to **Settings → WP oOS → High Token Tool Handling**
2. Scroll to **Per-Model Fallback Configuration**
3. For each model, select a fallback or leave blank to use global fallback
4. Click **Save Changes**

For additional models (with JetEngine):
1. Go to **WP oOS → Model Rate Limits** in WordPress admin
2. Edit the model you want to configure
3. Set the **High-Capacity Fallback Model** field
4. Save the configuration

The system will automatically use the per-model setting when available, falling back to the global setting if not configured.

**Q: What if I don't have JetEngine installed?**
A: You can still configure per-model fallbacks in Settings → WP oOS for the most common OpenAI models (gpt-4o-mini, gpt-4o, gpt-4-turbo, o1-preview, o1-mini). JetEngine is only needed to configure additional models beyond these.

## Related Documentation

- [Tool Reference](../../../reference/tools/tool-reference.md) - Documentation for all available tools
- [REST API](../../../reference/api/rest-api.md) - REST API endpoints and usage
- *(Admin Settings documentation pending)* - Complete settings documentation
- [Token Budget Manager](../../../../includes/services/class-wp-mcp-ai-token-budget-service.php) - Source code
- [Agentic Loop Implementation](../../../../includes/class-wp-mcp-ai-rest.php#L2100-L2170) - Source code
- [Model Rate Limits CCT](../../../../includes/class-wp-mcp-ai-model-rate-limits-cct.php) - Source code for per-model configuration

## Changelog

### Version 1.2.0
- Added per-model fallback configuration UI in Settings → WP oOS
- Support for configuring fallbacks for common OpenAI models (gpt-4o-mini, gpt-4o, gpt-4-turbo, o1-preview, o1-mini)
- Updated fallback resolution order: Settings per-model → CCT per-model → Global → Default
- Enhanced logging with three fallback source types: `settings_per_model`, `cct_per_model`, `global`
- Works without JetEngine for basic per-model configuration

### Version 1.1.0
- Added per-model fallback configuration support via CCT
- New `fallback_model` field in Model Rate Limits CCT for customizing fallback per model
- Enhanced logging to show whether per-model or global fallback was used
- Improved fallback resolution order: per-model → global → default
- Backward compatible with existing global configuration

### Version 1.0.0
- Initial implementation of automatic model switching
- Added admin settings for configuration
- Reduced default crawl4ai token limit from 450k to 100k
- Added comprehensive logging
- Created fallback to message truncation
